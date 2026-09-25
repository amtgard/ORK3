export const meta = {
  name: 'sc-2-surfaces',
  description: 'Survey sharing + credits Phase 3-4: shared credit modal, then list / builder / results / runner+profile / docs in parallel (no browser), then ONE serial browser verifier with a fixer, up to two rounds',
  phases: [
    { title: 'Modal', detail: 'Task 12: shared modal CSS move + Attendance credit modal', model: 'opus' },
    { title: 'Surfaces', detail: 'Tasks 13-17 in parallel, no browser', model: 'sonnet' },
    { title: 'Browser', detail: 'Task 18: serial browser verifier + fixer, up to two rounds', model: 'opus' },
  ],
}

// args (optional): { scratchSurveyIds: [int], backendNotes: string }
const A = (typeof args === 'string') ? JSON.parse(args || '{}') : (args || {})

const REPO = '/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias'
const SPEC = 'docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md'
const PLAN = 'docs/superpowers/plans/2026-09-10-survey-sharing-and-credits.md'
const SCRATCH = '/private/tmp/claude-501/-Users-averykrouse-GitHub-ORK-tobias-ORK3-tobias/1d774dfe-6cb6-484b-ada9-8692f58aa1db/scratchpad'
const SCRATCH_IDS = Array.isArray(A.scratchSurveyIds) ? A.scratchSurveyIds.join(', ') : '(none recorded; create your own)'

const COMMON = [
  'You are working in the git repo at ' + REPO + ' on branch feature/survey-module.',
  'Read ' + SPEC + ' (the design) and ' + PLAN + ' (the plan) FIRST. Your task is one numbered task in the plan: do exactly that task, every checkbox step, and nothing outside it. The plan\'s "Global Constraints" apply.',
  'Tasks 1-11 are complete and committed: schema, SurveyCredit, sharing/list rules, the credit engine, hooks, Model_Survey delegates, and the SurveyAjax actions results(+Context), credit_status, credit_enable, credit_reconcile, submit(+credit). Read orkui/controller/controller.SurveyAjax.php for the exact POST names and JSON keys before writing any client code.',
  'Scratch surveys left by the backend phase (safe to enable credits on): ' + SCRATCH_IDS + '. NEVER enable credits on 999048, 999049 or 999051 (configs are permanent).',
  (A.backendNotes ? 'Backend notes: ' + String(A.backendNotes).slice(0, 1500) : ''),
  'Line numbers in the plan are approximate; locate by quoted code. If plan code is wrong against the real file, fix minimally, keep the intent, and REPORT the deviation.',
  '',
  'HARD RULES:',
  '- .tpl files are PLAIN PHP (<?= ?>), never Smarty; they keep their existing tab indentation. Templates link CSS/JS with ?v=<?= filemtime(...) ?>.',
  '- Under orkui/ never $DB->, Ork3::$Lib, or new <DomainClass>(.',
  '- CSS: sv- prefix (svb-/svr- for builder/results-only), --ork-*/--sv-* tokens (no new hard-coded colour when a token exists), dark mode under html[data-theme="dark"], tap targets >= 44px on touch, no horizontal scroll at 360px, nothing duplicated between survey.css / reports.css / surface CSS.',
  '- Any heading (h1-h6) you add must reset orkui.css\'s global pill box (background, border, padding, radius, box-shadow) in BOTH themes — html[data-theme="dark"] h1..h6 outranks a plain class, so reset it in a dark-mode selector too.',
  '- No native alert()/confirm()/prompt(). Tooltips via data-tip (right-anchored in Actions columns). FontAwesome 7 (fas fa-…). Tabular data uses DataTables. Human-readable dates ("March 3, 2026").',
  '- Stage and commit EXPLICIT paths only with the path-limited form: git add <paths> && git commit -m "<msg>" -- <same paths>. Never git add -A / . / commit -a. Never stage class.Authorization.php, CLAUDE.md, agent-instructions/claude.md. If .git/index.lock exists, wait 5s and retry.',
  '- Never push. Never git stash. Never create git worktrees.',
  '- Local facts: app http://localhost:19080/orkui/ ; routes index.php?Route=Controller/action/arg ; login POST "index.php?Route=Login/login" with username=heraldsbridge&password=x&Action=Sign+In into a cookie jar (any password works); heraldsbridge = mundane 46193, kingdom 17, park 1049, ORK admin. DB: docker exec ork3-php8-db mariadb -uroot -proot ork.',
  '- Commit message prefix "Enhancement: Survey — …" ending with these two trailer lines:',
  '    Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>',
  '    Claude-Session: https://claude.ai/code/session_015f7Q1rwtQLdNo6Sx1YwLqm',
  '',
  'RETURN (data for an orchestrator): files created/modified, commit hashes, verification commands with real output, deviations, and a precise click-list for the browser verifier.',
].join('\n')

