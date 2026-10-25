import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

// The browser half of the issuer text model. The PHP half is ScrollText::IssuerLines(),
// tested by tests/Unit/ScrollTextModelTest.php against this same fixture file, so the
// two cannot drift. Pure Node: no page and no app needed.
const T = require('../../../orkui/template/revised-frontend/script/scrollery-textmodel.js');
const fx = JSON.parse(readFileSync('tests/fixtures/scroll-text-model.json', 'utf8'));

for (const c of fx.cases) {
  test(`issuerLines: ${c.name}`, () => {
    const got = T.issuerLines(c.in.title, c.in.name, c.in.useThe, c.in.useOf, c.in.scope);
    expect({ lead: got.lead, article: got.article, place: got.place }).toEqual(c.want);
  });
}

test('issuerLines: the effective form round-trips and keeps the article in name', () => {
  const first = T.issuerLines('', 'The Kingdom of the Wetlands', false, false, 'kingdom');
  expect(first).toEqual({
    lead: 'and the Kingdom of', article: 'the', place: 'Wetlands',
    title: 'Kingdom', useThe: true, useOf: true, name: 'the Wetlands',
  });
  expect(T.issuerLines(first.title, first.name, first.useThe, first.useOf, 'kingdom')).toEqual(first);
});

test('issuerLines: an unsplit name keeps its effective form unchanged', () => {
  expect(T.issuerLines('', 'The Celestial Kingdom', false, false, 'kingdom')).toEqual({
    lead: 'and', article: '', place: 'The Celestial Kingdom',
    title: '', useThe: false, useOf: false, name: 'The Celestial Kingdom',
  });
});
