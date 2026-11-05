/**
 * "Find your font" page: the customer's name in every lettering font, black or
 * white, and one line to copy: "Font Style : Bebas - Jasmine - Black".
 * The name is only ever set as text, never as HTML.
 */
(function () {
  'use strict';

  function init(root) {
    var FONTS;
    try { FONTS = JSON.parse(root.getAttribute('data-fonts') || '[]'); } catch (e) { FONTS = []; }
    if (!FONTS.length) return;

    var PLACEHOLDER = 'Your name';
    var nameEl = root.querySelector('.gfp-input');
    var grid = root.querySelector('.gfp-grid');
    var pick = root.querySelector('.gfp-pick');
    var pickPreview = root.querySelector('.gfp-pick-preview');
    var pickLine = root.querySelector('.gfp-pick-line');
    var copyBtn = root.querySelector('.gfp-btn');
    var statusEl = root.querySelector('.gfp-status');
    var colours = root.querySelectorAll('input[name="gfp-colour"]');

    var state = { font: '', colour: 'Black', line: '' };
    var statusTimer = null;
    var samples = {};

    function family(font) { return "'GFP " + font + "', cursive"; }
    function cleanName() { return nameEl.value.replace(/\s+/g, ' ').trim(); }

    // Long names shrink to fit the card instead of breaking mid-word.
    function fit(el) {
      var len = (el.textContent || '').length;
      var size = len <= 8 ? 40 : len <= 14 ? 34 : len <= 20 ? 28 : 24;
      el.style.fontSize = size + 'px';
      el.style.whiteSpace = 'nowrap';
      while (size > 18 && el.scrollWidth > el.clientWidth + 1) {
        size -= 1;
        el.style.fontSize = size + 'px';
      }
      el.style.whiteSpace = '';
    }

    // The small preview in the pick bar: one line if it can, then wrap between
    // words, and only break inside a word for a single very long name.
    function fitBox(el, start, min) {
      var fits = function () { return el.scrollHeight <= el.clientHeight + 1 && el.scrollWidth <= el.clientWidth + 1; };
      var shrink = function (from, to) {
        var size = from;
        el.style.fontSize = size + 'px';
        while (size > to && !fits()) { size -= 1; el.style.fontSize = size + 'px'; }
        return fits();
      };
      el.style.overflowWrap = 'normal';
      el.style.whiteSpace = 'nowrap';
      if (shrink(start, 14)) return;
      el.style.whiteSpace = 'normal';
      if (shrink(start, min)) return;
      el.style.overflowWrap = 'anywhere';
      shrink(start, min);
    }

    FONTS.forEach(function (font) {
      var card = document.createElement('label');
      card.className = 'gfp-card is-loading is-empty';

      var input = document.createElement('input');
      input.type = 'radio';
      input.name = 'gfp-font';
      input.value = font;
      input.addEventListener('change', function () { state.font = font; update(); });
      input.addEventListener('focus', function () {
        var keyboard = true;
        try { keyboard = input.matches(':focus-visible'); } catch (e) { keyboard = true; }
        if (keyboard) card.classList.add('is-focus');
      });
      input.addEventListener('blur', function () { card.classList.remove('is-focus'); });

      var sample = document.createElement('span');
      sample.className = 'gfp-sample';
      sample.style.fontFamily = family(font);
      sample.textContent = PLACEHOLDER;

      var meta = document.createElement('span');
      meta.className = 'gfp-meta';
      var label = document.createElement('span');
      label.className = 'gfp-font-name';
      label.textContent = font;
      var tick = document.createElement('span');
      tick.className = 'gfp-tick';
      tick.setAttribute('aria-hidden', 'true');
      meta.appendChild(label);
      meta.appendChild(tick);

      card.appendChild(input);
      card.appendChild(sample);
      card.appendChild(meta);
      grid.appendChild(card);
      samples[font] = { card: card, sample: sample };

      // Show each sample only once its own font is in, so nobody judges a
      // style by a fallback font. Shown anyway after a few seconds.
      var reveal = function () { card.classList.remove('is-loading'); fit(sample); };
      if (document.fonts && document.fonts.load) {
        Promise.race([
          document.fonts.load('40px ' + family(font), PLACEHOLDER),
          new Promise(function (r) { setTimeout(r, 4000); })
        ]).then(reveal, reveal);
      } else {
        reveal();
      }
    });

    function setStatus(msg) {
      if (statusTimer) { clearTimeout(statusTimer); statusTimer = null; }
      statusEl.textContent = msg;
    }

    function update() {
      var name = cleanName();
      var text = name || PLACEHOLDER;
      FONTS.forEach(function (font) {
        var s = samples[font];
        if (s.sample.textContent !== text) {
          s.sample.textContent = text;
          fit(s.sample);
        }
        s.card.classList.toggle('is-empty', !name);
        s.card.classList.toggle('is-picked', state.font === font);
      });

      grid.classList.toggle('is-white', state.colour === 'White');
      pick.classList.toggle('is-white', state.colour === 'White');

      if (state.font && name) {
        pickPreview.textContent = name;
        pickPreview.style.fontFamily = family(state.font);
        fitBox(pickPreview, 24, 9);
      } else {
        pickPreview.textContent = '';
      }

      var ready = !!(state.font && name);
      pickLine.classList.toggle('is-waiting', !ready);
      // Exactly what staff expect: "Font Style : Bebas - Jasmine - Black".
      state.line = ready ? 'Font Style : ' + state.font + ' - ' + name + ' - ' + state.colour : '';
      pickLine.textContent = '';
      if (ready) {
        // Each ":" or "-" stays with the word before it, so a wrapped line
        // never starts with one. Plain text nodes only.
        var parts = [['Font Style', ':'], [state.font, '-'], [name, '-'], [state.colour, '']];
        parts.forEach(function (part, i) {
          var words = part[0].split(' ');
          var last = words.pop();
          if (words.length) pickLine.appendChild(document.createTextNode(words.join(' ') + ' '));
          var tail = part[1] ? last + ' ' + part[1] : last;
          if (last.length <= 12) {
            var span = document.createElement('span');
            span.className = 'gfp-keep';
            span.textContent = tail;
            pickLine.appendChild(span);
          } else {
            pickLine.appendChild(document.createTextNode(tail));
          }
          if (i < parts.length - 1) pickLine.appendChild(document.createTextNode(' '));
        });
      } else {
        pickLine.textContent = !name ? 'Type your name to start' : 'Now pick a style';
      }
      copyBtn.disabled = !ready;
      copyBtn.textContent = 'Copy';
      setStatus('');
    }

    function fallbackCopy(text) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '0';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      ta.setSelectionRange(0, text.length);
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta);
      return ok;
    }

    copyBtn.addEventListener('click', function () {
      var text = state.line;
      if (!text) return;
      var done = function (ok) {
        if (ok) {
          copyBtn.textContent = 'Copied';
          setStatus('Copied. Paste it to us in chat.');
          statusTimer = setTimeout(function () { copyBtn.textContent = 'Copy'; statusEl.textContent = ''; }, 4000);
        } else {
          setStatus('Your browser would not copy it. Press and hold the line above to copy, or take a screenshot.');
        }
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(fallbackCopy(text)); });
      } else {
        done(fallbackCopy(text));
      }
    });

    nameEl.addEventListener('input', update);
    Array.prototype.forEach.call(colours, function (r) {
      r.addEventListener('change', function () { state.colour = r.value; update(); });
    });

    var resizeTimer = null;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () { FONTS.forEach(function (f) { fit(samples[f].sample); }); }, 120);
    });

    update();
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('.gfp-page'), init);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
