/*
 * The automated gates of the typeset engine (spec 10.2), as pure functions over what
 * corpus.spec.ts measured. Nothing here touches a page, a disk or the network, so the
 * rules can be tried against hand-built layouts (tests/gates.spec.ts) without the app.
 *
 * Each gate returns every offender with the reason it offends, never a bare boolean:
 * a gate that says "failed" sends someone to read a contact sheet to find out why.
 *
 * TWO CHOICES THAT MAKE THESE STRICT
 *
 * A gate over an attribute the engine writes (data-sc-planned, data-sc-budget,
 * data-sc-ms, a `.sc-line`) fails when the attribute is not there. Reading "no overflow
 * flags" off a page that has no engine would pass, and a gate that passes because nothing
 * was measured is the failure this harness exists to prevent. So a corpus rendered by a
 * build without the engine is red on Q2, Q3, Q9 and Q11 for that reason, and the offender
 * says so ("no data-sc-planned"). Q1, Q4, Q7 and Q8 read what is painted, which exists
 * either way, so they grade today's rendering too.
 *
 * Sizes are root em: the case's font size over `.Award`'s own, which is what the role
 * table in spec 6.4.1 is written in. They are rounded to three places on the way in, so a
 * comparison allows EPS_EM, twice the largest error that rounding can make.
 */

export type RoleName = 'name' | 'award' | 'place' | 'lead';

export type RoleMeasure = {
  sizeEm: number;
  /** Lines counted from where the text is painted (independent of the engine). */
  lines: number;
  /** data-sc-lines: the count the engine says it printed. */
  engineLines?: number;
  /** data-sc-planned: the count the engine planned. */
  planned?: number;
  overflow?: boolean;
};

export type CaseMeasure = {
  id: string;
  roles: Partial<Record<RoleName, RoleMeasure>>;
  /** Size of the "Amtgard" versal, root em. */
  versalEm?: number;
  rootEm?: number;
  comp?: string;
  budget?: string;
  ms?: number;
  /** Every `.sc-line` on the certificate that is wider than its allowed width + 0.5px. */
  wideLines?: Array<{ role: string; w: number; allowed: number | null }>;
  /** How many `.sc-line` elements there were; zero means the engine's markup is absent. */
  scLineCount?: number;
  /** The rendered issuer text: the lead, the place, and the two joined by a space. */
  issuerText?: string[];
  /** False for a certificate with no issuer, which may lack a place and a lead. Absent means it has one. */
  hasIssuer?: boolean;
  /** Multi-up factor the case was captured at (spec 6.4.1 FLOOR_SCALE applies from 2). */
  up?: number;
  error?: string;
};

export type CaseInfo = { id: string; scope?: string; up?: number; group?: string };

export type GateResult = { gate: string; title: string; ok: boolean; offenders: string[]; summary: string };

/** The role table of spec 6.4.1, the columns the hierarchy gate needs. */
export const ROLE_RANGE: Record<RoleName, { min: number; max: number }> = {
  name: { min: 0.8, max: 2.3 },
  award: { min: 0.7, max: 1.5 },
  place: { min: 0.62, max: 1.3 },
  lead: { min: 0.7, max: 1.04 },
};
/** Every min is multiplied by this at up=2|4, capped at max (spec 6.4.1). */
export const FLOOR_SCALE = 1.5;
export const EPS_EM = 0.003;
export const P95_MS_LIMIT = 20;
export const PLACE_PARITY = 0.2;
export const WIDTH_SLACK_PX = 0.5;

const minOf = (role: RoleName, up?: number): number => {
  const r = ROLE_RANGE[role];
  return up && up >= 2 ? Math.min(r.max, r.min * FLOOR_SCALE) : r.min;
};
const clamp = (x: number, lo: number, hi: number) => Math.min(hi, Math.max(lo, x));
const f3 = (n: number) => (Math.round(n * 1000) / 1000).toString();

