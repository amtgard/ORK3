/* GEM CASTLORKS: an ORKPLE II game. Walk the isometric castle, take every gem, and keep the looters off it.
   Runs against the terminal's game host; touches nothing but window.OrkpleGames. */
(function () {
    'use strict';

    var TICK = 40;
    var COLS = 40, ROWS = 24;
    var N = 9;				// the castle is at most 9 x 9 cells
    var CX = 18, CY = 3;	// a cell (x, y, z) is four characters wide at column CX + 2 (x - y), row CY + x + y - z
    var MSG_ROW = 22, HELP_ROW = 23;
    var DIRS = { UP: [0, -1], RIGHT: [1, 0], DOWN: [0, 1], LEFT: [-1, 0] };
    var ORDER = ['UP', 'RIGHT', 'DOWN', 'LEFT'];
    // Arrows rotate the compass clockwise: UP walks north-east on the screen, RIGHT south-east, DOWN south-west, LEFT north-west.
    var KEYMAP = { UP: 'UP', DOWN: 'DOWN', LEFT: 'LEFT', RIGHT: 'RIGHT', W: 'UP', A: 'LEFT', S: 'DOWN', D: 'RIGHT' };

    var PLAYER_MS = 260;	// one cell
    var AIR_MS = 320, COOL_MS = 380;
    var DOWN_MS = 7000;		// a stopped looter is out this long
    var GEM = 10, EXTRA_AT = 3500;
    var TAGS = [200, 400, 800, 1600];
    var HELM_BACK = 14000;
    var BOSS_AT = 5000;
    var WARP_AT = 24;		// gems taken or lost before the level 1 secret wakes

    /* Map characters are the floor height: ' ' is void, 0 1 2 are raised floor. Rows are y, columns are x.
       Every floor cell holds a gem except the start, the spawn points and the secret. */
    var LEVELS = [
        {
            name: 'BRIDGE BATTLE',
            flavor: ['THE BRIDGES ARE ONE PERSON WIDE.', 'THE ARGUMENT ABOUT THEM IS WIDER.'],
            rows: ['111000000', '111 0   0', '111 0 0 0', '0  000  0', '0  000  0', '0  000  0', '0   0 111', '0   0 111', '000000111'],
            start: [4, 4], spawns: [[1, 1], [7, 7], [8, 0], [0, 8]], helms: [[8, 3], [0, 5]], warp: [6, 2],
            looters: 3, step: 280, eat: 350, chase: 0.5, helm: 7500, swarm: 14000, pages: 3, pspeed: 3.0, boss: 0
        },
        {
            name: 'CAPTURE THE FLAG',
            flavor: ['BOTH FLAGS ARE IN THE MIDDLE.', 'NOBODY REMEMBERS WHO PUT THEM THERE.'],
            rows: ['11     11', '11     11', '00     00', '00 111 00', '000111000', '00 111 00', '00     00', '11     11', '11     11'],
            start: [4, 4], spawns: [[0, 0], [8, 8], [8, 0], [0, 8]], helms: [[2, 4], [6, 4]], warp: null,
            looters: 4, step: 250, eat: 330, chase: 0.6, helm: 6500, swarm: 11000, pages: 3, pspeed: 3.4, boss: 0
        },
        {
            name: 'GHOST MODE',
            flavor: ['EVERYONE HERE IS DEAD EXCEPT YOU.', 'THEY ARE STILL STEALING. RUDE.'],
            rows: ['000000000', '0   0   0', '111111111', '0   0   0', '000000000', '    0   0', '111111111', '0   0   0', '000000000'],
            start: [4, 4], spawns: [[0, 0], [8, 8], [0, 8], [8, 0]], helms: [[0, 3], [8, 5]], warp: null,
            looters: 4, step: 225, eat: 310, chase: 0.65, helm: 6000, swarm: 8000, pages: 4, pspeed: 3.8, boss: 0
        },
        {
            name: 'THE TWIN KEEPS',
            flavor: ['TWO KEEPS. ONE BORDER DISPUTE.', 'BOTH KEEPS CLAIM THE MIDDLE.'],
            rows: ['000000000', '0   0   0', '0   0   0', '0   01110', '000001110', '0   01110', '0   0   0', '0   0   0', '000000000'],
            start: [4, 4], spawns: [[0, 0], [8, 8], [8, 0], [0, 8], [4, 0]], helms: [[0, 4], [8, 4]], warp: null,
            looters: 5, step: 195, eat: 290, chase: 0.7, helm: 5500, swarm: 6500, pages: 4, pspeed: 4.2, boss: 0
        },
        {
            name: 'THE KING\'S KEEP',
            flavor: ['THE GUILDMASTER OF REEVES IS HOME.', 'HE HAS READ THE RULEBOOK. ALL OF IT.'],
            rows: ['000000000', '0   0   0', '0   1   0', '0  222  0', '001222100', '0  222  0', '0   1   0', '0   0   0', '000000000'],
            start: [4, 8], spawns: [[0, 0], [8, 0], [0, 8], [8, 8], [4, 0]], helms: [[0, 4], [8, 4]], warp: null,
            looters: 5, step: 175, eat: 270, chase: 0.8, helm: 4500, swarm: 5000, pages: 5, pspeed: 4.6, boss: 1, bossStart: [4, 4], bossMs: 260
        }
    ];

    var HOW_A = [
        'HOW TO PLAY',
        '',
        '     THE CASTLE IS FULL OF GEMS.',
        '     TAKE EVERY ONE, OR THE LAST ONE.',
        '',
        '',
        '',
        '',
        '             UP=NE     RIGHT=SE',
        '             DOWN=SW   LEFT=NW',
        '             ARROWS OR W A S D,',
        '             SEE THE COMPASS.',
        '',
        '',
        '',
        '             SPACE JUMPS TWO CELLS,',
        '             EVEN OVER GAPS.',
        '            LOOTERS CAN TAG A LOW JUMP.',
        '',
        '',
        '             P HOLDS. Q LEAVES.',
        '',
        '     PRESS RETURN FOR WHO IS WHO'
    ];
    var HOW_B = [
        'WHO IS WHO',
        '',
        '',
        '     LOOTERS. THEY EAT YOUR GEMS, AND',
        '     EVERY GEM THEY TAKE COSTS YOU 10.',
        '',
        '     TOUCH ONE WHILE IT IS EATING TO',
        '     STOP IT. OTHERWISE, DO NOT.',
        '',
        '     A HELM. YOU CAN TAG ANYTHING FOR A',
        '     FEW SECONDS, EVEN THE BOSS.',
        '',
        '',
        '',
        '',
        '     PAGES. THEY ARRIVE IF YOU DAWDLE.',
        '',
        '',
        '     THE GUILDMASTER OF REEVES GUARDS',
        '     THE KEEP. HE HAS READ THE RULES.',
        '',
        '',
        '     PRESS RETURN'
    ];

    var WIN_TEXT = [
        '', '', '', '', '', '', '', '', '', '', '',
        'THE LAST GEM IS YOURS.',
        'THE KEEP GOES QUIET.',
        'A REEVE ARRIVES WITH A CLIPBOARD.',
        '"THOSE WERE THE BOUNDARY ROCKS."',
        '"SPRAY PAINTED. FOUR HUNDRED OF THEM."',
        '"THE FIELD NOW HAS NO EDGES."',
        'YOU ARE ASKED TO PUT THEM BACK.',
        'IN THE DARK. BEFORE THE PARK CLOSES.'
    ];

    /* ----- pure helpers ----- */
    function idx(x, y) { return y * N + x; }
    function parseLevel(L) {
        var z = [], tr = [], x, y, n = 0, ch, bare = {};
        bare[L.start[0] + ',' + L.start[1]] = 1;
        L.spawns.forEach(function (s) { bare[s[0] + ',' + s[1]] = 1; });
        if (L.warp) { bare[L.warp[0] + ',' + L.warp[1]] = 1; }
        for (y = 0; y < N; y++) {
            z.push([]); tr.push([]);
            for (x = 0; x < N; x++) {
                ch = (L.rows[y] || '').charAt(x);
                z[y].push(ch >= '0' && ch <= '2' ? +ch : -1);
                tr[y].push(z[y][x] >= 0 && !bare[x + ',' + y]);
                if (tr[y][x]) { n++; }
            }
        }
        return { z: z, tr: tr, total: n };
    }
    // The neighbour you can walk to in one step: floor, and no more than one step up or down.
    function nbr(z, x, y, d) {
        var nx = x + DIRS[d][0], ny = y + DIRS[d][1];
        if (nx < 0 || ny < 0 || nx >= N || ny >= N || z[ny][nx] < 0 || Math.abs(z[ny][nx] - z[y][x]) > 1) { return null; }
        return [nx, ny];
    }
    function bfs(z, sources) {
        var dist = [], q = [], i, k, c, n;
        for (i = 0; i < N * N; i++) { dist.push(-1); }
        sources.forEach(function (s) { dist[idx(s[0], s[1])] = 0; q.push(s); });
        for (i = 0; i < q.length; i++) {
            c = q[i];
            for (k = 0; k < 4; k++) {
                n = nbr(z, c[0], c[1], ORDER[k]);
                if (n && dist[idx(n[0], n[1])] < 0) { dist[idx(n[0], n[1])] = dist[idx(c[0], c[1])] + 1; q.push(n); }
            }
        }
        return dist;
    }
    // Where a cell is drawn: top-left character column and row of its four-wide top face.
    function boxOf(x, y, z) { return { c: CX + 2 * (x - y), r: CY + x + y - z }; }

    /* ----- pixel art: the castle and everything on it are sprites on the 280x192 pixel grid ----- */
    var TW = 28, TH = 16;	// a floor diamond; neighbours sit 14 px across and 8 px down
    var SPR = {};			// name -> { w, h, ax, ay, rows }, the anchor is the point that sits on the ground
    function halo(rows) {
        // every clear pixel touching a lit one becomes a dark opaque outline, so figures show over lit floor
        return rows.map(function (row, y) {
            var out = '', x, dx, dy, near;
            for (x = 0; x < row.length; x++) {
                if (row.charAt(x) !== '.') { out += row.charAt(x); continue; }
                near = false;
                for (dy = -1; dy <= 1; dy++) { for (dx = -1; dx <= 1; dx++) { if (rows[y + dy] && rows[y + dy].charAt(x + dx) === '#') { near = true; } } }
                out += near ? 'o' : '.';
            }
            return out;
        });
    }
    function pad2(rows, l, t, r, b) {
        var w = rows[0].length + l + r, out = [], i, blank = new Array(w + 1).join('.');
        for (i = 0; i < t; i++) { out.push(blank); }
        rows.forEach(function (row) { out.push(new Array(l + 1).join('.') + row + new Array(r + 1).join('.')); });
        for (i = 0; i < b; i++) { out.push(blank); }
        return out;
    }
    function dither(rows) { return rows.map(function (row, y) { return row.split('').map(function (c, x) { return c === '#' && (x + y) % 2 ? '.' : c; }).join(''); }); }
    function sprite(name, rows, ax, ay, plain) {
        var r = plain ? rows : halo(pad2(rows, 1, 1, 1, 1));
        SPR[name] = { w: r[0].length, h: r.length, ax: ax + (plain ? 0 : 1), ay: ay + (plain ? 0 : 1), rows: r };
    }
    /* Full-frame art, one bit a pixel, six bits a character, top row first. */
    var PIC = {
        title: [280, 160, 'AAAAAgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAD-gACf--AAYABgAAAAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAAAAAAf-AAD--+ADwAPAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD-+AAf--8AfgB+AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAf-8AD---4B-AH8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD--4'+
        'AH---gH+A-wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAf--wAP--4Af4D+AAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAD---gAf--qB-wf-AAAAAAAAAAADoAAAAAIAAAAAAAAAAAAAfwB-APwAAAH-j-'+
        'gAAAAAAAAAAA9AAAAAD4AAAAAAAAAAAAB-VX8A-QAAAf-P-wAAAAAAAAAAHoAAAAACAAAAAAAAAAAAAH8AHAD8AAAB-9-4AAAAAAAAAAA9AAAAAAIAAAAAAAAAAAAAf0ANQP0AAAH---4AAAAAAAAA'+
        'AHoAAAAAAAAAAAAAAAAAAAB7H-8AvAAAAX-9+AAAAAAAAAAAfQAAAAAAAAAAAAAAAAAAAH9Kv0D--wAB--v6AAAAAAAAAAD6AAAAAAAAAAAAAAAAAAAAaxV7AK1VgAFb81gAAAAAAAAAAP0AAAAAAQ'+
        'AAAAAAAAAAAAB-Sr9A-6rAAfvn+gAAAAAAAAEA+gAAAAAAAAAAAAAAAAAAAGsAKwCtVWABWc1YAAAAAAAAAAD9AAAAAAAAAAAAAAAAAAAAQUABQAeq4AALBUoAAAAAAAAAAfoAAAAAAAAAAAAAAAAA'+
        'AABBAAEABRGAAAkECAAAAAAAAAAA-QAAAAAAAAAAAAAAAAAAAH9AP0D9f6AB+gH6AAAAAAAAAAD6AAAAACAAAAAAAAAAAAAAawArAKwAAAFYAVgAAAAAAAAAAP0AAAAAAAAAAAAAAAAAAAB3QB1AdQ'+
        'AAAOoA6gAAAAAAAAAA-oAAAAAAAAAABAAAAAAAAGsAKwCsAAABWAFYAAAAAAAAAAB-QAAAAEAAAAAAAAAAAAAAXUA3QN0AAAG6AboAAAAAAAAAAH+gAAAAAAAAAAAAAAAAAABrAGsArAAAAVgBWAAA'+
        'AAAAAAAAP9VAAAAAAAAAAAAAAAAAADX-1wBX-+ABVQFVAAAAAAAAAAAf6oAAAAAAAAAAAAAAAAAAGqqsAKqqsAGsAawAAAAAAAAAAA-1AAAAAAAAAAAAAAAAAAANVV1AVVVYAVUBVQAAAAAAAAAAA-'+
        '4AAAAAAAAAAAAAAAAAAAaqsACqqqwBrAGsAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAA1V1ANVVXADdAN0AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABosAAYiIwAHAAcAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAAAAAAAAAD-hAA-j-QABQAFAAAAAAAAAAAAAAAAAAAAAAAH8AABAAAfwAH-8AIAAAD+AA-8ABABAAfwAAAAAAAAAAAAAA-4AKOACj-iA8-4ByqoAf8AH84AOCOKj-gAAAAAAAAAAAAAH-wAB8AAf-'+
        'AHn-wPgAAD-4A-nwB8B8Af-AAAAAAAAAAAAAA--gAP4AD-+A8--h-AAAf-wH8-gP4P4D-+AAAAAAAAAAAAAH--AA-hAf-8Bn-8H8AAD--gfn-A-h-Af-8AAAAAAAAAAAAA--+AD-gD--4D--qf4AAP'+
        '--B8-+D+v6j--4AAAAAAAAAAAAD--4Af8AP--gH-8B-AAB--8Hn-4P4-gP--gAAAAAAAAAAAAf6-4B-4B-r-gL+qn+gAP6v4c6-4-v+p-r-gAAAAAAAAAAAB-A+AH-AH8D4AP4AfwAA-g-hnB-D+-g'+
        'H8D4AAAAAAAAAAAAH+r6Af+gf6voA-qh-oAD+r+k+v+P-+of6voAAAAAAAAAAAAXQCADd4B3AIADeAF0AAN4N4Vwdw93wBdAIAAAAAAAAAAAAB-oCgP-oH+gKAP6Af6AA-o-p-p-r-+oH+gKAAAAAA'+
        'AAAAAAHcAAA92APfwAA9gB3AAD2D+F394N3wAN-wAAAAAAAAAAAAAf6AAD-+Af-gAD+gH+gAP6P6f--q-+qAf-gAAAAQAAAAAAABVAAAdVQB1fAANYAVQAA1g-hVVcDVYAB1XAAAAAAAAAAAAAH+gA'+
        'B--gD-+AA-oB-oAD+j+n--qP-qAD-+AAIAAAAAAAAAEQQAAEAEAGAMACCAEEAAIIIIQAMAgGAAGAMAAAAAAAAAAAIBBoAAQAaAMAYAIKAQaAAgogpAAKiAOAAMAYAAAAAAAAAAAgFUAADVVgAf1gA1'+
        'gBVAADWDWFVXANVQAAf1gAAAAAAAAAAPgf6AAP-+gAr-gD+gH+gAP6P6f-+g--gAAr-gIAAAAAAAAAIBVAIA1VYAgFUANYAVQAA1g1hVNYDX3AAgFUAgAAAAAAAAAgG+hwC7u4HC+4A7oBvoADujun'+
        'u7oLq+AHC-4PgAAAAAAAAAAVQNgdfVA2BVADWAFUAANYNYVRXA1tYA2BVAIAAAAAAAAAAB7p7B7u+H8G+gLqAe6AA-ovpvvuDu74HsHuggAAAAAAAAAADX9YFUdQNf1gA1gBV-4BX-cFUNYNY1gNf1'+
        'gAAAAAAAAAAAAGqroa6ro+quoCqgGqqwH+qqa66oqprA7+uoAAAAAAAAAAAAdVcDWDWB1VwANYAVVVgNVWBVBXDWHUB1VwAAAAAAAAAAAAA6rqO6u6DquoA7oBqqrAeq6mumuO6O4D+uoAAAAAAAAA'+
        'AAAB1cAfAfAHVwAB8ADVVYA1WANgNgfAfAHVwAAAQAAAAAAAAADrqg6g6gOuqADqAGqroBq6geoeo6g6gOuqAAAAAAAAAAAAAH8ABABAAfwAAEAAP-4AD+AAgAgBABAAfwAAAABAAAAAAAAAKqgCoC'+
        'oAqqAAKgAKqqACqgAoAoCoCpAqqAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAqgAIAIACqAAAgAAqqAAKgAAAAAIAIACqAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAABrAAQAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAMmAAAAAAAAA'+
        'ABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABiMAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP-4AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABSpQAAAAAAAAAAAIAAAAAA'+
        'AAAAAAAAAAAAAAAAAAAAAAAAGMyZjAEAAAAAAAAAAAAAAAAgAAAAAAAAAAAAAAAAAAAAAAByRJEngAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAEAAc8ionvgAEAAAAAAAAAAAAAA+AIAAAAAAA'+
        'AAAAAAAAAACAAPP5Kk-fgAAAAAAAAAAAAAAAAgAAAAAAAAAAAAAAAAAQAAAD7-ycn+-gAAAAAAAAAAIAAAACAAAAAAAAAAAAIAAAAAAAAAEf-l0-1YAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAEk--Kn-6hAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAAAB44f+c--2PAAAAAAAAAAAAAAAAAAAAAAgAAAAAAAAAAAAAAfmx-8n--8-AAAAAAAAAAAAAAAAAAAABCAAABAAAAAAAAACH81n-4--+'+
        'B-AAAAAAAAAABAAAAAAAAAA+CAAAAAAAAAAAAAfmTf----wL8AAAAAAEAACAAAAAAAAAAAgAAAAAAAAAAAAABQNZ----4AVAAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAADCbP----ACsAAAAAAAA'+
        'AAABAAAAAAAAAAAAAAAAAAAAAAAQ044L3--gAPSGAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGT-pGPv-8QA-++AAAAAAgAAAAAAAAAAAAAAAAAAAAAAAAADzn8BAJWeDAB-9+AAAAAAAAAAAAAAAA'+
        'AAAAAAAAAAAAAAAA+bPkJAOpw8CT-7+AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABzWc4IGJgDwDn-VgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAALZNmyIGPAPIbP-qAAAAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAAAAgU1k1mBGIA8DWf-YCAAAAAAAAAAAAAAAAAAAAQAAAAAAAAPrps2TGBGgDwZN--y+AAAAAAAAAAAAAAAAAAAAAAABAAAAAZiTnNZGJGAMA1n-4N+AAAAAAAAAAAAAAAIAAAAAAACAAAAkGjk+b'+
        'BEJICgBs--A7+AAAAAAAAAAAEAAAAIAAAAAAAAAAA4ZiHc5xEJgABznPgDVgAAAAAAAAAAAAAAAAAAAAAAAAAAAGzhj65PxEKJAfk48AvqAAAAAAAAAAAAAAAAAAAAAAAAAAAA1mRi1R-w4IOH-ECA'+
        'A-YCAAAAAAAAAAAAAAAAAAAAEAAAAAGTaRjrP-myhs-+wwCD-++AAAAAAAAAAAAAAAAAAAAAAAACDNZiRC0-81mNZ-9IIAP-9+AEAAAAAAAAAAAAAAAAAAAAAA++bOiQD--2TJk3--HoA--7+AAAAA'+
        'AAAAAAAAAAAAAAAAAAB-85BiQi--NZjWf-geAAf-VgAAAAAAAAAAAAAAAAAAAAAAAL-5JBiBj7+bCGz+8B4AE--qAAAAAAAAAAAAAAAAAAAAAAAAVnxORgBi1853OfCAHgA5--YAAAAAAAAAAAAAAA'+
        'AAAAAAAADqf5sRgRirpP+S4ACeAGz--4AAAAAAAAAAAAAAAAAAAAAAACZ-NYRCRjUx-8dAAB4A1n-4AAAAAAAAAAAAAAAAAAAAAAAAj-9k0RiRiz---sAIEAWTf-gAAAAAAAAAAAAAAAAAAAAAAABi'+
        '9zWcRiRj----wAAAHNZngAAAAAAAAAAAAAAAAAAAAAAAABj7mz8RiRj---8AgAB+bMeAAAAAAAAAAAAAAAAAAAAAAAAARiVOf8RiRC1-+CAAAf84AAAgAAAAAAAAAAAAAAAAAAAABACRjqT-8RiQDr'+
        '3x6AAH-5IYAAAAAAAAAAAAAAAAAAAAAAAAAiRCYf-8RiQiWYHgAB--xgAAAgAAAAAAAAAAAAAAAAAAAAAIiQD---kRiBjpAeAAT---iAAIAAAAAAAAAAAAAAAAAAAAACBiQi-f84RgBigB4ADn-PgY'+
        'ACAgAAAAAAAAABAAAAAAAAAAgBiBj6-mwRgRiAngAbP4+HgAgIAAAAAAAAAAAAAAAAAAAIICRgBi1Q1kRCRoAeATWUCAeAACAoAAAAAAAAAAAAAAAAAA+AgRgRjrGTcRiRiBAHZMw4B4CAgPgAAAAA'+
        'AAAAAAAAAABAAoIARCRi0NZ8RiSAAB81lIAHgAICgAAAAAAAAAAAAAAAAOAI+AgQCRj+bP8RiYAAf5s-gAeICA+AAAAAAAAAAAAAAAABwAIoIAQiRi85-8BigAH-zngAB4AgKAAAAAAAAAAAAAAAAA'+
        'OAAI+AgRif-5P-5BiAE--k+AAEgID4AAAAAAAAAAAAAAAABwAAIoIARioqx--ORoA5--CAAAACAoAAAAAAAAAAAAAAAAAOAAAI+AgRjyerv5sRgGz+4IgACAgPgAAAAAAAAAAAAAAAABwAAAIoIARq'+
        'orU-NYSA1n9AGAAAICgAAAAAAAAAAAAAAAAAOAAAAI+AgR5yaz9k0YWTfsh4AICA+AAAAAAAAAAAAAAAAABwAAAAIoIASio7-zWcnNZ-wHgAAgKAAAAAAAAAAAAAAAAAAOAAAAAI+Ag75yT-mz9+bN'+
        '+AeAgID4AAAAAAAAAAAAAAAAQBwAAAAAIoIFIioi1Of-84GAB4ACAoAAAAAAAAAAAAAAAADgOAAAAAAI+Cqj5zjqT--5AYAHiAgPgAAAAAAAAAAAAAAAAbBwAAAAAAIoVVIitiUf--wAAAeAICgAAA'+
        'AAAAAAAAAAAAABUOAAAAAAAI+qqj55jp---pgABICA+AAAAAAAAAAAAAAAAABBwAAEbAMAIdVVIixi----gAAAAgKACAAAgkHgAAAEAAAAA-uAAKuoBgBK+qqj6Rj---+IAAgID4A+AAfGPqAKBAAB'+
        'ABAH-oABARIZAAFdVVIqRC1-+BgAACAoBBAgATmRGAEDiAEACAf8wAb6q+oAMK+qqjiQDr34eACAgPgA6+AaqqqoDoK4iBAkFgPgDFABRAFUFdVVLiQiWYB4AAICgE9FWBREBAQ0XFJyAKonK8AKqq'+
        'KqACqK+qqpiBjpgHgICA+AqqKoaiqqK2qqq645QFfPxBAAAAADgAFdVVRgBigAeAAgKAcBABcAAAABEBABEOiro-7ICoiKqOqKqK+qrxgRiAB4gID4CoqIqqioiIqoqqqqRkFB81fgAAAAVAAAFdVY'+
        'RCRoAHgCAoAEAAAEQAAAAEAAAASqoo-7yqIAACCqiAIK+vAQCRgASAgPgAKCAAKgAAACoCAiIgAAH-3RAAAAABAAAAFdgAQiSAAAICgAAAAAAAAAAAAAAAAAqoC+vYqoAAAAqogIAK8AgRiYAICA+A'+
        'ACiAAKgAwAAIAIAAAAAD3eAAAAAAAAAAAAFgIARigAAgKAAAAAAAAAHgAAAAAAAAIAO+4iIAAAACAAAAAKeAgRiAgID4AAAAAAAAA-AAAAAAAAAAA93gAAAAAAAAAAAAIoIARoACAoAAAAAAAAAH+A'+
        'AAAAAAAAAD6+gIAAAAAAAAAAAI+AgRiAgPgAAAAAAAAA-8AAMAAAAAAAH-2AAAAAAAAAAAAAIoIASAICgAAAAAAAAAf-4ABIAAAAAAAP+4AAAAAAAAAAAAAI+AgYCA+AAAAAAAAAH3vgAMwAAAAAAA'+
        'f3AAAAAAAAAAAAAAIoIAggKAAAAAAAAAA---gBzgAAAAAAA+sAAAAAAAAAAAAAAI+AgID4AAAAAAAAAH22-AP-AAAAAAAB3gAAAAAAAAAAAAAAIoICAoAAAAAAAAAAf--4B3uAAAAAAAPeAAAAAAAA'+
        'AAAAAAAI+AgPgAAAAAAAAAB---wPt4AAAAAAA94AAAAAAAAAAAAAAAIoICgAAAAAAAAAACv-qA3+gAAAAAAH3wAAAAAAAAAAAAAAAI+A+AAAAAAAAAAAKv6oDtuAAAAAAAePAAAAAAAAAAAAAAAAIo'+
        'KAAAAAAAAAAAAGAwAH-wAAAAAAB8+AAAAAAAAAAAAAAAAI-4AAAAAAAAAAAAcHAAP+AAAAAAAPz8AAAAAAAAAAAAAAAAIoAAAAAAAAAAAAAAAAAAAAAAA'],
        win: [280, 88, 'AAAAAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAAAAAQAAAAABAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAAAACAAAAAAAACAAAAAAAAAAAAAAAAA'+
        'AAAAAAAABEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAAAAAAIAAAAAAAAAAgAAAAAAAAAAAAAAAAAABAAAgAAAAAAAAAAAPAAAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAAAAAAAAAAAACAAAAAAD4AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAfAAAAAAAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAD4AAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAA'+
        'AAAAAgAAAAPgAAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAgAAAAAAAAAAAAB+AAAAAAAAAAAAAAIAAAAAAAAAAAIAAAgAAAAAAAAAAAAAAH4AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAA'+
        'fAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAB+AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP4AAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAfgAAAAAAAA'+
        'AAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAB+AAAAAAAAAAAAAAAAAAAAAhAAAEAAAAAAAAAAAAAAAAAAAH8AAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAJAAACAAAAAAAAf4AAAAAAAAAAAAAAAAAA'+
        'AAAAAAAACAAAAAAAAAAAAAAAAAA-wAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD-gAAAACAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAH-7AAAAAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAAAAAAAAAAAABAAAP-4AAAAAAAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAP+AAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAA'+
        'AAAAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAAAAAAAABAAAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAGAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAAAAAAAgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABAA'+
        'AAAAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAA'+
        'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD2OACAAIPQEAAABwAAHmMAgAAAOAAGAAYAUAAAAAAAAAAAA6pqIIAHhqD'+
        'IAAAPgAALpgGYAAAuAGLAEgCAAAFAAAAAAAABEFWbAA1lEHAAABrAADEVgVAEAdUA8UAZHzgAAcEAAIAAAAaqqu6ACqqjqAAAP+AAKqriqBBGqgH6gArKqADArgACwgAIAAEERAAwUAwADACawAAAA'+
        'FBADHxAkfgAAGQAA3AUBgFFhAAGqqqqgGqqqqgIAY+bACqqrqhooqqD-IBKqq4CoqoCjoqIDsABAAAA0BABBHABRxUgAAAUAFAIAHP8AkAABYEcEAl1AUMFWAiAiEOoCAqIKuCCKuAAiKooq5SAh-4'+
        'aqKiK4qqqyqq6uiqoAAAAfAAAAAfBXHmEX8AAAAcAMgA--8UAAABcBABwQABA0AAAAAIqoCAKIqqpKaqqoAAqKKq+AH--6qAiIqoqIqqqKqqiqAAAABEAAAABARORgBEAcAAMgQAABZoBAAAAEAAAE'+
        'AAQEBAAAAAAKoAAAAKCpsmoioH8CB-CgAAH-iigHACoCACoiCgooKgAAAAAAAAAAAAFQYAAAUQAAAAAAAduAAAyAAAAAAAAAAAAAACAAAAgAAAIAAEBoAACggAAIAAAAvQAAiIAAIAAACAAAAgAAgA'+
        'AAIAAACAAD+O4AAP+AACAAAAj-AAIfwACAAD4gAAAIAAIAAACAAAAgAAf9wgACAAAAgAAAIH4ACAAAAgAA-4AAACAAAAAAgAAAQAAAJ-2CEAAACAAABAAB-4AAAQAAAIDQgEAAACAAAAIAAAEAAACG'+
        'A4FAAAAgAAAQAAf-4AAEAAACAKCBAAAAgAAACAAABAAAAgcrgQAAAIAAAEAAD9vwABAAAAgBUEQAAAIAAAgAAAgAD4gAB8+-AAgAAAgAAAgf2-gAAAgAAAmgQAgAAAgAIAAAIAA-4AAD-gAAIAAAIA'+
        'AAIDvb3AAAIAAAIf-AIAAAIACAAACAAH-wAAHyAACAAACAAACAM9vNgACAAACAAACAAACAgAABAAACVBAED-sIAAAQAAAgAABz28-AAAEAAAIAAAQAAAgAAAQAAAioCBAf-yAAAEAAAIAAAWPbxMAA'+
        'BAAACAAAEAAAIAAAEAAAINQIQD68AAABAAACAAAEY9vEwAAQAAAgAABAAACAACAAAIAAqAgAPdwAIAAAgAACAABr28TAAACAAAIAAAgAACAAgAACAAFUBAA77ACAAAIAAAgAAOPbx8AAAgAACAAAIA'+
        'AAAAAfyAAAQf-8AD3cAACAAAQAACAAA9vHiAAAQAACAAAQAB-AAD-gAAEAAAAAPrwAAgAAEAAAgAAD28AgAAEAAAgAAEAAP+AA--gABAAAIAAf-AAIAABAAAIAABP-YIAABAAAIAABAAD-+AH--CAA'+
        'AgAAIA-4AAAgAAIAACAAB9vgIAACAAAgAAIAAf-8Cf-8gAAIAACAB-AAAIAACAAAgAAH2+CAAAgAAIAACAAB--wjVAIAACAAAgAD4AACAAAgAAIAAC-b8gAAIAACAAAgAANUAgKoAgIAAEAACAHdAA'+
        'AgAAQAAIAAD9vyAABAAAgAAQAAIqgCA1QCCAABAAAgA9wAAIAAEAACAABf2-gAAQAAIAAEAACDVAIGqAEgAAQAAIAD3AACAABAAAgAAR-b+AAEAACAABAAAgaoAQVUAQCAACAACAfeAACAACAACAAC'+
        'P9v8ACAACAACAACABVQBBqgBAgAAgAAgB44AAgAAgAAgAAg-w-wAgAAgAAgAAgAGqAENVACIAAIAAIAHzwAIAAIAAIAAIB+J+AIAAIAAIAAIAA1UAA---4CAAEAAIA-PgAgABAACAAEAAAAAQAAgAB'+
        'AACAAED---AAAAAgABAACAAAAAIAAQAAgABAACAAEAAIAAQAAgABAAAAA'],
        over: [280, 88, 'AAAAAAAgAAAAAAAAAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAAAI'+
        'AAAAAAAAAAAAAgAAAAAAAAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADwAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAACAAAAAAAA+AAAAAAAAAAAAAAA'+
        'AAAABAAAAAAAAAAAAAAAAAAAgABAAAHwAAAAAAAAAAAEBAAAAAAAAAAAAAAAAgAAAAAAAAAAAAAIA+AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAIAAAD4AAAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAAAAAAAAAAAAAAAAAAAfgAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAABAAB+AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIAAHwAAAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAA'+
        'AAAAAAAAAAfgAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD+AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAH4AAAAAAAACAAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAA'+
        'fgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAR-AAAAAAAgAAAAAQAAAAAAAAAgAAAAAAAAAAAAAAAAAAAAAH+AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAP8AAAAAAAA'+
        'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA-4AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAL-+wAAAAAAAAAAAAAAAAwAAAAAAAAAAAAAAAAAABAAAAAAAD-+AAAgAAAAAAAAAAAAH'+
        'gAAAAAAAAAAAAAAAAAAAAAAAAAAD-gAAAAAAAAAAAAAAAAMAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAAAAAAAIAAAAAAAH+AAAAgAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAkAAAAB-+AAAAAAAAA'+
        'AAAAAAAAAAAAAAAAAAgQCAAAAAAAAAAAAAAP-8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAIAAAAAAAB--4AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAHv3gAAAAAAAAAAAAAAAAAA'+
        'AAAAAAAAAAQAAAAAAAAAAAAAAceOAAAAAAAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAB794AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAH--gAAAAAAAAAAAAAAAAAAAAAAAAAAAA'+
        'AAAAAAAAAAAAAAAbe2AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABsDYAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEAAAACBBAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAA'+
        'AAAAAD-wAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAB-+8AAAAAAAAAAAAAAAAAAAAAAAAAAAgAgAAAAAAAAAAAAAfVVcAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAH-+6sA'+
        'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA3VVVYAAAAAAAAAAAAAAAAAgAAAAAAAAAAEAAAAAAAAAAAAH--7qwAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA-dVVVgAAAAAAAAAA'+
        'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAH--7qrAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA-dVVVGAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAACAAAAAAAD---uqoAAAAAAgAAAAAAAAAAAAA'+
        'AAAAAAAAAAAAAAAAAAAAf91VVUQAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAAAAAAAB--8OqrAAAAAAAAAAAAAAAAAAAAAAGACvwAAAANj4AAAAGMP91RVUTgA+wAAB6Dz4AAADAAAAAwGOAbqBkAA'+
        'AqaxAgAAIk---G6qrGKqAGOi8Ka4AABgAAIAB8TWBUQ9wcAEXFvFAADc3-3UVVRUTUQAUVxTRFA8APO4QCAKk6pKqqqggAKqqrLAAKq---w6qri6qgAmqq6qqCgB+qwHIAE5AYEAURsAGBAEGTAAEB'+
        '-3VFVRExEAYBgQAQAAUAH5UiHZqqqquqqqqoAKqqqqqICqv-4AAKq6qqogKqqqqq+qG-yqOqywAABQAEAEIAAABABEBAAf-AAAVFAAANaEAAQABUHz-EUURlIqAqgAoio6CAACKqvBAD-+AACqsqIA'+
        'qqogCggCoof+AiqqoAAAAAAAABXgAAAAATsAf-dUVVEIAAARAAAAAAABP--BgAAAqICoAIiKqqgICIiqqoj---xuqqqIAKqICAgIAop--+qoiqAAAAAAAAAEQAAAAABAAH-91FVUSAAAAAAAAAAAAA'+
        'WaAAAAAAAAIAAAACqgAAAAAioAf--8OqqoAAAKAAAAAAICJ-4AgAIAAAAAAAAAAAAAAAAAAAB-91RVUQgAAAAAAAAAAAAHbgAAAAACAAAAgAAAIAAACAAAAn---G6qqCAAAAgAAAIAAAL0AAAgAAgA'+
        'AAIAAACAAAAgAAAIf-3UVVRIgAAAIAAACAAAA-wAAIAAIAAACAAAAgAAAIAAACB---w6qqoAAACAAAAgAAAB+AACAAAAAAgAAAQAAAIAAAEAAH-3VFVRCAAgAAAQAAAIAAf+AAACAAAAIAAAEAAACA'+
        'AABAAAf--8bqqoAIAAAEAAACAAH-+AAAgAAACAAABAAAAgAAAQAAB--dRVVEgCAAABAAAAgAA-b8AAIAAAgAAAgAAAgAMAgAAAgH---DqqqAAAgAADAAAAgH9v4AAAgAIAAAIAAAIAB4IAAAIAf-dU'+
        'VVEIAAIAAAeAAAIA729wAAIACAAACAAACAAPyAAACAB---xuqqgACAAAD8AACAbPbzAACAgAABAAACAAAB-gAIAAAX-91FVUSAAACAAf4AAAL89vOAAAgAAAQAAAgAAAP-ACMAAEf--8OqqoAADAAD'+
        '-wAACMj28YAAIAAAEAAAIAAAH-+AhIABB-91RVUQgAASAB--gAAgyPbxgACAACAAAIAAAgB974AMwAAH---G6qqIADMgfe+AAALI9vGAACAAgAACAAAIAP--4BzgAAf-3UVVRKAAc4D--+AACPj28c'+
        'AAgAAACAAAQAAB9tvwP-CAB---+6qqgQD-yfbb8AACePbwAACAAAAj4AEAAAn--+B3ugAH-3VVVRCEAd7h---gAAgA9vAAAgAAAJ-8BAAAIf--8Pt4AAf---7qqpAD7eH---AAIAG-8AAIAAIAP-4A'+
        'AgAAr-6i3+ggB--dVVVEggN-oK-+oAAgAfb4ACAACAA--gAIAACr+qjtuD4H---7qqqIA7bgq-qgAIAB9vgAgAAgAFUBACAAAhgMAH-y-4f-dVVVEKAB-8AYDAACAAP2-AIAAAAQagEAAEAAHBwBP+'+
        'Cgh-------kPj-gBxcAAgAA-b8IAAEAEBVAQABAAAAAAQAANCAEAACAABD-gAAAQAAIAAH9v6AABABAGoBAAQAAIAAEAABoEBAAAgAAQKCIAAEAACAAAf2-gAAQAAC1QCAACAACAACAAFQQCAACAAC'+
        'A0KAACAACAACD-b-ACAAAAj--4AAgAAgAAgAAf-AgAAgAAgGgQAAgAAgAAgP8P8AgAAAIAAAAAIAAIAAIAAIAAIAAIAAIAVBAAIAAIAAIAfgfgIAAAABAACAAEAAIAAQAAgABAACAAEAB-8AQAAgAB'+
        'AAAAAAAAIAAAQAAgABAACAAEAAIAAQAAgABAAAAAEAAIAAQAAgABAACAA']
    };
    /* ----- CASTLORKS ART: sprite rows, same sizes and anchors as before ----- */
    var BODY = ['...###...', '..#####..', '.#######.', '.##.#.##.', '..#####..', '...#.#...', '.#######.', '##.###.##', '##.#.#.##', '#..###..#', '..#.#.#..', '..##.##..', '.###.###.'];
    var BODY_BACK = ['...###...', '..#####..', '.#######.', '.#######.', '..#####..', '...###...', '.#######.', '##..#..##', '##.###.##', '#...#...#', '..#####..', '..##.##..', '.###.###.'];
    var BODY_B = BODY.slice(0, 11).concat(['..#...#..', '.##...##.']);
    var BODY_BACK_B = BODY_BACK.slice(0, 11).concat(['..#...#..', '.##...##.']);
    var PLUME = ['.#.#.#.', '..###..'];
    var SWORD = { UP: [[14, 7], [15, 6], [16, 5], [17, 4], [18, 3]], RIGHT: [[14, 8], [15, 9], [16, 10], [17, 11], [18, 12]], LEFT: [[4, 7], [3, 6], [2, 5], [1, 4], [0, 3]], DOWN: [[4, 8], [3, 9], [2, 10], [1, 11], [0, 12]] };
    function playerRows(dir, frame, power) {
        var back = dir === 'UP' || dir === 'LEFT', body = back ? (frame ? BODY_BACK_B : BODY_BACK) : (frame ? BODY_B : BODY), rows = [], i, s = SWORD[dir];
        for (i = 0; i < 3; i++) { rows.push(new Array(20).join('.')); }
        body.forEach(function (r) { rows.push('.....' + r + '.....'); });
        if (power) { PLUME.forEach(function (r, k) { rows[k + 1] = rows[k + 1].slice(0, 6) + r + rows[k + 1].slice(13); }); }
        s.forEach(function (p) { var row = rows[p[1] + 3]; rows[p[1] + 3] = row.slice(0, p[0]) + '#' + row.slice(p[0] + 1); });
        return rows;
    }
    var LOOT = ['....###....', '..#######..', '.#########.', '.#.#...#.#.', '.#.......#.', '.#########.', '..#######..', '..##...##..', '.###...###.'];
    var LOOT_B = LOOT.slice(0, 7).concat(['...##.##...', '..##...##..']);
    var LOOT_EAT = ['....###....', '..#######..', '.##.....##.', '.#...#...#.', '.#..###..#.', '.#...#...#.', '.##.....##.', '..##...##..', '.###...###.'];
    var LOOT_EAT2 = ['....###....', '..##.#.##..', '.#..###..#.', '.#...#...#.', '.#.......#.', '.#.......#.', '.##.....##.', '..##...##..', '.###...###.'];
    var PAGE_A = ['##.....##', '.##.#.##.', '..#####..', '..#.#.#..', '..#####..'];
    var PAGE_B = ['.........', '.#.###.#.', '##.#.#.##', '#.#####.#', '..#####..'];
    var BOSS = ['.......#.......', '......###......', '.....#####.....', '.....#####.....', '....#######....', '..###########..', '....#.#.#.#....', '....#######....', '.....#...#.....', '.....#####.....', '...#########...', '.##..#####.####', '.##.##.#.##.#.#', '.#..#.###.#.###', '....#######.###', '...#########...', '..###########..'];
    var HELMET = ['.....#.....', '...#####...', '.#########.', '.##.###.##.', '.##.###.##.', '.#.......#.'];
    var GEMART = ['....#....', '...###...', '..#.#.#..', '.#######.', '#########', '.##.#.##.', '..#####..', '...###...', '....#....'];
    var WARP_A = ['....#....', '....#....', '..#.#.#..', '...###...', '#########', '...###...', '..#.#.#..', '....#....', '....#....'];
    var WARP_B = ['.........', '.#.....#.', '..#...#..', '.........', '....#....', '.........', '..#...#..', '.#.....#.', '.........'];
    var DUST_A = ['.#.....#.', '#.#...#.#', '.........'];
    var DUST_B = ['#.......#', '.........', '.........'];
    var CRUMB = ['...#.#...', '.#.....#.', '....#....'];
    var DEAD = ['.###.....#.....', '#.#.#########.#', '.###.....#.....'];
    var SOUL = ['..###..', '.#####.', '.#.#.#.', '.#####.', '.#####.', '.##.##.', '.#...#.', '.......', '.......'];
    function tileRows(par, th) {
        var rows = [], inD = function (x, y) { return Math.abs(x + 0.5 - TW / 2) / (TW / 2) + Math.abs(y + 0.5 - TH / 2) / (TH / 2) <= 1; };
        var bottom = [], x, y, H = TH + th, solid = function (px, py) { return py >= 0 && py < H && px >= 0 && px < TW && (inD(px, py) || (py >= bottom[px] && py < bottom[px] + th)); }, c, left, edge, v;
        for (x = 0; x < TW; x++) { bottom[x] = TH / 2; for (y = 0; y < TH; y++) { if (inD(x, y)) { bottom[x] = y + 1; } } }
        for (y = 0; y < H; y++) {
            c = '';
            for (x = 0; x < TW; x++) {
                if (!solid(x, y)) { c += '.'; continue; }
                left = x < TW / 2;
                edge = !solid(x - 1, y) || !solid(x + 1, y) || !solid(x, y - 1) || !solid(x, y + 1);
                if (inD(x, y)) {
                    // top face: bright rim round the top, one dark line under it, a quiet floor so figures and gems stand clear of it
                    if (!inD(x, y - 1) || !inD(x - 1, y) || !inD(x + 1, y) || !inD(x, y + 1)) { c += !inD(x, y + 1) ? 'o' : '#'; } else if (par ? (x + y) % 4 === 0 && y % 2 === 0 : (x + 2 * y) % 8 === 0) { c += '#'; } else { c += 'o'; }
                } else if (edge || x === TW / 2 - 1) {
                    // the front corner and the wall outline catch the light; the wall top stays dark under the floor
                    c += !inD(x, y - 1) && y < bottom[x] + th - 1 && x !== TW / 2 - 1 && (x === 0 || x === TW - 1) ? '#' : (y >= bottom[x] + th - 1 ? '#' : (x === TW / 2 - 1 ? '#' : 'o'));
                } else if (left) {
                    // the west wall: courses of masonry, two rows lit in three
                    v = y - Math.floor(x / 2);
                    c += ((v % 3 + 3) % 3) !== 2 && (x + v) % 2 === 0 ? '#' : 'o';
                } else {
                    // the east wall: nearly black, a sparse course of dots
                    v = y + Math.floor(x / 2);
                    c += ((v % 6 + 6) % 6) === 0 && x % 4 === 0 ? '#' : 'o';
                }
            }
            rows.push(c);
        }
        return rows;
    }
    var SPARK_A = ['...#...', '...#...', '...#...', '#######', '...#...', '...#...', '...#...'];
    var SPARK_B = ['.......', '.......', '...#...', '..###..', '...#...', '.......', '.......'];
    // The compass rose: four arrows running along the castle's own diagonals.
    var COMPASS = ['###...............###', '#####...........#####', '#.####.........####.#', '...####.......####...', '.....###.....###.....', '.......##...##.......', '.........###.........', '........#####........', '.........###.........', '.......##...##.......', '.....###.....###.....', '...####.......####...', '#.####.........####.#', '#####...........#####', '###...............###'];
    // A level's castle in miniature, drawn from its own map for the level card.
    function miniRows(L) {
        var m = parseLevel(L).z, W2 = 140, H2 = 96, B2 = [], x, y, s, z, cx, cy, dx, dy, k, half, set = function (px, py, c) { if (B2[py] && px >= 0 && px < W2) { B2[py][px] = c; } };
        for (y = 0; y < H2; y++) { B2.push([]); for (x = 0; x < W2; x++) { B2[y].push('.'); } }
        for (s = 0; s <= 16; s++) {
            for (x = Math.max(0, s - 8); x <= Math.min(8, s); x++) {
                y = s - x; z = m[y][x];
                if (z < 0) { continue; }
                cx = 70 + 7 * (x - y); cy = 14 + Math.floor(3.5 * (x + y)) + 4 * (2 - z);
                for (dy = -3; dy <= 3; dy++) {
                    half = 7 - Math.abs(dy) * 2;
                    for (dx = -half; dx < half; dx++) { set(cx + dx, cy + dy, dx === -half || dx === half - 1 || dy === -3 ? '#' : (x + y) % 2 ? ((cx + dx + cy + dy) % 2 === 0 ? '#' : 'o') : '#'); }
                }
                for (k = 0; k < 3 + 4 * z; k++) {
                    for (dx = -7; dx < 7; dx++) {
                        dy = 4 - Math.floor(Math.abs(dx + 0.5) / 2) + k;
                        set(cx + dx, cy + dy, dx === -1 ? '#' : dx < 0 ? ((cx + dx + cy + dy) % 2 === 0 ? '#' : 'o') : 'o');
                    }
                }
            }
        }
        return B2.map(function (r) { return r.join(''); });
    }
    function unpack(p) {
        var A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+-', bits = [], i, k, v, rows = [];
        for (i = 0; i < p[2].length; i++) { v = A.indexOf(p[2].charAt(i)); for (k = 5; k >= 0; k--) { bits.push(v >> k & 1 ? '#' : '.'); } }
        for (i = 0; i < p[1]; i++) { rows.push(bits.slice(i * p[0], (i + 1) * p[0]).join('')); }
        return rows;
    }
    function defineSprites(H) {
        var i, d, f, k;
        for (k = 0; k < ORDER.length; k++) {
            for (f = 0; f < 2; f++) {
                for (i = 0; i < 2; i++) { sprite('p' + ORDER[k] + f + i, playerRows(ORDER[k], f, i), 9, 15); }
            }
        }
        sprite('pdead', DEAD, 7, 2); sprite('soul', SOUL, 3, 8);
        sprite('l0', LOOT, 5, 8); sprite('l1', LOOT_B, 5, 8); sprite('le', LOOT_EAT, 5, 8); sprite('le2', LOOT_EAT2, 5, 8);
        sprite('l0s', dither(LOOT), 5, 8); sprite('l1s', dither(LOOT_B), 5, 8);
        sprite('pgA', PAGE_A, 4, 3); sprite('pgB', PAGE_B, 4, 3);
        sprite('boss', BOSS, 7, 16); sprite('bosss', dither(BOSS), 7, 16);
        sprite('helm', HELMET, 5, 3);
        sprite('heart', ['.##.##.', '#######', '#######', '.#####.', '..###..', '...#...'], 3, 3);
        sprite('gem', GEMART, 4, 4);
        sprite('warpA', WARP_A, 4, 4); sprite('warpB', WARP_B, 4, 4);
        sprite('dustA', DUST_A, 4, 1); sprite('dustB', DUST_B, 4, 1); sprite('crumb', CRUMB, 4, 1);
        sprite('shadow', ['.ooooooo.', 'ooooooooo', '.ooooooo.'], 4, 1, true);
        sprite('sparkA', SPARK_A, 3, 3); sprite('sparkB', SPARK_B, 3, 3);
        sprite('compass', COMPASS, 10, 7);
        sprite('picTitle', unpack(PIC.title), 0, 0, true);
        sprite('picWin', unpack(PIC.win), 0, 0, true);
        sprite('picOver', unpack(PIC.over), 0, 0, true);
        LEVELS.forEach(function (L, n) { sprite('mini' + n, miniRows(L), 0, 0, true); });
        for (i = 0; i < 2; i++) { for (d = 0; d < 3; d++) { sprite('t' + i + d, tileRows(i, 6 + 8 * d), TW / 2, TH / 2, true); } }
        Object.keys(SPR).forEach(function (n) { H.defSprite(n, SPR[n].rows); });
    }
    // The pixel centre of a cell's floor diamond; z is in rows (8 px).
    function centre(x, y, z) { return { X: 7 * CX + 14 * (x - y) + 14, Y: 8 * (CY + x + y - z) + 4 }; }
    function tileRect(x, y, z) { var c = centre(x, y, z); return { x: c.X - TW / 2, y: c.Y - TH / 2, w: TW, h: TH + 6 + 8 * z }; }
    function pad(n, w) { var s = String(n); while (s.length < w) { s = '0' + s; } return s; }

    function start(H) {
        var G = {
            mode: 'BUSY', level: 0, score: 0, lives: 3, extra: false, best: H.load('best', H.isBest),
            z: null, tr: null, left: 0, total: 0, got: 0, lost: 0, t: 0, anim: 0,
            p: null, loot: [], pages: [], boss: null, helms: [], swarmAt: 0, tagN: 0,
            msg: '', msgMs: 0, ms: 0, wasMode: '', hitBy: '', skip: false,
            bch: [], binv: [], pch: [], pinv: [], force: true,
            sfxQ: [], sfxWait: 0, dP: null, warpOn: false, chain: 0, lastGem: -9999, fx: [], tally: null
        };
        var i0;
        for (i0 = 0; i0 < COLS * ROWS; i0++) { G.bch.push(' '); G.binv.push(false); G.pch.push(null); G.pinv.push(false); }

        /* sound: one note at a time, so the audio queue can't back up behind the action */
        function sfx(notes) {
            if (G.sfxWait <= 0 && !G.sfxQ.length) { G.sfxQ = notes.slice(); pumpSfx(); } else { G.sfxQ = G.sfxQ.concat(notes); }
        }
        function pumpSfx() {
            if (!G.sfxQ.length) { return; }
            var n = G.sfxQ.shift();
            H.beep(n[0], n[1]);
            G.sfxWait = n[1];
        }

        /* ----- the frame buffer: draw the whole castle each tick, send only the cells that changed ----- */
        function B(c, r, ch, inv) {
            if (c < 0 || c >= COLS || r < 0 || r >= ROWS) { return; }
            G.bch[r * COLS + c] = ch;
            G.binv[r * COLS + c] = !!inv;
        }
        function BT(c, r, s, inv) { var i; for (i = 0; i < s.length; i++) { B(c + i, r, s.charAt(i), inv); } }
        function clearBuf() { var i; for (i = 0; i < COLS * ROWS; i++) { G.bch[i] = ' '; G.binv[i] = false; } }
        function flush() {
            var i, r, c;
            for (r = 0; r < ROWS; r++) {
                for (c = 0; c < COLS; c++) {
                    i = r * COLS + c;
                    if (G.force || G.bch[i] !== G.pch[i] || G.binv[i] !== G.pinv[i]) {
                        H.put(c, r, G.bch[i], G.binv[i]);
                        G.pch[i] = G.bch[i]; G.pinv[i] = G.binv[i];
                    }
                }
            }
            G.force = false;
        }
        function flash(s, ms) { G.msg = s; G.msgMs = ms || 2200; }

        /* ----- screens ----- */
        function lines(rows, hold, art) {
            var i;
            H.cls();
            G.force = true;
            if (art) { H.then(function () { H.sprites(art); }); }
            for (i = 0; i < rows.length; i++) { H.line(rows[i], { center: true, hold: hold || 10 }); }
        }
        function showTitle() {
            G.mode = 'BUSY';
            var b = G.best;
            var best = b ? 'BEST ' + pad(b.score, 5) + (b.won ? '  KEEP CLEARED' : '  REACHED LEVEL ' + b.level) : 'BEST 00000  (NONE YET)';
            // These lines go to the screen reader log as they always did, then the cells are blanked for the picture.
            lines(['G E M', 'C A S T L O R K S', 'THE GEMS ARE IN THE CASTLE.', 'SO ARE THEY.', 'SPACE  ENTER THE CASTLE', 'H      HOW TO PLAY', 'Q      LEAVE', best], 0);
            H.then(function () {
                H.fill(0, 0, COLS, ROWS, ' ', false);
                H.text(0, 20, 'THE GEMS ARE IN THE CASTLE. SO ARE THEY.', false);
                H.text(9, 21, 'SPACE  ENTER THE CASTLE', false);
                H.text(8, 22, 'H  HOW TO PLAY   Q  LEAVE', false);
                H.text(Math.floor((COLS - best.length) / 2), 23, best, false);
                G.mode = 'TITLE'; G.ms = 0;
                titleFrame();
            });
        }
        var TWINKLE = [[140, 69], [92, 106], [120, 118], [188, 106], [160, 118], [47, 8], [215, 30], [226, 66], [58, 100]];
        function titleFrame() {
            var list = [{ s: 'picTitle', x: 0, y: 0 }], n = Math.floor(G.ms / 200), i;
            if (!H.reduced) {
                for (i = 0; i < 3; i++) {
                    var w = TWINKLE[(n + i * 3) % TWINKLE.length];
                    list.push({ s: (n + i) % 2 ? 'sparkB' : 'sparkA', x: w[0] - 3, y: w[1] - 3 });
                }
            }
            H.sprites(list);
        }
        // Each help page is a list of rows; its pictures sit in the gutter beside them and step on the same clock.
        function howFrame() {
            var f = Math.floor(G.ms / 280) % 2, list = [], a = G.ms % 1200 / 1200, tick = H.reduced ? 0 : f;
            function at(name, X, Y) { put(list, name, { X: X, Y: Y }, 0); }
            if (G.howPage === 0) {
                at('gem', 16, 22);
                at('compass', 46, 56); at('t00', 46, 90); at('pRIGHT' + tick + '0', 46, 93);
                at('t00', 15, 138); at('t00', 68, 138);
                var px = H.reduced ? 42 : 15 + 53 * a, py = H.reduced ? 124 : 138 - Math.sin(Math.PI * a) * 28;
                at('shadow', px, 140);
                at('pRIGHT00', px, py - 3);
            } else {
                at('l' + tick, 16, 28);
                at(tick ? 'le' : 'le2', 16, 54);
                at('helm', 16, 76); at('pDOWN01', 16, 104);
                at(tick ? 'pgA' : 'pgB', 16, 124);
                at('boss', 16, 164);
            }
            H.sprites(list);
        }
        function showHow(page) {
            G.mode = 'BUSY';
            G.howPage = page || 0;
            H.cls();
            G.force = true;
            (G.howPage ? HOW_B : HOW_A).forEach(function (s, i) { H.line(s, i ? { hold: 10 } : { hold: 10, center: true, inv: true }); });
            H.then(function () { G.mode = 'HOW'; G.ms = 0; howFrame(); });
        }
        function showCard() {
            var L = LEVELS[G.level];
            G.mode = 'BUSY';
            lines(['', 'LEVEL ' + (G.level + 1) + ' OF ' + LEVELS.length, '', L.name, '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''].concat(L.flavor), 10, [{ s: 'mini' + G.level, x: 70, y: 40 }]);
            H.then(function () { G.mode = 'CARD'; G.ms = 0; });
        }

        /* ----- level set-up ----- */
        function placeActors() {
            var L = LEVELS[G.level], i;
            G.p = { x: L.start[0], y: L.start[1], fx: L.start[0], fy: L.start[1], walk: 0, dir: 'UP', want: 'UP', acc: 0, air: null, cool: 0, power: 0, dead: false };
            G.loot = [];
            for (i = 0; i < L.looters; i++) {
                G.loot.push({ id: i, x: L.spawns[i][0], y: L.spawns[i][1], sx: L.spawns[i][0], sy: L.spawns[i][1], fx: L.spawns[i][0], fy: L.spawns[i][1], walk: 0, state: 'wait', t: 1800 + i * 2200, acc: 0 });
            }
            G.pages = [];
            G.boss = L.boss ? { x: L.bossStart[0], y: L.bossStart[1], fx: L.bossStart[0], fy: L.bossStart[1], acc: 0, stun: 0, here: false } : null;
            G.tagN = 0;
        }
        function initLevel() {
            var L = LEVELS[G.level], m = parseLevel(L);
            G.z = m.z; G.tr = m.tr; G.total = m.total; G.left = m.total; G.got = 0; G.lost = 0;
            G.t = 0; G.anim = 0; G.swarmAt = L.swarm; G.skip = false; G.warpOn = false; G.chain = 0; G.lastGem = -9999; G.fx = []; G.tally = null;
            G.helms = L.helms.map(function (h) { return { x: h[0], y: h[1], on: true, back: 0 }; });
            placeActors();
        }
        function beginLevel() {
            initLevel();
            G.mode = 'BUSY';
            H.cls();
            G.force = true;
            H.then(function () {
                G.mode = 'READY';
                G.ms = 0;
                G.msg = 'HOLD!'; G.msgMs = 0;
                compose();
            });
        }

        /* ----- scoring ----- */
        function saveBest(won) {
            if (G.score > (G.best ? G.best.score : 0)) {
                G.best = { score: G.score, level: G.level + 1, won: !!won };
                H.save('best', G.best);
            }
        }
        function award(n) {
            G.score += n;
            if (!G.extra && G.score >= EXTRA_AT) {
                G.extra = true;
                G.lives += 1;
                flash('A BARD WROTE A SONG. EXTRA LIFE.');
                sfx([[784, 60], [1047, 60], [1319, 90]]);
            }
        }

        /* ----- landing dust and eaten-gem crumbs: short pixel effects, at most six, none with reduced motion ----- */
        function addFx(kind, x, y, life) {
            if (H.reduced) { return; }
            G.fx.push({ kind: kind, x: x, y: y, age: 0, life: life });
            while (G.fx.length > 6) { G.fx.shift(); }
        }

        /* ----- the player ----- */
        function pcell() {
            var a = G.p.air, f;
            if (!a) { return [G.p.x, G.p.y]; }
            f = Math.min(1, a.t / a.dur);
            return [Math.round(a.fx + (a.tx - a.fx) * f), Math.round(a.fy + (a.ty - a.fy) * f)];
        }
        function arrive() {
            var p = G.p, i, h, L = LEVELS[G.level];
            if (G.tr[p.y][p.x]) {
                G.tr[p.y][p.x] = false;
                G.left--;
                G.got++;
                award(GEM);
                G.chain = G.t - G.lastGem < 700 ? G.chain + 1 : 0;
                G.lastGem = G.t;
                if (G.sfxWait <= 0) { H.beep(Math.min(1320, 520 + 40 * G.chain), 20); G.sfxWait = 30; }
            }
            for (i = 0; i < G.helms.length; i++) {
                h = G.helms[i];
                if (h.on && h.x === p.x && h.y === p.y) {
                    h.on = false; h.back = HELM_BACK;
                    p.power = L.helm;
                    G.tagN = 0;
                    flash('HELM ON. TAG ANYTHING.', 1800);
                    sfx([[330, 50], [440, 50], [554, 80]]);
                }
            }
            if (L.warp && G.level === 0 && p.x === L.warp[0] && p.y === L.warp[1] && warpAwake()) { warp(); return; }
            if (G.left === 0) { clearLevel(true); }
        }
        // The secret tile stays dormant until half the gems are gone: a landing on it before then does nothing.
        function warpAwake() { return G.level === 0 && G.got + G.lost >= WARP_AT; }
        function warp() {
            G.skip = true;
            award(1000);
            clearLevel(false);
        }
        function stepPlayer() {
            var p = G.p, n = nbr(G.z, p.x, p.y, p.want);
            if (n) { p.dir = p.want; } else if (!nbr(G.z, p.x, p.y, p.dir)) { p.dir = p.want; }
            n = nbr(G.z, p.x, p.y, p.dir);
            p.fx = p.x; p.fy = p.y;
            if (!n) { return; }
            p.walk++;
            p.x = n[0]; p.y = n[1];
            arrive();
            if (G.mode === 'PLAY') { checkGround(); }
        }
        function jump() {
            var p = G.p, d = p.want || p.dir, dv = DIRS[d], x1 = p.x + dv[0], y1 = p.y + dv[1], x2 = x1 + dv[0], y2 = y1 + dv[1], t = [p.x, p.y], n, f, zf;
            if (p.air || p.cool > 0) { return; }
            if (x2 >= 0 && y2 >= 0 && x2 < N && y2 < N && G.z[y2][x2] >= 0 && Math.abs(G.z[y2][x2] - G.z[p.y][p.x]) <= 2) { t = [x2, y2]; } else {
                n = nbr(G.z, p.x, p.y, d);
                if (n) { t = n; }
            }
            p.dir = d;
            f = Math.min(1, p.acc / PLAYER_MS);
            zf = G.z[Math.round(p.fy)] && G.z[Math.round(p.fy)][Math.round(p.fx)] >= 0 ? G.z[Math.round(p.fy)][Math.round(p.fx)] : G.z[p.y][p.x];
            // the figure leaves from where it is drawn, which trails the logical cell by up to one step
            p.air = { fx: p.x, fy: p.y, tx: t[0], ty: t[1], fz: G.z[p.y][p.x], tz: G.z[t[1]][t[0]], t: 0, dur: AIR_MS, sx: p.fx + (p.x - p.fx) * f, sy: p.fy + (p.y - p.fy) * f, sz: zf + (G.z[p.y][p.x] - zf) * f };
            sfx([[500, 25], [700, 35]]);
        }
        function updateAir() {
            var p = G.p, a = p.air;
            a.t += TICK;
            if (G.mode === 'PLAY') { if (a.t < 80 || a.t > a.dur - 80) { checkGround(); } else { checkFliers(); } }
            if (G.mode === 'PLAY' && a.t >= a.dur) {
                p.x = a.tx; p.y = a.ty; p.fx = p.x; p.fy = p.y; p.air = null; p.cool = COOL_MS; p.acc = 0;
                addFx('dust', p.x, p.y, 240);
                arrive();
                if (G.mode === 'PLAY') { checkGround(); }
            }
        }

        /* ----- hazards ----- */
        function hit(by, text) {
            if (G.mode !== 'PLAY') { return; }
            G.lives--;
            G.mode = 'DYING';
            G.ms = 0;
            G.p.power = 0;
            G.chain = 0; G.fx = [];
            G.p.dead = true;
            G.msg = text; G.msgMs = 0;
            sfx([[440, 120], [330, 120], [220, 240]]);
        }
        function tagLoot(m, eating) {
            var pts = eating && G.p.power <= 0 ? 300 : TAGS[Math.min(G.tagN, TAGS.length - 1)];
            if (G.p.power > 0) { G.tagN++; }
            m.state = 'down'; m.t = DOWN_MS;
            award(pts);
            flash(eating && G.p.power <= 0 ? 'CAUGHT MID-BITE: ' + pts : 'LOOTER TAGGED OUT: ' + pts);
            sfx([[1047, 40], [1319, 40], [1568, 70]]);
        }
        var LOOT_HIT = ['A LOOTER CLUBBED YOU WITH A FOAM SWORD.', 'THE LOOTER SAYS "LIGHT". IT WAS NOT.', 'A LOOTER TAPPED YOU. YOU ARE DEAD, SIR.', 'THE ARM WAS FINE. THE REST WAS NOT.'];
        // Ground hazards reach a player on the floor, and one in the first or last 80 ms of a jump.
        function checkGround() {
            var p = G.p, i, m, c = pcell();
            for (i = 0; i < G.loot.length; i++) {
                m = G.loot[i];
                if ((m.state === 'walk' || m.state === 'eat') && m.x === c[0] && m.y === c[1]) {
                    if (p.power > 0 || m.state === 'eat') { tagLoot(m, m.state === 'eat'); } else { hit('loot', LOOT_HIT[H.rand(LOOT_HIT.length)]); return; }
                }
            }
            checkFliers();
        }
        // Pages and the boss reach you even in the air.
        function checkFliers() {
            var c = pcell(), p = G.p, i, g, b = G.boss;
            if (b && b.here && b.x === c[0] && b.y === c[1] && b.stun <= 0) {
                if (p.power > 0) {
                    b.stun = 5000;
                    award(1000);
                    flash('GUILDMASTER TAGGED. HE ALLOWS IT: 1000');
                    sfx([[1047, 40], [1319, 40], [1568, 70]]);
                } else { hit('boss', 'THE GUILDMASTER DECLARES YOU DEAD.'); return; }
            }
            for (i = 0; i < G.pages.length; i++) {
                g = G.pages[i];
                if (g.down <= 0 && Math.round(g.x) === c[0] && Math.round(g.y) === c[1]) {
                    if (p.power > 0) {
                        g.down = 6000;
                        award(100);
                        flash('PAGE TAGGED. IT FILES A COMPLAINT: 100', 1500);
                        sfx([[880, 30], [1175, 40]]);
                    } else { hit('page', 'THE PAGES ARRIVED WITH ENTHUSIASM.'); return; }
                }
            }
        }

        /* ----- looters ----- */
        // Every other looter lies in wait: it heads for the cell the player is about to reach, so two of them can pinch you.
        function ambush(m, P) {
            var t = [P.x, P.y], k, n;
            if (m.id % 2 === 0 || P.power > 0) { return t; }
            for (k = 0; k < 4; k++) { n = nbr(G.z, t[0], t[1], P.dir); if (!n) { break; } t = n; }
            return t;
        }
        function lootStep(m) {
            var P = G.p, L = LEVELS[G.level], opts = [], k, n, best = [], bv, v, goal, srcs = [], x, y, dist;
            for (k = 0; k < 4; k++) { n = nbr(G.z, m.x, m.y, ORDER[k]); if (n) { opts.push(n); } }
            if (!opts.length) { return; }
            if (P.power > 0 || H.rand(100) < L.chase * 100) {
                dist = bfs(G.z, [ambush(m, P)]);
                goal = P.power > 0 ? -1 : 1;
            } else {
                for (y = 0; y < N; y++) { for (x = 0; x < N; x++) { if (G.tr[y][x]) { srcs.push([x, y]); } } }
                if (!srcs.length) { return; }
                dist = bfs(G.z, srcs);
                goal = 1;
            }
            bv = Infinity;
            for (k = 0; k < opts.length; k++) {
                v = dist[idx(opts[k][0], opts[k][1])] * goal;
                if (dist[idx(opts[k][0], opts[k][1])] < 0) { v = Infinity; }
                if (v < bv) { bv = v; best = [opts[k]]; } else if (v === bv) { best.push(opts[k]); }
            }
            if (!best.length) { best = opts; }
            if (H.rand(100) < 12) { best = opts; }
            n = best[H.rand(best.length)];
            m.fx = m.x; m.fy = m.y; m.walk++;
            m.x = n[0]; m.y = n[1];
            if (G.tr[m.y][m.x]) {
                G.tr[m.y][m.x] = false;
                G.left--;
                G.lost++;
                G.score = Math.max(0, G.score - GEM);
                addFx('crumb', m.x, m.y, 160);
                m.state = 'eat'; m.t = L.eat;
                if (G.sfxWait <= 0) { H.beep(180, 40); G.sfxWait = 60; }
            }
        }
        function updateLoot() {
            var L = LEVELS[G.level], i, m;
            for (i = 0; i < G.loot.length; i++) {
                m = G.loot[i];
                if (m.state === 'wait' || m.state === 'down') {
                    m.t -= TICK;
                    if (m.t <= 0) {
                        if (Math.abs(G.p.x - m.sx) + Math.abs(G.p.y - m.sy) < 3) { m.t = 400; } else { m.state = 'walk'; m.x = m.sx; m.y = m.sy; m.fx = m.x; m.fy = m.y; m.acc = 0; }
                    }
                } else if (m.state === 'eat') {
                    m.t -= TICK;
                    if (m.t <= 0) { m.state = 'walk'; m.acc = 0; }
                } else {
                    m.acc += TICK;
                    if (m.acc >= (G.p.power > 0 ? L.step * 1.4 : L.step)) {
                        m.acc = 0;
                        lootStep(m);
                        if (!G.p.air || G.p.air.t < 80 || G.p.air.t > G.p.air.dur - 80) { checkGround(); if (G.mode !== 'PLAY') { return; } }
                        if (G.left === 0) { clearLevel(false); return; }
                    }
                }
            }
        }

        /* ----- the boss and the pages ----- */
        function updateBoss() {
            var b = G.boss, L = LEVELS[G.level], dist, k, n, bv, best = null, P = G.p;
            if (!b) { return; }
            if (!b.here) {
                if (G.t >= BOSS_AT) { b.here = true; flash('THE GUILDMASTER OF REEVES HAS ARRIVED.', 2600); sfx([[110, 200], [98, 300]]); }
                return;
            }
            if (b.stun > 0) { b.stun -= TICK; return; }
            b.acc += TICK;
            if (b.acc < L.bossMs) { return; }
            b.acc = 0;
            dist = bfs(G.z, [[P.x, P.y]]);
            bv = P.power > 0 ? -1 : Infinity;
            for (k = 0; k < 4; k++) {
                n = nbr(G.z, b.x, b.y, ORDER[k]);
                if (!n) { continue; }
                if (P.power > 0 ? dist[idx(n[0], n[1])] > bv : dist[idx(n[0], n[1])] < bv) { bv = dist[idx(n[0], n[1])]; best = n; }
            }
            if (best) { b.fx = b.x; b.fy = b.y; b.x = best[0]; b.y = best[1]; }
            checkFliers();
        }
        function updatePages() {
            var L = LEVELS[G.level], i, g, c = pcell(), dx, dy, d, sp, wob, corners = [[-2, -2], [10, -2], [-2, 10], [10, 10]], k;
            if (!G.pages.length && G.t >= G.swarmAt && L.pages) {
                k = H.rand(4);
                for (i = 0; i < L.pages; i++) { G.pages.push({ x: corners[k][0] + i * 0.7, y: corners[k][1] - i * 0.7, down: 0, ph: i * 2.1 }); }
                flash('THE PAGES HAVE HEARD YOU ARE SLOW.', 2400);
                sfx([[262, 80], [330, 80]]);
            }
            for (i = 0; i < G.pages.length; i++) {
                g = G.pages[i];
                if (g.down > 0) { g.down -= TICK; if (g.down <= 0) { g.x = corners[H.rand(4)][0]; g.y = corners[H.rand(4)][1]; } continue; }
                dx = c[0] - g.x; dy = c[1] - g.y;
                d = Math.sqrt(dx * dx + dy * dy) || 1;
                sp = L.pspeed * (1 + Math.max(0, G.t - G.swarmAt) / 40000) * TICK / 1000;
                if (G.p.power > 0) { sp = -sp; }
                wob = Math.sin(G.t / 260 + g.ph) * 0.5 * Math.abs(sp);
                g.x += (dx / d) * sp - (dy / d) * wob;
                g.y += (dy / d) * sp + (dx / d) * wob;
            }
            checkFliers();
        }
        function updateHelms() {
            var i, h;
            for (i = 0; i < G.helms.length; i++) {
                h = G.helms[i];
                if (!h.on) { h.back -= TICK; if (h.back <= 0) { h.on = true; } }
            }
        }

        /* ----- clearing a castle ----- */
        function clearLevel(byPlayer) {
            var fast = G.skip ? 0 : Math.max(0, Math.floor((45000 - G.t) / 100)), sweep = G.lost === 0 && !G.skip ? 100 * (G.level + 1) : 0, last = byPlayer ? 250 * (G.level + 1) : 0, tot = fast + sweep + last;
            G.mode = 'CLEAR';
            G.ms = 0;
            G.p.power = 0;
            G.fx = [];
            G.tally = { rem: tot, step: Math.max(10, Math.ceil(tot / 30)), i: 0, at: 600, end: 0, done: false, bard: 0, fanfare: !G.skip, text: '' };
            if (G.skip) { G.tally.text = 'SECRET! THE SHORTCUT SKIPS A LEVEL.'; } else if (byPlayer) { G.tally.text = 'LAST GEM! +' + last + '  SPEED +' + fast + (sweep ? '  CLEAN +' + sweep : ''); } else { G.tally.text = 'LOOTERS TOOK THE LAST ONE.  SPEED +' + fast; }
            G.msg = G.tally.text;
            G.msgMs = 0;
            if (G.skip) { sfx([[1568, 50], [1319, 50], [1047, 50], [784, 50], [523, 120]]); }
        }
        // The bonus counts up into the score, then holds. SPACE or ENTER pays the rest at once.
        function tallyTick(rush) {
            var T = G.tally, n;
            if (!T || T.done) { return; }
            if (rush) { if (T.rem > 0) { award(T.rem); } T.rem = 0; } else {
                while (T.rem > 0 && G.ms >= T.at) {
                    n = Math.min(T.step, T.rem);
                    award(n);
                    T.rem -= n;
                    H.beep(Math.min(1400, 440 + T.i * 32), 20);
                    T.i++;
                    T.at += 80;
                }
            }
            if (G.msg !== T.text) {
                // the extra-life song may borrow the message row for a moment
                if (!T.bard) { T.bard = G.ms + 1800; }
                if (G.ms >= T.bard) { G.msg = T.text; }
            }
            if (T.rem <= 0 && (rush || G.ms >= 600)) {
                T.done = true;
                T.end = Math.max(G.ms + 1200, 2600);
                saveBest(false);
                if (T.fanfare) { sfx([[523, 70], [659, 70], [784, 70], [1047, 130]]); }
            }
        }
        function advance() {
            G.level += G.skip ? 2 : 1;
            if (G.level >= LEVELS.length) { G.level = LEVELS.length - 1; showWin(); return; }
            showCard();
        }
        function afterHit() {
            if (G.lives <= 0) { showOver(); return; }
            var L = LEVELS[G.level], keep = G.tr, left = G.left;
            placeActors();
            G.tr = keep; G.left = left;
            G.swarmAt = G.t + L.swarm * 0.5;
            G.mode = 'READY';
            G.ms = 0;
            G.msg = 'HOLD!'; G.msgMs = 0;
        }

        /* ----- drawing: text for the HUD, sprites for the castle ----- */
        // Where something is on screen, easing from the cell it left to the cell it is in.
        function lerpPos(fx, fy, x, y, f) {
            var zf = G.z[Math.round(fy)] ? G.z[Math.round(fy)][Math.round(fx)] : 0, zt = G.z[y][x];
            f = Math.max(0, Math.min(1, f));
            if (zf < 0) { zf = zt; }
            return centre(fx + (x - fx) * f, fy + (y - fy) * f, zf + (zt - zf) * f);
        }
        function put(list, name, c, dy, k) {
            var d = SPR[name];
            list.push({ s: name, x: Math.round(c.X - d.ax), y: Math.round(c.Y - d.ay + (dy || 0)), k: k === undefined ? 99 : k });
        }
        function moverPos(m, ms) { return lerpPos(m.fx === undefined ? m.x : m.fx, m.fy === undefined ? m.y : m.fy, m.x, m.y, ms); }
        function playerName(g) {
            var p = G.p, f = p.walk % 2, pw = p.power > 0 && (p.power > 1800 || H.reduced || Math.floor(G.anim / 120) % 2 === 0) ? 1 : 0;
            return 'p' + p.dir + (g ? 0 : f) + pw;
        }
        function castleSprites(list) {
            var s, x, y, z, c, i, m, h, p = G.p, L = LEVELS[G.level], b = G.boss, blink = !H.reduced && Math.floor(G.anim / 160) % 2 === 0, lit, nm, pos;
            // A figure is drawn after every cell it is standing on or crossing: gliding north, it still overlaps the deeper cell it left.
            function depthOf(m) { return Math.max(m.x + m.y, Math.round(m.fx === undefined ? m.x : m.fx) + Math.round(m.fy === undefined ? m.y : m.fy)); }
            function add(name, m, f, k, who) { mv.push({ k: k, name: name, pos: moverPos(m, f), who: who }); }
            function emit(sum) {
                var j, q = [];
                for (j = 0; j < mv.length; j++) { if (mv[j].k === sum) { q.push(mv[j]); } }
                q.sort(function (u, v) { return u.pos.Y - v.pos.Y || u.who - v.who; });
                for (j = 0; j < q.length; j++) { put(list, q[j].name, q[j].pos, 3, sum); }
            }
            var mv = [];
            for (i = 0; i < G.loot.length; i++) {
                m = G.loot[i];
                if (m.state === 'walk' || m.state === 'eat') {
                    nm = m.state === 'eat' ? (blink ? 'le' : 'le2') : 'l' + (m.walk % 2);
                    if (p.power > 0 && (p.power > 1800 || blink)) { nm = m.state === 'eat' ? 'l0s' : nm + 's'; }
                    add(nm, m, m.state === 'eat' ? 1 : m.acc / L.step, depthOf(m), 1);
                }
            }
            if (b && b.here) { add(b.stun > 0 || p.power > 0 ? 'bosss' : 'boss', b, b.acc / L.bossMs, depthOf(b), 2); }
            if (!p.air) { add(p.dead ? 'pdead' : playerName(false), p, p.acc / PLAYER_MS, depthOf(p), 3); }
            lit = G.mode === 'CLEAR' && !H.reduced && Math.floor(G.ms / 280) % 2 === 0;
            var rise = G.mode === 'READY' && G.t === 0 && !H.reduced ? G.ms / 30 : 99;
            for (s = 0; s <= 2 * (N - 1); s++) {
                if (rise < s) { break; }
                for (x = Math.max(0, s - (N - 1)); x <= Math.min(N - 1, s); x++) {
                    y = s - x; z = G.z[y][x];
                    if (z < 0) { continue; }
                    c = centre(x, y, z);
                    put(list, 't' + (lit ? 1 - (x + y) % 2 : (x + y) % 2) + z, c, 0, s);
                    if (G.tr[y][x]) { put(list, 'gem', c, -1, s); }
                    if (G.warpOn && G.level === 0 && L.warp && L.warp[0] === x && L.warp[1] === y) { put(list, H.reduced || Math.floor(G.anim / 200) % 2 === 0 ? 'warpA' : 'warpB', c, -2, s); }
                    for (i = 0; i < G.helms.length; i++) {
                        h = G.helms[i];
                        if (h.on && h.x === x && h.y === y) { put(list, 'helm', c, H.reduced || Math.floor(G.anim / 300) % 2 ? -3 : -5, s); }
                    }
                }
                emit(s);
                for (i = 0; i < G.fx.length; i++) {
                    m = G.fx[i];
                    if (m.x + m.y === s) { put(list, m.kind === 'crumb' ? 'crumb' : m.age < 120 ? 'dustA' : 'dustB', centre(m.x, m.y, G.z[m.y][m.x]), 2, s); }
                }
            }
            if (p.dead && !H.reduced) { put(list, 'soul', moverPos(p, 1), -Math.min(40, G.ms / 30)); }
            if (p.air) {
                i = Math.min(1, p.air.t / p.air.dur);
                pos = centre(p.air.sx + (p.air.tx - p.air.sx) * i, p.air.sy + (p.air.ty - p.air.sy) * i, p.air.sz + (p.air.tz - p.air.sz) * i);
                put(list, 'shadow', pos, 2);
                put(list, playerName(true), pos, 3 - Math.sin(Math.PI * i) * 16);
            }
            for (i = 0; i < G.pages.length; i++) {
                m = G.pages[i];
                if (m.down > 0) { continue; }
                put(list, !H.reduced && Math.floor(G.anim / 140 + i) % 2 ? 'pgA' : 'pgB', centre(m.x, m.y, 0), -8);
            }
        }
        function drawHud() {
            var L = LEVELS[G.level], p = G.p, i, s, pw;
            BT(0, 0, 'SCORE ' + pad(G.score, 5) + '   LV ' + (G.level + 1) + '/' + LEVELS.length + '   BEST ' + pad(Math.max(G.score, G.best ? G.best.score : 0), 5), false);
            BT(0, 1, L.name, true);
                        // compass: the keys as the screen sees them
            BT(0, 2, 'LEFT', false); BT(6, 2, 'UP', false);
            BT(0, 6, 'DOWN', false); BT(5, 6, 'RIGHT', false);
            BT(30, 3, 'GEMS ' + pad(G.left, 3), false);
            BT(30, 4, 'LOST ' + pad(G.lost, 3), false);
            pw = p.power > 0 ? Math.ceil(p.power / L.helm * 6) : 0;
            BT(30, 5, 'HELM ' + (pw ? '======'.slice(0, pw) : '-'), false);
            if (G.pages.length) { BT(30, 6, 'PAGES!', !H.reduced && Math.floor(G.anim / 280) % 2 === 0); }
            if (G.boss && G.boss.here) { BT(30, 2, 'BOSS', false); }
            BT(3, 17, 'GEM', false);
            BT(3, 19, 'LOOTER', false);
            BT(3, 21, 'HELM', false);
            BT(30, 20, p.air || p.cool > 0 ? 'JUMP ....' : 'JUMP READY', false);
            BT(0, HELP_ROW, 'ARROWS/WASD  SPACE JUMP  P HOLD  Q QUIT', false);
            if (G.msg) { BT(Math.max(0, Math.floor((COLS - G.msg.length) / 2)), MSG_ROW, G.msg.slice(0, COLS), false); }
        }
        function legend(list) {
            var i, hearts = G.lives;
            put(list, 'gem', { X: 8, Y: 17 * 8 + 4 }, 0);
            put(list, 'l0', { X: 8, Y: 19 * 8 + 8 }, 0);
            put(list, 'helm', { X: 8, Y: 21 * 8 + 4 }, 0);
            put(list, 'compass', { X: 39, Y: 36 }, 0);
            for (i = 0; i < hearts; i++) { put(list, 'heart', { X: 226 + i * 11, Y: 12 }, 0); }
        }
        function compose() {
            var list = [];
            clearBuf();
            drawHud();
            castleSprites(list);
            legend(list);
            G.frame = list;
            G.frameP = G.p.x + ',' + G.p.y + ',' + G.p.fx + ',' + G.p.fy;
            H.sprites(list);
            flush();
        }

        /* ----- per-tick updates ----- */
        function updatePlay() {
            var p = G.p, pw0;
            G.t += TICK;
            G.anim += TICK;
            if (p.power > 0) {
                pw0 = p.power;
                p.power -= TICK;
                if (p.power <= 0) { p.power = 0; flash('HELM OFF.', 1000); } else if (pw0 > 500 && p.power <= 500) { sfx([[988, 25], [784, 25]]); } else if (pw0 > 1000 && p.power <= 1000) { sfx([[988, 25]]); } else if (pw0 > 1500 && p.power <= 1500) { sfx([[988, 25]]); }
            }
            if (!G.warpOn && LEVELS[G.level].warp && warpAwake()) {
                G.warpOn = true;
                flash('A TILE IS HUMMING.', 2200);
                sfx([[880, 40], [1175, 40], [880, 40], [1397, 80]]);
            }
            G.fx = G.fx.filter(function (e) { e.age += TICK; return e.age < e.life; });
            if (p.cool > 0) { p.cool -= TICK; }
            if (p.air) { updateAir(); } else {
                p.acc += TICK;
                while (p.acc >= PLAYER_MS && G.mode === 'PLAY') {
                    p.acc -= PLAYER_MS;
                    stepPlayer();
                }
            }
            if (G.mode !== 'PLAY') { return; }
            updateLoot();
            if (G.mode !== 'PLAY') { return; }
            updateBoss();
            if (G.mode !== 'PLAY') { return; }
            updatePages();
            updateHelms();
            if (G.msgMs > 0) { G.msgMs -= TICK; if (G.msgMs <= 0) { G.msg = ''; } }
        }
        function tick() {
            if (G.sfxWait > 0) {
                G.sfxWait -= TICK;
                if (G.sfxWait <= 0) { pumpSfx(); }
            }
            switch (G.mode) {
                case 'TITLE':
                    G.ms += TICK;
                    if (!H.reduced && G.ms % 200 < TICK) { titleFrame(); }
                    break;
                case 'HOW':
                    G.ms += TICK;
                    if (!H.reduced) { howFrame(); }
                    break;
                case 'CARD':
                    G.ms += TICK;
                    if (G.ms >= 2400) { beginLevel(); }
                    break;
                case 'READY':
                    G.ms += TICK;
                    G.anim += TICK;
                    compose();
                    if (G.ms >= 1500) { G.mode = 'PLAY'; flash('LAY ON!', 900); }
                    break;
                case 'PLAY':
                    updatePlay();
                    if (G.mode === 'PLAY' || G.mode === 'DYING' || G.mode === 'CLEAR') { compose(); }
                    break;
                case 'HOLD':
                case 'ASK':
                    compose();
                    break;
                case 'DYING':
                    G.ms += TICK;
                    compose();
                    if (G.ms >= 2200) { G.p.dead = false; afterHit(); }
                    break;
                case 'CLEAR':
                    G.ms += TICK;
                    G.anim += TICK;
                    tallyTick(false);
                    compose();
                    if (G.tally && G.tally.done && G.ms >= G.tally.end) { advance(); }
                    break;
                default:
                    break;
            }
        }

        /* ----- endings ----- */
        function showOver() {
            saveBest(false);
            G.mode = 'BUSY';
            lines([
                '', '', '', '', '', '', '', '', '', '', '', '',
                'OUT OF LIVES',
                'THE LOOTERS SPLIT YOUR GEMS.',
                'THEY ARE ROCKS. SPRAY PAINTED ROCKS.',
                'THEY WERE THE BOUNDARY MARKERS.',
                'THE LOOTERS ARE VERY PLEASED.',
                'THE GUILDMASTER WRITES YOU UP FOR',
                'NOT WEARING YOUR SASH WHILE DEAD.',
                '',
                'FINAL SCORE ' + pad(G.score, 5),
                '',
                'SPACE TO TRY AGAIN'
            ], 10, [{ s: 'picOver', x: 0, y: 0 }]);
            H.then(function () { G.mode = 'OVER'; });
        }
        function showWin() {
            award(2000);
            saveBest(true);
            G.mode = 'BUSY';
            lines(WIN_TEXT.concat(['', 'FINAL SCORE ' + pad(G.score, 5), '', 'SPACE TO PLAY AGAIN']), H.reduced ? 10 : 220, [{ s: 'picWin', x: 0, y: 0 }]);
            H.then(function () { G.mode = 'OVER'; });
        }
        function newGame() {
            G.level = 0; G.score = 0; G.lives = 3; G.extra = false;
            G.sfxQ = []; G.sfxWait = 0;
            showCard();
        }

        /* ----- input ----- */
        function askQuit() {
            G.wasMode = G.mode;
            G.mode = 'ASK';
            G.msg = 'LEAVE THE CASTLE?  Y OR N'; G.msgMs = 0;
        }
        function onKey(key) {
            var go = key === 'SPACE' || key === 'ENTER';
            switch (G.mode) {
                case 'TITLE':
                    if (go) { newGame(); } else if (key === 'H') { showHow(0); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'HOW':
                    if (go) { if (G.howPage) { showTitle(); } else { showHow(1); } } else if (key === 'Q') { H.quit(); }
                    break;
                case 'CARD':
                    if (go) { beginLevel(); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'CLEAR':
                    if (go) { tallyTick(true); }
                    break;
                case 'OVER':
                    if (go) { showTitle(); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'PLAY':
                case 'READY':
                    if (KEYMAP[key]) { G.p.want = KEYMAP[key]; } else if (key === 'Q') { askQuit(); } else if (key === 'SPACE' && G.mode === 'PLAY') { jump(); } else if (key === 'P' && G.mode === 'PLAY') {
                        G.mode = 'HOLD';
                        G.msg = 'HOLD!  P TO CONTINUE'; G.msgMs = 0;
                    }
                    break;
                case 'HOLD':
                    if (KEYMAP[key]) { G.p.want = KEYMAP[key]; } else if (key === 'Q') { askQuit(); } else if (key === 'P' || go) { G.mode = 'PLAY'; G.msg = ''; }
                    break;
                case 'ASK':
                    // A sits right beside Q on a keyboard, so a stray press must not end the run.
                    if (key === 'Y') { H.quit(); } else if (key === 'N' || go) { G.mode = G.wasMode; G.msg = G.wasMode === 'HOLD' ? 'HOLD!  P TO CONTINUE' : ''; G.msgMs = 0; }
                    break;
                default:
                    break;
            }
        }

        defineSprites(H);
        if (window.OrkpleStrict) { start.dbg = G; }   // tests only
        H.every(TICK, tick);
        H.keys(onKey);
        showTitle();
    }

    window.OrkpleGames = window.OrkpleGames || {};
    window.OrkpleGames.castlorks = {
        title: 'GEM CASTLORKS',
        start: start,
        _pure: { LEVELS: LEVELS, parseLevel: parseLevel, nbr: nbr, bfs: bfs, boxOf: boxOf, tileRect: tileRect, SPR: SPR, idx: idx, ORDER: ORDER, DIRS: DIRS, N: N, COLS: COLS, ROWS: ROWS }
    };
    // State hook for the specs, attached only when the test flag is set.
    if (window.OrkpleStrict) { window.OrkpleGames.castlorks._dbg = function () { return start.dbg; }; }
}());
