/**
 * GALADO Live Case Preview — Frontend Script
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

        // Position overlay within the preview widget (% positioning within .gcp-preview-inner)
        overlay.style.left      = gcpConfig.x + '%';
        overlay.style.top       = gcpConfig.y + '%';
        overlay.style.transform = 'translate(-50%, -50%) rotate(' + (gcpConfig.rotate || 0) + 'deg)';
        overlay.style.maxWidth  = maxWidthPct + '%';

        // Widget starts hidden — only shown when personalisation is ticked
        var $widget = $('#gcp-preview-widget');
        $widget.hide();

        // When "Add Personalisation" checkbox changes, show/hide and position the widget
        var $enable = $('#galado-fp-enable');
        $enable.on('change', function() {
            if (this.checked) {
                // Move widget inside #galado-fp-fields, between colour and font grid
                var $fontGridWrap = $('#galado-fp-grid').closest('.galado-fp-input-wrap');
                if ($fontGridWrap.length) {
                    $fontGridWrap.before($widget);
                } else {
                    $('#galado-fp-fields').prepend($widget);
                }
                $widget.show();
            } else {
                $widget.hide();
                // Clear overlay when personalisation is deselected
                currentText = '';
                currentFont = '';
                currentColor = 'black';
                updateOverlay();
            }
        });

        bindTextInput();
        bindFontCards();
        bindColourSelector();
    });

    function bindTextInput() {
        var input = document.getElementById('galado-fp-text');
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

    function bindFontCards() {
        // galado-font-preview uses touchend + e.preventDefault() on font cards,
        // which blocks the click event on mobile. Bind both touchend and click
        // at document level; use lastFont guard to prevent double-firing.
        var lastFont = '';

        function handleFontSelect(el) {
            var fontName = $(el).data('font') || $(el).data('font-slug');
            if (!fontName || fontName === lastFont) return;
            lastFont = fontName;
            currentFont = fontName;
            updateOverlay();
            // Reset guard after a tick (allows same font to be re-selected later)
            setTimeout(function() { lastFont = ''; }, 300);
        }

        $(document).on('touchend', '.galado-fp-card', function() {
            handleFontSelect(this);
        });

        $(document).on('click', '.galado-fp-card', function() {
            handleFontSelect(this);
        });
    }

    function bindColourSelector() {
        // galado-font-preview uses input[name="galado_font_color"] with values Black/White
        $(document).on('change', 'input[name="galado_font_color"]', function() {
            currentColor = $(this).val().toLowerCase();
            updateOverlay();
        });

        // Also catch any other colour selectors for flexibility
        $(document).on('click', '.galado-fp-color-btn, [data-text-color]', function() {
            currentColor = $(this).data('text-color') || $(this).data('color') || 'black';
            updateOverlay();
        });
    }

    function updateOverlay() {
        if (!overlay) return;

        if (!currentText) {
            overlay.classList.remove('visible');
            overlay.style.display = 'none';
            return;
        }

        overlay.textContent = currentText;

        if (currentFont) {
            // galado-font-preview registers fonts using their full name (e.g. 'Rustling Sound')
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

})(jQuery);
