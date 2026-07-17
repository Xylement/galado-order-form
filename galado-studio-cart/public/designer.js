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
    addSticker: '+ Sticker',
    addFrame: '+ Frame',
    frameSheetH: 'Add a frame',
    stickerSheetH: 'Add a sticker',
    stickerEmpty: 'Sticker packs are on their way. Check back soon.',
    saving: 'Saving your design...',
    addedH: 'In your cart!',
    addedB: 'Your design is saved with this order.',
    checkoutCta: 'Checkout',
    againCta: 'Design another',
    photoSheetH: 'Add a photo',
    uploadHint: 'JPG, PNG or HEIC, up to 10MB',
    rights: 'This photo is mine or I have permission to use it, and I am happy for GALADO to print it.',
    retention: 'We keep uploads for 30 days, then they are gone for good.',
    printNote: 'Preview is for illustration. The actual print can shift by a few pixels in size and position.',
    checking: 'Checking your photo...',
    choosePhoto: 'Choose a photo',
    rightsFirst: 'Tick the box above first, then choose your photo.',
    gettingReady: 'Getting things ready...',
    verifyFirst: 'Complete the quick check on the page first, then try again.',
    textSheetH: 'Add your words',
    textPh: 'Up to 24 characters',
    fontLabel: 'Lettering style',
    colourLabel: 'Colour',
    effectLabel: 'Stand out on busy photos',
    effectNone: 'None',
    effectShadow: 'Shadow',
    effectOutline: 'Outline',
    effectColourLabel: 'Outline colour',
    place: 'Place it',
    update: 'Update',
    cameraWarn: 'That sits under the camera, so it would be hidden. Drag it clear.',
    edgeWarn: 'Touching the very edge may get trimmed in printing.',
    crop: 'Crop',
    erase: 'Erase',
    eraseSheetH: 'Erase parts',
    eraseHint: 'Rub over anything that should not be in the picture.',
    eraseBrush: 'Brush size',
    eraseZoom: 'Zoom',
    eraseMove: 'Move',
    eraseDraw: 'Erase',
    applyErase: 'Apply',
    undo: 'Undo',
    cropSheetH: 'Crop your photo',
    applyCrop: 'Apply',
    fullPhoto: 'Full photo',
    cutout: 'Cut out',
    undoCutout: 'Undo cut out',
    outline: 'Outline',
    cuttingOut: 'Cutting out your subject... about 20 seconds.',
    multiOn: 'Select many',
    multiOff: 'Done',
    multiHint: 'Tap layers to pick them (they glow red). Drag any picked layer to move them together.',
    layerUp: 'Forward',
    layerDown: 'Back',
    duplicate: 'Copy',
    remove: 'Remove',
    doneCta: 'Looks good',
    confirmH: 'Here is your case',
    confirmB: 'This is exactly how we will print it. Happy with it?',
    confirmCta: 'Add to cart',
    confirmBack: 'Keep editing',
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
  var TEXT_COLOURS = [
    ['ink', '#111111'], ['white', '#FFFFFF'], ['red', '#E4002B'], ['rose', '#E86A8B'],
    ['orange', '#F28C28'], ['sunshine', '#F2C94C'], ['forest', '#2E7D4F'], ['teal', '#1F8A8A'],
    ['blue', '#2D6CDF'], ['navy', '#1F3A5F'], ['purple', '#7B5BD6'], ['grey', '#9C9A93'],
  ];
  var COLOUR_HEX = {};
  TEXT_COLOURS.forEach(function (c) { COLOUR_HEX[c[0]] = c[1]; });
  var LIGHT_COLOURS = { white: 1, sunshine: 1 };
  // (round 13 #12) legibility pass mirrored server-side in nameSvg().
  function applyTextEffect(o) {
    var fs = o.fontSize || 40;
    if (o.gdEffect === 'shadow') {
      o.set({ stroke: null, strokeWidth: 0 });
      o.set('shadow', new fabric.Shadow({ color: 'rgba(17,17,17,0.55)', blur: fs * 0.12, offsetX: fs * 0.05, offsetY: fs * 0.05 }));
    } else if (o.gdEffect === 'outline') {
      o.set('shadow', null);
      o.set({
        stroke: COLOUR_HEX[o.gdEffectColour] || (LIGHT_COLOURS[o.gdColour] ? '#111111' : '#FFFFFF'),
        strokeWidth: fs * 0.09, paintFirst: 'stroke', strokeLineJoin: 'round',
      });
    } else {
      o.set('shadow', null);
      o.set({ stroke: null, strokeWidth: 0 });
    }
  }

  var S = {
    modelId: '', modelLabel: '',
    token: '', turnstileToken: '',
    scene: null,
    stickers: null,
    multiMode: false,
    multiPicks: [],
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
      // One session per page, ever. Turnstile auto-refreshes its token every
      // few minutes and re-fires the callback; minting a new session then
      // would orphan every upload made under the old one (live bug 17 Jul:
      // long design sessions 404'd at Looks Good).
      if (S.token) return;
      api('/v1/session', { method: 'POST', json: { turnstile_token: t, wp_claim: cfg.wp_claim || '' } })
        .then(function (b) { if (b.__status === 200) { S.token = b.token; holder.style.display = 'none'; } })
        .catch(function () { /* retried via waitForToken when the customer acts */ });
    };
    if (cfg.sitekey && window.turnstile) {
      window.turnstile.render(holder, {
        sitekey: cfg.sitekey,
        callback: function (t) { S.turnstileToken = t; if (!S.token) open(t); },
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
      if (mock.plate_img) {
        // Owner-calibrated print area (fractions of the mock image).
        plate = {
          left: mock.plate_img.x * stageW,
          top: mock.plate_img.y * stageH,
          w: mock.plate_img.w * stageW,
          h: mock.plate_img.h * stageH,
        };
      } else {
        // Uncalibrated: contain-fit the print aspect over the case face.
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
      }
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
    // Real cases have rounded corners; the print gets trimmed to them, so
    // the canvas shows the same rounding (approx. 11 percent of width).
    var cornerR = Math.round(plate.w * 0.11);
    stage.appendChild(el('div', {
      class: 'gd-plate',
      style: 'left:' + plate.left + 'px;top:' + plate.top + 'px;width:' + plate.w + 'px;height:' + plate.h + 'px;border-radius:' + cornerR + 'px',
    }, [canvasEl]));

    var warn = el('p', { class: 'gd-warn', text: '' });
    var progress = el('div', { class: 'gd-progresswrap', style: 'display:none' }, [el('div', { class: 'gd-progressbar' })]);
    var cropBtn = el('button', { class: 'gd-tool', type: 'button', text: COPY.crop, onclick: cropSheet });
    var cutBtn = el('button', { class: 'gd-tool', type: 'button', text: COPY.cutout, onclick: doCutout });
    var eraseBtn = el('button', { class: 'gd-tool', type: 'button', text: COPY.erase, onclick: eraseSheet });
    var undoCutBtn = el('button', {
      class: 'gd-tool', type: 'button', text: COPY.undoCutout,
      onclick: function () {
        withActive(function (o) {
          if (!o.gdVariants || !o.gdVariants.original) return;
          var orig = o.gdVariants.original;
          o.gdVariants = null;
          swapImageSrc(o, orig);
        });
      },
    });
    var selBar = el('div', { class: 'gd-selbar', style: 'display:none' }, [
      cropBtn,
      cutBtn,
      eraseBtn,
      undoCutBtn,
      el('button', { class: 'gd-tool', type: 'button', text: COPY.layerUp, onclick: function () { activeContent().forEach(function (o) { C.bringForward(o); }); C.requestRenderAll(); } }),
      el('button', { class: 'gd-tool', type: 'button', text: COPY.layerDown, onclick: function () { activeContent().forEach(function (o) { C.sendBackwards(o); }); C.requestRenderAll(); } }),
      el('button', { class: 'gd-tool', type: 'button', text: COPY.duplicate, onclick: duplicateActive }),
      el('button', { class: 'gd-tool gd-tool--danger', type: 'button', text: COPY.remove, onclick: function () { activeContent().forEach(function (o) { C.remove(o); }); if (S.multiMode) { S.multiPicks = []; } C.discardActiveObject(); C.requestRenderAll(); updateSelUi(); } }),
    ]);

    var turnstileHolder = el('div', { class: 'gd-turnstile' });
    var multiBtn = el('button', {
      class: 'gd-multitoggle', type: 'button', text: COPY.multiOn,
      onclick: function () {
        if (S.multiMode) { exitMulti(); updateSelUi(); return; }
        S.multiMode = true;
        S.multiPicks = [];
        multiBtn.textContent = COPY.multiOff;
        multiBtn.classList.add('on');
        stageMeta.warn.textContent = COPY.multiHint;
        C.discardActiveObject();
        contentObjects().forEach(function (o) { o.selectable = false; });
        C.requestRenderAll();
        updateSelUi();
      },
    });
    var toolbar = el('div', { class: 'gd-toolbar' }, [
      el('button', { class: 'gstudio-btn gstudio-btn--ghost gd-add', type: 'button', text: COPY.addPhoto, onclick: photoSheet }),
      el('button', { class: 'gstudio-btn gstudio-btn--ghost gd-add', type: 'button', text: COPY.addSticker, onclick: function () { stickerSheet('sticker'); } }),
      el('button', { class: 'gstudio-btn gstudio-btn--ghost gd-add', type: 'button', text: COPY.addFrame, onclick: function () { stickerSheet('frame'); } }),
      el('button', { class: 'gstudio-btn gstudio-btn--ghost gd-add', type: 'button', text: COPY.addText, onclick: function () { textSheet(null); } }),
    ]);

    mount(
      el('h2', { text: S.modelLabel }),
      stage, warn, progress, selBar, toolbar, multiBtn, turnstileHolder,
      el('button', { class: 'gstudio-btn gstudio-btn--ink gd-done', type: 'button', text: COPY.doneCta, style: 'margin-top:14px', onclick: finishDesign }),
      el('p', { class: 'gstudio-note', text: COPY.printNote }),
      el('p', { class: 'gstudio-note', text: COPY.retention })
    );
    ensureSession(turnstileHolder);

    C = new fabric.Canvas(canvasEl, {
      width: Math.round(plate.w),
      height: Math.round(plate.h),
      selection: true,
      preserveObjectStacking: true,
    });
    stageMeta = { plateW: Math.round(plate.w), plateH: Math.round(plate.h), mock: mock, warn: warn, selBar: selBar, progress: progress, multiBtn: multiBtn, exitMulti: exitMulti };
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

    var multiDrag = null;
    function setGlow(o, on) {
      o.set('shadow', on ? new fabric.Shadow({ color: 'rgba(228,0,43,0.95)', blur: 14, offsetX: 0, offsetY: 0, nonScaling: true }) : null);
      o.gdPicked = !!on;
    }
    function exitMulti() {
      if (!S.multiMode) return;
      S.multiMode = false;
      S.multiPicks = [];
      contentObjects().forEach(function (o) { setGlow(o, false); o.selectable = true; });
      if (stageMeta.multiBtn) {
        stageMeta.multiBtn.textContent = COPY.multiOn;
        stageMeta.multiBtn.classList.remove('on');
      }
      stageMeta.warn.textContent = '';
      C.requestRenderAll();
    }
    function refreshMultiLabel() {
      if (stageMeta.multiBtn && S.multiMode) {
        stageMeta.multiBtn.textContent = COPY.multiOff + (S.multiPicks.length ? ' · ' + S.multiPicks.length + ' picked' : '');
      }
    }
    C.on('mouse:down', function (opt) {
      if (!S.multiMode) return;
      var t = opt.target && !opt.target.gdOverlay ? opt.target : null;
      if (!t) { multiDrag = { tapOnly: true, emptyTap: true, moved: false }; return; }
      var wasPicked = S.multiPicks.indexOf(t) >= 0;
      if (!wasPicked) {
        S.multiPicks.push(t);
        setGlow(t, true);
      }
      var p = C.getPointer(opt.e);
      multiDrag = {
        target: t, wasPicked: wasPicked, moved: false,
        startX: p.x, startY: p.y,
        starts: S.multiPicks.map(function (o) { return { o: o, left: o.left, top: o.top }; }),
      };
      refreshMultiLabel();
      C.requestRenderAll();
    });
    C.on('mouse:move', function (opt) {
      if (!S.multiMode || !multiDrag || multiDrag.emptyTap || !multiDrag.starts) return;
      var p = C.getPointer(opt.e);
      var dx = p.x - multiDrag.startX;
      var dy = p.y - multiDrag.startY;
      if (Math.abs(dx) + Math.abs(dy) > 4) multiDrag.moved = true;
      if (!multiDrag.moved) return;
      multiDrag.starts.forEach(function (st) {
        st.o.set({ left: st.left + dx, top: st.top + dy });
        st.o.setCoords();
      });
      C.requestRenderAll();
      checkPlacement();
    });
    C.on('mouse:up', function () {
      if (!S.multiMode || !multiDrag) return;
      if (multiDrag.emptyTap) {
        // Tap on empty case clears the picks.
        S.multiPicks.forEach(function (o) { setGlow(o, false); });
        S.multiPicks = [];
      } else if (!multiDrag.moved && multiDrag.wasPicked) {
        // A still tap on an already-picked layer unpicks it.
        var at = S.multiPicks.indexOf(multiDrag.target);
        if (at >= 0) S.multiPicks.splice(at, 1);
        setGlow(multiDrag.target, false);
      }
      multiDrag = null;
      refreshMultiLabel();
      C.requestRenderAll();
      updateSelUi();
    });
    C.on('selection:created', updateSelUi);    C.on('selection:created', updateSelUi);
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
    // (round 13 #10) an X badge on the object replaces hunting for the
    // Remove button; (#11) the rotate handle draws a curved arrow, not a dot.
    var deleteControl = new fabric.Control({
      x: 0.5, y: -0.5, offsetX: 14, offsetY: -14, sizeX: 26, sizeY: 26, cursorStyle: 'pointer',
      mouseUpHandler: function (eventData, transform) {
        var t = transform.target, cv = t.canvas;
        if (!cv) return true;
        (t.type === 'activeSelection' ? t.getObjects() : [t]).forEach(function (o) { cv.remove(o); });
        cv.discardActiveObject();
        cv.requestRenderAll();
        updateSelUi();
        return true;
      },
      render: function (ctx, left, top) {
        ctx.save();
        ctx.translate(left, top);
        ctx.beginPath(); ctx.arc(0, 0, 11, 0, Math.PI * 2);
        ctx.fillStyle = '#E4002B'; ctx.fill();
        ctx.strokeStyle = '#FFFFFF'; ctx.lineWidth = 2; ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(-4, -4); ctx.lineTo(4, 4);
        ctx.moveTo(4, -4); ctx.lineTo(-4, 4);
        ctx.stroke();
        ctx.restore();
      },
    });
    function rotateRender(ctx, left, top) {
      ctx.save();
      ctx.translate(left, top);
      ctx.beginPath(); ctx.arc(0, 0, 11, 0, Math.PI * 2);
      ctx.fillStyle = '#FFFFFF'; ctx.fill();
      ctx.strokeStyle = '#111111'; ctx.lineWidth = 1; ctx.stroke();
      ctx.lineWidth = 2; ctx.lineCap = 'round';
      ctx.beginPath(); ctx.arc(0, 0, 5.5, -Math.PI * 0.2, Math.PI * 1.1); ctx.stroke();
      var ax = 5.5 * Math.cos(-Math.PI * 0.2), ay = 5.5 * Math.sin(-Math.PI * 0.2);
      ctx.beginPath();
      ctx.moveTo(ax - 3.2, ay - 1.4); ctx.lineTo(ax, ay); ctx.lineTo(ax + 0.6, ay - 3.8);
      ctx.stroke();
      ctx.restore();
    }
    [fabric.Object, fabric.Textbox].forEach(function (cls) {
      if (!cls || !cls.prototype || !cls.prototype.controls) return;
      cls.prototype.controls.gdDelete = deleteControl;
      if (cls.prototype.controls.mtr) {
        cls.prototype.controls.mtr.render = rotateRender;
        cls.prototype.controls.mtr.sizeX = 26;
        cls.prototype.controls.mtr.sizeY = 26;
      }
    });
  }

  function withActive(fn) {
    var o = C.getActiveObject();
    if (o) { fn(o); C.requestRenderAll(); }
  }

  function activeContent() {
    if (S.multiMode) return S.multiPicks.slice();
    return (C.getActiveObjects() || []).filter(function (o) { return !o.gdOverlay; });
  }

  function updateSelUi() {
    var o = C.getActiveObject();
    stageMeta.selBar.style.display = o ? 'flex' : 'none';
    var multi = (o && o.type === 'activeSelection') || (S.multiMode && S.multiPicks.length > 0);
    if (S.multiMode) { stageMeta.selBar.style.display = S.multiPicks.length ? 'flex' : 'none'; }
    var cropBtn = stageMeta.selBar.children[0];
    var cutBtn = stageMeta.selBar.children[1];
    var eraseBtn = stageMeta.selBar.children[2];
    if (cropBtn) {
      var croppable = !multi && o && o.gdType === 'image' && String(o.gdRef).indexOf('sticker:') !== 0;
      cropBtn.style.display = croppable ? '' : 'none';
    }
    if (eraseBtn) {
      var erasable = !multi && o && o.gdType === 'image' && String(o.gdRef).indexOf('upload:') === 0;
      eraseBtn.style.display = erasable ? '' : 'none';
    }
    if (cutBtn) {
      var isPhoto = !multi && o && o.gdType === 'image' && String(o.gdRef).indexOf('upload:') === 0;
      cutBtn.style.display = isPhoto ? '' : 'none';
      cutBtn.textContent = o && o.gdVariants ? (o.gdRef === 'upload:' + o.gdVariants.sticker ? COPY.cutout : COPY.outline) : COPY.cutout;
      if (o && o.gdVariants && o.gdRef === 'upload:' + o.gdVariants.cutout) cutBtn.textContent = COPY.outline;
    }
    var undoCut = stageMeta.selBar.children[3];
    if (undoCut) {
      undoCut.style.display = (!multi && o && o.gdVariants && o.gdVariants.original) ? '' : 'none';
    }
    var removeBtn = stageMeta.selBar.children[6];
    if (removeBtn) {
      // The X badge on the object handles single removal (round 13 #10); the
      // bar button stays only for multi-select batches.
      removeBtn.style.display = multi ? '' : 'none';
    }
    checkPlacement();
  }

  function swapImageSrc(o, uploadId) {
    var prevW = o.getScaledWidth();
    fetch(cfg.api + '/v1/uploads/' + uploadId, { headers: { authorization: 'Bearer ' + S.token } })
      .then(function (r) { return r.blob(); })
      .then(function (blob) {
        var url = URL.createObjectURL(blob);
        o.setSrc(url, function () {
          o.gdCrop = null;
          o.set({ cropX: 0, cropY: 0 });
          var scale = prevW / (o.width || 1);
          o.set({ scaleX: scale, scaleY: scale });
          o.gdRef = 'upload:' + uploadId;
          o.setCoords();
          C.requestRenderAll();
          updateSelUi();
        });
      })
      .catch(function () { stageMeta.warn.textContent = COPY.errB; });
  }

  function doCutout() {
    var o = C.getActiveObject();
    if (!o || o.gdType !== 'image') return;
    if (o.gdVariants) {
      // Toggle between the plain cutout and the white-outline sticker.
      var next = o.gdRef === 'upload:' + o.gdVariants.cutout ? o.gdVariants.sticker : o.gdVariants.cutout;
      swapImageSrc(o, next);
      return;
    }
    var srcId = String(o.gdRef).slice(7);
    stageMeta.warn.textContent = COPY.cuttingOut;
    stageMeta.progress.style.display = '';
    api('/v1/uploads/' + srcId + '/cutout', { method: 'POST' })
      .then(function (b) {
        stageMeta.progress.style.display = 'none';
        if (b.__status !== 200) { stageMeta.warn.textContent = b.human_message || COPY.errB; return; }
        o.gdVariants = { original: srcId, cutout: b.cutout_id, sticker: b.sticker_id };
        stageMeta.warn.textContent = '';
        swapImageSrc(o, b.cutout_id);
        ga('studio_designer_cutout', {});
      })
      .catch(function () { stageMeta.progress.style.display = 'none'; stageMeta.warn.textContent = COPY.errB; });
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
    var targets = activeContent();
    C.discardActiveObject();
    targets.forEach(function (o) {
      o.clone(function (copy) {
        copy.set({ left: o.left + 14, top: o.top + 14 });
        copy.gdType = o.gdType; copy.gdRef = o.gdRef;
        copy.gdText = o.gdText; copy.gdFont = o.gdFont; copy.gdColour = o.gdColour;
        copy.gdEffect = o.gdEffect; copy.gdEffectColour = o.gdEffectColour; if (copy.gdType === 'text') applyTextEffect(copy);
        copy.gdCrop = o.gdCrop; copy.gdVariants = o.gdVariants;
        C.add(copy);
        if (targets.length === 1) C.setActiveObject(copy);
      });
    });
    C.requestRenderAll();
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
    if (stageMeta && stageMeta.exitMulti) stageMeta.exitMulti();
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

  function stickerSheet(category) {
    if (stageMeta && stageMeta.exitMulti) stageMeta.exitMulti();
    var isFrame = category === 'frame';
    var body = el('div', {});
    var overlay = sheet(isFrame ? COPY.frameSheetH : COPY.stickerSheetH, [body]);
    var render = function (manifest) {
      var packs = (manifest && manifest.packs) || {};
      var packIds = Object.keys(packs).filter(function (packId) {
        var cat = (packs[packId] && packs[packId].category) || (packId.indexOf('frame') === 0 ? 'frame' : 'sticker');
        return isFrame ? cat === 'frame' : cat !== 'frame';
      });
      if (!packIds.length) { body.appendChild(el('p', { class: 'gstudio-note', text: COPY.stickerEmpty })); return; }
      packIds.forEach(function (packId) {
        var pack = packs[packId];
        body.appendChild(el('div', { class: 'gd-packlabel', text: pack.label }));
        var grid = el('div', { class: 'gd-stickergrid' });
        (pack.stickers || []).forEach(function (st) {
          var img = el('img', {
            src: cfg.api + '/v1/stickers/' + packId + '/' + st.id + '.thumb',
            alt: st.label, loading: 'lazy',
          });
          grid.appendChild(el('button', {
            class: 'gd-sticker', type: 'button',
            onclick: function () { placeSticker(packId, st.id); overlay.remove(); },
          }, [img]));
        });
        body.appendChild(grid);
      });
    };
    if (S.stickers) { render(S.stickers); return; }
    fetch(cfg.api + '/v1/stickers')
      .then(function (r) { return r.json(); })
      .then(function (m) { S.stickers = m; render(m); })
      .catch(function () { render(null); });
  }

  function placeSticker(packId, id) {
    fabric.Image.fromURL(cfg.api + '/v1/stickers/' + packId + '/' + id, function (img) {
      if (!img || !img.width) return;
      var scale = (stageMeta.plateW * 0.34) / img.width;
      img.set({
        left: stageMeta.plateW / 2, top: stageMeta.plateH * 0.55,
        originX: 'center', originY: 'center', scaleX: scale, scaleY: scale,
      });
      img.setControlsVisibility({ ml: false, mr: false, mt: false, mb: false });
      img.gdType = 'image'; img.gdRef = 'sticker:' + packId + '/' + id;
      C.add(img); C.setActiveObject(img); C.requestRenderAll();
      ga('studio_designer_sticker', { pack: packId, sticker: id });
    }, { crossOrigin: 'anonymous' });
  }

  function textSheet(existing) {
    if (stageMeta && stageMeta.exitMulti) stageMeta.exitMulti();
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
    var effectKey = (existing && existing.gdEffect) || 'none';
    var effectColourKey = (existing && existing.gdEffectColour)
      || (LIGHT_COLOURS[colourKey] ? 'ink' : 'white');
    var effectColourRow = el('div', { class: 'gd-bgrow' });
    TEXT_COLOURS.forEach(function (c) {
      effectColourRow.appendChild(el('button', {
        class: 'gd-swatch' + (effectColourKey === c[0] ? ' sel' : ''), type: 'button', style: 'background:' + c[1],
        onclick: function (e) {
          effectColourKey = c[0];
          Array.prototype.forEach.call(effectColourRow.children, function (b) { b.classList.remove('sel'); });
          e.target.classList.add('sel');
          refreshPreview();
        },
      }));
    });
    var effectColourLabel = el('span', { class: 'gstudio-label', text: COPY.effectColourLabel });
    var effectRow = el('div', { class: 'gd-bgrow' });
    [['none', COPY.effectNone], ['shadow', COPY.effectShadow], ['outline', COPY.effectOutline]].forEach(function (ef) {
      effectRow.appendChild(el('button', {
        class: 'gd-tool' + (effectKey === ef[0] ? ' sel' : ''), type: 'button', text: ef[1],
        onclick: function (e) {
          effectKey = ef[0];
          Array.prototype.forEach.call(effectRow.children, function (b) { b.classList.remove('sel'); });
          e.target.classList.add('sel');
          refreshPreview();
        },
      }));
    });
    var preview = el('div', { class: 'gd-fontpreview', text: (existing ? existing.gdText : '') || 'Aiman' });
    function refreshPreview() {
      preview.textContent = input.value.trim() || 'Aiman';
      preview.style.fontFamily = "'gd-" + fontSel.value + "', cursive";
      preview.style.color = COLOUR_HEX[colourKey];
      preview.style.background = LIGHT_COLOURS[colourKey] ? '#111111' : '#F5F5F3';
      // The canvas strokes UNDER the glyph fill; -webkit-text-stroke paints
      // half inside and looked wrong (owner catch). Eight hard shadows fake
      // the under-fill stroke faithfully.
      if (effectKey === 'outline') {
        var oc = COLOUR_HEX[effectColourKey] || '#FFFFFF';
        var w2 = '2px';
        preview.style.textShadow = [
          w2 + ' 0 0 ' + oc, '-' + w2 + ' 0 0 ' + oc,
          '0 ' + w2 + ' 0 ' + oc, '0 -' + w2 + ' 0 ' + oc,
          '1.4px 1.4px 0 ' + oc, '-1.4px 1.4px 0 ' + oc,
          '1.4px -1.4px 0 ' + oc, '-1.4px -1.4px 0 ' + oc,
        ].join(', ');
      } else if (effectKey === 'shadow') {
        preview.style.textShadow = '2px 2px 5px rgba(17,17,17,0.55)';
      } else {
        preview.style.textShadow = 'none';
      }
      effectColourLabel.style.display = effectKey === 'outline' ? '' : 'none';
      effectColourRow.style.display = effectKey === 'outline' ? '' : 'none';
      fontSel.style.fontFamily = "'gd-" + fontSel.value + "', Inter, Arial, sans-serif";
    }
    input.oninput = refreshPreview;
    fontSel.onchange = refreshPreview;
    refreshPreview();

    var overlay = sheet(COPY.textSheetH, [
      input,
      preview,
      el('span', { class: 'gstudio-label', text: COPY.fontLabel }), fontSel,
      el('span', { class: 'gstudio-label', text: COPY.colourLabel }), colourRow,
      el('span', { class: 'gstudio-label', text: COPY.effectLabel }), effectRow,
      effectColourLabel, effectColourRow,
      el('button', {
        class: 'gstudio-btn gstudio-btn--ink', type: 'button', text: existing ? COPY.update : COPY.place,
        onclick: function () {
          var text = input.value.trim();
          if (!text) return;
          if (existing) {
            existing.set({ text: text, fontFamily: 'gd-' + fontSel.value, fill: COLOUR_HEX[colourKey] });
            existing.gdText = text; existing.gdFont = fontSel.value; existing.gdColour = colourKey;
            existing.gdEffect = effectKey;
            existing.gdEffectColour = effectColourKey;
            applyTextEffect(existing);
          } else {
            var t = new fabric.Text(text, {
              left: stageMeta.plateW / 2, top: stageMeta.plateH * 0.8,
              originX: 'center', originY: 'center',
              fontFamily: 'gd-' + fontSel.value, fill: COLOUR_HEX[colourKey],
              fontSize: Math.round(stageMeta.plateW / 7),
            });
            t.setControlsVisibility({ ml: false, mr: false, mt: false, mb: false });
            t.gdType = 'text'; t.gdText = text; t.gdFont = fontSel.value; t.gdColour = colourKey;
            t.gdEffect = effectKey;
            t.gdEffectColour = effectColourKey;
            applyTextEffect(t);
            C.add(t); C.setActiveObject(t);
          }
          C.requestRenderAll();
          ga('studio_designer_text', { font: fontSel.value });
          overlay.remove();
        },
      }),
    ]);
  }

  function cropSheet() {
    if (stageMeta && stageMeta.exitMulti) stageMeta.exitMulti();
    var o = C.getActiveObject();
    if (!o || o.gdType !== 'image' || !o._element) return;
    var nW = o._element.naturalWidth || o._element.width;
    var nH = o._element.naturalHeight || o._element.height;
    var r = o.gdCrop ? { x: o.gdCrop.x, y: o.gdCrop.y, w: o.gdCrop.w, h: o.gdCrop.h } : { x: 0, y: 0, w: 1, h: 1 };

    var img = el('img', { class: 'gd-cropimg', src: o._element.src, alt: '' });
    var rect = el('div', { class: 'gd-croprect' }, [
      el('span', { class: 'gd-crophd nw' }), el('span', { class: 'gd-crophd ne' }),
      el('span', { class: 'gd-crophd sw' }), el('span', { class: 'gd-crophd se' }),
    ]);
    var box = el('div', { class: 'gd-cropbox' }, [img, rect]);

    function paint() {
      rect.style.left = (r.x * 100) + '%';
      rect.style.top = (r.y * 100) + '%';
      rect.style.width = (r.w * 100) + '%';
      rect.style.height = (r.h * 100) + '%';
    }
    paint();

    var drag = null;
    rect.onpointerdown = function (e) {
      drag = { corner: e.target.className.indexOf('gd-crophd') === 0 ? e.target.className.split(' ')[1] : null,
        sx: e.clientX, sy: e.clientY, r0: { x: r.x, y: r.y, w: r.w, h: r.h } };
      try { rect.setPointerCapture(e.pointerId); } catch (err) { /* synthetic or lost pointers */ }
      e.preventDefault();
    };
    rect.onpointermove = function (e) {
      if (!drag) return;
      var dx = (e.clientX - drag.sx) / (img.clientWidth || 1);
      var dy = (e.clientY - drag.sy) / (img.clientHeight || 1);
      var r0 = drag.r0, c = drag.corner;
      var n = { x: r0.x, y: r0.y, w: r0.w, h: r0.h };
      if (!c) { n.x = r0.x + dx; n.y = r0.y + dy; }
      else {
        if (c === 'nw') { n.x = r0.x + dx; n.y = r0.y + dy; n.w = r0.w - dx; n.h = r0.h - dy; }
        if (c === 'ne') { n.y = r0.y + dy; n.w = r0.w + dx; n.h = r0.h - dy; }
        if (c === 'sw') { n.x = r0.x + dx; n.w = r0.w - dx; n.h = r0.h + dy; }
        if (c === 'se') { n.w = r0.w + dx; n.h = r0.h + dy; }
      }
      n.w = Math.max(0.05, Math.min(1, n.w));
      n.h = Math.max(0.05, Math.min(1, n.h));
      n.x = Math.min(Math.max(n.x, 0), 1 - n.w);
      n.y = Math.min(Math.max(n.y, 0), 1 - n.h);
      r = n;
      paint();
    };
    rect.onpointerup = function () { drag = null; };
    rect.onpointercancel = function () { drag = null; };

    function applyTo(target, cropOrNull) {
      var prevW = target.getScaledWidth();
      if (cropOrNull) {
        target.gdCrop = { x: +cropOrNull.x.toFixed(4), y: +cropOrNull.y.toFixed(4), w: +cropOrNull.w.toFixed(4), h: +cropOrNull.h.toFixed(4) };
        target.set({ cropX: cropOrNull.x * nW, cropY: cropOrNull.y * nH, width: cropOrNull.w * nW, height: cropOrNull.h * nH });
      } else {
        target.gdCrop = null;
        target.set({ cropX: 0, cropY: 0, width: nW, height: nH });
      }
      var scale = prevW / (target.width || 1);
      target.set({ scaleX: scale, scaleY: scale });
      target.setCoords();
      C.requestRenderAll();
      checkPlacement();
    }

    var overlay = sheet(COPY.cropSheetH, [
      box,
      el('button', {
        class: 'gstudio-btn gstudio-btn--ink', type: 'button', text: COPY.applyCrop,
        onclick: function () { applyTo(o, r); ga('studio_designer_crop', {}); overlay.remove(); },
      }),
      el('button', {
        class: 'gstudio-btn gstudio-btn--ghost', type: 'button', text: COPY.fullPhoto,
        onclick: function () { applyTo(o, null); overlay.remove(); },
      }),
    ]);
  }

  function eraseSheet() {
    if (stageMeta && stageMeta.exitMulti) stageMeta.exitMulti();
    var o = C.getActiveObject();
    if (!o || o.gdType !== 'image' || !o._element) return;
    var src = o._element;
    var nW = src.naturalWidth || src.width;
    var nH = src.naturalHeight || src.height;
    var cap = 1000;
    var scale = Math.min(1, cap / Math.max(nW, nH));
    var cw = Math.round(nW * scale), ch = Math.round(nH * scale);

    var work = document.createElement('canvas');
    work.width = cw; work.height = ch;
    work.className = 'gd-erasecanvas';
    var ctx = work.getContext('2d');
    ctx.drawImage(src, 0, 0, cw, ch);

    var brush = Math.round(Math.max(cw, ch) * 0.05);
    var undoStack = [];
    var drawing = false;

    function eraseAt(x, y) {
      ctx.globalCompositeOperation = 'destination-out';
      ctx.beginPath();
      ctx.arc(x, y, brush / 2, 0, Math.PI * 2);
      ctx.fill();
    }
    function toCanvasXY(e) {
      var r = work.getBoundingClientRect();
      return [ (e.clientX - r.left) * (cw / r.width), (e.clientY - r.top) * (ch / r.height) ];
    }
    work.style.touchAction = 'none';
    work.style.transformOrigin = '0 0';

    // (round 13 #4) zoom + pan for precise erasing; brush is a slider.
    var zoom = 1, panX = 0, panY = 0, mode = 'erase';
    var view = el('div', { class: 'gd-eraseview' }, [work]);
    function applyView() {
      var vw = view.clientWidth || 1, vh = view.clientHeight || 1;
      var dw = work.clientWidth * zoom, dh = work.clientHeight * zoom;
      panX = Math.min(0, Math.max(vw - dw, panX));
      panY = Math.min(0, Math.max(vh - dh, panY));
      if (dw <= vw) panX = (vw - dw) / 2;
      if (dh <= vh) panY = (vh - dh) / 2;
      work.style.transform = 'translate(' + panX + 'px,' + panY + 'px) scale(' + zoom + ')';
    }
    var panStart = null;
    work.onpointerdown = function (e) {
      if (mode === 'move') {
        panStart = { x: e.clientX - panX, y: e.clientY - panY };
        try { work.setPointerCapture(e.pointerId); } catch (err) { /* synthetic pointers */ }
        e.preventDefault();
        return;
      }
      if (undoStack.length >= 5) undoStack.shift();
      undoStack.push(ctx.getImageData(0, 0, cw, ch));
      drawing = true;
      try { work.setPointerCapture(e.pointerId); } catch (err) { /* synthetic pointers */ }
      var p = toCanvasXY(e);
      eraseAt(p[0], p[1]);
      e.preventDefault();
    };
    work.onpointermove = function (e) {
      if (mode === 'move' && panStart) {
        panX = e.clientX - panStart.x;
        panY = e.clientY - panStart.y;
        applyView();
        return;
      }
      if (!drawing) return;
      var p = toCanvasXY(e);
      eraseAt(p[0], p[1]);
    };
    work.onpointerup = function () { drawing = false; panStart = null; };
    work.onpointercancel = function () { drawing = false; panStart = null; };

    var modeRow = el('div', { class: 'gd-bgrow' });
    [['erase', COPY.eraseDraw], ['move', COPY.eraseMove]].forEach(function (m, i) {
      modeRow.appendChild(el('button', {
        class: 'gd-tool' + (i === 0 ? ' sel' : ''), type: 'button', text: m[1],
        onclick: function (e) {
          mode = m[0];
          Array.prototype.forEach.call(modeRow.children, function (b) { b.classList.remove('sel'); });
          e.target.classList.add('sel');
        },
      }));
    });
    modeRow.appendChild(el('button', {
      class: 'gd-tool', type: 'button', text: COPY.undo,
      onclick: function () {
        var snap = undoStack.pop();
        if (snap) { ctx.globalCompositeOperation = 'source-over'; ctx.putImageData(snap, 0, 0); }
      },
    }));

    var brushSlider = el('input', { class: 'gstudio-range', type: 'range', min: '12', max: '120', value: '50' });
    brushSlider.oninput = function () {
      brush = Math.round(Math.max(cw, ch) * (parseInt(brushSlider.value, 10) / 1000));
    };
    var zoomSlider = el('input', { class: 'gstudio-range', type: 'range', min: '100', max: '400', value: '100' });
    zoomSlider.oninput = function () {
      var vw = view.clientWidth || 1, vh = view.clientHeight || 1;
      var oldZoom = zoom;
      zoom = parseInt(zoomSlider.value, 10) / 100;
      // keep the viewport centre stable while zooming
      panX = vw / 2 - (vw / 2 - panX) * (zoom / oldZoom);
      panY = vh / 2 - (vh / 2 - panY) * (zoom / oldZoom);
      applyView();
    };
    var sliderRow = el('div', { class: 'gd-sliderrow' }, [
      el('label', { class: 'gstudio-note', text: COPY.eraseBrush }), brushSlider,
      el('label', { class: 'gstudio-note', text: COPY.eraseZoom }), zoomSlider,
    ]);

    var status = el('p', { class: 'gstudio-note', text: COPY.eraseHint });
    var overlay = sheet(COPY.eraseSheetH, [
      view,
      modeRow,
      sliderRow,
      status,
      el('button', {
        class: 'gstudio-btn gstudio-btn--ink', type: 'button', text: COPY.applyErase,
        onclick: function () {
          status.textContent = COPY.saving;
          work.toBlob(function (blob) {
            if (!blob) { status.textContent = COPY.errB; return; }
            var fd = new FormData();
            fd.append('file', new File([blob], 'erased.png', { type: 'image/png' }));
            api('/v1/uploads', { method: 'POST', body: fd })
              .then(function (b) {
                if (b.__status !== 200) { status.textContent = b.human_message || COPY.errB; return; }
                o.gdVariants = null;
                o.gdCrop = null;
                o.set({ cropX: 0, cropY: 0 });
                swapImageSrc(o, b.upload_id);
                ga('studio_designer_erase', {});
                overlay.remove();
              })
              .catch(function () { status.textContent = COPY.errB; });
          }, 'image/png');
        },
      }),
    ]);
    setTimeout(applyView, 0);
  }

  // ---- serialize + done ----------------------------------------------------

  function serializeScene() {
    // A live multi-selection stores member coordinates relative to the
    // group; drop it so every object reports absolute print coordinates.
    var act = C.getActiveObject();
    if (act && act.type === 'activeSelection') { C.discardActiveObject(); C.requestRenderAll(); }
    var pw = stageMeta.plateW, ph = stageMeta.plateH;
    var elements = contentObjects().map(function (o) {
      var base = {
        cx: +(o.left / pw).toFixed(4),
        cy: +(o.top / ph).toFixed(4),
        w: +((o.getScaledWidth()) / pw).toFixed(4),
        rot: Math.round(((o.angle % 360) + 540) % 360 - 180),
      };
      if (o.gdType === 'text') {
        var textEl = { type: 'text', text: o.gdText, font: o.gdFont, colour: o.gdColour, cx: base.cx, cy: base.cy, w: base.w, rot: base.rot };
        if (o.gdEffect && o.gdEffect !== 'none') {
          textEl.effect = o.gdEffect;
          if (o.gdEffect === 'outline' && o.gdEffectColour) textEl.effect_colour = o.gdEffectColour;
        }
        return textEl;
      }
      var imgEl = { type: 'image', ref: o.gdRef, cx: base.cx, cy: base.cy, w: base.w, rot: base.rot };
      if (o.gdCrop) imgEl.crop = o.gdCrop;
      return imgEl;
    });
    return {
      version: 1,
      model_id: S.modelId,
      background: null, // owner call (16 Jul round 4): clear case only
      elements: elements,
    };
  }

  function finishDesign() {
    if (stageMeta && stageMeta.exitMulti) stageMeta.exitMulti();
    var scene = serializeScene();
    if (!scene.elements.length) {
      stageMeta.warn.textContent = COPY.emptyNote;
      return;
    }
    S.scene = scene;
    var doneBtn = document.querySelector('.gd-done');
    if (doneBtn) { doneBtn.disabled = true; doneBtn.textContent = COPY.saving; }
    var restore = function (message) {
      if (doneBtn) { doneBtn.disabled = false; doneBtn.textContent = COPY.doneCta; }
      stageMeta.warn.textContent = message || '';
      stageMeta.warn.className = 'gd-warn gd-warn--hard';
    };
    ga('studio_designer_done', { elements: scene.elements.length });
    api('/v1/designs', { method: 'POST', json: { scene: scene } })
      .then(function (design) {
        if (design.__status !== 200) { restore(design.human_message || COPY.errB); return; }
        // (round 13 #9) show the server-rendered artwork and ask before
        // anything reaches the cart.
        restore('');
        confirmSheet(design);
      })
      .catch(function () { restore(COPY.errB); });
  }

  function confirmSheet(design) {
    var img = el('img', {
      class: 'gd-confirmimg',
      src: cfg.api + design.preview_url,
      alt: 'Your design, exactly as it prints',
    });
    var note = el('p', { class: 'gstudio-note', text: COPY.confirmB });
    var err = el('p', { class: 'gd-warn gd-warn--hard', text: '' });
    var addBtn = el('button', {
      class: 'gstudio-btn gstudio-btn--ink', type: 'button', text: COPY.confirmCta,
      onclick: function () {
        addBtn.disabled = true;
        addBtn.textContent = COPY.saving;
        fetch(cfg.cart_url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({
            artwork_token: design.artwork_token,
            artwork_id: design.artwork_id,
            model_id: S.modelId,
            style_id: 'designer',
            name_text: '',
          }),
        })
          .then(function (res) { return res.json().then(function (b) { b.__status = res.status; return b; }); })
          .then(function (body) {
            if (body.__status !== 200 || !body.ok) {
              addBtn.disabled = false;
              addBtn.textContent = COPY.confirmCta;
              err.textContent = body.message || COPY.errB;
              return;
            }
            ga('studio_cart', { style_id: 'designer', model_id: S.modelId, value: 169, currency: 'MYR' });
            ga('add_to_cart', {
              currency: 'MYR', value: 169,
              items: [{ item_id: 'studio-case', item_name: 'Studio Case', item_variant: S.modelId, item_category: 'Studio Designer', price: 169, quantity: 1 }],
            });
            overlay.remove();
            renderAdded(body.checkout || body.cart_url || '#');
          })
          .catch(function () {
            addBtn.disabled = false;
            addBtn.textContent = COPY.confirmCta;
            err.textContent = COPY.errB;
          });
      },
    });
    var overlay = sheet(COPY.confirmH, [
      img,
      note,
      addBtn,
      el('button', { class: 'gstudio-btn gstudio-btn--ghost', type: 'button', text: COPY.confirmBack, onclick: function () { overlay.remove(); } }),
      err,
    ]);
  }

  function renderAdded(checkoutUrl) {
    mount(
      el('div', { class: 'gstudio-ok', text: '\u2713' }),
      el('h2', { class: 'gstudio-center', text: COPY.addedH }),
      el('p', { class: 'gstudio-sub gstudio-center', text: COPY.addedB }),
      el('a', { class: 'gstudio-btn gstudio-btn--red', href: checkoutUrl, text: COPY.checkoutCta }),
      el('button', {
        class: 'gstudio-btn gstudio-btn--ghost', type: 'button', text: COPY.againCta, style: 'margin-top:10px',
        onclick: function () { S.scene = null; renderModelSelect(); },
      })
    );
  }

  renderModelSelect();
})();
