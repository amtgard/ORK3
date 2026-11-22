// The store against a fake schedule_batch server: request tokens and held
// requests, `seen` / `gone` / `conflict`, sync, maxOps and the change counter.
const test = require('node:test');
const assert = require('node:assert/strict');
const S = require('../../orkui/template/revised-frontend/script/event-board/evb-store.js');

// A fake server: it keeps rows with a Modified stamp, applies a token once (a
// repeat answers with the stored results), honours `seen` (conflict) and
// answers `gone` for a missing row.
function server(rows) {
  const db = new Map();
  let nextId = 100, clock = 0;
  const stamp = () => '2026-11-16 10:' + String(Math.floor(clock / 60)).padStart(2, '0') + ':' + String(clock++ % 60).padStart(2, '0');
  (rows || []).forEach(r => db.set(r.EventScheduleId, Object.assign({}, r, { Modified: r.Modified || stamp() })));
  const tokens = new Map();
  const calls = [];
  let gate = null, plan = [];
  const apply = ops => ops.map(o => {
    if (o.op === 'add') {
      const id = nextId++;
      const row = Object.assign({}, o.item, { EventScheduleId: id, Modified: stamp() });
      db.set(id, row);
      return { op: 'add', cid: o.cid, id, schedule: Object.assign({}, row) };
    }
    const row = db.get(o.id);
    if (!row) return { op: o.op, cid: '', id: o.id, schedule: null, gone: true };
    if (o.op === 'remove') { db.delete(o.id); return { op: 'remove', cid: '', id: o.id, schedule: null }; }
    if (o.seen !== undefined && o.seen !== row.Modified) return { op: 'update', cid: '', id: o.id, schedule: Object.assign({}, row), conflict: true };
    Object.assign(row, o.fields, { Modified: stamp() });
    return { op: 'update', cid: '', id: o.id, schedule: Object.assign({}, row) };
  });
  const send = (ops, token) => {
    calls.push({ ops: JSON.parse(JSON.stringify(ops)), token });
    const run = () => {
      const step = plan.shift();
      if (step && step !== 'lost-reply' && step !== 'ok') {
        if (step === 'network') return Promise.reject(new Error('down'));
        return Promise.resolve(step);
      }
      let results;
      if (token && tokens.has(token)) results = tokens.get(token);
      else { results = apply(ops); if (token) tokens.set(token, results); }
      if (step === 'lost-reply') return Promise.reject(new Error('the reply was lost'));
      return Promise.resolve({ status: 0, results: JSON.parse(JSON.stringify(results)) });
    };
    if (gate) { const g = gate; return new Promise(res => g.push(res)).then(run); }
    return run();
  };
  return {
    send, calls, db,
    // Answers for the next sends, in order: 'network' (nothing applied), 'lost-reply'
    // (applied, reply lost), 'ok', or a response object returned as is.
    then(...steps) { plan = plan.concat(steps); },
    hold() { gate = []; },
    release() { const g = gate; gate = null; g.forEach(r => r()); },
    // Another organiser's write, straight to the table.
    edit(id, fields) { Object.assign(db.get(id), fields, { Modified: stamp() }); },
    drop(id) { db.delete(id); },
    insert(row) { const id = nextId++; db.set(id, Object.assign({}, row, { EventScheduleId: id, Modified: stamp() })); return id; },
    list() { return [...db.values()].map(r => Object.assign({}, r)); },
  };
}
const item = (title, more) => Object.assign({ Title: title, StartTime: '2026-11-21 09:00', EndTime: '2026-11-21 10:00', Category: 'Other', Leads: [] }, more);
const tick = () => new Promise(r => setImmediate(r));
const TOKEN = /^[A-Za-z0-9_-]{22,}$/;

// ---- item 1: a request that may have been saved is sent again exactly ----

test('every request carries a fresh token of 22+ URL-safe characters', async () => {
  const f = server();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  s.add(item('A')); await s.idle();
  s.add(item('B')); await s.idle();
  assert.equal(f.calls.length, 2);
  f.calls.forEach(c => assert.match(c.token, TOKEN));
  assert.notEqual(f.calls[0].token, f.calls[1].token);
  assert.match(S.token(), TOKEN);
});

