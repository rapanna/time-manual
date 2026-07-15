/**
 * Time Manual – nástěnkový widget: klik na položku načte obsah do modalu.
 * Oprávnění se ověřuje na serveru (nonce + obě vrstvy) – tady jen zobrazení.
 */
(function ($) {
    'use strict';

    var $modal, $title, $body;

    function openModal() {
        $modal.attr('aria-hidden', 'false').addClass('is-open');
        $('body').addClass('tman-modal-open');
    }

    function closeModal() {
        $modal.attr('aria-hidden', 'true').removeClass('is-open');
        $('body').removeClass('tman-modal-open');
        $body.empty();
        $title.text('');
    }

    function loadManual(id) {
        $title.text('');
        $body.html('<p class="tman-loading">' + tmanData.loading + '</p>');
        openModal();

        $.post(tmanData.ajaxUrl, {
            action: 'tman_get_manual',
            nonce: tmanData.nonce,
            id: id
        }).done(function (res) {
            if (res && res.success) {
                $title.text(res.data.title);
                $body.html(res.data.content);
            } else {
                var msg = (res && res.data && res.data.message) ? res.data.message : tmanData.error;
                $body.html('<p class="tman-error"></p>');
                $body.find('.tman-error').text(msg);
            }
        }).fail(function () {
            $body.html('<p class="tman-error"></p>');
            $body.find('.tman-error').text(tmanData.error);
        });
    }

    $(function () {
        $modal = $('#tman-modal');
        $title = $modal.find('.tman-modal__title');
        $body = $modal.find('.tman-modal__body');

        $(document).on('click', '.tman-manual-link', function (e) {
            e.preventDefault();
            loadManual($(this).data('id'));
        });

        $modal.on('click', '[data-tman-close]', function (e) {
            e.preventDefault();
            closeModal();
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $modal.hasClass('is-open')) {
                closeModal();
            }
        });
    });
})(jQuery);
