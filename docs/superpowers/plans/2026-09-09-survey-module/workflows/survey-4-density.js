export const meta = {
  name: 'survey-4-density',
  description: 'Survey module Phase 4: compact the builder and survey chrome to the spec §7 Density targets, then verify by measurement',
  phases: [
    { title: 'Compact', detail: 'Task 17 — restructure the selected card, rewrite sizing rules, trim list/results headers', model: 'opus' },
    { title: 'Verify', detail: 'independent measurement in the browser, fix loop up to two rounds', model: 'opus' },
  ],
}

const A = (typeof args === 'string') ? JSON.parse(args || '{}') : (args || {})
const SURVEY_ID = A.surveyId ? String(A.surveyId) : '999012'

const REPO = '/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias'
const SPEC = 'docs/superpowers/specs/2026-09-09-survey-module-design.md'
const PLAN = 'docs/superpowers/plans/2026-09-09-survey-module.md'

const COMMON = [
  'You are working in the git repo at ' + REPO + ' on branch feature/survey-module. The survey module is built, reviewed and fixed (Phases 1–3 of ' + PLAN + ').',
  'Read spec §7 Builder (especially the **Density** bullet) in ' + SPEC + ' and Task 17 in ' + PLAN + ' FIRST.',
  'The owner\'s words: "all of your chrome of this is very large ... Look at how nice a clean / compact Google Forms is. Don\'t directly copy this but take inspiration." and then: "Don\'t limit the density work to just spacing. Font sizing also applies. Things can be sized down there to normal body text and header styles that would be found elsewhere in the CSS of the app." The app scale is tokens.css (--ork-font-size-base 13px, --ork-font-size-sm 12px) and reports.css (.rp-header-title 20px, .rp-chart-card-title 14px, card labels 11px uppercase, .rp-btn-ghost padding 7px 14px). Read those before sizing anything.',
  'LAYOUT CHANGE FROM THE OWNER (2026-09-10): "make this page layout more like our Reports layout. Take the survey settings and instead of having them in a slide out panel, make them the left sidebar, arranged into sections that are collapsible." So the builder page becomes .rp-root > .rp-header + .rp-body > .rp-sidebar (settings as collapsible .rp-filter-card sections: Basics, Screens, Audience, Schedule, Privacy, Promotion, Experience, About This Tool) + .rp-main (the canvas). Delete the drawer. Spec §7 Builder and plan Task 17 Step 2b describe it exactly; reports.css already styles .rp-sidebar/.rp-filter-card — reuse, never re-declare.',
  'A previous run of this task was stopped mid-way and left UNCOMMITTED edits in survey-build.js, survey-build.css, survey-results.css and survey.css — read `git diff` first and continue from them; they were done against the older 14/16px targets, so re-check every size against the new spec text.',
  '',
  'HARD RULES:',
  '- .tpl files are PLAIN PHP. Under orkui/ never $DB->, Ork3::$Lib, or new <DomainClass>( outside orkui/model/.',
  '- Keep every existing builder behaviour (autosave, Sortable, keyboard reordering, retype, show-if, image upload, lock handling, dialogs with focus trap). This is a LAYOUT and SIZING pass.',
  '- Keep dark mode (html[data-theme="dark"]) for every rule you touch and the 900/420 px breakpoints. Keep the runner\'s 44 px tap targets on touch widths.',
  '- No native alert/confirm/prompt. Tokens from tokens.css (--ork-*) and survey.css (--sv-*); no new hardcoded colours.',
  '- Stage EXPLICIT paths only. Never git add -A. Never stage system/lib/ork3/class.Authorization.php, CLAUDE.md, agent-instructions/claude.md. Never push, stash, or create worktrees.',
  '- You are the only agent running: Claude-in-Chrome IS allowed (load its tools with one ToolSearch call). Log in at http://localhost:19080/orkui/index.php?Route=Login/login by POSTing username=heraldsbridge&password=x&Action=Sign+In from the page (or use the login form); a curl login evicts the browser session, so do not mix curl and browser logins.',
  '  Survey ' + SURVEY_ID + ' is open (locked); clone it via SurveyAjax/clone from the list page for an editable card. resize_window is a no-op here — measure with getBoundingClientRect() at the real viewport and use the same-origin iframe harness for other widths.',
  '- Commit message prefix "Enhancement: Survey — …" and end the message with the trailer lines:',
  '    Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>',
  '    Claude-Session: https://claude.ai/code/session_016XHnYr7jkzChUZkaaKgKHb',
  '',
  'RETURN (data for an orchestrator): files changed, commit hashes, the before/after measurement table, open issues.',
].join('\n')

