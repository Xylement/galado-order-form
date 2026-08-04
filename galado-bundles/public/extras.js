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
    var msg = function () {
      return (CFG.i18n && CFG.i18n.case_confirm) ||
        'Removing your case also removes the PWP add-ons with it. Remove everything?';
    };
    var pwpRows = function () {
      return document.querySelectorAll('tr.galado-bundle-line, tr.galado-addon-line').length > 0;
    };

    // Would removing this row end the deal for anything still in the basket?
    // Protection rows ride the LAST CASE; accessory rows ride the LAST
    // ANCHOR of any kind (owner r15).
    var removalEndsDeal = function (row) {
      var lastCase = row.classList.contains('galado-case-line') &&
        document.querySelectorAll('tr.galado-case-line').length <= 1;
      var lastAnchor = row.classList.contains('galado-anchor-line') &&
        document.querySelectorAll('tr.galado-anchor-line').length <= 1;
      if (lastCase && document.querySelector('tr.galado-bundle-line')) return true;
      if (lastAnchor && document.querySelector('tr.galado-addon-line, tr.galado-bundle-line')) return true;
      return false;
    };

    // Path 1: the x remove link on an anchor row.
    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      var link = t.closest('tr.galado-anchor-line a.remove, tr.galado-anchor-line .remove');
      if (!link) return;
      var row = link.closest('tr.galado-anchor-line');
      if (!row || !removalEndsDeal(row)) return;
      if (!window.confirm(msg())) {
        e.preventDefault();
        e.stopPropagation();
      }
    }, true);

    // Path 2 (owner r11): quantity set to 0 + "Update basket" removes the
    // case without touching the x. If the update would leave ZERO cases
    // while PWP rows exist, confirm first; cancel keeps the basket as-is.
    var updateGuard = function (e) {
      if (!pwpRows()) return;
      var sumQty = function (selector) {
        var total = 0;
        Array.prototype.forEach.call(document.querySelectorAll(selector), function (row) {
          var inp = row.querySelector('input.qty');
          if (!inp) { total += 1; return; } // no input = row not editable, stays
          var v = parseFloat(inp.value);
          total += isNaN(v) ? 0 : v;
        });
        return total;
      };
      var casesLeft = sumQty('tr.galado-case-line');
      var anchorsLeft = sumQty('tr.galado-anchor-line');
      var endsCombos = casesLeft === 0 && document.querySelector('tr.galado-bundle-line');
      var endsAddons = anchorsLeft === 0 && document.querySelector('tr.galado-addon-line');
      if (!endsCombos && !endsAddons) return;
      if (!window.confirm(msg())) {
        e.preventDefault();
        e.stopPropagation();
      }
    };
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (f && f.classList && f.classList.contains('woocommerce-cart-form')) updateGuard(e);
    }, true);
    document.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) return;
      var btn = t.closest('button[name="update_cart"], input[name="update_cart"]');
      if (btn) updateGuard(e);
    }, true);
  }

  function boot() {
    if (CFG.sticky) bindSticky();
    bindCaseGuard();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
