/**
 * GALADO Live Case Preview — Frontend Script
 * Overlays customer's typed name on the product image in real-time
 */
(function($) {
    'use strict';

    if (typeof gcpConfig === 'undefined' || !gcpConfig.enabled) return;

    var overlay      = null;
    var gallery      = null;
    var currentFont  = '';
    var currentText  = '';
    var currentColor = 'black';
    var baseFontSize = parseInt(gcpConfig.fontSize) || 28;
    var maxWidthPct  = parseInt(gcpConfig.maxWidth)  || 60;

    $(document).ready(function() {
        setTimeout(init, 500);
    });

    function init() {
        overlay = document.getElementById('gcp-overlay-text');
        if (!overlay) return;

        gallery = document.querySelector('.woocommerce-product-gallery');
        if (!gallery) return;

        // Append overlay to the outer gallery container — never inside the slider
        gallery.style.position = 'relative';
        gallery.appendChild(overlay);

        repositionOverlay();
        bindTextInput();
        bindFontCards();
        bindColourSelector();
        observeGalleryChanges();

        // Reposition when the window is resized (e.g. orientation change on iPad)
        window.addEventListener('resize', debounce(repositionOverlay, 150));
    }

    /**
     * Position the overlay over the active slide's image using pixel offsets
     * relative to the gallery container. We never wrap or modify the slider DOM.
     */
    function repositionOverlay() {
        if (!overlay || !gallery) return;

        var slide = gallery.querySelector('.woocommerce-product-gallery__image.flex-active-slide') ||
                    gallery.querySelector('.woocommerce-product-gallery__image:first-child');
        var img = slide ? slide.querySelector('img') : null;
        if (!img) return;

        var gr = gallery.getBoundingClientRect();
        var ir = img.getBoundingClientRect();

        overlay.style.left      = (ir.left - gr.left + ir.width  * gcpConfig.x / 100) + 'px';
        overlay.style.top       = (ir.top  - gr.top  + ir.height * gcpConfig.y / 100) + 'px';
        overlay.style.transform = 'translate(-50%, -50%) rotate(' + (gcpConfig.rotate || 0) + 'deg)';
        overlay.style.maxWidth  = (ir.width * maxWidthPct / 100) + 'px';
    }

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
     * Bind to text colour selector
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
        if (currentColor === 'white' || currentColor === '#ffffff' || currentColor === '#fff') {
            overlay.style.color = '#ffffff';
        } else {
            overlay.style.color = '#1a1a1a';
        }

        var fontSize = baseFontSize;
        if (currentText.length > 12) {
            fontSize = Math.max(14, baseFontSize * (12 / currentText.length));
        }
        overlay.style.fontSize = fontSize + 'px';

        overlay.style.display = 'block';
        overlay.offsetHeight; // force reflow for transition
        overlay.classList.add('visible');

        repositionOverlay();
    }

    /**
     * Watch for gallery slide changes and reposition the overlay
     */
    function observeGalleryChanges() {
        if (!gallery) return;

        var observer = new MutationObserver(debounce(function() {
            repositionOverlay();
        }, 80));

        observer.observe(gallery, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class']
        });
    }

    function debounce(fn, ms) {
        var t;
        return function() { clearTimeout(t); t = setTimeout(fn, ms); };
    }

    function sanitizeSlug(name) {
        return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    }

})(jQuery);
