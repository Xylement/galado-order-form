(function ($) {
    'use strict';

    $(function () {
        // Trim whitespace on the order number so the auto-approve lookup (Phase 2)
        // doesn't miss matches because of leading/trailing spaces from copy-paste.
        $('.gwarr-form input[name="order_number"]').on('blur', function () {
            this.value = this.value.trim();
        });

        // Swap the order-number placeholder to match the selected marketplace —
        // each marketplace has a distinct ID shape, so a shared placeholder
        // would mislead the customer.
        var $marketplace = $('.gwarr-form select[name="marketplace"]');
        var $orderInput  = $('.gwarr-form input[name="order_number"]');
        if ($marketplace.length && $orderInput.length) {
            var updateOrderPlaceholder = function () {
                var example = $marketplace.find(':selected').attr('data-example');
                $orderInput.attr(
                    'placeholder',
                    example ? 'e.g. ' + example : 'Order number'
                );
            };
            $marketplace.on('change', updateOrderPlaceholder);
            updateOrderPlaceholder();
        }

        // Copy-coupon-to-clipboard convenience on the My Warranties view.
        $(document).on('click', '.gwarr-coupon-code', function () {
            var code = $(this).text().trim();
            if (!code || !navigator.clipboard) return;
            navigator.clipboard.writeText(code).then(function () {
                // Lightweight visual confirmation — no toast lib.
                var el = $('.gwarr-coupon-code:contains(' + code + ')');
                var orig = el.text();
                el.text('Copied!');
                setTimeout(function () { el.text(orig); }, 1200);
            }).catch(function () { /* clipboard blocked — silent */ });
        });

        // ---- Auth modal (AJAX login + register) -------------------------------
        var $modal = $('#gwarr-auth-modal');
        if (!$modal.length) return;

        function openModal(initialTab) {
            $modal.removeAttr('hidden').attr('aria-hidden', 'false');
            switchTab(initialTab || 'login');
            document.body.style.overflow = 'hidden';
            // focus the first input for keyboard users
            setTimeout(function () {
                $modal.find('.gwarr-modal-form.is-active input:not([type=hidden]):first').trigger('focus');
            }, 50);
        }

        function closeModal() {
            $modal.attr('hidden', '').attr('aria-hidden', 'true');
            $modal.find('.gwarr-modal-error').text('');
            document.body.style.overflow = '';
        }

        function switchTab(tab) {
            $modal.find('.gwarr-modal-tab').each(function () {
                var on = $(this).data('gwarr-tab') === tab;
                $(this).toggleClass('is-active', on).attr('aria-selected', on ? 'true' : 'false');
            });
            $modal.find('.gwarr-modal-form').each(function () {
                var on = $(this).data('gwarr-form') === tab;
                $(this).toggleClass('is-active', on);
                if (on) $(this).removeAttr('hidden'); else $(this).attr('hidden', '');
            });
            $modal.find('.gwarr-modal-title').text(tab === 'register' ? 'Create your GALADO account' : 'Log in to GALADO');
        }

        $(document).on('click', '[data-gwarr-auth]', function (e) {
            e.preventDefault();
            openModal($(this).data('gwarr-auth'));
        });
        $modal.on('click', '[data-gwarr-modal-close]', closeModal);
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && !$modal.attr('hidden')) closeModal();
        });
        $modal.on('click', '.gwarr-modal-tab', function () {
            switchTab($(this).data('gwarr-tab'));
        });

        $modal.on('submit', '.gwarr-modal-form', function (e) {
            e.preventDefault();
            var $form = $(this);
            var formKind = $form.data('gwarr-form'); // 'login' or 'register'
            var $err = $form.find('.gwarr-modal-error');
            var $submit = $form.find('.gwarr-modal-submit');

            if (typeof gwarrAuth === 'undefined') {
                $err.text('Configuration error — please refresh the page.');
                return;
            }

            $err.text('');
            $submit.prop('disabled', true).data('orig-text', $submit.text()).text(formKind === 'login' ? 'Logging in…' : 'Creating account…');

            var data = $form.serialize() +
                '&action=gwarr_' + formKind +
                '&nonce=' + encodeURIComponent(gwarrAuth.nonce);

            $.post(gwarrAuth.ajaxurl, data)
                .done(function (resp) {
                    if (resp && resp.success) {
                        // Reload so the (now-authenticated) shortcode renders the form.
                        window.location.reload();
                    } else {
                        var msg = (resp && resp.data && resp.data.message) || 'Something went wrong. Please try again.';
                        $err.text(msg);
                        $submit.prop('disabled', false).text($submit.data('orig-text'));
                    }
                })
                .fail(function () {
                    $err.text('Server error. Please try again in a moment.');
                    $submit.prop('disabled', false).text($submit.data('orig-text'));
                });
        });
    });
})(jQuery);
