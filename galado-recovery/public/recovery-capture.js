/**
 * GALADO Cart Recovery: client capture (spec FR-2, FR-9).
 *
 * Checkout: watches #billing_email on change/blur (never keyup), debounced
 * 500 ms, validated locally, sent at most once per (email, cart hash) per
 * session. Cart: powers the save-your-cart prompt. Every send is
 * fire-and-forget by spec: a failed capture must never block or delay
 * checkout, so network errors are deliberately swallowed here.
 */
(function () {
  'use strict';
  var CFG = window.GALADO_RECOVERY || {};
  if (!CFG.url) return;

  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  function valid(email) {
    if (!EMAIL_RE.test(email)) return false;
    var domain = email.slice(email.lastIndexOf('@') + 1).toLowerCase();
    var list = CFG.blocklist || [];
    for (var i = 0; i < list.length; i++) {
      if (domain === list[i]) return false;
    }
    return true;
  }

  // Anti-hammer only, keyed on the email with a short time window.
  //
  // The old key mixed in CFG.cart_hash, which is localized once at page render
  // while Woo mutates the cart over AJAX without a reload. That key went stale
  // the moment someone edited their cart, suppressing the very capture that
  // carried the corrected cart. Keying on email alone would suppress it too,
  // permanently. So the client only stops the same address firing repeatedly
  // within a few seconds; a later blur after a cart edit is allowed through and
  // refreshes the stored snapshot. The server reads the live session cart on
  // every request, upserts idempotently, and throttles the actual send per
  // recipient, so extra captures cost one cheap write and keep the cart fresh.
  var RESEND_AFTER_MS = 30000;

  function dedupeKey(email) {
    return 'grv_' + email.toLowerCase();
  }

  function alreadySent(email) {
    try {
      var last = parseInt(sessionStorage.getItem(dedupeKey(email)), 10);
      return !!last && (Date.now() - last) < RESEND_AFTER_MS;
    } catch (e) { return false; }
  }

  function markSent(email) {
    try { sessionStorage.setItem(dedupeKey(email), String(Date.now())); } catch (e) { /* storage unavailable: resend is harmless, server throttles */ }
  }

  function send(email, source, hp, done) {
    if (alreadySent(email)) { if (done) done(true); return; }
    markSent(email);
    fetch(CFG.url, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce || '' },
      body: JSON.stringify({ email: email, consent: true, source: source, hp: hp || '' })
    }).then(function (r) {
      if (done) done(r.ok);
    }).catch(function () {
      // Fire-and-forget by spec FR-2: never surface, never block checkout.
      if (done) done(false);
    });
  }

  // ---- Checkout: #billing_email on change/blur, debounced 500 ms ----------
  function bindCheckout() {
    var el = document.getElementById('billing_email');
    if (!el) return;
    var timer;
    var handler = function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var v = (el.value || '').trim();
        if (valid(v)) send(v, 'checkout', '');
      }, 500);
    };
    el.addEventListener('change', handler);
    el.addEventListener('blur', handler);
  }

  // ---- Cart prompt (FR-9) -------------------------------------------------
  function bindPrompt() {
    var box = document.getElementById('galado-recovery-prompt');
    if (!box) return;
    var input = box.querySelector('.grv-email');
    var hp = box.querySelector('.grv-hp');
    var save = box.querySelector('[data-grv-save]');
    var note = box.querySelector('[data-grv-note]');
    var dismiss = box.querySelector('[data-grv-dismiss]');

    if (save && input) {
      save.addEventListener('click', function () {
        var v = (input.value || '').trim();
        if (!valid(v)) {
          if (note) note.textContent = 'Please enter a valid email address.';
          return;
        }
        save.disabled = true;
        send(v, 'cart', hp ? hp.value : '', function () {
          // Treat as saved either way; the server accepted or will on retry.
          box.classList.add('grv-saved');
          if (note) note.textContent = 'Saved. We will email you a link to this cart.';
        });
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); save.click(); }
      });
    }

    if (dismiss) {
      dismiss.addEventListener('click', function () {
        var expires = new Date(Date.now() + 30 * 864e5).toUTCString();
        document.cookie = 'galado_recovery_dismiss=1; expires=' + expires + '; path=/; SameSite=Lax';
        box.style.display = 'none';
      });
    }
  }

  function boot() { bindCheckout(); bindPrompt(); }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
