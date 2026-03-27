/**
 * GALADO Live Case Preview — Frontend Script
 * Shows a dedicated mockup image with the customer's name overlaid in real-time.
 * Completely independent of the WooCommerce product gallery.
 */
(function($) {
    'use strict';

    if (typeof gcpConfig === 'undefined' || !gcpConfig.enabled) return;

    var overlay      = null;
    var currentFont  = '';
    var currentText  = '';
    var currentColor = 'black';
    var baseFontSize = parseInt(gcpConfig.fontSize) || 28;
    var maxWidthPct  = parseInt(gcpConfig.maxWidth)  || 60;

    $(document).ready(function() {
        overlay = document.getElementById('gcp-overlay-text');
        if (!overlay) return;

        // Position overlay within the preview widget (simple % positioning)
        overlay.style.left      = gcpConfig.x + '%';
        overlay.style.top       = gcpConfig.y + '%';
        overlay.style.transform = 'translate(-50%, -50%) rotate(' + (gcpConfig.rotate || 0) + 'deg)';
        overlay.style.maxWidth  = maxWidthPct + '%';

        bindTextInput();
        bindFontCards();
        bindColourSelector();
    });

    /**
     * Bind to the personalisation text input
     */
    function bindTextInput() {
        var input = document.getElementById('galado-fp-text');
        if (!input) input = document.querySelector('.wc-pao-addon-field[type="text"]');
        if (!input) input = document.querySelector('input[name*="custom_text"], input[name*="personalise"], input[name*="personalize"], input[name*="name"]');
        if (!input) return;

        input.addEventListener('input', function() {
            currentText = this.value.trim();
            updateOverlay();
        });

        if (input.value.trim()) {
            currentText = input.value.trim();
            updateOverlay();
        }
    }

    /**
     * Bind to font card clicks
     */
    function bindFontCards() {
        $(document).on('click', '.galado-fp-card', function() {
            var fontSlug = $(this).data('font-slug') || $(this).data('font');
            $('.galado-fp-card').removeClass('gcp-active');
            $(this).addClass('gcp-active');
            if (fontSlug) {
                currentFont = fontSlug;
                updateOverlay();
            }
        });

        $(document).on('change', 'input[name="galado_font_style"]', function() {
            var fontSlug = $(this).data('font-slug') || $(this).val();
            if (fontSlug) {
                currentFont = sanitizeSlug(fontSlug);
                updateOverlay();
            }
        });
    }

    /**
     * Bind to colour selector
     */
    function bindColourSelector() {
        $(document).on('click', '.galado-fp-color-btn, [data-text-color]', function() {
            currentColor = $(this).data('text-color') || $(this).data('color') || 'black';
            updateOverlay();
        });

        $(document).on('change', 'input[name*="text_color"], select[name*="text_color"], input[name*="font_color"], select[name*="font_color"]', function() {
            currentColor = $(this).val().toLowerCase();
            updateOverlay();
        });
    }

    /**
     * Update overlay text, font, colour, and size
     */
    function updateOverlay() {
        if (!overlay) return;

        if (!currentText) {
            overlay.classList.remove('visible');
            overlay.style.display = 'none';
            return;
        }

        overlay.textContent = currentText;

        if (currentFont) {
            overlay.style.fontFamily = "'" + currentFont + "', cursive";
        }

        overlay.setAttribute('data-color', currentColor);
        overlay.style.color = (currentColor === 'white' || currentColor === '#ffffff' || currentColor === '#fff')
            ? '#ffffff' : '#1a1a1a';

        var fontSize = baseFontSize;
        if (currentText.length > 12) {
            fontSize = Math.max(14, baseFontSize * (12 / currentText.length));
        }
        overlay.style.fontSize = fontSize + 'px';

        overlay.style.display = 'block';
        overlay.offsetHeight; // force reflow for CSS transition
        overlay.classList.add('visible');
    }

    function sanitizeSlug(name) {
        return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    }

})(jQuery);
