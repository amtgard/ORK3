/**
 * ORKEGON TRAIL: an Amtgard road-trip parody for ORKPLE II.
 * Four friends, 720 miles, one wet road and one boffer fight in a rest area.
 * Rules live in pure functions (see _test at the bottom); start() only draws and listens.
 * Touches nothing but window.OrkpleGames. All text is uppercase, with one lowercase z.
 */
(function () {
    'use strict';
    window.OrkpleGames = window.OrkpleGames || {};

    /* ---------------- data ---------------- */
    var ROLES = [
        { label: 'OFFICE JOB', cash: 250, mult: 1 },
        { label: 'GARB COMMISSIONS', cash: 200, mult: 2 },
        { label: 'COLLEGE STUDENT', cash: 175, mult: 3 }
    ];
    var ITEMS = [
        { k: 'gas', name: 'GAS (GAL)', home: 3, fort: 5, list: 40 },
        { k: 'snacks', name: 'SNACKS (BAGS)', home: 2, fort: 3, list: 16 },
        { k: 'ice', name: 'ICE (BAGS)', home: 2, fort: 3, list: 5 },
        { k: 'tape', name: 'DUCT TAPE', home: 5, fort: 8, list: 3 },
        { k: 'boffers', name: 'BOFFERS', home: 10, fort: 15, list: 2 },
        { k: 'garb', name: 'GARB (SETS)', home: 8, fort: 12, list: 4 },
        { k: 'tarps', name: 'TARP', home: 12, fort: 18, list: 1 }
    ];
    var FREE = { tape: 1, boffers: 1 };
    var PACE = [
        { n: 'SENSIBLE', mi: 90, gas: 4, h: 0, flat: 10, belt: 5, cop: 0 },
        { n: 'BRISK', mi: 120, gas: 6, h: -5, flat: 20, belt: 10, cop: 12 },
        { n: 'LEAD FOOT', mi: 150, gas: 9, h: -10, flat: 40, belt: 20, cop: 36 }
    ];
    var RAT = [
        { n: 'ONE CHIP EACH', b: 0.25, h: -3 },
        { n: 'A HANDFUL', b: 0.5, h: 0 },
        { n: 'THE WHOLE BAG', b: 0.75, h: 1 }
    ];
    var LM = [150, 300, 450, 600, 720];
    var LM_NAME = ['DUCT TAPE BALL', 'FORT GAS-MONEY', 'REST AREA 44', 'MAYBE CREEK', 'THE GATE'];
    var OPT1 = [90, 70, 45, 25, 10];
    var OPT2 = [95, 88, 72, 55, 40];
    var DEF_NAMES = ['THE DRIVER', 'GRUNK', 'LADY WAIVER', 'THE NEW GUY'];
    var SEATS = ['DRIVER', 'SHOTGUN', 'BACK LEFT', 'BACK MIDDLE'];
    var SEED_HALL = [
        { n: 'SIR NOT-YET', s: 2400, w: true },
        { n: 'DAME DAMP', s: 1800, w: true },
        { n: 'THE LOANER', s: 1300, w: true },
        { n: 'OLD TENT', s: 900, w: true },
        { n: 'LOST TABARD', s: 500, w: false }
    ];
    var RANKS = [[400, 'PERSON WHO MEANT WELL'], [900, 'CARRIER OF THE SPARE TENT POLE'], [1500, 'KEEPER OF THE EXTRA TENT STAKE'], [2200, 'FRIEND OF THE GATE REEVE'], [1e9, 'LEGEND OF THE HUNDRED TARPS']];
    var HOLD = 15;      // ms held after each printed line on text screens
    var remembered = null, dbg = null;

    /* ---------------- text helpers ---------------- */
    function pad(s, n) { s = String(s); while (s.length < n) { s += ' '; } return s; }
    function lpad(s, n) { s = String(s); while (s.length < n) { s = ' ' + s; } return s; }
    function cen(s, n) {
        s = String(s);
        var l = Math.floor((n - s.length) / 2);
        return pad(lpad('', Math.max(0, l)) + s, n);
    }
    function clean(s, n) {
        return String(s).toUpperCase().replace(/[^\x20-\x5F]/g, '').replace(/^\s+|\s+$/g, '').slice(0, n);
    }
    function wrap(text, n) {
        var out = [];
        String(text).split('\n').forEach(function (par) {
            if (par === '') { out.push(''); return; }
            var words = par.split(' '), cur = '';
            words.forEach(function (w) {
                if (!cur) { cur = w; } else if ((cur + ' ' + w).length <= n) { cur += ' ' + w; } else { out.push(cur); cur = w; }
            });
            if (cur) { out.push(cur); }
        });
        return out;
    }
    function clock(h) {
        var t = 6 + h, day = ['FRI', 'SAT', 'SUN'][Math.min(2, Math.floor(t / 24))], hh = t % 24;
        return day + ' ' + (hh % 12 || 12) + ':00 ' + (hh >= 12 ? 'PM' : 'AM');
    }
    function hLabel(H) { return H >= 70 ? 'GOOD' : H >= 45 ? 'FAIR' : H >= 20 ? 'POOR' : 'VERY POOR'; }
    function sub(txt, vars) { return txt.replace(/\{(\w+)\}/g, function (m, k) { return vars[k] === undefined ? m : vars[k]; }); }

    /* ---------------- pure rules ---------------- */
    function newGame(role, names) {
        var r = ROLES[role], party = [], i;
        for (i = 0; i < 4; i++) { party.push({ name: (names && names[i]) || DEF_NAMES[i], out: false, cause: null }); }
        return {
            role: role, mult: r.mult, cash: r.cash, gas: 0, snacks: 0, ice: 0, tape: 1, boffers: 1, garb: 0, tarps: 0,
            party: party, alive: 4, H: 60, hours: 0, turn: 0, mile: 0, next: 0, pace: 1, rat: 1,
            ditches: 0, fame: 0, lost: null, hungry: false, campRain: false, goneQ: [], genericUsed: {}, weather: null, lastRain: false
        };
    }
    function clampH(S) { if (S.H < 0) { S.H = 0; } if (S.H > 100) { S.H = 100; } }
    function burn(S) { return Math.ceil(S.alive * RAT[S.rat].b); }
    function upkeep(S, hot) {
        var b = burn(S), hungry = false, d, melt;
        if (S.snacks >= b) { S.snacks -= b; } else { S.snacks = 0; hungry = true; }
        melt = (S.turn % 2 === 0 ? 1 : 0) + (hot ? 1 : 0);
        S.ice = Math.max(0, S.ice - melt);
        d = RAT[S.rat].h;
        if (hungry) { d -= 8; }
        if (S.ice === 0) { d -= 4; if (S.snacks > 0) { S.snacks--; } }
        S.hungry = hungry;
        return d;
    }
    function pickIn(S, R) {
        var c = [], i;
        for (i = 0; i < 4; i++) { if (!S.party[i].out) { c.push(i); } }
        return S.party[c[R(c.length)]].name;
    }
    var GENERIC = [
        ['BELT', '{N}: BELT REVOKED.', 'IT WAS A BATHROBE BELT. STILL, A BLOW.'],
        ['HOLD', '{N} CALLS HOLD AND DOES NOT RESUME.', 'THE REEVE SAYS LAY ON. {N} SAYS NO.'],
        ['NIRVANA', '{N} HAS GONE TO NIRVANA.', 'THERE IS SHADE. THERE IS A LINE. {N} CUTS IT.'],
        ['PICKED UP', '{N} GOT PICKED UP BY A PARENT.', 'THE PARENT WAS VERY NICE ABOUT IT.'],
        ['DITCHENTERY', '{N} HAS DIED OF DITCHENTERY.', 'NOBODY KNOWS WHAT THAT IS. THE REEVE WRITES IT DOWN ANYWAY.'],
        ['NAP', '{N} HAS TAKEN A KNEE IN THE BACK SEAT.', 'YOU ASK IF {N} IS DEAD. {N} SAYS YES, DO NOT LOOT.']
    ];
    function causeFor(S, R) {
        if (S.ice === 0) { return ['DEHYDRATION', '{N} HAS DIED OF DEHYDRATION.', 'A REEVE CONFIRMS IT. OFF TO NIRVANA WITH A JUG.']; }
        if (S.hungry) { return ['FEAST WAIT', '{N} HAS DIED OF FEAST WAIT.', 'IT WAS ONLY 9. {N} IS IN NIRVANA, EATING.']; }
        if (S.tarps === 0 && S.campRain) { return ['DAMP', '{N} HAS DIED OF DAMP.', 'THE CAR IS NIRVANA NOW. THE CAR HAS HEAT.']; }
        if (S.garb < S.alive) { return ['TABARD ROT', '{N} HAS CAUGHT TABARD ROT.', 'THE TABARD CAN NOW STAND UNAIDED. {N} TAKES IT HOME.']; }
        if (S.pace === 2) { return ['WHIPLASH', '{N} HAS DIED OF WHIPLASH.', 'IT WAS 90 MPH. {N} WILL TAKE THE BUS BACK, THANK YOU.']; }
        var g = GENERIC[R(GENERIC.length)];
        if ((S.genericUsed[g[0]] || 0) >= 2) { g = GENERIC[R(GENERIC.length)]; }
        S.genericUsed[g[0]] = (S.genericUsed[g[0]] || 0) + 1;
        return g;
    }
    var RIVER_ROW = ['RIVER', '{N} WADES ASHORE AND HITCHES A RIDE WITH THE FARMER.', '{N} IS FINE. {N} NOW HELPS WITH THE TRACTOR.'];
    function goes(S, R, row) {
        var c = [], i, idx, cr;
        for (i = 1; i < 4; i++) { if (!S.party[i].out) { c.push(i); } }
        if (!c.length) { return; }
        idx = c[R(c.length)];
        cr = row || causeFor(S, R);
        S.party[idx].out = true;
        S.party[idx].cause = cr[0];
        S.alive--;
        S.H += 6;
        clampH(S);
        S.goneQ.push({ idx: idx, name: S.party[idx].name, l1: sub(cr[1], { N: S.party[idx].name }), l2: sub(cr[2], { N: S.party[idx].name }) });
        if (S.alive < 2) { S.lost = 'party'; }
    }
    function goneRoll(S, R) {
        if (S.lost) { return; }
        if (S.H <= 0) { goes(S, R); return; }
        if (S.H < 50 && R(1000) < Math.min(600, (50 - S.H) * 18)) { goes(S, R); }
    }
    function gateCheck(S) { if (S.hours >= 26 && S.next < 5 && !S.lost) { S.lost = 'gate'; } }

    // Steps 1-6 of a drive turn. Landmarks are returned in info.lms for the caller to run in order.
    function driveTurn(S, R) {
        var info = { lms: [], gasOut: null }, w, hot, rain, p = PACE[S.pace], mi, d, frac, buy;
        S.turn++; S.hours += 2;
        w = R(100);
        hot = w >= 55 && w < 75; rain = w >= 75;
        info.weather = hot ? 'HOT' : rain ? 'RAIN' : 'CLEAR';
        info.hot = hot; info.rain = rain;
        mi = p.mi - (rain ? 15 : 0);
        d = p.h;
        if (S.gas >= p.gas) { S.gas -= p.gas; } else {
            frac = S.gas / p.gas; S.gas = 0;
            buy = Math.min(Math.floor(S.cash / 5), 5);
            S.cash -= 5 * buy; S.gas += buy;
            mi = Math.max(40, Math.floor(mi * frac) + buy * 20);
            if (buy === 0) { d -= 8; }
            info.gasOut = { buy: buy };
        }
        d += upkeep(S, hot);
        if (hot) { d -= 4; }
        S.H += d; clampH(S);
        S.mile += mi;
        S.weather = info.weather; S.lastRain = rain;
        while (S.next < 5 && S.mile >= LM[S.next]) { info.lms.push(S.next); S.next++; }
        return info;
    }
    function restTurn(S, R) {
        S.turn++; S.hours += 2;
        S.H += upkeep(S, false) + 18; clampH(S);
        goneRoll(S, R);
        gateCheck(S);
    }

    // What the travel levers cost, in numbers only (rows 14-16 of the travel screen).
    function choiceRows(S, t) {
        var p = PACE[S.pace], left = 16 - S.hours, b = burn(S);
        t[14] = 'PACE     ' + pad(p.n, 10) + p.mi + ' MI, ' + p.gas + ' GAL, ' + ['EASY', 'TIRING', 'BRUTAL'][S.pace];
        t[15] = 'RATIONS  ' + pad(RAT[S.rat].n, 14) + b + (b === 1 ? ' BAG' : ' BAGS') + (S.rat === 0 ? ', HUNGRY' : '');
        t[16] = left <= 0 ? 'SIGN-IN HAS CLOSED' : 'SIGN-IN CLOSES IN ' + left + (left === 1 ? ' HOUR' : ' HOURS');
        return t;
    }
    function satHead(H) { return pad('SATURDAY', 23) + 'HEALTH  ' + hLabel(H); }
    
    /* events */
    var EVENTS = [
        { id: 'FLAT', w: function (S) { return PACE[S.pace].flat; }, f: function (S, R, N) {
            if (S.tape > 0) { S.tape--; return { t: 'A FLAT TIRE. ' + N + ' APPLIES ONE ROLL OF DUCT TAPE WITH GREAT CONFIDENCE.\nIT HOLDS. THIS IS NOT ADVICE.', foot: 'TAPE LEFT: ' + S.tape }; }
            S.hours += 2; S.H -= 3;
            return { t: 'A FLAT TIRE AND NO TAPE. TWO HOURS LOST. ' + N + ' EXPLAINS WHAT TAPE WOULD HAVE DONE.' };
        } },
        { id: 'BELT', w: function (S) { return PACE[S.pace].belt; }, f: function (S, R, N) {
            if (S.tape > 0) { S.tape--; return { t: N + ' REPLACES THE FAN BELT WITH TAPE AND A BELT FROM SOMEONE\'S GARB. NOBODY ASKS WHOSE.', foot: 'TAPE LEFT: ' + S.tape }; }
            if (S.cash >= 25) { S.cash -= 25; return { t: 'THE FAN BELT LETS GO. A MECHANIC CHARGES $25 AND ASKS ABOUT THE TABARDS.', foot: 'CASH $' + S.cash }; }
            S.hours += 2; S.H -= 4;
            return { t: 'THE FAN BELT LETS GO. NO TAPE, NO MONEY. TWO HOURS ON THE SHOULDER, IN VIEW OF EVERY TRUCKER ALIVE.' };
        } },
        { id: 'LIGHT', w: function () { return 10; }, f: function (S, R, N) { return { t: 'THE CHECK ENGINE LIGHT COMES ON. ' + N + ' PUTS A PIECE OF TAPE OVER IT. SOLVED.' }; } },
        { id: 'AC', w: function () { return 12; }, f: function (S) { S.H -= 5; return { t: 'THE A/C DIES. FOUR PEOPLE AND A BAG OF LAST WEEK\'S GARB SHARE THE AIR.' }; } },
        { id: 'COP', w: function (S) { return PACE[S.pace].cop; }, f: function (S) {
            if (S.cash >= 25) { S.cash -= 25; return { t: 'FLASHING LIGHTS. THE OFFICER ASKS ABOUT THE SWORDS. YOU SAY THEY ARE FOAM. THE TICKET IS NOT. -$25.', foot: 'CASH $' + S.cash }; }
            S.hours += 1; S.H -= 3;
            return { t: 'FLASHING LIGHTS. YOU CANNOT PAY, SO YOU DESCRIBE LAST WEEK\'S BATTLEGAME FOR AN HOUR.' };
        } },
        { id: 'RACCOON', w: function (S) { return S.snacks > 0 ? 14 : 0; }, f: function (S, R, N) { S.snacks = Math.max(0, S.snacks - 3); return { t: 'A RACCOON RAIDS THE CAR AT A GAS STOP AND LEAVES WITH 3 BAGS. ' + N + ' SALUTES IT.', foot: 'SNACKS LEFT: ' + S.snacks }; } },
        { id: 'GIFT', w: function () { return 12; }, f: function (S) { S.snacks += 3; return { t: 'A PASSING CARPOOL TOSSES YOU 3 BAGS OF SNACKS. YOU NEVER LEARN THEIR NAMES.', foot: 'SNACKS: ' + S.snacks }; } },
        { id: 'POUCH', w: function () { return 12; }, f: function (S, R, N) { var x = 8 + R(9); S.cash += x; return { t: N + ' FINDS $' + x + ' IN A BELT POUCH FROM TWO REIGNS AGO.', foot: 'CASH $' + S.cash }; } },
        { id: 'ROOF', w: function (S) { return S.boffers > 0 ? 8 : 0; }, f: function (S) { S.boffers--; return { t: 'A BOFFER WAS LEFT ON THE ROOF. IT IS NOW A BOFFER IN THE WIND. -1 BOFFER.', foot: 'BOFFERS LEFT: ' + S.boffers }; } },
        { id: 'CARSICK', w: function () { return 18; }, f: function (S, R, N) { S.H -= 7; return { t: N + ' HAS BEEN READING THE RULE BOOK IN THE BACK SEAT AND IS CARSICK.' }; } },
        { id: 'SONG', w: function () { return 16; }, f: function (S, R, N) { S.H += 6; return { t: N + ' STARTS A CAMP SONG. EVERYONE KNOWS IT. NOBODY KNOWS WHY. YOU FEEL BETTER.' }; } },
        { id: 'WRONG', w: function () { return 16; }, f: function (S, R, N) { S.mile = Math.max(0, S.mile - 30); S.gas = Math.max(0, S.gas - 3); return { t: 'THE MAP APP SENDS YOU TO A SILO. -30 MILES. ' + N + ' BLAMES THE MAP.', foot: 'GAS LEFT: ' + S.gas + ' GAL' }; } },
        { id: 'CORPORA', w: function () { return 14; }, f: function (S, R, N) { S.H -= 4; return { t: N + ' EXPLAINS THE CORPORA FOR 90 MILES. NOBODY CAN LEAVE.' }; } },
        { id: 'LEAK', w: function (S) { return S.ice > 0 ? 12 : 0; }, f: function (S, R, N) { S.ice--; return { t: 'THE COOLER HAS BEEN LEAKING INTO ' + N + '\'S SHOE. -1 BAG OF ICE.', foot: 'ICE LEFT: ' + S.ice }; } },
        { id: 'DOOR', w: function (S) { return S.garb > 0 ? 8 : 0; }, f: function (S, R, N) { S.garb--; return { t: N + '\'S TABARD SHUT IN THE DOOR FOR 40 MILES. IT HAS BECOME A FLAG. -1 GARB.', foot: 'GARB LEFT: ' + S.garb }; } },
        { id: 'TARPFLY', w: function (S) { return S.tarps > 0 ? 6 : 0; }, f: function (S) { S.tarps--; return { t: 'THE TARP COMES OFF THE ROOF ON THE INTERSTATE. A TRUCK WEARS IT NOW.', foot: 'TARPS LEFT: ' + S.tarps }; } }
    ];
    function randomEvent(S, R) {
        var tot = 0, w = [], i, x, ev;
        for (i = 0; i < EVENTS.length; i++) { w[i] = EVENTS[i].w(S); tot += w[i]; }
        x = R(tot);
        for (i = 0; i < EVENTS.length; i++) {
            if (x < w[i]) {
                ev = EVENTS[i].f(S, R, pickIn(S, R));
                ev.id = EVENTS[i].id;
                clampH(S);
                return ev;
            }
            x -= w[i];
        }
        return null;
    }

    /* the creek */
    function creekDepth(S, R) { return Math.min(6, 2 + R(4) + (S.lastRain ? 1 : 0)); }
    function river(S, D, choice, R) {
        var out = { ok: true, tarpLost: false, key: '' };
        if (choice === 3) { S.cash -= 30; out.key = '3'; return out; }
        if (choice === 2) {
            S.tape = Math.max(0, S.tape - 1);
            out.ok = R(100) < OPT2[D - 2];
            if (!out.ok || R(100) < 40) { S.tarps = Math.max(0, S.tarps - 1); out.tarpLost = true; }
            if (!out.ok) { S.hours += 2; S.H -= 6; S.snacks = Math.max(0, S.snacks - 2); }
            out.key = out.ok ? '2' : '2F';
            clampH(S);
            return out;
        }
        out.ok = R(100) < OPT1[D - 2];
        out.key = out.ok ? '1' : '1F';
        if (!out.ok) {
            S.hours += 3; S.H -= 10;
            S.snacks = Math.max(0, S.snacks - 3); S.ice = Math.max(0, S.ice - 1); S.gas = Math.max(0, S.gas - 3);
            clampH(S);
            if (D >= 4 && R(100) < 50) { goes(S, R, RIVER_ROW); }
        }
        clampH(S);
        return out;
    }

    /* the ditch (rest area 44) */
    var STEP = { N: 400, K: 550, R: 450 };
    function ditchNew(boffers) {
        return { t: 0, swings: 6 + 8 * Math.min(boffers, 3), blows: 0, bags: 0, fame: 0, ents: [], nextSpawn: 0, over: false, reason: null, msg: '', msgT: 0, early: false, kHit: false, five: false, fx: [], blow: false };
    }
    function dmsg(D, m) { D.msg = m; D.msgT = D.t; }
    function ditchTick(D, R) {
        var free, l, x, ty, i, e, keep;
        if (D.over) { return; }
        D.blow = false;
        if (D.t >= D.nextSpawn) {
            free = [];
            for (l = 0; l < 4; l++) {
                if (!D.ents.some(function (en) { return en.l === l && en.p >= 7; })) { free.push(l); }
            }
            if (free.length) {
                x = R(100); ty = x < 60 ? 'N' : x < 85 ? 'K' : 'R';
                D.ents.push({ l: free[R(free.length)], p: 8, ty: ty, hp: ty === 'K' ? 2 : 1, acc: 0 });
            }
            D.nextSpawn = D.t + (1300 - Math.floor(400 * D.t / 15000));
        }
        keep = [];
        for (i = 0; i < D.ents.length; i++) {
            e = D.ents[i];
            e.acc += 100;
            if (e.acc >= STEP[e.ty]) { e.acc -= STEP[e.ty]; e.p--; }
            if (e.p <= 0) { if (e.ty !== 'R') { D.blows++; D.blow = true; } } else { keep.push(e); }
        }
        D.ents = keep;
        D.t += 100;
        if (!D.five && D.t >= 10000) { D.five = true; dmsg(D, 'FIVE SECONDS.'); }
        if (D.blows >= 4) { D.over = true; D.reason = 'blows'; } else if (D.t >= 15000) { D.over = true; D.reason = 'time'; }
    }
    // Returns what the swing did: null (miss), or {ty, lane, p, dead}.
    function ditchSwing(D, lane) {
        var n = null, i, r = null;
        if (D.over) { return null; }
        D.swings--;
        for (i = 0; i < D.ents.length; i++) { if (D.ents[i].l === lane && (!n || D.ents[i].p < n.p)) { n = D.ents[i]; } }
        if (n && n.p <= 4) {
            r = { ty: n.ty, lane: lane, p: n.p, dead: false };
            if (n.ty === 'R') { D.bags = Math.max(0, D.bags - 1); D.ents.splice(D.ents.indexOf(n), 1); r.dead = true; dmsg(D, 'YOU HIT A REEVE. EVERYONE SAW.'); } else {
                n.hp--;
                if (n.hp <= 0) {
                    D.bags += n.ty === 'K' ? 3 : 1;
                    if (n.ty === 'K') { D.fame++; }
                    D.ents.splice(D.ents.indexOf(n), 1); r.dead = true;
                } else if (!D.kHit) { D.kHit = true; dmsg(D, 'HE IS STILL GOING.'); }
            }
        } else if (!D.early) { D.early = true; dmsg(D, 'TOO EARLY.'); }
        if (D.swings <= 0) { D.over = true; D.reason = 'swings'; dmsg(D, 'YOUR BOFFER HAS GIVEN UP.'); }
        return r;
    }
    function ditchFinish(S, D) {
        var bags = Math.max(0, Math.min(12, D.bags)), t;
        S.snacks += bags; S.hours += 1; S.ditches++; S.fame += D.fame;
        if (D.blows >= 4) { S.H -= 4; }
        clampH(S);
        if (bags === 0) { t = 'THE RIVALS EAT LUNCH. YOU WATCH. SOMEONE ASKS IF THAT WAS THE FIGHTING.'; } else if (bags <= 4) { t = 'YOU WIN ' + bags + ' BAGS AND THE RIGHT TO SAY IT WAS CLOSE.'; } else if (bags < 12) { t = 'YOU WIN ' + bags + ' BAGS. THE RIVALS PAY UP AND ASK WHAT PARK YOU PLAY AT.'; } else { t = 'THE TRUNK IS FULL. YOU LEAVE THEM ONE CHIP, OUT OF HONOR.'; }
        if (D.blows >= 4) { t += '\nYOU DIED FOUR TIMES AT A REST STOP. THE WALK BACK WAS LONG.'; }
        return { bags: bags, text: t };
    }

    /* weekend */
    function arrival(S, R) {
        var t, late = '';
        if (S.hours <= 12) { S.H += 6; t = 'EARLY! YOU CLAIM THE ONE FLAT SPOT IN CAMP. A KNIGHT NODS AT YOU.'; } else if (S.hours <= 16) { t = 'SIGN-IN IS OPEN. THE WAIVER PEN IS TIED TO A BOFFER.'; } else {
            S.H -= 15;
            if (R) { late = '"I THOUGHT IT STARTED AT NOON," SAYS ' + pickIn(S, R) + '.\n'; }
            if (S.cash >= 20) { S.cash -= 20; t = late + 'SIGN-IN CLOSED AT 10. THE GATE REEVE SIGHS. LATE FEE: $20. YOU PITCH THE TENT BY HEADLIGHT.'; } else {
                S.H -= 10;
                t = late + 'SIGN-IN CLOSED AT 10. THE GATE REEVE SIGHS. YOU CANNOT PAY THE FEE, SO YOU HAUL TRASH SUNDAY.';
            }
        }
        clampH(S);
        return t;
    }
    function fridayNight(S, R) {
        var d, rain, missing, t;
        S.turn++; S.hungry = false;
        d = upkeep(S, false);
        rain = R(100) < 65;
        S.campRain = rain;
        missing = Math.max(0, S.alive - S.garb);
        if (S.tarps === 0 && rain) { d -= 14; }
        if (S.tarps >= 1) { d += 6; }
        d -= 2 * missing;
        S.H += d; clampH(S);
        if (S.tarps >= 1) { t = rain ? 'RAIN AT 3 AM. THE TARP HOLDS. YOU LIE THERE FEELING SMUG IN THE DARK.' : 'A CLEAR NIGHT. SOMEONE IS PLAYING A LUTE, BADLY, AND THEN LESS BADLY. YOU SLEEP.'; } else {
            t = rain ? 'RAIN AT 3 AM. THE TENT BAG SAID WATERPROOF. THE BAG, AT LEAST, IS DRY.' : 'NO RAIN. YOU GOT AWAY WITH IT. THE SKY MAKES A NOTE.';
        }
        if (missing > 0) { t += '\nIT GETS COLD. ' + missing + ' OF YOU DID NOT PACK GARB AND SLEEP IN A CAR MAT.'; }
        goneRoll(S, R);
        return t;
    }
    function saturdayPrep(S, R) {
        var hot = R(100) < 50, d, passed = 0, b;
        S.turn++; S.hungry = false;
        d = upkeep(S, hot);
        for (b = 0; b < S.boffers; b++) {
            if (R(100) < 70) { passed++; } else if (S.tape > 0) { S.tape--; passed++; }
        }
        S.sat = { d: d, hot: hot, passed: passed, total: S.boffers };
        return S.sat;
    }
    function saturdayDo(S, choice, R) {
        var sat = S.sat, d = sat.d, hot = sat.hot, t, extra = '';
        if (choice === 1 && sat.passed > 0) { d -= hot ? 14 : 10; S.fame += 2 + 2 * Math.min(sat.passed, S.alive); t = 'YOU TAKE THE BRIDGE, LOSE THE BRIDGE, AND TAKE THE BRIDGE. NOBODY IS SURE WHY THERE IS A BRIDGE. ' + pickIn(S, R) + ' IS TELLING IT WRONG BY FEAST.'; } else if (choice === 3) { d += 12; t = 'A BATTLE RAGES IN THE NEXT FIELD. IT SOUNDS LOVELY FROM HERE.'; extra = 'zzz'; } else { d -= hot ? 5 : 3; S.fame += 1; t = 'A KID WITH A NEW SWORD ASKS FOR ONE MORE ROUND. YOU GIVE THEM A DOZEN.'; }
        if (S.ice === 0 && hot) { d -= 6; t += '\nTHE COOLER IS WARM SOUP. YOU DRINK FROM THE HOSE.'; }
        S.H += d; clampH(S);
        goneRoll(S, R);
        return { text: t, extra: extra };
    }
    function feastDo(S, choice, R) {
        var t;
        S.turn++; S.hungry = false;
        if (choice === 1) {
            S.cash -= 6 * S.alive; S.fame += 1;
            if (S.turn % 2 === 0) { S.ice = Math.max(0, S.ice - 1); }
            S.H += 12; clampH(S);
            t = 'FEAST WAS CALLED FOR 6. IT IS SERVED AT 9. THERE IS ROAST AND A MYSTERY POT, AND IT IS THE BEST FOOD YOU HAVE EVER EATEN.';
        } else {
            var had = S.snacks;
            S.H += upkeep(S, false); clampH(S);
            t = had > 0 ? 'YOU SMELL THE FEAST FROM THE TENT. ' + pickIn(S, R) + ' CALLS THE LAST CHIP A TRADITION.' : 'THERE ARE NO SNACKS. SOMEONE WALKS PAST WITH A PLATE AND DOES NOT MEET YOUR EYE.';
        }
        goneRoll(S, R);
        return t;
    }
    function sundayDo(S, R) {
        S.turn++; S.hungry = false;
        S.H += upkeep(S, false);
        if (S.garb >= S.alive) { S.fame += 2; }
        clampH(S);
        goneRoll(S, R);
    }

    /* score and hall */
    function score(S) {
        var won = !S.lost, rows, base = 0, i;
        if (won) {
            rows = [
                ['STILL IN', S.alive + ' X 200', S.alive * 200],
                ['HOW THEY FEEL', S.alive + ' X ' + S.H, S.alive * S.H],
                ['FAME', S.fame + ' X 20', S.fame * 20],
                ['CASH', '$' + S.cash, Math.min(S.cash, 100)],
                ['SNACKS, GAS', '(' + S.snacks + '+' + S.gas + ') X 2', (S.snacks + S.gas) * 2]
            ];
        } else {
            rows = [
                ['MILES', Math.min(S.mile, 720) + ' / 4', Math.floor(Math.min(S.mile, 720) / 4)],
                ['FAME', S.fame + ' X 10', S.fame * 10],
                ['STILL IN', S.alive + ' X 10', S.alive * 10]
            ];
        }
        for (i = 0; i < rows.length; i++) { base += rows[i][2]; }
        return { rows: rows, base: base, mult: S.mult, final: base * S.mult };
    }
    function rankOf(f) { for (var i = 0; i < RANKS.length; i++) { if (f < RANKS[i][0]) { return RANKS[i][1]; } } return RANKS[4][1]; }
    function validHall(h) {
        if (!Array.isArray(h) || h.length > 5 || !h.length) { return false; }
        return h.every(function (e) { return e && typeof e.n === 'string' && typeof e.s === 'number' && isFinite(e.s) && typeof e.w === 'boolean'; });
    }
    function hallInsert(hall, entry) {
        var h = hall.map(function (e) { return e; }), at = h.length, i, inserted = false;
        for (i = 0; i < h.length; i++) { if (entry.s > h[i].s) { at = i; break; } }
        if (at < 5) { h.splice(at, 0, entry); inserted = true; }
        return { hall: h.slice(0, 5), inserted: inserted && at < 5 };
    }

    /* ---------------- pixel art (1-bit sprites on the 280x192 grid) ---------------- */
    /* ORKEGON TRAIL art. Generated from scratchpad/polish/trailart. Paste inside the IIFE, replacing the sprite tables in ART.
     * ART2 rows are literal. PACK entries are [w, h, modeLagData]: see unpackArt. */
    var ART2 = {"car":["......#########.#..",".......#.....#..##.",".....#############.","....#o##o##o##o###.","..###o##o##o##o###.","###################","####o###o###o###o##","....#####...#####..",".....###.....###..."],"dP":["...##..#.","..####.#.","...##..#.",".#####.#.","#.###.##.","..###..#.","..#.#....",".##.##..."],"dN":["..###....",".#o#o#..#","..###..#.",".#####.#.","#.###.#..",".#####...","..#.#....",".##.##..."],"dK":["...##...#","..####..#","..#oo#..#",".######.#","###o####.","###o##.#.",".#.#..#..","...##.##."],"dR":["..#####..","#########","...###...","#..o#o..#","##.###.##",".#.#o#.#.","...###...","..##.##.."],"burst":["#..#..#",".#.#.#.","..#.#..","##...##","..#.#..",".#.#.#.","#..#..#"],"ring":[".###.","#...#","#...#","#...#",".###."],"dot":["##","##"],"slashH":["...#####...","###########","...#####..."],"slashV":[".#.",".#.","###","###","###",".#.",".#.",".#.",".#.","###",".#."],"rain":["#","#",".","#","."],"sun":[".....#.....",".#...#...#.","..#.....#..","....###....","...#####...","##.#####.##","...#####...","....###....","..#.....#..",".#...#...#.",".....#....."],"tent":[".........#.........","........###........",".......#.#.#.......","......#..#..#......",".....#.##o##.#.....","....#.##ooo##.#....","...#.###ooo###.#...","..#.###ooooo###.#..",".#.####ooooo####.#.","###################"],"cooler":[".#########.","###########","#ooooooooo#","###########","###########",".#.......#."],"mark":["..#",".##","###",".##","..#"]};
    function unpackArt(w, h, s) {
        var AB = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        var mode = +s.charAt(0), lag = +s.charAt(1), bw = (w + 7) >> 3, n = bw * h, planes = mode === 2 ? 2 : 1;
        var bytes = [], acc = 0, bits = 0, i, j, p = 0, q = 0, out = [], px = [];
        for (i = 2; i < s.length; i++) {
            acc = (acc << 6 | AB.indexOf(s.charAt(i))) & 0xFFFF; bits += 6;
            if (bits >= 8) { bits -= 8; bytes.push((acc >> bits) & 255); }
        }
        for (j = 0; j < planes; j++) {
            var pl = [];
            for (q = 0; q < n; q++) {
                var v = bytes[p++], run;
                if (v === 0) { run = bytes[p++]; while (run-- > 0) { pl.push(q >= bw * lag ? pl[q - bw * lag] : 0); q++; } q--; continue; }
                pl.push(v ^ (q >= bw * lag ? pl[q - bw * lag] : 0));
            }
            px.push(pl);
        }
        for (i = 0; i < h; i++) {
            var r = '';
            for (j = 0; j < w; j++) {
                var off = i * bw + (j >> 3), m = 0x80 >> (j & 7);
                r += px[0][off] & m ? '#' : mode === 1 ? 'o' : mode === 2 && px[1][off] & m ? 'o' : '.';
            }
            out.push(r);
        }
        return out;
    }
    var PACK = {"titlebg":[280,
    141,
    "12AEkP//AAAf///4A/8A/8D////wABD//+AAED//wAAT/wAfwABh//+AH////Af/gf/h////+AH///AAEH//4AAX/4A/4BAAUQAAEIAQADQEAIEAIQAAOBEAABAQABBAABAgABQAgCAgAGYAABBgAEMAABAgACgAADIGAAAsAYAAEBgAABBgACgQAF4AABBwAEOAABAQACQAADEeAAAuA4AAEBwAABBwACQAAFgAABAQEAAwhAAAICEAAEgAACICAAAkBAAQICAAUDA//AwcAf8AABcAACfxwB/+ADA//AAAHA//AwcAABw48ABQcDVUDgwBVQAjAAAlYMAVVgBwNVQAnA1VA4MAAB4Y4ABQQBVQABIAABBUABAAOUQAABUFCEAVUgJQABVUAIAAIgBEAABiqACAABAqAAA2OKgAABKJ+AAAEqv+AAAQqgAgACGAABgAAGVQABBAABAUAAAgHiRQACF05AAAFVGpAAARVAAQACHAEADQgAAQKEgAAChQABgAACAqAAAQQABAQABAQACDA8AAEHHFAAAQG4QVAAAgVUAAU4AQANcDgAAQcSIAABAfggoAACAqgAAQQAAzgABQQABwRAUQACJAAPFAEACCAAAxeB4gAC4oAABgf/wAAFDwOAAAsfgcQAApEABw//4AAFAwOAAAcggAAEiAAKCAABIAAGgAAIAYAAAhwAARAAApQAAgH4AAzgAAwUAAEgAALoAAIBWAABIAADCAAGYAAGgAABAYAAAgIAAUAAAiAAA1UAAwgAAQQABhAACYAAAgOBAAIHGAADJ4EgAAEPwAAHHAAGgAAFBYHAAAEHHAADEgOAAAEGwAAHDAAGB/wAAT/gP+B/gA/6+AABA/4AAaEH/AABv+H/AAEP+A/AA/AADRAAAQHjAARMAAIBAA8H/AABP+A/4H/ID/gfgAP+AAEfp/wAAb/h/wABD/gPwAPwAAUH/QABP+A/4H/QD/gfAAED/kAPl/0AAT/h/0AP+A/AA/AABQcDf8DgwAABcDgwAAJ8DAF/4AcDf8ABwN/wODAACf///1H/4A/4f/gf/h////+A////4D///9R/wAPwAASAAAH///8g/+AP8D/4H/wP////gP///+A////IP8AD8AAG4AABBxQADeAAAQcAATgAAQHFAAIDgAACIAABgAADAogAAwIABIAABgIABKIAChAAAQkBAAEgCAlACBACUAADgBAAAQigBAABAkBAQAABUAACIAAGoAAUKAABIAABCAAGDqqkQOqQBqQ6pA6pDqqqqkAOqqVQA6qpEDrAAAEgAAeqqAABCqAAAagCqAABqgABqqqqgAABqqgAAiqqAAECiAABCgAGAVVVAAEVVAFVBVUBVUFVVVVQAVVVFAABVVVABVAAAQQAB6qqAAEKqAABqgKqAAGqgKqqqqAAAaqqCAABKqqAAqAAEx////4T///+AgABB4AAAQ//8B//ABE/////B////wACD8AAAR//+D//gAAQIAACARQAAgECAAEIQAABEAABCCAAAYAAEeABwEAAA8AAATAwABjgAUAgAAPgAAFwOAADAgACIAAQAUAAASAEAAMgAAFACAABEAABCSAAAZAAAkAADQeAAAE4BwABf8AIAAICAAEcAAE4OAOAABAHAAJYAwABasAEAAIBAAEMAAEkGAJAAAJAAA0qAAIFAAIqgAADAgADCAACgAAQPEAAAQcAAgUAAgGABwgAAgUAAlAAEBCAAAECgAABCoAAAQOABwADAgACIAAQBQAHIAIAAQEIAAwCAAGAAAcCAAOgAALA4AABDgAbEEAAAQHAkAABCgABQAAMAgABgAAGCAAGIQEgAAEBABsQAAJuBxQMAcCAABUIAAZ+BIgcAsDgAB0BAAEQBAABIAAZAgACcAABoAABDcAIABxwAAFAAAEPwAQAGQIAAigCABEEAAeAAAceBwALAgAMAcAABwYHAAsHAAQCBAAJEB/+AAL/gf4AAR///+QD/8AH/AABAgAMAcAACmB/+BAABhgABAIAA4AABIAAARAf/gAC/4H/QP+VV/wD/8AH/AABP0AABQH//8AABh/+gAAB/4H/AAF/iof4A//IB/yAHyIABQ//f/gABmABwAMAAQHAgAABdUgBDAABIBgC/8AABn4AAj8ABv//wAf/gD/g//q//J//+D////8CAAQB8AACB8AABX//gAP/gD/A/+Af/A//8B////8ABQeAAAPwAAwIAAYCAAkeAAQ8AAcQAAQEAAEEAAQBAAk4AAQOAAWAAAFIBAABgCAggBIgBBAAAQggAAIBAAXgAAQDgAAJQAABEAAGBAAJP//////+AAV1VSADVYAdYHVIHVINVVgdVVVUgAADAwAGYAAEZVVAAAFVEAFEBVABVAFVUQFVVVUABAf////////wAAT6qqgAAaqgAqgKqgKqgqqqAqqqqqAAAwwABhgAAwGaqpAAAVVAAVAFVAFVAVVUAVVVVUAAAxgABgwAAwMP/+AAFDAABgYAAwYAAjAAFGAABgMAAwwAAhwAFMAABgGAAAI4AAKOABR//////////wADcHABMwATAQAIQAACwfgCbcAAEgP//////////+AAAQGHjgRroAASBgAIMAABAp4HAAFa8AASBAAIEAABDfgBiBboABIIAAgIAAESYAAB2B2mABIIAAgIAAEsAAJwG20ADWAABA////////////gAAVAAAiQG34AADPgABA////////////gAAaAAAwG3QAAEGAAGA54ABD////////////4BQAADB20gAAR+AAYHB4AAAYiIP////////////gqAAAICAtt4iIiIieeIiIiIiIiMAeiIAAwFAAW26AADA4HAAAU4B4gAAYiQQAAIAQIABe2siIiIhgABeIiIiIiI8AfqiAABPkAACAEUAAMBAAHbbwADPAABOAAEAccByAABiOf//////////AABH2gABTbdiIiI8YB+iIiIgAABf8HyCAPBv/////////gAAQeQAAUNtUAAAQPPwKsABAH48D8wiP+AAAYN4HdgAASAO20qqq7+cSpoiIiD/z44DyY8AAE////w////9/KdgAAFFtt4AAEb8D8TAAEP8///P8gPO6AABBD////OHz7AAAUFtu6qxgABDqTn//P8AAEB8D88n//////gAANwDTdgAARAA22qAAGYAAJH//AABDjxL4AABwLAAAEFUAAFBttpK+AAAQP/+wAFCMrbg+ccAicAAf///YAAAQNIAAUFtttMgfP/+/8AAgH/P4AUdgABKKIDYgAB///5AAKG3gAENAFtt9MBH/3//APz/wACBCBtA6e6AAGFAAMaAAIFuwADAfah220MAAHt//whAAIB/z+EAAELAAGpiAHAAAMkAANqwAACDh6RttvwAAHv/iqJA/P/AAQOAUKUAAGAAAH//1gAAkHaP/wAATHj8i2yQAEeAAEVcP/z//8//AABDQGFCAABgAABf/+gAAO2IAP/zgABORtRgAHwAAEhSf/z//8//CVRgA2uAAEFAAHABUAAAyo//AABMAABBxb2P4AAAhtS///////9DV0BBCICIgABcAuD/gABID7gA//AAAEDlp/gcAACO8////////8P+4EPnAInAAE8IjwB4AABUwAGcZg/jgABAc48AAUDmAmAAAXwC/wB//VT////////4HgeD///wM/fH9/x///h/x//////4APYPAHgFTAABgP4RA4AAQHA5wcfB/Hg/nAAAaAABBB//////8f//////H////d+R///8/AgAAHYAAEfAAEZ/z//4///8AANAghEAAMDuQQBBAABIIAIAAMcAAOAAAQ4AAQDgAABgIiAOAACD/D4ABkgCggABA7hBAEEAAEggAQAAjgABDgABDgABDgIAAKAOAADPMDYAAMbAAEEAAg+AAgCAAEkCAAEB/J/BwAB+A4fAQADOAAEOH8AAzgABLhoEIAAATgAAQP/z/hfHwfx+/4CAAwqAAMQBAABRAAE//gAAT//4AAE8gABOAADA4AAAX8AATgAAX8AAQOEAAGwYKgAAwP8AAIh5mZmZmZmcAAIPgAD1YAAAYAQBgFAAAMHAAEBAAETMzMxMyMzIAABOAADA4AAAzoAAUEAAROEDDABuAADB///8B////3/mf/gAAQKgAAEEkD/ggABgAAHCoA5OB////3/X3/hAAI4AAEqoDgAA3iQPkAQAewAAQGAAAI4CH+f/B////3//3/hAAQdwAAEPeAIAgAEASAAAwKA+T4f///9/5Pf4AACOAABVVA4AANN8QABQAABDuAAApAAAjgDge/vH////f7+3+AAAf//4ei//////9Bf9////////9////8wAV71H////fXvX+DPAAN/8AAEOOEAAw8AAxAAAzAAAZOSBmZmZGk5JmzA///h/////////////////////////wACh8IzMzMzKHwjMA8ACQ+AAAs/AiRIv////8JEh//AAAIcABM//gAC/////+AAAQ//wAACHAAU/CRIf////8JEh/wAAQ/4A/4AAf+AP+AP+AP+AAH/gD/gD/gD/gAB/IfCYA/4A8h8J4A/ABiTkgAECTkgAAIP+AP+AAH/gD/gD/gD/gAB/4A/4A/4A/4AAfxe9GAP+APF70eAPwAYb+wABAb+wAAaOTgABAOTgAAC////////////////////////////////n/P/////+f8///8"],
    "lmTape":[280,
    136,
    "01AP8A8P//wAAg///AAGMP//////8AHQ///////wBuD///ACD6qgrwAB4HSio7jgAePOCwm6/AAB3koHDqO3AACj///wAPAysYRvj/7AAKP///AA8OC1oW+OujAAPwABgSgGo7Qq8qgAABAQ4AGCACKCsKvj9AAAECYcAAAz////////AADeqCAqgCkPvwAAEEnAAEP///////8AAMASpBCohKAbvYAAEJA8AAFgZoQQqhYoP/vgABEgAYCGgQQ4UqD/b1AAEkABgaxBDtDLgn9uOASAAYKsSCvCjwO6rqQJAAGEohgBCDQbor/qEgABjKIRIiowXvLv3SQAAXAWEIOqaIHv6v/OyAABghCKuEqDuam/uxABgCiEGhBKCLgpu7sgAYBZhBKBSC7KLv7mYAGAESCqgwCO6O668IABgIhgrgIBq4nraumwAYEIRiwQN60nq6Pq2AABckIGCDAWvC7iJujkAAFwwirgoKCQIvC7ragAAXaQa8GBgQCq+IuvpgABcBFjBQUDAitCnr/gAYqDIgwMCsavAf6/YQABcoCwKCgS/qwNu/4iAAFgHhKwYGA6qogbt76pgAF0MoBAQIrSoH/zrq0AAXijggIBrkKBP2/urwABYCEtBgYHrogD/m+6u8ABdaQUFA6uCgv/ubq7AAF+qDAwIrQEI+656/4AAWBO4KCgatAWf/7/4/xgAWAuQQGB6qA6/+67t+gAAWA4AYUDq8CqmKu7e8iAAWA+CiwEqwEq+K//O4jAAWD8CjgWogOryqP+7QDQAWBwSNA6uAirCqfu6ACgAWAgyECq8BqiCjv7moqgAWAiooEqwHq4Jzvryo7gAWAmJgWsgOrwMvyvoi5gAWAetA6qAirAv/yqoyogAWA6kBqkBquAf8q6o6qgAXogeqgerQN/iqqm6qAA4D/////////AawCu4DqkBv+qai7iAACAEABQH////////4BrAm9ASqgP++sqOqIAAIB8AADAIgapAWrgP/a+qrioqAAAcacAAGic8g8eAAAQOB6qA6tAX+K+7+ip6AAAd1nAAGA+eAe+AAAQcD74CqkAv/7+z+or4ABwH7ZwAMBAjpiSrgJ/7vvbqjugAHArbZgAAFIAeAA8AAAhu0javAf9q3v7qruAAHDW22YAAGBQABAeAAAQI7qi6rAf+ap++qv+gABzrbbdgABXADAAQC/vI6ogL/Lu/vKj9AAAf9ttt2AAVT4Z574AABAX7I+rgN/277vis6AAhbbbbcAAWJyL7zwAABBPiIavAb/qn7equ4ggAGA7bbbbeAAAMEDnjnz3wAAQf8IirAP+6t/zqv6IYABh1tttts4AAEHz3z32wAAQv+JuyA/977/vwvwC8ABirbbbbbOAAGEAADA/q+6gl/zv+u+DqAKAAGfbbbbbbMAAQOPHOeAAID6pqkDP+r/4u4mIqgAAUB2222222zAAQOKGOPAAIC6sqqL/+q/5uoiI7gAAUOtttttttuwAAEGAAEB+uu4h/27P+vYigO5AAFFW22222227AAAgPADePfAAPr78jfpu277iIoCqIABX7bbbbbbbbsAAIH0UT33hAAAQGjuYk/u7+3voioqqAABedtttttttueAAEB////////+AOzuCN/6//juozo6rgABYH///////4CAAED////n////AAB7/on/O4//tIu4kI0ABe/uru473vugiqiAzAAFgG/aou3vvi7qCoqq6gAF34qn/O/+Lqobiv/gAAXv6v/7t/y7iLuI/cQABc+r7rum7b6IqojogAYeqO4u7u7ioqKqwjgABcatu27/vvKjor+DAAToAAELu7v9/3vIq4i/A5AAAgD///AAAsWqr/jvOojqiO4C4AACaqqgAALCap7i7uyg6KjmCsABwP/Af//gAAMqjuOu6KG4reIaAAJAf//gAALBir/7j/iDuI/AuQACQH//4AACwMr+7p/ogqiOgKuAAkB//+AAAyL87q5iKqouIqwAAkB//+AAAsBD+bvvIjqrejOqAAJAf//gAAM7+7P8iLiL8Dm8AAJAf//gAAMWqqf4iKiO4CjIAAJAVVVAA0oqr9Iqqo7iqtAAAkD///AAAwUq/4I7qp6jq2AABkKI/wAAewi8CwJABoHI7gAAagjoCgWABoBy7iKqquIqqAAG//93///3f/4ABgwAAEDL//v//7//vAAAQMAFg///OVVRVVUVVRP//wAFg///P1VRVVUVVfP//wAGf1VRVVUVVfAAE0B/AAB/////gAC/////z/////P//wABw///P///////AAG////////P///////////8AAC///////9/////////////////wAEwAAID/3/AAUDAApgAAgDgAAGAgAGAQOAAAMQAARgABcecAAEEAAHGAAUHgANGAAFBEAADQEEQAAKGAAREAACMANwAAsYABEQAAIwBgAFQAAcBAAFQAATGAAJBEAABgcAEBgACQEABwcAAQOAAAEDgAAB4AACCAAZA2AAAQOAAAHgAAMgABIcAAbgAAcP4AAOAoAAAhwACxwAHw"],
    "lmFort":[280,
    136,
    "02AP8A0QOAAA44ABID+AAOP4AAEn+AAA0H+AASB4AADngAEgeAAA54ABJ/gAANB/gAEQH4AA4fgAARAYAD///////////////AAAEYABQCAAtAABYBAAuAADp//////////////gAafOefAAHjjwAdQRREARRQAB0989sB598AHTgDwAABY55/AB0BQAABEBEAHTgDAAJjj38AHAHxQAHgHwAWB//9//wAAkEUhAEUQQADP//f//gADgf//f/8AAJA5EQAAfReAAM//9//+AAWBE5F9EAAHgbRRQRAAB4BHyD2wAAWB3////gAAwOAEOVAAAM3////uAAOB3////gAAwEAASgDgAADN////7gAFgEAARDgACAfCPAAHwRRRQEAHwRORfEAPB/gAAGAAAkBAAEB/gASECAB//////////////+AAQIAEg/AAgACMAAFDAACQAAB/AAUA///z///////8///wAA3D8AADvwAEhAgC///z///////8///+AECABIf4AwACxgB/gAUB///////////////4AAZMAAFDACCB///////////////4AAWAqqqqqqqqqqqqqqquAAWCqqqqqqqqqqqqqqqqAATf///8AALB////4AAD4AAAgVbVVVVVVVVVVVVtVgAA0AAD/////qkqqqqqqqqqqqqSq/////AAAdjGMYxjGMYxgATGMYxjGMYxjFjGMYxjGMYxgATGMYxjGMYxjGUpSlKUpSlKf////wACw/////lKUpSlKUpSpSlKUpSlKUpABMlKUpSlKUpSgABgCAIAgABgCD////8AAsP////wCAIAgABgCAIEAQBAAFAEAQBABMEAQABQBAEAQAs/////AALD////8AAMv////wACw/////AAFX////8AAsP////wAAy/////AALD////8AAVf////wACw/////AADL////8AAsP////wAArRhGEYRhGEf//wAADD//wAAE//8AADgE9z3Pc9z3PAAJ//AACD//wAAE//8AAAg/////AAAcB////////////gAAaCBx74Bxx4AABP/wACw/////AAAkIIoCAIooABgH/gAABB/4AEj77YDz74AAFAf+AAAEH/gASHPAAAQxzwAABP/wAA34AAgH4AAMP////wAALiAABAgIgAB0ceAABDHHgAAE//AADfggAAQH4IAACD////8AACQeA+AABPAPgAAZ+BAABAfgQABAIIgiAIoggAAcMAAMwAA8D6J7xRwKJ5wABP/wAA34GAAEB+BgAAg/////AAAgCCKICKKIDYgAHBwADHAAPAe+eAe66AAGFAAE//AACAf+DAAEH/gwAAg/////AAAgBwB4CYggBwAAFAf+DgAf+DgARCgABpRQAAYAABwGAAAIGAA8BwBgBQggAAYAAAT/8AAXAAAIDAAIP////wAAJD4oDa64AAQUACMAAAgMADwIIpAEIogIiAAE//AADPAAD8AADD////8AABwH9+N38GOP92P+AAAQ8AAPwAA8Bue57nv+57gACQAAZwjCMIwABwjAAAj/8AAsP////wAAy/////AALD////8AAVf////wACw/////AABhAAAIBABMIAAL////8AAM8AAFAAAHwAQACD////8AADAgACTwAA/AAOv////wACw/////AABUwAAEMAAHAAAEwABb////8AAIwAAEMAAHAAAEwAAIP////wAAHEAQBAAFAAAEEAXve973x/8AAAQP/AAIP/x//e973hAEAAUAQBAEAAfc9z3Pcxz3PAAQOAAE///wAAf//8AAB4AAEPc9z3Pc9z3P3ve973re973ve973///////////////97xve973ve973vewAFMAACwAAFAwAOAYAABQgAA0AAEQMAARgAEcAAAwGAAwAOAYAABQgAFQMAAQQACAgADAGAABEgAAcwABQEAAgIAAIMABwgAAcwABfgAAgMAB4DgAAGDAAD4AAEGAYAC+AAIwHAAAIDgAAGDAACOOAABBYGABgBwAAFAYAADgHAAA04AAUOAAFwABcBwAAFAYAAFA"],
    "lmRest":[280,
    136,
    "01AP8A/wD/AP8A/wALP8AAIAPwLgAgDjAVgAAfNRWcYAAfxlGouAAeAWBkMOQAHgMiZAaAAB4FS2SOywAeCgABoMxIAB4WKMkUBkAAHQYjTVBGgAAdMgmEAAEIIAAdUCagJCgwAB1kNIDkoGAAHUCJKcGAUAAdLAuJwQgQAB3NWFKFLQgAHBVlwlPhIwdgAAcD++AAEaoqCAmFCgXcAAcNDRgAEAOAywwoMixOYwAHIx1GABAGTOEwJzamqyKAAAZYZFkAEBKuJDYFL6LgAmAABgsgaYAADQMAARgiJA8BCC7aARAABoFQcwAODMBUAAEifUqbDtwNCAAFAhAQI2AADRAgJcwno2GZei6WJAAFBJQQC6AADWAYwU2li+BxbioTIAAFASTAJrAD/////////wAEAYAGAAHRi0Hw9RALSlsABQ+pwHEIAf////////4ABAYAAQFKUMkZOYQgD8N5AAUECQo8wAAB/////////AAECAACKCGCBtFQIgYAASGAAAQDAgxRgAABf///////+AAEMAACIoFhB9BQBg0g4IAABHeyJ0RDAAzAAAOwLUUMQIwBpPCAAASELYnEFdAAAfPnvgAGAwAEuGpSJE2MPqdAQAADDTGdUJAAAZQAAXnvtgAGBAAEpc6UqC9AMiNwAAQ2MoB+mBOYAAoYAAQP3cuRAugiiiEABBQAAbUGnAIQAAF5zwAHHAAERtdBcWp8JgUBgAADgpU3wgQMMAABUceAAAYB/////kChqeTa0CyPSQADAVOca9REBAQAATAACAH////+OkPhNN4YOCJ5gAADJ8hRBk9MHAABGe+AAAYB/////iVkZ7pNDxACPAADAjfFGJAqVVQAAYvvCAALewQjC0Y3AAF0DEAAA0GGImWzw8QAD2gg/ANVMDCEQoAAAgYAASMCxZISgAAKAf////6kAfgiEhAljGKAAAIHWIMAAeQICqgDnnzgAggABAH////+/MkS5y4Ik1wQgAACBSgiLkAg3rgHzz3wBBAACXLhGYYmzI4ksAADAXEAAS4EKQ44AAQMMAAJJuMbcQiEQUfnAAMDVPrABwGLkAOPOOAYYAAEAf////5wzBoSAUxpc0AAAwGcrvBXGNOoA4o44A00AAQB/////mQGS2gTjEAiCAADB/FReRAYSLgAAQYAAh10AAnQEFOuGOFofiAAAwFpBVoDjQABNAABAzwADMAE1rRYcwxFUAADAwGCExudLJAEUX0QAggABAH////+7CTNWlAQQI1gAAQgypAaU6cEAAoB/////hE38wYCRkCNwAADAQJZskrDwQQADwL4CshsM0h+AAWi6cItlEecABEM3GeLyagABUDhoAVcF7AACgH////+AAIDGIYDeiAABTBZAAEgVBAgAAoB/3/7/gAD+HAACAxgSgJdIAgAHAOg7gLZgAABf///////+AAWfxZCnqQ0/////////AADAf8AAQP+AA8OYhnMCf////////4AAwH/AAED/gAPA+HgN8P/x////x//ABgiADAB/wABA/4AHgH/AAED/gBkAf8AAQP+AB4B/wABA/4ASQiAABkB/wABA/4AECIADQH/AAED/gBkAf8AAQP+AB4B/wABA/4AQQH/f/v+AAz/////wf/////H////H//////8AAT////wf//////////////////////H////H////////////////////////////wAJOAAD+AAECAAHQAADgAARGAAECAAHQAADgAABCAAiCAIAIgIBABUDAAEEAAoBAAEgABMDAAEEAAwgAAUiIiIiIiKiIAADDAAXIiIiIiIioiAAAwwAKgMACsAABPgABg+AAAoDAArAAAI4DgAhOA74AAYPgAACAwALBgAWcwAIGAACBgACcAATcAAIGAAFcAANGAAiGABA+AAGD4AACAYAAwMAGQ8ABAYAAwMACAcABNoiIiIiIiIvgAADDzwACQHgAAUHAAQiIi2iIiIiIAAFPAAJAeAADA+AAALuABMcAAE"],
    "creek":[280,
    88,
    "04ABc/////////8AAbIAAGEAAbI8cc8AiciBAAGyIooogNoogQAAs/wAAOHdddd/Vdr+AABQYABSBAAA4DyL6ICr4gAAYGAAUgQAAOAU++eAABPqgABg8ABSJAAA9gAAIFAAGoAAYPAAUYgAAPD4B4AgABcAAGGYAABAIADwPIvogKviAABjnAABQChwL5x6IgAAv/3YAADgJAggIgIiAABhCAAAP/2IAADgIvAnogIiAABiBAAAP/3YAADw8giiiAAAdAIAACA//fgAAPB4D754AABxCAAAIDAB4gQAACAwABAgAIA8AABx7754AAB0AgAAIDAAEdwAAHA8AABR/w33XXf//gAAWAEAP/4AABIAAIA/gABSAIIIYogAABEAADPwACf/wAAR3/wCAABwPAAAUgCCCGKIAAARAAAQ//wP/fwAACAg/AIAAOIAg+fc8AAhH/8AADf+AAAgIAAdrgAA4////z//+ADgAF//AD/+JPr7AACDgACn+AAAQ/AAEf//wAAR0YBwATB4AAAw//wP/gAARAAAo4AAk4AAIB//AACA8HD4AAEAHAAAF//gAJAfBvsHgAEBIAAX+AAAoDGDrgB4AAD+AHgAAED/8ABQGwPeAAAXwABjgABwcAATgAAgf///AABgHwP++AA8AADTgBwAH/+AAKQB/AeAABOAAFOAAGA8AeAAEeAA0CAAEHgAYADBwAAewB4AAED/8ACnwBgAAL4AcMDgACB//4CAALA8BAAAoPAAE4DPAB//gAAw8ACzgwAAQ4AAVwA8AHAAEeAAQf8AALBggACQGAHAABeAHgA/wAAT/gAAwBhgAEOAAEDgAB4AOADgADf8f4AA1BgAAIcA8AATwAAfAAAg+AAAIPAAwwQAAHAYBwA8AG/gACMAACH/AADAggAAcOAYAcAAF4AAIDPAABP+AADQYQAAM4AANwDgAB4AOAAAIDAAF/wAAOAYwAB4BwAwABPAADBA+AABBDAAM4AAIHAYAcA8AB/gABGDAAESCAAAU4DgAB4BwABDDAABEQYAAFwHADgOAABEMAEgwQAAQHAAGAHAMABAGEABIDDAACOAABOAcAAWAcAAQGGAATgwAEwDgDgOAABBgwAAX//////////////////5///D///z///H///////7///////wAOEAABA8AAAQcAAhwABQGAAAUH/AACD/gAAh/wAAI/4AwAAXwAAjj/gGAB/wACA/gAAgf8ABADAAEDwAABwAABA4AABRgABv//////////////////////////////////////////////ACMH/PwAAQwYAAEfn/B8AAE8EAABD//AfgAB/nAAAQ4/AAE+A//4AAI8AAE/AGoPBAABA//wH4A/nAABA4/AD4D//gACDwABD8P+PgABBgQAAQfP+D8AazgAAT4f8fgAATggAAE/f8D4AAH4YAABHv8AAfwD/eAAARh8AAF8D/vwAGoP3/A+AAE+GAABB7/APwAB/3gAAQYfAAEfA/78AAEEHAABH4/4fAABHABr/B/z4AABIGAAAXx/w/AAAfAAAn//AfAD8cAAATn8AfgP/8AAASDwAGnw/AABPAACH//AfAAB/HAAAQ5/AAF+A//wAAEIPAABPg/5+AABGDAAAT8/AGn/wAABAeAB+H/HwAABwIAAAfn/B+AD4QAC+/wD4A/ngAABY/AD8D8AaTAgAAE+f8H4AAH4QAABPv8AAfgD+eAAARj8AAH8D/vgAAJwAAF8P+PwAGrD4APgf9+AAAGDgAPx/w+AA4AAAQH3/A/AD8YAAQHn8AfAP98AJQ"],
    "muster":[280,
    68,
    "01AA4HAAQNAB7wAAPwAB4IAAIBAB84AAIBwAAdAcAAAzgAHQIABAQAHQsABALAAIQ/8P/////8P/AAGh//////////4AAaA+i+Acc++AAcA2AeA++2eAAgIAAOHgAQBxwAAecAAXAACw/+ABAHHAABxwABcAAKAf/gACD/8AASHgPAAAF4AAkP/gASiL4B6Ij4AANAAAUOAAUBAAKgABPQAAUTQAAEA0AF9AAJH//////////gAbQABTbQAAQG0C/+gAACEgACgAACP/D/bb///D/wA20ABW2wAAQNtX//0AACNoAJoAAEAbbAAAQG2wAF22gBAAMbb///+gACbaAbaAACASADbaAABA22gAADAbbaAAQ2H////0AAAdtpNtoAAgNgJttoAAQbbaAgAAITbbYABG7/////6AG02222gAABBtBtttgAAhABNttgAAMG220AAQIAAt///////QNtttttAAJNsNtttAACNINNttAAASQAAQ2220ImgAABscYcccdVBtlttttAAAHbYLbbbQACLabbbbQAAW0AAQttttbtoAABbbbbbbQAAQ202222QAG20G222wABBBttltttRNtIJtttuxtoAAFJJJJJJAABCSuSSSQAAQEkkEkkkgABBBJJBJJJtJJIbJJJIBJIAAICAAIEAAIQgAABAQALQAABAQgAArQAAhAgAAMFgAABCwAFA0AACtAACCQQAAMIQAABEIAAASBAAAEFoAAJAWgAAQIEAAEBAgACBoAAAxAgAAEgQAABRSAADwRSAAECAQACQAgAAwQABYAQAAEIEAAJAgQAAQgBAAEEKIAAAYAEAAMmkAABRSAABBFIAAkEUgAHAQRCAANACAABgBABCIgAASAEAAXgAAMIAQABEIiACIBAAAaABAEAAQgCAAEEAAE8HAAFBwADDwcAASAAAUAQxCACAAEBAAMEQAIIhAQAAQIAAQIgAAqIAAFAAAEgIAABEAQQAAGAAAEBAAECA+A8AAoBEAAJPwHwAAEYIAACAgABAQABEEAIEEEAD4EEEAABggABCAACQAABBBAAAYAAAhAAAoAABwcABQEAAggAAxAAAiAAAQgYIEAAAiAAAkAADQIAAgQAAyAgAAEQAAEP4A/AAAI/wB/AAAIP//AAAQEQAAI//8AD/AH8AAM/8BfwAAIQEAAEICAAAw//8AACQAACP//AAAECAgAFIAgAAv//////////////////8P//X//8P//////////////////////////////////////w//9f//w///////////////////8ABmAABBgAAQGPAAKgAAEDwAAUYAABgAACGAABAYAADGAAD4AAChAAB2AAGhAAHTAAFwGAAAIGAAYwAA+AAAIwAAQBgAACBgADwAANDAAEgAACMAAMwAAJAgADDAAeAgALAYAAFQOAAAoBgAACBwAMMAAFA4AADgfgAAIcAAcB8AAIAwAN4AACHAAHAcAACAMPAAkMABgPAAkMABrgABoBgAAFDuAACwwADgGAAAUOAAwMAAcBwAAaB4AABQHAABoHgAAc"],
    "battle":[280,
    48,
    "01ABAEAAJAAGYYACHgBw4AIuAAQwwAAeDAAB8YBwGAAB8QCBEAHgP/H+/h/4AAHAH/H+/h/wAfQAABJABFMAAeBf9f7/X/AB0JVVVVVVVgABwb//////+wABw////////8ABz////////6ABsBf////////YAAEAgAAwQAAwIAAv////////5AAAQQAAMIAAMEAA0P/////////2AAD0AAAyAAAxAAAhP/////////+AADgAADQAADIAAOJ//////////0AA8BwAEAAuAAAYAAAXAAAU//////////+wABBAABA4ACAAEBwAEAAuD//743/v/fG/9/743/iqqqqqqqqqqo//v/fG/9/743/v/fG//xvjf++N8b/3xvjf+/////////////++N8b/3xvjf++N8bAAMYYAACDAADBgAEAYAACDAAAxgAAwwAAzBYAAIYHAACDA4AAwGAAAXgAAJgcAACMDgAAhgAAsAgAAJgEAACMAgADAGAQAACwCAAAmAQAAEBggADwAADYIAABgEABQMAAUAAAQGQAAPAEAABAQABIAMAAYQAAQGAQAgAAcgABAEABAwCJAABBgEQAAEDAAGBAAUGCARBAwADAYgABSAAAxgAASAAAQwBAAGABgAGEAQAAYABAgACBAEABiAAAxAAAQgAAQgQAAIEAAECAAIEBAAFIAEBAAQBgAAGEAgAASAABAQCAAEIAAIH8AACQKACAAEB/OAAAgGAAAUBAAIP4AABIAFAQAABA/gABBAQAAPgAAFg5AACA4AABMAABSAAAYAAAQIABAoAAx/AAAEB4oBgAAMDgAAEwAIAARQAAz+AAAGCBQABOAAIQDgADQgABwIAAjgABCgAAg5/OAACCgAJA/gAA1AAA/4ABAcABQ4AHAcAGnAAAnAAGwOA4AABcAACcBwADQGAAAsDgOAABRwADQGAACseAA3wABQeAA0"],
    "feast":[280,
    48,
    "01AEZJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJJEkkmkkkkkkkmkkokkkkmkkkkkkkmkkkkkkkmkkkkkkkmkkkAC0MwAA8NgAFNgACwAACNgAFdgAFNgAFNgAEHAAFHAABwAADHAAFnAAFHAAFHAAVARAAGMgACAEQABAcAAUcAAUcAAQDHAAFHAAFHAAEPgAFPgAFPgAEBf4ABT4ABT4AFQoAIhQAIAgAASgAHwf38FAAGP/3/wAEOAABD6AAH8AAAkAAGOAAAQMAAwEAA0AAEz4AAj4ABQPgAgABkkAgAAL4AAP4AAP4AA0gAAECAAMEAAMQABMiAAIiAAUCIAAHiAABCAABiAIAAogADUAAAQEAAwgAAwgAAwH3wAABHcAAD0AAAQEAAwgAAwgAAwIAASAAAWAwAAoUAAIUAAUBQAACkkAAA1AEAAEQUAADUAANIAABAgADBAADEAADCAABCAABgAgAFQIAAyAAAwgAAQgAAYAIAA8YAAEMAAMBAANAAAMEAAEQAA02AAI2AAEGAAEwAAEDYP8AA38AAtgCAAEg2GAwAAHYAAU////B///B//4AAT///B8ABf//B/4AAT8H4D//B////ABIJmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZkAAIqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqgAAhVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVAACKqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqoAAIVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVQAAiqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqAACFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVUAAIqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqgAAhVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVAACKqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqoAAIMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMwAAj//H////////////////////////////////////8f//ABq"],
    "storeH":[280,
    32,
    "01/////////////////////////////////////////////////////////////////////////////////////////////wBIA/AAAj///AACB//gAAMQAAUDgAAB/8P/AAIOAAk///wAAgP/wAACAe8ABQQABwPwAAg///wAAgwAATAAAgYAAcAABAgABwbeAA0QAAEIAAIIAAEgAAQQgAAGGGfwAAL//DAAAwgAAwgAARAAAhAAARAABCEAAzwAAwfbvgAEMAACQHcCAAIwAAEMAAIgAAEIAATCAAcN9twACICAAAdAEAQAAwEEAAL8AAE/AAILDbQABAwAAgEIQAACP//EAANsAAQCCAADGAADNvtsAAIf+AAENgADD9+AAAOCAAQEMAAHDb7YAAIEEAAIBAgACAwIQAAHG2G3AAIGMAADAkEgAAIGDAADgQECAAIKAAGAAAPnAANW32wAAgNgAAMCQSAAAgMGAAOBAQIAAgURAAgtt9oAAgHAAAgBAgAIAqAABOcAAxtsNgACAcAABDYAA+/fvAADggADAUIACDbb7AACA2AAA4EIQQACfv34AANsAAMBBAAIrbb6AAIGMAAEgIAAAiBAgAACQBADwAABAggACFtthgACG+gABHcAAzBgwAACIAAEBAgABBgAA2bbfIAABwgAAxgwYAACEAAECHQACPt23wAMCBAgAAIIAAQQigAID9uwAAf///8AAn79+AACBgABwAACIQYACT7fAAL///wAB////AACAe8/wAABAgAEA//AAAMB/gAB///////////////////////v////w///////////////+H9VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf//////////////////////////////////////////////VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVUAaQ"],
    "storeF":[280,
    32,
    "01/////////////////////////////////////////////////////////////////////////////////////////////wAVAic+A8cceAAdD7YB7774AAH//////wACP///AAwgAAkCAAJ///f//gAIP8AAB9gABAHAAAMO8AAID/P8AAsPBwAEAcAAAwx4ABYOAwAhAgIABCiHIqnHHPgAAQYMAAFgwAAQAgIABD/6//666vAACAqiqAACD8A/AAw///////wACQ/z/AALAQQABD///////AACCRIAAZEgAAIP8/wAAgH/+AAH2AAEKqqqqqqoAAkP8/wACwfeAAQf//////gACQqiqAAKAgAiBQgABQPz8/Pz8AAOAf/4AAYwAQAhEAAFA/Pz8/PwABfgACLAAAUD8/Pz8/AAFgUAIgIABgPz8/Pz8AACDx4AAfHgABAQAAGAACYf//////gAAn//9//+AAI///8AAg///wAMP//////8AAL//////wAB////////////////////4AABf///////////////////////VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVX//////////////////////////////////////////////1VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVAGk"],
    "roleA":[36,
    24,
    "21AAEEAAQbAAQggAADQEAACH/AAAP/4B/gAAGAIA/AAAtAR+/QAAEggAADGwAEBAQAAgHh4AADDAAB/8ACEhAAFP/ABAABCAAHB//7//AAGv/AAAP/wAAmHgAEDAAB/8AAARIAFf/AAA8"],
    "roleB":[36,
    24,
    "21AAUf+B/4AAUgAARgAAYH4AABwAAIAYAfAYD4AAQDAAkGAAIeeAwABx54GAAJMAAGAQAC4AAHAYBAAAsB6qeAAAEABgfgABIH4AANAYAAEh54AAgeeAAcAYAAEQ"],
    "roleC":[36,
    24,
    "21AAEPgAADBwAJ8HAAAgEHCAACAgABBAACBAABAj/gCAABAQcAAQ///yAgD///BwAJH8AAAz/gAAF/4AcAAg8AASAgAAEPAAEHAAkfwAADP+AAAX/gBwAEICAAAwcABg///x/AAAYHAA4HAAY/8AADP/AAAwcAAQ///wACD///BwAJP/AAAz/wAAF/4AcAAg8ABA8AAQcACT/wAAM/8AABf+AHAAkHAAs"],
    "w1":[18,
    15,
    "21AAkD/oAC+kAG+wABDJIAATAAAsSSAAEIiID4+MAIiIAHBwAHABIEkgAEBJIAAQiIgAiIgAAM"],
    "w2":[18,
    15,
    "21AAMOAAgxwAAEH5/AAAEPgAABBgABH4AAAgYAAgEAAgEAAg+AAAEfwAASH4AABx+AABA"],
    "w3":[18,
    15,
    "28IQiAIQiAIQiAAANCEQABQhEABcAAASDogCbwgC40gD4fAAE+HoBCEQAB///AAB/AAAEB4AABA/AABw"],
    "w4":[18,
    15,
    "04A/gAAQIIAAVAAAEDGAABAfAAAQ/8AAE/vwABfx+A/AfAcAOAQAABgAAD///Af/+A"],
    "w5":[18,
    15,
    "01AAkcDgAHItEAAsAAByIAAggAAyAAA4AUP4A"],
    "w6":[18,
    15,
    "21AAfAAAFBIIAAA3ITgAQIAAEIBAACwAAEIMEABB/+AAEf/gAEABnAAAXAAA0"],
    "signed":[70,
    12,
    "08ABIIAAgIEAADAgADFDAAARAAAQ4AAyQoIGgAARIAAhwmaNiMHBQfgeBChQ2E4mRg/gABgoYCAwGIgAACgYAAAwsAAwkABAwAAwgQAAMCAAM"],
    "hall":[280,
    88,
    "02gAA1gAANAQABgAA1gAANAQALQAAFAQA/QAAFAQA9gAAUgAAPCAARA4AACCAABIAAAQgAEQeAAA8IAAKAAA4MgAAIIAAGCAARGAAGAgAMkAAOEAAiIQAGAgAFCAAGEAAOIAAiAQAMCAATgAAkQAAKQAAQQAQAA4AAAoAAFwgACUCAAAVAAANAABBABAAGQAAVIAABCAAKYAAFQAAGQAAUIDgAEAQIAAMgAAsgH7wAC0AAFBAH+AAPBAgACoAABBgAAUwAIAwAARgADAEADoAABAcAAXAAIAP34AAMAQAU/4AAIQgAVwQACyAAOQQACyAADw/8ACE//wAhcAOAACDAAAHAAB8BgAABYAAfAwEAATAAHwYDAAEYAAMf+AAaBAACCAADf/4AGwIABeAHABoIAAIEAAIBgAGAABkIAAIEAAIDAALAAAM/8AAVAoAAAwIHAAFAAAN/+AAVAQABCAACBAiAIAACAcAOABUDgBQAAgQHgCAAAgGABgAaCAmAEAACAgABAQAEP8AADgH//4AAAggCgBAAAgYfAYAAA//wABUFAAQEAgABgAACAcA4AA4B//+AAAMNgFAAAggbAAFAAAIDAAEMAA4B//+AAAMEAAEgAAIIAAJAAAIGAAEGAAMH+AAQD4AABAUABAQEAgADH/4ACAHx//+DAAkTAAGAAAIIDAEAAzgHAAc//gAE//gB//4ABA8BQAACCBABAAMgAQAGf+AB8AACAwABB/AABhEABSgABEAAAYAABD+AP/4ABP/4Af/+AAQOAAUqBQADR8CAAAMDwH/gAAYH8f/+AAogAgADhAABQAACAfw/gAAOAf/4AAQ6AASAQEAAAv4DwAAJAf/+D8AACAQABARAgAABPwH8AA4/AAEB//gABAQABAfBQA/g/gAOD8D+Af/4AAkEAAED8D8AED8D8AAFAf/gAAMDwDwP4AAR/gH/+AAJBEAD8AASA/AABQH/4AADA4A8ABcwAAIB/+AAAQH/AAf/////8AACA///8AACD///8AACP///8AAC////H/g//////wATMAABf/H/4OAB/wAH//////AAAgP/v/AAAg////AAAj////AAAv///x/4P/fz//8AFAF/8AAC4AACH/gAAhgAC0AAFQgMABEgAAQBAAcf+AACGAAGcAACF4AAAXAAAl4AAnAAAQF4AAJwAAEF4AABBwABAXgABwRwAAILgAABcAACLgABIHAAArgAAvAAAQLgAAEHAAK4AAh/////gAABf////gACf///+AACf///4AABB///+AAHBH////+AAAF////+AAJ////4AAL//3/gAAEH///4ABQcABEOAAocAA2AABocABEOAAocAApwAAEYACIMABYBgAAIcAABGAAfDAACDAAWAeAAB+AACAwAAwwAAQ"],
    "tomb":[280,
    112,
    "02AN8QABmAAB8BAAsQAAoEAA6AAB8BABYEABOAACQBACCAACQBAEuAAB////////+AABoD/////3/////gABgD//8AA4AAAn//4AAX//wBVVVVVVVVQB//gAAVH/wCqqqqqqqqqqqgH/wAFAP/BVVVVVVV1VVVVVVQf+AAEz/gqqKpVVVV1VVVSqKqg/4ADCAABQH8FVVaq////3///+qtVVQfwAARH8KopV/8AAYf/VKKofwACyAABf5VFq/wAAgH+rRVP4AACIAABgPiqtfwAAoH9aqj4AAPHxVa/AAMH61UfAAIgAAGfKpXgAAN9SqfAA4B5VLwAA4HpVPAAA0HipcAEHSo8AAMIA5UuAAQDpU4AA04hcAAEAHQjgAMIHVuABI7VwAMAcpwABIHKcAACwOUwAASAZTgAAMCAAcGLQAUWiAACwReABQ9GAADAgAHCLgAFA6AAAsZcAAUB1AACxLgABQDsAAMQAAUAQwAIhgACyWAABXWAAsggAAVjAAiGgAiFgAiDAAiGgAiFgAiDAABIAABEAAeGgEgQRCAAB0WAAGAgPCAAB0MCUDxcBAAHRqIgGDAYAAdFmBwMEGQAB0MkjHgMeAAHRp4GYAzAAGAAAEBABoWEhsAARsAAYAAHAwYDwABHAABIAABAQAaGgwOAAEOAUAAHBfsAgABBgOgABwMGgYAAQwDwAAcG+UGAAIHAB0WHoAAAgo4ABwMAUAAAhvAAQAbGgABsAACKAgAHBYAAUgAAt/QAQAbDAABNAABAyAwABwaAAEJAAEEwBAAHBcAAQUAARcAHgwAAoAUAB4bAAECwAQAHhYAAQNAIAAeDAABASAgAAQEABkaAAKwSAAeFgAC0FgABAQAGQwAAkiQAB4aAAIsoAAHwAAWFgACNyAAAfgAAz//ABcMAAIbf/8AAgP/wAABwAAWGgABAfAAAvgH/gABP/8AFxYD/gQ//wAB+AP/wAAYDfwB/EAAAgf+ABoaA/4AAUAAAvgAGxf8AAJAAAOAABoMAAICAB8aAAICAAIQAAGAABoWACIMAAMgAAEQABwaAAMgAB4WAAIBAB8MAAIBAB8aACIWACIMAAMQAB4aAAMQAAYggAAWFoIAAoAABqqAABYOqoAAAYAABooAFxgogAAI20AAFhVtgAAI20D/////////////////////////////j22AAAEIAAZRQFVVVVVVVVVVVVVVVVVVVVVVVVVVVVUbRQACCAAGjgQAFho4AAJAAAbfQqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq3fQACQABFOAAFPwAa/AABOAAGqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqoACD9VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVfwACFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVAAU"],
    "bouquet":[30,
    22,
    "21AAEIAAMcQAACHOAAAoDgAAEBwAQAAQHADgADzgACzAAEDIAAAWEZwAABMLHAAAEeYQACDN+AAAJuAAL4AAc/wAACP8AAEQBBP8AAAj/AABE"],
    "crow":[8,
    6,
    "01MEimwREE"],
    "court":[280,
    136,
    "01ABE4AEUGACEH+/AAIP9Nf4AAHh/pmZ78AB0D/TMzM1/gABx/pmZmZme/ABsP9MzMzMzM1/gAGQH+mZmZmZmZme/AABg/0zMzMzMzMzM1/gAXB/pmZmZmZmZmZmZ78AAW/0zMzMzMzMzMzMzNf4AAFB/pmZmZmZmZmZmZmZme/AATA/0zMzMzMzMzMzMzMzMzX+AAEn+mZmZmZmZmZmZmZmZmZme/ABEP9MzMzMzMzMzMzMzMzMzMzNf4AA8B/pmZmZmZmZmZmZmZmZmZmZmZ78AADj/TMzMzMzMzMzMzMzMzMzMzMzM1/gANB/pmZmZmZmZmZmZmZmZmZmZmZmZme/AADP9MzMzMzMzMzMzMzMzMzMzMzMzMzM1/gAAKH+mZmZmZmZmZmZmZmZmZmZmZmZmZmZme/AAJA/0zMzMzMzMzMzMzMzMzMzMzMzMzMzMzM1/gAAh/pmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZ78ABw/0zMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzNf4AAUB/pmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZme/AAAQ/0zMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzX+AAMH+mZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZme/AAAv9MzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzMzNf4Af6ZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZmZme/ODu7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u6DACOBQBgAAQGAAAEYAAEBgAABGAABAYAAARgAAQGAAAEYAAEBgAABGAABAYAAARgBQUAAASQAAQJAAAEkAAECQAABJAABAkAAASQAAQJAAAEkAAECQAABJAABAkAAASQAAQIgAAFCAAEEIAABQgABBCAAAUIAAQQgAAFCAAEEIAABQgABBCAAAUIAAQQgAAFCAAEEEAABgQABCBAAAYEAAQgQAAGBAAEIEAABgQABCBAAAYEAAQgQAAGBAAEIEAABgQABCAwDAAHAMAwDAAHAMAwDAAHAMAwDAAHAMAwDAAHAMAwDAAHAMAwDAAHAEAABBAABIEACBAABIEACBAABIEACBAABIEACBAABIEACBAABIEACBAABIAACCAABEIABCAABEIABCAABEIABCAABEIABCAABEIABCAABEIABCAABEAACEAABCQACkAABCQACkAABCQACkAABCQACkAABCQACkAABCQACkAABCAAEBgACYAABBgACYAABBgACYAABBgACYAABBgACYAABBgACYAABBABuCAADgAAQAgADIAAsB//+AAF//+AADgH//4Af//gABwNttAABNttAAA+22wABDbbQAAcBttgAAS22wAAPbbYAAQbbYAAHA220AAE220AAD7bbAAENttAABwLbbAABG22AAA/bbQABC22wAAcBttgAAS22wAAGGAAIbbYAAQbbYAAHA220AAE220AAD7bbAAENttAABwLbbAABG22AAAYkAAjbbQABC22wAAcBttgAAS22wAAPbbYAAQbbYAAHA220AAE220AABWBCBgAHttsAAQ220AAHAvtMAAEZb4AABRAAAQgAB9tlAAEL7TAABwGGuAABLrDAAAWIgREAB22+AAEGGuAABwNttAABNttAAAUEAAEgAAeyywABDbbQAAcCw6wAARrhgAAEAQMAAcCAAAbdfQABCw6wAAcBtlgAAS02wAAPbZYAAQbZYAAHA2G0AAE2w0AABAIAA0AABrX7AAENhtAABwLabAABGy2AAA/aLQABC2mwAAcBsdgAAS3GwAAEAf///4AABm32AAEGx2AABwNrtAABNutAAA+32wABDa7QAAcC2mwAARstgAAGGAAI2i0AAQtpsAAHAbvYAAEt7sAABoEBAAdv1gABBu9gAAcDZTQAATZTQAAGZgKAAAa02wABDZTQAAcC2+wAARvtgAAFAQABhAAH310AAQtvsAAHAaaYAAEsssAABwQAB2mmAAEGmmAABwNd9AABN91AAAUB94IAB77bAAENd9AABwL7bAABG2+AAAY2AAjTbQABC+2wAAcBttgAAS22wAAGQQAIbbYAAQbbYAAHA220AAE220AAD7bbAAENttAABwLbbAABG22AAAakgAAH220AAQttsAAHAbbYAAEttsAABqSCAAdttgABBttgAAcDbbQAATbbQAAIgAAGttsAAQ220AAHAttsAAEbbYAABkEACNttAAELbbAABwG22AABLbbAAAY2AAhttgABBttgAAcC22wAARttgAAGCAAI220AAQttsAAHBAABAgABQAABIAAFA//AAAYBAAKAEAABCAAHAgABBAABIAABQAAPgAEAAQgAARAABwEAAQgAARAAAYAABhgEAAdAAgABBAABIAAIgBAAAQgBAAgBAAcgBAABAgABQAAIQCAAAQQCAAYBTWAABxAIAAEBAAGAAAggQAABAgQABgf/4AAHCBAAAoEACRCAAAEBCAAGArKgAAcEIAACQgAJCQADkAAQAkAAAiQACQYAA2AACAYABwGAAAIYAFoIAAEQAP8AFhAAAQgAiAHgGAeAAB0P/AADP/AAhKqlVVVVVVVaqgAZHP///////////xwAGCL///////////8iABAUAAMcAANB////////////QQADHAADHAAIIgADIgACD4CqqqqqqqqqqqqA8AACIgADIgAIQQADQQARQQADQQApHAADHAADHAANHAADHAADHAAEIgABQQABIgABQQABIgANIgABQQABIgABQQABIgAEQQABIgABQQABIgABQQANQQABIgABQQABIgABQQAGFAADHAARHAADHAAHA/fAAAEDf8AADwH/wAABA//AAAMCoEEAA0EAA0EMAAE///////////wAATBBAANBIAACQQKgAAIiAAMiAAMiAA0iAAMiAAMiAAQcAAMcAAMcAA0cAAMcAAMcAAMD/8AAAQP/wAABA//AAAU/wAAEA//AAAED/8AAAQP/wAASA4AAREOAABsEAAEgAAMDwAACBAABIAAOBAABIAABBAABIAAPBAABIAABBAABIAAnBAABIAABBAABIAABBAABIAAFRAAFBAABIAABBAABIAABBAABIAASEABoKAA0"],
    "errand":[280,
    136,
    "01qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqru7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7uqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqu7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u6qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqAAEKqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqAAEO7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7uAAEG7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7uAAECqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqAAKqqqqqqqqqqgqqqqqqqqqqqgqqqqqKqqqqqoKqqqqqqqoAAu7u7u7u7u7uDu7u6u7u7u7qDu7u7o7u7u7ugu7u7u6u7gACTu7uxu7u7uQG7u7gTu7u7sAE7u7sBO7u7uwAAW7u7uQO7gACCqqqgqqqqqACqqqgCqqqqoAAAaqqqAABqqqqqAABKqqqoAqqAAMCAAMIAASAAAMgAAQgAAMCAAQCAAMIAAMCAAMIAASAAAMgAAQgAAMCAAQCAAMIALwEACGAABcEAAcIAAMMAAsCABQBgBgABgIADwwAAQIAAhAAAhgAAQMABggABgYAChgAAwIAAzAAAggQAAYGAAQMAAwGAAIwAAQCAAYYAAEMAA8QAgwAAQZgAAIgAAIYAAUwAAMCAAIIAA4MAAYwAAcIAAICAA0GCAACQAALIAADBgAODAACCAAGIAAJDAYAGCAABkAAAgwADSgADEAABggAGWAABsAAAggADWAACsAAAcAAAgQBgAAQwAALAYAEAAQBABqAAAQMAQAKBAAGgAALAQABDAABGAACAwAQCAAIEBAAAhgABAYACAwAFQQQAAJAAAgYAAcYAAgwMAACEAAEBAAQMAAIYGAAAwwAA8AACBAAFRgAAgGAAARQAAMIAAcgAAhAQAATBAASEAACAQAF8QADGAAdAeAAAzAAAgwAGwMABhgAA///////////wAAOAUYAAyAABn//////////gBAABEAADxAQABsEABMwAATAAAIIAAwwAA1gAAMBhAAPYAAEf/////////+AAAMEAAQYAAhAAAEgAAb/z//////8/8BAAAMBDAACMAAHgAAEQAACEAANDAABGAACAgABCAABQAAEwAABYAACAgAEAhCEIQhAAAUYgAADIAAGAYGAAAHAAAQwAA8QAAIGAAEYAAHAAAIDAAYGAAFgAA0RgAADDAABMAGAAAMBAALAAAIMAAJAAAoEAAEDAQACMAAHAgEAB0AAAgXve973uAAKCAABIAEAB8AAAggAAsAAAQP/////8AADDAABAgMAAUBQAAgDAAEBgAAEAYAAAuQOPfeAAAMYAAIGAAKgAAgGAAoB8B9888AAAgIABcAACwEABQkAAxAABxAAAUAEAYDAAAgEAA144AADBgAXGAACCAACPOAEAAIMAALAAAGBABIwAAIIEAADBAAEAYAABggACQQABgHz33zzwAACCAADAYAAEiAgAALnznn3gAAEAQABAwAFGAAJDAAPEAAH4DAACRgABGAAAQP/////8AAFAgIAAwMAASAADsAAAQXve973uAADMCAAAQEAAzwACxAAD2AAAQYABh8/8AAMgAAKIGAMEwADPAAB4AABD/P8ABVAwAABBgADAwMABAP4ABRgAAEIMAACCAABxAAFB/gAE8CAAAFkAAowB/AAFwEYAAJ/P+AAAg/wD/PgABCAAAJAAAIwAAEIfz/f8/AAAQ/gDcAABw3ve973vAAIAwAFP/PwIAABH+AABAEAAw3ve973vAAEAgADBiAACh3AABgP/z//8//AAAID4AADAwANBgADBAABAQAGIAAHBgANDAAFAS////////AAHBAABQwABwQADQgABmAABQMAGwGf/+P//j7/wAAaAgAFBsYAGwQABQfF8AAgD+KAACAIIXwAB////83ve973vP/////////////IAAegAf/////////////////////////////////AAAUGwG8B////////ABQgAAEcAAI+//z4ABkQP/////4AAXgAAQfwABgD////////hwACD8AAGGZmZmZmZmYAAzAAFwFVV1VFVVVUAAEgAAEIABiqsSqqqqqqA9//8AAXIVUPlUVVVVQE+P+kABiqsGqqqqqqCwaAIAAXAVRCFUVVVVQUIUBwABiqncqqqqqqKdygAAUD/8AP/wABP/wAAf/wA//AD/8AAT/8wVQgIUVVVVQCAgABAz/wA/8D/8AP/wABP/wAAf/wA//AD/8AAT/8DM0HBMzMzMwQcEAAAT/wA/8AEw/8CIH/////AAGIABnwBEARAAREARA/ABcP+EAQ/////4QBD8AAGQiAAAWIABoBBwQABBBwQAAaICAABAICABudyAAECdyAABpCEAAEBCEAGzBgAAQDBgAbD4AABfgABv///////////////////////////8Af//////wB////////ABU/4AAEA/4ABv//////////////////////////////////////////////ABYgABLAAAYwAAEIAAYgAAQIAA3AAAYwAAEIAAYCAAQIABoIIAAIgAAQeAAHCCAAAQIABoAAEHgAF4MAEyAADoMADAwABiAABwYAEwwADgYABjgAIjgAIg"],
    "carA":[50,
    23,
    "21AAID/f3wAAMEAAIIAAID+AACBAACffACAgACB/4AAwQAAQeAEAACBAADL8/z+gADU4HAAASgAAUBQAAFAoOBwAEAAgz/z/P4AAED4A////8AATz+CZmCGQABQW0Kqo2KAAKCBVVQVQABgCgGZkUFwANFj/+orAAB/UV//6ivwAABKAACBQADggACEEAAAmwAAg2AAAIQAAICAAIAAwICABMCAgASD8/z+AADE4HAAAQgAAZAAAaDgcAABP/P8/gAAhAP////AAL+CZmCGQABAW0Kqo2KAAKCBVVQVQACKAZmRQUAAQNFj/+orAABA0WAAAFosAACKAACBQADggACEEAAAQFtAAItoAAC/gACH8AAAQ"],
    "carB":[50,
    23,
    "21AAID/f3wAAMEAAIIAAID+AACBAACffACAgACB/4AAwQAAQeAEAACBAADL8/z+gADU4HAAASgAAUBQAAFAoOBwAEAAgz/z/P4AAED4A////8AATz+CZmCGQABQW0Kqo2KAAKSBVVSVQABgCgGZkUFwAM5j/+nLAAB/Tl//6cvwAABKAACBQADkgACEkAAAmwAAg2AAAIQAAICAAIAAwICABMCAgASD8/z+AADE4HAAAQgAAZAAAaDgcAABP/P8/gAAhAP////AAL+CZmCGQABAW0Kqo2KAAKSBVVSVQACKAZmRQUAAQM5j/+nLAABAzmAAAFnMAACKAACBQADkgACEkAAAQFtAAItoAAC/gACH8AAAQ"],
    "bigcar":[96,
    46,
    "21AAoHAAzAAAs8AAcP/PgABz/P8AABBwABPAAEH8AABMDAAAMf4AAFIwADD+AMAAcHz/AP8AACB/z+AAIDsAfwAAEPz/v8/hAAAgf4AAEED8/8AAQDuAAKB8AAAgP/z//8//AABQQACw////////QABTAABQgABcAABQYABAP/fH9/x//5gAAEY2ADYAABfAABQAADD6PgAAUgAAMBR/AEEAABggAFPoQQBBAAAYIABQUABxAAA/YDYAADbAAEHz//fAPgOBAEAAIP4AABHgABf/////wIA/AAAuH////////ADAADZmZmZmZmZgABEAABBAABKqqqoqrqqoAP//vAVVVVVVSNVQABJf8fICqqqqKp8KqEBAFg0FVVVVVWDVUAAQ4ChCgqqqqiqEIqgAABBTuUVVVVVVO5VQABwAABQEAqqqqihAQqgwABAg4IMzMzMyDgszAAAhEAAf////+BED/w/AiAIgAEiAIgDwPwgCH/////CAIf8AACEQAFARAAAwIOCAAEIOCAAANAQAAEBAQAAwE7kAAEE7kABIQgAAQIQgAEYMAABAYMAAQfAAUB8AACACowAAEDADoH/P4AAgOAAAMPz/v8/gAHD8/8AAQDgAAOA//P//z/8AAFA///////8AAp/3x/f8f/+AAFY2ADYAABfAAFA6PgAAkBR/AEEAABggAFDoQQBBAAAYIABQUACzYDYAADbAAFP/98A+A4EAQABR4AAX/////8AAXh////////wAAEZmZmZmZmZgADBAABKqqqoqrqqoAP//vAVVVVVVSNVQABBf8fICqqqqKp8KqABAFg0FVVVVVWDVUAAQ4ChCgqqqqiqEIqgAABBTuUVVVVVVO5VQADQEAqqqqihAQqgAABAg4IMzMzMyDgszAAAhEAAf////+BED/w/AiAIgAEiAIgAAH8CIAiAASIAiAAAxEABQEQAAMCDggABCDggAADQEAABAQEAAMFO5QABFO5QAACAoQoAAQoQoAAAgFg0AAEFg0ABJ8gAAQJ8gAC"],
    "hillsP":[280,
    38,
    "01AP8A/wCkgAAgA///ABAP/+AADT3/f+AAD///3wAEA/8P//4AAwf+AAL+AA4H8AABP/gAA/3//////gABAf/AAAIdgAANPwAD/+AAAT/+AAHwAAEB/f//+AADA+AADdgAAwff//8ABQP//gAFfAAMA+AABD//wAANHwAMHwAVA+AAC3wAFtgABD//AAQD4AAWPwADH////AADHYAAFgfwAAED/8AAAf/gAAL+ABj////gAAID/8A/4AAYD//8AAQf/98AIT/gAAyAAAFAAAEgAAEQAAEIAAEEAAECAAEBAAKAAAFAAAEgAAEQAAEIAAEEAAECAAEBAAKAgBBACCAEEAIIAQQAAYIAAUEAASCAEEAIIAQQAggBBAABggABQQABIIAEEAIIAQQAAYIAAUEAASCAEEAIIAQQAggBBAABggABQQABIIAQQAggBPv//f/+//9//7//3//v//f/+//9//7//3//v//f/+//9//7"],
    "hillsM":[280,
    38,
    "03AFIBgAAhA8AAIQfwABAGABAOeAAQDwAQHDwAEB/AAA84DwAQOeAAD3AHgAAPcPAADgHgA8AAD+A4AAYGAAcDwAAB4AAOAcAeAAYPgAAGBmAKeAAOB4APAAYfwAAGCfgW5AAHGAAGDoAHgAAFOeAABhP8faoABzwABhHAbMAABXB4AAYmH/FrgAAGfwAGJ/DbsAAF4DwABkgH6AABwAAFAeeAAAVOe/aoAAQBwB4ABpADgAMgAAUDw8AABZA/gIwABAJgX4AABAMgAAEEAdAABQeA4AAEASAPICIABATwtUAABARAAANcAAUcAAF4AAQGQAQAAQ2AAAMJ/+xwAAQIgAABAgABBgAFI4OMAAQIgAABEAVAAAMyH4MYAAQLAAQaAAVH53IABBEABGAAA0QPQGQABBQABA+AAAMBnf7dAAQWAAMBIAADiAACOwAEaAAEAsAAAwIgfAXAAAMoAARwAAIBMAABIAqAAAOIAAQDIAADDEAYAAFgAAPQAAQsAAIBQAADgAACARAAAgEAAQHQAAMRgAEBkAACARAAAggAATIAAgKAAAEQAmAAAgJgAAVcAAMSAAPwAAICIAAEFQACBQAE0AACBIAAA4AAAQYAA2wAAoAoAAIEwAACBAABBYAAARkAAggAARgAAgkABhkAA5AAAwYAAgkABmAAASIABEYAAjIABEAAAQ7AAAEDEAACQBUAAhIAAwIAAQGQAAFEAAIEAAEZAAJEAAYCoAABBGAAAwGAAAFkAAaoAAGYAAQKwAABiAAEIAACMAABCIAAAiAEQAABiAADAQACLAEgAAICAAECoAEwAAeIAAEzAAQBsAEQAAYDAkAABTACQAAEEAACNgABRAAFqAJgAAYMBIAABYwEgAAHFQGIAAUMBIAABgUZAAYyGQAIAYIwAAUiGQAHASIABhUiAAgEZEAAAxAAAQ2iAAWAAAJEAAMBAAIFxAAIAZmAAAUFRAAI5/////+2223n///////22222ff/////2223n//////+2220"],
    "bandN":[280,
    12,
    "06/////////////////////////////////////////////////////////////////////////////////////////////wAjwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwAIzDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD///////////////////////////////////////////////zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzwBpZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZY"],
    "bandR":[280,
    12,
    "06///////////////////////////////////////////////wA//gB//AD/+AH/8AAT/+AAF//AAB//gB//AD/+AH/8AP/4Af/wBHP/4AAX/8AAH/+AH/8AP/4Af/wA//gB//AAE//gABf/wAAf/4Af/wACP///////////////////////////////////////////////P8H+f4P8/wf5/g/z/B/n+D/P8H+f4P8/wf5/g/z/B/n+D/AEY/wf5/g/z/B/n+D/P8H+f4P8/wf5/g/z/B/n+D/P8H+f4P81VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV"],
    "bandH":[280,
    12,
    "04/////////////////////////////////////////////////////////////////////////////////////////////7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVUAI6qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqANI"],
    "icTape":[28,
    30,
    "21AAEEAANbQAABAYAwAAECYYgAAQQAAwjDDgABEAABAQABAYYYAAEgAAKAAwwwgAYYYYAABEwww0BYYYZAAAQwwwwAASGGGAABIAACgAMMMAABEAABAQABDhhiAAMEAAECMMgAAQGAMAAC22AAAgQABg9/3wABD3/fAAUEEEEAAQwwwwAFGGGGEAAEMMMMMAAEYYYYYAAEwwwwwIYYYYAABAwwwwABGGGGEAAEMMMMMGGGGGAABMMMMMAABIYYYYAABAwwwwAFGGGGEBBBBBAAEA"],
    "icFort":[28,
    30,
    "21AAEHAATgAAfgAAIDAA5EQERAAAiqqqqgABERAAM/gAAt7r+u4AAoEREREAAVEQADP4AALj+AAAE"],
    "icRest":[28,
    30,
    "21AAT////gf///wAAEPBBQAAEeIIwAAmGCAAEewwACFGmiAAEM66wAAQYAARAAASIQQAAFf///wPn/5+AAPAAIf///wAAEPBBQAAEeIIwAAmGCAAEewwACFGmiAAEM66wAAQYAARAAASIQQAAFf///wABA"],
    "icCreek":[28,
    30,
    "21AAEMAAczAAMMAAPAwAACMwACAwABMAACwMAAAQwAAQwAAQMAATAAATAAAQMAAQ+ONAABw//4wAABccAAAcAAAsAPjjwAATP/8wABA3HwAAEMAAEMAALAwAABAwABMAACMwADwMAAAgwAAzMAFgANDAAHMwAHwMAABQMAATAABQ+ONAABA//4AAJxwAAFD448AAED//AAAQNx8AAGwMAABjMABwwAGg"],
    "icGate":[28,
    30,
    "01MAABAoAPAAE8AAUPAAE8AAEQAAECAAXf//9gAAiP//4gAAQP//4AAQ///gBF"],
    "moon":[15,
    15,
    "02AAIMAAEYAAE0AAFgAAFIAAOIAAOEAAEGAAFD2GHwMDge8A"],
    "stars":[280,
    24,
    "08AE0BABYBABsQAA0gABkQAAUCAASAACwBABAEAAwEABoDACMEAA6AABgBAAYCAA8BABsQAA0gABggEAABEAABEAABAgAEgAAOIAAdAQAQBAADAgAIBAAaAwAHBAAbBAANCIAAAgEAARAAB4AAEgJAAAqAAAxAABIEAAYgABEIAA0hAAIyAAEQAAIBAA8IAAIgABUEAAMQAA0CAAoCABcBAAo"],
    "fire1":[8,
    7,
    "01ECRoIkKZ2w"],
    "fire2":[8,
    7,
    "02QGR8GEDZAg"],
    "trailbar":[245,
    16,
    "01AGIOAAUQAAWAAAQCABMoAAQBQAAEBQAJGAAJKAAEAUAABAUACWeAAAIKABtAAB0BwAAdDgAeEABb848888848888888s8888888888888808888888888FlFllllllllllllllllllllFllllllllllllllllliqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqo"],
    "rule":[280,
    3,
    "02//////////////////////////////////////////////8AI1VVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV"],
    "ditchbg":[280,
    160,
    "01AP8A/wARCAAcIAAFIgAciAAoNgAc2AAFFAAcUAAoCAAcIAC6IAAgB4HcABH/f3wADQICA/wADgEAAgIADQQBABD+AAIBAA0EAQAPH3wAAYCAAA0CAgAOAf+AAAIBAA0B3AP8AAwB4AQAAgEADAeAIAAQC/P8/oAACg////////wADRTgcAAgKAAiUAAOD/z////n/AANoOBwAAFAAB0DP/P8/gAMBQADFAAN+AP////AAAr/+f////P/wAAKDz+CZmCGQAAcEFtCqqNigAAK//P////5/8AACyCBVVQVQAALFAADBQAMIAoBmZFBcAAd0WP/6isADCgAAwKAAAs/UV//6ivwAB0KAAIBQAAMUAADAUAADCCAAAEEEAAeGwACA2AADKAABKAADAQAA4AAL8AABGAACg//////////8AAaD//////////wAP8A/wD/AP8AFCAACiAAF4gACogAOtgACtgAEAgABlAAClAAECIAKSAACiAAEDYAIhQARQgAqAcAInigACABpyQAIAYPXgAgCAsfgAAfGKM6QAAfLKwAAaAADAP///////wADARNAgANAf//////+AAMQDUCQAANDz574AAPViGQAA0HnvtgAA4IAAGpYAAeBRkCWKqAAAwHnPAADh8BVPKFYAAMBRx4AA4nglQiAAGoAAwDABDyFuXAELQADAGe+AANASJhJdwcAgAMCL7wgAAMAlKn0BksJQANBBAADQFY9jACAAEmgAAMCCAADkg8MgdgmwANGGAADQdQSNrFAAGbAA0wwAAOI5GIVgABLkAADBpoAA0HQVYoE0AcAA066AANB+KHKMkJOQAcBYEI2EiIgQANBBAADQNgGZNpg8rAABsCZQEbOmBKgAAKAf//////+AALBZJzdvhJAAsD///z///8AAoBgCdjIopAgAATIAAIkacHBpQIABSIAAjsBifEiBgAHQwMYgQHDgAU2AAIDsZmQAcAFVAACAExJq8coAAf4NDvQAAJf/gACSAAChoAL1VYAOYIACOAAIWAAAuAABUCIAAED4AABAIgAAUqoAADDAAtA2AACgNgABUBQAAKAUAAOYAAC4AA/wAs"],
    "holdLogo":[151,
    40,
    "02ACYf+Af+AAE//8AH/8AAAQP///AB/4A//A//AAF//+AP/+AAAQf///gD/8AgBAgBAAFAAAEgCAABIAABBAACCAIAAUAAAQEAAkGAAAEYAAcGAAIQAAKAAAEjgAABHAACCAAEBwACCCAAAgECAAIECAABJAABBAACAQADOAACB6wP/wMOAAHgAAEHAAF/wMAAAhgAAgccCqsDhgABkAABAwABasDgAAUCEAKqAAGAAAEgAAMqgCAABkABVAADFAADBQAIgAABqgABIAABCAADCoAIAAMBAAYQAAsDcAARA/AAIAIAAUAAEAeBwAAQAYEgABJAAAED8AAPKAABArAADxAAAqAADkIAA0AACYAABH8AAyAACAHAAAQ2AAEH/A/4H/AAAf+B/wACgP+AP+AAAUAACwYABTaAB/wP+B/wAAH/gf8AAQ/Q/4A/4H8AAQf8D/of8gAB/4H/IAfI/6A/4DwAARgAAgccC/8D1gABv/ADAAFvwPWBgD/8D/8D///8j////8////8j/8Af/A/+A////Qf////D////Qf+AAAQDgAABHiAABgeLgeAABEAAAgRAAAYBEAABUCAECAEgQAABKIgAA0QAAgogQoAABxEABwRAARAdUgdUgDVVYgdVVVUrVVVYgDYgAVQAAVUAAQVVRAABVVVVQFVVUQABBEACqoCqoAqqqAABqqqqqKqqqgABCoABVQABVUAFVVAAAVVVVVBVVVQAAQUAAQ"],
    "layonLogo":[199,
    40,
    "02ADIf/wAE8AABB/4B/4AAAh//4AP/AAEfwf+AP/+AAAIB+AABD/8D/8AAAj//8Af/gD/j/8AgAAGAAAIBCAABCAECAAFAAAIgAAEQBAABgCAiAAFAAAUGBgADQAABEAACwAABDAACYAADEAACIAACDgcAAyAAAQgAAQHAAAEOAAJwAAEIAAEIIAABkAACCAEAAQgAAQIAAUAAAQEAAgIEAAEQICAAAjgDgAAFDgABA4HgAAEGB/+BhwABDDjgAAIYAkAABEAGAAEBgcAAAQ4GqoHDAAEOGJAABIAABQEAASACiAABCAKqAAFAAAECAAEgAARQAAIwAAGAAcCBjxAAA1UAARAAAQGAEAAEIAACcAAB4AABwOOOIAADqgABCAABAcAIAAdAAAEgAAEgIhRAAAhAAAgBwAgAATgceIAABgOAAAkBwAQAARgccQAHAoAQAAmgCAABBAABIgAIQAABAgABQAAEAYB4HAABBwABBAAIcCgDgcAABAOAGBwAAQMAAQgACLA4AYEgAAQCAAFABAAMCAADQAAFAXgADQ4AAygABQH4AQAMBgADEAAIgAALAQACQgAEAgAQAcAAAX8ABAcAEcAAATYAAQf8AAECA////QAC/4AAAg/4AAF/wPwAAT8AAkAAAxgMD/8DgAAONoAH/AABP1/1VP+AAAH/gAACD/gAAX/A/AABPwABfwABB/yAHw/wqP8AAv+gAAIP+gABf8D8AAE/AAE8AAEYAv/AAAEJVwACAwABcAACDgb/gcMAAwGBgD////8f/q//oAf/8AACAf///q/8AAE/A//AH////x/8A/+QA//gAAIB///+Q/wAAT8B/4AABoAABwHAAAEOIAACOAOB4AAGQAAEBAAFBRAABVAgAAIBEAIEAAGABAABEgADIAABEgQEAAEFAAFCgAAFAQACQAAIAUAABAEQHVVVVK1WA1WAA1VIAAMdVUiDUgABAoA2IAFVVVUBVEBVEAABVVAAAwFVUAABVAADBEACqqqqoqqAqqAAAaqqAAMCqqoAAaqAAAGgCoABVVVVQVUAAVVAAAFVVAADAVVUAAFVAAJABQAB"],
    "ringdemo":[38,
    22,
    "01ACMZAAQkAAQkBwcAAmQPj4wwxgAEggACDDARD4+AAAFEBwcAAmwAIg"],
    "nirvana":[56,
    36,
    "21ADQQAAUD/4AABAXvQAAEBe9AAAQD/4AABSgAFEQAEw+AAAV/hAAEAdB0fwADB6gP/+AAAQP+CgPA8AABD/wCgFAfgBQFAAGgFA9wOgFAKAUAAdgCgFAKAUAkYKAUAoBQCGAoBQABoBQGEAoBQCgFBiQCgFAKAUAdAAGgFAKAXAFAKAUAAaAIB7/3/v/f4A////////AABwA7EAAFAe8ABQHvAAYQADYPgAAFUHAABAGoDH8AAwYKA8DgAAED/AKAUBAAAQQFAAGgFA8AARoBQCgFAAHQAoBQCgFAICCgFAKAUAggKAUAAaAUBBAKAUAoBQQEAoBQCgFADQABoBQCgFgBQCgFAAGgAAEHv/f+/9/gAA4"],
    "evBreak":[72,
    40,
    "21AArAABAwwAAQMAAIAwARAwARGAARGAAaIAAI0AACA/398AACiAACBAACCAACRAABA/gAAgQAAgIAAX3wAgIAAyEH/gADBAACEAeAEAACBAADgAABL8/z+gACCEAAAVOBwAADBCAAAaAABhABQAAFAhACg4HAAQACAWAM/8/z+AADg+AP////AgACPP4JmYIZHcAAAUFtCqqNiiAgAAKCBVVQVQIAAoAoBmZFBcUAAgNFj/+orAiQAAH9RX//qK/IkAACKAAC5QABBQADggADQAIYAAJsAAIBgCA8//////8D/+A7///v///9////AJQCAgAZAgIAGA/P8/gABROBwAAGIAAIQAAIg4HAAAb/z/P4AAQQD////wAE/gmZghkAAwFtCqqNigAEggVVUFUCAAMoBmZFBQUAAgNFj/+orAiAAAEDRYAAAWiwCIAAAigAAiUAAQUAA4IAA0ACAAIBbQACAaAABP4AAgPAAAQQAAICAAM"],
    "evRaccoon":[72,
    40,
    "21AFIHAAEOABDAMAAGCD/BAAEP////AA1+AAUX//6AAAUEYDIAAv//8AABEg8E8cAABAFQ+PAABQjAMAAHBwAHBAcABngD//wIAAUB//g2AA14AAIEQSAAAygCAAJBAAH///A8AAICAAU8AAM2AAKAAAEnAAMIAAEH8AAHC2gABwQQAAEPoAADD/AH/wHgAAIH4AgIAAEB/AABA/gAAQgIAAKAAA0EEAACP//+AAIDYAAB/////////////////////3//AIgH//4ABgRgMgAC///wAAECDwQABgFQ+AAHwDAABwcAAjAABQcACggACDYAEUEgAAMUAANBAAH///A8AAg8AAM2AAKAAAEUAAMIAAEH8AAHC2gABwQQAAEBQAADEAADAeAABBgMAAEB4AAEGAwAAQFAAA0EEAAHC2gABwfwAAiAAAE"],
    "evCop":[72,
    40,
    "21AFgQAAgYAgAIBgAHBAwABw4YAAgwAAcCIAACA/398AAFBAACCAAEA/gAAgQAAvsAAX3wAgIAAwMH/gADBAADB4AQAAIEAAL4AAIvz/P6AAVTgcAAAQHwAAOgAAMGDAACAUAABwKDgcABB/wAAgz/z/P4AAE4AAED4A////8AAzz+CZmCGQADQW0Kqo2KPEQAAoIFVVBVAAOAKAZmRQXAAAIDRY//qKwAA/1Ff/+or8BEAAIoAAIFAAE8EAACggACEEAABGwAAg2AAAP/////////1///7////f////8AkwICABkCAgAYD8/z+AAFE4HAAAYgAAhAAAiDgcAABv/P8/gABBAP////AAT+CZmCGQADAW0Kqo2KAASCBVVQVQAEKAZmRQUAAwNFj/+orAADA0WAAAFosAAEKAACBQAFggACEEAAAwFtAAItoAAE/gACH8AABBAAAgIABA"],
    "evSong":[72,
    40,
    "21ACo8AAgcABnwAAhwAAnADwAIBwAH4AAPAwARA4AAATAAETgAMMAACGAACNgACEQCAAcDncAABgbgIAAGRYcACIiQAAdIkAAHBwAHBCAgAAaQHcAABwIAB8AABv//1////////////////////wD/ABIHAAgIgAAHCIAABwcAOw"]};
    function defPack(host) { for (var k in PACK) { if (PACK.hasOwnProperty(k)) { host.defSprite(k, unpackArt(PACK[k][0], PACK[k][1], PACK[k][2])); } } }
    function neg(rows) { return rows.map(function (r) { return r.replace(/#/g, 'o').replace(/./g, '#'); }); }
    function scale(rows, k) {
        var out = [];
        rows.forEach(function (r) {
            var w = '', i, j;
            for (i = 0; i < r.length; i++) { for (j = 0; j < k; j++) { w += r.charAt(i); } }
            for (j = 0; j < k; j++) { out.push(w); }
        });
        return out;
    }
    function waveRows(first) {
        var a = '..##......##..', b = '.#..#....#..#.', c = '#....####....#', rows = first ? [a, b, c] : [c, b, a], out = [], i, j, s;
        for (i = 0; i < 3; i++) { s = ''; for (j = 0; j < 20; j++) { s += rows[i]; } out.push(s); }
        return out;
    }
    function defArt(host) {
        var k;
        for (k in ART2) { if (ART2.hasOwnProperty(k)) { host.defSprite(k, ART2[k]); } }
        defPack(host);
        host.defSprite('dKh', neg(ART2.dK));
        host.defSprite('dN2', scale(ART2.dN, 2)); host.defSprite('dK2', scale(ART2.dK, 2)); host.defSprite('dR2', scale(ART2.dR, 2));
        host.defSprite('tentBig', scale(ART2.tent, 2));
        host.defSprite('wave1', waveRows(true)); host.defSprite('wave2', waveRows(false));
    }

    /* ---------------- the game ---------------- */
    function start(host) {
        var S = null, mode = null, inDitch = false, tid = 0, qAt = -99999, tick = 0, qArmed = false, qId = 0, blowUntil = -1, wid = 0;
        function R(n) { return host.rand(n); }
        function W(ms) { return host.reduced ? Math.min(ms, 45) : ms; }
        function L(t, o) { o = o || {}; if (t === '') { host.blank(); } else { host.line(t, { center: o.center, inv: o.inv, hold: o.hold === undefined ? HOLD : o.hold }); } }
        function dispatch(k) {
            if (!inDitch && k === 'Q') {
                // Mid-run, one stray Q must not end the trip: ask twice.
                if (!S || S.sc || qArmed) { if (qId) { host.cancel(qId); } host.quit(); return; }
                qArmed = true;
                host.text(0, 23, pad('PRESS Q AGAIN TO QUIT.', 40), true);
                qId = host.after(2000, function () { qArmed = false; host.text(0, 23, pad('', 40), false); });
                return;
            }
            if (mode) { mode(k); }
        }
        function arm() { host.keys(dispatch); }
        function cursorTo(n) { var i; for (i = 0; i < n; i++) { host.blank(); } }
        // A grid screen: clear, park the cursor on row `cur`, then draw rows with immediate calls.
        function grid(lines, cur, m, extra) {
            mode = null;
            host.cls();
            cursorTo(cur || 0);
            host.then(function () {
                var r;
                for (r = 0; r < 24; r++) { host.text(0, r, pad(lines[r] || '', 40)); }
                mode = m || null;
                if (extra) { extra(); }
            });
        }
        function isGo(k) { return k === 'ENTER' || k === 'SPACE'; }
        function pr(next) { return function (k) { if (isGo(k)) { mode = null; next(); } }; }
        // A text page: head, wrapped paragraphs, optional footnote, PRESS RETURN.
        function page(head, text, foot, next, beepFn, artFn) {
            mode = null;
            host.cls();
            if (head) { L(head); L(''); }
            wrap(text, 39).forEach(function (t) { L(t); });
            if (foot) { L(''); L(foot); }
            if (beepFn) { beepFn(); }
            L(''); L('PRESS RETURN');
            host.then(function () { mode = pr(next); if (artFn) { artFn(); } });
        }
        // A short card that moves on by itself (no RETURN).
        function auto(head, text, ms, next, artFn) {
            mode = null;
            host.cls();
            L(head); L('');
            wrap(text, 39).forEach(function (t) { L(t); });
            if (artFn) { host.then(artFn); }
            host.wait(W(ms));
            host.then(next);
        }
        function centered(txt, ms) {
            mode = null;
            host.cls(); cursorTo(11);
            host.line(txt, { center: true, hold: 0 });
            host.wait(W(ms));
        }

        // The drive and rest cards: a car crosses the road (or a tent sits under the stars) while you watch.
        function roadStrip(label, ms, wx, rest, next, m0, info) {
            mode = null;
            host.cls(); cursorTo(10);
            if (!rest) {
                host.line('PRESS RETURN TO SIZE UP THE SITUATION', { center: true, hold: 0, nolog: true });
                cursorTo(6);
            }
            host.line(label, { center: true, hold: 0 });
            host.then(function () {
                var f = 0, n = Math.max(1, Math.round(ms / 50)), id = 0, done = false, step = rest ? 300 : 50, z = 0, rx = [40, 84, 128, 172, 216, 248];
                var hill = S.mile < 360 ? 'hillsP' : 'hillsM', bandN = rest ? 'bandN' : wx === 'RAIN' ? 'bandR' : wx === 'HOT' ? 'bandH' : 'bandN';
                var IC = ['icTape', 'icFort', 'icRest', 'icCreek', 'icGate'], nx = -1, i, wlab = rest ? (S.weather || 'UNKNOWN') : wx;
                if (!rest) { for (i = 0; i < 5; i++) { if (LM[i] > m0) { nx = i; break; } } }
                function finish() {
                    if (done) { return; }
                    done = true; host.cancel(id); mode = null;
                    host.sprites([]);
                    next();
                }
                function status() {
                    var rows = [['DATE', clock(S.hours)], ['WEATHER', wlab], ['HEALTH', hLabel(S.H)], ['NEXT STOP', S.next >= 5 ? 'HERE' : Math.max(0, LM[S.next] - S.mile) + ' MI'], ['DRIVEN', Math.min(S.mile, 720) + ' MI']], r;
                    for (r = 0; r < 5; r++) { host.text(9, 12 + r, pad(rows[r][0], 10) + lpad(rows[r][1], 13)); }
                }
                // Drive: the ground runs right while the car sits still, as the wagon did. Rest: a tent under the stars.
                function scene(fr, still) {
                    var list, ofs, m, rem;
                    if (rest) {
                        list = [{ s: 'stars', x: 0, y: 0 }, { s: 'moon', x: 40, y: 6 }, { s: hill, x: 0, y: 24 }, { s: 'bandN', x: 0, y: 62 }, { s: 'tentBig', x: 108, y: 43 }, { s: still || fr % 2 ? 'fire1' : 'fire2', x: 158, y: 55 }, { s: 'carA', x: 204, y: 41 }];
                    } else {
                        ofs = still ? 0 : (fr * 3) % 280;
                        list = [{ s: hill, x: 0, y: 24 }, { s: bandN, x: ofs, y: 62 }, { s: bandN, x: ofs - 280, y: 62 }];
                        if (nx >= 0) {
                            m = m0 + (S.mile - m0) * fr / n; rem = Math.max(0, LM[nx] - m);
                            if (rem <= 120) { list.push({ s: IC[nx], x: Math.round(176 * (1 - rem / 120)) - 28, y: 32 }); }
                        }
                        if (wx === 'RAIN') { for (i = 0; i < 6; i++) { list.push({ s: 'rain', x: rx[i], y: 8 + (fr * 6 + rx[i]) % 46 }); } }
                        if (wx === 'HOT') { list.push({ s: 'sun', x: 30, y: 6 }); }
                        list.push({ s: still || fr % 4 < 2 ? 'carA' : 'carB', x: 204, y: 41 });
                    }
                    host.sprites(list);
                }
                status();
                mode = function (k) { if (isGo(k)) { finish(); } };
                if (host.reduced) {
                    scene(n, true);
                    if (rest) { host.text(22, 11, 'zzz'); }
                    id = host.after(W(350), finish);
                    return;
                }
                scene(0, false);
                id = host.every(step, function () {
                    f++;
                    if (rest) {
                        z++; host.text(22, 11, pad('zzz'.slice(0, Math.min(3, z)), 3));
                        scene(z, false);
                        if (z >= 3) { finish(); }
                        return;
                    }
                    if (f % 5 === 0) { host.beep(S.pace === 2 ? 150 : 110, 12); }
                    scene(f, false);
                    if (f >= n) { finish(); }
                });
            });
        }

        /* ---- title, waiver, hall ---- */
        function title() {
            S = null;
            var t = [];
            t[18] = cen('THE MUSTER IS ONLY 720 MILES AWAY', 40);
            t[20] = ' 1 LOAD THE CAR      3 HALL OF LEGENDS';
            t[21] = ' 2 READ THE WAIVER   Q STAY HOME';
            t[23] = cen('YOU HAVE PLENTY OF TIME. YOU DO NOT.', 40);
            mode = null;
            host.cls();
            ['ORKEGON TRAIL', 'THE MUSTER OF A HUNDRED TARPS', 'IS ONLY 720 MILES AWAY', '1 LOAD THE CAR', '2 READ THE WAIVER', '3 THE HALL OF LEGENDS', 'Q STAY HOME', 'YOU HAVE PLENTY OF TIME. YOU DO NOT.'].forEach(function (l) { L(l); });
            host.then(function () {
                var r;
                for (r = 18; r < 24; r++) { host.text(0, r, pad(t[r] || '', 40)); }
                host.sprites([{ s: 'titlebg', x: 0, y: 0 }]);
                mode = function (k) { if (k === '1') { roleScreen(); } else if (k === '2') { waiver(); } else if (k === '3') { hallScreen(title); } };
            });
        }
        function waiver() {
            grid([
                'THE WAIVER', '', 'I, THE UNDERSIGNED, AGREE:', '',
                '1. THE DRIVE IS 720 MILES. SIGN-IN', '   CLOSES AT 10 PM FRIDAY.',
                '2. GAS, SNACKS AND ICE RUN OUT.', '   ICE RUNS OUT ANYWAY.',
                '3. IT WILL RAIN ON THE TENTS. BRING', '   A TARP. THIS IS NOT A THREAT.',
                '4. FRIENDS WHO GO TO NIRVANA ARE', '   FINE. THEY ARE JUST NOT HELPING.',
                '5. A CARPOOL OF ONE IS NOT A CARPOOL.', '   KEEP AT LEAST TWO OF YOU GOING.',
                '6. REACH SUNDAY COURT AND YOU WIN.', '', 'SIGNED,', 'YOUR PARK\'S MOST TIRED OFFICER', '', 'PRESS RETURN'
            ], 0, pr(title), function () {
                host.sprites([{ s: 'w1', x: 262, y: 32 }, { s: 'w2', x: 262, y: 48 }, { s: 'w3', x: 262, y: 64 }, { s: 'w4', x: 262, y: 80 }, { s: 'w5', x: 262, y: 96 }, { s: 'w6', x: 262, y: 112 }, { s: 'signed', x: 56, y: 122 }]);
            });
        }
        function loadHall() {
            var h = host.load('trail.hall');
            return validHall(h) ? h : SEED_HALL.map(function (e) { return e; });
        }
        function hallScreen(back) {
            var h = loadHall(), t = ['THE HALL OF LEGENDS', ''], i;
            for (i = 0; i < h.length; i++) {
                t.push(' ' + (i + 1) + '  ' + pad(clean(h[i].n, 12), 13) + lpad(Math.max(0, Math.min(9999999, Math.floor(h[i].s))), 7) + '  ' + (h[i].w ? 'ARRIVED' : 'IN NIRVANA'));
            }
            t.push(''); t.push('THE TOP FIVE. THE REST ARE IN THE'); t.push('LOST AND FOUND.'); t.push(''); t.push('PRESS RETURN');
            grid(t, 0, pr(back), function () { host.sprites([{ s: 'hall', x: 0, y: 104 }]); });
        }

        /* ---- role and names ---- */
        function roleScreen() {
            var t = [
                'WHO IS PAYING FOR THIS?', '',
                '1  OFFICE JOB              $250', '   HAS VACATION DAYS AND A', '   REAL COOLER.     SCORE X1', '', '',
                '2  GARB COMMISSIONS        $200', '   SEWS TABARDS FOR PEOPLE', '   WHO WILL PAY LATER.  SCORE X2', '', '',
                '3  COLLEGE STUDENT         $175', '   WILL PAY YOU BACK.', '   SCORE X3'
            ];
            grid(t, 0, function (k) {
                if (k === '1' || k === '2' || k === '3') { S = newGame(+k - 1, null); nameFlow(); }
            }, function () {
                host.sprites([{ s: 'roleA', x: 238, y: 16 }, { s: 'roleB', x: 238, y: 56 }, { s: 'roleC', x: 238, y: 96 }]);
            });
        }
        function nameFlow() {
            var defs = remembered || DEF_NAMES, got = [], defaultTaken = false;
            mode = null;
            host.cls();
            L('WHO IS IN THE CAR?'); L('(RETURN TAKES THE NAME SHOWN.)'); L('');
            host.then(function () { host.sprites([{ s: 'bigcar', x: 92, y: 116 }]); });
            function ask(i) {
                var seat = SEATS[i];
                if (i === 3 && defs[3].length > 11) { seat = 'BACK'; }
                host.prompt(seat + ' [' + defs[i] + ']: ', function (txt) {
                    if (i === 3) { arm(); }
                    if (txt === 'QUIT') { host.quit(); return; }
                    var nm = clean(txt, 12);
                    if (!nm) { nm = defs[i]; if (i === 3 && defs[3] === DEF_NAMES[3]) { defaultTaken = true; } }
                    got.push(nm);
                    if (i < 3) { ask(i + 1); return; }
                    remembered = got.slice();
                    S.party.forEach(function (m, j) { m.name = got[j]; });
                    if (defaultTaken) { L('THE NEW GUY BROUGHT A POLEARM.'); L('THE POLEARM GETS A SEAT.', { hold: W(700) }); }
                    host.then(function () { store(false, travel); });
                });
            }
            ask(0);
        }

        /* ---- stores ---- */
        function store(fort, done) {
            var warned = { gas: false, tarps: false }, msg = '';
            function draw() {
                var t = [], i, it, price, rowp;
                if (fort) {
                    t[4] = 'FORT GAS-MONEY TRAVEL PLAZA'; t[5] = 'A TEENAGER. DOES NOT CARE.';
                } else {
                    t[4] = 'THE BOFFER BARN & GAS-N-GARB'; t[5] = 'OWNER: GAFF. MUNDANE NAME UNKNOWN.';
                }
                t[6] = 'CASH $' + S.cash;
                t[7] = pad('', 18) + 'PRICE  HAVE' + (fort ? '' : '  FOLKS BUY');
                for (i = 0; i < 7; i++) {
                    it = ITEMS[i]; price = fort ? it.fort : it.home;
                    rowp = pad((i + 1) + ' ' + it.name, 18) + pad('$' + price, 7) + lpad(S[it.k], 4);
                    if (!fort) { rowp += lpad(it.list, 9); }
                    t[8 + i] = rowp;
                }
                if (!fort) {
                    t[15] = S.role === 0 ? '"FOLKS BUY" IS WHAT FOLKS BUY.' : '"FOLKS BUY" IS MORE THAN YOU HAVE.'; t[16] = 'GAFF KNOWS. GAFF HAS SEEN YOUR CAR.';
                    t[17] = 'KEEP $6 A HEAD FOR FEAST.';
                } else {
                    t[15] = 'THE SIGN IS, FOR ONCE, CORRECT.';
                }
                t[18] = 'PICK 1-7, RETURN TO LEAVE';
                t[19] = msg;
                grid(t, 21, key, function () { host.sprites([{ s: fort ? 'storeF' : 'storeH', x: 0, y: 0 }]); });
            }
            function key(k) {
                var n = +k, it, have, price;
                if (k === 'ENTER') { leave(); return; }
                if (!/^[1-7]$/.test(k)) { return; }
                it = ITEMS[n - 1]; have = S[it.k]; price = fort ? it.fort : it.home;
                mode = null;
                host.prompt('HOW MANY? ', function (txt) {
                    arm();
                    if (txt === 'QUIT') { host.quit(); return; }
                    var v, cost, total;
                    if (!/^\d{1,3}$/.test(txt)) { bad(txt !== ''); return; }
                    v = +txt;
                    if (fort) {
                        total = have + v; cost = v * price;
                        if (total > 99 || cost > S.cash) { bad(true); return; }
                        S.cash -= cost; S[it.k] = total;
                    } else {
                        total = Math.max(FREE[it.k] || 0, v); cost = (total - have) * price;
                        if (total > 99 || cost > S.cash) { bad(true); return; }
                        S.cash -= cost; S[it.k] = total;
                    }
                    host.beep(880, 30);
                    msg = ''; draw();
                    });
            }
            function bad(scold) {
                host.beep(200, 120);
                msg = scold ? 'GAFF LOOKS AT YOUR WALLET, THEN AT YOU.' : '';
                if (fort && scold) { msg = 'THE TEENAGER SHRUGS.'; }
                draw();
            }
            function leave() {
                if (!fort && S.gas === 0 && !warned.gas) { warned.gas = true; msg = 'GAFF: THE CAR RUNS ON GAS, FRIEND.'; draw(); return; }
                if (!fort && S.tarps === 0 && !warned.tarps) { warned.tarps = true; msg = 'GAFF: NO TARP? I WAS BRAVE ONCE TOO.'; draw(); return; }
                mode = null;
                if (!fort) {
                    host.cls(); cursorTo(11);
                    host.line('GAFF WAVES. HE HAS SEEN THIS BEFORE.', { center: true, hold: 0 });
                    host.wait(W(700));
                }
                host.then(done);
            }
            draw();
        }

        /* ---- travel ---- */
        function travel() {
            var flashed = false;
            function rows() {
                var t = [], miles = Math.min(S.mile, 720), i, names, nm, wr;
                t[0] = pad('ORKEGON TRAIL', 27) + lpad(clock(S.hours), 12);
                                t[2] = pad('WEATHER  ' + (S.weather || 'UNKNOWN'), 22) + 'HEALTH   ' + hLabel(S.H);
                t[3] = 'NEXT     ' + LM_NAME[S.next] + ', ' + Math.max(0, LM[S.next] - S.mile) + ' MI';
                t[4] = 'TOTAL    ' + miles + ' OF 720 MI';
                t[7] = pad('GAS ' + S.gas + ' GAL', 13) + pad('SNACKS ' + S.snacks, 11) + 'ICE ' + S.ice;
                t[8] = pad('TAPE ' + S.tape, 13) + pad('BOFFERS ' + S.boffers, 13) + 'GARB ' + S.garb;
                t[9] = pad('TARPS ' + S.tarps, 13) + 'CASH $' + S.cash;
                names = [];
                for (i = 0; i < 4; i++) { if (!S.party[i].out) { names.push(S.party[i].name); } }
                wr = [];
                names.forEach(function (n, j) {
                    var piece = n + (j < names.length - 1 ? ',' : ''), last = wr.length - 1;
                    if (last >= 0 && (wr[last] + ' ' + piece).length <= 28) { wr[last] += ' ' + piece; } else { wr.push(piece); }
                });
                t[11] = 'IN THE CAR  ' + (wr[0] || ''); t[12] = '            ' + (wr[1] || ''); if (wr[2]) { t[13] = '            ' + wr[2]; }
                choiceRows(S, t);
t[18] = '1 DRIVE ON         2 CHANGE PACE';
t[19] = '3 CHANGE RATIONS   4 REST 2 HOURS';
if (S.ditches < 2) { t[20] = '5 DITCH FOR SNACKS'; }
t[22] = 'Q QUIT TO THE GAMES MENU';
                nm = t;
                return nm;
            }
            function redrawChoices() {
                var t = choiceRows(S, []), r;
                for (r = 14; r <= 16; r++) { host.text(0, r, pad(t[r], 40)); }
            }
            grid(rows(), 0, function (k) {
                if (k === 'ENTER' || k === '1') { drive(); } else if (k === '2') {
                    S.pace = (S.pace + 1) % 3; redrawChoices();
                } else if (k === '3') {
                    S.rat = (S.rat + 1) % 3; redrawChoices();
                } else if (k === '4') { rest(); } else if (k === '5' && S.ditches < 2) {
                    mode = null; ditch(false, travel);
                }
            }, function () {
                // The car rides the trail bar from the right (home) to the left (the gate); the posts mark the five stops.
                host.sprites([{ s: 'rule', x: 0, y: 10 }, { s: 'trailbar', x: 7, y: 40 }, { s: 'car', x: 7 + Math.floor((1 - Math.min(S.mile, 720) / 720) * 219), y: 44 }]);
                if (S.H < 20 && !host.reduced && !flashed) {
                    flashed = true;
                    host.text(22, 2, 'HEALTH   VERY POOR', true);
                    host.after(450, function () { host.text(22, 2, 'HEALTH   VERY POOR', false); });
                }
            });
        }
        function rest() {
            mode = null;
            restTurn(S, R);
            roadStrip('RESTING...', 900, null, true, function () {
                flushGone(function () { if (S.lost) { ending(); } else { travel(); } });
            });
        }
        var EVART = { FLAT: 'evBreak', BELT: 'evBreak', RACCOON: 'evRaccoon', COP: 'evCop', SONG: 'evSong' };
        function drive() {
            var info, m0;
            mode = null;
            m0 = S.mile;
            info = driveTurn(S, R);
            roadStrip('DRIVING...', [1200, 1000, 800][S.pace], info.weather, false, function () {
                function afterGas() {
                    var lms = info.lms.slice();
                    function nextLm() {
                        var i;
                        if (S.lost) { endTurn(); return; }
                        if (!lms.length) { afterLms(); return; }
                        i = lms.shift();
                        landmark(i, info, function () { flushGone(nextLm); });
                    }
                    function afterLms() {
                        var ev;
                        if (!info.lms.some(function (i) { return i >= 3; }) && R(100) < 50) {
                            ev = randomEvent(S, R);
                            if (ev) {
                                page(clock(S.hours), ev.t, ev.foot, endTurn, function () {
                                    if (ev.id === 'GIFT' || ev.id === 'POUCH' || ev.id === 'SONG') { host.beep(659, 60); host.beep(880, 60); } else if (ev.id !== 'LIGHT') { host.beep(196, 90); }
                                }, EVART[ev.id] && wrap(ev.t, 39).length + (ev.foot ? 2 : 0) + 4 <= 14 ? function () { host.sprites([{ s: EVART[ev.id], x: 104, y: 120 }]); } : null);
                                return;
                            }
                        }
                        endTurn();
                    }
                    nextLm();
                }
                if (info.gasOut) {
                    if (info.gasOut.buy > 0) {
                        page(clock(S.hours), 'THE TANK IS DRY. A PASSING CARPOOL SELLS YOU ' + info.gasOut.buy + ' GALLONS AT $5, BECAUSE THEY ARE KIND BUT NOT THAT KIND.', 'CASH $' + S.cash, afterGas);
                    } else {
                        page(clock(S.hours), 'THE TANK IS DRY AND SO IS THE WALLET. YOU PUSH. ' + pickIn(S, R) + ' STEERS AND CALLS IT LEADERSHIP.', null, afterGas);
                    }
                } else { afterGas(); }
            }, m0, info);
        }
        function endTurn() {
            if (!S.lost) { goneRoll(S, R); }
            flushGone(function () {
                gateCheck(S);
                if (S.lost) { ending(); } else if (S.next >= 5) { weekend(); } else { travel(); }
            });
        }
        function flushGone(done) {
            if (!S.goneQ.length) { done(); return; }
            var txt = [], g;
            while (S.goneQ.length) { g = S.goneQ.shift(); txt.push(g.l1 + '\n' + g.l2); }
            if (S.lost === 'party') {
                txt.push('THAT LEAVES ONLY ' + S.party[0].name + '. ONE PERSON IN A CAR IS NOT A CARPOOL. IT IS AN ERRAND. YOU TURN AROUND.');
                S.partyShown = true;
            }
            page(clock(S.hours), txt.join('\n\n'), null, done, function () { host.beep(330, 120); host.beep(220, 200); }, wrap(txt.join('\n\n'), 39).length + 4 <= 14 ? function () { host.sprites([{ s: 'nirvana', x: 112, y: 132 }]); } : null);
        }

        /* ---- landmarks ---- */
        function landmark(i, info, done) {
            if (i < 4) { host.beep(523, 60); host.beep(659, 60); host.beep(784, 90); }
            if (i === 0) {
                S.H += 4; clampH(S);
                auto('WORLD\'S LARGEST BALL OF DUCT TAPE', '40 FEET ACROSS. HOLDS NOTHING TOGETHER. YOU TAKE A PHOTO AND FEEL BETTER.', 2200, done, function () {
                    host.sprites([{ s: 'lmTape', x: 0, y: 56 }]);
                });
            } else if (i === 1) {
                auto('FORT GAS-MONEY', 'THE SIGN SAYS LAST GAS FOR 200 MILES. THE SIGN HAS SAID THAT SINCE 1987.', 1900, function () { store(true, done); }, function () {
                    host.sprites([{ s: 'lmFort', x: 0, y: 56 }]);
                });
            } else if (i === 2) {
                if (S.ditches === 0) { ditch(true, done); } else {
                    auto('REST AREA 44', 'THE RIVALS HAVE LEFT. THEY TOOK THE PICNIC TABLE.', 2000, done, function () { host.sprites([{ s: 'lmRest', x: 0, y: 56 }]); });
                }
            } else if (i === 3) { creek(done); } else { done(); }
        }
        function creek(done) {
    var D = creekDepth(S, R), waits = 0;
    function stopWaves() { if (wid) { host.cancel(wid); wid = 0; } }
    function show() {
        mode = null;
        stopWaves();
                host.cls();
                L('MAYBE CREEK                 MILE 600'); L('');
                wrap('THE ROAD TO CAMP GOES THROUGH THE CREEK. THE SIGN READS ROAD MAY FLOOD. THE ROAD HAS.', 39).forEach(function (t) { L(t); });
                L(''); L('DEPTH TODAY: ' + D + ' FT, GIVE OR TAKE'); L('');
                L('1  FORD IT. THE CAR IS NOT A FORD.'); L('   THIS DOES NOT MATTER.');
                if (S.tarps > 0 && S.tape > 0) { L('2  LAY THE TARP, TAPE IT DOWN, CREEP'); }
                if (S.cash >= 30) { L('3  PAY THE FARMER\'S TRACTOR $30'); }
                if (waits < 2) { L('4  WAIT 2 HOURS FOR IT TO DROP'); }
                host.then(function () {
    var fr = 0;
    function waves(car) {
        var l = [{ s: 'creek', x: 0, y: 104 }, { s: 'mark', x: 142, y: 150 - 5 * D }, { s: fr ? 'wave2' : 'wave1', x: 0, y: 170 }, { s: fr ? 'wave1' : 'wave2', x: 0, y: 178 }];
        if (car) { l.push(car[0]); if (car[1]) { l.push(car[1]); } }
        host.sprites(l);
    }
    waves();
    if (!host.reduced) { wid = host.every(400, function () { fr = 1 - fr; waves(); }); }
    mode = function (k) {
        var res, txt;
                        if (k === '1' || (k === '2' && S.tarps > 0 && S.tape > 0) || (k === '3' && S.cash >= 30)) {
                            mode = null;
                            stopWaves();
                            res = river(S, D, +k, R);
                            txt = {
                                '1': 'THE CAR ENTERS AS A CAR AND LEAVES AS A CAR. YOU DO NOT DISCUSS THE MIDDLE PART.',
                                '1F': 'THE ENGINE COUGHS. THE COOLER FLOATS AWAY, SLOWLY, WITH DIGNITY. A TRACTOR COMES ANYWAY. THE FARMER SAYS NOTHING. THE FARMER HAS BEEN WAITING ALL DAY.',
                                '2': 'THE TARP HOLDS. YOU DRIVE ACROSS A BLUE ROAD OF YOUR OWN MAKING. NOBODY WILL BELIEVE THIS.',
                                '2F': 'THE TARP LEAVES. THE TAPE STAYS. YOU GET ACROSS, MOSTLY BY ARGUING.',
                                '3': 'THE FARMER TAKES THE $30 AND ASKS IF THE SWORDS ARE REAL. YOU SAY THEY ARE FOAM. THE FARMER LOOKS SAD.'
                            }[res.key];
                            if (res.tarpLost && res.key === '2') { txt += '\nTHE TARP GOES ON DOWNSTREAM. SOMEONE ELSE WILL BE DRY.'; }
                            function report() {
                                page(null, txt, null, done, function () {
                                    if (res.ok) { host.beep(523, 60); host.beep(784, 80); } else { host.beep(220, 120); host.beep(165, 200); }
                                });
                            }
                            if (host.reduced) { report(); } else {
                                // The car crosses the creek in eight steps; on a failure it stops midstream and the cooler floats off.
                                var st = 0, cid = host.every(110, function () {
                                    var cx = res.ok ? Math.round(230 - 26.25 * st) : Math.max(120, 230 - 42 * st);
                                    st++;
                                    waves([{ s: 'carA', x: cx, y: 134 }, !res.ok && st >= 4 ? { s: 'cooler', x: 150 + 6 * (st - 4), y: 150 } : null]);
                                    if (st >= 8) { host.cancel(cid); report(); }
                                });
                            }
                        } else if (k === '4' && waits < 2) {
                            mode = null;
                            stopWaves();
                            waits++; S.hours += 2; D = Math.max(2, D - 1);
                            page(null, 'YOU THROW ROCKS AT THE CREEK. IT DROPS A FOOT. YOU TAKE THE CREDIT.', null, show);
                        }
                    };
                });
            }
            show();
        }

        /* ---- the ditch ---- */
        function ditch(atRest, done) {
            var D;
            mode = null;
            host.cls();
            host.then(function () {
                host.sprites([{ s: 'dN2', x: 4, y: 64 }, { s: 'dK2', x: 4, y: 88 }, { s: 'dR2', x: 4, y: 112 }, { s: 'ringdemo', x: 238, y: 138 }]);
            });
            L(atRest ? 'REST AREA 44                MILE 450' : 'A PARKING LOT, MILE ' + Math.min(S.mile, 720)); L('');
            if (atRest) { L('A RIVAL CARPOOL IS LUNCHING AT THE'); L('PICNIC TABLE, WITH A LOT OF SNACKS.'); L(''); L('THEIR LEADER RAISES A FOAM SWORD.'); L('LOSERS BUY LUNCH.'); } else {
                L('YOU FIND A CARPOOL AND STARE UNTIL IT'); L('DRAWS.'); L(''); L('THEIR LEADER RAISES A FOAM SWORD.'); L('LOSERS BUY LUNCH.');
            }
            L('');
            L('    NEWBIE. ONE HIT.'); L('    WORTH 1 BAG.'); L('');
            L('    KNIGHT. TWO HITS.'); L('    WORTH 3 BAGS.'); L('');
            L('    REEVE. DO NOT'); L('    HIT THE REEVE.'); L('');
            L('SWING W A S D OR THE ARROW KEYS.'); L('SWING WHEN THEY REACH THE RINGS.'); L('THE TRUNK HOLDS 12 BAGS.');
            if (S.boffers === 0) { L('NO BOFFERS. YOU HAVE A POOL NOODLE.'); } else { L(''); }
            L(''); L('PRESS RETURN');
            host.then(function () {
                mode = pr(function () {
                    mode = null;
                    host.cls(); cursorTo(11);
                    host.line('HOLD!', { center: true, hold: 0 });
                    host.then(function () { host.fill(0, 11, 40, 1, ' '); host.sprites([{ s: 'holdLogo', x: 64, y: 76 }]); });
                    host.wait(W(600));
                    host.cls(); cursorTo(11);
                    host.beep(1200, 80);
                    host.line('LAY ON!', { center: true, hold: 0 });
                    host.then(function () { host.fill(0, 11, 40, 1, ' '); host.sprites([{ s: 'layonLogo', x: 40, y: 76 }]); });
                    host.wait(W(500));
                    host.cls();
                    host.then(live);
                });
            });
            // Pixel centre of step pp (1 = beside you, 8 = far end) along a lane.
            function laneAt(l, pp) {
                if (l === 0) { return [144, (12 - pp) * 8 + 4]; }
                if (l === 1) { return [144, (12 + pp) * 8 + 4]; }
                if (l === 2) { return [(20 - 2 * pp) * 7 + 4, 100]; }
                return [(20 + 2 * pp) * 7 + 4, 100];
            }
            function at(name, w, h, c) { return { s: name, x: Math.round(c[0] - w / 2), y: Math.round(c[1] - h / 2) }; }
            function draw() {
                var l, pp, i, e, list = [{ s: 'ditchbg', x: 0, y: 12 }], left = 4 - D.blows, c;
                host.fill(0, 0, 40, 24, ' ');
                host.text(0, 0, pad('BAGS ' + Math.max(0, Math.min(12, D.bags)), 10) + pad('BLOWS ' + left, 11) + pad('SWINGS ' + D.swings, 13) + lpad(Math.ceil((15000 - D.t) / 1000), 2) + ' S');
if (D.t < blowUntil && !host.reduced) { host.text(10, 0, pad('BLOWS ' + left, 11), true); }
                for (l = 0; l < 4; l++) {
                    for (pp = 1; pp <= 8; pp++) { list.push(pp <= 4 ? at('ring', 5, 5, laneAt(l, pp)) : at('dot', 2, 2, laneAt(l, pp))); }
                }
                host.put(20, 3, 'W'); host.put(20, 21, 'S'); host.put(2, 12, 'A'); host.put(38, 12, 'D');
                list.push(at('dP', 9, 8, [144, 100]));
                for (i = 0; i < D.ents.length; i++) {
                    e = D.ents[i];
                    // Drawn between steps so they glide; the rules still use whole steps.
                    list.push(at(e.ty === 'K' && e.hp < 2 ? 'dKh' : 'd' + e.ty, 9, 8, laneAt(e.l, e.p - e.acc / STEP[e.ty])));
                }
                for (i = 0; i < D.fx.length; i++) {
                    if (D.fx[i].until <= D.t) { continue; }
                    if (D.fx[i].sl) {
                        list.push(D.fx[i].l < 2 ? at('slashV', 3, 11, laneAt(D.fx[i].l, 1)) : at('slashH', 11, 3, laneAt(D.fx[i].l, 1)));
                    } else { c = laneAt(D.fx[i].l, D.fx[i].p); list.push(at('burst', 7, 7, c)); }
                }
                host.sprites(list);
                if (D.t - D.msgT > 2000) { D.msg = ''; }
                host.text(0, 22, D.msg || 'THE REEVE IS WATCHING.');
                host.text(0, 23, 'WASD OR ARROWS    Q TWICE TO QUIT');
            }
            function finish() {
                var res;
                host.cancel(tid); inDitch = false; mode = null;
                host.beep(1200, 80); host.beep(1200, 80);
                res = ditchFinish(S, D);
                host.then(function () {
                    page(atRest ? 'REST AREA 44' : 'A PARKING LOT', (D.reason === 'swings' ? 'YOUR BOFFER HAS GIVEN UP.\n' : '') + res.text, 'BAGS WON: ' + res.bags + '   SNACKS NOW: ' + S.snacks, done);
                });
            }
            function live() {
                D = ditchNew(S.boffers);
                tick = 0; qAt = -99999; blowUntil = -1;
                inDitch = true;
                draw();
                tid = host.every(100, function () {
                    var i;
                    ditchTick(D, R);
                    if (D.blow) { host.beep(160, 80); blowUntil = D.t + 300; }
                    if (!D.over && D.t >= 10000 && D.t % 1000 === 0) { host.beep(880, 15); }
                    for (i = D.fx.length - 1; i >= 0; i--) { if (D.fx[i].until <= D.t) { D.fx.splice(i, 1); } }
                    draw();
                    if (D.over) { finish(); }
                });
                mode = function (k) {
                    var lane = { W: 0, UP: 0, S: 1, DOWN: 1, A: 2, LEFT: 2, D: 3, RIGHT: 3 }[k], r;
                    if (D.over) { return; }
                    if (k === 'Q') {
                        if (D.t - qAt <= 2000) { host.cancel(tid); inDitch = false; host.quit(); return; }
                        qAt = D.t; dmsg(D, 'PRESS Q AGAIN TO QUIT.'); draw();
                        return;
                    }
                    if (lane === undefined) { return; }
                    r = ditchSwing(D, lane);
                    D.fx.push({ l: lane, sl: true, until: D.t + 120 });
                    if (r) {
                        D.fx.push({ l: lane, p: r.p, until: D.t + 120 });
                        if (r.ty === 'R') { host.beep(90, 200); } else if (r.ty === 'K') {
                            if (r.dead) { host.beep(900, 40); host.beep(1200, 40); } else { host.beep(500, 40); }
                        } else { host.beep(700, 30); }
                    } else { host.beep(300, 25); }
                    draw();
                    if (D.over) { finish(); }
                };
            }
        }

        /* ---- weekend ---- */
        function weekend() {
    var late = S.hours > 16, t = arrival(S, R), txt = fridayNight(S, R), body = t + '\n\n' + txt;
    page('THE MUSTER OF A HUNDRED TARPS', body, null, function () {
        flushGone(function () { if (S.lost) { ending(); } else { saturday(); } });
    }, late ? function () { host.beep(196, 160); } : null, wrap(body, 39).length + 4 <= 15 ? function () {
        host.sprites([{ s: 'muster', x: 0, y: 124 }, { s: 'car', x: 96, y: 166 }]);
    } : null);
        }
        function saturday() {
            var sat = saturdayPrep(S, R), t = [];
            t[0] = satHead(S.H);
            t[2] = 'WEAPONS CHECK. A REEVE BENDS EACH';
            t[3] = 'BOFFER AND MAKES A SMALL NOISE.';
            t[5] = 'PASSED: ' + sat.passed + ' OF ' + sat.total;
            if (sat.passed === 0) { t[6] = 'NOTHING PASSES. THE REEVE HANDS BACK'; t[7] = 'YOUR FOAM WITH GREAT TENDERNESS.'; }
            if (sat.hot) { t[sat.passed === 0 ? 9 : 7] = 'THE SUN IS OUT. SO IS THE PERSON IN'; t[sat.passed === 0 ? 10 : 8] = 'FULL PLATE.'; }
            t[12] = 'HOW DO YOU SPEND THE DAY?';
            if (sat.passed > 0) { t[14] = '1  FIGHT IN THE BIG BATTLE'; }
            t[15] = '2  DITCH A LITTLE, WATCH THE REST';
            t[16] = '3  NAP IN A HAMMOCK ON PRINCIPLE';
            grid(t, 0, function (k) {
                if ((k === '1' && sat.passed > 0) || k === '2' || k === '3') {
                    mode = null;
                    var res = saturdayDo(S, +k, R);
                    flushGone(function () { if (S.lost) { ending(); } else { feast(res.text + (res.extra ? '\n' + res.extra : '')); } });
                }
            }, function () { host.sprites([{ s: 'battle', x: 0, y: 144 }]); });
        }
        function feast(satText) {
            var ticket = 6 * S.alive, can = S.cash >= ticket, t = [];
            var sw = wrap(satText, 39), i;
            t[0] = 'SATURDAY';
            for (i = 0; i < sw.length; i++) { t[2 + i] = sw[i]; }
            i = 3 + sw.length;
            t[i] = 'SATURDAY NIGHT: FEAST'; t[i + 1] = 'CASH $' + S.cash;
            if (can) { t[i + 3] = '1  BUY FEAST TICKETS, $' + ticket; }
            t[i + 4] = '2  EAT SNACKS IN THE TENT';
            grid(t, 0, function (k) {
                if ((k === '1' && can) || k === '2') {
                    mode = null;
                    var txt = feastDo(S, +k, R);
                    var COURT = 'COURT RUNS LONG. THE MONARCH THANKS THE REEVES, THE COOKS, AND ANYONE STILL AWAKE. YOUR ATTENDANCE GOES IN THE ORK. PROBABLY.\nA KID GETS THEIR FIRST AWARD AND CARRIES IT TO THE CAR LIKE GLASS.';
                    if (S.goneQ.length || S.lost) {
                        page('SATURDAY NIGHT', txt, null, function () {
                            flushGone(function () {
                                if (S.lost) { ending(); return; }
                                sundayDo(S, R);
                                flushGone(function () { if (S.lost) { ending(); } else { page('SUNDAY: COURT', COURT, null, ending); } });
                            });
                        });
                    } else {
                        sundayDo(S, R);
                        if (S.goneQ.length || S.lost) {
                            page('SATURDAY NIGHT', txt, null, function () { flushGone(function () { if (S.lost) { ending(); } else { page('SUNDAY: COURT', COURT, null, ending); } }); });
                        } else {
                            page('SATURDAY NIGHT', txt + '\n\n' + COURT, null, ending);
                        }
                    }
                }
            }, i + 4 <= 16 ? function () { host.sprites([{ s: 'feast', x: 0, y: 144 }]); } : null);
        }

        /* ---- endings ---- */
        function ending() {
            if (S.lost === 'party') {
                if (S.partyShown) { errandCard(); } else { page(null, 'THAT LEAVES ONLY ' + S.party[0].name + '. ONE PERSON IN A CAR IS NOT A CARPOOL. IT IS AN ERRAND. YOU TURN AROUND.', null, errandCard); }
            } else if (S.lost === 'gate') {
                page(null, 'IT IS SATURDAY MORNING AND YOU ARE STILL DRIVING. THE MUSTER HAS STARTED WITHOUT YOU. THE ORK WILL NEVER KNOW.', null, errandCard);
            } else { endCard('court', 'SUNDAY COURT. YOU ARE STILL HERE.', tomb); }
        }
        function errandCard() { endCard('errand', S.lost === 'gate' ? 'THE GATE WAS CLOSED.' : 'ONE PERSON, ONE CAR.', tomb); }
        // A picture card (rows 0-16 are art) with one caption below it.
        function endCard(name, text, next) {
            mode = null;
            host.cls(); cursorTo(18);
            L(text); L(''); L('PRESS RETURN');
            host.then(function () { host.sprites([{ s: name, x: 0, y: 0 }]); mode = pr(next); });
        }
        function tomb() {
            var won = !S.lost, sc = score(S), hall, ins, epi = won ? 'DAMP BUT WORTH IT' : S.lost === 'party' ? 'ONE PERSON, ONE CAR' : 'THE GATE WAS CLOSED';
            S.sc = sc;
            hall = hallInsert(loadHall(), { n: clean(S.party[0].name, 12) || 'DRIVER', s: sc.final, w: won });
            ins = hall.inserted; S.entry = ins;
            host.save('trail.hall', hall.hall);
            function stone(e) {
                var t = [], dn = S.party[0].name;
                t[4] = pad('', 7) + cen('HERE LIES THE', 25);
                t[5] = pad('', 7) + cen((won ? 'WEEKEND OF ' : 'CARPOOL OF ') + dn, 25);
                t[7] = pad('', 7) + cen(e, 25);
                t[9] = pad('', 7) + cen(won ? 'SURVIVED THE MUSTER' : 'LASTED ' + Math.min(S.mile, 720) + ' MILES', 25);
                t[14] = '    SCORE ' + sc.final;
                t[16] = 'EPITAPH? (RETURN FOR THE USUAL)';
                return t;
            }
            grid(stone(epi), 17, null, function () {
                var art = [{ s: 'tomb', x: 0, y: 0 }];
                if (won) { host.beep(523, 100); host.beep(659, 100); host.beep(784, 100); host.beep(1047, 220); } else { host.beep(220, 200); host.beep(196, 200); host.beep(147, 400); }
                art.push(won ? { s: 'bouquet', x: 210, y: 84 } : { s: 'crow', x: 262, y: 74 });
                host.sprites(art);
                host.prompt('> ', function (txt) {
                    arm();
                    if (txt === 'QUIT') { host.quit(); return; }
                    var e = clean(txt, 23) || epi;
                    host.text(7, 7, cen(e, 25));
                    host.wait(W(1100));
                    host.then(scoreScreen);
                });
            });
        }
        function scoreScreen() {
            var sc = S.sc, t = ['THE REEVE\'S TALLY', ''], i, r, gone = [];
            for (i = 0; i < sc.rows.length; i++) {
                r = sc.rows[i];
                t.push(pad(r[0], 15) + pad(r[1], 14) + lpad(r[2], 6));
            }
            t.push(lpad('-----', 35));
            t.push(pad('SUBTOTAL', 29) + lpad(sc.base, 6));
            t.push(pad(ROLES[S.role].label, 29) + lpad('X ' + sc.mult, 6));
            t.push(pad('FINAL', 29) + lpad(sc.final, 6));
            t.push('');
            t.push('RANK: ' + rankOf(sc.final));
            t.push('');
            t.push(S.entry ? 'NEW ENTRY IN THE HALL OF LEGENDS.' : '');
            t.push('');
            t.push('1 GO AGAIN   2 THE HALL   Q GO HOME');
            t.push('');
            S.party.forEach(function (m) { if (m.out) { gone.push(m.name + ': ' + m.cause); } });
            if (gone.length) { t.push('IN NIRVANA'); gone.slice(0, 3).forEach(function (g) { t.push('  ' + g); }); }
            grid(t, 0, function (k) {
                if (k === '1') { roleScreen(); } else if (k === '2') { hallScreen(scoreScreen); }
            }, function () { host.sprites([{ s: 'rule', x: 0, y: 10 }]); });
        }

        dbg = { state: function () { return S; }, qArmed: function () { return qArmed; }, ready: function () { return !!mode; } };
        defArt(host);
        arm();
        title();
    }

    window.OrkpleGames.trail = {
        title: 'ORKEGON TRAIL',
        start: start,
        _dbg: function () { return dbg; },
        _test: {
            choiceRows: choiceRows, satHead: satHead, pickIn: pickIn,
            ROLES: ROLES, ITEMS: ITEMS, PACE: PACE, RAT: RAT, newGame: newGame, upkeep: upkeep, driveTurn: driveTurn, restTurn: restTurn,
            goneRoll: goneRoll, gateCheck: gateCheck, randomEvent: randomEvent, creekDepth: creekDepth, river: river, ditchNew: ditchNew, ditchTick: ditchTick, ditchSwing: ditchSwing,
            ditchFinish: ditchFinish, arrival: arrival, fridayNight: fridayNight, saturdayPrep: saturdayPrep, saturdayDo: saturdayDo, feastDo: feastDo, sundayDo: sundayDo,
            score: score, hallInsert: hallInsert, validHall: validHall, clock: clock, wrap: wrap, hLabel: hLabel, LM: LM
        }
    };
}());
