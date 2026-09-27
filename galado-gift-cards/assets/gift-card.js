/* GALADO gift card: keeps the card preview in step with the amount, design, name and message. */
(function () {
  function init(box) {
    var form = box.closest('form');
    if (!form) { return; }
    var card = box.querySelector('[data-gc-card]');
    var headline = box.querySelector('[data-gc-headline]');
    var amount = box.querySelector('[data-gc-amount]');
    var to = box.querySelector('[data-gc-to]');
    var message = box.querySelector('[data-gc-message]');
    var select = form.querySelector('select[name="' + box.getAttribute('data-attribute') + '"]');
    var custom = form.querySelector('#galado_gc_custom_amount');
    var name = form.querySelector('#galado_gc_recipient_name');
    var text = form.querySelector('#galado_gc_message');

    function amountText() {
      if (!select || !select.value) { return box.getAttribute('data-empty-amount'); }
      if (select.value === box.getAttribute('data-custom-label')) {
        var v = custom ? parseInt(custom.value, 10) : 0;
        return v > 0 ? 'RM' + v.toLocaleString('en-MY') : box.getAttribute('data-empty-amount');
      }
      return select.value; // the option label, e.g. "RM100"
    }
    function update() {
      var chosen = form.querySelector('input[name="galado_gc_design"]:checked');
      if (chosen) {
        card.style.backgroundImage = "url('" + chosen.getAttribute('data-image') + "')";
        headline.textContent = chosen.getAttribute('data-headline');
      }
      amount.textContent = amountText();
      to.textContent = (name && name.value.trim()) || box.getAttribute('data-empty-name');
      message.textContent = (text && text.value.trim()) || box.getAttribute('data-empty-message');
    }
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    if (window.jQuery) { window.jQuery(form).on('woocommerce_variation_has_changed found_variation reset_data', update); }
    update();
  }
  document.querySelectorAll('[data-galado-gc-preview]').forEach(init);
})();
