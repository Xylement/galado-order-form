/**
 * GALADO Smart Cross-Sells - Frontend JS
 * Handles AJAX add-to-cart from cross-sell buttons
 */
(function($) {
    'use strict';

    $(document).on('click', '.galado-cs-add-btn[data-product-id]', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var productId = $btn.data('product-id');

        if ($btn.hasClass('adding') || $btn.hasClass('added')) return;

        // Show loading state
        $btn.addClass('adding');
        $btn.find('.btn-text').hide();
        $btn.find('.btn-loading').show();

        $.ajax({
            url: galadoCS.ajaxurl,
            type: 'POST',
            data: {
                action: 'galado_cs_add_to_cart',
                product_id: productId,
                quantity: 1,
                nonce: galadoCS.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success state
                    $btn.removeClass('adding').addClass('added');
                    $btn.find('.btn-loading').hide();
                    $btn.find('.btn-done').show();

                    // Update cart fragments
                    if (response.data.fragments) {
                        $.each(response.data.fragments, function(key, value) {
                            $(key).replaceWith(value);
                        });
                    }

                    // Trigger WooCommerce cart update events
                    $(document.body).trigger('added_to_cart', [response.data.fragments, response.data.cart_hash]);
                    $(document.body).trigger('wc_fragment_refresh');

                    // If on cart page, refresh after short delay
                    if ($('body').hasClass('woocommerce-cart')) {
                        setTimeout(function() {
                            $(document.body).trigger('wc_update_cart');

                            // If WC doesn't auto-refresh, do a soft reload
                            if ($('[name="update_cart"]').length) {
                                $('[name="update_cart"]').prop('disabled', false).trigger('click');
                            }
                        }, 500);
                    }

                    // If on checkout page, update order review
                    if ($('body').hasClass('woocommerce-checkout')) {
                        $(document.body).trigger('update_checkout');
                    }

                    // Reset button after 3 seconds
                    setTimeout(function() {
                        $btn.removeClass('added');
                        $btn.find('.btn-done').hide();
                        $btn.find('.btn-text').show();
                    }, 3000);

                } else {
                    // Error
                    $btn.removeClass('adding');
                    $btn.find('.btn-loading').hide();
                    $btn.find('.btn-text').show().text('Error');

                    setTimeout(function() {
                        $btn.find('.btn-text').text(galadoCS.i18n.add);
                    }, 2000);
                }
            },
            error: function() {
                $btn.removeClass('adding');
                $btn.find('.btn-loading').hide();
                $btn.find('.btn-text').show().text('Error');

                setTimeout(function() {
                    $btn.find('.btn-text').text(galadoCS.i18n.add);
                }, 2000);
            }
        });
    });

})(jQuery);
