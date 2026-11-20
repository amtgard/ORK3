const test = require('node:test');
const assert = require('node:assert/strict');
const V = require('../../orkui/template/revised-frontend/script/event-board/evb-view.js');

const item = (id, start, more) => Object.assign({ EventScheduleId: id, Title: 'T' + id, StartTime: start, EndTime: start, Leads: [] }, more);

test('a save reply row takes the page data shape', () => {
  const r = V.pageRow({ EventScheduleId: '7', Title: 'Feast', StartTime: '2026-11-21 17:00:00', EndTime: '2026-11-21 18:00:00',
    Location: null, Description: 'x', Category: 'Feast and Food', SecondaryCategory: null, Menu: null, Cost: 8.5,
    Dietary: null, Allergens: null, Modified: '2026-11-20 10:00:00',
    Leads: [{ MundaneId: '3', Persona: 'Luna', Auto: true }, { MundaneId: 4, Persona: 'Kai' }] });
  assert.deepEqual(r, { EventScheduleId: 7, Title: 'Feast', StartTime: '2026-11-21 17:00:00', EndTime: '2026-11-21 18:00:00',
    Location: '', Description: 'x', Category: 'Feast and Food', SecondaryCategory: '',
    Leads: [{ MundaneId: 3, Persona: 'Luna', Auto: true }, { MundaneId: 4, Persona: 'Kai', Auto: false }],
    Menu: '', Cost: 8.5, Dietary: '', Allergens: '', Modified: '2026-11-20 10:00:00' });
  assert.equal(V.pageRow({ Title: 'no id' }), null);
  assert.equal(V.pageRow(null), null);
});

test('a saved row replaces its item, or joins in start order', () => {
  const items = [item(1, '2026-11-21 09:00:00'), item(2, '2026-11-21 12:00:00')];
  let out = V.withChange(items, { saved: item(3, '2026-11-21 10:00:00') });
  assert.deepEqual(out.map(i => i.EventScheduleId), [1, 3, 2]);
  assert.notEqual(out, items);
  assert.equal(items.length, 2);
  out = V.withChange(items, { saved: item(1, '2026-11-21 13:00:00', { Title: 'moved' }) });
  assert.deepEqual(out.map(i => [i.EventScheduleId, i.Title]), [[2, 'T2'], [1, 'moved']]);
  // Items that start together keep their order; the saved one goes after them.
  out = V.withChange([item(5, '2026-11-21 09:00:00'), item(4, '2026-11-21 09:00:00')], { saved: item(6, '2026-11-21 09:00:00') });
  assert.deepEqual(out.map(i => i.EventScheduleId), [5, 4, 6]);
});

test('a removal drops the item; anything else asks for a re-read', () => {
  const items = [item(1, '2026-11-21 09:00:00'), item(2, '2026-11-21 12:00:00')];
  assert.deepEqual(V.withChange(items, { removed: '2' }).map(i => i.EventScheduleId), [1]);
  assert.equal(V.withChange(items), null);
  assert.equal(V.withChange(items, {}), null);
  assert.equal(V.withChange(items, { saved: { Title: 'no id' } }), null);
});

// ---- refresher: ordering of page reads, list reads and local merges ----

function deferred() {
  let resolve, reject;
  const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
  return { promise, resolve, reject };
}
// A fake page: its HTML is the JSON of {items, list} (list: what the List tab shows).
const html = (items, list) => JSON.stringify({ items, list: list || 'page' });
function rig(opts) {
  opts = opts || {};
  const env = {
    shown: opts.items || [],
    listTab: 'initial',
    pages: [], lists: [], taken: 0, editRefreshes: 0, editing: false,
  };
  const R = V.refresher({
    readPage: () => { const d = deferred(); env.pages.push(d); return d.promise; },
    parsePage: (h) => { const p = JSON.parse(h); return { items: p.items, swap: () => { env.listTab = p.list; } }; },
    readList: () => {
      if (opts.noList) return null;
      const d = deferred(); env.lists.push(d); return d.promise;
    },
    take: (items) => { env.shown = items; env.taken++; },
    items: () => env.shown,
    editing: () => env.editing,
    editRefresh: () => { env.editRefreshes++; },
  });
  return { R, env };
}
const ids = (items) => items.map(i => [i.EventScheduleId, i.Title]);
const tickOver = () => new Promise(r => setImmediate(r));

