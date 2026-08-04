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

  function itemData(group, pid) {
    for (var i = 0; i < group.items.length; i++) {
      if (String(group.items[i].product_id) === String(pid)) return group.items[i];
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
      var pid = card.getAttribute('data-product');
      var btn = card.querySelector('[data-gld-addon-add]');
      var item = itemData(group, pid);
      if (!item) return;
      btn.disabled = false; // server ships disabled for no-JS; JS takes over

      btn.addEventListener('click', function () {
        if (item.type === 'simple') { add(section, item, 0, btn); return; }
        // Variable: toggle the option row for this item.
        if (open && open.pid === pid) { closeOpts(); return; }
        openOpts(card, item);
      });
    });

    function openOpts(card, item) {
      open = { pid: String(item.product_id), chosen: 0 };
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
        t.textContent = o.label + (o.price !== item.price ? ' ' + rm(o.price) : '');
        b.appendChild(t);
        b.addEventListener('click', function () {
          open.chosen = o.id;
          Array.prototype.forEach.call(row.children, function (n) { n.classList.remove('is-on'); });
          b.classList.add('is-on');
          go.disabled = false;
          go.textContent = CFG.i18n.add + '  ' + rm(o.price);
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
        add(section, item, open.chosen, go);
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

  function add(section, item, variationId, btn) {
    var note = section.querySelector('[data-gld-note]');
    if (CFG.preview) { if (note) note.textContent = CFG.i18n.preview; return; }
    if (btn.disabled) return;

    btn.disabled = true;
    var idle = btn.textContent;
    btn.textContent = CFG.i18n.adding;

    var body = new URLSearchParams();
    body.set('product_id', item.product_id);
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
      if (note) note.textContent = '';
      setTimeout(function () {
        btn.textContent = idle;
        if (section.__close) section.__close();
      }, 1200);
    }).catch(function () {
      btn.disabled = false;
      btn.textContent = idle;
      if (note) note.textContent = CFG.i18n.failed;
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

  function boot() {
    Array.prototype.forEach.call(sections, bindSection);
    dedupeWcpa();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
