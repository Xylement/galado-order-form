(function ($) {
    'use strict';

    $(function () {
        // Trim whitespace on the order number so the auto-approve lookup (Phase 2)
        // doesn't miss matches because of leading/trailing spaces from copy-paste.
        $('.gwarr-form input[name="order_number"]').on('blur', function () {
            this.value = this.value.trim();
        });

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