phase('Compact')
const t17 = await agent(COMMON + '\n\n## YOUR TASK: Task 17 in ' + PLAN + ' — all six steps. Paste the before/after measurements as a table.',
  { label: 'T17:compact-builder', phase: 'Compact', model: 'opus', effort: 'medium' })

phase('Verify')
const FINDINGS = {
  type: 'object',
  properties: {
    measurements: { type: 'string' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          file: { type: 'string' },
          summary: { type: 'string' },
          evidence: { type: 'string' },
          theme: { type: 'string' },
          severity: { type: 'string', enum: ['blocker', 'major', 'minor'] },
        },
        required: ['file', 'summary', 'evidence', 'severity'],
      },
    },
    green: { type: 'boolean' },
  },
  required: ['findings', 'green', 'measurements'],
}

let verdict = null
const fixes = []
for (let round = 1; round <= 2; round++) {
  verdict = await agent(COMMON + '\n\n## YOU ARE THE VERIFIER. You did not write this. Do NOT fix anything.\nThe implementer reports:\n' + String(t17).slice(0, 3000) +
    (round > 1 ? '\n\nA fixer then addressed:\n' + JSON.stringify(fixes).slice(0, 2500) : '') +
    '\n\nOn Survey/build/<an unlocked clone> at the real viewport in BOTH themes, measure with getBoundingClientRect() every target in spec §7 Density (card padding, handle, prompt field height and style, type picker height and position, option row height, footer height and contents, type scale, drawer/header control heights). ' +
    'Then exercise: add option via Enter, remove via Backspace on an empty label, retype via the top-right picker, Required switch persists after reload, duplicate, delete with confirm strip, drag reorder, show-if from the ⋯ menu, expand and collapse every sidebar section. Console must be clean. Check 390 px via the iframe harness: no horizontal overflow, footer wraps cleanly. ' +
    'ALSO cover what no agent has verified yet, on Survey/take/<open clone> at 390 px via the iframe harness and at desktop: answer a matrix and a ranking question (keyboard and pointer), drag a question across pages in the builder, upload an image through the builder UI, set a show-if and confirm the dependent question hides/shows in the runner, round-trip the settings SIDEBAR (change welcome text + close date in their sections, reload, confirm the values and that section open/closed state persisted; confirm every section is collapsed at 390 px and the canvas is reachable), and submit once with the PARTIAL consent choice, then confirm in the DB (docker exec ork3-php8-db mariadb -uroot -proot ork) that the row has kingdom_id set, mundane_id NULL and submitted_at at midnight. Regressions and failures here are findings too. ' +
    'Report every unmet target or regression as a finding with numbers. green=true only when every target is met in both themes and nothing regressed.',
    { label: 'verify:r' + round, phase: 'Verify', model: 'opus', effort: 'medium', schema: FINDINGS })
  if (!verdict || verdict.green) break
  const open = verdict.findings.filter(f => f.severity !== 'minor')
  if (!open.length) break
  log('Density verifier round ' + round + ': ' + open.length + ' findings')
  const fixed = await agent(COMMON + '\n\n## YOU ARE THE FIXER. Apply EVERY finding below (all severities), re-measure the affected targets, commit with explicit paths.\n' + JSON.stringify(verdict.findings, null, 2),
    { label: 'fix:r' + round, phase: 'Verify', model: 'opus', effort: 'medium' })
  fixes.push(String(fixed).slice(0, 2500))
}

return { compact: String(t17).slice(0, 4000), verdict, fixes }
