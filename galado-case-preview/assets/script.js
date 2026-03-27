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

        // The widget lives inside #galado-fp-fields which galado-fp shows/hides
        // with the personalisation checkbox — no extra JS needed for visibility.

        // Position overlay within .gcp-preview-inner (% within the mockup image)
        overlay.style.left      = gcpConfig.x + '%';
        overlay.style.top       = gcpConfig.y + '%';
        overlay.style.transform = 'translate(-50%, -50%) rotate(' + (gcpConfig.rotate || 0) + 'deg)';
        overlay.style.maxWidth  = maxWidthPct + '%';

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
    }

    /**
     * Watch #galado-fp-grid for the 'selected' class appearing on any card.
     * This works on all devices without relying on touch/click event propagation.
     * galado-fp does: $('.galado-fp-card').removeClass('selected'); $(this).addClass('selected');
     */
    function bindFontCards() {
        var grid = document.getElementById('galado-fp-grid');
        if (!grid) return;

        var observer = new MutationObserver(function() {
            var selected = grid.querySelector('.galado-fp-card.selected');
            if (!selected) return;
            var fontName = selected.dataset.font;
            if (fontName && fontName !== currentFont) {
                currentFont = fontName;
                updateOverlay();
            }
        });

        observer.observe(grid, {
            childList:       true,   // cards re-created on text input
            attributes:      true,   // class changes when card is tapped
            attributeFilter: ['class'],
            subtree:         true
        });
    }

    function bindColourSelector() {
        // galado-fp uses input[name="galado_font_color"] with values "Black"/"White"
        $(document).on('change', 'input[name="galado_font_color"]', function() {
            currentColor = $(this).val().toLowerCase();
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

        // galado-fp registers fonts with full names e.g. 'Rustling Sound'
        // and cards store data-font="Rustling Sound" — so currentFont matches directly
        if (currentFont) {
            overlay.style.fontFamily = "'" + currentFont + "', cursive";
        }

        overlay.setAttribute('data-color', currentColor);
        overlay.style.color = (currentColor === 'white')
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
