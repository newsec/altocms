<?php
/*---------------------------------------------------------------------------
 * @Project: Alto CMS
 * @Project URI: http://altocms.com
 * @Description: Advanced Community Engine
 * @Copyright: Alto CMS Team
 * @License: GNU GPL v2 & MIT
 *----------------------------------------------------------------------------
 * Based on
 *   LiveStreet Engine Social Networking by Mzhelskiy Maxim
 *   Site: www.livestreet.ru
 *   E-mail: rus.engine@gmail.com
 *----------------------------------------------------------------------------
 */

F::IncludeFile(Config::Get('path.dir.libs') . '/DklabCache/config.php');
F::IncludeFile(LS_DKCACHE_PATH . 'Zend/Cache.php');
F::IncludeFile(LS_DKCACHE_PATH . 'Cache/Backend/MemcachedMultiload.php');
F::IncludeFile(LS_DKCACHE_PATH . 'Cache/Backend/TagEmuWrapper.php');
F::IncludeFile(LS_DKCACHE_PATH . 'Cache/Backend/Profiler.php');

/**
 * Типы кеширования: file и memory
 *
 */
define('SYS_CACHE_TYPE_FILE', 'file');
define('SYS_CACHE_TYPE_MEMORY', 'memory');
define('SYS_CACHE_TYPE_XCACHE', 'xcache');

/**
 * Модуль кеширования
 *
 * Для реализации кеширования используетс библиотека Zend_Cache с бэкэндами File, Memcached и XCache
 *
 * Т.к. в Memcached нет встроенной поддержки тегирования при кешировании, то для реализации тегов используется
 * враппер от Дмитрия Котерова - Dklab_Cache_Backend_TagEmuWrapper.
 *
 * Пример использования:
 * <pre>
 *    // Получает пользователя по его логину
 *    public function GetUserByLogin($sLogin) {
 *        // Пытаемся получить значение из кеша
 *        if (false === ($oUser = $this->Cache_Get("user_login_{$sLogin}"))) {
 *            // Если значение из кеша получить не удалось, то обращаемся к базе данных
 *            $oUser = $this->oMapper->GetUserByLogin($sLogin);
 *            // Записываем значение в кеш
 *            $this->Cache_Set($oUser, "user_login_{$sLogin}", array(), 60*60*24*5);
 *        }
 *        return $oUser;
 *    }
 *
 *    // Обновляет пользовател в БД
 *    public function UpdateUser($oUser) {
 *        // Удаляем кеш конкретного пользователя
 *        $this->Cache_Delete("user_login_{$oUser->getLogin()}");
 *        // Удалем кеш со списком всех пользователей
 *        $this->Cache_Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array('user_update'));
 *        // Обновлем пользовател в базе данных
 *        return $this->oMapper->UpdateUser($oUser);
 *    }
 *
 *    // Получает список всех пользователей
 *    public function GetUsers() {
 *        // Пытаемся получить значение из кеша
 *        if (false === ($aUserList = $this->Cache_Get("users"))) {
 *            // Если значение из кеша получить не удалось, то обращаемся к базе данных
 *            $aUserList = $this->oMapper->GetUsers();
 *            // Записываем значение в кеш
 *            $this->Cache_Set($aUserList, "users", array('user_update'), 60*60*24*5);
 *        }
 *        return $aUserList;
 *    }
 * </pre>
 *
 * @package engine.modules
 * @since   1.0
 */
class ModuleCache extends Module {

    const CACHE_MODE_NONE       = 0; // кеширование отключено
    const CACHE_MODE_AUTO       = 1; // включено автокеширование
    const CACHE_MODE_REQUEST    = 2; // кеширование только по запросу
    const CACHE_MODE_FORCE      = 4; // только принудительное кеширование

    /**
     * Доступные механизмы кеширования
     *
     * @var array
     */
    protected $aCacheTypesAvailable = array();

