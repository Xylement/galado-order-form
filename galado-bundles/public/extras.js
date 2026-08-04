/**
 * Bundles extras (ADDON-COMBOS-SPEC section 5): the mobile cart sticky
 * Continue-to-Checkout bar.
 *
 * The WCPA price-summary relabel that briefly lived here was dropped before
 * ever shipping: marketing shipped it as Code Snippet #182, and the spec is
 * explicit that the plugin must not relabel the same DOM twice (two observers
 * fighting over one summary).
 */
(function () {
  'use strict';
  var CFG = window.GALADO_BUNDLES_EXTRAS || {};

  function bindSticky() {
    if (!document.querySelector('.woocommerce-cart-form')) return; // empty cart

    var bar = document.createElement('div');
    bar.className = 'gld-sticky-cta';
    bar.innerHTML = '<span class="gld-sticky-cta__total" data-gld-total></span>' +
      '<a class="gld-sticky-cta__btn" href="' + CFG.checkout_url + '">' + CFG.i18n.continue_label + '</a>';
    document.body.appendChild(bar);
    document.body.classList.add('gld-has-sticky-cta');

    function total() {
      var el = document.querySelector('.cart_totals .order-total .amount') ||
               document.querySelector('.cart_totals .order-total td');
      var out = bar.querySelector('[data-gld-total]');
      if (el && out) out.textContent = el.textContent.trim();
    }
    total();
    if (window.jQuery) {
      window.jQuery(document.body).on('updated_cart_totals wc_fragments_refreshed', function () {
        // Cart may have emptied on update: remove the bar with the form gone.
        if (!document.querySelector('.woocommerce-cart-form')) {
          bar.remove();
          document.body.classList.remove('gld-has-sticky-cta');
          return;
        }
        total();
      });
    }
  }

  function boot() { if (CFG.sticky) bindSticky(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
