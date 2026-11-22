export const meta = {
  name: 'survey-2-surfaces',
  description: 'Survey module Phase 2: shared renderer + base CSS, then list / builder / runner / results / entry points in parallel, then serial browser verification',
  phases: [
    { title: 'Shared', detail: 'survey.css + survey-render.js (SvRender contract)', model: 'opus' },
    { title: 'Surfaces', detail: 'list (sonnet), builder (opus/high), runner (opus), results (opus), entry points + widget + banner (sonnet) — parallel, no browser', model: 'opus' },
    { title: 'Verify', detail: 'ONE serial browser verifier, then a fixer, up to two rounds', model: 'opus' },
  ],
}

const A = (typeof args === 'string') ? JSON.parse(args || '{}') : (args || {})
const CURL_SURVEY_ID = A.surveyId ? String(A.surveyId) : '(look it up: SELECT survey_id FROM ork_survey WHERE title = "Curl Survey")'

const REPO = '/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias'
const SPEC = 'docs/superpowers/specs/2026-09-09-survey-module-design.md'
const PLAN = 'docs/superpowers/plans/2026-09-09-survey-module.md'

const COMMON = [
  'You are working in the git repo at ' + REPO + ' on branch feature/survey-module.',
  'Read ' + SPEC + ' (the design) and ' + PLAN + ' (the plan) FIRST. Your task is one numbered task in the plan;',
  'do exactly that task, every checkbox step, and nothing outside it. The plan\'s "Global Constraints" apply.',
  'Phase 1 is complete and committed: schema, SurveyTypes, Survey, SurveyResponse, SurveyReport, Model_Survey, Controller_Survey, Controller_SurveyAjax.',
  'The AJAX contract is spec §6 and is implemented in orkui/controller/controller.SurveyAjax.php — read that file for the exact POST names and JSON keys.',
  'A survey titled "Curl Survey" exists in kingdom 17 with responses; its id is ' + CURL_SURVEY_ID + '. It is OPEN and therefore structure-locked; clone it (SurveyAjax/clone) when you need an editable one.',
  '',
  'HARD RULES:',
  '- .tpl files are PLAIN PHP (<?= ?>), never Smarty. Templates link their own CSS/JS with ?v=<?= filemtime(...) ?> cache-busting.',
  '- Under orkui/ never $DB->, Ork3::$Lib, or new <DomainClass>(. Controllers/templates talk only to Model_Survey.',
  '- CSS: prefix sv- (svb-/svr- for builder/results-only rules), tokens from orkui/template/default/style/tokens.css (--ork-*), dark mode under html[data-theme="dark"], tap targets >= 44px, text inputs 16px, nothing copied from reports.css or survey.css into another file.',
  '- No native alert()/confirm()/prompt() anywhere. Tooltips via data-tip. FontAwesome classes (fas fa-…).',
  '- Stage EXPLICIT paths only. Never git add -A / git add . Never stage system/lib/ork3/class.Authorization.php, CLAUDE.md, agent-instructions/claude.md.',
  '- Never push. Never git stash. Never create git worktrees.',
  '- Before editing an EXISTING PHP/.tpl/.theme file run: awk \'/^\\t/{c++}END{print c+0}\' <file>; PHP files with a non-zero count get tools/php-cs-fixer/php-cs-fixer.phar fix <file> first (separate commit). .tpl/.theme keep their existing indentation style — match it.',
  '- Local facts: app http://localhost:19080/orkui/ ; routes index.php?Route=Controller/action/arg ; login POST username=heraldsbridge&password=x&Action=Sign+In to Login/login into a cookie jar; heraldsbridge = mundane 46193, kingdom 17, park 1049, ORK admin. DB: docker exec ork3-php8-db mariadb -uroot -proot ork.',
  '- Commit message prefix "Enhancement: Survey — …" and end the message with the trailer lines:',
  '    Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>',
  '    Claude-Session: https://claude.ai/code/session_016XHnYr7jkzChUZkaaKgKHb',
  '',
  'RETURN (data for an orchestrator): files created/modified, commit hashes, verification commands with real output, open issues.',
].join('\n')