    /**
     * Доступные механизмы принудительного кеширования
     *
     * @var array
     */
    protected $aCacheTypesForce = array();

    /**
     * Объект бэкенда кеширования / LS-compatible /
     *
     * @var Zend_Cache_Backend
     */
    protected $oBackendCache = null;

    /**
     * Массив объектов движков кеширования
     *
     * @var array
     */
    protected $aBackends = array();

    /**
     * Используется кеширование или нет
     *
     * @var bool
     */
    protected $bUseCache;
    /**
     * Тип кеширования, прописан в глобльном конфиге config.php
     *
     * @var string
     */
    protected $sCacheType;

    protected $nCacheMode = self::CACHE_MODE_AUTO;

    /**
     * Статистика кеширования
     *
     * @var array
     */
    protected $aStats
        = array(
            'time'      => 0,
            'count'     => 0,
            'count_get' => 0,
            'count_set' => 0,
        );
    /**
     * Хранилище для кеша на время сессии
     * @see SetLife
     * @see GetLife
     *
     * @var array
     */
    protected $aStoreLife = array();
    /**
     * Префикс для "умного" кеширования
     * @see SmartSet
     * @see SmartGet
     *
     * @var string
     */
    protected $sPrefixSmartCache = 'for-smart-cache-';

    /**
     * Коэффициент вероятности удаления старого кеша
     *
     * "@see Init
     *
     * @var int
     */
    protected $nRandClearOld = 50;

    /**
     * Инициализируем нужный тип кеша
     *
     */
    public function Init() {

        $this->bUseCache = Config::Get('sys.cache.use');
        $this->sCacheType = Config::Get('sys.cache.type');

        $aCacheTypes = (array)Config::Get('sys.cache.backends');
        // Доступные механизмы кеширования
        $this->aCacheTypesAvailable = array_map('strtolower', array_keys($aCacheTypes));
        // Механизмы принудительного кеширования
        $this->aCacheTypesForce = (array)Config::Get('sys.cache.force');
        if ($this->aCacheTypesForce === true) {
            // Разрешены все
            $this->aCacheTypesForce = $this->aCacheTypesAvailable;
        } else {
            // Разрешены только те, которые есть в списке доступных
            $this->aCacheTypesForce = array_intersect(
                array_map('strtolower', $this->aCacheTypesForce), $this->aCacheTypesAvailable
            );
        }

        // По умолчанию кеширование данных полностью отключено
        $this->nCacheMode = self::CACHE_MODE_NONE;
        if ($this->_backendIsAvailable($this->sCacheType)) {
            if ($this->bUseCache) {
                // Включено автокеширование
                $this->nCacheMode = $this->nCacheMode | self::CACHE_MODE_AUTO | self::CACHE_MODE_REQUEST;
            } else {
                // Включено кеширование по запросу
                $this->nCacheMode = $this->nCacheMode | self::CACHE_MODE_REQUEST;
            }
            // Инициализация механизма кеширования по умолчанию
            $this->_backendInit($this->sCacheType);
        }
        if ($this->aCacheTypesForce) {
            // Разрешено принудительное кеширование
            $this->nCacheMode = $this->nCacheMode | self::CACHE_MODE_FORCE;
        }
        if ($this->nCacheMode != self::CACHE_MODE_NONE) {
            // Дабы не засорять место протухшим кешем, удаляем его в случайном порядке, например 1 из 50 раз
            if (rand(1, $this->nRandClearOld) == 33) {
                $this->Clean(Zend_Cache::CLEANING_MODE_OLD);
            }
        }
        return $this->nCacheMode;
    }

    /**
     * Проверка режима кеширования
     *
     * @param   string|null $sCacheType
     *
     * @return   int
     */
    protected function _cacheOn($sCacheType = null) {

        if (is_null($sCacheType)) {
            return $this->nCacheMode & self::CACHE_MODE_AUTO;
        } elseif ($sCacheType === true) {
            return $this->nCacheMode & self::CACHE_MODE_REQUEST;
        } elseif (in_array($sCacheType, $this->aCacheTypesForce)) {
            return $this->nCacheMode & self::CACHE_MODE_FORCE;
        }
        return self::CACHE_MODE_NONE;
    }