test('a send with no reply is held: retry sends the identical ops and token first; later edits go in a later request', async () => {
  const f = server([item('Old', { EventScheduleId: 7 })]);
  let n = 0;
  const s = S.create({ items: f.list(), send: f.send, saveMeal: true, token: () => 'tok-' + String(++n).padStart(20, '0') });
  f.then('network');
  const a = s.add(item('A'));
  s.update('i7', { Title: 'Old2' });
  await s.idle();
  assert.deepEqual(s.status(), { state: 'offline', pending: 2, reason: 'network' });
  // Edits while the request is held: they wait behind it, nothing joins it.
  s.update(a, { Title: 'A2' });
  s.update('i7', { Location: 'Field' });
  const b = s.add(item('B'));
  await tick();
  assert.equal(f.calls.length, 1, 'nothing is sent while offline');
  assert.equal(s.status().pending, 5);
  s.retry(); await s.idle();
  assert.equal(f.calls.length, 3);
  assert.deepEqual(f.calls[1].ops, f.calls[0].ops, 'the same wire ops');
  assert.equal(f.calls[1].token, f.calls[0].token, 'the same token');
  assert.notEqual(f.calls[2].token, f.calls[0].token);
  const id = s.get(a).EventScheduleId;
  assert.deepEqual(f.calls[2].ops.map(o => [o.op, o.id || o.item.Title, o.fields && Object.keys(o.fields)[0]]),
    [['update', id, 'Title'], ['update', 7, 'Location'], ['add', 'B', undefined]]);
  assert.deepEqual(s.status(), { state: 'saved', pending: 0, reason: null });
  assert.equal(s.get(a).Title, 'A2');
  assert.equal(s.get('i7').Location, 'Field');
  assert.ok(s.get(b).EventScheduleId > 0);
  assert.equal(f.db.size, 3);
});

test('a replayed success settles normally: a batch the server saved before its reply was lost is applied once', async () => {
  const f = server();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  f.then('lost-reply');
  const a = s.add(item('A')); const b = s.add(item('B'));
  await s.idle();
  assert.equal(s.status().state, 'offline');
  assert.equal(f.db.size, 2, 'the server saved it');
  s.retry(); await s.idle();
  assert.equal(f.calls[1].token, f.calls[0].token);
  assert.equal(f.db.size, 2, 'and saved it once');
  assert.deepEqual([s.get(a).EventScheduleId, s.get(b).EventScheduleId], [100, 101]);
  assert.deepEqual(s.status(), { state: 'saved', pending: 0, reason: null });
});

test('a server error and an unexpected reply are held the same way', async () => {
  for (const answer of [{ status: 0 }, { status: 7, error: '?' }, 'server']) {
    const f = server();
    const send = (ops, token) => {
      if (answer === 'server' && f.calls.length === 0) {
        f.calls.push({ ops: JSON.parse(JSON.stringify(ops)), token });
        const e = new Error('HTTP 500'); e.reason = 'server'; return Promise.reject(e);
      }
      return f.send(ops, token);
    };
    if (answer !== 'server') f.then(answer);
    const s = S.create({ items: [], send, saveMeal: true });
    const a = s.add(item('A'));
    await s.idle();
    assert.equal(s.status().state, 'offline');
    s.update(a, { Title: 'A2' });
    s.retry(); await s.idle();
    assert.deepEqual(f.calls[1].ops, f.calls[0].ops, JSON.stringify(answer));
    assert.equal(f.calls[1].token, f.calls[0].token);
    assert.equal(s.get(a).Title, 'A2');
    assert.equal(s.status().state, 'saved');
  }
});

test('a forbidden refusal requeues as before: retry regroups with a new token', async () => {
  const f = server();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  f.then({ status: 3, error: 'Not authorized.', failedIndex: -1 });
  s.add(item('A'));
  await s.idle();
  assert.deepEqual(s.status(), { state: 'offline', pending: 1, reason: 'forbidden' });
  s.add(item('B'));
  s.retry(); await s.idle();
  assert.equal(f.calls.length, 2);
  assert.deepEqual(f.calls[1].ops.map(o => o.item.Title), ['A', 'B']);
  assert.notEqual(f.calls[1].token, f.calls[0].token);
  assert.equal(s.status().state, 'saved');
});

