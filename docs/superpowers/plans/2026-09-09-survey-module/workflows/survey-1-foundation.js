export const meta = {
  name: 'survey-1-foundation',
  description: 'Survey module Phase 1: schema, type catalog, three domain classes, model facade, controllers, integration test and verification',
  phases: [
    { title: 'Schema', detail: 'migration, classification, config constants, sandbox refresh', model: 'sonnet' },
    { title: 'Types', detail: 'SurveyTypes pure catalog + unit tests', model: 'opus' },
    { title: 'Domain', detail: 'Survey / SurveyResponse / SurveyReport in parallel', model: 'opus' },
    { title: 'Wiring', detail: 'model facade, page + AJAX controllers, help doc, curl matrix', model: 'sonnet' },
    { title: 'Verify', detail: 'integration test, independent verifier, fix loop', model: 'opus' },
  ],
}

const A = (typeof args === 'string') ? JSON.parse(args || '{}') : (args || {})

const REPO = '/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias'
const SPEC = 'docs/superpowers/specs/2026-09-09-survey-module-design.md'
const PLAN = 'docs/superpowers/plans/2026-09-09-survey-module.md'

const COMMON = [
  'You are working in the git repo at ' + REPO + ' on branch feature/survey-module.',
  'Read ' + SPEC + ' (the design) and ' + PLAN + ' (the plan) FIRST. Your task is one numbered task in the plan;',
  'do exactly that task, every checkbox step, and nothing outside it. The plan\'s "Global Constraints" apply.',
  '',
  'HARD RULES:',
  '- .tpl files are PLAIN PHP. All SQL lives in system/lib/ork3/. Under orkui/ never $DB->, Ork3::$Lib, or new <DomainClass>( outside orkui/model/.',
  '- $this->db->Clear() before every DataSet/Execute; (int) cast ids; esc() strings; transactions around multi-statement writes.',
  '- Stage EXPLICIT paths only. Never git add -A / git add . Never stage system/lib/ork3/class.Authorization.php, CLAUDE.md, agent-instructions/claude.md.',
  '- Never push. Never git stash. Never create git worktrees. Never run destructive DB statements against the ork mirror beyond deleting rows you created.',
  '- Before editing an EXISTING PHP file run: awk \'/^\\t/{c++}END{print c+0}\' <file>; if non-zero, run tools/php-cs-fixer/php-cs-fixer.phar fix <file> first and commit that separately.',
  '- Do NOT use the browser in this phase. Verify with php -l, phpunit, curl and mariadb queries. Paste real output.',
  '- Local facts: app http://localhost:19080/orkui/ ; routes are index.php?Route=Controller/action/arg ; login POST Username=heraldsbridge&Password=x&Action=Sign+In to Login/login into a cookie jar; heraldsbridge = mundane 46193, kingdom 17, park 1049, ORK admin.',
  '  DB: docker exec ork3-php8-db mariadb -uroot -proot ork ; sandbox: docker exec ork3-php8-test-db mariadb -uroot -proot ork_test ; after any migration: docker restart ork3-php8-app.',
  '  Unit tests: ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter <Name>. The "catalog hash drift" line from drift-check is a known local-only failure; ignore it.',
  '- Commit message prefix "Enhancement: Survey — …" and end the message with the trailer lines:',
  '    Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>',
  '    Claude-Session: https://claude.ai/code/session_016XHnYr7jkzChUZkaaKgKHb',
  '',
  'RETURN (this is data for an orchestrator, not prose for a human): files created/modified, commit hashes, the verification commands you ran with their real output, and any open issue or deviation from the plan.',
].join('\n')

function task(n, extra) {
  return COMMON + '\n\n## YOUR TASK: Task ' + n + ' in ' + PLAN + '\n' + (extra || '')
}

phase('Schema')
const t1 = await agent(task(1,
  'Apply the migration to the dev mirror AND refresh the PHPUnit sandbox exactly as the plan says (bin/ork-db deploy-sandbox --force-refresh --yes). ' +
  'Confirm both databases show 10 ork_survey% tables. Confirm drift-check reports no unclassified migrations.'),
  { label: 'T1:schema', phase: 'Schema', model: 'sonnet', effort: 'low' })

phase('Types')
const t2 = await agent(task(2,
  'Write the failing tests FIRST (all cases listed in the plan), watch them fail, then implement. Every public static method in the plan\'s interface block must exist with that exact signature — three later agents will code against it in parallel without seeing your file.'),
  { label: 'T2:survey-types', phase: 'Types', model: 'opus', effort: 'medium' })

