export const meta = {
  name: 'sc-1-backend',
  description: 'Survey sharing + credits Phase 1-2: migration, park snapshot, report lens, SurveyCredit core and engine, access/list rules, system attendance/event writers, hooks, membrane; then an independent verifier and fix loop',
  phases: [
    { title: 'Schema', detail: 'Task 1: migration, classification, apply to dev + ork_test', model: 'sonnet' },
    { title: 'Domain', detail: 'Task 2, then Tasks 3 + 4 in parallel', model: 'opus' },
    { title: 'Rules', detail: 'Tasks 5-8 in order (shared integration test file)', model: 'opus' },
    { title: 'Engine', detail: 'Task 9 credit engine, Task 10 hooks', model: 'opus' },
    { title: 'Membrane', detail: 'Task 11 model, controllers, CLI sweep, curl contract', model: 'sonnet' },
    { title: 'Verify', detail: 'independent verifier (no browser), fixer, up to two rounds', model: 'opus' },
  ],
}

const A = (typeof args === 'string') ? JSON.parse(args || '{}') : (args || {})

const REPO = '/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias'
const SPEC = 'docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md'
const PLAN = 'docs/superpowers/plans/2026-09-10-survey-sharing-and-credits.md'
const BASE = 'docs/superpowers/specs/2026-09-09-survey-module-design.md'

const COMMON = [
  'You are working in the git repo at ' + REPO + ' on branch feature/survey-module.',
  'Read ' + SPEC + ' (the design) and ' + PLAN + ' (the plan) FIRST; the base module spec is ' + BASE + ' ("base §N").',
  'Your task is one numbered task in the plan: do exactly that task, every checkbox step, and nothing outside it. The plan\'s "Global Constraints" apply.',
  'Task 0 is resolved: the baseline is committed; the only expected dirty file besides your own work is system/lib/ork3/class.Authorization.php (local auth bypass) — never stage it.',
  'The plan\'s code blocks are the intended implementation. Line numbers in the plan are approximate: locate by the quoted code, not the number. If the plan\'s code is wrong against the real file (a name, a signature, a column), fix it minimally, keep the intent, and REPORT the deviation.',
  '',
  'HARD RULES:',
  '- .tpl files are PLAIN PHP. All SQL lives in system/lib/ork3/. Under orkui/ never $DB->, Ork3::$Lib, or new Survey(/SurveyResponse(/SurveyReport(/SurveyCredit( outside orkui/model/model.Survey.php.',
  '- $this->db->Clear() before every DataSet/Execute; (int) cast ids; esc() strings; ExecuteChecked for writes; read new ids with SELECT LAST_INSERT_ID() AS new_id and never treat 0 as a duplicate signal.',
  '- Only non-test consent=full responses are ever credited (spec D1). Shared viewers never reach rows/CSV/panel/builder (D2). Enforce on the server.',
  '- Before editing an EXISTING PHP file run: awk \'/^\\t/{c++}END{print c+0}\' <file>; if non-zero (and not a .tpl), run tools/php-cs-fixer/php-cs-fixer.phar fix <file> first and commit that separately.',
  '- Stage and commit EXPLICIT paths only, using the path-limited form so another agent\'s staged files can never ride along:',
  '    git add <paths> && git commit -m "<msg>" -- <same paths>',
  '  Never git add -A / git add . / git commit -a. Never stage class.Authorization.php, CLAUDE.md, agent-instructions/claude.md. If .git/index.lock exists, wait 5s and retry (another agent may be committing).',
  '- Never push. Never git stash. Never create git worktrees. Never delete DB rows you did not create. NEVER enable credits on surveys 999048, 999049, 999051 (configs are permanent) — use a clone or a new scratch survey.',
  '- Do NOT use any browser in this workflow. Verify with php -l, phpunit, curl and mariadb. Paste real output.',
  '- Local facts: app http://localhost:19080/orkui/ ; routes index.php?Route=Controller/action/arg ; login: curl -c jar -b jar -X POST "http://localhost:19080/orkui/index.php?Route=Login/login" -d "username=heraldsbridge&password=x&Action=Sign+In" (auth bypass accepts any password); heraldsbridge = mundane 46193, kingdom 17, park 1049, ORK admin.',
  '  DB: docker exec ork3-php8-db mariadb -uroot -proot ork ; sandbox: docker exec ork3-php8-test-db mariadb -uroot -proot ork_test ; after a migration: docker restart ork3-php8-app ; container repo path /var/www/ork.amtgard.com.',
  '  Tests: ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter <Name> (bin/run-unit-tests.sh never reaches phpunit locally). drift-check\'s "catalog hash drift" line is a known local-only failure; ignore it. deploy-sandbox --force-refresh fails locally — apply migrations to ork_test directly.',
  '- Commit message prefix "Enhancement: Survey — …" and end the message with these two trailer lines:',
  '    Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>',
  '    Claude-Session: https://claude.ai/code/session_015f7Q1rwtQLdNo6Sx1YwLqm',
  '',
  'RETURN (data for an orchestrator, not prose for a human): files created/modified, commit hashes, the exact public signatures you added, the verification commands you ran with their real output, and every deviation from the plan.',
].join('\n')

