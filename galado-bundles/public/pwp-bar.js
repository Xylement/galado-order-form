/**
 * Staged PWP flow + sticky-bar summary (owner 2026-08-04 r7, Casetify model).
 *
 * Nothing is carted from the PDP any more: combo/shelf picks are STAGED here
 * client-side, the sticky Buy Now (snippet #7's global, taken over below)
 * sends case + stage to the atomic galado_pwp_checkout endpoint, and the
 * server re-validates everything. No case selected = error highlight, no
 * request. A caseless PWP cart can therefore never exist in the honest flow;
 * the server-side case gates remain as defence in depth.
 *
 * The bar summarises the staged configuration: "N items", a red Discount
 * chip, and final price with the original struck. Reloading the page clears
 * the stage (nothing was carted), same as Casetify.
 */
(function () {
  'use strict';
  var CFG = window.GALADO_PWP_BAR || {};
  if (!CFG.checkout_url) return;

  var stage = [];        // staged picks: {type:'combo'|'addon', ...}
  var serverUsed = {};   // circles already claimed by lines in the cart
  var casePrice = null;  // selected variation display price
  var caseVid = 0;
  var caseModel = '';
  var sum = null;
  var busy = false;
  // The anchor is the page's own product. Simple anchors (charm pages) are
  // priced from load and need no variation; variable anchors track
  // found_variation exactly as before (owner r14: one method everywhere).
  var anchor = CFG.anchor || {};
  var anchorSimple = anchor.type === 'simple';
  if (anchorSimple) casePrice = parseFloat(anchor.price) || 0;

  function rm(n) { n = Math.round((+n || 0) * 100) / 100; return 'RM' + n.toFixed(2); }
  function form() { return document.querySelector('form.variations_form.cart') || document.querySelector('form.variations_form') || document.querySelector('form.cart'); }
  function info() { return document.querySelector('#galado-sticky-cart .galado-sticky-info'); }

  function usedCircle(circle) {
    if (!circle) return false;
    if (serverUsed[circle]) return true;
    for (var i = 0; i < stage.length; i++) {
      if (stage[i].type === 'addon' && stage[i].circle === circle && stage[i].pwp) return true;
    }
    return false;
  }

  // ---- stage API for the module scripts ----------------------------------
  window.GALADO_PWP = {
    isUsed: usedCircle,
    stageAddon: function (item) {
      var own = +item.own || 0;
      var ap = +item.addon_price || 0;
      var pwp = ap > 0 && ap < own && !usedCircle(item.circle);
      var entry = {
        type: 'addon',
        product_id: +item.product_id || 0,
        variation_id: +item.variation_id || 0,
        name: String(item.name || ''),
        circle: String(item.circle || item.product_id || ''),
        own: own > 0 ? own : ap,
        promised: pwp ? ap : (own > 0 ? own : ap),
        pwp: pwp,
        tier_key: String(item.tier_key || ''),
        tiers: item.tiers || null
      };
      stage.push(entry);
      render();
      return { price: entry.promised, reused: !pwp && ap > 0 };
    },
    stageCombo: function (item) {
      // One protection set per case (owner r10): staging a set replaces any
      // previously staged one - two glasses cannot go on one phone, and the
      // server prices only one set per case anyway.
      stage = stage.filter(function (s) { return s.type !== 'combo'; });
      stage.push({
        type: 'combo',
        slug: String(item.slug || ''),
        model: String(item.model || ''),
        extra: item.extra || {},
        name: String(item.name || ''),
        own: +item.own || 0,
        promised: +item.promised || 0
      });
      render();
    },
    count: function () { return stage.length; }
  };

  function totals() {
    var own = 0, pay = 0;
    for (var i = 0; i < stage.length; i++) { own += stage[i].own; pay += stage[i].promised; }
    // Quantity tier promos: percentage off the tier group's staged lines
    // once the count is met (server recomputes with the full basket, which
    // can only match or beat this promise).
    var groups = {};
    for (var j = 0; j < stage.length; j++) {
      var s = stage[j];
      if (s.type !== 'addon' || !s.tier_key || !s.tiers) continue;
      var g = groups[s.tier_key] = groups[s.tier_key] || { own: 0, n: 0, tiers: s.tiers };
      g.own += s.own;
      g.n += 1;
    }
    for (var k in groups) {
      var gg = groups[k], pct = 0;
      for (var t = 0; t < gg.tiers.length; t++) {
        if (gg.n >= gg.tiers[t][0]) pct = gg.tiers[t][1];
      }
      if (pct > 0) {
        var disc = Math.round(gg.own * pct) / 100;
        pay -= disc;
      }
    }
    return { own: own, pay: pay, saved: Math.max(0, own - pay) };
  }

  // ---- bar ----------------------------------------------------------------
  function render() {
    var h = info();
    if (!h) return;
    var name = h.querySelector('.galado-sticky-name');
    var price = h.querySelector('.galado-sticky-price');

    if (casePrice === null && !stage.length) {
      if (sum) sum.hidden = true;
      if (name) name.style.display = '';
      if (price) price.style.display = '';
      return;
    }

    if (!sum) {
      sum = document.createElement('span');
      sum.className = 'gld-pwp-sum';
      var top = document.createElement('span');
      top.className = 'gld-pwp-sum__top';
      var items = document.createElement('span');
      items.className = 'gld-pwp-sum__items';
      items.setAttribute('data-gld-items', '');
      var disc = document.createElement('span');
      disc.className = 'gld-pwp-sum__disc';
      disc.setAttribute('data-gld-disc', '');
      disc.hidden = true;
      top.appendChild(items);
      top.appendChild(disc);
      var line = document.createElement('span');
      line.className = 'gld-pwp-sum__price';
      var fin = document.createElement('b');
      fin.setAttribute('data-gld-final', '');
      var orig = document.createElement('s');
      orig.setAttribute('data-gld-orig', '');
      orig.hidden = true;
      line.appendChild(fin);
      line.appendChild(orig);
      sum.appendChild(top);
      sum.appendChild(line);
      h.appendChild(sum);
    }
    if (name) name.style.display = 'none';
    if (price) price.style.display = 'none';
    sum.hidden = false;

    var t = totals();
    var n = stage.length + (casePrice !== null ? 1 : 0);
    var final = (casePrice || 0) + t.pay;

    sum.querySelector('[data-gld-items]').textContent =
      n + ' ' + (n === 1 ? CFG.i18n.item : CFG.i18n.items);
    var d = sum.querySelector('[data-gld-disc]');
    if (t.saved > 0) { d.hidden = false; d.textContent = (CFG.i18n.discount || 'Discount') + ' ' + rm(t.saved); }
    else d.hidden = true;
    sum.querySelector('[data-gld-final]').textContent = rm(final);
    var o = sum.querySelector('[data-gld-orig]');
    if (t.saved > 0) { o.hidden = false; o.textContent = rm(final + t.saved); }
    else o.hidden = true;
    sum.classList.toggle('has-save', t.saved > 0);
  }

  // ---- case tracking ------------------------------------------------------
  function bindCase() {
    if (!window.jQuery) return;
    window.jQuery('form.variations_form')
      .on('found_variation', function (e, v) {
        if (v) {
          if (typeof v.display_price !== 'undefined') {
            var p = parseFloat(v.display_price);
            if (!isNaN(p)) casePrice = p;
          }
          if (v.variation_id) caseVid = parseInt(v.variation_id, 10) || 0;
          var m = v.attributes && (v.attributes.attribute_pa_model || v.attributes.attribute_model || '');
          if (m && m !== caseModel) {
            if (caseModel) dropStaleCombos(m);
            caseModel = m;
          }
        }
        render();
      })
      .on('reset_data hide_variation', function () { casePrice = null; caseVid = 0; render(); });
  }

  /** A staged protection set is model-specific: if the shopper changes model,
   * stale sets silently shipping the WRONG glass would be worse than losing
   * the pick, so they drop with a small note. */
  function dropStaleCombos(newModel) {
    var before = stage.length;
    stage = stage.filter(function (s) { return s.type !== 'combo' || s.model === newModel; });
    if (stage.length !== before) toast(CFG.i18n.combo_dropped);
  }

  // ---- Buy Now takeover ---------------------------------------------------
  function highlightMissing() {
    var selects = document.querySelectorAll('form.cart select, form.variations_form select');
    for (var i = 0; i < selects.length; i++) {
      if (!selects[i].value) {
        var sel = selects[i];
        sel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        sel.style.transition = 'box-shadow 0.3s,border-color 0.3s';
        sel.style.boxShadow = '0 0 0 3px rgba(228,0,43,.35)';
        sel.style.borderColor = '#E4002B';
        setTimeout(function () { sel.style.boxShadow = ''; sel.style.borderColor = ''; }, 2000);
        return true;
      }
    }
    return false;
  }

  function toast(msg) {
    if (!msg) return;
    var t = document.createElement('div');
    t.className = 'gld-pwp-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 3500);
  }

  /** Instant feedback on the buy buttons while the atomic add runs (owner
   * r8: the pause read as "nothing happened" and invited double taps). */
  function setBusyUi(on) {
    var btns = document.querySelectorAll('.galado-sticky-btn, form.cart .single_add_to_cart_button');
    Array.prototype.forEach.call(btns, function (b) {
      if (on) {
        if (!b.getAttribute('data-gld-idle')) b.setAttribute('data-gld-idle', b.textContent);
        b.textContent = CFG.i18n.adding;
        b.style.opacity = '.65';
        b.style.pointerEvents = 'none';
      } else {
        var idle = b.getAttribute('data-gld-idle');
        if (idle) b.textContent = idle;
        b.style.opacity = '';
        b.style.pointerEvents = '';
      }
    });
  }

  function checkout() {
    if (busy) return;
    var f = form();
    if (!f) return;
    var btn = f.querySelector('.single_add_to_cart_button');
    var blocked = btn && (btn.classList.contains('disabled') || btn.classList.contains('wc-variation-selection-needed'));
    if (!anchorSimple && (blocked || !caseVid)) {
      highlightMissing();
      toast(anchor.is_case === false ? CFG.i18n.pick_options : CFG.i18n.pick_case);
      return;
    }
    busy = true;
    setBusyUi(true);
    // Transport: urlencoded by default - the exact format every other module
    // endpoint has used successfully - switching to multipart ONLY when a
    // real file is attached (then $_FILES must travel too). String fields
    // feed $_POST either way, so WCPA's posted name fields always arrive.
    var fd = new FormData(f);
    // CRITICAL: the variations form carries a hidden add-to-cart input. If it
    // reaches the server, WooCommerce's own form handler (wp_loaded) adds the
    // case and REDIRECTS to the cart BEFORE wc-ajax ever dispatches - the
    // fetch then lands on cart-page HTML and the whole flow reads as a
    // generic failure (owner's 2026-08-04 "could not add to basket").
    fd.delete('add-to-cart');
    fd.delete('added-to-cart');
    // Simple-product forms carry product_id only on the submit button, which
    // FormData excludes - supply it from the localized anchor.
    if (!fd.get('product_id') && anchor.product_id) fd.set('product_id', String(anchor.product_id));
    if (!fd.get('variation_id') && caseVid) fd.set('variation_id', String(caseVid));
    if (!fd.get('quantity')) fd.set('quantity', '1');
    fd.set('gld_stage', JSON.stringify(stage));

    var hasFile = false;
    var fileInputs = f.querySelectorAll('input[type=file]');
    for (var i = 0; i < fileInputs.length; i++) {
      if (fileInputs[i].files && fileInputs[i].files.length) { hasFile = true; break; }
    }
    var opts = { method: 'POST', credentials: 'same-origin' };
    if (hasFile) {
      opts.body = fd;
    } else {
      var body = new URLSearchParams();
      fd.forEach(function (v, k) { if (typeof v === 'string') body.append(k, v); });
      opts.headers = { 'content-type': 'application/x-www-form-urlencoded' };
      opts.body = body.toString();
    }

    fetch(CFG.checkout_url, opts)
      .then(function (r) { return r.text(); })
      .then(function (txt) {
        var res = null;
        try { res = JSON.parse(txt); } catch (err) {
          // Server answered non-JSON (fatal HTML, edge page): keep the first
          // chunk in the console so a screenshot of it identifies the cause.
          console.error('galado pwp_checkout: non-JSON response:', String(txt).slice(0, 400));
        }
        if (res && res.ok && res.redirect) { window.location.href = res.redirect; return; }
        busy = false;
        setBusyUi(false);
        toast((res && res.message) || CFG.i18n.failed);
      })
      .catch(function () { busy = false; setBusyUi(false); toast(CFG.i18n.failed); });
  }

  function nativeBuy() {
    var btn = document.querySelector('.single_add_to_cart_button');
    if (!btn) return;
    if (btn.classList.contains('disabled') || btn.classList.contains('wc-variation-selection-needed')) {
      highlightMissing();
      return;
    }
    btn.click();
  }

  function bindBuyNow() {
    // Snippet #7's sticky button calls this global; with a stage we own the
    // flow, with an empty stage the native single-product buy is untouched.
    window.galadoStickyBuy = function () {
      if (!stage.length) { nativeBuy(); return; }
      checkout();
    };
    var f = form();
    if (f) {
      f.addEventListener('submit', function (e) {
        if (!stage.length) return;
        e.preventDefault();
        checkout();
      });
    }
  }

  // Coming BACK from the cart restores this page from the bfcache with the
  // button still mid-flight, so it sat on "Adding..." until a manual reload
  // (owner r24). Reset the UI and re-read the cart on every restore.
  window.addEventListener('pageshow', function (e) {
    busy = false;
    setBusyUi(false);
    if (e.persisted && CFG.state_url) {
      fetch(CFG.state_url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (s) { state = s; render(); })
        .catch(function () {});
    }
  });

  function boot() {
    bindCase();
    bindBuyNow();
    if (CFG.state_url) {
      fetch(CFG.state_url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (s) {
          if (s && s.used) {
            for (var i = 0; i < s.used.length; i++) serverUsed[String(s.used[i])] = true;
          }
          document.dispatchEvent(new CustomEvent('gld-pwp-used-loaded'));
        })
        .catch(function () {});
    }
    render();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