test('a held request refused on its resend (signed out) stays held: the first send may still have been saved', async () => {
  const f = server();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  f.then('lost-reply', { status: 5, error: 'Not logged in' });
  const a = s.add(item('A'));
  await s.idle();
  s.add(item('B'));
  s.retry(); await s.idle();
  assert.deepEqual(s.status(), { state: 'offline', pending: 2, reason: 'signed-out' });
  s.retry(); await s.idle();
  assert.deepEqual(f.calls.map(c => c.ops.map(o => o.item.Title)), [['A'], ['A'], ['A'], ['B']]);
  assert.equal(f.calls[2].token, f.calls[0].token);
  assert.equal(f.db.size, 2);
  assert.equal(s.get(a).EventScheduleId, 100);
});

test('a remove whose row is already gone is a normal success', async () => {
  const f = server([item('Old', { EventScheduleId: 7 })]);
  const s = S.create({ items: f.list(), send: f.send, saveMeal: true });
  const superseded = []; s.on('superseded', r => superseded.push(r));
  f.drop(7);
  s.remove('i7');
  await s.idle();
  assert.equal(f.calls[0].ops[0].op, 'remove');
  assert.deepEqual(s.status(), { state: 'saved', pending: 0, reason: null });
  assert.equal(s.get('i7'), null);
  assert.deepEqual(superseded, []);
});

test('maxOps splits requests; the default is 200', async () => {
  const f = server();
  const s = S.create({ items: [], send: f.send, saveMeal: true, maxOps: 2 });
  s.batch(() => { s.add(item('A')); s.add(item('B')); s.add(item('C')); });
  await s.idle();
  assert.deepEqual(f.calls.map(c => c.ops.length), [2, 1]);
  const g = server();
  const t = S.create({ items: [], send: g.send, saveMeal: true, maxOps: 0 });
  t.batch(() => { for (let i = 0; i < 201; i++) t.add(item('X' + i)); });
  await t.idle();
  assert.deepEqual(g.calls.map(c => c.ops.length), [200, 1]);
});

test('version() goes up with every change to the items and only then', async () => {
  const f = server();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const v0 = s.version();
  s.items(); s.status(); s.canUndo();
  assert.equal(s.version(), v0);
  const a = s.add(item('A'));
  const v1 = s.version();
  assert.ok(v1 > v0);
  await s.idle();
  const v2 = s.version();
  assert.ok(v2 > v1, 'the server id arriving is a change');
  s.update(a, { Title: 'A2' });
  assert.ok(s.version() > v2);
});

// ---- item 2: two organisers ----

test('updates carry seen, which follows the latest Modified the server returned', async () => {
  const f = server([item('Old', { EventScheduleId: 7, Modified: '2026-11-16 09:00:00' })]);
  f.hold();
  const s = S.create({ items: f.list(), send: f.send, saveMeal: true });
  s.update('i7', { Title: 'One' });
  await tick();
  s.update('i7', { Title: 'Two' }); // queued behind the first
  f.release(); await s.idle();
  assert.equal(f.calls.length, 2);
  assert.equal(f.calls[0].ops[0].seen, '2026-11-16 09:00:00');
  // The first update's result row said Modified 10:00:00; the queued update is sent against it.
  assert.equal(f.calls[1].ops[0].seen, '2026-11-16 10:00:00');
  assert.equal(f.db.get(7).Title, 'Two', 'both applied: no false conflict');
  assert.equal(s.get('i7').Title, 'Two');
  assert.equal(s.get('i7').Modified, '2026-11-16 10:00:01');
  assert.equal(s.get('i7').Modified, f.db.get(7).Modified);
  // An item with no Modified sends no seen.
  const g = server();
  const t = S.create({ items: [item('Bare', { EventScheduleId: 9 })], send: g.send, saveMeal: true });
  g.db.set(9, item('Bare', { EventScheduleId: 9, Modified: '2026-11-16 09:00:00' }));
  t.update('i9', { Title: 'Bare2' }); await t.idle();
  assert.equal('seen' in g.calls[0].ops[0], false);
  assert.equal(t.get('i9').Modified, g.db.get(9).Modified, 'and takes the Modified that comes back');
});

