/* FROGGORK: an ORKPLE II game. You are late. Sign-in is on the far side of a live battlegame.
   Runs against the terminal's game host; touches nothing but window.OrkpleGames.
   Everything on the field is pixel art on the host's sprite layer (280x192); text is only for the score, messages and screens. */
(function () {
    'use strict';

    var TICK = 40;
    var W = 280, Y0 = 10, BH = 12;
    var GOAL = 0, R1 = 1, R5 = 5, MED = 6, RD1 = 7, RD5 = 11, STARTB = 12;
    var PW = 9, HOP = 10, START_X = 136;
    var SLOTC = [28, 84, 140, 196, 252], TOL = 10;
    var RIDE_SLOP = 2.5;
    var MSG_ROW = 21, TIME_ROW = 22, HELP_ROW = 23;
    var KEYMAP = { UP: 'UP', DOWN: 'DOWN', LEFT: 'LEFT', RIGHT: 'RIGHT', W: 'UP', A: 'LEFT', S: 'DOWN', D: 'RIGHT' };
    var EXTRA_AT = 3500;
    var BAR_COL = 7, BAR_W = 26;

    /* ----- the lanes: bottom half is the battlefield, top half is the hazard ground you ride across ----- */
    // Speeds are pixels per second. w is the width of one object; period is the length of the loop it wraps around.
    function lane(band, kind, dir, v, period, offs, w, duck) {
        return { band: band, kind: kind, dir: dir, v: v, period: period, offs: offs, w: w, duck: duck || null };
    }
    function duck(up, warn, down) { return { up: up, warn: warn, down: down }; }

    var KINDS = {
        stretcher: { river: true }, wall: { river: true, unit: 12 }, tarp: { river: true, unit: 14 }, bearers: { river: true, unit: 12 },
        newbie: { ins: 1, hit: 'THE NEWBIE APOLOGIZES WHILE HITTING YOU.' },
        cart: { ins: 1, hit: 'THE LOANER CART WINS. THE GEAR WAS FREE.' },
        fighters: { ins: 1, hit: 'SHIELD LINE. NO BRAKES. NO APOLOGY.' },
        ball: { ins: 1, hit: 'SPELLBALL, CHEST. YOU CALL IT. FOR ONCE.' },
        arrow: { ins: 2, hit: 'AN ARROW. FOAM TIP. IT STILL COUNTS.' }
    };

    var LEVELS = [
        {
            name: 'THE MUD PIT', theme: 0, time: 32, lurk: 2200, evGap: 6500,
            flavor: ['SIGN-IN IS ACROSS THE BATTLEGAME.', 'THE MUD IS PART OF THE BATTLEGAME.'],
            mud: 'THE MUD KEEPS YOUR BOOT. AND YOU.',
            lanes: [
                lane(1, 'stretcher', 1, 38, 300, [0, 100, 200], 32),
                lane(2, 'bearers', -1, 24, 280, [0, 70, 140, 210], 24),
                lane(3, 'tarp', 1, 32, 320, [0, 160], 56),
                lane(4, 'wall', -1, 28, 300, [0, 100, 200], 36, duck(5200, 700, 1500)),
                lane(5, 'stretcher', 1, 24, 300, [0, 75, 150, 225], 34),
                lane(7, 'arrow', 1, 80, 340, [0, 170], 18),
                lane(8, 'ball', -1, 48, 280, [0, 70, 140, 210], 11),
                lane(9, 'fighters', 1, 34, 320, [0, 160], 30),
                lane(10, 'cart', -1, 24, 300, [0, 150], 24),
                lane(11, 'newbie', 1, 16, 280, [0, 95, 190], 9)
            ]
        },
        {
            name: 'THE CREEK BED', theme: 1, time: 29, lurk: 2600, evGap: 5500,
            flavor: ['NOW THE WATER IS ALSO PLAYING.', 'THE REEVE SAYS IT COUNTS AS TERRAIN.'],
            mud: 'THE CREEK KEEPS YOUR BOOT. AND YOU.',
            lanes: [
                lane(1, 'stretcher', 1, 46, 300, [0, 75, 150, 225], 30),
                lane(2, 'bearers', -1, 30, 284, [0, 71, 142, 213], 24, duck(4600, 700, 1700)),
                lane(3, 'tarp', 1, 40, 321, [0, 107, 214], 42),
                lane(4, 'wall', -1, 36, 300, [0, 100, 200], 36, duck(4200, 700, 1900)),
                lane(5, 'stretcher', 1, 30, 300, [0, 75, 150, 225], 30),
                lane(7, 'arrow', 1, 100, 340, [0, 113, 226], 18),
                lane(8, 'ball', -1, 60, 280, [0, 56, 112, 168, 224], 11),
                lane(9, 'fighters', 1, 42, 321, [0, 107, 214], 30),
                lane(10, 'cart', -1, 32, 300, [0, 100, 200], 24),
                lane(11, 'newbie', 1, 22, 280, [0, 70, 140, 210], 9)
            ]
        },
        {
            name: 'THE BRIDGE BATTLE', theme: 2, time: 26, lurk: 3000, evGap: 4500,
            flavor: ['THE FLOOR IS LAVA. THE REEVE SAID SO.', 'THE BRIDGES ARE MOVING. THAT IS NEW.'],
            mud: 'THE FLOOR WAS LAVA. YOU WERE WARNED.',
            lanes: [
                lane(1, 'bearers', 1, 54, 300, [0, 75, 150, 225], 24, duck(4400, 600, 1800)),
                lane(2, 'bearers', -1, 36, 284, [0, 71, 142, 213], 24, duck(3800, 600, 2000)),
                lane(3, 'tarp', 1, 50, 321, [0, 107, 214], 42),
                lane(4, 'wall', -1, 44, 300, [0, 100, 200], 36, duck(3400, 600, 2000)),
                lane(5, 'stretcher', 1, 36, 300, [0, 75, 150, 225], 28),
                lane(7, 'arrow', 1, 112, 340, [0, 113, 226], 18),
                lane(8, 'ball', -1, 74, 280, [0, 56, 112, 168, 224], 11),
                lane(9, 'fighters', 1, 52, 330, [0, 110, 220], 30),
                lane(10, 'cart', -1, 40, 300, [0, 100, 200], 24),
                lane(11, 'newbie', 1, 28, 280, [0, 70, 140, 210], 9)
            ]
        }
    ];

    var ALT_HIT = {
        arrow: 'THAT WAS A LIGHT HIT. THE REEVE SIGHS.', newbie: 'THE NEWBIE IS VERY SORRY. YOU ARE OUT.',
        ball: 'IT WAS A GOOD SHOT. YOU CALL IT.', cart: 'A LOANER CART HAS NO LOANER BRAKES.',
        fighters: 'THE SHIELD LINE SAYS EXCUSE US.', mud: 'THE MUD SIGNS YOU IN. FOR GOOD.', tent: 'THE TENT WAS ALSO NOT THE TABLE.'
    };
    var SFX_SPLASH = [[700, 40], [520, 40], [380, 40], [260, 40], [180, 100]], SFX_HIT = [[160, 40], [90, 140]];
    var SFX_LURK = [[200, 60], [150, 60], [110, 180]], SFX_TENT = [[300, 80], [220, 180]], SFX_TIME = [[440, 160], [440, 160], [330, 320]];
    var SFX_FANFARE = [[523, 70], [659, 70], [784, 70], [1047, 130], [784, 70], [1047, 220]];
    var SFX_TITLE = [[392, 90], [392, 90], [523, 90], [659, 120], [523, 90], [659, 220]];

    var TXT = '       ';
    var HOW_PAGES = [
        {
            head: 'HOW TO PLAY: THE BATTLEFIELD',
            rows: [
                '', '',
                'YOU ARRIVED LATE. THE BATTLEGAME HAS', 'STARTED. SIGN-IN IS ON THE FAR SIDE.', '',
                TXT + 'THAT IS YOU. ARROWS OR W A S D:', TXT + 'ONE HOP PER PRESS.', '',
                TXT + 'SHIELD LINE. NO BRAKES.', '',
                TXT + 'ARROW. FOAM TIP. IT STILL COUNTS', '',
                TXT + 'SPELLBALLS. YOU CALL IT.', '',
                TXT + 'THE NEWBIE IS VERY SORRY.', '',
                TXT + 'THE LOANER CART HAS NO BRAKES.', '',
                TXT + 'THE MIDDLE IS THE REEVE LINE.', TXT + 'IT IS SAFE. THE REEVE IS NOT.'
            ],
            art: [['ply', 16, 6], ['fgtR', 6, 9, -1], ['arwR', 12, 11, 1], ['ballAR', 15, 13, 0], ['newbAR', 16, 15, -1], ['cartAR', 9, 17, -1], ['reeveA', 16, 19]]
        },
        {
            head: 'HOW TO PLAY: THE FAR SIDE',
            rows: [
                '', '',
                TXT + 'MUD. RIDE ACROSS IT.', TXT + 'RIDE OFF THE EDGE: YOU ARE OUT.', '',
                TXT + 'STRETCHERS AND TARPS STAY UP.', '',
                TXT + 'SHIELDS DUCK. THEY BOB FIRST.', TXT + 'THE MUD DOES NOT CARE.', '',
                TXT + 'FILL ALL FIVE SIGN-IN TABLES.', '',
                TXT + 'SOMETHING LIVES UNDER SOME.', TXT + 'WATCH FOR THE EYES.', '',
                TXT + 'A BOTTLE ON A TABLE: +200.', '',
                TXT + 'A LOST FAVOR, CARRIED IN: +200.', '',
                'SPACE HOLDS THE GAME.  Q LEAVES.'
            ],
            art: [['rip', 16, 3], ['str28', 7, 6, -1], ['shd', 3, 8], ['shdW', 16, 8], ['rip', 29, 8], ['table', 12, 11, -1], ['lurkA', 12, 13], ['bottle', 19, 16, -1], ['favor', 17, 18]]
        }
    ];

    var WIN_TEXT = [
        'ALL FIVE TABLES SIGNED!',
        'THE REEVE STAMPS YOUR WAIVER,',
        'THEN READS THE TOP OF IT.',
        '',
        '"THIS IS THE WRONG PARK."',
        '',
        'YOUR PARK IS TWENTY MINUTES THAT WAY.',
        'THE FIGHTERS FOLLOW YOU OUT, CHEERING.',
        'THEY TOO WERE AT THE WRONG PARK.',
        ''
    ];

    function pad(n, w) { var s = String(n); while (s.length < w) { s = '0' + s; } return s; }
    function mod(a, n) { return ((a % n) + n) % n; }
    function bandY(b) { return Y0 + b * BH; }

    /* ----- the world: every lane is a pure function of time, so a bot can look ahead ----- */
    function makeWorld(level, rnd) {
        var lanes = LEVELS[level].lanes.map(function (l) {
            var c = {}, k, cyc, base, i;
            for (k in l) { if (l.hasOwnProperty(k)) { c[k] = l[k]; } }
            c.ph = rnd(l.period);
            c.dph = [];
            if (l.duck) {
                cyc = l.duck.up + l.duck.warn + l.duck.down;
                base = rnd(cyc);
                for (i = 0; i < l.offs.length; i++) { c.dph.push(base + Math.floor(i * cyc / l.offs.length)); }
            }
            return c;
        });
        var by = {};
        lanes.forEach(function (l) { by[l.band] = l; });
        return { level: level, lanes: lanes, by: by };
    }
    // Left edges of every copy of one object that is on screen (the second entry is the wrap-around).
    function copies(lane, i, t) {
        var x0 = mod(lane.offs[i] + lane.ph + lane.dir * lane.v * t / 1000, lane.period), out = [], x;
        for (x = x0 - lane.period; x < W; x += lane.period) { if (x + lane.w > 0) { out.push(x); } }
        return out;
    }
    function duckState(lane, i, t) {
        var d = lane.duck, m;
        if (!d) { return 'up'; }
        m = mod(t + lane.dph[i], d.up + d.warn + d.down);
        return m < d.up ? 'up' : (m < d.up + d.warn ? 'warn' : 'down');
    }
    // What is under the player at time t? { death: cause } or { ride: lane } or {}. m shrinks the margins (a careful bot's caution).
    function judge(world, p, t, m) {
        m = m || 0;
        var b = p.band, cx = p.x + 4.5, lane, i, xs, k, x, st, sink = false, a0, a1, ins, o0, o1;
        if (b >= R1 && b <= R5) {
            if (cx < 1 || cx > W - 1) { return { death: 'edge' }; }
            lane = world.by[b];
            for (i = 0; i < lane.offs.length; i++) {
                st = duckState(lane, i, t);
                xs = copies(lane, i, t);
                for (k = 0; k < xs.length; k++) {
                    x = xs[k];
                    if (cx > x - RIDE_SLOP + m && cx < x + lane.w + RIDE_SLOP - m) {
                        if (st === 'down') { sink = true; } else { return { ride: lane }; }
                    }
                }
            }
            return { death: sink ? 'sink' : 'mud' };
        }
        if (b >= RD1 && b <= RD5) {
            lane = world.by[b];
            ins = KINDS[lane.kind].ins;
            a0 = p.x + 1 - m; a1 = p.x + PW - 1 + m;
            for (i = 0; i < lane.offs.length; i++) {
                xs = copies(lane, i, t);
                for (k = 0; k < xs.length; k++) {
                    o0 = xs[k] + ins; o1 = xs[k] + lane.w - ins;
                    if (a1 > o0 && a0 < o1) { return { death: lane.kind }; }
                }
            }
        }
        return {};
    }
    // One tick: a rider is carried sideways, then the new moment is judged. Returns a cause of death or null.
    function advancePlayer(world, p, tNew, m) {
        var j;
        if (p.ride) { p.x += p.ride.dir * p.ride.v * TICK / 1000; }
        j = judge(world, p, tNew, m);
        p.ride = j.ride || null;
        return j.death || null;
    }
    function slotAt(p) {
        var cx = p.x + 4.5, i;
        for (i = 0; i < SLOTC.length; i++) { if (Math.abs(cx - SLOTC[i]) <= TOL) { return i; } }
        return -1;
    }
    // One hop. Returns {} , {death}, or {goal: slot index}.
    function hopPlayer(world, p, dir, t, m) {
        var b = p.band, j;
        if (dir === 'UP') { if (b === GOAL) { return {}; } p.band = b - 1; } else if (dir === 'DOWN') { if (b === STARTB) { return {}; } p.band = b + 1; } else if (dir === 'LEFT') { p.x = Math.max(0, p.x - HOP); } else { p.x = Math.min(W - PW, p.x + HOP); }
        if (p.band === GOAL) {
            p.ride = null;
            j = slotAt(p);
            return j < 0 ? { death: 'tent' } : { goal: j };
        }
        j = judge(world, p, t, m || 0);
        p.ride = j.ride || null;
        return j.death ? { death: j.death } : {};
    }

    /* ----- pixel art ----- */
    /* FROGGORK art, generated by froggork-src/build.js. Paste inside the IIFE in place of the "pixel art" section and defineSprites.
       Hand-placed at 280x192, 1-bit. Tone is only pattern: stripes, dot screens and ordered dither. */
    var ART = (function () {
        var AL = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        var SPR = {
            ply: '.o##o##o./.o#o#o#o./ooo###ooo/#o#####o#/##o###o##/oo#o#o#oo/oo#####oo/#o#####o#/###ooo###/#ooo.ooo#',
            plyH: '.o##o##o./.o#o#o#o./ooo###ooo/o#o###o#o/o#######o/oo#o#o#oo/.o#####o./oo#####oo/o##ooo##o/##oo.oo##',
            plyS1: '.o##o##o./.o#o#o#o./ooo###ooo/#o#####o#/##o###o##/oo#o#o#oo',
            plyS2: '.o##o##o./.o#o#o#o./.oo###oo.',
            plyD: 'ooooo...oooo/o#o#ooooo##o/oo#oo#####o#/o#o#########/oo#########o',
            life: 'o##oo##o/o#o##o#o/oo####oo/#o####o#/##o##o##/oo####oo/#o####o#/##oooo##',
            newbAR: 'oo####oo./o######o./o#o#o#ooo/oo###ooo#/o#####o#o/#o###o#oo/oo###ooo./oo###oo../o#ooo#oo./##o.o##o.',
            newbBR: 'oo####oo./o######oo/o#o#o#oo#/oo###oo#o/o#####o#o/o####o#oo/oo###ooo./.o###o.../oo#o#oo../o##o##o..',
            fgtR: '.oo###ooooooo....oo###ooooooo./.o#####o####o....o#####o####o./.o##o##o#oo#oo...o##o##o#oo#oo/.oo###oo#o##o#...oo###oo#o##o#/oo#####o##o#o#..oo#####o##o#o#/o#o####o#oo#o#..o#o####o#oo#o#/#oo####o####oo..#oo####o####oo/ooo####oo##oo...ooo####oo##oo./.oo##o##oooo.....oo##o##oooo../.o##ooo##o.......o##ooo##o....',
            cartAR: '.o#ooooo.o#o.oo#oooo.oo#/.oo#oo#o.o#ooo#ooo#ooo#o/..oo#o#o.o#oo#ooo#oo##oo/ooooooooooooooooooooooo#/o######################o/#o#o#o#o#o#o#o#o#o#o#o#o/o######################o/ooo###oooooooooo###ooooo/.o#o#o#o......o#o#o#o.../.oo###oo......oo###oo...',
            cartBR: '.o#ooooo.o#o.oo#ooooooo#/.oo#oo#o.o#ooo#ooo#oo#oo/..oo#o#o.o#oo#ooo#oo##oo/ooooooooooooooooooooooo#/o######################o/#o#o#o#o#o#o#o#o#o#o#o#o/o######################o/ooo#o#oooooooooo#o#ooooo/.o#####o......o#####o.../.oo#o#oo......oo#o#oo...',
            ballAR: 'oooo.oo###o/#o#ooo#o#o#/o#o#o#o#o##/#o#o#o#o###/ooo#o##o###/#ooooo#####/oo...oo###o',
            ballBR: 'ooo.ooo###o/o#ooo##o#o#/#o#o#oo#o##/o#o#o##o###/#oo#oo#o###/o#oooo#####/ooo..oo###o',
            arwR: '#o#oo.......oo###o/o#o#ooooooooo#####/################o#/o#o#ooooooooo#####/#o#oo.......oo###o',
            shd: '###########/#ooooooooo#/#o#######o#/#o###o###o#/#o#ooooo#o#/#o###o###o#/##o##o##o##/o##o#o#o##o/oo##ooo##oo/.ooo###ooo.',
            shdW: '.........../.........../.........../ooooooooooo/###########/#ooooooooo#/#o#######o#/#o###o###o#/#o#ooooo#o#/#o###o###o#',
            beaR: '..ooooooo../.oo#####oo./oo#o#o#o#oo/o#o#o#o#o##/o#########o/#o#o#o#o#o#/###########/o#o#####o#o/o##ooooo##o/oooo...oooo',
            beaWR: '.........../.........../.........../.........../..ooooooo../.oo#####oo./oo#o#o#o#oo/o#o#o#o#o##/o#########o/#o#o#o#o#o#',
            rip: '............/...oooooo.../.ooo####ooo./oo##oooo##oo/o#oo####oo#o/o#oo####oo#o/oo##oooo##oo/.ooo####ooo./...oooooo.../............',
            lurkA: '..oo##oo..oo##oo../.oo####oooo####oo./.o##oo##oo##oo##o./oo#o##o####o##o#oo/o##o#o#o##o#o#o##o/o###o#o####o#o###o/oo##############oo/.o#o#o#o##o#o#o#o./.oo#o#o#oo#o#o#oo./..oo#o#oooo#o#oo..',
            lurkB: '..oo##oo..oo##oo../.oo####oooo####oo./.o##oo##oo##oo##o./oo######oo######o./o##############oo./o##############oo./oo##############o./.o#o#o#o##o#o#o#o./.oo#o#o#oo#o#o#oo./..oo#o#oooo#o#oo..',
            peek: '.oo###oooo###oo.../.o#o#o#oo#o#o#o.../.o##o##oo##o##o.../.oo###oooo###oo...',
            table: '.....oo####oooo.../.....o#oooo#o#o.../.....o#o##o##oo.../oooooo#oooo#oooooo/##################/#o#o#o#o#o#o#o#o#o/o#o#o#o#o#o#o#o#o#/o##oooooooooooo##o/o##o..........o##o/o##o..........o##o',
            bottle: '.o##o./oo##oo/o#oo#o/o####o/#oooo#/#o##o#/#o##o#/#oooo#/#oooo#/o####o',
            favor: '##oooo##/#o#oo#o#/#oo##oo#/#oo##oo#/#o####o#/##o##o##/oo#oo#oo/o#oooo#o',
            splat: '#oo.o#o.oo#/o#ooo#ooo#o/oo#o###o#oo/ooo#####ooo/#o#######o#/o#########o/ooo#####ooo/oo#o###o#oo/o#ooo#ooo#o/#oo.o#o.oo#',
            reeveA: '.oo###oo./.o#####o./.o#o#o#o./ooo###ooo/o#######o/#o#o#o#o#/oo#o#o#oo/.o#####o./oo#ooo#oo/o##o.o##o',
            reeveB: '.oo###oo#/.o#####o#/.o#o#o#o#/ooo###o##/o#######o/#o#o#o#oo/oo#o#o#o./.o#####o./.oo#o#oo./.o##o##o.',
            str28: 'oooooooooooooooooooooooooooo/############################/###o###o##ooo##ooo#o###o####/#oo#o#o#o#ooo##oooo#o#o#ooo#/oooo#o#o#oo######o#o#o#o#ooo/ooo#o#o#o#o######oo#o#o#oooo/#ooo#o#o#oooo##ooo#o#o#o#oo#/###o###o##ooo##ooo#o###o####/############################/oooooooooooooooooooooooooooo',
            str30: 'oooooooooooooooooooooooooooooo/##############################/###o###o###ooo##oooo###o######/#oo#o#o#o#oooo##ooo#o#o#o#ooo#/oooo#o#o#o#o######oo#o#o#o#ooo/ooo#o#o#o#oo######o#o#o#o#oooo/#ooo#o#o#o#ooo##oooo#o#o#o#oo#/###o###o###ooo##oooo###o######/##############################/oooooooooooooooooooooooooooooo',
            str32: 'oooooooooooooooooooooooooooooooo/################################/###o###o###oooo##ooo###o###o####/#oo#o#o#o#o#ooo##oooo#o#o#o#ooo#/oooo#o#o#o#oo######o#o#o#o#o#ooo/ooo#o#o#o#o#o######oo#o#o#o#oooo/#ooo#o#o#o#oooo##ooo#o#o#o#o#oo#/###o###o###oooo##ooo###o###o####/################################/oooooooooooooooooooooooooooooooo',
            str34: 'oooooooooooooooooooooooooooooooooo/##################################/###o###o###o#ooo##ooo##o###o######/#oo#o#o#o#o#oooo##ooo#o#o#o#o#ooo#/oooo#o#o#o#o#o######oo#o#o#o#o#ooo/ooo#o#o#o#o#oo######o#o#o#o#o#oooo/#ooo#o#o#o#o#ooo##oooo#o#o#o#o#oo#/###o###o###o#ooo##ooo##o###o######/##################################/oooooooooooooooooooooooooooooooooo',
            tp42a: '...########o##o##########o##o##########.../.#oooooooooo##oooooooooooo##oooooooooooo#./#ooooooooooo##oooooooooooo##ooooooooooooo#/#ooooooooooo##oooooooooooo##ooooooooooooo#/#####oooo##o##oooo#####ooo##o###oooo######/###oooo####o##oo#####ooooo##o#oooo#####oo#/#ooooooooooo##oooooooooooo##ooooooooooooo#/#ooooooooooo##oooooooooooo##ooooooooooooo#/.#oooooooooo##oooooooooooo##oooooooooooo#./...########o##o##########o##o##########...',
            tp42b: '...########o##o##########o##o##########.../.#oooooooooo##oooooooooooo##oooooooooooo#./#ooooooooooo##oooooooooooo##ooooooooooooo#/#ooooooooooo##oooooooooooo##ooooooooooooo#/##oooo#####o##o#####oooo#o##ooooo#####ooo#/######oooo#o##ooooo#####oo##o####oooo#####/#ooooooooooo##oooooooooooo##ooooooooooooo#/#ooooooooooo##oooooooooooo##ooooooooooooo#/.#oooooooooo##oooooooooooo##oooooooooooo#./...########o##o##########o##o##########...',
            tp56a: '...########o##o##########o##o##########o##o##########.../.#oooooooooo##oooooooooooo##oooooooooooo##oooooooooooo#./#ooooooooooo##oooooooooooo##oooooooooooo##ooooooooooooo#/#ooooooooooo##oooooooooooo##oooooooooooo##ooooooooooooo#/#####oooo##o##oooo#####ooo##o###oooo###o##ooo#####oooo##/###oooo####o##oo#####ooooo##o#oooo#####o##o#####oooo####/#ooooooooooo##oooooooooooo##oooooooooooo##ooooooooooooo#/#ooooooooooo##oooooooooooo##oooooooooooo##ooooooooooooo#/.#oooooooooo##oooooooooooo##oooooooooooo##oooooooooooo#./...########o##o##########o##o##########o##o##########...',
            tp56b: '...########o##o##########o##o##########o##o##########.../.#oooooooooo##oooooooooooo##oooooooooooo##oooooooooooo#./#ooooooooooo##oooooooooooo##oooooooooooo##ooooooooooooo#/#ooooooooooo##oooooooooooo##oooooooooooo##ooooooooooooo#/##oooo#####o##o#####oooo#o##ooooo#####oo##o####oooo#####/######oooo#o##ooooo#####oo##o####oooo##o##oooo#####oooo#/#ooooooooooo##oooooooooooo##oooooooooooo##ooooooooooooo#/#ooooooooooo##oooooooooooo##oooooooooooo##ooooooooooooo#/.#oooooooooo##oooooooooooo##oooooooooooo##oooooooooooo#./...########o##o##########o##o##########o##o##########...',
            layon: '#####################################################/#ooooooooooooooooooooooooooooooooooooooooooooooooooo#/#ooooooooooooooooooooooooooooooooooooooooooooooooooo#/#ooooo#oooooo###oo#ooo#oooooooo###oo#ooo#ooo#ooooooo#/#ooooo#ooooo#ooo#o#ooo#ooooooo#ooo#o#ooo#ooo#ooooooo#/#ooooo#ooooo#ooo#oo#o#oooooooo#ooo#o##oo#ooo#ooooooo#/#ooooo#ooooo#####ooo#ooooooooo#ooo#o#o#o#ooo#ooooooo#/#ooooo#ooooo#ooo#ooo#ooooooooo#ooo#o#oo##ooo#ooooooo#/#ooooo#ooooo#ooo#ooo#ooooooooo#ooo#o#ooo#ooooooooooo#/#ooooo#####o#ooo#ooo#oooooooooo###oo#ooo#ooo#ooooooo#/#ooooooooooooooooooooooooooooooooooooooooooooooooooo#/#ooooooooooooooooooooooooooooooooooooooooooooooooooo#/#####################################################',
            holdb: '#########################################/#ooooooooooooooooooooooooooooooooooooooo#/#ooooooooooooooooooooooooooooooooooooooo#/#ooooo#ooo#oo###oo#ooooo####oooo#ooooooo#/#ooooo#ooo#o#ooo#o#ooooo#ooo#ooo#ooooooo#/#ooooo#ooo#o#ooo#o#ooooo#ooo#ooo#ooooooo#/#ooooo#####o#ooo#o#ooooo#ooo#ooo#ooooooo#/#ooooo#ooo#o#ooo#o#ooooo#ooo#ooo#ooooooo#/#ooooo#ooo#o#ooo#o#ooooo#ooo#ooooooooooo#/#ooooo#ooo#oo###oo#####o####oooo#ooooooo#/#ooooooooooooooooooooooooooooooooooooooo#/#ooooooooooooooooooooooooooooooooooooooo#/#########################################',
            outb: '###################################################################################/#ooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooo#/#ooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooo#/#oooooo###oo#ooo#o#####oooooooo###oo#####ooooooo#oooooo###oo#ooo#o#####oo####ooooo#/#ooooo#ooo#o#ooo#ooo#ooooooooo#ooo#o#ooooooooooo#ooooooo#ooo#ooo#o#ooooo#ooooooooo#/#ooooo#ooo#o#ooo#ooo#ooooooooo#ooo#o#ooooooooooo#ooooooo#ooo#ooo#o#ooooo#ooooooooo#/#ooooo#ooo#o#ooo#ooo#ooooooooo#ooo#o####oooooooo#ooooooo#ooo#ooo#o####ooo###oooooo#/#ooooo#ooo#o#ooo#ooo#ooooooooo#ooo#o#ooooooooooo#ooooooo#ooo#ooo#o#ooooooooo#ooooo#/#ooooo#ooo#o#ooo#ooo#ooooooooo#ooo#o#ooooooooooo#ooooooo#oooo#o#oo#ooooooooo#ooooo#/#oooooo###ooo###oooo#oooooooooo###oo#ooooooooooo#####oo###oooo#ooo#####o####oooooo#/#ooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooo#/#ooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooo#/###################################################################################',
            stamp: '.o#ooo#ooo#ooo#o../.oo#oo#oo#oo#ooo../oooo#o#o#o#o#o#ooo/#oooo#####ooooooo#/o#oo#ooooo#oooo#oo/#####o###o######o./o#oo#ooooo#oooo#oo/#oooo#####ooooooo#/oooo#o#o#o#o#o#ooo/..o#oo#oo#oo#ooo..',
            ttlBlink: 'oooooooooooooooooooooo/ooooooo######ooooooooo/ooooo##########ooooooo/ooo##############ooooo/oo################oooo/oo################oooo/o##################ooo/o###################oo/####################oo/####################oo/####################oo/####################oo/#ooooooooooooooooooooo/####################oo/o###################oo/o###################oo/oo#################ooo/oo################oooo/ooo###############oo##/ooooo###########oooo##/#ooooo#########oooo###/###oooooooooooooo#####',
            ttlFlag: 'oooooooooooo/####oooooooo/####ooooooo#/###########o/############/###########o/########ooo#/########oooo/ooooooooooo#'
        };
        var IMG = {
            ttl: [280, 168, 'o', 'A~tf_~r4QA~rIQA~XMAIAYA~NIQA~XEAIAQA~NIQA~PHgA~EEAIAQA~JD_AAIQA~EQA~IPgA~EEAIAQA~GH__8A4AIQA~EcAAAWA~ENwA~CEACAIAgAQA~EYA~CGAIQA~E0AAAV8AAACAe4A~CCACAAAgAgA~EgA~CBAIQA~E2AAAUzwAAOAO4A~CCA~FgA~EgA~DgIQAAQAAA2AAAXDPgAdAPcA~CBA~EBA~Ed8i8g-AQIQAAcAAA6AAAUMMeAbAHcAAEAAgAAPwACAAQAAAmikiggAIISIi0iIiaiIiUwwx86InuiIiIioAA_8AKiIiIiI6ioiggAEoQAA2AAAaAAAXDDDO0ADuAABA~CD__AAABAAADi8w8g8AEIQAA2AAAaAAAEMMNw0AB3AAAgAACH__gAACAAAMioogggAEIQAAaAAAbAAAIww-DoAB7gAAQAAKH__gAAEAAAIikkgggACISIiaCIibCIiLDDQHaIg7iIiIgAqP-HwACIiIiQciig--ACoQAAbAAAdAAAMMMgHQAA9wAAEACqP4Bx4AQAAAQA~FCIQAAbAAANAAAoww4OgAAdwAACAKqfwM5-AgAAAgA~FCIQAAdAAANAAArDDGOgAAe4A~Cqqfwe5-A~DiL7x5xyICISIiNCIiNiIisMMNlCIiO4IiIAqqfg9Z_AIiIiiKCKAiKICoQAANAAANgAAowwwxAAAHcAAACqqfg9Z_A~DiKCKAiLICIQAANgAAOgAArDDDMAAAHsAAACqqfg_Z_AAAgAiLzxwiKoCIQAAOgAAGgAAv_~CAAADuBAAKqqfg_Z_AABcAiKCgIiKYCISIiGiIiGiIigA~CCIiL3IyAKqoPwex-IIlDghSCQIiKICoQAAGgAAGgAAwAADoAAAB3AIAqqoPwMx-oAJAcAj6LxxyIEIQAAGwAAGwAAwAAHQAAAB7gGAqqoH4Bh4KAyADA~GIIQAAGwAAGwAAwAAHQA~C7gAAqqgH-HgACACAA4AA_4AAAwISIiHSIiHSIAAiIugiIiIdwiCqqhj__H-AAiAAHAPAHwAPAoQAADQAADQA_wAANgA~CdwACqqjg_8H_ggEMAA5wAAP_wAIQiIjQiIjQHquIuBCIiIiO4ICqqjwPwP_4AEDgAECIiAAAiIQAADYAADYaqrgN8A~DPcAKqqn8AA__8AIAYAIA~FISIiLqD_DYqqqyTD4CIiIncgKqqH_AD___AoYHAKIiIiIiIoQAABodV4pq_6oQw3wAAAHuAKqqH_~EgIHA4IA~FIQiIhpqquCrqusfMMMiIiDuJqqqP_~E4QAwGQiIiIiIiIQAABjVVVGuqrqX_DEAAAD3HqqqP_~E8Q8OAQA~FISIiJmr-q~EUx-0IiIh3fqqqP5_~CvmT_BwSIiIiIiIoQAAANfV1K6qq6sMN8A~C78qqqP4_~Cv-n3gMgA~FIQiIia6qqaq~CrDDUiIiI_wKqqP8P___X-e74AoiIiIiIiIQAAA11VWbqqqu4ww4A~CfwCqqP-H___X_Pd2BA~GISIiIq~CrqGKusMMaIiIi_6AqqP_g__-3_vuwBCIiIiIiIoQAABXVVUqqGKorDDoAAAD_9gqqP_wD_-34P3QBA~GIQiIhuq~DGKp4ww4iIiPv_4KqP_-AH94AP3oCIiIiIiIiIQAADVVVUqq_6pMMMoAAAOH_8CqH__4ABwDH7nCA~GISIiKq~E_6pLDDqIiIiH__AqH___wAB-H9g6iIiIiIiIoQAADdVVUqqGKpwww4A~CH__gKD_~E8P_AEAAA4A~CIQiIm6q-qqqGKpcMMoiIiJ___4AB_~Ewf-IgIiI_IiIiIQAAFVV_UqqGKrTADIA~CB__-AQVf_~CA_-A~D_4AAAISqqm6rPqbq~CB7Byq~CoP__h4Kqq__4D_-Kq~C_6qqqoQAAFVXPVbqqqAfy8A~EH__j-BVVf8AP_8A~D_A~CIQiIm6r_qKqqAfpyLwCIiIiD__H_AH_AAA__8IiIiI6IiIiIQAAFVX_VK68fiJyAPA~DB_-H_4A~CB__4A~DgA~CISqqm6r-qmgdoiJyAg8Cqqqg_-P__gAIoH__wq~IoQAAFVV9VAP8IiJxAADwA~Cf8f__8AIgf__wA~DgA~CIQiIiqqqgP48IiJxICAsiIiIP8fwAAAEB__viIiIiIiIiIiIQAADdVAPyI8IiJxAAAEA~CD8gAAAHgH__fA~DBgA~CISqqqunP4iI8IiJ7AgIEqqroB4AAB_4A__--Cq~Ciyq~CoQAABAPSIiI8IiP5AAAEAAeAAYAf-CgH__98MA~CGoA~CIQiIgP_CIiI8In47oCAkiA~CBfgqqAf__74AAiIiKsIiIiIQAAHyPCIiI-L6I5fgAEAMAAKBCqqoD___XwAYAAAKsA~CISuH4iPCIiIu8gA5A_oEqqqoKhKqAAP__-vgq~DaqKqqqoQO6IiPCIiL-ICI5AAfkA~CCjKo_B___9fA~EqrA~CIQ-iIiPiIj8uAiI5ICA-gD__ACKh_H___6-AiIiIhqqoiIiIQvCIiLiJ-IOIiI4gAACD8AA_iqn_P___14A~DBqqgAAAISvCIiLj-iAuIiI9ggIC8gICAyqnxP__-rwAAD__yqqz_gAIQvCIiLuIAIuIiL8gAACA~DCqvxP__1XAAP8AAGqqoAf_oSfCIi8AAiIuIj88ICAiICAgIAqPxP_-qsAP4AAgKqqsIAAoQfCI_AAAiIuJ-IcgAACA~EqcAP_9VYHwADwAaqqqADwoQfC_gf_AiIveiA_8gICAgICAgqcAP_qqg4APGIAaqqqPGIIRffIn__8CI_IAI8j_8CA~EqcAP9VUAA8YqM8qqqrIqMoWfiAb___C_nAiI8oCD-ICAgIAqfxH__4CBioyLhq~CgyLoUbgIn_A_hInIiI8gAACA~EqfxH__gA-jIiIjgAABwiIoUTiL_jAH4AHIiI9ggICAgICAiqfxj_8AhIiIiIgP__8AiIoULiM-DAP8InIiJ8QAACA~DCqPxh_gABIiIiIiIiIkIiIoWLiL8DAM-InIj--ICAiICAgICqvxwAAICIiIiIiIiIkIiIoULiTwDAMPInI-IOQAACA~DCqnx4AHACIiIiIiIiIkIiIoULivgDgcHgnfiAuQAACAgICAiqn_6qvAiIiIiIiIiIkIiIoULznwBgYDg_ogIuT_gCA~DCqh_iqmACIiIiIiIiIkIiIoWL3v4Bg4DzjgCIv__4_ICAgICqo_KqmICIiIiIiIiIkIiIoUNzOcBgwBwjoiIuT_8BA~DCqqAKqmACIiIiL-IiIkIiIoUFy8OBgwB4DoiIuvw-DAgICAiq~DmAhIiIqL-IgIkIqIoUBycHBxwH4joiI_Pw_BA~DCq~DmABIio2P-ogIko2MoWF24Dwxgfcjoifvuw3BICAgICqqgAAkIA-jYjz_IgIlYjyoUF04B4zg8cjo_oucxzhA~DCqoAAAEAABiPA__4gIkPABoUF-4AczDwcjviAO85jyAAICByqgD_4AAgI8AA__4gAkAAIoX054AO_PgeHogIk4ZhwA~CHSqA___gA~EIAf4H8AAAIWE7wAH_eAObwCIm4ZjAfgAgaiqH___4ACAgICIgQAAAICAoUD5wAD_4AOBwiIk4fkD1cAA1SqP___-B_~LwoUK7__DnwAODwiI78fwOqrADqiqP_~CgAICAgICQgICAgPoUC5___DAAODwiPp__g1VV4NVCiP_~C4BA~CIAQA~CCIoWC7wB_D_AODwnwL34Dqqqv6qqCP_~C-DAgICIgYCBgIDQoUC5wADn__-Dz4AJwwFV~EKQH_~DCA~GRAABQoUK7wAH_B_-D0AILwwaq~FoH_~DgICAgICAkaCAgAoUC5wAP_gAOfwAAJwg1V~EIUD_-r__wA~EQCqA~CoWB74A9_wAeB4CArwBq~FoqB_~D4AgICEkYCggICAoUB84D5m4AcA~CJwHV~FBVh_-qqv8AAABUCgA~EoUJ-4HhmcAcq~Cu6Kq~GrA_~D-ACAk4CogICAgKoQB84eDmPAcAAIBE8V~HUAf_qqr_AAACoCIA~EISpe98DGHg4q~Cu8av__-q~C4IP_~DggIHgr6q~EoQB-fwHHBw4ABQAs8_wAAB9VVXAKH_qqq_gABUABQA~EISpvvAGDA5wqr6qq8A~DD6q8AQD_~D4Kq~CCq~EoQAvPAGDAfwARABRMADA~CP_gAKB_6qq_8AAqA~HISquvAODAPiqqqr7vYfKqqgAAACqgf_~C-AKgq~HoQA-ngMDAHgAKAgAn4-AAAEA~FB6qv_A~FQA~CISqO7wcDgPKqoqqq7_8Kq~CvoKqqAAH___-AACqqu6q~CoQIgJ8YBg-QAAFAAd_4AAACgFA~EB_r___8AAACgA~CISvqu-YBh8Krrvqqr_gq~CKoKq~Dg_~EgKq~FoQFADf4Bn4gRAoAAKA~Nf_~D4A~GISoKqv8B_gKq~CrogKq~GrqqqoP___Dw-Kq~EuoQAAK7___AAKAAARA~JRAAIgH___n5_A~EBEISqqv3__8Kqoq~QvqA_~D-Kq~FoQAAFQf_A~FKA~JKAAFAAAP__8A~GoISqqoCAACq~Eoq~JoqqoKoA~EKq~EioQA~rIf_~r4A~_A~_A~_A~_A~_A~_A~gH_~Cw_~CAD_~Cwf___-D_~Cwf___-H___4A_8Af_AAAH_~C4_~C4H_~C4_~DH_~C4_~DH_~CA_-A_-gAAH_~C4_~C8H_~C4_~Dn_~C4_~Dn_~Cg_-B_-gAAH_~C6_~C-H_~C6_~Dn_~C6_~Dn_~Cw_-j_6oAAH_~C6_~DH_~C6_~Dv_~C6_~Dv_~C4_-j_6oAAH_~C6_~Dn_~C6_~Dv_~C6_~Dv_~C4_-n_6oAAH_~C6_~Dn_~C6_~Dv_~C6_~Dv_~C-_-v_qgAAH_~C6_~Dv_~C6_~Dv_~C6_~Dv_~C-_-v_qgAAH_~C6_-qv_v_~C6_~Dv_~C6_~Dv_qq_-_-__qgAAH_q~C_-qv_v_r7_6_-qqqv_q~C_8AP_v_qq_-_-_-qAAAH_q~C_-qv_v_v7_6_-qqqv_q~C_8EP_v_qq_-___-qAAAH_q~C_-qv_v_v__6_-qqqv_q~C_8EP_v_qq_-___6oAAAH_q~C_-gH_v_vP_6_-qqqv_q~C_8EP_v_qA_-___6oAAAH_qAAA_-gH_v_uF_6_-gAAH_qAAA_8EP_v_qA_-___6oAAAH___wA_~Dv_8D_6_-gAAH_qAAA_8eP_v_~C6___qgAAAH___4A_~Dv_8T_6_-gAAH_qAAA_8_P_v_~C6___qgAAAEAAAYAgAAACsA8TAKgGgAAEAqAAAgE_IBsA~CagABqgAAAH___6A_~C-v_8D_6_-j__H_qf_4_9___v_~Cq___qA~CH___6A_~C-v_8D_6_-j__n_qf_4_8_P_v_~Cq___6A~CH___6A_~C6v_8D_6_-j__n_qf_6_8eP_v___-q___4A~CH___6A_~Cqv_uF_6_-j__v_qf_6_8EP_v___6q___4A~CEAf_6AgGwCqkAvNAKgGiABsAqQAKgEEIBsAqAaqgAAGA~CH_qqqA_-v-qn_v9_6_-j__v_qf_6_8EP_v_r_6q___-A~CH_qqqA_-v_qH_v5_6_-j__v_qf_6_8EP_v_r_6o___-gAAAH_qqqA_-v_oH_rx_6_-gv_v_qL_6_8EP_v_q_-g_-__gAAAH_qqqA_-n_oH_~C6_~Dv_~C6_8AP_v_q_-A_-__gAAAEAqAAAgGiAYEA~CKgAAABsA~CKgAAABsAqQCAgGwAYAAAEAqAAAgGhAIEA~CKgAAABsA~CKgAAABsAqIBggGoAYAAAH_qAAA_-h_6H_~C6_~Dv_~C6_~Dv_qP_g_-n_4AAAH_qAAA_-g_-H_~C6_~Dv_~C6_~Dv_qH_g_-j_-AAAH_qAAA_-g_-H_~C6_~Dv_~C6_~Dv_qH_4_-j_-AAAEAqAAAgGgQCkA~CKgAAABsA~CKgAAABsAqCAYgGhACAAAEAqAAAgGgQBkA~CKgAAABsA~CKgAAABsAqCAIgGggBgAAH_qAAA_-gP_j_~C6_~C-r_~C6_~C-v_qB_-_-gf_gAACqqAAAKqgCqoq~Ioq~LAqqKqgKqgAAAqqAAAKqgCqoq~Diq~Coq~Diq~CoqqAKqKqgCqoAAAqqAAACqgAqoKq~Ciq~CoKq~Ciq~CoqqAKqiqgCqoAAAKqAAACqgAqqKq~CAq~CoKq~CAq~CoKqACqiqgAqqA~_A~_A~_A~_A~V'],
            card0: [280, 80, 'o', '_~s8gA~rEgA~rEgA~rEgA~rEgA~rEgA~rEgA~PD_~F8A~REiIiIiIiIiIiIiIiIiIiq~FuIiIiIiIiIiIiIiIiIiIkgA~PCq~FsA~REgA~PCq~FsA~REgA~PCq~FsA~REiIiIiIiIiIiIiIiIiIiq~FuIiIiIiIiIiIiIiIiIiIkgA~PD_~F8A~REoiIiIiIiIiIiIiIiIiIf_~EgiIiIiIiIiIiIiIiIiIiEgA~Qeq_~DgA~REiIiIiIiIiIiIiIiIiIg_~FiIiIiIiIiIiIiIiIiIiIkgA~Q-q_~DgA~REoiIiIiIiIiIiIiIiIiI_~FwiIiIiIiIiIiIiIiIiIiEgA~Q6q_~DwA~REiIiIiIiIiIiIiIiIiIg_~FyIiIiIiIiIiIiIiIiIiIkgA~Q6q_~DwA~REoiIiIiIiIiIiIiIiIiJ___wH__wiIiIiIiIiIiIiIiIiIiEgA~PB6q_wAP_wA~REq~Qp_~CwAf4q~RkgA~PB6r___gP4A~REoiIiIiIiIiIiIiIiIiB_~EP4iIiIiIiIiIiIiIiIiIiEgA~PD6r_~D4A~REq~Qj_~F4q~RkgA~PD6r_wH__4A~REoiIiIiIiIiIiIiIiPCD___wAP_4iIiIiIiIiIiIiIiIiIiEgA~NfgH6r__wAf8A~EcABwA~HEqqqpwCnAKq~FVCH_~DgP8oAH__--977wADwq~DkgAAD7wPvA~GOIPqr_~CP8B_4AAA2kDaT_34A~DEqqqraQNpP___gCqqfQf_~F8-CAgICctJy0gFR_8AKqkgAABy3nLQAAAf4AAug_qr_~D8A~E-1D7UADiAD_gAEqgHz7UPtQICAgP8AOI_~CwH__8AgICAhelF6UCH0ICA_wEgP4F6UXpQA~DD8OB_qr_wAP_-A~DCe8J7wALoA~CPEnwgJ7wnvCAgICAgLRB_~DwAf-ICAgIAeYB5ggDiAgICA0oAAB5gHmA~Gxj_qr___gP-A~EbABsAADgA~DEgICBsIGwgICAgICAgD_~FP-AgICAgxiDGICEQICAgIEgAADGAMYA~HH_qr_~EA~KMYA~DEiAgICAgICAgICAgICH_~HACAgICAgICAgICAgICAkgA~OP_qr_wH___gA~QEgICAgICAgICAgICAgf_~CwAP__4AICAgICAgICAgICAgIEgA~Of_qr__wAf_-A~QEiAgICAgICAgICAgIA_~FgP__gAgICAgICAgICAgICAkgA~NB__qr_~CP__4A~PEgICAgICAgICAgICAj_~KACAgICAgICAgICAgIEgA___A~JD_-qr_~GwAAA_~H-AAAEv_qqq_~DgICAgIH_~K-Af_q~Hr__AklV~Hf_gAAAP_-qr_~HgV~M_kq~K_8CAP_~L4Cq~M0lV~KX_wf_-qr_~H-BV~MUq~No_~Ngq~MklV~MQ__-qr_~IwFV~LUq~GoCoCqqqp_~N8Cq~LklV~FDxDxVVVR__-qr_~JBV~LUq~Gv8v8qqqp_~OwKq~KklV~FPMPMVVVR__-_~L4FV~KUq~GeGeGqqqp_~O-Cq~KklV~FeGeGVVVR_~PFV~KUq~GeGeGqqqvq~Pjq~KkkA~FeGeGAAAB_V~EX__1V~EdV~EUA~DEq~GPMPMqqqor-q~Cr-qq_q~Dviq~KkgA~FP8P8A~CVX_VVf9V~C_V~C9VVVQA~HEq~Gjwjwq~F__6q~E_qq-qiq~KkgA~EBV~CQAAAV~L__VVQAAf___A~EEq~T_~E-q~F_wAAB_q~DkgAAAP__hV~CQAAAVVU__A~EB__VVVQBwA~CBwA~CEqqqnwAAfq~Hr_A~IB_q~CA~EKq~CkgAAYA~CwA~FVeA~L9VQBgA~DwA~CEqqqgA~CKq~GoA~LKqqq_wAAB_q~DkgAAQA~CQA~FVeA~KB9VQAAf___A~EEqqqvwAAfq~Hr_A~IB_q~NkgAAAP__gA~GVVV__A~EB__VVVQA~KEq~T_~E-q~QkgA~NV~PQA~KEgA~rE_~s8'],
            card1: [280, 80, 'o', '_~s8gA~rEgA~rEgA~rEiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIv_~F-IiIiIiIkgA~bIA~FEA~FEgA~bIA~FEA~FEgA~bIccii-eAEA~FEiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIoi~CIgAGIiIiIiIkgA~bIgiiyIgAEA~FEgA~bIgiiqIcAEA~FEgA~bIgiimICAEA~FEiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIoi~CICAGIiIiIiIkgA~bIccciI8AEA~FEoiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIA~FEiIiIiIiEgA~bIA~FEA~FEiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIv_~F-IiIiIiIkgA~TeA~JMA~JEoiIiIiIiIieiIiLyIiIiIjjiIiIiIiIiIiMiIiIieiIiLyEgADwAAAeABjgAAOMA~DCiQA~CeAAADwMAAeABjgAAOMEqquOqqqpqqiaqqyK-qqqr8iKq-qrjr6quOuqqpqqiaqgSKUoASKeABIj0iIAeSLjjwAOMiIApACiUoASKeABIj0iIAfyLkk-SLjj6I-MiKIpiKiUoiSIiOJIr0iIk-SLvj6I-MiP_piKkkpiKiUqIyIiMBIiMiIkeSIiPyI-MiIkpiKuUqIyIiMBIiMknIiMiImIyIiP6JiMiImpiYiMqIyIiInIiMuImIyIiPyJiMkiJiMiImIiYiMqJiIiIlIiYiImIyIiYiJiMuImIiYiMqJiIkiJiIiImIiYiImJiIiYiJiYiImIiYiYiJiIuImJyYiImJiIkmJiIiYmJiYiImJiYiYiJiYiImIiYiYmJiIuYmJyYiImJiYkmJiYiYmJiYiImJiYiYmJiYiYmJiYiYmJiYuYmJyYiImJiYkmJiYiYmJiYiYmJiYiYmJiYiYmJiYiYmJiYuYmJyYiYmJiYkmJiYiYmJiYiYmJiYiYmJiYiYmJiYiYmJiYuYmJyZyYmJickmJiYiYmJiYiYmJiYiYmJiYiYmJiYiImJiYuYmJyZyYmJickmJiYiYiJiYiYmIiYiImJiIiYmJiYiImJiYuYiJiZyYmIickmIiYiImJiIiYmIiYiImJiIiYiJiYiImIiYuImJiJyYmIickuIiYiIlJiIiYiIyY_A~KPuA_DuInJiJyYiPyckiIyYiIkpiMiImIyP_ABAEA~CeAAAP-A_DuIkpiNyImPyckiIyIiMqeiMiIlIv_wAfAD8AfADgAAA_z_ziMoeiMiPnPuIkhIuIiPwASKiYkpf8PA~CDhgAAAHwADz_ziPwAyKifkvD8kipj8iIgISLjsqf_wAAAB4A~C8AAAIPTMziICAyLjnofCMkgeAEiIAAOMeDx_wPAB8AH4AfAD8APwAjMziIAAuM-HwHAKkgICCiYCAj4CA_8AAAOA~CHgAACDwAAA_PiQgIjwgPCCgJkgAQBngA~CBH_B_A~Cf4A~C8AAAODA_PzgAAgAgHACAIUiEwJeAgICIx_wGA~EEAB_ADwAPwAP_8_ICAgIiCgKD4kgCgAoAAACIf8A~CP4A~CeA~CBwAAP_8f4A~CgCACB4EgKiAoICAhf_AADgA~C_A~CB4AAAHAzMwB_gICAgKCCh4EgAiABAAAB_wAB8AH4ADA~EH8AP4AzMwAP8AAAgCACB4EiA0ITAgIf8AAOAAAHA~CD-AAADAwAADMwAA_wgIiCgKD4kgAUAKAAD_A~DHwAABgA~CB4AAAHADMwAeH-AAACACBwEgIKAqIC_6AAH_wAOAA-A~EHwA~CD_wAB__6AgKCCgoEgCJIAAP-gAAcA~HP-A~CB_4AD_wAAAD_AACACAgEiBzICj_ygBAAAD4A~CeA~CDwAf_vgAzAABgAP8CCgKCgkgBbQI_5QAA_AAAH4AA_gA_gAAP8n_gQAzDwAfgB_wCAAAgEgILQv-A~DgA~DPA~CfAAACqqqwDzwAAAYAH-KCAgoEgAAD_gAAD8AAADgAAABwA~CD4AeAHA~If4AAAgEiAg_4A~CD4AD8AAAP-AD_AAAHwB_7u7u7u7j_-AD_gICgkgAP-A~KDwAAAB8A~NBAAP8AAgEgL_gA~D8AAADA~EMAAAHgA~NB_ggoEg_6A~CH_AAAH8AA__4AAfgAAf8AB__8AD8AAAPgAAH-AgEv-KA~GB4A~E__AeAAADwA~DcAAAPwA~C_6gkvgA~EMA~HHPA4AAHwA~GB4A~CeAAD_AEoA~E_wADYAAAP_Ab_-uQAAP4AA__-AD-AAAB_gAAAP8EgA~DPAAACr4A~C8j_8BPA~GBAEA~CeA~DB_kgA~HhwAABgAAqvqrAAPA~NPA~CH0gA~Cf_-AAb6AAAfwAYAAGAAA_8AAf_-AA__4AB_wA~D0gA~HN3wAAAOAHqq4A~CD4AAABA~MEgAAB-A~C-CoAAfA~C__AAAPA~CGA~CEA~JEgAAAB_8AAAD4AAB-A~DQAAA_4AAB__AAD__AAD__wAAAEgA~DDwAA77A~X4A~CYAAAEgAAD4A~CPuO8A~DBgA~CcA~CDwA~C8A~CPA~DEgAD8A~Ccx4iDH_8AAAf_4AAD_8AAAP-AAAD_wAAA_wAAAEgA~FBv_-qgAD_A~HD-A~HPgAAAMAAAEgAAOA~CCH_-AQA~IcA~CB8A~CH_A~CfA~CEgAfwAAAH6r_6q38AAAH__wAAD_4AAAD8A~CB__gAA__wAEgHgA~CYBA~CgD_4A~bEgA~FM6qqrA~JwA~D8A~DwA~HEv_-A~C_wPAA8P_8AAf__wAAP_-AAAD-A~CPwAAAB__wAEgA~DPAAA__BwA~FIA~CB_AAABA~DPA~GEgA~KB8A~Nf_wA~KfkgA~rE_~s8'],
            card2: [280, 80, 'o', '_~s8gA~rEgA~rEgA~rEgA~rEgA~rEgA~rEgA~rEiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIkgA~rEgA~rEgA~rEiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIkgA~rEgA~rEgA~rEiIiIiIiIiIiIiIiIiIiIiIiciIiIiIiIiIiIiIiIiIiIiIkgA~TDvgA~TEgA~NwA~C8cMbYA~CDA~NEgA~MB4AAAB-gMCoA~CPA~NEiIiIiIiIiIiIiIiO34iIhUCMhxiIiPz4iIiIiIiIiIiKiIkgA~MYwP-H84gML6_w4DjAOA~ICAAEgA~LBgwAB4B9AMN2APD3zPfA~ICAAEgA~LOAwAAAC6AMCoAACWzJbA~ICAAEiIiIiIiIiIhwCHAIyIiIg4iMj4iIrTjtOIiIiIiIiIiKiIkgACA~FD7wPvAwA~C4AML6AAK3zrfA~ICAAEoiKiIiIiIiLaQtpC4iIiJEIuOOIiKXrpeuIiIiIiIiIiIiEgACA~FBy3HLQwAAADGAMICAAD3nPeR8A~GCAAEiIiIiIiIv8j7WPtYyIAAH33_33wABnjGeIj_if-IiIiKiIkgACA~DMD16UXpQwAX3~C_3~C0NjA2AAA_nMA~CCAAEoiKiIiIiMiJ7wnvAz3~KYzBjCIiIvsiIiIjIiEgACA~DMAB5gHmH3~NwA~DKsA~CDAAEiIiIiIiIuIhsCGw3~PwAiIiHGIiIiPiIkgADA~DMADGAMb3~QwAAAf0A~CFgAEoiLiIiIiMiIgH3~TwCIqsiIiIm4iEgAFA~DMAAH3~GwAAMAAH3~GwAKkAAAf9eAEiImoiIiIuIB3~FgACIiMiIgAD3~FAPuIiIqqrokgAFQA~CMAX3~D0AAAgAAMA~EX3~D0IkAABVVVgEov6uIiIiMH3~DwwiIioiIuIiIiIiDH3~DY0iIjqqqyEgVVXA~CN3~DwAwAAAgAAMA~EDAH3~DMAADVVVYEq6qrqqqgf3~CwCq6q~Euq~ErqgH3~C8Cq~Ekg1VVgAAH_333wAAAwAAAgAAMAAACAADAAAH333_wAFVVVUEoqqq4iA3~CwAiIi4iIj4iIuIiIiIiLiIiAH3332AmqqquEhVVVQAH3332A~DwAABQAAMAAACAADA~D3~CwNVVVWErq~Ch3~Cgq~D6qqr6qquq~Erq~DD3~CCq~CkjVVVYH333wA~EwAAJYAAMAAAFAADA~EH333wV~CEq~Cs3332CIiIiIi4iI-uiIuIiInIiLiIiIiIg3332qqqrEjVVVU333sA~FwAA1WAAMAAB9wADA~FP332V~Ckuqqqu33wOq~F6qqqrqquq~Erq~FsH32q~CklVVVW33AMA~FwABVVAAMAADVUADA~FMB32V~Ckuq~C3wCMiIiIiIi4iJqqiIuIiOqsiLiIiIiIiMgH2q~CklVVVW2AAMA~FwADVVgAMAAFVWADA~FMAA2V~Ckmq~CwAAMA~FwACqqwAMAAKqrADA~FMAAGq~CkjVVVUHv8f8A~DD_4_VVgOP4AHVXAD-AA_wAAN_-AV~C0h-qv8AAAMAD_A~C8AA_-D_t8AB_-AH4A~CBwMAH_qqq-Evj__gAAA_-_8_-AAwAAAD_4MA~DHDADwAfwAMAAAf__gEgA~CDj-f_gAH__AwAfHgAH_gA~DDA~Cf_AMA~FEgAD_A~CPgAP8f-AwAD_wAA8AP-f4ADA~ED_A~FEgA~FMH-A~Dx-A~DMA~EDA~Ef8A~FEn8P4_-AAMAAOA~CwA~EMA~DB_4PwAAAP8D_A__4AEgA~F_4A~DD8__8A~EeA~EfwAcA~CBjP_gEgA~HPwA~L-Af_gA~DP_gAAADRgAAEgAAAD4AA_4A~Rf_4A~HCA-AAEgAAAMGAB4AAAD_gP_gAAAD4A__4A~C-A~CP_w8ACAv-AEgHwP4DA~D_-AfgA~LDgA~Ef4AABj_AAEv4AARBAP4P_AD_4A~DB_AA4eAD_gA_8D4A~F-A_8EgAAAQBAAAf-A~CB_B-cA~IeB_MGAAfgAAH8AeH0v_-AQBAAA_8_-P_gA~KD4AAAQBAAA_gA~EEgAAB4DA~KH__wAH-AH4A~DwB_A~CB-A~CEg4D8OGP_v-AAP_-AP4AAA4H_A-A~FhAgAA4P-AA_4A0gAAAH4AAAf-Af_jAAAHgA~DH4A~EgAgA~Ff_AEg_-AAOP_8A4AADR__PgAH_-AAAP_gA~CgAgA~IEgA~EB8AAAH-AgAAAP_A~KgAjgA~HEv_AD_gAA_8AD4CAuAD4A~DD_8_v__AAQDgA~FP__0gA~IfABj4P__A~CHA~HMGAAAH8B_4AAP0vwAH_gA~DD5_~CAA_~CwAA_-A4AAAD4P_8AA_8AD__0uAf8AAAPgAAH_A~IB8AD__9_8A~FfwAH_wAEgA~D_wA~G_wAAA-A~E8AAOAAA_AAP-B-_z4AUgA~rE_~s8'],
            over: [280, 88, 'o', '_~s8gA~rEsM~QIA~YEgw~Q4A~YEjD~QIA~YEsM~RA~YEgw~Q0A~YEjD~QEA~YEsM~RiIiIiIiIiIiIiIiIiIiIiIiIiIkgw~Q0A~YEjD~QEA~YEsM~QIA~YEgw~Q4iIiIiIiIiIiIiIiIiIiIiIiIiIkjD~QIA~YEsM~QQA~YEgw~RA~YEjD~QgiIiIiIiIiIiIiIiIiIiIiIiIiIksM~PNA~ZEgw~PyCIiIiIiIiIiIiIiIiIiIiIiIiIiEjD~NADMA~ZEsM~NAAwIiIiIiIiIiIiIiIiIiIiIiIiIiIkgw~NABA~aEjD~MAAAIiIiIiIiIiIiIiIiIiIiIiIiIiIiEsM~MA~dEgw~Mx_-CIiIiIiIiIiIiIiIiIiIiIiIiIiIkrD~N_-A~aEkM~MD_-ACIiIiIiIiIiIiIiIiIiIiIiIiIiEjw~E-w~FDwM4A~ZEgjD~DRD~GwM-AiIiIiIiIiIiIiIiIiIiIiIiIiIkgcM~DgsM~DOBwI_A~ZEoDw~Dow~E4B4Y_wIiIiIiIiIiIiIiIiIiIiIiIiIiEgAfjDDDITD~CHgY_x_4A~YEqqAcMMOYcMMMP4IYPB_8Kq~XkgAAIww04Iw~CAA8AD_-A~YEoiInDDZcnDDDgiI_AP_-IiIiIiIiIiIiIiIiIiIiIiIiIiEgAAB8PhOhsMOAAA_~DA~YEqqqoP8Ivgcw4qqo_~DKq~KAAKq~IkgA~EriD_A~C_~DA~YEoiIiIiIoiQACIiA__z_-IiIiIiIiIiIiAKqAIiIiIiIiIiEgA~EoiQA~C8__8PhA~KCqqgA~IEq~FoiSqqqr8___gOKq~IgKqqqCq~HkgA~EoiQAAABAf___-A~Kq~CA~IEq~EpIiSqqqo8P___8Kq~ICq~Cgq~HkgA~DBIiQA~CgP___4A~JCq~CoA~HEq~EpIiKq~CfH___gq~IoKq~CoKq~GkgA~DBIiIA~CQB__-A~KKq~DA~HEq~EpIiKq~CPg__wAq~Igq~ECq~GkgA~DCIiIA~CEAP8AQA~Jq~EgA~GEq~FIiMq~Cn8AAD4Kq~Hiq~Egq~GkgA~DCIiMA~CCAAA_-A~ICq~EgA~GEq~FIiMq~Cp8f-__AqqBKnAKcACnAKcAqoqqqnAKcAkgA~DCIiKA~CBAf-v_gAD_APvA-8KvvK-8qoAAAPvA-8Eq~EmIiKq~Co-f-q_4Kr_KtpI8kKtpK2kqjyqqtpK2kkhEREREWIiKREREQgP-qr8ET_RHLV-NKnLactqn5ERHLUctUq~C__2IiKf___-eP-qq-Cr_KPtZU1KPtY-1qlSqqPtY-10gB__AAGIiJA~DIP-qq_GD_AXpQ5FKXpZelqjkAAXpdelEr-CAgIGIiJCAgICPH-qqvj7_KnvJ9cKnvKe8qn0qvnvCe8EsA~DKIiJA~DEH-qqvh7_RHmF6YqHmIeYqnp_4HmAeYEiAgICAqIiJAICAgHj-qqvx7_Kmwo4AqmwqbCqjjICGwIbAkgA~DKIiIgA~CCD-qqA~CQMYA5gqsaqxq_7gAAMYAxgEgICAgIKIiIiAgICBx-qoAAA4v-qoooq~Cv_gCiAgICAgIEgA~DKIiIgAAQABB-qof_AQAB_9tEqqv_wAAGwA~FEiAgICASIiIgIGQgI4-qo__4ICAgp_~CyAgICAgICAwICAkgA~DSIiIwACgAAQ_qp__-AAAIgA~HBAAAEQAAAEgICAgISIiIyAioCAcfqp___AgIHAgICAgICAgZCAgKiAgIEgA~DSIiIwABAAAIP6p___gAAnA~IKAABCgAAAEiAgICASIiIwITAgIKP6p___4CA0KCAgICAgICKgITAgICAkgA~DiIiIoAKAAADH-p___8AAUiiAAAEAAQA~CKA~DEgICAgIiIiIqAqICAjD-4___-AICU0ICBkIGQgICAqICAgIEgA~DiIiIoA~DDg_4_~CAAAUUBAAoACgA~IEiAgICqiIiIkq~Dpwf8f___iq~C7qqqgioCQgICAgICAkgAAAiAiIiIkA~E8D8P___wAAiAKA~EEQA~GEq~C-piIiIkq~EGAAH___wqq-q~I___qqqgIkgAAAUBiIiIkA~EBwAA___4AAckA~FCgA~FIkq~DpiIiIiq~Eof_gAf_4qqvuq~Gr___q~CvkgA~CBiIiIiA~FH_4AB_8AAFoA~H___A~CFEq~DpiIiIiq~Fh_qqAf8Kq~Hp_~F-KqkgA~CCiIiIiA~FB_gAAP8CIA~GD_~GAAEq~EiIiIjKq~Ev_6qqP-D6q~Gn_~GiqkgA~CD_~DA~FfA_AAH-A~HCP_~GwAEq~DoA~DKq~Dr-q_qqH_gAKq~ErgIA~EICqkgA~GUA~FHwAL4AH__gA~FBUIAFA~CIAQEq~P_6qq-qj__-Kq~F-6qvq~Cuu6kgA~CCIiA~GB-gAAPgD___A~HQAIgAAAECgEq~Dr6-q~E_~Hx__-Kq~G6qvq~CuqqkgA~CBQUA~G__AAA__AP_A~IQAFA~CEAAEgA~rE_~s8'],
            win: [280, 80, 'o', '_~s8gA~rEgA~rEgA~rEgA~rEgA~rEgA~rEgA~rEiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIyIiIiIiIkgA~hgAQA~FEgA~hgAQA~FEgA~hQAQAIA~DEiIv_~LiIiIiIiIiIiIiIiIiIiIyIyIqIiIiIkgAIA~LYA~RQQQAQA~DEgAIA~LEA~PgAQ~DA~DEgAIA~LDA~PQAIQQgQA~DEiIoPonHgPHPIgA~CwiIiIiIiIiIiIiIiIyIqYyoiIiIiIkgAICIiIAIoopA~DIA~OIIIQQggA~DEoiICIiIAIooqA~DGIiIiIiIiIiIiIiIiIuIyYjoiYiIiEgAICPiHAPPvMA~DGA~OEEIIghBAQAAAEiIoCIiAgIIqKA~DIiIiIiIiIiIiIiIiImKmIjKjJiIiIkgAICIiAgIIpJA~DwA~OCCAAACCCA~CEoiIA~LDCIiIiIiIiIiIiIiIiLiIAAiKiIiIiEgAIA~LEA~NEBAgA~CEEA~CEiIoA~LYIiIiIiIiIiIiIiIiIiIAqoAiIiIiIkgAP_~LgA~NDAQAKqqgAQEAAAEoiIA~LIiIiIiIiIiIiIiIiIiYAqqqoAyIiIiEgA~HcA~UQACq~CAAQCAAEiIiIiIiIiI-IiIiIiIiIiIiIiIiIiIiIiIgKq~CgIiMiIkgA~eEAq~DoBAIAAEoiIiB_~L8iIiIiIiIiIiIiIiCq~DoCIyIiEgAAAGA~LEA~LGACq~EABAAAEq~CIA~LEq~LroKq~ECuqqqkgAAAwA~LEA~KwAwKq~EgYAAAEoiIjAAAici8A8c8iAAEiIiIiIiIiIiIuIgq~FgPCIiEgAAEAAAi~CAiiikAAEA~KDAAq~FofkAAEqqqYAAAUiiiAiiioAAEq~L6gq~FoVCqqkgAAYAAAIii8A8-8wAAEA~LMCq~DpwAHAAAEoiIEAAAIiioAgiooAAEiIiIiIiIiIiIiKiq~Dr7wPvIiEgAADAAAIiikAgikkAAEA~MCq~DraStpGAEqqqowAAIcciAgiiiAAEq~MCq~Dpy2HLQ_0gAAAIA~LEA~KHgKPKqqqj7WPtfAEq~CmA~LEq~L6Kfiqqql6WXpaqkgAAAB_~L8A~MKVKqqqp7wnvAAEq~CoA~Mq~LCpCSqqqh5jHmKqkgA~HcA~RIBIiIiqqqpsMGwAAEq~Dg_Dqq-q~Rv8okTCqqoDGqsaqqkgA~D_DAAcA~OPA~FaqoB_A~EEq~Dj_zqq-q~Ofqqqn___qgD-q~FkgA~CD_zAAcA~OVAAAKqqqgP8A~GEq~DjMzqq-q~KcApwGKqqn___v6q~HkgA~CDMzAAcA~K-8D73QAABwA4A~JEq~DA_Pqq-q~K2kraSiqqir9Uq~JkhERERA_PREdEREREREREREQctBy2BEAJwA4A~JEq~DP_8qq-q~Jo-1j7WIAf6q~MkgA~CP_8AAcA~JBelF6VB_gA~NEq~DzMwqq-q~Ke8p7xuq~PkhERERzMwREdEREREREREREQeYB5gA~QEq~DDMyqq-q~JAbD5sKq~QkgA~CDMwAAcA~J-xgDGA~KQABAQABEq~Dj_yqq-q~DoAAH__q~Ou6q7u6q7khERERD_wREdEREAAD__4BEAAAQA~KCgAKCgAKEq~DgzCqq-ANh__-q~Fu6q~SkgA~DzAAAcfquA~FoACKgA~SEq~DjzwD__qHCq~Hr6q~H6q~JkhEAAADzz8I8AvoAAAIiIAAABQA~DCIAEQA~DQA~DEgAP__6qqqv-q3aqqqvr6q~Ir6q~Fu6q~Dkv_wAEAFAAFcAKgAAAFBQA~IBQACgAABACgA~DEqqqruqvqqq-qPiq~V7q~GkgAAAoA~DcAvoA~UBKQA~FEq~K46q~Muq~F7u6q~FkgAEA~HgIA~LBEAIgAAACKSgA~EEEqruqqq6q~Juq~Jvq~Cr-6q~DurukgAoAAEQA~IBEA~HoAHIiAABSgA~CBEAoEq~cr6-q~LkgA~CCgA~JoA~JBQUA~IoAAEq~skgA~rEgA~rE_~s8'],
            goal: [280, 12, '.', 'gAGAAADAAOABgAAAwADgAYAAAMAA4AGAAADAAOABgAAAwA~CCAAACAAcAAgAAAgAHAAIAAAIABwACAAACAAcAAgAAAgAA4AGAAADAD_gBgAAAwA_4AYAAAMAP-AGAAADAD_gBgAAAwAM3ACAAACAd_cAgAAAgHf3AIAAAIB39wCAAACAd_cAgAAAgB044CAAACDj_jggAAAg4_44IAAAIOP-OCAAACDj_jggAAAgOM8HCAAACcH_wcgAAAnB_8HIAAAJwf_ByAAACcH_wcgAAAhwc8DyAAAD4H_wPgAAA-B_8D4AAAPgf_A-AAAD4H_wPgAAAngcMzCAAADMzMzMgAAAzMzMzIAAAMzMzMyAAADMzMzMgAAAkzMQAiAAACgBBACgAAAoAQQAoAAAKAEEAKAAACgBBACgAAAiAEIAiAAACgCAgCgAAAoAgIAoAAAKAICAKAAACgCAgCgAAAiAIEAiAAACgEAQCgAAAoBAEAoAAAKAQBAKAAACgEAQCgAAAiAQ_~s8'],
            road: [280, 60, '.', 'A~_A~_A~_A~_A~_A~_A~_A~_A~qBwBwBwBwBwBwBwBwBwBwBwBwBwBwBwBwBwBwBwBwBwBwBwAACACACACACACACACACACACACACACACACACACACACACACACA~_A~_A~_A~_A~_A~_A~_A~PDkDkDkDkDkDkDkDkDkDkDkDkDkDkDkDkDkDkDkDkDkDkDkA~_A~_A~_A~_A~_A~_A~_A~8HAHAHAHAHAHAHAHAHAHAHAHAHAHAHAHAHAHAHAHAHAHAHAEAIAIAIAIAIAIAIAIAIAIAIAIAIAIAIAIAIAIAIAIAIAIAIA~_A~_A~_A~_A~_A~_A~_A~POQOQOQOQOQOQOQOQOQOQOQOQOQOQOQOQOQOQOQOQOQOQOQA~_A~_A~_A~_A~_A~_A~_A~8'],
            median: [280, 12, '.', 'AwAAADA~CMA~CwAAADA~CMA~CwAAADA~CMA~CwAAAU1BQUDBQUFNQUFAwUFBTUFBQMFBQU1BQUDBQUFNQUFAwUFAIyAgIDAgICMgICAwICAjICAgMCAgIyAgIDAgICMgICAwICAAwAAADA~CMA~CwAAADA~CMA~CwAAADA~CMA~CwAAAazb~GNbbbazb~GNbbbazb~GNbbbazbbYAwAAADA~CMA~CwAAADA~CMA~CwAAADA~CMA~CwA~CwICAjICAgMCAgIyAgIDAgICMgICAwICAjICAgMCAgIyAgIAwAAADA~CMA~CwAAADA~CMA~CwAAADA~CMA~CwAAAiwiIiLCIiIsIiIiwiIiLCIiIsIiIiwiIiLCIiIsIiIiwiIgU1BQUDBQUFNQUFAwUFBTUFBQMFBQU1BQUDBQUFNQUFAwUFAIyAgIDAgICMgICAwICAjICAgMCAgIyAgIDAgICMgICAwICAAwAAADA~CMA~CwAAADA~CMA~CwAAADA~CMA~CwAAA'],
            start: [280, 12, '.', 'zMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMwA~tICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA~uiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIA~tiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFAICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA~u'],
            rv0a: [280, 60, '.', 'A~_A~bIgA~JCIiA~HCA~SIA~tqoiIiA~DCIiKqqgA~FiqqIA~ECIiIA~FIoA~tIA~KCIiA~HiIA~RIA~tqIiIgA~DiIiKqogA~FiqqIA~ECIiIA~FIoA~tIA~KiIgA~HiIA~RIA~tiIA~FIiIiqqogA~FqqqIgA~EIiIAAiIAAAIgA~4gACIgA~GIiIgA~_AiA~GIqoiIiIA~ECIqqqoA~FIqIiIiIA~CgA~3IA~KCIiIA~GCIiA~5IiIgACIiIgAiA~DCIiKqqqA~FCKqqqIiIA~1CIgA~PCIiA~HiIiIA~1iIiqqogA~FCIAAAIiIiIqogA~Fiq~CIgA~0iIiIgA~aIiIiA~2q~DoA~GiqiIiIiIAACIA~ECIqqqogA~FoA~tIiIiIA~HIiIA~PIiIA~HIA~tq~CiA~FiKqqqIA~FIgAAIiIiIiqiA~GKoA~tIiA~JiIiIA~aCIA~tqoiA~FiKq~CiA~FCqqoiIiIA~KiqoA~tIA~HIiIiIiA~HiIiA~PCIA~tiA~GKq~DoA~FIq~CiIA~EiICIiIiACIgA~2CIiIiA~HCIiIgA~JCIgA~2iIAACKqoiIiA~FiKqqqoA~FIq~DogA~_A~HiIiIA~GCIiIiA~2iKqogAAIiA~HiIiKqqiA~FCq~CIiA~2iIgA~QIgA~HiIiA~2iIqqqogA~FiIgAAIiIiIiIgA~FiqqqiA~FgA~tIiIiIA~IgA~RiIgA~2q~DIA~FIqqiA~FCIgA~FiqqoA~FIoA~tIiIA~ICIiA~RiIA~HIzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMw'],
            rv0b: 'rv0a',
            rv1a: [280, 60, '.', 'A~tB4A~CB_gAAAB_gA~C_gA~CDgBgA~CB-A~CB_gAAAB8A~ICAADgAD4AA-AAPAADA~LCAADgAA-A~D-A~DCAHgA~CH-A~CH-A~CD-A~DOAGA~DiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgA~_A~ePgA~DgB4A~CB_gAAAB_gA~C_gA~CDgBgA~CB-Ai-iIvIiLiIiIiIiIiIiIiKiIjoiL6Ii-iIvIiLiIiIiIiIgA~CH-A~CH-A~D-A~DCAHgA~CH-A~CH-A~CD-A~_A~diIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgAB_gAAAB_gA~CPgA~DgB4A~CB_gAAAB_gA~C_gA~ECAADgAD4AA-AAPAADA~LCAADgAD4AA-AAPAAOAGA~DH4A~CH-A~CH-A~D-A~DCAHgA~CH-AAAiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgA~_A~bBgA~CB-A~CB_gAAAB_gA~CPgA~DgB4A~CB_gAAAB8iIiIiIiIiIiIiKiIjoiL6Ii-iIvIiLiIiIiIiIiIiIiKiIg-A~CD-A~DOAGA~DH4A~CH-A~CH-A~D-A~DCA~_A~ciIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgAAA_gA~CDgBgA~CB-A~CB_gAAAB_gA~CPgA~DgB4AD4AA-AAPAADA~LCAADgAD4AA-AAPAADA~IH-A~CH-A~CD-A~DOAGA~DH4A~CH-A~CH-AAiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgA~_A~cB_gAAAB_gA~C_gA~CDgBgA~CB-A~CB_gAAAB_gA~CiIiIiKiIjoiL6Ii-iIvIiLiIiIiIiIiIiIiKiIjoiL6Ii-gA-A~DCAHgA~CH-A~CH-A~CD-A~DOAGA~DH4A~_A~eiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgA~DgB4A~CB_gAAAB_gA~C_gA~CDgBgA~CB-A~CB8AADA~LCAADgAD4AA-AAPAADA~M-A~CH-A~D-A~DCAHgA~CH-A~CH-A~CD-A~DOAiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgA~_A~dB_gA~CPgA~DgB4A~CB_gAAAB_gA~C_gA~CDgBgAjoiL6Ii-iIvIiLiIiIiIiIiIiIiKiIjoiL6Ii-iIvIiLiIgA~CH4A~CH-A~CH-A~D-A~DCAHgA~CH-A~CH-A~_A~diIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgAB-A~CB_gAAAB_gA~CPgA~DgB4A~CB_gAAAB_gA~KCAADgAD4AA-AAPAADA~LCAADgAD4AD-A~DOAGA~DH4A~CH-A~CH-A~D-A~DCAHgAAAiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgA~_A~fDgBgA~CB-A~CB_gAAAB_gA~CPgA~DgB4A~CB8iIvIiLiIiIiIiIiIiIiKiIjoiL6Ii-iIvIiLiIiIiIiIiIg-A~CH-A~CD-A~DOAGA~DH4A~CH-A~CH-A~D-A~uzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMw'],
            rv1b: [280, 60, '.', 'A~tMA~DPwA~CP8A~CP8A~CB8A~DEAPA~DP8A~CP8A~LQAAcAAfAAHwAB4AAYA~LQAAcwA~CfwA~CBwAwA~D_A~D_wA~C_wA~CHwA~DQAiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgA~_A~dH8A~DcAMA~DPwA~CP8A~CP8A~CB8A~DEAPAA_IiPyIj4iI6IiIiIiIiIiIiIyIiciI_IiPyIj4iI6IiIiIgA~C_wA~C_wA~CfwA~CBwAwA~D_A~D_wA~C_wA~_A~diIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgAP8A~CP8A~CH8A~DcAMA~DPwA~CP8A~CP8A~IQAAcAAfAAHwAB4AAYA~LQAAcAAfAAHwAHwA~DQA8A~D_wA~C_wA~CfwA~CBwAwA~D_A~CiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgA~_A~fEAPA~DP8A~CP8A~CH8A~DcAMA~DPwA~CP8iI6IiIiIiIiIiIiIyIiciI_IiPyIj4iI6IiIiIiIiIiIiIwwA~C_wA~CHwA~DQA8A~D_wA~C_wA~CfwA~CBwA~_A~ciIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgAAP8A~CB8A~DEAPA~DP8A~CP8A~CH8A~DcAMAAcAAfAAHwAB4AAYA~LQAAcAAfAAHwAB4AAYA~F_A~D_wA~C_wA~CHwA~DQA8A~D_wA~C_wAAiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgA~_A~cPwA~CP8A~CP8A~CB8A~DEAPA~DP8A~CP8A~CEiIiIiIiIyIiciI_IiPyIj4iI6IiIiIiIiIiIiIyIiciI_IgfwA~CBwAwA~D_A~D_wA~C_wA~CHwA~DQA8A~_A~fiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgA~CcAMA~DPwA~CP8A~CP8A~CB8A~DEAPA~DP8AB4AAYA~LQAAcAAfAAHwAB4AAYA~JwA~C_wA~CfwA~CBwAwA~D_A~D_wA~C_wA~CHwAiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgA~_A~dP8A~CH8A~DcAMA~DPwA~CP8A~CP8A~CB8A~CyIiciI_IiPyIj4iI6IiIiIiIiIiIiIyIiciI_IiPyIj4iI4AAQA8A~D_wA~C_wA~CfwA~CBwAwA~D_A~D_wA~_A~diIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgAPA~DP8A~CP8A~CH8A~DcAMA~DPwA~CP8A~CMA~JQAAcAAfAAHwAB4AAYA~LQAAcAA_wA~CHwA~DQA8A~D_wA~C_wA~CfwA~CBwAwA~CiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIgA~_A~eB8A~DEAPA~DP8A~CP8A~CH8A~DcAMA~DPwiPyIj4iI6IiIiIiIiIiIiIyIiciI_IiPyIj4iI6IiIiIiIgA~D_wA~C_wA~CHwA~DQA8A~D_wA~C_wA~CfwA~uzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMw'],
            rv2a: [280, 60, '.', 'A~wGAABgYQAABgAAAHOAAAIAAABgAAYGEAAAYAAABzgAACAIAACAAAv4AAAgA~CvIyAAoCAAAgAAL-AAAIA~CLyMgAKA~CQAAAD4AAAEABAARADABA~CEA~C-AAABAAQAEQAwAQAQA~EOnAAAgAAACDgAuOgEA~EDpwAAIA~Cg4ALjoAAACA~CIBYAAgAAACAAAD8A~CgAAACAWAAIA~CgAAA_AAgAMA~CwCDIDA~CMA~C-AIADA~CMAgyAwAAADA~CPgAAAQA~FMGA~GBgwQAEA~FDBgA~GYMEAAiAAACAEAD8gAIAgAAASAiQAIgAAAgBAA_IACAIAAAEgIkgDAAAEA~D_AAQAAQAAEAAYAwAABA~DPwAEAAEAABAAEOuA~CYAAABi4AAGA~C4AADrgAAAGA~CYuAABgAAAGAAAB8AAAQgAAACADAAIA~CgAAAfAAAEIA~CgAwACA~CIAAAG_gAAYAAABKAAMm4AAAQAAABv4AAGA~CSgADJuAAAEA~CBAYAAUA~CQAAB3gAAAEA~CQGAAFA~CEAAAd4AAABA~CKgJgAoA~FCfgCAACAAECoCYAKA~Gn4AgAAgABAIAAMAgABACA~CfwAAAkAAACAADAIAAQAgAAAH8AAAJA~CwQAB_JAAAMGAAAyKgADBAAAMEAAfyQAADBgAAMioAAwQA~CBAAfgA~CEAABAAYAEA~EQAH4A~CBAAAQAGABA~ECAAf6AAAgEAACAAJiIA~CgAgAH-gAAIBAAAgACYiA~CIAEAAwDAABA~FQPwA~DBAAMAwAAQA~EED8A~EGABgAqIGA~CYABAP6gAAGABgAYAKiBgAAAGAAQD-oAABgAAACAABgYAQAAgABAHOAABIA~CgAAYGAEAAIAAQBzgAASAAIA~D_4A~EBguIyAAoACA~DP-A~FYLiMgAKA~CQAABD4AAAEAABAQADABA~CEAAAQ-AAABAAAQEAAwAQA~GunAAAgAAACAAAuOgA~FLpwAAIA~CgAALjoAAACAAABYAYAAgAAACAAAD8A~CgAAAWAGAAIA~CgAAA_A~CMBAAA4ADIDgAAAMA~C-AAADAQAAOAAyA4AAADA~CPgABARA~CEAAMGUAAABAAABkwAQEQAAABAADBlA~CQAAAZMAAAgiAACCAAD8sAAAggAACSiAAIIgAAggAA_LAAAIIAAAkoggDAUAAABAAA_EA~CQAAEBAYAwFA~CQAAPxA~CEAABAQEOuAIAAYAgABi6AAGAIAAYAgDrgCAAGAIAAYugABgCAAGAIAB8AEAAgAQQCADAAIAEAAgAAAfABAAIAEEAgAwACABAAIAAAK_gCAAQAAAKAgMi4ADA~DCv4AgAEAAACgIDIuAAwA~DBAYQAEA~CQAAB3gABAEA~CQGEABA~CEAAAd4AAQBA~CKgJgAoAACA~CCfgADgA~CCoCYAKAAAgA~Cn4AA4A~DIAAMAgAABCA~CfwAAAgAAACAADAIAAAQgAAAH8AAAIA~CwAAB_IAADMA~CyKgADA~CMAAAfyAAAzA~CMioAAwA~GfgA~FBAAYAEA~CQAAAH4A~GQAGABA~CEAACAf6AAEgAAACIAJiIA~CwAAgH-gABIA~CiACYiA~CMA~CwHAABA~DQAAfwA~GMBwAAQA~CEAAH8A~GBgAqAGA~CYQAAP6AAAGA~CYAKgBgAAAGEAAD-gAABgAAACAQBgYA~CgAAAHOAAAIA~CgEAYGA~CIAAABzgAACAEA~CYAv4BA~CEAAuIyAAoBA~CGAL-AQAAABAALiMgAKA~CQAQAD4AAAEBAAAQADABA~CEAEAA-AAABAQAAEAAwAQABA~CkAOnARAgABACAAGuOkAQAAAJADpwEQIAAQAgABrjpAAACA~CJAYAAgAAACAAED8A~CgAAACQGAAIA~CgABA_AAAQMAABAwADODA~CMAABA-AAEDAAAQMAAzgwAAADAAAQPgAABQA~FMGA~GBgwAAUA~FDBgA~GYMAAGgAAACAAAH8gAAAgAAACAiABoA~CgAAB_IAAAIA~CgIggHA~H_A~CQAAAEAAYBwA~GPwAAAEAAABAAEOuA~CYIAABi4QAHAAAgYAADrgAAAGCAAAYuEABwAAIGAAAB8A~CgAAASADAAJA~CgBAAfA~CIAAAEgAwACQAAAIAQAC_gA~CQAAKAAMi5gA~Fv4A~CEAACgADIuYA~FBAYAAEBAAAQAAB3hAAAEA~CQGAABAQAAEAAAd4QAABA~CKgJgAoAgA~DCfgA~FCoCYAKAIA~En4A~GIAAMAgBQACA~CfwAAAgAUACAADAIAUAAgAAAH8AAAIAFAAwAAB_IAIAOAAgAyOgADAAIAMAAAfyACADgAIAMjoAAwACAABABAfgAEA~CUBAAZAEAAEAAQAQH4ABA~CFAQAGQBAABA~Dif6ACAgAAMCAAJiIAASAgAAIn-gAgIAADAgACYiAAEgIzMzczPzNzMzM3MzMzfzMzNzMzM3Mz8zczMzNzMzM38zMzcw'],
            rv2b: [280, 60, '.', 'A~vCBAAA_A~CkAAACAAYAIA~CgQAAPwAAAJA~CgAGACA~CIAAACy4AACGAAAIAIDowAAACA~CsuAAAhgAACACA6MA~CQEAABADEAEA~CQAAAfwAAAEBAAAQAxABA~CEAAAH8A~CgGAACAAIiYIAAAgAQAv4AAAIBgAAgACImCAAAIAEAL-AAACAFAAIAABhwAAACABAAMGAAAgBQACAAAYcA~CgAQADBgAAGA~CYAAQPgAAAGAAgAYCYABgAAAGAAED4AAABgAIAGAmAAYA~EBQP4A~DBQBAADAGA~FUD-A~EUAQAAwzoAQACAAAgqOgABgAAICCAA86AEAAgAAIKjoAAYAACAggAMfgAAEA~CQQBYBBAAAEA~CX4AABA~CEEAWAQQAABA~CEv6AACwAAALAAJiuAAACwAAAL-gAAsAAACwACYrgAAAsA~CYDAABgAAAGAAAP8AAABgAAAGAwAAYAAABgAAD_A~CYA~CoAqACgAAAKAAAL-AAACgAAAaAKgAoAAACgAAC_gAAAoAAAEEABgEQAAAQAAAD-AAAEA~CRAAYBEAAAEA~C_gAABA~CEAAAv4IAAAgAIAGKyAQIA~CgAAL-CAAAIACABisgECA~CIAAED8EAACA~CIQDAAgAAACAABA_BAAAgAAACEAwAIA~CgAAAL_MAAGAAQAYAAuLgDAAGAAAC_zAABgAEAGAALi4AwABgAAAGAYAAQA~FB-ABA~EBgGAAEA~GfgAQA~EKADICoAAgCA~C_IDAAgAAACgAyAqAAIAgAAAPyAwAIA~FMDAABA~E5wA~IDAwAAQA~DOcA~FjAAAD_gADAwCAADIiEAMAAAIwAAA_4AAwMAgAAyIhADAAABCABAA_A~CgAAAGAAYAIAAAQgAQAPwAAAIAAABgAGACAAABIA~Cy4AASAAABIAALowAAASA~CMuAAEgAAASAAC6MA~CQAAABADAAEA~CQAAAfgAAAEA~CQAwABA~CEAAAH4A~CwAAADAAIicA~C4AAAv4AAAMA~CwACInA~COAAAL-AAAGA~CIAABhwAAACQAAAMGAABgAAACAAAYcA~CkAAADBgAAGEAIAYYAAPhAAAGkAAAYTYABhACAGGAAD4QAABpAAAGE2AAYA~DQAAP4A~EBBAADAGA~DEAAD-A~FQQAAwzoAAACAEAAqOwAAhAAACAGAc6A~CgBAAKjsAAIQAAAgBgEPgA~GQAZABA~EEAD4A~GEAGQAQA~DBAAP6AAAwAAADAAPiMA~CwABAD-gAAMA~CwAD4jA~CMAAQAIDABAgAAACAAEP4A~CgAAACAwAQIA~CgABD-A~CIA~ChAqECACAAIAAGL6AAAKA~CIQKhAgAgACAABi-gAACgA~EBkEA~CUAAAD-AAAEAAQAQAAZBA~CFA~C_gAABAAEAEAAAv4A~CgAAIGIyAAIA~CgAAL-A~CIAACBiMgACA~CIAAAH8AAACA~CIADABgAAACAAAB_A~CgAAACAAwAYA~CgAAAL_AAAGQAAAYAAuLgAAAGAAAC_wAABkAAAGAALi4AAABgAAAGAYAARQA~EB_QA~FBgGAAEUA~Ff0A~GaADICogAACA~C_IACAggAAGgAyAqIAAAgAAAPyAAgIIQAAAEAMDAQABABAAA50A~CUAAABADAwEAAQAQAAOdA~CEwADACAD-hIAAwAiADIiAAMAMAAwAgA_oSAAMAIgAyIgADAAQACABAA_AEAAgAUACABYAIAEAAgAQAPwBAAIAFAAgAWACAAIAIAAgAy4CACAAMAIAAjowASACAAIAMuAgAgADACAAI6MAEEAQAAQBADAAEAAEAQAAAfgABAEAAEAQAwABAABAEAAAH4A~CgAAMCAAIiYAACAgQAAv4AgAIAADAgACImAAAgIEAAL-AIACEAAEIAABhwAAQCA~CMGAAAhAABCAAAYcAAEAgAAADBgAAGAAAIYAAAPwAAAGAAADYCYABgAACGAAAD8AAABgAAA2AmAAYA~GP4A~FBAADAGA~GD-A~GQAAwzoAAACA~CqugAAgAAADAAAc6A~CgAAAKroAAIA~CwAAEPgA~GRAYABA~GD4A~GEQGAAQA~GP6gAAwABADCAJiMA~CwAAAD-oAAMAAQAwgCYjA~CMA~CIDAABgAAACAAAP4AEAAgAAACAwAAYA~CgAAD-ABAAIA~CgBqACAACAIAQAL6BAACIAAAIAagAgAAgCAEAC-gQAAiA~EBgEA~CQAAAD-AQAEA~CQAAYBA~CEA~C_gEABA~CEAAAv4A~CgAAAGIyIAIA~CgAAL-A~CIAAABiMiACA~CIAEAD8AAACA~CIADQAgAAACABAA_A~CgAAACAA0AIA~CgAAEL_AAYGAQBAYAAuLgAARGAABC_wAGBgEAQGAALi4AAERgAAAGAYAQQA~FB-BA~FBgGAEEA~GfgQA~FKADICoAAACAABA_IAACgAAACgAyAqA~CgAAQPyAAAoAzMzMzM_PzMzMzMzMz9zMzMzMzMzMzPz8zMzMzMzM_czMzMw']
        };
        // Six pixels per character, left pixel high; '~' plus a count character repeats the previous character.
        function unpack(a) {
            var w = a[0], h = a[1], dark = a[2], s = a[3], cols = Math.ceil(w / 6), out = [], i = 0, v = 0, k, b, x, y, row;
            while (i < s.length) {
                if (s.charAt(i) === '~') { for (k = AL.indexOf(s.charAt(i + 1)) + 1; k > 0; k--) { out.push(v); } i += 2; } else { v = AL.indexOf(s.charAt(i)); out.push(v); i++; }
            }
            var rows = [];
            for (y = 0; y < h; y++) {
                row = '';
                for (x = 0; x < w; x++) { b = out[y * cols + Math.floor(x / 6)]; row += (b >> (5 - (x % 6))) & 1 ? '#' : dark; }
                rows.push(row);
            }
            return rows;
        }
        function rep(ch, n) { return new Array(n + 1).join(ch); }
        function flip(s) { return s.split('').reverse().join(''); }
        function define(H) {
            var k, rows;
            for (k in SPR) {
                if (SPR.hasOwnProperty(k)) {
                    rows = SPR[k].split('/');
                    H.defSprite(k, rows);
                    if (k.charAt(k.length - 1) === 'R') { H.defSprite(k.slice(0, -1) + 'L', rows.map(flip)); }
                }
            }
            for (k in IMG) { if (IMG.hasOwnProperty(k)) { H.defSprite(k, unpack(typeof IMG[k] === 'string' ? IMG[IMG[k]] : IMG[k])); } }
            var dots = '', bar = '', x;
            for (x = 0; x < 280; x++) { dots += x % 2 ? '.' : '#'; bar += x % 4 < 2 ? '#' : 'o'; }
            H.defSprite('rule', [rep('#', 280), rep('o', 280), bar]);
            H.defSprite('hud', [dots]);
            H.defSprite('blk', [rep('o', 280)]);
        }
        // Scanlines in the order HGR page memory fills, for the disk-load reveal of a picture.
        function hgrOrder() {
            var o = [], a, b, c;
            for (a = 0; a < 8; a++) { for (b = 0; b < 8; b++) { for (c = 0; c < 3; c++) { o.push(c * 64 + b * 8 + a); } } }
            return o;
        }
        return { define: define, hgrOrder: hgrOrder };
    }());

    function start(H) {
        var G = {
            mode: 'BUSY', level: 0, score: 0, lives: 3, extra: false, best: H.load('best', H.isBest),
            world: null, t: 0, p: null, filled: [], timeMs: 0, ms: 0, msgMs: 0, cause: '', wasMode: '',
            ev: null, evNext: 0, pax: null, paxNext: 0, sfxQ: [], sfxWait: 0, hopMs: 0, homeSlot: -1, lastSec: -1, blink: false,
            titled: false, extraPend: false, buf: null, lastSay: '', askSay: '', loiterMs: 0, loitered: false, clearLine: false
        };

        function T(c, r, s, inv) { H.text(c, r, s, inv); }
        function say(s) {
                G.lastSay = s;
            H.fill(0, MSG_ROW, 40, 1, ' ', false);
            T(Math.max(0, Math.floor((40 - s.length) / 2)), MSG_ROW, s, false);
        }
        function flash(s, ms) { say(s); G.msgMs = ms || 2200; }

        function sfx(notes) {
            if (G.sfxWait <= 0 && !G.sfxQ.length) { G.sfxQ = notes.slice(); pumpSfx(); } else { G.sfxQ = G.sfxQ.concat(notes); }
        }
        function pumpSfx() {
            if (!G.sfxQ.length) { return; }
            var n = G.sfxQ.shift();
            H.beep(n[0], n[1]);
            G.sfxWait = n[1];
        }

        /* ----- screens ----- */
        // A picture loads the way a hi-res page does: scanlines arrive in memory order, not top to bottom.
        function stopLoad() { if (G.load) { H.cancel(G.load); G.load = null; } }
        function picture(list, h) {
            var order, n = 0, step;
            stopLoad();
            if (H.reduced) { H.sprites(list); return; }
            order = ART.hgrOrder().filter(function (l) { return l < h; });
            step = Math.ceil(order.length / 20);
            G.load = H.every(TICK, function () {
                var cov = list.slice(), i;
                n += step;
                for (i = n; i < order.length; i++) { cov.push({ s: 'blk', x: 0, y: order[i] }); }
                H.sprites(cov);
                if (n >= order.length) { stopLoad(); }
            });
        }
        // Cover first, then type under it, so a screen reader hears the words and the eye sees the picture arrive.
        function screen(name, h, rows, hold, after) {
            var i;
            stopLoad();
            H.cls();
            H.then(function () {
                var y, cov = [];
                for (y = 0; y < h; y++) { cov.push({ s: 'blk', x: 0, y: y }); }
                H.sprites(cov);
                picture([{ s: name, x: 0, y: 0 }], h);
            });
            for (i = 0; i < rows.length; i++) { H.line(rows[i], { center: true, hold: hold }); }
            if (after) { H.then(after); }
        }
        function titleAnim() {
            var n = 0, list = [{ s: 'ttl', x: 0, y: 0 }];
            if (H.reduced) { return; }
            G.anim = H.every(200, function () {
                var l;
                n++;
                if (G.mode !== 'TITLE' || G.load) { return; }
                l = list.slice();
                if (n % 2) { l.push({ s: 'ttlFlag', x: 246, y: 43 }); }
                if (n % 22 === 0) { l.push({ s: 'ttlBlink', x: 163, y: 10 }); }
                H.sprites(l);
            });
        }
        function showTitle() {
            G.mode = 'BUSY';
            var b = G.best, keys = 'SPACE LAY ON   H HOW TO PLAY   Q LEAVE';
            screen('ttl', 168, [
                '', '',
                'F R O G G O R K',
                '',
                'YOU ARE LATE. SIGN-IN IS ACROSS',
                'A BATTLEGAME THAT HAS ALREADY STARTED.',
                '',
                'SPACE  LAY ON         ',
                'H      HOW TO PLAY    ',
                'Q      LEAVE THE FIELD',
                '', '', '', '', '', '', '', '', '', '', '',
                b ? 'BEST ' + pad(b.score, 5) + (b.won ? '  ALL TABLES SIGNED' : '  REACHED LEVEL ' + b.level) : 'BEST 00000  (NONE YET)',
                'A STRANGER LENDS A TABARD AT 3500'
            ], 0, function () {
                var c = 1;
                T(c, 23, keys, false); T(c, 23, 'SPACE', true); T(c + 15, 23, 'H', true); T(c + 31, 23, 'Q', true);
                G.mode = 'TITLE';
                if (!G.anim) { titleAnim(); }
                if (!G.titled) { G.titled = true; sfx(SFX_TITLE); }
            });
        }
        function showHow(page) {
            var p = HOW_PAGES[page], list = [{ s: 'rule', x: 0, y: 9 }, { s: 'rule', x: 0, y: 174 }];
            G.mode = 'BUSY';
            G.howPage = page;
            stopLoad();
            H.cls();
            H.line(p.head, { center: true, hold: 0 });
            p.rows.forEach(function (s) { H.line(s, { hold: 0 }); });
            H.then(function () {
                p.art.forEach(function (a) { list.push({ s: a[0], x: a[1], y: a[2] * 8 + (a[3] || 0) }); });
                H.sprites(list);
                T(0, 23, page ? 'RETURN  BACK TO THE TITLE     Q LEAVE' : 'RETURN  NEXT PAGE             Q LEAVE', false);
                T(0, 23, 'RETURN', true);
                G.mode = 'HOW';
            });
        }
        function showCard() {
            var lv = LEVELS[G.level];
            G.mode = 'BUSY';
            screen('card' + G.level, 80, ['', '', '', '', '', '', '', '', '', '', '', 'LEVEL ' + (G.level + 1), '', lv.name, '', lv.flavor[0], lv.flavor[1], '', '', 'SPACE TO TAKE THE FIELD'], 0, function () {
                var mult = [1, 1.122, 1.26][G.level] || 1;
                G.mode = 'CARD';
                G.ms = 0;
                sfx([[523, 70], [659, 70], [784, 120]].map(function (n) { return [Math.round(n[0] * mult), n[1]]; }));
            });
        }

        /* ----- drawing the field ----- */
        function drawScore() {
            T(6, 0, pad(G.score, 5), false);
            T(15, 0, pad(Math.max(G.score, G.best ? G.best.score : 0), 5), false);
        }
        function drawHud() {
            T(0, 0, 'SCORE', false);
            T(12, 0, 'HI', false);
            T(21, 0, 'LV ' + (G.level + 1) + '/3', false);
            T(28, 0, 'LIVES', false);
            drawScore();
            T(0, TIME_ROW, 'REEVE', false);
            T(BAR_COL - 1, TIME_ROW, '[', false);
            T(BAR_COL + BAR_W, TIME_ROW, ']', false);
            T(0, HELP_ROW, 'ARROWS/WASD HOP  SPACE HOLD  Q LEAVE', false);
        }
        function drawTimer() {
            var lv = LEVELS[G.level], cells = Math.ceil(BAR_W * Math.max(0, G.timeMs) / (lv.time * 1000)), i, low = G.timeMs < lv.time * 250;
            var on = !(low && !H.reduced && G.blink);
            for (i = 0; i < BAR_W; i++) { H.put(BAR_COL + i, TIME_ROW, i < cells ? ' ' : '.', i < cells && on); }
            H.fill(BAR_COL + BAR_W + 2, TIME_ROW, 3, 1, ' ', false);
            T(BAR_COL + BAR_W + 2, TIME_ROW, String(Math.max(0, Math.ceil(G.timeMs / 1000))) + 'S', false);
        }
        function laneSprites(lane, list, frame) {
            var i, xs, k, x, y = bandY(lane.band) + 1, st, u, n, d = lane.dir > 0 ? 'R' : 'L', j, base;
            for (i = 0; i < lane.offs.length; i++) {
                st = duckState(lane, i, G.t);
                xs = copies(lane, i, G.t);
                for (k = 0; k < xs.length; k++) {
                    x = xs[k];
                    switch (lane.kind) {
                        case 'stretcher': list.push({ s: 'str' + lane.w, x: x, y: y }); break;
                        case 'tarp': list.push({ s: 'tp' + lane.w + (frame ? 'b' : 'a'), x: x, y: y }); break;
                        case 'wall':
                        case 'bearers':
                            n = lane.w / 12;
                            for (j = 0; j < n; j++) {
                                if (x + j * 12 + 11 <= 0 || x + j * 12 >= W) { continue; }
                                base = lane.kind === 'wall' ? 'shd' : 'bea';
                                if (st === 'down') { list.push({ s: 'rip', x: x + j * 12, y: y }); } else {
                                    u = st === 'warn' ? base + 'W' : base;
                                    list.push({ s: lane.kind === 'wall' ? u : u + d, x: x + j * 12, y: st === 'warn' ? y + (G.blink && !H.reduced ? 1 : 0) : y });
                                }
                            }
                            break;
                        case 'newbie': list.push({ s: (frame ? 'newbB' : 'newbA') + d, x: x, y: y }); break;
                        case 'cart': list.push({ s: (frame ? 'cartB' : 'cartA') + d, x: x, y: y }); break;
                        case 'fighters': list.push({ s: 'fgt' + d, x: x, y: y }); break;
                        case 'ball': list.push({ s: (G.blink && !H.reduced ? 'ballB' : 'ballA') + d, x: x, y: y + 2 }); break;
                        case 'arrow': list.push({ s: 'arw' + d, x: x, y: y + 3 }); break;
                        default: break;
                    }
                }
            }
        }
        // A rider is drawn relative to its platform's own rounded position, so it never twitches against it.
        function drawX(p) {
            var l = p.ride, x0;
            if (!l) { return p.x; }
            x0 = mod(l.offs[0] + l.ph + l.dir * l.v * G.t / 1000, l.period);
            return Math.round(x0) + Math.round(p.x - x0);
        }
        function clampX(x, hi) { return Math.max(0, Math.min(hi, x)); }
        function deathArt(list, p, ly) {
            var c = G.cause, ms = G.ms, x = clampX(p.x - 1, 268);
            if (c === 'lurker') { return; }
            if (c === 'mud' || c === 'sink') {
                if (!H.reduced && ms < 300) { list.push({ s: 'plyS1', x: clampX(p.x, 271), y: ly + 4 }); } else if (!H.reduced && ms < 600) { list.push({ s: 'plyS2', x: clampX(p.x, 271), y: ly + 7 }); } else { list.push({ s: 'rip', x: clampX(p.x - 2, 268), y: ly }); }
            } else if (c === 'edge') {
                list.push({ s: 'splat', x: clampX(p.x - 1, 269), y: ly });
            } else if (c === 'time' || H.reduced || ms >= 500) {
                list.push({ s: 'plyD', x: x, y: ly + 5 });
            } else {
                list.push({ s: 'splat', x: clampX(p.x - 1, 269), y: ly });
            }
        }
        function render() {
            var lv = LEVELS[G.level], list = [{ s: 'hud', x: 0, y: 9 }], frame = H.reduced ? 0 : Math.floor(G.t / 220) % 2, i, p = G.p, ly = bandY(p.band) + 1, k, xs, dx, hop, rx;
            list.push({ s: 'goal', x: 0, y: bandY(GOAL) });
            list.push({ s: 'rv' + lv.theme + (frame ? 'b' : 'a'), x: 0, y: bandY(R1) });
            list.push({ s: 'median', x: 0, y: bandY(MED) });
            list.push({ s: 'road', x: 0, y: bandY(RD1) });
            list.push({ s: 'start', x: 0, y: bandY(STARTB) });
            for (i = 0; i < SLOTC.length; i++) {
                if (G.filled[i]) { list.push({ s: 'ply', x: SLOTC[i] - 4, y: bandY(GOAL) + 1 - (G.mode === 'CLEAR' && !H.reduced && i === Math.floor(G.ms / 120) % 5 ? 2 : 0) }); } else if (G.ev && G.ev.slot === i) {
                    if (G.ev.kind === 'bottle') { list.push({ s: 'bottle', x: SLOTC[i] - 3, y: bandY(GOAL) + 1 }); } else if (G.ev.ms > lv.lurk) { list.push({ s: 'peek', x: SLOTC[i] - 9, y: bandY(GOAL) + 6 }); } else { list.push({ s: frame && G.mode !== 'DYING' ? 'lurkB' : 'lurkA', x: SLOTC[i] - 9, y: bandY(GOAL) + 1 }); }
                } else { list.push({ s: 'table', x: SLOTC[i] - 9, y: bandY(GOAL) + 1 }); }
            }
            G.world.lanes.forEach(function (l) { laneSprites(l, list, frame); });
            rx = Math.floor(G.t / 90) % 500; rx = rx < 250 ? rx + 16 : 516 - rx;
            list.push({ s: Math.floor(G.t / 400) % 2 && !H.reduced ? 'reeveB' : 'reeveA', x: rx, y: bandY(MED) + 1 });
            if (G.pax) {
                xs = copies(G.world.by[G.pax.band], G.pax.i, G.t);
                for (k = 0; k < xs.length; k++) { if (xs[k] + G.pax.off < W && xs[k] + G.pax.off + 8 > 0) { list.push({ s: 'favor', x: xs[k] + G.pax.off, y: bandY(G.pax.band) + 1 }); } }
            }
            if (G.mode === 'DYING') {
                deathArt(list, p, ly);
            } else if (p.band !== GOAL) {
                dx = drawX(p);
                hop = G.hopMs > 0 && !H.reduced;
                list.push({ s: hop ? 'plyH' : 'ply', x: dx, y: ly - (hop ? 2 : 0) });
                if (p.carry) { list.push({ s: 'favor', x: dx + 1, y: ly - 4 }); }
            }
            for (i = 0; i < G.lives; i++) { list.push({ s: 'life', x: 238 + i * 10, y: 0 }); }
            if (G.mode === 'HOME' && G.ms < 500 && G.homeSlot >= 0) { list.push({ s: 'stamp', x: SLOTC[G.homeSlot] - 9, y: bandY(GOAL) + 1 }); }
            if (G.mode === 'READY' && G.layon) { list.push({ s: 'layon', x: 113, y: bandY(MED) - 1 }); }
            if (G.mode === 'HOLD') { list.push({ s: 'holdb', x: 122, y: bandY(MED) - 1 }); }
            if (G.mode === 'DYING' && G.lives <= 0) { list.push({ s: 'outb', x: 98, y: bandY(MED) - 1 }); }
            H.sprites(list);
        }

        /* ----- level set-up ----- */
        function respawn() {
            G.p = { band: STARTB, x: START_X, ride: null, max: STARTB, carry: false };
            G.timeMs = LEVELS[G.level].time * 1000;
            G.hopMs = 0;
            G.lastSec = -1;
            G.buf = null; G.loiterMs = 0; G.loitered = false;
        }
        function beginLevel() {
            var lv = LEVELS[G.level];
            G.world = makeWorld(G.level, function (n) { return H.rand(n); });
            G.t = 0;
            G.filled = [false, false, false, false, false];
            G.ev = null; G.evNext = 4000; G.pax = null; G.paxNext = 5000;
            G.mode = 'BUSY';
            stopLoad();
            H.cls();
            H.then(function () {
                respawn();
                drawHud();
                drawTimer();
                render();
                say(lv.name);
                G.layon = true;
                G.mode = 'READY';
                G.ms = 0;
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
                G.extraPend = true;
                sfx([[784, 60], [1047, 60], [1319, 90]]);
            }
            drawScore();
        }

        /* ----- the crossing ----- */
        function die(cause) {
            var lv = LEVELS[G.level], msg, alt = H.rand(3) === 0, notes;
            G.lives--;
            G.msgMs = 0;
            G.mode = 'DYING';
            G.ms = 0;
            G.cause = cause;
            G.p.carry = false;
            G.p.ride = null;
            if (KINDS[cause]) { msg = KINDS[cause].hit; } else {
                msg = {
                    mud: lv.mud, sink: 'THE SHIELD DUCKED. SO DID YOUR DIGNITY.', edge: 'CARRIED OFF THE FIELD. LITERALLY.',
                    time: 'THE REEVE CALLS TIME. SIGN-IN IS CLOSED.', tent: 'THAT IS A TENT. THE TABLE IS NEXT DOOR.',
                    taken: 'THAT TABLE IS TAKEN. THE REEVE FROWNS.', lurker: 'SOMETHING LIVES UNDER THAT TABLE.'
                }[cause] || 'YOU ARE OUT.';
            }
            if (alt && ALT_HIT[cause] && (cause !== 'mud' || G.level === 0)) { msg = ALT_HIT[cause]; }
            say(msg);
            render();
            if (cause === 'mud' || cause === 'sink' || cause === 'edge') { notes = SFX_SPLASH; } else if (cause === 'lurker') { notes = SFX_LURK; } else if (cause === 'tent' || cause === 'taken') { notes = SFX_TENT; } else if (cause === 'time') { notes = SFX_TIME; } else { notes = SFX_HIT; }
            G.sfxQ = []; G.sfxWait = 0;
            sfx(notes);
        }
        function land(slot) {
            var lv = LEVELS[G.level], pts, ev = G.ev, left, bonus = '';
            if (G.filled[slot]) { die('taken'); return; }
            if (ev && ev.slot === slot && ev.kind === 'lurker' && ev.ms <= lv.lurk) { die('lurker'); return; }
            G.filled[slot] = true;
            pts = 50 + 10 * Math.floor(G.timeMs / 1000);
            if (ev && ev.slot === slot && ev.kind === 'bottle') { pts += 200; G.ev = null; G.evNext = lv.evGap; bonus = ' +BOTTLE'; }
            if (G.p.carry) { pts += 200; G.p.carry = false; bonus += ' +FAVOR'; }
            award(pts);
            left = G.filled.filter(function (f) { return !f; }).length;
            G.mode = 'HOME';
            G.ms = 0;
            G.homeSlot = slot;
            G.p.ride = null;
            flash(left ? 'SIGNED! ' + pts + bonus + '. ' + left + ' TO GO.' : 'THE LAST TABLE! ' + pts + bonus + '.', 2200);
            sfx([[784, 50], [988, 50], [1175, 80]]);
            render();
        }
        function doHop(dir) {
            var p = G.p, before = p.band, r;
            if (G.mode !== 'PLAY') { return; }
            r = hopPlayer(G.world, p, dir, G.t);
            G.hopMs = 120;
            if (G.sfxWait <= 0) { H.beep(dir === 'UP' ? 520 + (12 - p.band) * 20 : 480, 16); G.sfxWait = 30; }
            if (r.death) { die(r.death); return; }
            if (p.band < before && p.band < p.max) { p.max = p.band; award(10); if (p.band === MED) { sfx([[660, 30], [880, 40]]); } }
            if (r.goal !== undefined) { land(r.goal); return; }
            if (G.pax && !p.carry && p.band === G.pax.band) { checkPax(); }
            render();
        }
        function checkPax() {
            var xs = copies(G.world.by[G.pax.band], G.pax.i, G.t), k;
            if (G.p.band !== G.pax.band || G.p.carry) { return; }
            for (k = 0; k < xs.length; k++) {
                if (Math.abs((G.p.x + 4) - (xs[k] + G.pax.off + 4)) < 7) {
                    G.p.carry = true;
                    G.pax = null;
                    G.paxNext = 8000;
                    award(50);
                    flash('LOST FAVOR! TAKE IT TO A TABLE.');
                    sfx([[880, 50], [1175, 70]]);
                    return;
                }
            }
        }

        /* ----- per-tick updates ----- */
        function updateEvents() {
            var lv = LEVELS[G.level], free = [], i, cands, lane;
            if (G.ev) {
                G.ev.ms -= TICK;
                if (G.ev.ms <= 0) { G.ev = null; G.evNext = lv.evGap + H.rand(2500); }
            } else {
                G.evNext -= TICK;
                if (G.evNext <= 0) {
                    for (i = 0; i < 5; i++) { if (!G.filled[i]) { free.push(i); } }
                    if (free.length) {
                        i = free[H.rand(free.length)];
                        G.ev = H.rand(100) < 55 ? { slot: i, kind: 'lurker', ms: lv.lurk + 900 } : { slot: i, kind: 'bottle', ms: 5500 };
                    } else { G.evNext = 3000; }
                }
            }
            if (G.pax) {
                G.pax.ms -= TICK;
                if (G.pax.ms <= 0) { G.pax = null; G.paxNext = 6000; }
            } else if (!G.p.carry) {
                G.paxNext -= TICK;
                if (G.paxNext <= 0) {
                    cands = [5, 3, 1];
                    lane = G.world.by[cands[H.rand(3)]];
                    if (lane.duck) { lane = G.world.by[5]; }
                    G.pax = { band: lane.band, i: H.rand(lane.offs.length), off: 4 + H.rand(Math.max(1, lane.w - 12)), ms: 11000 };
                }
            }
        }
        function updatePlay() {
            var d, sec;
            G.t += TICK;
            G.blink = Math.floor(G.t / 160) % 2 === 0;
            G.timeMs -= TICK;
            if (G.hopMs > 0) { G.hopMs -= TICK; }
            d = advancePlayer(G.world, G.p, G.t, 0);
            if (d) { die(d); return; }
            if (G.timeMs <= 0) { die('time'); return; }
                if (G.p.band === MED) {
                    G.loiterMs += TICK;
                    if (G.loiterMs >= 6000 && !G.loitered) { G.loitered = true; flash('REEVE: PLAYING, OR SPECTATING?'); }
                } else { G.loiterMs = 0; }
            updateEvents();
            if (G.pax) { checkPax(); }
            sec = Math.ceil(G.timeMs / 1000);
            if (sec !== G.lastSec) {
                G.lastSec = sec;
                if (sec <= 5 && sec > 0 && G.sfxWait <= 0) { H.beep(300, 40); G.sfxWait = 50; }
            }
            drawTimer();
            if (G.msgMs > 0) { G.msgMs -= TICK; if (G.msgMs <= 0) { say(''); } }
            render();
        }
        function idleWorld() {
            G.t += TICK;
            G.blink = Math.floor(G.t / 160) % 2 === 0;
            render();
        }
        function afterDeath() {
            if (G.lives <= 0) { showOver(); return; }
            respawn();
            drawTimer();
            if (G.lives === 1) { flash('LAST LIFE. THE HEALER WAVES POLITELY.'); } else if (H.rand(10) === 0) { flash('HEALER ON THE FAR BANK. NOT HELPING YET.'); }
            G.mode = 'READY';
            G.ms = 0;
            render();
        }
        function afterHome() {
            if (G.filled.every(function (f) { return f; })) {
                award(1000);
                saveBest(false);
                G.mode = 'CLEAR';
                G.ms = 0;
                G.clearLine = false;
                say('ALL FIVE SIGNED. REEVE BONUS 1000!');
                sfx(SFX_FANFARE);
                return;
            }
            respawn();
            drawTimer();
            G.mode = 'READY';
            G.ms = 0;
            render();
        }
        function tick() {
            var b;
            if (G.extraPend && (G.mode === 'READY' || G.mode === 'PLAY')) { G.extraPend = false; flash('A STRANGER LENDS A TABARD. EXTRA LIFE.'); }
            if (G.sfxWait > 0) {
                G.sfxWait -= TICK;
                if (G.sfxWait <= 0) { pumpSfx(); }
            }
            switch (G.mode) {
                case 'CARD':
                    G.ms += TICK;
                    if (G.ms >= 4000) { beginLevel(); }
                    break;
                case 'READY':
                    G.ms += TICK;
                    idleWorld();
                    if (G.ms >= 800) {
                        G.layon = false;
                        G.mode = 'PLAY';
                        if (G.msgMs <= 0) { say(''); }
                        if (G.buf) { b = G.buf; G.buf = null; doHop(b); }
                    }
                    break;
                case 'PLAY':
                    updatePlay();
                    break;
                case 'DYING':
                    G.ms += TICK;
                    idleWorld();
                    if (G.ms >= 1500) { afterDeath(); }
                    break;
                case 'HOME':
                    G.ms += TICK;
                    idleWorld();
                    if (G.msgMs > 0) { G.msgMs -= TICK; }
                    if (G.ms >= 1000) { afterHome(); }
                    break;
                case 'CLEAR':
                    G.ms += TICK;
                    idleWorld();
                    if (!G.clearLine && G.ms >= 1200) { G.clearLine = true; say('THE REEVE SQUINTS AT THE WAIVER.'); }
                    if (G.ms >= 2400) { advance(); }
                    break;
                default:
                    break;
            }
        }
        function advance() {
            if (G.level >= LEVELS.length - 1) { showWin(); return; }
            G.level++;
            showCard();
        }

        /* ----- endings ----- */
        function showOver() {
            saveBest(false);
            G.mode = 'BUSY';
            screen('over', 88, [
                '', '', '', '', '', '', '', '', '', '', '', '',
                'OUT OF LIVES',
                '',
                'THE REEVE CLOSES THE SIGN-IN SHEET.',
                'YOU ARE A SPECTATOR NOW.',
                'THERE IS A CHAIR. IT IS A GOOD CHAIR.',
                '',
                'NEXT TIME, ARRIVE BEFORE BATTLEGAME.',
                '',
                'FINAL SCORE ' + pad(G.score, 5),
                '',
                'SPACE TO TRY AGAIN'
            ], 0, function () { G.mode = 'OVER'; });
        }
        function showWin() {
            award(2000);
            saveBest(true);
            G.mode = 'BUSY';
            screen('win', 80, ['', '', '', '', '', '', '', '', '', '', ''].concat(WIN_TEXT, ['FINAL SCORE ' + pad(G.score, 5), 'SPACE TO PLAY AGAIN']), H.reduced ? 10 : 160, function () { G.mode = 'OVER'; });
        }
        function newGame() {
            G.level = 0; G.score = 0; G.lives = 3; G.extra = false;
            G.sfxQ = []; G.sfxWait = 0; G.extraPend = false; G.buf = null;
            showCard();
        }

        /* ----- input ----- */
        function askQuit() {
            G.wasMode = G.mode;
                G.askSay = G.lastSay;
            G.mode = 'ASK';
            say('LEAVE THE FIELD?  Y OR N');
        }
        function onKey(key) {
            var go = key === 'SPACE' || key === 'ENTER';
            switch (G.mode) {
                case 'TITLE':
                    if (go) { newGame(); } else if (key === 'H') { showHow(0); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'HOW':
                    if (go) { if (G.howPage === 0) { showHow(1); } else { showTitle(); } } else if (key === 'Q') { H.quit(); }
                    break;
                case 'CARD':
                    if (go) { beginLevel(); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'OVER':
                    if (go) { showTitle(); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'PLAY':
                case 'READY':
                case 'HOME':
                case 'DYING':
                    if (KEYMAP[key]) {
                        // A press in the last stretch of the respawn pause is kept and played the moment the field goes live.
                        if (G.mode === 'READY') { if (G.ms >= 400) { G.buf = KEYMAP[key]; } } else { doHop(KEYMAP[key]); }
                    } else if (key === 'Q') { askQuit(); } else if (key === 'SPACE' && G.mode === 'PLAY') {
                        G.mode = 'HOLD';
                        render();
                        say(H.rand(8) === 0 ? 'HOLD! LUNCH. JUST KIDDING. LAY ON.' : 'HOLD!  SPACE TO LAY ON');
                    }
                    break;
                case 'HOLD':
                    if (key === 'Q') { askQuit(); } else if (go) { G.mode = 'PLAY'; say(''); }
                    break;
                case 'ASK':
                    // A sits right beside Q on a keyboard, so a stray press must not end the run.
                    if (key === 'Y') { H.quit(); } else if (key === 'N' || go) { G.mode = G.wasMode; say(G.askSay); }
                    break;
                default:
                    break;
            }
        }

        if (window.OrkpleStrict) { start.dbg = G; }   // tests only
        ART.define(H);
        H.every(TICK, tick);
        H.keys(onKey, { noRepeat: true });
        showTitle();
    }

    window.OrkpleGames = window.OrkpleGames || {};
    window.OrkpleGames.froggork = {
        title: 'FROGGORK',
        start: start,
        _pure: {
            LEVELS: LEVELS, KINDS: KINDS, makeWorld: makeWorld, judge: judge, advancePlayer: advancePlayer, hopPlayer: hopPlayer, copies: copies,
            duckState: duckState, slotAt: slotAt, SLOTC: SLOTC, TOL: TOL, HOP: HOP, W: W, TICK: TICK, START_X: START_X, PW: PW, STARTB: STARTB
        }
    };
    // State hook for the specs, attached only when the test flag is set.
    if (window.OrkpleStrict) { window.OrkpleGames.froggork._dbg = function () { return start.dbg; }; }
}());