function task(n, extra) {
  return COMMON + '\n\n## YOUR TASK: Task ' + n + ' in ' + PLAN + '\n' + (extra || '')
}

function brief(label, r, n) {
  return '--- ' + label + ' ---\n' + String(r || '(no report)').slice(0, n || 1500)
}

phase('Schema')
const t1 = await agent(task(1,
  'Apply the migration twice to the dev mirror (second run silent) and once to ork_test, restart the app, and paste the verification query output for BOTH databases. If drift-check needs the override render described in Step 4, do it.'),
  { label: 'T1:migration', phase: 'Schema', model: 'sonnet', effort: 'low' })

phase('Domain')
const t2 = await agent(task(2, 'Migration report:\n' + brief('T1', t1, 1200)),
  { label: 'T2:park-snapshot', phase: 'Domain', model: 'sonnet', effort: 'medium' })

const sib = '\n\nTask 2 is committed (SurveyResponse::kingdomIdList is now public; scrubForConsent keeps park_id for full only):\n' + brief('T2', t2, 1000) +
  '\n\nAnother agent is writing a sibling task RIGHT NOW. Touch only the files your task lists. Commit with the path-limited form.'
const [t3, t4] = await parallel([
  () => agent(task(3, sib + '\n\nThe lens must survive any later normalizeFilters() call — that is why park_id and impossible are real filter keys. Read every responseWhere()/reportWhere() call site and confirm none can widen past the lens; report what you checked.'),
    { label: 'T3:report-lens', phase: 'Domain', model: 'sonnet', effort: 'high' }),
  () => agent(task(4, sib + '\n\nThe pure functions are the precedence and privacy core of the credit feature. Write the tests first, watch them fail, then implement. Every signature in the Interfaces block must match exactly — Tasks 5, 6 and 9 code against it.'),
    { label: 'T4:credit-core', phase: 'Domain', model: 'opus', effort: 'high' }),
])

phase('Rules')
const domainNote = '\n\nEarlier tasks are committed:\n' + brief('T3 report lens', t3, 1000) + '\n' + brief('T4 credit core', t4, 1000)
const t5 = await agent(task(5, domainNote + '\n\nYou create tests/Integration/SurveyOrgFixture.php and tests/Integration/SurveySharingCreditTest.php; later tasks append to the test class, so keep its setUp org tree exactly as the plan writes it.'),
  { label: 'T5:access', phase: 'Rules', model: 'opus', effort: 'medium' })
const t6 = await agent(task(6, domainNote + '\n' + brief('T5', t5, 1000)),
  { label: 'T6:list-for-scope', phase: 'Rules', model: 'opus', effort: 'medium' })
const t7 = await agent(task(7, '\n\n' + brief('T6', t6, 800)),
  { label: 'T7:system-credit', phase: 'Rules', model: 'sonnet', effort: 'medium' })
const t8 = await agent(task(8, '\n\n' + brief('T7', t7, 800)),
  { label: 'T8:system-event', phase: 'Rules', model: 'sonnet', effort: 'medium' })

phase('Engine')
const t9 = await agent(task(9,
  '\n\nCommitted so far:\n' + brief('T4 credit core', t4, 800) + '\n' + brief('T7 AddSystemCredit', t7, 800) + '\n' + brief('T8 CreateSystemEvent', t8, 800) +
  '\n\nRe-read spec §3 end to end before coding. grant() must keep the attendance insert and the ledger insert in ONE transaction and must never be reached for a non-full response. CreateSystemEvent opens its own transaction — ensureEvent() must never be called while grant()\'s transaction is open.'),
  { label: 'T9:credit-engine', phase: 'Engine', model: 'opus', effort: 'high' })