const NO_BROWSER = '\n\nBROWSER: Do NOT use Claude-in-Chrome or any browser tool in this task — four other agents are building sibling surfaces at the same time and the browser session is single-tenant. ' +
  'Where the plan step says "Verify with Claude-in-Chrome", instead verify what you can with curl (page returns 200 and contains your root element and script/link tags), node -e syntax checks of your JS (node --check <file>), php -l, and by reading your own code against the contract. ' +
  'The serial browser pass happens in the next phase; list in your report exactly what that verifier should click.'

function task(n, extra) {
  return COMMON + '\n\n## YOUR TASK: Task ' + n + ' in ' + PLAN + '\n' + (extra || '')
}

phase('Shared')
const t8 = await agent(task(8,
  'You are alone in this phase, so you MAY use Claude-in-Chrome for the self-check harness step (load the harness from the scratchpad directory via a file:// URL or by temporarily serving it from assets/ — remove any temporary file afterwards). ' +
  'The SvRender contract in the plan is consumed by three agents in parallel next; every listed function must exist with that exact behaviour. Document the HTML structure each type renders at the top of survey-render.js so the builder and runner authors can style against it without guessing.'),
  { label: 'T8:renderer+base-css', phase: 'Shared', model: 'opus', effort: 'medium' })

phase('Surfaces')
const shared = '\n\nTask 8 (survey.css + survey-render.js) is committed. Its author reports:\n' + String(t8).slice(0, 2500) + '\nRead both files before you start; use SvRender, do not reimplement rendering.'
const [t9, t10, t11, t12, t13] = await parallel([
  () => agent(task(9, shared + NO_BROWSER), { label: 'T9:list-page', phase: 'Surfaces', model: 'sonnet', effort: 'medium' }),
  () => agent(task(10, shared + NO_BROWSER + '\n\nIMPORTANT — RESUMED TASK: a previous attempt at this task was cut off mid-way and left three UNTRACKED files: orkui/template/default/Survey_build.tpl (~150 lines), orkui/template/default/script/survey-build.js (~1850 lines), orkui/template/default/style/survey-build.css (~830 lines). Read them first, run node --check on the JS, and FINISH that work against the plan rather than starting over; rewrite a file only if it is broken beyond repair. Never stage assets/survey/*.png (uploaded test images).' +
  '\n\nDESIGN CHANGE FROM THE OWNER (supersedes any inspector-panel approach in the untracked files): the CANVAS IS THE EDITOR. No side inspector for content. Clicking a question card switches it to in-place edit mode: the prompt is a borderless textarea, options are rows with an inline label input and a remove button, with "+ Add option" / "+ Add Other" links below; matrix rows/columns, rating/NPS end labels, text limits and sections are all edited inline; a footer toolbar on the selected card holds type, Required, Duplicate, Delete and a more-menu (show-if, randomize, limits, image). There is NO palette and NO floating add toolbar: a "+ Add Element" button under the questions of each page creates a starter single-choice card (selected, prompt focused), and the card footer Type dropdown lists every element type (12 question types + Section + Image) to retype in place. If question_update does not yet accept Type, add it to class.Survey.php / controller.SurveyAjax.php (unlocked surveys only; keep prompt and reuse options where the new type has them) and extend the unit test. Survey-level settings live in a header-opened drawer. Re-read spec §7 Builder and plan Task 10 Step 2 — both were rewritten for this. Salvage whatever in the untracked files still fits (template shell, save/debounce, Sortable wiring, settings fields, image upload) and rebuild the card editing around inline editors. Every settings key of every type in spec §4 must still be editable somewhere. Keep survey-build.js as one IIFE with named functions; no framework.'), { label: 'T10:builder', phase: 'Surfaces', model: 'opus', effort: 'high' }),
  () => agent(task(11, shared + NO_BROWSER + '\n\nThe consent card copy must be the fixed text in spec §2, verbatim. Port SurveyTypes::isShown to JS inside survey-take.js and keep the two in lock-step (same truth table as tests/Unit/SurveyTypesTest.php::testIsShownQuestionLevel).'), { label: 'T11:runner', phase: 'Surfaces', model: 'opus', effort: 'medium' }),
  () => agent(task(12, shared + NO_BROWSER + '\n\nLoad the `dataviz` skill (Skill tool) before writing any chart code and follow it for palette, mark choice and dark-mode legibility. Highcharts 11.4.8 from code.highcharts.com is loaded AFTER orkui.js (which inlines Highcharts 3); reference Highcharts only inside your IIFE.'), { label: 'T12:results', phase: 'Surfaces', model: 'opus', effort: 'medium' }),
  () => agent(task(13, shared + NO_BROWSER + '\n\nYou are the ONLY agent touching Kingdomnew_index.tpl, Parknew_index.tpl, Admin_index.tpl, Playernew_index.tpl, class.Controller.php and default.theme. Apply each edit with a python3 replace script that prints found: True, and keep the diffs minimal and additive.'), { label: 'T13:entry+widget+banner', phase: 'Surfaces', model: 'sonnet', effort: 'medium' }),
])

