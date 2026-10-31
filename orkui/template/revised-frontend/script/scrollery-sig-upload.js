/*
 * Upload Signature: one person's signature image on the signature line of every
 * certificate on this print page.
 *
 * The file never leaves the browser. It is read here, cropped on a canvas and shown
 * through an object URL, so nothing reaches the server and nothing outlives the page:
 * a reload, including the reload an arms change makes, starts without it.
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

    /* The box above the writing line, up to the text over it. object-fit does the rest. */
    function fit(signatory) {
        var image = signatory.querySelector('.ScUploadedSig');
        var line  = signatory.querySelector('.Underline');
        if (!image || !line) { return; }

        var box  = signatory.getBoundingClientRect();
        var rule = line.getBoundingClientRect();
        if (!box.width || !box.height || !rule.width) { return; }

        var section = signatory.closest('section');
        var above   = section ? section.previousElementSibling : null;
        var room    = above ? rule.top - above.getBoundingClientRect().bottom : 0;
        var height  = Math.min(Math.max(room, rule.top - box.top), rule.width * MAX_HEIGHT);

        image.style.left   = ((rule.left - box.left) / box.width * 100) + '%';
        image.style.width  = (rule.width / box.width * 100) + '%';
        image.style.height = (height / box.height * 100) + '%';
        image.style.bottom = ((box.bottom - rule.top - height * INK_DROP) / box.height * 100) + '%';
    }

    function fitAll() {
        signatories().forEach(fit);
    }

    function clear() {
        if (observer) { observer.disconnect(); observer = null; }
        signatories().forEach(function (signatory) {
            var image = signatory.querySelector('.ScUploadedSig');
            if (image) { image.remove(); }
            signatory.removeAttribute('data-sc-signed');
        });
        if (url) { URL.revokeObjectURL(url); url = null; }
        input.value = '';
        remove.hidden = true;
        button.textContent = 'Upload Signature';
    }

    function apply(blob, transparent) {
        clear();
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
            observer = new ResizeObserver(fitAll);
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
            result.canvas.toBlob(function (blob) { apply(blob, result.transparent); }, 'image/png');
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
        say('', false);
    });
    window.addEventListener('beforeprint', fitAll);
    window.addEventListener('pagehide', clear);

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