test('an update to an item someone else deleted removes it, drops its history and queue, reports it; the rest settles', async () => {
  const f = server([item('Gone', { EventScheduleId: 7 }), item('Kept', { EventScheduleId: 8 })]);
  const s = S.create({ items: f.list(), send: f.send, saveMeal: true });
  const superseded = []; s.on('superseded', r => superseded.push(r));
  const rejected = []; s.on('rejected', r => rejected.push(r));
  s.update('i7', { Location: 'Hall' });
  await s.idle();
  f.drop(7);
  f.hold();
  s.batch(() => { s.update('i7', { Title: 'Gone2' }); s.update('i8', { Title: 'Kept2' }); });
  const a = s.add(item('New'));
  await tick();
  s.update('i7', { Location: 'Field' }); // queued behind the request in flight
  f.release(); await s.idle();
  assert.equal(s.get('i7'), null);
  assert.equal(s.get('i8').Title, 'Kept2');
  assert.ok(s.get(a).EventScheduleId > 0);
  assert.deepEqual(superseded, [{ kind: 'gone', cid: 'i7', title: 'Gone2' }]);
  assert.deepEqual(rejected, []);
  assert.ok(!f.calls.flatMap(c => c.ops).some(o => o.op === 'update' && o.id === 7 && o.fields.Location === 'Field'), 'its queued edit was dropped');
  assert.deepEqual(s.status(), { state: 'saved', pending: 0, reason: null });
  // Undo now reaches only what is left: the add, then the other half of the batch.
  assert.ok(s.undo()); assert.equal(s.get(a), null);
  assert.ok(s.undo()); assert.equal(s.get('i8').Title, 'Kept');
  assert.equal(s.get('i7'), null);
  assert.equal(s.undo(), false, 'the Location step on the gone item is gone too');
  await s.idle();
});

test('an update in conflict takes the server row, drops the item\'s queue and history, and reports it', async () => {
  const f = server([item('Talk', { EventScheduleId: 7, Modified: '2026-11-16 09:00:00' }), item('Other', { EventScheduleId: 8 })]);
  const s = S.create({ items: f.list(), send: f.send, saveMeal: true });
  const superseded = []; s.on('superseded', r => superseded.push(r));
  s.update('i8', { Title: 'Other2' });
  await s.idle();
  f.edit(7, { Title: 'Their talk', Location: 'Barn' });
  f.hold();
  s.update('i7', { Title: 'My talk' });
  await tick();
  s.update('i7', { Location: 'Field' });
  f.release(); await s.idle();
  assert.equal(f.calls.length, 2, 'the queued edit was dropped');
  assert.equal(f.calls[1].ops[0].seen, '2026-11-16 09:00:00');
  assert.equal(s.get('i7').Title, 'Their talk');
  assert.equal(s.get('i7').Location, 'Barn');
  assert.equal(s.get('i7').Modified, f.db.get(7).Modified);
  assert.deepEqual(superseded, [{ kind: 'conflict', cid: 'i7', title: 'My talk' }]);
  assert.ok(s.undo()); assert.equal(s.get('i8').Title, 'Other');
  assert.equal(s.undo(), false);
  await s.idle();
  // The next edit is made against their version, and applies.
  s.update('i7', { Title: 'Our talk' }); await s.idle();
  assert.equal(f.db.get(7).Title, 'Our talk');
  assert.equal(superseded.length, 1);
});

test('a gone or conflict row on the wrong op is a protocol failure', async () => {
  for (const bad of [{ gone: true }, { conflict: true }]) {
    const f = server();
    let first = true;
    const send = (ops, token) => f.send(ops, token).then(r => {
      if (!first) return r;
      first = false;
      return Object.assign({}, r, { results: r.results.map(x => Object.assign({}, x, bad)) });
    });
    const s = S.create({ items: [], send, saveMeal: true });
    s.add(item('A'));
    await s.idle();
    assert.deepEqual(s.status(), { state: 'offline', pending: 1, reason: 'protocol' });
  }
  const f = server([item('Old', { EventScheduleId: 7 })]);
  const send = ops => Promise.resolve({ status: 0, results: [{ op: 'update', id: 7, schedule: null, conflict: true }] });
  const s = S.create({ items: f.list(), send, saveMeal: true });
  s.update('i7', { Title: 'X' }); await s.idle();
  assert.equal(s.status().reason, 'protocol', 'a conflict must carry the current row');
});