const NO_BROWSER = '\n\nBROWSER: Do NOT use Claude-in-Chrome, Playwright or any browser in this task — other agents are building sibling surfaces at the same time and the browser session is single-tenant. ' +
  'Verify with curl (page returns 200 and contains your root element/script tags), node --check on JS, php -l on .tpl/.php, and by reading your code against the SurveyAjax contract. The serial browser pass comes next; list exactly what it should click.'

function task(n, extra) {
  return COMMON + '\n\n## YOUR TASK: Task ' + n + ' in ' + PLAN + '\n' + (extra || '')
}

phase('Modal')
const t12 = await agent(task(12,
  '\n\nYou are alone in this phase. Step 1 moves shared modal CSS out of Survey_index.tpl; after the move, curl Survey/index/Kingdom/17 as heraldsbridge and confirm the page still returns 200 and still contains the New Survey modal markup. ' +
  'SvCredit.open is consumed by two agents in parallel next; its signature and the SvCreditConfig contract must match the plan exactly.' + NO_BROWSER),
  { label: 'T12:credit-modal', phase: 'Modal', model: 'opus', effort: 'medium' })

phase('Surfaces')
const shared = '\n\nTask 12 is committed (shared .sv-overlay/.sv-modal CSS now lives in survey.css; _survey_credit_modal.tpl + survey-credit.js exist). Its author reports:\n' + String(t12).slice(0, 2000) +
  '\nYou are one of five parallel agents. Touch ONLY the files your task lists.'
const [t13, t14, t15, t16, t17] = await parallel([
  () => agent(task(13, shared + NO_BROWSER + '\n\nYou are the only agent editing Survey_index.tpl. After Step 1, grep -c "#sv-table" must print 0.'),
    { label: 'T13:list-sections', phase: 'Surfaces', model: 'sonnet', effort: 'high' }),
  () => agent(task(14, shared + NO_BROWSER + '\n\nYou are the only agent editing Survey_build.tpl and survey-build.js. CONSENT_COPY.credit must equal the runner\'s copy word for word (spec §3.7).'),
    { label: 'T14:builder', phase: 'Surfaces', model: 'sonnet', effort: 'medium' }),
  () => agent(task(15, shared + NO_BROWSER + '\n\nYou are the only agent editing Survey_results.tpl, survey-results.js and survey-results.css. Removing server-side markup is the point: a shared viewer\'s page must not contain the rows table, export link, print button or response panel at all.'),
    { label: 'T15:results-shared', phase: 'Surfaces', model: 'sonnet', effort: 'medium' }),
  () => agent(task(16, shared + NO_BROWSER + '\n\nYou are the only agent editing survey-take.js and Playernew_index.tpl. You share survey.css with nobody else in this phase (Task 12 is done); append your rules at the end. The data-gate credit line is fixed copy (spec §3.7) — verbatim.'),
    { label: 'T16:runner+profile', phase: 'Surfaces', model: 'sonnet', effort: 'medium' }),
  () => agent(task(17, shared + '\n\nDocs only; no browser needed. docs/survey-guide.md is served in-app through SurveyAjax/help — keep its existing heading levels and voice.'),
    { label: 'T17:docs', phase: 'Surfaces', model: 'sonnet', effort: 'low' }),
])

phase('Browser')
const FINDINGS = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          surface: { type: 'string', enum: ['list', 'modal', 'builder', 'results', 'runner', 'profile', 'event', 'docs', 'backend'] },
          file: { type: 'string' },
          summary: { type: 'string' },
          evidence: { type: 'string' },
          viewport: { type: 'string' },
          theme: { type: 'string' },
          severity: { type: 'string', enum: ['blocker', 'major', 'minor'] },
        },
        required: ['surface', 'file', 'summary', 'evidence', 'severity'],
      },
    },
    green: { type: 'boolean' },
    fixturesCreated: { type: 'string' },
    notes: { type: 'string' },
  },
  required: ['findings', 'green'],
}

