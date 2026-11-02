(function () {
    'use strict';
    window.OrkpleGames = window.OrkpleGames || {};

    /* ---------- constants ---------- */
    var TICK = 50;
    var CW = 5, CH = 5;
    var LANES = { REEVE: ' REEVE ', SCOUT: ' SCOUT ', SQUIRE: 'SQUIRE ', BARD: ' BARD  ' };
    var TOUCH = {
        REEVE: 'HOLD! YOU RAN INTO THE REEVE. APOLOGIZE.',
        SCOUT: 'HOLD! THE SCOUT FOUND YOU. YOU DIED.',
        SQUIRE: 'HOLD! THE SQUIRE TRIPPED YOU. SORRY!',
        BARD: 'HOLD! THE BARD GOT YOU. EXPECT A SONG.'
    };
    var RANKS = [
        [4000, 'THE TERROR OF THE SNACK TABLE'],
        [3000, 'A PERSON OF SOME RENOWN'],
        [1500, 'SOMEONE WHO OWNS GARB'],
        [500, 'A REGULAR AT THE PARK'],
        [0, 'A PEASANT WITH A LOANER SWORD']
    ];

    /* ---------- level data ---------- */
    var LEVELS = [null,
        { title: 'FIRST SIGN-IN', type: 'mult', t: 6, w: 19, en: [['REEVE', 3000]] },
        { title: 'SIGN-IN SHEET', type: 'class', t: 7, w: 12, en: [['REEVE', 3000], ['SQUIRE', 8000]] },
        { title: 'PICKING TEAMS', type: 'prime', t: 7, w: 18, en: [['REEVE', 2500], ['SCOUT', 7000]] },
        { title: 'THE REEVE\'S TENT', type: 'reeve', t: 8, w: 12, en: [['REEVE', 2500], ['BARD', 5000], ['SCOUT', 9000]] },
        { title: 'CREDIT CHECK', type: 'five', t: 8, w: 12, en: [['REEVE', 2000], ['SCOUT', 5000], ['SQUIRE', 8000]] },
        { title: 'FINAL COURT', type: 'order', t: 9, w: 12, en: [['REEVE', 2000], ['SCOUT', 4500], ['BARD', 7000], ['SQUIRE', 10000]] }
    ];
    var IV = [null,
        { REEVE: 900 },
        { REEVE: 850, SQUIRE: 1500 },
        { REEVE: 800, SCOUT: 1200 },
        { REEVE: 750, SCOUT: 1150, BARD: 1000 },
        { REEVE: 700, SCOUT: 1100, SQUIRE: 1400 },
        { REEVE: 650, SCOUT: 1000, SQUIRE: 1300, BARD: 900 }
    ];
    var BANNERS = {
        '1:3': ['MUNCH MULTIPLES OF 3', 'THE SIGN-IN SHEET IS COUNTING BY THREES.'],
        '1:4': ['MUNCH MULTIPLES OF 4', 'FOUR WEEKS TO A MONTH, ROUGHLY.'],
        '1:5': ['MUNCH MULTIPLES OF 5', '5 CREDITS REACHES 2ND LEVEL.'],
        '2': ['MUNCH LEGAL CLASSES', 'SIGN IN AS ONE OF THESE. NOT THE OTHERS.'],
        '3': ['MUNCH PRIME NUMBERS', 'HEADCOUNTS THAT WILL NOT MAKE EVEN TEAMS'],
        '4': ['MUNCH THINGS A REEVE SAYS', 'WHEN IN DOUBT, STOP AND KNEEL.'],
        '5:F24': ['MUNCH FACTORS OF 24', 'TEAM SIZES THAT FIT 24 PLAYERS EXACTLY.'],
        '5:F30': ['MUNCH FACTORS OF 30', 'TEAM SIZES THAT FIT 30 PLAYERS EXACTLY.'],
        '5:E12': ['MUNCH WHAT EQUALS 12', '12 CREDITS REACHES 3RD LEVEL.'],
        '5:E21': ['MUNCH WHAT EQUALS 21', '21 CREDITS REACHES 4TH LEVEL.'],
        '5:E34': ['MUNCH WHAT EQUALS 34', '34 CREDITS REACHES 5TH LEVEL.'],
        '5:E53': ['MUNCH WHAT EQUALS 53', '53 CREDITS REACHES 6TH LEVEL.'],
        '6': ['MUNCH REAL AMTGARD ORDERS', 'COURT STARTS SOON. ALLEGEDLY.']
    };
    var VARIANTS5 = ['F24', 'F30', 'E12', 'E21', 'E34', 'E53'];

    var PRIMES = [2, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47];
    var NONPRIMES = [1, 4, 6, 8, 9, 10, 12, 14, 15, 16, 18, 20, 21, 22, 24, 25, 26, 27, 28, 30, 32, 33, 34, 35, 36, 38, 39, 40, 42, 44, 45, 46, 48, 49, 51];

    var W_CLASS_R = ['ANTI-/PALADIN', 'ARCHER', 'ASSAS-/SIN', 'BARBA-/RIAN', 'BARD', 'DRUID', 'HEALER', 'MONK', 'PALADIN', 'SCOUT', 'WARRIOR', 'WIZARD'];
    var W_CLASS_W = [
        'DENTIST ~ NO CLASS TREATS TEETH. YET.',
        'ACCOUN-/TANT ~ THAT IS WHAT MINISTERS ARE FOR.',
        'PIRATE ~ THAT IS A PERSONA, NOT A CLASS.',
        'NINJA ~ THAT IS AN ASSASSIN WITH A HOOD.',
        'VIKING ~ THAT IS YOUR GARB, NOT YOUR CLASS.',
        'CLERIC ~ WRONG GAME. PUT THE DICE AWAY.',
        'ROGUE ~ WRONG GAME. LEAVE THE DICE IN CAR.',
        'SORCER-/ER ~ ASK A WIZARD. BRING A CHAIR.',
        'KNIGHT ~ THAT TAKES A BELT AND SOME YEARS.',
        'LAWYER ~ UNOFFICIAL. EVERY PARK HAS SEVEN.',
        'MIME ~ NOBODY HEARD THE SPELL.',
        'DUCK ~ THAT IS A TACTIC, NOT A CLASS.',
        'HERO ~ NOT ON YOUR FIRST DAY.',
        'BARISTA ~ VITAL SUPPORT. STILL NOT A CLASS.',
        'PLUMBER ~ THAT IS A DIFFERENT QUEST.',
        'TANK ~ THAT IS A LIFESTYLE, NOT A CLASS.'
    ];
    var W_REEVE_R = ['HOLD', 'LAY ON', 'WEAPON/CHECK', 'WATER/BREAK', 'LAST/GAME', 'NEW/TEAMS', 'EASE UP', 'CALL/SHOTS', 'TAKE A/KNEE', 'ONE/MORE', 'TEAMS/READY?'];
    var W_REEVE_W = [
        'ROLL/D20 ~ DICE ARE A DIFFERENT HOBBY.',
        'NO/HOLDS ~ THERE ARE ALWAYS HOLDS.',
        'LAG ~ NOT AN ACCEPTED EXCUSE.',
        'CRIT ~ NO SUCH THING. TAKE THE HIT.',
        'SAVE/GAME ~ THERE IS NO SAVE. THERE IS SUNDAY.',
        'UNDO ~ NOT AVAILABLE ON THE FIELD.',
        'REROLL ~ SIGN IN NEXT WEEK INSTEAD.',
        'PAUSE ~ IT IS CALLED A HOLD.',
        'SKIP/SONG ~ THAT IS THE BARD. YOU CANNOT.',
        'GG EZ ~ HONOR IS ALSO A STAT. YOU LOST IT.',
        'REFUND ~ DUES ARE FINAL. SO ARE YOU.',
        'TAX/SEASON ~ NOT EVEN THE SCRIBE SAYS THAT.',
        'RESPAWN/PLZ ~ ASK NICELY AND WAIT LIKE EVERYONE.',
        'OBJEC-/TION ~ THIS IS A FIELD, NOT A COURTROOM.',
        'PAY ME ~ BRIBES ARE HANDLED AT FEAST.',
        'NICE/DODGE ~ THE REEVE IS NOT YOUR HYPE MAN.'
    ];
    var W_ORDER_R = ['ROSE', 'SMITH', 'LION', 'OWL', 'DRAGON', 'GARBER', 'WARRIOR', 'JOVIUS', 'ZODIAC', 'MASK', 'HYDRA', 'GRIFFIN', 'CROWN'];
    var W_ORDER_W = [
        'BADGER ~ PETITION YOUR MONARCH.',
        'SPATULA ~ A FEAST AWARD AT BEST.',
        'STAPLER ~ THE SCRIBE WOULD LIKE THIS ONE.',
        'TEAPOT ~ SHORT AND STOUT, BUT NOT REAL.',
        'PIGEON ~ IT JUST FOLLOWS THE SNACKS.',
        'SPORK ~ NOT YET. BE PATIENT.',
        'WAFFLE ~ YOU ARE THINKING OF BREAKFAST.',
        'BUCKET ~ THAT IS A HELM ON A BUDGET.',
        'SOCK ~ WE ALL LOST ONE. NO ORDER FOR IT.',
        'MILDEW ~ THAT IS JUST WHAT TENTS HAVE.',
        'DUCT/TAPE ~ HOLY, BUT NOT AN ORDER.',
        'CARDI-/GAN ~ NOT PERIOD. NOT AN ORDER.',
        'OTTER ~ ADORABLE. NOT AN ORDER.',
        'BEAN ~ THIS IS NOT A CHILI COOK-OFF.',
        'LEDGER ~ THAT IS A PRIME MINISTER\'S FRIEND.',
        'NAP ~ EARNED, BUT NOT AWARDED.'
    ];
    var EXPR = {
        E12: { r: '3X4 2X6 7+5 9+3 8+4 20-8 15-3 24/2 36/3 6+6', w: '3X5 2X7 7+6 9+4 8+5 20-9 15-4 24/3 36/4 6+7 4X4 30/3' },
        E21: { r: '3X7 7X3 14+7 15+6 20+1 30-9 25-4 42/2 63/3 11+10', w: '3X6 4X5 7X4 14+8 15+7 20+2 30-8 25-5 42/3 63/7 5X4 11+9' },
        E34: { r: '2X17 17X2 30+4 28+6 25+9 40-6 50-16 68/2 20+14 27+7', w: '3X12 5X7 6X6 30+5 28+7 25+8 40-5 50-15 70/2 3X11 27+8 20+15' },
        E53: { r: '50+3 40+13 60-7 45+8 30+23 70-17 100-47 106/2 26+27 48+5', w: '9X6 6X9 7X8 50+4 40+14 60-6 45+9 30+22 70-16 108/2 26+28 48+6' }
    };

    /* ---------- pure logic ---------- */
    function evalExpr(s) {
        var m = /^(\d+)([X+\-\/])(\d+)$/.exec(s);
        if (!m) { return NaN; }
        var a = +m[1], b = +m[3];
        return m[2] === 'X' ? a * b : m[2] === '+' ? a + b : m[2] === '-' ? a - b : a / b;
    }
    function blankTile() { return { a: '', b: '', ok: false, blank: true }; }
    function numTile(v, ok) { return { a: String(v), b: '', ok: !!ok, v: v }; }
    function wordTile(spec, ok) {
        var q = '', i = spec.indexOf(' ~ ');
        if (i >= 0) { q = spec.slice(i + 3); spec = spec.slice(0, i); }
        var p = spec.split('/');
        return { a: p[0], b: p[1] || '', ok: !!ok, q: q };
    }
    function exprTile(s, ok) { return { a: s, b: '', ok: !!ok, v: evalExpr(s) }; }
    function tileKey(t) { return t.a + '/' + t.b; }
    function isTarget(t) { return !!t && t.ok === true && !t.blank; }
    function range(a, b) { var o = [], i; for (i = a; i <= b; i++) { o.push(i); } return o; }

    // Every candidate tile for a level, plus the board's target and wrong counts.
    function pools(level, variant) {
        var L = LEVELS[level], T = [], Wr = [], must = [], i, n, N;
        if (L.type === 'mult') {
            n = variant;
            for (i = 1; i <= 12; i++) { T.push(numTile(n * i, true)); }
            for (i = 2; i <= n * 12; i++) { if (i % n !== 0) { Wr.push(numTile(i, false)); } }
            return { T: T, W: Wr, must: must, nT: 6, nW: 19 };
        }
        if (L.type === 'prime') {
            for (i = 0; i < PRIMES.length; i++) { T.push(numTile(PRIMES[i], true)); }
            for (i = 0; i < NONPRIMES.length; i++) { Wr.push(numTile(NONPRIMES[i], false)); }
            return { T: T, W: Wr, must: ['1', '9', '21'], nT: 7, nW: 18 };
        }
        if (L.type === 'five') {
            if (variant === 'F24' || variant === 'F30') {
                N = variant === 'F24' ? 24 : 30;
                for (i = 1; i <= N; i++) { if (N % i === 0) { T.push(numTile(i, true)); } }
                for (i = 5; i <= 40; i++) { if (N % i !== 0) { Wr.push(numTile(i, false)); } }
                return { T: T, W: Wr, must: must, nT: 8, nW: 17 };
            }
            var ex = EXPR[variant], r = ex.r.split(' '), w = ex.w.split(' ');
            for (i = 0; i < r.length; i++) { T.push(exprTile(r[i], true)); }
            for (i = 0; i < w.length; i++) { Wr.push(exprTile(w[i], false)); }
            return { T: T, W: Wr, must: must, nT: 8, nW: 12 };
        }
        var R = L.type === 'class' ? W_CLASS_R : L.type === 'reeve' ? W_REEVE_R : W_ORDER_R;
        var X = L.type === 'class' ? W_CLASS_W : L.type === 'reeve' ? W_REEVE_W : W_ORDER_W;
        for (i = 0; i < R.length; i++) { T.push(wordTile(R[i], true)); }
        for (i = 0; i < X.length; i++) { Wr.push(wordTile(X[i], false)); }
        return { T: T, W: Wr, must: must, nT: L.t, nW: L.w };
    }
    function shuffle(a, rng) {
        var i, j, t;
        for (i = a.length - 1; i > 0; i--) { j = rng(i + 1); t = a[i]; a[i] = a[j]; a[j] = t; }
        return a;
    }
    function pick(list, n, rng, mustKeys) {
        var pool = list.slice(), out = [], i, k;
        mustKeys = mustKeys || [];
        for (k = 0; k < mustKeys.length; k++) {
            for (i = 0; i < pool.length; i++) { if (pool[i].a === mustKeys[k]) { out.push(pool.splice(i, 1)[0]); break; } }
        }
        shuffle(pool, rng);
        while (out.length < n && pool.length) { out.push(pool.shift()); }
        return out;
    }
    // A flat array of 25 tiles, index = row * 5 + col.
    function makeBoard(level, variant, rng) {
        var p = pools(level, variant), tiles = [], i;
        tiles = tiles.concat(pick(p.T, p.nT, rng), pick(p.W, p.nW, rng, p.must));
        while (tiles.length < 25) { tiles.push(blankTile()); }
        for (i = 0; i < tiles.length; i++) { tiles[i] = copyTile(tiles[i]); }
        return shuffle(tiles, rng);
    }
    function copyTile(t) {
        var o = {}, k;
        for (k in t) { if (t.hasOwnProperty(k)) { o[k] = t[k]; } }
        return o;
    }
    function ruleNumber(level, variant) {
        if (level === 1) { return variant; }
        if (variant === 'F24') { return 24; }
        if (variant === 'F30') { return 30; }
        if (variant === 'E12') { return 12; }
        if (variant === 'E34') { return 34; }
        if (variant === 'E53') { return 53; }
        return 21;
    }
    function smallestFactor(x) {
        var p;
        for (p = 2; p * p <= x; p++) { if (x % p === 0) { return p; } }
        return x;
    }
    function holdMessage(level, variant, t) {
        var L = LEVELS[level], X, N, p;
        if (t.q) { return 'HOLD! ' + t.q; }
        X = t.v;
        N = ruleNumber(level, variant);
        if (L.type === 'mult') { return 'HOLD! ' + X + ' IS NOT A MULTIPLE OF ' + N + '.'; }
        if (L.type === 'prime') {
            if (X === 1) { return 'HOLD! 1 IS NOT PRIME. IT IS JUST EARLY.'; }
            p = smallestFactor(X);
            return 'HOLD! ' + X + ' = ' + p + ' X ' + (X / p) + '. ' + (X / p) + ' TEAMS OF ' + p + '. FAIR.';
        }
        if (variant === 'F24' || variant === 'F30') {
            if (X > N) { return 'HOLD! ' + X + ' PLAYERS? THERE ARE ONLY ' + N + '.'; }
            return 'HOLD! ' + N + ' BY ' + X + 'S LEAVES ' + (N % X) + ' PLAYERS OUT.';
        }
        return 'HOLD! THAT TILE IS ' + X + ', NOT ' + N + '.';
    }
    function banner(level, variant) {
        return BANNERS[level === 1 || level === 5 ? level + ':' + variant : String(level)];
    }
    function onBoard(c, r) { return c >= 0 && c < CW && r >= 0 && r < CH; }
    function sgn(x) { return x > 0 ? 1 : x < 0 ? -1 : 0; }
    function reeveStart(c, r) { return { hdir: c <= 2 ? 1 : -1, vdir: r <= 2 ? 1 : -1 }; }
    function reeveStep(e, ok) {
        var c = e.c, r = e.r, h = e.hdir, v = e.vdir;
        if (ok(c + h, r)) { return { c: c + h, r: r, hdir: h, vdir: v }; }
        h = -h;
        if (ok(c, r + v)) { return { c: c, r: r + v, hdir: h, vdir: v }; }
        if (!onBoard(c, r + v)) {
            v = -v;
            if (ok(c, r + v)) { return { c: c, r: r + v, hdir: h, vdir: v }; }
        }
        return { c: c, r: r, hdir: h, vdir: v };
    }
    function scoutStep(e, pc, pr, ok) {
        var dx = pc - e.c, dy = pr - e.r, tries, i;
        if (Math.abs(dx) >= Math.abs(dy)) { tries = [[sgn(dx), 0], [0, sgn(dy)]]; } else { tries = [[0, sgn(dy)], [sgn(dx), 0]]; }
        for (i = 0; i < 2; i++) {
            if ((tries[i][0] || tries[i][1]) && ok(e.c + tries[i][0], e.r + tries[i][1])) { return { c: e.c + tries[i][0], r: e.r + tries[i][1] }; }
        }
        return { c: e.c, r: e.r };
    }
    var DIRS = [[0, -1], [0, 1], [-1, 0], [1, 0]];
    function neighbours(c, r, ok) {
        var o = [], i;
        for (i = 0; i < 4; i++) { if (ok(c + DIRS[i][0], r + DIRS[i][1])) { o.push({ c: c + DIRS[i][0], r: r + DIRS[i][1] }); } }
        return o;
    }
    // ctx: { tiles, pc, pr, eaten, ok }. Returns { eat: true } or the new cell.
    function squireStep(e, ctx, rng) {
        var tiles = ctx.tiles, here = tiles[e.r * 5 + e.c], best = null, bd = 99, i, c, r, d, dx, dy, nb;
        if (ctx.eaten < 3) {
            if (isTarget(here)) { return { eat: true, c: e.c, r: e.r }; }
            for (r = 0; r < CH; r++) {
                for (c = 0; c < CW; c++) {
                    if (!isTarget(tiles[r * 5 + c]) || (c === ctx.pc && r === ctx.pr)) { continue; }
                    d = Math.abs(c - e.c) + Math.abs(r - e.r);
                    if (d < bd) { bd = d; best = { c: c, r: r }; }
                }
            }
            if (best) {
                dx = sgn(best.c - e.c); dy = sgn(best.r - e.r);
                if (dx && ctx.ok(e.c + dx, e.r)) { return { c: e.c + dx, r: e.r }; }
                if (dy && ctx.ok(e.c, e.r + dy)) { return { c: e.c, r: e.r + dy }; }
                return { c: e.c, r: e.r };
            }
        }
        nb = neighbours(e.c, e.r, ctx.ok);
        if (!nb.length) { return { c: e.c, r: e.r }; }
        i = rng(nb.length);
        return nb[i];
    }
    function bardStep(e, ok, rng) {
        var nb = neighbours(e.c, e.r, ok), f = [], i;
        if (!nb.length) { return { c: e.c, r: e.r, lc: e.lc, lr: e.lr }; }
        for (i = 0; i < nb.length; i++) { if (!(nb[i].c === e.lc && nb[i].r === e.lr)) { f.push(nb[i]); } }
        if (f.length) { nb = f; }
        i = rng(nb.length);
        return { c: nb[i].c, r: nb[i].r, lc: e.c, lr: e.r };
    }
    // Replacement tile for a wrong tile the Bard leaves, or null.
    function bardRewrite(tiles, level, variant, wantTarget, rng) {
        var p = pools(level, variant), used = {}, i, c;
        for (i = 0; i < tiles.length; i++) { if (!tiles[i].blank) { used[tileKey(tiles[i])] = true; } }
        c = (wantTarget ? p.T : p.W).filter(function (t) { return !used[tileKey(t)]; });
        if (!c.length) { return null; }
        return copyTile(c[rng(c.length)]);
    }
    function dist(c, r, pc, pr) { return Math.abs(c - pc) + Math.abs(r - pr); }
    // ctx: { pc, pr, taken: [{c, r}] }
    function pickSpawn(ctx, rng) {
        var min, c, r, out;
        for (min = 3; min >= 2; min--) {
            out = [];
            for (r = 0; r < CH; r++) {
                for (c = 0; c < CW; c++) {
                    if (c !== 0 && c !== 4 && r !== 0 && r !== 4) { continue; }
                    if (dist(c, r, ctx.pc, ctx.pr) < min || taken(ctx.taken, c, r)) { continue; }
                    out.push({ c: c, r: r });
                }
            }
            if (out.length) { return out[rng(out.length)]; }
        }
        return null;
    }
    function taken(list, c, r) {
        var i;
        for (i = 0; i < list.length; i++) { if (list[i].c === c && list[i].r === r) { return true; } }
        return false;
    }
    // ctx: { tiles, pc, pr, taken }
    function pickFeast(ctx, rng) {
        var out = [], c, r;
        for (r = 0; r < CH; r++) {
            for (c = 0; c < CW; c++) {
                if (ctx.tiles[r * 5 + c].blank && dist(c, r, ctx.pc, ctx.pr) >= 2 && !taken(ctx.taken, c, r)) { out.push({ c: c, r: r }); }
            }
        }
        return out.length ? out[rng(out.length)] : null;
    }
    function rankFor(score) {
        var i;
        for (i = 0; i < RANKS.length; i++) { if (score >= RANKS[i][0]) { return RANKS[i][1]; } }
        return RANKS[RANKS.length - 1][1];
    }
    function creditsLine(level) {
        return level + (level === 1 ? ' CREDIT' : ' CREDITS') + ' EARNED. ' + (53 - level) + ' TO GO.';
    }
    function p5(n) { var s = String(Math.max(0, Math.min(99999, n))); while (s.length < 5) { s = '0' + s; } return s; }
    function padLabel(s) {
        var left = Math.floor((7 - s.length) / 2), o = '';
        while (o.length < left) { o += ' '; }
        o += s;
        while (o.length < 7) { o += ' '; }
        return o;
    }
    function ordinal(n) { return n + (n === 1 ? 'ST' : n === 2 ? 'ND' : n === 3 ? 'RD' : 'TH'); }
    function loadBest(v) {
        if (!v || typeof v !== 'object' || typeof v.score !== 'number' || !isFinite(v.score) || v.score < 0 || Math.floor(v.score) !== v.score) { return null; }
        var l = typeof v.level === 'number' && isFinite(v.level) ? Math.floor(v.level) : 1;
        return { score: v.score, level: Math.max(1, Math.min(6, l)), won: v.won === true };
    }

    /* ---------- static screens ---------- */
    var HOW1 = [
        'HOW TO PLAY                         1/2',
        '',
        'YOU ARE A MUNCHORK.',
        'YOU ARE HUNGRY.',
        '',
        '         W A S D',
        '         OR ARROWS: MOVE',
        '', '', '',
        '   SPACE     MUNCH',
        '',
        '   Q   QUIT',
        '',
        'THE TOP ROW SAYS WHAT IS EDIBLE. EAT',
        'EVERYTHING THAT FITS. ON MULTIPLES OF 3:',
        '',
        '         GOOD BITE.          HOLD! LOSE',
        '         POINTS.             A LIFE. YOU',
        '                             LEARN WHY.',
        '',
        'YOU HAVE 4 LIVES. SOME BATTLEGAMES',
        'GIVE YOU FEWER.',
        'PRESS RETURN'
    ];
    var HOW1_INV = [[1, 17, '       '], [1, 18, '  12   '], [1, 19, '       '], [21, 17, '       '], [21, 18, '   7   '], [21, 19, '       ']];
    var HOW1_ART = [{ s: 'tHow1', x: 0, y: 0 }, { s: 'mkChompN', x: 26, y: 136 }, { s: 'mkDeadN', x: 166, y: 136 }];
    var HOW2 = [
        'THE FIELD                           2/2',
        '',
        'TOUCHING ANYONE COSTS A LIFE.',
        '',
        '     WALKS THE FIELD, ROW BY ROW.',
        '     VERY THOROUGH.',
        '',
        '     COMES STRAIGHT FOR YOU.',
        '',
        '',
        '     HELPS. EATS YOUR SQUARES.',
        '     NO CREDIT. HE MEANS WELL.',
        '',
        '     RETELLS SQUARES HE PASSES.',
        '     CHECK THEM AGAIN.',
        '',
        '     NOBODY FIGHTS AT FEAST, BUT',
        '     THE FOOD RUNS OUT.',
        '',
        '     SOMEONE IS ARRIVING HERE.',
        '', '', '',
        'PRESS RETURN'
    ];
    var HOW2_INV = [[5, 3, 'REEVE'], [5, 6, 'SCOUT'], [5, 9, 'SQUIRE'], [5, 12, 'BARD'], [5, 15, 'FEAST'], [5, 18, 'SOON']];
    var HOW2_ART = [{ s: 'tHow2', x: 0, y: 0 }];
    var SCENE1 = [
        'THE LESSER CHRONICLES OF THE MUNCHORK',
        'ACT I: THE WEAPON CHECK',
        '---------------------------------------',
        '', '', '', '', '', '', '', '', '',
        'THE REEVE ASKS TO SEE YOUR SWORD.',
        '"THIS IS ALMOST ENTIRELY BITE MARKS,"',
        'THE REEVE SAYS.',
        '',
        '"IT WAS LIKE THAT WHEN I ATE IT,"',
        'YOU EXPLAIN.',
        '',
        'THE SWORD PASSES. NOBODY KNOWS WHY.',
        'THE REEVE WRITES SOMETHING DOWN.',
        '',
        'THE REEVE IS NOW MISSING A PEN.'
    ];
    var SCENE2 = [
        'THE LESSER CHRONICLES OF THE MUNCHORK',
        'ACT II: THE POTLUCK',
        '---------------------------------------',
        '', '', '', '', '', '', '', '', '',
        'THE SIGN-UP SHEET SAID BRING A SIDE.',
        'YOU DID NOT BRING A SIDE.',
        'YOU ATE A SIDE. THEN ANOTHER SIDE.',
        'THEN THE TABLE, WHICH HAD FOUR SIDES.',
        '',
        'THE SQUIRE CAME TO CLEAN UP AND FOUND',
        'NOTHING LEFT TO HELP WITH.',
        'HE HAS NEVER BEEN SO HAPPY.',
        '',
        'THE BARD HAS WRITTEN IT ALL DOWN.',
        'IN HIS VERSION, THERE WERE EIGHT SIDES.'
    ];

    /* ---------- pixel art ---------- */
    // '#' lit, 'o' dark over lit, '.' clear. Figures are 11x8: one lane row of a tile is 49x8 pixels.
    function neg(rows) { return rows.map(function (r) { return r.replace(/#/g, 'o'); }); }
// NUMBER MUNCHORKS art. Paste between the marked points of games/munchorks.js. See munchorks-art.md.
/* ---------- full art, packed: [w, h, data] ---------- */
var PACKED = {
    tTitle: [280, 192, '}}}}}?eADweAeDwADwf/wD//wf/w?B&/AH4/A/H4AH4//4H//4//4%D/+*Bd3%hgEIhghkIAEIgAIE!IgAI#B3d3dg)H+//4$wAEAQAQGAYEAqGACqsAqG!f//8ABw)UIiVU$YAC$HA4CAVHAFVQAVH!VUzMzI1VV#H/n4#H$M&BAg!FBABVUAFBABgAVVVAP//w!N3diACIj$G(/#KAgAqoAKAgB!RER#iQ!NVYAVVVQ/4!D(/%Q&QDVV#VVVVY!czIiETMiVU!Bg-B&B!REREREREz%VV#FVS!o4%CAo!vCAD+!vCAC!Ko)UR!ERE!BA/Xn4/A/H9/X4/g8H6!/g8ABREEREURER#I-E&B%BQAB#BQAgAgCqqqoAI!FVERERERERA/I/4/A/H4/P4/q8n6qg/v4gAVV#FVURQ!Cq!Kq#CA/D/4/A/H4/H4/q8H6qA/v4!Kq%Cqg$ERRERERE#g&h#KBgAqgAM,BE%oAgAqqqo&Q#F#FAQBV!BE=VV*Y!f#c,DF=Cq:B!FQ#B%B}#wAF!Cg!fFAF/w#g}#EQ%QEQE%Q`hAEAM!k!EAg!k!IhAh{FABQBVRBQABQFVRBVVUFAF*w<cADSDVSDSADSdVSDVVQcAc*w<FQBUBVUBUABUFVUBVVUFQFQ)w<CgAoAqoAo!oCqoAqqoCgCg)w$B+!B+B+AH4H4AB+AH//4H4AfgB//gB//+AH4Afg!B+#D/!D/D/AP8P8AD/AP//8P8A/wD//wD///AP8A/w#/#CBgACBiBgIGIGACBgI!GIGAgYC!YC!BgIGAgY!CBg$w#wAwADAD#w#DAD!M#M$wAD!M!Dgw$YAcAQAQABABg!Rw!BAB!Ec!G$YABHAEcADAQ$MA8AIAI!gAw!Lw#gAgAC8!D$M!vAG/gDgI$GAg)Y!CAKg&gCoBgAKgG!IAojwB&D,M$H4(B8AwAHwD$aAQB&B/*G$Fc(BUAQAFQB!wAUAMBw&/*D%GB*I$gAQB!OBgQ<Bg$Dq>K$Kg=w$B+g=Ggg!fo=c%V!/)Pw$FAwD/lQ!D/D/D/D/AP8P8Pz/D/#P/g/w/wD/D/4PBP/wQAb+%D/L/L/D/AP8P8v//D/#P///w/wD/D///KP/ygAP//o#D/H/H/D/AP8P8X//D/#P///w/wD/D///Gv/xoAF//8#D/H/H/D/AP8P8T//D/#P///w/wD/D///FP/zQ!//2w!D/D/D/D/AP8P8G//D/#P8A/w/wD/D/D/AP/+!BgD8w<D*q)I$Bg!6oBQ%B*B*f)E%w!f8&D%Pw$w$H4!V)HAI#YAflUAQ%eQ#4%E?Q4ADww%I%6g*M$IG.wG!gGAgY)fo%E#E%D)B#QD!QD!MAZ&FQAD*DAPwB%wD8%B!cBgAH8-Q!5&Q4!g$EOAOQ$gABD!MAWQ*w!K&w)M!Cg&DAY#Cg#B#BAQABGgE!BAQ!EAE!QE!RoB!EAEBAc#Ro!D/wAD/wP//1P/AD/wP///P/A/8D//9T/wP/P/A/4//+BQ#B4!B4!B4AHgAB4#HgHgAe#eAB4AHgHgAc!Bk&f}.o&f}-B6}?BU}_B}@Hw!/////////gP/////8H///////wB///wf////w&O//g/////////wf/////8H///////4D///wf/////g%JKqg*dw%DY(Pe#Ng$P/&Cqg*Pg%D4(H8#Pg-BFVAP////////9///////f////////f///9////+P/%INVA/////////////////////////////////////g%O//gw}(Bw&Hw}?B}}}<D$C};f#///4}.f!P/9//gAG*1q,HZmZ4-wB/#H8AY)Dltg*M5nM+,DOHw$fDm)NYOw*+qBXl-x+%D8Y)YoL4)B/aALxx!B(O4&zg(B70X+)HOEQ5q/wAHwAI$B4%B8$C#DXUV/$O#OMcY4wO4AccAE%+%Dw$E#PqkG50#9*IABibAP%Hw$e%O#czmMY/!D44!OfB8Hwe4DQ4gHgB/!B8#B8AEH!8#xjCKILgAELc!GXJ0P4XwH8NcE4eIj4AP#PgB+I4B0$ggggg!aR3)BAfUXsAcB/H+AD4!+AD7HGH$58HwfLgB3Rbh$C#B/gX8CGcAs#8ADwABBoaOI#ZcnQ/vAHuQ4/w#C#HruE4CLgAYOCAEADACDgwGZI)EAOMY4e4#B#OGGOcAFyAwY/4$/4wYDy<I$B&IAYKcAgz9+#D9+YIDl&I$OfN8O4$gAPwfB8EhGOBAnAHEADHAHIEuGW%I$GXX0Hw$gAIkXL8MZDDhBMABgACMABkEcMRw$QI%yAB#CAQAP+AG#Ch6BYAc0ABZwA0CwUEP$QI%g#GfB/nwQ!M#BQeAQA+Q!T4AQDgoBw//4AgE*Zg8AIPg!I!CgoMCAB7CACHsACBBQgHiIh8f4fO!f/8ADvd3d3dw!Q#gY!gBBI!kEAIABggA//+DADgxwBgADwMY)w$OACACYiACJiACAH!IiIn////+AB///wDoiIiIiIjggD4!J!g!I!g!IAJ!H///////+P/////z///////+f/8!QGgBACAkABICAEAWBAE|IAQBgBQBBUABUEAUAYBeC|Qd!wA4Bj4!+MA4AQADx//////////////////////////g#oA8A/4!/4B4AQCGAf////////////////////////+AfoAQAfAbw!ewHw!CBwf////////////////////////+!IAIAN99g!N99g!Io}AQg#HCHAwYHCH}-B98AwYB98#oO}A30$C!wYAC$EBg}AE(wY&s+}AH6<J/g}Bzw:JwBg}AJ+:Hx+}ABwPg$H/+%2YBg}+AB8CAP//////ACD4}&fCfA8EPxA8DiHAY}!+#B/uB4HgeBH4C}(HBghCEIQhCEYH!+}A/wAcB*IBwABw}#7CASBIEgSBIOOQge}A/wD/DASBIEgSBIGPuCpw}4AG8AgMAwDAMAwQBViI}#NgAgMAwDAMAwQAchQ}A4AZ;TY}!Mx!Q)gAMbm}AfwggAQ)gAYBxw}ANAw.UQe}AfzAYAI(B!oEBw}ACAMBo(BMB0S}!QEAEBg)MBgB}!/kADAE!SSSQACAHEQ}!E!DAE!kkkkACAM}$Hg%BbbbY#NER}!s$iAC2222AEKo%qqqqqqqqqqqqqqqqqqqqqqqqgAYI#jAFtttsgEMRERg}A4$B4CSSSSA6o&qqqqqqqqqqqqqqqqqqqqqqqqgAgI$/7bbbbf4FRERg}(H//////I}@BERERO}:Ig$Bw}AE-BUREQO}!E,K$BBw}AC-RERES}#D*CI$EG}!B-VMREQBw}!g)CgAE#G}#w,RmREYBw}!Y)i!Q!Q}#P8*BRmRE/+}A/4H)q!UAFAB}!lzg)ERMRPP+}A/SA4&Ii$VQB}!UAc)UREQiw}#2AH&Kg#Faw}#x4D4&BURE6D/+}A/sAK//////91VVVQfgBw}AP4KnwAH///+FVK!f+}A/wANAP/4$//g#Bw}E!Ng!B///g)f////////////////////////+AVEAH.Dg!f////////////////////////+AbdAHHwAF///h!f!f+B//////////////////////////gqx!/wAHg!P!f4DgBy|QRTAB6CCDw!eCCC8Af+E|I/7ADCCCAY!wCCCG!B3////////3////////////////+IOO&D/+}(BwAOCD$Y!w#G.I,Y%Bw!Kh4!Dz/+e#8Af+(Q.H/8#Bw+AIg99d/g!P9194!B/w%Pw%G%YAD4&FAF998#B999!f+AP4$QPw$B/g#H/8%+ANg!g&I$B/w%PwP4#GAf4$D4$//wf////////////////wH/////wP////+Af//////////8!H&P////g:P4%f4}}$P4&P////g}f//+A//////////////////////////////////////////////8AP4}.f//+~~~~}['],
    tHow1: [280, 192, '~~}}=//////////////////////////////////////////////8}}}}}&//////////////////////////////////////////////8}}}}}}:P//////////w})P//////////w})D//////////}*D//////////},iIiIiIiIiI},EREREREREQ},qqqqqqqqqo},VVVVVVVVVU},qqqqqqqqqo},VVVVVVVVVU},qqqqqqqqqo},VVVVVVVVVU},qqqqqqqqqo},VERERERERU},qIiIiIiIio},V!B/+#U},qgAP//wAIo},VwAeAB4AYU},qYAw!MAwo},VsDeAB7BoU},qmDPgHzDIo},VDEuYZ0mAU&D/+}AqBkfwP4sAo&CAC}AVA4xwOMYAU&B/8}AqQYBgGAQQo(C}!VQAx4eMAQU(H}!q!cQI4!o(F}!VIASQJIAgU(Ig}AqMANoWwBgo(Yw}AVGACABADAU(Pg}AqDlxrWMOAo(fw}AVB973vd8AU},qAYKUpRwAo&B/8}AV!EIQg!U&B/8}AqBFEIQlGAo&D/+}AVDF#FHAU$P/4!//g{qGg$Jgo$IAKACgAg{VMw$YwU$H/x/8f/|qIYg!IwQo%E#B}VAIg!IgAU%MAfwBg|q!f//w!o%4APgA4|V!f//w!U$BwAYwAc|qI&Qo(Ig}AVM&wU$BwAF!c|qG%Bgo%4AH!4{LVDg$HAXQ$MACABg{NCB4$eAuw#H7x/8e/{H1Af#D4Afg#H/x/8f/{B4AG#BgA+$P/7/+//g{9ABB/+C!8$P/7/+//g{eAGA/8BgA4},PAeBwOB4Aw},H)g},Dxbu0e7oM}-BYPdIh3AY}.9VVVVVQ8}.eKqqqqh4}.PVVVVVDw%P//////////_HiqqqqHg%I*B_D9VVVUf&H/////////+_B8qqqo+};3VVVRs};bKqqjY};NxVVGw};GyqqNg};DYVUb}<BwiIO}.////H7vj////})////////////}*RIkRxEceIg}(CqAbIkSJEiRJABo!H/////////+@BVAEACDwA+PCgAQ!H/////////+@C!OABAI4APF%P//////////@B!EACg#F&P//////////[gAEABAI4AMF}*Q!PgjwA+FCg$P/+}#RIkSJEiSJ%IAC}AQARHERxEcRIg$H/8}Ag///////////+}&BA///////////+}&C}@BV-BQ}%Cq.o~},H/8}>H/8}>P/+}>P/+~~~~~~~~~~~~~~~~~~~}}}<'],
    tHow2: [280, 192, '~~}}=//////////////////////////////////////////////8}}}}}&//////////////////////////////////////////////8~~}}[cg}?Tg}=/wP}(D/////4#/wP}:I!BAIP}(D/////w!BAID}=eAHsg}<eAHvg}<eAHg}(D/////w!eZng}(C%I#Zg})B/////4#Zg}?Whg}=GAHg}=Ppew}(B/////4!JmZw}(C%I!QAB})D/////w!Q}@g}-I(o},B4(/ge})D3////w!3ge})Bw$I!B54})D/////4!B54}*I)OBg}>fBg}>xhQ}>gxY}=BAYc}=BAMe}=CAGK},CBgM!H/AF},DA/4!F/GF},BxXU!ISE})O7u44$IS}*O7u4BY0#S!g}&O7u45AE!BtBc}(O7u5xQU!J/HAg}*DBnM!IABc},CA/4$HF}.Yw%F}@K}@e}=H/4c}=EMJY}=Dzww}=Dzxg}=Dzw}[D}(B/////#D/gH}(B$B#H/wF})/////#EAQB}-BB#4AP};M!4API}-BAM!4APK}-CAM!+c/C}:M!H/wQ}-C$GcwU}-B$H/wE}=H/w}.BAD$Ig}-CAD!fh5I}:D!YSFs}-CAD#MD4}-B&H8}:M%C}-BAM!YMAC}-CAM!ceP+})/////M!ESP/}(B#C&FU}(B/////#Dz1V}=Dzw}=BwTADg}<z8ADg};Bvsw})P/+AP/+!0D4})IACAIAC!QAMAg}&H/8AH/8!H/4Eg}<MAcF}.G#DJgJ},EAF$2Aa},GAC$2AW},DAB$IAk},5gY%eo},BAY#E2fI},7gQ#EEB}-GAc$MI}-EAM$Gc}?G2I}>Dg}?CWI}(H/8AH/8#Bs})IACAIAC!EAhw}(P/+AP/+!EAvw}=D/2}>D/}}}#g#C};BQ#F};CI#Ig}}}&CI#Ig}:BQ#F}<gH4AC#B+}-P8%D/}-YG%GBg},QC%EAg},Q&E}.YF%GBQ},Pxg$D8Y},H9w$B/c}-Pg%D4}*gAGwC$Bs})BQ#F};CI#Ig}}}&CI#Ig}:BQ#F}<g#C}}}}}}[CSSSSSQ}}}&CSSSSSQ}:C%Q}}}&C%Q!GMY})CAGMYAQ!Pe8},Pe8}<C%Q!JSk})CAJSkAQ!EIQ},EIQ$GMY})CAGMYAQ!EIQ})CAEIQAQ!GMY},GMY$GMY})CAGMYAQ!GMY})CAGMYAQ}}}&C%Q}:CSSSSSQ}}}&CSSSSSQ~~~~~}}}}}};'],
    tCard: [280, 192, '}[f/////////////////////////////////////////////4f/////////////////////////////////////////////4H/5////////////////////////////////////////+f/gH35////////////////////////////////////////+ffgAcB////////////////////////////////////////+Bw!2}<DYABjB////////////////////////////////////////+GMADBg};MG#g}<CADAg};MCABhg};GG!z}<DM!e}<B4!M}=wAH/4};f/gH/4};f/gG/+}:B/9gG/+}:B/9g~~~~~~~~~~~~~~~~~~~(mg}=FkZ}>CY/g}=H8~~}}}}}}!/g}=H8Z}>CYmg}=Fk~~~~~~~~~~~~~}}{G/+}:B/9gG/+}:B/9gH/4};f/gH34};ffgAc}<Bw!2}<DYABj}<GMADBg};MG#g}<CADAg};MCABhg};GG!zB////////////////////////////////////////+DM!e}<B4!MB////////////////////////////////////////+AwAH/5////////////////////////////////////////+f/gH/5////////////////////////////////////////+f/gf/////////////////////////////////////////////4'],
    tField: [280, 192, '~~~~}}}.//////////////////////////////////////////////g//////////////////////////////////////////////g////////9/////////f////////3////////9/////////gf///////9/////////f////////3////////9/////////~~~~~~}}}}>F)BQ)U)F,f/////////////////////////////////////////////}]f/////////////////////////////////////////////*F)BQ)U)F~~~~~~}}}&F)BQ)U)F,f/////////////////////////////////////////////}]f/////////////////////////////////////////////*F)BQ)U)F~~~~~~}}}&F)BQ)U)F,f/////////////////////////////////////////////}]f/////////////////////////////////////////////*F)BQ)U)F~~~~~~}}}&F)BQ)U)F,f/////////////////////////////////////////////}]f/////////////////////////////////////////////*F)BQ)U)F~~~~~~}}}}?f///////9/////////f////////3////////9/////////A////////9/////////f////////3////////9/////////g//////////////////////////////////////////////g//////////////////////////////////////////////g~}}}}}};'],
    tS1: [280, 72, '|c}@Q})B,M;f/g>BVVE>BVVVQBVVQ>H+//w=P/////gAc<EAWIiZ0=U#N2IAE;B/34#O=FVVU#VW;FRVCMiIhQ%Bs)EREQIiIRE;DVIAFVVR/g$Hj-BVVVAC=IiM!iNw$LJg(QREQ#R>VVQ!FVI$yp4(Kqqo$I;ERMzMREzM0#Dnv2(BVVVEREVQ;C.PuBjg:qg;BAEREREREU#N+D6g,ERF>qgAKq#I!BzoC94*CgAK>EURVVVVFQ!HX1Ffu,VVU?IAgAqqqg!MP1Ff7g*Kqg?BU$Q#0+oAr/w}#Cq(Dq8JBk6d}$Q(HMYxjnOPw}-MYwwiimC4!//})IIIIIII#//}(OfB8HwfC4!P})GXJ0/4XLw!P}.w!B#P8}?P8},BI*D//w}%BI*D//w}%C(P8},C(P8}]M!M}=M!M)D4/+H/4Q.D+,P(H////8f8C!///D8g*/8(D#P(Y%gB78f4AHwBn8&P/ABwfB//wfA/8A/8/w!D/BwcHBwHABgD#zPAD//////wAHABg+!PoBAD//8/w!D/Q&B6wt4AHsGoc(cABzwPA8D/8wc*FzsIBMH8CYM+AfM8gAHBwcHBwAH8&HO!H+(I(YPS5hnSYAg(T!cwQAjDgAwg#/w!D/EcwQAgf8AGR/A/iw4D4+Pj4+!/(PwAD4+A/w!D/4&DgDjHA4xg3(PwADg/g/g/gA/&8P!Hg/g/gA8FBgGAYB|PA8P!4H+A/w/wBADHh4w}$/w%gAEABxAjgAH`P&H+A/wfw+gBJAkg!///AH/+A//gA//+A////$DA8PA/38A/8A8!wA2hbAEH///AH/+A//wB//+A////+%8P!4%gA+YAIAEAM$wYABB!YD!BB$/8!/AP8!H8A/8AcD/+XGtYw4$/4AB/!P+!B/$B8!8AP8!+AH/wAgEAH3ve938$PgEA+!H8AIA+AC!B8!DwP8Dz4&D/xgpSlH(f(+#PgA/8!DwP8DwGAH/w!E#QhCAMA/////7///////3///9//+!DwPDw8P4)EUQhCUYA/////g///////B///wf/#DwPDw8M*MU#Uc{M;a%k{M;z$Bg}#D*hi#jA4}!D(D8Ai#iA4,D8!//8:H8!/8f8!B///./8{gD8AB///!P8*D8!//8!/8*38!/8f8Ag%BAD8!//8!/8*D8!w%w%gDww%DP8,///!DwA/8)MD!D/8f8AY%GAD8!//8,P/AD8!w$M&gDwO%cD8&Pw!///!DwA8$D/!/wD!D/8f8AHg#B4AD/8!P/w*P/#P///!/w&gDAB8#PgD8&Pw$///w!8*DwA///wf8!Y#G!D/8!P/wA/.P/8$Dw%gD!EH/4I!M&P/$///w!P8-///wf8!YD/wG!D//8!DwA/,D/8:g!B4HM4HgAM&P/AD8#//wAP8$P8P8AH/8#f8*D//8!DwA/8&P8AD/8!P8P8/4%g!B//M//g*/AD8#//w.H/8#f8!f8AP+#///8$/8&P8#///8#/4%'],
    tS2: [280, 72, '}}}>Kg)Kg)Kg)Kg)Kg!FAFVABU#FAFVABU#FAFVABU#FAFVABU#FAFV!CqACg!qoACqACg!qoACqACg!qoACqACg!qoACqACg#FAFVABU#FAFVABU#FAFVABU#FAFVABU#FAFV!IC#g!IAIC#g!IAIC#g!IAIC#g!IAIC#gAVUABU!VQAVUABU!VQAVUABU!VQAVUABU!VQAVUABU%CC!g%CC!g%CC!g%CC!g%CC!QEAB#QQAQEAB#QQAQEAB#QQAQEAB#QQAQEAB#II&gAII&gAII&gAII&gAII%E#E!B!E#E!B!E#E!B!E#E!B!E#E&o!K&o!KI%o!K&o!K&o#Q!Q!E#Q!Q!EOCYQcAQ!E#Q!Q!E#Q!Q!Cg)Cg&Ofigc(Cg)Cg%B*B(N9n*B*B`Ggf}>CABgE}=//Ak}<BgDgo}=ZMBI}:P/AGwDX}:H+AGwC3;Dw`IBABAEg;Dw}AD14+}.E!mz54/w}-E4AggIAB4#I*M!P//w@4ABh#O!D/g!/%M!P//w^zg!G!P34AB/g#D#w!M^2xgAB!MAYADAw#D#w!M)H/4-c#B!QAEACAQ=C!///!g)Sxi!g%C?DAB4AHgBg)Ng9/8!gACADAo%D/#D8$BgD#wD)gHDC!QDwAHgB+M%D/#D8$CwN4AHsGg%4AgDN9/8+&/u$D-CYM+AfMMg%4!Fy$D/3/gAB8$D.MS5hnSY#P/8B//4z9/8B/!B///J///+wD/#D8%GR/A/iw#P/8B/gHwC!P//3/////////wD/#D8AkkkkDjHA4xg#Q$f5/9/8.BAEg8Pw8kk#BBgGAYBB#Q(C?8Pw8!jEYjADHh4wB#g`3ADwPAjE%BxAjg#Dg`zADwP#7jEBgBJAkgCAgP`sAY///8EY$wA2hbAGA/8`8A4///8!cMIAoAIAEAMAfw`QAE$/4$OXGtYw4}!E&gPwH33ve93wB/wD/AP8A/wD/AP8A/wD/AP8A/wADA///88AB/AH5gpSlH!//////////////////////////wD8///8Ac&QhC!CwH8`MP///8!B/AH8UQhCUYAw|MD///8AcP+AHsU#UcDwI`DPwA/wDzg$a%mAw{DP!/wDwAP+AHT$Bj}8AMAP!Dg$hi#jB}MAMAP$/4AGAi#i}AP/8$D8%B///}!P/8&/4AGAB///#DI}$D8$g%BAD}(/gAHQ%DADH8^Dw&Dg$Y%GAD{w)/gAHu%c}$PADzg$Hg#B4}$PADwA+!H+c#PgADwD/AP8A/wD/AP8A/wD/AP8A/w///AwwN/8%Y#G}A///AwwM!+!H/kH/4I!D/////////////////////////&B/8%YDfwG}D:B4HA4HgAMAM}>MAM}*B/8g//gAMAM^DMzM-fzAP+!MAM^DMzM.M}%D4/w/w)Pg}&/w/w!'],
    tLose: [280, 76, '}}}&Q}>EP/g}<FVVFQAE%H4AfgB//gB+#H//4Afg;f7wAcB/w$P8A/wD//wD/#P//8A/w&I%QBYiIiNHZ0$IGAgYC!YCBg!I!GAgY%B/$H/fg#D+AO%D!M#M!w%D!M%D3g#VFUIyIiISIh%B!Ec!G!Q%BgAE%GAw#NUgAVV#BU&gAC8!D!I&wAC%EAQ%iIwAiIiI,gCoBg&qAY(IAI$BVV!VVVU-B8Aw&fAM(L/o#REzMxEzMzMR,BUAQ&VAE:I_I)C(D/g#EAREREREREQ}#OA4#Cq!q%K}#P34$RRFVERERFU&/_Bj&gCAg#qg$P/g/w/wD/D/#P8A/w/w&U&FQARUQEQ%P///w/wD/D/#P8A/w/w:KoAKqqq&P///w/wD/D/#P8A/w/w:B#QB(P///w/wD/D/#P8A/w/w%fj8?P8A/w/wD/D/#P8Arw/w%fj8[q}@f>Q%fr8[V=w&fr8})UAHk}?Oo}(B%U#QH6}$wD8#P/4gA/!e0}$EOAOQA4#DgDkBQ}$M!Cg#Gg#ogY}E!QE!Ro#HU!EaAM|M!wAD!BT#BM#UwE|B5AHkAf/kAf//5h//5AGm|DqAOoA6qoA6qqqD/+qAOo*//<B+gH6Af/6Af//+h//+gH6*//=VABUAFVUAFVVVAf9VP/U*P}#B//A//g*P}@P8}!B4HA4Hg*P8}#YD/wG:D//w{EH/4I:D//w*D//////w)Y#GH/////w#P8(H////8f8&P/&B8#Pg%Pj!P8%B/4%gBwcHBwc!/8$Hg#B+cHBwc!/8#M!M+ABwcHBwH(HBAD////O%c%HB$PM!MAH(BzsIBMfwAwc$Y%HsIBMfwAwcP&+AFzsIBMH8&HO!HBwfw%D%HC!PAP$Bn4(cwQAjDgAwg$g%BAQAjDgAwg/8/w!D/AEcwQAgf8&PP!Ex/c.PDAD/8/w!D/f4&Dg/g/g/gAf8-Pg/g/gAf8*Hg/g/gA8H+A/wHw/!cwQ!g#BAOA/wHwj!gw(4H+A/w/w&OAg8$gY#GB%OAg8!/w!D/fw%gAH+A/wHwA/zg/g8wk#JDOA/wHwA/zgw/w!D/!H+A/wfw/8A/8A+#H+A/4b#2G/8A/8O#Hw!8P!f38A/8A8(P8/w$OE///Ic&j/w$8P#4%gA/8A/8Aw!8H+A/nj#x4/8A/8!8AHw%8AH8A/8AcD/wA/+AP8AD8A/8BQ///Bn/wA/+Aj!/w(+AH/wAgE&H/A8$Bw#B(D8%8PA8/4&D/wA/+#wD8A/8HQ#B3/wA/+AU/w/w!8P#GAH/w!E&H/!/+AHOQAh!4&X#w8AP8!/4<w%YBhgwwM(/w!8AP8]/+AHwBzB5wG,wDwP8Dw_gBegvQC-DwP8Dw`Btg2w-DwPDw8M^B!hAQgB,DwPDw8M)D8!//8(BBghAQhB!//8&MPDw8!H8!/8f8;Dhth2xg-MPDw8A/4%gD8!//8!/8$GRAghSw!//8!/8*H8!/8f8*D8!4PSDh6CY)D8(/4%gDw!///!/8#CYN/g+88n/!DwA/8!D////8AH!D/8f8*D8!6zNwADsGg$P/AD8AD////9/4%gDw!///!D8#BgD#wzH/!DwA8)+AH!D/8f8*D/8ADP/4AHgBg$P/#P//w!B/4%gD#///wAD8#C!///!g///w!8)+#///wf8*D/8!P+H//.P//w!B('],
    tWin: [280, 72, ',qqqqqq#qqgAqoACqqg#Kqqqqqqqqg;FVVVVV#VV!VUAFVV$VVVVVVVVU<qqqqqq#qqgAqoACqqg#KqqqqqoAKg-BVV6qqqqq///qq//qr/6qq////qqqqqqg<qq}!IAI.B!/////////////////////////////wAE:g}%I:Q}%Q&P//AD//4}%gAP//!P//AD//w}$B!P//(3dI}>u7}%B&oMRH!qqQ}%gAMRHAVcRH!VVg}%QAMRHACo$qq}&I-V1lV//////////////////AzMwf//////9VQ-rKaq7u#Kqqq(C!zMwKsmLLgAKqo*UAUV!ER///+qqq////n////$Ky5s0f/4-qooa(K(8!C&Cpo<BQWF(BQ$IBmAQ$Q!BZU>sK)o#Ps7Dc3w#i!iwI>UN)F#fnWBrn4!MTAFBA0>oC)Cg!wDUArAM!MRAKCgI>YFBQ(UABh4oAUVGB!wAQBgE>gGCq(KABDPv/3zC&CgY>YFAE(BQCHQ#Lw$IABgE>gG,oAOw#Wq!g#CgY>QF$E%FHdm!BtvBAgAIABAU>sC$Ko$CH7T///TX&Cwo>QN$BVQ$Dmm!Bkm!QAQABQ0,E&qa%Cqg#FtbwAPZNQAcBwACho,o&WV&FV#EyZ8A+YZQAPPgABYU&VQ!Q&qK(Kq#clzDOkw!DO!Coo$qqqo*V1)VU!Mj+B/Fg!B#BTU!VVVV-rK*qoAHGOBxj#BQ!CuoACqq:VV*BQKDAMAwCCg!Q!BVUAB<qr-KAGPDxgCg$Kgqs>lX:DiBH(VDVY=CauQ,FACSBJAF!C#JKx=DJcw,HgBtC2AN!Cg!M1j=Bm5g*IDwAQAIAY#g!GTG>63.cuNaxhy&Duc>du,IBvve97vk&B04>GY-BDBSlKOK!E$Zg>Dw*qoAgAhCEAG!F$P?Bg(VVVQBIohCEo4!B$G{Cqqq#Yo#o6!I^FVVU#FVV$C0$BN!K#VVOw<Cqqo#Ko%Hm$DHgAC#Kquw?BU*FDE!BGCg|qqq,BE!BE&I@FVVU*E!D//+$Q!VQ>qqq-K!D//+$U!Cqg=VQ-BQF&CgAE#FV.Co<KgHg%Hg&Kg-VQ;BUBDw%NB@o<KgAB8%+!g(C{U#/$D+!o(BV`KqqPj4#fHwAI)qqqqqo!o=VVVPww#MPx-VVVVQ!U>P//8IP/wQ///I}(KqqowH/gMKqqY}(CqqvwORwP6qqg?MRH%DERw$FVVQ!8!VVVQ%xEc%MRH!MRH%DERw!P/yqqv//v//6qqn/4#xEc%MRH=KqlVVS/6W/+1VVSqo}#CqqqqpVUrVVqqqqqg=P//%D//w!FVVVVV/9Vf9VVVVVQ#//8%P//!P//%D//wP/yqqqqqqqqqqqqqqqn/4A//8%P//;KqlVVVVVVVVVVVVVVVSqo|Cqqqqqqqqqqqqqqqqqqqg|FVVVVVVVVVVVVVVVVVVVQ:'],
    e1: [40, 34, '[g%BQ#BwCI#/wE#f9gIIAP8/oAQA+f8I#/8AQQgAOAHwhgAgH4BAgAh4Hg#IH4FC!J4Hkl#H4AR!B4Ho#QH4IK!R4HgC#H4C#F4HiQ!EH4AU!B4!E!IA/k#J/gEg$/ho!B/gBI!C!c#CAP/w!EP+fw!F/P8#Bv+$D/%Dg$'],
    d1: [30, 34, '(Pw#f4#QM$GAP/gCAf/gBAQ$Q$Y$M$HV#D/g#qg$f4#f4#f4#f4#f4}$P/gB$HAQ#w$4Y#ID///MHVVVQD///0Aqqqo'],
    e2: [40, 34, '}!g#EABQ#KAB////+)CH///hACI#RABT///KABQ#K!D///,D///*EH///gg(EH///gg)D///,D/8#BQ#KABT/8AKACI#RACH///h)B////+ABQ#K!g#E^'],
    d2: [30, 34, '&//wAB//4AB!M$GAO!DAe!BgQBUAwQA+AYYAqAIMC!EHU#D9$qO$B4I!P5Q!f40AD/4o!eC!EAU#CN!4AK#cgABAF$DQAOAFf#c!Q#w$4Y#ID///MHVVVQD///0Aqqqo'],
    e3: [40, 34, ']Bg#BwCw#DwC4#GADc#MQDu#YwB3#xgA7gABj!dwADG!O4AGM!HcAMY!DuAYw!B3Axg#7hj#YdbGM!eO+M8!XH0Z0!djozc!OxUm4!DZKtg!BvF7$2OW$Qd%QQO$xjn#Bjj7g!DGAdw!GMAO4!MYAHc!IwADk!BgABw!P#8A'],
    d3: [30, 34, '&//wAB//4AB!M$GAO!DAe!BgQBUAwQA+AYYAqAIMC!EHU#D9$p+$B4IAD/5QAD/40AB/5oADB/!B0Aw!+AYPwKAI$EQM$G!gYD+!CGAHIG!BQC!I0Bg!oAP/y!dVU!P/9!CqqA'],
    e4: [40, 34, '#O&Pw%Bw%Bw%Hw%W&0%Bm%DL%GNg$cG4$6Cc#BuAG#DMAL#G!Ng!e!G4!+!CcABs#GADI#LAGOOOOBgCOOOOIAGMwADMgAMwYDM$8&k%BC%BC%CB%GBg$EAg$IAQ#M3/jMABz!MzAB/////A'],
    d4: [30, 34, '%PwA/Af4B/gQMBAwAG!YAC!IAB!E}$B+!f/B/gf///gP///gH///gMAB/gHVU!D/+#qq}*g!Bg$PI#dQ#P0#Co'],
    e5: [40, 34, '}}!G$BgH4#fgAfwAP4AB7/D/+!P//9Y!X+91#FPnY#BRmg$Q=Y!o!B#CgAUY!oMBB#CgAU$MBAg!Q!CgAB0!KGAB5!o+AGegAv5gHzuB/PgB+Pnx+!P5mfw!B/D+$H/g%8#'],
    d5: [30, 34, '%P///Af///gQ#w$Y$I$MABVVQ!//0ABVao!/4$MAQ!GAY!DAMAB+AHVV/QD///4Aqr/o!B/k,Pw)QM$G!gYD+!CGAHIG!BQC!I0Bg!oAP/y!dVU!P/9!CqqA'],
    e6: [40, 34, ',I&c&2,I!m!Ic!g!c2!k!0!BC#2ADD!kQACB!sGAEAgBoDAEAgDAJgIAQGQIwYAYMQAYQAIY!MgAEw!HgAHg!EAcCw!HAcBw*HAcBw!HAcBwAH/////gH/////g(D/////AD/////AD/////AD/////}!'],
    d6: [30, 34, '&//wAB//4AB!M$GAO!CAe!DAQBVU#/9!BVa#/4$M$G$DAf4B+Af5V/Qf4//4f4r/of4B/k}!gYB+!CHAHIG!BQC!I0Bg!oAP/y!dVU!P/9!CqqA'],
    pREEVE: [26, 24, '#Pw#OQ#G!f4HgAf4HgAgEHgAgEBgPAD2QPAD3wPADwAPMzw!Mw#Mw#LQwADADwAH0vYAEzM4AI!gAI$Q$U$HwP!bwP!Y$'],
    pSCOUT: [26, 24, '&HAw!Pgw!Ywo!QYs!gMO!gGPABADFAD/gCgC/jCgEJC!EJ$J!QA2guAE/jgQE!u#Dig#Cg#F$PAD/8OACGEsAB54o$wA'],
    pSQUIRE: [26, 24, '#Bg#BgB/wCAD/4CgCAIAgcAHgAcAHkAcAHlAfOfhAD/4IADOYKAD/4CAD/4$EQAPw8kAMJC2!GB8#D+$BAMGABAOPH/ACJH/g$g!Cqg'],
    pBARD: [26, 24, 'g$4JgBw5+ABw32Y!aB8!IAGAQD/8CQGAOCgBkwEgAbAN!bAL!EAS#PUACbPkACCAg!GE#DO#DbE!Bw#BLE#2!CAcIACAM4#XwA'],
    pMK: [46, 40, '?B/+#gAP//wAIwAeAB4AYYAw!MAwsDeAB7BomDPgHzDIDEuYZ0mABkfwP4s!4xwOMYAQYBgGAQQQAx4eMAQ!cQI4!IASQJIAgMANoWwBgGACABADADlxrWMOAB973vd8!YKUpRw#EIQg!BFEIQlGADF#FHAGg$JgMw$YwIYg!IwQAIg!Ig#f//w$f//w!I&QM&wG%BgDg$HAB4$e!f#D4!G#Bg!BB/+C#GA/8Bg!eBwOB4*'],
    pMKS: [46, 40, '?B/+#gAP//wAIwAeAB4AYYAw!MAwsDeAB7BomDPgHzDIDEuYZ0mABkfwP4s!4xwOMYAQYBgGAQQQAx4eMAQ!cQI4!IASQJIAgMANoWwBgGBCABCDADlAIQCOABw$c!c$Q!Q$Y#f//wgADh#CHAH5f//y/gM&wI&Q`I&QM&wG%BgDg$HAB4$e!f#D4!G#Bg!BB/+C#GA/8Bg!eBwOB4*']
};
/* ---------- game sprites: replaces the whole of ART, and drops the K/W derivation loop ---------- */
var ART = {
    mk: ['##.......##', '.#########.', '.##.###.##.', '.#########.', '##.#####.##', '###########', '.#########.', '..##...##..'],
    mkChomp: ['##.#####.##', '.#########.', '.##.###.##.', '.#.#.#.#.#.', '.#.......#.', '.##.....##.', '.#########.', '..##...##..'],
    mkDead: ['##.......##', '.#########.', '.#.#.#.#.#.', '.##.###.##.', '.#.#.#.#.#.', '.#########.', '.##.#.#.##.', '..#######..'],
    REEVE: ['..####...##', '#######..##', '..#.#.#..#.', '..#####.#..', '.########..', '.#.###.....', '...###.....', '..##.##....'],
    REEVEW: ['..####...##', '#######..##', '..#.#.#..#.', '..#####.#..', '.########..', '.#.###.....', '...###.....', '.##...##...'],
    REEVEK: ['...........', '..####.....', '#######....', '..#.#.#..##', '..#####.###', '.######.#..', '.#.####.#..', '.###.###...'],
    SCOUT: ['....#....#.', '...###....#', '..#####...#', '..#...#...#', '..#####.###', '..#####...#', '...###...#.', '..##.##.#..'],
    SCOUTW: ['....#....#.', '...###....#', '..#####...#', '..#...#...#', '..#####.###', '..#####...#', '...###...#.', '.##...##...'],
    SCOUTK: ['...........', '....#......', '...###.....', '..#####..#.', '..#...#...#', '..#####...#', '.######...#', '.###.###.#.'],
    SQUIRE: ['..#####..#.', '.#######.#.', '.#.....#.#.', '.#######.#.', '..#####.##.', '.#######.#.', '...###..###', '..##.##.###'],
    SQUIREW: ['..#####..#.', '.#######.#.', '.#.....#.#.', '.#######.#.', '..#####.##.', '.#######.#.', '...###..###', '.##...##.##'],
    SQUIREK: ['...........', '...........', '..#####....', '.#######...', '.#.....#...', '.#######...', '..#####.#.#', '.#########.'],
    BARD: ['#...###..##', '.#######.#.', '..#.#.#.#..', '..#####.#..', '...###.###.', '..####.###.', '..####..#..', '..##.##....'],
    BARDW: ['#...###..##', '.#######.#.', '..#.#.#.#..', '..#####.#..', '...###.###.', '..####.###.', '..####..#..', '.##...##...'],
    BARDK: ['...........', '#...###....', '.#######...', '..#.#.#....', '..#####.##.', '...###.####', '.#####.###.', '.###.###...'],
    platter: ['..######.....', '.########....', '##########...', '##########...', '.#########...', '..######.##..', '.......#####.', '........##.##'],
    note: ['..##.', '..#.#', '..#..', '..#..', '###..', '###..', '.##..'],
    crumb: ['#.#..#.', '..#.#..', '#....#.'],
    spark: ['..#..', '.#.#.', '#.#.#', '.#.#.', '..#..'],
    warn: ['.##...##...##.', '####.####.####', '.##...##...##.', '.##...##...##.', '..#....#....#.', '..............', '.##...##...##.', '.##...##...##.'],
    life: ['#......#', '.######.', '.#.##.#.', '.######.', '##.##.##', '.######.', '..#..#..']
};
var LID = ['..........................', '.............#............', '........###########.......', '......###############.....', '.....#################....', '....###################...', '...#####################..', '..#######################.', '..#######################.', '..#######################.', '.#########################', '..##oooooooooooooooooooo#.', '..##oooooooooooooooooooo#.', '..#######################.', '...#####################..', '....###################...', '.....#################....', '......###############.....', '........###########.......', '.............#............'];

// Six-bit groups, XORed against two rows up, with zero runs. Period-2 dither packs to almost nothing.
function unpack(p) {
    var A64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', RUN = '!#$%&()*,-.:;<=>?@[]^_`{|}~';
    var w = p[0], h = p[1], s = p[2], n = Math.ceil(w / 6), g = [], i, j, c, r, x, y, row, out = [], v;
    for (i = 0; i < s.length; i++) {
        c = s.charAt(i); j = RUN.indexOf(c);
        if (j < 0) { g.push(A64.indexOf(c)); } else { for (r = c === '~' ? 200 : j + 2; r > 0; r--) { g.push(0); } }
    }
    while (g.length < n * h) { g.push(0); }
    for (i = 2 * n; i < g.length; i++) { g[i] ^= g[i - 2 * n]; }
    for (y = 0; y < h; y++) {
        row = '';
        for (x = 0; x < w; x++) { v = g[y * n + Math.floor(x / 6)]; row += (v >> (5 - x % 6)) & 1 ? '#' : '.'; }
        out.push(row);
    }
    return out;
}
// Lit pixels, with a one-pixel opaque keyline, so a sprite can walk across art.
function keyline(rows) {
    var h = rows.length, w = rows[0].length;
    function lit(x, y) { return y >= 0 && y < h && x >= 0 && x < w && rows[y].charAt(x) === '#'; }
    return rows.map(function (r, y) {
        var o = '', x;
        for (x = 0; x < w; x++) { o += lit(x, y) ? '#' : lit(x - 1, y) || lit(x + 1, y) || lit(x, y - 1) || lit(x, y + 1) ? 'o' : '.'; }
        return o;
    });
}
function mirror(rows) { return rows.map(function (r) { return r.split('').reverse().join(''); }); }
// The bite that eats the board between levels: a black sheet with a toothed right edge.
function biteRows() {
    var o = [], y, n, x, r;
    for (y = 0; y < 192; y++) {
        n = 288 + Math.round(13 * (1 - Math.abs((y % 32) - 16) / 16)); r = '';
        for (x = 0; x < 302; x++) { r += x < n ? 'o' : x === n ? '#' : '.'; }
        o.push(r);
    }
    return o;
}
function defAll(H) {
    var k;
    for (k in ART) { if (ART.hasOwnProperty(k)) { H.defSprite(k, ART[k]); H.defSprite(k + 'N', neg(ART[k])); } }
    for (k in PACKED) { if (PACKED.hasOwnProperty(k)) { H.defSprite(k, unpack(PACKED[k])); } }
    H.defSprite('bnL', ['oo......', 'oooo....', 'oooooo..', 'oooooooo', 'oooooooo', 'oooooo..', 'oooo....', 'oo......']);
    H.defSprite('bnR', mirror(['oo......', 'oooo....', 'oooooo..', 'oooooooo', 'oooooooo', 'oooooo..', 'oooo....', 'oo......']));
    H.defSprite('tLid', LID);
    H.defSprite('biteS', biteRows());
    ['REEVE', 'REEVEW', 'SCOUT', 'SCOUTW'].forEach(function (n) {
        H.defSprite(n + 'H', keyline(ART[n]));
        H.defSprite(n + 'F', keyline(mirror(ART[n])));
    });
}

    var SCENE1_ART = [{ s: 'tS1', x: 0, y: 24 }];
    var SCENE2_ART = [{ s: 'tS2', x: 0, y: 24 }];

    /* ---------- the game ---------- */
    var game = { title: 'NUMBER MUNCHORKS' };
    game._t = {
        makeBoard: makeBoard, isTarget: isTarget, holdMessage: holdMessage, reeveStep: reeveStep, scoutStep: scoutStep,
        squireStep: squireStep, bardStep: bardStep, bardRewrite: bardRewrite, pickSpawn: pickSpawn, pickFeast: pickFeast,
        rankFor: rankFor, creditsLine: creditsLine, evalExpr: evalExpr, pools: pools, banner: banner, LEVELS: LEVELS,
        IV: IV, padLabel: padLabel, loadBest: loadBest, reeveStart: reeveStart, HOW1: HOW1, HOW2: HOW2, SCENE1: SCENE1, SCENE2: SCENE2,
        VARIANTS5: VARIANTS5, TOUCH: TOUCH
    };

    game.start = function (H) {
        var G = {
            cellSpr: {},
            mode: 'BUSY', prev: 'PLAY', score: 0, lives: 4, level: 1, extra: false, v1: 3, v5: 'F24', best: null,
            tiles: [], pc: 2, pr: 2, enemies: [], feast: null, playMs: 0, animMs: 0, ph: 0, invMs: 0, chompMs: 0, moveCool: 0,
            noLoss: true, msg: null, msgMs: 0, eaten: 0, rewrites: 0, tAdded: 0, retold: false, dead: false, touch: false,
            holdMs: 0, clearMs: 0, cardMs: 0, tickId: 0, sfxQ: [], sfxWait: 0, dirty: {}, rngNote: 0, won: false, newBest: false,
            rule: '', flavor: '', quitConfirm: false,
            biteLock: 0, notes: {}, pops: {}, streak: 0, seen: {}, startBest: 0
        };
        game._dbg = function () { return G; };
        function rng(n) { return H.rand(n); }
        G.best = loadBest(H.load('best'));
            defAll(H);

        /* ----- drawing helpers ----- */
        function T(col, row, s, inv) { H.text(col, row, s, !!inv); }
        function center(row, s, inv) { T(Math.max(0, Math.floor((40 - s.length) / 2)), row, s, inv); }
        function rowsOut(arr) {
            var i;
            for (i = 0; i < arr.length; i++) { if (arr[i]) { T(0, i, arr[i], false); } }
        }
        function bestShown() { return Math.max(G.best ? G.best.score : 0, G.score); }
        function drawStatus() {
            T(0, 23, 'SCORE ' + p5(G.score) + '         LVL ' + G.level + '  BEST ' + p5(bestShown()) + ' ', false);
        }
        function pressReturn() { T(0, 23, 'PRESS RETURN                           ', false); }
        function drawRow1() {
            var m = G.msg;
            H.fill(0, 1, 40, 1, ' ', !!(m && m.inv));
            if (m) { center(1, m.text, m.inv); } else { center(1, G.flavor, false); }
        }
        function setMsg(text, inv, ms) {
            G.msg = { text: text, inv: !!inv };
            G.msgMs = ms || -1;
            drawRow1();
        }
        function drawBanner() {
            H.fill(0, 0, 40, 1, ' ', true);
            center(0, G.rule, true);
        }
        function mark(c, r) { G.dirty[r * 5 + c] = true; }
        function markAll() { var i; for (i = 0; i < 25; i++) { G.dirty[i] = true; } }
        function enemyAt(c, r) {
            var i, e;
            for (i = 0; i < G.enemies.length; i++) {
                e = G.enemies[i];
                if (e.state === 'live' && e.c === c && e.r === r) { return e; }
            }
            return null;
        }
        function warnAt(c, r) {
            var i, e;
            for (i = 0; i < G.enemies.length; i++) {
                e = G.enemies[i];
                if (e.state === 'warn' && e.wc === c && e.wr === r) { return e; }
            }
            return null;
        }
        function feastAt(c, r) { return G.feast.state === 'on' && G.feast.c === c && G.feast.r === r; }
        function drawCell(c, r) {
            var t = G.tiles[r * 5 + c], x = 8 * c, y = 3 + 4 * r, px = 56 * c, py = 8 * y;
            var lane = '       ', l1 = padLabel(t.a), l2 = padLabel(t.b), laneInv = false, whole = false, e, spr = [], feast = feastAt(c, r), name, idx = r * 5 + c, w, pop, label = false, isP = c === G.pc && r === G.pr;
            if (feast) {
                l1 = 'FEAST ' + Math.ceil(G.feast.left / 1000);
                l2 = lane;
            }
            if (c === G.pc && r === G.pr) {
                if (G.dead) { whole = true; name = 'mkDead'; }
                else {
                    whole = G.invMs > 0 ? (!H.reduced && G.ph === 0) : true;
                    name = G.chompMs > 0 ? 'mkChomp' : 'mk';
                }
                spr.push({ s: name + (whole ? 'N' : ''), x: px + 19, y: py });
            } else if ((e = enemyAt(c, r))) {
                laneInv = true; label = true;
                w = G.mode === 'HOLD' ? 'K' : e.f ? 'W' : '';
                spr.push({ s: e.kind + w + 'N', x: px + 19, y: py });
            } else if (warnAt(c, r)) {
                laneInv = H.reduced ? true : G.ph === 0;
                spr.push({ s: laneInv ? 'warnN' : 'warn', x: px + 17, y: py });
            } else if (feast) {
                spr.push({ s: 'platter', x: px + 18, y: py });
            }
            if (feast && !(G.feast.left <= 1500 && !H.reduced && G.ph === 1)) {
                spr.push({ s: whole ? 'sparkN' : 'spark', x: px + 1, y: py + 1 }, { s: whole ? 'sparkN' : 'spark', x: px + 43, y: py + 1 });
                spr.push({ s: whole ? 'sparkN' : 'spark', x: px + 1, y: py + 17 }, { s: whole ? 'sparkN' : 'spark', x: px + 43, y: py + 17 });
            }
            pop = G.pops[idx];
            if (!feast && pop && pop.ms > 0) {
                l1 = padLabel('+' + pop.n);
                spr.push({ s: whole ? 'crumbN' : 'crumb', x: px + 6, y: py + 18 }, { s: whole ? 'crumbN' : 'crumb', x: px + 36, y: py + 18 });
            }
            if (G.notes[idx] > 0 && !spr.length) { spr.push({ s: 'note', x: px + 22, y: py }); }
            G.cellSpr[idx] = spr;
            if (label) { l2 = LANES[e.kind]; } else if (!isP && !feast && t.b === '' && (w = warnAt(c, r))) { l2 = LANES[w.kind]; }
            T(x, y, lane, laneInv || whole);
            T(x, y + 1, l1, whole);
            T(x, y + 2, l2, whole || label);
        }
        function refresh() {
            var all = [{ s: 'tField', x: 0, y: 0 }, { s: 'bnL', x: 0, y: 0 }, { s: 'bnR', x: 272, y: 0 }], i;
            for (i = 0; i < G.lives; i++) { all.push({ s: 'life', x: 91 + i * 9, y: 184 }); }
            for (i = 0; i < 25; i++) { if (G.cellSpr[i]) { all = all.concat(G.cellSpr[i]); } }
            H.sprites(all);
        }
        function flush() {
            var k;
            for (k in G.dirty) { if (G.dirty.hasOwnProperty(k)) { drawCell(+k % 5, Math.floor(+k / 5)); } }
            G.dirty = {};
            refresh();
        }
        function drawField() {
            drawBanner();
            drawRow1();
            markAll(); flush(); drawStatus();
        }
        function sfx(notes) {
            var idle = G.sfxWait <= 0 && !G.sfxQ.length, i;
            for (i = 0; i < notes.length; i++) { if (G.sfxQ.length < 6) { G.sfxQ.push(notes[i]); } }
            if (idle) { pumpSfx(); }
        }
        function pumpSfx() {
            var n;
            if (G.sfxQ.length) { n = G.sfxQ.shift(); H.beep(n[0], n[1]); G.sfxWait = n[1]; }
        }
        var INTRO = {
                REEVE: 'THE REEVE WALKS THE FIELD. THOROUGHLY.',
                SCOUT: 'A SCOUT HAS SPOTTED YOU. KEEP MOVING.',
                SQUIRE: 'THE SQUIRE IS HERE TO HELP. OH NO.',
                BARD: 'THE BARD ENTERS. COUNT TWICE.'
            };
            var CLEAR_SND = [[523, 70], [659, 70], [784, 70], [1047, 120]];

        /* ----- title / how to ----- */
        function stopTick() {
            if (G.tickId) { H.cancel(G.tickId); G.tickId = 0; }
            G.sfxQ = []; G.sfxWait = 0;
        }
        var attractId = 0;
        function stopAttract() { if (attractId) { H.cancel(attractId); attractId = 0; } }
        // The title's only motion: he blinks, and a Reeve paces the far ridge.
        function attract() {
            var n = 0, x = 262, dir = -1;
            function frame() {
                var who = n % 240 < 120 ? 'REEVE' : 'SCOUT', walk = Math.floor(n / 4) % 2 ? 'W' : '', list = [{ s: 'tTitle', x: 0, y: 0 }];
                list.push({ s: who + walk + (dir < 0 ? 'H' : 'F'), x: x, y: 87 });
                if (n % 40 >= 37) { list.push({ s: 'tLid', x: 27, y: 85 }, { s: 'tLid', x: 63, y: 85 }); }
                H.sprites(list);
            }
            frame();
            if (H.reduced) { return; }
            attractId = H.every(150, function () {
                n++;
                x += dir;
                if (x <= 122 || x >= 266) { dir = -dir; }
                frame();
            });
        }
        function showTitle() {
            stopTick(); stopAttract();
            G.mode = 'BUSY';
            H.cls();
            H.then(attract);
            // The logo is art, and host.text never reaches the live log. This one real line does; the row is blanked at once.
            H.line('NUMBER MUNCHORKS. SPACE TO PLAY, H HELP', { hold: 0 });
            H.then(function () {
                H.fill(0, 0, 40, 1, ' ', false);
                T(18, 13, 'SPACE  TAKE THE FIELD', false);
                T(18, 14, 'H      HOW TO PLAY', false);
                T(18, 15, 'Q      LEAVE THE PARK', false);
                var b = G.best;
                T(18, 17, 'BEST ' + p5(b ? b.score : 0), false);
                T(18, 18, b ? (b.won ? '6TH LEVEL MUNCHORK' : 'REACHED LEVEL ' + b.level) : '(NONE YET)', false);
                T(8, 22, 'THE ORKEK GAMES DIVISION', false);
                T(3, 23, 'AN EDUCATIONAL PRODUCT, ALLEGEDLY', false);
                G.mode = 'TITLE';
            });
        }
        function showHow(page) {
            stopAttract();
            G.mode = 'BUSY';
            H.cls();
            H.then(function () {
                var inv = page === 1 ? HOW1_INV : HOW2_INV;
                rowsOut(page === 1 ? HOW1 : HOW2);
                inv.forEach(function (c) { T(c[0], c[1], c[2], true); });
                H.sprites(page === 1 ? HOW1_ART : HOW2_ART);
                G.mode = page === 1 ? 'HOW1' : 'HOW2';
            });
        }

        /* ----- game flow ----- */
        function saveBest(won, level) {
            var beaten = G.score > (G.best ? G.best.score : 0);
            if (beaten) {
                G.best = { score: G.score, level: Math.max(1, Math.min(6, level)), won: !!won };
                H.save('best', G.best);
            }
            return beaten;
        }
        function beginGame() {
            stopAttract();
            G.score = 0; G.lives = 4; G.level = 1; G.extra = false; G.won = false; G.newBest = false;
            G.v1 = 3 + rng(3);
            G.v5 = VARIANTS5[rng(VARIANTS5.length)];
            G.sfxQ = []; G.sfxWait = 0;
            G.seen = {}; G.startBest = G.best ? G.best.score : 0;
            if (!G.tickId) { G.tickId = H.every(TICK, tick); }
            showCard();
        }
        function variantFor(level) { return level === 1 ? G.v1 : level === 5 ? G.v5 : 0; }
        function showCard() {
            var L = LEVELS[G.level], b = banner(G.level, variantFor(G.level));
            G.mode = 'BUSY';
            H.cls();
            H.then(function () {
                var art = [{ s: 'tCard', x: 0, y: 0 }, { s: 'e' + G.level, x: 36, y: 34 }, { s: 'e' + G.level, x: 204, y: 34 }, { s: 'd' + G.level, x: 125, y: 32 }];
                var x0 = 140 - L.en.length * 28, i, k, kx;
                for (i = 0; i < L.en.length; i++) {
                    k = L.en[i][0]; kx = x0 + i * 56 + 14;
                    art.push({ s: 'p' + k, x: kx, y: 124 });
                    T(Math.round((kx + 13) / 7 - k.length / 2), 19, k, false);
                }
                H.sprites(art);
                center(3, 'LEVEL', false);
                center(9, L.title, false);
                center(11, b[0], true);
                center(13, b[1], false);
                T(10, 21, 'SCORE ' + p5(G.score) + '   LIVES ' + G.lives, false);
                if (H.reduced) { pressReturn(); }
                G.cardMs = 0;
                G.mode = 'CARD';
            });
        }
        function initLevel() {
            var L = LEVELS[G.level], b = banner(G.level, variantFor(G.level)), i;
            G.rule = b[0]; G.flavor = b[1];
            G.tiles = makeBoard(G.level, variantFor(G.level), rng);
            G.pc = 2; G.pr = 2;
            G.enemies = [];
            for (i = 0; i < L.en.length; i++) {
                G.enemies.push({ kind: L.en[i][0], due: L.en[i][1], state: 'wait', c: 0, r: 0, wc: 0, wr: 0, wms: 0, acc: 0, hdir: 1, vdir: 1, lc: -1, lr: -1, iv: IV[G.level][L.en[i][0]] });
            }
            G.feast = { state: 'wait', ms: 6000, left: 0, c: 0, r: 0 };
            G.playMs = 0; G.animMs = 0; G.ph = 0; G.invMs = 0; G.chompMs = 0; G.moveCool = 0;
            G.noLoss = true; G.msg = null; G.msgMs = 0; G.eaten = 0; G.rewrites = 0; G.tAdded = 0; G.retold = false;
            G.dead = false; G.dirty = {}; G.cellSpr = {};
            G.biteLock = 0; G.notes = {}; G.pops = {}; G.streak = 0;
        }
        function enterPlay() {
            initLevel();
            G.mode = 'BUSY';
            H.cls();
            H.then(function () {
                drawField();
                G.mode = 'PLAY';
                setMsg('LAY ON!', false, 800);
                sfx([[523, 60], [784, 90]]);
            });
        }
        function award(n) {
            G.score += n;
            if (!G.extra && G.score >= 1000 && G.lives < 5) {
                G.extra = true; G.lives += 1;
                setMsg('EXTRA LIFE. A HEALER WAS WATCHING.', false, 2000);
                sfx([[784, 60], [1047, 60], [1319, 90]]);
            }
            drawStatus();
        }
        function countTargets() {
            var n = 0, i;
            for (i = 0; i < 25; i++) { if (isTarget(G.tiles[i])) { n++; } }
            return n;
        }
        function checkClear() {
            if (G.mode !== 'PLAY' || countTargets() > 0) { return false; }
            G.mode = 'CLEAR'; G.clearMs = 0;
            setMsg(G.noLoss ? 'CLEAN FIELD! BONUS CREDIT.' : 'FIELD CLEAR!', false, 2000);
            award(50 * G.level);
            if (G.noLoss) { award(25 * G.level); }
            if (G.level < 6) { sfx(CLEAR_SND); }
            saveBest(false, Math.min(6, G.level + 1));
            drawStatus();
            if (H.reduced) { pressReturn(); }
            return true;
        }
        function afterClear() {
            if (G.level >= 6) { showWin(); return; }
            var done = G.level;
            G.level += 1;
            if (done === 2) { showScene(SCENE1, SCENE1_ART); } else if (done === 4) { showScene(SCENE2, SCENE2_ART); } else { showCard(); }
        }
        function showScene(rows, art) {
            var i;
            G.mode = 'BUSY';
            H.cls();
            H.then(function () { H.sprites(art); });
            for (i = 0; i < 23; i++) {
                if (rows[i]) { H.line(rows[i], H.reduced ? {} : { hold: 120 }); } else { H.line(''); }
            }
            H.then(function () { pressReturn(); G.mode = 'SCENE'; });
        }
        function startHold(text, touch) {
            G.mode = 'HOLD'; G.holdMs = 0; G.dead = true; G.touch = !!touch; G.noLoss = false;
            G.lives -= 1;
            G.invMs = 0; G.chompMs = 0;
            G.streak = 0; G.sfxQ = []; G.sfxWait = 0;
            setMsg(text, true, -1);
            markAll(); flush(); drawStatus();
            sfx(touch ? [[1760, 80], [1568, 80], [1760, 160], [196, 220]] : [[392, 90], [330, 90], [262, 90], [196, 340]]);
            if (H.reduced) { pressReturn(); }
        }
        function endHold() {
            var i, e, k = 0;
            if (G.lives <= 0) { showLose(); return; }
            G.dead = false;
            if (G.touch) { mark(G.pc, G.pr); G.pc = 2; G.pr = 2; }
            for (i = 0; i < G.enemies.length; i++) {
                e = G.enemies[i];
                if (e.state === 'live' || e.state === 'warn') {
                    if (e.state === 'live') { mark(e.c, e.r); } else { mark(e.wc, e.wr); }
                    e.state = 'wait'; e.due = G.playMs + 1000 + 1000 * k; k++;
                }
            }
            G.invMs = 1500; G.biteLock = 350;
            G.mode = 'PLAY';
            markAll(); flush(); drawStatus();
            setMsg('LAY ON!', false, 800);
            sfx([[523, 60], [784, 90]]);
        }
        function showLose() {
            G.mode = 'BUSY';
            saveBest(false, G.level);
            var lv = G.level;
            H.cls();
            H.then(function () {
                H.sprites([{ s: 'tLose', x: 0, y: 0 }]);
                T(0, 10, 'YOU HAVE RUN OUT OF LIVES ON LEVEL ' + lv + '.', false);
                T(0, 11, 'THE FIELD GOES ON WITHOUT YOU.', false);
                T(0, 12, 'THE FIELD WAS GOING TO DO THAT ANYWAY.', false);
                T(0, 14, 'THE GOOD NEWS IS THAT YOU SIGNED IN.', false);
                T(0, 15, 'YOU STILL GET THE CREDIT.', false);
                T(0, 17, creditsLine(lv), false);
                T(0, 19, 'SCORE ' + p5(G.score) + '  ' + (G.score > G.startBest ? 'NEW BEST!' : 'BEST ' + p5(bestShown())), false);
                T(0, 20, 'RANK: ' + rankFor(G.score), false);
                pressReturn();
                G.mode = 'END';
            });
        }
        function showWin() {
            G.mode = 'BUSY';
            award(250 * G.lives);
            saveBest(true, 6); G.newBest = G.score > G.startBest;
            G.won = true;
            H.cls();
            H.then(function () {
                T(10, 1, 'HEAR YE. COURT IS OPEN.', false);
                H.sprites([{ s: 'tWin', x: 0, y: 0 }]);
                T(0, 9, 'THE CROWN NOTES THAT YOU HAVE CLEARED', false);
                T(0, 10, 'SIX FIELDS, EATEN A PAGE OF THE RULES,', false);
                T(0, 11, 'AND BITTEN MOSTLY THE RIGHT SQUARES.', false);
                T(0, 13, 'YOU ARE NOW A 6TH LEVEL MUNCHORK.', false);
                T(0, 14, 'YOU ARE ADMITTED TO THE ORDER OF THE', false);
                T(0, 15, 'SPORK, WHICH EXISTS AS OF THIS MORNING.', false);
                T(0, 17, 'THE PRIME MINISTER HAS SOME QUESTIONS', false);
                T(0, 18, 'ABOUT 53 CREDITS IN THREE MINUTES.', false);
                T(0, 20, 'SCORE ' + p5(G.score) + '  LIVES LEFT ' + G.lives + '  ' + (G.newBest ? 'NEW BEST!' : 'BEST ' + p5(bestShown())), false);
                T(0, 21, 'RANK: ' + rankFor(G.score), false);
                pressReturn();
                G.mode = 'END';
                sfx(CLEAR_SND.concat(CLEAR_SND));
            });
        }

        /* ----- play ----- */
        function legalFor(self) {
            return function (c, r) {
                var i, e;
                if (!onBoard(c, r) || feastAt(c, r)) { return false; }
                for (i = 0; i < G.enemies.length; i++) {
                    e = G.enemies[i];
                    if (e === self) { continue; }
                    if (e.state === 'live' && e.c === c && e.r === r) { return false; }
                    if (e.state === 'warn' && e.wc === c && e.wr === r) { return false; }
                }
                return true;
            };
        }
        function takenList() {
            var o = [], i, e;
            for (i = 0; i < G.enemies.length; i++) {
                e = G.enemies[i];
                if (e.state === 'live') { o.push({ c: e.c, r: e.r }); } else if (e.state === 'warn') { o.push({ c: e.wc, r: e.wr }); }
            }
            if (G.feast.state === 'on') { o.push({ c: G.feast.c, r: G.feast.r }); }
            return o;
        }
        function collide() {
            var e = enemyAt(G.pc, G.pr);
            if (e && G.invMs <= 0 && G.mode === 'PLAY') { startHold(TOUCH[e.kind], true); return true; }
            return false;
        }
        function enemyStep(e) {
            var ok = legalFor(e), s, oc = e.c, or = e.r, t, nt;
            if (e.kind === 'REEVE') { s = reeveStep(e, ok); e.hdir = s.hdir; e.vdir = s.vdir; }
            else if (e.kind === 'SCOUT') { s = scoutStep(e, G.pc, G.pr, ok); }
            else if (e.kind === 'SQUIRE') {
                s = squireStep(e, { tiles: G.tiles, pc: G.pc, pr: G.pr, eaten: G.eaten, ok: ok }, rng);
                if (s.eat) {
                    G.tiles[e.r * 5 + e.c] = blankTile(); G.eaten += 1;
                    mark(e.c, e.r); flush();
                    setMsg('THE SQUIRE TIDIED UP. NO CREDIT.', false, 2000);
                    sfx([[300, 40]]);
                    checkClear();
                    return;
                }
            } else {
                s = bardStep(e, ok, rng);
                e.lc = s.lc; e.lr = s.lr;
            }
            e.c = s.c; e.r = s.r;
            if (e.c !== oc || e.r !== or) {
                e.f = !e.f;
                mark(oc, or); mark(e.c, e.r);
                if (e.kind === 'BARD') {
                    t = G.tiles[or * 5 + oc];
                    if (!t.ok && !t.blank && G.rewrites < 6) {
                        nt = bardRewrite(G.tiles, G.level, variantFor(G.level), rng(3) === 0 && G.tAdded < 2, rng);
                        if (nt) {
                            G.tiles[or * 5 + oc] = nt; G.rewrites += 1;
                            if (nt.ok) { G.tAdded += 1; }
                            if (!G.retold) { G.retold = true; setMsg('THE BARD IS RETELLING THE BOARD.', false, 2000); }
                            G.notes[or * 5 + oc] = 1400;
                            sfx([[494, 40], [659, 40]]);
                        }
                    }
                }
                flush();
                collide();
            }
        }
        function trySpawn(e) {
            var c = pickSpawn({ pc: G.pc, pr: G.pr, taken: takenList() }, rng);
            if (!c) { e.due += 500; return; }
            e.state = 'warn'; e.wc = c.c; e.wr = c.r; e.wms = 1500;
            mark(c.c, c.r);
            sfx([[330, 60]]);
            if (!G.seen[e.kind]) { G.seen[e.kind] = true; setMsg(INTRO[e.kind], false, 2000); }
        }
        function playTick() {
            var i, e, ph, f, n;
            G.playMs += TICK; G.animMs += TICK;
            if (G.moveCool > 0) { G.moveCool -= TICK; }
            if (G.chompMs > 0) { G.chompMs -= TICK; if (G.chompMs <= 0) { mark(G.pc, G.pr); } }
            if (G.invMs > 0) { G.invMs -= TICK; if (G.invMs <= 0) { mark(G.pc, G.pr); } }
            if (G.biteLock > 0) { G.biteLock -= TICK; }
            for (i in G.notes) { if (G.notes.hasOwnProperty(i) && G.notes[i] > 0) { G.notes[i] -= TICK; if (G.notes[i] <= 0) { delete G.notes[i]; mark(+i % 5, Math.floor(+i / 5)); } } }
            for (i in G.pops) { if (G.pops.hasOwnProperty(i)) { G.pops[i].ms -= TICK; if (G.pops[i].ms <= 0) { delete G.pops[i]; mark(+i % 5, Math.floor(+i / 5)); } } }
            if (G.msgMs > 0) { G.msgMs -= TICK; if (G.msgMs <= 0) { G.msg = null; G.msgMs = 0; drawRow1(); } }
            ph = Math.floor(G.animMs / 200) % 2;
            if (ph !== G.ph && !H.reduced) {
                G.ph = ph;
                if (G.invMs > 0) { mark(G.pc, G.pr); }
                for (i = 0; i < G.enemies.length; i++) { if (G.enemies[i].state === 'warn') { mark(G.enemies[i].wc, G.enemies[i].wr); } }
                if (G.feast.state === 'on' && G.feast.left <= 1500) { mark(G.feast.c, G.feast.r); }
            }
            for (i = 0; i < G.enemies.length && G.mode === 'PLAY'; i++) {
                e = G.enemies[i];
                if (e.state === 'wait') {
                    if (G.playMs >= e.due) { trySpawn(e); }
                } else if (e.state === 'warn') {
                    e.wms -= TICK;
                    if (e.wms <= 0 && ((e.wc === G.pc && e.wr === G.pr) || enemyAt(e.wc, e.wr))) { e.wms = 500; }
                    if (e.wms <= 0) {
                        e.state = 'live'; e.c = e.wc; e.r = e.wr; e.acc = 0; e.lc = -1; e.lr = -1;
                        if (e.kind === 'REEVE') { f = reeveStart(e.c, e.r); e.hdir = f.hdir; e.vdir = f.vdir; }
                        mark(e.c, e.r);
                    }
                } else {
                    e.acc += TICK;
                    while (e.acc >= e.iv && G.mode === 'PLAY') { e.acc -= e.iv; enemyStep(e); }
                }
            }
            if (G.mode !== 'PLAY') { flush(); return; }
            f = G.feast;
            if (f.state === 'wait') {
                f.ms -= TICK;
                if (f.ms <= 0) {
                    n = pickFeast({ tiles: G.tiles, pc: G.pc, pr: G.pr, taken: takenList() }, rng);
                    if (!n) { f.ms = 1000; } else {
                        f.state = 'on'; f.c = n.c; f.r = n.r; f.left = 5000; mark(n.c, n.r);
                        if (!G.seen.FEAST) { G.seen.FEAST = true; setMsg('FEAST! NOBODY FIGHTS AT FEAST.', false, 2000); }
                        sfx([[660, 50]]);
                    }
                }
            } else {
                f.left -= TICK;
                if (f.left <= 0) { f.state = 'wait'; f.ms = 7000; mark(f.c, f.r); } else if (f.left % 1000 === 0) { mark(f.c, f.r); }
            }
            flush();
        }
        function biteWipe(f) {
            var x = Math.round(f * 300) - 8;
            H.sprites([{ s: 'biteS', x: x - 293, y: 0 }, { s: Math.floor(f * 10) % 2 ? 'pMK' : 'pMKS', x: x - 8, y: 76 }]);
        }
        function tick() {
            if (G.sfxWait > 0) { G.sfxWait -= TICK; if (G.sfxWait <= 0) { G.sfxWait = 0; pumpSfx(); } }
            var m = G.mode;
            if (m === 'PLAY') { playTick(); }
            else if (m === 'CARD') {
                G.cardMs += TICK;
                if (!H.reduced && G.cardMs >= 2200) { enterPlay(); }
            } else if (m === 'HOLD') {
                G.holdMs += TICK;
                if (!H.reduced && G.holdMs >= (G.touch ? 1500 : 2600)) { endHold(); }
            } else if (m === 'CLEAR') {
                G.clearMs += TICK;
                if (!H.reduced && G.level < 6 && G.clearMs > 500) { biteWipe((G.clearMs - 500) / 500); }
                if (!H.reduced && G.clearMs >= 1000) { afterClear(); }
            }
        }
        function move(dx, dy) {
            var c = G.pc + dx, r = G.pr + dy;
            if (G.moveCool > 0 || !onBoard(c, r)) { return; }
            mark(G.pc, G.pr);
            G.pc = c; G.pr = r; G.moveCool = 100;
            mark(c, r); flush();
            collide();
        }
        function munch() {
            var idx = G.pr * 5 + G.pc, t = G.tiles[idx], f;
            if (G.biteLock > 0) { return; }
            G.chompMs = 150; mark(G.pc, G.pr);
            if (t.blank || feastAt(G.pc, G.pr)) { sfx([[200, 30]]); flush(); return; }
            if (t.ok) {
                G.tiles[idx] = blankTile();
                G.pops[idx] = { n: 10 * G.level, ms: 450 };
                if (countTargets() > 0) {
                    f = [659, 698, 784, 880, 988, 1047, 1175, 1319, 1397][Math.min(G.streak, 8)];
                    sfx([[f, 30], [Math.round(f * 1.5), 40]]);
                } else if (G.level >= 6) { sfx([[1047, 60], [1319, 60], [1568, 120]]); }
                G.streak += 1;
                award(10 * G.level);
                flush();
                checkClear();
                return;
            }
            startHold(holdMessage(G.level, variantFor(G.level), t), false);
        }

        /* ----- input ----- */
        function quitNow() { saveBest(G.won, G.level); H.quit(); }
        function onKey(k) {
            var m = G.mode;
            if (m === 'BUSY') { if (k === 'Q') { quitNow(); } return; }
            if (m === 'TITLE') {
                if (k === 'SPACE' || k === 'ENTER') { beginGame(); } else if (k === 'H') { showHow(1); } else if (k === 'Q') { H.quit(); }
                return;
            }
            if (m === 'HOW1' || m === 'HOW2') {
                if (k === 'Q') { showTitle(); } else if (k === 'ENTER' || k === 'SPACE') { if (m === 'HOW1') { showHow(2); } else { showTitle(); } }
                return;
            }
            if (m === 'CARD') {
                if (k === 'Q') { quitNow(); } else if ((k === 'ENTER' || k === 'SPACE') && G.cardMs >= 500) { enterPlay(); }
                return;
            }
            if (m === 'SCENE') {
                if (k === 'Q') { quitNow(); } else if (k === 'ENTER' || k === 'SPACE') { showCard(); }
                return;
            }
            if (m === 'END') {
                if (k === 'Q') { H.quit(); } else if (k === 'ENTER' || k === 'SPACE') { showTitle(); }
                return;
            }
            if (m === 'CLEAR') {
                if (k === 'Q') { quitNow(); } else if (H.reduced && (k === 'ENTER' || k === 'SPACE')) { afterClear(); }
                return;
            }
            if (m === 'QUIT') {
                if (k === 'Q') { quitNow(); return; }
                G.mode = G.prev;
                if (G.mode === 'HOLD') { setMsg(G.msg.text, true, -1); } else { drawRow1(); }
                return;
            }
            if (k === 'Q') {
                G.prev = m; G.mode = 'QUIT';
                H.fill(0, 1, 40, 1, ' ', true);
                center(1, 'LEAVE THE FIELD? Q AGAIN = YES. ELSE NO.', true);
                return;
            }
            if (m === 'HOLD') {
                if ((k === 'ENTER' || k === 'SPACE') && G.holdMs >= 800) { endHold(); }
                return;
            }
            if (m === 'PLAY') {
                if (k === 'W' || k === 'UP') { move(0, -1); }
                else if (k === 'S' || k === 'DOWN') { move(0, 1); }
                else if (k === 'A' || k === 'LEFT') { move(-1, 0); }
                else if (k === 'D' || k === 'RIGHT') { move(1, 0); }
                else if (k === 'SPACE' || k === 'ENTER' || k === 'M') { munch(); }
            }
        }

        showTitle();
        H.keys(onKey);
    };

    window.OrkpleGames.munchorks = game;
})();
