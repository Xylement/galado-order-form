/**
 * Bundles extras (ADDON-COMBOS-SPEC sections 5 and 6):
 *  - WCPA price-summary relabel + RM0 add-ons row hiding (PDP)
 *  - mobile cart sticky Continue-to-Checkout bar (/cart/)
 */
(function () {
  'use strict';
  var CFG = window.GALADO_BUNDLES_EXTRAS || {};

  // ---- WCPA relabel (spec 6: "this scared customer") ------------------------
  function relabelOnce(scope) {
    var rows = (scope || document).querySelectorAll('.wcpa_price_summary tr, .wcpa_price_summary > div, .wcpa_price_summary li');
    Array.prototype.forEach.call(rows, function (row) {
      var text = row.textContent || '';
      var label = row.querySelector('td, th, span, label, div');
      if (/product\s*price/i.test(text)) {
        swapLabel(row, label, /product\s*price\s*:?/i, CFG.i18n.case);
      } else if (/options\s*price/i.test(text)) {
        swapLabel(row, label, /options\s*price\s*:?/i, CFG.i18n.addons);
        // Hide the add-ons row entirely while it reads zero; it reappears the
        // moment a paid add-on is picked (the observer re-runs us).
        row.style.display = /(RM|MYR)\s*0([.,]00)?(\s|$)/.test(text) ? 'none' : '';
      }
    });
  }

  function swapLabel(row, label, re, replacement) {
    var target = null;
    var walker = document.createTreeWalker(row, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
      if (re.test(walker.currentNode.nodeValue)) { target = walker.currentNode; break; }
    }
    if (target) target.nodeValue = target.nodeValue.replace(re, replacement);
    else if (label && re.test(label.textContent)) label.textContent = label.textContent.replace(re, replacement);
  }

  function bindRelabel() {
    relabelOnce(document);
    var host = document.querySelector('.wcpa_price_summary');
    var watch = host ? host.parentNode : document.body;
    var t;
    new MutationObserver(function () {
      clearTimeout(t);
      t = setTimeout(function () { relabelOnce(document); }, 60);
    }).observe(watch, { childList: true, subtree: true, characterData: true });
  }

  // ---- mobile cart sticky CTA (spec 5: slide 8 "floating") ------------------
  function bindSticky() {
    if (!document.querySelector('.woocommerce-cart-form')) return; // empty cart

    var bar = document.createElement('div');
    bar.className = 'gld-sticky-cta';
    bar.innerHTML = '<span class="gld-sticky-cta__total" data-gld-total></span>' +
      '<a class="gld-sticky-cta__btn" href="' + CFG.checkout_url + '">' + CFG.i18n.continue + '</a>';
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

  function boot() {
    if (CFG.relabel) bindRelabel();
    if (CFG.sticky) bindSticky();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
