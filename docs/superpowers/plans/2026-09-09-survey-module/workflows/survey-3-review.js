export const meta = {
  name: 'survey-3-review',
  description: 'Survey module Phase 3: six-lens review of the branch, adversarial refutation, grouped fixes, final verification, release note',
  phases: [
    { title: 'Review', detail: 'security+consent, layering+CSS reuse, mobile, dark mode, correctness, a11y — parallel', model: 'opus' },
    { title: 'Refute', detail: 'two independent refuters per finding', model: 'opus' },
    { title: 'Fix', detail: 'confirmed findings grouped by file ownership, fixed in parallel', model: 'opus' },
    { title: 'Verify', detail: 'final verifier (fixes nothing) + release note', model: 'opus' },
  ],
}

const A = (typeof args === 'string') ? JSON.parse(args || '{}') : (args || {})

const REPO = '/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias'
const SPEC = 'docs/superpowers/specs/2026-09-09-survey-module-design.md'
const PLAN = 'docs/superpowers/plans/2026-09-09-survey-module.md'

const COMMON = [
  'You are working in the git repo at ' + REPO + ' on branch feature/survey-module. The survey module (Phases 1 and 2 of ' + PLAN + ') is fully built and committed.',
  'The design is ' + SPEC + '. The branch diff is `git diff master...HEAD` (use `git diff master...HEAD --stat` first).',
  '',
  'HARD RULES:',
  '- Stage EXPLICIT paths only. Never git add -A / git add . Never stage system/lib/ork3/class.Authorization.php, CLAUDE.md, agent-instructions/claude.md.',
  '- Never push. Never git stash. Never create git worktrees.',
  '- .tpl files are PLAIN PHP. Under orkui/ never $DB->, Ork3::$Lib, or new <DomainClass>( outside orkui/model/.',
  '- Local facts: app http://localhost:19080/orkui/ ; routes index.php?Route=Controller/action/arg ; login POST username=heraldsbridge&password=x&Action=Sign+In to Login/login into a cookie jar; heraldsbridge = mundane 46193, kingdom 17, park 1049, ORK admin. DB: docker exec ork3-php8-db mariadb -uroot -proot ork. Unit tests: ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter Survey.',
  '- Commit message prefix "Enhancement: Survey — …" and end the message with the trailer lines:',
  '    Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>',
  '    Claude-Session: https://claude.ai/code/session_016XHnYr7jkzChUZkaaKgKHb',
].join('\n')

const NO_BROWSER = '\nDo NOT use the browser (other agents are running concurrently). Read code, run php -l / phpunit / curl / node --check, and reason from the spec.'

const FINDINGS = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          lens: { type: 'string' },
          file: { type: 'string' },
          line: { type: 'integer' },
          summary: { type: 'string' },
          failure_scenario: { type: 'string' },
          fix_hint: { type: 'string' },
          severity: { type: 'string', enum: ['blocker', 'major', 'minor'] },
        },
        required: ['lens', 'file', 'line', 'summary', 'failure_scenario', 'severity'],
      },
    },
  },
  required: ['findings'],
}

const LENSES = [
  { key: 'security', effort: 'high', prompt: 'SECURITY + CONSENT LEAKAGE. Check every SurveyAjax action for IDOR (scope taken from the request instead of the survey row), missing requireLogin/requireManage, structure-lock bypass, is_test abuse by non-managers, draft readability by managers, whether ANY table or column lets an anonymous/partial response be re-linked to a player (participation has no timestamp; submitted_at truncation; response_id ordering vs. participation insertion; CSV/rows output), CSV formula injection (=,+,-,@ prefixes), image upload (is_uploaded_file, exif_imagetype, size cap, path construction), markdown/HTML injection (Parsedown safe mode on every user-authored markdown path; DOMPurify on the client), and XSS in every template echo of survey-authored text.' },
  { key: 'layering', effort: 'medium', prompt: 'LAYERING + CSS REUSE. Grep orkui/ for $DB->, Ork3::$Lib, new Survey(, new SurveyResponse(, new SurveyReport(, new SurveyTypes( — only orkui/model/model.Survey.php may match. Check the model facade contains no logic. Then scan survey.css, survey-build.css, survey-results.css, the .svb- rules in default.theme and any inline <style> in the four Survey_*.tpl for rules duplicated from each other or from reports.css/orkui.css, hardcoded colours where an --ork-* or --sv-* token exists, and inline style attributes that dark mode cannot reach. Report each duplicate with both locations.' },
  { key: 'mobile', effort: 'medium', prompt: 'MOBILE. Read the runner, builder and results markup/CSS for: elements that can exceed 360px (matrix tables, ranking rows, chart cards, filter sidebar, DataTable), tap targets under 44px, text inputs under 16px (iOS zoom), missing inputmode/type on number and date inputs, the builder bottom sheet at <=900px, hover-only affordances, sticky headers eating the viewport, and the banner wrapping at 600px.' },
  { key: 'darkmode', effort: 'medium', prompt: 'DARK MODE + PRINT. For every new selector with a colour/background/border in survey*.css, the theme .svb- block and any inline <style>: is there an html[data-theme="dark"] counterpart? Check Highcharts theming in survey-results.js (backgroundColor transparent, axis/grid/legend/tooltip colours, redraw on theme change), the global orkui.css h1-h6 pill-box rule (new headings must reset background/border/padding in BOTH light and dark), and @media print for the results page.' },
  { key: 'correctness', effort: 'high', prompt: 'CORRECTNESS. Compare SurveyTypes::isShown with the JS port in survey-take.js (same truth table). Check: structure lock covers option_set id changes and question_move; page_delete moves questions; response_count maintenance; submitted_at truncation timezone (server NOW() vs PHP date); ranking Borda/mean_rank maths; matrix weighted_mean only when every column has value_num; NPS boundaries (6/7, 8/9); histogram bin edges; median on even counts; cross-tab excluding anonymous rows when kingdom filter is set; eligibility for principality members; draft deletion inside the submit transaction; clone copying image files and resetting response_count; slug collision retry; setStatus("open") validation of show_if precedence.' },
  { key: 'a11y', effort: 'medium', prompt: 'ACCESSIBILITY. Labels for every control (rating/NPS buttons need aria-label with the value and end labels), fieldset/legend for choice groups, aria-live for validation and autosave state, focus moved to the first error on Next/submit, keyboard-operable ranking (up/down buttons, not only drag), keyboard-operable builder canvas selection, the banner close button labelled, Highcharts containers with an accessible summary or table fallback, colour-only meaning (required dot, status pills).' },
]

