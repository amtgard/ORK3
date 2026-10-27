/**
 * The 150-combination procedural sweep: a seeded, stratified matrix of composed certificates.
 *
 * Where the ink sweep (sweep.spec.ts) crosses a few axes exhaustively against synthetic text
 * profiles, this one spreads MANY axes thinly: every order and variant, its rank range, park and
 * kingdom issuers, issuer-name and recipient-name length tiers, reasons, signers, all ten hands,
 * the heading modes, signature hands, and the Customize Design options (Custom Border exact and
 * match, Custom Ribbon, heraldry source, colour pairs). Each axis's levels are dealt out in equal
 * shares and shuffled independently with one seeded PRNG, so every level appears many times,
 * against different partners each time, and the same seed always deals the same 150 cases.
 *
 * Read by combo150-matrix.spec.ts (which writes matrix.json and asks PHP what each case should
 * draw), combo150.spec.ts (which renders and measures) and combo150-report.spec.ts.
 */

export const COMBO_SEED = Number(process.env.ORK3_COMBO_SEED ?? 20261027);
export const COMBO_COUNT = 150;

/* ------------------------------------------------------------------------------------------ */
/* PRNG                                                                                        */
/* ------------------------------------------------------------------------------------------ */

/** mulberry32: small, fast, and the same sequence on every platform for the same seed. */
export function prng(seed: number): () => number {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function shuffle<T>(xs: T[], rnd: () => number): T[] {
  const a = xs.slice();
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(rnd() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

/** `n` items drawn from `levels` in equal shares (remainder spread by weight order), shuffled. */
function deal<T>(levels: T[], n: number, rnd: () => number, weights?: number[]): T[] {
  const w = weights ?? levels.map(() => 1);
  const total = w.reduce((s, x) => s + x, 0);
  const out: T[] = [];
  const exact = levels.map((_, i) => (w[i] / total) * n);
  const counts = exact.map(Math.floor);
  let left = n - counts.reduce((s, x) => s + x, 0);
  const byFrac = exact.map((x, i) => [x - Math.floor(x), i] as const).sort((a, b) => b[0] - a[0]);
  for (const [, i] of byFrac) { if (left-- <= 0) break; counts[i]++; }
  levels.forEach((lv, i) => { for (let k = 0; k < counts[i]; k++) out.push(lv); });
  return shuffle(out, rnd);
}

/* ------------------------------------------------------------------------------------------ */
/* The axes                                                                                    */
/* ------------------------------------------------------------------------------------------ */

/**
 * Where a case is issued from. Read off the local database on 2026-10-27:
 *   kingdom 1  The Kingdom of the Wetlands (arms)       kingdom 10 The Kingdom of the Rising Winds (arms)
 *   park 1     Mordengaard, k1 (arms)                   park 7     Granite Spyre, k1 (arms)
 *   park 797   Direwolf's Rest, k10 (arms)              park 133   Everfield, k10 (NO arms)
 * The printed issuer is the `issuerName` param, so the scope only decides the catalogue, the
 * permission check and which heraldry is available.
 */
export type Scope = { scopeType: 'park' | 'kingdom'; scopeId: number; kingdomId: number; label: string };
export const SCOPES: Scope[] = [
  { scopeType: 'kingdom', scopeId: 1, kingdomId: 1, label: 'K1' },
  { scopeType: 'kingdom', scopeId: 10, kingdomId: 10, label: 'K10' },
  { scopeType: 'park', scopeId: 1, kingdomId: 1, label: 'P1' },
  { scopeType: 'park', scopeId: 7, kingdomId: 1, label: 'P7' },
  { scopeType: 'park', scopeId: 797, kingdomId: 10, label: 'P797' },
  { scopeType: 'park', scopeId: 133, kingdomId: 10, label: 'P133-noarms' },
];

/**
 * The recipient record behind the case (`playerId`): drives the pronoun and the Persona / Park
 * heraldry sources. The printed name is a separate axis.
 *   2433  arms, home park 1049 (arms, kingdom 17)     19669 no arms, park 1049 (arms)
 *   844   no arms, park 797 (arms, k10)               7511  no arms, park 133 (no arms, k10)
 *   2     arms, park 1 (arms, k1)                     1407  no arms, park 3 (no arms, k1)
 *   0     no recipient
 * 1460 is avoided: has_heraldry=1 with no file locally (a data gap, not a defect).
 */
export const PLAYER_IDS = [0, 2433, 19669, 844, 7511, 2, 1407];

export type Issuer = { tier: string; scope: 'park' | 'kingdom'; issuerName: string; issuerTitle: string; useThe: boolean; useOf: boolean };
export const ISSUERS: Issuer[] = [
  // park-style
  { tier: 'short', scope: 'park', issuerName: 'Ash', issuerTitle: 'Shire', useThe: true, useOf: true },
  { tier: 'typical', scope: 'park', issuerName: 'Iron Keep', issuerTitle: 'Barony', useThe: true, useOf: true },
  { tier: 'long', scope: 'park', issuerName: 'Sunset Shores of the Western Reach', issuerTitle: 'Duchy', useThe: true, useOf: true },
  { tier: 'extreme', scope: 'park', issuerName: 'The Most Ancient and Honourable Freehold of the Seven Silver Rivers', issuerTitle: 'Grand Duchy', useThe: true, useOf: true },
  { tier: 'nonascii', scope: 'park', issuerName: 'Främling', issuerTitle: 'Barony', useThe: true, useOf: true },
  { tier: 'article', scope: 'park', issuerName: 'The Marsh', issuerTitle: 'Duchy', useThe: true, useOf: true },
  { tier: 'typical', scope: 'park', issuerName: "Satyr's Crossing", issuerTitle: 'Shire', useThe: false, useOf: false },
  // kingdom-style
  { tier: 'phrase', scope: 'kingdom', issuerName: 'The Kingdom of the Wetlands', issuerTitle: '', useThe: false, useOf: false },
  { tier: 'typical', scope: 'kingdom', issuerName: 'Crystal Groves', issuerTitle: 'Kingdom', useThe: true, useOf: true },
  { tier: 'short', scope: 'kingdom', issuerName: 'Tal Dagore', issuerTitle: 'Kingdom', useThe: false, useOf: true },
  { tier: 'long', scope: 'kingdom', issuerName: 'The Principality of the Crimson Sands', issuerTitle: 'Principality', useThe: true, useOf: true },
  { tier: 'extreme', scope: 'kingdom', issuerName: 'The Celestial Kingdom of the Northern Lights and the Frozen Wastes Beyond', issuerTitle: '', useThe: false, useOf: false },
  { tier: 'nonascii', scope: 'kingdom', issuerName: 'Şadhibek', issuerTitle: 'Principality', useThe: true, useOf: true },
  { tier: 'phrase', scope: 'kingdom', issuerName: 'The Grand Duchy of Wolven Fang', issuerTitle: '', useThe: false, useOf: false },
];

export type Text = { tier: string; value: string };
export const PLAYERS: Text[] = [
  { tier: 'empty', value: '' },
  { tier: 'short', value: 'Bo' },
  { tier: 'typical', value: 'Elora Raven-Goldmoon' },
  { tier: 'typical', value: 'Micah Helstaven' },
  { tier: 'long', value: 'Sir Aldric Thornwood the Unbowed of the Iron Vale' },
  { tier: 'extreme', value: 'Dame Wilhelmina Featherstonehaugh-Montgomery de la Mancha, Warden of the Eastern Marches and Keeper of the Flame' },
  { tier: 'nonascii', value: 'Zoë Ångström-Núñez' },
  { tier: 'allcaps', value: 'GRIMWALD THE UNYIELDING' },
  { tier: 'punct', value: "Ka'thrin O'Malley-d'Arcy" },
  { tier: 'unbreakable', value: 'Wilhelminafeatherstonehaughdelamancha' },
];

export const REASONS: Text[] = [
  { tier: 'empty', value: '' },
  { tier: 'short', value: 'For valor at Midreign.' },
  { tier: 'medium', value: 'For tireless service as Prime Minister of the park, keeping the books, the sign-in sheets and the peace through two hard winters.' },
  { tier: 'long', value: 'Brill was essential for the successful organization and completion of Night Quest this year at Keep, capstoning a tradition of becoming heavily involved in the successful organization and completion of quests at BFP and Midgard for half a decade, Kingdom for a few years now, and Keep twice. While her own brilliant performance has been recommended already, she was able to rely on a strong team and lifted them all.' },
];

export const GIVEN_BY: Text[] = [
  { tier: 'empty', value: '' },
  { tier: 'short', value: 'Bo' },
  { tier: 'typical', value: 'King Aldric II' },
  { tier: 'long', value: 'Her Royal Majesty Queen Seraphina Nightingale of the Wetlands, Monarch' },
  { tier: 'unbreakable', value: "Smiles/Hobi/Can'tCallHimSmileyCanWe?" },
];

export const DATES: Text[] = [
  { tier: 'empty', value: '' },
  { tier: 'short', value: '1st May 2026' },
  { tier: 'longest', value: '27th September 2026' },
  { tier: 'typical', value: '12th December 2025' },
];

export const HANDS = ['chancery', 'sharp', 'pirata', 'cinzel', 'gotisch', 'uncial', 'fondamento', 'garamond', 'unicase', 'metamorphous'];
export const HEADINGS = ['auto', 'classic', 'centered'];
export const SIG_HANDS = ['hand', 'greatvibes', 'pinyon', 'alexbrush', 'allura', 'saintdelafield', 'muellerhoff', 'doulaise', 'cedarville'];

export const COLOUR_PAIRS: { tier: string; color1: string; color2: string }[] = [
  { tier: 'default', color1: '#6AC1FF', color2: '#E84C92' },
  { tier: 'white-black', color1: '#FFFFFF', color2: '#000000' },
  { tier: 'black-white', color1: '#000000', color2: '#FFFFFF' },
  { tier: 'near-parchment', color1: '#F2E6C8', color2: '#E9DBB5' },
  { tier: 'saturated', color1: '#FF0000', color2: '#00FF00' },
  { tier: 'crystal-groves', color1: '#1E4FA3', color2: '#F6B323' },
];
export const RIBBON_COLOURS = ['#4473CF', '#FFFFFF', '#000000', '#F2E6C8', '#FF0000', '#1E4FA3'];

/** Design modes. `heraldry` changes only the heraldry source. */
export type DesignMode = 'none' | 'border' | 'borderMatch' | 'ribbon' | 'border+ribbon' | 'borderMatch+ribbon' | 'heraldry';
const DESIGN_ROTATION: DesignMode[] = ['border', 'borderMatch', 'ribbon', 'border+ribbon', 'heraldry', 'borderMatch+ribbon'];
const DESIGN_ABBR: Record<DesignMode, string> = {
  none: 'plain', border: 'bx', borderMatch: 'bm', ribbon: 'rb', 'border+ribbon': 'bxrb', 'borderMatch+ribbon': 'bmrb', heraldry: 'her',
};
export const HERALDRY_REQUESTS = ['kingdom', 'park', 'persona'];

/** Custom recipes: plain, dark, raster borders, framed grounds (compatible and not). */
export type Recipe = { ground: string; border: string; rankSet: string; label: string };
export const RECIPES: Recipe[] = [
  { ground: 'parchment', border: 'knot', rankSet: 'common', label: 'parchment+knot' },
  { ground: 'parchment', border: 'none', rankSet: 'common', label: 'plain-parchment' },
  { ground: 'cyanotype', border: 'owl', rankSet: 'common', label: 'cyanotype+owl' },
  { ground: 'cyanotype', border: 'knot', rankSet: 'zodiac', label: 'cyanotype+knot' },
  { ground: 'parchment', border: 'crown', rankSet: 'common', label: 'parchment+crown' },
  { ground: 'starfield', border: 'battle', rankSet: 'zodiac', label: 'starfield+battle' },
  { ground: 'jovious', border: 'none', rankSet: 'common', label: 'framed-jovious' },
  { ground: 'sunburst', border: 'none', rankSet: 'garber', label: 'framed-sunburst' },
  { ground: 'linen', border: 'none', rankSet: 'common', label: 'framed-linen' },
  { ground: 'map', border: 'none', rankSet: 'flame', label: 'framed-map' },
  { ground: 'flame', border: 'none', rankSet: 'flame', label: 'framed-flame' },
  { ground: 'rosewash', border: 'rose', rankSet: 'garber', label: 'rosewash+rose' },
  { ground: 'forge', border: 'smith', rankSet: 'common', label: 'forge+smith' },
  { ground: 'arcane', border: 'hydra', rankSet: 'common', label: 'arcane+hydra' },
  { ground: 'starfield2', border: 'mask', rankSet: 'zodiac', label: 'starfield2+mask' },
  { ground: 'parchment', border: 'lion', rankSet: 'common', label: 'parchment+lion' },
];
/** Scroll::DEFAULT_RECIPE's pair, as drive.ts sends it. */
export const RECIPE_COLOURS = { primary: 'hsl(35, 29%, 55%)', secondary: 'hsl(34, 72%, 78%)' };

export const CUSTOM_NAMES: Text[] = [
  { tier: 'short', value: 'Order of the Bee' },
  { tier: 'typical', value: 'Guardian of the Hearth' },
  { tier: 'cap64', value: 'Guildmaster of Reeves, Principality of the Crimson Sands Order' },
  { tier: 'nonascii', value: 'Ordre de la Fleur-de-Lys Dorée' },
];

/**
 * The composer catalogue the matrix composes against, read from Scrollery/create/kingdom/{1,10}
 * on 2026-10-27 (the option value and data-name the controller re-matches on). The regular
 * orders carry identical names in both kingdoms. hunter is reachable ONLY on kingdom 10, as
 * "Order of the Archer" (award 94 recognised by name); kingdom 1 has no hunter row. combo150.spec
 * checks every render's .award-template[data-order] against the order asked for.
 */
export const CATALOGUE: Record<string, { awardId: number; title: string; min: number; max: number }> = {
  battle: { awardId: 243, title: 'Order of Battle', min: 0, max: 10 },
  crown: { awardId: 239, title: 'Order of the Crown', min: 0, max: 10 },
  dragon: { awardId: 25, title: 'Order of the Dragon', min: 0, max: 10 },
  flame: { awardId: 34, title: 'Order of the Flame', min: 0, max: 10 },
  garber: { awardId: 26, title: 'Order of the Garber', min: 0, max: 10 },
  griffon: { awardId: 33, title: 'Order of the Griffin', min: 0, max: 10 },
  jovious: { awardId: 28, title: 'Order of the Jovius', min: 0, max: 10 },
  lion: { awardId: 23, title: 'Order of the Lion', min: 0, max: 10 },
  hunter: { awardId: 94, title: 'Order of the Archer', min: 0, max: 10 },
  hydra: { awardId: 32, title: 'Order of the Hydra', min: 0, max: 10 },
  mask: { awardId: 29, title: 'Order of the Mask', min: 0, max: 10 },
  owl: { awardId: 24, title: 'Order of the Owl', min: 0, max: 10 },
  rose: { awardId: 21, title: 'Order of the Rose', min: 0, max: 10 },
  smith: { awardId: 22, title: 'Order of the Smith', min: 0, max: 10 },
  warrior: { awardId: 27, title: 'Order of the Warrior', min: 1, max: 10 },
  zodiac: { awardId: 30, title: 'Order of the Zodiac', min: 0, max: 10 },
};
/** Custom rows that take a typed name (data-custom-name), per kingdom, and one named row. */
export const CUSTOM_ROWS: Record<number, { awardId: number; title: string }[]> = {
  1: [{ awardId: 246, title: 'Custom Title' }],
  10: [{ awardId: 94, title: 'Custom Award' }, { awardId: 246, title: 'Custom Title' }],
};
export const CUSTOM_NAMED_ROW = { kingdomId: 1, awardId: 94, title: 'Order of the Awesome' };

/** Orders whose border a Custom Border can never replace (data/scroll-custom-border.php). */
export const INCOMPATIBLE_ORDERS = ['flame', 'garber', 'zodiac'];

/* ------------------------------------------------------------------------------------------ */
/* The case                                                                                    */
/* ------------------------------------------------------------------------------------------ */

export type Design = {
  mode: DesignMode;
  heraldrySource: string;      // '' = scope default
  customBorder: boolean;
  matchStyle: boolean;
  color1: string; color2: string; colourTier: string;
  customRibbon: boolean;
  ribbonColor: string;
};

export type ComboCase = {
  id: string;
  n: number;
  driver: 'compose';
  order: string;
  variant: string;
  rank: number;
  scope: Scope;
  awardId: number;
  title: string;
  customName?: string;
  customNameTier?: string;
  titlePhrase?: string;
  recipe?: Recipe;
  issuer: Issuer;
  player: Text;
  playerId: number;
  pronounOverride: string;
  reason: Text;
  givenBy: Text;
  date: Text;
  hand: string;
  heading: string;
  sig: string;
  design: Design;
  /** Filled by combo150-matrix.spec from PHP (Scroll::CustomBorderRule, heraldry_sources). */
  expect?: Expectation;
};

export type Expectation = {
  compatible: boolean;            // CustomBorderRule() !== null
  passes: number;                 // rule passes (match-mode knot count)
  knots: number;                  // what should be on the page
  groundSwap: string | null;      // frameless ground the rule swaps in (when knots > 0)
  heraldrySources: Record<string, string>;
  heraldrySource: string;         // ResolveHeraldrySource() answer
  heraldryUrl: string;            // '' = no arms drawn
  heraldryFellBack: boolean;      // requested a source that is unavailable
  recipe?: Record<string, string>;// NormaliseRecipe() answer for custom
  design: Record<string, unknown>;// NormaliseDesign() answer
};

function ranksFor(order: string, n: number, rnd: () => number): number[] {
  const c = CATALOGUE[order] ?? { min: 0, max: 10 };
  const base = order === 'warrior' ? [1, 9, 10, 5, 2, 8] : [c.min, 5, c.max];
  const out = base.slice(0, n);
  while (out.length < n) out.push(c.min + Math.floor(rnd() * (c.max - c.min + 1)));
  return shuffle(out, rnd);
}

/** Every order-variant and how many cases it gets; 150 in all. */
export function orderSlots(): { order: string; variant: string; n: number }[] {
  const ov: [string, string, number][] = [
    ['battle', 'arcane', 7], ['battle', 'martial', 7], ['crown', '', 7], ['dragon', '', 7], ['flame', '', 7],
    ['garber', '', 7], ['griffon', '', 7], ['jovious', '', 7], ['lion', '', 7], ['hunter', '', 7],
    ['hydra', '', 7], ['mask', '', 7], ['owl', 'parchment', 7], ['owl', 'cyanotype', 7], ['owl', 'transparency', 7],
    ['rose', '', 7], ['smith', '', 7], ['warrior', '', 8], ['zodiac', '', 7], ['custom', '', 16],
  ];
  return ov.map(([order, variant, n]) => ({ order, variant, n }));
}

export function generate(seed = COMBO_SEED): ComboCase[] {
  const rnd = prng(seed);
  const slots = orderSlots();
  const total = slots.reduce((s, x) => s + x.n, 0);
  if (total !== COMBO_COUNT) throw new Error(`order slots sum to ${total}, not ${COMBO_COUNT}`);

  /* Per-order rows: rank and design mode are stratified WITHIN the order, so every order gets
     its min/mid/max ranks and a spread of design modes (two or three plain per order, ~30%). */
  type Row = { order: string; variant: string; rank: number; mode: DesignMode; recipe?: Recipe };
  const rows: Row[] = [];
  slots.forEach((s, si) => {
    const ranks = ranksFor(s.order, s.n, rnd);
    const plain = s.order === 'custom' ? 5 : (si % 3 === 0 ? 3 : 2);
    const modes: DesignMode[] = [];
    for (let i = 0; i < plain; i++) modes.push('none');
    for (let i = 0; modes.length < s.n; i++) modes.push(DESIGN_ROTATION[(si + i) % DESIGN_ROTATION.length]);
    const dealt = shuffle(modes, rnd);
    for (let i = 0; i < s.n; i++) {
      const row: Row = { order: s.order, variant: s.variant, rank: ranks[i], mode: dealt[i] };
      if (s.order === 'custom') row.recipe = RECIPES[i % RECIPES.length];
      rows.push(row);
    }
  });

  /* Warrior 1 and 9 are the rank-specific incompatibles: make sure each asks for a Custom
     Border, so the "original border, no knot" fallback is exercised. Same for the three framed
     custom grounds without a frameless twin. */
  for (const r of rows) {
    if (r.order === 'warrior' && r.rank === 1) r.mode = 'border';
    if (r.order === 'warrior' && r.rank === 9) r.mode = 'borderMatch+ribbon';
    if (r.order === 'custom' && r.recipe && ['linen', 'map', 'flame'].includes(r.recipe.ground)) {
      r.mode = r.recipe.ground === 'linen' ? 'border' : r.recipe.ground === 'map' ? 'borderMatch' : 'border+ribbon';
    }
    if (r.order === 'custom' && r.recipe && ['jovious', 'sunburst'].includes(r.recipe.ground)) {
      r.mode = r.recipe.ground === 'jovious' ? 'border' : 'borderMatch';
    }
  }

  const order = shuffle(rows.map((_, i) => i), rnd);
  const N = rows.length;

  /* The cross-cutting axes, dealt independently in equal shares. */
  const scopes = deal(SCOPES, N, rnd);
  const players = deal(PLAYERS, N, rnd);
  const playerIds = deal(PLAYER_IDS, N, rnd);
  const reasons = deal(REASONS, N, rnd);
  const givers = deal(GIVEN_BY, N, rnd);
  const dates = deal(DATES, N, rnd);
  const hands = deal(HANDS, N, rnd);
  const headings = deal(HEADINGS, N, rnd, [3, 1, 1]);
  const sigs = deal(SIG_HANDS, N, rnd, [10, 1, 1, 1, 1, 1, 1, 1, 1]);
  const colours = deal(COLOUR_PAIRS, N, rnd);
  const ribbons = deal(RIBBON_COLOURS, N, rnd);
  const heraldry = deal(['', ...HERALDRY_REQUESTS], N, rnd, [1, 2, 2, 2]);
  const parkIssuers = deal(ISSUERS.filter(i => i.scope === 'park'), N, rnd);
  const kingdomIssuers = deal(ISSUERS.filter(i => i.scope === 'kingdom'), N, rnd);
  const customNames = deal(CUSTOM_NAMES, N, rnd);
  const pronouns = deal(['', '', '', '', '', 'she/her', 'they/them', 'he/him'], N, rnd);

  const cases: ComboCase[] = [];
  order.forEach((ri, k) => {
    const r = rows[ri];
    let scope = scopes[k];
    /* hunter exists only in kingdom 10's catalogue. */
    if (r.order === 'hunter' && scope.kingdomId !== 10) {
      scope = SCOPES.find(s => s.kingdomId === 10 && s.scopeType === scope.scopeType)!;
    }
    const issuer = scope.scopeType === 'park' ? parkIssuers[k] : kingdomIssuers[k];
    const mode = r.mode;
    const border = mode.startsWith('border');
    const match = mode.startsWith('borderMatch');
    const ribbon = mode.endsWith('ribbon');
    const design: Design = {
      mode,
      heraldrySource: mode === 'none' ? '' : (mode === 'heraldry' ? (heraldry[k] || 'persona') : heraldry[k]),
      customBorder: border,
      matchStyle: match,
      color1: border ? colours[k].color1 : '', color2: border ? colours[k].color2 : '',
      colourTier: border ? colours[k].tier : '',
      customRibbon: ribbon,
      ribbonColor: ribbon ? ribbons[k] : '',
    };

    let awardId: number, title: string, customName: string | undefined, customNameTier: string | undefined, titlePhrase: string | undefined;
    if (r.order === 'custom') {
      /* One named row on kingdom 1 (prints its own name), the rest typed names. */
      if (r.recipe?.label === 'parchment+lion') {
        scope = SCOPES.find(s => s.kingdomId === CUSTOM_NAMED_ROW.kingdomId && s.scopeType === scope.scopeType)!;
        awardId = CUSTOM_NAMED_ROW.awardId; title = CUSTOM_NAMED_ROW.title;
      } else {
        const rowsK = CUSTOM_ROWS[scope.kingdomId];
        const row = rowsK[k % rowsK.length];
        awardId = row.awardId; title = row.title;
        customName = customNames[k].value; customNameTier = customNames[k].tier;
        if (k % 5 === 0) titlePhrase = 'and named';
      }
    } else {
      const c = CATALOGUE[r.order];
      awardId = c.awardId; title = c.title;
    }

    const n = k + 1;
    const ov = r.order + (r.variant ? '-' + r.variant : '');
    const rec = r.recipe ? '-' + r.recipe.label : '';
    const id = `c${String(n).padStart(3, '0')}-${ov}-r${r.rank}${rec}-${scope.scopeType === 'park' ? 'park' : 'kdom'}-${DESIGN_ABBR[mode]}`;
    cases.push({
      id, n, driver: 'compose',
      order: r.order, variant: r.variant, rank: r.rank, scope,
      awardId, title,
      ...(customName !== undefined ? { customName, customNameTier } : {}),
      ...(titlePhrase !== undefined ? { titlePhrase } : {}),
      ...(r.recipe ? { recipe: r.recipe } : {}),
      issuer,
      player: players[k], playerId: playerIds[k], pronounOverride: pronouns[k],
      reason: reasons[k], givenBy: givers[k], date: dates[k],
      hand: hands[k], heading: headings[k], sig: sigs[k],
      design,
    });
  });
  return cases;
}

/** The compose URL for a case, relative to the app's /orkui/. */
export function composeUrl(c: ComboCase): string {
  const q = new URLSearchParams({ Route: 'Scrollery/compose' });
  q.set('scopeType', c.scope.scopeType);
  q.set('scopeId', String(c.scope.scopeId));
  q.set('awardId', String(c.awardId));
  q.set('title', c.title);
  q.set('rank', String(c.rank));
  q.set('variant', c.variant);
  q.set('player', c.player.value);
  if (c.playerId) q.set('playerId', String(c.playerId));
  if (c.pronounOverride) q.set('pronounOverride', c.pronounOverride);
  q.set('reason', c.reason.value);
  q.set('givenBy', c.givenBy.value);
  q.set('date', c.date.value);
  q.set('issuerName', c.issuer.issuerName);
  q.set('issuerTitle', c.issuer.issuerTitle);
  if (c.issuer.useThe) q.set('useThe', '1');
  if (c.issuer.useOf) q.set('useOf', '1');
  if (c.customName !== undefined) q.set('customName', c.customName);
  if (c.titlePhrase !== undefined) q.set('titlePhrase', c.titlePhrase);
  if (c.recipe) {
    q.set('ground', c.recipe.ground);
    q.set('border', c.recipe.border);
    q.set('rankSet', c.recipe.rankSet);
    q.set('primary', RECIPE_COLOURS.primary);
    q.set('secondary', RECIPE_COLOURS.secondary);
  }
  const d = c.design;
  if (d.heraldrySource) q.set('heraldrySource', d.heraldrySource);
  if (d.customBorder) {
    q.set('customBorder', '1');
    q.set('color1', d.color1);
    q.set('color2', d.color2);
    if (d.matchStyle) q.set('matchStyle', '1');
  }
  if (d.customRibbon) {
    q.set('customRibbon', '1');
    q.set('ribbonColor', d.ribbonColor);
  }
  /* face, heading and sig are ALWAYS in the URL: without face the page re-applies a remembered
     hand, and without sig a remembered signature hand. */
  q.set('face', c.hand);
  q.set('heading', c.heading);
  q.set('sig', c.sig);
  return './index.php?' + q.toString();
}

/** A short one-line description for contact-sheet tiles. */
export function summary(c: ComboCase): string {
  const d = c.design;
  const parts = [
    `${c.scope.label} ${c.hand}/${c.heading}${c.sig !== 'hand' ? '/' + c.sig : ''}`,
    `iss:${c.issuer.tier} name:${c.player.tier} rsn:${c.reason.tier} by:${c.givenBy.tier}`,
  ];
  const des: string[] = [];
  if (d.customBorder) des.push((d.matchStyle ? 'border-match ' : 'border ') + d.colourTier);
  if (d.customRibbon) des.push('ribbon ' + d.ribbonColor);
  if (d.heraldrySource) des.push('her:' + d.heraldrySource);
  parts.push(des.length ? des.join(' | ') : 'no design');
  return parts.join(' · ');
}

/** Five review batches of 30, orders mixed: sort by order, deal round-robin. */
export function batches(cases: ComboCase[], k = 5): string[][] {
  const sorted = cases.slice().sort((a, b) => (a.order + a.variant).localeCompare(b.order + b.variant) || a.n - b.n);
  const out: string[][] = Array.from({ length: k }, () => []);
  sorted.forEach((c, i) => out[i % k].push(c.id));
  return out;
}