    /**
     * Инициализация бэкенда кеширования
     *
     * @param $sCacheType
     *
     * @return Dklab_Cache_Backend_Profiler|Dklab_Cache_Backend_TagEmuWrapper
     * @throws Exception
     */
    protected function _backendInit($sCacheType) {

        if (is_string($sCacheType)) {
            $sCacheType = strtolower($sCacheType);
        } elseif ($sCacheType === true || is_null($sCacheType)) {
            $sCacheType = $this->sCacheType;
        }
        if ($sCacheType) {
            if (!isset($this->aBackends[$sCacheType])) {
                if (!in_array($sCacheType, $this->aCacheTypesAvailable)) {
                    /*
                     * Неизвестный тип кеша
                     */
                    throw new Exception('Wrong type of caching: ' . $this->sCacheType);
                } else {
                    $aCacheTypes = (array)Config::Get('sys.cache.backends');
                    $sClass = 'CacheBackend' . $aCacheTypes[$sCacheType];
                    $sFile = './backend/' . $sClass . '.class.php';
                    if (!F::IncludeFile($sFile)) {
                        throw new Exception('Cannot include cache backend file: ' . basename($sFile));
                    } elseif (!class_exists($sClass, false)) {
                        throw new Exception('Cannot load cache backend class: ' . $sClass);
                    } else {
                        if (!$sClass::IsAvailable() || !($oBackendCache = $sClass::Init(array($this, 'CalcStats')))) {
                            throw new Exception('Cannot use cache type: ' . $sCacheType);
                        } else {
                            $this->aBackends[$sCacheType] = $oBackendCache;
                            //* LS-compatible *//
                            if ($sCacheType == $this->sCacheType) {
                                $this->oBackendCache = $oBackendCache;
                            }
                            $oBackendCache = null;
                            return $sCacheType;
                        }
                    }
                }
            } else {
                return $sCacheType;
            }
        }
    }

    protected function _backendIsAvailable($sCacheType) {

        if (is_null($sCacheType) || $sCacheType === true) {
            $sCacheType = $this->sCacheType;
        }
        return $sCacheType && in_array($sCacheType, $this->aCacheTypesAvailable);
    }

    protected function _backendIsMultiLoad($sCacheType) {

        if ($sCacheType = $this->_backendInit($sCacheType)) {
            return $this->aBackends[$sCacheType]->IsMultiLoad();
        }
    }

    protected function _backendIsConcurent($sCacheType) {

        if (is_null($sCacheType) || $sCacheType === true) {
            $sCacheType = $this->sCacheType;
        }
        if ($this->_backendInit($sCacheType) && Config::Get('sys.cache.concurrent_delay')) {
            return intval(Config::Get('sys.cache.concurrent_delay'));
        }
        return 0;
    }

    /**
     * Внутренний метод получения данных из конкретного вида кеша
     *
     * @param   string  $sCacheType
     * @param   string  $sHash
     *
     * @return  bool|mixed
     */
    protected function _backendLoad($sCacheType, $sHash) {

        if ($sCacheType = $this->_backendInit($sCacheType)) {
            return $this->aBackends[$sCacheType]->Load($sHash);
        }
        return false;
    }

    /**
     * Внутренний метод сохранения данных в конкретном виде кеша
     *
     * @param $sCacheType
     * @param $data
     * @param $sHash
     * @param $aTags
     * @param $nTimeLife
     *
     * @return bool
     */
    protected function _backendSave($sCacheType, $data, $sHash, $aTags, $nTimeLife) {

        if ($sCacheType = $this->_backendInit($sCacheType)) {
            return $this->aBackends[$sCacheType]->Save($data, $sHash, $aTags, $nTimeLife ? $nTimeLife : false);
        }
        return false;
    }