test('sync adds, changes and removes, keeps history for untouched items, and leaves pending work alone', async () => {
  const at = { Location: '' };
  const f = server([item('Keep', Object.assign({ EventScheduleId: 7 }, at)), item('Change', Object.assign({ EventScheduleId: 8 }, at)),
    item('Drop', Object.assign({ EventScheduleId: 9 }, at)), item('Busy', Object.assign({ EventScheduleId: 10 }, at))]);
  const s = S.create({ items: f.list(), send: f.send, saveMeal: true });
  const changes = []; s.on('change', () => changes.push(1));
  s.update('i7', { Location: 'Hall' });   // a step on an untouched item
  s.update('i9', { Location: 'Barn' });   // a step on the item that will be dropped
  s.batch(() => { s.update('i7', { Title: 'Keep2' }); s.update('i9', { Title: 'Drop2' }); }); // touches both
  await s.idle();
  // Someone else changes 8, deletes 9 and adds one.
  f.edit(8, { Title: 'Changed' });
  f.drop(9);
  const newId = f.insert(item('Theirs', at));
  // Meanwhile an add is in flight and 10 has an edit queued behind it.
  f.hold();
  const pendingCid = s.add(item('Mine', at));
  await tick();
  s.update('i10', { Title: 'Busy (mine)' });
  changes.length = 0;
  const counts = s.sync(f.list());
  assert.deepEqual(counts, { added: 0, changed: 1, removed: 1 }, 'no unknown row is added while an add is in flight');
  assert.equal(changes.length, 1, 'one change event');
  assert.equal(s.get('i8').Title, 'Changed');
  assert.equal(s.get('i9'), null);
  assert.equal(s.get('i10').Title, 'Busy (mine)', 'an item with a queued edit keeps its local state');
  assert.equal(s.get('i7').Title, 'Keep2');
  assert.ok(s.get(pendingCid));
  f.release(); await s.idle();
  assert.equal(f.db.get(10).Title, 'Busy (mine)');
  changes.length = 0;
  const again = s.sync(f.list());
  assert.deepEqual(again, { added: 1, changed: 0, removed: 0 });
  assert.equal(changes.length, 1);
  assert.ok(s.items().some(i => i.Title === 'Theirs' && i.cid === 'i' + newId));
  assert.equal(s.items().filter(i => i.Title === 'Mine').length, 1, 'its own add is not added twice');
  changes.length = 0;
  assert.deepEqual(s.sync(f.list()), { added: 0, changed: 0, removed: 0 });
  assert.equal(changes.length, 0, 'nothing changed, no event');
  // History kept: the edit of 10, the add, both steps on 7. 9's changes left every step:
  // its own step is gone, and the batch now undoes only 7's half.
  assert.ok(s.undo()); assert.equal(s.get('i10').Title, 'Busy');
  assert.ok(s.undo()); assert.equal(s.get(pendingCid), null);
  assert.ok(s.undo()); assert.equal(s.get('i7').Title, 'Keep'); assert.equal(s.get('i7').Location, 'Hall');
  assert.equal(s.get('i9'), null, 'undo never brings the removed item back');
  assert.ok(s.undo()); assert.equal(s.get('i7').Location, '');
  assert.equal(s.undo(), false, 'the step only on 9 was dropped');
  assert.equal(s.get('i9'), null);
  await s.idle();
  assert.ok(s.redo()); assert.equal(s.get('i7').Location, 'Hall');
  assert.equal(s.get('i9'), null, 'nor does redo');
  await s.idle();
});

test('after sync takes someone else\'s change to an item, undo never touches that item: their change survives', async () => {
  const f = server([item('Talk', { EventScheduleId: 7, Location: 'Hall' }), item('Other', { EventScheduleId: 8 })]);
  const s = S.create({ items: f.list(), send: f.send, saveMeal: true });
  s.update('i7', { Title: 'My talk' });                                                         // a step only on A
  s.batch(() => { s.update('i7', { Description: 'Bring a mat' }); s.update('i8', { Title: 'Other2' }); }); // a mixed step
  await s.idle();
  f.edit(7, { Location: 'Barn' });                                                              // someone else moves A
  assert.deepEqual(s.sync(f.list()), { added: 0, changed: 1, removed: 0 });
  assert.equal(s.get('i7').Location, 'Barn');
  const sent = f.calls.length;
  assert.ok(s.undo(), 'the mixed step keeps its other half');
  assert.equal(s.get('i8').Title, 'Other');
  assert.equal(s.undo(), false, 'the step only on A is gone');
  await s.idle();
  assert.ok(!f.calls.slice(sent).flatMap(c => c.ops).some(o => o.id === 7), 'no undo request touches A');
  assert.deepEqual([f.db.get(7).Title, f.db.get(7).Location, f.db.get(7).Description], ['My talk', 'Barn', 'Bring a mat']);
  assert.ok(s.redo()); assert.equal(s.get('i8').Title, 'Other2');
  await s.idle();
  assert.equal(f.db.get(7).Location, 'Barn', 'nor does redo');
});