/** Healthy cases only; a case that could not be measured is reported by the capture test. */
const measured = (cases: CaseMeasure[]) => cases.filter(c => !c.error);

function result(gate: string, title: string, offenders: string[], okSummary: string, badSummary?: string): GateResult {
  return {
    gate,
    title,
    ok: offenders.length === 0,
    offenders,
    summary: offenders.length === 0 ? okSummary : (badSummary ?? `${offenders.length} offender(s)`),
  };
}

/** Name and award always; place and lead when the certificate has an issuer (the default). */
const requiredRoles = (c: CaseMeasure): RoleName[] => (c.hasIssuer === false ? ['name', 'award'] : ['name', 'award', 'place', 'lead']);

/* Q1 -------------------------------------------------------------------------------- */
export function q1Hierarchy(cases: CaseMeasure[]): GateResult {
  const bad: string[] = [];
  for (const c of measured(cases)) {
    const name = c.roles.name?.sizeEm;
    const award = c.roles.award?.sizeEm;
    const place = c.roles.place?.sizeEm;
    const lead = c.roles.lead?.sizeEm;
    const why: string[] = [];
    // A missing role or versal is a failure, not a rule with nothing to check.
    for (const role of requiredRoles(c)) if (!c.roles[role]) why.push(`no ${role} element measured`);
    if (c.versalEm === undefined) why.push('no versal element found');

    if (name !== undefined && award !== undefined) {
      const lim = 0.85 * name;
      if (award > lim + EPS_EM && award > minOf('award', c.up) + EPS_EM) why.push(`award ${f3(award)} > 0.85*name ${f3(lim)}`);
    }
    if (place !== undefined && name !== undefined) {
      const lim = 0.75 * name;
      if (place > lim + EPS_EM && place > minOf('place', c.up) + EPS_EM) why.push(`place ${f3(place)} > 0.75*name ${f3(lim)}`);
    }
    if (place !== undefined && award !== undefined) {
      const lim = 0.9 * award;
      if (place > lim + EPS_EM && place > minOf('place', c.up) + EPS_EM) why.push(`place ${f3(place)} > 0.90*award ${f3(lim)}`);
    }
    if (lead !== undefined && place !== undefined) {
      const lim = Math.max(minOf('lead', c.up), place);
      if (lead > lim + EPS_EM) why.push(`lead ${f3(lead)} > max(lead.min, place) ${f3(lim)}`);
    }
    if (c.versalEm !== undefined && name !== undefined) {
      const want = clamp(name, 1.2, 1.495);
      if (Math.abs(c.versalEm - want) > EPS_EM) why.push(`versal ${f3(c.versalEm)} != clamp(name, 1.20, 1.495) ${f3(want)}`);
    }
    if (why.length) bad.push(`${c.id}: ${why.join('; ')}`);
  }
  return result('Q1', 'hierarchy', bad, 'no hierarchy violations');
}

/* Q2 -------------------------------------------------------------------------------- */
export function q2PlannedIsPrinted(cases: CaseMeasure[]): GateResult {
  const bad: string[] = [];
  for (const c of measured(cases)) {
    const why: string[] = [];
    for (const role of ['name', 'award', 'place', 'lead'] as RoleName[]) {
      const r = c.roles[role];
      if (!r) {
        if (requiredRoles(c).includes(role)) why.push(`${role}: no element measured`);
        continue;
      }
      if (r.planned === undefined) {
        why.push(`${role}: no data-sc-planned`);
      } else if (r.engineLines === undefined) {
        why.push(`${role}: no data-sc-lines`);
      } else if (r.planned !== r.engineLines || r.planned !== r.lines) {
        why.push(`${role}: planned ${r.planned}, engine says ${r.engineLines}, painted ${r.lines}`);
      }
    }
    if (why.length) bad.push(`${c.id}: ${why.join('; ')}`);
  }
  return result('Q2', 'planned = printed', bad, 'planned, reported and painted line counts agree on every role');
}

