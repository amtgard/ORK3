/*
 * EXPERIMENT (scrollery-heraldry): clear the white surround from arms drawn IN FRONT
 * of other arms.
 *
 * Heraldry files are opaque JPEGs, most on a white field, and a device that only ever
 * multiplies onto parchment never shows it. In the overlap layout the park's arms sit
 * over the kingdom's inside an isolated group, so that surround would cut a white notch
 * out of the kingdom wherever the park's box is not the park's shield.
 *
 * Only the white CONNECTED TO THE IMAGE EDGE is cleared (a flood fill from the border),
 * so an argent field or a white charge inside the arms stays opaque. Same-origin images
 * only; a canvas tainted by anything else simply leaves the image as it was.
 *
 * ScrolleryArms.knockout(root) treats every img[data-sc-knockout] under root once.
 */
(function () {
    'use strict';

    var TOLERANCE = 236; // every channel at or above this counts as white
    var MAX_SIDE = 1200; // work at most this many px on a side

    function clear(img) {
        var w = img.naturalWidth, h = img.naturalHeight;
        if (!w || !h) { return; }
        var k = Math.min(1, MAX_SIDE / Math.max(w, h));
        w = Math.round(w * k);
        h = Math.round(h * k);

        var canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        var g = canvas.getContext('2d');
        g.drawImage(img, 0, 0, w, h);

        var data;
        try { data = g.getImageData(0, 0, w, h); } catch (e) { return; }
        var px = data.data, seen = new Uint8Array(w * h), stack = [];

        function white(i) {
            var o = i * 4;
            return px[o + 3] < 16 || (px[o] >= TOLERANCE && px[o + 1] >= TOLERANCE && px[o + 2] >= TOLERANCE);
        }
        function push(i) {
            if (!seen[i] && white(i)) { seen[i] = 1; stack.push(i); }
        }

        var x, y;
        for (x = 0; x < w; x++) { push(x); push((h - 1) * w + x); }
        for (y = 0; y < h; y++) { push(y * w); push(y * w + w - 1); }
        while (stack.length) {
            var i = stack.pop();
            px[i * 4 + 3] = 0;
            x = i % w;
            y = (i - x) / w;
            if (x > 0) { push(i - 1); }
            if (x < w - 1) { push(i + 1); }
            if (y > 0) { push(i - w); }
            if (y < h - 1) { push(i + w); }
        }

        g.putImageData(data, 0, 0);
        img.src = canvas.toDataURL('image/png');
    }

    function treat(img) {
        if (img.dataset.scKnockout === 'done') { return; }
        img.dataset.scKnockout = 'done';
        if (img.complete && img.naturalWidth) { clear(img); return; }
        img.addEventListener('load', function () { clear(img); }, { once: true });
    }

    window.ScrolleryArms = {
        knockout: function (root) {
            (root || document).querySelectorAll('img[data-sc-knockout]').forEach(treat);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { window.ScrolleryArms.knockout(); });
    } else {
        window.ScrolleryArms.knockout();
    }
}());