test('a merge while a page read is on its way does not drop the read (new leads arrive)', async () => {
  const { R, env } = rig({ items: [item(1, '2026-11-21 09:00:00', { Modified: 'a' }), item(2, '2026-11-21 12:00:00', { Modified: 'a' })] });
  const page = R.page();                 // staff save: page read goes out
  await R.change({ saved: item(2, '2026-11-21 12:00:00', { Title: 'saved', Modified: 'b' }) });
  assert.deepEqual(ids(env.shown), [[1, 'T1'], [2, 'saved']]);
  // The page was read before the save: new leads on 1, old copy of 2.
  env.pages[0].resolve(html([item(1, '2026-11-21 09:00:00', { Title: 'new leads', Modified: 'a' }), item(2, '2026-11-21 12:00:00', { Modified: 'a' })], 'v1'));
  await tickOver();
  // Board: the page's leads, with the newer merged row laid over it.
  assert.deepEqual(ids(env.shown), [[1, 'new leads'], [2, 'saved']]);
  // The List was not swapped for the stale copy; the page is read again for it.
  assert.equal(env.listTab, 'initial');
  assert.equal(env.pages.length, 2);
  env.pages[1].resolve(html([item(1, '2026-11-21 09:00:00', { Title: 'new leads', Modified: 'a' }), item(2, '2026-11-21 12:00:00', { Title: 'saved', Modified: 'b' })], 'v2'));
  await page;
  assert.equal(env.listTab, 'v2');
  assert.deepEqual(ids(env.shown), [[1, 'new leads'], [2, 'saved']]);
});

test('a merge after a page read landed changes nothing about it; no extra read', async () => {
  const { R, env } = rig({ items: [item(1, '2026-11-21 09:00:00')] });
  const page = R.page();
  env.pages[0].resolve(html([item(1, '2026-11-21 09:00:00', { Title: 'leads' })], 'v1'));
  await page;
  assert.equal(env.listTab, 'v1');
  await R.change({ saved: item(1, '2026-11-21 09:00:00', { Title: 'saved' }) });
  assert.deepEqual(ids(env.shown), [[1, 'saved']]);
  assert.equal(env.pages.length, 1);
});

test('a page read that already has a later change than the merge keeps it (by Modified)', async () => {
  const { R, env } = rig({ items: [item(1, '2026-11-21 09:00:00', { Modified: '2026-11-20 10:00:00' })] });
  const page = R.page();
  await R.change({ saved: item(1, '2026-11-21 09:00:00', { Title: 'mine', Modified: '2026-11-20 10:05:00' }) });
  env.pages[0].resolve(html([item(1, '2026-11-21 09:00:00', { Title: 'theirs, later', Modified: '2026-11-20 10:09:00' })]));
  await tickOver();
  assert.deepEqual(ids(env.shown), [[1, 'theirs, later']]);
  env.pages[1].resolve(html([item(1, '2026-11-21 09:00:00', { Title: 'theirs, later', Modified: '2026-11-20 10:09:00' })]));
  await page;
});

test('a removal while a page read is out stays removed', async () => {
  const { R, env } = rig({ items: [item(1, '2026-11-21 09:00:00'), item(2, '2026-11-21 12:00:00')] });
  const page = R.page();
  await R.change({ removed: 2 });
  env.pages[0].resolve(html([item(1, '2026-11-21 09:00:00'), item(2, '2026-11-21 12:00:00')]));
  await tickOver();
  assert.deepEqual(env.shown.map(i => i.EventScheduleId), [1]);
  env.pages[1].resolve(html([item(1, '2026-11-21 09:00:00')], 'v2'));
  await page;
  assert.equal(env.listTab, 'v2');
});