    /**
     * Внутренний метод сброса кеша по коючу
     *
     * @param $sCacheType
     * @param $sHash
     *
     * @return bool
     */
    protected function _backendRemove($sCacheType, $sHash) {

        // Если тип кеша задан, то сбрасываем у него
        if ($sCacheType && isset($this->aBackends[$sCacheType])) {
            return $this->aBackends[$sCacheType]->Remove($sHash);
        } else {
            // Иначе сбрасываем у всех типов кеша
            foreach ($this->aBackends as $oBackend) {
                $oBackend->Remove($sHash);
            }
            return true;
        }
        return false;
    }

    /**
     * Internal method for clearing of cache
     *
     * @param $sCacheType
     * @param $sMode
     * @param $aTags
     *
     * @return bool
     */
    protected function _backendClean($sCacheType, $sMode, $aTags) {

        // Если тип кеша задан, то сбрасываем у него
        if ($sCacheType && isset($this->aBackends[$sCacheType])) {
            return $this->aBackends[$sCacheType]->Clean($sMode, $aTags);
        } else {
            // Иначе сбрасываем у всех типов кеша
            foreach ($this->aBackends as $oBackend) {
                $oBackend->Clean($sMode, $aTags);
            }
            return true;
        }
        return false;
    }

    /**
     * Хеширование имени кеш-ключа
     *
     * @param $sKey
     *
     * @return string
     */
    protected function _hash($sKey) {

        return md5(Config::Get('sys.cache.prefix') . $sKey);
    }

    public function CacheTypeAvailable($sCacheType) {

        return $this->_backendIsAvailable($sCacheType);
    }

    /**
     * Записать значение в кеш
     *
     * The following life time periods are recognized:
     * <pre>
     * Time interval    | Number of seconds
     * ----------------------------------------------------
     * 3600             | 3600 seconds
     * 2 hours          | Two hours = 60 * 60 * 2 = 7200 seconds
     * 1 day + 12 hours | One day and 12 hours = 60 * 60 * 24 + 60 * 60 * 12 = 129600 seconds
     * 3 months         | Three months = 3 * 30 days = 3 * (60 * 60 * 24 * 30) = 7776000 seconds
     * PT3600S          | 3600 seconds
     * P1DT12H          | One day and 12 hours = 60 * 60 * 24 + 60 * 60 * 12 = 129600 seconds
     * P3M              | Three months = 3 * 30 days = 3 * (60 * 60 * 24 * 30) = 7776000 seconds
     * ----------------------------------------------------
     * Full ISO 8601 interval format: PnYnMnDTnHnMnS
     * </pre>
     *
     * @param   mixed               $xData      - Данные для хранения в кеше
     * @param   string              $sCacheKey  - Имя ключа кеширования
     * @param   array               $aTags      - Список тегов, для возможности удалять сразу несколько кешей по тегу
     * @param   string|int|bool     $nTimeLife  - Время жизни кеша (в секундах или в строковом интервале)
     * @param   string|bool|null    $sCacheType - Тип используемого кеша
     *
     * @return  bool
     */
    public function Set($xData, $sCacheKey, $aTags = array(), $nTimeLife = false, $sCacheType = null) {

        // Проверяем возможность кеширования
        $nMode = $this->_cacheOn($sCacheType);
        if (!$nMode) {
            return false;
        }

        // Если модуль завершил свою работу и не включено принудительное кеширование, то ничего не кешируется
        if ($this->isDone() && ($nMode != self::CACHE_MODE_FORCE)) {
            return false;
        }

        // Теги - это массив строковых значений
        if (!is_array($aTags)) {
            if (!$aTags || !is_string($aTags)) {
                $aTags = array();
            } else {
                $aTags = array((string)$aTags);
            }
        }
        if (is_string($nTimeLife)) {
            $nTimeLife = F::ToSeconds($nTimeLife);
        } else {
            $nTimeLife = intval($nTimeLife);
        }
        if (!$sCacheType) {
            $sCacheType = $this->sCacheType;
        }

        // Если необходимо разрешение конкурирующих запросов к кешу, то реальное время жизни кеша увеличиваем
        if ($nTimeLife && ($nConcurentDaley = $this->_backendIsConcurent($sCacheType))) {
            $aData = array(
                'time' => time() + $nTimeLife,  // контрольное время жизни кеша
                'tags' => $aTags,               // теги, чтобы можно было пересохранить данные
                'data' => $xData,               // сами данные
            );
            $nTimeLife += $nConcurentDaley;
        } else {
            $aData = array(
                'time' => false,   // контрольное время не сохраняем, конкурирующие запросу к кешу игнорируем
                'tags' => $aTags,
                'data' => $xData,
            );
        }
        return $this->_backendSave($sCacheType, $aData, $this->_hash($sCacheKey), $aTags, $nTimeLife);
    }

