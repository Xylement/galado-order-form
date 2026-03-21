/**
 * GALADO AI Recommendations - Frontend Tracker
 * Tracks product views and browsing behaviour via AJAX
 */
(function($) {
    'use strict';

    // Track product page views
    if ($('body').hasClass('single-product')) {
        var productId = $('button.single_add_to_cart_button').val() ||
                        $('input[name="add-to-cart"]').val() ||
                        $('[data-product_id]').first().data('product_id');

        // Try to get product ID from various WooCommerce elements
        if (!productId) {
            var match = document.body.className.match(/postid-(\d+)/);
            if (match) productId = match[1];
        }

        if (productId) {
            $.post(gairData.ajaxurl, {
                action: 'gair_track',
                nonce: gairData.nonce,
                event_type: 'product_view',
                product_id: productId,
                category: $('nav.woocommerce-breadcrumb a').map(function() {
                    return $(this).text().trim();
                }).get().join(',')
            });
        }
    }

    // Track category page views
    if ($('body').hasClass('tax-product_cat')) {
        var catName = $('h1.woocommerce-products-header__title, .page-title').first().text().trim();
        $.post(gairData.ajaxurl, {
            action: 'gair_track',
            nonce: gairData.nonce,
            event_type: 'category_view',
            category: catName
        });
    }

    // Track search queries
    if ($('body').hasClass('search-results')) {
        var query = $('input.search-field').val() || new URLSearchParams(window.location.search).get('s');
        if (query) {
            $.post(gairData.ajaxurl, {
                action: 'gair_track',
                nonce: gairData.nonce,
                event_type: 'search',
                event_data: JSON.stringify({ query: query })
            });
        }
    }

})(jQuery);
