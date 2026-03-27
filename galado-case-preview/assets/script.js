/**
 * GALADO Live Case Preview — Frontend Script
 */
(function($) {
    'use strict';

    if (typeof gcpConfig === 'undefined' || !gcpConfig.enabled) return;

    var overlay      = null;
    var inner        = null;  // .gcp-preview-inner — the positioned image container
    var currentFont  = '';
    var currentText  = '';
    var currentColor = 'black';
    var maxFontSize  = parseInt(gcpConfig.fontSize) || 40;

    // Rectangle config (percentages of the mockup image)
    var rectX = parseFloat(gcpConfig.rectX) || 20;  // left edge
    var rectY = parseFloat(gcpConfig.rectY) || 35;  // top edge
    var rectW = parseFloat(gcpConfig.rectW) || 60;  // width
    var rectH = parseFloat(gcpConfig.rectH) || 30;  // height
    var rotate = parseInt(gcpConfig.rotate) || 0;

    $(document).ready(function() {
        overlay = document.getElementById('gcp-overlay-text');
        inner   = document.getElementById('gcp-preview-inner');
        if (!overlay || !inner) return;

        // Position overlay at the centre of the rectangle
        overlay.style.left     = (rectX + rectW / 2) + '%';
        overlay.style.top      = (rectY + rectH / 2) + '%';
        overlay.style.width    = rectW + '%';
        overlay.style.transform = 'translate(-50%, -50%) rotate(' + rotate + 'deg)';

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
     * Two-layer font detection:
     * 1. Capture-phase event on the grid — fires before galado-fp's own handlers
     *    and before e.preventDefault() suppresses the tap on iOS Safari.
     * 2. MutationObserver — syncs the font when galado-fp re-renders the grid
     *    on every keystroke (the previously-selected card re-appears with .selected).
     */
    function bindFontCards() {
        var grid = document.getElementById('galado-fp-grid');
        if (!grid) return;

        function handleFontTap(e) {
            var card = e.target.closest ? e.target.closest('.galado-fp-card') : null;
            if (!card) return;
            var fontName = card.dataset.font || card.getAttribute('data-font');
            if (fontName) { currentFont = fontName; updateOverlay(); }
        }
        grid.addEventListener('touchend', handleFontTap, { capture: true, passive: true });
        grid.addEventListener('click',    handleFontTap, { capture: true });

        // Fallback: pick up pre-selected card after grid re-render
        new MutationObserver(function() {
            var selected = grid.querySelector('.galado-fp-card.selected');
            if (!selected) return;
            var fontName = selected.dataset.font || selected.getAttribute('data-font');
            if (fontName && fontName !== currentFont) { currentFont = fontName; updateOverlay(); }
        }).observe(grid, { childList: true });
    }

    function bindColourSelector() {
        $(document).on('change', 'input[name="galado_font_color"]', function() {
            currentColor = $(this).val().toLowerCase();
            updateOverlay();
        });
    }

    function updateOverlay() {
        if (!overlay || !inner) return;

        if (!currentText) {
            overlay.classList.remove('visible');
            overlay.style.display = 'none';
            return;
        }

        // Apply text and style before measuring
        overlay.textContent = currentText;
        if (currentFont) overlay.style.fontFamily = "'" + currentFont + "', cursive";
        overlay.setAttribute('data-color', currentColor);
        overlay.style.color = (currentColor === 'white') ? '#ffffff' : '#1a1a1a';

        // Show (but still invisible via opacity:0) so we can measure
        overlay.style.display = 'block';

        // ── Auto-fit font size to the rectangle's height ─────────────────────
        // rectH is a % of the mockup image height → convert to px
        var rectHpx = inner.clientHeight * rectH / 100;

        if (rectHpx > 0) {
            // Binary search: find the largest font that fits inside the rect height
            var lo = 8, hi = maxFontSize;
            while (hi - lo > 1) {
                var mid = Math.round((lo + hi) / 2);
                overlay.style.fontSize = mid + 'px';
                // scrollHeight reflects the full rendered height including wrapped lines
                if (overlay.scrollHeight <= rectHpx) lo = mid;
                else hi = mid;
            }
            overlay.style.fontSize = lo + 'px';
        } else {
            overlay.style.fontSize = maxFontSize + 'px';
        }

        // Trigger reflow then fade in
        overlay.offsetHeight;
        overlay.classList.add('visible');
    }

})(jQuery);