    /**
     * Получить значение из кеша
     *
     * @param   string      $sCacheKey  - Имя ключа кеширования
     * @param   string|null $sCacheType - Механизм используемого кеширования
     *
     * @return mixed|bool
     */
    public function Get($sCacheKey, $sCacheType = null) {

        // Проверяем возможность кеширования
        if (!$this->_cacheOn($sCacheType)) {
            return false;
        }

        if (!is_array($sCacheKey)) {
            $aData = $this->_backendLoad($sCacheType, $this->_hash($sCacheKey));
            if (is_array($aData) && array_key_exists('data', $aData)) {
                // Если необходимо разрешение конкурирующих запросов...
                if (isset($aData['time']) && ($nConcurentDaley = $this->_backendIsConcurent($sCacheType))) {
                    if ($aData['time'] < time()) {
                        // Если данные кеша по факту "протухли", то пересохраняем их с доп.задержкой и без метки времени
                        // За время задержки кеш должен пополниться свежими данными
                        $aData['time'] = false;
                        $this->_backendSave($sCacheType, $aData, $this->_hash($sCacheKey), $aData['tags'], $nConcurentDaley);
                        return false;
                    }
                }
                return $aData['data'];
            }
        } else {
            return $this->multiGet($sCacheKey, $sCacheType);
        }
        return false;
    }

    /**
     * Поддержка мульти-запросов к кешу
     *
     * Если движок кеша не поддерживает такие запросы, то делаем эмуляцию
     *
     * @param   array   $aCacheKeys     - Массив ключей кеширования
     * @param   string  $sCacheType     - Тип кеша
     *
     * @return bool|array
     */
    public function MultiGet($aCacheKeys, $sCacheType = null) {

        if (count($aCacheKeys) == 0 || !$this->_cacheOn($sCacheType)) {
            return false;
        }
        if ($this->_backendIsMultiLoad($sCacheType)) {
            $aHashKeys = array();
            $aTmpKeys = array();
            foreach ($aCacheKeys as $sCacheKey) {
                $sHash = $this->_hash($sCacheKey);
                $aHashKeys[] = $sHash;
                $aTmpKeys[$sHash] = $sCacheKey;
            }
            $data = $this->_backendLoad($sCacheType, $aHashKeys);
            if ($data && is_array($data)) {
                $aData = array();
                foreach ($data as $key => $value) {
                    $aData[$aTmpKeys[$key]] = $value;
                }
                if (count($aData) > 0) {
                    return $aData;
                }
            }
            return false;
        } else {
            $aData = array();
            foreach ($aCacheKeys as $sCacheKey) {
                if ((false !== ($data = $this->Get($sCacheKey, $sCacheType)))) {
                    $aData[$sCacheKey] = $data;
                }
            }
            if (count($aData) > 0) {
                return $aData;
            }
            return false;
        }
    }