const surfaceReports = {
  modal: String(t12).slice(0, 1500), list: String(t13).slice(0, 1800), builder: String(t14).slice(0, 1500),
  results: String(t15).slice(0, 1500), runner: String(t16).slice(0, 1500), docs: String(t17).slice(0, 600),
}

const BROWSER_HOWTO = [
  'BROWSER HOW-TO (you are the ONLY agent running; serial work only):',
  '- Run all curl work FIRST: a curl login evicts the browser session (single-device sessions).',
  '- Prefer Claude-in-Chrome: load its tools with ONE ToolSearch call (tabs_context_mcp, navigate, computer, read_page, javascript_tool, read_console_messages, tabs_create_mcp). Open a NEW tab. If Chrome has no logged-in session or refuses to type a password, switch to headless Playwright + Chromium from the repo node_modules: write a driver in ' + SCRATCH + ' that POSTs index.php?Route=Login/login (username/password fields) and reuses the cookie; set a normal desktop User-Agent (code.highcharts.com 403s HeadlessChrome).',
  '- resize_window is a no-op: measure 360px and 1280px in a same-origin iframe harness (write a small HTML page into the app\'s own origin only if needed, remove it afterwards), check document.documentElement.scrollWidth <= clientWidth and filter elements to offsetParent !== null.',
  '- Dark mode: set document.documentElement.dataset.theme = "dark" (and "light"), then read COMPUTED styles — never judge by eye alone.',
  '- Read the console after every page load; any uncaught error is a major finding.',
  '- Non-admin views: find a kingdom-17 kingdom officer and a park officer with SELECT a.mundane_id, a.kingdom_id, a.park_id, a.role, m.username FROM ork_authorization a JOIN ork_mundane m USING (mundane_id) WHERE a.role IN (\'create\',\'admin\') AND (a.kingdom_id = 17 OR a.park_id IN (SELECT park_id FROM ork_park WHERE kingdom_id = 17)) LIMIT 10; the bypass accepts any password.',
  '- Fixtures: create what Task 18 lists (an ORK-scoped clone of 999051 with results_share=scoped and kingdom-17 responses; a kingdom-17 survey with results_share=scoped; one scratch survey per credit mode). Use SurveyAjax/clone + SurveyAjax/update + direct SQL for scope/results_share. NEVER enable credits on 999048/999049/999051. Report every fixture id in fixturesCreated.',
].join('\n')

let verdict = null
const fixes = []
for (let round = 1; round <= 2; round++) {
  verdict = await agent(COMMON + '\n\n## YOU ARE THE SERIAL BROWSER VERIFIER (Task 18). You did not write this code. Do NOT fix anything.\n' + BROWSER_HOWTO +
    '\n\nWalk EVERY check in Task 18 (1-7) in light and dark, at 1280px and 360px, and spec §9 acceptance criteria 1-7. Also confirm server enforcement from the page: as a shared viewer, fetch SurveyAjax/rows from the console and expect status 3.\n' +
    'The surface authors asked you to check:\n' + JSON.stringify(surfaceReports).slice(0, 7000) +
    (round > 1 ? '\n\nA fixer just addressed these findings:\n' + JSON.stringify(fixes).slice(0, 3000) + '\nRe-verify EVERY surface, not only the fixed items.' : '') +
    '\n\nSave screenshots/notes under ' + SCRATCH + '/sc-browser-r' + round + '/. Report every failure with page, viewport, theme, console output. green=true only with zero blocker/major findings.',
    { label: 'browser-verify:r' + round, phase: 'Browser', model: 'opus', effort: 'high', schema: FINDINGS })
  // Polish rule: ALL findings get fixed, minors included — stop only when there are none.
  if (!verdict || !verdict.findings.length) break
  const open = verdict.findings
  log('Browser verifier round ' + round + ': ' + open.length + ' findings to fix (all severities)')
  const fixed = await agent(COMMON + '\n\n## YOU ARE THE FIXER for Tasks 12-18. Apply EVERY finding below (all severities, minors included). You are alone, so you may use the browser (same how-to as the verifier) to confirm each fix in light/dark and 360/1280. Commit per surface with the path-limited form.\n' +
    BROWSER_HOWTO + '\n\nFINDINGS:\n' + JSON.stringify(verdict.findings, null, 2),
    { label: 'fix:r' + round, phase: 'Browser', model: 'opus', effort: 'high' })
  fixes.push(String(fixed).slice(0, 2500))
}

// Findings left after round 2 go back to the orchestrator.
return { surfaces: surfaceReports, verdict, fixes }
