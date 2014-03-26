/**
 * Топик
 */

var ls = ls || {};

ls.topic = (function ($) {
    "use strict";

    /**
     * Дефолтные опции
     */
    var defaults = {
        // Роутеры
        routers: {
            preview: ls.routerUrl('ajax') + 'preview/topic/'
        },

        // Селекторы
        selectors: {
            previewPlace: '.js-topic-preview-place',
            previewImage: '.js-topic-preview-image',
            previewImageLoader: '.js-topic-preview-loader',
            previewTopicTextButton: '.js-topic-preview-text-button',
            previewTopicTextHideButton: '.js-topic-preview-text-hide-button',
            addTopicTitle: '.js-topic-add-title'
        }
    };

    /**
     * Инициализация
     *
     * @param  {Object} options Опции
     */
    this.init = function (options) {
        var self = this;

        this.options = $.extend({}, defaults, options);

        // Подгрузка избражений-превью
        $(this.options.selectors.previewImage).each(function () {
            $(this).imagesLoaded(function () {
                var $this = $(this),
                    $preview = $this.closest(self.options.selectors.previewImageLoader).removeClass('loading');

                $this.height() < $preview.height() && $this.css('top', ($preview.height() - $this.height()) / 2);
            });
        });

        // Превью текста
        $(this.options.selectors.previewTopicTextButton).on('click', function (e) {
            self.showPreviewText(this, self.options.selectors.previewPlace);
        });

        // Закрытие превью текста
        $(document).on('click', this.options.selectors.previewTopicTextHideButton, function (e) {
            self.hidePreviewText();
        });

        // Подгрузка информации о выбранном блоге при создании топика
        $(this.options.selectors.addTopicTitle).on('change', function (e) {
            ls.blog.loadInfo($(this).val());
        });
    };

    /**
     * Превью текста
     *
     * @param form
     * @param previewPlace
     */
    this.showPreviewText = function (form, previewPlace) {
        if (form) {
            form = $(form);
        }
        if (!form.is('form')) {
            form = form.parents('form').first();
        }
        previewPlace = $(previewPlace);

        if (form.length && previewPlace.length) {
            ls.progressStart();
            ls.ajaxSubmit(this.options.routers.preview, form, function (result) {
                ls.progressDone();
                if (!result) {
                    ls.msg.error(null, 'System error #1001');
                } else if (result.bStateError) {
                    ls.msg.error(null, result.sMsg);
                } else {
                    previewPlace.html(result.sText).show();

                    ls.hook.run('ls_topic_preview_after', [form, previewPlace, result]);
                }
            });
        }
    };

    /**
     * Закрытие превью
     */
    this.hidePreviewText = function () {
        $('#topic-text-preview').hide();
    };

    $(function(){
        ls.topic.init();
    });

    return this;
}).call(ls.topic || {}, jQuery);