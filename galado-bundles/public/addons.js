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

  /** The PDP's model <select>: the global pa_model taxonomy on most products,
   * a local "Model" attribute on the rest. Same pair combos.js reads. */
  function modelSelect() {
    return document.querySelector('form.variations_form select[name="attribute_pa_model"]')
        || document.querySelector('form.variations_form select[name="attribute_model"]');
  }

  /**
   * The select's value as the slug the server keyed by_model with.
   *
   * A global attribute already puts the term SLUG in the value and slugifying a
   * slug returns it unchanged; a local attribute puts the display TEXT there
   * ("Apple Watch 45mm") and this mirrors WordPress sanitize_title, which is what
   * norm_model() applied server-side. So one pass is exact for both kinds, and
   * unlike combos.js this needs no slug -> label map to reverse.
   */
  function currentModel() {
    var sel = modelSelect();
    var raw = sel && sel.value ? sel.value : '';
    if (!raw) return '';
    return String(raw).toLowerCase()
      .replace(/&.+?;/g, '')
      .replace(/\./g, '-')
      .replace(/[^a-z0-9 _-]/g, '')
      .replace(/\s+/g, '-')
      .replace(/-+/g, '-')
      .replace(/^-|-$/g, '');
  }

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

      var activate = function () {
        // "Match PDP model": no picker, the size comes from the page's own
        // model select. Resolved at tap so a model changed after the shelf
        // rendered is still honoured.
        if (item.mode === 'model_match') {
          var model = currentModel();
          if (!model) { if (note) note.textContent = CFG.i18n.pick_model; return; }
          var hit = item.by_model && item.by_model[model];
          if (!hit) { if (note) note.textContent = CFG.i18n.no_model; return; }
          if (note) note.textContent = '';
          add(section, item.product_id, hit.id, btn, {
            name: item.name, price: hit.price, was: item.was,
            circle: String(item.key), addon_price: item.addon_price,
            own: item.was > 0 ? item.was : hit.price,
            // Binds the staged pick to this model so the bar can drop it if
            // the shopper changes size afterwards.
            model: model
          });
          return;
        }
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
      };
      btn.addEventListener('click', activate);
      // The circle image is the biggest tap target - same action (owner r20).
      var circle = card.querySelector('.gld-addon__circle');
      if (circle) circle.addEventListener('click', activate);
    });

    function optById(item, id) {
      for (var i = 0; i < item.options.length; i++) {
        if (String(item.options[i].id) === String(id)) return item.options[i];
      }
      return null;
    }

    function openOpts(card, item) {
      // Multi-select (owner r13): circles WITHOUT a PWP price (the Stylink
      // Clip-Ons) let the shopper tick several designs and add them in one
      // go. PWP circles stay single-pick - that price is one per order.
      open = {
        pid: String(item.key),
        chosen: 0,
        picked: [],
        multi: item.type === 'group' && !(item.addon_price > 0) && !(item.was > 0),
        type: item.type
      };
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

      // Quantity tier teaser (owner r20, clip-ons): picked thumbnails plus
      // dashed + circles up to the next tier, title counting down to it.
      var tiers = (group.tiers && open.multi) ? group.tiers : null;
      var tierBox = null;
      if (tiers) {
        tierBox = document.createElement('div');
        tierBox.className = 'gld-tier';
        var tTitle = document.createElement('p');
        tTitle.className = 'gld-tier__title';
        var tSlots = document.createElement('div');
        tSlots.className = 'gld-tier__slots';
        tierBox.appendChild(tTitle);
        tierBox.appendChild(tSlots);
        optsHost.appendChild(tierBox);
      }
      function renderTier() {
        if (!tierBox) return;
        var n = open.picked.length;
        var cur = 0, next = null;
        for (var ti = 0; ti < tiers.length; ti++) {
          if (n >= tiers[ti][0]) cur = tiers[ti][1];
          else if (!next) next = tiers[ti];
        }
        var tpl;
        if (!next) tpl = CFG.i18n.tier_max;
        else if (n === 0) tpl = CFG.i18n.tier_start;
        else tpl = CFG.i18n.tier_more;
        var need = next ? (next[0] - n) : 0;
        var pct = next ? next[1] : cur;
        tierBox.querySelector('.gld-tier__title').textContent =
          tpl.replace('{n}', String(need)).replace('{p}', String(pct));
        tierBox.classList.toggle('is-on', cur > 0);

        var slots = tierBox.querySelector('.gld-tier__slots');
        slots.textContent = '';
        var target = next ? next[0] : tiers[tiers.length - 1][0];
        var show = Math.max(target, Math.min(n, tiers[tiers.length - 1][0]));
        for (var si = 0; si < show; si++) {
          if (si < n) {
            var o3 = optById(item, open.picked[si]);
            var slot = document.createElement('span');
            slot.className = 'gld-tier__slot';
            if (o3 && o3.thumb) {
              var im = document.createElement('img');
              im.src = o3.thumb; im.alt = '';
              slot.appendChild(im);
            }
            slots.appendChild(slot);
          } else {
            var plus = document.createElement('span');
            plus.className = 'gld-tier__plus';
            plus.textContent = '+';
            slots.appendChild(plus);
          }
        }
      }
      renderTier();

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
          var lbl = CFG.i18n.add_basket || 'Add to Basket';
          if (open.multi) {
            var at = open.picked.indexOf(o.id);
            if (at >= 0) { open.picked.splice(at, 1); b.classList.remove('is-on'); }
            else { open.picked.push(o.id); b.classList.add('is-on'); }
            go.disabled = !open.picked.length;
            go.textContent = open.picked.length > 1 ? lbl + ' (' + open.picked.length + ')' : lbl;
            renderTier();
            return;
          }
          open.chosen = o.id;
          Array.prototype.forEach.call(row.children, function (n) { n.classList.remove('is-on'); });
          b.classList.add('is-on');
          go.disabled = false;
          go.textContent = lbl;
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
        if (open && open.multi) {
          var picks = open.picked.slice();
          if (!picks.length) { if (note) note.textContent = CFG.i18n.pick; return; }
          var first = optById(item, picks[0]);
          var metaAll = {
            name: picks.length === 1 && first ? item.name + ' (' + first.label + ')' : item.name + ' × ' + picks.length,
            price: 0, was: 0, circle: String(item.key), addon_price: 0, own: 0
          };
          if (window.GALADO_PWP) {
            picks.forEach(function (id) {
              var o2 = optById(item, id);
              window.GALADO_PWP.stageAddon({
                product_id: id, variation_id: 0,
                name: item.name + (o2 ? ' (' + o2.label + ')' : ''),
                own: o2 ? o2.price : item.price, addon_price: 0, circle: String(item.key),
                tier_key: group.tiers ? group.slug : '', tiers: group.tiers || null
              });
            });
            if (section.__close) section.__close();
            showAdded(section, metaAll);
            return;
          }
          addMany(section, picks, go, metaAll);
          return;
        }
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
      // Long option lists push the confirm button below the fold and shoppers
      // did not realise they still had to press it (owner r24). Bring it into
      // view as soon as the picker opens.
      requestAnimationFrame(function () {
        if (go.scrollIntoView) go.scrollIntoView({ behavior: 'smooth', block: 'center' });
      });
    }

    function closeOpts() {
      open = null;
      optsHost.hidden = true;
      optsHost.textContent = '';
      Array.prototype.forEach.call(section.querySelectorAll('.gld-addon.is-open'), function (c) { c.classList.remove('is-open'); });
    }

    section.__close = closeOpts;
  }

  /** Sequential fallback adds for a multi-pick (non-case surfaces have no
   * stage): one request per design, fragments refreshed as they land. */
  function addMany(section, ids, btn, meta) {
    var note = section.querySelector('[data-gld-note]');
    if (CFG.preview) { if (note) note.textContent = CFG.i18n.preview; return; }
    if (btn.disabled) return;
    btn.disabled = true;
    var idle = btn.textContent;
    btn.textContent = CFG.i18n.adding;
    var ok = 0, i = 0;
    function next() {
      if (i >= ids.length) {
        btn.disabled = false;
        btn.textContent = idle;
        if (ok) {
          if (section.__close) section.__close();
          showAdded(section, meta);
        }
        if (ok < ids.length && note) note.textContent = CFG.i18n.failed;
        return;
      }
      var body = new URLSearchParams();
      body.set('product_id', ids[i]);
      i++;
      fetch(CFG.ajax, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'content-type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (res && res.ok !== false) {
          ok++;
          if (res.fragments && window.jQuery) {
            var $ = window.jQuery;
            $.each(res.fragments, function (k, v) { $(k).replaceWith(v); });
            $(document.body).trigger('wc_fragments_refreshed');
          }
        }
        next();
      }).catch(function () { next(); });
    }
    next();
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
        circle: meta.circle,
        model: meta.model || ''
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
