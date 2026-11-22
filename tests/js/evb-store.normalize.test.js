const test = require('node:test');
const assert = require('node:assert/strict');
const S = require('../../orkui/template/revised-frontend/script/event-board/evb-store.js');
const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');

// A server that saves everything it is sent.
function fake() {
  const calls = []; let nextId = 100;
  const send = ops => {
    calls.push(JSON.parse(JSON.stringify(ops)));
    return Promise.resolve({ status: 0, results: ops.map(o => ({ op: o.op, cid: o.cid || '', id: o.op === 'add' ? nextId++ : o.id,
      schedule: o.op === 'remove' ? null : Object.assign({ EventScheduleId: o.op === 'add' ? nextId - 1 : o.id }, o.item || o.fields) })) });
  };
  return { send, calls };
}
const item = (title, more) => Object.assign({ Title: title, StartTime: '2026-11-21 09:00', EndTime: '2026-11-21 10:00', Category: 'Other', Leads: [] }, more);

// Edit mode's normalize: the server's limits for a Friday-to-Sunday event.
const WIN = C.hardWindow('2026-11-20 09:00:00', '2026-11-22 16:00:00');
const editNormalize = (next, prev) => {
  const msg = C.timesRefusal(next, prev, WIN);
  return msg ? { refuse: msg } : { item: next };
};

test('normalize sees every add (prev null) and update (prev the item before), and may adjust it', async () => {
  const f = fake(), seen = [];
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true,
    normalize: (next, prev) => {
      seen.push([next.Title, prev && prev.Title]);
      return { item: Object.assign({}, next, { Location: 'Gate' }) };
    } });
  const cid = s.add(item('New'));
  assert.equal(s.get(cid).Location, 'Gate', 'the adjusted add is applied');
  assert.equal(s.update('i7', { Title: 'Older' }), true);
  assert.deepEqual([s.get('i7').Title, s.get('i7').Location], ['Older', 'Gate'], 'a field normalize changed goes along');
  assert.deepEqual(seen, [['New', null], ['Older', 'Old']]);
  await s.idle();
  assert.equal(f.calls[0].find(o => o.op === 'add').item.Location, 'Gate', 'the adjusted add is what is sent');
  assert.deepEqual(f.calls[0].find(o => o.op === 'update').fields, { Title: 'Older', Location: 'Gate' });
});

test('a refused add changes and sends nothing, returns null and fires refused once the call ends', async () => {
  const f = fake(), refused = [], changes = [];
  const s = S.create({ items: [], send: f.send, saveMeal: true, normalize: editNormalize });
  s.on('refused', r => refused.push(r));
  s.on('change', () => changes.push(1));
  const cid = s.add(item('Early', { StartTime: '2026-11-12 23:59', EndTime: '2026-11-13 01:00' }));
  assert.equal(cid, null);
  assert.deepEqual(s.items(), []);
  assert.equal(s.canUndo(), false, 'no undo step');
  assert.deepEqual(changes, []);
  assert.deepEqual(refused.map(r => [r.message, r.item.Title]), [['That time is outside the event.', 'Early']]);
  await s.idle();
  assert.deepEqual(f.calls, []);
  // The first minute of the window is allowed.
  assert.ok(s.add(item('Gate', { StartTime: '2026-11-13 00:00', EndTime: '2026-11-13 01:00' })));
});

test('a refused update changes and sends nothing and returns false; an end before the start is refused', async () => {
  const f = fake(), refused = [];
  const s = S.create({ items: [item('Ditch', { EventScheduleId: 7 })], send: f.send, saveMeal: true, normalize: editNormalize });
  s.on('refused', r => refused.push(r.message));
  assert.equal(s.update('i7', { StartTime: '2026-11-21 09:00', EndTime: '2026-11-21 08:55' }), false);
  assert.equal(s.update('i7', { StartTime: '2026-11-30 00:00', EndTime: '2026-11-30 01:00' }), false);
  assert.deepEqual([s.get('i7').StartTime, s.get('i7').EndTime], ['2026-11-21 09:00', '2026-11-21 10:00']);
  assert.equal(s.update('i7', { StartTime: '2026-11-29 23:59', EndTime: '2026-11-30 01:00' }), true, 'the last minute of the window is allowed');
  assert.deepEqual(refused, ['An item cannot end before it starts.', 'That time is outside the event.']);
  await s.idle();
  assert.deepEqual(f.calls.map(c => c.map(o => o.op)), [['update']]);
});

test('in a batch each refusal stands alone and refused fires after the batch', () => {
  const f = fake(), events = [];
  const s = S.create({ items: [], send: f.send, saveMeal: true, normalize: editNormalize });
  s.on('refused', r => events.push('refused:' + r.item.Title));
  s.on('change', () => events.push('change'));
  const out = [];
  s.batch(() => {
    out.push(s.add(item('A')));
    out.push(s.add(item('B', { StartTime: '2026-12-25 09:00', EndTime: '2026-12-25 10:00' })));
    assert.deepEqual(events, [], 'nothing fires inside the batch');
  });
  assert.equal(typeof out[0], 'string');
  assert.equal(out[1], null);
  assert.deepEqual(s.items().map(i => i.Title), ['A']);
  assert.deepEqual(events, ['change', 'refused:B']);
});

test('undo and redo bypass normalize: they only bring back states that were valid', async () => {
  const f = fake();
  let calls = 0, refuseAll = false;
  const s = S.create({ items: [item('Ditch', { EventScheduleId: 7 })], send: f.send, saveMeal: true,
    normalize: next => { calls++; return refuseAll ? { refuse: 'No.' } : { item: next }; } });
  s.update('i7', { StartTime: '2026-11-21 11:00', EndTime: '2026-11-21 12:00' });
  const cid = s.add(item('New'));
  assert.equal(calls, 2);
  refuseAll = true;
  assert.ok(s.undo());
  assert.equal(s.get(cid), null);
  assert.ok(s.undo());
  assert.equal(s.get('i7').StartTime, '2026-11-21 09:00');
  assert.ok(s.redo());
  assert.equal(s.get('i7').StartTime, '2026-11-21 11:00');
  assert.ok(s.redo());
  assert.equal(s.get(cid).Title, 'New');
  assert.equal(calls, 2, 'normalize was not asked');
  await s.idle();
});

test('without normalize add and update behave as before (update returns true)', () => {
  const s = S.create({ items: [item('Ditch', { EventScheduleId: 7 })], send: fake().send, saveMeal: true });
  assert.equal(s.update('i7', { StartTime: '2030-01-01 09:00', EndTime: '2030-01-01 08:00' }), true);
  assert.equal(s.get('i7').StartTime, '2030-01-01 09:00');
  assert.ok(s.add(item('Any', { StartTime: '1999-01-01 09:00' })));
});
