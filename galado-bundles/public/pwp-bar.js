/**
 * Floating PWP summary bar: once bundle items are in the basket, a slim bar
 * shows how many, the total saving, the basket total and a basket link
 * (owner 2026-08-04, Casetify reference). State comes from an uncached
 * cart-derived endpoint, never from cacheable page markup.
 */
(function () {
  'use strict';
  var CFG = window.GALADO_PWP_BAR || {};
  if (!CFG.state_url) return;

  var bar = null;

  function rm(n) { n = Math.round((+n || 0) * 100) / 100; return 'RM' + (n % 1 === 0 ? String(n) : n.toFixed(2)); }

  function ensure() {
    if (bar) return bar;
    bar = document.createElement('div');
    bar.className = 'gld-pwp-bar';
    bar.hidden = true;

    var count = document.createElement('span');
    count.className = 'gld-pwp-bar__count';
    count.setAttribute('data-gld-count', '');
    var saved = document.createElement('span');
    saved.className = 'gld-pwp-bar__saved';
    saved.setAttribute('data-gld-saved', '');
    var total = document.createElement('span');
    total.className = 'gld-pwp-bar__total';
    total.setAttribute('data-gld-total', '');
    var link = document.createElement('a');
    link.className = 'gld-pwp-bar__link';
    link.href = CFG.cart_url || '/cart/';
    link.textContent = CFG.i18n.view + ' →';

    bar.appendChild(count); bar.appendChild(saved); bar.appendChild(total); bar.appendChild(link);
    document.body.appendChild(bar);
    offset();
    window.addEventListener('resize', offset);
    return bar;
  }

  /** Sit above any theme sticky bottom bar rather than covering it. */
  function offset() {
    if (!bar) return;
    var off = 0;
    var candidates = document.querySelectorAll('[class*="sticky"], [id*="sticky"], [class*="fixed-bottom"]');
    Array.prototype.forEach.call(candidates, function (el) {
      if (el === bar || bar.contains(el)) return;
      var cs = getComputedStyle(el);
      if (cs.position !== 'fixed' || cs.display === 'none') return;
      var r = el.getBoundingClientRect();
      if (r.height > 0 && r.height < 180 && r.bottom >= window.innerHeight - 6 && r.top > window.innerHeight / 2) {
        off = Math.max(off, r.height);
      }
    });
    bar.style.bottom = 'calc(' + off + 'px + env(safe-area-inset-bottom))';
  }

  function refresh(state) {
    if (!state) return;
    var b = ensure();
    if (!state.count) { b.hidden = true; return; }
    b.querySelector('[data-gld-count]').textContent = state.count + ' ' + (state.count === 1 ? CFG.i18n.item : CFG.i18n.items);
    var sv = b.querySelector('[data-gld-saved]');
    if (state.saved > 0) { sv.hidden = false; sv.textContent = CFG.i18n.saved + ' ' + rm(state.saved); }
    else sv.hidden = true;
    b.querySelector('[data-gld-total]').textContent = CFG.i18n.total + ' ' + rm(state.total);
    b.hidden = false;
    offset();
  }

  window.GALADO_PWP_REFRESH = refresh;

  function boot() {
    fetch(CFG.state_url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(refresh)
      .catch(function () { /* bar simply stays hidden */ });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
