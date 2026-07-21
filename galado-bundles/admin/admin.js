/* global jQuery, GALADO_BUNDLES_ADMIN, wp */
(function ($) {
  'use strict';
  var cfg = window.GALADO_BUNDLES_ADMIN || {};
  var $host = $('#galado-bundle-items');
  var $json = $('#galado-bundle-items-json');
  if (!$host.length) return;

  var items = [];
  try { items = JSON.parse($host.attr('data-items') || '[]') || []; } catch (e) { items = []; }

  function money(n) { return 'RM' + (Math.round((+n || 0) * 100) / 100).toFixed(2); }
  function uid() { return 's' + Math.random().toString(36).slice(2, 7); }

  function serialize() {
    $json.val(JSON.stringify(items.map(function (it) {
      return {
        slot: it.slot, product_id: it.product_id, line_type: it.line_type, qty: it.qty,
        variation_mode: it.variation_mode, default_variation_id: it.default_variation_id,
        label: it.label || '', name_cache: it.name_cache || '', price_cache: it.price_cache || 0
      };
    })));
    honesty();
  }

  function honesty() {
    var sum = items.reduce(function (a, it) { return a + (+it.price_cache || 0) * (+it.qty || 1); }, 0);
    var save = parseFloat($('#galado-bundle-save').val()) || 0;
    var $h = $('#galado-bundle-honesty');
    if (!items.length) { $h.text('Add items to see the saving check.'); return; }
    if (save <= 0) { $h.html('Buy separately ' + money(sum) + '. <em>Link-only set (no saving).</em>'); return; }
    var total = sum - save, cls = '#1a7f37', note = 'genuine ' + money(save) + ' saving';
    if (save >= sum) { cls = '#d63638'; note = 'saving is not less than the total, will be blocked'; }
    else if (save > 0.4 * sum) { cls = '#bd8600'; note = 'over 40% of the total, double-check margin'; }
    $h.html('Buy separately ' + money(sum) + ' &rarr; Bundle ' + money(total) + ' <span style="color:' + cls + '">(' + note + ')</span>');
  }

  function fetchVariations(it, $sel) {
    $.ajax({
      url: cfg.variations + '?product_id=' + it.product_id,
      headers: { 'X-WP-Nonce': cfg.nonce }
    }).done(function (vs) {
      it._variations = vs || [];
      $sel.empty().append('<option value="0">Pick a variation</option>');
      (vs || []).forEach(function (v) {
        $sel.append('<option value="' + v.id + '"' + (+it.default_variation_id === +v.id ? ' selected' : '') +
          (v.stock === 'out' ? ' disabled' : '') + '>' + v.label + ' (' + money(v.price) + ')' + (v.stock === 'out' ? ' - out' : '') + '</option>');
      });
    });
  }

  function rowHtml(it) {
    var $row = $('<div class="gb-row" />').data('slot', it.slot);
    $row.append('<span class="gb-drag dashicons dashicons-menu-alt2" title="Drag to reorder"></span>');
    if (it.thumb) $row.append('<img class="gb-thumb" src="' + it.thumb + '" alt="">');
    var $main = $('<div class="gb-main" />');
    $main.append('<div class="gb-name">' + (it.name_cache || ('#' + it.product_id)) +
      ' <span class="gb-type gb-type-' + it.line_type + '">' + it.line_type + '</span></div>');

    if (it.line_type === 'variable') {
      var $mode = $('<div class="gb-mode" />');
      var name = 'mode_' + it.slot;
      $mode.append('<label><input type="radio" name="' + name + '" value="pinned"' + (it.variation_mode !== 'shopper_choice' ? ' checked' : '') + '> Pin one</label>');
      $mode.append('<label><input type="radio" name="' + name + '" value="shopper_choice"' + (it.variation_mode === 'shopper_choice' ? ' checked' : '') + '> Let shopper choose</label>');
      $main.append($mode);
      var $pin = $('<div class="gb-pin" />');
      var $sel = $('<select class="gb-variation" />');
      $pin.append($sel);
      $main.append($pin);
      $pin.toggle(it.variation_mode !== 'shopper_choice');
      fetchVariations(it, $sel);
      $mode.on('change', 'input', function () {
        it.variation_mode = $(this).val();
        $pin.toggle(it.variation_mode === 'pinned');
        serialize();
      });
      $sel.on('change', function () { it.default_variation_id = parseInt(this.value, 10) || 0; serialize(); });
    }

    var $qty = $('<label class="gb-qty">Qty <input type="number" min="1" value="' + (it.qty || 1) + '"></label>');
    $qty.find('input').on('change', function () { it.qty = Math.max(1, parseInt(this.value, 10) || 1); serialize(); });
    $main.append($qty);
    $row.append($main);

    var $rm = $('<button type="button" class="gb-remove button-link" title="Remove">&times;</button>');
    $rm.on('click', function () { items = items.filter(function (x) { return x.slot !== it.slot; }); render(); });
    $row.append($rm);
    return $row;
  }

  function render() {
    var $list = $('#gb-list');
    if (!$list.length) {
      $host.html('<div id="gb-list"></div>' +
        '<div class="gb-search"><input type="text" id="gb-search-input" placeholder="Search a product to add..." autocomplete="off"><div id="gb-search-results"></div></div>');
      $list = $('#gb-list');
      bindSearch();
    }
    $list.empty();
    items.forEach(function (it) { $list.append(rowHtml(it)); });
    $list.sortable({
      handle: '.gb-drag', axis: 'y',
      update: function () {
        var order = $list.children().map(function () { return $(this).data('slot'); }).get();
        items.sort(function (a, b) { return order.indexOf(a.slot) - order.indexOf(b.slot); });
        serialize();
      }
    });
    serialize();
  }

  function bindSearch() {
    var t, $in = $('#gb-search-input'), $res = $('#gb-search-results');
    $in.on('input', function () {
      var q = $in.val();
      clearTimeout(t);
      if (q.length < 2) { $res.empty(); return; }
      t = setTimeout(function () {
        $.ajax({ url: cfg.search + '?q=' + encodeURIComponent(q), headers: { 'X-WP-Nonce': cfg.nonce } }).done(function (list) {
          $res.empty();
          (list || []).forEach(function (p) {
            var $r = $('<div class="gb-result" />');
            $r.append('<img src="' + p.thumb + '" alt="">');
            $r.append('<span>' + p.text + ' <em>' + money(p.price) + '</em> <span class="gb-type gb-type-' + p.type + '">' + p.type + (p.stock === 'out' ? ' &middot; out' : '') + '</span></span>');
            $r.on('click', function () {
              items.push({
                slot: uid(), product_id: p.id, line_type: p.type, qty: 1,
                variation_mode: p.type === 'variable' ? 'shopper_choice' : 'fixed',
                default_variation_id: 0, label: '', name_cache: p.text, price_cache: p.price, thumb: p.thumb
              });
              $in.val(''); $res.empty(); render();
            });
            $res.append($r);
          });
        });
      }, 250);
    });
    $(document).on('click', function (e) { if (!$(e.target).closest('.gb-search').length) $res.empty(); });
  }

  // Image picker.
  $(document).on('click', '#galado-bundle-image-pick', function (e) {
    e.preventDefault();
    var frame = wp.media({ title: 'Set image', multiple: false, library: { type: 'image' } });
    frame.on('select', function () {
      var a = frame.state().get('selection').first().toJSON();
      $('#galado-bundle-image').val(a.id);
      $('#galado-bundle-image-preview').attr('src', a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url).show();
      $('#galado-bundle-image-clear').show();
    });
    frame.open();
  });
  $(document).on('click', '#galado-bundle-image-clear', function (e) {
    e.preventDefault();
    $('#galado-bundle-image').val('');
    $('#galado-bundle-image-preview').hide();
    $(this).hide();
  });
  $('#galado-bundle-save').on('input', honesty);

  render();
})(jQuery);