/* Q3 -------------------------------------------------------------------------------- */
export function q3Width(cases: CaseMeasure[]): GateResult {
  const bad: string[] = [];
  for (const c of measured(cases)) {
    const flagged = Object.values(c.roles).some(r => r?.overflow);
    if (flagged) continue; // the engine said so itself; Q9 counts these
    if (!c.scLineCount) {
      bad.push(`${c.id}: no .sc-line elements`);
      continue;
    }
    const wide = c.wideLines ?? [];
    if (wide.length) bad.push(`${c.id}: ${wide.map(w => (w.allowed === null ? `${w.role} line ${f3(w.w)}px has no data-sc-w` : `${w.role} line ${f3(w.w)}px > ${f3(w.allowed)}px`)).join('; ')}`);
  }
  return result('Q3', 'width', bad, 'every .sc-line is within its allowed width');
}

/* Q4 -------------------------------------------------------------------------------- */
/** `forced` maps a composition ('classic' | 'centered') to the cases captured with that heading forced. */
export function q4LeadNeverWraps(cases: CaseMeasure[], forced: Record<string, CaseMeasure[]> = {}): GateResult {
  const bad: string[] = [];
  const check = (label: string, c: CaseMeasure) => {
    const lead = c.roles.lead;
    if (!lead) return;
    const counts = [lead.lines, ...(lead.engineLines !== undefined ? [lead.engineLines] : [])];
    if (counts.some(n => n !== 1)) bad.push(`${label}: lead printed ${lead.lines} line(s)${lead.engineLines !== undefined ? `, engine says ${lead.engineLines}` : ''}`);
  };
  for (const c of measured(cases)) check(c.comp ? `${c.id} [${c.comp}]` : c.id, c);
  for (const [comp, list] of Object.entries(forced)) for (const c of measured(list)) check(`${c.id} [${comp} forced]`, c);
  return result('Q4', 'lead never wraps', bad, 'the lead is one line in every composition');
}

/* Q7 -------------------------------------------------------------------------------- */
const median = (xs: number[]): number => {
  const s = xs.slice().sort((a, b) => a - b);
  const m = Math.floor(s.length / 2);
  return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
};

/** `infos` says each case's scope (kingdom or park); `group` names the corpus, so each is graded alone. */
export function q7Parity(cases: CaseMeasure[], infos: CaseInfo[]): GateResult {
  const scopeOf = new Map(infos.map(i => [i.id, i]));
  const groups = new Map<string, { kingdom: number[]; park: number[] }>();
  for (const c of measured(cases)) {
    const info = scopeOf.get(c.id);
    const size = c.roles.place?.sizeEm;
    if (!info || size === undefined || (info.scope !== 'kingdom' && info.scope !== 'park')) continue;
    const g = groups.get(info.group ?? '') ?? { kingdom: [], park: [] };
    g[info.scope].push(size);
    groups.set(info.group ?? '', g);
  }
  const bad: string[] = [];
  const notes: string[] = [];
  for (const [name, g] of groups) {
    const label = name ? `${name}: ` : '';
    if (!g.kingdom.length || !g.park.length) {
      bad.push(`${label}cannot compare, ${g.kingdom.length} kingdom and ${g.park.length} park case(s)`);
      continue;
    }
    const mk = median(g.kingdom);
    const mp = median(g.park);
    const ratio = mk / mp;
    notes.push(`${label}kingdom median ${f3(mk)} (n=${g.kingdom.length}), park median ${f3(mp)} (n=${g.park.length}), ratio ${f3(ratio)}`);
    if (Math.abs(ratio - 1) > PLACE_PARITY) bad.push(`${label}kingdom median ${f3(mk)} vs park median ${f3(mp)}: ratio ${f3(ratio)}, outside 1 +/- ${PLACE_PARITY}`);
  }
  if (!groups.size) bad.push('no place sizes were measured');
  const r = result('Q7', 'place parity', bad, notes.join(' | '));
  if (!r.ok && notes.length) r.summary = notes.join(' | ');
  return r;
}

