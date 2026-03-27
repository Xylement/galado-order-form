/**
 * GALADO Live Case Preview — Frontend Script
 * Overlays customer's typed name on the product image in real-time
 */
(function($) {
    'use strict';

    if (typeof gcpConfig === 'undefined' || !gcpConfig.enabled) return;

    var overlay = null;
    var galleryImage = null;
    var currentFont = '';
    var currentText = '';
    var currentColor = 'black';
    var baseFontSize = parseInt(gcpConfig.fontSize) || 28;
    var maxWidthPct = parseInt(gcpConfig.maxWidth) || 60;

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

        // Find the main gallery image container
        galleryImage = document.querySelector('.woocommerce-product-gallery__image');
        if (!galleryImage) {
            galleryImage = document.querySelector('.woocommerce-product-gallery__wrapper');
        }
        if (!galleryImage) return;

        // Move overlay inside the gallery image container
        galleryImage.style.position = 'relative';
        galleryImage.appendChild(overlay);

        // Position the overlay based on preset
        positionOverlay();

        // Listen to the font preview plugin's text input
        bindTextInput();

        // Listen to font card clicks
        bindFontCards();

        // Listen to text colour selection
        bindColourSelector();

        // Listen to gallery image changes (when user clicks thumbnails)
        observeGalleryChanges();
    }

    /**
     * Position overlay based on admin preset config
     */
    function positionOverlay() {
        if (!overlay) return;

        overlay.style.left = gcpConfig.x + '%';
        overlay.style.top = gcpConfig.y + '%';
        overlay.style.transform = 'translate(-50%, -50%) rotate(' + (gcpConfig.rotate || 0) + 'deg)';
        overlay.style.maxWidth = maxWidthPct + '%';
    }

    /**
     * Bind to the personalisation text input (from galado-font-preview or WooCommerce custom fields)
     */
    function bindTextInput() {
        // Try galado-font-preview input first
        var input = document.getElementById('galado-fp-text');

        // Fallback: WooCommerce product add-on text fields
        if (!input) {
            input = document.querySelector('.wc-pao-addon-field[type="text"]');
        }
        if (!input) {
            input = document.querySelector('input[name*="custom_text"], input[name*="personalise"], input[name*="personalize"], input[name*="name"]');
        }

        if (!input) return;

        // Listen for input
        input.addEventListener('input', function() {
            currentText = this.value.trim();
            updateOverlay();
        });

        // Initialize if already has value
        if (input.value.trim()) {
            currentText = input.value.trim();
            updateOverlay();
        }
    }

    /**
     * Bind to font card clicks (from galado-font-preview plugin)
     */
    function bindFontCards() {
        // Listen for clicks on font preview cards
        $(document).on('click', '.galado-fp-card', function() {
            var fontSlug = $(this).data('font-slug') || $(this).data('font');

            // Remove active state from all cards
            $('.galado-fp-card').removeClass('gcp-active');
            // Add to clicked card
            $(this).addClass('gcp-active');

            if (fontSlug) {
                currentFont = fontSlug;
                updateOverlay();
            }
        });

        // Also listen for radio button changes (if font preview uses radios)
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
        // From galado-font-preview colour buttons
        $(document).on('click', '.galado-fp-color-btn, [data-text-color]', function() {
            currentColor = $(this).data('text-color') || $(this).data('color') || 'black';
            updateOverlay();
        });

        // Radio/select colour inputs
        $(document).on('change', 'input[name*="text_color"], select[name*="text_color"], input[name*="font_color"], select[name*="font_color"]', function() {
            currentColor = $(this).val().toLowerCase();
            updateOverlay();
        });
    }

    /**
     * Update the overlay text
     */
    function updateOverlay() {
        if (!overlay) return;

        if (!currentText) {
            overlay.classList.remove('visible');
            overlay.style.display = 'none';
            return;
        }

        // Set text
        overlay.textContent = currentText;

        // Set font
        if (currentFont) {
            overlay.style.fontFamily = "'" + currentFont + "', cursive";
        }

        // Set colour
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

        // Show
        overlay.style.display = 'block';
        // Force reflow then add visible class for transition
        overlay.offsetHeight;
        overlay.classList.add('visible');
    }

    /**
     * Watch for gallery image changes (thumbnail clicks, Flatsome lightbox, etc.)
     */
    function observeGalleryChanges() {
        // Flatsome theme uses a slider — watch for slide changes
        var gallery = document.querySelector('.woocommerce-product-gallery');
        if (!gallery) return;

        // MutationObserver to detect when the main image changes
        var observer = new MutationObserver(function(mutations) {
            // Re-find and re-attach overlay to the current visible image
            var newImage = gallery.querySelector('.woocommerce-product-gallery__image.flex-active-slide') ||
                           gallery.querySelector('.woocommerce-product-gallery__image:first-child');

            if (newImage && newImage !== galleryImage) {
                galleryImage = newImage;
                galleryImage.style.position = 'relative';
                galleryImage.appendChild(overlay);
                positionOverlay();
                updateOverlay();
            }
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
