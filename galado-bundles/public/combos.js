/**
 * PDP protector combos (ADDON-COMBOS-SPEC): hydrates the server-rendered
 * module off the WooCommerce variation events. All prices/availability come
 * from the localized per-model map (server-computed); every add is resolved
 * and validated server-side again.
 */
(function () {
  'use strict';
  var CFG = window.GALADO_COMBOS || {};
  var root = document.getElementById('gld-protect');
  if (!root || !CFG.cards) return;

  var model = '';                 // current pa_model slug, '' until chosen
  var picks = {};                 // combo -> slot -> attrKey -> value

  function rm(n) { n = Math.round((+n || 0) * 100) / 100; return 'RM' + (n % 1 === 0 ? String(n) : n.toFixed(2)); }

  function modelLabel(slug) { return (CFG.models && CFG.models[slug]) || slug; }

  function cards() { return Array.prototype.slice.call(root.querySelectorAll('.gld-combo')); }

  /** Not-ready is aria-disabled, NOT the native attribute: a natively disabled
   * button swallows the tap, and the spec wants a "Select your model first"
   * hint on exactly that tap. Native disabled is only used momentarily while a
   * request is in flight. */
  function setReady(btn, ready) {
    btn.classList.toggle('is-off', !ready);
    btn.setAttribute('aria-disabled', ready ? 'false' : 'true');
  }
  function isReady(btn) { return !btn.classList.contains('is-off') && !btn.disabled; }

  function update() {
    var head = root.querySelector('[data-gld-model]');
    if (head) head.textContent = model ? modelLabel(model) : 'phone';

    var visible = 0;
    cards().forEach(function (card) {
      var slug = card.getAttribute('data-combo');
      var map = CFG.cards[slug] || {};
      var info = model ? map[model] : null;

      if (model && (!info || !info.ok)) { card.hidden = true; return; }
      // Fallback combos (spec section 2's mutual exclusivity) stay hidden until
      // a model proves the fuller combo is unavailable; showing both pre-model
      // would break "never show both".
      if (!model && card.getAttribute('data-fallback') === '1') { card.hidden = true; return; }
      card.hidden = false; visible++;

      var now = card.querySelector('[data-gld-now]');
      var was = card.querySelector('[data-gld-was]');
      var chip = card.querySelector('[data-gld-chip]');
      var btn = card.querySelector('[data-gld-add]');
      var note = card.querySelector('[data-gld-note]');

      if (!info) {
        // No model yet: show the generic "from" state using the first ok model.
        var first = null;
        for (var k in map) { if (map[k] && map[k].ok) { first = map[k]; break; } }
        if (first) {
          now.textContent = rm(first.total);
          was.textContent = first.save > 0 ? rm(first.sum) : '';
          chip.hidden = !(first.save > 0);
          if (first.save > 0) chip.textContent = 'Save ' + rm(first.save);
        }
        setReady(btn, false);
        renderAxes(card, slug, null);
        return;
      }

      now.textContent = rm(info.total);
      was.textContent = info.save > 0 ? rm(info.sum) : '';
      chip.hidden = !(info.save > 0);
      if (info.save > 0) chip.textContent = 'Save ' + rm(info.save);

      renderAxes(card, slug, info.axes || {});
      var ready = axesResolved(slug, info.axes || {});
      setReady(btn, ready);
      if (note && ready) note.textContent = '';
    });

    root.style.display = model && visible === 0 ? 'none' : '';
  }

  /** Leftover axes (e.g. G-Armor phone colour) become chip rows on the card. */
  function renderAxes(card, slug, axes) {
    var host = card.querySelector('[data-gld-axes]');
    host.textContent = '';
    if (!axes) return;
    Object.keys(axes).forEach(function (slot) {
      Object.keys(axes[slot]).forEach(function (attr) {
        var values = axes[slot][attr];
        var row = document.createElement('div');
        row.className = 'gld-combo__axis';
        var lab = document.createElement('span');
        lab.className = 'gld-combo__axislab';
        lab.textContent = attr.replace(/^pa_/, '').replace(/[-_]/g, ' ');
        row.appendChild(lab);
        Object.keys(values).forEach(function (val) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'gld-combo__opt';
          b.textContent = values[val];
          var picked = picks[slug] && picks[slug][slot] && picks[slug][slot][attr] === val;
          if (picked) b.classList.add('is-on');
          b.addEventListener('click', function () {
            picks[slug] = picks[slug] || {};
            picks[slug][slot] = picks[slug][slot] || {};
            picks[slug][slot][attr] = val;
            update();
          });
          row.appendChild(b);
        });
        host.appendChild(row);
      });
    });
  }

  function axesResolved(slug, axes) {
    for (var slot in axes) {
      for (var attr in axes[slot]) {
        if (!picks[slug] || !picks[slug][slot] || !picks[slug][slot][attr]) return false;
      }
    }
    return true;
  }

  function add(card) {
    var slug = card.getAttribute('data-combo');
    var btn = card.querySelector('[data-gld-add]');
    var note = card.querySelector('[data-gld-note]');

    if (!isReady(btn)) {
      // The spec's on-tap hint for the gated state.
      if (note) note.textContent = model ? CFG.i18n.pick_axis : CFG.i18n.pick_model;
      return;
    }
    if (CFG.preview) { if (note) note.textContent = CFG.i18n.preview; return; }

    btn.disabled = true;
    var idle = btn.textContent;
    btn.textContent = CFG.i18n.adding;

    var body = new URLSearchParams();
    body.set('combo', slug);
    body.set('model', model);
    var p = picks[slug] || {};
    Object.keys(p).forEach(function (slot) {
      Object.keys(p[slot]).forEach(function (attr) {
        body.set('extra[' + slot + '][' + attr + ']', p[slot][attr]);
      });
    });

    fetch(CFG.ajax, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'content-type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (r) { return r.json(); }).then(function (res) {
      btn.disabled = false;
      if (res && res.ok === false) {
        btn.textContent = idle;
        if (note) note.textContent = res.message || CFG.i18n.failed;
        return;
      }
      if (res && res.fragments && window.jQuery) {
        var $ = window.jQuery;
        $.each(res.fragments, function (k, v) { $(k).replaceWith(v); });
        $(document.body).trigger('wc_fragments_refreshed');
        // No button element in the trigger: passing it makes WooCommerce
        // append its own half-styled "View basket" link into the card (the
        // misaligned pill). The confirmation below replaces it properly.
        $(document.body).trigger('added_to_cart', [res.fragments, res.cart_hash]);
      }
      btn.textContent = CFG.i18n.added;
      showAdded(card, slug);
      if (res && res.state && window.GALADO_PWP_REFRESH) window.GALADO_PWP_REFRESH(res.state);
      setTimeout(function () { btn.textContent = idle; }, 1400);
    }).catch(function () {
      btn.disabled = false;
      btn.textContent = idle;
      if (note) note.textContent = CFG.i18n.failed;
    });
  }

  /** Post-add confirmation (owner deck slides 6-7): WHAT was added, the
   * saving, and a proper basket link, in place of the note line. */
  function showAdded(card, slug) {
    var note = card.querySelector('[data-gld-note]');
    if (!note) return;
    var info = (CFG.cards[slug] || {})[model] || {};
    var title = (card.querySelector('.gld-combo__name') || {}).textContent || '';
    note.textContent = '';
    var box = document.createElement('span');
    box.className = 'gld-added';
    var line = document.createElement('b');
    line.textContent = '\u2713 ' + title + ' ' + CFG.i18n.added_for + ' ' + modelLabel(model);
    box.appendChild(line);
    if (info.save > 0) {
      var sv = document.createElement('span');
      sv.className = 'gld-added__save';
      sv.textContent = CFG.i18n.you_saved + ' ' + rm(info.save);
      box.appendChild(sv);
    }
    // No basket link here (owner 2026-08-04 r6): the journey continues to the
    // case via the sticky Buy Now, never around it.
    note.appendChild(box);
  }

  /** Hide the configured WCPA protector rows on pages where the module shows,
   * so the same protectors are not sold twice (spec section 3). */
  function dedupeWcpa() {
    var keys = CFG.hide || [];
    if (!keys.length) return;
    // Owner 2026-08-04: the WCPA Case/Total price summary is redundant on
    // pages where our modules sell the add-ons; remove it outright here.
    Array.prototype.forEach.call(document.querySelectorAll('.wcpa_price_summary'), function (el) {
      el.style.display = 'none';
    });
    var fields = document.querySelectorAll('.wcpa_form_item, [class*="wcpa_type_"], .wcpa-form .form-group');
    Array.prototype.forEach.call(fields, function (el) {
      var name = ((el.getAttribute('data-name') || '') + ' ' + el.textContent).toLowerCase();
      for (var i = 0; i < keys.length; i++) {
        if (name.indexOf(keys[i]) !== -1) { el.style.display = 'none'; return; }
      }
    });
  }

  function readModel() {
    var sel = document.querySelector('form.variations_form select[name="attribute_pa_model"]');
    model = sel && sel.value ? sel.value : '';
    update();
  }

  function bind() {
    cards().forEach(function (card) {
      var btn = card.querySelector('[data-gld-add]');
      // The server ships the button natively disabled for the no-JS case; once
      // hydrated, gating moves to aria-disabled so the hint tap works.
      btn.disabled = false;
      setReady(btn, false);
      btn.addEventListener('click', function () { add(card); });
    });

    // Native listener works with and without jQuery; Woo also fires jQuery
    // events on the form, which we use when present for reset coverage.
    var sel = document.querySelector('form.variations_form select[name="attribute_pa_model"]');
    if (sel) sel.addEventListener('change', readModel);
    if (window.jQuery) {
      window.jQuery('form.variations_form')
        .on('found_variation reset_data hide_variation', readModel);
    }

    dedupeWcpa();
    readModel();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
  else bind();
})();
