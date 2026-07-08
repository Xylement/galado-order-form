/**
 * GALADO AI Recommendations - one-tap add-to-cart on recommendation cards.
 * Mirrors the Smart Cross-Sells behaviour so both surfaces feel identical:
 * + Add to cart -> Adding... -> ✓ Added -> card fades out (can't double-add).
 */
(function($) {
    'use strict';

    $(document).on('click', '.gair-add-btn[data-product-id]', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var productId = $btn.data('product-id');

        if ($btn.hasClass('adding') || $btn.hasClass('added')) return;

        $btn.addClass('adding');
        $btn.find('.btn-text').hide();
        $btn.find('.btn-loading').show();

        $.ajax({
            url: gairData.ajaxurl,
            type: 'POST',
            data: {
                action: 'gair_add_to_cart',
                product_id: productId,
                quantity: 1,
                nonce: gairData.nonce
            },
            success: function(response) {
                if (response.success) {
                    $btn.removeClass('adding').addClass('added');
                    $btn.find('.btn-loading').hide();
                    $btn.find('.btn-done').show();

                    var $card = $btn.closest('.gair-product-card');
                    setTimeout(function() {
                        $card.css({
                            'transition': 'opacity 0.3s, transform 0.3s',
                            'opacity': '0',
                            'transform': 'scale(0.95)'
                        });
                        setTimeout(function() {
                            $card.remove();
                            $('.gair-products-grid').each(function() {
                                if ($(this).children().length === 0) {
                                    $(this).closest('.gair-section').fadeOut(200);
                                }
                            });
                        }, 300);
                    }, 800);

                    if (response.data.fragments) {
                        $.each(response.data.fragments, function(key, value) {
                            $(key).replaceWith(value);
                        });
                    }
                    $(document.body).trigger('added_to_cart', [response.data.fragments, response.data.cart_hash]);
                    $(document.body).trigger('wc_fragment_refresh');

                    if ($('body').hasClass('woocommerce-cart')) {
                        setTimeout(function() {
                            $(document.body).trigger('wc_update_cart');
                            if ($('[name="update_cart"]').length) {
                                $('[name="update_cart"]').prop('disabled', false).trigger('click');
                            }
                        }, 500);
                    }
                } else {
                    $btn.removeClass('adding');
                    $btn.find('.btn-loading').hide();
                    $btn.find('.btn-text').show().text('Try again');
                    setTimeout(function() {
                        $btn.find('.btn-text').text('+ Add to cart');
                    }, 2000);
                }
            },
            error: function() {
                $btn.removeClass('adding');
                $btn.find('.btn-loading').hide();
                $btn.find('.btn-text').show().text('Try again');
                setTimeout(function() {
                    $btn.find('.btn-text').text('+ Add to cart');
                }, 2000);
            }
        });
    });

})(jQuery);
