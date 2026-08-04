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

  /**
   * Removing the last case sweeps the PWP items with it (plugin behaviour),
   * so ask first (owner 2026-08-04 r9). The marker classes only render for
   * sessions that can transact, so while the modules are dark this never
   * fires for customers. Capture phase beats any theme AJAX-remove handler.
   */
  function bindCaseGuard() {
    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      var link = t.closest('tr.galado-case-line a.remove, tr.galado-case-line .remove');
      if (!link) return;
      if (document.querySelectorAll('tr.galado-case-line').length > 1) return; // another case anchors the deal
      if (!document.querySelectorAll('tr.galado-bundle-line, tr.galado-addon-line').length) return; // nothing rides on it
      var msg = (CFG.i18n && CFG.i18n.case_confirm) ||
        'Removing your case also removes the PWP add-ons with it. Remove everything?';
      if (!window.confirm(msg)) {
        e.preventDefault();
        e.stopPropagation();
      }
    }, true);
  }

  function boot() {
    if (CFG.sticky) bindSticky();
    bindCaseGuard();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
