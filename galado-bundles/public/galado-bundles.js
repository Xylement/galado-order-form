(function () {
  'use strict';
  var CFG = window.GALADO_BUNDLES || {};

  function ev(name, params) {
    try {
      (window.dataLayer = window.dataLayer || []).push(Object.assign({ event: name }, params || {}));
      if (typeof window.gtag === 'function') window.gtag('event', name, params || {});
      if (window.clarity) window.clarity('set', name, (params && (params.bundle_key || params.bundle_name)) || '1');
    } catch (e) {}
  }
  function rm(n) { n = Math.round((+n || 0) * 100) / 100; return (n % 1 === 0) ? String(n) : n.toFixed(2); }
  function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }
  function parse(s, d) { try { return JSON.parse(s); } catch (e) { return d; } }

  function Sheet(title) {
    var veil = el('div', 'gld-sheet-veil');
    var sheet = el('div', 'gld-sheet');
    var head = el('div', 'gld-sheet__head');
    head.appendChild(el('div', 'gld-sheet__title', title));
    var x = el('button', 'gld-sheet__x'); x.setAttribute('aria-label', 'Close'); x.innerHTML = '&times;';
    head.appendChild(x);
    var body = el('div', 'gld-sheet__body');
    sheet.appendChild(head); sheet.appendChild(body);
    veil.appendChild(sheet);
    var last;
    function close() { veil.classList.remove('is-open'); veil.style.pointerEvents = 'none'; document.body.classList.remove('gld-noscroll'); if (last && last.focus) last.focus(); setTimeout(function () { if (veil.parentNode) veil.parentNode.removeChild(veil); }, 180); }
    x.addEventListener('click', close);
    veil.addEventListener('click', function (e) { if (e.target === veil) close(); });
    document.addEventListener('keydown', function esc(e) { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc); } });
    return {
      open: function (trigger) { last = trigger; document.body.appendChild(veil); document.body.classList.add('gld-noscroll'); requestAnimationFrame(function () { veil.classList.add('is-open'); }); var f = body.querySelector('button'); if (f) f.focus(); },
      close: close, body: body
    };
  }

  function optionData(sel) {
    var out = [];
    Array.prototype.forEach.call(sel.options, function (o) {
      if (!o.value) return;
      out.push({ id: o.value, attrs: parse(o.getAttribute('data-attrs'), {}), price: parseFloat(o.getAttribute('data-price')) || 0, thumb: o.getAttribute('data-thumb') || '', label: o.textContent });
    });
    return out;
  }

  function setupPick(pick, onChange) {
    var sel = pick.querySelector('.gld-pick__native');
    var axes = parseInt(pick.getAttribute('data-axes'), 10) || 1;
    var keys = parse(pick.getAttribute('data-axis-keys'), []);
    var labels = parse(pick.getAttribute('data-axis-labels'), []);
    var opts = optionData(sel);
    var minPrice = opts.reduce(function (m, o) { return Math.min(m, o.price); }, opts.length ? opts[0].price : 0);
    pick.__min = minPrice; pick.__price = minPrice; pick.__ready = false;

    if (axes >= 3 || !opts.length) { sel.classList.add('gld-pick__native--visible'); return; } // fallback to native
    sel.setAttribute('aria-hidden', 'true');

    function commit(variationId) {
      sel.value = variationId || '';
      var o = opts.filter(function (x) { return x.id === variationId; })[0];
      pick.__price = o ? o.price : minPrice;
      pick.__ready = !!variationId;
      onChange();
    }

    if (axes === 1) buildStrip(pick, sel, opts, labels[0] || 'option', commit);
    else buildTwoAxis(pick, sel, opts, keys, labels, commit);
  }

  function chip(o, primaryKey, selected) {
    var b = el('button', 'gld-chip' + (selected ? ' is-on' : '')); b.type = 'button';
    var val = o.attrs[primaryKey] || o.label;
    var img = el('span', 'gld-chip__img');
    if (o.thumb) { img.style.backgroundImage = 'url(' + o.thumb + ')'; }
    b.appendChild(img);
    b.appendChild(el('span', 'gld-chip__t', val));
    b.setAttribute('aria-label', val);
    return b;
  }

  function buildStrip(pick, sel, opts, label, commit) {
    var key = parse(pick.getAttribute('data-axis-keys'), [null])[0];
    // Compact by default: one field that opens the full grid in a bottom sheet,
    // so the card stays short instead of showing a long inline chip strip.
    var field = el('button', 'gld-pick__field'); field.type = 'button';
    field.innerHTML = '<span class="gld-pick__fieldtxt">Choose your ' + label + '</span><span class="gld-pick__chev">&rsaquo;</span>';
    var txt = field.querySelector('.gld-pick__fieldtxt');
    field.addEventListener('click', function () {
      var sh = Sheet('Choose your ' + label);
      var grid = el('div', 'gld-grid');
      opts.forEach(function (o) {
        var c = chip(o, key, sel.value === o.id);
        c.addEventListener('click', function () { select(o); sh.close(); });
        grid.appendChild(c);
      });
      sh.body.appendChild(grid); sh.open(field);
      ev('bundle_build_open', {});
    });
    function select(o) {
      txt.textContent = o.attrs[key] || o.label;
      field.classList.add('is-chosen');
      commit(o.id);
    }
    pick.appendChild(field);
  }

  function buildTwoAxis(pick, sel, opts, keys, labels, commit) {
    var modelKey = keys[0], colourKey = keys[1];
    var modelLabel = labels[0] || 'model', colourLabel = labels[1] || 'colour';
    var models = [], colours = [];
    opts.forEach(function (o) {
      if (models.indexOf(o.attrs[modelKey]) < 0) models.push(o.attrs[modelKey]);
      if (colours.indexOf(o.attrs[colourKey]) < 0) colours.push(o.attrs[colourKey]);
    });
    var chosenModel = null, chosenColour = null;

    var mBtn = el('button', 'gld-pick__field'); mBtn.type = 'button';
    mBtn.innerHTML = '<span class="gld-pick__fieldtxt">Choose your ' + modelLabel + '</span><span class="gld-pick__chev">&rsaquo;</span>';
    mBtn.addEventListener('click', function () { openModelSheet(); });

    var swWrap = el('div', 'gld-pick__swatches');
    var swLabel = el('div', 'gld-pick__swlabel', colourLabel.charAt(0).toUpperCase() + colourLabel.slice(1));
    var swRow = el('div', 'gld-swatches');
    colours.forEach(function (cv) {
      var s = el('button', 'gld-swatch'); s.type = 'button'; s.setAttribute('aria-label', cv);
      s.appendChild(el('span', 'gld-swatch__dot' + swatchClass(cv)));
      s.appendChild(el('span', 'gld-swatch__t', cv));
      s.addEventListener('click', function () { if (s.disabled) return; chosenColour = cv; paintSwatches(); resolve(); });
      swRow.appendChild(s); s.__val = cv;
    });
    swWrap.appendChild(swLabel); swWrap.appendChild(swRow);

    function available(model, colour) {
      return opts.some(function (o) { return o.attrs[modelKey] === model && o.attrs[colourKey] === colour; });
    }
    function paintSwatches() {
      Array.prototype.forEach.call(swRow.children, function (s) {
        var ok = !chosenModel || available(chosenModel, s.__val);
        s.disabled = !ok; s.classList.toggle('is-off', !ok);
        s.classList.toggle('is-on', chosenColour === s.__val);
      });
    }
    function resolve() {
      if (chosenModel && chosenColour) {
        var o = opts.filter(function (x) { return x.attrs[modelKey] === chosenModel && x.attrs[colourKey] === chosenColour; })[0];
        if (o) { commit(o.id); ev('bundle_build_complete', {}); return; }
      }
      commit('');
    }
    function openModelSheet() {
      var sh = Sheet('Choose your ' + modelLabel);
      var groups = groupByBrand(models);
      if (groups) {
        var tabs = el('div', 'gld-tabs'); var lists = {};
        Object.keys(groups).forEach(function (brand, i) {
          var t = el('button', 'gld-tab' + (i === 0 ? ' is-on' : '')); t.type = 'button'; t.textContent = brand;
          t.addEventListener('click', function () { Array.prototype.forEach.call(tabs.children, function (x) { x.classList.remove('is-on'); }); t.classList.add('is-on'); Object.keys(lists).forEach(function (b) { lists[b].style.display = b === brand ? 'block' : 'none'; }); });
          tabs.appendChild(t);
        });
        sh.body.appendChild(tabs);
        Object.keys(groups).forEach(function (brand, i) { var l = modelList(groups[brand], sh); l.style.display = i === 0 ? 'block' : 'none'; lists[brand] = l; sh.body.appendChild(l); });
      } else {
        sh.body.appendChild(modelList(models, sh));
      }
      sh.open(mBtn);
      function modelList(arr, sheet) {
        var list = el('div', 'gld-modellist');
        arr.forEach(function (mv) {
          var b = el('button', 'gld-modelrow' + (chosenModel === mv ? ' is-on' : '')); b.type = 'button'; b.textContent = mv;
          b.addEventListener('click', function () { chosenModel = mv; mBtn.querySelector('.gld-pick__fieldtxt').textContent = mv; mBtn.classList.add('is-chosen'); chosenColour = null; paintSwatches(); resolve(); sheet.close(); });
          list.appendChild(b);
        });
        return list;
      }
    }
    pick.appendChild(mBtn); pick.appendChild(swWrap);
    paintSwatches();
    ev('bundle_build_open', {});
  }

  function groupByBrand(models) {
    var g = {};
    models.forEach(function (m) {
      var l = m.toLowerCase(), brand = null;
      if (l.indexOf('iphone') >= 0 || l.indexOf('apple') >= 0) brand = 'Apple';
      else if (l.indexOf('galaxy') >= 0 || l.indexOf('samsung') >= 0) brand = 'Samsung';
      if (!brand) return;
      (g[brand] = g[brand] || []).push(m);
    });
    var keys = Object.keys(g), covered = keys.reduce(function (a, k) { return a + g[k].length; }, 0);
    return (keys.length >= 2 && covered === models.length) ? g : null;
  }
  function swatchClass(v) {
    var l = v.toLowerCase();
    if (l.indexOf('black') >= 0) return ' is-black';
    if (l.indexOf('white') >= 0) return ' is-white';
    if (l.indexOf('clear') >= 0 || l.indexOf('transparent') >= 0) return ' is-clear';
    return '';
  }

  function initCard(card) {
    var picks = Array.prototype.slice.call(card.querySelectorAll('.gld-pick'));
    var cta = card.querySelector('.gld-bundle__cta[data-action="add"]');
    var note = card.querySelector('.gld-bundle__note');
    var now = card.querySelector('.gld-bundle__price .now');
    var was = card.querySelector('.gld-bundle__price .was');
    var baseSum = parseFloat(card.getAttribute('data-sum')) || 0;
    var save = parseFloat(card.getAttribute('data-save')) || 0;
    var slug = card.getAttribute('data-slug');
    var idleLabel = cta ? cta.textContent : 'Add the set';

    function recalc() {
      var sum = baseSum, ready = true;
      picks.forEach(function (p) { sum += (p.__price - p.__min); if (!p.__ready) ready = false; });
      var total = sum - (save > 0 && save < sum ? save : 0);
      if (now) now.textContent = 'RM' + rm(total > 0 ? total : sum);
      if (was && save > 0) was.textContent = 'RM' + rm(sum);
      if (cta) {
        cta.classList.toggle('is-ready', ready);
        if (ready) { cta.textContent = idleLabel; cta.classList.remove('is-wait'); }
        else if (picks.length) cta.textContent = idleLabel;
      }
      if (ready && note) note.textContent = ''; // clear a lingering "please choose" nudge
      card.__ready = ready;
    }

    picks.forEach(function (p) { setupPick(p, recalc); });
    recalc();

    if (cta) cta.addEventListener('click', function () {
      if (picks.length && !card.__ready) {
        var missing = picks.filter(function (p) { return !p.__ready; });
        if (note) note.textContent = 'Please choose ' + missing.map(function (p) { return (parse(p.getAttribute('data-axis-labels'), ['an option'])).join(' and '); }).join(', ') + ' first.';
        missing.forEach(function (p) { p.classList.add('gld-pick--nudge'); setTimeout(function () { p.classList.remove('gld-pick--nudge'); }, 800); });
        return;
      }
      add();
    });

    function add() {
      if (CFG.preview) { if (note) note.textContent = 'Preview mode. Turn the storefront on to enable checkout.'; return; }
      if (cta.classList.contains('is-wait')) return;
      cta.classList.add('is-wait'); cta.textContent = 'Adding...'; if (note) note.textContent = '';
      var selections = {};
      picks.forEach(function (p) { var s = p.querySelector('.gld-pick__native'); if (s && s.value) selections[p.getAttribute('data-slot')] = s.value; });
      var body = new URLSearchParams(); body.set('slug', slug);
      Object.keys(selections).forEach(function (slot) { body.set('selections[' + slot + ']', selections[slot]); });
      fetch(CFG.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: body.toString() })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.ok === false) { cta.classList.remove('is-wait'); cta.textContent = idleLabel; if (note) note.textContent = res.message || 'Could not add the set.'; return; }
          ev('bundle_add', { bundle_key: slug, value: (now ? now.textContent : '') });
          if (res && res.fragments && window.jQuery) {
            var $ = window.jQuery;
            $.each(res.fragments, function (k, v) { $(k).replaceWith(v); });
            $(document.body).trigger('wc_fragments_refreshed');
            $(document.body).trigger('added_to_cart', [res.fragments, res.cart_hash, $(cta)]);
          }
          cta.classList.remove('is-wait'); cta.textContent = 'Added';
          setTimeout(function () { cta.textContent = idleLabel; }, 1400);
        })
        .catch(function () { cta.classList.remove('is-wait'); cta.textContent = idleLabel; if (note) note.textContent = 'Something went wrong, please try again.'; });
    }

    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { ev('bundle_view', { bundle_key: slug, bundle_name: card.getAttribute('data-title'), save: save }); io.disconnect(); } }); }, { threshold: 0.4 });
      io.observe(card);
    }
  }

  function boot() { Array.prototype.forEach.call(document.querySelectorAll('.gld-bundle'), initCard); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