/* Q8 -------------------------------------------------------------------------------- */
export const OF_THE = /\bof The\b/;
export function q8TextModel(cases: CaseMeasure[]): GateResult {
  const bad: string[] = [];
  for (const c of measured(cases)) {
    const hit = (c.issuerText ?? []).find(t => OF_THE.test(t));
    if (hit !== undefined) bad.push(`${c.id}: "${hit}"`);
  }
  return result('Q8', 'text model', bad, 'no rendered issuer text contains "of The"');
}

/* Q9 -------------------------------------------------------------------------------- */
/**
 * `before` is the pass-3 case ids of the frozen corpus before the engine (T1b), and
 * `scope` the ids of the cases that corpus is made of. Pass it only for a corpus that has
 * one; a random corpus has no "before" and is held to zero floor and zero overflow alone.
 */
export function q9Budget(cases: CaseMeasure[], before?: { ids: string[]; scope: string[] }): GateResult {
  const bad: string[] = [];
  const ok = measured(cases);
  for (const c of ok) {
    const why: string[] = [];
    if (c.budget === undefined) why.push('no data-sc-budget');
    else if (c.budget === 'floor') why.push('budget at the floor');
    const over = (Object.entries(c.roles) as [RoleName, RoleMeasure][]).filter(([, r]) => r.overflow).map(([n]) => n);
    if (over.length) why.push(`data-sc-overflow on ${over.join(', ')}`);
    if (why.length) bad.push(`${c.id}: ${why.join('; ')}`);
  }
  let summary = 'no case at the budget floor or overflowing';
  if (before) {
    const inScope = new Set(before.scope);
    const scaled = ok.filter(c => inScope.has(c.id) && c.rootEm !== undefined && c.rootEm < 0.999).map(c => c.id);
    summary += `; pass 3 fired on ${scaled.length} of the frozen corpus, before ${before.ids.length}`;
    if (scaled.length > before.ids.length) {
      const fresh = scaled.filter(id => !before.ids.includes(id));
      bad.push(`${fresh[0] ?? scaled[0]}: pass 3 fires on ${scaled.length} frozen cases (${scaled.join(', ')}), more than the ${before.ids.length} before the engine (${before.ids.join(', ')})`);
    }
  }
  return result('Q9', 'budget', bad, summary);
}

/* Q11 ------------------------------------------------------------------------------- */
export function q11Performance(cases: CaseMeasure[]): GateResult {
  const ok = measured(cases);
  const bad: string[] = [];
  const missing = ok.filter(c => c.ms === undefined).map(c => c.id);
  const ms = ok.map(c => c.ms).filter((x): x is number => x !== undefined).sort((a, b) => a - b);
  if (!ms.length) {
    bad.push('no data-sc-ms on any case, so there is nothing to time');
    return result('Q11', 'performance', bad, '');
  }
  if (missing.length) bad.push(`no data-sc-ms: ${missing.join(', ')}`);
  const p95 = ms[Math.min(ms.length - 1, Math.ceil(ms.length * 0.95) - 1)];
  if (p95 > P95_MS_LIMIT) {
    const slow = ok.filter(c => c.ms !== undefined && c.ms > P95_MS_LIMIT).map(c => `${c.id} ${c.ms}ms`);
    bad.push(`p95 ${p95}ms > ${P95_MS_LIMIT}ms; over the limit: ${slow.join(', ')}`);
  }
  const r = result('Q11', 'performance', bad, `p95 ${p95}ms, median ${ms[Math.floor(ms.length / 2)]}ms over ${ms.length} cases`);
  return r;
}

export function renderGates(rs: GateResult[]): string {
  return rs
    .map(r => `${r.ok ? 'PASS' : 'FAIL'} ${r.gate} ${r.title}: ${r.summary}${r.offenders.length ? '\n' + r.offenders.map(o => '  ' + o).join('\n') : ''}`)
    .join('\n');
}
