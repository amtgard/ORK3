/* PAC-MORK: an ORKPLE II game. Recover your spellballs before a dagger finds you.
   Runs against the terminal's game host; touches nothing but window.OrkpleGames. */
(function () {
    'use strict';

    var TICK = 40;
    var MAZE_W = 29, MAZE_H = 21;
    var SIDE = 30;			// first sidebar column
    var MSG_ROW = 22, HELP_ROW = 23;
    var DOOR = [14, 9], OUT = [14, 8], HOME = [14, 10], START = [14, 15], BONUS = [14, 13];
    var DIRS = { UP: [0, -1], LEFT: [-1, 0], DOWN: [0, 1], RIGHT: [1, 0] };
    var ORDER = ['UP', 'LEFT', 'DOWN', 'RIGHT'];
    var BACK = { UP: 'DOWN', DOWN: 'UP', LEFT: 'RIGHT', RIGHT: 'LEFT' };
    var KEYMAP = { UP: 'UP', DOWN: 'DOWN', LEFT: 'LEFT', RIGHT: 'RIGHT', W: 'UP', A: 'LEFT', S: 'DOWN', D: 'RIGHT' };

    // Left half of each field, column 0 to the centre column; the right half is the mirror image.
    // # wall, . spellball, * fireball, - Nirvana door, space is open ground.
    var FIELDS = [
        {
            name: 'THE PARK', art: 'park',
            flavor: 'SOMEONE PARKED ON THE NORTH FIELD.',
            rows: [
                '###############',
                '#............##',
                '#*##.####.##.##',
                '#.##.####.##.##',
                '#..............',
                '#.##.#.#####.##',
                '#....#...#.....',
                '####.###.#.####',
                '####.#.........',
                '####.#.##.####-',
                '####.#.##.#    ',
                '    ......#    ',
                '####.#.##.#####',
                '####.#.........',
                '####.#.#####.##',
                '#......#.......',
                '#*##.###.##.###',
                '#.##.....#.....',
                '#.##.##.##.##.#',
                '#.............#',
                '###############'
            ]
        },
        {
            name: 'THE CASTLE RUINS', art: 'ruins',
            flavor: 'TWO SALLY PORTS. NEITHER IS GUARDED.',
            rows: [
                '###############',
                '#......#.......',
                '#*####.#.#####.',
                '#..............',
                '#.##.#####.####',
                '#.##...#.......',
                '#.####.#.#####.',
                '#......#.......',
                '####.##........',
                '####.##.#.####-',
                '    .##.#.#    ',
                '####.##.#.#    ',
                '    .##.#.#####',
                '####.##........',
                '####.##.###.###',
                '#..............',
                '#*##.#####.##.#',
                '#.##...#......#',
                '#.####.#.####.#',
                '#.............#',
                '###############'
            ]
        },
        {
            name: 'THE TREE LINE', art: 'trees',
            flavor: 'THE TREES ARE NOT IN PLAY. TELL THEM.',
            rows: [
                '###############',
                '#.....#.......#',
                '#.###.#.#####.#',
                '#*#.......#....',
                '#.#.###.#.#.###',
                '#...#...#...#..',
                '#.###.#.###.#.#',
                '#.....#.......#',
                '###.#..........',
                '###.#.###.####-',
                '###.#.###.#    ',
                '   .......#    ',
                '###.#.###.#####',
                '###.#..........',
                '#...#.###.##.##',
                '#.#...#........',
                '#*#.#.#.###.#.#',
                '#...#...#...#..',
                '#.#.#.#.#.#.#.#',
                '#.............#',
                '###############'
            ]
        }
    ];

    // Speeds are milliseconds per step. The field gets faster and the fireball gets shorter.
    var LEVELS = [
        { foe: 205, fire: 8000, doors: [0, 2500, 7000, 12000], plan: [[6000, 0], [20000, 1], [5000, 0], [20000, 1], [4000, 0]], item: { spr: 'dues', name: 'DUES RECEIPT', tag: 'DUES', value: 100 } },
        { foe: 190, fire: 7000, doors: [0, 2000, 5500, 9500], plan: [[5000, 0], [20000, 1], [4000, 0], [25000, 1], [3000, 0]], item: { spr: 'turkey', name: 'TURKEY LEG', tag: 'TURKEY', value: 300 } },
        { foe: 175, fire: 6000, doors: [0, 1500, 4000, 7500], plan: [[4000, 0], [25000, 1], [3000, 0]], item: { spr: 'favor', name: 'BELT FAVOR', tag: 'FAVOR', value: 500 } }
    ];
    var PLAYER_MS = 105, SCARED_MS = 240, EYES_MS = 70, LEAVE_MS = 190;
    var COUNT_MS = 3000;	// the death count in Nirvana
    var ITEM_MS = 9000;
    var ITEM_AT = [90, 190];	// spellballs recovered when a bonus item appears
    var EXTRA_AT = 3000;
    var TAGS = [200, 400, 800, 1600];

    var FOES = [
        { key: 'R', name: 'RUSHER', home: [27, 0], hits: ['RUSHER GOT YOU. "GOOD SHOT," YOU LIE.', 'RUSHER: "WAS THAT GOOD?" IT WAS GOOD.'] },
        { key: 'F', name: 'FLANKER', home: [1, 0], hits: ['THE FLANKER, FROM BEHIND. YOU TAKE IT.', 'THE FLANKER SAYS SORRY. NOT VERY SORRY.'] },
        { key: 'B', name: 'BACKLINE', home: [27, 20], hits: ['BACKLINE TAGGED YOU AROUND A SHIELD.', 'BACKLINE FAILED WEAPON CHECK. YOU DIED.'] },
        { key: 'N', name: 'NEWBIE', home: [1, 20], hits: ['THE NEWBIE GOT YOU. THEY ARE THRILLED.', 'NEWBIE GOT YOU, THEN ASKED WHO IS KING.'] }
    ];

    var HOW = [
        '', '',
        'YOU THREW ALL YOUR SPELLBALLS. NOW THEY',
        'ARE ALL OVER THE FIELD, AND SO ARE FOUR',
        'PEOPLE WITH DAGGERS.',
        '',
        '     SPELLBALL.  GET EVERY ONE.',
        '',
        '     FIREBALL.  FOR A SHORT TIME THE',
        '     DAGGERS FLEE SHAKING.',
        '',
        '',
        '           TOUCH THEM AND THEY GO',
        '           TO NIRVANA AND COUNT TO 300.',
        '',
        '           LOST PROPERTY. WORTH POINTS',
        '           WHILE IT LASTS.',
        '',
        'ARROWS OR W A S D TO MOVE',
        'THE OPEN SIDES OF THE FIELD WRAP AROUND',
        'SPACE CALLS A HOLD.  Q LEAVES THE FIELD',
        '',
        'PRESS RETURN'
    ];
    var HOW2 = [
        '', '',
        '       RUSHER',
        '       COMES STRAIGHT AT YOU',
        '', '',
        '       FLANKER',
        '       GETS IN FRONT OF YOU',
        '', '',
        '       BACKLINE',
        '       WORKS OFF THE RUSHER',
        '', '',
        '       NEWBIE',
        '       BRAVE UNTIL IT IS CLOSE',
        '', '', '', '', '', '',
        'PRESS RETURN'
    ];

    var WIN_TEXT = [
        '', '', '', '', '', '', '', '', '',
        'HOLD! HOLD! HOLD!',
        'EVERY SPELLBALL IS BACK IN YOUR POUCH.',
        'YOU TURN TO THE REEVE IN TRIUMPH.',
        '',
        'THE REEVE LOOKS AT YOUR SASH, THEN AT',
        'THE SIGN-IN SHEET, THEN AT YOU.',
        '',
        '"YOU SIGNED IN AS A BARBARIAN."',
        '',
        'PLEASE RETURN THE SPELLBALLS TO THE',
        'WIZARD, WHO HAS BEEN LOOKING FOR THEM.',
        ''
    ];

    /* ----- pure helpers ----- */
    function reverse(s) { return s.split('').reverse().join(''); }
    function parseField(rows) {
        return rows.map(function (half) { return (half + reverse(half.slice(0, 14))).split(''); });
    }
    function wrap(c) { return (c + MAZE_W) % MAZE_W; }
    function step(c, r, dir) { return [wrap(c + DIRS[dir][0]), r + DIRS[dir][1]]; }
    function open(grid, c, r, door) {
        if (r < 0 || r >= MAZE_H) { return false; }
        var t = grid[r][c];
        return t !== '#' && (t !== '-' || !!door);
    }
    // Steps from every cell to (tc, tr), walking through the door: how returning and leaving foes navigate.
    function distField(grid, tc, tr) {
        var dist = [], q = [[tc, tr]], i, r, c, k, n, cur;
        for (r = 0; r < MAZE_H; r++) { dist.push([]); for (c = 0; c < MAZE_W; c++) { dist[r].push(-1); } }
        dist[tr][tc] = 0;
        for (i = 0; i < q.length; i++) {
            cur = q[i];
            for (k = 0; k < 4; k++) {
                n = step(cur[0], cur[1], ORDER[k]);
                if (open(grid, n[0], n[1], true) && dist[n[1]][n[0]] < 0) {
                    dist[n[1]][n[0]] = dist[cur[1]][cur[0]] + 1;
                    q.push(n);
                }
            }
        }
        return dist;
    }
    function pad(n, w) { var s = String(n); while (s.length < w) { s = '0' + s; } return s; }
    function sq(ax, ay, bx, by) { return (ax - bx) * (ax - bx) + (ay - by) * (ay - by); }

    /* ----- pixel art: 1-bit sprites, built once at start ----- */
    function mirror(rows) { return rows.map(reverse); }
    function scale2(rows) {
        var out = [];
        rows.forEach(function (r) {
            var w = '';
            r.split('').forEach(function (ch) { w += ch + ch; });
            out.push(w, w);
        });
        return out;
    }
    // The player, 9x10: a half-circle wizard under a pointed hat, facing right or left. All lit pixels sit inside the
    // 7x8 cell, exactly the foes' size, and a one-pixel dark ring (o) around them cuts a notch in any wall line, so the
    // hat tip always reads. Drawn 1px left of and 1px above its cell to make room for the ring.
    var WIZ_W = 9, WIZ_H = 10, WIZ_DX = -1, WIZ_DY = -1;
    var WIZ_HAT = ['...#...', '..###..', '..###..', '#######', '.......'];
    var WIZ_BODY = [
        ['###.###', '.#####.', '..###..'],	// 0 closed
        ['###.#..', '.####..', '..###..'],	// 1 half
        ['###.#..', '.###...', '..###..']	// 2 wide
    ];
    // Pad one pixel all round; any unlit pixel touching a lit one (8 neighbours) becomes a dark o.
    function wizRing(rows) {
        var h = rows.length, w = rows[0].length, out = [], y, x, dy, dx, s, near;
        function lit(px, py) { return py >= 0 && py < h && px >= 0 && px < w && rows[py].charAt(px) === '#'; }
        for (y = -1; y <= h; y++) {
            s = '';
            for (x = -1; x <= w; x++) {
                if (lit(x, y)) { s += '#'; continue; }
                near = false;
                for (dy = -1; dy <= 1; dy++) { for (dx = -1; dx <= 1; dx++) { if (lit(x + dx, y + dy)) { near = true; } } }
                s += near ? 'o' : '.';
            }
            out.push(s);
        }
        return out;
    }
    function wizardRows(dir, frame) {
        var rows = WIZ_HAT.concat(WIZ_BODY[frame]);
        return wizRing(dir === 'LEFT' ? mirror(rows) : rows);
    }
    // The body sinks away a row at a time, and the hat settles to the ground where it stood.
    function wizardDieRows(i) {
        var rows = wizardRows('RIGHT', 0), gone = Math.min(i, 5), out = [], k;
        for (k = 0; k < gone; k++) { out.push('.........'); }
        return out.concat(rows.slice(0, WIZ_H - gone));
    }
    // Points shown on the field when a dagger carrier is tagged: 3x5 digits, a clear column between, then the ring.
    var DIGITS = {
        '0': ['###', '#.#', '#.#', '#.#', '###'], '1': ['.#.', '##.', '.#.', '.#.', '###'], '2': ['###', '..#', '###', '#..', '###'],
        '4': ['#.#', '#.#', '###', '..#', '..#'], '6': ['###', '#..', '###', '#.#', '###'], '8': ['###', '#.#', '###', '#.#', '###']
    };
    function ptsRows(n) {
        var rows = ['', '', '', '', ''], t = String(n), k, y;
        for (k = 0; k < t.length; k++) { for (y = 0; y < 5; y++) { rows[y] += (k ? '.' : '') + DIGITS[t.charAt(k)][y]; } }
        return wizRing(rows);
    }
    // Full-frame art: 1-bit rows, each row XORed with the one four above so ordered dither vanishes, zero-run coded, base 64.
    var IMG = {
        title: ['igABoQEBgKABA8CPAAiFBP////+AhAED4IUBf8COBP/////4hAEG8IQCB//8jgT//////oEACIEBBmiEAh///44F//////+AgwMMOABAggN////AhgAIhQYIiIiI92ABggEMnIMEAfcIt3CRAQf4gwEYCoEGEAAD+AAD+JEDAfwACIEBGBaDAQfggQD8iwAghQB+ggQEMAUAQIEBD4CBAD6QAwgAFwGCAjgKgIIBFoCBAAeEAECKAzwAB4CCBmAEgAAQADyCAgeAEIsGIiIi/yIhwIICQiNAggVaIiLCIcCKACCCBAH/gAHggQMEwAJAggXwAAHgAaCMBiIiI3yCIlCCAuIjIIIFwiIjeiEQgwBAgwAChAQDz8AAUIENAYABIAACAcAAA/AHiBCNBAUQwAAogQMBgACQgQYBgAADIC3EgwAIiQQGIGAABIEAA4EAkIEAA4EDASA/uIoACIIEDEPgADSBDAOAAMgCAAOAAAHY1nCHASAChAQETCAANIEABoEDSAACBoEDA/H4iIwGCIiMkCiIioEDBIiIhIEGBIiIi0XzBIMACIkEBKAgAAaBAwwHACSBAASCAs/EGIoACIIEDsBAACaBBg4FADICAAaCAhWYYIEACIEAEIMAAYMEB8DgACaBAxgfwBKBAAiCAv5ggIEAf4EG/gAB/AAP4IIEAUNgACCBAxA1IAGBAAiBAgNYg4IR/4AB/QAD/gAf0ABEREc/hERggQM0OGRJgQUMRERH8wSCCf+AAf8AA/oAH/CCAQPVgQBGgQY40VgOgAAIgQIlzBiCCfWAAe0AA9YAHtCDAH+BAMaBBGDiOASAgwI/EGCBCgGBwAMAgAYFADAIgwqWAANGAARDYnYDQIMCJmCAggADgQAIgQAsgQCAgwMEAAeKgQSAxJwGQIQAg4MABYEAFoEDUAABYIUBDRSBBOAJAAHAgwEnDIMALYEAvIEY9AALwAARERERER8UAAGR0hkQIAABEREvEIIKAYHAAxCABiUAMQiFCtYkAAUDYjYD4AAIgQEloIMJ9IAB5QAD8gAeUIQLBbxIAAMAtDwAkAAMgQEDMIMJ+oAB6QAjqgAekIQLLVmQAAOA0FgAEAAIgQECWIMJ0IABQQADAgAUEIINERFu5hAABwA88ABIAAiBAQHuggEEf4EG7gAB3AAO4IINFVVVmCAABAA3YADIAAaCAZWAggMIAgAQgQIgAAGBFBERe7u+YEAACREakRFkAAUREREawIcAIIYIFVV/gYAADgANgQIEAASCAQ1wjwM8AHgGgQIYAAKBAgIAB4MA6IEABIwABIEAGIECEAACgQMyAAOAggDWgwACigAEgQBggQAggwIZAAKDAWuAjgYEAAeAAA/YgwID8AGDASVAhAAEhgRERAf/+IEOCrREREREQLgBxEREREKgkwEVQIMDD1wAwIIDAxAAIJEBGoCDAweqAKCCAQGIjAFERIIQQAA+xERERERG5QBQREREQUSEAASNACCFAhUAKIIBAqiTAECFAwqAFICBACWBACCRAHCGAoAKQIEBaiiBAICOAkAAoIUHBEAFSAACFESMAVVVhA7VVVVVVVVVUkACjQAWqIiMApVVQIMOFVVVVVVVVVagAc0qlXMQjAKKqsCDDkqqqqqqqqqqoADsqqrMIIEAgIkCgRFAgw6BEREREREREVAAWJETMECCAAKIAvAAOIMA8IYGsAAn0r/AgIsDAb//hIECP/+whgUh/+eAP/OCAgP//4IH////gAHf/8SBAj//2IYGIf/5///zgIENA///gAAB////wAFnd2SBAnd3ZIIACIIGEXd293dHcIENA3V3QIAHd3d2IAHwACyBEH///f///AAf///x//+H/D/8gQkD//8gAA////gQggBogQLAAAGBAh/dXIYBD/+EAhAAH4EBD4iCACiBAsAACYECf//7hwF/gIMCkAB+gQEf0IICFAABgQAFgQP/fVWAhgEHYIMCUIDwgQE2QIILJAABgAADAAP8AB7ghgED8IMCMAHwgRB4kAAiIjIAAyIiIAAH4CCJUIEGIiIiAiIi2IEIIiIAB8IiIPEIggISAAODBA+AAAGohAD8gQB8hAUPgAAD4hCCAgEABoMEFwCCANSDAQF2gQAWhAUXgAAPRGCCAgkABoMAPIIASoMBA/+BAA+EAD6BAg+IgIICBIAOgwA4ggATgwEF8oEBBoCDANqBAT8xgwIEgAyDAHCCAQOAggUPE8AAA0CCAQHwgQE8QoEgIiIgQBIiIiAA6ACCAATAACIiJiFCIiNAACIiA0IiIviEgwICQBiDAMCDAKCCAgxAwIEAIIIFD8AAAfEIgwIDIDCCBgHAACCAA2CCBgiP4AADoBCBAA2BAgHiMIMCASAwggEBgIECQAHQ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', 280, 152],
        park: ['aKqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqRERERERERERERERERERERERERERERERERERERERERERERESqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqsUiRERERERERERERERERERERERERERERERERERERERERERERESZASqgwgICtWqgAf/4nwMJf/SAngIH//+fAzfgP2CeAz8AB+CeA5wAAcieAHiBAPCdAQJggQAyngDAgQAYhRmIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiLwIEGHoiIiIiIiJcBAYCBAAydAAmCAQSAnAADggAGnQACggACnQACggACnQACggACqQIC3aCfAgdVcJ8CNERGkwACggAChgI1VVaTAAKCAAKFBALKVSmgkgACggMCACXaggQBUgAlQJIAA4IEBgB9V4CBGCBxEQJCIiIiIiIiIiIiIiIiIiIiIiIiIimCAQSjgQdKIiIDaIiDYJIBAYCBBAwDVVVQgQUGFVVRMiCRAQPAgQQeJK1SioEADIIBH+CSAMCBBBgdKALWgQYEEAAQBnSAkQBggQEwk4EJSoAADoAACDVXgJEAeIEB8PaBAwXgAD2BAwUaqOCRBBwAAcGsgQMCmgAYggIKtXCRBD8AB+1IgQMDVAARgQMBABLgkQQX4D9SsIICooAYggKAAtSRBAf//zWgggK1gASEAGiRAwH//EmDARKghQAlkgL/+NiDAQFAhQAGkgWXSTAFVUCBAagYhAEBQJIEAWAIiICBAdAQhRLiIiIiIiIiIiIiIiIiIiIiIiIigQPAFVVQgQEyGIUAYJIBA4CEARAYhQBgkAYGgA8AUAAUgQEIDoIAgIEAIJACD+AMggAIgQEIDIUAcJAGD/4IAEAABYEBGAiFADCQAg//44UBCAGBAAGCABiICyIiIiIgAAIAAed4oIEAAYIAToUEMAQUABCDBgRERERERECCAyfysICBAICBAAmBAAWDAwgIACCDDKqqqqqqqqqCCAAR9aCFAEiGAwQUABCFBVAABAAQBYIAGIUECCgQABCDCIKiiKCoiIgiIoMHAiIgIiAQPKCEBBgAiAAIgwMQQABAgQQFREAAEIIGFEVUQDjqsIIGgAAIoFVFUIIIGAIiAAggAKqqhgUooqAX1aCEAAiGCQQRQQBEUAQBAAWBCAQQAEAAFAA8rYcBARGDBx4ggACAiqqqiQIB9WiCAAGBBBwIAIiAgg0FQQBEBFREQAABAQAQEYEGEAADvEAAgIMDBgwAEIMGAyCAqoKKqooGA1KAAEAAAYEBCx6FEwGAAAEBABEEQBABBBEBAAQQQAKShgEFBIYDgACqAowBAqOBAlAABIEBBhSGBsABAQEAERCDAgEAAYECAQEWgQIIAAiBAQMOhQEB4JAAAoECFABUgQEBB4YAwIIFBAAQEABAgQZAQQEAAQABiADAhQBgkAUDgAAFVVCCAwRIACqDAMCGAhAAAYIAAYMAQIEBiICCA1dsABSDFhVVVVVVVVVVVVVVVVVVVVVVQVdgAAFVgwNKUAAqgxMJSw5S19T1oG19ShpSwpT1pWFsUoYDWlgAEIMUClrSlrSlrSlrSlrSlrSlrSlhSttAhQFVHoUU1VVFVVRVVURVRVVVVUVVVVVBVRVohQHhzIUUY+Hw+Hw+Hw+Hw+Hw+Hw+Hw+Bw9SshQEeBIUU3B4PB4PB4PB4PB4PB4PB4PBgPAdWhQFzt4QVAcdzudzudzudzudzudzudzudwOdyK4YBA4CEAICSAz2AACqCAh4KwIQXvB4PB4PB4PB4PB4PB4PB4PBgPB4GwAAUgQMBAAFggwEBgJIDA0gAKoEDA53O8IMYBzudzudzudweDweDwedzudzuYTudzq0AFIQA0oMABYYE//////6IAtWqgIEAAYECa0AQgQBKhQYB//////+AhwJqqoCBAAOBAjVAkoEA1IUGB0AAMAADQIcCHVVAgQUm7nciwO+BCunc7nc7nc7vgAAwgQ73nc7nc6Hc7nc7tIBAAGuBBQdBVWqlUIUGHP//7//+WIgE/8FoAlSBBQHAuqqq4IUGff//z///nooCrVKoggQBx1VVgIUG10AAIAADRYkTAVVVcPB4PAAB7u4B4PB4PB4O/4CDHfPeDweDweDweDweAOqqwR4PB8HB3wB4PB4PB4PHXIQOWWPB4PBgPB4PB4PB3ad4ggBBiAEP/IQBHniJAQf4gwBBhwt/9YVVVUVVVVBX/4CNAEGFDgP///4P///P///8P///+IkiHg8HgMHg8Hg8HgxS1pIVVVVFVVVUNpS1qGA8Hg8Hg8Hg8HiJBAfgAA+AgQAwggB4gQAciQ0eDweAweDweDweB/UqoIYNAqrVVGw8Hg8Hg8Hg8HiJDwwREf0RERERERERH9ERFgyEAEGMAw4ABa2GBFrQAA4MhABBjA8NSqOuKqqqqqqqqjriqrYMhABBgg4eDweAweDweDweCqAQAECEDgEABAACYDweDweDgODweIkOBURkqGREREREREZKhkREkw4CqoWtCqqqqqqqqFrQqqiVAjkE4IQCA5BOlwJwIHCEAgcCB4siHg8HgMHg8Hg8HgVVIFAlVVVVVVVSBQJVVHg8Hg8Hg4Dg8HiJDg//4Kg////////+CoP//pMOCqqwUGqqqqqqqqsFBqqqkwENVYEGBVVVVVVVUIEBVVaJIh4PB4DB4PB4PB4P/7BQb///////+wUG//54PB4PB4OA4PB4ggB/hwIgqCCEAgIKgo4Af4cCIFAghAICBQKOAH+HAjAgYIQCAwIGjgB/hwIZBMCEAgGQTJcCFa1AhAIBWtSHAH+OAgqqgIUBqqiHAH+CIoPB4PB4PB4PB4PB4PUtPB4PB4PB4KUoPB4PB4PB4PAHPB4PiwEDroYBOuCHAH+PAPiGAQ+AriKDweDweDweDweDweDweDweDweDweDweDweDweDweDweDweDw', 280, 120],
        ruins: ['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', 280, 120],
        trees: ['IqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqoiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIo4AAYYAAooiIiIiIiIiIiIyIiIiIiIiIiIiIiIiIiIiIiIiIjIiIiIiIiKDAAiEAgQACIoAAasACI4AAYYAApIAEJIAEIkACIQCBAAIhgCAggABqwAIxAACkAABgQCAvwACtAACkAABwgECIJQAIMEiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiKPACCEAAGOACCKAAGzAAG5AAGaAAGzAAG5ACCOAAiMAAiDAKiLAwIgAAiMABCDAFCLAwIiAAiFAAiEASAQgwBigQIiACCEBSIABVAAFIUACIUAKIMAoIYAEIMojuiAmICMgICAgJSAgIiIgICoiICIgICIgACAgICImICAAIgK4AAtAASDAhAAgIIBgFCBAgQA2IYAKIMMKIAiICAqABIiACgggIEgIKJwCAAEAUAAIgAiYiIAKCAiCAANyAAmgAgAEAIAKAFAgQcBQGwIABIBgIMfQAgAQAEACAAfyIBygJwAoI8ANoGACAgJgIAcABICDIiBFYCggAh8gQAUiBmAAGEAFQAgBABQAoCBCBLgwBQALAKIAoIUgBQA7AKAEAAIDiCDYgsiMAhAQgKigQgyI0IgIC8gAgKBVwLgMgCAAiA6ICuIIKOAK4BQCwChBUBCACRBRDgAQgMABQAgAUCoAUIFICgAgACoAKD6gMQNoICPOEoAJzgCNgB6iAwOAPgBGACBxg8otgAzBlCAgFUAoBCBDEoA5QBeIYFQAGwGBAqBAgKBVoF/BtBQAAQGYwHAB6CCMMDAaAKmIlgLAEICgysEKACAAoOSIQMIYHIBagEjgFBmwMA4QYGkCUIAdBEAoQDASgoNgKADA6ACAwywYAHTAgLAqMgBxiA6AqwZ8ICgEgCAAcIYKhyBuA8SI48AHoiBgLYC1IDhSEGAAEJBDAKlAMABAQAAAYEcEBgBQAYFAgSAFRDAAWAAQAADPCOCMAaD6AIEI0CBD8ACAgA+CGAiBAcgKwHjysCECxJUQYEAYYGwDBhBQIIJgYFYGyiDhAYKEIEyG4TAgtAApAAg8CsAwDMCCA8YACwOAwCLALAggAtgDAgJgAACAQGKsADIADGYFQDQEAMEgQwBqAQCAEUAhDAwJQIUgRUIAFAEgEMsALsAKDADAoAkBwgEDkNggh6mAIhwgGQiNCwIEABrAoAn2AAyAFZAGQAgOIQADAhFgRQFgKgAWEAgChUkGAAyABYEgKaQAASBDDAKAgADCAg8AC6ABoiCDjCAsAgKgAAO7AAwAoAIMIMBUBCBBxoGABYECWABgQAggQlgICACmCAECABNgQEOQIIgAQAKAQAOAAYMB2LAAAQADABAAGAsCABgABAAeACAAEABgRQCAAwCAAgMAAwABQABjAA4AGjAABiBBBgAIAAGgRwsoAJoAAjAAg7ACLgAEACEgAC7AKgAq4AAIA5gMIIADIEbEFABCAARgAAMgA0QBBRECERFAkRkRIBAQCAECIEMwABAAIBoMAKcAAqAA4EjBAwHKqsgqqomqoqqwmBwYAAwAAOgAEAAICzgASgAA0AFgAACgQk67/7u7nbu7u7fghMBAJgAGAAyAUBYQALAAAgAAqAAAYEZtVXdVVVlVdVVTiAYsAADMAZQAMwBgIAAAYCCBgIQAAEwADCHEAGACAACgGAAgABYAIAAsAmsgxQLAAJAATf///////////2AGgABAsCCCLAAQEAgBggAEIEF0gACmAAIhzgCABSAASGAExgBYABwwMAKMAAqAAIHAAKwCQhE58A5EB5A5EIAG7AG7wAEkAPAAAOwgACgABUAAnqBGjAAB7ru/+7v7r7rvAAKYAJMAARgBlAAQmAACIEgIwABIAABQAoAZREAEZARQRKAABAAASDAAUACMABCQAAUgQACgQBggQDAgQdVEQARUB5B8YQCgYAQgQZgAFDAgC6wghECawABAAoACfbAKCAOAfVAAAiBFEUAKAABQAAZAIBwIABAAAWiAAdQBoUFAQAFQAAEgQQIAFxgA4EAAYEDKYAA64EwoAANgC4AIfAAKIABPAOAADLAAJrAwkAOsAAmgAASwAGWAAE4ABlAAQBVEQARUB5B8YENBYAAsYATgBkgAGlAAAiBEIwAAlAACAApAE0RABEwEEERgQ0hwADDAAUACMAAEMAAKIECGAABgRECAAMHuu7/7u/vvu78AAeAAMeCCwGAABuAAuqAAFAACYMMCAhE4QA5EBB9EQIAKIEBFAKBAAWBAAWBBoDAAEAAFRiCAQgIhwICAByDApGABIEpDAAChgALrAAwkACaAKo3///////////9gJrAAmoCgwC6gAAqwABfAAJIgQXQAAUAhTCHLAGAJYABRwBbACSAAGyAAnQACjAAC8AApgCHlVXFVVxVXFVf9SCiAAKMAnAAo4EDyQAB4IET4AAUgAAYAB4u7y7u+G7y7u7ugB6BBDwB4AAOgQgGAAuqAAlAAByBFbwAMKqjqqobqjqqqGqgnAACUAuqAJSBBQwACAcAB4EYXFAAWACgRAxERGfAxERBxEB4AAUACAMAUIEgKACqHqqmoADCwABqC6sAEJADCAEJAAagAGoAC6gKGqrqgmkCAWERCSAAEZERgAYWABDREQQRDREYAAGCABwcAWACEgABkgAJBqqo6qqGqqksChqqo6qqGCo6qpLAASwACjqjqqqOqqksAkxEREFERBxERBxEZEQMRERkQMREQcREHERAxExERDRERBxEghgGqqpqqqpqoaqquqqpqCuqqqaqqmqqo6qggQrqqqpqqBEQABkREYIDkRcREYZ3CREQkRENERERExEREJERVVP////////8VV////////x////FVVRVVV/////////8VVfweDweDweDweDweDweDweDwGDweDweDweDweDweDweDweDwYPB4PB4PB4PB4PB4PB4PB4MB4PB4PB4PB4PB4PB4PB4PB4PomgPh8Ph8Ph8Ph8Ph8Ph8Ph8PB8Ph8Ph8Ph8Ph8Ph8Ph8Ph8PvB4PB4PB4PB4PB4PB4PB4PAYPB4PB4PB4PB4PB4PB4PB4PBnc7nc7nc7nc7nc7nc7nc7nQ7nc7nc7nc7nc7nc7nc7nc7neiIvB4PB4PB4PB4PB4PB4PB4PAYPB4PB4PB4PB4PB4PB4PB4PBoiLudzudzudzudzudzudzudzuFzudzudzudzudzudzudzudzuegic7nc7nc7nc7nc7nc7nc7ncxnc7nc7nc7nc7nc7nc7nc7nc7oRYPB4PB4PB4PB4PB4PB4PB4MB4PB4PB4PB4PB4PB4PB4PB4P8Hg8Hg8Hg8Hg8Hg8Hg8Hg8Jg8Hg8Hg8Hg8Hg8Hg8Hg8Hg8GPAQOAoAEDgKABA4CQIvB4PB4PB4PB4PB4PB4PB4PB4PB4PB4PB4PB4PB4PB4PB4PBoiLweDweDweDweDweDweDweDweDweDweDweDweDweDweDweDwcU', 280, 120],
        win: ['IqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqoiKqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqrkAqKEA+KACCQSAnwEFVaABBOygAg2tgIkiiIiIiIiIiIiIiIiIiIiIiIiIiIiIiKkEqIiIiIiIiIiIiIiVAnAAcJ8C0RFYnwECqqACJNUgnwJ7KvCfAtAAWMICCYCAnwINgYCJIiIiIiIiIiIiIiIiIiIiIiIiIiIiIiImAyIiIiIiIiIiIiIilQIPB4CfAhgAwJ8CPKtgnwI2ViCfAj8tYJ8CEBVAnwEggJ8DCPBACJ4DH+ggMJ0EDd3kEBCdBA//yCAwnQQHf4QQEJ0DDh8CCIoiIiIiIiIiIiIiIiIiIiIiIiIjKmIgAAEEAiIiIiIiIiIiIiKQAgEIQIEBAgieAwH/AQScBYiAAf8wgpsGAYFACAAgQZgJA4AAAfdACf8wgpEDAiIiIoIBD4CBBXcACf8gQYYDAiIiIIYERERERECBAA6BAwHXwAiBASCAhAQERERERIUIKqqqqqqqoAAPgREDiSAB/wAQWACqqqqqqqqqqqqNAByBBwMA4AH/ACCIiQeIoiIiIiIiIIIJIiIiHIIiBwBwgIEIEFoiIiIiIiIggQcCRERERERERIMTBEREP8REFAoUAA/gCChERERERECDBqqqqqqqqoCEDQqqNMqqOKqaAgBABBKqlgpgYABW/rUH/zAIIIkBqoCKCgrkaADhk5ctVUAElwsDzjwAwBBBg7TQAiCWCw3EMACRIhCuqgABUJcGf+AAgVQAgIEBAqCWBweAyADG5uWAgQEBQJcBGbyBARFQgQKAAIiXAR9QgQYiAAIAwAAYlgED8IEHhUREggCAAAiJOFVVVVVVVVVVVVVVVVVcB9VVwiAhxRXAAB1VVVVVVVVVVVVVtDx4WktDx4WktDx4Wk4YR4XxEAOL2oEtA4WktDx4WktDx4Upa0pa0pa0pa0pa0pa3x+0pbAABnd6FQVEpa0pa0pa0pa0pYwBA/CBBl0RCQ+ABY2KaA+Hw+Hw+Hw+Hw+Hw+H0/3w+iQBLQCEQAEQ+Hw+Hw+Hw+Hw+8Hg8Hg8Hg8Hg8Hg8Hgn+A8EkO0YNngWNA8Hg8Hg8Hg8Hg8GdzudzudzudzudzudzuR/udyaqo99TRQUWdzudzudzudzud5AHDAAYDYBgADCJIvB4PB4PB4PB4PB4PB4PB4PB46rgL55AABPB4PB4PB4PB4PBkQZ/AAIAYAAwiSLudzudzudzudzudzudzudzudzwdzydBVUDudzudzudzudzuZUCIFAgnwIlJSCfAjDYYIkic7nc7nc7nc7nc7nc7nc7nc7nc7nc7kfD/c7nc7nc7nc7nc6VAhCIQIk', 280, 72],
        lose: ['iwCAlgAQkgIgAECKAICsAAKiAICWABCJACCHAiAAQIcAgIECgAABmABAkAACxQAgkAKAAICDAAGCAICUAECKAR/8oAJ//xCHABGWAv/9gJUAgIcEAf9/wAKdAwPgA2CSAAiKAweAAPCHABGFAICBACCLAA+BAMCVARAghgAOgQFggp0DHAAKIIMACIgAAoMACIcAEIEAGIEASIgAAYQAgIEAIIsAEIsBA8CKAxAgAICEABCCAICHAQPwlgBAgwAIgwEHYIIEAgBAAECIABCBAiAAAYkBDsCKAAGJAShAiAAcjgCAhgEFIIgBHHCLAICGAggKpYkBMOCEAkAQQIsDIAAUIIgAcIUAIIQBAUCGAgoqgYkAQpQDFAVQgIgC4EAQigCAhgIaoFCEABCCAgTi4IUAEIwDGVVCCIcCAYAghAAgggABgQFAQIUCF5FUiAIBgCCEAAGBACCKAwtQqgGHAwEIMBCNAAiDAQ1VhQAQggMHgDAIkgMGoiCAhwICHBCIAAGCAECFAwJEAASDAAKCAgKcuIQAAYEAIIsCqgABhAAQgQIGNgiIAAiEAAiPAwYUCAiTAQiIiAIMYwyWAASDAAKCAg4ADJQABIYAEIECDEBEiAIIAASJAICIAhAADIsCIAAIkAIQYwKSAASLAhgUAoYAQIkDIiIiJoQCgAACgQIwMgaGADCBA4CEAAKHAICIAiAUAYsMIAAIAKqqqqqqqqqgCoIBCAKBAnENEYIOAqqqqqqqqqqqqqqqqgBAhANABAACgQAEgQJAAAOBAUAEhQ5AQAAEBACioggIAIAiIiqBAoAACIESURkQgICiIiKiopIiIiIiKigqIo8CNVVVlg8KoKqqqqKoqqqRERCCqqqoiwZAAEQABEBAggICAASCBKqqqsBAgQgEQEAAQAAEAESEFYAACACAgAAioioiIKKhlVVVQiIACAiBAQiAhACAjwQB////wJ0AAYIAaI4EBAQAQECBDARAQEBEAEBEA6qqqqCCBUAEBAQABIIBQECEA4AACAiCDYAIgACvVVVVeoAIAIAIgQAIggCAgQCAjgRw7u7u144yVVVVVVVVVVVVVVVVVVVLAVVVVUBpVVVVVVVVVVVVVVVVVVXHhaS0PHxeS0PDgaT0PEtWgjI1OLB8eFpLQ8PFpLA8eFoPvK2tqWtCWtIWPK2tqWsd8VVVVUfcKetKWtqWtC2lIWvKWtqOBHEREREXjg58Ph8Ph8Ph8Ph8Ph8PhwuEVGhvh8Ph8Ph8Ph8Ph8Ph8IPB4PB4PB4PB4PB4PB5SxERERERPLB4PB4PB4PB4PB4PB4P7nc7nc7nc7nc7nc7nccVVVVVVVVUMc7nc7nc7nc7i87nc7mMCAkRERERERERGIcAHIMOg8Hg8ng8Hg8Hg8ng8GJAhA4BPHg8Hg8Hg8Hgzng8Hg+CAAeFAByBACWGAAaHABSDDXO53PC3O53O53PC3O4shg0YdzudzudzudzAdzudzoIABYUAFIEAWIYACYIAIIMAHJAAcIYAB4IA+IMAPoYAB4IAAoEAHIEA4IYBAoCBAPiDABSDDZ3O52153O5gu52153KAhwz53O/zO53O50u53O53ggAFggMPgAAUgQBAigD4iwACggMfwAAIgQhqqqqqqqqqqquTAQ6AgwnERERERERERESAgQDYgQAghUUeDweDweDwYDweDweCqqqqqqqqqqqqgeDx/Dwe9weDweDweIPB4PB4PB4Nh4PB4PBpLQ8eFpLQ8eF4PB7XB4D54PB4PB4PhgEfwI8D+AAB/IwBDICPACCBAOiMAQ+AmSKDweDweDweAgeDweDweDweDweDweDweDweDweA2eDweDweD5oBAfyFIoPB4PB4PB4PB4PB4PB4PB4PB4PB4PB4PB4PB4MJ4PB4PB4PmwD4oQAghQ', 280, 88],
        side: ['NH/hxx/uxhu/+P/hxx/uxhu//P/uuu/kuut//P/uuu/kuut//IAPfeAEfPFABAAPfeAEfPFAgQYPOBfuAPKAgQYPOBfuAPKAgQABgQIEAFCCAAGBAgQAUIIGDjgX5ADCgIEGDjgX5ADCgIIFAeAAfFFAgg8B4AB8UUAAgBBFEBFFJIAE', 70, 15],
        how: ['OH///uxu/gx/hvxu///+///+7G7+DH+G/G7///////7rrv+7v7r7rv///////uuu/7u/uvuu////gIEHB8ABt8A8B9uBAAGCBwfAAbfAPAfbhQHgBIICPAOVhQHgBIICPAOVhgAEggIEAA6GAASCAgQADoUB4A6CAjgDgIUB4A6CAjgDgIUGB8QAB8AA8IYGB8QAB8AA8IMKgAABFFsAREBBBESBAAE', 112, 15],
        who: ['QH///3XY/3Y3QYf3Y3f///j///912P92N0GH92N3///8////ddd/ddd3f/ddd////P///3XXf3XXd3/3XXf///yAggcPgAPkNvgNvoIABIMHD4AD5Db4Db6GCCHAACHCAPAKgIYIIcAAIcIA8AqAhgAggQQgBQCIB4cAIIEEIAUAiAeHBnHAAHHCAHiIBnHAAHHCAHiICSAPgCABAPgAPviFCSAPgCABAPgAPviCAICBCdoogNooiAgCIoiBAAQ', 126, 15],
        ready: ['Ln//////4P//////8PDBjDd38Pdfddd38IigiiUIEA88+iIIAAUe+eqAAAGAAAqIgQIeAeeBCw88+iIIAIogiiIIEA', 44, 11],
        layon: ['KH///////4D////////A9+N3+N3fwPfdd/dd38CIIlAIsiBACD4gCKoggQM+qA+EgwCogQYgAAeAcA+QgQ0IPiAIqiAAiCIgCKYgQA', 50, 11]
    };
    var B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    function unpack(a) {
        var s = a[0], w = a[1], h = a[2], bw = (w + 7) >> 3, raw = [], acc = 0, nb = 0, i, k, n, by = [], t, out = [], y, x, row;
        for (i = 0; i < s.length; i++) {
            acc = (acc << 6) | B64.indexOf(s.charAt(i)); nb += 6;
            if (nb >= 8) { nb -= 8; raw.push((acc >> nb) & 255); acc &= (1 << nb) - 1; }
        }
        for (i = 0; i < raw.length;) {
            t = raw[i++];
            if (t < 128) { for (k = 0; k <= t; k++) { by.push(raw[i++]); } } else { n = t - 127; for (k = 0; k < n; k++) { by.push(0); } }
        }
        for (i = 4 * bw; i < by.length; i++) { by[i] ^= by[i - 4 * bw]; }
        for (y = 0; y < h; y++) {
            row = '';
            for (x = 0; x < w; x++) { row += (by[y * bw + (x >> 3)] >> (7 - (x & 7))) & 1 ? '#' : 'o'; }
            out.push(row);
        }
        return out;
    }
    // Doubling for the big cast: EPX keeps diagonals as diagonals, so nothing looks like a stack of bricks.
    function epx(rows) {
        var h = rows.length, w = rows[0].length, a = [], b = [], x, y;
        function g(px, py) { return py >= 0 && py < h && px >= 0 && px < w ? rows[py].charAt(px) : '.'; }
        for (y = 0; y < h; y++) {
            a = ['', ''];
            for (x = 0; x < w; x++) {
                var P = g(x, y), U = g(x, y - 1), D = g(x, y + 1), L = g(x - 1, y), R = g(x + 1, y), p = [P, P, P, P];
                if (L === U && L !== D && U !== R) { p[0] = U; }
                if (U === R && U !== L && R !== D) { p[1] = R; }
                if (D === L && D !== R && L !== U) { p[2] = L; }
                if (R === D && R !== U && D !== L) { p[3] = D; }
                a[0] += p[0] + p[1]; a[1] += p[2] + p[3];
            }
            b.push(a[0], a[1]);
        }
        return b;
    }
    // The hero, large, drawn rather than doubled: frames closed, half, wide; 18x22 with its ring. Draw 1px up and left of the 16x20 box.
    var WIZ_BIG = [['.........ooooo....', '........oo###o....', '.......oo###oo....', '.......o###oo.....', '......oo####oo....', '......o######o....', '.....oo##o###oo...', '.....o##ooo###o...', '....oo###o####oo..', '....o##########o..', 'ooooo##########ooo', 'o################o', 'o################o', 'oooooooooooooooooo', 'o################o', 'o######oo########o', 'o######oo########o', 'oo########oooooooo', '.o##############o.', '.oo############oo.', '..oo##########oo..', '...oooooooooooo...'], ['.........ooooo....', '........oo###o....', '.......oo###oo....', '.......o###oo.....', '......oo####oo....', '......o######o....', '.....oo##o###oo...', '.....o##ooo###o...', '....oo###o####oo..', '....o##########o..', 'ooooo##########ooo', 'o################o', 'o################o', 'oooooooooooooooooo', 'o################o', 'o######oo#######oo', 'o######oo####oooo.', 'oo#########ooo....', '.o###########ooo..', '.oo############o..', '..oo##########oo..', '...oooooooooooo...'], ['.........ooooo....', '........oo###o....', '.......oo###oo....', '.......o###oo.....', '......oo####oo....', '......o######o....', '.....oo##o###oo...', '.....o##ooo###o...', '....oo###o####oo..', '....o##########o..', 'ooooo##########ooo', 'o################o', 'o################o', 'oooooooooooooooooo', 'o###########o.....', 'o######oo##oo.....', 'o######ooooo......', 'oo######oo........', '.o#######ooo......', '.oo########oo.....', '..oo########o.....', '...oooooooooo.....']];
    // Opponents: silhouette first, one idea each. Six rows of body, then two leg poses; art faces right, mirrored for left.
    var FOE_ART = {
        R: {	// horned helmet, dagger up
            body: ['#...#.#', '#####.#', '#.#.#.#', '.###.##', '#####.#', '.###...'],
            legs: [['.#.#...', '##.##..'], ['..#.#..', '.##.##.']]
        },
        F: {	// pointed hood, dark face, dagger low and forward
            body: ['..#....', '.###...', '##.##..', '.###.##', '#####.#', '#####..'],
            legs: [['#.#.#..', '.#.#...'], ['.#.#.#.', '#.#.#..']]
        },
        B: {	// round helm behind a kite shield
            body: ['.###...', '#.#.###', '#####.#', '.##.#.#', '###.#.#', '###..#.'],
            legs: [['.#.#...', '##.##..'], ['..#.#..', '.##.##.']]
        },
        N: {	// floppy feathered hat too big for them, tiny dagger
            body: ['.....#.', '.###.#.', '#######', '.#.#.#.', '.#####.', '..###.#'],
            legs: [['..#.#..', '.##.##.'], ['.#...#.', '##..##.']]
        }
    };
    var SCARED_ART = [
        ['.#####.', '#######', '#.###.#', '#######', '#.#.#.#', '##.#.##', '#######', '#.#.#.#'],
        ['.#####.', '#######', '#.###.#', '#######', '##.#.##', '#.#.#.#', '#######', '.#.#.#.']
    ];
    // Eyes of a dagger carrier on the way back to Nirvana: a halo, two wide open eyes.
    var EYES_ART = [
        ['.#####.', '.......', '###.###', '#.#.#.#', '###.###', '.......', '.......', '.......'],
        ['.......', '.#####.', '.......', '###.###', '#.#.#.#', '###.###', '.......', '.......']
    ];
    var ITEM_ART = {
        dues: ['#######', '#.....#', '#.###.#', '#.....#', '#.##..#', '#.....#', '#.#.#.#', '.#.#.#.'],	// torn receipt
        turkey: ['...###.', '..#####', '.######', '.######', '..####.', '..##...', '.#.....', '##.....'],
        favor: ['##...##', '###.###', '.#####.', '###.###', '##.#.##', '..#.#..', '.#...#.', '.#...#.']	// knotted bow, two tails
    };
    var BALL_ART = ['.#.', '###', '.#.'];
    var FIRE_ART = ['...#...', '#..##.#', '#.###.#', '##.####', '#######', '###.###', '.##.##.', '..###..'];
    var TREE_ART = ['.....##.....', '....####....', '...######...', '..########..', '....####....', '..########..', '.##########.', '...######...', '.##########.', '############', '.....##.....', '.....##.....'];
    var CLOAK_ART = ['#####.##########', '.#############.#', '..###########...', '...####.####....'];
    var BANG_ART = ['##', '##', '##', '##', '##', '..', '##', '##'];
    var COOLER_ART = ['..#####..', '.#######.', '.#.....#.', '.#.###.#.', '.#.....#.', '.#.###.#.', '.#####.#.', '.#######.', '..#####..', '...#.#...', '..#...#..', '.#.....#.'];

    // The maze as one sprite. Corners round off in two passes, as the wall lines did on the original; the mass of each wall
    // carries the field's own texture, sparse enough that a spellball never gets lost in it.
    var WALL_TEX = [
        function (x, y) { return (x + (y >> 2 & 1) * 3) % 6 === 0 && y % 4 === 0; },	// the Park: mown stipple
        function (x, y) { return y % 4 === 0 && (x + (y >> 2) * 3) % 6 < 4; },	// the Ruins: coursed stone
        function (x, y) { return (x + y) % 7 === 0 && y % 2 === 0; }	// the Tree Line: needles
    ];
    function mazeRows(grid, solid, style) {
        var W = MAZE_W * 7, Hh = MAZE_H * 8, m = [], rows = [], x, y, s, pass, next, tex = WALL_TEX[style], lit, deep, dx, dy;
        function isW(px, py) { return px >= 0 && py >= 0 && px < W && py < Hh && m[py][px]; }
        for (y = 0; y < Hh; y++) {
            m.push([]);
            for (x = 0; x < W; x++) { m[y].push(grid[Math.floor(y / 8)][Math.floor(x / 7)] === '#'); }
        }
        function outer(px, py) { return !isW(px - 1, py) + !isW(px + 1, py) + !isW(px, py - 1) + !isW(px, py + 1); }
        for (pass = 0; pass < 2; pass++) {
            next = m.map(function (r) { return r.slice(); });
            for (y = 0; y < Hh; y++) {
                for (x = 0; x < W; x++) {
                    if (m[y][x] && outer(x, y) === 2 && !(isW(x - 1, y) && isW(x + 1, y)) && !(isW(x, y - 1) && isW(x, y + 1))) { next[y][x] = false; }
                }
            }
            m = next;
        }
        for (y = 0; y < Hh; y++) {
            s = '';
            for (x = 0; x < W; x++) {
                if (grid[Math.floor(y / 8)][Math.floor(x / 7)] === '-') { s += (y % 8 === 3 || y % 8 === 4) && x % 3 !== 2 ? '#' : '.'; continue; }
                if (!m[y][x]) { s += '.'; continue; }
                if (solid) { s += '#'; continue; }
                lit = !isW(x - 1, y) || !isW(x + 1, y) || !isW(x, y - 1) || !isW(x, y + 1);
                if (lit) { s += '#'; continue; }
                deep = true;
                for (dy = -4; dy <= 4; dy++) { for (dx = -4; dx <= 4; dx++) { if (!isW(x + dx, y + dy)) { deep = false; } } }
                s += deep && tex(x, y) ? '#' : '.';
            }
            rows.push(s);
        }
        return rows;
    }
    function shutRows(k) {	// the k-th step of the closing venetian blind, one 8px band: k+1 rows of dark
        var o = [], y, w = '';
        for (y = 0; y < 280; y++) { w += 'o'; }
        for (y = 0; y <= k; y++) { o.push(w); }
        return o;
    }
    function defAll(H) {
        var d = ['RIGHT', 'LEFT'], k, i, fa, def;
        for (k in IMG) { if (IMG.hasOwnProperty(k)) { H.defSprite('img_' + k, unpack(IMG[k])); } }
        H.defSprite('stage', unpack(IMG.title).slice(112));
        for (i = 0; i < 8; i++) { H.defSprite('shut' + i, shutRows(i)); }
        d.forEach(function (dir) {
            H.defSprite('pac_' + dir + '_0', wizardRows(dir, 0));
            H.defSprite('pac_' + dir + '_1', wizardRows(dir, 1));
            H.defSprite('pac_' + dir + '_2', wizardRows(dir, 2));
            for (i = 0; i < 3; i++) { H.defSprite('pac2_' + dir + '_' + i, dir === 'LEFT' ? mirror(WIZ_BIG[i]) : WIZ_BIG[i]); }
            H.defSprite('pac2_' + dir, dir === 'LEFT' ? mirror(WIZ_BIG[2]) : WIZ_BIG[2]);
        });
        TAGS.forEach(function (n, j) { H.defSprite('pts' + j, ptsRows(n)); });
        for (i = 0; i < 8; i++) { H.defSprite('pac_die_' + i, wizardDieRows(i)); }
        for (k in FOE_ART) {
            if (FOE_ART.hasOwnProperty(k)) {
                def = FOE_ART[k];
                for (fa = 0; fa < 2; fa++) {
                    var rows = def.body.concat(def.legs[fa]);
                    H.defSprite(k + 'r' + fa, rows);
                    H.defSprite(k + 'l' + fa, mirror(rows));
                    H.defSprite('r' + k + 'r' + fa, wizRing(rows));
                    H.defSprite('r' + k + 'l' + fa, wizRing(mirror(rows)));
                    H.defSprite(k + '2r' + fa, wizRing(epx(rows)));
                    H.defSprite(k + '2l' + fa, wizRing(epx(mirror(rows))));
                }
            }
        }
        for (fa = 0; fa < 2; fa++) {
            H.defSprite('scared' + fa, SCARED_ART[fa]);
            H.defSprite('scared2' + fa, wizRing(epx(SCARED_ART[fa])));
            H.defSprite('rscared' + fa, wizRing(SCARED_ART[fa]));
            H.defSprite('eyes' + fa, EYES_ART[fa]);
            H.defSprite('eyes2' + fa, wizRing(epx(EYES_ART[fa])));
            H.defSprite('reyes' + fa, wizRing(EYES_ART[fa]));
        }
        for (k in ITEM_ART) { if (ITEM_ART.hasOwnProperty(k)) { H.defSprite(k, ITEM_ART[k]); H.defSprite(k + '2', epx(ITEM_ART[k])); } }
        H.defSprite('ball', BALL_ART);
        H.defSprite('ball2', epx(BALL_ART));
        H.defSprite('fire', FIRE_ART);
        H.defSprite('fire2', epx(FIRE_ART));
        H.defSprite('tree', wizRing(epx(TREE_ART)));
        H.defSprite('cloak', wizRing(epx(CLOAK_ART)));
        H.defSprite('bang', scale2(BANG_ART));
        H.defSprite('cooler', wizRing(epx(COOLER_ART)));
        FIELDS.forEach(function (f, n) {
            var g = parseField(f.rows);
            H.defSprite('maze' + n, mazeRows(g, false, n));
            H.defSprite('mazes' + n, mazeRows(g, true, n));
        });
    }

    function start(H) {
        var G = {
            mode: 'BUSY', level: 0, score: 0, lives: 3, extra: false, best: H.load('best'),
            grid: null, out: null, back: null, left: 0, got: 0, total: 0,
            p: { c: 0, r: 0, pc: 0, pr: 0, dir: 'LEFT', face: 'LEFT', want: 'LEFT', acc: 0, open: false, ph: 0 },
            foes: [], planI: 0, planMs: 0, chase: 0, fireMs: 0, tagN: 0,
            item: null, itemsShown: 0, msgMs: 0, ms: 0, blink: 0, bright: true,
            solid: false, sfxQ: [], sfxWait: 0, wasMode: '', scene: null,
            readyMs: 1500, layonMs: 0, wipe: -1, tt: 0, stopMs: 0, pop: null, sirenMs: 0, sirenAlt: false, smelled: false, deathN: 0
        };
        defAll(H);

        function T(c, r, s, inv) { H.text(c, r, s, inv); }
        function say(s) {
            H.fill(0, MSG_ROW, 40, 1, ' ', false);
            T(Math.max(0, Math.floor((40 - s.length) / 2)), MSG_ROW, s, false);
        }
        function flash(s, ms) { say(s); G.msgMs = ms || 2200; }

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

        /* ----- screens ----- */
        function lines(rows, hold, art) {
            var i;
            H.cls();
            if (art) { H.then(art); }
            for (i = 0; i < rows.length; i++) { H.line(rows[i], { center: true, hold: rows[i] ? hold || 10 : 0 }); }
        }
        function showTitle() {
            G.mode = 'BUSY';
            var b = G.best;
            lines([
                '', '',
                'P A C - M O R K',			// stays in the log for screen readers; the opaque logo covers its cells
                '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
                'YOU THREW THEM. YOU FETCH THEM.',
                '',
                'SPACE LAY ON   H HOW TO PLAY   Q LEAVE',
                b ? 'BEST ' + pad(b.score, 5) + (b.won ? '  ALL SPELLBALLS HOME' : '  REACHED LEVEL ' + b.level) : 'BEST 00000  (NONE YET)'
            ], 10, function () { G.tt = 0; titleArt(0); });
            H.then(function () { G.mode = 'TITLE'; });
        }
        // The attract strip: the wizard crosses the field eating spellballs with all four behind, then the fireball turns them.
        var TITLE_LOOP = 15000, TY = 132;
        function titleArt(t) {
            var list = [{ s: 'img_title', x: 0, y: 0 }], i, w, f = Math.floor(t / 110) % 2, m = [2, 1, 0, 1][Math.floor(t / 70) % 4], back = t > 7600;
            w = back ? 300 - (t - 7600) * 0.085 : -20 + t * 0.042;
            if (H.reduced) { w = 128; back = false; m = 2; f = 0; }
            for (i = 0; i < 12; i++) {
                if (back || H.reduced ? i * 24 + 6 > w + 10 : i * 24 + 6 > w + 12) { list.push({ s: 'ball', x: i * 24 + 6, y: TY + 12 }); }
            }
            for (i = 0; i < 4; i++) {
                if (back) { list.push({ s: 'scared2' + f, x: Math.round(w - 34 - i * 18), y: TY + 2 }); } else { list.push({ s: FOES[i].key + '2r' + f, x: Math.round(w - 34 - i * 18), y: TY + 2 }); }
            }
            list.push({ s: 'pac2_' + (back ? 'LEFT' : 'RIGHT') + '_' + m, x: Math.round(w) - 1, y: TY - 3 });
            H.sprites(list);
        }
        function showHow() {
            G.mode = 'BUSY';
            H.cls();
            H.then(function () {
                var y = 11 * 8 + 2;
                H.sprites([
                    { s: 'img_how', x: 84, y: 0 },
                    { s: 'ball2', x: 12, y: 6 * 8 + 1 },
                    { s: 'fire2', x: 8, y: 8 * 8 },
                    { s: 'pac2_RIGHT_2', x: 5, y: y }, { s: 'scared20', x: 27, y: y + 3 }, { s: 'eyes20', x: 49, y: y + 3 },
                    { s: 'dues2', x: 7, y: 15 * 8 - 2 }, { s: 'turkey2', x: 24, y: 15 * 8 - 2 }, { s: 'favor2', x: 41, y: 15 * 8 - 2 }
                ]);
            });
            HOW.forEach(function (t) { H.line(t, { hold: 10 }); });
            H.then(function () { G.mode = 'HOW'; });
        }
        function showHow2() {
            G.mode = 'BUSY';
            H.cls();
            H.then(function () {
                var l = [{ s: 'img_who', x: 77, y: 0 }];
                l.push({ s: 'stage', x: 0, y: 17 * 8 - 4 });
                FOES.forEach(function (f, i) {
                    l.push({ s: f.key + '2r' + 0, x: 14, y: (2 + i * 4) * 8 + 1 });
                    l.push({ s: f.key + '2r' + (i % 2), x: 96 - i * 22, y: 17 * 8 + 14 });
                });
                l.push({ s: 'pac2_RIGHT_1', x: 128, y: 17 * 8 + 9 });
                for (var b = 0; b < 5; b++) { l.push({ s: 'ball', x: 158 + b * 18, y: 17 * 8 + 26 }); }
                H.sprites(l);
            });
            HOW2.forEach(function (t) { H.line(t, { hold: 10 }); });
            H.then(function () { G.mode = 'HOW2'; });
        }
        function showCard() {
            var f = FIELDS[G.level];
            G.mode = 'BUSY';
            lines(['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'LEVEL ' + (G.level + 1) + ' OF 3: ' + f.name, '', f.flavor], 10, function () { H.sprites([{ s: 'img_' + f.art, x: 0, y: 0 }]); });
            H.then(function () { G.mode = 'CARD'; G.ms = 0; });
        }

        /* ----- level set-up ----- */
        function initField() {
            var L = LEVELS[G.level], r, c;
            G.grid = parseField(FIELDS[G.level].rows);
            G.out = distField(G.grid, OUT[0], OUT[1]);
            G.back = distField(G.grid, HOME[0], HOME[1]);
            G.total = 0;
            for (r = 0; r < MAZE_H; r++) {
                for (c = 0; c < MAZE_W; c++) { if (G.grid[r][c] === '.' || G.grid[r][c] === '*') { G.total++; } }
            }
            G.left = G.total;
            G.got = 0;
            G.itemsShown = 0;
            G.item = null;
            G.solid = false;
            G.foeMs = L.foe;
            G.smelled = false;
            G.pop = null;
        }
        function placeActors() {
            var L = LEVELS[G.level];
            G.p.c = START[0]; G.p.r = START[1]; G.p.pc = START[0]; G.p.pr = START[1]; G.p.dir = 'LEFT'; G.p.face = 'LEFT'; G.p.want = 'LEFT'; G.p.acc = 0; G.p.open = false; G.p.ph = 0;
            G.foes = FOES.map(function (f, i) {
                var inside = i > 0;
                return {
                    def: f, i: i, c: inside ? 10 + i * 2 : OUT[0], r: inside ? HOME[1] + 1 : OUT[1], pc: inside ? 10 + i * 2 : OUT[0], pr: inside ? HOME[1] + 1 : OUT[1],
                    dir: 'LEFT', face: 'l', par: 0, state: inside ? 'home' : 'run', wait: L.doors[i], acc: 0, scared: false
                };
            });
            G.planI = 0; G.planMs = L.plan[0][0]; G.chase = L.plan[0][1];
            G.fireMs = 0; G.tagN = 0;
            G.stopMs = 0; G.pop = null; G.sirenMs = 0;
        }
        function drawSide() {
            var L = LEVELS[G.level];
            T(SIDE, 0, 'PAC-MORK', true);	// log/accessible name; covered by the img_side plate
            T(SIDE, 2, 'SCORE', false);
            T(SIDE, 5, 'BEST', false);
            T(SIDE, 8, 'LEVEL ' + (G.level + 1) + '/3', false);
            T(SIDE, 10, 'LIVES', false);
            FOES.forEach(function (f, i) { T(SIDE + 2, 13 + i, f.name, false); });
            T(SIDE + 2, 18, 'SPELL', false);
            T(SIDE + 2, 19, 'FIREBALL', false);
            T(SIDE + 2, 20, L.item.tag, false);
            T(0, HELP_ROW, 'ARROWS/WASD  SPACE HOLD  Q LEAVE', false);
            drawScore();
        }
        function drawScore() {
            T(SIDE, 3, pad(G.score, 5), false);
            T(SIDE, 6, pad(Math.max(G.score, G.best ? G.best.score : 0), 5), false);
        }
        // Where a mover is drawn: eased from its last cell to its cell, arriving a little before the next step.
        function at(c, r, pc, pr, acc, ms) {
            var f = G.mode === 'PLAY' && ms ? Math.min(1, acc / (ms * 0.75)) : 1;
            if (Math.abs(c - pc) > 1) { f = 1; }
            return { x: Math.round((pc + (c - pc) * f) * 7), y: Math.round((pr + (r - pr) * f) * 8) };
        }
        function foeArt(f) {
            var n = f.def.key + f.face + f.par;
            if (f.state === 'eyes') { return 'eyes' + f.par; }
            if (f.scared) { return G.fireMs < 1800 && G.bright && !H.reduced ? n : 'scared' + f.par; }
            return n;
        }
        // The whole sprite layer: maze, spellballs, bonus, opponents, you, and the sidebar.
        function drawActors() {
            var list = [], p = G.p, r, c, t, i, f, q, spr;
            list.push({ s: (G.solid ? 'mazes' : 'maze') + G.level, x: 0, y: 0 });
            for (r = 0; r < MAZE_H; r++) {
                for (c = 0; c < MAZE_W; c++) {
                    t = G.grid[r][c];
                    if (t === '.') { list.push({ s: 'ball', x: c * 7 + 2, y: r * 8 + 2 }); } else if (t === '*' && G.bright) { list.push({ s: 'fire', x: c * 7, y: r * 8 }); }
                }
            }
            if (G.item) { list.push({ s: LEVELS[G.level].item.spr, x: BONUS[0] * 7, y: BONUS[1] * 8 }); }
            for (i = G.foes.length - 1; i >= 0; i--) {
                f = G.foes[i];
                q = at(f.c, f.r, f.pc, f.pr, f.state === 'home' ? 9999 : f.acc, foeInterval(f));
                list.push({ s: 'r' + foeArt(f), x: q.x - 1, y: q.y - 1 });
            }
            q = at(p.c, p.r, p.pc, p.pr, p.acc, PLAYER_MS);
            if (G.mode === 'DYING') {
                i = Math.floor(G.ms / 250);
                list.push({ s: 'pac_die_' + Math.min(i, 7), x: q.x + WIZ_DX, y: q.y + WIZ_DY });
            } else {
                i = G.mode === 'PLAY' ? [2, 1, 0, 1][p.ph % 4] : 2;
                spr = 'pac_' + p.face + '_' + i;
                list.push({ s: spr, x: q.x + WIZ_DX, y: q.y + WIZ_DY });
            }
            for (i = 0; i < G.lives; i++) { list.push({ s: 'pac_LEFT_2', x: SIDE * 7 + i * 11, y: 11 * 8 + 1 }); }
            FOES.forEach(function (fo, n) { list.push({ s: fo.key + 'r0', x: SIDE * 7, y: (13 + n) * 8 }); });
            list.push({ s: 'ball', x: SIDE * 7 + 2, y: 18 * 8 + 2 }, { s: 'fire', x: SIDE * 7, y: 19 * 8 }, { s: LEVELS[G.level].item.spr, x: SIDE * 7, y: 20 * 8 });
            if (G.pop) { list.push({ s: G.pop.s, x: G.pop.x, y: G.pop.y }); }
            list.push({ s: 'img_side', x: SIDE * 7, y: 0 });
            if (G.mode === 'READY') { list.push({ s: 'img_ready', x: 80, y: BONUS[1] * 8 }); }
            if (G.layonMs > 0) { list.push({ s: 'img_layon', x: 77, y: BONUS[1] * 8 }); }
            if (G.wipe >= 0) { for (r = 0; r < 24; r++) { list.push({ s: 'shut' + G.wipe, x: 0, y: r * 8 }); } }
            H.sprites(list);
        }
        function beginLevel() {
            initField();
            placeActors();
            G.mode = 'BUSY';
            H.cls();
            H.then(function () {
                G.mode = 'READY';
                drawSide();
                drawActors();
                say('READY');
                G.ms = 0;
                G.readyMs = 1500;
                sfx([[392, 120], [523, 120], [659, 120], [784, 240], [659, 120], [784, 360]]);
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
                flash('A HEALER WAS WATCHING. EXTRA LIFE.');
                sfx([[784, 60], [1047, 60], [1319, 90]]);
            }
            drawScore();
        }

        /* ----- the player ----- */
        function eat() {
            var p = G.p, t = G.grid[p.r][p.c];
            if (t === '.' || t === '*') {
                G.grid[p.r][p.c] = ' ';
                G.left--;
                G.got++;
                if (G.left === 40 && !G.smelled) { G.smelled = true; flash('THE RUSHER SMELLS VICTORY.', 1800); }
                award(t === '.' ? 10 : 50);
                if (t === '*') { lightFire(); } else if (G.sfxWait <= 0) { H.beep(G.got % 2 ? 660 : 520, 20); G.sfxWait = 30; }
                if (G.itemsShown < ITEM_AT.length && G.got >= ITEM_AT[G.itemsShown]) {
                    G.itemsShown++;
                    G.item = { ms: ITEM_MS };
                }
            }
            if (G.item && p.c === BONUS[0] && p.r === BONUS[1]) {
                var L = LEVELS[G.level];
                G.item = null;
                award(L.item.value);
                flash(L.item.name + ' RECOVERED: ' + L.item.value);
                sfx([[880, 50], [1175, 70]]);
            }
        }
        function lightFire() {
            G.fireMs = LEVELS[G.level].fire;
            G.tagN = 0;
            G.foes.forEach(function (f) {
                if (f.state !== 'eyes') {
                    if (f.state === 'run') { f.dir = BACK[f.dir]; }
                    f.scared = true;
                }
            });
            flash('FIREBALL! THEY HAVE REMEMBERED ERRANDS.', 1800);
            sfx([[330, 50], [440, 50], [554, 80]]);
        }
        function stepPlayer() {
            var p = G.p, n = step(p.c, p.r, p.want);
            if (open(G.grid, n[0], n[1], false)) { p.dir = p.want; }
            if (p.dir === 'LEFT' || p.dir === 'RIGHT') { p.face = p.dir; }
            n = step(p.c, p.r, p.dir);
            if (!open(G.grid, n[0], n[1], false)) { p.pc = p.c; p.pr = p.r; return; }	// settled: nothing to glide from
            p.pc = p.c; p.pr = p.r; p.c = n[0]; p.r = n[1]; p.open = !p.open; p.ph++;
            eat();
        }

        /* ----- the opponents ----- */
        function targetOf(f) {
            var p = G.p, d = DIRS[p.dir], rush;
            if (!G.chase) { return f.def.home; }
            if (f.i === 0) { return [p.c, p.r]; }
            if (f.i === 1) { return [p.c + d[0] * 4, p.r + d[1] * 4]; }
            if (f.i === 2) {
                rush = G.foes[0];
                return [2 * (p.c + d[0] * 2) - rush.c, 2 * (p.r + d[1] * 2) - rush.r];
            }
            return sq(f.c, f.r, p.c, p.r) > 64 ? [p.c, p.r] : f.def.home;
        }
        function choose(f) {
            var opts = [], i, n, best, bd, d, tg;
            for (i = 0; i < 4; i++) {
                if (ORDER[i] !== BACK[f.dir]) {
                    n = step(f.c, f.r, ORDER[i]);
                    if (open(G.grid, n[0], n[1], false)) { opts.push({ dir: ORDER[i], c: n[0], r: n[1] }); }
                }
            }
            if (!opts.length) { return BACK[f.dir]; }
            if (f.scared) { return opts[H.rand(opts.length)].dir; }
            tg = targetOf(f);
            bd = Infinity;
            for (i = 0; i < opts.length; i++) {
                d = sq(opts[i].c, opts[i].r, tg[0], tg[1]);
                if (d < bd) { bd = d; best = opts[i].dir; }
            }
            return best;
        }
        function follow(f, dist) {
            var i, n, here = dist[f.r][f.c];
            for (i = 0; i < 4; i++) {
                n = step(f.c, f.r, ORDER[i]);
                if (open(G.grid, n[0], n[1], true) && dist[n[1]][n[0]] === here - 1) {
                    f.c = n[0]; f.r = n[1]; f.dir = ORDER[i];
                    return;
                }
            }
        }
        function foeInterval(f) {
            if (f.state === 'eyes') { return EYES_MS; }
            if (f.scared) { return SCARED_MS; }
            if (f.state === 'leave') { return LEAVE_MS; }
            // With few spellballs left the Rusher smells victory: 15% faster under 40, 25% faster under 15, never as fast as you.
            if (f.i === 0 && G.left <= 15) { return Math.max(PLAYER_MS + 5, Math.round(G.foeMs * 0.75)); }
            if (f.i === 0 && G.left <= 40) { return Math.max(PLAYER_MS + 5, Math.round(G.foeMs * 0.85)); }
            return G.foeMs;
        }
        function stepFoe(f) {
            var n;
            f.pc = f.c; f.pr = f.r; f.par = 1 - f.par;
            if (f.state === 'leave') {
                follow(f, G.out);
                if (f.c === OUT[0] && f.r === OUT[1]) { f.state = 'run'; f.dir = 'LEFT'; }
            } else if (f.state === 'eyes') {
                follow(f, G.back);
                if (f.c === HOME[0] && f.r === HOME[1]) { f.state = 'home'; f.wait = COUNT_MS; f.scared = false; }
            } else {
                f.dir = choose(f);
                n = step(f.c, f.r, f.dir);
                f.c = n[0]; f.r = n[1];
            }
            if (f.dir === 'LEFT') { f.face = 'l'; } else if (f.dir === 'RIGHT') { f.face = 'r'; }
        }
        function collide() {
            var i, f;
            for (i = 0; i < G.foes.length; i++) {
                f = G.foes[i];
                if (f.c === G.p.c && f.r === G.p.r && f.state !== 'eyes' && f.state !== 'home') {
                    if (f.scared) { tag(f); } else { hit(f); return true; }
                }
            }
            return false;
        }
        function tag(f) {
            var pts = TAGS[Math.min(G.tagN, TAGS.length - 1)];
            G.tagN++;
            f.scared = false;
            f.state = 'eyes';
            var pw = 4 * String(pts).length + 1;
            G.stopMs = 300;
            G.pop = { s: 'pts' + Math.min(G.tagN - 1, TAGS.length - 1), x: Math.max(0, Math.min(f.c * 7 + 3 - Math.floor(pw / 2), MAZE_W * 7 - pw)), y: f.r * 8, ms: 700 };
            award(pts);
            flash(f.def.name + ' TO NIRVANA: ' + pts + '. COUNT TO 300.');
            sfx([[1047, 40], [1319, 40], [1568, 70]]);
        }
        function hit(f) {
            G.lives--;
            G.mode = 'DYING';
            G.ms = 0;
            G.fireMs = 0;
            say(f.def.hits[G.deathN++ % 2]);
            G.pop = null;
            drawActors();
            G.sfxQ = []; G.sfxWait = 0;
            sfx([[494, 120], [440, 120], [392, 120], [349, 120], [330, 120], [262, 120], [220, 240]]);
        }
        function clearField() {
            G.mode = 'CLEAR';
            G.ms = 0;
            G.item = null;
            G.pop = null;
            saveBest(false);
            say('FIELD CLEAR. POUCH FULL. KIND OF.');
            sfx([[523, 70], [659, 70], [784, 70], [1047, 130]]);
        }

        /* ----- per-tick updates ----- */
        function updateTimers() {
            var L = LEVELS[G.level];
            if (G.fireMs > 0) {
                G.fireMs -= TICK;
                if (G.fireMs <= 0) { G.foes.forEach(function (f) { f.scared = false; }); }
            } else if (G.planMs > 0) {
                G.planMs -= TICK;
                if (G.planMs <= 0 && G.planI < L.plan.length - 1) {
                    G.planI++;
                    G.planMs = L.plan[G.planI][0];
                    G.chase = L.plan[G.planI][1];
                    G.foes.forEach(function (f) { if (f.state === 'run') { f.dir = BACK[f.dir]; } });
                } else if (G.planMs <= 0) {
                    G.chase = 1;	// the last break is over: from here on they mean it
                }
            }
            if (G.item) {
                G.item.ms -= TICK;
                if (G.item.ms <= 0) { G.item = null; }
            }
        }
        // Rising pressure: a low pulse that quickens as the field empties; a warble under fireball. Direct beeps, so the munch is never blocked.
        function siren() {
            var n = G.left / G.total, per = 0, hz = 0;
            if (G.sfxQ.length) { return; }
            G.sirenMs -= TICK;
            if (G.sirenMs > 0) { return; }
            if (G.fireMs > 0) { per = 240; G.sirenAlt = !G.sirenAlt; hz = G.sirenAlt ? 196 : 165; } else if (G.left <= 15) { per = 220; hz = 196; } else if (n < 0.25) { per = 350; hz = 147; } else if (n < 0.5) { per = 600; hz = 110; }
            if (!per) { G.sirenMs = 0; return; }
            G.sirenMs = per;
            H.beep(hz, 30);
        }
        function updatePlay() {
            var i, f, iv;
            if (G.layonMs > 0) { G.layonMs -= TICK; }
            if (G.pop) { G.pop.ms -= TICK; if (G.pop.ms <= 0) { G.pop = null; } }
            if (G.stopMs > 0) { G.stopMs -= TICK; drawActors(); return; }
            updateTimers();
            siren();
            G.p.acc += TICK;
            while (G.p.acc >= PLAYER_MS) {
                G.p.acc -= PLAYER_MS;
                stepPlayer();
                if (collide()) { return; }
                if (G.left === 0) { drawActors(); clearField(); return; }
            }
            for (i = 0; i < G.foes.length; i++) {
                f = G.foes[i];
                if (f.state === 'home') {
                    f.wait -= TICK;
                    if (f.wait <= 0) { f.state = 'leave'; f.acc = 0; }
                } else {
                    f.acc += TICK;
                    iv = foeInterval(f);
                    if (f.acc >= iv) {
                        f.acc -= iv;
                        stepFoe(f);
                        if (collide()) { return; }
                    }
                }
            }
            if (G.msgMs > 0) {
                G.msgMs -= TICK;
                if (G.msgMs <= 0) { say(''); }
            }
            drawActors();
        }
        function blinkPower() {
            G.blink += TICK;
            if (G.blink < 280) { return; }
            G.blink = 0;
            if (H.reduced) { return; }
            G.bright = !G.bright;
            drawActors();
        }
        function flashWalls() {
            G.solid = !H.reduced && Math.floor(G.ms / 280) % 2 === 1;
            drawActors();
        }
        function advanceField() {
            if (G.level >= LEVELS.length - 1) { showWin(); return; }
            G.level++;
            showScene(G.level - 1);
        }
        function tick() {
            if (G.sfxWait > 0) {
                G.sfxWait -= TICK;
                if (G.sfxWait <= 0) { pumpSfx(); }
            }
            switch (G.mode) {
                case 'CARD':
                    G.ms += TICK;
                    if (G.ms >= 2200) { beginLevel(); }
                    break;
                case 'READY':
                    G.ms += TICK;
                    blinkPower();
                    if (G.ms >= G.readyMs) { G.mode = 'PLAY'; G.layonMs = 700; flash('LAY ON!', 900); }
                    break;
                case 'PLAY':
                    blinkPower();
                    updatePlay();
                    break;
                case 'DYING':
                    G.ms += TICK;
                    if (G.ms >= 1750) { afterHit(); } else { drawActors(); }
                    break;
                case 'CLEAR':
                    G.ms += TICK;
                    if (G.ms < 1500) { flashWalls(); } else if (H.reduced || G.ms >= 1820) { G.wipe = -1; advanceField(); } else { G.wipe = Math.min(7, Math.floor((G.ms - 1500) / 40)); drawActors(); }
                    break;
                case 'TITLE':
                    if (!H.reduced) { G.tt = (G.tt + TICK) % TITLE_LOOP; titleArt(G.tt); }
                    break;
                case 'SCENE':
                    updateScene();
                    break;
                default:
                    break;
            }
        }
        function afterHit() {
            if (G.lives <= 0) { showOver(); return; }
            placeActors();
            G.mode = 'READY';
            drawActors();
            say('READY');
            G.ms = 0;
            G.readyMs = 900;
            sfx([[330, 60], [392, 90]]);
        }

        /* ----- between levels: the reeve calls a break ----- */
        var SCENES = [
            { cast: 'RFBN', text: ['WATER BREAK.', 'NOBODY HAS TOLD THE RUSHER.'] },
            { cast: 'R', snag: true, text: ['THE RUSHER HAS A CLOAK.', 'THE TREE LINE HAS THE RUSHER\'S CLOAK.'] }
        ];
        function showScene(i) {
            var s = SCENES[i];
            G.mode = 'BUSY';
            lines(['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''].concat(s.text, ['', '', 'SPACE TO TAKE THE FIELD']), 10, function () {
                G.scene = { s: s, t: 0, done: false };
                G.mode = 'SCENE';
                drawScene(H.reduced ? (s.snag ? 5000 : 3000) : 0);
                if (H.reduced) { G.scene.done = true; }
            });
        }
        // One frame of a break scene, t ms in. Positions are pixels; the walk is the old one cell per 110 ms.
        function drawScene(t) {
            var sc = G.scene, cast = sc.s.cast, y = 100, P = t / 110 * 7, list = [{ s: 'img_' + FIELDS[G.level].art, x: 0, y: 0 }], i, x;
            if (!sc.s.snag) { list.push({ s: 'cooler', x: 232, y: y - 8 }); }
            if (sc.s.snag) {
                x = Math.min(P - 49, 168);
                list.push({ s: 'tree', x: 186, y: y - 8 }, { s: 'bang', x: 193, y: y - 32 });
                if (x >= 168) { list.push({ s: 'cloak', x: 176, y: y + 2 }); }
                list.push({ s: 'R2r0', x: Math.round(x), y: y });
                list.push({ s: 'pac2_RIGHT_' + [2, 1, 0, 1][Math.floor(t / 70) % 4], x: Math.round(P) - 1, y: y - 5 });
                if (t > 56 * 110) { sc.done = true; }
            } else if (t < 56 * 110) {
                for (i = 0; i < cast.length; i++) { list.push({ s: FOES[i].key + '2r' + (Math.floor(t / 110) % 2), x: Math.round(P - 42 - i * 20), y: y }); }
                list.push({ s: 'pac2_RIGHT_' + [2, 1, 0, 1][Math.floor(t / 70) % 4], x: Math.round(P) - 1, y: y - 5 });
            } else {
                x = (110 - t / 110) * 7;
                for (i = 0; i < cast.length; i++) { list.push({ s: 'scared2' + (Math.floor(t / 110) % 2), x: Math.round(x - i * 20), y: y }); }
                list.push({ s: 'pac2_LEFT_' + [2, 1, 0, 1][Math.floor(t / 70) % 4], x: Math.round(x + 56) - 1, y: y - 5 });
                if (t > 120 * 110) { sc.done = true; }
            }
            H.sprites(list);
        }
        function updateScene() {
            var sc = G.scene;
            if (!sc || sc.done) { return; }
            sc.t += TICK;
            drawScene(sc.t);
        }
        function endScene() { G.scene = null; showCard(); }

        /* ----- endings ----- */
        function showOver() {
            saveBest(false);
            G.mode = 'BUSY';
            lines([
                '', '', '', '', '', '', '', '', '', '', '', '',
                'OUT OF LIVES',
                '',
                'YOU ARE DEAD FOR THE REST OF THE GAME.',
                'YOUR SPELLBALLS ARE STILL OUT THERE.',
                '',
                'SOMEONE WILL STEP ON THEM.',
                '',
                'FINAL SCORE ' + pad(G.score, 5),
                '', '',
                'SPACE TO TRY AGAIN'
            ], 10, function () { H.sprites([{ s: 'img_lose', x: 0, y: 0 }]); });
            H.then(function () { G.mode = 'OVER'; });
        }
        function showWin() {
            award(1000);
            saveBest(true);
            G.mode = 'BUSY';
            lines(WIN_TEXT.concat(['FINAL SCORE ' + pad(G.score, 5), 'SPACE TO PLAY AGAIN']), H.reduced ? 10 : 220, function () {
                H.sprites([{ s: 'img_win', x: 0, y: 0 }, { s: 'Rl0', x: 30, y: 54 }, { s: 'Fl0', x: 44, y: 54 }, { s: 'Bl0', x: 58, y: 54 }, { s: 'Nl0', x: 72, y: 54 }]);
            });
            H.then(function () { G.mode = 'OVER'; });
        }
        function newGame() {
            G.level = 0; G.score = 0; G.lives = 3; G.extra = false; G.deathN = 0;
            G.sfxQ = []; G.sfxWait = 0;
            showCard();
        }

        /* ----- input ----- */
        function askQuit() {
            G.wasMode = G.mode;
            G.mode = 'ASK';
            say('LEAVE THE FIELD?  Y OR N');
        }
        function onKey(key) {
            var go = key === 'SPACE' || key === 'ENTER';
            switch (G.mode) {
                case 'TITLE':
                    if (go) { newGame(); } else if (key === 'H') { showHow(); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'HOW':
                    if (go) { showHow2(); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'HOW2':
                    if (go) { showTitle(); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'CARD':
                    if (go) { beginLevel(); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'SCENE':
                    if (go) { endScene(); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'OVER':
                    if (go) { showTitle(); } else if (key === 'Q') { H.quit(); }
                    break;
                case 'PLAY':
                case 'READY':
                    if (KEYMAP[key]) { G.p.want = KEYMAP[key]; } else if (key === 'Q') { askQuit(); } else if (key === 'SPACE' && G.mode === 'PLAY') {
                        G.mode = 'HOLD';
                        say('HOLD!  SPACE TO LAY ON');
                    }
                    break;
                case 'HOLD':
                    if (KEYMAP[key]) { G.p.want = KEYMAP[key]; } else if (key === 'Q') { askQuit(); } else if (go) { G.mode = 'PLAY'; say(''); }
                    break;
                case 'ASK':
                    // A sits right beside Q on a keyboard, so a stray press must not end the run.
                    if (key === 'Y') { H.quit(); } else if (key === 'N' || go) { G.mode = G.wasMode; say(''); }
                    break;
                default:
                    break;
            }
        }

        start.dbg = G;
        H.every(TICK, tick);
        H.keys(onKey);
        showTitle();
    }

    window.OrkpleGames = window.OrkpleGames || {};
    window.OrkpleGames.pacmork = {
        title: 'PAC-MORK',
        start: start,
        _pure: { FIELDS: FIELDS, parseField: parseField, distField: distField, open: open, step: step, ORDER: ORDER, DOOR: DOOR, OUT: OUT, HOME: HOME, START: START, BONUS: BONUS, MAZE_W: MAZE_W, MAZE_H: MAZE_H, wizardRows: wizardRows, ptsRows: ptsRows },
        _dbg: function () { return start.dbg; }
    };
}());
