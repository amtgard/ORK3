/* ASTERORKS: an ORKPLE II game. An archer, a wrapping field, and a horde that breaks apart when hit.
   Runs against the terminal's game host; touches nothing but window.OrkpleGames.
   A/D turn (a tap turns one step; holding keeps turning at about 180 degrees a second when the host reports held keys),
   W run, S brake, SPACE shoot. */
(function () {
    'use strict';

    var TICK = 40, DT = TICK / 1000;
    var TURN_MS = 250;				// one 45 degree step per 250ms while A or D is held: 180 degrees a second
    // The field is drawn as pixel sprites on the host's 280x192 pixel grid (a text cell is 7x8 pixels).
    // Row 0 is the status line and row 23 the message line, so the field is the 280x176 pixels between them.
    var W = 280, H_PX = 176, TOP_PX = 8;
    var MSG_ROW = 23;

    // The one scaling rule: round N has round(BASE_ZOMBIES * 1.1^(N-1)) zombies, all moving at BASE_SPEED * 1.1^(N-1).
    var BASE_ZOMBIES = 12, BASE_SPEED = 17;		// pixels per second
    var GROWTH = 1.1;
    var SIZE_MUL = { 4: 1, 2: 1.35, 1: 1.8 };	// splits fly off faster, as in Asteroids
    var SIZE_PTS = { 4: 20, 2: 50, 1: 100 };
    var SPLIT_SPREAD = [0.5, 1.1];			// radians away from the parent's heading, each side
    var ZR = 4.5;					// a zombie is a 9 pixel circle
    var OFFS = { 4: [[-4.5, -4.5], [4.5, -4.5], [-4.5, 4.5], [4.5, 4.5]], 2: [[[-4.5, 0], [4.5, 0]], [[-3, -3], [3, 3]], [[0, -4.5], [0, 4.5]], [[3, -3], [-3, 3]]], 1: [[0, 0]] };
    // a pair is drawn along its heading: 0 horizontal, 1 down-right, 2 vertical, 3 down-left
    var PAIR_SPRITE = ['z2', 'z2d', 'z2v', 'z2u'];
    var GRAZE = 2.5;				// arrows are generous: a shaft within this of a circle counts
    var BODY_R = 2.5;				// the archer's hurt-box is smaller than the drawing

    var RUN_IMPULSE = 31, MAX_RUN = 67, FRICTION = 1.1, BRAKE_FRICTION = 7, BRAKE_MS = 450, BRAKE_CUT = 0.45;
    var ARROW_SPEED = 180, ARROW_RANGE = 135, MAX_ARROWS = 4;
    var START_X = 140, START_Y = 88;
    var SAFE_MS = 2200, SAFE_R = 45, WAIT_MAX_MS = 2600, DIE_MS = 1400;
    var RUNNER_COOLDOWN = 1500, RUNNER_BAD = 12;	// one in twelve calls goes badly on purpose
    var NECRO_FIRST = 10000, NECRO_EVERY = 13000, NECRO_SPEED = 52, NECRO_BOLT_MS = 1800, BOLT_SPEED = 60, BOLT_LIFE = 3600, NECRO_PTS = 500;
    var NECRO_HW = 6.5, NECRO_HH = 7, BOLT_R = 3;
    var EXTRA_EVERY = 10000;
    var WIN_ROUND = 5;
    // The Hunger clock: it runs only while the field is live, never resets between rounds, and tightens the screws.
    // Zombie count and speed never change with it (the 1.1x rule stands); only heading, the necromancer, and his aim do.
    var STAGES = [
        { at: 0, turn: 0, every: 13000, boltMs: 1800, boltSp: 60, lead: false, msg: '' },
        { at: 90, turn: 6, every: 11000, boltMs: 1600, boltSp: 65, lead: false, msg: 'THE DEAD HAVE CAUGHT YOUR SCENT.' },
        { at: 150, turn: 12, every: 9000, boltMs: 1400, boltSp: 70, lead: false, msg: 'THE HORDE IS GETTING IDEAS.' },
        { at: 210, turn: 20, every: 7000, boltMs: 1200, boltSp: 80, lead: true, msg: 'THE HOODED ONE IS AIMING NOW.' },
        { at: 270, turn: 35, every: 5000, boltMs: 1000, boltSp: 90, lead: true, msg: 'THE REEVE CHECKS THE TIME.' },
        { at: 330, turn: 60, every: 3000, boltMs: 800, boltSp: 100, lead: true, msg: 'LAST CALL. EVERYONE WANTS YOU.' }
    ];

    // Facings clockwise from north. dx, dy are screen steps (y grows downward).
    var FACE = [[0, -1], [1, -1], [1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1]];
    var KEYMAP = { A: 'LEFT', LEFT: 'LEFT', D: 'RIGHT', RIGHT: 'RIGHT', W: 'RUN', UP: 'RUN', S: 'BRAKE', DOWN: 'BRAKE' };

    // Help is two pages of 23 lines; sprites sit in the blank left column. Line text is padded so the log reads in order.
    var HOW = [
        '              HOW TO PLAY', '',
        ' THE DEAD WALK THE FIELD IN CLUMPS.',
        ' SHOOT A CLUMP AND IT BREAKS UP.', '',
        '       FOUR CIRCLES  A CLUMP',
        '       HIT: TWO PAIRS       20 POINTS', '',
        '       TWO CIRCLES   A PAIR',
        '       HIT: TWO SINGLES     50 POINTS', '',
        '       ONE CIRCLE    A SINGLE',
        '       HIT: STAYS DOWN     100 POINTS', '',
        '       THE HOODED ONE IS A NECROMANCER.',
        '       SHOOT HIM: 500 POINTS.', '',
        '       DODGE HIS BOLTS.', '',
        'EVERY ROUND IS TEN PERCENT MORE ZOMBIES',
        'AND TEN PERCENT FASTER. NO ONE ASKED.', '',
        '             SPACE FOR THE KEYS'
    ];
    var HOW_ART = [
        { s: 'rule', x: 0, y: 12 }, { s: 'z4', x: 16, y: 38 }, { s: 'z2', x: 16, y: 65 }, { s: 'z1', x: 21, y: 89 },
        { s: 'nec', x: 18, y: 113 }, { s: 'bolt', x: 22, y: 137 }, { s: 'rule', x: 0, y: 148 }
    ];
    var HOW2 = [
        '               THE KEYS', '',
        ' A   D    TURN', '',
        ' W        RUN', '',
        ' S        DIG IN AND STOP', '',
        ' SPACE    SHOOT (FOUR ARROWS AT A TIME)', '',
        ' H        CALL A RUNNER (RANDOM, RISKY)', '',
        ' P        HOLD   Q   LEAVE THE FIELD', '',
        '           THE FIELD WRAPS AROUND',
        '           ON ALL SIDES.', '',
        '           DAWDLE AND THE DEAD',
        '           NOTICE YOU.', '',
        'CLEAR ROUND 5 AND THE FIELD IS YOURS.', '',
        '             PRESS RETURN'
    ];
    var HOW2_ART = [
        { s: 'rule', x: 0, y: 12 }, { s: 'key1', x: 5, y: 13 }, { s: 'key1', x: 33, y: 13 }, { s: 'key1', x: 5, y: 29 }, { s: 'key1', x: 5, y: 45 },
        { s: 'key5', x: 5, y: 61 }, { s: 'key1', x: 5, y: 77 }, { s: 'key1', x: 5, y: 93 }, { s: 'key1', x: 117, y: 93 },
        { s: 'a7', x: 196, y: 16 }, { s: 'a0', x: 218, y: 16 }, { s: 'a1', x: 240, y: 16 }, { s: 'r2', x: 260, y: 20 }, { s: 'wrap', x: 7, y: 113 }
    ];

    var TWINKLE = [[128, 54], [36, 114], [262, 120], [150, 112]];

    var DEATH_LINES = [
        'A ZOMBIE TAGGED YOU. TAKE A KNEE.',
        'YOU HAVE BEEN TOUCHED BY THE UNDEAD.',
        'DEAD. THE REEVE SAW IT. YOU SAW IT.',
        'NOT A LIGHT HIT, BUT THE REEVE ALLOWS.',
        'THAT WAS A CLEAN HIT AND YOU KNOW IT.',
        'OFF TO NIRVANA FOR A COUNT OF 300.',
        'YOU CALLED IT YOURSELF. GOOD SPORT.'
    ];
    var BOLT_LINES = [
        'THE NECROMANCER HIT YOU. IT WAS BLUNT.',
        'A BOLT TO THE CHEST. CALL IT YOURSELF.'
    ];

    /* ----- pure helpers ----- */
    function pad(n, w) { var s = String(n); while (s.length < w) { s = '0' + s; } return s; }
    function mod(a, m) { return ((a % m) + m) % m; }
    function countFor(round) { return Math.round(BASE_ZOMBIES * Math.pow(GROWTH, round - 1)); }
    function speedFor(round) { return BASE_SPEED * Math.pow(GROWTH, round - 1); }
    function speedMul(round) { return Math.pow(GROWTH, round - 1); }
    // Divide a round's total into fours, with the remainder as a pair and/or a single.
    function splitTotal(total) {
        var out = [], i;
        for (i = 0; i < Math.floor(total / 4); i++) { out.push(4); }
        if (total % 4 >= 2) { out.push(2); }
        if (total % 2 === 1) { out.push(1); }
        return out;
    }
    // The circles a cluster is made of, in field pixels: what is drawn is what can be hit.
    function circlesOf(z) {
        var offs = z.n === 2 ? OFFS[2][z.o || 0] : OFFS[z.n];
        return offs.map(function (o) { return [z.x + o[0], z.y + o[1]]; });
    }
    // the orientation (0 to 3) a pair is drawn in for a heading, given as a velocity
    function orientOf(vx, vy) { return Math.round(((Math.atan2(vy, vx) + 2 * Math.PI) % Math.PI) / (Math.PI / 4)) % 4; }
    function stageFor(clockMs) { var i, k = 0; for (i = 0; i < STAGES.length; i++) { if (clockMs >= STAGES[i].at * 1000) { k = i; } } return k; }
    function wdist(ax, ay, bx, by) {
        var dx = Math.abs(ax - bx), dy = Math.abs(ay - by);
        dx = Math.min(dx, W - dx);
        dy = Math.min(dy, H_PX - dy);
        return Math.sqrt(dx * dx + dy * dy);
    }

    /* ----- pixel art ----- */
    // turn a sprite 90 degrees clockwise
    function rot90(rows) {
        var h = rows.length, w = rows[0].length, out = [], i, j;
        for (i = 0; i < w; i++) { out.push(''); for (j = 0; j < h; j++) { out[i] += rows[h - 1 - j][i]; } }
        return out;
    }
    /* ASTERORKS art: one 1-bit encoding and every sprite. Hand drawn and generated for this game; see asterorks-art.md. */
    var ART_AL = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz+/';
    // Packed bitmap: 6 bits a character, row by row. '~x' is a run of zero sextets, '_x' a run of all-ones sextets (x+3 long).
    function artBits(s, n) {
        var out = [], i, c, k, b, r;
        for (i = 0; i < s.length; i++) {
            c = s.charAt(i);
            if (c === '~' || c === '_') { r = ART_AL.indexOf(s.charAt(++i)) + 3; for (k = 0; k < r * 6; k++) { out.push(c === '_' ? 1 : 0); } } else { b = ART_AL.indexOf(c); for (k = 5; k >= 0; k--) { out.push((b >> k) & 1); } }
        }
        return out.slice(0, n);
    }
    // off is what an unlit pixel becomes: 'o' opaque dark, '.' clear. mask (optional, same encoding) marks pixels forced to 'o'.
    function artRows(a, mask) {
        var w = a[1], h = a[2], bits = artBits(a[0], w * h), m = mask ? artBits(mask, w * h) : null, rows = [], x, y, r;
        for (y = 0; y < h; y++) { r = ''; for (x = 0; x < w; x++) { r += bits[y * w + x] ? '#' : (m && m[y * w + x] ? 'o' : a[3]); } rows.push(r); }
        return rows;
    }

    var ZOMBIE_A = {z1: '..#####..,.#######.,#########,##oo#oo##,##oo#oo##,####o####,##o#o#o##,.#######.,..#####..'.split(','), z2: '..#####....#####..,.#######..#######.,########o##oo#oo##,##oo#oo#o##oo#oo##,##oo#oo#o#########,####o###o###ooo###,##o#o#o#o##ooooo##,.#######..##ooo##.,..#####....#####..'.split(','), z2d: '..#####........,.#######.......,#########......,##oo#oo##......,##oo#oo##......,####o###o......,##o#o#oo#####..,.#####o#######.,..###o##oo#oo##,......##oo#oo##,......#########,......###ooo###,......##ooooo##,.......##ooo##.,........#####..'.split(','), z2v: '..#####..,.#######.,#########,##oo#oo##,##oo#oo##,####o####,##o#o#o##,.#######.,..#####..,..ooooo..,.#######.,##oo#oo##,##oo#oo##,#########,###ooo###,##ooooo##,.##ooo##.,..#####..'.split(','), z2u: '........#####..,.......#######.,......#########,......##oo#oo##,......##oo#oo##,......o###o####,..#####oo#o#o##,.#######o#####.,##oo#oo##o###..,##oo#oo##......,#########......,###ooo###......,##ooooo##......,.##ooo##.......,..#####........'.split(','), z4: '..#####....#####..,.#######..#######.,########o##oo#oo##,##oo#oo#o##oo#oo##,##oo#oo#o#########,####o###o###ooo###,##o#o#o#o##ooooo##,.#######..##ooo##.,..#####....#####..,..ooooo....ooooo..,.#######..#######.,########o#o#o#oo##,#oo###ooo##o##oo##,#oo###ooo#o#o#####,########o#########,#oooooooo#ooooo###,.#o#o#o#..##ooo##.,..#####....#####..'.split(',')};
    var ZOMBIE_B = {z1: '..#####..,.#######.,##oo#oo##,##oo#oo##,#########,####o####,##ooooo##,.#o#o#o#.,..#####..'.split(','), z2: '..#####....#####..,.#######..#######.,##oo#oo#o#########,##oo#oo#o##oo#oo##,########o##oo#oo##,####o###o#########,##ooooo#o###ooo###,.#o#o#o#..#ooooo#.,..#####....#####..'.split(','), z2d: '..#####........,.#######.......,##oo#oo##......,##oo#oo##......,#########......,####o###o......,##oooooo#####..,.#o#o#o#######.,..###o#########,......##oo#oo##,......##oo#oo##,......#########,......###ooo###,.......#ooooo#.,........#####..'.split(','), z2v: '..#####..,.#######.,##oo#oo##,##oo#oo##,#########,####o####,##ooooo##,.#o#o#o#.,..#####..,..ooooo..,.#######.,#########,##oo#oo##,##oo#oo##,#########,###ooo###,.#ooooo#.,..#####..'.split(','), z2u: '........#####..,.......#######.,......##oo#oo##,......##oo#oo##,......#########,......o###o####,..#####oooooo##,.#######o#o#o#.,#########o###..,##oo#oo##......,##oo#oo##......,#########......,###ooo###......,.#ooooo#.......,..#####........'.split(','), z4: '..#####....#####..,.#######..#######.,##oo#oo#o#########,##oo#oo#o##oo#oo##,########o##oo#oo##,####o###o#########,##ooooo#o###ooo###,.#o#o#o#..#ooooo#.,..#####....#####..,..ooooo....ooooo..,.#######..#######.,#oo###ooo#o#o#oo##,#oo###ooo##o##oo##,########o#o#o#####,########o###ooooo#,#o#o#o#oo###ooooo#,.ooooooo..###ooo#.,..#####....#####..'.split(',')};
    var ARCHER_N = '........#........,.......###.......,....####.####....,..###...#...###..,.##.....#.....##.,.#......#......#.,..#.....#.....#..,...#...###...#...,....#o#####o#....,.....o#####o.....,....oo#####oo....,...###ooooo###...,...###########...,..#####.#.#####..,..####..#..####..,..###...#...###..,..#.....#.....#..'.split(',');
    var ARCHER_NE = '..............###,......###......##,....##...##...#.#,....#......#.#...,.....#......#....,......#....#.#...,.......#..#...#..,.......###....#..,.....o#####....#.,...#oo#####....#.,..##oo#####.#..#.,.###.o#####..#.#.,####..ooo.....##.,.#####.##.....#..,..#####..........,...####.##.......,....##...........'.split(',');
    var ARROW_N = '...##...,..####..,.######.,.#.##.#.,...##...,...##...,...##...,...##...,..####..,.##..##.,.#....#.'.split(',');
    var ARROW_NE = '......#####,.......####,.......####,......##.##,.....##...#,....##.....,...##......,.###.......,##.#.......,##.........,#..........'.split(',');
    var PUFF_ROWS = '#..#..#,.......,..o.o..,#.....#,..o.o..,.......,#..#..#'.split(',');
    var NECRO_ROWS = '....#####....#.,..#########.###,.###########.#.,.###ooooo###.#.,.##ooooooo##.#.,.##o#ooo#o##.#.,.##ooooooo##.#.,..##ooooo##..#.,.###########.#.,###.#######.###,#...#######...#,....#######..#.,...#########.#.,...#.##.##.#.#.'.split(',');
    var NECRO_ROWSB = '....#####....#.,..#########.###,.###########.#.,.###ooooo###.#.,.##ooooooo##.#.,.##oo#o#oo##.#.,.##ooooooo##.#.,..##ooooo##..#.,.###########.#.,###.#######.###,#...#######...#,....#######..#.,...#########.#.,..#.##.##.#..#.'.split(',');
    var BOLT_ROWS = '...#...,.#.#.#.,..###..,###o###,..###..,.#.#.#.,...#...'.split(',');
    var BOLT_ROWSB = '#.....#,.#.#.#.,..###..,.##o##.,..###..,.#.#.#.,#.....#'.split(',');
    var LIFE_ROWS = '...#...,.#####.,#..#..#,...#...,..###..,.##.##.,.#...#.'.split(',');
    var BURST0 = '.................,.................,.................,........#........,........#........,.....#..#..#.....,........#........,.......###.......,...#####o#####...,.......###.......,........#........,.....#..#..#.....,........#........,........#........,.................,.................,.................'.split(',');
    var BURST1 = '..........#......,....#....#.......,.........#.......,.....#...#.......,.........#.....#.,......#......#...,#..........#.....,.####............,.................,............####.,.....#..........#,...#......#......,.#.....#.........,.......#...#.....,.......#.........,.......#....#....,......#..........'.split(',');
    var BURST2 = '........#........,.................,........#........,.................,....#.......#....,.................,.................,.................,#.#...........#.#,.................,.................,.................,....#.......#....,.................,........#........,.................,........#........'.split(',');
    var KEY1 = '.##########.,#..........#,#..........#,#..........#,#..........#,#..........#,#..........#,#..........#,#..........#,#..........#,############,.##########.,..########..'.split(',');
    var KEY5 = '.######################################.,#......................................#,#......................................#,#......................................#,#......................................#,#......................................#,#......................................#,#......................................#,#......................................#,#......................................#,########################################,.######################################.,..####################################..'.split(',');
    function ringed(rows) {
        var g = rows.map(function (r) { return r.split(''); }), k;
        for (k = 0; k < 24; k++) { if (k % 3 !== 2) { g[Math.round(8 + 8 * Math.sin(k * Math.PI / 12))][Math.round(8 + 8 * Math.cos(k * Math.PI / 12))] = '#'; } }
        return g.map(function (r) { return r.join(''); });
    }
    function flipRows(rows) { return rows.map(function (r) { return r.split('').reverse().join(''); }); }
    var ART = {
        title: ['~/~/~/~/~F1/+00V_03_13_13_0m~23_0m3/mF/0V_0~07/u03_0yF_0yF_0yF_0W0F/m0F_0WF/1/u3_0y~0//W0V_0o_1o_1o_0+03//m2_0+2/y7/WV_0m003/+03_1J_1J_1J_0y0z//mJ_0yJ/m/y3_1~0l/u0V_0uV_0ul_0ul_0u7_0Wl_0ul/7/WV_0u004//m1_1b_1a_1a_1Wrt//4_1a/yVy1_1W007//07_0+7_0+7_0+7/0F+7_0+7/0F+7/Z/m7_0+001V/y1V_0vV_0vV_0vVzK/urt//yVzK/vV+V+1V_0u001/Vm9/u0080F+01/u001/oh/Zl_0n/oh/X/v/m9/u~2Fz/0N/bLGLK/vGN/bLGN/1F+LLT//d/1F+N/l+0N/bLG002/dy2Vygg2gh/g2V+gg2Vy1/nl_0+Vy1/oV//u2Vygg~03+Vm3/m~01F+05/u005/WN/5LLl/y/WN/5_003/m~2Vv/0l_0m01/m0d/W00d+0Vyk0U0Vp+0Vyd//u0l_0m005/d+0_100N/01V//y1Vu5/rG1m1/lu5/nV//00_1~07yVuB_0+00Vy09_0m9/W7/AW7W7+/W7/9//y0B_0+001Vr/XF_0u05/m0N_00N_0yL060Vx_0yN//W1F_0u00B/7+2_1m0d/02V//y2V_0Ye0u1/l_0YV/+02_1m~0100G~21~04~14~11G107U~14~1G~32~1W~28~0W~1W~1h0A0Vu~1W~1W~3N+7+5F_0m0N/00_0u0_0+HLLDNTl//+0_0W5F_0m00V//uAV_002Vy0B_0WB_0wAkyV/+_0uB//+0AV_0005_0WK00/y03/W1F/001F_0a5LHLLR_0XF//y0K00/y00d_00ggh/m0l+02/ygW2/yV+Ygw3//FyV+2_0m0ggh/m~31LL~4G05K0G04014LLLTK0400G~11LL~18~32~08~0W~1W~02AeH2VW~1W~32~1F_0~03/W1F+04/u004/zF/G0X49MVzF/4/z/u~03/W01_0y7_0+02/u0B_0+B/o/yYg4Glp/o/yB/t/m7_0+00N_0mV_0u0J/W1F_0vF/J/r18H2NF/J/nF+F/0V_0u~AW002~1200W0AAX4Eu00W0202~8400G~24~0G~1G0400K~0L00400G~1G~3l+h/wV_0m07/00V_0uV+B/mWggkuV+B/mV+d/YV_0m00/v7/b_101Vy05_1b/v7/X0HLN5/v7/b/vV/5_100B/YV+d_0u01/m0d_0+d/YV+AWggmd/YV+d/f/yd_0u01VyL/vV_0W0N/01V_0vV+L/uL00G1V+L/vV+J/vV_0W01/md/f_0y02Vy09_1f/ud/WA~09/ud/f/wl/f_0y00G0100G~24~0G~1G01~0L010G0100G0400G~22gWAgYggge00ge02ggggYgeAgW0ggW2geAgYgeAgYggge005K0LK5LLL001L005LLLK5L0LK00LG05L0LK5L1LK5LLL~/~/~N8~/~/~1G~48~/~g2~O2~C80F/uW0W~KS~D3//u~08~8F/m~07/u00W~0Vm~8_0u~B3//m001//u~27/m~7F_0u~BtV/mG0VV/u~2z/W008~31tV//m~A7_0W03_0m~17//001pu~2Ex_0W~ArT//W0rT//W~1rt+002Vm~11LLN//~A7_107_1~0W3//u0039W~1802//+~38~3rT//y0LT//+~1C63m00Cc~41V/u~21m~26_1u2_1y~1WOF~0/u~43//m~22~3LLt//mLLt//m~0500q003ZW~45//~92_21_2W~0C03m00C6~5F/+~9DLt//+DLt//+~1KLt~0Om~5N/u~9g0V0Fuh_1y~02gVy~0+~6//m~81G1S0/XK1S0/m~01HL7y~81V/~9BW7m3/Bu7u3/~1gbP/y~83/y~81K0T0FzL0TWF+~01QLD/u~85/m~79Ae0u0/ok1+0/u~02fP//m~01~4F/~9501W1t5G5m1tW~1LDN/W~71Vy~58002k0E0FykWVWF+~1Ail/+~83/u~41~05G0W0/HLLNT/O~1171Xy~87/~9ghE+//Ahl_0W~2E67m4~7/y~9LLHLtKLLLLtM~3GON~81Vm~27/W~02hl7//ohl_0m~22XXy~8F/~3V+~11LG5tU1LLLNT~3LLtG~8Vy~27/+~1ggW//ugepTly~3fg/~83/m~2V/w~14001LW4~0LW~2101S~3G~15+~27//u~0AY4Gdy2e003+~3A07W~23W~1/u~2V//W~048H2LG45LLLG~310M~3U~11/~21uOU~1gX4Bk0AYCpl~3Wghm~23e~1Fy~27XXu~124GbG01LLLQ~4HM~3uG~1NW~2U67W~02eH2l02ggxl8~32hm~270W~03+~21uOU~115LLO015LLOW02~114~3u1~1Nm~27+Vu~1Aggg002ggh210G~8304~1+~3Vv/W~2H5m~0H5O442~9808~05m~21vcU~2ggi~0Agk0GGG~81W0G~0+~37cPu~62~1111~9401~05W~37/+~B44882~8m02~0u~4V/u~2F/m~07/u88X~92004004~5V+~23//m001//uGY8~9O00G~71/u~2tV/m00VV/uW1~4G~11~0W~E7_0W03_0n003~8C001~FrT//W0rT//W01m~8m002~E7_107_100OW~73~08~BG00rT//y0LT//+00U~88~0H~D6_1u2_1y07u~71W~0d/~CLLt//mLLt//m3/W~76~03/+~B2_21_2W3//m~6O~0//y~BDLt//+DLt//+07/0//~41W003z/uG~Ag0V0Fuh/l0Fy07ZW07/u~26~08d/c~A1G1S0/XLKS3Vm2E~2V/W020G~0XV/e~7C00BW7m3/BuZm1/048~3201u3~06p//~8/01K0T0FzLGT07/48~6VWC~0S7/y~73duAe8uG/YkZy0VyGG~5F_101yV/u~7Fy0501W1s5K5m1tmWW~57u30F_2~8+02k0E0FukYF07+2~77WC0/047/+~73005G0m0/5LKK0VO8~720G0/0F//O~7800ghF+/+AhhU//WW00C~51W0VWJ/pW~3C~0W00LLLLtKLLGL/M1~0/~5600VWV/S~4/002002hl_0ohlZ//m4007du~4O00FoFpX~33du0S001LLLtU1LK5tT0G0WFy~3G1W00F/Yy~4Fy02m00gg0F/uge01/y1~0+~56~07/d~5+00/u005G0LLW5005LW2003~68~0D+um~3300Kvm0AgW1/y2g00V+08008~6m001Nt4W~3800jjW04K05LG4K01LG~1W~483~0DFOb~4W05Mh00gW0Bk0Ae0Bl~12~6C~0oPid~3200ggg00100LG0100LO~1S~6G007ajac0W~1S06grN02g0Bl02g03l~12m~51W00k8paa7~16q0rhMi0141LO01405O~1/u0G~32004wciai8~1/u6qjfO0Aggg002Ygh~14vo~5C00JeQqae~14vossssm00H5mW00H5O~1jjW~5G02EZBaam~0ejjcJR9jW0ggi~0Agk~15MhA~41W0Iwiiab~1DMhV_1001402W014~2ggg2~4282dgIqac~1gggjjjji~48~36grM~4AC0eUeZaaa0027//yIIIIG~22e~2A8rhMi202~2ufXwfCaae~0MssogYgg2~3802~027_0mW8YW0081m8~04aaW021999AgAgeYW2~6e0Djjj0A80W0203X0Ugf4abW0W2e2egWAgW0YW~8IIIG~1e0W03a1wgGaacg80AWAYg0gg00W~4e0W02gYgW~57W7geyaae200g0gAe2ge~78e00Ae2g~2AW00E0Ughaaam002e2egWAgW~78~0gWAe~12~1G3wgkaaaW00AWAYg0gg~B2g0gW~7Fggyakb~0g0gAe2ge~BAe2g~8_0aatq~a3//+ab0u~aF/V+ac~b1/v/tae~1G00W~0W00W~0G~0G~0G~0W00402~0W7/3/3am~b/uFy1r~080800W00W0800G0080800200W100G00W04020//0Vu2+1~ZyV_0W0O~Zy3/W3/m~180W8204080W2020102040G0W080820100G1y0/y0Fyy0G4~Wy0F/00VuE~010W84410GGWGWG4810G820GW8GG224410Wy03/m01/W7eG48410WW82242420X08210G241220GGWW84y00F+001/W1w3y~S1y~0/W003/W0Vm12228888H22128WH22210X4G88GX0Y43uX~1X~0GW~08GGH11128GG8H428GGG848Y112484GWu48~0480024~VC~C4AKAALGeegYgAAA5555AeKKKKAAAAAB0012~012~0X~0WGWGGY11144GGGG8888H0WWWWGGGGGG008G~08G0048~T6~D5KfGfIbKAKgbKKeKgXGeeLIb5KKAA5W~024~0240012008X21248WGX48WX0X42110Y488WWGGC~1GW~0GW008G~R3~/~g', 280, 160, 'o'],
        lose: ['~/~/~v1/y3yVn//u007/mF//003y00F/0/7yV/+7//~8F/uFn/7//W00//W//y00Fm00/y3yVn//u//y~73//o/7yV/y00F//B//W02/00B/Wln+7//7//W~7F//3zVp//m00//yF/+003y00V+0/FvV/yV/+~72+1ylX/F//00Bu7o//u00lW01/uBy/X//n//u~73vNn+Nv1+~0FbV3u~1+00H+0NpyNm0Nu~9lWV7uVgdw002+1yVge007u00du2VFm/00VW~9+5zVb+1VW003uNn+~1VW01VW5//3/+5//W~7Bu7n+Fu1y~0lWV7/y001+001+07/ul/u7//~8FXVNu/WNm~0+5zV/m00Nu00Nu1V/W//XV/y~C8~L8~G4~04~4G~14~3G~2G~AV2+7o/W7m001yBuV/m007m00Fm0V/2//Wl/+~75y3u/3y0/~0NmFb+~01V~0/05/u3y010Fu~77mlZyFm3y~0V2+7wW001y00By07/WlW~0/~71V0+F//0Fm005y3vVW~0N//0Fm1F+0//u//y~A2~02~G2~GN//Z//m3u001V/+Fm~03//mF/03/0V/+F//~8l/yd/+0lW002//o/~1l//2/y0ly1//w//u~74//XV/m0+~0J/+3y~1//y3/m0/WN//Z//W~79/y2/+0Bu~0d/mlm~0B//ml/0B+0V/+l/y~8G00K~0G~01001~2G001~0G04001~Bge0gg02e~02gWAW~02ggWAg02g0AggAge~/~vG~/~o8~/~/~/~JF/Y~6W01~S1/+~51/y~GFW~68007/u~5F/u~F1/~A//W~43//u~FCc~A/u~5V//m~A2~1oO~BW~43_0W~AS~03/W~A2~61/+~FEE~91IC~61/y~6+~06~1mO~BW~63/u~57y~0O~01Z~B2~77/W~5/u001W~03u~A/u~7F+~539W00604~D1/~8Vu~5Cc0FWO0y~EW~71/W~5zu1/1WVy~P3/~53LWF+63ty~0+008~08~1F/u~7FuG~47y0d8O0Vm007y~2FU~1d7W~7/W08~3FW2SXW1/~0gO~13/+~02SU~73+0F+~5F+657y003PW~1N/y~09nu~7Fu3/+~5W8S0Vm00A+~13//u~08uW~7/WLty~41L0u1/W00/u~1LT/m0013Y~73y3//u~43u1m7+00I3W~02_0W0VaE8~7FWL//m~637/u006C~02DT/+03+d7W~7+3_0W~66//W00FW~1u61y044SU~21~17mDNV+~7Shm~510G7m0ULnu~4800U0l//y~0W~3wg~6A9YV012OuY~63W5GS7m00/u~21oi~51G61y07BZY~7S0A3mV00F/u~22gW~3W0AWO7u0H6EC~9K51S01tVm~2AgW~2/u045GN01zt7G~8Amy7u0F//W~11gi~2F/u0k//y041ST~9LLtT00tV+~26ga~11t/m5LLtG0Vznq~9hl/y06//y~2QgW~1F//WAe7/01/uum~01~41LHLG0K43m~11giW~1rV+0H0LO07/ZZ~9AbjV00mOF~2Aga~16z/y2e0/W00UEC~9401O050Wy~2gga~1K23m1G1K00177G~82WREW0e23u~12giW~1mOF0AWhW004ST~AL5K01GMD~2Aga~111WS00LK100Hnq~9AhkW02lVy~2gga~1gY3u0ghW001uum~91LK005GTm~12geW~01LMD001~17ZZ~AghW00gbF~2AgaW~02kFy~4V/y~BG~0AKO~2gga~1505G~4EE8~3G~38~0AfNW00G0F/ya~1e0/~5uu~F2bS~2//4W~1G5O~43ZW~FghW~11/+aW~0AWFW~4EE~EW00O~03y7/ya~111S~5uu~G8~03mFV/qa~1ehW~43ZW~J1m03//aW~14O~5EE001~H4003/yaW~18~6uu~0AW0e0AW0e0AW0e0AW0e1gW0l/wW0e0AW0e0AW0e8AZZe0AW0geAg0geAg0geAg0geAh//+//WgeAg0geAg0geAg0//Ag0e~/~O80100G4080100W0200G801100W200W40W0100W080201011008020W10080400G0210088040G040W4008040100G0808~i81004100G0G00WW0210G0220880W0G0200404008080800H0800W80202004400G8200GG11040200G00W0W010101002~i20W10WW8040W20080800W20200W0W04080200G0G020G0W0G40844100W40G01010040G0G040400W100G020200G204~j', 280, 96, 'o'],
        win: ['~/~/~/~a//dpy//W0V/m/mV/pu0V+003/W//00+Fd/y+07/m~33/+VFp/+01//3/1//FW1/y00F+7/y03u+V/pu0V/W~3V/ry/F/m0N/uFyN/u+0N/m00/mV/m0FZv//FW5/+~31//7hy//00V/Y/mV/hu0U7W0B/1//02+ld/y+07Xy~3GV1UFZm005y05y5y0FW5vU00JuNm003v+V03u1UNm~3fy3u+V~0FW0dmFW1+0FXu02lW+~0ldvy0lW1uV~4NmF/v/y00/+1V0/+7m0+NW00+3/y00//d/Wy0Nby~4V2//d/m0B/u1yB/uV0BuU00Bul/m0B/+V+Bm0U7m~35y3/+V+00F/WNWF/by0F3m00V1V/W0F/r/uF05nU~47ml/v/u02/+0U2/+7m2yF001y9/+01//F/Xy0F1u~4+0+Vdm003u05u3u1V03my00NmG3u07ny+07m0yNW~33uBv+V~0lg07WlW1y0lBm00V00F00V7pu0V0BmU~4FWFdr/+00+01/W//N/uyF007+3/y05zVF/v//F1u~32+1yVF/u07m07+7/y//d/u00VuF/m07py//d/y//~43u7ry//W0V01VuV/p/+V/W05/W//01VFp/+V/p/u~4lWV7p/+01y01/X//F/v/y007+7/u01y/F/v//F/W~4+5zVF/u0Nm0N+N/y//d/m01VuV/W0Npy//d/y/y~480822~1W00W0W0800W~0202~1WW800W08~6K1KL5LG05G05K5LKLL5LG00LGLL005HKLL5LKLK~/~/~/~AG04020201~58~QW0G080804~5m~P200W0W100W~42W~P402020402~59W~M800G0808GG0G00G~2X~NG00W0GWW202002~222~NW010102480800G~284~1C~H100404880WX002~3WG~1+~H2008GGGW20400G~221~13/~H400WWW28G0W02~11084~1F/m~G801024811200G~1/WWG~1//u~GG02080Y48G02~17/20W~03//y~GW08GH20W210G~2482~1F/+~1W~8200100G108Y2882~3GW8~1//~1/u~73/W0201040a8H10G~199Y0W~03/~17Vm~7T/004228GY0W402~3482~1FW~1//W~63/+00844GW940W0G~3Ge8~1m~15V/~7L/y00G8G28WGY02~080013mW~02~2//+~63//u00WGW~04G0G00300FuE2~18~111Xu~6467W010Y8~0102~0m0041m8~1W~1867W~6eOU00204~18GG40C00V/+0W~02~2G0U~0W~2111u004~3220W3001ghe4~18~12W1y0/+~3A67m0G8~0800GG40G007gyWG~1W~155TW3/u0S03/0LLsO0WG00//W02006~0Qgo1~12~2kd+0F/W0W~02whuO10W0V//m0G01W001gh84~18~31u0m602~37WO2107_0m200O~06giWG~1W~1AfNW30O1/03/0e0U0G401_1m0G2~1wgg1~12~22bK0F/WV/~21G0m00F_1W20m~02gge8~18~2gMW1m73/+~02gw00m01_20GC~1QggX~2W~25S070SF/u3/00Lm20m0F_1+0308001gggO~12~22gW0S1n//m~0Ag040m1_2y0m4001kggg~28~51//7//~420W7TtTtTm42W01ugggm~2W~57/yV/y3/~2101_3m0G00u6ggg~22~6V/p//u~13m0207TtTtTt0800y0UggW~28~4_8u0u010_3+100S00wgg~3W~33_8W0U005LLLLLLK00U003gge~22~4F_7+00700V_3m0E~07ggW~28~4_8u003m1LLLLLLL0F~1Ugh~3W~33_8W~0mF_3+3~11wgi~22~2Y0333333333BB~3LLLLLLLG~27ggu~28~02eYeh8888888eABY05007_0yv//m01G00QghAe~1W00AW008E~3e80CY82e0LLLG005LWAW009ggi0A800200W~2o8~0Ye~0m2AW51kx~2OD~0YF//m028W0828e~132AY28W~03~0A8D4~328WYWW1nm~08gAgY~3C00YY~2C0022A8~58eWW077~222~5m~6m~GSS~38~43~63~G1nm~3W~4C~6C~G77~32~5m~6m~0FW~BOC~3800200803080800W0830G01/G010W008020400ZYu800802W0GG0100C101004010C200Ac0Fe400100G0W04EJX00100I02~3m~6m~0sO1/~9uE~38~0G0403004001080Z010AlWF+0G0800840083Wy108004W0G200W0S00W008104C081F+0oO2010010W010E3W81~0Y02~3m~6m~0Wu39W~71m7~38001~0WG0040010200W021Z2FV001~0W0G0870S00400GW4080042~0W0080G0400G3uGrO00800402010S1m00W0220W4~I1/~K420G040G0W0100W00G00W03u0G0801~0G0800W0W0400G0WG200W204008040020040010201008002010040400W0200', 280, 88, 'o'],
        field: ['~/~/~P4~/~/~/~M8~HG~/~/~A4~L8~lW~/~/~/~/~/~98~/~/~/~88~W8~g1G~/~/~/~/~5G~rG~/~/~/~/~ZG~tG~/~/~14~/~/~/~3W~T1~95~/~/~1G~/~34~/~/~/~h2~hK~vG~/~44~/~/~/~/~/~XW~g5~/~/~/~X2~/~/~/~/~/~/~/~/~/~/~/~/~/~c8~/~/~/~Q4~/~/~b2~/~WW~/~k1~/~/~/~/~/~vG~/~/~/~/004~m1~/~R4~G8~Ne~F1G~98~g1G~/~/~/~/~/~/~24~/~t4~/~2W~g5~/02~hK~98~/~/~/~/~/~/~/~/~/~/~/~/~/~KG~/~/~/~yG~/~/~/~E', 280, 176, '.'],
        round: ['3//m7/+3+7yVm/Z//WV//1//uVm/Z+7yV/yB//yF//B+7yVw/h//mV//Z//yVo/h/7yV/+By7yV//h+7yVw/h//uVg/hu7yVX/B/dyV0/By7yVA/dyFuVy/Bwdu/Y/Bu7y/X/7/lu/2/7u7u/2/7ylu_07u7w/2/7u7w/X/7//w/2/7u7w/2/7ylw_07u7w_07u7w/X/7//w/2/7//Y+1/7ylw_07mFo//u7mFw/X/7//w+1+7//2+1/7uVw_07mlo/du7mlw/3+7//w+1+7y/X+1+FuVo/V/7mlo/dyFmln/3+7x/w+1+7y/X+1+l//o+l+7mFn/7yl//n//+Fn/n//+Fw/X//+l//n+l+F//X/7yd//Y//ylo/n//ulw/o//ud//1+d+l//1/7+B//2V/uloVn//mlw/oV/mB/+1+B+l/y20808~0W00W0W2~0gYg0gg0Age2eAeggW0', 105, 27, '.'],
        dig1: ['07u0Vm1/W7/0V+0/y5/u7/mE/XH+17y4Fu1Vm0/W5/03+0Fy0Vu7/+F/zV/u//p//d//F//001LL0', 17, 27, '.'],
        dig2: ['3//mF//Y_07//yV//r_080FzLK/W03+1//uF//W//+7//uV/+1//mNy00VgW5+007//m_03//yF//o_03//yl//n~0AggW', 22, 27, '.'],
        dig3: ['3//yF//o_07//yV//r//+80FvLK/W03+0V/u1//WN/y0V/W5//W7/+10Vu2f/W07yF//m_0B//yF//o//+3//ml/+1~0Agg0', 22, 27, '.'],
        dig4: ['3+7yFuVo/X/3+NyVuVn/3/7yFvVm/X/3+N//uV//Z//+F//u_0h//+G0Vwgf/W0N+00Vm05/00Fy00/m0B/00Fy02/m04~0gW', 22, 27, '.'],
        dig5: ['3//yF//o_03//uV//X//+7y01VrK1/00N//WV//3//yF//u_0h//+G0Vwgf/W07yF//m_0B//yF//o//+3//ml/+1~0Agg0', 22, 27, '.'],
        wrap: ['CpCpCpCpCp~8W~6C~61~IW~6C~61~IW~6C~61~IW~6C~61C~51nm00W~020Ul007~1S7yO00y~03mp33//u0F//cHu00y~03myh007~1S6jm00W~020VC~51m~7W~6C~61~IW~6C~61~IW~6C~61~IW~69cPcPcPcPcP', 63, 40, '.'],
        bnready: ['F/m//mFuF/m/7o0180120Y01851G05008G4G050eI00O0140Y00a62GU3008W4GU2WmY2GO7+4GY2GI04GI51+0Y2GI2G124Ge088GI2GH08G+501152GI2822018088eI2WWWGW0A01272Wa444401G08G0K4WWWWWGA1+202Wa444421GFyW0K4WWWWWGA00K01Wy448450m02WmC01110We600KA1W0G88450m031GC02110We600OA1W0W883u/F/y+Fp/u0+0', 81, 20, 'o'],
        bnlayon: ['Fm03+3yV007/XyFa2010H0e80204Wa50W0G4GA40100e91G808123100W061GK2020GWmW08V1WK50W0Y4408024GO31G808WX0400X440Wa2048881008H10091015220W024GG02GG0HGWG800X8C00e408S84400GY300A10202110048Wm02WG0W0WGG0128C00e7+G084400GY300A00K0112004FWWO4W051WGWW0100861801Ge48800G061WI00OA12200202WK4W062WGWW00W18517/+V7u7m007/X+FW', 92, 20, 'o'],
        bnclear: ['F/y7/3//Fm0//001/+VW1//WVWV/X008W2G030W402008061080121201400Y0900C20G040100O40W0484802G02G1400m8100G0801WG200H0GW0500904G030W47WW0W06108014127mK3/31X0/q20GI2023/e80W/uH48H1GVW4447uG8118808810W43y249149010GGG0H0W44WW0WW420G088Ga4Ga0411101440GI20220G8100WZ2GV2G0G48404GG11GG08G10W4024C900I010WWW0I108910110420G08Gma0280422201840Wa40440GG100Y02G08WVW8887uWG22GG0GG11043y8090WY101WmWVw1/e91011/q7/GVyW0a2284080Y00O01Wy40400W0200C01W88WW0W2801W0600W0G0200800mC61GI20208W0600O040100O01W051GO5188080Y00O01W0G0201W0600O51WK4WW0W2801W06020080600O01WK61GHy01/mV/v//d/m00V/d/+V/v+Fdu+0', 172, 20, 'o'],
        bnhold: ['FnyF/3y0F/mWK4W1840W0H0eA01G8100I1GO01WG200a4WmU30W47WeA1Wa610891GS318C20GI2W062GO40Wa500C4WmG118A00OA2WW22We01Wa610891G0318C20GI2WO62GO40Wa50mC4WmFz18A1WOF1W063mK50m0300C018A1W0A00O04GK2W0K00m08We501801W0W+Fn/yF/y/+0', 65, 20, 'o']
    };
    var ART_MASK = {bnready: '~A1/+7/+1/1/+7u+F/u//mFuF/u/7X//d/+3/1//3vyFXy//mVuFXyVF1yFdu03l1yFX/uFXu+00TyFXyF+1uF7/m7lXyFW/mF1u/+0uyFXy7y1/+7/m77XyV0VWV/n/+1uyV3u3u3/+F/mF/ZuV0V0Vln+01/yV3u3u3z+Fm0V/ZuV0V0Vln//Z/+V3u3m3u/F/yVFp/+0+0V7v//Zn+V/W7m3u/F/y+Fp/y0+0V7v//dn+V/07m~B', bnlayon: '~C3y00/W/7m01/uV3u/00FuFnu00//7m+Fm07+1y+00V/v+FZy01/WVF007W+VZu/00Tu3/m01uFdy+Fm07V0/u00U3x/V3y03tm7+007W+//m+00uy1/001uFl/yFW0EF0Fm00U7p//7u07Zm3u00F1y//n+01/y0+003mVF/yVW0V/0FW00y7p//7u0F/m3u00F1y//n//Z/+0y003mVVduV/u+VWV~0//tv+7/+F7u7m00F/v+VX//dn+1y001/yVZuV/vyVWV~0V+7u+~D', bnclear: '~Q//mVyF/y/03/y007/v+07/+1+1/+3//1/m//py0F/u00//du0V/u7u7/yF/yF+3//Fm0//W07/+VW1//W/WV/u//m/uF/y/03uV00V/v+07/+3+1uFZy00+0/03y0FXy01y07m0V00Eu7W+FW03u3u0Fm0+7m07m0/03y01xm+3m/+0FWF/W/03uV00V03y0F/m7l3uF3/u0+0/+3u0FXy01y0Fm0//0SyFWyF/W3m3/uFW0+FW07W0/03/y3pm//X/+0V0V/X+07m+00+03y0F/mFF3/y7/u1y1/+7u0V3u03u0FW0//1/yF/mVW07m7u0VW1yFW0FW0+03y07/m/V1+00V0VW1+07m+00+03u0FW0V/3zy7u07/1//d/+V3u03//V/z//p/+VtmV00Vy7/+V/v//00F/z//t//Fpv+FXy01/mV/v//d/u00//d/+V/u+Fdu+7m07/1//d/+V/W01/+V/v//du+VZuV00Vy7/+V/v/y007/v//d/+VZv+FW~Q', bnhold: '~8VZuV+7u0V/W/7n/+Fm0//X+Fd/+VW1//3uVFXy/03uV7n+V3v+07m+FZy+7py0FXyV/vyFdu0V3u//puVFW0+7n//dnyV01yV7/+V3v+07m+F/y+7py0FXyVdvyFdu0V3u/FpuVFm0+7n+Vdm+V/vyFZu/F/y//p/+7n+V/n//d/uFZyV/Z//F/mV7u/+7/+V/~9'};

    function turned(n, ne, face) { var rows = face % 2 ? ne : n, k; for (k = 0; k < Math.floor(face / 2); k++) { rows = rot90(rows); } return rows; }

    function start(H) {
        var G = {
            mode: 'BUSY', round: 1, score: 0, lives: 3, nextExtra: EXTRA_EVERY, best: H.load('best', H.isBest),
            turnAct: '', turnMs: 0,
            p: { x: START_X, y: START_Y, vx: 0, vy: 0, face: 2, alive: true, safeMs: 0, brakeMs: 0, runnerMs: 0, waitMs: 0, dieMs: 0 },
            zombies: [], arrows: [], bolts: [], necro: null, necroMs: 0,
            total: 0, spawned: 0, msg: '', msgMs: 0, ms: 0, wasMode: '', sfxQ: [], sfxWait: 0, blink: 0, deaths: 0, hits: 0, sprites: [], clock: 0, stage: 0, stageMsg: '', fx: [], hbMs: 0, hbFlip: 0, warMs: 0, warFlip: 0, won: false
        };
        var DEF = {}, clipped = {};
        var BANNER = { READY: 'bnready', CLEAR: 'bnclear', HOLD: 'bnhold' };

        function T(c, r, s, inv) { H.text(c, r, s, inv); }
        function say(s) {
            H.fill(0, MSG_ROW, 40, 1, ' ', false);
            T(Math.max(0, Math.floor((40 - s.length) / 2)), MSG_ROW, s, false);
        }
        function help() { say('A/D TURN W RUN S STOP SPACE FIRE H P Q'); }
        function flash(s, ms) { G.msg = s; G.msgMs = ms || 2200; say(s); }

        function def(name, rows) {
            DEF[name] = { w: rows[0].length, h: rows.length, rows: rows };
            H.defSprite(name, rows);
        }
        function defAll() {
            var i, k;
            for (k in ZOMBIE_A) { def(k, ZOMBIE_A[k]); def(k + 'b', ZOMBIE_B[k]); }
            def('puff', PUFF_ROWS);
            for (i = 0; i < 8; i++) { def('a' + i, turned(ARCHER_N, ARCHER_NE, i)); def('s' + i, ringed(turned(ARCHER_N, ARCHER_NE, i))); def('r' + i, turned(ARROW_N, ARROW_NE, i)); }
            def('nec', NECRO_ROWS); def('necb', NECRO_ROWSB); def('necl', flipRows(NECRO_ROWS)); def('neclb', flipRows(NECRO_ROWSB));
            def('bolt', BOLT_ROWS); def('boltb', BOLT_ROWSB);
            def('burst', BURST0); def('burst1', BURST1); def('burst2', BURST2);
            def('life', LIFE_ROWS); def('key1', KEY1); def('key5', KEY5);
            def('rule', [new Array(71).join('##..')]);
            def('tw1', ['ooo', 'o#o', 'ooo']);
            for (k in ART) { def(k, artRows(ART[k], ART_MASK[k])); }
            def('wipe0', wipeRows(0)); def('wipe1', wipeRows(1)); def('wipe2', wipeRows(2));
        }
        // a three step dither to dark, for screen changes
        function wipeRows(k) {
            var a = ['o...', 'o.o.', 'oooo'], b = ['....', '.o.o', 'o.oo'], c = [a[k], b[k]], rows = [], y, x, r;
            for (y = 0; y < 192; y++) { r = ''; for (x = 0; x < 280; x++) { r += c[y % 2].charAt(x % 4); } rows.push(r); }
            return rows;
        }
        // runs on the tick, so it needs no timers; reduced motion cuts straight to the next screen
        function wipe(fn) {
            if (H.reduced) { fn(); return; }
            G.mode = 'BUSY'; G.wipe = fn; G.wipeMs = 0;
        }
        function tickWipe() {
            var k = Math.floor(G.wipeMs / 50), f = G.wipe;
            if (k > 2) { G.wipe = null; f(); return; }
            H.sprites(G.sprites.concat([{ s: 'wipe' + k, x: 0, y: 0 }]));
            G.wipeMs += TICK;
        }
        // Add a sprite centred on a field point. Sprites that cross an edge are cut at the status and message rows,
        // and (when wrap is on) drawn again on the far side: the wrap-around is a second entry.
        function addSprite(name, cx, cy, wrap) {
            var d = DEF[name], left = Math.round(cx - d.w / 2), top = Math.round(cy - d.h / 2), xs = wrap ? [-W, 0, W] : [0], ys = [-H_PX, 0, H_PX], i, j, l, t, a, b, nm;
            for (i = 0; i < xs.length; i++) {
                for (j = 0; j < ys.length; j++) {
                    l = left + xs[i]; t = top + ys[j];
                    if (l >= W || l + d.w <= 0 || t >= H_PX || t + d.h <= 0) { continue; }
                    a = Math.max(0, -t); b = Math.min(d.h, H_PX - t);
                    nm = name;
                    if (a > 0 || b < d.h) {
                        nm = name + '@' + a + '-' + b;
                        if (!clipped[nm]) { clipped[nm] = true; H.defSprite(nm, d.rows.slice(a, b)); }
                    }
                    G.sprites.push({ s: nm, x: l, y: TOP_PX + t + a });
                }
            }
        }

        /* sound: one note at a time, so the audio queue can't back up behind the action */
        function sfx(notes, low) {
            if (low && (G.sfxWait > 0 || G.sfxQ.length)) { return; }	// turn and run sounds never queue behind the action
            if (G.sfxWait <= 0 && !G.sfxQ.length) { G.sfxQ = notes.slice(); pumpSfx(); } else if (G.sfxQ.length < 6) { G.sfxQ = G.sfxQ.concat(notes); }
        }
        function pumpSfx() {
            if (!G.sfxQ.length) { return; }
            var n = G.sfxQ.shift();
            H.beep(n[0], n[1]);
            G.sfxWait = n[1];
        }

        /* ----- screens ----- */
        function lines(rows, hold) {
            H.cls();
            rows.forEach(function (s) { H.line(s, { center: true, hold: hold || 10 }); });
        }
        function showTitle() {
            var b = G.best, i, rows = ['', '', 'A S T E R O R K S'];
            G.mode = 'BUSY';
            H.cls();
            H.then(function () { G.sprites = [{ s: 'title', x: 0, y: 0 }]; H.sprites(G.sprites); });
            // the logo covers these rows, so the title stays in the live log for a screen reader
            for (i = 3; i < 20; i++) { rows.push(''); }
            rows.push('THE DEAD COME IN CLUMPS. THEY BREAK UP.', 'SPACE LAY ON   H HOW TO PLAY   Q LEAVE', b ? 'BEST ' + pad(b.score, 5) + (b.won ? '  FIELD HELD' : '  ROUND ' + b.round) : 'BEST 00000  (NONE YET)');
            rows.forEach(function (s) { H.line(s, { center: true, hold: 10 }); });
            H.then(function () { G.mode = 'TITLE'; G.ms = 0; });
        }
        function twinkle() {
            var t = Math.floor(G.ms / 280), i, l = [{ s: 'title', x: 0, y: 0 }];
            for (i = 0; i < TWINKLE.length; i++) { if ((t + i * 3) % 7 === 0) { l.push({ s: 'tw1', x: TWINKLE[i][0] - 1, y: TWINKLE[i][1] - 1 }); } }
            H.sprites(l);
        }
        function showHow(page) {
            G.mode = 'BUSY';
            H.cls();
            H.then(function () { G.sprites = (page ? HOW2_ART : HOW_ART).slice(); H.sprites(G.sprites); });
            (page ? HOW2 : HOW).forEach(function (s) { H.line(s, { hold: 10 }); });
            H.then(function () { G.mode = page ? 'HOW2' : 'HOW'; });
        }
        function showCard() {
            var n = countFor(G.round), mul = speedMul(G.round), rows = [], i;
            G.mode = 'BUSY';
            for (i = 0; i < 13; i++) { rows.push(''); }
            rows.push(G.round >= WIN_ROUND ? 'LAST ROUND. THE REEVE IS WATCHING.' : 'THE HORDE ADVANCES', '', 'ROUND ' + G.round + '  ZOMBIES ' + n + '  SPEED X' + mul.toFixed(2), '', cardJoke(), '', '', 'SPACE TO LAY ON');
            H.cls();
            H.then(function () { G.sprites = cardArt(n); H.sprites(G.sprites); });
            rows.forEach(function (s) { H.line(s, { center: true, hold: 10 }); });
            H.then(function () { G.mode = 'CARD'; G.ms = 0; });
        }
        // The big round number, then the horde as it will really arrive: its clumps, drawn to count, and one arrow per round of speed.
        function cardArt(n) {
            var l = [{ s: 'round', x: 66, y: 16 }, { s: 'dig' + Math.min(G.round, 5), x: 186, y: 16 }, { s: 'rule', x: 0, y: 8 }, { s: 'rule', x: 0, y: 96 }], sizes = splitTotal(n), w = 0, x, i, nm;
            for (i = 0; i < sizes.length; i++) { w += DEF['z' + sizes[i]].w + 6; }
            x = Math.round((280 - w + 6) / 2);
            for (i = 0; i < sizes.length; i++) { nm = 'z' + sizes[i]; l.push({ s: nm, x: x, y: 64 - Math.floor(DEF[nm].h / 2) }); x += DEF[nm].w + 6; }
            x = Math.round(140 - G.round * 7);
            for (i = 0; i < G.round; i++) { l.push({ s: 'r2', x: x + i * 14, y: 80 }); }
            return l;
        }
        function cardJoke() {
            var j = [
                'THE REEVE CHECKS YOUR ARROWS. BLUNT.',
                'SOMEONE IS STILL TAPING A SWORD.',
                'NOBODY SIGNED A WAIVER FOR THIS.',
                'SOMEBODY ORDERED A HORDE. IT IS HERE.',
                'THE FIELD IS LOUDER THAN LAST ROUND.',
                'IS THAT A SCREAM OR A BATTLE CRY?'
            ];
            return j[(G.round - 1) % j.length];
        }

        /* ----- round set-up ----- */
        function newZombie(n, x, y, ang, mul) {
            var sp = BASE_SPEED * SIZE_MUL[n] * mul;
            var vx = Math.cos(ang) * sp, vy = Math.sin(ang) * sp;
            return { n: n, x: mod(x, W), y: mod(y, H_PX), vx: vx, vy: vy, o: n === 2 ? orientOf(vx, vy) : 0 };
        }
        function spawnRound() {
            var sizes = splitTotal(countFor(G.round)), mul = speedMul(G.round), made = [], i, t, x, y, ok, k, best, bd, d;
            for (i = 0; i < sizes.length; i++) {
                best = null; bd = -1;
                for (t = 0; t < 80; t++) {
                    x = H.rand(W * 4) / 4; y = H.rand(H_PX * 4) / 4;
                    ok = wdist(x, y, START_X, START_Y) >= 75;
                    d = 999;
                    for (k = 0; k < made.length; k++) { d = Math.min(d, wdist(x, y, made[k].x, made[k].y)); }
                    if (ok && d >= 38) { best = [x, y]; break; }
                    if (ok && d > bd) { bd = d; best = [x, y]; }
                }
                if (!best) { best = [mod(START_X + 140, W), mod(START_Y + 88, H_PX)]; }
                made.push(newZombie(sizes[i], best[0], best[1], H.rand(628) / 100, mul));
            }
            G.zombies = made;
            G.spawned = countFor(G.round);
        }
        function placeArcher() {
            var p = G.p;
            p.x = START_X; p.y = START_Y; p.vx = 0; p.vy = 0; p.face = 2; p.alive = true; p.brakeMs = 0; p.runnerMs = 0;
        }
        function beginRound() {
            G.arrows = []; G.bolts = []; G.necro = null; G.necroMs = NECRO_FIRST; G.fx = [];
            spawnRound();
            placeArcher();
            G.p.safeMs = SAFE_MS;
            G.mode = 'BUSY';
            H.cls();
            H.then(function () {
                G.mode = 'READY';
                G.ms = 0;
                draw();
                say('READY');
            });
        }
        function live() {
            var n = 0, i;
            for (i = 0; i < G.zombies.length; i++) { n += G.zombies[i].n; }
            return n;
        }

        /* ----- drawing ----- */
        function status() {
            H.fill(0, 0, 40, 1, ' ', false);
            T(0, 0, 'SCORE ' + pad(G.score, 5), false);
            T(12, 0, 'ROUND ' + G.round, false);
            T(21, 0, 'LEFT ' + live(), false);
            T(29, 0, 'LIVES', false);
            if (G.lives >= 5) { T(35, 0, 'X' + G.lives, false); }
        }
        function draw() {
            var i, z, p = G.p, n = G.necro, show, frame, t;
            G.sprites = [{ s: 'field', x: 0, y: TOP_PX }];
            frame = !H.reduced && Math.floor(G.blink / 320) % 2 ? 'b' : '';
            for (i = 0; i < G.zombies.length; i++) { z = G.zombies[i]; addSprite((z.n === 2 ? PAIR_SPRITE[z.o || 0] : 'z' + z.n) + frame, z.x, z.y, true); }
            for (i = 0; i < G.bolts.length; i++) { addSprite('bolt' + (!H.reduced && Math.floor(G.blink / 120) % 2 ? 'b' : ''), G.bolts[i].x, G.bolts[i].y, false); }
            if (n) { addSprite('nec' + (n.dir < 0 ? 'l' : '') + frame, n.x, n.y, false); }
            for (i = 0; i < G.arrows.length; i++) { addSprite('r' + G.arrows[i].face, G.arrows[i].x, G.arrows[i].y, true); }
            if (p.alive) {
                show = p.safeMs <= 0 || H.reduced || Math.floor(G.blink / 120) % 2 === 0;
                if (show) { addSprite((p.safeMs > 0 ? 's' : 'a') + p.face, p.x, p.y, true); }
            } else if (p.dieMs > 0 && (H.reduced || Math.floor(p.dieMs / 160) % 2 === 0 || p.dieMs > DIE_MS - 400)) {
                t = DIE_MS - p.dieMs;
                addSprite(H.reduced || t < 160 ? 'burst' : t < 400 ? 'burst1' : 'burst2', p.x, p.y, true);
            }
            for (i = 0; i < G.fx.length; i++) { addSprite(G.fx[i].s, G.fx[i].x, G.fx[i].y, true); }
            if (G.lives < 5) { for (i = 0; i < G.lives; i++) { G.sprites.push({ s: 'life', x: 245 + i * 8, y: 0 }); } }
            t = G.mode === 'PLAY' && G.layMs > 0 ? 'bnlayon' : BANNER[G.mode];
            if (t) { G.sprites.push({ s: t, x: Math.round((W - DEF[t].w) / 2), y: 44 }); }
            H.sprites(G.sprites);
            status();
        }

        /* ----- scoring ----- */
        function saveBest() {
            if (G.score > (G.best ? G.best.score : 0)) {
                G.best = { score: G.score, round: G.round, won: G.won };
                H.save('best', G.best);
            }
        }
        function award(n) {
            G.score += n;
            if (G.score >= G.nextExtra) {
                G.nextExtra += EXTRA_EVERY;
                G.lives += 1;
                flash('A REEVE LOOKED AWAY. EXTRA LIFE.');
                sfx([[784, 60], [1047, 60], [1319, 90]]);
            }
        }

        /* ----- the archer ----- */
        function turn(dirn) {
            var p = G.p;
            if (!p.alive) { return; }
            p.face = mod(p.face + dirn, 8);
            sfx([[300, 15]], true);
        }
        function turnHeld(act) { return !!H.held && (act === 'LEFT' ? H.held('A') || H.held('LEFT') : H.held('D') || H.held('RIGHT')); }
        // A press turns at once; while the key stays down, one more step every TURN_MS.
        // The OS auto-repeat presses that arrive meanwhile are ignored, so a hold turns at a steady rate.
        function pressTurn(act) {
            if (G.turnAct === act && turnHeld(act)) { return; }
            G.turnAct = act; G.turnMs = 0;
            turn(act === 'LEFT' ? -1 : 1);
        }
        function holdTurn() {
            if (!G.turnAct) { return; }
            if (!turnHeld(G.turnAct)) { G.turnAct = ''; G.turnMs = 0; return; }
            G.turnMs += TICK;
            while (G.turnMs >= TURN_MS) { G.turnMs -= TURN_MS; turn(G.turnAct === 'LEFT' ? -1 : 1); }
        }
        function run() {
            var p = G.p, d = FACE[p.face], len = Math.sqrt(d[0] * d[0] + d[1] * d[1]), sp;
            if (!p.alive) { return; }
            p.vx += d[0] / len * RUN_IMPULSE;
            p.vy += d[1] / len * RUN_IMPULSE;
            sp = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
            if (sp > MAX_RUN) { p.vx = p.vx / sp * MAX_RUN; p.vy = p.vy / sp * MAX_RUN; }
            sfx([[200, 15]], true);
        }
        function brake() {
            var p = G.p;
            if (!p.alive) { return; }
            p.vx *= BRAKE_CUT; p.vy *= BRAKE_CUT;
            p.brakeMs = BRAKE_MS;
        }
        function shoot() {
            var p = G.p, d = FACE[p.face], len = Math.sqrt(d[0] * d[0] + d[1] * d[1]);
            if (!p.alive || G.arrows.length >= MAX_ARROWS) { return; }
            G.arrows.push({ x: mod(p.x + d[0] / len * 11, W), y: mod(p.y + d[1] / len * 11, H_PX), vx: d[0] / len * ARROW_SPEED, vy: d[1] / len * ARROW_SPEED, face: p.face, left: ARROW_RANGE });
            sfx([[880, 18]]);
        }
        function callRunner() {
            var p = G.p, t;
            if (!p.alive || p.runnerMs > 0) { return; }
            p.runnerMs = RUNNER_COOLDOWN;
            p.x = H.rand(W * 4) / 4; p.y = H.rand(H_PX * 4) / 4; p.vx = 0; p.vy = 0;
            G.arrows = [];
            if (H.rand(RUNNER_BAD) === 0) {
                t = ['THE RUNNER TRIPPED ON A ROPE. ON YOU.', 'THE RUNNER WAS A ZOMBIE. NOBODY CHECKED.'];
                kill(t[H.rand(t.length)]);
            } else {
                t = ['A RUNNER CARRIES YOU OFF. BRING SNACKS.', 'RUNNER! RUNNER! YOU ARE SOMEWHERE ELSE.', 'THE RUNNER HAS NO IDEA WHERE THIS IS.'];
                flash(t[H.rand(t.length)], 1800);
                sfx([[500, 25], [700, 25], [900, 25]]);
            }
        }
        function kill(line) {
            var p = G.p;
            if (!p.alive) { return; }
            G.lives--;
            G.deaths++;
            p.alive = false;
            p.dieMs = DIE_MS;
            p.waitMs = 0;
            p.vx = 0; p.vy = 0;
            G.arrows = [];
            flash(line, DIE_MS + 800);
            G.sfxQ = []; G.sfxWait = 0;
            sfx([[440, 120], [330, 120], [220, 240]]);
        }

        /* ----- splitting ----- */
        function puff(x, y) {
            if (H.reduced) { return; }
            G.fx.push({ s: 'puff', x: x, y: y, ms: 120 });
            if (G.fx.length > 6) { G.fx.shift(); }
        }
        function hitZombie(i, hx, hy) {
            var z = G.zombies[i], cs = circlesOf(z), mul = speedMul(G.round), ang = Math.atan2(z.vy, z.vx), lo = SPLIT_SPREAD[0], hi = SPLIT_SPREAD[1], a, b, kid = z.n === 4 ? 2 : 1;
            G.hits++;
            puff(hx, hy);
            award(SIZE_PTS[z.n]);
            G.zombies.splice(i, 1);
            if (z.n > 1) {
                a = lo + (hi - lo) * H.rand(100) / 100;
                b = lo + (hi - lo) * H.rand(100) / 100;
                // the halves start where they were drawn (a clump splits top and bottom, a pair left and right), then fly apart
                if (z.n === 4) {
                    G.zombies.push(newZombie(kid, z.x, z.y - 4.5, ang - b, mul));
                    G.zombies.push(newZombie(kid, z.x, z.y + 4.5, ang + a, mul));
                } else {
                    G.zombies.push(newZombie(kid, cs[0][0], cs[0][1], ang - b, mul));
                    G.zombies.push(newZombie(kid, cs[1][0], cs[1][1], ang + a, mul));
                }
                sfx([[z.n === 4 ? 392 : 523, 40]]);
            } else {
                sfx([[1047, 35], [1319, 45]]);
            }
        }
        // Arrows are generous: a shaft within GRAZE of a drawn circle counts, so a grazing shot still lands.
        function arrowHit(x, y) {
            var i, j, cs;
            for (i = 0; i < G.zombies.length; i++) {
                cs = circlesOf(G.zombies[i]);
                for (j = 0; j < cs.length; j++) { if (wdist(x, y, cs[j][0], cs[j][1]) <= ZR + GRAZE) { return i; } }
            }
            return -1;
        }
        function necroHit(x, y) {
            var n = G.necro;
            return !!n && Math.abs(x - n.x) <= NECRO_HW + GRAZE && Math.abs(y - n.y) <= NECRO_HH + GRAZE;
        }

        /* ----- the world ----- */
        function moveArrows() {
            var i, a, steps = 4, s, k, hit;
            for (i = G.arrows.length - 1; i >= 0; i--) {
                a = G.arrows[i];
                hit = false;
                for (s = 0; s < steps && !hit; s++) {
                    a.x = mod(a.x + a.vx * DT / steps, W);
                    a.y = mod(a.y + a.vy * DT / steps, H_PX);
                    a.left -= ARROW_SPEED * DT / steps;
                    k = arrowHit(a.x, a.y);
                    if (k >= 0) { hitZombie(k, a.x, a.y); hit = true; } else if (necroHit(a.x, a.y)) {
                        award(NECRO_PTS);
                        flash('NECROMANCER DOWN +' + NECRO_PTS + '. GOOD SPORT.', 2400);
                        if (!H.reduced) { G.fx.push({ s: 'burst', x: G.necro.x, y: G.necro.y, ms: 240 }); }
                        sfx([[523, 60], [784, 60], [1047, 90]]);
                        G.necro = null; G.necroMs = STAGES[G.stage].every; hit = true;
                    }
                }
                if (hit || a.left <= 0) { G.arrows.splice(i, 1); }
            }
        }
        function moveZombies() {
            var i, z, dx, dy, a0, d, sp, turn = STAGES[G.stage].turn * Math.PI / 180 * DT;
            for (i = 0; i < G.zombies.length; i++) {
                z = G.zombies[i];
                if (turn > 0 && G.p.alive) {
                    // bend the heading toward the archer along the shortest wrapped vector; the speed does not change
                    dx = G.p.x - z.x; dy = G.p.y - z.y;
                    if (dx > W / 2) { dx -= W; } else if (dx < -W / 2) { dx += W; }
                    if (dy > H_PX / 2) { dy -= H_PX; } else if (dy < -H_PX / 2) { dy += H_PX; }
                    a0 = Math.atan2(z.vy, z.vx);
                    d = Math.atan2(dy, dx) - a0;
                    while (d > Math.PI) { d -= 2 * Math.PI; }
                    while (d < -Math.PI) { d += 2 * Math.PI; }
                    d = Math.max(-turn, Math.min(turn, d));
                    sp = Math.sqrt(z.vx * z.vx + z.vy * z.vy);
                    z.vx = Math.cos(a0 + d) * sp; z.vy = Math.sin(a0 + d) * sp;
                    if (z.n === 2) { z.o = orientOf(z.vx, z.vy); }
                }
                z.x = mod(z.x + z.vx * DT, W);
                z.y = mod(z.y + z.vy * DT, H_PX);
            }
        }
        function moveNecro() {
            var n = G.necro, p = G.p, ang, i, st, tl;
            if (!n) {
                if (G.zombies.length) {
                    G.necroMs -= TICK;
                    if (G.necroMs <= 0) {
                        n = G.necro = { dir: H.rand(2) ? 1 : -1, y: 22 + H.rand(H_PX - 44), x: 0, bolt: 1200 };
                        n.x = n.dir > 0 ? -9 : W + 9;
                        flash('A NECROMANCER CROSSES THE FIELD.', 2000);
                    }
                }
            } else {
                n.x += n.dir * NECRO_SPEED * DT;
                n.y += Math.sin(n.x / 35) * 0.9;
                n.y = Math.max(NECRO_HH + 1, Math.min(H_PX - NECRO_HH - 1, n.y));
                n.bolt -= TICK;
                if (n.bolt <= 0 && n.x > 4 && n.x < W - 4) {
                    n.bolt = STAGES[G.stage].boltMs;
                    if (p.alive) {
                        st = STAGES[G.stage];
                        tl = st.lead ? Math.sqrt((p.x - n.x) * (p.x - n.x) + (p.y - n.y) * (p.y - n.y)) / st.boltSp : 0;
                        ang = Math.atan2(p.y + p.vy * tl - n.y, p.x + p.vx * tl - n.x);
                        G.bolts.push({ x: n.x, y: n.y, vx: Math.cos(ang) * st.boltSp, vy: Math.sin(ang) * st.boltSp, ms: BOLT_LIFE });
                        sfx([[180, 60]]);
                    }
                }
                if ((n.dir > 0 && n.x > W + 10) || (n.dir < 0 && n.x < -10)) { G.necro = null; G.necroMs = STAGES[G.stage].every; }
            }
            for (i = G.bolts.length - 1; i >= 0; i--) {
                G.bolts[i].x += G.bolts[i].vx * DT;
                G.bolts[i].y += G.bolts[i].vy * DT;
                G.bolts[i].ms -= TICK;
                if (G.bolts[i].ms <= 0 || G.bolts[i].x < -6 || G.bolts[i].x > W + 6 || G.bolts[i].y < -6 || G.bolts[i].y > H_PX + 6) { G.bolts.splice(i, 1); }
            }
        }
        function moveArcher() {
            var p = G.p, k;
            if (p.safeMs > 0) { p.safeMs -= TICK; }
            if (p.runnerMs > 0) { p.runnerMs -= TICK; }
            if (p.brakeMs > 0) { p.brakeMs -= TICK; }
            k = Math.exp(-(p.brakeMs > 0 ? BRAKE_FRICTION : FRICTION) * DT);
            p.vx *= k; p.vy *= k;
            if (Math.abs(p.vx) < 0.4) { p.vx = 0; }
            if (Math.abs(p.vy) < 0.4) { p.vy = 0; }
            p.x = mod(p.x + p.vx * DT, W);
            p.y = mod(p.y + p.vy * DT, H_PX);
        }
        function checkArcher() {
            var p = G.p, i, j, cs, n, b;
            if (!p.alive || p.safeMs > 0) { return; }
            for (i = 0; i < G.zombies.length; i++) {
                cs = circlesOf(G.zombies[i]);
                for (j = 0; j < cs.length; j++) {
                    if (wdist(p.x, p.y, cs[j][0], cs[j][1]) < ZR + BODY_R) { kill(DEATH_LINES[H.rand(DEATH_LINES.length)]); return; }
                }
            }
            n = G.necro;
            if (n && Math.abs(p.x - n.x) < NECRO_HW + BODY_R && Math.abs(p.y - n.y) < NECRO_HH + BODY_R) { kill(BOLT_LINES[0]); return; }
            for (i = 0; i < G.bolts.length; i++) {
                b = G.bolts[i];
                if (wdist(p.x, p.y, b.x, b.y) < BOLT_R + BODY_R) { G.bolts.splice(i, 1); kill(BOLT_LINES[H.rand(BOLT_LINES.length)]); return; }
            }
        }
        function tryRespawn() {
            var p = G.p, i, clear = true;
            p.waitMs += TICK;
            for (i = 0; i < G.zombies.length; i++) { if (wdist(G.zombies[i].x, G.zombies[i].y, START_X, START_Y) < SAFE_R) { clear = false; } }
            for (i = 0; i < G.bolts.length; i++) { if (wdist(G.bolts[i].x, G.bolts[i].y, START_X, START_Y) < SAFE_R) { clear = false; } }
            if (clear || p.waitMs >= WAIT_MAX_MS) {
                placeArcher();
                p.safeMs = SAFE_MS;
                flash('I WAS RELOADING. HOLD ON.', 1600);
            }
        }
        function advanceStage() {
            var k = stageFor(G.clock);
            if (k > G.stage) { G.stage = k; G.stageMsg = STAGES[k].msg; }
        }
        function tickFx() {
            var i;
            for (i = G.fx.length - 1; i >= 0; i--) { G.fx[i].ms -= TICK; if (G.fx[i].ms <= 0) { G.fx.splice(i, 1); } }
        }
        // The heartbeat speeds up as the horde thins and as the clock tightens; the necromancer warbles while he is out.
        // Both go straight to the beeper so they never wait behind a sound effect.
        function heartbeat() {
            if (!G.p.alive) { return; }
            G.hbMs -= TICK;
            if (G.hbMs <= 0) {
                G.hbMs = Math.max(240, 300 + 800 * live() / Math.max(1, G.spawned) - 40 * G.stage);
                G.hbFlip ^= 1;
                H.beep(G.hbFlip ? 70 : 58, 70);
            }
            if (G.necro) {
                G.warMs -= TICK;
                if (G.warMs <= 0) { G.warMs = 240; G.warFlip ^= 1; H.beep(G.warFlip ? 620 : 520, 30); }
            }
        }
        function updatePlay() {
            var p = G.p;
            G.blink += TICK;
            G.clock += TICK;
            if (G.layMs > 0) { G.layMs -= TICK; }
            advanceStage();
            moveZombies();
            moveNecro();
            if (p.alive) {
                moveArcher();
                moveArrows();
                checkArcher();
            } else if (p.dieMs > 0) {
                p.dieMs -= TICK;
                moveArrows();
                if (p.dieMs <= 0) {
                    if (G.lives <= 0) { showOver(); return; }
                }
            } else {
                tryRespawn();
            }
            tickFx();
            if (G.msgMs > 0) {
                G.msgMs -= TICK;
                if (G.msgMs <= 0) { G.msg = ''; help(); }
            }
            if (G.stageMsg && G.msgMs <= 0) {
                flash(G.stageMsg, 2200);
                G.stageMsg = '';
                sfx([[294, 80], [247, 120]]);
            }
            heartbeat();
            if (!G.zombies.length && G.mode === 'PLAY' && (p.alive || G.lives > 0)) { roundClear(); return; }
            draw();
        }
        function roundClear() {
            saveBest();
            G.stageMsg = '';
            G.mode = 'CLEAR';
            G.ms = 0;
            G.necro = null; G.bolts = [];
            draw();
            flash('FIELD CLEAR. THE REEVE CALLS A BREAK.', 2000);
            sfx([[523, 70], [659, 70], [784, 70], [1047, 130]]);
        }
        function tick() {
            if (G.sfxWait > 0) {
                G.sfxWait -= TICK;
                if (G.sfxWait <= 0) { pumpSfx(); }
            }
            if (G.wipe) { tickWipe(); return; }
            switch (G.mode) {
                case 'TITLE':
                    G.ms += TICK;
                    if (!H.reduced) { twinkle(); }
                    break;
                case 'CARD':
                    G.ms += TICK;
                    if (G.ms >= 3500) { beginRound(); }
                    break;
                case 'READY':
                    G.ms += TICK;
                    holdTurn();
                    if (G.ms >= 1000) { G.mode = 'PLAY'; G.layMs = 900; flash('LAY ON!', 1200); }
                    break;
                case 'PLAY':
                    holdTurn();
                    updatePlay();
                    break;
                case 'CLEAR':
                    G.ms += TICK;
                    moveArrows();
                    tickFx();
                    if (G.p.dieMs > 0) { G.p.dieMs -= TICK; }
                    draw();
                    if (G.ms >= 2000) { if (G.round >= WIN_ROUND) { showWin(); } else { G.round++; wipe(showCard); } }
                    break;
                default:
                    break;
            }
        }

        /* ----- endings ----- */
        // A scene across the top; its heading line sits under the art so the log still gets it.
        function ending(art, rows) {
            H.cls();
            H.then(function () { G.sprites = [{ s: art, x: 0, y: 0 }]; H.sprites(G.sprites); });
            rows.forEach(function (s) { H.line(s, { center: true, hold: 10 }); });
        }
        function showOver() {
            saveBest();
            G.mode = 'BUSY';
            ending('lose', [
                '', 'OUT OF LIVES', '', '', '', '', '', '', '', '', '', '',
                'THE HORDE HAS THE FIELD.',
                'THE REEVE WRITES YOUR NAME DOWN.',
                'SPELLED CORRECTLY, EVEN.',
                'THE ZOMBIES ASK IF THERE IS POTLUCK.',
                'THERE IS NOT. THEY ARE UPSET.',
                '',
                'ROUND ' + G.round + '   FINAL SCORE ' + pad(G.score, 5),
                'BEST ' + pad(G.best ? G.best.score : G.score, 5),
                'REMEMBER: ARROWS MUST BE BLUNT.',
                '',
                'SPACE TO TRY AGAIN'
            ]);
            H.then(function () { G.mode = 'OVER'; });
        }
        function showWin() {
            G.score += 1000 * Math.max(0, G.lives);
            G.won = true;
            saveBest();
            G.mode = 'BUSY';
            ending('win', [
                '', 'THE FIELD IS HELD', '', '', '', '', '', '', '', '', '',
                'THE LAST OF THE DEAD TAKES A KNEE.',
                'THE REEVE CALLS HOLD. NOBODY HEARS.',
                'YOUR ARROWS WERE BLUNT THE WHOLE TIME.',
                '',
                'THE ZOMBIES ASK IF THERE IS POTLUCK.',
                'THERE IS. THEY BROUGHT THE CHIPS.',
                '',
                'ROUND ' + G.round + '   FINAL SCORE ' + pad(G.score, 5),
                'BEST ' + pad(G.best ? G.best.score : G.score, 5),
                '',
                'SPACE TO GO AGAIN'
            ]);
            H.then(function () { G.mode = 'OVER'; });
        }
        function newGame() {
            G.clock = 0; G.stage = 0; G.stageMsg = ''; G.fx = []; G.hbMs = 0; G.warMs = 0; G.won = false;
            G.round = 1; G.score = 0; G.lives = 3; G.nextExtra = EXTRA_EVERY; G.deaths = 0; G.hits = 0;
            G.sfxQ = []; G.sfxWait = 0; G.msg = ''; G.msgMs = 0;
            wipe(showCard);
        }

        /* ----- input ----- */
        function askQuit() {
            G.wasMode = G.mode;
            G.mode = 'ASK';
            say('LEAVE THE FIELD?  Y OR N');
        }
        function onKey(key) {
            var go = key === 'SPACE' || key === 'ENTER', act = KEYMAP[key];
            switch (G.mode) {
                case 'TITLE':
                    if (go) { newGame(); } else if (key === 'H') { showHow(0); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'HOW':
                    if (go) { showHow(1); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'HOW2':
                    if (go) { showTitle(); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'CARD':
                    if (go) { beginRound(); } else if (key === 'Q') { saveBest(); H.quit(); }
                    break;
                case 'OVER':
                    if (go) { showTitle(); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'PLAY':
                case 'READY':
                    if (act === 'LEFT' || act === 'RIGHT') { pressTurn(act); } else if (act === 'RUN') { run(); } else if (act === 'BRAKE') { brake(); } else if (key === 'Q') { askQuit(); } else if (G.mode === 'PLAY') {
                        if (key === 'SPACE') { shoot(); } else if (key === 'H') { callRunner(); } else if (key === 'P') {
                            G.mode = 'HOLD';
                            draw();
                            say('HOLD!  P TO LAY ON');
                        }
                    }
                    break;
                case 'HOLD':
                    if (key === 'Q') { askQuit(); } else if (key === 'P' || key === 'ENTER') { G.mode = 'PLAY'; draw(); if (G.msgMs > 0) { say(G.msg); } else { help(); } }
                    break;
                case 'ASK':
                    // A sits right beside Q on a keyboard, so a stray press must not end the run.
                    if (key === 'Y') { saveBest(); H.quit(); } else if (key === 'N' || go) { G.mode = G.wasMode; if (G.mode === 'HOLD') { say('HOLD!  P TO LAY ON'); } else { help(); } }
                    break;
                default:
                    break;
            }
        }

        if (window.OrkpleStrict) { start.dbg = G; }   // tests only
        defAll();
        H.every(TICK, tick);
        H.keys(onKey);
        showTitle();
    }

    window.OrkpleGames = window.OrkpleGames || {};
    window.OrkpleGames.asterorks = {
        title: 'ASTERORKS',
        start: start,
        _pure: {
            WIN_ROUND: WIN_ROUND, STAGES: STAGES, orientOf: orientOf, stageFor: stageFor, rot90: rot90, countFor: countFor, speedFor: speedFor, speedMul: speedMul, splitTotal: splitTotal, circlesOf: circlesOf, wdist: wdist,
            BASE_ZOMBIES: BASE_ZOMBIES, BASE_SPEED: BASE_SPEED, GROWTH: GROWTH, SIZE_MUL: SIZE_MUL, SIZE_PTS: SIZE_PTS, FACE: FACE,
            W: W, H: H_PX, TOP: TOP_PX, START_X: START_X, START_Y: START_Y, MAX_ARROWS: MAX_ARROWS, ARROW_RANGE: ARROW_RANGE, MAX_RUN: MAX_RUN
        }
    };
    // State hook for the specs, attached only when the test flag is set.
    if (window.OrkpleStrict) { window.OrkpleGames.asterorks._dbg = function () { return start.dbg; }; }
}());
