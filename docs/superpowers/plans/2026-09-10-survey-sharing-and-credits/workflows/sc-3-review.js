export const meta = {
  name: 'sc-3-review',
  description: 'Survey sharing + credits Task 19: five-lens review of the branch diff, adversarial verification per lens, one fixer for every confirmed finding, then a final recheck',
  phases: [
    { title: 'Review', detail: 'privacy, authorization, credit-engine correctness, layers (skill), spec conformance + tests', model: 'opus' },
    { title: 'Verify', detail: 'one skeptic per lens tries to refute each finding and checks it was introduced by this work', model: 'opus' },
    { title: 'Fix', detail: 'one fixer applies every confirmed finding', model: 'opus' },
    { title: 'Recheck', detail: 'suites, lint, layering grep, re-check of each fixed item', model: 'sonnet' },
  ],
}

// args: { base: '<commit before Task 1>' } — the diff under review is base..HEAD
const A = (typeof args === 'string') ? JSON.parse(args || '{}') : (args || {})
const BASE_REF = A.base || '(find it: the commit that added docs/superpowers/plans/2026-09-10-survey-sharing-and-credits.md)'

const REPO = '/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias'
const SPEC = 'docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md'
const PLAN = 'docs/superpowers/plans/2026-09-10-survey-sharing-and-credits.md'

const COMMON = [
  'You are working in the git repo at ' + REPO + ' on branch feature/survey-module.',
  'The work under review is the "survey sharing + attendance credits" feature: spec ' + SPEC + ', plan ' + PLAN + '. The diff is `git diff ' + BASE_REF + '..HEAD` (read `git log --oneline ' + BASE_REF + '..HEAD` first).',
  'SCOPE RULE: a finding counts only if it is INTRODUCED by that diff (about 60% of raw review findings in this repo turn out to be pre-existing — check `git blame` / the base commit before reporting).',
  'Local facts: app http://localhost:19080/orkui/ ; login POST "index.php?Route=Login/login" with username=heraldsbridge&password=x&Action=Sign+In into a cookie jar; DB docker exec ork3-php8-db mariadb -uroot -proot ork ; tests ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter Survey.',
  'NEVER enable credits on surveys 999048, 999049, 999051. Do not use a browser unless your instructions say so.',
].join('\n')

const FINDINGS = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          id: { type: 'string' },
          file: { type: 'string' },
          line: { type: 'integer' },
          summary: { type: 'string' },
          failure_scenario: { type: 'string' },
          evidence: { type: 'string' },
          severity: { type: 'string', enum: ['blocker', 'major', 'minor'] },
          fix_hint: { type: 'string' },
        },
        required: ['id', 'file', 'summary', 'failure_scenario', 'evidence', 'severity'],
      },
    },
  },
  required: ['findings'],
}

const VERDICTS = {
  type: 'object',
  properties: {
    verdicts: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          id: { type: 'string' },
          confirmed: { type: 'boolean' },
          introduced_by_diff: { type: 'boolean' },
          reason: { type: 'string' },
        },
        required: ['id', 'confirmed', 'introduced_by_diff', 'reason'],
      },
    },
  },
  required: ['verdicts'],
}

const LENSES = [
  {
    key: 'privacy',
    prompt: 'LENS: PRIVACY AND CONSENT. Attack spec D1/D2 and base spec §2. Can any path credit a partial/anonymous/test response? Can a shared (rolled-down) viewer reach rows, CSV, the response panel, include_test, survey-wide counts (starts, audience, excluded_anonymous), or widen the lens (crafted Filters JSON, park_id/impossible keys, kingdom_ids outside the lens, a Context for an org they are not officer of, ORK->park)? Does credit_status leak another kingdom\'s config/credit counts? Can credit timing (entered_at, attendance_id order, event dates) re-link a non-full response? Does park_id ever get stored for partial/anonymous?',
  },
  {
    key: 'authz',
    prompt: 'LENS: AUTHORIZATION AND SECURITY. For every new/changed SurveyAjax action and page route: login, CSRF (credit_status is the only new read-allowlisted action), authority resolved from the SURVEY ROW and validated grantor (validGrantor + canCreate), never from the request alone. Route-segment collapse in Survey/results/{id}/{Kingdom|Park}/{id}. SQL injection (every interpolated value cast or esc()\'d; mysql_real_escape_string is a no-op shim). AddSystemCredit/CreateSystemEvent are token-free: confirm only SurveyCredit calls them. Status codes 1/3/5 correct.',
  },
  {
    key: 'engine',
    prompt: 'LENS: CREDIT ENGINE CORRECTNESS. SurveyCredit coverage/precedence (earliest enabled_at, ties by id, owner event covers visitors, home_park needs a park), idempotency (ledger PK, reconcile twice, concurrent grant), transaction boundaries (attendance+ledger atomic; CreateSystemEvent never inside grant\'s transaction), dates (home_park = DATE(submitted_at), event = occurrence start, startDate = later of opened_at/open_at), class = last class else Color 6, note "Survey #id", entry_method survey, by_whom_id = enabled_by, event one-day published with at_park_id for parks, self-heal when the event is deleted, onOpened hook, gate lock, submit never fails because of a credit, audience rule ignores survey credits, cache busting. Try edge cases: retired park, player moved park after answering, survey re-opened, principality players, event-audience visitors, draft owner config, a config enabled on a closed survey.',
  },
  {
    key: 'layers',
    prompt: 'LENS: LAYERS AND CSS. Invoke the `layers-review` skill (Skill tool) scoped to the diff above and follow it (including its mechanical CSS reuse scan). Then additionally check: dark mode for every new element (computed-style reasoning, especially headings vs orkui.css global h1-h6 pill boxes), 360px behaviour, 44px touch targets, no native dialogs, data-tip tooltips, no SQL / Ork3::$Lib / new DomainClass( under orkui/ outside orkui/model/. Return the skill\'s confirmed findings in this schema.',
  },
  {
    key: 'conformance',
    prompt: 'LENS: SPEC CONFORMANCE AND TESTS. Walk spec §1-§9 and every acceptance criterion in §9 against the code: is each implemented as written (copy strings verbatim — the data-gate credit line, lens strips, warning text, event name prefix)? Is each behaviour covered by a unit or integration test that would fail if it regressed? Report missing behaviour, wrong copy, and untested rules. Run the Survey test filter and report its real result.',
  },
]

