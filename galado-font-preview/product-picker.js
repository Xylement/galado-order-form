/**
 * "Find your font" on a product page: the customer's text in every lettering font, black or white, one tap to pick.
 * Customers never see a font name: cards and the pick carry opaque ids only and show the drawn text.
 * Hides the Make It Mine (WCPA) text fields it replaces, matching their labels the same way the app does.
 * The text is only ever set as textContent, never as HTML.
 */
(function () {
  'use strict';
  var root = document.querySelector('.gfpp');
  if (!root) return;
  var cfg;
  try { cfg = JSON.parse(root.getAttribute('data-gfpp') || '{}'); } catch (e) { return; }
  var fonts = cfg.fonts || [];
  var on = root.querySelector('input[name="gfpp_on"]');
  var body = root.querySelector('.gfpp-body');
  var text = root.querySelector('.gfpp-input');
  var grid = root.querySelector('.gfpp-grid');
  var hidden = root.querySelector('input[name="gfpp_font"]');
  var pick = root.querySelector('.gfpp-pick');
  var form = root.closest('form');
  var chosen = '';

  // Make It Mine fields this picker replaces: hide them and make sure they are not submitted as ticked.
  function hideWcpa() {
    var want = (cfg.hide || []).map(function (s) { return s.toLowerCase(); });
    document.querySelectorAll('.wcpa_form_item').forEach(function (item) {
      var label = item.querySelector('label, .wcpa_label, .wcpa-label');
      var t = ((label && label.textContent) || '').toLowerCase();
      if (!want.some(function (w) { return t.indexOf(w) !== -1; })) return;
      // !important: the product page styles force some WCPA rows (e.g. the tick-box row) to show.
      item.style.setProperty('display', 'none', 'important');
      item.querySelectorAll('input[type="checkbox"]').forEach(function (c) {
        if (c.checked) { c.checked = false; c.dispatchEvent(new Event('change', { bubbles: true })); }
      });
    });
  }
  hideWcpa();
  // WCPA draws its form after load and redraws it when the model changes, so re-hide whenever it does.
  var queued = false;
  new MutationObserver(function () {
    if (queued) return;
    queued = true;
    requestAnimationFrame(function () { queued = false; hideWcpa(); });
  }).observe(form || document.body, { childList: true, subtree: true });

  function colour() {
    var c = root.querySelector('input[name="gfpp_colour"]:checked');
    return c ? c.value : 'Black';
  }
  function clean() { return text.value.replace(/\s+/g, ' ').trim(); }

  function fit(el) {
    var len = (el.textContent || '').length;
    var size = len <= 8 ? 30 : len <= 14 ? 26 : len <= 20 ? 22 : 18;
    el.style.fontSize = size + 'px';
    while (size > 13 && el.scrollWidth > el.clientWidth + 1) { size -= 1; el.style.fontSize = size + 'px'; }
  }

  function draw() {
    var name = clean() || 'Your name';
    grid.classList.toggle('gfpp-white', colour() === 'White');
    grid.querySelectorAll('.gfpp-sample').forEach(function (s) { s.textContent = name; fit(s); });
    update();
  }

  // The pick is shown as a picture of the customer's text in the chosen lettering: never a font name.
  function update() {
    var t = clean();
    pick.classList.remove('gfpp-err');
    pick.textContent = '';
    pick.style.fontFamily = '';
    if (!t) return;
    if (!chosen) { pick.textContent = 'Tap a style to pick it.'; return; }
    var label = document.createElement('span');
    label.className = 'gfpp-pick-label';
    label.textContent = 'Your pick';
    var art = document.createElement('span');
    art.className = 'gfpp-pick-art' + (colour() === 'White' ? ' gfpp-pick-white' : '');
    art.style.fontFamily = "'gfpp-" + chosen + "', cursive";
    art.textContent = t;
    pick.appendChild(label);
    pick.appendChild(art);
  }

  fonts.forEach(function (f) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'gfpp-card';
    b.setAttribute('role', 'radio');
    b.setAttribute('aria-checked', 'false');
    var s = document.createElement('span');
    s.className = 'gfpp-sample';
    s.style.fontFamily = "'gfpp-" + f + "', cursive";
    b.setAttribute('aria-label', 'Lettering style ' + (fonts.indexOf(f) + 1));
    b.appendChild(s);
    b.addEventListener('click', function () {
      chosen = f;
      hidden.value = f;
      grid.querySelectorAll('.gfpp-card').forEach(function (c) { c.setAttribute('aria-checked', c === b ? 'true' : 'false'); });
      update();
    });
    grid.appendChild(b);
  });

  on.addEventListener('change', function () {
    body.hidden = !on.checked;
    if (on.checked) { draw(); text.focus(); }
  });
  text.addEventListener('input', draw);
  root.querySelectorAll('input[name="gfpp_colour"]').forEach(function (r) { r.addEventListener('change', draw); });
  // Each lettering font arrives on its own; re-fit the samples as each one lands so none stays cut off.
  if (document.fonts) {
    if (document.fonts.ready) document.fonts.ready.then(draw);
    if (document.fonts.addEventListener) document.fonts.addEventListener('loadingdone', draw);
  }

  // Catch a half-filled picker before the page reloads; the server checks the same rules again.
  if (form) form.addEventListener('submit', function (e) {
    if (!on.checked) return;
    var msg = !clean() ? 'Please type the text for your case.' : (!chosen ? 'Please pick a lettering style.' : '');
    if (msg) { e.preventDefault(); pick.textContent = msg; pick.classList.add('gfpp-err'); body.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
  });
})();
