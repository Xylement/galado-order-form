/**
 * PDP accessory add-on shelves: circle-first selection. Simple items add in
 * one tap; variable items open a circle row (image chips or colour-style
 * dots) under the shelf, Add gated until an option is picked. All adds are
 * re-validated server-side.
 */
(function () {
  'use strict';
  var CFG = window.GALADO_ADDONS || {};
  var sections = document.querySelectorAll('.gld-addons');
  if (!sections.length || !CFG.groups) return;

  function rm(n) { n = Math.round((+n || 0) * 100) / 100; return 'RM' + (n % 1 === 0 ? String(n) : n.toFixed(2)); }

  function groupData(slug) {
    for (var i = 0; i < CFG.groups.length; i++) {
      if (CFG.groups[i].slug === slug) return CFG.groups[i];
    }
    return null;
  }

  function itemData(group, key) {
    for (var i = 0; i < group.items.length; i++) {
      if (String(group.items[i].key) === String(key)) return group.items[i];
    }
    return null;
  }

  function bindSection(section) {
    var group = groupData(section.getAttribute('data-group'));
    if (!group) return;
    var optsHost = section.querySelector('[data-gld-opts]');
    var note = section.querySelector('[data-gld-note]');
    var open = null; // {pid, chosen}

    Array.prototype.forEach.call(section.querySelectorAll('.gld-addon'), function (card) {
      var key = card.getAttribute('data-key');
      var btn = card.querySelector('[data-gld-addon-add]');
      var item = itemData(group, key);
      if (!item) return;
      btn.disabled = false; // server ships disabled for no-JS; JS takes over

      btn.addEventListener('click', function () {
        if (item.type === 'simple') {
          add(section, item.product_id, 0, btn, {
            name: item.name, price: item.price, was: item.was,
            circle: String(item.key), addon_price: item.addon_price,
            own: item.was > 0 ? item.was : item.price
          });
          return;
        }
        // Variable and grouped items: toggle the option row.
        if (open && open.pid === key) { closeOpts(); return; }
        openOpts(card, item);
      });
    });

    function openOpts(card, item) {
      open = { pid: String(item.key), chosen: 0, type: item.type };
      section.querySelectorAll('.gld-addon.is-open').forEach
        ? section.querySelectorAll('.gld-addon.is-open').forEach(function (c) { c.classList.remove('is-open'); })
        : null;
      card.classList.add('is-open');
      optsHost.hidden = false;
      optsHost.textContent = '';

      var title = document.createElement('p');
      title.className = 'gld-addons__optstitle';
      title.textContent = item.name;
      optsHost.appendChild(title);

      var row = document.createElement('div');
      row.className = 'gld-addons__optrow';
      item.options.forEach(function (o) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'gld-addons__opt' + (o.thumb ? '' : ' is-text');
        b.setAttribute('aria-label', o.label);
        if (o.thumb) {
          var img = document.createElement('img');
          img.src = o.thumb; img.alt = ''; img.width = 56; img.height = 56;
          b.appendChild(img);
        }
        var t = document.createElement('span');
        t.className = 'gld-addons__optlabel';
        // Mixed-price groups price every chip; uniform groups only the odd one.
        t.textContent = o.label + ((item.varies || o.price !== item.price) ? ' ' + rm(o.price) : '');
        b.appendChild(t);
        b.addEventListener('click', function () {
          open.chosen = o.id;
          Array.prototype.forEach.call(row.children, function (n) { n.classList.remove('is-on'); });
          b.classList.add('is-on');
          go.disabled = false;
          go.textContent = CFG.i18n.add_basket || 'Add to Basket';
        });
        row.appendChild(b);
      });
      optsHost.appendChild(row);

      var go = document.createElement('button');
      go.type = 'button';
      go.className = 'gld-addons__go';
      go.disabled = true;
      go.textContent = CFG.i18n.pick;
      go.addEventListener('click', function () {
        if (!open || !open.chosen) { if (note) note.textContent = CFG.i18n.pick; return; }
        // Grouped: the chosen option IS the product. Variable: it is the variation.
        var picked = null;
        for (var i = 0; i < item.options.length; i++) {
          if (String(item.options[i].id) === String(open.chosen)) { picked = item.options[i]; break; }
        }
        var meta = {
          name: item.name + (picked ? ' (' + picked.label + ')' : ''),
          price: picked ? picked.price : item.price,
          was: item.was,
          circle: String(item.key),
          addon_price: item.addon_price,
          own: item.was > 0 ? item.was : (picked ? picked.price : item.price)
        };
        if (item.type === 'group') add(section, open.chosen, 0, go, meta);
        else add(section, item.product_id, open.chosen, go, meta);
      });
      optsHost.appendChild(go);
    }

    function closeOpts() {
      open = null;
      optsHost.hidden = true;
      optsHost.textContent = '';
      Array.prototype.forEach.call(section.querySelectorAll('.gld-addon.is-open'), function (c) { c.classList.remove('is-open'); });
    }

    section.__close = closeOpts;
  }

  function add(section, productId, variationId, btn, meta) {
    var note = section.querySelector('[data-gld-note]');
    if (CFG.preview) { if (note) note.textContent = CFG.i18n.preview; return; }
    if (btn.disabled) return;

    // Staged flow (owner r7): picks live client-side until the sticky Buy Now
    // sends case + stage to the atomic endpoint. Nothing is carted here.
    if (window.GALADO_PWP && meta) {
      var staged = window.GALADO_PWP.stageAddon({
        product_id: productId,
        variation_id: variationId,
        name: meta.name,
        own: meta.own,
        addon_price: meta.addon_price,
        circle: meta.circle
      });
      meta.price = staged.price;
      if (staged.reused) meta.was = 0;
      var idleStaged = btn.textContent;
      btn.textContent = CFG.i18n.added;
      if (section.__close) section.__close();
      showAdded(section, meta);
      markUsedCards();
      setTimeout(function () { btn.textContent = idleStaged; }, 1200);
      return;
    }

    btn.disabled = true;
    var idle = btn.textContent;
    btn.textContent = CFG.i18n.adding;

    var body = new URLSearchParams();
    body.set('product_id', productId);
    if (variationId) body.set('variation_id', variationId);

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
      }
      btn.textContent = CFG.i18n.added;
      if (res && res.reused && meta) { meta.price = meta.was || meta.price; meta.reused = true; }
      // Collapse the option panel FIRST, then confirm: the layout shift from
      // the collapse used to leave the viewport on the description tabs
      // (owner 2026-08-04 r4); with the panel gone, showAdded anchors the
      // viewport on the confirmation with the shelf still in view.
      if (section.__close) section.__close();
      showAdded(section, meta);
      if (res && res.state) {
        applyState(res.state);
        if (window.GALADO_PWP_REFRESH) window.GALADO_PWP_REFRESH(res.state);
      }
      setTimeout(function () { btn.textContent = idle; }, 1200);
    }).catch(function () {
      btn.disabled = false;
      btn.textContent = idle;
      if (note) note.textContent = CFG.i18n.failed;
    });
  }

  /** Post-add confirmation: what was added, the with-case saving, basket link. */
  function showAdded(section, meta) {
    var note = section.querySelector('[data-gld-note]');
    if (!note || !meta) return;
    note.textContent = '';
    var box = document.createElement('span');
    box.className = 'gld-added';
    var line = document.createElement('b');
    line.textContent = '\u2713 ' + meta.name + ' ' + CFG.i18n.added_lbl;
    box.appendChild(line);
    if (meta.was > 0 && meta.was > meta.price) {
      var sv = document.createElement('span');
      sv.className = 'gld-added__save';
      sv.textContent = CFG.i18n.you_saved + ' ' + rm(meta.was - meta.price);
      box.appendChild(sv);
    }
    // No basket link here (owner 2026-08-04 r6): the journey continues to the
    // case via the sticky Buy Now, never around it.
    note.appendChild(box);
    // Anchor the confirmation mid-screen so "added to basket" is the focus
    // and the shelf stays visible above it.
    requestAnimationFrame(function () {
      if (note.scrollIntoView) note.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  }

  /** Hide the WCPA accessory rows this shelf replaces (admin-editable list). */
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

  /** Mark circles whose with-case price is already claimed in this cart:
   * the strike goes and the normal price shows (no label, owner 2026-08-04
   * round 2 - the price speaks for itself). */
  function applyState(state) {
    if (!state || !state.used) return;
    Array.prototype.forEach.call(sections, function (section) {
      var group = groupData(section.getAttribute('data-group'));
      if (!group) return;
      Array.prototype.forEach.call(section.querySelectorAll('.gld-addon'), function (card) {
        var item = itemData(group, card.getAttribute('data-key'));
        if (!item || !(item.was > 0)) return;
        var priceEl = card.querySelector('.gld-addon__price');
        if (!priceEl) return;
        if (state.used.indexOf(String(item.key)) !== -1) {
          card.classList.add('is-used');
          priceEl.textContent = '+' + rm(item.was);
        } else if (card.classList.contains('is-used')) {
          card.classList.remove('is-used');
          priceEl.textContent = '';
          priceEl.appendChild(document.createTextNode('+' + rm(item.price) + ' '));
          var s = document.createElement('s');
          s.textContent = rm(item.was);
          priceEl.appendChild(s);
        }
      });
    });
  }

  /** Circles claimed either by cart lines (server used, loaded by the bar
   * script) or by picks staged on this page: strike gone, normal price. */
  function markUsedCards() {
    if (!window.GALADO_PWP) return;
    Array.prototype.forEach.call(sections, function (section) {
      var group = groupData(section.getAttribute('data-group'));
      if (!group) return;
      Array.prototype.forEach.call(section.querySelectorAll('.gld-addon'), function (card) {
        var item = itemData(group, card.getAttribute('data-key'));
        if (!item || !(item.was > 0)) return;
        if (!window.GALADO_PWP.isUsed(String(item.key))) return;
        card.classList.add('is-used');
        var priceEl = card.querySelector('.gld-addon__price');
        if (priceEl) priceEl.textContent = '+' + rm(item.was);
      });
    });
  }

  function boot() {
    Array.prototype.forEach.call(sections, bindSection);
    dedupeWcpa();
    if (window.GALADO_PWP) {
      document.addEventListener('gld-pwp-used-loaded', markUsedCards);
      markUsedCards();
    } else {
      fetch(CFG.state_url || (CFG.ajax || '').replace('galado_addon_add', 'galado_pwp_state'), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(applyState)
        .catch(function () {});
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
