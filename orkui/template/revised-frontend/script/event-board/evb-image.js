/*
 * EvBoardImage: draws the Event Schedule Board as a PNG to post (Facebook,
 * Discord) and owns the Schedule toolbar's "Share as image" menu.
 *
 * The picture is laid out from the board model (EvBoardCore.buildBoard, kept
 * as EvBoardView.board), never from the DOM, with the Canvas 2D API. User text
 * is drawn as text. wrapLines, fitText, oneLine, dayLaneWidth, columnLayout, dayChoices, pictureDay,
 * midnightMarks and fileName are pure so node tests can load this file; everything that touches the DOM or a canvas runs only when called.
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
    var HALF_LINE = '#f1f5f9';  // half-hour lines, fainter than the hour lines
    var MIDNIGHT_LINE = '#a0aec0';
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

    // text cut to fit maxWidth character by character, ending with an ellipsis
    // when cut; '' when not even one character and the ellipsis fit.
    function fitText(measure, text, maxWidth) {
        var s = String(text === null || text === undefined ? '' : text).replace(/\s+/g, ' ').trim();
        if (!s || measure(s) <= maxWidth) return s;
        var chars = Array.from(s);
        while (chars.length) {
            chars.pop();
            var cut = chars.join('').replace(/\s+$/, '');
            if (cut && measure(cut + ELLIPSIS) <= maxWidth) return cut + ELLIPSIS;
        }
        return '';
    }

    // The one-line block (30 minutes or less): the time always comes first and
    // the title gets what is left, cut with an ellipsis. measureTime and
    // measureTitle measure in the two fonts. Returns {time, title, titleX}.
    var ONE_LINE_GAP = 6;
    function oneLine(measureTime, measureTitle, time, title, maxWidth) {
        var t = fitText(measureTime, time, maxWidth);
        var titleX = t ? measureTime(t) + ONE_LINE_GAP : 0;
        return { time: t, title: fitText(measureTitle, title, maxWidth - titleX), titleX: titleX };
    }

    // Lane width for a one-day picture: the day's board area about DAY_TARGET_W
    // wide, never narrower than the all-days lane nor wider than DAY_LANE_MAX.
    var DAY_TARGET_W = 1080;
    var DAY_LANE_MAX = 360;
    function dayLaneWidth(lanes) {
        var n = Math.max(1, parseInt(lanes, 10) || 1);
        return Math.min(DAY_LANE_MAX, Math.max(LANE_W, Math.round(DAY_TARGET_W / n)));
    }

    // A day column (a buildBoard day) laid out at laneW per lane, its lanes
    // widened by grow (default 1): rails (day.rails, ongoing items) RAIL_W wide
    // at its left with RAIL_GAP after each, the lanes in the width after them.
    // -> { width, railW, lanesW, rails: [{seg, x, w}], blocks: [{seg, x, w}] },
    // x from the column's left edge, with the board's 1px inset at the sides.
    var RAIL_W = 22;
    var RAIL_GAP = 2;
    function columnLayout(d, laneW, grow) {
        var rails = d.rails || [];
        var railW = rails.length * (RAIL_W + RAIL_GAP);
        var lanesW = Math.max(d.lanes || 1, 1) * laneW * (grow || 1);
        return {
            width: railW + lanesW,
            railW: railW,
            lanesW: lanesW,
            rails: rails.map(function (sg, i) {
                var at = typeof sg.rail === 'number' ? sg.rail : i;
                return { seg: sg, x: 1 + at * (RAIL_W + RAIL_GAP), w: RAIL_W };
            }),
            blocks: (d.segs || []).map(function (sg) {
                return { seg: sg, x: railW + (+sg.left || 0) / 100 * lanesW + 1, w: (+sg.width || 100) / 100 * lanesW - 2 };
            })
        };
    }

    // The "Which days" options for the board's days ('YYYY-MM-DD'): All days
    // then one per day, named the way the board's day pills are (the long
    // label past 7 days, so repeated weekdays differ). [] for 0 or 1 day.
    function dayChoices(days) {
        var list = days || [];
        if (list.length < 2) return [];
        return [{ day: null, label: 'All days' }].concat(list.map(function (day) {
            return { day: day, label: list.length > 7 ? C.dayLabelLong(day) : C.dayLabel(day) };
        }));
    }

    // The day to draw: a one-day board is always drawn as its day (wider
    // lanes); otherwise the chosen day while the board still has it, else
    // null (all days).
    function pictureDay(days, chosen) {
        var list = days || [];
        if (list.length === 1) return list[0];
        return chosen && list.indexOf(chosen) !== -1 ? chosen : null;
    }

    // The day columns that reach midnight (board minute 1440 or later) and the
    // calendar day that begins there: [{index, day}], e.g. day 'Sunday'.
    var MIDNIGHT = 1440;
    function midnightMarks(days) {
        var out = [];
        (days || []).forEach(function (d, i) {
            var late = (d.segs || []).concat(d.rails || []).some(function (s) {
                return s.endMin > MIDNIGHT || s.startMin >= MIDNIGHT;
            });
            var info = late ? C.lateNight(d.day, MIDNIGHT) : null;
            if (info) out.push({ index: i, day: info.day });
        });
        return out;
    }

    // Browsers refuse canvases past these limits (toBlob then gives null).
    var MAX_SIDE = 16384;
    var MAX_AREA = 100000000;

    // The largest scale up to wantedScale at which a width x height layout fits
    // the canvas limits once rounded up to whole device pixels. It drops below 1
    // when even scale 1 is too large.
    function fitScale(width, height, wantedScale) {
        var w = Math.max(1, +width || 0), h = Math.max(1, +height || 0);
        var s = Math.min(+wantedScale > 0 ? +wantedScale : 1, MAX_SIDE / w, MAX_SIDE / h, Math.sqrt(MAX_AREA / (w * h)));
        s = Math.floor(s * 10000) / 10000;
        while (s > 0.0001 && (Math.ceil(w * s) > MAX_SIDE || Math.ceil(h * s) > MAX_SIDE ||
            Math.ceil(w * s) * Math.ceil(h * s) > MAX_AREA)) {
            s -= 0.0001;
        }
        return s;
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
            // Too short for two lines: the time, then the title shortened to
            // the room left, on one line, centred.
            var base = y + h / 2 + 4.5;
            var line = oneLine(function (s) { ctx.font = font(11, false); return measure(s); },
                function (s) { ctx.font = font(13, true); return measure(s); }, time, title, innerW);
            ctx.font = font(11, false);
            ctx.fillText(line.time, tx, base);
            if (line.title) {
                ctx.font = font(13, true);
                ctx.fillText(line.title, tx + line.titleX, base);
            }
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

    // One rail (an ongoing item): the fill, a secondary-category stripe, and the
    // title running down it (turned a quarter clockwise, as the board's
    // vertical text), cut with an ellipsis to the rail's length.
    function drawRail(ctx, font, seg, x, y, w, h, byName) {
        var item = seg.item || {};
        var cat = catName(item.Category, byName);
        var sec = item.SecondaryCategory && item.SecondaryCategory !== cat &&
            Object.prototype.hasOwnProperty.call(byName, item.SecondaryCategory) ? item.SecondaryCategory : '';
        ctx.save();
        roundRect(ctx, x, y, w, h, 4, !seg.isFirst, !seg.isLast);
        ctx.fillStyle = boldOf(cat, byName);
        ctx.fill();
        ctx.clip();
        if (sec) {
            ctx.fillStyle = boldOf(sec, byName);
            ctx.fillRect(x + w - 3, y, 3, h);
        }
        var pad = 6;
        ctx.font = font(12, true);
        var text = fitText(function (s) { return ctx.measureText(s).width; }, item.Title || '', h - pad * 2);
        if (text) {
            ctx.translate(x + (sec ? (w - 3) / 2 : w / 2), y + pad);
            ctx.rotate(Math.PI / 2);
            ctx.fillStyle = '#fff';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, 0, 0);
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
        // One day drawn alone gets wider lanes, so it reads on a phone.
        var laneW = (opts.day && days.length === 1 && days[0].day === opts.day) ? dayLaneWidth(days[0].lanes) : LANE_W;

        // The time axis: the board's own, or tightened to the one day drawn; it
        // always reaches the bottom of the lowest block.
        var axisStart = board ? board.axisStart : 540, axisEnd = board ? board.axisEnd : 1080;
        var segs = [];
        days.forEach(function (d) { segs = segs.concat(d.segs, d.rails || []); });
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

        // Widths: lanes and rails set each day's width; a long title or address
        // may widen a narrow board, and the extra is shared out between the
        // days' lanes (rails keep their width).
        var cols = days.map(function (d) { return columnLayout(d, laneW); });
        var sum = function (list, key) { return list.reduce(function (a, c) { return a + c[key]; }, 0); };
        var gridW = GUTTER + sum(cols, 'width');
        ctx.font = font(24, true);
        var want = measure(meta.title || '');
        ctx.font = font(11, false);
        want = Math.max(want, measure(meta.url || ''));
        var contentW = Math.max(gridW, Math.min(Math.ceil(want), MAX_HEAD_W));
        if (contentW > gridW) {
            var railsW = sum(cols, 'railW');
            var grow = (contentW - GUTTER - railsW) / (gridW - GUTTER - railsW);
            cols = days.map(function (d) { return columnLayout(d, laneW, grow); });
        }
        var dayWs = cols.map(function (c) { return c.width; });

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

        // A very wide board draws at a lower scale rather than not at all.
        scale = fitScale(width, height, scale);
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
        ctx.fillStyle = HALF_LINE;
        for (var m = axisStart + 30; m < axisEnd; m += 60) {
            ctx.fillRect(left + GUTTER, Math.round(bodyTop + (m - axisStart) * PX_PER_MIN), contentW - GUTTER, 1);
        }
        ctx.fillStyle = HOUR_LINE;
        for (m = axisStart; m <= axisEnd; m += 60) {
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

        // Midnight: a dashed line under the blocks in each column that runs
        // past it; its label (the calendar day that begins there) goes on top.
        var marks = (MIDNIGHT >= axisStart && MIDNIGHT <= axisEnd) ? midnightMarks(days) : [];
        var midY = Math.round(bodyTop + (MIDNIGHT - axisStart) * PX_PER_MIN) + 0.5;
        if (marks.length) {
            ctx.save();
            ctx.strokeStyle = MIDNIGHT_LINE;
            ctx.lineWidth = 1;
            ctx.setLineDash([5, 4]);
            marks.forEach(function (mk) {
                ctx.beginPath();
                ctx.moveTo(dayXs[mk.index] + 1, midY);
                ctx.lineTo(dayXs[mk.index] + dayWs[mk.index] - 1, midY);
                ctx.stroke();
            });
            ctx.restore();
        }

        // Rails and blocks, with the board's 1px inset top and bottom and at the sides.
        cols.forEach(function (col, i) {
            var place = function (draw) {
                return function (b) {
                    var sg = b.seg;
                    var by = bodyTop + (sg.startMin - axisStart) * PX_PER_MIN + 1;
                    var bh = Math.max(sg.endMin - sg.startMin, MIN_BLOCK_MIN) * PX_PER_MIN - 2;
                    draw(ctx, font, sg, dayXs[i] + b.x, by, b.w, bh, byName);
                };
            };
            col.rails.forEach(place(drawRail));
            col.blocks.forEach(place(drawBlock));
        });

        // Midnight labels: a small tag at the column's right edge, just above
        // the line (just below it when the line is the top of the grid).
        ctx.font = font(10, true);
        marks.forEach(function (mk) {
            var text = fitText(measure, mk.day, dayWs[mk.index] - 12);
            if (!text) return;
            var tw = Math.ceil(measure(text)) + 8, th = 14;
            var tx = dayXs[mk.index] + dayWs[mk.index] - 3 - tw;
            var ty = midY - th - 1 < bodyTop ? midY + 1 : midY - th - 1;
            ctx.fillStyle = HEAD_BG;
            roundRect(ctx, tx, ty, tw, th, 3);
            ctx.fill();
            ctx.strokeStyle = MIDNIGHT_LINE;
            ctx.lineWidth = 1;
            ctx.stroke();
            ctx.fillStyle = GREY;
            ctx.fillText(text, tx + 4, ty + 10.5);
        });

        // Footer: the event's address on the ORK
        if (urlLine) {
            ctx.fillStyle = MUTED;
            ctx.font = font(11, false);
            ctx.fillText(urlLine, PAD, footTop + 11);
        }
        return canvas;
    }

    // Save the image as a file; onFail runs when the browser cannot encode it.
    function download(canvas, name, onFail) {
        canvas.toBlob(function (blob) {
            if (!blob) {
                if (typeof onFail === 'function') onFail();
                return;
            }
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

    // Copy the image to the clipboard. Resolves true when copied, 'too-large'
    // when the browser could not encode the image, and false when it cannot copy.
    function copy(canvas) {
        return new Promise(function (resolve) {
            var nav = win.navigator;
            if (!nav || !nav.clipboard || typeof nav.clipboard.write !== 'function' || typeof win.ClipboardItem !== 'function') {
                resolve(false);
                return;
            }
            try {
                var noImage = false;
                // A promised blob keeps the click's user activation in Safari.
                var blob = new Promise(function (res, rej) {
                    canvas.toBlob(function (b) {
                        if (b) { res(b); return; }
                        noImage = true;
                        rej(new Error('no image'));
                    }, 'image/png');
                });
                nav.clipboard.write([new win.ClipboardItem({ 'image/png': blob })])
                    .then(function () { resolve(true); }, function () { resolve(noImage ? 'too-large' : false); });
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

    // The board the picture shows: the view's real items only (no preview ghosts,
    // no editing placeholder) on the view-mode axis, whatever mode the page is in,
    // so an unsaved item never reaches a posted image. conf: {eventStart, eventEnd}.
    function modelFor(view, conf) {
        var c = conf || cfg();
        return C.buildBoard(view.getItems() || [], { eventStart: c.eventStart || '', eventEnd: c.eventEnd || '' });
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
        var wrap = btn.closest('.evb-share') || btn.parentNode;
        var label = btn.innerHTML;
        var resetTimer = null;

        // Show a short result on the button for two seconds.
        function flash(html) {
            win.clearTimeout(resetTimer);
            btn.innerHTML = html;
            resetTimer = win.setTimeout(function () { btn.innerHTML = label; }, 2000);
        }
        function tooLarge() { flash('<i class="fas fa-exclamation-circle"></i> Image too large'); }

        // "Which days": All days or one board day; Download PNG and Copy image
        // both draw the choice. The old "Download this day" entry is replaced.
        var chosen = null;
        var oldDay = menu.querySelector('[data-evb-share="download-day"]');
        if (oldDay) oldDay.parentNode.removeChild(oldDay);
        var group = doc.createElement('div');
        group.className = 'evb-share-days';
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-label', 'Which days');
        group.hidden = true;
        menu.insertBefore(group, menu.firstChild);

        function radios() { return Array.prototype.slice.call(group.querySelectorAll('[role="radio"]')); }

        function choose(value) {
            chosen = value || null;
            radios().forEach(function (r) {
                var on = (r.getAttribute('data-evb-day') || null) === chosen;
                r.setAttribute('aria-checked', on ? 'true' : 'false');
                r.tabIndex = on ? 0 : -1;
                var icon = r.querySelector('i');
                if (icon) icon.className = on ? 'fas fa-dot-circle' : 'far fa-circle';
            });
        }

        // Rebuilt on each open from the saved items' days; starts on the board's
        // selected day pill, else All days.
        function buildChoice() {
            var days = (modelFor(view).days || []).map(function (d) { return d.day; });
            var options = dayChoices(days);
            group.innerHTML = '';
            group.hidden = !options.length;
            if (group.hidden) { chosen = null; return; }
            options.forEach(function (o) {
                var r = doc.createElement('button');
                r.type = 'button';
                r.setAttribute('role', 'radio');
                if (o.day) r.setAttribute('data-evb-day', o.day);
                r.innerHTML = '<i class="far fa-circle" aria-hidden="true"></i> ';
                r.appendChild(doc.createTextNode(o.label));
                group.appendChild(r);
            });
            var shown = view.getDay();
            choose(shown && days.indexOf(shown) !== -1 ? shown : null);
        }

        group.addEventListener('click', function (e) {
            var r = e.target.closest && e.target.closest('[role="radio"]');
            if (!r) return;
            choose(r.getAttribute('data-evb-day'));
            r.focus();
        });
        // Arrow keys move the choice within the group (one tab stop).
        group.addEventListener('keydown', function (e) {
            var list = radios();
            var i = list.indexOf(doc.activeElement);
            if (i === -1) return;
            var n = list.length, to = -1;
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') to = (i + 1) % n;
            else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') to = (i - 1 + n) % n;
            else if (e.key === 'Home') to = 0;
            else if (e.key === 'End') to = n - 1;
            if (to === -1) return;
            e.preventDefault();
            choose(list[to].getAttribute('data-evb-day'));
            list[to].focus();
        });

        function setOpen(open) {
            if (open) {
                var copyItem = menu.querySelector('[data-evb-share="copy"]');
                if (copyItem) copyItem.hidden = typeof win.ClipboardItem !== 'function';
                buildChoice();
            }
            menu.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function act(kind) {
            if (!view.board) return;
            var model = modelFor(view);
            var days = (model.days || []).map(function (d) { return d.day; });
            // A day the board no longer has (a refresh while the menu was open)
            // counts as All days. A one-day event is drawn as its day but still
            // titled and named as the whole event.
            var drawn = pictureDay(days, chosen);
            var day = days.length > 1 ? drawn : null;
            var meta = metaFor(day);
            var canvas;
            try {
                canvas = render(model, meta, { scale: 2, day: drawn });
            } catch (e) {
                if (win.console) win.console.error(e);
                return;
            }
            if (kind === 'copy') {
                copy(canvas).then(function (result) {
                    if (result === 'too-large') { tooLarge(); return; }
                    flash(result ? '<i class="fas fa-check"></i> Copied'
                        : '<i class="fas fa-exclamation-circle"></i> Copy failed');
                });
                return;
            }
            download(canvas, fileName(day ? meta.title + ' ' + day : meta.title), tooLarge);
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
        // A disclosure: it closes when focus moves on past both the button and
        // the panel. (A null relatedTarget is a click, which the handler above
        // deals with; Safari does not focus clicked buttons.)
        wrap.addEventListener('focusout', function (e) {
            var to = e.relatedTarget;
            if (menu.hidden || !to || wrap.contains(to)) return;
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
        fitText: fitText,
        oneLine: oneLine,
        dayLaneWidth: dayLaneWidth,
        columnLayout: columnLayout,
        dayChoices: dayChoices,
        pictureDay: pictureDay,
        midnightMarks: midnightMarks,
        fileName: fileName,
        fitScale: fitScale,
        modelFor: modelFor,
        render: render,
        download: download,
        copy: copy,
        init: init
    };
});
