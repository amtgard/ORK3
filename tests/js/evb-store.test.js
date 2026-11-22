const test = require('node:test');
const assert = require('node:assert/strict');
const S = require('../../orkui/template/revised-frontend/script/event-board/evb-store.js');
function fake() {
  const calls = []; let nextId = 100; let gate = null; let failNext = null; let badTitle = null;
  const send = ops => {
    calls.push(JSON.parse(JSON.stringify(ops)));
    const run = () => {
      if (failNext) { const f = failNext; failNext = null; if (f === 'network') return Promise.reject(new Error('down')); return Promise.resolve(f); }
      const bad = badTitle ? ops.findIndex(o => o.op === 'add' && o.item.Title === badTitle.title) : -1;
      if (bad >= 0) return Promise.resolve({ status: 1, error: badTitle.message, failedIndex: bad });
      return Promise.resolve({ status: 0, results: ops.map(o => ({ op: o.op, cid: o.cid || '', id: o.op === 'add' ? nextId++ : o.id,
        schedule: o.op === 'remove' ? null : Object.assign({ EventScheduleId: o.op === 'add' ? nextId - 1 : o.id }, o.item || o.fields) })) });
    };
    if (gate) { const g = gate; return new Promise(res => g.push(res)).then(run); }
    return run();
  };
  return { send, calls, hold() { gate = []; }, release() { const g = gate; gate = null; g.forEach(r => r()); }, failWith(f) { failNext = f; },
    rejectTitle(title, message) { badTitle = { title, message }; } };
}
const item = (title, more) => Object.assign({ Title: title, StartTime: '2026-11-21 09:00', EndTime: '2026-11-21 10:00', Category: 'Other', Leads: [] }, more);
const tick = () => new Promise(r => setImmediate(r));