const RECHECK = (Array.isArray(A.recheck) && A.recheck.length) ? '\n\nRecently fixed browser findings in your area (verify the fixes by reading the code; regressions here are findings):\n' + A.recheck.map(s => '- ' + s).join('\n') : ''

phase('Review')
const reviews = await parallel(LENSES.map(l => () =>
  agent(COMMON + NO_BROWSER + '\n\n## YOU ARE A REVIEWER — lens: ' + l.key + '\n' + l.prompt + ((l.key === 'mobile' || l.key === 'darkmode' || l.key === 'a11y') ? RECHECK : '') +
    '\n\nOnly report defects INTRODUCED BY THIS BRANCH (git diff master...HEAD). Every finding needs a file, a line, a concrete failure scenario (inputs → wrong behaviour) and a severity. No style nits. Be exhaustive within your lens; another agent will try to refute each finding, so include the evidence that makes it real.',
    { label: 'review:' + l.key, phase: 'Review', model: 'opus', effort: l.effort, schema: FINDINGS })))

// Barrier is deliberate: dedup needs every lens's findings before refutation.
const seen = new Set()
const all = []
for (const r of reviews.filter(Boolean)) {
  for (const f of r.findings) {
    const key = f.file + ':' + f.line + ':' + f.summary.toLowerCase().slice(0, 40)
    if (seen.has(key)) continue
    seen.add(key)
    all.push(f)
  }
}
log('Review: ' + all.length + ' unique findings from ' + reviews.filter(Boolean).length + ' lenses')

phase('Refute')
const VERDICT = {
  type: 'object',
  properties: { refuted: { type: 'boolean' }, reason: { type: 'string' } },
  required: ['refuted', 'reason'],
}
const judged = await pipeline(all,
  f => parallel([0, 1].map(i => () =>
    agent(COMMON + NO_BROWSER + '\n\n## YOU ARE A SKEPTIC (#' + (i + 1) + '). Try to REFUTE this finding by reading the actual code and, where cheap, running it (php -r, phpunit --filter, curl):\n' +
      JSON.stringify(f, null, 2) +
      '\nrefuted=true if the scenario cannot happen on this branch, is pre-existing on master, is a pure style preference, or the evidence does not hold. If you are uncertain, refuted=false is WRONG — default to refuted=true only when you have shown why. State the reason in two sentences.',
      { label: 'refute:' + f.file.split('/').pop() + ':' + f.line, phase: 'Refute', model: 'opus', effort: 'low', schema: VERDICT })))
    .then(vs => ({ ...f, refutations: vs.filter(Boolean), confirmed: vs.filter(Boolean).filter(v => !v.refuted).length >= 1 })))
const confirmed = judged.filter(Boolean).filter(f => f.confirmed)
const dropped = judged.filter(Boolean).filter(f => !f.confirmed)
log('Refute: ' + confirmed.length + ' confirmed, ' + dropped.length + ' refuted')

