/*
 * ORKPLE II terminal: a 40x24 Apple II style text page drawn to a canvas.
 * Exposes window.OrkpleTerminal.mount({canvas, input, live, service, ui, onExit, reducedMotion})
 * which returns { start(), destroy() }. The shell owns the DOM, focus and Escape.
 * service and ui are the site's JSON service and UI route prefixes; both are reduced
 * to same-origin paths here. The pure screen builders are exposed as OrkpleTerminal.lib.
 */
(function () {
    'use strict';
    // Where this file was loaded from: games/<id>.js sit beside it, cache-busted with the same ?v=.
    var SELF = document.currentScript && document.currentScript.src ? document.currentScript.src : '';
    var GAME_BASE = SELF.slice(0, SELF.lastIndexOf('/') + 1);
    var GAME_VER = (/[?&]v=([^&]+)/.exec(SELF) || [])[1] || '';
    var GAMES = ['trail', 'zork', 'munchorks', 'pacmork', 'asterorks', 'castlorks', 'froggork', 'invadorks'];

    var COLS = 40, ROWS = 24;      // text page
    var CW = 14, CH = 16;          // cell in backing-store pixels (7x8 font pixels at 2x)
    var PHOSPHOR = '#7CF5C4';
    var CHAR_MS = 5;               // print rate
    var LINE_MS = 180;             // boot screen: pause after each whole line
    var GARBAGE_MS = 800;          // power-on garbage duration
    var BOOT_DISK_MS = 1500;       // the ORKPLE ][ banner and disk sound before DOS comes up
    var BLINK_MS = 400;            // ~2.5Hz
    var LIVE_MAX = 30;
    var FETCH_MS = 12000;          // disk read timeout
    var ART_MS = 8000;             // heraldry image timeout

    /* ---- 5x7 bitmap font, in the look of the Apple II character ROM ----
       Rows are 5 wide (drawn in cell columns 1-5). A 7-wide row spans the whole
       cell so underscores, dashes and equals signs join up in drawn boxes. */
    var G = {
        ' ': '...../...../...../...../...../...../.....',
        'A': '.###./#...#/#...#/#####/#...#/#...#/#...#',
        'B': '####./#...#/#...#/####./#...#/#...#/####.',
        'C': '.###./#...#/#..../#..../#..../#...#/.###.',
        'D': '####./#...#/#...#/#...#/#...#/#...#/####.',
        'E': '#####/#..../#..../####./#..../#..../#####',
        'F': '#####/#..../#..../####./#..../#..../#....',
        'G': '.###./#...#/#..../#.###/#...#/#...#/.####',
        'H': '#...#/#...#/#...#/#####/#...#/#...#/#...#',
        'I': '.###./..#../..#../..#../..#../..#../.###.',
        'J': '..###/...#./...#./...#./...#./#..#./.##..',
        'K': '#...#/#..#./#.#../##.../#.#../#..#./#...#',
        'L': '#..../#..../#..../#..../#..../#..../#####',
        'M': '#...#/##.##/#.#.#/#.#.#/#...#/#...#/#...#',
        'N': '#...#/#...#/##..#/#.#.#/#..##/#...#/#...#',
        'O': '.###./#...#/#...#/#...#/#...#/#...#/.###.',
        'P': '####./#...#/#...#/####./#..../#..../#....',
        'Q': '.###./#...#/#...#/#...#/#.#.#/#..#./.##.#',
        'R': '####./#...#/#...#/####./#.#../#..#./#...#',
        'S': '.####/#..../#..../.###./....#/....#/####.',
        'T': '#####/..#../..#../..#../..#../..#../..#..',
        'U': '#...#/#...#/#...#/#...#/#...#/#...#/.###.',
        'V': '#...#/#...#/#...#/#...#/#...#/.#.#./..#..',
        'W': '#...#/#...#/#...#/#.#.#/#.#.#/##.##/#...#',
        'X': '#...#/#...#/.#.#./..#../.#.#./#...#/#...#',
        'Y': '#...#/#...#/.#.#./..#../..#../..#../..#..',
        'Z': '#####/....#/...#./..#../.#.../#..../#####',
        '0': '.###./#...#/#..##/#.#.#/##..#/#...#/.###.',
        '1': '..#../.##../..#../..#../..#../..#../.###.',
        '2': '.###./#...#/....#/...#./..#../.#.../#####',
        '3': '#####/...#./..#../...#./....#/#...#/.###.',
        '4': '...#./..##./.#.#./#..#./#####/...#./...#.',
        '5': '#####/#..../####./....#/....#/#...#/.###.',
        '6': '..##./.#.../#..../####./#...#/#...#/.###.',
        '7': '#####/....#/...#./..#../.#.../.#.../.#...',
        '8': '.###./#...#/#...#/.###./#...#/#...#/.###.',
        '9': '.###./#...#/#...#/.####/....#/...#./.##..',
        '!': '..#../..#../..#../..#../..#../...../..#..',
        '"': '.#.#./.#.#./.#.#./...../...../...../.....',
        '#': '.#.#./.#.#./#####/.#.#./#####/.#.#./.#.#.',
        '$': '..#../.####/#.#../.###./..#.#/####./..#..',
        '%': '##..#/##..#/...#./..#../.#.../#..##/#..##',
        '&': '.##../#..#./#.#../.#.../#.#.#/#..#./.##.#',
        "'": '..#../..#../.#.../...../...../...../.....',
        '(': '...#./..#../.#.../.#.../.#.../..#../...#.',
        ')': '.#.../..#../...#./...#./...#./..#../.#...',
        '*': '...../..#../#.#.#/.###./#.#.#/..#../.....',
        '+': '...../..#../..#../#####/..#../..#../.....',
        ',': '...../...../...../...../..##./..#../.#...',
        '-': '......./......./......./#######/......./......./.......',
        '.': '...../...../...../...../...../..##./..##.',
        '/': '....#/....#/...#./..#../.#.../#..../#....',
        ':': '...../..##./..##./...../..##./..##./.....',
        ';': '...../..##./..##./...../..##./..#../.#...',
        '<': '...#./..#../.#.../#..../.#.../..#../...#.',
        '=': '......./......./#######/......./#######/......./.......',
        '>': '.#.../..#../...#./....#/...#./..#../.#...',
        '?': '.###./#...#/....#/...#./..#../...../..#..',
        '@': '.###./#...#/#.###/#.#.#/#.###/#..../.###.',
        '[': '.###./.#.../.#.../.#.../.#.../.#.../.###.',
        '\\': '#..../#..../.#.../..#../...#./....#/....#',
        ']': '.###./...#./...#./...#./...#./...#./.###.',
        '^': '..#../.#.#./#...#/...../...../...../.....',
        '_': '......./......./......./......./......./......./#######',
        '|': '..#../..#../..#../..#../..#../..#../..#..',
        'z': '...../...../#####/...#./..#../.#.../#####'
    };

    var atlasCache = null;
    function glyphRows(ch) {
        var g = G[ch] || G['?'];
        return g.split('/');
    }
    function buildGlyph(ch, inv) {
        var c = document.createElement('canvas');
        c.width = CW; c.height = CH;
        var x = c.getContext('2d');
        if (inv) {
            x.fillStyle = PHOSPHOR;
            x.fillRect(0, 0, CW, CH);
            x.globalCompositeOperation = 'destination-out';
        } else {
            x.fillStyle = PHOSPHOR;
        }
        var rows = glyphRows(ch);
        for (var r = 0; r < rows.length; r++) {
            var row = rows[r], ox = row.length === 7 ? 0 : 1;
            for (var k = 0; k < row.length; k++) {
                if (row.charAt(k) === '#') { x.fillRect((ox + k) * 2, r * 2 + 1, 2, 2); }
            }
        }
        return c;
    }
    function glyph(ch, inv) {
        if (!atlasCache) { atlasCache = {}; }
        var key = (inv ? 'i' : 'n') + ch;
        return atlasCache[key] || (atlasCache[key] = buildGlyph(ch, inv));
    }

    /* ---- text helpers: ORK data is mixed case, entity-encoded and sometimes slashed ---- */
    var W = COLS - 1;              // widest line a screen may print (40 would auto-wrap and eat a row)
    var PER_PAGE = 8;
    var HOLD_MS = 45;              // data screens: pause after each whole line

    function clean(s) {
        s = (s === null || s === undefined) ? '' : String(s);
        if (s.indexOf('&') >= 0) {
            try { s = new DOMParser().parseFromString(s, 'text/html').documentElement.textContent || ''; } catch (e) { /* keep raw */ }
        }
        s = s.replace(/\\/g, '');
        if (s.normalize) { s = s.normalize('NFD').replace(/[̀-ͯ]/g, ''); }
        s = s.replace(/[‘’‚‛′´`]/g, "'")
            .replace(/[“”„‟″]/g, '"')
            .replace(/[‐-―−]/g, '-')
            .replace(/…/g, '...')
            .replace(/Æ/g, 'AE').replace(/Œ/g, 'OE').replace(/[Øø]/g, 'O')
            .replace(/[Łł]/g, 'L').replace(/[Đđ]/g, 'D');
        s = s.toUpperCase().replace(/[\s ]+/g, ' ');
        s = s.replace(/[^\x20-\x5F|]/g, '?');
        return s.replace(/^ +| +$/g, '');
    }
    function noThe(s) { return s.replace(/^THE /, ''); }
    function trunc(s, n) { return s.length > n ? s.slice(0, n) : s; }
    function spaces(n) { var r = ''; while (r.length < n) { r += ' '; } return r; }
    function rep(ch, n) { var r = ''; while (r.length < n) { r += ch; } return r; }
    function padTo(s, n) { return s.length >= n ? s : s + spaces(n - s.length); }
    function centerLine(s) { return spaces(Math.max(0, Math.floor((W - s.length) / 2))) + s; }
    function wrap(s, n) {
        var words = s.split(' '), out = [], cur = '';
        for (var i = 0; i < words.length; i++) {
            var w = words[i];
            if (!w) { continue; }
            while (w.length > n) {
                if (cur) { out.push(cur); cur = ''; }
                out.push(w.slice(0, n));
                w = w.slice(n);
            }
            if (!cur) { cur = w; }
            else if (cur.length + 1 + w.length <= n) { cur += ' ' + w; }
            else { out.push(cur); cur = w; }
        }
        if (cur) { out.push(cur); }
        return out;
    }
    function leftRight(left, right, width) {
        width = width || W;
        left = trunc(left, Math.max(0, width - right.length - 1));
        return left + spaces(width - left.length - right.length) + right;
    }
    function fmtNum(n) { return String(Math.round((+n || 0) * 100) / 100); }
    function sortNewest(list, dateKey, idKey) {
        return list.slice().sort(function (a, b) {
            var x = String(a[dateKey] || ''), y = String(b[dateKey] || '');
            if (x !== y) { return x < y ? 1 : -1; }
            return (+b[idKey] || 0) - (+a[idKey] || 0);
        });
    }
    function fmtDate(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
        return (m && m[1] !== '0000') ? m[2] + '/' + m[3] + '/' + m[1].slice(2) : '--/--/--';
    }
    function extend(a, b) {
        var o = {}, k;
        for (k in a) { if (a.hasOwnProperty(k)) { o[k] = a[k]; } }
        for (k in b) { if (b.hasOwnProperty(k)) { o[k] = b[k]; } }
        return o;
    }
    var MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
    var WEEKDAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
    function weekdayOf(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
        return m ? WEEKDAYS[new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])).getUTCDay()] : '';
    }
    function fmtTime(s) {
        var m = /^(\d{1,2}):(\d{2})/.exec(String(s || ''));
        if (!m) { return ''; }
        var h = +m[1];
        return ((h % 12) || 12) + ':' + m[2] + ' ' + (h < 12 ? 'AM' : 'PM');
    }
    function ordinal(n) {
        n = +n || 0;
        var m = n % 100;
        return n + ((m >= 11 && m <= 13) ? 'TH' : (['TH', 'ST', 'ND', 'RD'][n % 10] || 'TH'));
    }
    // ORK descriptions and directions hold HTML (or Markdown the site renders). Strip the tags without loading
    // anything (DOMParser never fetches images or runs scripts), keep paragraph breaks, then clean and wrap.
    // Returns lines of at most n columns; maxRows cuts the text with "...".
    function htmlText(s, n, maxRows) {
        n = n || W;
        s = (s === null || s === undefined) ? '' : String(s);
        var text = s;
        if (/[<&]/.test(s)) {
            try {
                var h = s.replace(/<\s*(script|style)\b[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi, ' ').replace(/<\s*(br|\/p|\/div|\/h[1-6]|\/tr)\b[^>]*>/gi, '\n').replace(/<\s*li\b[^>]*>/gi, '\n- ').replace(/<\s*\/li\s*>/gi, '\n');
                text = new DOMParser().parseFromString(h, 'text/html').body.textContent || '';
            } catch (e) { text = s.replace(/<[^>]*>/g, ' '); }
        }
        text = text.replace(/\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/\*\*|__/g, '').replace(/^#+[ \t]*/gm, '');
        var out = [], pending = false;
        text.split(/\r?\n/).forEach(function (par) {
            var c = clean(par);
            if (!c) { pending = out.length > 0; return; }
            if (pending) { out.push(''); pending = false; }
            out = out.concat(wrap(c, n));
        });
        if (maxRows && out.length > maxRows) {
            out = out.slice(0, maxRows);
            out[maxRows - 1] = trunc(out[maxRows - 1], n - 3) + '...';
        }
        return out;
    }

    /* One pager for every list: 8 rows a page, 9 flips, 0 goes back. cfg.perPage sets a different page size
       (text screens), an item with a newline is several screen lines, cfg.notes print under the rows. */
    function pagerLines(cfg) {
        var items = cfg.items || [], per = cfg.perPage || PER_PAGE;
        var pages = Math.max(1, Math.ceil(items.length / per));
        var page = Math.min(Math.max(0, cfg.page || 0), pages - 1);
        var rows = items.slice(page * per, page * per + per);
        var lines = [leftRight(cfg.title, 'PAGE ' + (page + 1) + ' OF ' + pages), rep('=', W)];
        if (!items.length) {
            lines = lines.concat(wrap(cfg.empty || 'NOTHING ON THIS DISK.', W));
        }
        var prevGroup = null;
        for (var i = 0; i < rows.length; i++) {
            // cfg.groups: a heading per item; printed above the first item of each run on the page.
            if (cfg.groups && cfg.groups[page * per + i] !== prevGroup) { if (prevGroup !== null) { lines.push(''); } prevGroup = cfg.groups[page * per + i]; lines.push(trunc(prevGroup, W)); }
            if (cfg.selectable) { lines.push(trunc((i + 1) + '. ' + rows[i], W)); }
            else { String(rows[i]).split('\n').forEach(function (part) { lines.push(trunc(part, W)); }); }
        }
        (cfg.notes || []).forEach(function (x) { lines.push(trunc(x, W)); });
        lines.push('');
        (cfg.extras || []).forEach(function (x) { lines.push(x.key + '. ' + x.label); });
        if (pages > 1) { lines.push(page === pages - 1 ? '9. ' + (cfg.nineLast || 'FIRST PAGE') : '9. MORE'); }
        else if (cfg.nineLast) { lines.push('9. ' + cfg.nineLast); }
        lines.push('0. ' + cfg.back);
        return { lines: lines, page: page, pages: pages, count: rows.length, per: per, nine: cfg.nineLast || '' };
    }

    /* ---- arms: the kingdom's heraldry as a 1-bit bitmap on the 280x192 pixel grid (a cell is 7x8 pixels) ---- */
    var ART_W = 13, ART_H = 10;    // text cells the bitmap reserves: 91x80 pixels
    var ART_GAP = 2;
    var BAYER = [0, 8, 2, 10, 12, 4, 14, 6, 3, 11, 1, 9, 15, 7, 13, 5];
    // A plain heraldic shield, for kingdoms without usable arms: outline, a pale line and a checked half.
    function shieldBitmap() {
        var w = ART_W * 7, h = ART_H * 8, bits = new Uint8Array(w * h), mid = (w - 1) / 2, hw = 36, y, x;
        function edge(yy) {
            var top = h * 0.5;
            return yy <= top ? hw : hw * Math.sqrt(Math.max(0, 1 - Math.pow((yy - top) / (h - 1 - top), 2)));
        }
        for (y = 4; y < h; y++) {
            var e = Math.max(edge(y), 1.5);
            for (x = 0; x < w; x++) {
                var dx = Math.abs(x - mid);
                if (dx > e) { continue; }
                // Measure against the next row too, or the steep curve near the tip leaves the rim broken.
                    var rim = dx > edge(y + 1) - 2.5 || y < 6 || y > h - 4;
                var chk = x < mid && ((x + y) & 1) === 0 && dx < e - 4 && y > 8;
                var pale = Math.abs(x - mid) < 1.5 && y > 6;
                if (rim || chk || pale) { bits[y * w + x] = 1; }
            }
        }
        return { w: w, h: h, bits: bits };
    }
    // img: a loaded same-origin <img>. Returns {w, h, bits} or throws (tainted canvas, blank image).
    function renderArt(img, force) {
        var SS = 3, cw = ART_W * 7 * SS, ch = ART_H * 8 * SS;
        var iw = img.naturalWidth || img.width, ih = img.naturalHeight || img.height;
        if (!iw || !ih) { throw new Error('empty image'); }
        var c = document.createElement('canvas');
        c.width = cw; c.height = ch;
        var x = c.getContext('2d');
        x.imageSmoothingQuality = 'high';
        var sc = Math.min(cw / iw, ch / ih), dw = Math.max(1, Math.round(iw * sc)), dh = Math.max(1, Math.round(ih * sc));
        var ox = Math.floor((cw - dw) / 2), oy = Math.floor((ch - dh) / 2);
        x.drawImage(img, ox, oy, dw, dh);
        var d = x.getImageData(0, 0, cw, ch).data;
        // Background: transparent pixels, or the flat colour that most of the image's edge shares. Only
        // background connected to the edge is removed, so a white field inside a shield stays part of it.
        var i, k, p, bg = null, votes = {}, best = 0, edge = 0;
        function edgeAt(px, py) {
            i = (py * cw + px) * 4;
            if (d[i + 3] < 200) { return; }
            var key = (d[i] >> 5) + ',' + (d[i + 1] >> 5) + ',' + (d[i + 2] >> 5);
            var v = votes[key] || (votes[key] = { n: 0, r: 0, g: 0, b: 0 });
            v.n++; v.r += d[i]; v.g += d[i + 1]; v.b += d[i + 2];
            edge++;
            if (v.n > best) { best = v.n; bg = [v.r / v.n, v.g / v.n, v.b / v.n]; }
        }
        for (k = 0; k < dw; k++) { edgeAt(ox + k, oy); edgeAt(ox + k, oy + dh - 1); }
        for (k = 0; k < dh; k++) { edgeAt(ox, oy + k); edgeAt(ox + dw - 1, oy + k); }
        if (best < edge * 0.3) { bg = null; }
        function bgLike(q) {
            var j = q * 4;
            return d[j + 3] < 128 || (bg && Math.abs(d[j] - bg[0]) + Math.abs(d[j + 1] - bg[1]) + Math.abs(d[j + 2] - bg[2]) < 60);
        }
        var gone = new Uint8Array(cw * ch), stack = [];
        function seed(q) { if (!gone[q] && bgLike(q)) { gone[q] = 1; stack.push(q); } }
        for (k = 0; k < cw; k++) { seed(k); seed((ch - 1) * cw + k); }
        for (k = 0; k < ch; k++) { seed(k * cw); seed(k * cw + cw - 1); }
        while (stack.length) {
            var q = stack.pop(), qx = q % cw;
            if (qx > 0) { seed(q - 1); }
            if (qx < cw - 1) { seed(q + 1); }
            if (q >= cw) { seed(q - cw); }
            if (q < cw * (ch - 1)) { seed(q + cw); }
        }
        var lum = new Float32Array(cw * ch), hist = new Array(256), n = 0;
        for (k = 0; k < 256; k++) { hist[k] = 0; }
        for (p = 0, i = 0; p < cw * ch; p++, i += 4) {
            if (gone[p] || d[i + 3] < 128) {
                lum[p] = -1;
            } else {
                var l = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
                lum[p] = l; hist[Math.round(l)]++; n++;
            }
        }
        if (!n) { throw new Error('blank image'); }
        // Stretch the arms' own light-to-dark range so pale and dark shields both read.
        var lo = 0, hi = 255, acc = 0;
        for (k = 0; k < 256; k++) { acc += hist[k]; if (acc >= n * 0.03) { lo = k; break; } }
        acc = 0;
        for (k = 255; k >= 0; k--) { acc += hist[k]; if (acc >= n * 0.03) { hi = k; break; } }
        if (hi - lo < 24) { lo = Math.max(0, lo - 12); hi = Math.min(255, lo + 24); }
        // Light the minority tone: pale shields glow where their charges are dark, dark shields where they are bright.
        var mean = 0;
        for (k = lo; k <= hi; k++) { mean += hist[k] * (k - lo) / (hi - lo); }
        var dark = force === undefined ? mean / n > 0.65 : !!force;
        // Down to the 1-bit grid: each pixel averages its SSxSS block, then Bayer-dithers its tone.
        var w = ART_W * 7, h = ART_H * 8, bits = new Uint8Array(w * h), cov = new Float32Array(w * h), tone = new Float32Array(w * h);
        var px, py, xx, yy;
        for (py = 0; py < h; py++) {
            for (px = 0; px < w; px++) {
                var cnt = 0, sum = 0;
                for (yy = py * SS; yy < (py + 1) * SS; yy++) {
                    for (xx = px * SS; xx < (px + 1) * SS; xx++) {
                        var lv = lum[yy * cw + xx];
                        if (lv >= 0) { cnt++; sum += Math.min(1, Math.max(0, (lv - lo) / (hi - lo))); }
                    }
                }
                cov[py * w + px] = cnt / (SS * SS);
                tone[py * w + px] = cnt ? sum / cnt : 0;
            }
        }
        for (py = 0; py < h; py++) {
            for (px = 0; px < w; px++) {
                var q = py * w + px;
                if (cov[q] < 0.5) { continue; }
                var onEdge = px === 0 || py === 0 || px === w - 1 || py === h - 1 ||
                    cov[q - 1] < 0.5 || cov[q + 1] < 0.5 || cov[q - w] < 0.5 || cov[q + w] < 0.5;
                var t = dark ? 1 - tone[q] : tone[q];
                if (onEdge || t > (BAYER[(py & 3) * 4 + (px & 3)] + 0.5) / 16) { bits[q] = 1; }
            }
        }
        return { w: w, h: h, bits: bits, mean: mean / n };
    }

    var OFFICES = [['Monarch', 'MONARCH'], ['Regent', 'REGENT'], ['Prime Minister', 'PRIME MIN'], ['Champion', 'CHAMPION'], ['GMR', 'GMR']];
    // The profile as {lines, head, log}: the ART_W x ART_H cells at the top left stay blank for the arms
    // bitmap (drawn as a graphics layer), head is how many rows they cover, log is what the live log
    // gets instead of those rows.
    function profileParts(p, back) {
        var colW = W - ART_W - ART_GAP, right = wrap(clean(p.name), colW);
        if (p.abbr) { right.push(clean(p.abbr)); }
        if (p.isPrinz && p.parentName) { right = right.concat(wrap('PRINCIPALITY OF ' + noThe(clean(p.parentName)).replace(/^(KINGDOM|EMPIRE) OF (THE )?/, ''), colW)); }
        right.push(rep('=', colW));
        var n = function (v) { return (v === null || v === undefined) ? '--' : String(v); };
        // The four counts: rows statRow.. of the screen, to the right of the arms. They may fill in after it prints.
        var statRow = right.length, stat = ['PARKS ' + n(p.parkCount), 'EVENTS ' + n(p.eventCount), 'AVG/WEEK ' + n(p.avgWeek), 'AVG/MONTH ' + n(p.avgMonth)];
        right = right.concat(stat);
        var head = Math.max(ART_H, right.length), lines = [];
        for (var h = 0; h < head; h++) {
            lines.push((spaces(ART_W + ART_GAP) + trunc(right[h] || '', colW)).replace(/ +$/, ''));
        }
        var log = ['ARMS OF ' + clean(p.name)].concat(right);
        lines.push('');
        for (var i = 0; i < OFFICES.length; i++) {
            var val = '(UNAVAILABLE)';
            if (p.officers) {
                val = '(VACANT)';
                for (var j = 0; j < p.officers.length; j++) {
                    var o = p.officers[j];
                    if (o.OfficerRole === OFFICES[i][0] && +o.MundaneId > 0 && clean(o.Persona)) { val = clean(o.Persona); break; }
                }
            }
            lines.push(trunc(padTo(OFFICES[i][1], 10) + val, W));
        }
        lines.push('');
        lines.push('1. PARKS');
        lines.push('2. EVENTS');
        if (p.prinz && p.prinz.length) { lines.push('3. PRINCIPALITIES'); }
        lines.push((p.prinz && p.prinz.length ? '4' : '3') + '. PAST EVENTS');
        lines.push((p.prinz && p.prinz.length ? '5' : '4') + '. COMPANIES AND HOUSEHOLDS');
        lines.push((p.prinz && p.prinz.length ? '6' : '5') + '. KNIGHTS AND MASTERS');
        lines.push('0. ' + back);
        lines = fitRows(lines, head);
        return { lines: lines, head: head, log: log, stat: { row: statRow, col: ART_W + ART_GAP, width: colW, lines: stat } };
    }
    function profileLines(p, back) { return profileParts(p, back).lines; }
    // A screen may print 23 lines (the prompt takes the 24th row). Over that, drop blank spacer lines below the arms, last first.
    function fitRows(lines, head) {
        for (var i = lines.length - 1; lines.length > ROWS - 1 && i >= head; i--) {
            if (lines[i] === '') { lines.splice(i, 1); }
        }
        return lines;
    }

    /* ---- parks ---- */
    var PARK_MENU = [
        ['MEETING DAYS', 'days', 'PDAYS'], ['WHERE TO FIND US', 'details', 'PWHERE'], ['OFFICERS', 'officers', 'POFFICERS'],
        ['RECENT PLAYERS', 'recent', 'PRECENT'], ['EVENTS', 'events', 'PKEVENTS'], ['COMPANIES', 'companies', 'PCOMP'],
        ['ATTENDANCE', 'chart', 'PCHART'], ['SIGN-INS BY DAY', 'dates', 'PDATES']
    ];
    var OFFICE_SHORT = { 'Prime Minister': 'PRIME MIN', 'Monarch': 'MONARCH', 'Regent': 'REGENT', 'Champion': 'CHAMPION', 'GMR': 'GMR' };
    function capRows(lines, rows, n) {
        if (lines.length <= rows) { return lines; }
        var out = lines.slice(0, rows);
        out[rows - 1] = trunc(out[rows - 1], n - 3) + '...';
        return out;
    }
    // The park profile, same layout as the kingdom's: arms left, facts right. A figure the page hides stays out.
    function parkParts(p, back) {
        var colW = W - ART_W - ART_GAP, d = p.details || {};
        var right = capRows(wrap(clean(p.name), colW), 3, colW);
        var sub = [clean(d.ParkTitle), clean(p.kingdomAbbr)].filter(Boolean).join(', ');
        if (sub) { right = right.concat(wrap(sub, colW)); }
        var where = [clean(d.City), clean(d.Province)].filter(Boolean).join(', ');
        if (where) { right = right.concat(capRows(wrap(where, colW), 2, colW)); }
        if (p.retired) { right.push('RETIRED'); }
        right.push(rep('=', colW));
        // The page shows the count only when today is an in-person park day (Parknew_index.tpl: $_pdIsToday && $TodayAtField).
        if (p.field > 0 && p.parkDayToday) { right.push('AT FIELD NOW ' + p.field); }
        right.push('AVG/WEEK ' + (p.avgWeek === null ? '--' : p.avgWeek), 'AVG/MONTH ' + (p.avgMonth === null ? '--' : p.avgMonth));
        var head = Math.max(ART_H, right.length), lines = [];
        for (var h = 0; h < head; h++) {
            lines.push((spaces(ART_W + ART_GAP) + trunc(right[h] || '', colW)).replace(/ +$/, ''));
        }
        lines.push('');
        for (var i = 0; i < PARK_MENU.length; i++) { lines.push((i + 1) + '. ' + PARK_MENU[i][0]); }
        lines.push('0. ' + back);
        return { lines: lines, head: head, log: ['ARMS OF ' + clean(p.name)].concat(right) };
    }
    // One park day as screen lines: when, what and where, then the page's own short description.
    function parkDayText(day) {
        var wd = clean(day.WeekDay), rec = clean(day.Recurrence);
        if (day.Recurrence === 'weekly') { rec = wd + 'S'; }
        else if (day.Recurrence === 'week-of-month') { rec = ordinal(day.WeekOfMonth) + ' ' + wd; }
        else if (day.Recurrence === 'every-x-weeks') { rec = +day.WeekInterval === 2 ? 'EVERY OTHER ' + wd : 'EVERY ' + (+day.WeekInterval || '?') + ' WEEKS ON ' + wd + 'S'; }
        else if (day.Recurrence === 'monthly') { rec = 'MONTHLY ON THE ' + ordinal(day.MonthDay); }
        var purpose = { 'park-day': 'PARK DAY', 'fighter-practice': 'FIGHTER PRACTICE', 'arts-day': 'A&S DAY', 'other': 'OTHER' }[day.Purpose] || 'PARK DAY';
        var lines = [trunc(rec + ' ' + fmtTime(day.Time), W), '  ' + purpose + (+day.Online ? ', ONLINE' : '')];
        if (!+day.Online) {
            var addr = clean(day.Address), city = clean(day.City);
            if (!(city && addr.indexOf(city) >= 0)) { addr = [addr, city, clean(day.Province), clean(day.PostalCode)].filter(Boolean).join(', '); }
            if (addr) { lines.push('  ' + trunc(addr, W - 2)); }
        }
        htmlText(day.Description, W - 2, 2).forEach(function (t) { if (t) { lines.push('  ' + t); } });
        return lines.join('\n');
    }
    // The lines for WHERE TO FIND US: address, directions, about, web address. Empty when the park gave none.
    function parkWhereLines(d) {
        var out = [];
        function section(label, lines) {
            if (!lines.length) { return; }
            if (out.length) { out.push(''); }
            out.push(label);
            out = out.concat(lines);
        }
        function trim(x) { return String(x === null || x === undefined ? '' : x).replace(/^\s+|\s+$/g, ''); }
        var city = trim(d.City);
        // The address often already ends in the city; say it once.
        var line1 = [d.Address, city && trim(d.Address).toUpperCase().indexOf(city.toUpperCase()) >= 0 ? '' : city].map(trim).filter(Boolean).join(', ');
        var line2 = [d.Province, d.PostalCode].map(trim).filter(Boolean).join(' ');
        var addr = [line1, line2].filter(Boolean).join(', ');
        section('ADDRESS', addr ? wrap(clean(addr), W) : []);
        section('DIRECTIONS', htmlText(d.Directions, W, 40));
        section('ABOUT', htmlText(d.Description, W, 40));
        var url = trim(d.Url);
        section('WEB', /^https?:\/\//i.test(url) ? wrap(clean(url), W) : []);
        return out;
    }

    /* ---- events ---- */
    // Event descriptions are stored URL-encoded; decode every valid %XX run and leave a stray % alone.
    function evDecode(s) {
        s = (s === null || s === undefined) ? '' : String(s);
        try { return decodeURIComponent(s); } catch (e) {
            return s.replace(/(?:%[0-9A-Fa-f]{2})+/g, function (m) { try { return decodeURIComponent(m); } catch (e2) { return m; } });
        }
    }
    // A link cannot be clicked here, so a long one shrinks to its host.
    function shortLinks(s) {
        return String(s).replace(/https?:\/\/[^\s)<>"']+/gi, function (u) {
            if (u.length <= 30) { return u; }
            var m = /^https?:\/\/([^\/?#]+)/i.exec(u);
            return (m ? m[1].replace(/^www\./i, '') : 'LINK') + '/...';
        });
    }
    function evTime(s) {
        var t = String(s || '').slice(11, 16);
        return (!t || t === '00:00') ? '' : fmtTime(t);
    }
    function validDay(s) { return /^\d{4}-\d{2}-\d{2}$/.test(s) && s.slice(0, 4) !== '0000'; }
    // When an event runs, as one or two lines of at most 24 columns.
    function evWhen(start, end) {
        var sd = String(start || '').slice(0, 10), ed = String(end || '').slice(0, 10);
        if (!validDay(sd)) { return ['DATE TO BE SET']; }
        var st = evTime(start), et = evTime(end);
        var first = [weekdayOf(sd), fmtDate(sd), st].filter(Boolean).join(' ');
        if (!validDay(ed) || ed < sd) { return [first]; }
        if (ed === sd) { return et && et !== st ? [first, 'TO ' + et] : [first]; }
        return [first, 'TO ' + [weekdayOf(ed), fmtDate(ed), et].filter(Boolean).join(' ')];
    }
    function trimS(x) { return String(x === null || x === undefined ? '' : x).replace(/^\s+|\s+$/g, ''); }
    // The park hosting the event stands in when the occurrence gave no place of its own, as on the page.
    function evNoPlace(d) { return !trimS(d.Address) && !trimS(d.City) && !trimS(d.Province); }
    function evCity(d, park) {
        var src = (evNoPlace(d) && park) ? park : d;
        return [trimS(src.City), trimS(src.Province)].filter(Boolean).join(', ');
    }
    function evAddress(d, park) {
        var src = (evNoPlace(d) && park) ? park : d, addr = trimS(src.Address), low = addr.toLowerCase(), parts = [];
        if (addr) { parts.push(addr); }
        ['City', 'Province'].forEach(function (k) {
            var v = trimS(src[k]);
            if (v && low.indexOf(v.toLowerCase()) < 0) { parts.push(v); }
        });
        return parts.join(', ');
    }
    function evPrice(p) {
        p = +p || 0;
        return p > 0 ? '$' + (p % 1 ? p.toFixed(2) : String(p)) : '';
    }
    // The event screen: arms left, facts right, then the submenu.
    var EVENT_MENU = [['DESCRIPTION', 'desc', 'EVDESC'], ['RSVP COUNTS', 'rsvp', 'EVRSVP'], ['SCHEDULE', 'sched', 'EVSCHED'], ['WHO CAME', 'who', 'EVWHO']];
    function eventParts(e, back) {
        var colW = W - ART_W - ART_GAP, d = e.detail || {}, i = e.info || {};
        var right = capRows(wrap(clean(e.name), colW), 3, colW);
        var type = clean(d.EventType);
        if (type) { right.push(trunc(type, colW)); }
        var host = clean(i.ParkName) || noThe(clean(i.KingdomName));
        if (host) { right = right.concat(capRows(wrap(host, colW), 2, colW)); }
        right = right.concat(evWhen(d.EventStart, d.EventEnd).map(function (x) { return trunc(x, colW); }));
        var place = evCity(d, e.atPark);
        if (place) { right = right.concat(capRows(wrap(clean(place), colW), 2, colW)); }
        var price = evPrice(d.Price);
        if (price) { right.push('PRICE ' + price); }
        right.push(rep('=', colW));
        var head = Math.max(ART_H, right.length), lines = [];
        for (var h = 0; h < head; h++) {
            lines.push((spaces(ART_W + ART_GAP) + trunc(right[h] || '', colW)).replace(/ +$/, ''));
        }
        lines.push('');
        for (var k = 0; k < EVENT_MENU.length; k++) { lines.push((k + 1) + '. ' + EVENT_MENU[k][0]); }
        lines.push('0. ' + back);
        return { lines: lines, head: head, log: ['ARMS OF ' + clean(e.name)].concat(right) };
    }
    // DESCRIPTION: address, the wrapped description, the event's web address.
    function eventDescLines(e) {
        var d = e.detail || {}, out = [];
        function section(label, lines) {
            if (!lines.length) { return; }
            if (out.length) { out.push(''); }
            out.push(label);
            out = out.concat(lines);
        }
        var addr = evAddress(d, e.atPark);
        section('ADDRESS', addr ? wrap(clean(addr), W) : []);
        section('DESCRIPTION', htmlText(shortLinks(evDecode(d.Description)), W, 60));
        var url = trimS(d.Url);
        section('WEB', /^https?:\/\//i.test(url) ? wrap(clean(shortLinks(url)), W) : []);
        return out;
    }
    // SCHEDULE: one page a day (a long day continues on the next page), at most 16 lines each.
    function schedItem(it) {
        var t = fmtTime(String(it.start || '').slice(11)), en = fmtTime(String(it.end || '').slice(11));
        var lines = [t ? (en && en !== t ? t + ' - ' + en : t) : 'TIME TO BE SET'];
        capRows(wrap(clean(it.title), W - 2), 2, W - 2).forEach(function (x) { lines.push('  ' + x); });
        var loc = clean(it.location), leads = Array.isArray(it.leads) ? it.leads.map(clean).filter(Boolean).join(', ') : '';
        if (loc) { lines.push(trunc('  AT ' + loc, W)); }
        if (leads) { lines.push(trunc('  LEAD ' + leads, W)); }
        return lines;
    }
    function schedPages(days) {
        var pages = [];
        days.forEach(function (day) {
            var hdr = validDay(String(day.date || '')) ? weekdayOf(day.date) + ' ' + fmtDate(day.date) : (clean(day.day_label) || 'TIME TO BE SET');
            var items = Array.isArray(day.items) ? day.items.map(schedItem) : [], cur = [], first = true;
            function flush() {
                if (!cur.length) { return; }
                pages.push([first ? hdr : hdr + ' (CONT)'].concat(cur).join('\n'));
                first = false; cur = [];
            }
            items.forEach(function (it) {
                if (cur.length && 1 + cur.length + it.length > 16) { flush(); }
                cur = cur.concat(it);
            });
            flush();
            if (!items.length) { pages.push(hdr + '\n  NOTHING SCHEDULED.'); }
        });
        return pages;
    }
    // The calendar feed's rows, normalised: E an event (opens EVENT), C a calendar item, P a park day.
    function calRow(e) {
        var x = e.extendedProps || {}, st = String(e.start || '').replace('T', ' '), t = String(e.title || '');
        if (e.type === 'kingdom-event' || e.type === 'park-event') {
            // A park's event is titled "ABBR: name" by the feed; the event's own name comes from its record.
            return { kind: 'E', title: t, name: e.type === 'park-event' ? t.replace(/^\S+: /, '') : t, start: st, end: String(e.end || ''), eid: +x.eventId || 0, did: +x.detailId || 0 };
        }
        if (e.type === 'calendar-item') {
            return { kind: 'C', title: t, start: String(x.rawStart || st), end: String(x.rawEnd || ''), allDay: !!e.allDay, desc: String(x.description || ''), parkId: +x.parkId || 0 };
        }
        if (e.type === 'park-day') {
            var m = /Park\/profile\/(\d+)/.exec(String(e.url || ''));
            return { kind: 'P', title: t, start: st, parkId: m ? +m[1] : 0 };
        }
        return null;
    }
    function calRowText(r) {
        return trunc(r.start.slice(5, 7) + '/' + r.start.slice(8, 10) + ' ' + clean(r.title), W - 3);
    }
    function calItemLines(r) {
        var lines = wrap(clean(r.title), W);
        lines.push('');
        if (r.allDay) {
            var a = r.start.slice(0, 10), b = r.end.slice(0, 10);
            lines.push('ALL DAY ' + weekdayOf(a) + ' ' + fmtDate(a));
            if (validDay(b) && b > a) { lines.push('TO ' + weekdayOf(b) + ' ' + fmtDate(b)); }
        } else {
            evWhen(r.start, r.end).forEach(function (x) { lines.push(x); });
        }
        lines.push(r.parkId > 0 ? 'PARK-LEVEL CALENDAR ITEM' : 'KINGDOM-LEVEL CALENDAR ITEM');
        var desc = htmlText(shortLinks(r.desc), W, 40);
        if (desc.length) { lines.push(''); lines = lines.concat(desc); }
        return lines;
    }

    /* ---- companies and households ---- */
    var UNIT_MENU = [['ABOUT', 'UNABOUT'], ['HISTORY', 'UNHIST']];
    var UNIT_NOTE = 'CO COMPANY HH HOUSEHOLD * INACTIVE';
    var UNIT_CAP = 100;            // the website's default unit list is the 100 largest
    function unitCode(type) { return type === 'Company' ? 'CO' : (type === 'Household' ? 'HH' : '??'); }
    // A row of the kingdom's list: name, then CO or HH, with * when no member signed in during the last 12 months
    // (the website's INACTIVE badge). The caller leaves 3 columns for the row number.
    function unitRowText(u) {
        return leftRight(clean(u.name), unitCode(u.type) + (u.active > 0 ? '' : '*'), W - 3);
    }
    // The unit screen: arms left, facts right, then the submenu. leaders is null when the list row was not found.
    function unitParts(u, back) {
        var colW = W - ART_W - ART_GAP;
        var right = capRows(wrap(clean(u.name), colW), 3, colW);
        var type = clean(u.type);
        if (type) { right.push(trunc(type, colW)); }
        if (u.retired) { right.push('RETIRED'); }
        right.push(rep('=', colW));
        if (u.leaders !== null && u.leaders !== undefined) {
            right.push('LEADERS');
            var ls = wrap(clean(u.leaders), colW);
            right = right.concat(ls.length ? capRows(ls, 6, colW) : ['NONE LISTED']);
        }
        var head = Math.max(ART_H, right.length), lines = [];
        for (var h = 0; h < head; h++) {
            lines.push((spaces(ART_W + ART_GAP) + trunc(right[h] || '', colW)).replace(/ +$/, ''));
        }
        lines.push('');
        for (var i = 0; i < UNIT_MENU.length; i++) { lines.push((i + 1) + '. ' + UNIT_MENU[i][0]); }
        lines.push('0. ' + back);
        return { lines: lines, head: head, log: ['ARMS OF ' + clean(u.name)].concat(right) };
    }
    // Unit text is Markdown, shown by the page with images removed and raw tags shown as text; here links shrink
    // to their words, images go, and the rest is wrapped. 240 lines at most.
    function unitTextLines(text) {
        return htmlText(shortLinks(String(text === null || text === undefined ? '' : text).replace(/!\[[^\]]*\]\([^)]*\)/g, '')), W, 240);
    }
    function unitAboutLines(u) {
        var out = unitTextLines(u.desc), url = trimS(u.url);
        if (/^https?:\/\//i.test(url)) {
            if (out.length) { out.push(''); }
            out.push('WEB');
            out = out.concat(wrap(clean(shortLinks(url)), W));
        }
        return out;
    }

    /* ---- global search: players, parks, kingdoms, companies, households in that order ---- */
    var OSEARCH_LINES = ['SEARCH THE ORK', rep('=', W), '', 'TYPE A NAME. FINDS PLAYERS, PARKS,', 'KINGDOMS, COMPANIES, HOUSEHOLDS.', 'PLAYER SHORTCUTS:', 'KCG:AR TOBIAS', 'KCG: TOBIAS', '', '0. MAIN MENU'];
    var OS_GROUPS = [['player', 'PLAYERS'], ['park', 'PARKS'], ['kingdom', 'KINGDOMS'], ['company', 'COMPANIES'], ['household', 'HOUSEHOLDS']];
    // rows: [{kind, id, name, tag}] in group order; the pager prints each group's heading.
    function osRowText(r) { return leftRight(r.name, r.tag, W - 3); }
    function osGroups(rows) {
        return rows.map(function (r) { return OS_GROUPS.filter(function (g) { return g[0] === r.kind; })[0][1]; });
    }

    /* ---- players ---- */
    var SEARCH_LINES =['PLAYER SEARCH', rep('=', W), '', 'TYPE A NAME, OR A SHORTCUT:', 'KCG:AR TOBIAS', 'KCG: TOBIAS', '', '0. MAIN MENU'];
    function myLines(m) {
        var lines = ['MY AMTGARD', rep('=', W), ''];
        function section(list, fmt) {
            if (list === null) { lines.push('  (UNAVAILABLE)'); }
            else if (!list.length) { lines.push('  NONE'); }
            else { list.forEach(function (x) { lines.push(trunc('  ' + fmt(x), W)); }); }
        }
        // The home park's next meeting from the kingdom calendar (the server expands the recurrences). No home park, no line.
        if (m.parkDay !== undefined) {
            lines.push('NEXT PARK DAY');
            if (m.parkDay === null) { lines.push('  (UNAVAILABLE)'); }
            else if (!m.parkDay) { lines.push('  NONE IN THE NEXT FIVE WEEKS'); }
            else { lines.push(trunc('  ' + parkDayNext(m.parkDay), W)); }
            lines.push('');
        }
        // Upcoming RSVPs come from the signed-in session's own route (EventRsvpAjax/mine, no player id). Logged out,
        // the route refuses and the block is left out, not printed as (UNAVAILABLE).
        if (m.rsvps !== null) {
            lines.push('NEXT UP');
            section(m.rsvps, function (r) { return fmtDate(r.EventStart) + ' ' + clean(r.EventName); });
            lines.push('');
        }
        lines.push('RECENT CLASSES');
        section(m.classes, function (c) { return classRow(c, 9); });
        lines.push('', 'LATEST AWARD');
        section(m.award, function (a) { return awardRows([a])[0]; });
        lines.push('', 'FULL DASHBOARD: THE MODERN ORK', '', '0. BACK');
        return lines;
    }
    // A shortcut is KINGDOM:PARK or KINGDOM: followed by a name; a bare prefix is no search at all.
    function searchProblem(t) {
        var m = /^([A-Z0-9]{2,3}):([A-Z0-9]{2,3}|\*)?(\s+(.*))?$/.exec(t);
        var term = m ? (m[4] || '') : t;
        if (term.replace(/\s/g, '').length < 2) {
            return m ? 'SHORTCUT NEEDS A NAME: KCG:AR TOBIAS' : 'TYPE AT LEAST 2 LETTERS';
        }
        return '';
    }
    // The words for a qualification the service already judged on server time (Player/GetPlayer ReeveStatus, CorporaStatus).
    function qualWord(status, until) {
        if (status === 'no_end') { return 'YES, NO END DATE'; }
        if (status === 'until' || status === 'expired') { return (status === 'expired' ? 'EXPIRED ' : 'UNTIL ') + fmtDate(until); }
        return 'NO';
    }
    // The player profile, from the fields the public page shows a logged-out visitor (never the account name,
    // the id, real names, email, notes or a suspension reason). Returns the lines above the prompt.
    function playerLines(p, back, own) {
        function cap(s, rows) {
            var w = wrap(s, W);
            if (w.length > rows) { w = w.slice(0, rows); w[rows - 1] = trunc(w[rows - 1], W - 3) + '...'; }
            return w;
        }
        function build(nameRows) {
            var lines = cap(clean(p.name), nameRows);
            if (p.pronunciation) { lines.push(trunc('(' + clean(p.pronunciation) + ')', W)); }
            if (p.pronouns) { lines.push(trunc(clean(p.pronouns), W)); }
            if (p.where) { lines = lines.concat(cap(clean(p.where), 2)); }
            lines.push(rep('=', W));
            lines.push(padTo('STATUS', 9) + p.status);
            lines.push('PLAYER SINCE ' + p.since);
            lines.push('LAST SIGN-IN ' + p.last);
            lines.push(padTo('DUES', 9) + p.dues);
            lines.push(padTo('REEVE', 9) + p.reeve);
            lines.push(padTo('CORPORA', 9) + p.corpora);
            lines.push('');
            lines.push('1. CLASSES');
            lines.push('2. AWARDS');
            lines.push('3. ATTENDANCE');
            lines.push('4. TITLES');
            var k = 5;
            if (own) { lines.push((k++) + '. MY AMTGARD'); }
            for (var i = 0; i < PLAYER_MORE.length; i++) { lines.push((k++) + '. ' + PLAYER_MORE[i][0]); }
            lines.push('0. ' + (back || 'BACK'));
            return lines;
        }
        // A long name, home and pronouns could push the menu off the page: give the name fewer rows, then drop the spacer.
        var lines = build(3);
        if (lines.length > ROWS - 1) { lines = build(2); }
        if (lines.length > ROWS - 1) { lines = build(1); }
        return fitRows(lines, 0);
    }
    var PLAYER_MORE = [['ABOUT', 'about'], ['COMPANIES', 'companies'], ['MILESTONES', 'miles']];

    // A loader bar: inverse cells for the filled part, dots for the rest. A full bar only at the maximum.
    function barCells(rank, max, w) {
        w = w || 10;
        rank = Math.max(0, +rank || 0); max = Math.max(1, +max || 10);
        var filled = rank >= max ? w : Math.min(w - 1, Math.max(rank > 0 ? 1 : 0, Math.round(rank * w / max)));
        return (filled ? '\x01' + spaces(filled) + '\x01' : '') + rep('.', w - filled);
    }
    // One ladder as a loader bar. A full bar only at max rank.
    function ladderRow(l) {
        var rank = Math.max(0, +l.Rank || 0), max = Math.max(1, +l.MaxRank || 10);
        return padTo(trunc(clean(l.Short || l.Name), 9), 9) + ' [' + barCells(rank, max) + '] ' + (l.Approx ? '~' : '') + rank + '/' + max + (l.HasMaster ? ' M' : '');
    }
    // A class as a loader bar filling toward the next level (credits 5/12/21/34/53 make LV2-LV6, as ClassLevel):
    // WARRIOR     [#####.....] LV4 27/34. A full bar at LV6. labelW is the name's column; the bar's two \x01
    // markers count toward the trunc width, so labelW + 14 + 12 + 2 stays within the row.
    var CLASS_STEPS = [0, 5, 12, 21, 34, 53];
    function classRow(c, labelW) {
        var lv = Math.min(6, Math.max(1, +c.Level || 1)), cr = +c.Credits || 0;
        var lo = CLASS_STEPS[lv - 1], hi = CLASS_STEPS[lv];
        var bar = lv >= 6 ? barCells(1, 1) : barCells(cr - lo, hi - lo);
        return padTo(trunc(clean(c.ClassName), labelW), labelW) + ' [' + bar + '] LV' + lv + ' ' + fmtNum(cr) + (lv >= 6 ? '' : '/' + hi);
    }
    // One upcoming park day from the calendar feed: {start, title}, as SAT 11/14/26 11:00 AM PARK DAY.
    function parkDayNext(r) {
        var d = String(r.start).slice(0, 10), t = String(r.start).slice(11, 16);
        return weekdayOf(d) + ' ' + fmtDate(d) + (t && t !== '00:00' ? ' ' + fmtTime(t) : '') + ' ' + clean(String(r.title).replace(/^[^:]*: /, ''));
    }
    // The park page's Next Park Day card counts only in-person meetings. The calendar feed does not say which rows are
    // online, so a row is matched to the park's own days by purpose (the feed's title label), time and day. It is
    // online only when every day it matches is online; a row that matches no day is kept.
    var PD_LABEL = { 'park-day': 'PARK DAY', 'fighter-practice': 'FIGHTER PRACTICE', 'arts-day': 'A&S DAY' };
    function parkDayInPerson(r, days) {
        var d = String(r.start).slice(0, 10), t = String(r.start).slice(11, 19) || '00:00:00';
        var label = clean(String(r.title).replace(/^[^:]*: /, '')), m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(d);
        if (!m) { return true; }
        var wd = weekdayOf(d), dom = +m[3], last = new Date(Date.UTC(+m[1], +m[2], 0)).getUTCDate();
        var online = false, inPerson = false;
        days.forEach(function (x) {
            var lbl = PD_LABEL[x.Purpose] || clean(String(x.Purpose || '').replace(/-/g, ' '));
            var tm = String(x.Time || '') || '00:00:00';
            var on = x.Recurrence === 'monthly' ? Math.min(+x.MonthDay || 0, last) === dom : clean(x.WeekDay).slice(0, 3) === wd;
            if (lbl !== label || tm !== t || !on) { return; }
            if (+x.Online) { online = true; } else { inPerson = true; }
        });
        return inPerson || !online;
    }
    // Bars in the ladder look: [{label, value}] scaled to the biggest value, which gets the full bar.
    function barChart(items) {
        var max = 1;
        items.forEach(function (x) { if (+x.value > max) { max = +x.value; } });
        return items.map(function (x) {
            return padTo(trunc(clean(x.label), 9), 9) + ' [' + barCells(x.value, max) + '] ' + (+x.value || 0);
        });
    }
    // Newest first, the page's own name for each award, with the rank in brackets when it has one.
    function awardRows(list) {
        return sortNewest(list, 'Date', 'AwardsId').map(function (a) {
            var name = clean(String(a.CustomAwardName || '').replace(/^\s+|\s+$/g, '') ? a.CustomAwardName : (a.KingdomAwardName || a.Name || a.AliasAwardName));
            return leftRight(fmtDate(a.Date) + ' ' + name, +a.Rank > 0 ? '[' + (+a.Rank) + ']' : '');
        });
    }

    /* ---- THE ORK TODAY: bulletin board screens. Each shows what the matching public page shows an anonymous visitor. ---- */
    var TODAY_MENU = [['KINGDOM STANDINGS', 'TSTAND'], ['TOP PARKS', 'TPARKS'], ['THIS WEEK', 'TWEEK'], ['ON THE FIELD NOW', 'TLIVE'], ['WEATHER FOR PLAY', 'TWX']];
    function todayLines(ep) {
        var lines = ['THE ORK TODAY', rep('=', W)];
        if (ep) {
            lines.push(trunc(ep.text, W));
            if (ep.holiday) { lines = lines.concat(capRows(wrap('HOLIDAY: ' + ep.holiday, W), 2, W)); }
        }
        lines.push('');
        for (var i = 0; i < TODAY_MENU.length; i++) { lines.push((i + 1) + '. ' + TODAY_MENU[i][0]); }
        lines.push('', '0. MAIN MENU');
        return lines;
    }
    // Era Phoenice date as the website's popover words it, upper case; null when the answer is not usable.
    function epOf(d) {
        if (!d || !d.ok || !d.ep || typeof d.ep.formatted !== 'string' || !clean(d.ep.formatted)) { return null; }
        return { text: clean(d.ep.formatted), holiday: typeof d.holiday === 'string' ? clean(d.holiday) : '' };
    }
    function kingdomShort(n) { return noThe(clean(n)).replace(/^(KINGDOM|EMPIRE) OF (THE )?/, ''); }
    // Kingdoms (not principalities, as the home page's cards) ranked by average players a week, six months (AvgPerWeek).
    function standingRows(list) {
        return list.filter(function (k) { return +k.KingdomId > 0 && clean(k.KingdomName) && !(+k.ParentKingdomId); }).map(function (k) {
            return { id: +k.KingdomId, name: k.KingdomName, parks: +k.ParkCount || 0, weekly: +k.AvgPerWeek || 0 };
        }).sort(function (a, b) { return (b.weekly - a.weekly) || (clean(a.name) < clean(b.name) ? -1 : 1); });
    }
    function standingText(r) { return leftRight(kingdomShort(r.name), r.parks + 'P ' + r.weekly.toFixed(1), W - 3); }
    function topParkText(r, rank) { return leftRight(clean(r.name), '#' + rank + ' ' + r.avg.toFixed(1), W - 3); }
    // A peerage award with the word the heading already says taken off: KNIGHT OF THE BATTLE reads BATTLE under KNIGHTS.
    function peerShort(name) {
        var c = clean(name), s = c.replace(/^(KNIGHT OF THE|KNIGHT OF|PARAGON|MASTER) /, '');
        return s || c;
    }
    // THIS WEEK, from Recap/json: sections in the page's order. Rows are {text, kind, ...ids}; groups is the heading per row.
    var WEEK_NEW_PLAYERS = 30;
    function weekRows(r) {
        var rows = [], groups = [];
        function add(group, list, fn) { list.forEach(function (x) { var row = fn(x); if (row) { rows.push(row); groups.push(group); } }); }
        var ok = function (a) { return Array.isArray(a) ? a : []; };
        add('BIGGEST EVENTS', ok(r.TopEvents), function (e) {
            return clean(e.EventName) ? { kind: 'event', eid: +e.EventId || 0, did: +e.EventCalendarDetailId || 0, name: e.EventName, text: leftRight(clean(e.EventName), String(+e.Attendance || 0), W - 3) } : null;
        });
        add('MOST ACTIVE PARKS', ok(r.TopParks), function (p) {
            return (+p.ParkId > 0 && clean(p.ParkName)) ? { kind: 'park', id: +p.ParkId, text: leftRight(clean(p.ParkName), String(+p.Attendance || 0), W - 3) } : null;
        });
        [['NEW KNIGHTS', 'Knightings'], ['NEW MASTERS', 'Masterhoods'], ['NEW PARAGONS', 'Paragons']].forEach(function (g) {
            add(g[0], ok(r[g[1]]), function (x) {
                return (+x.MundaneId > 0 && clean(x.Persona)) ? { kind: 'player', id: +x.MundaneId, text: leftRight(clean(x.Persona), trunc(peerShort(x.AwardName), 18), W - 3) } : null;
            });
        });
        var np = r.NewPlayers && Array.isArray(r.NewPlayers.Players) ? r.NewPlayers.Players : [], total = +(r.NewPlayers && r.NewPlayers.Count) || np.length;
        add(np.length > WEEK_NEW_PLAYERS ? 'NEW PLAYERS (FIRST ' + WEEK_NEW_PLAYERS + ' OF ' + total + ')' : 'NEW PLAYERS (' + total + ')', np.slice(0, WEEK_NEW_PLAYERS), function (x) {
            return (+x.MundaneId > 0 && clean(x.Persona)) ? { kind: 'player', id: +x.MundaneId, text: leftRight(clean(x.Persona), trunc(clean(x.ParkName), 16), W - 3) } : null;
        });
        return { rows: rows, groups: groups };
    }
    // ON THE FIELD NOW, from Live/stats: parks and events with sign-ins in the past 3 hours, busiest first.
    // The service sends an empty list ([]) where it has no parks or events, and an object keyed by id otherwise.
    function entriesOf(o) {
        if (!o || typeof o !== 'object') { return []; }
        return Object.keys(o).map(function (k) { return [k, o[k]]; }).filter(function (x) { return x[1] && typeof x[1] === 'object'; });
    }
    function liveRows(s) {
        var rows = [];
        entriesOf(s.parks).forEach(function (x) {
            var n = +x[1].h3 || 0;
            if (n > 0 && +x[0] > 0 && clean(x[1].name)) { rows.push({ kind: 'park', id: +x[0], name: x[1].name, n: n }); }
        });
        entriesOf(s.events).forEach(function (x) {
            var n = +x[1].h3 || 0;
            if (n > 0 && +x[0] > 0 && clean(x[1].name)) { rows.push({ kind: 'event', eid: +x[0], did: +x[1].calendar_detail_id || 0, name: x[1].name, n: n }); }
        });
        return rows.sort(function (a, b) { return (b.n - a.n) || (clean(a.name) < clean(b.name) ? -1 : 1); });
    }
    function liveText(r) { return leftRight(clean(r.name) + (r.kind === 'event' ? ' (EVENT)' : ''), String(r.n), W - 3); }
    // Live/recent: tuples [iso time (UTC), park id, event id, calendar detail id, first ever, self sign-in]; names come
    // from the parks and events maps beside them, else from the stats maps. Newest first, at most 50.
    function recentRows(d, stats) {
        var pk = {}, ev = {};
        [stats, d].forEach(function (src) {
            if (!src) { return; }
            entriesOf(src.parks).forEach(function (x) { if (x[1].name) { pk[x[0]] = x[1].name; } });
            entriesOf(src.events).forEach(function (x) { if (x[1].name) { ev[x[0]] = x[1].name; } });
        });
        var out = [];
        (Array.isArray(d.signins) ? d.signins : []).forEach(function (t) {
            if (!Array.isArray(t) || t.length < 5) { return; }
            var pid = +t[1] || 0, eid = +t[2] || 0, name = pid > 0 ? pk[pid] : (eid > 0 ? ev[eid] : '');
            if (!name || !clean(name)) { return; }
            out.push(pid > 0 ? { kind: 'park', id: pid, name: name, t: Date.parse(t[0]), first: +t[4] === 1 } : { kind: 'event', eid: eid, did: +t[3] || 0, name: name, t: Date.parse(t[0]), first: +t[4] === 1 });
        });
        return out.slice(0, 50);
    }
    function agoText(t, now) {
        if (!(t > 0)) { return ''; }
        var m = Math.max(0, Math.floor((now - t) / 60000));
        return m < 60 ? m + 'M AGO' : Math.floor(m / 60) + 'H AGO';
    }
    function recentText(r, now) { return leftRight(clean(r.name), agoText(r.t, now) + (r.first ? ' NEW' : ''), W - 3); }
    // WEATHER FOR PLAY, from WeatherService/GetPlayForDate: the website's default view, the parks playing today that carry a
    // weather warning, worst first.
    var WX_SHORT = { 'extreme heat': 'HEAT', 'frostbite risk': 'FROST', 'thunderstorms': 'STORM', 'severe wind': 'WIND', 'very high uv': 'UV' };
    function wxFlagged(list) {
        return list.filter(function (p) { return Array.isArray(p.badges) && p.badges.length && +p.park_id > 0 && clean(p.park_name); }).map(function (p) {
            var fc = p.forecast && p.forecast.hi_f !== null && p.forecast.hi_f !== undefined ? Math.round(+p.forecast.hi_f) : null;
            return { id: +p.park_id, name: p.park_name, score: +p._score || 0, hi: isNaN(fc) ? null : fc, badges: p.badges.map(function (b) { return WX_SHORT[String(b.label).toLowerCase()] || trunc(clean(b.label), 8); }) };
        }).sort(function (a, b) { return (b.score - a.score) || (clean(a.name) < clean(b.name) ? -1 : 1); });
    }
    function wxText(r) { return leftRight(clean(r.name), r.badges.slice(0, 2).join('/') + (r.hi === null ? '' : ' ' + r.hi + 'F'), W - 3); }

    /* ---- kingdom KNIGHTS AND MASTERS, from Report/PlayerAwards (scoped to the kingdom). Named public fields only. ---- */
    function kamRows(list) {
        return list.filter(function (a) { return (a.Peerage === 'Knight' || a.Peerage === 'Master') && +a.MundaneId > 0 && clean(a.Persona); }).map(function (a) {
            return { id: +a.MundaneId, persona: a.Persona, award: a.AwardName, date: a.Date, parkId: +a.ParkId || 0, park: a.ParkName, peerage: a.Peerage };
        }).sort(function (a, b) {
            if (a.peerage !== b.peerage) { return a.peerage === 'Knight' ? -1 : 1; }
            var x = clean(a.persona), y = clean(b.persona);
            return x < y ? -1 : (x > y ? 1 : (clean(a.award) < clean(b.award) ? -1 : 1));
        });
    }
    function kamText(r) { return leftRight(clean(r.persona), trunc(peerShort(r.award), 18), W - 3); }
    function kamGroups(rows) {
        var k = rows.filter(function (r) { return r.peerage === 'Knight'; }).length, m = rows.length - k;
        return rows.map(function (r) { return r.peerage === 'Knight' ? 'KNIGHTS (' + k + ')' : 'MASTERS (' + m + ')'; });
    }
    function kamDetailLines(r) {
        var out = wrap(clean(r.persona), W).concat(['']);
        out = out.concat(wrap(clean(r.award), W), ['', 'GIVEN ' + fmtDate(r.date)]);
        if (clean(r.park)) { out = out.concat(['', 'PARK'], wrap(clean(r.park), W)); }
        return out;
    }

    /* ---- player ABOUT, COMPANIES and MILESTONES ---- */
    // Text the player wrote: Markdown and HTML are flattened to words (images go, links shrink).
    function aboutText(s) {
        return htmlText(shortLinks(String(s === null || s === undefined ? '' : s).replace(/!\[[^\]]*\]\([^)]*\)/g, '')), W, 120);
    }
    function aboutLines(p) {
        var out = [], a = aboutText(p.aboutPersona), s = aboutText(p.aboutStory);
        if (a.length) { out = out.concat(wrap('ABOUT ' + clean(p.persona), W), [rep('-', W)], a); }
        if (s.length) {
            if (out.length) { out.push(''); }
            out = out.concat(wrap(clean(p.persona) + "'S STORY", W), [rep('-', W)], s);
        }
        return out;
    }
    // The service already applied the player's own switches and order (ApplyVisibility); keep the readable rows.
    function milestoneRows(list) {
        return list.filter(function (m) { return m && clean(m.description) && validDay(String(m.date || '')); });
    }
    function milestoneText(m) { return capRows(wrap(fmtDate(m.date) + ' ' + clean(m.description), W), 3, W).join('\n'); }

    /* ---- programs: one entry per screen. enter(P, arg, quick) draws, line(P, text) handles a committed line. ---- */
    var SCROLL = [
        '  .--.____________________________.--.',
        '  |: |                            | :|',
        '  |: |  ONLINE RECORD KEEPER 0.1  | :|',
        '  |: |  ------------------------  | :|',
        '  |: |   AMTGARD INTERNATIONAL    | :|',
        '  |: |                            | :|',
        '  |: |____________________________| :|',
        "  '--'                            '--'"
    ];
    var MENU = ['1. KINGDOMS', '2. PLAYERS', '3. MY PROFILE', '4. ORK GAMES', '5. SEARCH THE ORK', '6. THE ORK TODAY'];
    // Titles in GAMES order: the menu entries and the ORKEK title cards both read this.
    var GAME_TITLES = ['ORKEGON TRAIL', 'DUNGEORK', 'NUMBER MUNCHORKS', 'MORKMAZE', 'ASTERORKS', 'GEM CASTLORKS', 'FROGGORK', 'INVADORKS'];
    var GAME_NOTICE = '* ALL ORK GAMES ARE CONSIDERED AN INDEPENDENT PARODY WORK AND ARE NOT AFFILIATED WITH THE ORIGINAL CREATORS OR OWNERS OF THE GAMES BEING PARODIED. ALL TRADEMARKS BELONG TO THEIR RESPECTIVE OWNERS.';
    var GAME_MENU = ['ORK GAMES', rep('=', W), ''].concat(GAME_TITLES.map(function (t, i) { return (i + 1) + '. ' + t; }), [''], wrap(GAME_NOTICE, W), ['', '0. MAIN MENU']);
    function gameTitle(id) {
        var i = GAMES.indexOf(id);
        return i < 0 ? '' : GAME_TITLES[i];
    }

    /* ---- ORKEK title card: shown for SPLASH_MS before every game ----
       A 280x192 hi-res page, lit phosphor with dark ink: the game's title in a
       dithered frame, the striped crown and ORKEK wordmark, the Konsortium's
       name in a small lowercase face, and a copyright bar over a dithered band. */
    var SPLASH_MS = 4000;
    var ORKEK_LINE = 'online record keeper educational konsortium';
    var SMALL = {   // lowercase only, 8 rows: 2 ascender, 5 x-height, 1 descender
        ' ': ['..', '..', '..', '..', '..', '..', '..', '..'],
        'a': ['....', '....', '.##.', '...#', '.###', '#..#', '.###', '....'],
        'c': ['...', '...', '.##', '#..', '#..', '#..', '.##', '...'],
        'd': ['...#', '...#', '.###', '#..#', '#..#', '#..#', '.###', '....'],
        'e': ['....', '....', '.##.', '#..#', '####', '#...', '.###', '....'],
        'i': ['#', '.', '#', '#', '#', '#', '#', '.'],
        'k': ['#...', '#...', '#..#', '#.#.', '##..', '#.#.', '#..#', '....'],
        'l': ['#.', '#.', '#.', '#.', '#.', '#.', '.#', '..'],
        'm': ['.....', '.....', '####.', '#.#.#', '#.#.#', '#.#.#', '#.#.#', '.....'],
        'n': ['....', '....', '###.', '#..#', '#..#', '#..#', '#..#', '....'],
        'o': ['....', '....', '.##.', '#..#', '#..#', '#..#', '.##.', '....'],
        'p': ['....', '....', '###.', '#..#', '#..#', '###.', '#...', '#...'],
        'r': ['...', '...', '#.#', '##.', '#..', '#..', '#..', '...'],
        's': ['...', '...', '.##', '#..', '.#.', '..#', '##.', '...'],
        't': ['.#.', '.#.', '###', '.#.', '.#.', '.#.', '..#', '...'],
        'u': ['....', '....', '#..#', '#..#', '#..#', '#..#', '.###', '....']
    };
    function orkekSplash(title) {
        var w = 280, h = 192, bits = new Uint8Array(w * h), x, y;
        for (var i = 0; i < bits.length; i++) { bits[i] = 1; }
        function ink(px, py) { px = Math.round(px); if (px >= 0 && px < w && py >= 0 && py < h) { bits[py * w + px] = 0; } }
        function box(x0, y0, x1, y1) { for (var yy = y0; yy < y1; yy++) { for (var xx = x0; xx < x1; xx++) { ink(xx, yy); } } }
        function dither(x0, y0, x1, y1) { for (var yy = y0; yy < y1; yy++) { for (var xx = x0; xx < x1; xx++) { if ((xx + yy) % 2) { ink(xx, yy); } } } }
        // Big-font text from the character ROM: scale sx by sy, sheared right by `lean` px per row from the bottom.
        function rom(str, x0, y0, sx, sy, lean) {
            for (var k = 0; k < str.length; k++) {
                var rows = glyphRows(str.charAt(k)), gh = rows.length * sy;
                for (var r = 0; r < rows.length; r++) {
                    var row = rows[r], ox = row.length === 7 ? -1 : 0;
                    for (var c = 0; c < row.length; c++) {
                        if (row.charAt(c) !== '#') { continue; }
                        for (var yy = 0; yy < sy; yy++) {
                            var py = y0 + r * sy + yy, shift = lean ? (gh - (py - y0)) * lean : 0;
                            for (var xx = 0; xx < sx; xx++) { ink(x0 + k * 6 * sx + (ox + c) * sx + xx + shift, py); }
                        }
                    }
                }
            }
        }
        function romWidth(str, sx) { return str.length * 6 * sx - sx; }

        // Title frame: solid rule, dithered band, inner rule.
        box(26, 6, 254, 8); box(26, 70, 254, 72); box(26, 6, 28, 72); box(252, 6, 254, 72);
        dither(28, 8, 252, 12); dither(28, 66, 252, 70); dither(28, 8, 32, 70); dither(248, 8, 252, 70);
        box(32, 12, 248, 13); box(32, 65, 248, 66); box(32, 12, 33, 66); box(247, 12, 248, 66);
        var t = String(title || ''), sx2 = romWidth(t, 2) > 210 ? 1 : 2;
        rom(t, Math.round(140 - romWidth(t, sx2) / 2), sx2 === 2 ? 32 : 36, sx2, sx2, 0);

        // Wordmark: a striped crown, then ORKEK in leaning bold capitals.
        var LEAN = 0.25, cx0, cw = 52, top = 86, base = 121;
        var word = 'ORKEK', ww = romWidth(word, 5);
        cx0 = Math.round(140 - (cw + 14 + ww + 9) / 2);
        var peaks = [[0, top], [cw * 0.25, top + 20], [cw * 0.5, top], [cw * 0.75, top + 20], [cw, top]];
        for (y = top; y < base; y++) {
            if ((y - top) % 5 > 2) { continue; }
            for (x = 0; x <= cw; x++) {
                var seg = 0;
                while (seg < peaks.length - 2 && x > peaks[seg + 1][0]) { seg++; }
                var a = peaks[seg], b = peaks[seg + 1], edge = a[1] + (b[1] - a[1]) * (x - a[0]) / (b[0] - a[0]);
                if (y >= edge) { ink(cx0 + x + (base - y) * LEAN, y); }
            }
        }
        rom(word, cx0 + cw + 14, base - 35, 5, 5, LEAN);

        // The Konsortium's name, small and lowercase.
        var lw = 0, ch, g;
        for (i = 0; i < ORKEK_LINE.length; i++) { lw += SMALL[ORKEK_LINE.charAt(i)][0].length + 1; }
        x = Math.round(140 - (lw - 1) / 2);
        for (i = 0; i < ORKEK_LINE.length; i++) {
            g = SMALL[ORKEK_LINE.charAt(i)];
            for (y = 0; y < g.length; y++) {
                for (ch = 0; ch < g[y].length; ch++) { if (g[y].charAt(ch) === '#') { ink(x + ch, 128 + y); } }
            }
            x += g[0].length + 1;
        }

        // Copyright bar, then a dithered band to the bottom edge.
        box(0, 142, w, 144);
        rom('COPYRIGHT 1983', w - 10 - romWidth('COPYRIGHT 1983', 1), 147, 1, 1, 0);
        box(0, 157, w, 158);
        dither(0, 160, w, 186);
        return { w: w, h: h, bits: bits };
    }

    function badInput(P, t) {
        P.bad(/^[0-9]$/.test(t) ? '?ILLEGAL QUANTITY ERROR' : '?SYNTAX ERROR');
    }
    // e.timeout marks a read the FETCH_MS clock gave up on; anything else is a failed read.
    function ioFail(P, e) { P.bad('?I/O ERROR', 2, e && e.timeout ? 'THE ORK IS SLOW TODAY. TRY AGAIN.' : 'DISK READ FAILED. TRY AGAIN.'); }
    function openProfile(P, id, before) {
        P.load(function () { return P.data.profile(id); }, function () {
            if (before) { before(); }
            P.go('PROFILE', { id: id });
        }, function (e) { ioFail(P, e); });
    }
    // cfg.extras is a list of {key, label, go}, or a function (P, arg) returning one when the labels depend on state.
    // d.nineLast / cfg.nine: on the last page (or the only page) 9 does that instead of wrapping to the first page.
    function extrasOf(cfg, P, a) { return typeof cfg.extras === 'function' ? cfg.extras(P, a) : (cfg.extras || []); }
    function pagerProgram(name, cfg) {
        return {
            enter: function (P, arg, quick) {
                var d = cfg.build(P, arg);
                var v = pagerLines({ title: d.title, items: d.items, page: arg.page, selectable: cfg.selectable, back: d.back, empty: d.empty, extras: extrasOf(cfg, P, arg), perPage: cfg.perPage, notes: d.notes, nineLast: d.nineLast, groups: d.groups });
                arg.page = v.page;
                P.view = v;
                // Loader bars: about a second a page at most, none when motion is reduced.
                var tick = 0, cells = v.lines.join('').split('\x01').filter(function (x, k) { return k % 2 === 1; }).join('').length;
                if (cfg.loader && cells && !quick && !P.reduced) { tick = Math.min(25, Math.max(2, Math.floor(900 / cells))); }
                P.screen(v.lines, quick, undefined, tick);
                if (tick) { P.beep(1800, 15); }
                P.prompt('SELECT: ');
            },
            line: function (P, t) {
                var v = P.view, a = P.arg;
                if (t === '') { P.prompt('SELECT: '); return; }
                if (t === 'EXIT') { P.exit(); return; }
                if (t === '0') { cfg.back(P, a); return; }
                var ex = extrasOf(cfg, P, a);
                for (var xi = 0; xi < ex.length; xi++) {
                    if (t === ex[xi].key) { ex[xi].go(P, a); return; }
                }
                if (t === '9' && v.nine && v.page === v.pages - 1) { cfg.nine(P, a); return; }
                if (t === '9' && v.pages > 1) { P.go(name, extend(a, { page: (v.page + 1) % v.pages })); return; }
                if (cfg.selectable && /^[1-8]$/.test(t) && +t <= v.count) { cfg.pick(P, a, v.page * v.per + (+t - 1)); return; }
                badInput(P, t);
            }
        };
    }

    function openPark(P, id, ret) {
        P.load(function () { return P.data.park(id); }, function () {
            P.S.pkret = ret;
            P.go('PARK', { id: id });
        }, function (e) { if (e && e.notFound) { P.bad('?FILE NOT FOUND'); } else { ioFail(P, e); } });
    }
    // A persona on a park screen opens that player's profile, and 0 there comes back to the same page.
    function openPlayerFrom(P, mundaneId, ret) {
        P.load(function () { return P.data.player(mundaneId); }, function () {
            P.S.pfrom = 'RET';
            P.S.pret = ret;
            P.go('PPROFILE', { id: mundaneId });
        }, function (e) { if (e && e.notFound) { P.bad('?FILE NOT FOUND'); } else { ioFail(P, e); } });
    }
    function parkBack(P, a) { P.go('PARK', { id: a.id }); }
    // A park list screen: loaded by PARK's menu, drawn from P.data.parkPeek.
    function parkList(name, cfg) {
        cfg.back = cfg.back || parkBack;
        return pagerProgram(name, cfg);
    }

    function openEvent(P, eid, did, name, ret) {
        if (!(+eid > 0 && +did > 0)) { P.bad('?FILE NOT FOUND'); return; }
        P.load(function () { return P.data.event(eid, did, name); }, function () {
            P.S.evret = ret;
            P.go('EVENT', { did: +did });
        }, function (e) { if (e && e.notFound) { P.bad('?FILE NOT FOUND'); } else { ioFail(P, e); } });
    }
    // The month on screen is P.S.cal {id, y, m, pd}; pd is the page's Park Days filter (off, as on the page).
    function calRows(P) {
        var c = P.S.cal;
        return P.data.peekCal(c.id, c.y, c.m).filter(function (r) { return c.pd || r.kind !== 'P'; });
    }
    function calGo(P, id, y, m) {
        P.load(function () { return P.data.calMonth(id, y, m); }, function () {
            P.S.cal.y = y; P.S.cal.m = m;
            P.go('EVENTS', { id: id, page: 0 });
        }, function (e) { ioFail(P, e); });
    }
    function openCal(P, id, y, m, pd) {
        var now = P.data.now();
        y = y || now.y; m = m || now.m;
        P.load(function () { return P.data.calMonth(id, y, m); }, function () {
            P.S.cal = { id: id, y: y, m: m, pd: !!pd };
            P.go('EVENTS', { id: id, page: 0 });
        }, function (e) { ioFail(P, e); });
    }
    function openPast(P, scope, id, back) {
        P.load(function () { return P.data.past(scope, id); }, function () {
            P.go('PASTEV', { scope: scope, id: id, page: 0 });
        }, function (e) { ioFail(P, e); });
    }

    function openUnit(P, id, ret) {
        P.load(function () { return P.data.unit(id); }, function () {
            P.S.unret = ret;
            P.go('UNIT', { id: id });
        }, function (e) { if (e && e.notFound) { P.bad('?FILE NOT FOUND'); } else { ioFail(P, e); } });
    }
    // The kingdom's list as the website's default shows it, narrowed by the f key ('', 'Company' or 'Household').
    function unitRowsOf(P, a) {
        return (P.data.peekKingdomUnits(a.id) || []).filter(function (u) { return !a.f || u.type === a.f; });
    }

    var programs = {
        DOS: {
            enter: function (P) {
                P.cls();
                P.block(true);
                P.blank();
                P.line('ORKPLE II', { center: true });
                P.line('ORKDOS 3.3 SYSTEM MASTER', { center: true });
                P.blank();
                P.line('NOVEMBER 20, 1983', { center: true });
                P.blank();
                P.line('COPYRIGHT AMTGARD INTERNATIONAL 1983', { center: true });
                P.line('  TRY TYPING ORK  ', { center: true, inv: true });
                P.block(false);
                P.prompt(']');
            },
            line: function (P, t) {
                if (t === '') { P.prompt(']'); return; }
                if (t === 'EXIT') { P.exit(); return; }
                var snd = /^SOUND(?: (ON|OFF))?$/.exec(t);
                if (snd) {
                    // A bare SOUND reports the setting; SOUND ON / SOUND OFF changes it.
                    P.print((snd[1] ? P.sound(snd[1] === 'ON') : P.soundOn()) ? 'SOUND ON' : 'SOUND OFF');
                    P.prompt(']');
                    return;
                }
                if (t === 'ORK') {
                    P.print('LOADING ORK 0.1...');
                    P.wait(P.reduced ? 300 : 1000);
                    P.go('ORK_MENU');
                    return;
                }
                P.print('?SYNTAX ERROR');
                P.beep();
                P.prompt(']');
            }
        },
        ORK_MENU: {
            enter: function (P) {
                P.cls();
                P.block(true);
                P.blank();
                for (var i = 0; i < SCROLL.length; i++) { P.print(SCROLL[i], { nolog: true, at: 1 }); }
                P.say('ONLINE RECORD KEEPER 0.1');
                // The Era Phoenice date fills this row when it arrives; the menu never waits for it.
                P.dateLine();
                P.blank();
                for (var j = 0; j < MENU.length; j++) { P.print(MENU[j]); }
                P.block(false);
                P.blank();
                P.prompt('SELECT: ');
            },
            line: function (P, t) {
                if (t === '') { P.prompt('SELECT: '); return; }
                if (t === 'EXIT') { P.exit(); return; }
                if (t === '1') {
                    P.load(function () { return P.data.kingdoms(); }, function () { P.go('KINGDOMS', { page: 0 }); }, function (e) { ioFail(P, e); });
                } else if (t === '2') {
                    P.go('PSEARCH');
                } else if (t === '4') {
                    P.go('ORK_GAMES');
                } else if (t === '5') {
                    P.go('OSEARCH');
                } else if (t === '6') {
                    P.go('TODAY');
                } else if (t === '3') {
                    if (!P.data.user) { P.bad('?NOT LOGGED IN', 1, 'LOG IN TO THE ORK FIRST.'); return; }
                    P.load(function () { return P.data.player(P.data.user); }, function () {
                        P.S.pfrom = 'MENU';
                        P.go('PPROFILE', { id: P.data.user });
                    }, function (e) { if (e && e.notFound) { P.bad('?FILE NOT FOUND'); } else { ioFail(P, e); } });
                } else {
                    badInput(P, t);
                }
            }
        },
        ORK_GAMES: {
            enter: function (P, arg, quick) {
                P.screen(GAME_MENU, quick);
                P.prompt('SELECT: ');
                P.preloadDisk();
            },
            line: function (P, t) {
                if (t === '') { P.prompt('SELECT: '); return; }
                if (t === 'EXIT') { P.exit(); return; }
                if (t === '0') { P.go('ORK_MENU'); return; }
                if (/^[1-8]$/.test(t)) { P.playGame(GAMES[+t - 1]); return; }
                badInput(P, t);
            }
        },
        // A running game: its line prompts come back through gameLine.
        GAME: {
            enter: function () {},
            line: function (P, t) { P.gameLine(t); }
        },
        KINGDOMS: pagerProgram('KINGDOMS', {
            selectable: true,
            build: function (P) {
                return { title: 'KINGDOMS', back: 'MAIN MENU', items: P.data.kingdomList().map(function (k) { return k.name; }) };
            },
            back: function (P) { P.go('ORK_MENU'); },
            pick: function (P, a, idx) {
                var k = P.data.kingdomList()[idx];
                openProfile(P, k.id, function () { P.S.stack = [{ name: 'KINGDOMS', arg: { page: a.page } }]; });
            }
        }),
        PROFILE: {
            enter: function (P, arg, quick) {
                var p = P.data.peek(arg.id);
                var top = P.S.stack[P.S.stack.length - 1], back = !top || top.name === 'KINGDOMS' ? 'KINGDOM LIST' : 'BACK';
                var parts = profileParts(p, back);
                P.screen(parts.lines, quick, { rows: parts.head, log: parts.log, art: p.art || shieldBitmap() });
                // Counts still out (or failed before) are read now and written into their rows as each arrives.
                if (p.parkCount === null || p.eventCount === null || p.avgWeek === null) {
                    P.patch(P.data.profileStats(arg.id), function () { return profileParts(P.data.peek(arg.id), back).stat; });
                }
                P.prompt('SELECT: ');
            },
            line: function (P, t) {
                var a = P.arg, p = P.data.peek(a.id);
                if (t === '') { P.prompt('SELECT: '); return; }
                if (t === 'EXIT') { P.exit(); return; }
                if (t === '1') {
                    P.load(function () { return P.data.kingdomParks(a.id); }, function () { P.go('PARKS', { id: a.id, page: 0 }); }, function (e) { ioFail(P, e); });
                    return;
                }
                if (t === '2') { openCal(P, a.id, null, null, false); return; }
                if (t === '3' && p.prinz && p.prinz.length) { P.go('PRINCES', { id: a.id, page: 0 }); return; }
                if (t === (p.prinz && p.prinz.length ? '4' : '3')) { openPast(P, 'Kingdom', a.id, { name: 'PROFILE', arg: { id: a.id } }); return; }
                if (t === (p.prinz && p.prinz.length ? '5' : '4')) {
                    P.load(function () { return P.data.kingdomUnits(a.id); }, function () { P.go('KUNITS', { id: a.id, page: 0, f: '' }); }, function (e) { ioFail(P, e); });
                    return;
                }
                if (t === (p.prinz && p.prinz.length ? '6' : '5')) {
                    P.load(function () { return P.data.kingdomKam(a.id); }, function () { P.go('KAM', { id: a.id, page: 0 }); }, function (e) { ioFail(P, e); });
                    return;
                }
                if (t === '0') {
                    var top = P.S.stack.pop();
                    P.go(top ? top.name : 'KINGDOMS', top ? top.arg : { page: 0 });
                    return;
                }
                badInput(P, t);
            }
        },
        PARKS: pagerProgram('PARKS', {
            selectable: true,
            build: function (P, a) {
                var p = P.data.peek(a.id);
                return {
                    title: (p.abbr ? clean(p.abbr) + ' ' : '') + 'PARKS', back: 'BACK',
                    items: (p.parks || []).map(function (k) {
                        return leftRight(clean(k.ParkName) + (clean(k.Title) ? ' (' + clean(k.Title) + ')' : ''), p.parkAvg(k.ParkId), W - 3);
                    }),
                    empty: p.parks ? 'NOTHING ON THIS DISK.' : '(UNAVAILABLE)'
                };
            },
            back: function (P, a) { P.go('PROFILE', { id: a.id }); },
            pick: function (P, a, idx) {
                var k = (P.data.peek(a.id).parks || [])[idx];
                if (!k) { return; }
                openPark(P, +k.ParkId, { name: 'PARKS', arg: { id: a.id, page: a.page }, label: 'PARK LIST' });
            }
        }),
        // KINGDOM CALENDAR: one month at a time, dated rows. 9 on the last page is the next month.
        EVENTS: pagerProgram('EVENTS', {
            selectable: true,
            perPage: 6,
            build: function (P, a) {
                var p = P.data.peek(a.id), c = P.S.cal, rows = calRows(P);
                var nx = c.m === 12 ? [c.y + 1, 1] : [c.y, c.m + 1];
                return {
                    title: (p.abbr ? clean(p.abbr) + ' ' : '') + 'EVENTS ' + MONTHS[c.m - 1] + ' ' + c.y, back: 'BACK',
                    items: rows.map(calRowText),
                    empty: c.pd ? 'NOTHING THIS MONTH.' : 'NOTHING THIS MONTH. PARK DAYS ARE HIDDEN.',
                    nineLast: 'NEXT MONTH (' + MONTHS[nx[1] - 1] + ')'
                };
            },
            extras: function (P) {
                var c = P.S.cal;
                return [
                    { key: '7', label: 'PREVIOUS MONTH', go: function (P2, a) { calGo(P2, a.id, c.m === 1 ? c.y - 1 : c.y, c.m === 1 ? 12 : c.m - 1); } },
                    { key: '8', label: 'PARK DAYS: ' + (c.pd ? 'ON' : 'OFF'), go: function (P2, a) { c.pd = !c.pd; P2.go('EVENTS', { id: a.id, page: 0 }); } }
                ];
            },
            nine: function (P, a) { var c = P.S.cal; calGo(P, a.id, c.m === 12 ? c.y + 1 : c.y, c.m === 12 ? 1 : c.m + 1); },
            back: function (P, a) { P.go('PROFILE', { id: a.id }); },
            pick: function (P, a, idx) {
                var r = calRows(P)[idx];
                if (!r) { return; }
                var ret = { name: 'EVENTS', arg: { id: a.id, page: a.page }, label: 'CALENDAR' };
                if (r.kind === 'E') { openEvent(P, r.eid, r.did, r.name, ret); return; }
                if (r.kind === 'P') { if (r.parkId > 0) { openPark(P, r.parkId, ret); } else { P.bad('?FILE NOT FOUND'); } return; }
                P.S.citem = r;
                P.go('CITEM', { id: a.id, from: a.page });
            }
        }),
        CITEM: pagerProgram('CITEM', {
            selectable: false,
            perPage: 16,
            build: function (P) { return { title: 'CALENDAR ITEM', back: 'CALENDAR', items: calItemLines(P.S.citem), empty: 'NOTHING ON THIS DISK.' }; },
            back: function (P, a) { P.go('EVENTS', { id: a.id, page: a.from || 0 }); }
        }),
        // EVENT: arms and facts, then DESCRIPTION, RSVP COUNTS, SCHEDULE and WHO CAME.
        EVENT: {
            enter: function (P, arg, quick) {
                var e = P.data.peekEvent(arg.did), r = P.S.evret;
                var parts = eventParts(e, r ? r.label : 'MAIN MENU');
                P.screen(parts.lines, quick, { rows: parts.head, log: parts.log, art: e.art || shieldBitmap() });
                P.prompt('SELECT: ');
            },
            line: function (P, t) {
                var a = P.arg;
                if (t === '') { P.prompt('SELECT: '); return; }
                if (t === 'EXIT') { P.exit(); return; }
                if (t === '0') {
                    var r = P.S.evret;
                    if (r) { P.go(r.name, r.arg); } else { P.go('ORK_MENU'); }
                    return;
                }
                if (/^[1-4]$/.test(t)) {
                    var item = EVENT_MENU[+t - 1];
                    P.load(function () { return P.data.eventList(a.did, item[1]); }, function () { P.go(item[2], { did: a.did, page: 0 }); }, function (e) { ioFail(P, e); });
                    return;
                }
                badInput(P, t);
            }
        },
        EVDESC: pagerProgram('EVDESC', {
            selectable: false,
            perPage: 16,
            build: function (P, a) { return { title: 'DESCRIPTION', back: 'BACK', items: P.data.peekEvent(a.did).lists.desc, empty: 'NO DESCRIPTION PROVIDED.' }; },
            back: function (P, a) { P.go('EVENT', { did: a.did }); }
        }),
        EVRSVP: pagerProgram('EVRSVP', {
            selectable: false,
            build: function (P, a) {
                var c = P.data.peekEvent(a.did).lists.rsvp;
                return {
                    title: 'RSVP COUNTS', back: 'BACK', notes: ['', 'COUNTS ONLY.'],
                    items: [padTo('GOING', 12) + c.going, padTo('INTERESTED', 12) + c.interested, padTo('TOTAL', 12) + c.total]
                };
            },
            back: function (P, a) { P.go('EVENT', { did: a.did }); }
        }),
        EVSCHED: pagerProgram('EVSCHED', {
            selectable: false,
            perPage: 1,
            build: function (P, a) { return { title: 'SCHEDULE', back: 'BACK', items: P.data.peekEvent(a.did).lists.sched, empty: 'NO SCHEDULE POSTED.' }; },
            back: function (P, a) { P.go('EVENT', { did: a.did }); }
        }),
        EVWHO: pagerProgram('EVWHO', {
            selectable: true,
            build: function (P, a) {
                var rows = P.data.peekEvent(a.did).lists.who;
                return {
                    title: 'WHO CAME (' + rows.length + ')', back: 'BACK', empty: 'NO ATTENDANCE ON RECORD.',
                    items: rows.map(function (r) { return leftRight(clean(r.Persona) || '(UNKNOWN)', clean(r.ClassName), W - 3); })
                };
            },
            back: function (P, a) { P.go('EVENT', { did: a.did }); },
            pick: function (P, a, idx) {
                var r = P.data.peekEvent(a.did).lists.who[idx];
                if (!r || !(+r.MundaneId > 0)) { P.bad('?FILE NOT FOUND'); return; }
                openPlayerFrom(P, +r.MundaneId, { name: 'EVWHO', arg: { did: a.did, page: a.page } });
            }
        }),
        // PAST EVENTS of a kingdom or park: newest first, right column the sign-in count.
        PASTEV: pagerProgram('PASTEV', {
            selectable: true,
            build: function (P, a) {
                var rows = P.data.peekPast(a.scope, a.id);
                return {
                    title: 'PAST EVENTS', back: 'BACK', empty: 'NO PAST EVENTS ON RECORD.', notes: rows.length ? ['', 'RIGHT: PLAYERS SIGNED IN'] : [],
                    items: rows.map(function (r) { return leftRight(fmtDate(r.EventStart) + ' ' + clean(r.EventName), String(r.AttendanceCount), W - 3); })
                };
            },
            back: function (P, a) { if (a.scope === 'Park') { P.go('PKEVENTS', { id: a.id, page: 0 }); } else { P.go('PROFILE', { id: a.id }); } },
            pick: function (P, a, idx) {
                var r = P.data.peekPast(a.scope, a.id)[idx];
                if (!r) { return; }
                openEvent(P, r.EventId, r.DetailId, r.EventName, { name: 'PASTEV', arg: { scope: a.scope, id: a.id, page: a.page }, label: 'PAST EVENTS' });
            }
        }),
        // COMPANIES AND HOUSEHOLDS of a kingdom, largest first, as the website's default unit list.
        KUNITS: pagerProgram('KUNITS', {
            selectable: true,
            perPage: 7,   // key 8 is the filter
            build: function (P, a) {
                var all = P.data.peekKingdomUnits(a.id) || [], rows = unitRowsOf(P, a);
                var notes = rows.length ? ['', UNIT_NOTE] : [];
                if (all.length >= UNIT_CAP) { notes.push('LARGEST ' + UNIT_CAP + ' ONLY. SEARCH FOR OTHERS.'); }
                return {
                    title: a.f === 'Company' ? 'COMPANIES' : (a.f === 'Household' ? 'HOUSEHOLDS' : 'COMPANIES AND HOUSEHOLDS'), back: 'BACK',
                    items: rows.map(unitRowText), notes: notes,
                    empty: all.length ? 'NONE OF THAT TYPE HERE.' : 'NO COMPANIES OR HOUSEHOLDS HERE.'
                };
            },
            extras: function (P, a) {
                if (!(P.data.peekKingdomUnits(a.id) || []).length) { return []; }
                var next = !a.f ? 'Company' : (a.f === 'Company' ? 'Household' : '');
                return [{ key: '8', label: next === 'Company' ? 'COMPANIES ONLY' : (next === 'Household' ? 'HOUSEHOLDS ONLY' : 'ALL UNITS'), go: function (P2, a2) { P2.go('KUNITS', { id: a2.id, page: 0, f: next }); } }];
            },
            back: function (P, a) { P.go('PROFILE', { id: a.id }); },
            pick: function (P, a, idx) {
                var u = unitRowsOf(P, a)[idx];
                if (!u) { return; }
                openUnit(P, u.id, { name: 'KUNITS', arg: { id: a.id, page: a.page, f: a.f }, label: 'UNIT LIST' });
            }
        }),
        // UNIT: arms and facts, then ABOUT and HISTORY.
        UNIT: {
            enter: function (P, arg, quick) {
                var u = P.data.peekUnit(arg.id), r = P.S.unret;
                var parts = unitParts(u, r ? r.label : 'MAIN MENU');
                P.screen(parts.lines, quick, { rows: parts.head, log: parts.log, art: u.art || shieldBitmap() });
                P.prompt('SELECT: ');
            },
            line: function (P, t) {
                if (t === '') { P.prompt('SELECT: '); return; }
                if (t === 'EXIT') { P.exit(); return; }
                if (t === '0') {
                    var r = P.S.unret;
                    if (r) { P.go(r.name, r.arg); } else { P.go('ORK_MENU'); }
                    return;
                }
                if (/^[1-2]$/.test(t)) { P.go(UNIT_MENU[+t - 1][1], { id: P.arg.id, page: 0 }); return; }
                badInput(P, t);
            }
        },
        UNABOUT: pagerProgram('UNABOUT', {
            selectable: false,
            perPage: 16,
            build: function (P, a) { return { title: 'ABOUT', back: 'BACK', items: unitAboutLines(P.data.peekUnit(a.id)), empty: 'NO DESCRIPTION PROVIDED.' }; },
            back: function (P, a) { P.go('UNIT', { id: a.id }); }
        }),
        UNHIST: pagerProgram('UNHIST', {
            selectable: false,
            perPage: 16,
            build: function (P, a) { return { title: 'HISTORY', back: 'BACK', items: unitTextLines(P.data.peekUnit(a.id).hist), empty: 'NO HISTORY PROVIDED.' }; },
            back: function (P, a) { P.go('UNIT', { id: a.id }); }
        }),
        // SEARCH THE ORK: players, parks, kingdoms, companies and households in one go.
        OSEARCH: {
            prompt: 'SEARCH: ',
            enter: function (P, arg, quick) {
                P.screen(OSEARCH_LINES, quick);
                P.prompt('SEARCH: ');
            },
            line: function (P, t) {
                if (t === '') { P.prompt('SEARCH: '); return; }
                if (t === 'EXIT') { P.exit(); return; }
                if (t === '0') { P.go('ORK_MENU'); return; }
                var problem = searchProblem(t);
                if (problem) { P.bad('?MISSING NAME', 1, problem); return; }
                P.load(function () { return P.data.searchOrk(t); }, function (rows) {
                    if (!rows.length) { P.print('NOTHING FOUND.'); P.prompt('SEARCH: '); return; }
                    P.S.osearch = { term: t, rows: rows };
                    P.go('OSRESULTS', { page: 0 });
                }, function (e) { ioFail(P, e); });
            }
        },
        OSRESULTS: pagerProgram('OSRESULTS', {
            selectable: true,
            build: function (P) {
                var os = P.S.osearch;
                return { title: 'FOUND ' + os.term, back: 'NEW SEARCH', items: os.rows.map(osRowText), groups: osGroups(os.rows) };
            },
            back: function (P) { P.go('OSEARCH'); },
            pick: function (P, a, idx) {
                var r = P.S.osearch.rows[idx];
                if (!r) { return; }
                var ret = { name: 'OSRESULTS', arg: { page: a.page }, label: 'RESULTS' };
                if (r.kind === 'player') { openPlayerFrom(P, r.id, ret); }
                else if (r.kind === 'park') { openPark(P, r.id, ret); }
                else if (r.kind === 'kingdom') { openProfile(P, r.id, function () { P.S.stack = [{ name: 'OSRESULTS', arg: { page: a.page } }]; }); }
                else { openUnit(P, r.id, ret); }
            }
        }),
        PSEARCH: {
            prompt: 'NAME: ',
            enter: function (P, arg, quick) {
                P.screen(SEARCH_LINES, quick);
                P.prompt('NAME: ');
            },
            line: function (P, t) {
                if (t === '') { P.prompt('NAME: '); return; }
                if (t === 'EXIT') { P.exit(); return; }
                if (t === '0') { P.go('ORK_MENU'); return; }
                var problem = searchProblem(t);
                if (problem) { P.bad('?MISSING NAME', 1, problem); return; }
                P.load(function () { return P.data.search(t); }, function (list) {
                    if (!list.length) { P.print('NO SUCH PLAYER.'); P.prompt('NAME: '); return; }
                    P.S.psearch = { term: t, list: list, page: 0 };
                    P.go('PRESULTS', { page: 0 });
                }, function (e) { ioFail(P, e); });
            }
        },
        PRESULTS: pagerProgram('PRESULTS', {
            selectable: true,
            build: function (P, a) {
                var ps = P.S.psearch;
                ps.page = a.page || 0;
                return {
                    title: 'MATCHES FOR ' + ps.term, back: 'NEW SEARCH',
                    items: ps.list.map(function (r) {
                        if (r.kind === 'park') { return leftRight(r.name, 'PARK' + (r.abbr ? ' ' + r.abbr : ''), W - 3); }
                        var tag = (r.abbr ? '(' + r.abbr + ')' : '') + (r.active ? '' : ' (INACTIVE)');
                        return leftRight(r.name, tag.replace(/^ /, ''), W - 3);
                    })
                };
            },
            back: function (P) { P.go('PSEARCH'); },
            pick: function (P, a, idx) {
                var r = P.S.psearch.list[idx];
                P.S.psearch.page = a.page;
                if (r.kind === 'park') { openPark(P, r.id, { name: 'PRESULTS', arg: { page: a.page }, label: 'RESULTS' }); return; }
                P.load(function () { return P.data.player(r.id); }, function () { P.S.pfrom = 'RESULTS'; P.go('PPROFILE', { id: r.id }); }, function (e) {
                    if (e && e.notFound) { P.bad('?FILE NOT FOUND'); } else { ioFail(P, e); }
                });
            }
        }),
        PPROFILE: {
            enter: function (P, arg, quick) {
                P.screen(playerLines(P.data.peekPlayer(arg.id), P.S.pfrom === 'MENU' ? 'MAIN MENU' : 'BACK', arg.id === P.data.user), quick);
                P.prompt('SELECT: ');
            },
            line: function (P, t) {
                var a = P.arg;
                if (t === '') { P.prompt('SELECT: '); return; }
                if (t === 'EXIT') { P.exit(); return; }
                if (t === '0') {
                    if (P.S.pfrom === 'MENU') { P.go('ORK_MENU'); }
                        else if (P.S.pfrom === 'RET' && P.S.pret) { P.go(P.S.pret.name, P.S.pret.arg); }
                        else { P.go('PRESULTS', { page: P.S.psearch ? P.S.psearch.page : 0 }); }
                    return;
                }
                if (t === '5' && a.id === P.data.user) {
                    P.load(function () { return P.data.myAmtgard(a.id); }, function (my) { P.go('PMYA', { id: a.id, my: my }); }, function (e) { ioFail(P, e); });
                    return;
                }
                if (t === '2') {
                    P.load(function () { return P.data.awardsEntry(a.id); }, function (hasLadders) {
                        P.go(hasLadders ? 'PLADDERS' : 'PAWARDS', { id: a.id, page: 0 });
                    }, function (e) { ioFail(P, e); });
                    return;
                }
                var more = PLAYER_MORE[+t - (a.id === P.data.user ? 6 : 5)];
                if (/^[5-8]$/.test(t) && more) {
                    if (more[1] === 'miles') {
                        P.load(function () { return P.data.playerList(a.id, 'milestones'); }, function () { P.go('PMILES', { id: a.id, page: 0 }); }, function (e) { ioFail(P, e); });
                    } else {
                        P.go(more[1] === 'about' ? 'PABOUT' : 'PLCOMP', { id: a.id, page: 0 });
                    }
                    return;
                }
                var kinds = { '1': 'classes', '2': 'awards', '3': 'attendance', '4': 'titles' };
                if (kinds[t]) {
                    var name = { classes: 'PCLASSES', awards: 'PAWARDS', attendance: 'PATTEND', titles: 'PTITLES' }[kinds[t]];
                    P.load(function () { return P.data.playerList(a.id, kinds[t]); }, function () { P.go(name, { id: a.id, page: 0 }); }, function (e) { ioFail(P, e); });
                    return;
                }
                badInput(P, t);
            }
        },
        // A light taste of the real My Amtgard tab: RSVPs, recent classes, latest award. No notifications.
        PMYA: {
            enter: function (P, arg, quick) {
                P.screen(myLines(arg.my || P.data.peekPlayer(arg.id).my), quick);
                P.prompt('SELECT: ');
            },
            line: function (P, t) {
                if (t === '') { P.prompt('SELECT: '); return; }
                if (t === 'EXIT') { P.exit(); return; }
                if (t === '0') { P.go('PPROFILE', { id: P.arg.id }); return; }
                badInput(P, t);
            }
        },
        PCLASSES: pagerProgram('PCLASSES', {
            selectable: false,
            build: function (P, a) {
                return {
                    title: 'CLASSES', back: 'BACK',
                    items: P.data.peekPlayer(a.id).classes.map(function (c) { return classRow(c, 11); })
                };
            },
            back: function (P, a) { P.go('PPROFILE', { id: a.id }); }
        }),
        PLADDERS: pagerProgram('PLADDERS', {
            selectable: false,
            loader: true,
            extras: [{ key: '1', label: 'ALL AWARDS', go: function (P, a) { P.go('PAWARDS', { id: a.id, page: 0 }); } }],
            build: function (P, a) { return { title: 'LADDERS', back: 'BACK', items: P.data.peekPlayer(a.id).ladders.map(ladderRow) }; },
            back: function (P, a) { P.go('PPROFILE', { id: a.id }); }
        }),
        PAWARDS: pagerProgram('PAWARDS', {
            selectable: false,
            build: function (P, a) {
                var pl = P.data.peekPlayer(a.id);
                return { title: 'AWARDS', back: pl.ladders && pl.ladders.length ? 'LADDERS' : 'BACK', items: awardRows(pl.awards) };
            },
            back: function (P, a) {
                var pl = P.data.peekPlayer(a.id);
                P.go(pl.ladders && pl.ladders.length ? 'PLADDERS' : 'PPROFILE', { id: a.id });
            }
        }),
        PTITLES: pagerProgram('PTITLES', {
            selectable: false,
            build: function (P, a) { return { title: 'TITLES', back: 'BACK', items: awardRows(P.data.peekPlayer(a.id).titles) }; },
            back: function (P, a) { P.go('PPROFILE', { id: a.id }); }
        }),
        PATTEND: pagerProgram('PATTEND', {
            selectable: false,
            build: function (P, a) {
                return {
                    title: 'ATTENDANCE', back: 'BACK',
                    items: P.data.peekPlayer(a.id).attendance.map(function (r) {
                        return trunc(fmtDate(r.Date) + ' ' + padTo(clean(r.ClassName), 9) + ' ' + clean(r.EventName || r.ParkName), W);
                    })
                };
            },
            back: function (P, a) { P.go('PPROFILE', { id: a.id }); }
        }),
        /* ---- THE ORK TODAY ---- */
        TODAY: {
            enter: function (P, arg, quick) {
                P.screen(todayLines(P.data.epPeek()), quick);
                P.prompt('SELECT: ');
            },
            line: function (P, t) {
                if (t === '') { P.prompt('SELECT: '); return; }
                if (t === 'EXIT') { P.exit(); return; }
                if (t === '0') { P.go('ORK_MENU'); return; }
                if (/^[1-5]$/.test(t)) {
                    var item = TODAY_MENU[+t - 1], kid = 0;
                    P.load(function () { return P.data.today(item[1], kid); }, function () { P.go(item[1], { page: 0, kid: kid }); }, function (e) { ioFail(P, e); });
                    return;
                }
                badInput(P, t);
            }
        },
        TSTAND: pagerProgram('TSTAND', {
            selectable: true,
            build: function (P) {
                return { title: 'KINGDOM STANDINGS', back: 'BACK', empty: 'NO KINGDOMS ON RECORD.', notes: ['', 'RIGHT: PARKS, PLAYERS A WEEK', 'AVERAGE OVER THE LAST SIX MONTHS'], items: P.data.peekToday('TSTAND').map(standingText) };
            },
            back: function (P) { P.go('TODAY'); },
            pick: function (P, a, idx) {
                var k = P.data.peekToday('TSTAND')[idx];
                if (k) { openProfile(P, k.id, function () { P.S.stack = [{ name: 'TSTAND', arg: { page: a.page } }]; }); }
            }
        }),
        TPARKS: pagerProgram('TPARKS', {
            selectable: true,
            build: function (P) {
                var d = P.data.peekToday('TPARKS');
                return {
                    title: 'TOP PARKS', back: 'BACK', empty: 'NO ATTENDANCE ON RECORD.',
                    notes: d.rows.length ? ['', 'RIGHT: RANK, PLAYERS A WEEK', fmtDate(d.start) + ' TO ' + fmtDate(d.end)] : [],
                    items: d.rows.map(function (r, i) { return topParkText(r, i + 1); })
                };
            },
            back: function (P) { P.go('TODAY'); },
            pick: function (P, a, idx) {
                var r = P.data.peekToday('TPARKS').rows[idx];
                if (r) { openPark(P, r.id, { name: 'TPARKS', arg: { page: a.page }, label: 'TOP PARKS' }); }
            }
        }),
        // THIS WEEK: the Recap page's sections, for all of Amtgard or one kingdom.
        TWEEK: pagerProgram('TWEEK', {
            selectable: true,
            perPage: 6,   // keys 7 and 8 change the scope
            build: function (P, a) {
                var w = P.data.peekWeek(a.kid);
                return {
                    title: 'THIS WEEK', back: 'BACK', empty: w.recap.empty ? 'NO RECAP HAS BEEN COMPUTED YET.' : 'NOTHING TO REPORT THIS WEEK.',
                    notes: ['', w.recap.empty ? '' : 'WEEK OF ' + fmtDate(w.recap.WeekStart) + ' TO ' + fmtDate(w.recap.WeekEnd), a.kid ? 'KINGDOM: ' + (kingdomShort(P.data.kingdomName(a.kid)) || '?') : 'ALL OF AMTGARD'].filter(function (x, i) { return i === 0 || x; }),
                    items: w.rows.map(function (r) { return r.text; }), groups: w.groups
                };
            },
            extras: function (P, a) {
                var list = [{ key: '8', label: 'A KINGDOM', go: function (P2, a2) { P2.load(function () { return P2.data.kingdoms(); }, function () { P2.go('TWKING', { kid: a2.kid, page: 0 }); }, function (e2) { ioFail(P2, e2); }); } }];
                if (a.kid) { list.unshift({ key: '7', label: 'ALL OF AMTGARD', go: function (P2) { P2.load(function () { return P2.data.today('TWEEK', 0); }, function () { P2.go('TWEEK', { kid: 0, page: 0 }); }, function (e2) { ioFail(P2, e2); }); } }); }
                return list;
            },
            back: function (P) { P.go('TODAY'); },
            pick: function (P, a, idx) {
                var r = P.data.peekWeek(a.kid).rows[idx];
                if (!r) { return; }
                var ret = { name: 'TWEEK', arg: { kid: a.kid, page: a.page }, label: 'THIS WEEK' };
                if (r.kind === 'player') { openPlayerFrom(P, r.id, ret); }
                else if (r.kind === 'park') { openPark(P, r.id, ret); }
                else { openEvent(P, r.eid, r.did, r.name, ret); }
            }
        }),
        TWKING: pagerProgram('TWKING', {
            selectable: true,
            build: function (P) { return { title: 'WHICH KINGDOM', back: 'BACK', items: P.data.kingdomList().map(function (k) { return k.name; }), empty: 'NO KINGDOMS ON RECORD.' }; },
            back: function (P, a) { P.go('TWEEK', { kid: a.kid, page: 0 }); },
            pick: function (P, a, idx) {
                var k = P.data.kingdomList()[idx];
                if (k) { P.load(function () { return P.data.today('TWEEK', k.id); }, function () { P.go('TWEEK', { kid: k.id, page: 0 }); }, function (e) { ioFail(P, e); }); }
            }
        }),
        TLIVE: pagerProgram('TLIVE', {
            selectable: true,
            perPage: 7,   // key 8 is the sign-in ticker
            build: function (P) {
                var l = P.data.peekLive();
                return {
                    title: 'ON THE FIELD NOW', back: 'BACK', empty: 'NOBODY HAS SIGNED IN DURING THE PAST 3 HOURS.',
                    notes: ['', 'RIGHT: SIGN-INS IN THE PAST 3 HOURS', 'ALL SIGN-INS PAST 3 HOURS: ' + l.active3h],
                    items: l.rows.map(liveText)
                };
            },
            extras: function (P) { return P.data.peekLive().recent ? [{ key: '8', label: 'LATEST SIGN-INS', go: function (P2) { P2.go('TLIVER', { page: 0 }); } }] : []; },
            back: function (P) { P.go('TODAY'); },
            pick: function (P, a, idx) {
                var r = P.data.peekLive().rows[idx];
                if (!r) { return; }
                var ret = { name: 'TLIVE', arg: { page: a.page }, label: 'ON THE FIELD NOW' };
                if (r.kind === 'park') { openPark(P, r.id, ret); } else { openEvent(P, r.eid, r.did, r.name, ret); }
            }
        }),
        TLIVER: pagerProgram('TLIVER', {
            selectable: true,
            build: function (P) {
                var l = P.data.peekLive(), now = Date.now() + P.data.skew();
                return { title: 'LATEST SIGN-INS', back: 'BACK', empty: 'NO SIGN-INS IN THE LAST 24 HOURS.', notes: l.recent && l.recent.length ? ['', 'NEW = THEIR FIRST SIGN-IN EVER'] : [], items: (l.recent || []).map(function (r) { return recentText(r, now); }) };
            },
            back: function (P) { P.go('TLIVE', { page: 0 }); },
            pick: function (P, a, idx) {
                var r = (P.data.peekLive().recent || [])[idx];
                if (!r) { return; }
                var ret = { name: 'TLIVER', arg: { page: a.page }, label: 'LATEST SIGN-INS' };
                if (r.kind === 'park') { openPark(P, r.id, ret); } else { openEvent(P, r.eid, r.did, r.name, ret); }
            }
        }),
        TWX: pagerProgram('TWX', {
            selectable: true,
            perPage: 6,
            build: function (P) {
                var w = P.data.peekToday('TWX');
                return {
                    title: 'WEATHER FOR PLAY', back: 'BACK',
                    empty: w.total ? 'CONDITIONS LOOK FAVORABLE AT ALL ' + w.total + ' PARKS PLAYING TODAY. NO WARNINGS.' : 'NO PARKS HAVE IN-PERSON PLAY TODAY.',
                    notes: w.rows.length || w.total ? ['', 'PARKS PLAYING TODAY: ' + w.total, 'WITH A WARNING: ' + w.rows.length].concat(w.rows.length ? ['RIGHT: WARNING, HIGH IN F'] : []) : [],
                    items: w.rows.map(wxText)
                };
            },
            back: function (P) { P.go('TODAY'); },
            pick: function (P, a, idx) {
                var r = P.data.peekToday('TWX').rows[idx];
                if (r) { openPark(P, r.id, { name: 'TWX', arg: { page: a.page }, label: 'WEATHER' }); }
            }
        }),
        // KNIGHTS AND MASTERS of a kingdom, as its Reports tab lists them; a row opens the belt, then the player.
        KAM: pagerProgram('KAM', {
            selectable: true,
            build: function (P, a) {
                var rows = P.data.peekKam(a.id);
                return { title: 'KNIGHTS AND MASTERS', back: 'BACK', empty: 'NO KNIGHTS OR MASTERS ON RECORD.', items: rows.map(kamText), groups: kamGroups(rows) };
            },
            back: function (P, a) { P.go('PROFILE', { id: a.id }); },
            pick: function (P, a, idx) { if (P.data.peekKam(a.id)[idx]) { P.go('KAMD', { id: a.id, idx: idx, from: a.page, page: 0 }); } }
        }),
        KAMD: pagerProgram('KAMD', {
            selectable: false,
            perPage: 16,
            extras: [{ key: '1', label: 'PLAYER PROFILE', go: function (P, a) { openPlayerFrom(P, P.data.peekKam(a.id)[a.idx].id, { name: 'KAMD', arg: { id: a.id, idx: a.idx, from: a.from, page: 0 }, label: 'BELT' }); } }],
            build: function (P, a) {
                var r = P.data.peekKam(a.id)[a.idx];
                return { title: r.peerage === 'Knight' ? 'KNIGHT' : 'MASTER', back: 'LIST', items: kamDetailLines(r) };
            },
            back: function (P, a) { P.go('KAM', { id: a.id, page: a.from || 0 }); }
        }),
        PABOUT: pagerProgram('PABOUT', {
            selectable: false,
            perPage: 16,
            build: function (P, a) { return { title: 'ABOUT', back: 'BACK', items: aboutLines(P.data.peekPlayer(a.id)), empty: 'NO ABOUT TEXT ON FILE.' }; },
            back: function (P, a) { P.go('PPROFILE', { id: a.id }); }
        }),
        PLCOMP: pagerProgram('PLCOMP', {
            selectable: true,
            build: function (P, a) {
                var c = P.data.peekPlayer(a.id).companies;
                return { title: 'COMPANIES', back: 'BACK', empty: c ? 'NOT IN ANY COMPANY.' : '(UNAVAILABLE)', items: (c || []).map(function (u) { return leftRight(clean(u.name), unitCode(u.type), W - 3); }) };
            },
            back: function (P, a) { P.go('PPROFILE', { id: a.id }); },
            pick: function (P, a, idx) {
                var u = (P.data.peekPlayer(a.id).companies || [])[idx];
                if (u) { openUnit(P, u.id, { name: 'PLCOMP', arg: { id: a.id, page: a.page }, label: 'COMPANIES' }); }
            }
        }),
        PMILES: pagerProgram('PMILES', {
            selectable: false,
            perPage: 6,
            build: function (P, a) { return { title: 'MILESTONES', back: 'BACK', items: P.data.peekPlayer(a.id).milestones.map(milestoneText), empty: 'NO MILESTONES TO SHOW.' }; },
            back: function (P, a) { P.go('PPROFILE', { id: a.id }); }
        }),
        PRINCES: pagerProgram('PRINCES', {
            selectable: true,
            build: function (P, a) {
                return { title: 'PRINCIPALITIES', back: 'BACK', items: P.data.peek(a.id).prinz.map(function (k) { return k.name; }) };
            },
            back: function (P, a) { P.go('PROFILE', { id: a.id }); },
            pick: function (P, a, idx) {
                var k = P.data.peek(a.id).prinz[idx];
                openProfile(P, k.id, function () { P.S.stack.push({ name: 'PRINCES', arg: { id: a.id, page: a.page } }); });
            }
        }),
        PARK: {
            enter: function (P, arg, quick) {
                var p = P.data.peekPark(arg.id), r = P.S.pkret;
                var parts = parkParts(p, r ? r.label : 'MAIN MENU');
                P.screen(parts.lines, quick, { rows: parts.head, log: parts.log, art: p.art || shieldBitmap() });
                P.prompt('SELECT: ');
            },
            line: function (P, t) {
                var a = P.arg;
                if (t === '') { P.prompt('SELECT: '); return; }
                if (t === 'EXIT') { P.exit(); return; }
                if (t === '0') {
                    var r = P.S.pkret;
                    if (r) { P.go(r.name, r.arg); } else { P.go('ORK_MENU'); }
                    return;
                }
                if (/^[1-8]$/.test(t)) {
                    var item = PARK_MENU[+t - 1];
                    P.load(function () { return P.data.parkList(a.id, item[1]); }, function () { P.go(item[2], { id: a.id, page: 0 }); }, function (e) { ioFail(P, e); });
                    return;
                }
                badInput(P, t);
            }
        },
        PDAYS: parkList('PDAYS', {
            selectable: false,
            perPage: 3,
            build: function (P, a) {
                var days = P.data.peekPark(a.id).lists.days;
                return { title: 'MEETING DAYS', back: 'BACK', items: days.map(parkDayText), empty: 'NO PARK DAYS ON RECORD.' };
            }
        }),
        PWHERE: parkList('PWHERE', {
            selectable: false,
            perPage: 16,
            build: function (P, a) {
                var d = P.data.peekPark(a.id).lists.details;
                return { title: 'WHERE TO FIND US', back: 'BACK', items: parkWhereLines(d), empty: 'NO LOCATION ON FILE.' };
            }
        }),
        POFFICERS: parkList('POFFICERS', {
            selectable: true,
            build: function (P, a) {
                var list = P.data.peekPark(a.id).lists.officers;
                return {
                    title: 'OFFICERS', back: 'BACK', empty: 'NO OFFICERS ON RECORD.',
                    items: list.map(function (o) {
                        var who = +o.MundaneId > 0 && clean(o.Persona) ? clean(o.Persona) : '(VACANT)';
                        return trunc(padTo(OFFICE_SHORT[o.OfficerRole] || trunc(clean(o.OfficerRole), 11), 11) + who, W - 3);
                    })
                };
            },
            pick: function (P, a, idx) {
                var o = P.data.peekPark(a.id).lists.officers[idx];
                if (!o || !(+o.MundaneId > 0)) { P.bad('?VACANT OFFICE'); return; }
                openPlayerFrom(P, +o.MundaneId, { name: 'POFFICERS', arg: { id: a.id, page: a.page } });
            }
        }),
        PRECENT: parkList('PRECENT', {
            selectable: true,
            build: function (P, a) {
                return {
                    title: 'RECENT PLAYERS', back: 'BACK', empty: 'NO SIGN-INS IN THE LAST 90 DAYS.',
                    items: P.data.peekPark(a.id).lists.recent.map(function (r) { return leftRight(clean(r.Persona), fmtDate(r.LastSignIn), W - 3); })
                };
            },
            pick: function (P, a, idx) {
                var r = P.data.peekPark(a.id).lists.recent[idx];
                if (!r) { return; }
                openPlayerFrom(P, +r.MundaneId, { name: 'PRECENT', arg: { id: a.id, page: a.page } });
            }
        }),
        PKEVENTS: parkList('PKEVENTS', {
            selectable: true,
            perPage: 6,
            build: function (P, a) {
                return {
                    title: 'EVENTS', back: 'BACK', empty: 'NO UPCOMING EVENTS.',
                    items: P.data.peekPark(a.id).lists.events.map(function (e) { return fmtDate(e.NextDate) + ' ' + clean(e.Name); })
                };
            },
            extras: [{ key: '7', label: 'PAST EVENTS', go: function (P, a) { openPast(P, 'Park', a.id, { name: 'PKEVENTS', arg: { id: a.id, page: a.page } }); } }],
            pick: function (P, a, idx) {
                var e = P.data.peekPark(a.id).lists.events[idx];
                if (!e) { return; }
                openEvent(P, e.EventId, e.NextDetailId, e.Name, { name: 'PKEVENTS', arg: { id: a.id, page: a.page }, label: 'EVENT LIST' });
            }
        }),
        PCOMP: parkList('PCOMP', {
            selectable: false,
            build: function (P, a) {
                return {
                    title: 'COMPANIES', back: 'BACK', empty: 'NO COMPANIES HERE.', notes: ['', 'RIGHT: ACTIVE/TOTAL MEMBERS'],
                    items: P.data.peekPark(a.id).lists.companies.map(function (u) { return leftRight(clean(u.Name), (+u.ActiveMemberCount || 0) + '/' + (+u.TotalMemberCount || 0)); })
                };
            }
        }),
        PCHART: parkList('PCHART', {
            selectable: false,
            loader: true,
            build: function (P, a) {
                var rows = P.data.peekPark(a.id).lists.chart;
                return {
                    title: 'ATTENDANCE', back: 'BACK', empty: 'NO ATTENDANCE IN THE LAST 6 MONTHS.', notes: rows.length ? ['', 'DISTINCT PLAYERS PER MONTH'] : [],
                    items: barChart(rows.map(function (m) { return { label: (MONTHS[(+m.Month || 1) - 1] || '???') + ' ' + m.Year, value: m.Count }; }))
                };
            }
        }),
        PDATES: parkList('PDATES', {
            selectable: true,
            build: function (P, a) {
                return {
                    title: 'SIGN-IN DAYS', back: 'BACK', empty: 'NO SIGN-INS IN THE LAST 6 MONTHS.',
                    items: P.data.peekPark(a.id).lists.dates.map(function (d) { return fmtDate(d) + ' ' + weekdayOf(d); })
                };
            },
            pick: function (P, a, idx) {
                var date = P.data.peekPark(a.id).lists.dates[idx];
                if (!date) { return; }
                P.load(function () { return P.data.parkDay(a.id, date); }, function () {
                    P.go('PDAY', { id: a.id, date: date, page: 0, from: a.page });
                }, function (e) { ioFail(P, e); });
            }
        }),
        PDAY: pagerProgram('PDAY', {
            selectable: false,
            build: function (P, a) {
                var rows = P.data.peekPark(a.id).days[a.date];
                return {
                    title: weekdayOf(a.date) + ' ' + fmtDate(a.date) + ' (' + rows.length + ')', back: 'DAY LIST', empty: 'NO SIGN-INS ON FILE.',
                    items: rows.map(function (r) { return leftRight(clean(+r.MundaneId > 0 ? r.Persona : r.AttendancePersona), clean(r.ClassName)); })
                };
            },
            back: function (P, a) { P.go('PDATES', { id: a.id, page: a.from || 0 }); }
        })
    };

    var RECAP_FIELDS = ['Status', 'WeekStart', 'WeekEnd', 'TopEvents', 'EventId', 'EventCalendarDetailId', 'EventName', 'Attendance', 'TopParks', 'ParkId', 'ParkName',
        'Knightings', 'Masterhoods', 'Paragons', 'MundaneId', 'Persona', 'AwardName', 'NewPlayers', 'Players', 'Count'];
    /* ---- the allow-list: every endpoint the terminal may call, kept in step with docs/superpowers/plans/2026-11-01-orkple-read-surfaces.md.
       Adding one means checking the matching website page first (a screen shows nothing the page hides from the same visitor).
       Names are Class/Method for the JSON service and Controller/action for the UI routes. A call to anything else is refused
       (and logged, and noted in window.OrkpleViolations). Each entry's third column is the field projection: getJson() keeps
       only those keys, at any depth, before a loader sees the answer. Keys that are ids (all digits) are kept, so maps keyed by
       id survive; a top-level Status keeps only Status and Error. List every key a loader or screen reads, containers included. ---- */
    var ALLOWED = [
        ['Kingdom/GetKingdoms', 'KINGDOMS list', ['Kingdoms', 'KingdomId', 'KingdomName', 'ParentKingdomId']],
        ['Kingdom/GetKingdomShortInfo', 'kingdom profile', ['KingdomInfo', 'KingdomName', 'Abbreviation', 'HasHeraldry', 'IsPrincipality', 'ParentKingdomId']],
        ['Kingdom/GetKingdomDetails', 'kingdom profile: principality roll-up flag', ['KingdomConfiguration', 'IncludePrincipalityInStatistics', 'Value']],
        ['Kingdom/GetOfficers', 'kingdom profile', ['Officers', 'OfficerRole', 'MundaneId', 'Persona']],
        ['Kingdom/GetPrincipalities', 'kingdom profile', ['Principalities', 'KingdomId', 'Name']],
        ['Report/GetKingdomParkAverages', 'kingdom PARKS (active parks only, as the Parks tab)', ['KingdomParkAveragesSummary', 'ParkId', 'ParkName', 'Title']],
        ['KingdomAjax/calendar', 'KINGDOM CALENDAR (a month); MY AMTGARD NEXT PARK DAY (park-day rows, five weeks)', ['events', 'type', 'title', 'start', 'end', 'allDay', 'url', 'extendedProps', 'eventId', 'detailId', 'rawStart', 'rawEnd', 'description', 'parkId']],
        ['Kingdom/park_averages_json', 'kingdom and park averages', ['_kingdom', 'wk', 'mo', 'att']],
        ['Heraldry/GetHeraldryUrl', 'arms: Kingdom, Park, Unit, Event', ['Url']],
        ['SearchAjax/universal', 'player, park, kingdom, company and household search', ['players', 'parks', 'kingdoms', 'units', 'id', 'name', 'abbr', 'park', 'active', 'unitType']],
        ['Player/GetPlayer', 'player profile', ['Player', 'MundaneId', 'ParkId', 'KingdomId', 'IsActive', 'DuesStatus', 'NamePrefix', 'Persona', 'NameSuffix', 'SuffixComma',
            'PronunciationGuide', 'PronounCustomText', 'PronounText', 'ReeveStatus', 'ReeveQualifiedUntil', 'CorporaStatus', 'CorporaQualifiedUntil',
            'AboutPersona', 'AboutStory', 'Company', 'UnitId', 'Name', 'Type']],
        ['Player/AttendanceForPlayer', 'player profile and ATTENDANCE', ['Attendance', 'Date', 'ClassId', 'ClassName', 'EventName', 'ParkName']],
        ['Player/ComputeClassProgress', 'player CLASSES', ['Detail', 'ClassId', 'ClassName', 'Level', 'Credits']],
        ['Player/AwardsForPlayer', 'player AWARDS and TITLES', ['Awards', 'AwardsId', 'IsTitleEffective', 'CustomAwardName', 'KingdomAwardName', 'Name', 'AliasAwardName', 'Date', 'Rank']],
        ['Player/GetLadderProgress', 'player LADDERS', ['Detail', 'Short', 'Name', 'Rank', 'MaxRank', 'Approx', 'HasMaster']],
        ['EventRsvpAjax/mine', 'MY AMTGARD NEXT UP (the signed-in session\'s own RSVPs, no player id)', ['status', 'UpcomingRsvps', 'EventName', 'EventStart']],
        ['Park/GetParkShortInfo', 'park header, player home park', ['ParkInfo', 'KingdomInfo', 'ParkName', 'KingdomName', 'KingdomId', 'Abbreviation', 'Active', 'HasHeraldry']],
        ['Park/GetParkDetails', 'park header and WHERE TO FIND US', ['ParkName', 'ParkTitle', 'Address', 'City', 'Province', 'PostalCode', 'Directions', 'Description', 'Url']],
        ['Park/GetParkDays', 'park MEETING DAYS (recurrence only); header AT FIELD NOW, only on a park day',
            ['ParkDays', 'WeekDay', 'Recurrence', 'WeekOfMonth', 'WeekInterval', 'MonthDay', 'Purpose', 'Time', 'Online', 'Address', 'City', 'Province', 'PostalCode', 'Description']],
        ['Park/GetOfficers', 'park OFFICERS', ['Officers', 'OfficerRole', 'MundaneId', 'Persona']],
        ['Report/GetParkFieldCountToday', 'park header', ['Count', 'IsParkDay']],
        ['Report/RecentParkAttendees', 'park RECENT PLAYERS', ['Attendees', 'MundaneId', 'Persona', 'LastSignIn']],
        ['SearchService/Event', 'park EVENTS; event name, host, arms flag and publish check (event_id)', ['EventId', 'Name', 'NextDate', 'NextDetailId', 'HasHeraldry', 'ParkName', 'KingdomName']],
        ['SearchService/CalendarDetail', 'EVENT DETAIL', ['EventId', 'AtParkId', 'EventType', 'EventStart', 'EventEnd', 'Address', 'City', 'Province', 'Price', 'Description', 'Url']],
        ['Event/GetRsvpCounts', 'EVENT RSVP COUNTS (numbers only)', ['Going', 'Interested', 'Total']],
        ['EventEmbed/schedule', 'EVENT SCHEDULE', ['ok', 'days', 'date', 'day_label', 'items', 'start', 'end', 'title', 'location', 'leads']],
        ['Report/AttendanceForEvent', 'EVENT WHO CAME (EventId and EventCalendarDetailId)', ['Attendance', 'MundaneId', 'Persona', 'ClassName']],
        ['Report/EventAttendanceReport', 'PAST EVENTS (KingdomId or ParkId)', ['Events', 'EventId', 'DetailId', 'EventName', 'EventStart', 'EventEnd', 'AttendanceCount']],
        ['Report/UnitSummary', 'park COMPANIES (ParkId, IncludeCompanies); kingdom COMPANIES AND HOUSEHOLDS (KingdomId, both Include flags, TopBySize, Limit 100)',
            ['Units', 'UnitId', 'Name', 'Type', 'Active', 'LeaderNames', 'MemberCount', 'ActiveMemberCount', 'TotalMemberCount']],
        ['Unit/GetUnit', 'UNIT PROFILE (UnitId)', ['Unit', 'UnitId', 'Name', 'Type', 'Active', 'Description', 'History', 'Url', 'HasHeraldry']],
        ['Search/unitactivity', 'kingdom unit list: members signed in over 12 months, 25 ids a call', []],
        ['Search/unitsearch', 'UNIT PROFILE leaders, for a unit opened from search (q is its exact name)', ['UnitId', 'LeaderNames']],
        ['Report/GetMonthlyChartData', 'park ATTENDANCE (ParkId, Periods, PerMonths)', ['Month', 'Year', 'Count']],
        ['Report/GetAttendanceDates', 'park SIGN-INS BY DAY (Type Park, Id)', ['Dates']],
        ['Report/AttendanceForDate', 'park SIGN-INS BY DAY (ParkId and a listed Date)', ['Attendance', 'MundaneId', 'Persona', 'AttendancePersona', 'ClassName']],
        ['Report/GetActiveKingdomsSummary', 'THE ORK TODAY: KINGDOM STANDINGS', ['ActiveKingdomsSummaryList', 'KingdomId', 'KingdomName', 'ParentKingdomId', 'ParkCount', 'AvgPerWeek']],
        ['Report/GetTopParksByAttendance', 'THE ORK TODAY: TOP PARKS (Limit, StartDate, EndDate)', ['TopParksSummary', 'ParkId', 'ParkName', 'AvgPerWeek']],
        ['Recap/json', 'THE ORK TODAY: THIS WEEK, all of Amtgard', RECAP_FIELDS],
        ['Recap/json_kingdom', 'THE ORK TODAY: THIS WEEK, one kingdom', RECAP_FIELDS],
        ['Live/stats', 'THE ORK TODAY: ON THE FIELD NOW', ['active_3h', 'parks', 'events', 'name', 'h3', 'calendar_detail_id']],
        ['Live/recent', 'THE ORK TODAY: LATEST SIGN-INS', ['signins', 'parks', 'events', 'name']],
        ['WeatherService/GetPlayForDate', 'THE ORK TODAY: WEATHER FOR PLAY (date, empty Token)', ['park_id', 'park_name', 'badges', 'label', 'forecast', 'hi_f', '_score']],
        ['EraPhoenice/today', 'main menu date line', ['ok', 'ep', 'formatted', 'holiday']],
        ['Report/PlayerAwards', 'kingdom KNIGHTS AND MASTERS (KingdomId, ParkId 0, IncludeKnights, IncludeMasters)', ['Awards', 'Peerage', 'MundaneId', 'Persona', 'AwardName', 'Date', 'ParkId', 'ParkName']],
        ['Player/GetPlayerMilestones', 'player MILESTONES', ['Detail', 'date', 'description']]
    ];
    function allowedEntry(name) {
        for (var i = 0; i < ALLOWED.length; i++) { if (ALLOWED[i][0] === name) { return ALLOWED[i]; } }
        return null;
    }
    function isAllowed(name) { return !!allowedEntry(name); }
    // Keep only the listed keys (and id keys) of an answer, at every depth. Arrays keep their order; plain values pass.
    function project(v, keep, top) {
        if (Array.isArray(v)) { return v.map(function (x) { return project(x, keep, false); }); }
        if (!v || typeof v !== 'object') { return v; }
        var o = {};
        for (var k in v) {
            if (!v.hasOwnProperty(k)) { continue; }
            if (top && k === 'Status') {
                var st = v.Status;
                o.Status = (st && typeof st === 'object') ? { Status: st.Status, Error: st.Error } : st;
            } else if (top && k === 'Result') {
                o.Result = project(v.Result, keep, false);
            } else if (keep[k] === true || /^\d+$/.test(k)) {
                o[k] = project(v[k], keep, false);
            }
        }
        return o;
    }
    function projectFor(name, d) {
        var e = allowedEntry(name), keep = {};
        if (!e) { return null; }
        (e[2] || []).forEach(function (k) { keep[k] = true; });
        return project(d, keep, true);
    }

    // Keep only path and query, so an http-configured constant cannot make mixed content on an https page.
    function sameOrigin(x) {
        if (!x) { return ''; }
        try { var u = new URL(String(x), window.location.href); return u.pathname + u.search; } catch (e) { return ''; }
    }

    // Disk reads kept across mounts, so ESC and back in reads nothing twice. A mount that starts once the set is
    // CACHE_MS old (about the life of the server's own ghettocache) starts a fresh one; a mount already running keeps
    // the set it began with. Pending reads are kept too (shared by a second ask), and a read that fails is forgotten.
    // A mount's destroy() aborts its reads (pending arms images included) but keeps what already arrived.
    var CACHE_MS = 10 * 60 * 1000, dataCache = null;
    function sharedCache() {
        if (!dataCache || Date.now() - dataCache.at > CACHE_MS) {
            dataCache = {
                at: Date.now(), skew: 0, kingdomsP: null, kingdomsMem: null, profileP: {}, profileMem: {}, armsP: {}, avgP: {},
                playerP: {}, playerMem: {}, parkP: {}, parkMem: {}, calP: {}, calMem: {}, evP: {}, evMem: {}, pastP: {}, pastMem: {},
                unitKP: {}, unitKMem: {}, unitLeaders: {}, unitP: {}, unitMem: {}, epMem: null, epP: null,
                todayP: {}, todayMem: {}, weekP: {}, weekMem: {}, liveP: null, liveMem: null, kamP: {}, kamMem: {}, nextDayP: {}
            };
        }
        return dataCache;
    }

    function mount(opts) {
        var canvas = opts.canvas, input = opts.input, live = opts.live;
        var onExit = opts.onExit, reduced = !!opts.reducedMotion;
        var power = opts.power || null, sound = opts.sound || null;   // sound: {on(), set(on)} from the shell's speaker switch
        function soundOn() { return !sound || sound.on() !== false; }
        var svcBase = sameOrigin(opts.service), uiBase = sameOrigin(opts.ui), userId = parseInt(opts.user, 10) || 0;
        var ctx = canvas.getContext('2d');
        canvas.width = COLS * CW;
        canvas.height = ROWS * CH;

        var destroyed = false, started = false, exited = false;
        var scr = [], cx = 0, cy = 0;
        var queue = [], pumpT = 0, waitT = 0, lastPump = 0, holdOn = false;
        var timers = {}, blinkId = 0, garbageId = 0, rafId = 0, dirty = true;
        var accepting = false, cursorOn = true, promptStr = '', promptCol = 0, buf = '', cap = 38;
        var ahead = '';                // type-ahead: keys pressed while output is printing, '\n' for Return
        var current = null;
        var blockOn = false, blockBuf = [];
        var dateSlot = null, scrollCount = 0;   // the menu's Era Phoenice row, and how often the page has scrolled since
        var screenGen = 0;                       // bumped by every clear, so a late in-place write knows its screen is gone
        var game = null, gameCb = null, keyCb = null, rawOn = false, keyNoRepeat = false, gameTimers = {}, gameSeq = 0, rngState = 0;
        var heldKeys = {};             // keys down right now, by game key name (A, LEFT, SPACE); see host.held
        var gfx = null;                // arms bitmap layer: {canvas, w, h, row, rows} anchored at cell column 0
        var spriteDefs = {}, spriteList = [];  // game sprites: drawn over the text cells, on the 280x192 pixel grid
        var splash = null;             // the ORKEK title card while it is up: a full-page canvas over everything
        var audio = null;

        function blankRow() {
            var r = [];
            for (var i = 0; i < COLS; i++) { r.push({ ch: ' ', inv: false }); }
            return r;
        }
        for (var i = 0; i < ROWS; i++) { scr.push(blankRow()); }

        function later(fn, ms) {
            var id = setTimeout(function () { delete timers[id]; if (!destroyed) { fn(); } }, ms);
            timers[id] = true;
            return id;
        }

        /* ---- drawing: only when dirty, one rAF at a time ---- */
        function markDirty() {
            dirty = true;
            if (!rafId && !destroyed) { rafId = requestAnimationFrame(draw); }
        }
        function draw() {
            rafId = 0;
            if (destroyed) { return; }
            dirty = false;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            if (splash) { ctx.drawImage(splash, 0, 0); return; }
            for (var r = 0; r < ROWS; r++) {
                var row = scr[r];
                for (var c = 0; c < COLS; c++) {
                    var cell = row[c];
                    if (cell.ch === ' ' && !cell.inv) { continue; }
                    ctx.drawImage(glyph(cell.ch, cell.inv), c * CW, r * CH);
                }
            }
            if (gfx) {
                var gy = gfx.row * 16, vis = Math.min(gfx.h * 2, gfx.rows * 16);
                var cut = Math.max(0, -gy);
                if (vis > cut) { ctx.drawImage(gfx.canvas, 0, cut, gfx.w * 2, vis - cut, 0, gy + cut, gfx.w * 2, vis - cut); }
            }
            for (var si = 0; si < spriteList.length; si++) {
                var sp = spriteList[si], img = sp && spriteDefs[sp.s];
                if (img) { ctx.drawImage(img, Math.round(sp.x) * 2, Math.round(sp.y) * 2); }
            }
            if (accepting && cursorOn) {
                var col = Math.min(COLS - 1, promptCol + buf.length);
                ctx.fillStyle = PHOSPHOR;
                ctx.fillRect(col * CW, cy * CH, CW, CH);
            }
        }

        /* ---- screen model ---- */
        function rowText(r) {
            var s = '';
            for (var c = 0; c < COLS; c++) { s += scr[r][c].ch; }
            return s.replace(/\s+$/, '').replace(/^\s+/, '');
        }
        // One child per line, oldest dropped past LIVE_MAX: the log only ever gains nodes, so screen
        // readers announce the new lines and never re-read the ones already spoken.
        function pushLive(lines) {
            if (!live || !lines.length) { return; }
            for (var i = 0; i < lines.length; i++) {
                var d = document.createElement('div');
                d.textContent = lines[i];
                live.appendChild(d);
            }
            while (live.childNodes.length > LIVE_MAX) { live.removeChild(live.firstChild); }
        }
        function logLine(t) {
            if (!t) { return; }
            if (blockOn) { blockBuf.push(t); } else { pushLive([t]); }
        }
        function newline(log) {
            if (log) { logLine(rowText(cy)); }
            cx = 0;
            cy++;
            if (cy >= ROWS) {
                scrollCount++;
                scr.shift();
                scr.push(blankRow());
                cy = ROWS - 1;
                if (gfx) {
                    gfx.row--;
                    if (gfx.row * 8 + gfx.h <= 0) { gfx = null; }
                }
            }
        }
        function putChar(ch, inv) {
            if (cx >= COLS) { newline(true); }
            scr[cy][cx].ch = G[ch] ? ch : '?';
            scr[cy][cx].inv = !!inv;
            cx++;
            if (cx >= COLS) { newline(true); }
        }
        // The arms bitmap, pre-drawn at the 2x2 backing block per pixel; it moves up with the text.
        function setGfx(bm, rows) {
            var c = document.createElement('canvas'), x = c.getContext('2d');
            c.width = bm.w * 2; c.height = bm.h * 2;
            x.fillStyle = PHOSPHOR;
            for (var py = 0; py < bm.h; py++) {
                for (var px = 0; px < bm.w; px++) { if (bm.bits[py * bm.w + px]) { x.fillRect(px * 2, py * 2, 2, 2); } }
            }
            gfx = { canvas: c, w: bm.w, h: bm.h, row: cy, rows: rows };
            markDirty();
        }
        function clearScreen() {
            screenGen++;
            gfx = null;
            dateSlot = null;
            for (var r = 0; r < ROWS; r++) { scr[r] = blankRow(); }
            cx = 0; cy = 0;
        }

        /* ---- print queue ---- */
        function kick() {
            if (pumpT || waitT || holdOn || destroyed) { return; }
            lastPump = Date.now() - CHAR_MS * 4;
            pumpT = later(pump, 0);
        }
        function pump() {
            pumpT = 0;
            var now = Date.now();
            var budget = reduced ? Infinity : Math.max(1, Math.floor(Math.min(100, now - lastPump) / CHAR_MS));
            lastPump = now;
            while (queue.length && !destroyed) {
                var op = queue[0];
                if (op.o === 'wait') {
                    waitT = later(function () { waitT = 0; queue.shift(); pump(); }, op.ms);
                    markDirty();
                    return;
                }
                if (op.o === 'async') {
                    queue.shift();
                    holdOn = true;
                    markDirty();
                    op.f(function (cb) {
                        if (destroyed) { return; }
                        holdOn = false;
                        try { cb(); } catch (err) {
                            if (window.console) { console.error('ORKPLE screen failed', err); }
                            P.bad('?I/O ERROR', 2);
                        }
                        kick();
                    });
                    return;
                }
                if (op.o === 'c' && op.ch !== ' ' && !op.whole) {
                    if (budget <= 0) { break; }
                    budget--;
                }
                queue.shift();
                run(op);
            }
            markDirty();
            if (queue.length && !destroyed) { pumpT = later(pump, 16); }
        }
        function run(op) {
            switch (op.o) {
                case 'c': putChar(op.ch, op.inv); break;
                case 'at': cx = op.x; break;
                case 'nl': newline(op.log); break;
                case 'cls': clearScreen(); break;
                case 'date': holdDate(); break;
                case 'gfx': setGfx(op.art, op.rows); break;
                case 'gfxrows': if (gfx) { gfx.rows = op.n; markDirty(); } break;
                case 'beep': beep(op.freq, op.ms); break;
                case 'say': logLine(op.t); break;
                case 'block':
                    if (op.on) { blockOn = true; blockBuf = []; }
                    else { blockOn = false; pushLive(blockBuf); blockBuf = []; }
                    break;
                case 'fn': op.f(); break;
                case 'prompt': openPrompt(op.p); break;
                case 'gprompt': if (game === op.g) { rawOn = false; keyCb = null; keyNoRepeat = false; gameCb = op.cb; openPrompt(op.p); } break;
                case 'gkeys': if (game === op.g) { rawOn = true; keyCb = op.cb; keyNoRepeat = !!op.nr; accepting = false; ahead = ''; markDirty(); } break;
            }
        }
        function enq(op) { queue.push(op); kick(); }
        // The menu's Era Phoenice row. Cached, it is written at once; otherwise the page asks in the background and writes the
        // row if the menu is still on screen unmoved. A failed or slow answer leaves a blank row and costs the menu nothing.
        function writeDate(slot, text) {
            var t = centerLine(text), row = scr[slot.row];
            for (var i = 0; i < t.length && i < COLS; i++) { row[i].ch = G[t.charAt(i)] ? t.charAt(i) : '?'; row[i].inv = false; }
            markDirty();
        }
        // Overwrite text cells in place (P.patch); a row that changed goes to the live log too, just before anchor (the
        // screen's last line when it finished printing), so the log still ends on that screen's last line.
        function writeCells(row, col, text, anchor) {
            var r = scr[row], before = '';
            if (!r) { return; }
            for (var c = col; c < col + text.length && c < COLS; c++) { before += r[c].ch; }
            if (before === text.slice(0, before.length)) { return; }
            for (var i = 0; i < text.length && col + i < COLS; i++) { r[col + i].ch = G[text.charAt(i)] ? text.charAt(i) : '?'; r[col + i].inv = false; }
            var t = text.replace(/\s+$/, '');
            if (t && live && !blockOn && anchor && anchor.parentNode === live) {
                var d = document.createElement('div');
                d.textContent = t;
                live.insertBefore(d, anchor);
                while (live.childNodes.length > LIVE_MAX) { live.removeChild(live.firstChild); }
            } else { logLine(t); }
            markDirty();
        }
        function holdDate() {
            var slot = dateSlot = { row: cy, scrolls: scrollCount };
            function show(ep) {
                if (destroyed || dateSlot !== slot || slot.scrolls !== scrollCount) { return; }
                writeDate(slot, ep.text);
                logLine(ep.text);
            }
            var cached = P.data.epPeek();
            if (cached) { show(cached); }
            else {
                var pr;
                try { pr = P.data.epToday(); } catch (e) { pr = Promise.reject(e); }
                pr.then(show, function () { /* no date today */ });
            }
            newline(false);
        }

        /* ---- prompt and input ---- */
        function openPrompt(p) {
            if (cx !== 0) { newline(true); }
            promptStr = p;
            for (var i = 0; i < p.length; i++) { putChar(p.charAt(i), false); }
            promptCol = cx;
            buf = '';
            cap = Math.min(38, COLS - 1 - promptCol);
            input.value = '';
            cursorOn = true;
            accepting = true;
            markDirty();
            drainAhead();
        }
        // Replay type-ahead into a newly opened prompt, one line per prompt.
        function drainAhead() {
            if (!ahead) { return; }
            var nl = ahead.indexOf('\n');
            input.value = nl < 0 ? ahead : ahead.slice(0, nl);
            ahead = nl < 0 ? '' : ahead.slice(nl + 1);
            onInput();
            if (nl >= 0) { later(function () { if (accepting) { commit(); } }, 0); }
        }
        function paintInput() {
            var row = scr[cy];
            for (var c = promptCol; c < COLS; c++) { row[c].ch = ' '; row[c].inv = false; }
            for (var i = 0; i < buf.length; i++) { row[promptCol + i].ch = G[buf.charAt(i)] ? buf.charAt(i) : '?'; }
            cursorOn = true;
            markDirty();
        }
        function onInput() {
            if (destroyed) { return; }
            if (rawOn && keyCb) {
                // Phones: the on-screen keyboard delivers characters here, not as named keys.
                var typed = String(input.value);
                input.value = '';
                for (var ti = 0; ti < typed.length && rawOn && keyCb; ti++) {
                    var tc = typed.charAt(ti).toUpperCase();
                    sendKey(tc === ' ' ? 'SPACE' : tc);
                }
                return;
            }
            if (!accepting) { ahead = (ahead + String(input.value)).slice(0, 64); input.value = ''; return; }
            var v = String(input.value).toUpperCase().replace(/[^\x20-\x5F]/g, '');
            if (v.length > cap) { v = v.slice(0, cap); }
            if (input.value !== v) { input.value = v; }
            buf = v;
            paintInput();
        }
        // The game's name for a key: UP DOWN LEFT RIGHT ENTER BACKSPACE SPACE, or the character upper-cased; '' for the rest.
        function gameKeyName(k) {
            var named = { ArrowUp: 'UP', ArrowDown: 'DOWN', ArrowLeft: 'LEFT', ArrowRight: 'RIGHT', Enter: 'ENTER', Backspace: 'BACKSPACE', ' ': 'SPACE' }[k];
            return named || (k && k.length === 1 ? k.toUpperCase() : '');
        }
        function onKeyup(e) {
            var name = gameKeyName(e.key);
            if (name) { delete heldKeys[name]; }
        }
        // A key released while the page is hidden or unfocused never sends its keyup, so forget them all.
        function releaseKeys() { heldKeys = {}; }
        function onVisibility() { if (document.hidden) { releaseKeys(); } }
        function onKeydown(e) {
            if (destroyed) { return; }
            var k = e.key;
            if (rawOn && keyCb) {
                if (e.ctrlKey || e.metaKey || e.altKey) { return; }
                var name = gameKeyName(k);
                if (name) { heldKeys[name] = true; }
                // host.keys(fn, { noRepeat: true }): holding a key sends one press, not a stream.
                if (keyNoRepeat && e.repeat) { e.preventDefault(); return; }
                if (name) {
                    e.preventDefault();
                    sendKey(name);
                } else if (k === 'Home' || k === 'End') {
                    e.preventDefault();
                }
                return;
            }
            if (k === 'Enter') {
                e.preventDefault();
                if (accepting) { commit(); }
                // A bare Return during output is ignored, so mashing it can't skip through prompts.
                else if (ahead && ahead.charAt(ahead.length - 1) !== '\n') { ahead += '\n'; }
            } else if (k === 'ArrowLeft' || k === 'ArrowRight' || k === 'Home' || k === 'End' || k === 'ArrowUp' || k === 'ArrowDown') {
                e.preventDefault();
            }
        }
        function commit() {
            accepting = false;
            var text = buf.replace(/^\s+|\s+$/g, '');
            if (text) { logLine(promptStr + text); }
            buf = '';
            input.value = '';
            newline(false);
            markDirty();
            current.line(P, text);
        }

        /* ---- the API handed to programs ---- */
        var P = {
            reduced: reduced,
            S: { stack: [] },
            data: null,
            arg: {},
            errs: 0,
            pstr: 'SELECT: ',
            view: null,
            cls: function () { enq({ o: 'cls' }); },
            block: function (on) { enq({ o: 'block', on: on }); },
            say: function (t) { enq({ o: 'say', t: t }); },
            beep: function (freq, ms) { enq({ o: 'beep', freq: freq, ms: ms }); },
            soundOn: function () { return soundOn(); },
            // SOUND ON / SOUND OFF; with no argument, flips it. Returns the new setting.
            sound: function (on) {
                var next = on === undefined ? !soundOn() : !!on;
                if (sound && sound.set) { sound.set(next); } else if (!next) { stopDisk(); }
                return soundOn();
            },
            wait: function (ms) { enq({ o: 'wait', ms: ms }); },
            blank: function () { enq({ o: 'nl', log: false }); },
            dateLine: function () { enq({ o: 'date' }); },
            prompt: function (p) { enq({ o: 'prompt', p: p }); },
            print: function (t, o) {
                o = o || {};
                // \x01 in the text flips inverse video; with o.tick the inverse cells appear one by one, like a loader.
                var x = o.center ? Math.max(0, Math.floor((COLS - t.replace(/\x01/g, '').length) / 2)) : (o.at || 0);
                if (x) { enq({ o: 'at', x: x }); }
                var inv = !!o.inv;
                for (var i = 0; i < t.length; i++) {
                    var ch = t.charAt(i);
                    if (ch === '\x01') { inv = !inv; continue; }
                    enq({ o: 'c', ch: ch, inv: inv, whole: !!o.whole });
                    if (o.tick && inv && !reduced) { enq({ o: 'wait', ms: o.tick }); }
                }
                enq({ o: 'nl', log: !o.nolog });
            },
            // Print a whole line at once, then hold before the next one ({hold: ms} overrides).
            line: function (t, o) {
                o = o || {};
                P.print(t, { center: o.center, at: o.at, inv: o.inv, nolog: o.nolog, whole: true, tick: o.tick });
                var h = o.hold === undefined ? LINE_MS : o.hold;
                if (!reduced && h > 0) { P.wait(h); }
            },
            // A data screen: clear, then every line whole at HOLD_MS, logged as one block.
            // head = {rows, log, art}: the first rows stay out of the live log, which gets head.log instead,
            // and head.art (a bitmap) is drawn over those blank rows, ticking in a cell row at a time.
            screen: function (lines, quick, head, tick) {
                P.cls();
                P.block(true);
                if (head) {
                    for (var k = 0; k < head.log.length; k++) { P.say(head.log[k]); }
                    enq({ o: 'gfx', art: head.art, rows: quick || reduced ? ART_H : 0 });
                }
                for (var i = 0; i < lines.length; i++) {
                    if (head && !quick && !reduced && i < ART_H) { enq({ o: 'gfxrows', n: i + 1 }); }
                    P.line(lines[i], { hold: quick ? 0 : HOLD_MS, nolog: !!head && i < head.rows, tick: quick ? 0 : tick });
                }
                P.block(false);
            },
            go: function (name, arg) {
                enq({ o: 'fn', f: function () { current = programs[name]; P.arg = arg || {}; P.errs = 0; P.pstr = current.prompt || 'SELECT: '; current.enter(P, P.arg, false); } });
            },
            // Fill rows in place as reads arrive: rowsOf() returns {row, col, width, lines}. Nothing is written once the
            // screen has been cleared or has scrolled since it printed.
            patch: function (list, rowsOf) {
                enq({ o: 'fn', f: function () {
                    var gen = screenGen, scrolls = scrollCount, anchor = live ? live.lastChild : null;
                    list.forEach(function (pr) {
                        pr.then(function () {
                            if (destroyed || gen !== screenGen || scrolls !== scrollCount) { return; }
                            var s = rowsOf();
                            for (var i = 0; i < s.lines.length; i++) { writeCells(s.row + i, s.col, padTo(trunc(s.lines[i], s.width), s.width), anchor); }
                        }, function () { /* the row keeps -- */ });
                    });
                } });
            },
            // Redraw the current screen at once (after repeated errors have scrolled it away).
            redraw: function () {
                enq({ o: 'fn', f: function () { P.errs = 0; current.enter(P, P.arg, true); } });
            },
            // Print an error, beep, and give the prompt back; the third error in a row redraws the screen.
            bad: function (msg, weight, extra) {
                P.print(msg);
                if (extra) { P.print(extra); }
                P.beep();
                P.errs += weight || 1;
                if (P.errs >= 3) { P.redraw(); } else { P.prompt(P.pstr); }
            },
            // Run a disk read with the keyboard closed. ok(value) or fail() runs on the print queue.
            load: function (read, ok, fail) {
                P.print('READING DISK...');
                enq({ o: 'async', f: function (done) {
                    var pr;
                    try { pr = read(); } catch (e) { pr = Promise.reject(e); }
                    pr.then(function (v) { done(function () { ok(v); }); }, function (e) { done(function () { fail(e); }); });
                } });
            },
            exit: function () {
                enq({ o: 'fn', f: function () { if (!exited) { exited = true; if (onExit) { onExit(); } } } });
            }
        };

        /* ---- disk reads: GET only, 12s timeout, all aborted on destroy ---- */
        var images = [], inflight = [], C = sharedCache();   // C.skew: server clock minus ours, from the Date header
        function abortAll() {
            var list = inflight;
            inflight = [];
            for (var i = 0; i < list.length; i++) { try { list[i].abort(); } catch (e) { /* ignore */ } }
        }
        // Which endpoint a URL calls: the call= name of the JSON service, else Controller/action after the UI prefix.
        function endpointOf(url) {
            var m = /[?&]call=([^&]+)/.exec(url);
            if (m) { return decodeURIComponent(m[1]); }
            var rest = uiBase && url.indexOf(uiBase) === 0 ? url.slice(uiBase.length) : url;
            m = /^([A-Za-z_]+\/[A-Za-z_]+)/.exec(rest);
            return m ? m[1] : rest;
        }
        // The endpoint's name when it is on the allow-list; otherwise the call is logged, noted and refused.
        function checkAllowed(url) {
            var name = endpointOf(url);
            if (isAllowed(name)) { return name; }
            window.OrkpleViolations = (window.OrkpleViolations || []).concat(name);
            if (window.console) { console.error('ORKPLE: endpoint not on the allow-list: ' + name); }
            return null;
        }
        function getJson(url) {
            if (destroyed || !url || !window.fetch || !window.AbortController) { return Promise.reject(new Error('no disk')); }
            var name = checkAllowed(url);
            if (name === null) { return Promise.reject(new Error('endpoint not on the allow-list: ' + endpointOf(url))); }
            var ac = new AbortController();
            inflight.push(ac);
            var timedOut = false;
            var t = setTimeout(function () { timedOut = true; ac.abort(); }, FETCH_MS);
            function settle() {
                clearTimeout(t);
                var k = inflight.indexOf(ac);
                if (k >= 0) { inflight.splice(k, 1); }
            }
            return fetch(url, { credentials: 'same-origin', signal: ac.signal }).then(function (r) {
                if (!r.ok) { throw new Error('http ' + r.status); }
                var hd = Date.parse(r.headers.get('Date') || '');
                if (hd) { C.skew = hd - Date.now(); }
                return r.json();
            }).then(function (d) { settle(); return projectFor(name, d); }, function (e) {
                settle();
                if (timedOut) { var te = new Error('disk read timed out'); te.timeout = true; throw te; }
                throw e;
            });
        }
        function qs(params, wrapped) {
            var out = [];
            for (var k in params) {
                if (params.hasOwnProperty(k)) {
                    out.push((wrapped ? encodeURIComponent('request[' + k + ']') : encodeURIComponent(k)) + '=' + encodeURIComponent(params[k]));
                }
            }
            return out.join('&');
        }
        // Kingdom/Report calls take a request[] envelope; SearchService takes plain params. Three response shapes:
        //   {Status: {Status: 0, Error, Detail}, ...fields}  fields at the top level; Detail is never shown
        //   {Status: true, Result: ...}                      resolves to Result
        //   {Status: 2}                                      a bare number: 0 is success, anything above is a failure
        function svc(call, params, plain) {
            if (!svcBase) { return Promise.reject(new Error('no service')); }
            return getJson(svcBase + 'Json/index.php?call=' + call + '&' + qs(params, !plain)).then(function (d) {
                if (!d || typeof d !== 'object') { throw new Error('service error'); }
                var st = d.Status;
                if (st === false || st === null) { throw new Error('service error'); }
                if (typeof st === 'object') {
                    if (+st.Status !== 0) { var se = new Error('service error'); se.status = +st.Status; throw se; }
                    return d;
                }
                if (typeof st === 'number' || (typeof st === 'string' && /^\d+$/.test(st))) {
                    if (+st > 0) { throw new Error('service error'); }
                }
                return d.hasOwnProperty('Result') ? d.Result : d;
            });
        }
        // A failed read is null, except after destroy(): then it stays a failure, so nothing half-read is kept.
        function soft(p) { return p.then(function (v) { return v; }, function (e) { if (destroyed) { throw e; } return null; }); }
        // Share a pending read under key in pend; forget it if it fails.
        function memo(pend, key, make) {
            if (pend[key]) { return pend[key]; }
            var pr = make();
            pend[key] = pr;
            pr.then(null, function () { if (pend[key] === pr) { delete pend[key]; } });
            return pr;
        }
        function listOf(d, key) {
            if (!d || !Array.isArray(d[key])) { throw new Error('bad ' + key); }
            return d[key];
        }
        // Heraldry image from the same origin; gives up after 8s so the arms never hold up the profile.
        function loadImage(url) {
            return new Promise(function (resolve, reject) {
                if (!url || destroyed) { reject(new Error('no image')); return; }
                var img = new Image(), t = setTimeout(function () { img.src = ''; reject(new Error('image timeout')); }, ART_MS);
                // destroy() fails a pending image at once, so the shared arms read is dropped, not left to time out.
                img.orkFail = function () { clearTimeout(t); if (!img.orkDone) { img.src = ''; reject(new Error('aborted')); } };
                images.push(img);
                img.onload = function () { clearTimeout(t); img.orkDone = true; resolve(img); };
                img.onerror = function () { clearTimeout(t); img.orkDone = true; reject(new Error('image failed')); };
                img.src = url;
            });
        }
        function byName(a, b) { return a.name < b.name ? -1 : (a.name > b.name ? 1 : 0); }
        // The kingdom page's own event window (a week back to a year ahead): the calendar feed the page
        // uses, kept to real events plus calendar items. The feed already rolls principalities in when the
        // kingdom's statistics flag says so. (SearchService/Event gives one date per event and misses the
        // calendar items, so its counts do not match the page.)
        // The page's SQL uses the server's NOW(), so the window is cut on the server's clock (read as UTC).
        function stamp(d) {
            function two(n) { return (n < 10 ? '0' : '') + n; }
            return d.getUTCFullYear() + '-' + two(d.getUTCMonth() + 1) + '-' + two(d.getUTCDate()) + ' ' + two(d.getUTCHours()) + ':' + two(d.getUTCMinutes()) + ':' + two(d.getUTCSeconds());
        }
        function eventsOf(id) {
            if (!uiBase) { return Promise.reject(new Error('no ui')); }
            var now = new Date(Date.now() + C.skew), lo = new Date(now.getTime() - 7 * 86400000), hi = new Date(now.getTime());
            hi.setUTCMonth(hi.getUTCMonth() + 12);
            var loS = stamp(lo), hiS = stamp(hi), end = new Date(hi.getTime() + 86400000);
            return getJson(uiBase + 'KingdomAjax/calendar/' + id + '&start=' + loS.slice(0, 10) + '&end=' + stamp(end).slice(0, 10)).then(function (d) {
                var list = listOf(d, 'events'), out = [];
                list.forEach(function (e) {
                    var x = e.extendedProps || {}, st = String(e.start || '');
                    if (e.type === 'calendar-item') {
                        if (String(x.rawEnd || st) >= loS && String(x.rawStart || st) <= hiS) { out.push({ Name: e.title, NextDate: x.rawStart || st }); }
                    } else if (x.detailId && st >= loS && st <= hiS) {
                        out.push({ Name: e.title, NextDate: st });
                    }
                });
                return out;
            });
        }
        // The active park list. AverageMonths 6, in this order, is the request park_averages_json makes of the same
        // report, so both share the server's cached copy; the figures shown come only from park_averages_json (wk, mo).
        function parksOf(id) {
            return svc('Report/GetKingdomParkAverages', { KingdomId: id, AverageMonths: 6 }).then(function (d) { return listOf(d, 'KingdomParkAveragesSummary'); });
        }
        // The kingdom page's per-week and per-month figures, worked out on the server; one read per kingdom for the kingdom and park screens.
        function averagesOf(kid) {
            return memo(C.avgP, kid, function () {
                return (uiBase ? getJson(uiBase + 'Kingdom/park_averages_json/' + kid) : Promise.reject(new Error('no ui'))).then(function (avg) {
                    if (!avg || typeof avg !== 'object' || Array.isArray(avg)) { throw new Error('bad averages'); }
                    return avg;
                });
            });
        }
        function sortEvents(list) {
            return list.slice().sort(function (a, b) {
                var x = String(a.NextDate || '9999'), y = String(b.NextDate || '9999');
                return x < y ? -1 : (x > y ? 1 : 0);
            });
        }

        var profileP = C.profileP, profileMem = C.profileMem;
        function loadKingdoms() {
            if (!C.kingdomsP) {
                C.kingdomsP = svc('Kingdom/GetKingdoms', { x: 1 }).then(function (d) {
                    if (!d || !d.Kingdoms) { throw new Error('no kingdoms'); }
                    var raw = d.Kingdoms, list = [];
                    for (var k in raw) {
                        if (raw.hasOwnProperty(k)) {
                            list.push({ id: +raw[k].KingdomId, name: noThe(clean(raw[k].KingdomName)), parent: +raw[k].ParentKingdomId || 0 });
                        }
                    }
                    list.sort(byName);
                    C.kingdomsMem = list;
                    return list;
                });
                C.kingdomsP.then(null, function () { C.kingdomsP = null; });
            }
            return C.kingdomsP;
        }
        function nameOf(id) {
            var list = C.kingdomsMem || [];
            for (var i = 0; i < list.length; i++) { if (list[i].id === id) { return list[i].name; } }
            return '';
        }
        // With IncludePrincipalityInStatistics on, the page rolls its principalities' parks into the park count.
        // The flag is one cheap read, made behind the header: off, the count is the kingdom's own parks at once.
        // On, it takes one read per principality, so that part runs only when PARKS is opened.
        function rollFlag(p) {
            return memo(p.stat, 'flag', function () {
                return svc('Kingdom/GetKingdomDetails', { KingdomId: p.id }).then(function (dt) {
                    if (!dt) { throw new Error('no details'); }
                    var flag = dt.KingdomConfiguration && dt.KingdomConfiguration.IncludePrincipalityInStatistics;
                    return !!flag && +flag.Value === 1;
                });
            });
        }
        // The count when the flag is off; parkCount stays null when a read fails or the flag is on.
        function plainCount(p) {
            return Promise.all([rollFlag(p), kParks(p)]).then(function (r) {
                if (!r[0]) { p.parkCount = p.parks.length; }
                return p;
            });
        }
        function rollUp(p) {
            return soft(rollFlag(p)).then(function (on) {
                if (on === null) { return p; }
                if (!on) { p.parkCount = p.parks.length; return p; }
                return Promise.all(p.prinz.map(function (k) { return soft(parksOf(k.id)); })).then(function (kids) {
                    var n = p.parks.length;
                    for (var i = 0; i < kids.length && n !== null; i++) { n = kids[i] ? n + kids[i].length : null; }
                    p.parkCount = n;
                    return p;
                });
            });
        }
        // Arms for a Kingdom, Park, Unit or Event as a 1-bit bitmap; null when it has none or the image will not load.
        var armsP = C.armsP;
        function loadArms(type, id) {
            var key = type + id;
            if (!armsP[key]) {
                armsP[key] = svc('Heraldry/GetHeraldryUrl', { Type: type, Id: id }).then(function (d) {
                    return loadImage(d && d.Url ? sameOrigin(d.Url) : '');
                }).then(function (img) { return renderArt(img); });
                armsP[key].then(null, function () { delete armsP[key]; });
            }
            return armsP[key];
        }
        // The kingdom header waits only for its record, arms, officers and principalities. The counts (parks, events,
        // averages) load behind it and fill in on screen as they arrive (P.patch); each is kept on p.stat once read.
        function loadProfile(id) {
            if (profileP[id]) { return profileP[id]; }
            var infoP = svc('Kingdom/GetKingdomShortInfo', { KingdomId: id }).then(function (d) {
                if (!d || !d.KingdomInfo || !d.KingdomInfo.KingdomName) { throw new Error('no kingdom'); }
                return d.KingdomInfo;
            });
            var artP = infoP.then(function (info) {
                return +info.HasHeraldry ? loadArms('Kingdom', id) : null;
            });
            var pr = Promise.all([
                infoP,
                soft(svc('Kingdom/GetOfficers', { KingdomId: id }).then(function (d) { return listOf(d, 'Officers'); })),
                soft(svc('Kingdom/GetPrincipalities', { KingdomId: id }).then(function (d) { return listOf(d, 'Principalities'); })),
                soft(loadKingdoms()),
                soft(artP)
            ]).then(function (r) {
                var info = r[0];
                var prinz = r[2] ? r[2].map(function (k) { return { id: +k.KingdomId, name: noThe(clean(k.Name)) }; }).sort(byName) : null;
                var p = {
                    id: id, name: info.KingdomName, abbr: info.Abbreviation, isPrinz: !!(+info.IsPrincipality),
                    parentName: nameOf(+info.ParentKingdomId || 0),
                    officers: r[1], prinz: prinz, art: r[4], events: null, parks: null,
                    parkCount: null, eventCount: null, avgWeek: null, avgMonth: null,
                    parkAvg: function () { return '--'; },
                    stat: {}
                };
                // A kingdom with principalities may roll their parks into its count: that count waits for PARKS.
                p.rolls = !p.isPrinz && !!(prinz && prinz.length);
                profileMem[id] = p;
                // A header with a failed read is shown, but not kept: the next visit asks again.
                if (r[1] === null || r[2] === null || (+info.HasHeraldry && !r[4])) { delete profileP[id]; }
                profileStats(id);
                return p;
            });
            profileP[id] = pr;
            pr.then(null, function () { delete profileP[id]; });
            return pr;
        }
        function kParks(p) {
            return memo(p.stat, 'parks', function () {
                return parksOf(p.id).then(function (parks) {
                    p.parks = parks.slice().sort(function (a, b) { var x = clean(a.ParkName), y = clean(b.ParkName); return x < y ? -1 : (x > y ? 1 : 0); });
                    if (!p.rolls) { p.parkCount = parks.length; }
                    return p.parks;
                });
            });
        }
        function kAvg(p) {
            return memo(p.stat, 'avg', function () {
                return averagesOf(p.id).then(function (avg) {
                    // The per-week figures the server worked out (wk), as knFillAverages in Kingdomnew_index.tpl shows them.
                    var kg = avg._kingdom || {};
                    p.avgWeek = (+kg.wk || 0).toFixed(1);
                    p.avgMonth = (+kg.mo || 0).toFixed(1);
                    p.parkAvg = function (parkId) { return avg[parkId] ? (+avg[parkId].wk || 0).toFixed(1) : '--'; };
                    return avg;
                });
            });
        }
        function kEvents(p) {
            return memo(p.stat, 'events', function () {
                return eventsOf(p.id).then(function (events) {
                    p.events = sortEvents(events);
                    p.eventCount = events.length;
                    return p.events;
                });
            });
        }
        // The count reads behind a kingdom header, started (or retried after a failure) each time it is shown.
        function profileStats(id) {
            var p = profileMem[id];
            if (!p) { return []; }
            var list = [p.rolls ? plainCount(p) : kParks(p), kAvg(p), kEvents(p)];
            list.forEach(function (x) { x.then(null, function () { /* the header keeps -- */ }); });
            return list;
        }
        // PARKS: the list, the per-park averages and, only here, the principality roll-up of the count.
        function kingdomParks(id) {
            var p = profileMem[id];
            if (!p) { return Promise.reject(new Error('no kingdom')); }
            var roll = memo(p.stat, 'roll', function () {
                return kParks(p).then(function () { return p.rolls ? rollUp(p) : p; }).then(function () {
                    if (p.parkCount === null) { delete p.stat.roll; }   // a failed principality read: ask again next time
                    return p;
                });
            });
            return Promise.all([roll, soft(kAvg(p))]);
        }
        /* ---- players: search, then the public (logged-out) profile ---- */
        function searchPlayers(text) {
            if (!uiBase) { return Promise.reject(new Error('no ui')); }
            // Same endpoint and parameters as the site's nav search (no inactive flag), the text as typed.
            return getJson(uiBase + 'SearchAjax/universal&q=' + encodeURIComponent(text)).then(function (d) {
                if (!d || !Array.isArray(d.players)) { throw new Error('bad search'); }
                // Parks the search finds come first (a missing parks list is just none).
                var parks = (Array.isArray(d.parks) ? d.parks : []).filter(function (r) { return +r.id > 0 && clean(r.name); }).map(function (r) {
                    return { kind: 'park', id: +r.id, name: clean(r.name), abbr: clean(r.abbr), active: 1 };
                });
                return parks.concat(d.players.map(function (r) {
                    return { kind: 'player', id: +r.id, name: clean(r.name), abbr: clean(r.abbr), park: clean(r.park), active: +r.active ? 1 : 0 };
                }));
            });
        }
        var playerP = C.playerP, playerMem = C.playerMem;
        function missing(msg) { var e = new Error(msg); e.notFound = true; return e; }
        function loadPlayer(id) {
            if (playerP[id]) { return playerP[id]; }
            var call = function (c, params) { return svc(c, params); };
            var pr = call('Player/GetPlayer', { MundaneId: id }).then(function (d) {
                var P0 = d && d.Player;
                if (!P0 || !+P0.MundaneId) { throw missing('no player'); }
                return P0;
            }, function (e) {
                // A service-level refusal (bad id) is "not found"; a network failure is an I/O error.
                throw (e && /service error/.test(String(e.message))) ? missing('no player') : e;
            }).then(function (P0) {
                var parkId = +P0.ParkId || 0, kingdomId = +P0.KingdomId || 0;
                var wherePr = parkId
                    ? svc('Park/GetParkShortInfo', { ParkId: parkId }).then(function (d) {
                        var pk = d && d.ParkInfo && d.ParkInfo.ParkName, kn = d && d.KingdomInfo && d.KingdomInfo.KingdomName;
                        return [pk, kn].filter(Boolean).join(', ');
                    })
                    : (kingdomId ? svc('Kingdom/GetKingdomShortInfo', { KingdomId: kingdomId }).then(function (d) { return d.KingdomInfo.KingdomName; }) : Promise.resolve(''));
                var att = function (extra) {
                    var q = { MundaneId: id, limit: 1 };
                    for (var k in extra) { if (extra.hasOwnProperty(k)) { q[k] = extra[k]; } }
                    return svc('Player/AttendanceForPlayer', q).then(function (d) { return listOf(d, 'Attendance'); });
                };
                return Promise.all([soft(wherePr), soft(att({ order: 'desc' })), soft(att({ order: 'asc', date_start: '1988-01-01' }))]).then(function (r) {
                    // A record with a failed read is shown, but not kept: the next visit asks again.
                    if (r[0] === null || r[1] === null || r[2] === null) { delete playerP[id]; }
                    var active = +P0.IsActive === 1;
                    var dues = { paid: 'PAID', expired: 'EXPIRED' }[P0.DuesStatus] || 'NONE ON FILE';
                    var name = (P0.NamePrefix ? P0.NamePrefix + ' ' : '') + P0.Persona;
                    if (P0.NameSuffix) { name += (+P0.SuffixComma ? ', ' : ' ') + P0.NameSuffix; }
                    var day = function (rows) { return rows === null ? '--' : (rows.length ? fmtDate(rows[0].Date) : 'N/A'); };
                    // Only the fields the public page shows; nothing from the account, the real name or the notes is kept.
                    var p = {
                        id: id, parkId: parkId, kingdomId: kingdomId, name: name, pronunciation: P0.PronunciationGuide || '', pronouns: P0.PronounCustomText || P0.PronounText || '',
                        where: r[0] || '', status: active ? 'ACTIVE' : 'INACTIVE',
                        since: day(r[2]), last: day(r[1]), dues: dues,
                        reeve: qualWord(P0.ReeveStatus, P0.ReeveQualifiedUntil), corpora: qualWord(P0.CorporaStatus, P0.CorporaQualifiedUntil),
                        classes: null, awards: null, titles: null, attendance: null,
                        // The About text and the active companies: what the public page shows anyone.
                        persona: P0.Persona || '', aboutPersona: P0.AboutPersona || '', aboutStory: P0.AboutStory || '',
                        companies: Array.isArray(P0.Company) ? P0.Company.filter(function (u) { return +u.UnitId > 0 && clean(u.Name); }).map(function (u) { return { id: +u.UnitId, name: u.Name, type: u.Type }; }) : null
                        };
                    playerMem[id] = p;
                    return p;
                });
            });
            playerP[id] = pr;
            pr.then(null, function () { delete playerP[id]; });
            return pr;
        }
        // The long lists, fetched only when picked and kept for the mount.
        function loadPlayerList(id, kind) {
            var p = playerMem[id];
            if (p[kind]) { return Promise.resolve(p[kind]); }
            if (kind === 'classes' || kind === 'attendance' || kind === 'milestones') {
                return memo(p._listP || (p._listP = {}), kind, function () { return playerList(p, id, kind); });
            }
            return playerList(p, id, kind);
        }
        function playerList(p, id, kind) {
            var pr;
            if (kind === 'classes') {
                pr = svc('Player/ComputeClassProgress', { MundaneId: id }).then(function (d) { return listOf(d, 'Detail'); });
            } else if (kind === 'attendance') {
                pr = svc('Player/AttendanceForPlayer', { MundaneId: id }).then(function (d) { return listOf(d, 'Attendance'); });
            } else if (kind === 'milestones') {
                pr = svc('Player/GetPlayerMilestones', { MundaneId: id, ApplyVisibility: 1 }).then(function (d) { return milestoneRows(listOf(d, 'Detail')); });
            } else {
                if (p._awardsP) { pr = p._awardsP; } else {
                    pr = p._awardsP = svc('Player/AwardsForPlayer', { MundaneId: id }).then(function (d) {
                        var all = listOf(d, 'Awards'), awards = [], titles = [];
                        all.forEach(function (a) { (+a.IsTitleEffective === 1 ? titles : awards).push(a); });
                        return { awards: awards, titles: titles };
                    });
                }
                pr = pr.then(function (x) { p.awards = x.awards; p.titles = x.titles; return x[kind]; });
                pr.then(null, function () { p._awardsP = null; });
                return pr;
            }
            return pr.then(function (rows) { p[kind] = rows; return rows; });
        }
        // Awards and the ladder bars together: true when the player has ladders to show first.
        function awardsEntry(id) {
            var p = playerMem[id];
            var lad = p.ladders ? Promise.resolve(p.ladders) : memo(p, '_ladP', function () {
                return soft(svc('Player/GetLadderProgress', { MundaneId: id }).then(function (d) { return listOf(d, 'Detail'); })).then(function (r) {
                    if (r) { p.ladders = r; } else { delete p._ladP; }   // a failed read shows no bars and is asked again next time
                    return r || [];
                });
            });
            return Promise.all([loadPlayerList(id, 'awards'), lad]).then(function (r) { return r[1].length > 0; });
        }
        // The home park's next meeting: the kingdom calendar's park-day rows (the server expands every recurrence,
        // every-x-weeks included) for the next five weeks, earliest in-person one first (online meetings are left out,
        // as on the park page; the park's own day list says which are online). false when it meets on none of them.
        function nextParkDay(parkId, kid) {
            return memo(C.nextDayP, parkId, function () {
                if (!uiBase) { return Promise.reject(new Error('no ui')); }
                var today = serverToday(), end = new Date(Date.UTC(+today.slice(0, 4), +today.slice(5, 7) - 1, +today.slice(8, 10)) + 35 * 86400000);
                return Promise.all([
                    getJson(uiBase + 'KingdomAjax/calendar/' + kid + '&start=' + today + '&end=' + stamp(end).slice(0, 10)),
                    svc('Park/GetParkDays', { ParkId: parkId }).then(function (d) { return listOf(d, 'ParkDays'); })
                ]).then(function (res) {
                    var best = null;
                    listOf(res[0], 'events').forEach(function (e) {
                        var r = calRow(e);
                        if (r && r.kind === 'P' && r.parkId === parkId && r.start.slice(0, 10) >= today && (!best || r.start < best.start) && parkDayInPerson(r, res[1])) { best = r; }
                    });
                    return best || false;
                });
            });
        }
        // MY AMTGARD: the home park's next park day, the next three RSVPs, the three most recently played classes
        // with credits, the latest award.
        function loadMy(id) {
            var p = playerMem[id];
            if (p.my) { return Promise.resolve(p.my); }
            return memo(p, '_myP', function () { return readMy(p, id); });
        }
        function readMy(p, id) {
            // undefined leaves the line out (no home park); null prints (UNAVAILABLE).
            var parkDay = (p.parkId > 0 && p.kingdomId > 0) ? soft(nextParkDay(p.parkId, p.kingdomId)) : Promise.resolve(undefined);
            var rsvps = soft((uiBase ? getJson(uiBase + 'EventRsvpAjax/mine') : Promise.reject(new Error('no ui'))).then(function (d) {
                if (!d || +d.status !== 0) { throw new Error('no rsvps'); }
                return listOf(d, 'UpcomingRsvps');
            }))
                .then(function (r) { return r && r.slice().sort(function (a, b) { return String(a.EventStart) < String(b.EventStart) ? -1 : 1; }).slice(0, 3); });
            var classes = soft(Promise.all([loadPlayerList(id, 'attendance'), loadPlayerList(id, 'classes')]).then(function (r) {
                var byId = {}, seen = {}, out = [];
                r[1].forEach(function (c) { byId[c.ClassId] = c; });
                // Attendance is newest first; the page's own rule: distinct classes, three of them, credits above zero.
                for (var i = 0; i < r[0].length && Object.keys(seen).length < 3; i++) {
                    var cid = +r[0][i].ClassId;
                    if (cid > 0 && !seen[cid]) { seen[cid] = true; if (byId[cid] && Math.floor(byId[cid].Credits) > 0) { out.push(byId[cid]); } }
                }
                return out;
            }));
            var award = soft(Promise.all([loadPlayerList(id, 'awards'), loadPlayerList(id, 'titles')]).then(function (r) {
                var all = sortNewest(r[0].concat(r[1]), 'Date', 'AwardsId');
                return all.length ? [all[0]] : [];
            }));
            return Promise.all([rsvps, classes, award, parkDay]).then(function (r) {
                var my = { rsvps: r[0], classes: r[1], award: r[2], parkDay: r[3] };
                // A screen with a failed read is shown, but not kept: the next visit asks again.
                if (r[1] !== null && r[2] !== null && r[3] !== null) { p.my = my; } else { delete p._myP; }
                return my;
            });
        }
        /* ---- parks: the header loads with the park; each menu list loads when picked and is kept for the mount ---- */
        var parkP = C.parkP, parkMem = C.parkMem;
        function loadPark(id) {
            id = parseInt(id, 10) || 0;
            if (!(id > 0)) { return Promise.reject(missing('no park')); }
            if (parkP[id]) { return parkP[id]; }
            var pr = svc('Park/GetParkShortInfo', { ParkId: id }).then(function (d) {
                if (!d || !d.ParkInfo || !d.ParkInfo.ParkName) { throw missing('no park'); }
                return d;
            }, function (e) {
                throw (e && /service error/.test(String(e.message))) ? missing('no park') : e;
            }).then(function (d) {
                var pi = d.ParkInfo, ki = d.KingdomInfo || {}, kid = +pi.KingdomId || +ki.KingdomId || 0;
                var detailsP = soft(svc('Park/GetParkDetails', { ParkId: id }).then(function (x) {
                    if (!x || !x.ParkName) { throw new Error('no details'); }
                    return x;
                }));
                var avgP = kid ? averagesOf(kid) : Promise.reject(new Error('no kingdom'));
                return Promise.all([
                    detailsP,
                    soft(svc('Report/GetParkFieldCountToday', { ParkId: id }).then(function (x) { return typeof x.Count === 'number' ? { count: x.Count, parkDay: +x.IsParkDay === 1 } : null; })),
                    soft(avgP),
                    soft(+pi.HasHeraldry ? loadArms('Park', id) : Promise.resolve(null))
                ]).then(function (r) {
                    var avg = r[2], mine = avg && avg[id];
                    var fig = function (v) { return v > 0 ? v.toFixed(1) : null; };
                    var p = {
                        id: id, name: pi.ParkName, abbr: pi.Abbreviation, kingdomId: kid, kingdomAbbr: ki.Abbreviation || '',
                        retired: !!pi.Active && pi.Active !== 'Active', details: r[0], field: r[1] ? r[1].count : null, art: r[3],
                        // The field count shows only when today is an in-person park day (the service judges it on the server's date).
                        parkDayToday: !!r[1] && r[1].parkDay,
                        // The per-week and per-month figures the server already worked out for the kingdom page.
                        avgWeek: mine ? fig(+mine.wk || 0) : null, avgMonth: mine ? fig(+mine.mo || 0) : null,
                        lists: {}, listP: {}, days: {}
                    };
                    if (r[0]) { p.lists.details = r[0]; }
                    parkMem[id] = p;
                    // A header with a failed read is shown, but not kept: the next visit asks again.
                    if (r[0] === null || r[1] === null || (kid && r[2] === null) || (+pi.HasHeraldry && !r[3])) { delete parkP[id]; }
                    return p;
                });
            });
            parkP[id] = pr;
            pr.then(null, function () { delete parkP[id]; });
            return pr;
        }
        // Server clock minus ours is already known from the Date header; cut windows on the server's clock.
        function serverNow() { return new Date(Date.now() + C.skew); }
        // The app runs on America/Chicago time (config.dist.php), so "today" on the page is that date, not UTC's.
        function serverToday() {
            var d = serverNow();
            try {
                var parts = {};
                new Intl.DateTimeFormat('en-US', { timeZone: 'America/Chicago', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(d).forEach(function (x) { parts[x.type] = x.value; });
                if (parts.year && parts.month && parts.day) { return parts.year + '-' + parts.month + '-' + parts.day; }
            } catch (e) { /* no Intl: fall back to UTC */ }
            return stamp(d).slice(0, 10);
        }
        function loadParkList(id, kind) {
            var p = parkMem[id];
            if (!p) { return Promise.reject(new Error('no park')); }
            if (p.lists[kind]) { return Promise.resolve(p.lists[kind]); }
            if (p.listP[kind]) { return p.listP[kind]; }
            var pr, num = function (x) { return +x || 0; };
            if (kind === 'days') {
                pr = svc('Park/GetParkDays', { ParkId: id }).then(function (d) { return listOf(d, 'ParkDays'); });
            } else if (kind === 'details') {
                pr = svc('Park/GetParkDetails', { ParkId: id }).then(function (d) { if (!d || !d.ParkName) { throw new Error('no details'); } return d; });
            } else if (kind === 'officers') {
                pr = svc('Park/GetOfficers', { ParkId: id }).then(function (d) { return listOf(d, 'Officers'); });
            } else if (kind === 'recent') {
                pr = svc('Report/RecentParkAttendees', { ParkId: id }).then(function (d) {
                    return listOf(d, 'Attendees').slice().sort(function (a, b) {
                        var x = String(a.LastSignIn || ''), y = String(b.LastSignIn || '');
                        if (x !== y) { return x < y ? 1 : -1; }
                        return clean(a.Persona) < clean(b.Persona) ? -1 : 1;
                    });
                });
            } else if (kind === 'events') {
                // The page lists events from a week back onward.
                pr = svc('SearchService/Event', { park_id: id }, true).then(function (list) {
                    if (!Array.isArray(list)) { throw new Error('bad events'); }
                    var lo = stamp(new Date(serverNow().getTime() - 7 * 86400000));
                    return sortEvents(list.filter(function (e) { return e && String(e.NextDate || '') >= lo; }));
                });
            } else if (kind === 'companies') {
                pr = svc('Report/UnitSummary', { ParkId: id, IncludeCompanies: 1 }).then(function (d) {
                    return listOf(d, 'Units').filter(function (u) { return u.Type === 'Company'; });
                });
            } else if (kind === 'chart') {
                pr = svc('Report/GetMonthlyChartData', { ParkId: id, Periods: 6, PerMonths: 1, PerWeeks: 0 }).then(function (list) {
                    if (!Array.isArray(list)) { throw new Error('bad chart'); }
                    return list.filter(function (m) { return num(m.Month) >= 1 && num(m.Month) <= 12 && num(m.Year) > 0; });
                });
            } else if (kind === 'dates') {
                // The public attendance report covers the past six months; so does this list.
                pr = svc('Report/GetAttendanceDates', { Type: 'Park', Id: id }).then(function (d) {
                    // The page cuts on the server's local date, which can trail UTC by a day: start a day early so no listed date is lost.
                    var cut = new Date(serverNow().getTime() - 86400000);
                    cut.setUTCMonth(cut.getUTCMonth() - 6);
                    var lo = stamp(cut).slice(0, 10);
                    return listOf(d, 'Dates').filter(function (x) { return /^\d{4}-\d{2}-\d{2}$/.test(String(x)) && String(x) > lo; });
                });
            } else {
                return Promise.reject(new Error('no such list'));
            }
            p.listP[kind] = pr.then(function (rows) { p.lists[kind] = rows; return rows; });
            p.listP[kind].then(null, function () { delete p.listP[kind]; });
            return p.listP[kind];
        }
        // One day's sign-ins, only for a date the park's own list offered.
        function loadParkDay(id, date) {
            var p = parkMem[id];
            if (!p || !p.lists.dates || p.lists.dates.indexOf(date) < 0) { return Promise.reject(new Error('no such day')); }
            if (p.days[date]) { return Promise.resolve(p.days[date]); }
            return memo(p.dayP || (p.dayP = {}), date, function () {
                return svc('Report/AttendanceForDate', { ParkId: id, Date: date }).then(function (d) {
                    var rows = listOf(d, 'Attendance');
                    p.days[date] = rows;
                    return rows;
                });
            });
        }
        /* ---- events: a month of the kingdom calendar, one event with its lists, past events ---- */
        var calMem = C.calMem;
        function loadCalMonth(id, y, m) {
            var key = id + '-' + y + '-' + m;
            if (calMem[key]) { return Promise.resolve(calMem[key]); }
            if (!uiBase) { return Promise.reject(new Error('no ui')); }
            function two(n) { return (n < 10 ? '0' : '') + n; }
            var lo = y + '-' + two(m) + '-01', hi = (m === 12 ? y + 1 : y) + '-' + two(m === 12 ? 1 : m + 1) + '-01';
            return memo(C.calP, key, function () {
                return getJson(uiBase + 'KingdomAjax/calendar/' + id + '&start=' + lo + '&end=' + hi).then(function (d) {
                    var rows = listOf(d, 'events').map(calRow).filter(Boolean);
                    rows.sort(function (a, b) { return a.start < b.start ? -1 : (a.start > b.start ? 1 : (a.title < b.title ? -1 : (a.title > b.title ? 1 : 0))); });
                    calMem[key] = rows;
                    return rows;
                });
            });
        }
        var evP = C.evP, evMem = C.evMem;
        // One occurrence: its record, the event's own record (name, host, arms flag) and, when the occurrence names no
        // place, the hosting park's address, as the page does. Lists (description, RSVP counts, schedule, who came) load on demand.
        function loadEvent(eid, did, nameHint) {
            eid = parseInt(eid, 10) || 0; did = parseInt(did, 10) || 0;
            if (!(eid > 0 && did > 0)) { return Promise.reject(missing('no event')); }
            if (evP[did]) { return evP[did]; }
            var pr = svc('SearchService/CalendarDetail', { event_calendardetail_id: did }, true).then(function (det) {
                if (!det || +det.EventId !== eid) { throw missing('no event'); }
                // The event record doubles as the publish gate: SearchService/Event leaves draft events out, while the
                // occurrence record above answers for a draft too, and the page shows a draft only to people who can edit it.
                // A missing record (or a failed read) therefore stops the screen. PAST EVENTS comes from a report that lists drafts.
                var infoP = svc('SearchService/Event', { event_id: eid }, true).then(function (list) {
                    if (!(Array.isArray(list) && list[0] && +list[0].EventId === eid)) { throw missing('no event'); }
                    return list[0];
                });
                var atId = +det.AtParkId || 0, wantPark = evNoPlace(det) && atId > 0;
                var parkP = wantPark
                    ? soft(svc('Park/GetParkDetails', { ParkId: atId }).then(function (x) { if (!x || !x.ParkName) { throw new Error('no park'); } return x; }))
                    : Promise.resolve(null);
                return Promise.all([infoP, parkP]).then(function (r) {
                    var info = r[0];
                    return soft((info && +info.HasHeraldry) ? loadArms('Event', eid) : Promise.resolve(null)).then(function (art) {
                        var e = { eid: eid, did: did, name: (info && info.Name) || nameHint || 'EVENT', detail: det, info: info, atPark: r[1], art: art, lists: {}, listP: {} };
                        evMem[did] = e;
                        // A screen with a failed read is shown, but not kept: the next visit asks again.
                        if ((wantPark && !r[1]) || (+info.HasHeraldry && !art)) { delete evP[did]; }
                        return e;
                    });
                });
            });
            evP[did] = pr;
            pr.then(null, function () { delete evP[did]; });
            return pr;
        }
        function loadEventList(did, kind) {
            var e = evMem[did];
            if (!e) { return Promise.reject(new Error('no event')); }
            if (e.lists[kind]) { return Promise.resolve(e.lists[kind]); }
            if (e.listP[kind]) { return e.listP[kind]; }
            var pr;
            if (kind === 'desc') {
                pr = Promise.resolve(eventDescLines(e));
            } else if (kind === 'rsvp') {
                pr = svc('Event/GetRsvpCounts', { EventCalendarDetailId: did }).then(function (d) {
                    if (!d || typeof d.Going !== 'number' || typeof d.Interested !== 'number') { throw new Error('bad counts'); }
                    return { going: d.Going, interested: d.Interested, total: typeof d.Total === 'number' ? d.Total : d.Going + d.Interested };
                });
            } else if (kind === 'sched') {
                pr = uiBase ? getJson(uiBase + 'EventEmbed/schedule/' + e.eid + '/' + did).then(function (d) {
                    if (!d || !d.ok || !Array.isArray(d.days)) { throw new Error('bad schedule'); }
                    return schedPages(d.days);
                }) : Promise.reject(new Error('no ui'));
            } else if (kind === 'who') {
                // The report answers a parameter error (status 4) when nobody signed in; the ids here are our own, so that is an empty list.
                pr = svc('Report/AttendanceForEvent', { EventId: e.eid, EventCalendarDetailId: did }).then(function (d) {
                    return listOf(d, 'Attendance').slice().sort(function (a, b) { var x = clean(a.Persona), y = clean(b.Persona); return x < y ? -1 : (x > y ? 1 : 0); });
                }, function (err) { if (err && err.status === 4) { return []; } throw err; });
            } else {
                return Promise.reject(new Error('no such list'));
            }
            e.listP[kind] = pr.then(function (rows) { e.lists[kind] = rows; return rows; });
            e.listP[kind].then(null, function () { delete e.listP[kind]; });
            return e.listP[kind];
        }
        // Events that are over, newest first. The report also carries future occurrences and undated ones; both stay out.
        var pastP = C.pastP, pastMem = C.pastMem;
        function loadPast(scope, id) {
            var key = scope + id;
            if (pastMem[key]) { return Promise.resolve(pastMem[key]); }
            if (pastP[key]) { return pastP[key]; }
            var pr = svc('Report/EventAttendanceReport', scope === 'Park' ? { ParkId: id } : { KingdomId: id }).then(function (d) {
                var today = serverToday();
                var rows = listOf(d, 'Events').filter(function (r) {
                    var ref = validDay(String(r.EventEnd || '').slice(0, 10)) ? String(r.EventEnd).slice(0, 10) : String(r.EventStart || '').slice(0, 10);
                    return +r.DetailId > 0 && +r.EventId > 0 && validDay(ref) && ref < today;
                });
                rows.sort(function (a, b) { return a.EventStart < b.EventStart ? 1 : (a.EventStart > b.EventStart ? -1 : 0); });
                pastMem[key] = rows;
                return rows;
            });
            pastP[key] = pr;
            pr.then(null, function () { delete pastP[key]; });
            return pr;
        }
        /* ---- companies and households ---- */
        // A list row's leaders are the personas of active captains and lords, as on the website's unit list.
        var unitKP = C.unitKP, unitKMem = C.unitKMem, unitLeaders = C.unitLeaders, unitP = C.unitP, unitMem = C.unitMem;
        function unitRowOf(u) {
            var id = +u.UnitId;
            unitLeaders[id] = String(u.LeaderNames === null || u.LeaderNames === undefined ? '' : u.LeaderNames);
            return { id: id, name: u.Name, type: u.Type, leaders: unitLeaders[id], active: 0 };
        }
        // The website's default unit list for a kingdom: the 100 largest units (TopBySize), then the 12 month activity
        // counts the page fetches 25 ids at a time. Retired units are not in the report unless asked for.
        function loadKingdomUnits(kid) {
            kid = parseInt(kid, 10) || 0;
            if (!(kid > 0)) { return Promise.reject(new Error('no kingdom')); }
            if (unitKMem[kid]) { return Promise.resolve(unitKMem[kid]); }
            if (unitKP[kid]) { return unitKP[kid]; }
            if (!uiBase) { return Promise.reject(new Error('no ui')); }
            var pr = svc('Report/UnitSummary', { KingdomId: kid, IncludeCompanies: 1, IncludeHouseHolds: 1, TopBySize: 1, Limit: UNIT_CAP }).then(function (d) {
                var rows = listOf(d, 'Units').filter(function (u) {
                    return +u.UnitId > 0 && clean(u.Name) && (u.Type === 'Company' || u.Type === 'Household') && u.Active !== 'Retired';
                }).map(function (u) { var r = unitRowOf(u); r.size = +u.MemberCount || 0; return r; });
                var batches = [];
                for (var i = 0; i < rows.length; i += 25) { batches.push(rows.slice(i, i + 25)); }
                return Promise.all(batches.map(function (b) {
                    return getJson(uiBase + 'Search/unitactivity&ids=' + b.map(function (r) { return r.id; }).join(',')).then(function (m) {
                        if (!m || typeof m !== 'object' || Array.isArray(m)) { throw new Error('bad activity'); }
                        b.forEach(function (r) { r.active = +m[r.id] || 0; });
                    });
                })).then(function () {
                    rows.sort(function (a, b) { var x = clean(a.name), y = clean(b.name); return (b.size - a.size) || (x < y ? -1 : (x > y ? 1 : 0)); });
                    unitKMem[kid] = rows;
                    return rows;
                });
            });
            unitKP[kid] = pr;
            pr.then(null, function () { delete unitKP[kid]; });
            return pr;
        }
        // One unit: its record, arms, and leaders (from a list row we hold, else the unit list search by exact name).
        function loadUnit(id) {
            id = parseInt(id, 10) || 0;
            if (!(id > 0)) { return Promise.reject(missing('no unit')); }
            if (unitP[id]) { return unitP[id]; }
            var pr = svc('Unit/GetUnit', { UnitId: id }).then(function (d) {
                if (!d || !d.Unit || +d.Unit.UnitId !== id || !clean(d.Unit.Name)) { throw missing('no unit'); }
                return d.Unit;
            }, function (e) {
                throw (e && /service error/.test(String(e.message))) ? missing('no unit') : e;
            }).then(function (u) {
                var leadersP = unitLeaders.hasOwnProperty(id) ? Promise.resolve(unitLeaders[id]) : soft(uiBase ? getJson(uiBase + 'Search/unitsearch&q=' + encodeURIComponent(String(u.Name).replace(/^\s+|\s+$/g, ''))).then(function (list) {
                    if (!Array.isArray(list)) { throw new Error('bad unit list'); }
                    for (var i = 0; i < list.length; i++) { if (+list[i].UnitId === id) { return unitLeaders[id] = String(list[i].LeaderNames || ''); } }
                    return unitLeaders[id] = null;
                }) : Promise.reject(new Error('no ui')));
                return Promise.all([leadersP, soft(+u.HasHeraldry ? loadArms('Unit', id) : Promise.resolve(null))]).then(function (r) {
                    var m = {
                        id: id, name: u.Name, type: u.Type, retired: u.Active === 'Retired', desc: u.Description, hist: u.History, url: u.Url,
                        leaders: r[0] === undefined ? null : r[0], art: r[1]
                    };
                    unitMem[id] = m;
                    // A screen with a failed read is shown, but not kept: the next visit asks again.
                    if (!unitLeaders.hasOwnProperty(id) || (+u.HasHeraldry && !r[1])) { delete unitP[id]; }
                    return m;
                });
            });
            unitP[id] = pr;
            pr.then(null, function () { delete unitP[id]; });
            return pr;
        }
        // SEARCH THE ORK: the site's nav search. Kingdoms the KINGDOMS list does not carry (retired) stay out.
        function searchOrk(text) {
            if (!uiBase) { return Promise.reject(new Error('no ui')); }
            return getJson(uiBase + 'SearchAjax/universal&q=' + encodeURIComponent(text)).then(function (d) {
                if (!d || !Array.isArray(d.players)) { throw new Error('bad search'); }
                var arr = function (x) { return Array.isArray(x) ? x : []; };
                return soft(loadKingdoms()).then(function (known) {
                    var ids = {};
                    (known || []).forEach(function (k) { ids[k.id] = true; });
                    var keep = function (r) { return +r.id > 0 && clean(r.name); };
                    var rows = [];
                    d.players.filter(keep).forEach(function (r) {
                        rows.push({ kind: 'player', id: +r.id, name: clean(r.name), tag: (clean(r.abbr) ? '(' + clean(r.abbr) + ')' : '') + (+r.active ? '' : ' (INACTIVE)') });
                    });
                    arr(d.parks).filter(keep).forEach(function (r) { rows.push({ kind: 'park', id: +r.id, name: clean(r.name), tag: clean(r.abbr) }); });
                    arr(d.kingdoms).filter(function (r) { return keep(r) && (!known || ids[+r.id]); }).forEach(function (r) {
                        rows.push({ kind: 'kingdom', id: +r.id, name: noThe(clean(r.name)), tag: clean(r.abbr) });
                    });
                    ['Company', 'Household'].forEach(function (t) {
                        arr(d.units).filter(function (r) { return keep(r) && r.unitType === t; }).forEach(function (r) {
                            rows.push({ kind: t === 'Company' ? 'company' : 'household', id: +r.id, name: clean(r.name), tag: '' });
                        });
                    });
                    return rows;
                });
            });
        }
        /* ---- THE ORK TODAY: each read mirrors a public page (home page, Top Parks, Recap, Live, Weather) ---- */
        function loadEp() {
            if (C.epMem) { return Promise.resolve(C.epMem); }
            if (C.epP) { return C.epP; }
            if (!uiBase) { return Promise.reject(new Error('no ui')); }
            var pr = C.epP = getJson(uiBase + 'EraPhoenice/today').then(function (d) {
                var ep = epOf(d);
                if (!ep) { throw new Error('bad date'); }
                return (C.epMem = ep);
            });
            pr.then(null, function () { if (C.epP === pr) { C.epP = null; } });
            return pr;
        }
        var todayMem = C.todayMem, weekMem = C.weekMem;
        function sixMonthsBack(day) {
            var d = new Date(Date.UTC(+day.slice(0, 4), +day.slice(5, 7) - 1, +day.slice(8, 10)));
            d.setUTCMonth(d.getUTCMonth() - 6);
            return stamp(d).slice(0, 10);
        }
        function loadWeek(kid) {
            kid = parseInt(kid, 10) || 0;
            if (weekMem[kid]) { return Promise.resolve(weekMem[kid]); }
            if (!uiBase) { return Promise.reject(new Error('no ui')); }
            return memo(C.weekP, kid, function () {
                return getJson(uiBase + (kid ? 'Recap/json_kingdom/' + kid : 'Recap/json')).then(function (d) {
                    if (!d || typeof d !== 'object' || Array.isArray(d)) { throw new Error('bad recap'); }
                    var recap = (d.Status === 'not_computed' || !d.WeekStart) ? { empty: true } : d;
                    var w = recap.empty ? { rows: [], groups: [] } : weekRows(recap);
                    return (weekMem[kid] = { recap: recap, rows: w.rows, groups: w.groups });
                });
            });
        }
        // Read again each time it is opened; a second ask while one is out shares it.
        function loadLive() {
            if (!uiBase) { return Promise.reject(new Error('no ui')); }
            if (C.liveP) { return C.liveP; }
            var pr = C.liveP = Promise.all([getJson(uiBase + 'Live/stats'), soft(getJson(uiBase + 'Live/recent'))]).then(function (r) {
                var s = r[0];
                if (!s || typeof s !== 'object' || typeof s.active_3h !== 'number') { throw new Error('bad live'); }
                var recent = r[1] && typeof r[1] === 'object' && Array.isArray(r[1].signins) ? recentRows(r[1], s) : null;
                return (C.liveMem = { active3h: s.active_3h, rows: liveRows(s), recent: recent });
            });
            var done = function () { if (C.liveP === pr) { C.liveP = null; } };
            pr.then(done, done);
            return pr;
        }
        // One THE ORK TODAY screen's data, kept for the mount except the live feed, which is read again each time.
        function loadToday(kind, kid) {
            if (kind === 'TWEEK') { return loadWeek(kid); }
            if (kind === 'TLIVE') { return loadLive(); }
            if (todayMem[kind]) { return Promise.resolve(todayMem[kind]); }
            if (C.todayP[kind]) { return C.todayP[kind]; }
            var pr;
            if (kind === 'TSTAND') {
                pr = svc('Report/GetActiveKingdomsSummary', {}).then(function (d) {
                    return standingRows(listOf(d, 'ActiveKingdomsSummaryList'));
                });
            } else if (kind === 'TPARKS') {
                // The Top Parks page: 25 parks, the six months to today, ranked by average players a week.
                var end = serverToday(), start = sixMonthsBack(end);
                pr = svc('Report/GetTopParksByAttendance', { Limit: 25, StartDate: start, EndDate: end }).then(function (d) {
                    var rows = listOf(d, 'TopParksSummary').filter(function (p) { return +p.ParkId > 0 && clean(p.ParkName); }).map(function (p) {
                        return { id: +p.ParkId, name: p.ParkName, avg: +p.AvgPerWeek || 0 };
                    });
                    return { rows: rows, start: start, end: end };
                });
            } else if (kind === 'TWX') {
                pr = svc('WeatherService/GetPlayForDate', { date: serverToday(), Token: '' }, true).then(function (list) {
                    if (!Array.isArray(list)) { throw new Error('bad weather'); }
                    return { total: list.length, rows: wxFlagged(list) };
                });
            } else {
                return Promise.reject(new Error('no such screen'));
            }
            return memo(C.todayP, kind, function () { return pr.then(function (v) { return (todayMem[kind] = v); }); });
        }
        // A kingdom's knights and masters: the report is read scoped to the kingdom and kept to the named fields.
        var kamMem = C.kamMem;
        function loadKam(id) {
            id = parseInt(id, 10) || 0;
            if (!(id > 0)) { return Promise.reject(new Error('no kingdom')); }
            if (kamMem[id]) { return Promise.resolve(kamMem[id]); }
            return memo(C.kamP, id, function () {
                return svc('Report/PlayerAwards', { KingdomId: id, ParkId: 0, IncludeKnights: 1, IncludeMasters: 1 }).then(function (d) {
                    return (kamMem[id] = kamRows(listOf(d, 'Awards')));
                });
            });
        }
        P.data = {
            epToday: loadEp,
            epPeek: function () { return C.epMem; },
            today: loadToday,
            peekToday: function (kind) { return todayMem[kind]; },
            peekWeek: function (kid) { return weekMem[parseInt(kid, 10) || 0]; },
            peekLive: function () { return C.liveMem; },
            skew: function () { return C.skew; },
            kingdomName: nameOf,
            kingdomKam: loadKam,
            peekKam: function (id) { return kamMem[id] || []; },
            kingdomUnits: loadKingdomUnits,
            peekKingdomUnits: function (id) { return unitKMem[id]; },
            unit: loadUnit,
            peekUnit: function (id) { return unitMem[id]; },
            searchOrk: searchOrk,
            calMonth: loadCalMonth,
            peekCal: function (id, y, m) { return calMem[id + '-' + y + '-' + m] || []; },
            now: function () { var t = serverToday(); return { y: +t.slice(0, 4), m: +t.slice(5, 7) }; },
            event: loadEvent,
            peekEvent: function (did) { return evMem[did]; },
            eventList: loadEventList,
            past: loadPast,
            peekPast: function (scope, id) { return pastMem[scope + id] || []; },
            park: loadPark,
            peekPark: function (id) { return parkMem[id]; },
            parkList: loadParkList,
            parkDay: loadParkDay,
            user: userId,
            myAmtgard: loadMy,
            awardsEntry: awardsEntry,
            search: searchPlayers,
            player: loadPlayer,
            peekPlayer: function (id) { return playerMem[id]; },
            playerList: loadPlayerList,
            kingdoms: loadKingdoms,
            kingdomList: function () { return C.kingdomsMem || []; },
            profile: loadProfile,
            profileStats: profileStats,
            kingdomParks: kingdomParks,
            peek: function (id) { return profileMem[id]; }
        };

        /* ---- games: lazy-loaded files that run against the host below (see games/host-contract.md) ---- */
        function sendKey(key) {
            var g = game, cb = keyCb;
            if (!g || !cb) { return; }
            guard(g, function () { cb(key); });
        }
        function guard(g, fn) {
            if (destroyed || game !== g) { return; }
            try { fn(); } catch (err) {
                if (window.console) { console.error('ORKPLE game failed', err); }
                if (game === g) { P.print('?I/O ERROR'); P.beep(); quitGame(); }
            }
        }
        function flushQueue() {
            queue = [];
            if (pumpT) { clearTimeout(pumpT); delete timers[pumpT]; pumpT = 0; }
            if (waitT) { clearTimeout(waitT); delete timers[waitT]; waitT = 0; }
        }
        function endGame() {
            for (var id in gameTimers) { if (gameTimers.hasOwnProperty(id)) { clearInterval(gameTimers[id]); clearTimeout(gameTimers[id]); } }
            gameTimers = {};
            game = null; gameCb = null; keyCb = null; rawOn = false; keyNoRepeat = false;
            spriteDefs = {}; spriteList = [];
            splash = null;
            stopDisk();
            accepting = false;
            try { input.value = ''; } catch (e) { /* ignore */ }
            flushQueue();
        }
        function quitGame() {
            if (!game) { return; }
            endGame();
            P.go('ORK_GAMES');
        }
        function loadGame(id) {
            return new Promise(function (resolve, reject) {
                var reg = window.OrkpleGames && window.OrkpleGames[id];
                if (reg && typeof reg.start === 'function') { resolve(reg); return; }
                if (GAMES.indexOf(id) < 0 || !GAME_BASE) { reject(new Error('no such game')); return; }
                var el = document.createElement('script'), t = setTimeout(function () { fail(); }, FETCH_MS);
                function fail() { clearTimeout(t); el.onload = el.onerror = null; if (el.parentNode) { el.parentNode.removeChild(el); } reject(new Error('game not found')); }
                el.onload = function () {
                    clearTimeout(t);
                    var r = window.OrkpleGames && window.OrkpleGames[id];
                    if (r && typeof r.start === 'function') { resolve(r); } else { fail(); }
                };
                el.onerror = fail;
                el.src = GAME_BASE + 'games/' + id + '.js?v=' + (GAME_VER || Date.now());
                document.head.appendChild(el);
            });
        }
        function makeHost(g) {
            function live(fn) { return function () { if (game === g && !destroyed) { return fn.apply(null, arguments); } }; }
            function cell(c, r) { return c >= 0 && c < COLS && r >= 0 && r < ROWS; }
            function put(c, r, ch, inv) {
                c = Math.floor(c); r = Math.floor(r);
                if (!cell(c, r)) { return; }
                var ch1 = String(ch === undefined || ch === null ? ' ' : ch).charAt(0) || ' ';
                scr[r][c].ch = G[ch1] ? ch1 : '?';
                scr[r][c].inv = !!inv;
            }
            function rnd() {
                if (!rngState) { return Math.random(); }
                rngState = (rngState + 0x6D2B79F5) | 0;
                var t = Math.imul(rngState ^ (rngState >>> 15), 1 | rngState);
                t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
                return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
            }
            function timer(ms, fn, repeat) {
                var id = ++gameSeq;
                var run = function () {
                    if (!repeat) { delete gameTimers[id]; }
                    guard(g, fn);
                    markDirty();
                };
                gameTimers[id] = repeat ? setInterval(run, ms) : setTimeout(run, ms);
                return id;
            }
            return {
                reduced: reduced,
                cls: live(function () { P.cls(); enq({ o: 'fn', f: function () { spriteList = []; markDirty(); } }); }),
                print: live(function (t, o) { P.print(String(t), o); }),
                line: live(function (t, o) { o = o || {}; P.line(String(t), { center: o.center, inv: o.inv, at: o.at, nolog: o.nolog, hold: o.hold === undefined ? HOLD_MS : o.hold }); }),
                blank: live(function () { P.blank(); }),
                wait: live(function (ms) { P.wait(ms); }),
                beep: live(function (freq, ms) { P.beep(freq, ms); }),
                then: live(function (fn) { enq({ o: 'fn', f: function () { guard(g, fn); } }); }),
                prompt: live(function (p, cb) { enq({ o: 'gprompt', g: g, p: String(p), cb: function (t) { guard(g, function () { cb(t); }); } }); }),
                keys: live(function (cb, opts) { enq({ o: 'gkeys', g: g, cb: cb, nr: !!(opts && opts.noRepeat) }); }),
                keysOff: live(function () { rawOn = false; keyCb = null; keyNoRepeat = false; }),
                put: live(function (c, r, ch, inv) { put(c, r, ch, inv); markDirty(); }),
                text: live(function (c, r, str, inv) {
                    str = String(str);
                    for (var i = 0; i < str.length; i++) { put(c + i, r, str.charAt(i), inv); }
                    markDirty();
                }),
                fill: live(function (c, r, w, h, ch, inv) {
                    for (var y = 0; y < h; y++) { for (var x = 0; x < w; x++) { put(c + x, r + y, ch, inv); } }
                    markDirty();
                }),
                // Rows of '#' lit, 'o' dark and opaque, '.' clear. Any size, in 280x192 pixels.
                defSprite: live(function (name, rows) {
                    var c = document.createElement('canvas'), x = c.getContext('2d');
                    c.width = rows[0].length * 2; c.height = rows.length * 2;
                    rows.forEach(function (row, py) {
                        for (var px = 0; px < row.length; px++) {
                            var t = row.charAt(px);
                            if (t === '#' || t === 'o') { x.fillStyle = t === '#' ? PHOSPHOR : '#000'; x.fillRect(px * 2, py * 2, 2, 2); }
                        }
                    });
                    spriteDefs[name] = c;
                }),
                // The whole sprite layer for this frame: [{s: name, x: px, y: px}], later entries on top. Replaces the last list.
                sprites: live(function (list) { spriteList = list || []; markDirty(); }),
                every: live(function (ms, fn) { return timer(Math.max(1, ms), fn, true); }),
                after: live(function (ms, fn) { return timer(Math.max(0, ms), fn, false); }),
                cancel: live(function (id) {
                    if (gameTimers.hasOwnProperty(id)) { clearInterval(gameTimers[id]); clearTimeout(gameTimers[id]); delete gameTimers[id]; }
                }),
                quit: live(function () { quitGame(); }),
                save: function (key, value) {
                    try { window.localStorage.setItem('orkple.games.' + g.id + '.' + key, JSON.stringify(value)); } catch (e) { /* storage off */ }
                },
                // load(key, valid): a stored value that fails valid(value) comes back as null, so a bad save cannot stick.
                load: function (key, valid) {
                    try {
                        var v = window.localStorage.getItem('orkple.games.' + g.id + '.' + key);
                        v = v === null ? null : JSON.parse(v);
                        return v !== null && typeof valid === 'function' && !valid(v) ? null : v;
                    } catch (e) { return null; }
                },
                // The shared check for a saved best: an object whose score is a whole number of 0 or more,
                // every other field a finite number, except won, which is true or false.
                isBest: function (v) {
                    if (!v || typeof v !== 'object' || Array.isArray(v)) { return false; }
                    if (typeof v.score !== 'number' || !isFinite(v.score) || v.score < 0 || Math.floor(v.score) !== v.score) { return false; }
                    for (var f in v) {
                        if (!v.hasOwnProperty(f)) { continue; }
                        if (f === 'won' ? typeof v[f] !== 'boolean' : typeof v[f] !== 'number' || !isFinite(v[f])) { return false; }
                    }
                    return true;
                },
                // held('A'): is that key down right now? Same names as keys() sends. Cleared on blur and when the page hides.
                held: function (key) { return game === g && !destroyed && !!heldKeys[String(key).toUpperCase()]; },
                rand: function (n) { return Math.floor(rnd() * Math.max(1, Math.floor(n))); },
                seed: function (n) { rngState = (n | 0) || 1; }
            };
        }
        P.playGame = function (id) {
            P.load(function () { return loadGame(id); }, function (reg) {
                var g = { id: id };
                game = g;
                current = programs.GAME;
                P.errs = 0;
                clearScreen();
                showSplash(gameTitle(id));
                later(function () {
                    if (game !== g) { return; }
                    splash = null;
                    ahead = '';            // keys pressed at the title card do not reach the game
                    markDirty();
                    // window.OrkpleSeed (a number) makes host.rand repeatable from the start of the game, for tests.
                    var sd = window.OrkpleSeed;
                    rngState = typeof sd === 'number' && isFinite(sd) ? ((sd | 0) || 1) : 0;
                    releaseKeys();
                    guard(g, function () { reg.start(makeHost(g)); });
                }, SPLASH_MS);
            }, function () { P.bad('?FILE NOT FOUND'); });
        };
        // One putImageData at 1x, scaled up with smoothing off; cached per title.
        var splashCache = {};
        function splashCanvas(title) {
            if (splashCache[title]) { return splashCache[title]; }
            var bm = orkekSplash(title), small = document.createElement('canvas'), sx = small.getContext('2d');
            small.width = bm.w; small.height = bm.h;
            var img = sx.createImageData(bm.w, bm.h), d = img.data;
            var pr = parseInt(PHOSPHOR.slice(1, 3), 16), pg = parseInt(PHOSPHOR.slice(3, 5), 16), pb = parseInt(PHOSPHOR.slice(5, 7), 16);
            for (var i = 0; i < bm.bits.length; i++) {
                if (!bm.bits[i]) { continue; }
                d[i * 4] = pr; d[i * 4 + 1] = pg; d[i * 4 + 2] = pb; d[i * 4 + 3] = 255;
            }
            sx.putImageData(img, 0, 0);
            var c = document.createElement('canvas'), x = c.getContext('2d');
            c.width = bm.w * 2; c.height = bm.h * 2;
            x.imageSmoothingEnabled = false;
            x.drawImage(small, 0, 0, c.width, c.height);
            splashCache[title] = c;
            return c;
        }
        function showSplash(title) {
            splash = splashCanvas(title);
            diskSound(SPLASH_MS);
            pushLive(['ORKEK', 'ONLINE RECORD KEEPER EDUCATIONAL KONSORTIUM']);
            markDirty();
        }
        P.gameLine = function (t) {
            var cb = gameCb;
            gameCb = null;
            if (cb) { cb(t); }
        };

        /* ---- disk drive: plays under the ORKEK title card and the ORKPLE ][ boot banner ----
           sounds/disk-read.m4a: source, edits and license status are recorded in sounds/CREDITS.txt.
           Fetched once; if it arrives after the card goes up, it joins in step. */
        var diskSrc = null, diskBuf = null, diskLoading = null, diskSeq = 0, diskFailed = false;
        function loadDisk() {
            if (diskBuf) { return Promise.resolve(diskBuf); }
            if (diskFailed) { return Promise.resolve(null); }   // a 404 or an undecodable file is not retried
            if (diskLoading) { return diskLoading; }
            if (!audio || !window.fetch || !GAME_BASE) { return Promise.resolve(null); }
            var ctx0 = audio;
            diskLoading = fetch(GAME_BASE + 'sounds/disk-read.m4a?v=' + (GAME_VER || '1'))
                .then(function (r) { if (!r.ok) { throw new Error('disk sound ' + r.status); } return r.arrayBuffer(); })
                .then(function (bytes) { return new Promise(function (res, rej) { ctx0.decodeAudioData(bytes, res, rej); }); })
                .then(function (buf) { diskBuf = buf; return buf; })
                .catch(function () { diskLoading = null; diskFailed = true; return null; });
            return diskLoading;
        }
        var bootDisk = false;          // true while the boot banner is up, so the disk plays without a title card
        function diskSound(ms) {
            stopDisk();
            if (!soundOn()) { return; }
            try {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) { return; }
                if (!audio) { audio = new AC(); }
                if (audio.state === 'suspended' && audio.resume) { audio.resume(); }
            } catch (e) { return; }
            var seq = diskSeq, t0 = Date.now(), len = ms / 1000;
            loadDisk().then(function (buf) {
                if (!buf || !audio || destroyed || seq !== diskSeq || !(splash || bootDisk) || !soundOn()) { return; }
                var off = (Date.now() - t0) / 1000;
                if (off > len - 0.3) { return; }
                try {
                    var src = audio.createBufferSource();
                    src.buffer = buf;
                    src.connect(audio.destination);
                    src.onended = function () { if (diskSrc === src) { diskSrc = null; } };
                    src.start(0, off, Math.min(len, buf.duration) - off);
                    diskSrc = src;
                } catch (e) { /* no audio: stay silent */ }
            });
        }
        P.preloadDisk = function () {
            try {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (AC && !audio) { audio = new AC(); }
            } catch (e) { return; }
            loadDisk();
        };
        function stopDisk() {
            diskSeq++;
            if (!diskSrc) { return; }
            try { diskSrc.stop(); } catch (e) { /* already stopped */ }
            diskSrc = null;
        }

        /* ---- beep ---- */
        function beep(freq, ms) {
            if (!soundOn()) { return; }
            try {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) { return; }
                if (!audio) { audio = new AC(); }
                if (audio.state === 'suspended' && audio.resume) { audio.resume(); }
                var o = audio.createOscillator(), g = audio.createGain(), t = audio.currentTime;
                o.type = 'square';
                o.frequency.value = freq || 1000;
                g.gain.setValueAtTime(0.06, t);
                g.gain.setValueAtTime(0, t + (ms || 80) / 1000);
                o.connect(g);
                g.connect(audio.destination);
                o.start(t);
                o.stop(t + (ms || 80) / 1000 + 0.01);
            } catch (e) { /* no audio: stay silent */ }
        }

        /* ---- power-on garbage ---- */
        var GARBAGE = '@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_ !"#$%&\'()*+,-./0123456789:;<=>?';
        function garbage() {
            for (var r = 0; r < ROWS; r++) {
                for (var c = 0; c < COLS; c++) {
                    var cell = scr[r][c];
                    cell.ch = GARBAGE.charAt(Math.floor(Math.random() * GARBAGE.length));
                    cell.inv = Math.random() < 0.3;
                }
            }
            markDirty();
        }

        // A precise pointer is the best sign of a real keyboard: phones and tablets report only touch,
        // while a touchscreen laptop or a tablet with a trackpad still reports a fine pointer.
        function suitableDevice() {
            if (!window.matchMedia) { return true; }
            return window.matchMedia('(any-pointer: fine)').matches;
        }
        // The input stays disabled to keep the phone keyboard away; POWER (or ESC) is the way out and takes focus.
        // A real key press proves a keyboard (an iPad with one reports no fine pointer), so it boots DOS after all.
        var biosOn = false;
        function onBiosKey(e) {
            if (!biosOn || destroyed) { return; }
            var k = e.key;
            if (!k || k === 'Unidentified' || k === 'Escape' || k === 'Esc' || k === 'Tab' || k === 'Shift' || k === 'Control' || k === 'Alt' || k === 'Meta' || k === 'CapsLock') { return; }
            // Browser and OS shortcuts (Cmd+R, Ctrl+L) keep working; they are not "any key".
            if (e.ctrlKey || e.metaKey || e.altKey) { return; }
            // Return or Space on a focused button presses that button (POWER); every other key still boots DOS.
            if ((k === 'Enter' || k === ' ' || k === 'Spacebar') && e.target && e.target.tagName === 'BUTTON') { return; }
            e.preventDefault();
            leaveBios();
            queue = [];
            if (waitT) { clearTimeout(waitT); delete timers[waitT]; waitT = 0; }
            input.disabled = false;
            try { input.focus({ preventScroll: true }); } catch (e2) { input.focus(); }
            bootDos(true);
        }
        function leaveBios() {
            if (!biosOn) { return; }
            biosOn = false;
            window.removeEventListener('keydown', onBiosKey, true);
        }
        function biosError() {
            var hold = reduced ? 0 : 260;
            input.disabled = true;
            beep(220, 300);
            [
                '', '', '', '',
                'ORKPLE ][',
                '',
                '*',
                '?NO KEYBOARD ERROR',
                '*',
                '', '',
                '(WE\'RE SORRY, BUT THE ORKPLE II',
                'WORKS BEST ON A DESKTOP',
                'OR LAPTOP DEVICE!)',
                '', '',
                'PRESS POWER OR ESC TO RETURN'
            ].forEach(function (t) {
                // The monitor's * prompt and its error sit at the left edge, as on the real machine.
                var left = t === '*' || t.charAt(0) === '?';
                P.line(t, { center: !left, hold: t ? hold : 0 });
            });
            biosOn = true;
            window.addEventListener('keydown', onBiosKey, true);
            if (power) { try { power.focus({ preventScroll: true }); } catch (e) { power.focus(); } }
        }
        function bootDos(keyboard) {
            if (!keyboard && !suitableDevice()) { biosError(); return; }
            current = programs.DOS;
            current.enter(P);
        }

        function start() {
            if (started || destroyed) { return; }
            started = true;
            input.addEventListener('input', onInput);
            input.addEventListener('keydown', onKeydown);
            input.addEventListener('keyup', onKeyup);
            input.addEventListener('blur', releaseKeys);
            window.addEventListener('blur', releaseKeys);
            document.addEventListener('visibilitychange', onVisibility);
            blinkId = setInterval(function () {
                if (accepting) { cursorOn = !cursorOn; markDirty(); }
            }, BLINK_MS);
            if (reduced) {
                bootDos();
                return;
            }
            garbage();
            garbageId = setInterval(garbage, 60);
            later(function () {
                clearInterval(garbageId); garbageId = 0;
                beep();
                clearScreen();
                // The machine's name on the top line while the Disk II grinds through the boot.
                var name = 'ORKPLE ][', c0 = Math.floor((COLS - name.length) / 2);
                for (var i = 0; i < name.length; i++) { scr[0][c0 + i].ch = name.charAt(i); scr[0][c0 + i].inv = false; }
                markDirty();
                bootDisk = true;
                diskSound(BOOT_DISK_MS);
                later(function () {
                    bootDisk = false;
                    stopDisk();
                    clearScreen();
                    markDirty();
                    bootDos();
                }, BOOT_DISK_MS);
            }, GARBAGE_MS);
        }

        function destroy() {
            if (destroyed) { return; }
            destroyed = true;
            accepting = false;
            leaveBios();
            queue = [];
            abortAll();
            endGame();
            images.forEach(function (im) { im.onload = im.onerror = null; if (im.orkFail) { im.orkFail(); } });
            for (var id in timers) { if (timers.hasOwnProperty(id)) { clearTimeout(+id); } }
            timers = {};
            if (blinkId) { clearInterval(blinkId); blinkId = 0; }
            if (garbageId) { clearInterval(garbageId); garbageId = 0; }
            if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }
            input.removeEventListener('input', onInput);
            input.removeEventListener('keydown', onKeydown);
            input.removeEventListener('keyup', onKeyup);
            input.removeEventListener('blur', releaseKeys);
            window.removeEventListener('blur', releaseKeys);
            document.removeEventListener('visibilitychange', onVisibility);
            releaseKeys();
            try { input.value = ''; } catch (e) { /* ignore */ }
            if (audio) { try { audio.close(); } catch (e2) { /* ignore */ } audio = null; }
        }

        // Read-only snapshot of the 24 text rows, for tests.
        function screenRows() {
            var out = [];
            for (var r = 0; r < ROWS; r++) {
                var t = '';
                for (var c = 0; c < COLS; c++) { t += scr[r][c].ch; }
                out.push(t);
            }
            return out;
        }

        var api = { start: start, destroy: destroy, screenRows: screenRows, hush: stopDisk };
        // probe: tests only, attached only when the test flag window.OrkpleStrict is set at mount; goes through the allow-list.
        if (window.OrkpleStrict) { api.probe = getJson; }
        return api;
    }

    window.OrkpleTerminal = {
        mount: mount,
        lib: {
            orkekSplash: orkekSplash, gameTitle: gameTitle, clean: clean, wrap: wrap, pagerLines: pagerLines, profileLines: profileLines, profileParts: profileParts, renderArt: renderArt, sameOrigin: sameOrigin,
            htmlText: htmlText, barChart: barChart, eventParts: eventParts, evWhen: evWhen, evDecode: evDecode, eventDescLines: eventDescLines, schedPages: schedPages, calRow: calRow, calRowText: calRowText, calItemLines: calItemLines, shortLinks: shortLinks, parkParts: parkParts, parkDayText: parkDayText, parkDayInPerson: parkDayInPerson, parkWhereLines: parkWhereLines, unitParts: unitParts, unitRowText: unitRowText, unitTextLines: unitTextLines, unitAboutLines: unitAboutLines, osRowText: osRowText, todayLines: todayLines, epOf: epOf, standingRows: standingRows, standingText: standingText, topParkText: topParkText, weekRows: weekRows, liveRows: liveRows, liveText: liveText, recentRows: recentRows, recentText: recentText, wxFlagged: wxFlagged, wxText: wxText, kamRows: kamRows, kamText: kamText, kamDetailLines: kamDetailLines, aboutLines: aboutLines, milestoneRows: milestoneRows, milestoneText: milestoneText, playerLines: playerLines, allowed: ALLOWED.map(function (x) { return x[0]; })
        }
    };
}());
