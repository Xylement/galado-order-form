/**
 * GALADO Studio DESIGNER (spec Addendum D): free canvas composition on the
 * real case mock. Boots only with ?designer=1 while in silent QA; studio.js
 * yields the mount when the flag is present. Fabric.js (self-hosted) drives
 * the canvas; the scene serialises to normalised print coordinates and the
 * server re-renders it at print DPI (compose.ts), so this preview and the
 * print file agree by construction.
 */
(function () {
  'use strict';

  var cfg = window.GSTUDIO_CFG || {};
  var root = document.getElementById('galado-studio');
  if (!root || !window.fabric) return;
  if (window.location.search.indexOf('designer=1') === -1) return;

  var COPY = {
    heroTitle: 'Design a case that is completely you.',
    heroSub: 'Your photos, your words, your layout. Straight onto the case.',
    priceLine: 'RM169, free shipping included',
    modelH: 'Choose your model',
    addPhoto: '+ Photo',
    addText: '+ Text',
    bgLabel: 'Background',
    bgClear: 'Clear case',
    photoSheetH: 'Add a photo',
    uploadHint: 'JPG, PNG or HEIC, up to 10MB',
    rights: 'This photo is mine or I have permission to use it, and I am happy for GALADO to print it.',
    retention: 'We keep uploads for 30 days, then they are gone for good.',
    checking: 'Checking your photo...',
    choosePhoto: 'Choose a photo',
    rightsFirst: 'Tick the box above first, then choose your photo.',
    gettingReady: 'Getting things ready...',
    verifyFirst: 'Complete the quick check on the page first, then try again.',
    textSheetH: 'Add your words',
    textPh: 'Up to 24 characters',
    fontLabel: 'Lettering style',
    colourLabel: 'Colour',
    place: 'Place it',
    update: 'Update',
    cameraWarn: 'That sits under the camera, so it would be hidden. Drag it clear.',
    edgeWarn: 'Touching the very edge may get trimmed in printing.',
    layerUp: 'Forward',
    layerDown: 'Back',
    duplicate: 'Copy',
    remove: 'Remove',
    doneCta: 'Looks good',
    emptyNote: 'Add a photo or some words to start.',
    doneH: 'Your design is saved',
    doneB: 'Checkout wiring arrives in the next build. This design serialised cleanly:',
    backCta: 'Keep editing',
    errB: 'Something hiccuped on our side. Give it another go.',
    modT: 'We could not use that one',
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
  var TEXT_COLOURS = [['ink', '#111111'], ['white', '#FFFFFF'], ['red', '#E4002B']];
  var BG_COLOURS = ['#FFFFFF', '#111111', '#E4002B', '#F5F5F3', '#A8C3A0', '#A9C7E4', '#F5C6C6', '#F2D98D'];

  var S = {
    modelId: '', modelLabel: '',
    token: '', turnstileToken: '',
    bgFill: null,
    scene: null,
  };
  var C = null; // fabric canvas
  var stageMeta = null; // { plateW, plateH, mock }

  function ga(name, params) {
    try { if (typeof window.gtag === 'function') window.gtag('event', name, params || {}); } catch (e) { /* never breaks the flow */ }
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') node.textContent = attrs[k];
      else if (k === 'class') node.className = attrs[k];
      else if (k.indexOf('on') === 0) node[k] = attrs[k];
      else node.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { node.appendChild(c); });
    return node;
  }

  function mount() {
    root.innerHTML = '';
    for (var i = 0; i < arguments.length; i += 1) root.appendChild(arguments[i]);
    root.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function api(path, opts) {
    opts = opts || {};
    var headers = opts.headers || {};
    if (S.token) headers['authorization'] = 'Bearer ' + S.token;
    if (opts.json) { headers['content-type'] = 'application/json'; opts.body = JSON.stringify(opts.json); }
    return fetch(cfg.api + path, { method: opts.method || 'GET', headers: headers, body: opts.body })
      .then(function (res) {
        if (res.status === 204) return { __status: 204 };
        return res.json().catch(function () { return {}; }).then(function (b) { b.__status = res.status; return b; });
      });
  }

  // Fonts for live text preview (same files the server letters with).
  var fontReady = {};
  function refreshFont(key) {
    fontReady[key] = true;
    if (!C) return;
    try { if (fabric.util.clearFabricFontCache) fabric.util.clearFabricFontCache('gd-' + key); } catch (e) { /* cache clear is best-effort */ }
    C.getObjects().forEach(function (o) {
      if (o.gdType === 'text' && o.gdFont === key) {
        o.dirty = true;
        if (o.initDimensions) o.initDimensions();
        o.setCoords();
      }
    });
    C.requestRenderAll();
  }
  if (cfg.fonts_base) {
    FONTS.forEach(function (f) {
      var face = new FontFace('gd-' + f[0], 'url("' + cfg.fonts_base + encodeURIComponent(f[1]) + '")');
      face.load().then(function (loaded) { document.fonts.add(loaded); refreshFont(f[0]); }).catch(function () { /* falls back */ });
    });
  }

  // ---- step 1: model --------------------------------------------------------

  function renderModelSelect() {
    var grid = el('div', { class: 'gstudio-models' });
    (cfg.models || []).forEach(function (m) {
      grid.appendChild(el('button', {
        class: 'gstudio-model', type: 'button', text: m.label,
        onclick: function () {
          S.modelId = m.model_id || m.id; S.modelLabel = m.label;
          ga('studio_designer_model', { model_id: m.id });
          renderEditor();
        },
      }));
    });
    mount(
      el('h1', { text: COPY.heroTitle }),
      el('p', { class: 'gstudio-sub', text: COPY.heroSub + ' ' + COPY.priceLine + '.' }),
      el('h2', { text: COPY.modelH }),
      grid
    );
  }

  // ---- session (Turnstile) ---------------------------------------------------

  function ensureSession(holder) {
    var open = function (t) {
      api('/v1/session', { method: 'POST', json: { turnstile_token: t, wp_claim: cfg.wp_claim || '' } })
        .then(function (b) { if (b.__status === 200) { S.token = b.token; holder.style.display = 'none'; } });
    };
    if (cfg.sitekey && window.turnstile) {
      window.turnstile.render(holder, {
        sitekey: cfg.sitekey,
        callback: function (t) { S.turnstileToken = t; open(t); },
      });
    } else {
      open(''); // dev harness / test config (production always carries a sitekey)
    }
  }

  // ---- editor ----------------------------------------------------------------

  function mockFor(modelId) {
    var map = cfg.mocks || {};
    if (map.mocks && !map.print) map = map.mocks; // tolerate the full-manifest shape
    return map[modelId] || null;
  }

  var manifestRefreshed = false;
  function renderEditor() {
    var mock = mockFor(S.modelId);

    // Self-heal a stale inlined manifest (cached page HTML / cached script
    // saw this live: entries without print geometry, or no entry at all).
    // Pull the manifest fresh once, then re-render with the real data.
    if ((!mock || !mock.print) && cfg.mocks_base && !manifestRefreshed) {
      manifestRefreshed = true;
      fetch(cfg.mocks_base + 'mocks.json?ver=' + encodeURIComponent(cfg.ver || Date.now()))
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.mocks) { cfg.mocks = d.mocks; renderEditor(); } })
        .catch(function () { /* keep the neutral stage */ });
    }

    // The editor canvas IS the print rect: contain-fitted over the case
    // cutout when a frame photo exists (the camera overlay must land on the
    // photographed camera), a neutral case card otherwise. Height is capped
    // so the toolbar stays in reach on a phone.
    var printAspect = (mock && mock.print) ? mock.print.w / mock.print.h : 0.49;
    var maxH = Math.min(Math.round((window.innerHeight || 700) * 0.56), 560);
    var stage, plate;

    if (mock && mock.print && mock.file) {
      var img = new Image();
      img.src = cfg.mocks_base + mock.file;
      var natural = (mock.img && mock.img.h) ? mock.img.w / mock.img.h : 0.507;
      var stageH = maxH;
      var stageW = Math.min(Math.round(stageH * natural), (root.clientWidth - 20) || 370);
      stageH = Math.round(stageW / natural);
      var inset = 0.025;
      var boxW = stageW * (1 - 2 * inset);
      var boxH = stageH * (1 - 2 * inset);
      var plateH = Math.min(boxH, boxW / printAspect);
      var plateW = plateH * printAspect;
      plate = {
        left: (stageW - plateW) / 2,
        top: (stageH - plateH) / 2,
        w: plateW,
        h: plateH,
      };
      stage = el('div', { class: 'gd-stage', style: 'width:' + stageW + 'px;height:' + stageH + 'px' });
      img.className = 'gd-mock';
      img.alt = S.modelLabel;
      img.onerror = function () { img.style.display = 'none'; stage.classList.add('gd-stage--plain'); };
      stage.appendChild(img);
    } else {
      // No frame photo: neutral case card, SAME print geometry and rules
      // (camera overlay + warnings still apply when the model has them).
      var ph = maxH - 24;
      plate = { left: 12, top: 12, w: ph * printAspect, h: ph };
      stage = el('div', {
        class: 'gd-stage gd-stage--plain',
        style: 'width:' + (plate.w + 24) + 'px;height:' + maxH + 'px',
      });
    }

    var canvasEl = el('canvas', { class: 'gd-canvas' });
    stage.appendChild(el('div', {
      class: 'gd-plate',
      style: 'left:' + plate.left + 'px;top:' + plate.top + 'px;width:' + plate.w + 'px;height:' + plate.h + 'px',
    }, [canvasEl]));

    var warn = el('p', { class: 'gd-warn', text: '' });
    var selBar = el('div', { class: 'gd-selbar', style: 'display:none' }, [
      el('button', { class: 'gd-tool', type: 'button', text: COPY.layerUp, onclick: function () { withActive(function (o) { C.bringForward(o); }); } }),
      el('button', { class: 'gd-tool', type: 'button', text: COPY.layerDown, onclick: function () { withActive(function (o) { C.sendBackwards(o); }); } }),
      el('button', { class: 'gd-tool', type: 'button', text: COPY.duplicate, onclick: duplicateActive }),
      el('button', { class: 'gd-tool gd-tool--danger', type: 'button', text: COPY.remove, onclick: function () { withActive(function (o) { C.remove(o); C.discardActiveObject(); C.requestRenderAll(); } ); } }),
    ]);

    var bgRow = el('div', { class: 'gd-bgrow' });
    bgRow.appendChild(el('span', { class: 'gstudio-label', style: 'margin:0 8px 0 0', text: COPY.bgLabel }));
    bgRow.appendChild(el('button', {
      class: 'gd-swatch gd-swatch--clear' + (S.bgFill === null ? ' sel' : ''), type: 'button', title: COPY.bgClear,
      onclick: function () { setBg(null, bgRow); },
    }));
    BG_COLOURS.forEach(function (hex) {
      bgRow.appendChild(el('button', {
        class: 'gd-swatch' + (S.bgFill === hex ? ' sel' : ''), type: 'button', style: 'background:' + hex,
        onclick: function () { setBg(hex, bgRow); },
      }));
    });

    var turnstileHolder = el('div', { class: 'gd-turnstile' });
    var toolbar = el('div', { class: 'gd-toolbar' }, [
      el('button', { class: 'gstudio-btn gstudio-btn--ghost gd-add', type: 'button', text: COPY.addPhoto, onclick: photoSheet }),
      el('button', { class: 'gstudio-btn gstudio-btn--ghost gd-add', type: 'button', text: COPY.addText, onclick: function () { textSheet(null); } }),
    ]);

    mount(
      el('h2', { text: S.modelLabel }),
      stage, warn, selBar, toolbar, bgRow, turnstileHolder,
      el('button', { class: 'gstudio-btn gstudio-btn--ink', type: 'button', text: COPY.doneCta, style: 'margin-top:14px', onclick: finishDesign }),
      el('p', { class: 'gstudio-note', text: COPY.retention })
    );
    ensureSession(turnstileHolder);

    C = new fabric.Canvas(canvasEl, {
      width: Math.round(plate.w),
      height: Math.round(plate.h),
      selection: false,
      preserveObjectStacking: true,
    });
    stageMeta = { plateW: Math.round(plate.w), plateH: Math.round(plate.h), mock: mock, warn: warn, selBar: selBar };
    window.__gd = { version: cfg.ver || 'dev', canvas: C, state: S, serialize: serializeScene }; // QA/support handle

    // Camera keep-out overlay (from the die-line, via mocks.json).
    if (mock && mock.camera) {
      var cam = mock.camera;
      var camRect = new fabric.Rect({
        left: cam.x0 * plate.w, top: cam.y0 * plate.h,
        width: (cam.x1 - cam.x0) * plate.w, height: (cam.y1 - cam.y0) * plate.h,
        fill: 'rgba(17,17,17,0.16)', stroke: 'rgba(17,17,17,0.35)', strokeDashArray: [4, 3],
        selectable: false, evented: false, excludeFromExport: true,
      });
      camRect.gdOverlay = true;
      C.add(camRect);
    }

    C.on('selection:created', updateSelUi);
    C.on('selection:updated', updateSelUi);
    C.on('selection:cleared', updateSelUi);
    C.on('object:modified', checkPlacement);
    C.on('object:moving', checkPlacement);
    C.on('mouse:dblclick', function (opt) {
      var t = opt.target;
      if (t && t.gdType === 'text') textSheet(t);
    });
    fabric.Object.prototype.set({
      transparentCorners: false, cornerStyle: 'circle', cornerColor: '#FFFFFF',
      cornerStrokeColor: '#111111', borderColor: '#111111', cornerSize: 12, padding: 4,
    });
  }

  function withActive(fn) {
    var o = C.getActiveObject();
    if (o) { fn(o); C.requestRenderAll(); }
  }

  function updateSelUi() {
    stageMeta.selBar.style.display = C.getActiveObject() ? 'flex' : 'none';
    checkPlacement();
  }

  function contentObjects() {
    return C.getObjects().filter(function (o) { return !o.gdOverlay; });
  }

  function checkPlacement() {
    var mock = stageMeta.mock;
    var msg = '';
    if (mock && mock.camera) {
      var cam = mock.camera;
      var cx0 = cam.x0 * stageMeta.plateW, cy0 = cam.y0 * stageMeta.plateH;
      var cx1 = cam.x1 * stageMeta.plateW, cy1 = cam.y1 * stageMeta.plateH;
      contentObjects().forEach(function (o) {
        var r = o.getBoundingRect(true, true);
        if (r.left < cx1 && r.left + r.width > cx0 && r.top < cy1 && r.top + r.height > cy0) {
          msg = COPY.cameraWarn;
        }
      });
    }
    if (!msg && mock && mock.keepout) {
      var k = mock.keepout * stageMeta.plateW;
      contentObjects().forEach(function (o) {
        var r = o.getBoundingRect(true, true);
        if (r.left < k || r.top < k || r.left + r.width > stageMeta.plateW - k || r.top + r.height > stageMeta.plateH - k) {
          msg = COPY.edgeWarn;
        }
      });
    }
    stageMeta.warn.textContent = msg;
    stageMeta.warn.className = 'gd-warn' + (msg === COPY.cameraWarn ? ' gd-warn--hard' : '');
  }

  function duplicateActive() {
    withActive(function (o) {
      o.clone(function (copy) {
        copy.set({ left: o.left + 14, top: o.top + 14 });
        copy.gdType = o.gdType; copy.gdRef = o.gdRef;
        copy.gdText = o.gdText; copy.gdFont = o.gdFont; copy.gdColour = o.gdColour;
        C.add(copy); C.setActiveObject(copy);
      });
    });
  }

  function setBg(fill, bgRow) {
    S.bgFill = fill;
    C.setBackgroundColor(fill || '', C.renderAll.bind(C));
    Array.prototype.forEach.call(bgRow.querySelectorAll('.gd-swatch'), function (b) { b.classList.remove('sel'); });
    var idx = fill === null ? 0 : BG_COLOURS.indexOf(fill) + 1;
    var swatches = bgRow.querySelectorAll('.gd-swatch');
    if (swatches[idx]) swatches[idx].classList.add('sel');
    ga('studio_designer_bg', { fill: fill || 'clear' });
  }

  // ---- sheets ------------------------------------------------------------------

  function sheet(title, bodyNodes) {
    var overlay = el('div', { class: 'gd-sheetwrap', onclick: function (e) { if (e.target === overlay) overlay.remove(); } });
    var head = el('div', { class: 'gd-sheethead' }, [
      el('h3', { text: title }),
      el('button', { class: 'gd-close', type: 'button', text: '✕', 'aria-label': 'Close', onclick: function () { overlay.remove(); } }),
    ]);
    var box = el('div', { class: 'gd-sheet' }, [head].concat(bodyNodes));
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    return overlay;
  }

  function waitForToken(cb, status) {
    if (S.token) return cb();
    status.textContent = COPY.gettingReady;
    var tries = 0;
    var t = setInterval(function () {
      tries += 1;
      if (S.token) { clearInterval(t); cb(); }
      else if (tries > 24) { clearInterval(t); status.textContent = COPY.verifyFirst; }
    }, 500);
  }

  function photoSheet() {
    var file = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/heic,image/heif', style: 'display:none' });
    var rights = el('input', { type: 'checkbox', id: 'gd-rights' });
    var status = el('p', { class: 'gstudio-note', text: COPY.uploadHint });
    var choose = el('button', {
      class: 'gstudio-btn gstudio-btn--ink', type: 'button', text: COPY.choosePhoto,
      onclick: function () {
        if (!rights.checked) { status.textContent = COPY.rightsFirst; return; }
        file.click();
      },
    });
    var overlay = sheet(COPY.photoSheetH, [
      el('label', { class: 'gd-rights', for: 'gd-rights' }, [rights, el('span', { text: ' ' + COPY.rights })]),
      choose,
      file,
      status,
    ]);
    file.onchange = function () {
      if (!file.files || !file.files[0]) return;
      waitForToken(function () { doUpload(file.files[0]); }, status);
    };
    function doUpload(picked) {
      status.textContent = COPY.checking;
      choose.disabled = true;
      var fd = new FormData();
      fd.append('file', picked);
      api('/v1/uploads', { method: 'POST', body: fd })
        .then(function (b) {
          if (b.__status !== 200) { status.textContent = b.human_message || COPY.errB; choose.disabled = false; return; }
          return fetch(cfg.api + '/v1/uploads/' + b.upload_id, { headers: { authorization: 'Bearer ' + S.token } })
            .then(function (r) { return r.blob(); })
            .then(function (blob) {
              var url = URL.createObjectURL(blob);
              fabric.Image.fromURL(url, function (img) {
                var scale = (stageMeta.plateW * 0.6) / (img.width || 1);
                img.set({
                  left: stageMeta.plateW / 2, top: stageMeta.plateH / 2,
                  originX: 'center', originY: 'center', scaleX: scale, scaleY: scale,
                });
                img.setControlsVisibility({ ml: false, mr: false, mt: false, mb: false });
                img.gdType = 'image'; img.gdRef = 'upload:' + b.upload_id;
                C.add(img); C.setActiveObject(img); C.requestRenderAll();
                ga('studio_designer_photo', {});
              });
              overlay.remove();
            });
        })
        .catch(function () { status.textContent = COPY.errB; choose.disabled = false; });
    }
  }

  function textSheet(existing) {
    var input = el('input', {
      class: 'gstudio-input', type: 'text', maxlength: '24',
      placeholder: COPY.textPh, value: existing ? existing.gdText : '',
    });
    var fontSel = el('select', { class: 'gstudio-input' });
    FONTS.forEach(function (f) {
      var o = el('option', { value: f[0], text: f[2] });
      if (existing && existing.gdFont === f[0]) o.selected = true;
      fontSel.appendChild(o);
    });
    var colourKey = existing ? existing.gdColour : 'ink';
    var colourRow = el('div', { class: 'gd-bgrow' });
    TEXT_COLOURS.forEach(function (c) {
      colourRow.appendChild(el('button', {
        class: 'gd-swatch' + (colourKey === c[0] ? ' sel' : ''), type: 'button', style: 'background:' + c[1],
        onclick: function (e) {
          colourKey = c[0];
          Array.prototype.forEach.call(colourRow.children, function (b) { b.classList.remove('sel'); });
          e.target.classList.add('sel');
          refreshPreview();
        },
      }));
    });
    var preview = el('div', { class: 'gd-fontpreview', text: (existing ? existing.gdText : '') || 'Aiman' });
    function refreshPreview() {
      preview.textContent = input.value.trim() || 'Aiman';
      preview.style.fontFamily = "'gd-" + fontSel.value + "', cursive";
      var hex = { ink: '#111111', white: '#FFFFFF', red: '#E4002B' }[colourKey];
      preview.style.color = hex;
      preview.style.background = colourKey === 'white' ? '#111111' : '#F5F5F3';
    }
    input.oninput = refreshPreview;
    fontSel.onchange = refreshPreview;
    refreshPreview();

    var overlay = sheet(COPY.textSheetH, [
      input,
      preview,
      el('span', { class: 'gstudio-label', text: COPY.fontLabel }), fontSel,
      el('span', { class: 'gstudio-label', text: COPY.colourLabel }), colourRow,
      el('button', {
        class: 'gstudio-btn gstudio-btn--ink', type: 'button', text: existing ? COPY.update : COPY.place,
        onclick: function () {
          var text = input.value.trim();
          if (!text) return;
          var hexByKey = {}; TEXT_COLOURS.forEach(function (c) { hexByKey[c[0]] = c[1]; });
          if (existing) {
            existing.set({ text: text, fontFamily: 'gd-' + fontSel.value, fill: hexByKey[colourKey] });
            existing.gdText = text; existing.gdFont = fontSel.value; existing.gdColour = colourKey;
          } else {
            var t = new fabric.Text(text, {
              left: stageMeta.plateW / 2, top: stageMeta.plateH * 0.8,
              originX: 'center', originY: 'center',
              fontFamily: 'gd-' + fontSel.value, fill: hexByKey[colourKey],
              fontSize: Math.round(stageMeta.plateW / 7),
            });
            t.setControlsVisibility({ ml: false, mr: false, mt: false, mb: false });
            t.gdType = 'text'; t.gdText = text; t.gdFont = fontSel.value; t.gdColour = colourKey;
            C.add(t); C.setActiveObject(t);
          }
          C.requestRenderAll();
          ga('studio_designer_text', { font: fontSel.value });
          overlay.remove();
        },
      }),
    ]);
  }

  // ---- serialize + done ----------------------------------------------------

  function serializeScene() {
    var pw = stageMeta.plateW, ph = stageMeta.plateH;
    var elements = contentObjects().map(function (o) {
      var base = {
        cx: +(o.left / pw).toFixed(4),
        cy: +(o.top / ph).toFixed(4),
        w: +((o.getScaledWidth()) / pw).toFixed(4),
        rot: Math.round(((o.angle % 360) + 540) % 360 - 180),
      };
      if (o.gdType === 'text') {
        return { type: 'text', text: o.gdText, font: o.gdFont, colour: o.gdColour, cx: base.cx, cy: base.cy, w: base.w, rot: base.rot };
      }
      return { type: 'image', ref: o.gdRef, cx: base.cx, cy: base.cy, w: base.w, rot: base.rot };
    });
    return {
      version: 1,
      model_id: S.modelId,
      background: S.bgFill ? { fill: S.bgFill } : null,
      elements: elements,
    };
  }

  function finishDesign() {
    var scene = serializeScene();
    if (!scene.elements.length && !scene.background) {
      stageMeta.warn.textContent = COPY.emptyNote;
      return;
    }
    S.scene = scene;
    ga('studio_designer_done', { elements: scene.elements.length, bg: scene.background ? 'fill' : 'clear' });
    mount(
      el('div', { class: 'gstudio-ok', text: '✓' }),
      el('h2', { class: 'gstudio-center', text: COPY.doneH }),
      el('p', { class: 'gstudio-sub gstudio-center', text: COPY.doneB }),
      el('pre', { class: 'gd-scenedump', text: JSON.stringify(scene, null, 1) }),
      el('button', { class: 'gstudio-btn gstudio-btn--ghost', type: 'button', text: COPY.backCta, onclick: renderEditor })
    );
  }

  renderModelSelect();
})();