phase('Review')
const reviewed = await pipeline(
  LENSES,
  (lens) => agent(COMMON + '\n\n## YOU ARE A REVIEWER. Do NOT modify any file.\n' + lens.prompt +
    '\n\nGive each finding an id "' + lens.key + '-N", a concrete failure scenario (inputs/state -> wrong result) and evidence (file:line, command output or quoted code). Report nothing you cannot evidence.',
    { label: 'review:' + lens.key, phase: 'Review', model: 'opus', effort: 'high', schema: FINDINGS }),
  (found, lens) => {
    const list = (found && found.findings) || []
    if (!list.length) { return { lens: lens.key, findings: [], verdicts: [] } }
    return agent(COMMON + '\n\n## YOU ARE A SKEPTIC. Do NOT modify any file. Try to REFUTE each finding below: reproduce it (read the code, run the command, write a throwaway test in /tmp if needed), and check with git that it was introduced by the diff. ' +
      'Default to confirmed=false when the evidence does not hold up. Return one verdict per id.\n\n' + JSON.stringify(list, null, 2),
      { label: 'verify:' + lens.key, phase: 'Verify', model: 'opus', effort: 'high', schema: VERDICTS })
      .then(v => ({ lens: lens.key, findings: list, verdicts: (v && v.verdicts) || [] }))
  },
)

// Barrier (genuine): the fixer needs every confirmed finding at once to avoid conflicting edits.
const confirmed = []
const rejected = []
for (const r of reviewed.filter(Boolean)) {
  for (const f of r.findings) {
    const v = r.verdicts.find(x => x.id === f.id)
    if (v && v.confirmed && v.introduced_by_diff) { confirmed.push(Object.assign({}, f, { verdict: v.reason })) }
    else { rejected.push({ id: f.id, summary: f.summary, reason: v ? v.reason : 'no verdict returned' }) }
  }
}
log(confirmed.length + ' confirmed findings, ' + rejected.length + ' rejected or out of scope')

let fixReport = null
let recheck = null
if (confirmed.length) {
  phase('Fix')
  const RESUME = A.resumeNote ? '\n\n## RESUMED RUN — READ FIRST\n' + String(A.resumeNote) + '\n' : ''
  fixReport = await agent(COMMON + RESUME + '\n\n## YOU ARE THE FIXER. Apply EVERY confirmed finding below (all severities). For each behavioural fix add or extend a test that fails before and passes after. Follow the plan\'s Global Constraints (normalize-first for PHP, explicit path-limited commits `git add <paths> && git commit -m "Enhancement: Survey — …" -- <paths>` with the trailer lines ' +
    '"Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>" and "Claude-Session: https://claude.ai/code/' + (A.session || 'session_015f7Q1rwtQLdNo6Sx1YwLqm') + '"; never stage class.Authorization.php; never push/stash). ' +
    'If a finding needs the browser to confirm, you may use Claude-in-Chrome (you are alone) — load its tools with one ToolSearch call and open a new tab. Report per finding id: fixed | not-fixed (why) and the commit hash.\n\n' + JSON.stringify(confirmed, null, 2),
    { label: 'fix', phase: 'Fix', model: 'opus', effort: 'high' })

  phase('Recheck')
  recheck = await agent(COMMON + '\n\n## YOU ARE THE RECHECKER. Do NOT modify files. Run the Survey test filter, php -l on every changed PHP/.tpl file, node --check on every changed JS file, and the layering grep ' +
    '(grep -rnE \'\\$DB->|Ork3::\\$Lib|new (Survey|SurveyResponse|SurveyReport|SurveyCredit)\\(\' orkui --include=\'*.php\' --include=\'*.tpl\' | grep -v orkui/model/model.Survey.php). ' +
    'Then re-check each finding below against the fixer\'s report and the code: is it actually fixed? Paste real output.\n\nFINDINGS:\n' + JSON.stringify(confirmed, null, 2) + '\n\nFIXER REPORT:\n' + String(fixReport).slice(0, 5000),
    { label: 'recheck', phase: 'Recheck', model: 'sonnet', effort: 'medium' })
}

return { confirmed, rejected, fixReport: fixReport ? String(fixReport).slice(0, 5000) : null, recheck: recheck ? String(recheck).slice(0, 4000) : null }