test('loaded and new items get client ids; an add is saved and takes its server id', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true });
  assert.equal(s.items()[0].cid, 'i7');
  const cid = s.add(item('Mothman'));
  assert.equal(s.get(cid).EventScheduleId, 0);
  assert.deepEqual(s.status(), { state: 'saving', pending: 1, reason: null });
  await s.idle();
  assert.equal(s.get(cid).EventScheduleId, 100);
  assert.deepEqual(s.status(), { state: 'saved', pending: 0, reason: null });
  assert.deepEqual(f.calls[0].map(o => [o.op, o.cid, o.item.Title]), [['add', cid, 'Mothman']]);
  assert.equal(f.calls[0][0].item.cid, undefined);
});
test('edits made while a save is in flight are merged and sent once with the real id', async () => {
  const f = fake(); f.hold();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const cid = s.add(item('Mothman'));
  await tick();
  s.update(cid, { StartTime: '2026-11-21 10:00' });
  s.update(cid, { StartTime: '2026-11-21 11:00', EndTime: '2026-11-21 12:00' });
  f.release(); await s.idle();
  assert.equal(f.calls.length, 2);
  assert.deepEqual(f.calls[1], [{ op: 'update', id: 100, fields: { StartTime: '2026-11-21 11:00', EndTime: '2026-11-21 12:00' } }]);
  assert.equal(s.get(cid).StartTime, '2026-11-21 11:00');
});
test('changes to an unsent add fold into it; removing an unsent add sends nothing', async () => {
  const f = fake(); f.hold();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true });
  s.update('i7', { Title: 'Older' });
  await tick();
  const a = s.add(item('A')); s.update(a, { Title: 'A2' });
  const b = s.add(item('B')); s.remove(b);
  s.update('i7', { Title: 'Oldest' }); s.remove('i7');
  f.release(); await s.idle();
  assert.deepEqual(f.calls[1].map(o => [o.op, o.id || o.item.Title]), [['add', 'A2'], ['remove', 7]]);
  assert.deepEqual(s.items().map(i => i.Title), ['A2']);
});
test('undo and redo', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7, Location: 'Hall' })], send: f.send, saveMeal: true });
  s.update('i7', { Location: 'Field' });
  assert.ok(s.canUndo()); assert.ok(!s.canRedo());
  assert.ok(s.undo()); assert.equal(s.get('i7').Location, 'Hall');
  assert.ok(s.redo()); assert.equal(s.get('i7').Location, 'Field');
  s.remove('i7'); assert.equal(s.get('i7'), null);
  s.undo(); await s.idle();
  assert.equal(s.get('i7').Title, 'Old');
  assert.notEqual(s.get('i7').EventScheduleId, 7);
  assert.ok(f.calls.flat().some(o => o.op === 'remove' && o.id === 7));
  assert.ok(f.calls.flat().some(o => o.op === 'add' && o.item.Title === 'Old'));
  const cid = s.add(item('New')); s.undo();
  assert.equal(s.get(cid), null);
  while (s.canUndo()) s.undo();
  assert.equal(s.undo(), false);
  await s.idle();
});
test('batch is one undo step; a new change clears redo; undoable:false records nothing', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true });
  s.batch(() => { s.add(item('A')); s.add(item('B')); s.update('i7', { Title: 'Renamed' }); });
  assert.equal(s.items().length, 3);
  s.undo();
  assert.deepEqual(s.items().map(i => i.Title), ['Old']);
  assert.equal(s.redo(), true); assert.equal(s.undo(), true); s.add(item('C'));
  assert.ok(!s.canRedo());
  s.undo();
  s.remove('i7', { undoable: false });
  assert.ok(!s.canUndo());
  await s.idle();
});
test('when the server cannot be reached nothing is lost and retry sends the held request first, then the rest', async () => {
  const f = fake(); f.failWith('network');
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const a = s.add(item('A'));
  await tick(); await tick();
  assert.equal(s.status().state, 'offline');
  const b = s.add(item('B'));
  assert.equal(s.items().length, 2);
  s.retry(); await s.idle();
  assert.deepEqual(f.calls.map(c => c.map(o => o.item.Title)), [['A'], ['A'], ['B']]);
  assert.ok(s.get(a).EventScheduleId > 0 && s.get(b).EventScheduleId > 0);
  assert.equal(s.status().state, 'saved');
});
test('a change the server rejects is reverted, reported, and does not block the rest', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true });
  const rejected = []; s.on('rejected', r => rejected.push(r));
  f.rejectTitle('Bad', 'A title is required.');
  s.update('i7', { Title: 'First' });
  const bad = s.add(item('Bad')); const good = s.add(item('Good'));
  await s.idle();
  assert.equal(s.get('i7').Title, 'First');
  assert.equal(s.get(bad), null);
  assert.ok(s.get(good).EventScheduleId > 0);
  assert.deepEqual(rejected.map(r => [r.kind, r.item.Title, r.message]), [['add', 'Bad', 'A title is required.']]);
  assert.equal(s.status().state, 'saved');
  s.undo();
  assert.equal(s.get(good), null);
});
test('redo brings back an undone add, and undo history survives', async () => {
  const f = fake();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const a = s.add(item('A'));
  s.update(a, { Title: 'A2' });
  assert.equal(s.undo(), true);
  assert.equal(s.undo(), true);
  assert.equal(s.get(a), null);
  assert.equal(s.redo(), true);
  assert.equal(s.get(a).Title, 'A');
  assert.equal(s.redo(), true);
  assert.equal(s.get(a).Title, 'A2');
  await s.idle();
  assert.ok(s.get(a).EventScheduleId > 0);
});
test('a batch survives the undo and redo of a later step', async () => {
  const f = fake();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  let a, b;
  s.batch(() => { a = s.add(item('A')); b = s.add(item('B')); });
  s.remove(a);
  assert.equal(s.undo(), true); assert.ok(s.get(a));
  assert.equal(s.redo(), true); assert.equal(s.get(a), null);
  assert.equal(s.undo(), true);
  assert.equal(s.undo(), true);
  assert.deepEqual(s.items(), []);
  assert.equal(s.get(b), null);
  await s.idle();
});
test('a malformed success response is treated as not saved', async () => {
  for (const bad of [{ status: 0 }, undefined, 'short']) {
    const f = fake();
    let first = true;
    const send = ops => {
      if (first) {
        first = false;
        if (bad === 'short') return f.send(ops).then(r => Object.assign({}, r, { results: r.results.slice(0, ops.length - 1) }));
        return Promise.resolve(bad);
      }
      return f.send(ops);
    };
    const s = S.create({ items: [], send, saveMeal: true });
    const a = s.add(item('A'));
    await s.idle();
    s.update(a, { Title: 'A2' });
    await s.idle();
    assert.equal(s.status().state, 'offline', String(bad && JSON.stringify(bad)));
    assert.ok(s.status().pending > 0);
    s.retry(); await s.idle();
    assert.ok(s.get(a).EventScheduleId > 0);
    const id = s.get(a).EventScheduleId;
    const sent = f.calls.slice(bad === 'short' ? 1 : 0).flat();
    assert.ok(sent.some(o => o.op === 'add' && o.item.Title === 'A2') ||
      (sent.some(o => o.op === 'add') && sent.some(o => o.op === 'update' && o.id === id && o.fields.Title === 'A2')));
    assert.equal(s.status().state, 'saved');
  }
});
test('a rejection in a later request reverts only its item', async () => {
  const f = fake(); f.hold();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 }), item('Two', { EventScheduleId: 8 })], send: f.send, saveMeal: true });
  const rejected = []; s.on('rejected', r => rejected.push(r));
  f.rejectTitle('Bad', 'A title is required.');
  s.update('i7', { Title: 'Old2' });
  await tick();
  const bad = s.add(item('Bad'));
  s.update('i8', { Title: 'Two2' });
  f.release(); await s.idle();
  // Requests the server applied: every one that did not carry the rejected add.
  const applied = f.calls.filter(c => !c.some(o => o.op === 'add' && o.item.Title === 'Bad')).flat();
  assert.deepEqual(applied.filter(o => o.op === 'update' && o.id === 7).map(o => o.fields.Title), ['Old2']);
  assert.deepEqual(applied.filter(o => o.op === 'update' && o.id === 8).map(o => o.fields.Title), ['Two2']);
  assert.equal(s.get('i7').Title, 'Old2');
  assert.equal(s.get('i8').Title, 'Two2');
  assert.equal(s.get(bad), null);
  assert.equal(rejected.length, 1);
  assert.equal(s.status().state, 'saved');
});
test('a non-undoable remove also forgets earlier steps on that item', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true });
  const b = s.add(item('B'));
  s.update('i7', { Title: 'Renamed' });
  s.remove('i7', { undoable: false });
  assert.equal(s.get('i7'), null);
  assert.ok(s.canUndo());
  assert.equal(s.undo(), true);
  assert.equal(s.get(b), null);
  assert.ok(!s.items().some(i => i.cid === 'i7'));
  assert.equal(s.get('i7'), null);
  assert.equal(s.undo(), false);
  assert.equal(s.get('i7'), null);
  await s.idle();
  assert.equal(s.get('i7'), null);
  const sent = f.calls.flat();
  assert.ok(sent.some(o => o.op === 'remove' && o.id === 7));
  assert.ok(!sent.some(o => o.op === 'add' && (o.item.Title === 'Old' || o.item.Title === 'Renamed')));
});
test('a non-undoable remove also drops redo steps on that item', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true });
  s.update('i7', { Title: 'Renamed' });
  assert.equal(s.undo(), true);
  assert.ok(s.canRedo());
  s.remove('i7', { undoable: false });
  assert.ok(!s.canRedo());
  assert.equal(s.redo(), false);
  assert.equal(s.get('i7'), null);
  await s.idle();
});
test('an offline store says why: network, signed out, forbidden or an unexpected reply', async () => {
  const cases = [
    ['network', 'network'],
    [{ status: 5, error: 'Not logged in' }, 'signed-out'],
    [{ status: 3, error: 'Not authorized.', failedIndex: -1 }, 'forbidden'],
    // A well-formed reply with any other error status is now 'server' (evb-store.batch.test.js).
    ['not a reply', 'protocol'],
  ];
  for (const [answer, reason] of cases) {
    const f = fake(); f.failWith(answer);
    const s = S.create({ items: [], send: f.send, saveMeal: true });
    const seen = []; s.on('status', st => seen.push(st));
    const a = s.add(item('A'));
    await s.idle();
    assert.deepEqual(s.status(), { state: 'offline', pending: 1, reason }, reason);
    assert.equal(seen[seen.length - 1].reason, reason);
    assert.ok(s.get(a), 'nothing is lost');
    s.retry(); await s.idle();
    assert.deepEqual(s.status(), { state: 'saved', pending: 0, reason: null });
    assert.ok(s.get(a).EventScheduleId > 0);
  }
});
test('a reply with an error status is a server problem, not an unreachable server', async () => {
  let first = true;
  const f = fake();
  const send = ops => {
    if (!first) return f.send(ops);
    first = false;
    const err = new Error('HTTP 500'); err.reason = 'server'; err.status = 500;
    return Promise.reject(err);
  };
  const s = S.create({ items: [], send, saveMeal: true });
  const a = s.add(item('A'));
  await s.idle();
  assert.deepEqual(s.status(), { state: 'offline', pending: 1, reason: 'server' });
  s.retry(); await s.idle();
  assert.deepEqual(s.status(), { state: 'saved', pending: 0, reason: null });
  assert.ok(s.get(a).EventScheduleId > 0);
});
test('an update or remove row with an id that is not a positive integer is a protocol failure', async () => {
  for (const op of ['update', 'remove']) {
    for (const badId of [0, -3, 'x', 1.5, '07']) {
      const f = fake();
      let first = true;
      const send = ops => f.send(ops).then(r => {
        if (!first) return r;
        first = false;
        return Object.assign({}, r, { results: r.results.map(x => Object.assign({}, x, { id: badId })) });
      });
      const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send, saveMeal: true });
      if (op === 'update') s.update('i7', { Title: 'New' }); else s.remove('i7');
      await s.idle();
      assert.deepEqual(s.status(), { state: 'offline', pending: 1, reason: 'protocol' }, op + ' ' + badId);
      if (op === 'update') assert.equal(s.get('i7').EventScheduleId, 7, 'the bad row is not merged');
      s.retry(); await s.idle();
      assert.equal(s.status().state, 'saved');
    }
  }
});
test('a rejected update restores the saved values; meal fields are sent only when allowed; reset refuses while busy', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7, Menu: 'Soup' })], send: f.send, saveMeal: false });
  f.failWith({ status: 3, error: 'No authorization.', failedIndex: 0 });
  s.update('i7', { Title: 'Changed', Menu: 'Stew' });
  assert.equal(s.reset([]), false);
  await s.idle();
  assert.equal(s.get('i7').Title, 'Old');
  assert.equal(f.calls[0][0].fields.Menu, undefined);
  assert.equal(s.reset([item('Fresh', { EventScheduleId: 9 })]), true);
  assert.deepEqual(s.items().map(i => i.cid), ['i9']);
  assert.ok(!s.canUndo());
});
test('adopt adds server rows without sending them and undoes as one step', async () => {
  const f = fake();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const cids = s.adopt([item('A', { EventScheduleId: 41 }), item('B', { EventScheduleId: 42 })]);
  assert.deepEqual(cids, ['i41', 'i42']);
  assert.equal(f.calls.length, 0);
  assert.deepEqual(s.status(), { state: 'saved', pending: 0, reason: null });
  s.undo(); await s.idle();
  assert.equal(s.items().length, 0);
  assert.deepEqual(f.calls.flat().map(o => [o.op, o.id]).sort(), [['remove', 41], ['remove', 42]]);
});
test('redo after undoing an adopt adds the rows again; known or id-less rows are not adopted', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true });
  const changes = []; s.on('change', () => changes.push(1));
  assert.deepEqual(s.adopt([item('A', { EventScheduleId: 41 }), item('Dup', { EventScheduleId: 7 }), item('NoId')]), ['i41']);
  assert.equal(changes.length, 1);
  assert.equal(s.get('i41').Title, 'A');
  assert.equal(s.get('i7').Title, 'Old');
  assert.deepEqual(s.adopt([]), []);
  s.undo(); await s.idle();
  assert.equal(s.get('i41'), null);
  assert.ok(s.redo()); await s.idle();
  assert.equal(s.get('i41').Title, 'A');
  assert.ok(s.get('i41').EventScheduleId > 41);
  assert.deepEqual(f.calls.flat().map(o => o.op), ['remove', 'add']);
  assert.equal(s.status().state, 'saved');
});
test('adopt skips a row whose server id an item already has, whatever its cid', async () => {
  const f = fake();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const a = s.add(item('A'));
  await s.idle();
  const id = s.get(a).EventScheduleId;
  assert.deepEqual(s.adopt([item('A again', { EventScheduleId: id }), item('B', { EventScheduleId: 50 }), item('B twice', { EventScheduleId: '50' })]), ['i50']);
  assert.deepEqual(s.items().map(i => i.Title), ['A', 'B']);
  // An item restored under 'i7' with a new id keeps its cid; a later adopt of 7 gets a fresh cid.
  const t = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true });
  t.remove('i7'); t.undo(); await t.idle();
  assert.notEqual(t.get('i7').EventScheduleId, 7);
  const [cid] = t.adopt([item('Seven', { EventScheduleId: 7 })]);
  assert.match(cid, /^n\d+$/);
  assert.equal(t.get('i7').Title, 'Old');
  assert.equal(t.get(cid).Title, 'Seven');
});
test('leads go out as MundaneId and Auto; the store keeps Persona and Auto', async () => {
  const f = fake(); f.hold();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const leads = [{ MundaneId: 5, Persona: 'Luna', Auto: true }, { MundaneId: '6', Persona: 'Hand' }];
  const cid = s.add(item('Mothman', { Leads: leads }));
  await tick();
  assert.deepEqual(f.calls[0][0].item.Leads, [{ MundaneId: 5, Auto: true }, { MundaneId: 6, Auto: false }]);
  assert.deepEqual(s.get(cid).Leads, leads);
  f.release(); await s.idle();
  s.update(cid, { Leads: [{ MundaneId: 6, Persona: 'Hand', Auto: false }] });
  await s.idle();
  assert.deepEqual(f.calls[1][0].fields.Leads, [{ MundaneId: 6, Auto: false }]);
});
