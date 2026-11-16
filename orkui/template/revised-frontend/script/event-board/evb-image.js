/*
 * EvBoardImage: draws the Event Schedule Board as a PNG to post (Facebook,
 * Discord) and owns the Schedule toolbar's "Share as image" menu.
 *
 * The picture is laid out from the board model (EvBoardCore.buildBoard, kept
 * as EvBoardView.board), never from the DOM, with the Canvas 2D API. User text
 * is drawn as text. wrapLines and fileName are pure so node tests can load this
 * file; everything that touches the DOM or a canvas runs only when called.
 */
(function (root, factory) {
    var isNode = typeof module === 'object' && module.exports;
    var core = isNode ? require('./evb-core.js') : root.EvBoardCore;
    var api = factory(core, root);
    if (isNode) module.exports = api;
    else root.EvBoardImage = api;
})(this, function (C, win) {
    'use strict';

    // Layout in CSS pixels; render() multiplies everything by opts.scale.
    var PX_PER_MIN = 1.2;
    var MIN_BLOCK_MIN = 15;
    var LANE_W = 190;
    var GUTTER = 56;
    var PAD = 24;
    var DAY_HEAD = 34;
    var MAX_HEAD_W = 640;   // the header may widen a narrow board up to this
    var ELLIPSIS = '…';

    var INK = '#1a202c';
    var GREY = '#4a5568';
    var MUTED = '#718096';
    var HOUR_LINE = '#e2e8f0';
    var DAY_LINE = '#cbd5e0';
    var HEAD_BG = '#f7fafc';

    // Break text into at most maxLines lines no wider than maxWidth, on word
    // boundaries; a word wider than maxWidth is broken. When text remains, the
    // last line ends with an ellipsis. measure(str) returns a width.
    function wrapLines(measure, text, maxWidth, maxLines) {
        var words = String(text === null || text === undefined ? '' : text).replace(/\s+/g, ' ').trim();
        if (!words || maxLines < 1) return [];
        var lines = [], cur = '';
        words.split(' ').forEach(function (word) {
            var next = cur ? cur + ' ' + word : word;
            if (measure(next) <= maxWidth) { cur = next; return; }
            if (cur) lines.push(cur);
            var chars = Array.from(word);
            while (chars.length && measure(chars.join('')) > maxWidth) {
                var n = 1;
                while (n < chars.length && measure(chars.slice(0, n + 1).join('')) <= maxWidth) n++;
                lines.push(chars.slice(0, n).join(''));
                chars = chars.slice(n);
            }
            cur = chars.join('');
        });
        if (cur) lines.push(cur);
        if (lines.length <= maxLines) return lines;
        var out = lines.slice(0, maxLines);
        var last = Array.from(out[maxLines - 1]);
        while (last.length && measure(last.join('').replace(/\s+$/, '') + ELLIPSIS) > maxWidth) last.pop();
        out[maxLines - 1] = last.join('').replace(/\s+$/, '') + ELLIPSIS;
        return out;
    }

    function fileName(title) {
        var slug = String(title || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        return slug ? slug + '-schedule.png' : 'schedule.png';
    }

    function catName(name, byName) {
        return (name && Object.prototype.hasOwnProperty.call(byName, name)) ? name : 'Other';
    }

    function boldOf(name, byName) {
        var def = byName[name] || byName.Other;
        return (def && def.bold) || '#616161';
    }

    function roundRect(ctx, x, y, w, h, r, squareTop, squareBottom) {
        var rt = squareTop ? 0 : Math.min(r, w / 2, h / 2);
        var rb = squareBottom ? 0 : Math.min(r, w / 2, h / 2);
        ctx.beginPath();
        ctx.moveTo(x + rt, y);
        ctx.lineTo(x + w - rt, y);
        ctx.arcTo(x + w, y, x + w, y + rt, rt);
        ctx.lineTo(x + w, y + h - rb);
        ctx.arcTo(x + w, y + h, x + w - rb, y + h, rb);
        ctx.lineTo(x + rb, y + h);
        ctx.arcTo(x, y + h, x, y + h - rb, rb);
        ctx.lineTo(x, y + rt);
        ctx.arcTo(x, y, x + rt, y, rt);
        ctx.closePath();
    }

    // One block: the fill, a secondary-category stripe, then title, time and
    // (when there is room) location, clipped to the block.
    function drawBlock(ctx, font, seg, x, y, w, h, byName) {
        var item = seg.item || {};
        var cat = catName(item.Category, byName);
        var sec = item.SecondaryCategory && item.SecondaryCategory !== cat &&
            Object.prototype.hasOwnProperty.call(byName, item.SecondaryCategory) ? item.SecondaryCategory : '';
        ctx.save();
        roundRect(ctx, x, y, w, h, 5, !seg.isFirst, !seg.isLast);
        ctx.fillStyle = boldOf(cat, byName);
        ctx.fill();
        ctx.clip();
        if (sec) {
            ctx.fillStyle = boldOf(sec, byName);
            ctx.fillRect(x + w - 4, y, 4, h);
        }

        var padX = 6, padTop = 3;
        var innerW = Math.max(1, w - padX - (sec ? 10 : padX));
        var avail = h - padTop * 2;
        var time = (seg.isFirst ? '' : '→ ') + C.fmtRange(seg.startMin, seg.endMin) + (seg.isLast ? '' : ' →');
        var title = item.Title || '';
        var tx = x + padX;
        var measure = function (s) { return ctx.measureText(s).width; };
        ctx.fillStyle = '#fff';
        ctx.textBaseline = 'alphabetic';

        if (avail < 30) {
            // Too short for two lines: title then time on one line, centred.
            var base = y + h / 2 + 4.5;
            ctx.font = font(13, true);
            var t = wrapLines(measure, title, innerW, 1)[0] || '';
            ctx.fillText(t, tx, base);
            var used = measure(t);
            ctx.font = font(11, false);
            if (used + 8 + measure(time) <= innerW) ctx.fillText(time, tx + used + 8, base);
            ctx.restore();
            return;
        }

        var cy = y + padTop;
        ctx.font = font(13, true);
        var titleLines = wrapLines(measure, title, innerW, Math.max(1, Math.floor((avail - 14) / 16)));
        titleLines.forEach(function (line) {
            ctx.fillText(line, tx, cy + 12);
            cy += 16;
        });
        ctx.font = font(11, false);
        ctx.fillText(wrapLines(measure, time, innerW, 1)[0] || '', tx, cy + 11);
        cy += 14;
        if (item.Location && y + h - padTop - cy >= 14) {
            ctx.fillText(wrapLines(measure, item.Location, innerW, 1)[0] || '', tx, cy + 11);
        }
        ctx.restore();
    }

    // Draw the board (all days, or opts.day only) and return the canvas.
    // meta = {title, subtitle, url, categories}; opts = {scale, day}.
    function render(board, meta, opts) {
        meta = meta || {};
        opts = opts || {};
        var scale = +opts.scale > 0 ? +opts.scale : 2;
        var doc = win.document;
        var family = (win.getComputedStyle && doc.body && win.getComputedStyle(doc.body).fontFamily) || 'sans-serif';
        var font = function (px, bold) { return (bold ? '700 ' : '400 ') + px + 'px ' + family; };

        var cats = Array.isArray(meta.categories) ? meta.categories : [];
        var byName = {};
        cats.forEach(function (c) { byName[c.name] = c; });

        var allDays = (board && board.days) || [];
        var days = opts.day ? allDays.filter(function (d) { return d.day === opts.day; }) : allDays;
        if (!days.length) days = allDays;

        // The time axis: the board's own, or tightened to the one day drawn; it
        // always reaches the bottom of the lowest block.
        var axisStart = board ? board.axisStart : 540, axisEnd = board ? board.axisEnd : 1080;
        var segs = [];
        days.forEach(function (d) { segs = segs.concat(d.segs); });
        if (opts.day && segs.length) {
            axisStart = Math.floor(Math.min.apply(null, segs.map(function (s) { return s.startMin; })) / 60) * 60;
            axisEnd = 0;
        }
        segs.forEach(function (s) {
            axisEnd = Math.max(axisEnd, Math.ceil((s.startMin + Math.max(s.endMin - s.startMin, MIN_BLOCK_MIN)) / 60) * 60);
        });
        if (axisEnd <= axisStart) axisEnd = axisStart + 60;

        var present = {};
        segs.forEach(function (s) {
            var it = s.item || {};
            present[catName(it.Category, byName)] = true;
            if (it.SecondaryCategory && byName[it.SecondaryCategory]) present[it.SecondaryCategory] = true;
        });
        var legend = cats.filter(function (c) { return present[c.name]; });

        var canvas = doc.createElement('canvas');
        var ctx = canvas.getContext('2d');
        var measure = function (s) { return ctx.measureText(s).width; };

        // Widths: lanes set each day's width; a long title or address may widen
        // a narrow board, and the extra is shared out between the days.
        var dayWs = days.map(function (d) { return Math.max(d.lanes, 1) * LANE_W; });
        var gridW = GUTTER + dayWs.reduce(function (a, b) { return a + b; }, 0);
        ctx.font = font(24, true);
        var want = measure(meta.title || '');
        ctx.font = font(11, false);
        want = Math.max(want, measure(meta.url || ''));
        var contentW = Math.max(gridW, Math.min(Math.ceil(want), MAX_HEAD_W));
        if (contentW > gridW) {
            var grow = (contentW - GUTTER) / (gridW - GUTTER);
            dayWs = dayWs.map(function (w) { return w * grow; });
        }

        // Header and legend lines, measured before the canvas is sized.
        ctx.font = font(24, true);
        var titleLines = wrapLines(measure, meta.title || '', contentW, 2);
        ctx.font = font(14, false);
        var subLines = wrapLines(measure, meta.subtitle || '', contentW, 2);
        ctx.font = font(13, false);
        var legendRows = [], row = [], rowW = 0;
        legend.forEach(function (c) {
            var label = wrapLines(measure, c.name, contentW - 18, 1)[0] || '';
            var w = 12 + 6 + measure(label);
            if (row.length && rowW + 18 + w > contentW) { legendRows.push(row); row = []; rowW = 0; }
            row.push({ cat: c, label: label, x: row.length ? rowW + 18 : 0 });
            rowW = (row.length > 1 ? rowW + 18 : 0) + w;
        });
        if (row.length) legendRows.push(row);
        ctx.font = font(11, false);
        var urlLine = wrapLines(measure, meta.url || '', contentW, 1)[0] || '';

        var y = PAD;
        var titleTop = y;
        y += titleLines.length * 30;
        var subTop = y + 2;
        if (subLines.length) y += 2 + subLines.length * 20;
        var legendTop = y + 16;
        if (legendRows.length) y = legendTop + legendRows.length * 22;
        var gridTop = y + 16;
        var bodyTop = gridTop + DAY_HEAD;
        var bodyH = (axisEnd - axisStart) * PX_PER_MIN;
        var footTop = bodyTop + bodyH + 14;
        var height = footTop + (urlLine ? 16 : 0) + PAD;
        var width = contentW + PAD * 2;

        canvas.width = Math.ceil(width * scale);
        canvas.height = Math.ceil(height * scale);
        ctx = canvas.getContext('2d');
        ctx.scale(scale, scale);
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, width, height);
        ctx.textBaseline = 'alphabetic';

        // Header
        ctx.fillStyle = INK;
        ctx.font = font(24, true);
        titleLines.forEach(function (line, i) { ctx.fillText(line, PAD, titleTop + 24 + i * 30); });
        ctx.fillStyle = GREY;
        ctx.font = font(14, false);
        subLines.forEach(function (line, i) { ctx.fillText(line, PAD, subTop + 15 + i * 20); });

        // Legend
        ctx.font = font(13, false);
        legendRows.forEach(function (r, ri) {
            var ry = legendTop + ri * 22;
            r.forEach(function (it) {
                ctx.fillStyle = it.cat.bold || '#616161';
                roundRect(ctx, PAD + it.x, ry + 4, 12, 12, 3);
                ctx.fill();
                ctx.fillStyle = INK;
                ctx.fillText(it.label, PAD + it.x + 18, ry + 15);
            });
        });

        // Grid: day heads, hour lines and labels, day dividers
        var left = PAD, gridRight = PAD + contentW, gridBottom = bodyTop + bodyH;
        ctx.fillStyle = HEAD_BG;
        ctx.fillRect(left + GUTTER, gridTop, contentW - GUTTER, DAY_HEAD);
        ctx.fillStyle = HOUR_LINE;
        for (var m = axisStart; m <= axisEnd; m += 60) {
            ctx.fillRect(left + GUTTER, Math.round(bodyTop + (m - axisStart) * PX_PER_MIN), contentW - GUTTER, 1);
        }
        ctx.font = font(11, true);
        ctx.fillStyle = MUTED;
        ctx.textAlign = 'right';
        for (m = axisStart; m < axisEnd; m += 60) {
            ctx.fillText(C.fmtTime(m), left + GUTTER - 8, bodyTop + (m - axisStart) * PX_PER_MIN + 12);
        }
        ctx.textAlign = 'left';

        var dayXs = [], dx = left + GUTTER;
        dayWs.forEach(function (w) { dayXs.push(dx); dx += w; });
        ctx.fillStyle = DAY_LINE;
        dayXs.forEach(function (x) { ctx.fillRect(Math.round(x), gridTop, 1, gridBottom - gridTop); });
        ctx.fillRect(Math.round(gridRight) - 1, gridTop, 1, gridBottom - gridTop);
        ctx.fillRect(left + GUTTER, gridTop + DAY_HEAD - 1, contentW - GUTTER, 1);
        ctx.fillRect(left + GUTTER, gridBottom, contentW - GUTTER, 1);

        ctx.font = font(13, true);
        days.forEach(function (d, i) {
            ctx.fillStyle = INK;
            var label = wrapLines(measure, C.dayLabelLong(d.day), dayWs[i] - 16, 1)[0] || '';
            ctx.fillText(label, dayXs[i] + 8, gridTop + 22);
        });

        // Blocks, with the board's 1px inset top and bottom and at the sides.
        days.forEach(function (d, i) {
            d.segs.forEach(function (sg) {
                var bx = dayXs[i] + (+sg.left || 0) / 100 * dayWs[i] + 1;
                var bw = (+sg.width || 100) / 100 * dayWs[i] - 2;
                var by = bodyTop + (sg.startMin - axisStart) * PX_PER_MIN + 1;
                var bh = Math.max(sg.endMin - sg.startMin, MIN_BLOCK_MIN) * PX_PER_MIN - 2;
                drawBlock(ctx, font, sg, bx, by, bw, bh, byName);
            });
        });

        // Footer: the event's address on the ORK
        if (urlLine) {
            ctx.fillStyle = MUTED;
            ctx.font = font(11, false);
            ctx.fillText(urlLine, PAD, footTop + 11);
        }
        return canvas;
    }

    function download(canvas, name) {
        canvas.toBlob(function (blob) {
            if (!blob) return;
            var url = win.URL.createObjectURL(blob);
            var a = win.document.createElement('a');
            a.href = url;
            a.download = name || 'schedule.png';
            a.style.display = 'none';
            win.document.body.appendChild(a);
            a.click();
            a.parentNode.removeChild(a);
            win.setTimeout(function () { win.URL.revokeObjectURL(url); }, 10000);
        }, 'image/png');
    }

    // Copy the image to the clipboard; resolves false when the browser cannot.
    function copy(canvas) {
        return new Promise(function (resolve) {
            var nav = win.navigator;
            if (!nav || !nav.clipboard || typeof nav.clipboard.write !== 'function' || typeof win.ClipboardItem !== 'function') {
                resolve(false);
                return;
            }
            try {
                // A promised blob keeps the click's user activation in Safari.
                var blob = new Promise(function (res, rej) {
                    canvas.toBlob(function (b) { if (b) res(b); else rej(new Error('no image')); }, 'image/png');
                });
                nav.clipboard.write([new win.ClipboardItem({ 'image/png': blob })])
                    .then(function () { resolve(true); }, function () { resolve(false); });
            } catch (e) {
                resolve(false);
            }
        });
    }

    // ---- Toolbar menu (browser only) ----

    function cfg() { return (win && win.EvConfig) || {}; }

    // The event's ORK address: this origin plus the event detail route.
    function eventUrl() {
        var c = cfg();
        var path = '/';
        try { path = new win.URL(c.uir || '', win.location.href).pathname; } catch (e) { /* keep '/' */ }
        return win.location.origin + path + '?Route=Event/detail/' + (parseInt(c.eventId, 10) || 0) + '/' + (parseInt(c.detailId, 10) || 0);
    }

    function metaFor(day) {
        var c = cfg();
        var when = day ? C.dayLabelLong(day) : (c.eventDateLabel || '');
        return {
            title: c.eventName || 'Event',
            subtitle: [when, c.eventPlace || ''].filter(Boolean).join(' · '),
            url: eventUrl(),
            categories: Array.isArray(c.scheduleCategories) ? c.scheduleCategories : []
        };
    }

    function init() {
        var doc = win.document;
        var btn = doc.querySelector('.evb-share-btn');
        var menu = doc.querySelector('.evb-share-menu');
        var view = win.EvBoardView;
        if (!btn || !menu || !view) return;
        var label = btn.innerHTML;
        var resetTimer = null;

        function setOpen(open) {
            if (open) {
                var dayItem = menu.querySelector('[data-evb-share="download-day"]');
                var copyItem = menu.querySelector('[data-evb-share="copy"]');
                if (dayItem) dayItem.hidden = !view.getDay();
                if (copyItem) copyItem.hidden = typeof win.ClipboardItem !== 'function';
            }
            menu.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function act(kind) {
            if (!view.board) return;
            var day = kind === 'download-day' ? view.getDay() : null;
            var meta = metaFor(day);
            var canvas;
            try {
                canvas = render(view.board, meta, { scale: 2, day: day });
            } catch (e) {
                if (win.console) win.console.error(e);
                return;
            }
            if (kind === 'copy') {
                copy(canvas).then(function (ok) {
                    win.clearTimeout(resetTimer);
                    btn.innerHTML = ok ? '<i class="fas fa-check"></i> Copied'
                        : '<i class="fas fa-exclamation-circle"></i> Copy failed';
                    resetTimer = win.setTimeout(function () { btn.innerHTML = label; }, 2000);
                });
                return;
            }
            download(canvas, fileName(day ? meta.title + ' ' + day : meta.title));
        }

        btn.addEventListener('click', function () { setOpen(menu.hidden); });
        menu.addEventListener('click', function (e) {
            var item = e.target.closest && e.target.closest('[data-evb-share]');
            if (!item) return;
            setOpen(false);
            act(item.getAttribute('data-evb-share'));
        });
        doc.addEventListener('click', function (e) {
            if (menu.hidden || !e.target.closest || e.target.closest('.evb-share')) return;
            setOpen(false);
        });
        doc.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || menu.hidden) return;
            setOpen(false);
            btn.focus();
        });
    }

    return {
        wrapLines: wrapLines,
        fileName: fileName,
        render: render,
        download: download,
        copy: copy,
        init: init
    };
});