const t10 = await agent(task(10, '\n\n' + brief('T9', t9, 1500) +
  '\n\nThe submit() credit call goes AFTER the COMMIT and must never change the submit result; wrap it exactly as the plan shows.'),
  { label: 'T10:hooks', phase: 'Engine', model: 'sonnet', effort: 'medium' })

phase('Membrane')
const t11 = await agent(task(11,
  '\n\nThe domain is complete and committed. Reports:\n' + brief('T5 access', t5, 700) + '\n' + brief('T6 lists', t6, 700) + '\n' + brief('T9 engine', t9, 1200) + '\n' + brief('T10 hooks', t10, 700) +
  '\n\nRead the real domain signatures before writing the model delegates. For Step 7, create a scratch survey for the enable path (clone 999051 via SurveyAjax/clone with the CSRF token) and report its id; never enable credits on 999048/999049/999051.'),
  { label: 'T11:membrane', phase: 'Membrane', model: 'sonnet', effort: 'medium' })

phase('Verify')
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
    scratchSurveyIds: { type: 'array', items: { type: 'integer' } },
    notes: { type: 'string' },
  },
  required: ['findings', 'green'],
}

const reports = [t1, t2, t3, t4, t5, t6, t7, t8, t9, t10, t11].map((r, i) => brief('T' + (i + 1), r, 900)).join('\n')
let verdict = null
const fixes = []
for (let round = 1; round <= 2; round++) {
  verdict = await agent(COMMON + '\n\n## YOU ARE THE INDEPENDENT VERIFIER for Tasks 1-11. You did not write this code. Do NOT fix anything.\n' +
    'Run, and paste real output for each:\n' +
    '1. ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist --filter Survey  (all Survey* unit + integration).\n' +
    '2. php -l on every PHP file changed since the commit before Task 1 (git log to find it; git diff --name-only <base>.. -- "*.php").\n' +
    '3. The layering grep from Task 11 Step 6.\n' +
    '4. The Task 11 Step 7 curl contract from a FRESH cookie jar, plus: credit_enable without X-CSRF-Token -> csrf:true; credit_status as a player with no authority (find a non-officer mundane in kingdom 17, log in as them) -> status 3; SurveyAjax/rows and Survey/export for a shared-only viewer -> refused.\n' +
    '5. Privacy by READING code: the only INSERT into ork_survey_credit_grant is in SurveyCredit::grant(); grantFor()/reconcile() only ever select consent=\'full\' AND is_test=0; submit() calls grantFor after COMMIT and only for full; AddSystemCredit and CreateSystemEvent are only called from SurveyCredit.\n' +
    '6. Idempotency: on the scratch survey from T11 (or a new one you create and clone), run docker exec -e HTTP_HOST=localhost:19080 ork3-php8-app php /var/www/ork.amtgard.com/bin/survey-credit-sweep.php twice (the sweep exits 2 without a host) and show the ork_attendance count for note=\'Survey #<id>\' is unchanged by the second run.\n' +
    '7. Migration re-run on dev is silent; ork_test has the same columns.\n' +
    'Builder reports:\n' + reports.slice(0, 9000) +
    (round > 1 ? '\n\nA fixer just addressed these findings:\n' + JSON.stringify(fixes).slice(0, 3000) + '\nRe-verify EVERYTHING, not only the fixed items.' : '') +
    '\n\nReport every failure as a finding with the exact command and output as evidence. green=true only with zero blocker/major findings. List any scratch survey ids you created in scratchSurveyIds.',
    { label: 'verify:r' + round, phase: 'Verify', model: 'opus', effort: 'high', schema: FINDINGS })
  // Polish rule: ALL findings get fixed, minors included — stop only when there are none.
  if (!verdict || !verdict.findings.length) break
  const open = verdict.findings
  log('Verifier round ' + round + ': ' + open.length + ' findings to fix (all severities)')
  const fixed = await agent(COMMON + '\n\n## YOU ARE THE FIXER for Tasks 1-11. Apply EVERY finding below (all severities, minors included), write or extend a test for each behavioural fix, re-run the affected verification, and commit per logical fix with the path-limited form.\n' +
    JSON.stringify(verdict.findings, null, 2),
    { label: 'fix:r' + round, phase: 'Verify', model: 'opus', effort: 'high' })
  fixes.push(String(fixed).slice(0, 2500))
}

const tasks = {}
;[t1, t2, t3, t4, t5, t6, t7, t8, t9, t10, t11].forEach((r, i) => { tasks['T' + (i + 1)] = String(r || '').slice(0, 1500) })
return { tasks, verdict, fixes }
