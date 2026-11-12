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

        // Shopee Order ID check (v1.12.1). Customers kept registering their SPX
        // tracking number, which was only caught at review. The rule and the copy
        // ride on the marketplace <option> (data-pattern and friends, from
        // GWARR_Marketplaces), so this never drifts from the server check, which
        // stays the real gate. setCustomValidity lets the submit handler below
        // refuse the form until the number is fixed.
        var checkOrderNumber = null;
        if ($marketplace.length && $orderInput.length) {
            var orderEl = $orderInput[0];
            var $orderError = $('<span class="gwarr-field-error" id="gwarr-order-error" role="alert" hidden></span>');
            $orderInput.after($orderError);

            // Same normalising as GWARR_Marketplaces::normalise_order_number(): every
            // \s character out, a leading # off, and ASCII-only upper-casing like PHP's
            // strtoupper (toUpperCase would also turn the dotless i into I).
            var normalisedOrder = function () {
                return orderEl.value.replace(/\s+/g, '').replace(/^#+/, '')
                    .replace(/[a-z]/g, function (c) { return c.toUpperCase(); });
            };
            var orderProblem = function () {
                var $opt = $marketplace.find(':selected');
                var pattern = $opt.attr('data-pattern');
                var v = normalisedOrder();
                // An empty field is left to "required" and the server's own message.
                // Empty means what the server trims away (ASCII spaces only): a lone #
                // or a lone non-breaking space gets the format message there, so here too.
                if (!pattern || /^[ \t\n\r\0\x0B]*$/.test(orderEl.value)) return '';
                var prefix = $opt.attr('data-tracking-prefix') || '';
                if (prefix && v.indexOf(prefix) === 0) return $opt.attr('data-msg-tracking') || '';
                var bad = $opt.attr('data-msg-format') || '';
                if (!new RegExp(pattern).test(v)) return bad;
                var maxDate = $opt.attr('data-max-date');
                if (maxDate) {
                    // First 6 digits: a real YYMMDD date, not after tomorrow in KL.
                    var y = 2000 + parseInt(v.substr(0, 2), 10);
                    var m = parseInt(v.substr(2, 2), 10);
                    var d = parseInt(v.substr(4, 2), 10);
                    var dt = new Date(Date.UTC(y, m - 1, d));
                    var real = dt.getUTCFullYear() === y && dt.getUTCMonth() === m - 1 && dt.getUTCDate() === d;
                    var iso = y + '-' + (m < 10 ? '0' : '') + m + '-' + (d < 10 ? '0' : '') + d;
                    if (!real || iso > maxDate) return bad;
                }
                return '';
            };

            // eager: show the message now (blur, marketplace change, submit). While
            // typing, wait until the number looks complete (14 or more characters)
            // or is an SPX number, so nobody is told off for the first digit.
            checkOrderNumber = function (eager) {
                var msg = orderProblem();
                orderEl.setCustomValidity(msg);
                if (!msg) {
                    $orderError.text('').prop('hidden', true);
                    $orderInput.removeAttr('aria-invalid aria-describedby');
                    return;
                }
                var tracking = msg === $marketplace.find(':selected').attr('data-msg-tracking');
                if (eager || tracking || !$orderError.prop('hidden') || normalisedOrder().length >= 14) {
                    $orderError.text(msg).prop('hidden', false);
                    $orderInput.attr({ 'aria-invalid': 'true', 'aria-describedby': 'gwarr-order-error' });
                }
            };
            $orderInput.on('input', function () { checkOrderNumber(false); });
            $orderInput.on('blur', function () { checkOrderNumber(true); });
            $marketplace.on('change', function () { checkOrderNumber(true); });
            checkOrderNumber(false);
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
                if (checkOrderNumber) checkOrderNumber(true);

                // The form is novalidate, but honour required fields and the order
                // number check so the overlay never shows for a submit that cannot
                // go through. novalidate means the browser would post it anyway,
                // so stop it here.
                if (typeof formEl.checkValidity === 'function' && !formEl.checkValidity()) {
                    e.preventDefault();
                    if (typeof formEl.reportValidity === 'function') {
                        formEl.reportValidity();
                    }
                    return;
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
