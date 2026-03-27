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
     * Two-layer approach for maximum cross-device reliability:
     *
     * 1. Capture-phase listener on #galado-fp-grid — fires BEFORE galado-fp's
     *    own direct handlers and before e.preventDefault() suppresses the click
     *    on iOS Safari. Works on desktop and all mobile browsers.
     *
     * 2. MutationObserver as a fallback — catches the pre-selected card when
     *    galado-fp re-renders the grid on every keystroke, so the overlay font
     *    stays in sync even after the cards are replaced.
     */
    function bindFontCards() {
        var grid = document.getElementById('galado-fp-grid');
        if (!grid) return;

        // --- Layer 1: capture-phase event (most reliable on mobile) ---
        function handleFontTap(e) {
            var card = e.target.closest ? e.target.closest('.galado-fp-card') : null;
            if (!card) return;
            var fontName = card.dataset.font || card.getAttribute('data-font');
            if (fontName) {
                currentFont = fontName;
                updateOverlay();
            }
        }
        // passive:true lets the browser scroll freely; capture:true fires first
        grid.addEventListener('touchend', handleFontTap, { capture: true, passive: true });
        grid.addEventListener('click',    handleFontTap, { capture: true });

        // --- Layer 2: MutationObserver — picks up pre-selected font after re-render ---
        var observer = new MutationObserver(function() {
            var selected = grid.querySelector('.galado-fp-card.selected');
            if (!selected) return;
            var fontName = selected.dataset.font || selected.getAttribute('data-font');
            if (fontName && fontName !== currentFont) {
                currentFont = fontName;
                updateOverlay();
            }
        });
        // childList only — fires when renderPreviews() replaces $grid.html(...)
        observer.observe(grid, { childList: true });
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