test('sync keeps cids and selection-facing ids, and refreshes Modified without counting it as a change', async () => {
  const f = server();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const a = s.add(item('A'));
  await s.idle();
  const id = s.get(a).EventScheduleId;
  f.edit(id, {});
  const counts = s.sync(f.list());
  assert.deepEqual(counts, { added: 0, changed: 0, removed: 0 });
  assert.equal(s.get(a).Modified, f.db.get(id).Modified);
  assert.equal(s.items()[0].cid, a, 'a new item keeps its n cid');
  assert.ok(s.canUndo());
  const synced = s.get(a).Modified;
  s.update(a, { Title: 'A2' }); await s.idle();
  assert.equal(f.calls[f.calls.length - 1].ops[0].seen, synced);
  assert.equal(f.db.get(id).Title, 'A2', 'the seen sent after a sync is current, so the edit applies');
});

// ---- the whole-change review: requests refused as a whole, and the Details form's save ----

test('a reply refusing the whole request is a server problem: held for Retry, with the server\'s text', async () => {
  const f = server();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const statuses = []; s.on('status', st => statuses.push(st));
  f.then({ status: 1, error: ' Could not save the schedule. ', failedIndex: -1 });
  const a = s.add(item('A'));
  await s.idle();
  assert.deepEqual(s.status(), { state: 'offline', pending: 1, reason: 'server', message: 'Could not save the schedule.' });
  assert.equal(statuses[statuses.length - 1].message, 'Could not save the schedule.');
  assert.ok(s.get(a), 'nothing is undone');
  s.retry();
  assert.equal('message' in s.status(), false, 'Retry clears it');
  await s.idle();
  assert.deepEqual(f.calls[1].ops, f.calls[0].ops, 'the held request is sent again as it was');
  assert.equal(f.calls[1].token, f.calls[0].token);
  assert.deepEqual(s.status(), { state: 'saved', pending: 0, reason: null });
  assert.ok(s.get(a).EventScheduleId > 0);

  // No usable op index, any other error status: the same. No text, no message.
  const cases = [
    [{ status: 1, error: 'Too many changes in one request.', failedIndex: 3 }, 'Too many changes in one request.'],
    [{ status: 7, error: 'Something odd.' }, 'Something odd.'],
    [{ status: 1, failedIndex: -1 }, undefined],
    [{ status: 1, error: '   ', failedIndex: -1 }, undefined],
    [{ status: 1, error: { text: 'no' }, failedIndex: -1 }, undefined],
  ];
  for (const [answer, message] of cases) {
    const g = server();
    const t = S.create({ items: [], send: g.send, saveMeal: true });
    g.then(answer);
    t.add(item('B'));
    await t.idle();
    const want = { state: 'offline', pending: 1, reason: 'server' };
    if (message) want.message = message;
    assert.deepEqual(t.status(), want, JSON.stringify(answer));
  }
});

test('a reply that is not a well-formed object stays a protocol failure, with no message', async () => {
  for (const answer of ['oops', [], { error: 'x' }, { status: '1', error: 'x' }, { status: 1.5, error: 'x' }, { status: null, error: 'x' }]) {
    const f = server();
    const s = S.create({ items: [], send: f.send, saveMeal: true });
    f.then(answer);
    const a = s.add(item('A'));
    await s.idle();
    assert.deepEqual(s.status(), { state: 'offline', pending: 1, reason: 'protocol' }, JSON.stringify(answer));
    s.retry(); await s.idle();
    assert.equal(f.calls[1].token, f.calls[0].token, 'held as before');
    assert.ok(s.get(a).EventScheduleId > 0);
  }
});

