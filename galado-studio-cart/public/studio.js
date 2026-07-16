/**
 * GALADO Studio frontend (SPEC-STUDIO.md sections 3/4, walkthrough-approved
 * flow). Vanilla JS, self-hosted, mobile-first. Every customer-facing string
 * is the copy deck verbatim (pack/copy/copy-deck-en-v1.md); do not invent
 * copy here.
 */
(function () {
  'use strict';

  var cfg = window.GSTUDIO_CFG || {};
  var root = document.getElementById('galado-studio');
  if (!root) return;

  // ---- copy deck (verbatim) -------------------------------------------------
  var COPY = {
    heroTitle: 'Design a case that is completely you.',
    heroSub: 'Pick your style, add a photo or an idea, and see it on your phone in seconds.',
    priceLine: 'RM169, free shipping included',
    step1: 'Step 1 of 3 · Your phone',
    step1H: 'Choose your model',
    step2: 'Step 2 of 3 · Your style',
    step2H: 'Two ways to make it yours',
    stylePet: 'Pet Portrait', stylePetB: 'Your pet, painted with love.',
    styleIll: 'Illustrated', styleIllB: 'Hand-drawn strokes in the GALADO house style.',
    step3: 'Step 3 of 3 · Your idea',
    uploadCta: 'Upload a photo',
    uploadHint: 'JPG, PNG or HEIC, up to 10MB',
    textPh: 'Or type your idea in one line, like: a corgi surfing at sunset',
    nameLabel: 'Add a name (optional)',
    namePh: 'e.g. Aiman',
    nameHint: 'Up to 12 characters, lettered below your design.',
    fontLabel: 'Lettering style',
    colourLabel: 'Lettering colour',
    rights: 'This photo is mine or I have permission to use it, and I am happy for GALADO to print it.',
    retention: 'We keep uploads for 30 days, then they are gone for good.',
    generateCta: 'Create my designs',
    genTitle: 'Studio is sketching',
    genLines: ['Mixing your colours...', 'Laying down the strokes...', 'Warming up the studio lights...', 'Adding the finishing touches...'],
    genNote: 'This takes about half a minute.',
    resultsH: 'Pick your favourite',
    resultsSub: 'Three takes on your idea. One free refine is included with each design.',
    refineLabel: 'Not quite? Use your free refine:',
    refineUsed: 'Refine used for this design.',
    refining: 'Refining...',
    chooseCta: 'Use this design',
    chips: { warmer: 'Warmer', cooler: 'Cooler', centered: 'More centered', simpler_background: 'Simpler background', more_detail: 'More detail' },
    mockH: 'Here it is on your ',
    sizeLabel: 'Size',
    sizeSmall: 'Cosy',
    sizeLarge: 'Full',
    honesty: 'What you see here is what we print.',
    price: 'RM169',
    perks: 'Free shipping. 1-to-1 drop warranty. Earn G-Coins with your order.',
    cartCta: 'Add to cart',
    backCta: 'Try a different design',
    addedH: 'In your cart!',
    addedB: 'Your design is saved with this order.',
    checkoutCta: 'Checkout',
    againCta: 'Design another',
    capT: 'Studio is resting for today',
    capB: 'Today’s design slots are all used up. Leave your email and we will save you a spot when Studio wakes up tomorrow.',
    capMembers: 'GALADO Club members get priority access.',
    capPh: 'Your email',
    capCta: 'Save my spot',
    capThanks: 'Done! We will email you when Studio is ready for you.',
    quotaGT: 'That is your designs for today',
    quotaGB: 'Sign in to your GALADO account and you get 6 designs a day, plus your work is saved to you.',
    quotaGCta: 'Sign in',
    quotaCT: 'You have used all your designs for today',
    quotaCB: 'Studio resets at midnight. Your renders from today stay right here for 72 hours.',
    modT: 'We could not design from that one',
    modB: 'Studio works best with your own photos and original ideas. Famous faces, brand logos and characters from shows are the usual culprits it has to skip.',
    modNameB: 'That name cannot go on a case. Try a different spelling or a nickname.',
    modRetry: 'Try a different idea',
    modBlockedCard: 'This one did not pass our quality check, so we set it aside. Your other designs are ready.',
    errT: 'Something hiccuped on our side',
    errB: 'Your design slot was not used. Give it another go.',
    errRetry: 'Try again',
    upBig: 'That photo is over 10MB. A regular phone photo works perfectly.',
    upType: 'Studio reads JPG, PNG and HEIC photos.',
    expired: 'This design has expired (designs are kept for 72 hours). Create a fresh one, it only takes a minute.',
    shareCta: 'Share your design',
    shareSaved: 'Story image saved. Post it anywhere.',
    shareCaption: 'Designed with me in GALADO Studio. Make yours at galado.com.my/studio',
  };

  var FONTS = [
    ['shorelines-script', 'Shorelines Script Bold.otf', 'Shorelines Script'],
    ['ladylike', 'LadylikeBB.otf', 'Ladylike'],
    ['gotcha', 'gotcha-regular.ttf', 'Gotcha'],
    ['angelic-bonques', 'Angelic_Bonques_Script.ttf', 'Angelic Bonques'],
    ['ayla-handwritten', 'AylaHandwritten-Regular.ttf', 'Ayla Handwritten'],
    ['rustling-sound', 'Rustling Sound.ttf', 'Rustling Sound'],
    ['kiss-me-or-not', 'Kiss Me or Not - OTF.otf', 'Kiss Me or Not'],
    ['right-strongline', 'Right Strongline.ttf', 'Right Strongline'],
    ['bebas', 'Bebas-Regular.otf', 'Bebas'],
    ['orange-gummy', 'Orange Gummy.otf', 'Orange Gummy'],
  ];
  var COLOURS = [['ink', '#111111'], ['white', '#FFFFFF'], ['red', '#E4002B']];

  // ---- state ------------------------------------------------------------------
  var S = {
    step: 1,
    modelId: '', modelLabel: '',
    styleId: '',
    uploadId: '', uploadName: '',
    text: '',
    nameText: '', nameFont: 'shorelines-script', nameColour: 'ink',
    rights: false,
    token: '', turnstileToken: '',
    renders: [], selected: null, refinedIds: {},
    artwork: null, checkoutUrl: '',
    sizeFactor: 1,
    genStartedAt: 0,
  };

  function ga(name, params) {
    try { if (typeof window.gtag === 'function') window.gtag('event', name, params || {}); } catch (e) { /* analytics never breaks the flow */ }
  }

  // ---- api helper ---------------------------------------------------------------
  function api(path, opts) {
    opts = opts || {};
    var headers = opts.headers || {};
    if (S.token) headers['authorization'] = 'Bearer ' + S.token;
    if (opts.json) {
      headers['content-type'] = 'application/json';
      opts.body = JSON.stringify(opts.json);
    }
    return fetch(cfg.api + path, {
      method: opts.method || 'GET',
      headers: headers,
      body: opts.body,
    }).then(function (res) {
      if (res.status === 204) return { __status: 204 };
      return res.json().catch(function () { return {}; }).then(function (body) {
        body.__status = res.status;
        return body;
      });
    });
  }

  function handleApiError(body, retry) {
    var code = body && body.error_code;
    if (code === 'CAPACITY') return renderCapacity();
    if (code === 'QUOTA') return renderQuota();
    if (code === 'MODERATION_INPUT') { ga('studio_blocked', { stage: 'input' }); return renderBlocked(COPY.modB); }
    if (code === 'MODERATION_NAME') { ga('studio_blocked', { stage: 'input' }); return renderBlocked(COPY.modNameB); }
    if (code === 'UPLOAD_TOO_BIG') return renderBlocked(COPY.upBig);
    if (code === 'UPLOAD_TYPE') return renderBlocked(COPY.upType);
    if (code === 'RENDER_EXPIRED') return renderError(COPY.expired, retry);
    return renderError((body && body.human_message) || COPY.errB, retry);
  }

  // ---- tiny dom helpers ------------------------------------------------------------
  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) {
      if (k === 'text') node.textContent = attrs[k];
      else if (k === 'html') node.innerHTML = attrs[k];
      else if (k.indexOf('on') === 0) node.addEventListener(k.slice(2), attrs[k]);
      else node.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) node.appendChild(c); });
    return node;
  }
  function mount() {
    root.innerHTML = '';
    var frag = document.createDocumentFragment();
    for (var i = 0; i < arguments.length; i++) if (arguments[i]) frag.appendChild(arguments[i]);
    root.appendChild(frag);
    root.scrollIntoView({ block: 'nearest' });
  }
  function dots(step) {
    return el('div', { class: 'gstudio-steps' }, [1, 2, 3].map(function (n) {
      return el('i', { class: n <= step ? 'on' : '' });
    }));
  }
  function backLink(label, fn) {
    return el('button', { class: 'gstudio-back', type: 'button', text: '← ' + label, onclick: fn });
  }

  // ---- fonts (lazy, reusing the name-case font files) ---------------------------------
  var loadedFonts = {};
  function fontFamily(id) { return 'gstudio-' + id; }
  function loadFont(id) {
    if (loadedFonts[id] || !window.FontFace) return Promise.resolve();
    var meta = FONTS.filter(function (f) { return f[0] === id; })[0];
    if (!meta) return Promise.resolve();
    var face = new FontFace(fontFamily(id), 'url("' + cfg.fonts_base + encodeURIComponent(meta[1]) + '")');
    return face.load().then(function (f) {
      document.fonts.add(f);
      loadedFonts[id] = true;
    }).catch(function () { /* preview falls back to cursive */ });
  }

  // ---- screens -------------------------------------------------------------------------

  function renderStep1() {
    S.step = 1;
    var list = el('div', { class: 'gstudio-models' }, (cfg.models || []).map(function (m) {
      return el('button', {
        class: 'gstudio-model' + (S.modelId === m.model_id ? ' sel' : ''),
        type: 'button',
        text: m.label,
        onclick: function () { S.modelId = m.model_id; S.modelLabel = m.label; renderStep2(); },
      });
    }));
    mount(
      el('h1', { text: COPY.heroTitle }),
      el('p', { class: 'gstudio-sub', text: COPY.heroSub + ' ' + COPY.priceLine + '.' }),
      dots(1),
      el('span', { class: 'gstudio-label', text: COPY.step1 }),
      el('h2', { text: COPY.step1H }),
      list
    );
  }

  function renderStep2() {
    S.step = 2;
    function styleCard(id, name, blurb, emoji) {
      return el('button', {
        class: 'gstudio-style' + (S.styleId === id ? ' sel' : ''), type: 'button',
        onclick: function () { S.styleId = id; renderStep3(); },
      }, [
        el('div', { class: 'art', text: emoji }),
        el('div', { class: 'pad' }, [
          el('p', { class: 'name', text: name }),
          el('p', { class: 'blurb', text: blurb }),
        ]),
      ]);
    }
    mount(
      dots(2),
      el('span', { class: 'gstudio-label', text: COPY.step2 }),
      el('h2', { text: COPY.step2H }),
      el('div', { class: 'gstudio-styles' }, [
        styleCard('pet-portrait', COPY.stylePet, COPY.stylePetB, '🐱'),
        styleCard('illustrated', COPY.styleIll, COPY.styleIllB, '🎨'),
      ]),
      backLink(S.modelLabel || 'Back', renderStep1)
    );
  }

  var fileInput = null;
  function renderStep3() {
    S.step = 3;
    S.uploadName = ''; // the File object does not survive re-render; never show a stale pick
    fileInput = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/heic,image/heif', class: 'gstudio-hidden' });
    fileInput.addEventListener('change', function () {
      if (fileInput.files && fileInput.files[0]) {
        S.uploadId = ''; // re-upload happens at generate
        S.uploadName = fileInput.files[0].name;
        uploadZone.classList.add('has-file');
        uploadZone.querySelector('strong').textContent = S.uploadName;
      }
    });
    var uploadZone = el('button', { class: 'gstudio-upload' + (S.uploadName ? ' has-file' : ''), type: 'button', onclick: function () { fileInput.click(); } }, [
      el('strong', { text: S.uploadName || COPY.uploadCta }),
      document.createTextNode(COPY.uploadHint),
    ]);

    var textInput = el('input', { class: 'gstudio-input', type: 'text', placeholder: COPY.textPh, value: S.text, maxlength: '140' });
    textInput.addEventListener('input', function () { S.text = textInput.value; });

    var nameInput = el('input', { class: 'gstudio-input', type: 'text', placeholder: COPY.namePh, value: S.nameText, maxlength: '12' });
    var namePreview = el('div', { class: 'gstudio-note', 'aria-hidden': 'true' });
    function refreshNamePreview() {
      namePreview.textContent = S.nameText || '';
      namePreview.style.cssText = 'font-size:26px;margin-top:8px;font-family:"' + fontFamily(S.nameFont) + '",cursive;color:' +
        (COLOURS.filter(function (c) { return c[0] === S.nameColour; })[0][1]) +
        (S.nameColour === 'white' ? ';text-shadow:0 0 2px rgba(17,17,17,.5)' : '');
    }
    nameInput.addEventListener('input', function () { S.nameText = nameInput.value; refreshNamePreview(); });

    var fontSelect = el('select', { class: 'gstudio-select' }, FONTS.map(function (f) {
      var o = el('option', { value: f[0], text: f[2] });
      if (f[0] === S.nameFont) o.selected = true;
      return o;
    }));
    fontSelect.addEventListener('change', function () {
      S.nameFont = fontSelect.value;
      loadFont(S.nameFont).then(refreshNamePreview);
    });

    var colourRow = el('div', { class: 'gstudio-colours' }, COLOURS.map(function (c) {
      return el('button', {
        class: 'gstudio-colour' + (S.nameColour === c[0] ? ' sel' : ''), type: 'button',
        'aria-label': c[0], style: 'background:' + c[1] + (c[0] === 'white' ? ';border-color:#C9C7C0' : ''),
        onclick: function () {
          S.nameColour = c[0];
          colourRow.querySelectorAll('.gstudio-colour').forEach(function (b) { b.classList.remove('sel'); });
          this.classList.add('sel');
          refreshNamePreview();
        },
      });
    }));

    var rightsBox = el('input', { type: 'checkbox' });
    rightsBox.checked = S.rights;
    rightsBox.addEventListener('change', function () { S.rights = rightsBox.checked; });

    var turnstileHolder = el('div', { style: 'margin:14px 0 0' });

    var goBtn = el('button', { class: 'gstudio-btn gstudio-btn--ink', type: 'button', text: COPY.generateCta, onclick: startGenerate, style: 'margin-top:16px' });

    mount(
      dots(3),
      el('span', { class: 'gstudio-label', text: COPY.step3 }),
      uploadZone, fileInput,
      el('span', { class: 'gstudio-label', text: 'Or your idea' }),
      textInput,
      el('span', { class: 'gstudio-label', text: COPY.nameLabel }),
      nameInput,
      el('div', { class: 'gstudio-row', style: 'margin-top:10px' }, [
        el('div', {}, [el('span', { class: 'gstudio-label', style: 'margin-top:0', text: COPY.fontLabel }), fontSelect]),
        el('div', {}, [el('span', { class: 'gstudio-label', style: 'margin-top:0', text: COPY.colourLabel }), colourRow]),
      ]),
      el('p', { class: 'gstudio-note', text: COPY.nameHint }),
      namePreview,
      el('label', { class: 'gstudio-check' }, [rightsBox, document.createTextNode(COPY.rights)]),
      el('p', { class: 'gstudio-note', text: COPY.retention }),
      turnstileHolder,
      goBtn,
      backLink(COPY.step2, renderStep2)
    );
    loadFont(S.nameFont).then(refreshNamePreview);
    if (cfg.sitekey && window.turnstile) {
      window.turnstile.render(turnstileHolder, {
        sitekey: cfg.sitekey,
        callback: function (t) { S.turnstileToken = t; },
      });
    }
  }

  // ---- generation flow ----------------------------------------------------------------

  function ensureSession() {
    if (S.token) return Promise.resolve(true);
    return api('/v1/session', { method: 'POST', json: { turnstile_token: S.turnstileToken, wp_claim: cfg.wp_claim || '' } })
      .then(function (body) {
        if (body.__status !== 200) { handleApiError(body, renderStep3); return false; }
        S.token = body.token;
        return true;
      });
  }

  function validateStep3() {
    var hasFile = fileInput && fileInput.files && fileInput.files[0];
    if (!hasFile && !S.text.trim()) { renderError(COPY.errB, renderStep3); return false; }
    if (hasFile && !S.rights) { renderBlocked(COPY.rights); return false; }
    return true;
  }

  function startGenerate() {
    if (!validateStep3()) return;
    renderGenerating(COPY.genTitle);
    ensureSession().then(function (ok) {
      if (!ok) return;
      var hasFile = fileInput && fileInput.files && fileInput.files[0];
      var uploadStep = Promise.resolve('');
      if (hasFile) {
        var form = new FormData();
        form.append('file', fileInput.files[0]);
        uploadStep = api('/v1/uploads', { method: 'POST', body: form }).then(function (body) {
          if (body.__status !== 200) { handleApiError(body, renderStep3); return null; }
          return body.upload_id;
        });
      }
      uploadStep.then(function (uploadId) {
        if (uploadId === null) return;
        var input = {};
        if (uploadId) input.upload_id = uploadId;
        if (S.text.trim()) input.text = S.text.trim();
        if (S.nameText.trim()) input.name = { text: S.nameText.trim(), font: S.nameFont, colour: S.nameColour };
        ga('studio_try', { style_id: S.styleId, model_id: S.modelId, input_type: uploadId ? 'photo' : 'text', logged_in: !!cfg.logged_in });
        S.genStartedAt = Date.now();
        api('/v1/generate', { method: 'POST', json: { style_id: S.styleId, model_id: S.modelId, input: input } })
          .then(function (body) {
            if (body.__status !== 202) return handleApiError(body, renderStep3);
            poll(body.job_id, function (job) {
              S.renders = job.renders;
              S.selected = null;
              ga('studio_render', {
                style_id: S.styleId, model_id: S.modelId,
                duration_ms: Date.now() - S.genStartedAt,
                renders_shown: job.renders.filter(function (r) { return !r.blocked; }).length,
              });
              renderResults();
            });
          });
      });
    });
  }

  var genTimer = null;
  function renderGenerating(title, note) {
    var line = el('p', { class: 'gstudio-sub gstudio-center', text: COPY.genLines[0] });
    mount(
      el('div', { class: 'gstudio-spin', role: 'status', 'aria-label': title }),
      el('h2', { class: 'gstudio-center', text: title }),
      line,
      el('p', { class: 'gstudio-note gstudio-center', text: note || COPY.genNote })
    );
    var i = 0;
    clearInterval(genTimer);
    genTimer = setInterval(function () {
      i = (i + 1) % COPY.genLines.length;
      line.textContent = COPY.genLines[i];
    }, 3200);
  }

  function poll(jobId, onDone) {
    var tick = function () {
      api('/v1/jobs/' + jobId).then(function (body) {
        if (body.__status !== 200) { clearInterval(genTimer); return handleApiError(body, renderStep3); }
        if (body.status === 'done') { clearInterval(genTimer); return onDone(body); }
        if (body.status === 'error') { clearInterval(genTimer); return renderError(COPY.errB, renderStep3); }
        setTimeout(tick, 1300);
      });
    };
    tick();
  }

  function renderResults() {
    clearInterval(genTimer);
    var grid = el('div', { class: 'gstudio-renders' }, S.renders.map(function (r, i) {
      if (r.blocked) {
        return el('div', { class: 'gstudio-blockedcard', text: COPY.modBlockedCard });
      }
      var card = el('button', { class: 'gstudio-render' + (S.selected === i ? ' sel' : ''), type: 'button', onclick: function () { S.selected = i; renderResults(); } }, [
        el('img', { src: cfg.api + r.preview_url, alt: 'Design option ' + (i + 1) }),
      ]);
      if (r.refined) card.appendChild(el('span', { class: 'flag', text: COPY.refineUsed }));
      return card;
    }));

    var actions = [];
    if (S.selected !== null && S.renders[S.selected] && !S.renders[S.selected].blocked) {
      var sel = S.renders[S.selected];
      if (!sel.refined) {
        actions.push(el('span', { class: 'gstudio-label', text: COPY.refineLabel }));
        actions.push(el('div', { class: 'gstudio-chips' }, Object.keys(COPY.chips).map(function (chipId) {
          return el('button', { class: 'gstudio-chip', type: 'button', text: COPY.chips[chipId], onclick: function () { doRefine(chipId); } });
        })));
      }
      actions.push(el('button', { class: 'gstudio-btn gstudio-btn--ink', type: 'button', text: COPY.chooseCta, style: 'margin-top:14px', onclick: renderMockup }));
    }

    mount.apply(null, [
      el('h2', { text: COPY.resultsH }),
      el('p', { class: 'gstudio-sub', text: COPY.resultsSub }),
      grid,
    ].concat(actions, [backLink(COPY.modRetry, renderStep3)]));
  }

  function doRefine(chipId) {
    var target = S.renders[S.selected];
    ga('studio_refine', { style_id: S.styleId, chip_id: chipId });
    renderGenerating(COPY.refining, COPY.genNote);
    api('/v1/refine', { method: 'POST', json: { render_id: target.render_id, chip_id: chipId } })
      .then(function (body) {
        if (body.__status !== 202) return handleApiError(body, renderResults);
        poll(body.job_id, function (job) {
          var fresh = job.renders.filter(function (r) { return !r.blocked; })[0];
          target.refined = true;
          if (fresh) {
            fresh.refined = true;
            S.renders[S.selected] = fresh;
          }
          renderResults();
        });
      });
  }

  function applySize() {
    var f = S.sizeFactor;
    var nodes = document.querySelectorAll('.gstudio-sizable');
    for (var i = 0; i < nodes.length; i++) {
      nodes[i].style.transform = 'scale(' + f + ')';
    }
  }

  function nameLetterEl(extraStyle) {
    var letter = el('div', { text: S.nameText.trim(), style: extraStyle || '' });
    letter.style.fontFamily = '"' + fontFamily(S.nameFont) + '",cursive';
    letter.style.color = COLOURS.filter(function (c) { return c[0] === S.nameColour; })[0][1];
    letter.style.textAlign = 'center';
    return letter;
  }

  function casePreview() {
    var target = S.renders[S.selected];
    var mock = (cfg.mocks || {})[S.modelId];

    // Real product mock frame: the design multiplies onto the clear case
    // inside the frame's Design Plate area, exactly like the print preview.
    if (mock && mock.file && mock.plate_pct) {
      var p = mock.plate_pct; // [left, top, width, height] as fractions
      var pct = function (v) { return (v * 100).toFixed(2) + '%'; };
      var wrap = el('div', { style: 'position:relative;width:min(88%,330px);margin:6px auto' }, [
        el('img', {
          src: (cfg.mocks_base || '') + mock.file,
          alt: 'Your ' + S.modelLabel + ' case',
          style: 'width:100%;display:block;border-radius:16px',
        }),
        el('img', {
          class: 'gstudio-sizable',
          src: cfg.api + target.preview_url,
          alt: 'Your design on the case',
          style: 'position:absolute;left:' + pct(p[0]) + ';top:' + pct(p[1]) +
            ';width:' + pct(p[2]) + ';height:' + pct(p[3]) +
            ';object-fit:contain;mix-blend-mode:multiply;transform:scale(' + S.sizeFactor + ');',
        }),
      ]);
      if (S.nameText.trim()) {
        var letter = nameLetterEl(
          'position:absolute;left:' + pct(p[0]) + ';width:' + pct(p[2]) +
          ';top:' + pct(p[1] + p[3] * 0.86) + ';font-size:clamp(14px,6vw,22px);'
        );
        letter.className = 'gstudio-sizable';
        letter.style.transform = 'scale(' + S.sizeFactor + ')';
        wrap.appendChild(letter);
      }
      return wrap;
    }

    // Fallback: the honest CSS case.
    var cssCase = el('div', { class: 'gstudio-case' }, [
      el('img', { class: 'design', src: cfg.api + target.preview_url, alt: 'Your design on the clear case' }),
      el('div', { class: 'cam', 'aria-hidden': 'true' }, [el('i'), el('i'), el('i'), el('i')]),
    ]);
    if (S.nameText.trim()) {
      cssCase.appendChild(nameLetterEl('position:absolute;left:0;right:0;bottom:7%;font-size:24px;'));
    }
    return cssCase;
  }

  function renderMockup() {
    clearInterval(genTimer);
    var cartBtn = el('button', { class: 'gstudio-btn gstudio-btn--red', type: 'button', text: COPY.cartCta, onclick: doCart });

    var slider = el('input', {
      class: 'gstudio-range', type: 'range', min: '55', max: '100', step: '1',
      value: String(Math.round(S.sizeFactor * 100)), 'aria-label': COPY.sizeLabel,
    });
    slider.addEventListener('input', function () {
      S.sizeFactor = Number(slider.value) / 100;
      applySize();
    });
    var sizer = el('div', { class: 'gstudio-sizer' }, [
      el('span', { class: 'gstudio-note', text: COPY.sizeSmall }),
      slider,
      el('span', { class: 'gstudio-note', text: COPY.sizeLarge }),
    ]);

    mount(
      el('h2', { text: COPY.mockH + S.modelLabel }),
      casePreview(),
      sizer,
      el('p', { class: 'gstudio-sub gstudio-center', text: COPY.honesty }),
      el('div', { class: 'gstudio-center', style: 'margin:6px 0 14px' }, [
        el('span', { class: 'gstudio-price', text: COPY.price }),
        el('p', { class: 'gstudio-perks', text: COPY.perks }),
      ]),
      cartBtn,
      el('button', { class: 'gstudio-btn gstudio-btn--ghost', type: 'button', text: COPY.shareCta, style: 'margin-top:10px', onclick: doShare }),
      backLink(COPY.backCta, renderResults)
    );
  }

  function doCart() {
    var target = S.renders[S.selected];
    renderGenerating(COPY.genTitle, '');
    // Finalize happens HERE so the chosen size travels into the print master.
    api('/v1/finalize', { method: 'POST', json: { render_id: target.render_id, size: S.sizeFactor } })
      .then(function (fin) {
        if (fin.__status !== 200) { handleApiError(fin, renderMockup); return null; }
        S.artwork = fin;
        return fetch(cfg.cart_url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({
            artwork_token: S.artwork.artwork_token,
            artwork_id: S.artwork.artwork_id,
            model_id: S.modelId,
            style_id: S.styleId,
            name_text: S.nameText.trim(),
          }),
        });
      })
      .then(function (res) {
        if (!res) return null;
        return res.json().then(function (b) { b.__status = res.status; return b; });
      })
      .then(function (body) {
        if (!body) return;
        clearInterval(genTimer);
        if (body.__status !== 200 || !body.ok) {
          return renderError((body && body.message) || COPY.errB, renderMockup);
        }
        S.checkoutUrl = body.checkout || body.cart_url;
        ga('studio_cart', { style_id: S.styleId, model_id: S.modelId, value: 169, currency: 'MYR', size_pct: Math.round(S.sizeFactor * 100) });
        ga('add_to_cart', {
          currency: 'MYR', value: 169,
          items: [{ item_id: 'studio-case', item_name: 'Studio Case', item_variant: S.modelId, item_category: 'Studio', price: 169, quantity: 1 }],
        });
        renderAdded();
      })
      .catch(function () { clearInterval(genTimer); renderError(COPY.errB, renderMockup); });
  }

  function renderAdded() {
    mount(
      el('div', { class: 'gstudio-ok', text: '✓' }),
      el('h2', { class: 'gstudio-center', text: COPY.addedH }),
      el('p', { class: 'gstudio-sub gstudio-center', text: COPY.addedB }),
      el('a', { class: 'gstudio-btn gstudio-btn--red', href: S.checkoutUrl, text: COPY.checkoutCta }),
      el('button', { class: 'gstudio-btn gstudio-btn--ghost', type: 'button', text: COPY.againCta, style: 'margin-top:10px', onclick: resetAll })
    );
  }

  function doShare() {
    var target = S.renders[S.selected];
    ga('studio_share', { style_id: S.styleId });
    fetch(cfg.api + target.preview_url)
      .then(function (r) { return r.blob(); })
      .then(function (blob) {
        return createImageBitmap(blob).then(function (bmp) {
          var canvas = document.createElement('canvas');
          canvas.width = 1080; canvas.height = 1920;
          var ctx = canvas.getContext('2d');
          ctx.fillStyle = '#F5F5F3'; ctx.fillRect(0, 0, 1080, 1920);
          var scale = Math.min(940 / bmp.width, 1300 / bmp.height);
          var w = bmp.width * scale, h = bmp.height * scale;
          ctx.drawImage(bmp, (1080 - w) / 2, 240, w, h);
          ctx.fillStyle = '#111111';
          ctx.font = '800 64px Archivo, Arial, sans-serif';
          ctx.textAlign = 'center';
          ctx.fillText('GALADO Studio', 540, 150);
          ctx.font = '500 36px Inter, Arial, sans-serif';
          ctx.fillStyle = '#6B6B66';
          ctx.fillText(COPY.shareCaption, 540, 1740, 980);
          return new Promise(function (resolve) { canvas.toBlob(resolve, 'image/png'); });
        });
      })
      .then(function (blob) {
        var file = new File([blob], 'galado-studio.png', { type: 'image/png' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
          return navigator.share({ files: [file], text: COPY.shareCaption }).catch(function () { /* user closed */ });
        }
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'galado-studio.png';
        a.click();
        window.alert(COPY.shareSaved);
      })
      .catch(function () { /* sharing is best-effort */ });
  }

  // ---- guard states ---------------------------------------------------------------------

  function renderCapacity() {
    clearInterval(genTimer);
    ga('studio_capacity', { logged_in: !!cfg.logged_in });
    var email = el('input', { class: 'gstudio-input', type: 'email', placeholder: COPY.capPh, autocomplete: 'email' });
    var btn = el('button', {
      class: 'gstudio-btn gstudio-btn--ink', type: 'button', text: COPY.capCta, style: 'margin-top:10px',
      onclick: function () {
        api('/v1/notify-capacity', { method: 'POST', json: { email: email.value } }).then(function (body) {
          if (body.__status === 204) {
            ga('studio_capacity_lead');
            mount(
              el('div', { class: 'gstudio-ok', text: '✓' }),
              el('p', { class: 'gstudio-sub gstudio-center', text: COPY.capThanks })
            );
          }
        });
      },
    });
    mount(
      el('h2', { text: COPY.capT }),
      el('p', { class: 'gstudio-sub', text: COPY.capB }),
      email, btn,
      el('p', { class: 'gstudio-note gstudio-center', style: 'margin-top:14px', text: COPY.capMembers })
    );
  }

  function renderQuota() {
    clearInterval(genTimer);
    ga('studio_quota', { logged_in: !!cfg.logged_in });
    if (cfg.logged_in) {
      mount(
        el('h2', { text: COPY.quotaCT }),
        el('p', { class: 'gstudio-sub', text: COPY.quotaCB })
      );
    } else {
      mount(
        el('h2', { text: COPY.quotaGT }),
        el('p', { class: 'gstudio-sub', text: COPY.quotaGB }),
        el('a', { class: 'gstudio-btn gstudio-btn--ink', href: cfg.login_url || '#', text: COPY.quotaGCta })
      );
    }
  }

  function renderBlocked(bodyText) {
    clearInterval(genTimer);
    mount(
      el('h2', { text: COPY.modT }),
      el('p', { class: 'gstudio-sub', text: bodyText }),
      el('button', { class: 'gstudio-btn gstudio-btn--ink', type: 'button', text: COPY.modRetry, onclick: renderStep3 })
    );
  }

  function renderError(bodyText, retry) {
    clearInterval(genTimer);
    mount(
      el('h2', { text: COPY.errT }),
      el('p', { class: 'gstudio-sub', text: bodyText }),
      el('button', { class: 'gstudio-btn gstudio-btn--ink', type: 'button', text: COPY.errRetry, onclick: retry || renderStep3 })
    );
  }

  function resetAll() {
    S.uploadId = ''; S.uploadName = ''; S.text = ''; S.nameText = '';
    S.renders = []; S.selected = null; S.artwork = null;
    renderStep1();
  }

  renderStep1();
})();