phase('Domain')
const domainNote = 'SurveyTypes (Task 2) is already committed:\n' + String(t2).slice(0, 1200) +
  '\n\nTwo other agents are writing the sibling domain classes RIGHT NOW. Do not create or edit any file other than the ones your task lists. ' +
  'Code against the interface blocks in the plan for the sibling classes; do not read their files (they may not exist yet).'
const [t3, t4, t5] = await parallel([
  () => agent(task(3, domainNote), { label: 'T3:survey-domain', phase: 'Domain', model: 'opus', effort: 'medium' }),
  () => agent(task(4, domainNote + '\n\nThe consent scrub is the single most important piece of this module: scrubForConsent must be pure, unit-tested, and the ONLY path that writes ork_survey_response. Re-read spec §2 before writing submit().'), { label: 'T4:survey-response', phase: 'Domain', model: 'opus', effort: 'high' }),
  () => agent(task(5, domainNote + '\n\nLoad no browser and no charting; this is SQL + pure PHP maths. Keep aggregateType() free of SQL so the unit tests run without a database.'), { label: 'T5:survey-report', phase: 'Domain', model: 'opus', effort: 'medium' }),
])

phase('Wiring')
const t6 = await agent(task(6,
  'Domain classes are committed. Summaries from their authors:\n' +
  '--- Survey ---\n' + String(t3).slice(0, 1500) + '\n--- SurveyResponse ---\n' + String(t4).slice(0, 1500) + '\n--- SurveyReport ---\n' + String(t5).slice(0, 1500) +
  '\n\nRead the three class files for the real signatures before writing the facade. Implement EVERY action in spec §6 with exactly those POST names and response keys — ' +
  'the frontend phase codes against the contract, not against your file. Run the whole curl matrix in the plan and paste the real JSON. Report the id of the curl survey you leave in place.'),
  { label: 'T6:model+controllers', phase: 'Wiring', model: 'sonnet', effort: 'medium' })

phase('Verify')
const t7 = await agent(task(7,
  'Do Steps 1–2 only (write and run the integration test). Wiring report from the previous agent:\n' + String(t6).slice(0, 2000)),
  { label: 'T7:integration-test', phase: 'Verify', model: 'opus', effort: 'medium' })

const FINDINGS = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          file: { type: 'string' },
          summary: { type: 'string' },
          evidence: { type: 'string' },
          severity: { type: 'string', enum: ['blocker', 'major', 'minor'] },
        },
        required: ['file', 'summary', 'evidence', 'severity'],
      },
    },
    green: { type: 'boolean' },
    notes: { type: 'string' },
  },
  required: ['findings', 'green'],
}

let verdict = null
let fixes = []
for (let round = 1; round <= 2; round++) {
  verdict = await agent(COMMON + '\n\n## YOU ARE THE VERIFIER for Phase 1 (Task 7 Step 3). You did not write this code. Do NOT fix anything.\n' +
    'Run the Task 6 curl matrix from a fresh cookie jar, the negative cases listed in Task 7 Step 3, the layering grep, php -l on every new PHP file, and the Survey-filtered phpunit run. ' +
    'Also read class.SurveyResponse.php::submit and confirm by reading the SQL that nothing but scrubForConsent decides what identity columns are written. ' +
    'Report every failure as a finding with the exact command and output as evidence. green=true only if there are zero blocker/major findings.\n' +
    (round > 1 ? '\nA fixer just addressed these findings:\n' + JSON.stringify(fixes).slice(0, 3000) + '\nRe-verify everything, not only the fixed items.' : ''),
    { label: 'verify:r' + round, phase: 'Verify', model: 'opus', effort: 'medium', schema: FINDINGS })
  if (!verdict || verdict.green) break
  const open = verdict.findings.filter(f => f.severity !== 'minor')
  if (!open.length) break
  log('Verifier round ' + round + ': ' + open.length + ' findings to fix')
  const fixed = await agent(task(7, 'Do Step 4 only. Apply EVERY finding below (all severities), re-run the affected verification, commit.\n' + JSON.stringify(verdict.findings, null, 2)),
    { label: 'fix:r' + round, phase: 'Verify', model: 'opus', effort: 'medium' })
  fixes.push(String(fixed).slice(0, 2000))
}

return {
  schema: String(t1).slice(0, 2000),
  types: String(t2).slice(0, 1500),
  domain: { survey: String(t3).slice(0, 2000), response: String(t4).slice(0, 2000), report: String(t5).slice(0, 2000) },
  wiring: String(t6).slice(0, 3000),
  integrationTest: String(t7).slice(0, 2000),
  verdict,
  fixes,
}
