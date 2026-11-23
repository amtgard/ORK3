/*
 * Upload Signature: one person's signature image on the signature line of every
 * certificate on this print page.
 *
 * The file never leaves the browser. It is read here, cropped on a canvas and shown
 * through an object URL, so nothing reaches the server. A reload starts without it,
 * with two exceptions: the reload a Heraldry or Version change makes carries the crop
 * across in sessionStorage for that one load (keepForReload, below), and a BATCHED run
 * (bulk, `mine`, a court) keeps it in localStorage under the run's key for RUN_TTL, so
 * batches 2..N — each its own tab, opened noopener — draw the signature uploaded in
 * batch 1 (storeRun / readRun, below). Expired run entries are swept on every load.
 *
 * The typed signer stays in the page, hidden, because the fit measures it and because
 * Remove has to bring it back. The image's box is stored in percentages of its
 * .AwardSignatory, so it scales with the certificate from screen to paper.
 */
(function () {
    'use strict';

    var MAX_BYTES  = 10 * 1024 * 1024;
    var MAX_SIDE   = 2400;  // Far past what a signature line prints at; keeps the crop quick.
    var MIN_ALPHA  = 8;     // Below this a pixel is scanner haze, not ink.
    var MAX_HEIGHT = 0.45;  // Of the line's width: a tall file must not swamp the block.
    var INK_DROP   = 0.1;   // Of the height: signatures sit through their line, not on it.

    var input  = document.getElementById('sc-sigfile');
    var button = document.getElementById('sc-sigfile-pick');
    if (!input || !button) { return; }

    var remove = document.getElementById('sc-sigfile-remove');
    var status = document.getElementById('sc-sigfile-status');
    var url    = null;
    var observer = null;
    var kept   = null;  // { canvas, transparent } — the crop, for carrying across a reload.

    /* One-reload carry. A Heraldry or Version change reloads the page (Scrollery_render.tpl),
       and the object URL dies with it, so the crop rides in sessionStorage as a data URL
       for exactly that one load, keyed to the run: the path plus the query string
       without the parameters the reload itself changes. A different run in the
       same tab never picks it up, and it is removed the moment it is read. */
    var KEEP_KEY = 'sc-sig-keep';

    function runKey() {
        var s = window.location.search.replace(/^\?/, '').split('&').filter(function (part) {
            var name = part.split('=')[0];
            return part !== '' && name !== 'heraldry' && name !== 'variant' && name !== 'print' && name !== 'configure';
        });
        return window.location.pathname + '?' + s.join('&');
    }

    /* The batched-run carry. data-sc-sig-run on <body> is the run's key
       (Scrollery_render.tpl: &sigrun= from bulk / `mine`, or the court id), shared by
       every batch tab of the run. localStorage rather than sessionStorage because each
       batch opens noopener in a tab of its own, which starts an empty session store. */
    var RUN          = document.body.getAttribute('data-sc-sig-run') || '';
    var RUN_PREFIX   = 'sc-sig-run:';
    var RUN_TTL      = 2 * 60 * 60 * 1000;  // A sitting of printing, not a keepsake.
    var RUN_MAX_SIDE = 1600;  // Still sharper than the line prints; keeps the entry inside quota.
    var runNote      = document.getElementById('sc-sig-run-note');

    function local() {
        try { return window.localStorage || null; } catch (e) { return null; }
    }

    /* Drop every run entry that is past its time (or unreadable), from any run. */
    function sweepRuns() {
        var ls = local();
        if (!ls) { return; }
        try {
            var now = Date.now(), dead = [];
            for (var i = 0; i < ls.length; i++) {
                var k = ls.key(i);
                if (!k || k.indexOf(RUN_PREFIX) !== 0) { continue; }
                var entry = null;
                try { entry = JSON.parse(ls.getItem(k)); } catch (e) { entry = null; }
                if (!entry || typeof entry.exp !== 'number' || entry.exp < now) { dead.push(k); }
            }
            dead.forEach(function (k) { ls.removeItem(k); });
        } catch (e) { /* storage refused: nothing to sweep */ }
    }

    function shrinkForRun(canvas) {
        var scale = Math.min(1, RUN_MAX_SIDE / Math.max(canvas.width, canvas.height));
        if (scale >= 1) { return canvas; }
        var out = document.createElement('canvas');
        out.width  = Math.max(1, Math.round(canvas.width * scale));
        out.height = Math.max(1, Math.round(canvas.height * scale));
        out.getContext('2d').drawImage(canvas, 0, 0, out.width, out.height);
        return out;
    }

    /* Keep an uploaded signature for the rest of this run. When the image itself
       cannot be stored (quota), a small marker is, so the later batches can say the
       signature was lost rather than silently printing the typed signer. */
    function storeRun(canvas, transparent) {
        var ls = RUN ? local() : null;
        if (!ls) { return; }
        var key = RUN_PREFIX + RUN, exp = Date.now() + RUN_TTL;
        try {
            ls.setItem(key, JSON.stringify({ exp: exp, data: shrinkForRun(canvas).toDataURL('image/png'), transparent: !!transparent }));
        } catch (e) {
            try { ls.setItem(key, JSON.stringify({ exp: exp, failed: true })); } catch (e2) { /* nothing more to try */ }
        }
    }

    /* Remove, not a page leaving: the next batch should print the typed signer. */
    function dropRun() {
        var ls = RUN ? local() : null;
        if (!ls) { return; }
        try { ls.removeItem(RUN_PREFIX + RUN); } catch (e) { /* nothing stored to drop */ }
    }

    /* This run's entry: null when nothing was uploaded, {failed:true} when one was and
       cannot be brought back. */
    function readRun() {
        var ls = RUN ? local() : null;
        if (!ls) { return null; }
        var raw = null;
        try { raw = ls.getItem(RUN_PREFIX + RUN); } catch (e) { return null; }
        if (raw === null) { return null; }
        try {
            var entry = JSON.parse(raw);
            return entry && typeof entry === 'object' ? entry : { failed: true };
        } catch (e) { return { failed: true }; }
    }

    function noteRunLost() {
        var msg = 'Signature image was used in batch 1; upload again.';
        if (runNote) {
            runNote.textContent = msg + ' It could not be carried into this tab — use Upload Signature before printing.';
            runNote.hidden = false;
        }
        say(msg, true);
    }

    function hideRunNote() {
        if (runNote) { runNote.hidden = true; runNote.textContent = ''; }
    }

    function dataUrlToBlob(dataUrl) {
        var comma = dataUrl.indexOf(',');
        var bytes = window.atob(dataUrl.slice(comma + 1));
        var out = new Uint8Array(bytes.length);
        for (var i = 0; i < bytes.length; i++) { out[i] = bytes.charCodeAt(i); }
        return new Blob([out], { type: 'image/png' });
    }

    function signatories() {
        return Array.prototype.slice.call(document.querySelectorAll('.AwardSignatory'));
    }

    function say(message, isProblem) {
        status.textContent = message;
        status.hidden = message === '';
        status.classList.toggle('sc-opt-note', !!isProblem);
        status.classList.toggle('sc-opt-help', !isProblem);
    }

    /* Crop to the ink, so a signature with wide empty margins still fills the line. */
    function crop(image) {
        var scale  = Math.min(1, MAX_SIDE / Math.max(image.naturalWidth, image.naturalHeight));
        var width  = Math.max(1, Math.round(image.naturalWidth * scale));
        var height = Math.max(1, Math.round(image.naturalHeight * scale));
        var full   = document.createElement('canvas');
        full.width  = width;
        full.height = height;
        var context = full.getContext('2d', { willReadFrequently: true });
        context.drawImage(image, 0, 0, width, height);

        var pixels = context.getImageData(0, 0, width, height).data;
        var left = width, right = -1, top = height, bottom = -1, transparent = false;
        for (var y = 0; y < height; y++) {
            for (var x = 0; x < width; x++) {
                var alpha = pixels[(y * width + x) * 4 + 3];
                if (alpha < 250) { transparent = true; }
                if (alpha >= MIN_ALPHA) {
                    if (x < left) { left = x; }
                    if (x > right) { right = x; }
                    if (y < top) { top = y; }
                    if (y > bottom) { bottom = y; }
                }
            }
        }
        if (right < 0) { return null; }

        var cut = document.createElement('canvas');
        cut.width  = right - left + 1;
        cut.height = bottom - top + 1;
        cut.getContext('2d').drawImage(full, -left, -top);
        return { canvas: cut, transparent: transparent };
    }

    /* The box above the writing line, up to the text over it. object-fit does the rest.
       Read only: measure every signatory first, then write every one, so a bulk page
       of N certificates costs one layout rather than N (a write between two reads
       forces the browser to lay the page out again before the next read). */
    function measure(signatory) {
        var image = signatory.querySelector('.ScUploadedSig');
        var line  = signatory.querySelector('.Underline');
        if (!image || !line) { return null; }

        var box  = signatory.getBoundingClientRect();
        var rule = line.getBoundingClientRect();
        if (!box.width || !box.height || !rule.width) { return null; }

        var section = signatory.closest('section');
        var above   = section ? section.previousElementSibling : null;
        var room    = above ? rule.top - above.getBoundingClientRect().bottom : 0;
        var height  = Math.min(Math.max(room, rule.top - box.top), rule.width * MAX_HEIGHT);

        return {
            image:  image,
            left:   ((rule.left - box.left) / box.width * 100) + '%',
            width:  (rule.width / box.width * 100) + '%',
            height: (height / box.height * 100) + '%',
            bottom: ((box.bottom - rule.top - height * INK_DROP) / box.height * 100) + '%'
        };
    }

    function place(m) {
        if (!m) { return; }
        m.image.style.left   = m.left;
        m.image.style.width  = m.width;
        m.image.style.height = m.height;
        m.image.style.bottom = m.bottom;
    }

    function fitSome(list) {
        list.map(measure).forEach(place);
    }

    function fitAll() {
        fitSome(signatories());
    }

    /* A ResizeObserver hands over only the boxes that changed; refit those alone. */
    function refitObserved(entries) {
        var seen = [];
        for (var i = 0; i < entries.length; i++) {
            if (seen.indexOf(entries[i].target) < 0) { seen.push(entries[i].target); }
        }
        fitSome(seen);
    }

    function clear() {
        if (observer) { observer.disconnect(); observer = null; }
        signatories().forEach(function (signatory) {
            var image = signatory.querySelector('.ScUploadedSig');
            if (image) { image.remove(); }
            signatory.removeAttribute('data-sc-signed');
        });
        if (url) { URL.revokeObjectURL(url); url = null; }
        kept = null;
        input.value = '';
        remove.hidden = true;
        button.textContent = 'Upload Signature';
    }

    function apply(blob, transparent, canvas) {
        clear();
        hideRunNote();
        kept = canvas ? { canvas: canvas, transparent: transparent } : null;
        url = URL.createObjectURL(blob);
        var signed = signatories();
        signed.forEach(function (signatory) {
            var image = document.createElement('img');
            image.className = 'ScUploadedSig';
            image.alt = 'Signature';
            image.src = url;
            signatory.insertBefore(image, signatory.querySelector('[data-scroll-text="givenBy"]'));
            signatory.setAttribute('data-sc-signed', transparent ? 'clear' : 'opaque');
        });
        fitAll();
        // A later refit of the page (a hand or heading change) moves the line.
        if (window.ResizeObserver) {
            observer = new ResizeObserver(refitObserved);
            signed.forEach(function (signatory) { observer.observe(signatory); });
        }
        remove.hidden = false;
        button.textContent = 'Replace Signature';

        var first = signed[0] && signed[0].querySelector('.ScUploadedSig');
        var decoded = first && first.decode ? first.decode() : Promise.resolve();
        return decoded.then(function () {
            say(transparent
                ? 'Signature added to ' + signed.length + (signed.length === 1 ? ' scroll.' : ' scrolls.')
                : 'This image has no transparent background, so it may show a box on the parchment. A transparent PNG will look better.',
                !transparent);
        });
    }

    function load(file) {
        if (file.type !== 'image/png') {
            say('That file is not a PNG. Please choose a PNG with a transparent background.', true);
            input.value = '';
            return;
        }
        if (file.size > MAX_BYTES) {
            say('That image is over 10 MB. Please choose a smaller PNG.', true);
            input.value = '';
            return;
        }

        var source = URL.createObjectURL(file);
        var image  = new Image();
        image.onload = function () {
            var result = crop(image);
            URL.revokeObjectURL(source);
            if (!result) {
                say('That image is completely blank. Please choose another.', true);
                input.value = '';
                return;
            }
            result.canvas.toBlob(function (blob) {
                apply(blob, result.transparent, result.canvas);
                storeRun(result.canvas, result.transparent);
            }, 'image/png');
        };
        image.onerror = function () {
            URL.revokeObjectURL(source);
            say('That file could not be read as an image. Please choose another PNG.', true);
            input.value = '';
        };
        image.src = source;
    }

    button.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () {
        if (input.files && input.files[0]) { load(input.files[0]); }
    });
    remove.addEventListener('click', function () {
        clear();
        dropRun();
        hideRunNote();
        say('', false);
    });
    window.addEventListener('beforeprint', fitAll);
    window.addEventListener('pagehide', clear);

    /* For the Heraldry reload in Scrollery_render.tpl. has(): is a signature on the
       page. keepForReload(): stash it for the next load of this run; false when it
       could not be stored (quota, private mode), so the caller can ask first. */
    window.ScrolleryUploadedSig = {
        has: function () { return !!kept; },
        keepForReload: function () {
            if (!kept) { return true; }
            try {
                window.sessionStorage.setItem(KEEP_KEY, JSON.stringify({
                    run: runKey(),
                    data: kept.canvas.toDataURL('image/png'),
                    transparent: kept.transparent
                }));
                return true;
            } catch (e) {
                try { window.sessionStorage.removeItem(KEEP_KEY); } catch (e2) { /* nothing to undo */ }
                // A batched run's own entry brings it back on the reload anyway.
                var entry = readRun();
                return !!(entry && !entry.failed && typeof entry.data === 'string');
            }
        }
    };

    /* Draw a stored crop ({data, transparent}); onFail when it cannot be. */
    function restoreSaved(saved, onFail) {
        var fail = function () { if (onFail) { onFail(); } };
        try {
            if (!saved || typeof saved.data !== 'string' ||
                saved.data.indexOf('data:image/png;base64,') !== 0) { fail(); return; }
            var blob = dataUrlToBlob(saved.data);
            var image = new Image();
            image.onload = function () {
                var canvas = document.createElement('canvas');
                canvas.width = image.naturalWidth;
                canvas.height = image.naturalHeight;
                canvas.getContext('2d').drawImage(image, 0, 0);
                apply(blob, !!saved.transparent, canvas);
            };
            image.onerror = fail;
            image.src = saved.data;
        } catch (e) { fail(); }
    }

    /* Bring a carried signature back, after the autofit pass so the first fit
       measures the finished layout. This tab's own reload carry first (it is the
       signature on screen a moment ago), else the run's. */
    sweepRuns();
    var carried = null;
    try {
        carried = window.sessionStorage.getItem(KEEP_KEY);
        if (carried !== null) { window.sessionStorage.removeItem(KEEP_KEY); }
    } catch (e) { carried = null; }

    var restore = null;
    if (carried) {
        var saved = null;
        try { saved = JSON.parse(carried); } catch (e) { saved = null; }
        if (saved && saved.run === runKey()) {
            restore = function () { restoreSaved(saved, null); };
        }
    }
    if (!restore && RUN) {
        var entry = readRun();
        if (entry && entry.failed) {
            restore = noteRunLost;
        } else if (entry) {
            restore = function () { restoreSaved(entry, noteRunLost); };
        }
    }
    if (restore) {
        var fitted = window.ScrolleryAutofit && window.ScrolleryAutofit.ready;
        if (fitted && typeof fitted.then === 'function') { fitted.then(restore, restore); } else { restore(); }
    }

    var toolbar = document.getElementById('sc-signature-open');
    if (toolbar) {
        toolbar.addEventListener('click', function () {
            var configure = document.getElementById('sc-configure');
            if (configure) { configure.click(); }
            var option = document.getElementById('sc-sigfile-opt');
            if (option) { option.scrollIntoView({ block: 'center' }); }
            button.focus();
        });
    }
}());