    /**
     * LS-compatible
     *
     * @param   mixed           $data       - Данные для хранения в кеше
     * @param   string          $sCacheKey  - Имя ключа кеширования
     * @param   array           $aTags      - Список тегов, для возможности удалять сразу несколько кешей по тегу
     * @param   string|int|bool $nTimeLife  - Время жизни кеша (в секундах или в строковом интервале)
     *
     * @return  bool
     */
    public function SmartSet($data, $sCacheKey, $aTags = array(), $nTimeLife = false) {

        return $this->Set($data, $sCacheKey, $aTags, $nTimeLife);
    }

    /**
     * LS-compatible
     *
     * @param   string      $sCacheKey      - Имя ключа
     * @param   string|null $sCacheType     - Механизм используемого кеширования
     *
     * @return  bool|mixed
     */
    public function SmartGet($sCacheKey, $sCacheType = null) {

        return $this->Get($sCacheKey, $sCacheType);
    }

    /**
     * Delete cache value by its key
     *
     * @param string      $sCacheKey    - Name of cache key
     * @param string|null $sCacheType   - Type of cache (if null then clear in all cache types)
     *
     * @return bool
     */
    public function Delete($sCacheKey, $sCacheType = null) {

        if (!$this->bUseCache) {
            return false;
        }
        if (is_array($sCacheKey)) {
            foreach ($sCacheKey as $sItemName) {
                $this->_backendRemove($sCacheType, $this->_hash($sItemName));
            }
            return true;
        } else {
            return $this->_backendRemove($sCacheType, $this->_hash($sCacheKey));
        }
    }

    /**
     * Clear cache
     *
     * @param   string    $sMode
     * @param   array     $aTags
     * @param string|null $sCacheType - Type of cache (if null then clear in all cache types)
     *
     * @return  bool
     */
    public function Clean($sMode = Zend_Cache::CLEANING_MODE_ALL, $aTags = array(), $sCacheType = null) {

        if (!$this->bUseCache) {
            return false;
        }
        return $this->_backendClean($sCacheType, $sMode, $aTags);
    }

    /**
     * Clear cache by tags
     *
     * @param array       $aTags      - Array of tags
     * @param string|null $sCacheType - Type of cache (if null then clear in all cache types)
     *
     * @return bool
     */
    public function CleanByTags($aTags, $sCacheType = null) {

        if (!is_array($aTags)) {
            $aTags = array((string)$aTags);
        }
        return $this->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, $aTags, $sCacheType);
    }

    /**
     * Подсчет статистики использования кеша
     *
     * @param int    $iTime      - Время выполнения метода
     * @param string $sMethod    - Имя метода
     */
    public function CalcStats($iTime, $sMethod) {

        $this->aStats['time'] += $iTime;
        $this->aStats['count']++;
        if ($sMethod == 'Dklab_Cache_Backend_Profiler::load') {
            $this->aStats['count_get']++;
        }
        if ($sMethod == 'Dklab_Cache_Backend_Profiler::save') {
            $this->aStats['count_set']++;
        }
    }

    /**
     * Возвращает статистику использования кеша
     *
     * @return array
     */
    public function GetStats() {
        return $this->aStats;
    }

    /**
     * LS-compatible
     * Сохраняет значение в кеше на время исполнения скрипта(сессии), некий аналог Registry
     *
     * @param mixed  $data         - Данные для сохранения в кеше
     * @param string $sCacheKey    - Имя ключа кеширования
     */
    public function SetLife($data, $sCacheKey) {

        $this->Set($data, $sCacheKey, array(), false, 'tmp');
    }

    /**
     * LS-compatible
     * Получает значение из текущего кеша сессии
     *
     * @param string $sCacheKey    - Имя ключа кеширования
     *
     * @return mixed
     */
    public function GetLife($sCacheKey) {

        return $this->Get($sCacheKey, 'tmp');
    }
}

// EOF