phase('Verify')
const FINDINGS = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          surface: { type: 'string', enum: ['list', 'builder', 'runner', 'results', 'entry', 'widget', 'banner', 'shared'] },
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
    notes: { type: 'string' },
  },
  required: ['findings', 'green'],
}

const surfaceReports = {
  list: String(t9).slice(0, 1500), builder: String(t10).slice(0, 2500), runner: String(t11).slice(0, 2000),
  results: String(t12).slice(0, 2000), entry: String(t13).slice(0, 1500),
}

let verdict = null
const fixes = []
for (let round = 1; round <= 2; round++) {
  verdict = await agent(COMMON + '\n\n## YOU ARE THE SERIAL BROWSER VERIFIER (Task 14 Step 1). You did not write this code. Do NOT fix anything.\n' +
    'You are the only agent running now, so use Claude-in-Chrome. Load its tools with ONE ToolSearch call. Log in at http://localhost:19080/orkui/ as heraldsbridge (any password). ' +
    'Walk spec §10 criteria 2, 4, 5, 8, 9, 10 across: Survey/index/Kingdom/17 → Survey/build/<clone id> → Survey/take/<open id> at desktop, 390px and 360px (measure with the same-origin iframe harness if resize_window is a no-op; check document.documentElement.scrollWidth <= innerWidth) → Survey/results/<open id> in light and dark → Player/profile/46193 My Amtgard widget → the site banner (set show_banner=1 via SurveyAjax/update, then dismiss it). ' +
    'Read the console after every page (read_console_messages) and treat any uncaught error as a major finding. Re-run the Task 6 curl matrix once. ' +
    'The surface authors asked you to check the following:\n' + JSON.stringify(surfaceReports).slice(0, 6000) +
    (round > 1 ? '\n\nA fixer just addressed these findings:\n' + JSON.stringify(fixes).slice(0, 3000) + '\nRe-verify every surface, not only the fixed items.' : '') +
    '\n\nReport every failure with page, viewport, theme, console output. green=true only with zero blocker/major findings.',
    { label: 'browser-verify:r' + round, phase: 'Verify', model: 'opus', effort: 'medium', schema: FINDINGS })
  if (!verdict || verdict.green) break
  const open = verdict.findings.filter(f => f.severity !== 'minor')
  if (!open.length) break
  log('Browser verifier round ' + round + ': ' + open.length + ' findings to fix')
  const fixed = await agent(task(14, 'Do Step 2 only. Apply EVERY finding below (all severities). You may use the browser (you are alone). Re-verify each affected surface, commit per surface.\n' + JSON.stringify(verdict.findings, null, 2)),
    { label: 'fix:r' + round, phase: 'Verify', model: 'opus', effort: 'medium' })
  fixes.push(String(fixed).slice(0, 2500))
}

return { shared: String(t8).slice(0, 2000), surfaces: surfaceReports, verdict, fixes }