test('of two page reads, an older reply landing last is dropped', async () => {
  const { R, env } = rig();
  const a = R.page(), b = R.page();
  env.pages[1].resolve(html([item(1, '2026-11-21 09:00:00', { Title: 'newer' })], 'newer'));
  await b;
  env.pages[0].resolve(html([item(1, '2026-11-21 09:00:00', { Title: 'older' })], 'older'));
  await a;
  assert.equal(env.listTab, 'newer');
  assert.deepEqual(ids(env.shown), [[1, 'newer']]);
});

test('a page read in Edit mode lets the store re-read; a change there goes to the store', async () => {
  const { R, env } = rig();
  env.editing = true;
  const p = R.page();
  env.pages[0].resolve(html([item(1, '2026-11-21 09:00:00')], 'v1'));
  await p;
  assert.equal(env.listTab, 'v1');
  assert.equal(env.editRefreshes, 1);
  await R.change({ saved: item(1, '2026-11-21 09:00:00') });
  assert.equal(env.editRefreshes, 2);
  assert.equal(env.lists.length, 0);
});

test('a change with nothing to merge reads schedule_list and takes it in the page shape', async () => {
  const { R, env } = rig();
  const p = R.change();
  assert.equal(env.lists.length, 1);
  env.lists[0].resolve({ schedule: [{ EventScheduleId: '4', Title: 'Court', StartTime: '2026-11-21 19:00:00' }, { Title: 'no id' }] });
  await p;
  assert.deepEqual(ids(env.shown), [[4, 'Court']]);
  assert.equal(env.shown[0].Location, '');
  assert.equal(env.pages.length, 0);
});

test('a refused or timed-out list read falls back to the page (data only)', async () => {
  const { R, env } = rig();
  const p = R.change();
  env.lists[0].reject(new Error('no answer'));   // fetchList's 30-second limit
  await tickOver();
  assert.equal(env.pages.length, 1);
  env.pages[0].resolve(html([item(9, '2026-11-21 09:00:00')], 'from page'));
  await p;
  assert.deepEqual(env.shown.map(i => i.EventScheduleId), [9]);
  assert.equal(env.listTab, 'initial');
});

test('with no list read on offer (no EvBoardEdit: a feast-only editor) the page is read', async () => {
  const { R, env } = rig({ noList: true });
  const p = R.change({});
  await tickOver();
  assert.equal(env.pages.length, 1);
  env.pages[0].resolve(html([item(3, '2026-11-21 09:00:00')]));
  await p;
  assert.deepEqual(env.shown.map(i => i.EventScheduleId), [3]);
});

test('a list read sent after a page read wins over the page data that lands later, List still swaps', async () => {
  const { R, env } = rig();
  const page = R.page();
  const list = R.change();
  env.lists[0].resolve({ schedule: [item(1, '2026-11-21 09:00:00', { Title: 'list' })] });
  await list;
  env.pages[0].resolve(html([item(1, '2026-11-21 09:00:00', { Title: 'page' })], 'v1'));
  await page;
  assert.deepEqual(ids(env.shown), [[1, 'list']]);
  assert.equal(env.listTab, 'v1');
});

test('a failed page read leaves things as they are and forgets merges once idle', async () => {
  const { R, env } = rig({ items: [item(1, '2026-11-21 09:00:00')] });
  const page = R.page();
  await R.change({ saved: item(1, '2026-11-21 09:00:00', { Title: 'saved' }) });
  env.pages[0].reject(new Error('HTTP 500'));
  await page;
  assert.deepEqual(ids(env.shown), [[1, 'saved']]);
  // A later read is not overlaid with the old merge.
  const p2 = R.page();
  env.pages[1].resolve(html([item(1, '2026-11-21 09:00:00', { Title: 'server' })], 'v2'));
  await p2;
  assert.deepEqual(ids(env.shown), [[1, 'server']]);
  assert.equal(env.pages.length, 2);
});