test('syncRow takes the Details form\'s saved row at once: the next edit is sent against it, with no conflict', async () => {
  const f = server([item('Talk', { EventScheduleId: 7, Modified: '2026-11-16 09:00:00' }), item('Other', { EventScheduleId: 8 })]);
  const s = S.create({ items: f.list(), send: f.send, saveMeal: true });
  const superseded = []; s.on('superseded', r => superseded.push(r));
  s.update('i8', { Title: 'Other2' }); // a step on another item
  s.update('i7', { Location: 'Hall' }); // a step on this one
  await s.idle();
  // The Details form saves 7 through its own endpoint; its reply carries the row.
  f.edit(7, { Description: 'Bring a mat' });
  const row = f.list().find(r => r.EventScheduleId === 7);
  const changes = []; s.on('change', () => changes.push(1));
  assert.deepEqual(s.syncRow(row), { added: 0, changed: 1, removed: 0 });
  assert.equal(changes.length, 1);
  assert.equal(s.get('i7').Description, 'Bring a mat');
  assert.equal(s.get('i7').Modified, row.Modified);
  // A nudge straight after goes out with that row's Modified.
  s.update('i7', { StartTime: '2026-11-21 09:15', EndTime: '2026-11-21 10:15' });
  await s.idle();
  assert.equal(f.calls[f.calls.length - 1].ops[0].seen, row.Modified);
  assert.deepEqual(superseded, []);
  assert.deepEqual([f.db.get(7).StartTime, f.db.get(7).Description], ['2026-11-21 09:15', 'Bring a mat']);
  // The content changed outside the store: the item's earlier step is dropped, as sync drops it;
  // the other item's step and the nudge stay.
  assert.ok(s.undo()); assert.equal(s.get('i7').StartTime, '2026-11-21 09:00');
  assert.ok(s.undo()); assert.equal(s.get('i8').Title, 'Other');
  assert.equal(s.undo(), false, 'the Location step on 7 is gone');
  await s.idle();
  assert.equal(f.db.get(7).Location, 'Hall');
});

test('syncRow: unchanged content only refreshes Modified; with work pending only the saved copy takes the row', async () => {
  const f = server([item('Talk', { EventScheduleId: 7, Location: '', Modified: '2026-11-16 09:00:00' }), item('Other', { EventScheduleId: 8 })]);
  const s = S.create({ items: f.list(), send: f.send, saveMeal: true });
  s.update('i7', { Location: 'Hall' }); await s.idle();
  f.edit(7, {});
  const plain = f.list().find(r => r.EventScheduleId === 7);
  assert.deepEqual(s.syncRow(plain), { added: 0, changed: 0, removed: 0 });
  assert.equal(s.get('i7').Modified, plain.Modified);
  assert.ok(s.undo(), 'the item\'s history is kept');
  await s.idle();
  assert.equal(f.db.get(7).Location, '');

  // An edit of 7 waits behind a request for 8 when the form's save comes back.
  f.hold();
  s.update('i8', { Title: 'Other2' });
  await tick();
  s.update('i7', { Title: 'Mine' });
  f.edit(7, { Description: 'Bring a mat' });
  const row = f.list().find(r => r.EventScheduleId === 7);
  assert.deepEqual(s.syncRow(row), { added: 0, changed: 0, removed: 0 });
  assert.equal(s.get('i7').Title, 'Mine', 'the board copy keeps the edit waiting to be sent');
  assert.equal(s.get('i7').Description, undefined, 'and is not replaced while work is pending');
  const superseded = []; s.on('superseded', r => superseded.push(r));
  f.release(); await s.idle();
  const sent = f.calls.flatMap(c => c.ops).filter(o => o.id === 7);
  assert.equal(sent[sent.length - 1].seen, row.Modified, 'the waiting edit is sent against the saved row');
  assert.deepEqual(superseded, []);
  assert.deepEqual([f.db.get(7).Title, f.db.get(7).Description], ['Mine', 'Bring a mat']);
  assert.deepEqual([s.get('i7').Title, s.get('i7').Description], ['Mine', 'Bring a mat']);
  // The form changed 7's content while its edit waited: 7's steps left the history, so undo
  // reaches only 8 and never sends 7 back to a snapshot from before the form's save.
  const beforeUndo = f.calls.length;
  assert.ok(s.undo()); assert.equal(s.get('i8').Title, 'Other');
  await s.idle();
  assert.ok(!f.calls.slice(beforeUndo).flatMap(c => c.ops).some(o => o.id === 7), 'no undo request touches 7');
  assert.equal(f.db.get(7).Description, 'Bring a mat');
  assert.equal(f.db.get(8).Title, 'Other');

  // pendingFor: queued or in flight for that item only.
  f.hold();
  s.update('i8', { Title: 'Other3' });
  await tick();
  s.update('i7', { Title: 'Mine2' });
  assert.deepEqual([s.pendingFor('i8'), s.pendingFor('i7')], [true, true], 'in flight, and queued');
  f.release(); await s.idle();
  assert.deepEqual([s.pendingFor('i8'), s.pendingFor('i7')], [false, false]);

  // A row the store does not know, or no row at all, changes nothing.
  const before = s.items();
  assert.deepEqual(s.syncRow(item('Stranger', { EventScheduleId: 999 })), { added: 0, changed: 0, removed: 0 });
  assert.deepEqual(s.syncRow(null), { added: 0, changed: 0, removed: 0 });
  assert.deepEqual(s.items(), before);
});