phase('Fix')
// Barrier is deliberate: grouping by file ownership needs the full confirmed set so two fixers never edit one file.
const GROUPS = [
  { key: 'domain', match: p => p.startsWith('system/lib/ork3/') },
  { key: 'controllers', match: p => p.startsWith('orkui/controller/') || p.startsWith('orkui/model/') || p === 'system/lib/system/class.Controller.php' },
  { key: 'builder', match: p => p.includes('Survey_build') || p.includes('survey-build') },
  { key: 'runner', match: p => p.includes('Survey_take') || p.includes('survey-take') },
  { key: 'results', match: p => p.includes('Survey_results') || p.includes('survey-results') },
  { key: 'shared-frontend', match: p => p.includes('survey.css') || p.includes('survey-render') || p.includes('Survey_index') },
  { key: 'entry', match: p => p.includes('revised-frontend/') || p.endsWith('default.theme') },
  { key: 'tests-docs', match: p => p.startsWith('tests/') || p.startsWith('docs/') },
]
const buckets = GROUPS.map(g => ({ ...g, items: confirmed.filter(f => g.match(f.file)) })).filter(g => g.items.length)
const unbucketed = confirmed.filter(f => !GROUPS.some(g => g.match(f.file)))
if (unbucketed.length) buckets.push({ key: 'other', items: unbucketed })

const fixReports = await parallel(buckets.map(b => () =>
  agent(COMMON + NO_BROWSER + '\n\n## YOU ARE THE FIXER for file group "' + b.key + '". Other fixers are editing OTHER file groups right now — touch only the files named in your findings (and their tests).\n' +
    'Fix EVERY finding below (all severities; verify each is still real before changing code — if one is not, say so and skip it). Keep changes minimal. Run php -l / node --check / the Survey unit tests as relevant. Commit per logical fix with explicit paths.\n' +
    JSON.stringify(b.items, null, 2),
    { label: 'fix:' + b.key, phase: 'Fix', model: 'opus', effort: 'medium' })
    .then(r => ({ group: b.key, count: b.items.length, report: String(r).slice(0, 2500) }))))

phase('Verify')
const FINAL = {
  type: 'object',
  properties: {
    green: { type: 'boolean' },
    unit_tests: { type: 'string' },
    layering_grep: { type: 'string' },
    curl: { type: 'string' },
    browser: { type: 'string' },
    git: { type: 'string' },
    closeout: { type: 'string' },
    remaining: { type: 'array', items: { type: 'string' } },
  },
  required: ['green', 'closeout', 'remaining'],
}
const final = await agent(COMMON + '\n\n## YOU ARE THE FINAL VERIFIER. You did not write any of this. Do NOT fix anything. You are alone, so Claude-in-Chrome is allowed (load tools with one ToolSearch call).\n' +
  'Fix groups just landed:\n' + JSON.stringify(fixReports).slice(0, 5000) +
  (Array.isArray(A.recheck) && A.recheck.length ? '\n\nRE-CHECK FIRST — these Phase 2 browser findings were fixed in the last round but never independently re-verified; confirm each one is actually resolved on the real page (same viewport/theme) and report any that is not as a remaining item:\n' + A.recheck.map((s, i) => (i + 1) + '. ' + s).join('\n') : '') +
  '\n\n1. ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter Survey — paste the summary line.' +
  '\n2. php -l on every PHP file in git diff master...HEAD --name-only; node --check on every new .js.' +
  '\n3. Layering grep under orkui/ (only orkui/model/model.Survey.php may match).' +
  '\n4. The Task 6 curl matrix from ' + PLAN + ' plus the negative cases (foreign scope → 3, foreign option → 1, second submit → completed).' +
  '\n5. Serial browser smoke: Survey/index/Kingdom/17, Survey/build/<clone>, Survey/take/<open> at desktop and 390px, Survey/results/<open> in light and dark, the My Amtgard widget on Player/profile/46193. Console clean on each.' +
  '\n6. git status --porcelain (nothing unexpected), class.Authorization.php not staged and unmodified, `git log origin/feature/survey-module..HEAD` shows the branch was never pushed (or origin ref absent).' +
  '\n7. CLOSE-OUT in under 200 words for the repo owner: is this branch ready for their own review? Name anything that would embarrass them. List remaining items the workflow did not resolve.',
  { label: 'verify:final', phase: 'Verify', model: 'opus', effort: 'high', schema: FINAL })

const note = await agent(COMMON + '\n\n## YOUR TASK: Task 16 Steps 1–2 ONLY in ' + PLAN + ' (release note entry in orkui/whats_new_content.php). Do NOT open a PR and do NOT push — the owner does that. ' +
  'Read the file\'s own header comments for how versions are bumped. Keep the new entry to one item titled "Surveys" with a two-sentence body that mentions the data gate. Commit with explicit path.',
  { label: 'T16:release-note', phase: 'Verify', model: 'sonnet', effort: 'low' })

return {
  reviewed: all.length,
  confirmed: confirmed.map(f => ({ file: f.file, line: f.line, severity: f.severity, summary: f.summary })),
  dropped: dropped.map(f => ({ file: f.file, line: f.line, summary: f.summary, reason: (f.refutations[0] || {}).reason })),
  fixes: fixReports,
  final,
  releaseNote: String(note).slice(0, 1200),
}
