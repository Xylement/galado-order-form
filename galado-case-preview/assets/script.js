/**
 * GALADO Live Case Preview — Frontend Script
 * Overlays customer's typed name on the product image in real-time
 */
(function($) {
    'use strict';

    if (typeof gcpConfig === 'undefined' || !gcpConfig.enabled) return;

    var overlay    = null;
    var imageWrap  = null; // .gcp-img-wrap span we inject around the <img>
    var currentFont  = '';
    var currentText  = '';
    var currentColor = 'black';
    var baseFontSize = parseInt(gcpConfig.fontSize) || 28;
    var maxWidthPct  = parseInt(gcpConfig.maxWidth)  || 60;

    /**
     * Initialize once DOM is ready
     */
    $(document).ready(function() {
        // Wait a tick for WooCommerce gallery to initialize
        setTimeout(init, 500);
    });

    function init() {
        overlay = document.getElementById('gcp-overlay-text');
        if (!overlay) return;

        // Find the active/first gallery slide
        var slide = document.querySelector('.woocommerce-product-gallery__image.flex-active-slide') ||
                    document.querySelector('.woocommerce-product-gallery__image:first-child');
        if (!slide) return;

        attachOverlayToSlide(slide);
        positionOverlay();
        bindTextInput();
        bindFontCards();
        bindColourSelector();
        observeGalleryChanges();
    }

    /**
     * Wrap only the <img> inside the slide in a positioned span, then
     * append the overlay inside that span.
     * This avoids touching the FlexSlider/Flatsome gallery structure.
     */
    function attachOverlayToSlide(slide) {
        var img = slide.querySelector('img');
        if (!img) return;

        // Already wrapped — just (re)append overlay
        if (img.parentNode.classList && img.parentNode.classList.contains('gcp-img-wrap')) {
            img.parentNode.appendChild(overlay);
            imageWrap = img.parentNode;
            return;
        }

        var wrap = document.createElement('span');
        wrap.className = 'gcp-img-wrap';
        img.parentNode.insertBefore(wrap, img);
        wrap.appendChild(img);
        wrap.appendChild(overlay);
        imageWrap = wrap;
    }

    /**
     * Position overlay based on admin config (percentages within the image)
     */
    function positionOverlay() {
        if (!overlay) return;
        overlay.style.left      = gcpConfig.x + '%';
        overlay.style.top       = gcpConfig.y + '%';
        overlay.style.transform = 'translate(-50%, -50%) rotate(' + (gcpConfig.rotate || 0) + 'deg)';
        overlay.style.maxWidth  = maxWidthPct + '%';
    }

    /**
     * Bind to the personalisation text input (from galado-font-preview or WooCommerce custom fields)
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
     * Bind to font card clicks (from galado-font-preview plugin)
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
     * Update the overlay text, font, colour, and size
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

        // Auto-size: shrink font for longer names
        var fontSize = baseFontSize;
        if (currentText.length > 12) {
            fontSize = Math.max(14, baseFontSize * (12 / currentText.length));
        }
        overlay.style.fontSize = fontSize + 'px';

        overlay.style.display = 'block';
        overlay.offsetHeight; // force reflow for transition
        overlay.classList.add('visible');
    }

    /**
     * Watch for gallery image changes (thumbnail clicks, Flatsome slider, etc.)
     */
    function observeGalleryChanges() {
        var gallery = document.querySelector('.woocommerce-product-gallery');
        if (!gallery) return;

        var observer = new MutationObserver(function() {
            var newSlide = gallery.querySelector('.woocommerce-product-gallery__image.flex-active-slide') ||
                           gallery.querySelector('.woocommerce-product-gallery__image:first-child');
            if (!newSlide) return;

            var newImg = newSlide.querySelector('img');
            if (!newImg) return;

            // Already attached to this image's wrap
            if (imageWrap && imageWrap.contains(newImg)) return;

            // Detach overlay from its current parent before re-attaching
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);

            attachOverlayToSlide(newSlide);
            positionOverlay();
            updateOverlay();
        });

        observer.observe(gallery, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class']
        });
    }

    /**
     * Sanitize font name to slug
     */
    function sanitizeSlug(name) {
        return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    }

})(jQuery);