// ---- the item ceiling: one refusal drops the rest of the adds ----

test('a ceiling refusal drops every later add in that send at once, with one notice', async () => {
  const f = server();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const rejected = []; s.on('rejected', r => rejected.push(r));
  const MSG = 'A schedule can hold at most 2 items.';
  f.then({ status: 1, error: MSG, failedIndex: 2 });
  const ids = [];
  s.batch(() => { for (let i = 0; i < 5; i++) ids.push(s.add(item('Line ' + i))); });
  await s.idle();
  assert.equal(f.calls.length, 2, 'one refused send and one resend, not one per extra line');
  assert.deepEqual(f.calls[1].ops.map(o => o.item.Title), ['Line 0', 'Line 1']);
  assert.equal(rejected.length, 1);
  assert.equal(rejected[0].item.Title, 'Line 2');
  assert.equal(rejected[0].message, MSG, 'the server\'s text, untouched');
  assert.equal(rejected[0].dropped, 2);
  assert.deepEqual(s.items().map(i => i.Title), ['Line 0', 'Line 1']);
  assert.equal(s.status().state, 'saved');
});

test('a ceiling refusal keeps later adds when a remove follows it in the send', async () => {
  const f = server([{ EventScheduleId: 5, Title: 'Old', StartTime: '2026-11-21 08:00', EndTime: '2026-11-21 09:00', Category: 'Other', Leads: [] }]);
  const s = S.create({ items: f.list(), send: f.send, saveMeal: true });
  const rejected = []; s.on('rejected', r => rejected.push(r));
  f.then({ status: 1, error: 'A schedule can hold at most 2 items.', failedIndex: 0 });
  s.batch(() => { s.add(item('A')); s.add(item('B')); s.remove('i5'); });
  await s.idle();
  assert.equal(rejected.length, 1);
  assert.equal(rejected[0].dropped, 0);
  assert.ok(s.items().some(i => i.Title === 'B'), 'B is still sent');
});

test('an item that starts outside the event, resized at its end edge, is refused before any request', async () => {
  const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');
  const win = C.hardWindow('2026-11-20 09:00:00', '2026-11-22 16:00:00');
  const f = server();
  const s = S.create({
    items: [item('Stray', { EventScheduleId: 7, StartTime: '2026-12-25 10:00', EndTime: '2026-12-25 11:00' })],
    send: f.send, saveMeal: true,
    normalize: (next, prev) => { const m = C.timesRefusal(next, prev, win); return m ? { refuse: m } : { item: next }; }
  });
  const refused = []; s.on('refused', r => refused.push(r.message));
  // An end-edge resize sends both times, as evb-drag does.
  assert.equal(s.update('i7', { StartTime: '2026-12-25 10:00', EndTime: '2026-12-25 12:00' }), false);
  assert.deepEqual(refused, ['That time is outside the event.']);
  await s.idle();
  assert.equal(f.calls.length, 0);
  assert.equal(s.get('i7').EndTime, '2026-12-25 11:00');
});
