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
    });
})(jQuery);
