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

        // Processing overlay — the registration form does a full POST → server
        // work (Club webhook, sheet auto-approve, emails) → redirect. That wait
        // can take a few seconds and otherwise looks frozen, so show an overlay
        // with cycling status messages the moment a valid submit starts. We do
        // NOT preventDefault: the native POST proceeds and the redirect tears
        // the overlay down when the result page loads.
        var $regForm = $('.gwarr-form');
        var $overlay = $('#gwarr-processing');
        if ($regForm.length && $overlay.length) {
            var stepEl = document.getElementById('gwarr-processing-step');
            var fillEl = document.getElementById('gwarr-progress-fill');
            var stepTimer = null;
            var submitting = false;
            // Slower, calmer cadence — registration can take up to a couple of
            // minutes, so the copy reassures rather than implying it's stuck.
            var steps = [
                'Sending your details securely',
                'Verifying your order against our records',
                'This can take a minute or two, hang tight',
                'Setting up your warranty coverage',
                'Preparing your welcome coupon',
                'Almost there, finalising your registration'
            ];

            $regForm.on('submit', function (e) {
                var formEl = this;

                // The form is novalidate, but honour required fields so the
                // overlay never shows for a submit the browser will reject.
                if (typeof formEl.checkValidity === 'function' && !formEl.checkValidity()) {
                    if (typeof formEl.reportValidity === 'function') {
                        formEl.reportValidity();
                    }
                    return; // let native validation handle it; no overlay
                }

                // Guard against double submit with a flag — NOT by disabling the
                // submit button. The button is <button name="gwarr_submit"> and
                // the server gates on $_POST['gwarr_submit']; a disabled control
                // is excluded from the POST, so disabling it here would drop the
                // gate value and the registration would silently do nothing.
                if (submitting) {
                    e.preventDefault();
                    return;
                }
                submitting = true;

                $overlay.removeAttr('hidden').attr('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';

                // Estimated progress bar. We can't get real progress from a
                // synchronous POST, so ease toward ~95% over ~110s (the slow
                // end of the observed 1–2 min wait): it shoots up early then
                // crawls, so it never looks finished-but-frozen. The redirect
                // to My Warranties tears the overlay down whenever the real
                // work actually completes — fast OR slow, the bar adapts.
                if (fillEl) {
                    fillEl.style.transition = 'none';
                    fillEl.style.width = '0%';
                    // force reflow so the 0% sticks before we animate
                    void fillEl.offsetWidth;
                    fillEl.style.transition = 'width 110s cubic-bezier(0.05, 0.7, 0.05, 1)';
                    fillEl.style.width = '95%';
                }

                // Cycle the status line, slower so it reads as calm progress.
                if (stepEl) {
                    var i = 0;
                    stepTimer = setInterval(function () {
                        i = (i + 1) % steps.length;
                        stepEl.style.opacity = '0';
                        setTimeout(function () {
                            stepEl.textContent = steps[i];
                            stepEl.style.opacity = '1';
                        }, 260);
                    }, 4500);
                }

                // Let the native POST proceed (no preventDefault) — the button's
                // name/value stays in the payload because it isn't disabled.
            });

            // If the customer comes back via the browser's back button (bfcache),
            // the overlay can be left visible — hide it and reset the guard.
            window.addEventListener('pageshow', function () {
                if (stepTimer) { clearInterval(stepTimer); stepTimer = null; }
                submitting = false;
                if (fillEl) { fillEl.style.transition = 'none'; fillEl.style.width = '0%'; }
                $overlay.attr('hidden', 'hidden').attr('aria-hidden', 'true');
                document.body.style.overflow = '';
            });
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
                $err.text('Configuration error. Please refresh the page.');
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
