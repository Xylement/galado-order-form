/**
 * PWP summary inside the sticky Buy Now bar (owner 2026-08-04 round 2,
 * Casetify reference). Snippet #7 owns the bar itself; this script takes over
 * its info column: the product name goes, replaced by "N items" plus the
 * combined price (case + bundle lines) with the pre-saving price struck.
 * The Buy Now button is untouched. Before any variation is chosen and with
 * nothing added, the bar keeps its original name + price.
 *
 * Cart state comes from the uncached galado_pwp_state endpoint, never from
 * cacheable markup. This script only enqueues when a module renders, so while
 * the storefront is dark customers never load it.
 */
(function () {
  'use strict';
  var CFG = window.GALADO_PWP_BAR || {};
  if (!CFG.state_url) return;

  var state = null;      // {used, count, saved, total, bundle_total}
  var casePrice = null;  // selected variation display price, null until chosen
  var sum = null;        // our injected block

  function rm(n) { n = Math.round((+n || 0) * 100) / 100; return 'RM' + n.toFixed(2); }

  function info() { return document.querySelector('#galado-sticky-cart .galado-sticky-info'); }

  function render() {
    var h = info();
    if (!h) return;
    var name = h.querySelector('.galado-sticky-name');
    var price = h.querySelector('.galado-sticky-price');
    var count = state ? (+state.count || 0) : 0;

    if (casePrice === null && !count) {
      // Nothing to summarise yet: the bar stays exactly as snippet #7 made it.
      if (sum) sum.hidden = true;
      if (name) name.style.display = '';
      if (price) price.style.display = '';
      return;
    }

    if (!sum) {
      sum = document.createElement('span');
      sum.className = 'gld-pwp-sum';
      var items = document.createElement('span');
      items.className = 'gld-pwp-sum__items';
      items.setAttribute('data-gld-items', '');
      var line = document.createElement('span');
      line.className = 'gld-pwp-sum__price';
      var fin = document.createElement('b');
      fin.setAttribute('data-gld-final', '');
      var orig = document.createElement('s');
      orig.setAttribute('data-gld-orig', '');
      orig.hidden = true;
      line.appendChild(fin);
      line.appendChild(orig);
      sum.appendChild(items);
      sum.appendChild(line);
      h.appendChild(sum);
    }
    if (name) name.style.display = 'none';
    if (price) price.style.display = 'none';
    sum.hidden = false;

    var n = count + (casePrice !== null ? 1 : 0);
    var final = (casePrice || 0) + (state ? (+state.bundle_total || 0) : 0);
    var saved = state ? (+state.saved || 0) : 0;

    sum.querySelector('[data-gld-items]').textContent =
      n + ' ' + (n === 1 ? CFG.i18n.item : CFG.i18n.items);
    sum.querySelector('[data-gld-final]').textContent = rm(final);
    var o = sum.querySelector('[data-gld-orig]');
    if (saved > 0) { o.hidden = false; o.textContent = rm(final + saved); }
    else o.hidden = true;
    sum.classList.toggle('has-save', saved > 0);
  }

  window.GALADO_PWP_REFRESH = function (s) { if (s) { state = s; render(); } };

  function bindCase() {
    if (!window.jQuery) return;
    window.jQuery('form.variations_form')
      .on('found_variation', function (e, v) {
        if (v && typeof v.display_price !== 'undefined') {
          var p = parseFloat(v.display_price);
          if (!isNaN(p)) casePrice = p;
        }
        render();
      })
      .on('reset_data hide_variation', function () { casePrice = null; render(); });
  }

  function boot() {
    bindCase();
    fetch(CFG.state_url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (s) { state = s; render(); })
      .catch(function () { /* bar simply keeps its original content */ });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
