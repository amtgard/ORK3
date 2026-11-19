# Digital Waivers Amendment — Real-World Coverage Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the shipped Digital Waivers system (branch `feature/digital-waivers`) so it can express the field set found on six real-world kingdom waivers — demographics (DOB, address, phone, email, preferred name, gender), emergency contact, multi-minor roster, witness signature, kingdom-defined custom fields (checkbox / radio / initial / text / date), and officer ID-intake metadata.

**Architecture:** Additive-only migration; extend `ork_waiver_template` with nine feature flags + `custom_fields_json`, extend `ork_waiver_signature` with demographic/emergency/witness/custom-responses/ID-intake columns, add child table `ork_waiver_signature_minor` for multi-minor rosters. Domain, model, controller, and template layers extended — no new controllers or routes. Reference spec: [docs/superpowers/specs/2026-04-17-digital-waivers-design.md](../specs/2026-04-17-digital-waivers-design.md) §Amendment 1.

**Tech Stack:** Same as base plan — PHP 8 / MariaDB 10.x / yapo ORM / vanilla JS + fetch / Parsedown / `wv-` CSS prefix / real-DB tests via `docker exec ork3-php8-app php tests/php/WaiverTest.php`.

**Hard project rules (enforced throughout — all inherited from base plan):**
- Edit PHP files ≥2 lines via Python `pathlib` string replace, NEVER the Edit tool. Single-line PHP edits may use Edit.
- `$DB->Clear()` before any raw `Execute()` / `DataSet()`.
- NEVER stage `class.Authorization.php`; leave out of every commit.
- NEVER commit `CLAUDE.md` / `agent-instructions/claude.md`.
- New headings reset global gray-box `h1-h6` styling.
- Debug output goes to the browser console only (`console.log`, `die(json_encode(...))`).
- `revised.js` IIFE guards use a config flag, never `document.getElementById`.
- No native `title` attributes — use `data-tip`.
- Stage files explicitly by name, never `git add -A`.
- Tests hit a real DB. No mocking.
- `$r['Status']['Status']` — NOT `['Code']` — holds the numeric error code.
- Status helpers: `Success()`, `NoAuthorization()`, `ProcessingError()`, `InvalidParameter()`, `BadToken()`, `Warning()`. No `Error()`, `Unauthorized()`, `NotFound()`.
- `$DB->DataSet($sql)` and `$DB->Execute($sql)` DO NOT accept a params array — use `$DB->field = $val` before the call, then `:field` in SQL.
- `Yapo::save()` returns `null`, not a bool — check post-save state.
- `IsAuthorized_h` caches `mundane_id` in `$_SESSION['is_authorized_mundane_id']`; test setup must `unset()` it when issuing new tokens.
- PR title (for any follow-up PR off this amendment): `Enhancement: Digital Waivers — Real-World Coverage`.

---

## File map

**Create:**
- `db-migrations/2026-04-18-digital-waivers-amendment.sql`

**Modify:**
- `system/lib/ork3/class.Waiver.php` — new request keys accepted on SaveTemplate / SubmitSignature / VerifySignature; new `_shape_template` / `_shape_signature` output keys; new `GetSignatureMinors()` method; `custom_fields_json` validator.
- `orkui/template/revised-frontend/Waiver_builder.tpl` — Fields & Demographics pane above the existing grid; custom-fields editor; Max-minors input.
- `orkui/template/revised-frontend/Waiver_sign.tpl` — conditional demographics/emergency/witness blocks; minors repeater; custom-fields renderer; client-side required-field validator.
- `orkui/template/revised-frontend/Waiver_review.tpl` — demographics / emergency / custom-response / minors / witness render; officer form ID-intake fields.
- `orkui/template/revised-frontend/Waiver_print.tpl` — mirror review-page render.
- `orkui/controller/controller.Waiver.php` — `review()` hands new data shape to template; `sign()` prefills demographics from player.
- `tests/php/WaiverTest.php` — 15+ new assertions covering the additive surface.

No new routes. No new controllers. No new files beyond the migration.

---

## Phase 0 — Migration & domain extensions (unblocks everything)

### Task 0.1: Write additive migration SQL

**Files:**
- Create: `db-migrations/2026-04-18-digital-waivers-amendment.sql`

- [ ] **Step 1: Write the migration file**

```sql
-- Digital Waivers Amendment 1 — real-world waiver coverage.
-- Additive. Safe to run on a DB that has the 2026-04-17 base migration.
-- 2026-04-18

ALTER TABLE `ork_waiver_template`
  ADD COLUMN `requires_dob`               TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_address`           TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_phone`             TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_email`             TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_preferred_name`    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_gender`            TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_emergency_contact` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `requires_witness`           TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `max_minors`                 TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN `custom_fields_json`         MEDIUMTEXT NOT NULL;

ALTER TABLE `ork_waiver_signature`
  ADD COLUMN `preferred_name_snapshot`         VARCHAR(64)  NOT NULL DEFAULT '',
  ADD COLUMN `dob_snapshot`                    DATE NULL DEFAULT NULL,
  ADD COLUMN `gender_snapshot`                 VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `address_snapshot`                VARCHAR(255) NOT NULL DEFAULT '',
  ADD COLUMN `phone_snapshot`                  VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `email_snapshot`                  VARCHAR(128) NOT NULL DEFAULT '',
  ADD COLUMN `emergency_contact_name`          VARCHAR(128) NOT NULL DEFAULT '',
  ADD COLUMN `emergency_contact_phone`         VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `emergency_contact_relationship`  VARCHAR(64)  NOT NULL DEFAULT '',
  ADD COLUMN `witness_printed_name`            VARCHAR(128) NOT NULL DEFAULT '',
  ADD COLUMN `witness_signature_type`          ENUM('drawn','typed') NULL DEFAULT NULL,
  ADD COLUMN `witness_signature_data`          MEDIUMTEXT NULL DEFAULT NULL,
  ADD COLUMN `custom_responses_json`           MEDIUMTEXT NOT NULL,
  ADD COLUMN `verifier_id_type`                VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `verifier_id_number_last4`        VARCHAR(8)   NOT NULL DEFAULT '',
  ADD COLUMN `verifier_age_bracket`            ENUM('', '18+', '14+', 'under14') NOT NULL DEFAULT '',
  ADD COLUMN `verifier_scanned_paper`          TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `ork_waiver_signature_minor` (
  `waiver_signature_minor_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waiver_signature_id`       INT UNSIGNED NOT NULL,
  `seq`                       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `legal_first`               VARCHAR(64)  NOT NULL DEFAULT '',
  `legal_last`                VARCHAR(64)  NOT NULL DEFAULT '',
  `preferred_name`            VARCHAR(64)  NOT NULL DEFAULT '',
  `persona_name`              VARCHAR(128) NOT NULL DEFAULT '',
  `dob`                       DATE NULL DEFAULT NULL,
  PRIMARY KEY (`waiver_signature_minor_id`),
  KEY `idx_signature` (`waiver_signature_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Run the migration against dev DB**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-04-18-digital-waivers-amendment.sql
```
Expected: no output, exit code 0. Confirm with:
```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "DESCRIBE ork_waiver_template" | grep requires_dob
docker exec ork3-php8-db mariadb -u root -proot ork -e "DESCRIBE ork_waiver_signature" | grep dob_snapshot
docker exec ork3-php8-db mariadb -u root -proot ork -e "SHOW TABLES LIKE 'ork_waiver_signature_minor'"
```
Expected: each command returns a row.

- [ ] **Step 3: Commit**

```bash
git add db-migrations/2026-04-18-digital-waivers-amendment.sql
git commit -m "Enhancement: Digital Waivers — amendment migration (demographics, minors, custom fields)"
```

---

### Task 0.2: Extend `class.Waiver.php` — SaveTemplate writes new columns

**Files:**
- Modify: `system/lib/ork3/class.Waiver.php:14-70` (SaveTemplate) and `:72-87` (_shape_template).

- [ ] **Step 1: Write the failing test first**

Append to `tests/php/WaiverTest.php` inside the `run()` driver, before the final `Summary` section — add a new numbered section. Target the `->tests` registry pattern the existing file uses:

```php
echo "\n--- A1: SaveTemplate persists new feature flags + custom_fields_json ---\n";
$customFields = json_encode([
	['id' => 'yp_ack', 'type' => 'checkbox', 'label' => 'Read Youth Policy', 'required' => true],
	['id' => 'visit_type', 'type' => 'radio', 'label' => 'Visit type',
	 'options' => ['Joining', 'Transferring', 'Updating'], 'required' => true],
]);
$r = $this->waiver->SaveTemplate([
	'Token' => $this->token, 'KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom',
	'IsEnabled' => 1,
	'HeaderMarkdown' => '# H', 'BodyMarkdown' => 'B', 'FooterMarkdown' => 'F', 'MinorMarkdown' => 'M',
	'RequiresDob' => 1, 'RequiresAddress' => 1, 'RequiresEmergencyContact' => 1,
	'RequiresWitness' => 1, 'MaxMinors' => 4,
	'CustomFieldsJson' => $customFields,
]);
$this->assertStatus(0, $r, 'A1 SaveTemplate with new flags succeeds');
$tid = (int)($r['TemplateId'] ?? 0);
$this->assertTrue($tid > 0, 'A1 TemplateId returned');

$t = $this->waiver->GetTemplate(['TemplateId' => $tid]);
$this->assertStatus(0, $t, 'A1 GetTemplate succeeds');
$tpl = $t['Template'];
$this->assertEq(1, (int)$tpl['RequiresDob'],               'A1 RequiresDob persisted');
$this->assertEq(1, (int)$tpl['RequiresAddress'],           'A1 RequiresAddress persisted');
$this->assertEq(1, (int)$tpl['RequiresEmergencyContact'], 'A1 RequiresEmergencyContact persisted');
$this->assertEq(1, (int)$tpl['RequiresWitness'],           'A1 RequiresWitness persisted');
$this->assertEq(4, (int)$tpl['MaxMinors'],                 'A1 MaxMinors persisted');
$decoded = json_decode($tpl['CustomFieldsJson'] ?? '[]', true);
$this->assertTrue(is_array($decoded) && count($decoded) === 2, 'A1 CustomFieldsJson decodes to 2 entries');
$this->assertEq('yp_ack', $decoded[0]['id'] ?? null, 'A1 CustomFieldsJson first id preserved');
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker exec -e ENVIRONMENT=DEV -w /var/www/ork.amtgard.com/tests/php ork3-php8-app php WaiverTest.php
```
Expected: the A1 section fails every assertion because `RequiresDob`, etc., don't yet exist in the shape.

- [ ] **Step 3: Extend `SaveTemplate` to read new request keys and write them to the template row**

Using Python `pathlib` (PHP file, multi-line):

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Waiver.php')
t = p.read_text()
old = """\t\t$this->template->kingdom_id            = $kingdom_id;
\t\t$this->template->scope                 = $scope;
\t\t$this->template->version               = $nextVersion;
\t\t$this->template->is_active             = 1;
\t\t$this->template->is_enabled            = ((int)($request['IsEnabled'] ?? 0)) ? 1 : 0;
\t\t$this->template->header_markdown       = (string)($request['HeaderMarkdown'] ?? '');
\t\t$this->template->body_markdown         = (string)($request['BodyMarkdown']   ?? '');
\t\t$this->template->footer_markdown       = (string)($request['FooterMarkdown'] ?? '');
\t\t$this->template->minor_markdown        = (string)($request['MinorMarkdown']  ?? '');
\t\t$this->template->created_by_mundane_id = $mundane_id;
\t\t$this->template->created_at            = date('Y-m-d H:i:s');
\t\t$this->template->save();"""
new = """\t\t$cfRaw = (string)($request['CustomFieldsJson'] ?? '[]');
\t\t$cfErr = $this->_validate_custom_fields_json($cfRaw);
\t\tif ($cfErr !== null) return ['Status' => InvalidParameter($cfErr)];
\t\t$maxMinors = max(1, min(6, (int)($request['MaxMinors'] ?? 1)));

\t\t$this->template->kingdom_id                = $kingdom_id;
\t\t$this->template->scope                     = $scope;
\t\t$this->template->version                   = $nextVersion;
\t\t$this->template->is_active                 = 1;
\t\t$this->template->is_enabled                = ((int)($request['IsEnabled'] ?? 0)) ? 1 : 0;
\t\t$this->template->header_markdown           = (string)($request['HeaderMarkdown'] ?? '');
\t\t$this->template->body_markdown             = (string)($request['BodyMarkdown']   ?? '');
\t\t$this->template->footer_markdown           = (string)($request['FooterMarkdown'] ?? '');
\t\t$this->template->minor_markdown            = (string)($request['MinorMarkdown']  ?? '');
\t\t$this->template->requires_dob              = ((int)($request['RequiresDob']              ?? 0)) ? 1 : 0;
\t\t$this->template->requires_address          = ((int)($request['RequiresAddress']          ?? 0)) ? 1 : 0;
\t\t$this->template->requires_phone            = ((int)($request['RequiresPhone']            ?? 0)) ? 1 : 0;
\t\t$this->template->requires_email            = ((int)($request['RequiresEmail']            ?? 0)) ? 1 : 0;
\t\t$this->template->requires_preferred_name   = ((int)($request['RequiresPreferredName']    ?? 0)) ? 1 : 0;
\t\t$this->template->requires_gender           = ((int)($request['RequiresGender']           ?? 0)) ? 1 : 0;
\t\t$this->template->requires_emergency_contact= ((int)($request['RequiresEmergencyContact'] ?? 0)) ? 1 : 0;
\t\t$this->template->requires_witness          = ((int)($request['RequiresWitness']          ?? 0)) ? 1 : 0;
\t\t$this->template->max_minors                = $maxMinors;
\t\t$this->template->custom_fields_json        = $cfRaw;
\t\t$this->template->created_by_mundane_id     = $mundane_id;
\t\t$this->template->created_at                = date('Y-m-d H:i:s');
\t\t$this->template->save();"""
assert old in t, 'old save block not found'
p.write_text(t.replace(old, new, 1))
print('SaveTemplate patched')
PY
```

- [ ] **Step 4: Add `_validate_custom_fields_json` helper**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Waiver.php')
t = p.read_text()
old = """\tprivate function _shape_template($rs) {"""
new = """\tprivate function _validate_custom_fields_json($raw) {
\t\tif ($raw === '' || $raw === null) return null;
\t\t$arr = json_decode($raw, true);
\t\tif (!is_array($arr))   return 'CustomFieldsJson not valid JSON array';
\t\tif (count($arr) > 50)  return 'CustomFieldsJson exceeds 50 fields';
\t\t$seen = [];
\t\t$allowed = ['text','textarea','checkbox','initial','radio','select','date'];
\t\tforeach ($arr as $i => $f) {
\t\t\tif (!is_array($f)) return \"CustomFieldsJson entry $i not an object\";
\t\t\t$id = (string)($f['id'] ?? '');
\t\t\tif (!preg_match('/^[a-z0-9_]{1,32}$/', $id)) return \"CustomFieldsJson entry $i has invalid id\";
\t\t\tif (isset($seen[$id])) return \"CustomFieldsJson entry $i has duplicate id '$id'\";
\t\t\t$seen[$id] = 1;
\t\t\t$type = (string)($f['type'] ?? '');
\t\t\tif (!in_array($type, $allowed, true)) return \"CustomFieldsJson entry $i type '$type' not allowed\";
\t\t\tif (($type === 'radio' || $type === 'select')) {
\t\t\t\t$opts = $f['options'] ?? null;
\t\t\t\tif (!is_array($opts) || count($opts) < 1) return \"CustomFieldsJson entry $i requires options\";
\t\t\t}
\t\t\t$label = (string)($f['label'] ?? '');
\t\t\tif ($label === '' || strlen($label) > 512) return \"CustomFieldsJson entry $i has invalid label\";
\t\t}
\t\treturn null;
\t}

\tprivate function _shape_template($rs) {"""
assert old in t, '_shape_template anchor not found'
p.write_text(t.replace(old, new, 1))
print('validator added')
PY
```

- [ ] **Step 5: Extend `_shape_template` to expose the new columns**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Waiver.php')
t = p.read_text()
old = """\t\treturn [
\t\t\t'TemplateId'      => (int)$rs->waiver_template_id,
\t\t\t'KingdomId'       => (int)$rs->kingdom_id,
\t\t\t'Scope'           => $rs->scope,
\t\t\t'Version'         => (int)$rs->version,
\t\t\t'IsActive'        => (int)$rs->is_active,
\t\t\t'IsEnabled'       => (int)$rs->is_enabled,
\t\t\t'HeaderMarkdown'  => $rs->header_markdown,
\t\t\t'BodyMarkdown'    => $rs->body_markdown,
\t\t\t'FooterMarkdown'  => $rs->footer_markdown,
\t\t\t'MinorMarkdown'   => $rs->minor_markdown,
\t\t\t'CreatedAt'       => $rs->created_at,
\t\t];"""
new = """\t\treturn [
\t\t\t'TemplateId'                => (int)$rs->waiver_template_id,
\t\t\t'KingdomId'                 => (int)$rs->kingdom_id,
\t\t\t'Scope'                     => $rs->scope,
\t\t\t'Version'                   => (int)$rs->version,
\t\t\t'IsActive'                  => (int)$rs->is_active,
\t\t\t'IsEnabled'                 => (int)$rs->is_enabled,
\t\t\t'HeaderMarkdown'            => $rs->header_markdown,
\t\t\t'BodyMarkdown'              => $rs->body_markdown,
\t\t\t'FooterMarkdown'            => $rs->footer_markdown,
\t\t\t'MinorMarkdown'             => $rs->minor_markdown,
\t\t\t'RequiresDob'               => (int)($rs->requires_dob ?? 0),
\t\t\t'RequiresAddress'           => (int)($rs->requires_address ?? 0),
\t\t\t'RequiresPhone'             => (int)($rs->requires_phone ?? 0),
\t\t\t'RequiresEmail'             => (int)($rs->requires_email ?? 0),
\t\t\t'RequiresPreferredName'     => (int)($rs->requires_preferred_name ?? 0),
\t\t\t'RequiresGender'            => (int)($rs->requires_gender ?? 0),
\t\t\t'RequiresEmergencyContact'  => (int)($rs->requires_emergency_contact ?? 0),
\t\t\t'RequiresWitness'           => (int)($rs->requires_witness ?? 0),
\t\t\t'MaxMinors'                 => (int)($rs->max_minors ?? 1),
\t\t\t'CustomFieldsJson'          => (string)($rs->custom_fields_json ?? '[]'),
\t\t\t'CreatedAt'                 => $rs->created_at,
\t\t];"""
assert old in t, '_shape_template return block not found'
p.write_text(t.replace(old, new, 1))
print('_shape_template patched')
PY
```

- [ ] **Step 6: Run the test**

```bash
docker exec -e ENVIRONMENT=DEV -w /var/www/ork.amtgard.com/tests/php ork3-php8-app php WaiverTest.php
```
Expected: A1 section all PASS; all prior tests still PASS.

- [ ] **Step 7: Commit**

```bash
git add system/lib/ork3/class.Waiver.php tests/php/WaiverTest.php
git commit -m "Enhancement: Digital Waivers — template feature flags + custom_fields_json"
```

---

### Task 0.3: `class.Waiver.php` — `_validate_custom_fields_json` rejection tests

**Files:**
- Modify: `tests/php/WaiverTest.php` (append).

- [ ] **Step 1: Add rejection-path tests**

```php
echo "\n--- A2: _validate_custom_fields_json rejects malformed input ---\n";
$bad = [
	'not-json'                                   => 'not valid JSON array',
	'{"not":"array"}'                            => 'not valid JSON array',
	json_encode([['id' => 'BAD ID', 'type' => 'text', 'label' => 'X']])
		=> 'invalid id',
	json_encode([['id' => 'dup', 'type' => 'text', 'label' => 'A'],
	             ['id' => 'dup', 'type' => 'text', 'label' => 'B']])
		=> 'duplicate',
	json_encode([['id' => 'r1', 'type' => 'radio', 'label' => 'R', 'options' => []]])
		=> 'requires options',
	json_encode([['id' => 'nolabel', 'type' => 'text', 'label' => '']])
		=> 'invalid label',
];
foreach ($bad as $raw => $expectedFragment) {
	$r = $this->waiver->SaveTemplate([
		'Token' => $this->token, 'KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom',
		'CustomFieldsJson' => $raw,
	]);
	$code = $r['Status']['Status'] ?? null;
	$msg  = $r['Status']['Message'] ?? '';
	$this->assertTrue($code !== 0, "A2 reject: $expectedFragment — not Success");
	$this->assertTrue(stripos((string)$msg, $expectedFragment) !== false
		|| stripos(json_encode($r['Status']), $expectedFragment) !== false,
		"A2 message mentions '$expectedFragment'");
}
```

- [ ] **Step 2: Run tests**

```bash
docker exec -e ENVIRONMENT=DEV -w /var/www/ork.amtgard.com/tests/php ork3-php8-app php WaiverTest.php
```
Expected: A2 all PASS. (Assertion on Message fragment may use whichever key `InvalidParameter()` populates — check first failure and adjust the fragment only if the message key differs; DO NOT skip the assertion.)

- [ ] **Step 3: Commit**

```bash
git add tests/php/WaiverTest.php
git commit -m "Enhancement: Digital Waivers — custom_fields_json validator tests"
```

---

### Task 0.4: `class.Waiver.php` — SubmitSignature writes demographics, emergency, witness, custom responses, minors

**Files:**
- Modify: `system/lib/ork3/class.Waiver.php::SubmitSignature` (line ~141 through ~201).
- Modify: `system/lib/ork3/class.Waiver.php` — add `GetSignatureMinors()` helper and wire into `GetSignature()` return.
- Modify: `tests/php/WaiverTest.php` (append).

- [ ] **Step 1: Write the failing test**

```php
echo "\n--- A3: SubmitSignature persists demographics, emergency, witness, custom responses, minors ---\n";
// Reuse the A1 template (kingdom scope, RequiresDob/RequiresAddress/RequiresEmergencyContact/RequiresWitness/MaxMinors=4)
// Need the most-recent active template id for kingdom scope:
$act = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
$this->assertStatus(0, $act, 'A3 active template available');
$tid = (int)$act['Template']['TemplateId'];

$minorsPayload = [
	['LegalFirst' => 'Alice', 'LegalLast' => 'Smith', 'PreferredName' => 'Ali',  'PersonaName' => 'Ali the Bold', 'Dob' => '2015-06-01'],
	['LegalFirst' => 'Bob',   'LegalLast' => 'Smith', 'PreferredName' => '',     'PersonaName' => '',             'Dob' => '2017-02-14'],
];
$customResponses = json_encode(['yp_ack' => true, 'visit_type' => 'Joining']);

$r = $this->waiver->SubmitSignature([
	'Token' => $this->token, 'TemplateId' => $tid,
	'MundaneFirst' => 'Test', 'MundaneLast' => 'Player', 'PersonaName' => 'Tester',
	'ParkId' => $this->testParkId, 'KingdomId' => $this->testKingdomId,
	'SignatureType' => 'typed', 'SignatureData' => 'Test Player',
	'Dob' => '1990-01-02', 'Address' => '123 Amtgard Way', 'Phone' => '555-1212',
	'Email' => 'tester@example.com', 'PreferredName' => 'Testy', 'Gender' => 'non-binary',
	'EmergencyContactName' => 'Jane Doe', 'EmergencyContactPhone' => '555-7777',
	'EmergencyContactRelationship' => 'spouse',
	'WitnessPrintedName' => 'Officer Pat', 'WitnessSignatureType' => 'typed', 'WitnessSignatureData' => 'Officer Pat',
	'CustomResponsesJson' => $customResponses,
	'IsMinor' => 1,
	'MinorRepFirst' => 'Test', 'MinorRepLast' => 'Player', 'MinorRepRelationship' => 'father',
	'Minors' => $minorsPayload,
]);
$this->assertStatus(0, $r, 'A3 SubmitSignature success');
$sid = (int)($r['SignatureId'] ?? 0);
$this->assertTrue($sid > 0, 'A3 SignatureId returned');

$g = $this->waiver->GetSignature(['Token' => $this->token, 'SignatureId' => $sid]);
$this->assertStatus(0, $g, 'A3 GetSignature succeeds');
$sig = $g['Signature'];
$this->assertEq('1990-01-02',       $sig['Dob'] ?? null,                      'A3 Dob snapshot');
$this->assertEq('123 Amtgard Way',  $sig['Address'] ?? null,                  'A3 Address snapshot');
$this->assertEq('tester@example.com', $sig['Email'] ?? null,                  'A3 Email snapshot');
$this->assertEq('Testy',            $sig['PreferredName'] ?? null,            'A3 PreferredName snapshot');
$this->assertEq('non-binary',       $sig['Gender'] ?? null,                   'A3 Gender snapshot');
$this->assertEq('Jane Doe',         $sig['EmergencyContactName'] ?? null,     'A3 Emergency name snapshot');
$this->assertEq('spouse',           $sig['EmergencyContactRelationship'] ?? null, 'A3 Emergency relationship snapshot');
$this->assertEq('Officer Pat',      $sig['WitnessPrintedName'] ?? null,       'A3 Witness name');
$this->assertEq('typed',            $sig['WitnessSignatureType'] ?? null,     'A3 Witness signature type');
$this->assertTrue(is_array($sig['Minors'] ?? null) && count($sig['Minors']) === 2, 'A3 two minors returned');
$this->assertEq('Alice', $sig['Minors'][0]['LegalFirst'] ?? null,             'A3 first minor first name');
$this->assertEq('2017-02-14', $sig['Minors'][1]['Dob'] ?? null,               'A3 second minor DOB');
$resp = json_decode($sig['CustomResponsesJson'] ?? '{}', true);
$this->assertEq('Joining', $resp['visit_type'] ?? null,                       'A3 visit_type response');
$this->assertTrue(!empty($resp['yp_ack']),                                    'A3 yp_ack response');
```

- [ ] **Step 2: Run to verify failure**

```bash
docker exec -e ENVIRONMENT=DEV -w /var/www/ork.amtgard.com/tests/php ork3-php8-app php WaiverTest.php
```
Expected: A3 fails (keys not yet present).

- [ ] **Step 3: Wire writes in `SubmitSignature`**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Waiver.php')
t = p.read_text()
old = """\t\t$this->signature->verification_status    = 'pending';
\t\t$this->signature->verifier_notes         = '';
\t\t$this->signature->save();"""
new = """\t\t$this->signature->preferred_name_snapshot       = substr(trim((string)($request['PreferredName'] ?? '')), 0, 64);
\t\t$dob = (string)($request['Dob'] ?? '');
\t\t$this->signature->dob_snapshot                  = ($dob !== '' && preg_match('/^\\\\d{4}-\\\\d{2}-\\\\d{2}$/', $dob)) ? $dob : null;
\t\t$this->signature->gender_snapshot               = substr(trim((string)($request['Gender']  ?? '')), 0, 32);
\t\t$this->signature->address_snapshot              = substr(trim((string)($request['Address'] ?? '')), 0, 255);
\t\t$this->signature->phone_snapshot                = substr(trim((string)($request['Phone']   ?? '')), 0, 32);
\t\t$this->signature->email_snapshot                = substr(trim((string)($request['Email']   ?? '')), 0, 128);
\t\t$this->signature->emergency_contact_name        = substr(trim((string)($request['EmergencyContactName']         ?? '')), 0, 128);
\t\t$this->signature->emergency_contact_phone       = substr(trim((string)($request['EmergencyContactPhone']        ?? '')), 0, 32);
\t\t$this->signature->emergency_contact_relationship= substr(trim((string)($request['EmergencyContactRelationship'] ?? '')), 0, 64);
\t\t$witType = in_array($request['WitnessSignatureType'] ?? '', ['drawn','typed']) ? $request['WitnessSignatureType'] : null;
\t\t$this->signature->witness_printed_name          = substr(trim((string)($request['WitnessPrintedName'] ?? '')), 0, 128);
\t\t$this->signature->witness_signature_type        = $witType;
\t\t$this->signature->witness_signature_data        = ($witType === null) ? null : (string)($request['WitnessSignatureData'] ?? '');
\t\t$crRaw = (string)($request['CustomResponsesJson'] ?? '{}');
\t\t$crDecoded = json_decode($crRaw, true);
\t\t$this->signature->custom_responses_json         = is_array($crDecoded) ? json_encode($crDecoded) : '{}';
\t\t$this->signature->verification_status           = 'pending';
\t\t$this->signature->verifier_notes                = '';
\t\t$this->signature->save();"""
assert old in t, 'submit save block not found'
p.write_text(t.replace(old, new, 1))
print('SubmitSignature demographic writes added')
PY
```

- [ ] **Step 4: Persist minors via a new helper and call it after the signature row is saved**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Waiver.php')
t = p.read_text()
# Insert minors persistence just after $newId check inside SubmitSignature.
old = """\t\t$newId = (int)$this->signature->waiver_signature_id;
\t\tif ($newId <= 0) return ['Status' => ProcessingError('Signature save failed')];

\t\t// Supersede any prior pending/verified signature by this same player for this template."""
new = """\t\t$newId = (int)$this->signature->waiver_signature_id;
\t\tif ($newId <= 0) return ['Status' => ProcessingError('Signature save failed')];

\t\t// Minors roster (up to template->max_minors). Additive: prior rows for this signature are replaced.
\t\t$minors = $request['Minors'] ?? null;
\t\tif (is_array($minors) && count($minors) > 0) {
\t\t\t$tpl = $this->GetTemplate(['TemplateId' => $tid]);
\t\t\t$maxMinors = (int)($tpl['Template']['MaxMinors'] ?? 1);
\t\t\t$this->db->Clear();
\t\t\t$this->db->waiver_signature_id = $newId;
\t\t\t$this->db->Execute(\"DELETE FROM \" . DB_PREFIX . \"waiver_signature_minor WHERE waiver_signature_id = :waiver_signature_id\");
\t\t\t$minorOrm = new yapo($this->db, DB_PREFIX . 'waiver_signature_minor');
\t\t\t$seq = 0;
\t\t\tforeach ($minors as $m) {
\t\t\t\tif ($seq >= $maxMinors) break;
\t\t\t\t$minorOrm->clear();
\t\t\t\t$minorOrm->waiver_signature_id = $newId;
\t\t\t\t$minorOrm->seq                 = $seq;
\t\t\t\t$minorOrm->legal_first         = substr(trim((string)($m['LegalFirst']    ?? '')), 0, 64);
\t\t\t\t$minorOrm->legal_last          = substr(trim((string)($m['LegalLast']     ?? '')), 0, 64);
\t\t\t\t$minorOrm->preferred_name      = substr(trim((string)($m['PreferredName'] ?? '')), 0, 64);
\t\t\t\t$minorOrm->persona_name        = substr(trim((string)($m['PersonaName']   ?? '')), 0, 128);
\t\t\t\t$mdob = (string)($m['Dob'] ?? '');
\t\t\t\t$minorOrm->dob                 = ($mdob !== '' && preg_match('/^\\\\d{4}-\\\\d{2}-\\\\d{2}$/', $mdob)) ? $mdob : null;
\t\t\t\t$minorOrm->save();
\t\t\t\t$seq++;
\t\t\t}
\t\t}

\t\t// Supersede any prior pending/verified signature by this same player for this template."""
assert old in t, 'minors insertion anchor not found'
p.write_text(t.replace(old, new, 1))
print('Minors persistence inserted')
PY
```

- [ ] **Step 5: Extend `_shape_signature` and `GetSignature` to return new fields + minors**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Waiver.php')
t = p.read_text()
old = """\t\treturn [
\t\t\t'SignatureId'           => (int)$rs->waiver_signature_id,
\t\t\t'TemplateId'            => (int)$rs->waiver_template_id,
\t\t\t'MundaneId'             => (int)$rs->mundane_id,
\t\t\t'MundaneFirst'          => $rs->mundane_first_snapshot,
\t\t\t'MundaneLast'           => $rs->mundane_last_snapshot,
\t\t\t'PersonaName'           => $rs->persona_name_snapshot,
\t\t\t'ParkId'                => (int)$rs->park_id_snapshot,
\t\t\t'KingdomId'             => (int)$rs->kingdom_id_snapshot,
\t\t\t'SignatureType'         => $rs->signature_type,
\t\t\t'SignatureData'         => $rs->signature_data,
\t\t\t'SignedAt'              => $rs->signed_at,
\t\t\t'IsMinor'               => (int)$rs->is_minor,
\t\t\t'MinorRepFirst'         => $rs->minor_rep_first,
\t\t\t'MinorRepLast'          => $rs->minor_rep_last,
\t\t\t'MinorRepRelationship'  => $rs->minor_rep_relationship,
\t\t\t'VerificationStatus'    => $rs->verification_status,
\t\t\t'VerifiedByMundaneId'   => (int)$rs->verified_by_mundane_id,
\t\t\t'VerifiedAt'            => $rs->verified_at,
\t\t\t'VerifierPrintedName'   => $rs->verifier_printed_name,
\t\t\t'VerifierPersonaName'   => $rs->verifier_persona_name,
\t\t\t'VerifierOfficeTitle'   => $rs->verifier_office_title,
\t\t\t'VerifierSignatureType' => $rs->verifier_signature_type,
\t\t\t'VerifierSignatureData' => $rs->verifier_signature_data,
\t\t\t'VerifierNotes'         => $rs->verifier_notes,
\t\t];"""
new = """\t\treturn [
\t\t\t'SignatureId'                 => (int)$rs->waiver_signature_id,
\t\t\t'TemplateId'                  => (int)$rs->waiver_template_id,
\t\t\t'MundaneId'                   => (int)$rs->mundane_id,
\t\t\t'MundaneFirst'                => $rs->mundane_first_snapshot,
\t\t\t'MundaneLast'                 => $rs->mundane_last_snapshot,
\t\t\t'PersonaName'                 => $rs->persona_name_snapshot,
\t\t\t'ParkId'                      => (int)$rs->park_id_snapshot,
\t\t\t'KingdomId'                   => (int)$rs->kingdom_id_snapshot,
\t\t\t'SignatureType'               => $rs->signature_type,
\t\t\t'SignatureData'               => $rs->signature_data,
\t\t\t'SignedAt'                    => $rs->signed_at,
\t\t\t'IsMinor'                     => (int)$rs->is_minor,
\t\t\t'MinorRepFirst'               => $rs->minor_rep_first,
\t\t\t'MinorRepLast'                => $rs->minor_rep_last,
\t\t\t'MinorRepRelationship'        => $rs->minor_rep_relationship,
\t\t\t'PreferredName'               => $rs->preferred_name_snapshot ?? '',
\t\t\t'Dob'                         => $rs->dob_snapshot ?? null,
\t\t\t'Gender'                      => $rs->gender_snapshot ?? '',
\t\t\t'Address'                     => $rs->address_snapshot ?? '',
\t\t\t'Phone'                       => $rs->phone_snapshot ?? '',
\t\t\t'Email'                       => $rs->email_snapshot ?? '',
\t\t\t'EmergencyContactName'        => $rs->emergency_contact_name ?? '',
\t\t\t'EmergencyContactPhone'       => $rs->emergency_contact_phone ?? '',
\t\t\t'EmergencyContactRelationship'=> $rs->emergency_contact_relationship ?? '',
\t\t\t'WitnessPrintedName'          => $rs->witness_printed_name ?? '',
\t\t\t'WitnessSignatureType'        => $rs->witness_signature_type ?? null,
\t\t\t'WitnessSignatureData'        => $rs->witness_signature_data ?? null,
\t\t\t'CustomResponsesJson'         => $rs->custom_responses_json ?? '{}',
\t\t\t'VerificationStatus'          => $rs->verification_status,
\t\t\t'VerifiedByMundaneId'         => (int)$rs->verified_by_mundane_id,
\t\t\t'VerifiedAt'                  => $rs->verified_at,
\t\t\t'VerifierPrintedName'         => $rs->verifier_printed_name,
\t\t\t'VerifierPersonaName'         => $rs->verifier_persona_name,
\t\t\t'VerifierOfficeTitle'         => $rs->verifier_office_title,
\t\t\t'VerifierSignatureType'       => $rs->verifier_signature_type,
\t\t\t'VerifierSignatureData'       => $rs->verifier_signature_data,
\t\t\t'VerifierNotes'               => $rs->verifier_notes,
\t\t\t'VerifierIdType'              => $rs->verifier_id_type ?? '',
\t\t\t'VerifierIdNumberLast4'       => $rs->verifier_id_number_last4 ?? '',
\t\t\t'VerifierAgeBracket'          => $rs->verifier_age_bracket ?? '',
\t\t\t'VerifierScannedPaper'        => (int)($rs->verifier_scanned_paper ?? 0),
\t\t];"""
assert old in t, '_shape_signature anchor not found'
p.write_text(t.replace(old, new, 1))
print('_shape_signature extended')
PY
```

- [ ] **Step 6: Attach minors inside `GetSignature` before return**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Waiver.php')
t = p.read_text()
old = """\t\t$t = $this->GetTemplate(['TemplateId' => $sig['TemplateId']]);
\t\t$sig['Template'] = (($t['Status']['Status'] ?? 1) === 0) ? $t['Template'] : null;

\t\treturn ['Status' => Success(), 'Signature' => $sig];
\t}"""
new = """\t\t$t = $this->GetTemplate(['TemplateId' => $sig['TemplateId']]);
\t\t$sig['Template'] = (($t['Status']['Status'] ?? 1) === 0) ? $t['Template'] : null;

\t\t// Minors roster (child table)
\t\t$this->db->Clear();
\t\t$this->db->waiver_signature_id = $sid;
\t\t$mrs = $this->db->DataSet(\"SELECT * FROM \" . DB_PREFIX . \"waiver_signature_minor WHERE waiver_signature_id = :waiver_signature_id ORDER BY seq ASC\");
\t\t$minors = [];
\t\tif ($mrs) {
\t\t\twhile ($mrs->Next()) {
\t\t\t\t$minors[] = [
\t\t\t\t\t'LegalFirst'    => $mrs->legal_first,
\t\t\t\t\t'LegalLast'     => $mrs->legal_last,
\t\t\t\t\t'PreferredName' => $mrs->preferred_name,
\t\t\t\t\t'PersonaName'   => $mrs->persona_name,
\t\t\t\t\t'Dob'           => $mrs->dob,
\t\t\t\t];
\t\t\t}
\t\t}
\t\t$sig['Minors'] = $minors;

\t\treturn ['Status' => Success(), 'Signature' => $sig];
\t}"""
assert old in t, 'GetSignature return anchor not found'
p.write_text(t.replace(old, new, 1))
print('GetSignature attaches Minors')
PY
```

- [ ] **Step 7: Run tests**

```bash
docker exec -e ENVIRONMENT=DEV -w /var/www/ork.amtgard.com/tests/php ork3-php8-app php WaiverTest.php
```
Expected: A3 all PASS. All prior tests still PASS.

- [ ] **Step 8: Commit**

```bash
git add system/lib/ork3/class.Waiver.php tests/php/WaiverTest.php
git commit -m "Enhancement: Digital Waivers — signature demographics, emergency, witness, minors, custom responses"
```

---

### Task 0.5: `class.Waiver.php` — VerifySignature accepts ID-intake metadata

**Files:**
- Modify: `system/lib/ork3/class.Waiver.php::VerifySignature`
- Modify: `tests/php/WaiverTest.php` (append).

- [ ] **Step 1: Failing test**

```php
echo "\n--- A4: VerifySignature writes ID-intake fields ---\n";
// reuse $sid from A3
$vr = $this->waiver->VerifySignature([
	'Token' => $this->token, 'SignatureId' => $sid, 'Action' => 'verified',
	'PrintedName' => 'Officer Smith', 'PersonaName' => 'Osmith',
	'OfficeTitle' => 'GMR', 'Notes' => '',
	'SignatureType' => 'typed', 'SignatureData' => 'Officer Smith',
	'IdType' => 'Driver License', 'IdNumber' => '1234567890',  // server keeps last4 only
	'AgeBracket' => '18+', 'ScannedPaper' => 1,
]);
$this->assertStatus(0, $vr, 'A4 verify with id intake');
$g = $this->waiver->GetSignature(['Token' => $this->token, 'SignatureId' => $sid]);
$sig = $g['Signature'];
$this->assertEq('Driver License', $sig['VerifierIdType'] ?? null, 'A4 id type persisted');
$this->assertEq('7890', $sig['VerifierIdNumberLast4'] ?? null,   'A4 id number last4 only');
$this->assertEq('18+',  $sig['VerifierAgeBracket'] ?? null,      'A4 age bracket persisted');
$this->assertEq(1,      (int)($sig['VerifierScannedPaper'] ?? 0),'A4 scanned paper flag');
```

- [ ] **Step 2: Run to verify failure**

Expected: A4 assertions fail (fields not written).

- [ ] **Step 3: Patch `VerifySignature`**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Waiver.php')
t = p.read_text()
old = """\t\t$this->signature->verifier_notes          = (string)($request['Notes'] ?? '');
\t\t$this->signature->save();

\t\treturn ['Status' => Success()];
\t}

\tpublic function PreviewMarkdown($request) {"""
new = """\t\t$this->signature->verifier_notes          = (string)($request['Notes'] ?? '');
\t\t$idNumRaw = preg_replace('/[^0-9]/', '', (string)($request['IdNumber'] ?? ''));
\t\t$last4    = ($idNumRaw === '') ? (string)($request['IdNumberLast4'] ?? '') : substr($idNumRaw, -4);
\t\t$last4    = substr(preg_replace('/[^0-9]/', '', $last4), 0, 4);
\t\t$ageIn    = (string)($request['AgeBracket'] ?? '');
\t\t$ageOk    = in_array($ageIn, ['', '18+', '14+', 'under14'], true) ? $ageIn : '';
\t\t$this->signature->verifier_id_type         = substr(trim((string)($request['IdType'] ?? '')), 0, 32);
\t\t$this->signature->verifier_id_number_last4 = $last4;
\t\t$this->signature->verifier_age_bracket     = $ageOk;
\t\t$this->signature->verifier_scanned_paper   = ((int)($request['ScannedPaper'] ?? 0)) ? 1 : 0;
\t\t$this->signature->save();

\t\treturn ['Status' => Success()];
\t}

\tpublic function PreviewMarkdown($request) {"""
assert old in t, 'VerifySignature save block not found'
p.write_text(t.replace(old, new, 1))
print('VerifySignature patched')
PY
```

- [ ] **Step 4: Run tests**

Expected: A4 all PASS.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Waiver.php tests/php/WaiverTest.php
git commit -m "Enhancement: Digital Waivers — verifier ID intake metadata"
```

---

### Task 0.6: `class.Waiver.php` — SubmitSignature enforces template-required fields

**Files:**
- Modify: `system/lib/ork3/class.Waiver.php::SubmitSignature`
- Modify: `tests/php/WaiverTest.php` (append).

- [ ] **Step 1: Failing test (request without DOB against RequiresDob=1 template)**

```php
echo "\n--- A5: SubmitSignature rejects when required template fields missing ---\n";
$r = $this->waiver->SubmitSignature([
	'Token' => $this->token, 'TemplateId' => $tid,  // A1 template requires DOB/address/emergency/witness
	'MundaneFirst' => 'Test', 'MundaneLast' => 'Player', 'PersonaName' => 'Tester',
	'ParkId' => $this->testParkId, 'KingdomId' => $this->testKingdomId,
	'SignatureType' => 'typed', 'SignatureData' => 'Test Player',
	// deliberately omit Dob, Address, EmergencyContactName, Witness*
]);
$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'A5 missing required fields -> non-Success');

// Also fail when a custom-field required response is missing.
$r2 = $this->waiver->SubmitSignature([
	'Token' => $this->token, 'TemplateId' => $tid,
	'MundaneFirst' => 'Test', 'MundaneLast' => 'Player', 'PersonaName' => 'Tester',
	'ParkId' => $this->testParkId, 'KingdomId' => $this->testKingdomId,
	'SignatureType' => 'typed', 'SignatureData' => 'Test Player',
	'Dob' => '1990-01-02', 'Address' => 'x', 'Phone' => 'x', 'Email' => 'x@y.z',
	'EmergencyContactName' => 'x', 'EmergencyContactPhone' => 'x', 'EmergencyContactRelationship' => 'x',
	'WitnessPrintedName' => 'x', 'WitnessSignatureType' => 'typed', 'WitnessSignatureData' => 'x',
	// custom required yp_ack + visit_type deliberately omitted
	'CustomResponsesJson' => '{}',
]);
$this->assertTrue(($r2['Status']['Status'] ?? 0) !== 0, 'A5 missing required custom response -> non-Success');
```

- [ ] **Step 2: Insert validator between template-load and signature-write**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Waiver.php')
t = p.read_text()
old = """\t\t$isMinor = ((int)($request['IsMinor'] ?? 0)) ? 1 : 0;
\t\tif ($isMinor) {"""
new = """\t\t// Enforce template demographic requirements
\t\t$reqMap = [
\t\t\t'RequiresDob' => ['Dob', 'Date of birth'],
\t\t\t'RequiresAddress' => ['Address', 'Address'],
\t\t\t'RequiresPhone' => ['Phone', 'Phone number'],
\t\t\t'RequiresEmail' => ['Email', 'Email'],
\t\t\t'RequiresPreferredName' => ['PreferredName', 'Preferred name'],
\t\t\t'RequiresGender' => ['Gender', 'Gender'],
\t\t];
\t\tforeach ($reqMap as $flag => $pair) {
\t\t\tif ((int)($t['Template'][$flag] ?? 0) === 1) {
\t\t\t\tif (trim((string)($request[$pair[0]] ?? '')) === '') {
\t\t\t\t\treturn ['Status' => InvalidParameter($pair[1] . ' is required')];
\t\t\t\t}
\t\t\t}
\t\t}
\t\tif ((int)($t['Template']['RequiresEmergencyContact'] ?? 0) === 1) {
\t\t\tforeach ([['EmergencyContactName', 'Emergency contact name'],
\t\t\t          ['EmergencyContactPhone', 'Emergency contact phone'],
\t\t\t          ['EmergencyContactRelationship', 'Emergency contact relationship']] as $pair) {
\t\t\t\tif (trim((string)($request[$pair[0]] ?? '')) === '') {
\t\t\t\t\treturn ['Status' => InvalidParameter($pair[1] . ' is required')];
\t\t\t\t}
\t\t\t}
\t\t}
\t\tif ((int)($t['Template']['RequiresWitness'] ?? 0) === 1) {
\t\t\tif (trim((string)($request['WitnessPrintedName'] ?? '')) === '')
\t\t\t\treturn ['Status' => InvalidParameter('Witness printed name is required')];
\t\t\tif (!in_array($request['WitnessSignatureType'] ?? '', ['drawn','typed'], true))
\t\t\t\treturn ['Status' => InvalidParameter('Witness signature type is required')];
\t\t\tif (trim((string)($request['WitnessSignatureData'] ?? '')) === '')
\t\t\t\treturn ['Status' => InvalidParameter('Witness signature is required')];
\t\t}
\t\t$cfTplRaw = (string)($t['Template']['CustomFieldsJson'] ?? '[]');
\t\t$cfTpl = json_decode($cfTplRaw, true) ?: [];
\t\t$cfResp = json_decode((string)($request['CustomResponsesJson'] ?? '{}'), true);
\t\t$cfResp = is_array($cfResp) ? $cfResp : [];
\t\tforeach ($cfTpl as $f) {
\t\t\tif (empty($f['required'])) continue;
\t\t\t$id = (string)($f['id'] ?? '');
\t\t\t$v  = $cfResp[$id] ?? null;
\t\t\tif ($v === null || $v === '' || $v === false) {
\t\t\t\treturn ['Status' => InvalidParameter('Custom field \"' . ($f['label'] ?? $id) . '\" is required')];
\t\t\t}
\t\t}

\t\t$isMinor = ((int)($request['IsMinor'] ?? 0)) ? 1 : 0;
\t\tif ($isMinor) {"""
assert old in t, 'pre-isMinor anchor not found'
p.write_text(t.replace(old, new, 1))
print('SubmitSignature required-field enforcement added')
PY
```

- [ ] **Step 3: Run tests**

Expected: A5 PASS, A1-A4 still PASS.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Waiver.php tests/php/WaiverTest.php
git commit -m "Enhancement: Digital Waivers — enforce required template fields on submission"
```

---

## Phase 1 — Builder UX

### Task 1.1: Waiver_builder.tpl — Fields & Demographics pane

**Files:**
- Modify: `orkui/template/revised-frontend/Waiver_builder.tpl`

- [ ] **Step 1: Patch the template — insert the new pane above the existing `.wv-grid`, inside each per-scope `<form>`**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Waiver_builder.tpl')
t = p.read_text()
old = """\t\t\t\t<div class=\"wv-grid\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div class=\"wv-field\">
\t\t\t\t\t\t\t<label>Header (shown on every page &mdash; markdown)</label>"""
new = """\t\t\t\t<div class=\"wv-fields-pane\">
\t\t\t\t\t<h3>Fields &amp; Demographics</h3>
\t\t\t\t\t<p class=\"wv-hint\">Toggle the data you want signers to provide. All demographics prefill from the signer's profile where available.</p>
\t\t\t\t\t<div class=\"wv-dem-grid\">
\t\t\t\t\t\t<label><input type=\"checkbox\" name=\"RequiresDob\"               value=\"1\" <?= (!empty($tpl['RequiresDob']))              ? 'checked' : '' ?>> Date of birth</label>
\t\t\t\t\t\t<label><input type=\"checkbox\" name=\"RequiresPreferredName\"     value=\"1\" <?= (!empty($tpl['RequiresPreferredName']))    ? 'checked' : '' ?>> Preferred name</label>
\t\t\t\t\t\t<label><input type=\"checkbox\" name=\"RequiresGender\"            value=\"1\" <?= (!empty($tpl['RequiresGender']))           ? 'checked' : '' ?>> Gender</label>
\t\t\t\t\t\t<label><input type=\"checkbox\" name=\"RequiresAddress\"           value=\"1\" <?= (!empty($tpl['RequiresAddress']))          ? 'checked' : '' ?>> Address</label>
\t\t\t\t\t\t<label><input type=\"checkbox\" name=\"RequiresPhone\"             value=\"1\" <?= (!empty($tpl['RequiresPhone']))            ? 'checked' : '' ?>> Phone</label>
\t\t\t\t\t\t<label><input type=\"checkbox\" name=\"RequiresEmail\"             value=\"1\" <?= (!empty($tpl['RequiresEmail']))            ? 'checked' : '' ?>> Email</label>
\t\t\t\t\t\t<label><input type=\"checkbox\" name=\"RequiresEmergencyContact\" value=\"1\" <?= (!empty($tpl['RequiresEmergencyContact'])) ? 'checked' : '' ?>> Emergency contact</label>
\t\t\t\t\t\t<label><input type=\"checkbox\" name=\"RequiresWitness\"           value=\"1\" <?= (!empty($tpl['RequiresWitness']))          ? 'checked' : '' ?>> Witness signature</label>
\t\t\t\t\t</div>
\t\t\t\t\t<div class=\"wv-field wv-max-minors-field\">
\t\t\t\t\t\t<label>Maximum minors per signing</label>
\t\t\t\t\t\t<input type=\"number\" name=\"MaxMinors\" min=\"1\" max=\"6\" value=\"<?= (int)($tpl['MaxMinors'] ?? 1) ?>\">
\t\t\t\t\t\t<span class=\"wv-hint\">1 for individual waivers; up to 6 for family waivers.</span>
\t\t\t\t\t</div>
\t\t\t\t\t<div class=\"wv-field\">
\t\t\t\t\t\t<label>Custom fields</label>
\t\t\t\t\t\t<div class=\"wv-cfe\" data-scope=\"<?= $scope ?>\"></div>
\t\t\t\t\t\t<button type=\"button\" class=\"wv-cfe-add\">+ Add custom field</button>
\t\t\t\t\t\t<input type=\"hidden\" name=\"CustomFieldsJson\" value='<?= htmlspecialchars($tpl['CustomFieldsJson'] ?? '[]', ENT_QUOTES) ?>'>
\t\t\t\t\t</div>
\t\t\t\t</div>

\t\t\t\t<div class=\"wv-grid\">
\t\t\t\t\t<div>
\t\t\t\t\t\t<div class=\"wv-field\">
\t\t\t\t\t\t\t<label>Header (shown on every page &mdash; markdown)</label>"""
assert old in t, 'builder grid anchor not found'
p.write_text(t.replace(old, new, 1))
print('builder Fields & Demographics pane inserted')
PY
```

- [ ] **Step 2: Add styles for the new pane**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Waiver_builder.tpl')
t = p.read_text()
old = """.wv-builder .wv-status-err  { color: #a00; font-weight: bold; }
</style>"""
new = """.wv-builder .wv-status-err  { color: #a00; font-weight: bold; }
.wv-builder .wv-fields-pane { background: #fafcff; border: 1px solid #d4e0ee; border-radius: 6px; padding: 14px; margin-bottom: 14px; }
.wv-builder .wv-fields-pane h3 { margin: 0 0 6px 0; font-size: 16px; }
.wv-builder .wv-hint { font-size: 12px; color: #666; margin: 0 0 10px 0; }
.wv-builder .wv-dem-grid { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 8px 12px; margin-bottom: 10px; }
.wv-builder .wv-dem-grid label { font-weight: normal; font-size: 13px; }
.wv-builder .wv-max-minors-field input[type=number] { width: 80px; }
.wv-builder .wv-cfe { display: flex; flex-direction: column; gap: 6px; margin-bottom: 8px; }
.wv-builder .wv-cfe-row { display: grid; grid-template-columns: auto 1fr 140px auto auto auto; gap: 6px; align-items: center; padding: 6px; background: #fff; border: 1px solid #ddd; border-radius: 4px; }
.wv-builder .wv-cfe-row .wv-cfe-grab { cursor: grab; color: #888; font-size: 18px; padding: 0 4px; user-select: none; }
.wv-builder .wv-cfe-row input[type=text] { padding: 4px 6px; border: 1px solid #ccc; border-radius: 3px; }
.wv-builder .wv-cfe-row select { padding: 4px; }
.wv-builder .wv-cfe-row .wv-cfe-del { color: #a00; cursor: pointer; background: none; border: none; font-size: 18px; }
.wv-builder .wv-cfe-options { grid-column: 2 / -1; padding-left: 26px; display: none; }
.wv-builder .wv-cfe-options textarea { width: 100%; min-height: 48px; font-family: monospace; font-size: 12px; border: 1px solid #ddd; border-radius: 3px; padding: 4px; }
.wv-builder .wv-cfe-row.wv-cfe-has-opts .wv-cfe-options { display: block; }
.wv-builder .wv-cfe-add { margin-top: 4px; padding: 6px 10px; cursor: pointer; }
</style>"""
assert old in t, 'builder style close anchor not found'
p.write_text(t.replace(old, new, 1))
print('builder styles extended')
PY
```

- [ ] **Step 3: Add the custom-fields editor JS + submit hook**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Waiver_builder.tpl')
t = p.read_text()
old = """\t\t\t\trenderPreview(form);
\t\t\t\tform.addEventListener('submit', async (e) => {"""
new = """\t\t\t\trenderPreview(form);

\t\t\t\t// --- Custom Fields Editor ---
\t\t\t\tconst cfe = form.querySelector('.wv-cfe');
\t\t\t\tconst cfeHidden = form.querySelector('input[name=\"CustomFieldsJson\"]');
\t\t\t\tconst cfeAddBtn = form.querySelector('.wv-cfe-add');
\t\t\t\tconst typeOpts = ['text','textarea','checkbox','initial','radio','select','date'];
\t\t\t\tfunction slugify(s) {
\t\t\t\t\treturn (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 32) || ('f_' + Math.random().toString(36).slice(2, 8));
\t\t\t\t}
\t\t\t\tfunction uniqueId(existing, proposed) {
\t\t\t\t\tlet id = proposed; let i = 1;
\t\t\t\t\twhile (existing.has(id)) { id = (proposed + '_' + (++i)).slice(0, 32); }
\t\t\t\t\treturn id;
\t\t\t\t}
\t\t\t\tfunction collectState() {
\t\t\t\t\tconst rows = [...cfe.querySelectorAll('.wv-cfe-row')];
\t\t\t\t\treturn rows.map(r => {
\t\t\t\t\t\tconst type = r.querySelector('select[name^=cfe_type]').value;
\t\t\t\t\t\tconst entry = {
\t\t\t\t\t\t\tid:       r.querySelector('input[name^=cfe_id]').value.trim(),
\t\t\t\t\t\t\tlabel:    r.querySelector('input[name^=cfe_label]').value.trim(),
\t\t\t\t\t\t\ttype:     type,
\t\t\t\t\t\t\trequired: r.querySelector('input[name^=cfe_req]').checked,
\t\t\t\t\t\t};
\t\t\t\t\t\tif (type === 'radio' || type === 'select') {
\t\t\t\t\t\t\tconst raw = r.querySelector('textarea[name^=cfe_opts]').value;
\t\t\t\t\t\t\tentry.options = raw.split(/[\\n,]+/).map(s => s.trim()).filter(Boolean);
\t\t\t\t\t\t}
\t\t\t\t\t\treturn entry;
\t\t\t\t\t}).filter(e => e.label);
\t\t\t\t}
\t\t\t\tfunction syncHidden() {
\t\t\t\t\tcfeHidden.value = JSON.stringify(collectState());
\t\t\t\t}
\t\t\t\tfunction makeRow(entry) {
\t\t\t\t\tconst row = document.createElement('div');
\t\t\t\t\trow.className = 'wv-cfe-row';
\t\t\t\t\tconst hasOpts = (entry.type === 'radio' || entry.type === 'select');
\t\t\t\t\tif (hasOpts) row.classList.add('wv-cfe-has-opts');
\t\t\t\t\trow.innerHTML = ''
\t\t\t\t\t\t+ '<span class=\"wv-cfe-grab\" title=\"Drag to reorder\">⋮⋮</span>'
\t\t\t\t\t\t+ '<input type=\"text\" name=\"cfe_label\" placeholder=\"Field label\" value=\"' + (entry.label || '').replace(/\"/g,'&quot;') + '\">'
\t\t\t\t\t\t+ '<select name=\"cfe_type\">' + typeOpts.map(tp => '<option value=\"' + tp + '\"' + (tp === entry.type ? ' selected' : '') + '>' + tp + '</option>').join('') + '</select>'
\t\t\t\t\t\t+ '<label><input type=\"checkbox\" name=\"cfe_req\"' + (entry.required ? ' checked' : '') + '> req</label>'
\t\t\t\t\t\t+ '<input type=\"text\" name=\"cfe_id\" placeholder=\"id\" value=\"' + (entry.id || '').replace(/\"/g,'&quot;') + '\" size=\"10\">'
\t\t\t\t\t\t+ '<button type=\"button\" class=\"wv-cfe-del\" title=\"Delete\">×</button>'
\t\t\t\t\t\t+ '<div class=\"wv-cfe-options\"><textarea name=\"cfe_opts\" placeholder=\"One option per line or comma-separated\">' + ((entry.options || []).join('\\n')) + '</textarea></div>';
\t\t\t\t\trow.querySelector('.wv-cfe-del').addEventListener('click', () => { row.remove(); syncHidden(); });
\t\t\t\t\trow.querySelector('select[name=cfe_type]').addEventListener('change', (ev) => {
\t\t\t\t\t\tconst t2 = ev.target.value;
\t\t\t\t\t\trow.classList.toggle('wv-cfe-has-opts', (t2 === 'radio' || t2 === 'select'));
\t\t\t\t\t\tsyncHidden();
\t\t\t\t\t});
\t\t\t\t\trow.querySelector('input[name=cfe_label]').addEventListener('blur', (ev) => {
\t\t\t\t\t\tconst idInput = row.querySelector('input[name=cfe_id]');
\t\t\t\t\t\tif (!idInput.value) {
\t\t\t\t\t\t\tconst used = new Set([...cfe.querySelectorAll('input[name=cfe_id]')].map(i => i.value).filter(Boolean));
\t\t\t\t\t\t\tidInput.value = uniqueId(used, slugify(ev.target.value));
\t\t\t\t\t\t\tsyncHidden();
\t\t\t\t\t\t}
\t\t\t\t\t});
\t\t\t\t\trow.addEventListener('input', syncHidden);
\t\t\t\t\trow.addEventListener('change', syncHidden);
\t\t\t\t\t// Drag to reorder: HTML5 DnD
\t\t\t\t\trow.setAttribute('draggable', 'true');
\t\t\t\t\trow.addEventListener('dragstart', (ev) => { row.dataset.dragging = '1'; ev.dataTransfer.effectAllowed = 'move'; });
\t\t\t\t\trow.addEventListener('dragend',   () => { delete row.dataset.dragging; syncHidden(); });
\t\t\t\t\trow.addEventListener('dragover',  (ev) => {
\t\t\t\t\t\tev.preventDefault();
\t\t\t\t\t\tconst dragging = cfe.querySelector('.wv-cfe-row[data-dragging=\"1\"]');
\t\t\t\t\t\tif (dragging && dragging !== row) {
\t\t\t\t\t\t\tconst rect = row.getBoundingClientRect();
\t\t\t\t\t\t\tcfe.insertBefore(dragging, (ev.clientY - rect.top < rect.height / 2) ? row : row.nextSibling);
\t\t\t\t\t\t}
\t\t\t\t\t});
\t\t\t\t\treturn row;
\t\t\t\t}
\t\t\t\ttry {
\t\t\t\t\tconst initial = JSON.parse(cfeHidden.value || '[]');
\t\t\t\t\tif (Array.isArray(initial)) initial.forEach(entry => cfe.appendChild(makeRow(entry)));
\t\t\t\t} catch (e) { /* bad seed, start empty */ }
\t\t\t\tcfeAddBtn.addEventListener('click', () => {
\t\t\t\t\tcfe.appendChild(makeRow({ type: 'text', label: '', required: false }));
\t\t\t\t\tsyncHidden();
\t\t\t\t});
\t\t\t\tsyncHidden();

\t\t\t\tform.addEventListener('submit', async (e) => {"""
assert old in t, 'builder submit anchor not found'
p.write_text(t.replace(old, new, 1))
print('builder CFE JS added')
PY
```

- [ ] **Step 4: Sync hidden before submit (so last-typed value is captured)**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Waiver_builder.tpl')
t = p.read_text()
old = """\t\t\t\tform.addEventListener('submit', async (e) => {
\t\t\t\t\te.preventDefault();
\t\t\t\t\tconst fd = new FormData(form);"""
new = """\t\t\t\tform.addEventListener('submit', async (e) => {
\t\t\t\t\te.preventDefault();
\t\t\t\t\tsyncHidden();
\t\t\t\t\tconst fd = new FormData(form);"""
assert old in t, 'submit handler anchor not found'
p.write_text(t.replace(old, new, 1))
print('builder submit syncs hidden')
PY
```

- [ ] **Step 5: Smoke-test**

Load `http://localhost:19080/orkui/Waiver/builder/1` in a browser. Confirm: the Fields & Demographics pane appears above the markdown grid. Toggle a few checkboxes, add two custom fields (one text, one radio with options), save, reload the page — values persist. Open browser console — no uncaught errors.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/Waiver_builder.tpl
git commit -m "Enhancement: Digital Waivers — builder Fields & Demographics pane"
```

---

## Phase 2 — Sign-page UX

### Task 2.1: Waiver_sign.tpl — demographics, emergency contact, witness, minors repeater, custom-fields renderer

**Files:**
- Modify: `orkui/template/revised-frontend/Waiver_sign.tpl`
- Modify: `orkui/controller/controller.Waiver.php::sign` (prefill demographics from player)

- [ ] **Step 1: Controller — extend prefill with demographic data**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Waiver.php')
t = p.read_text()
old = """\t\t\t'prefill'    => [
\t\t\t\t'MundaneFirst' => $player['GivenName']   ?? '',
\t\t\t\t'MundaneLast'  => $player['Surname']     ?? '',
\t\t\t\t'PersonaName'  => $player['Persona']     ?? '',
\t\t\t\t'ParkId'       => (int)($player['ParkId']    ?? 0),
\t\t\t\t'KingdomId'    => (int)($player['KingdomId'] ?? 0),
\t\t\t],"""
new = """\t\t\t'prefill'    => [
\t\t\t\t'MundaneFirst' => $player['GivenName']   ?? '',
\t\t\t\t'MundaneLast'  => $player['Surname']     ?? '',
\t\t\t\t'PersonaName'  => $player['Persona']     ?? '',
\t\t\t\t'ParkId'       => (int)($player['ParkId']    ?? 0),
\t\t\t\t'KingdomId'    => (int)($player['KingdomId'] ?? 0),
\t\t\t\t'Address'      => trim(($player['Address']     ?? '') . ' ' . ($player['Address2'] ?? '')),
\t\t\t\t'Phone'        => $player['Phone']       ?? '',
\t\t\t\t'Email'        => $player['Email']       ?? '',
\t\t\t\t'Dob'          => $player['DateOfBirth'] ?? '',
\t\t\t],"""
assert old in t, 'controller prefill anchor not found'
p.write_text(t.replace(old, new, 1))
print('controller Waiver::sign prefill extended')
PY
```

- [ ] **Step 2: Template — extend sign form with conditional blocks**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Waiver_sign.tpl')
t = p.read_text()
old = """\t\t\t<div class=\"wv-section\">
\t\t\t\t<h2>Your Information</h2>
\t\t\t\t<div class=\"wv-playerhdr\">
\t\t\t\t\t<div><label>First (legal) name</label><input type=\"text\" name=\"MundaneFirst\" required value=\"<?= htmlspecialchars($prefill['MundaneFirst']) ?>\"></div>
\t\t\t\t\t<div><label>Last (legal) name</label><input type=\"text\" name=\"MundaneLast\" required value=\"<?= htmlspecialchars($prefill['MundaneLast']) ?>\"></div>
\t\t\t\t\t<div><label>Persona name</label><input type=\"text\" name=\"PersonaName\" value=\"<?= htmlspecialchars($prefill['PersonaName']) ?>\"></div>
\t\t\t\t\t<div><label>Home park / kingdom</label><input type=\"text\" value=\"(auto-captured from your profile)\" disabled></div>
\t\t\t\t</div>
\t\t\t</div>"""
new = """\t\t\t<div class=\"wv-section\">
\t\t\t\t<h2>Your Information</h2>
\t\t\t\t<div class=\"wv-playerhdr\">
\t\t\t\t\t<div><label>First (legal) name</label><input type=\"text\" name=\"MundaneFirst\" required value=\"<?= htmlspecialchars($prefill['MundaneFirst']) ?>\"></div>
\t\t\t\t\t<div><label>Last (legal) name</label><input type=\"text\" name=\"MundaneLast\" required value=\"<?= htmlspecialchars($prefill['MundaneLast']) ?>\"></div>
\t\t\t\t\t<div><label>Persona name</label><input type=\"text\" name=\"PersonaName\" value=\"<?= htmlspecialchars($prefill['PersonaName']) ?>\"></div>
\t\t\t\t\t<?php if (!empty($tpl['RequiresPreferredName'])): ?>
\t\t\t\t\t<div><label>Preferred name</label><input type=\"text\" name=\"PreferredName\" required></div>
\t\t\t\t\t<?php endif; ?>
\t\t\t\t\t<?php if (!empty($tpl['RequiresGender'])): ?>
\t\t\t\t\t<div><label>Gender</label><input type=\"text\" name=\"Gender\" required></div>
\t\t\t\t\t<?php endif; ?>
\t\t\t\t\t<?php if (!empty($tpl['RequiresDob'])): ?>
\t\t\t\t\t<div><label>Date of birth</label><input type=\"date\" name=\"Dob\" required value=\"<?= htmlspecialchars($prefill['Dob'] ?? '') ?>\"></div>
\t\t\t\t\t<?php endif; ?>
\t\t\t\t\t<?php if (!empty($tpl['RequiresAddress'])): ?>
\t\t\t\t\t<div style=\"grid-column: span 2;\"><label>Address</label><input type=\"text\" name=\"Address\" required value=\"<?= htmlspecialchars($prefill['Address'] ?? '') ?>\"></div>
\t\t\t\t\t<?php endif; ?>
\t\t\t\t\t<?php if (!empty($tpl['RequiresPhone'])): ?>
\t\t\t\t\t<div><label>Phone</label><input type=\"text\" name=\"Phone\" required value=\"<?= htmlspecialchars($prefill['Phone'] ?? '') ?>\"></div>
\t\t\t\t\t<?php endif; ?>
\t\t\t\t\t<?php if (!empty($tpl['RequiresEmail'])): ?>
\t\t\t\t\t<div><label>Email</label><input type=\"email\" name=\"Email\" required value=\"<?= htmlspecialchars($prefill['Email'] ?? '') ?>\"></div>
\t\t\t\t\t<?php endif; ?>
\t\t\t\t\t<div><label>Home park / kingdom</label><input type=\"text\" value=\"(auto-captured from your profile)\" disabled></div>
\t\t\t\t</div>
\t\t\t</div>

\t\t\t<?php if (!empty($tpl['RequiresEmergencyContact'])): ?>
\t\t\t<div class=\"wv-section\">
\t\t\t\t<h2>Emergency Contact</h2>
\t\t\t\t<div class=\"wv-playerhdr\">
\t\t\t\t\t<div><label>Contact name</label><input type=\"text\" name=\"EmergencyContactName\" required></div>
\t\t\t\t\t<div><label>Relationship</label><input type=\"text\" name=\"EmergencyContactRelationship\" required placeholder=\"e.g. spouse, parent\"></div>
\t\t\t\t\t<div><label>Phone</label><input type=\"text\" name=\"EmergencyContactPhone\" required></div>
\t\t\t\t</div>
\t\t\t</div>
\t\t\t<?php endif; ?>"""
assert old in t, 'sign Your Information anchor not found'
p.write_text(t.replace(old, new, 1))
print('sign template demographics + emergency contact added')
PY
```

- [ ] **Step 3: Insert custom-fields renderer between body and minor toggle**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Waiver_sign.tpl')
t = p.read_text()
old = """\t\t\t<div class=\"wv-section wv-body-md\"><?= $md($tpl['BodyMarkdown']) ?></div>

\t\t\t<div class=\"wv-section wv-minor-toggle\">"""
new = """\t\t\t<div class=\"wv-section wv-body-md\"><?= $md($tpl['BodyMarkdown']) ?></div>

\t\t\t<?php
\t\t\t$customFields = [];
\t\t\ttry { $customFields = json_decode($tpl['CustomFieldsJson'] ?? '[]', true) ?: []; } catch (Throwable $e) { $customFields = []; }
\t\t\tif (is_array($customFields) && count($customFields) > 0):
\t\t\t?>
\t\t\t<div class=\"wv-section wv-custom-fields\">
\t\t\t\t<h2>Acknowledgements &amp; Additional Information</h2>
\t\t\t\t<?php foreach ($customFields as $f):
\t\t\t\t\t$fid   = preg_replace('/[^a-z0-9_]/', '', (string)($f['id'] ?? ''));
\t\t\t\t\tif ($fid === '') continue;
\t\t\t\t\t$type  = (string)($f['type'] ?? 'text');
\t\t\t\t\t$label = (string)($f['label'] ?? '');
\t\t\t\t\t$req   = !empty($f['required']);
\t\t\t\t\t$name  = 'cf_' . $fid;
\t\t\t\t\t$opts  = is_array($f['options'] ?? null) ? $f['options'] : [];
\t\t\t\t?>
\t\t\t\t<div class=\"wv-cf-row\" data-cf-id=\"<?= htmlspecialchars($fid) ?>\" data-cf-type=\"<?= htmlspecialchars($type) ?>\" data-cf-req=\"<?= $req ? '1' : '0' ?>\">
\t\t\t\t\t<?php if ($type === 'checkbox'): ?>
\t\t\t\t\t\t<label><input type=\"checkbox\" name=\"<?= htmlspecialchars($name) ?>\" value=\"1\" <?= $req ? 'data-wv-required=\"1\"' : '' ?>> <?= htmlspecialchars($label) ?></label>
\t\t\t\t\t<?php elseif ($type === 'initial'): ?>
\t\t\t\t\t\t<label><?= htmlspecialchars($label) ?></label>
\t\t\t\t\t\t<input type=\"text\" name=\"<?= htmlspecialchars($name) ?>\" maxlength=\"3\" style=\"width:60px; text-transform:uppercase;\" <?= $req ? 'data-wv-required=\"1\"' : '' ?>>
\t\t\t\t\t<?php elseif ($type === 'radio'): ?>
\t\t\t\t\t\t<label><?= htmlspecialchars($label) ?></label>
\t\t\t\t\t\t<div class=\"wv-cf-radio\">
\t\t\t\t\t\t\t<?php foreach ($opts as $i => $o): ?>
\t\t\t\t\t\t\t\t<label><input type=\"radio\" name=\"<?= htmlspecialchars($name) ?>\" value=\"<?= htmlspecialchars((string)$o) ?>\" <?= $req ? 'data-wv-required=\"1\"' : '' ?>> <?= htmlspecialchars((string)$o) ?></label>
\t\t\t\t\t\t\t<?php endforeach; ?>
\t\t\t\t\t\t</div>
\t\t\t\t\t<?php elseif ($type === 'select'): ?>
\t\t\t\t\t\t<label><?= htmlspecialchars($label) ?></label>
\t\t\t\t\t\t<select name=\"<?= htmlspecialchars($name) ?>\" <?= $req ? 'data-wv-required=\"1\"' : '' ?>>
\t\t\t\t\t\t\t<option value=\"\">— select —</option>
\t\t\t\t\t\t\t<?php foreach ($opts as $o): ?>
\t\t\t\t\t\t\t\t<option value=\"<?= htmlspecialchars((string)$o) ?>\"><?= htmlspecialchars((string)$o) ?></option>
\t\t\t\t\t\t\t<?php endforeach; ?>
\t\t\t\t\t\t</select>
\t\t\t\t\t<?php elseif ($type === 'textarea'): ?>
\t\t\t\t\t\t<label><?= htmlspecialchars($label) ?></label>
\t\t\t\t\t\t<textarea name=\"<?= htmlspecialchars($name) ?>\" rows=\"3\" <?= $req ? 'data-wv-required=\"1\"' : '' ?>></textarea>
\t\t\t\t\t<?php elseif ($type === 'date'): ?>
\t\t\t\t\t\t<label><?= htmlspecialchars($label) ?></label>
\t\t\t\t\t\t<input type=\"date\" name=\"<?= htmlspecialchars($name) ?>\" <?= $req ? 'data-wv-required=\"1\"' : '' ?>>
\t\t\t\t\t<?php else: ?>
\t\t\t\t\t\t<label><?= htmlspecialchars($label) ?></label>
\t\t\t\t\t\t<input type=\"text\" name=\"<?= htmlspecialchars($name) ?>\" <?= $req ? 'data-wv-required=\"1\"' : '' ?>>
\t\t\t\t\t<?php endif; ?>
\t\t\t\t</div>
\t\t\t\t<?php endforeach; ?>
\t\t\t</div>
\t\t\t<?php endif; ?>

\t\t\t<div class=\"wv-section wv-minor-toggle\">"""
assert old in t, 'sign body/minor anchor not found'
p.write_text(t.replace(old, new, 1))
print('sign custom-fields renderer inserted')
PY
```

- [ ] **Step 4: Replace the single minor block with repeater supporting max_minors**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Waiver_sign.tpl')
t = p.read_text()
old = """\t\t\t<div class=\"wv-section\" id=\"wvMinorBlock\" style=\"display:none;\">
\t\t\t\t<div class=\"wv-minor-md\"><?= $md($tpl['MinorMarkdown']) ?></div>
\t\t\t\t<div class=\"wv-playerhdr\" style=\"margin-top:10px;\">
\t\t\t\t\t<div><label>Representative first name</label><input type=\"text\" name=\"MinorRepFirst\"></div>
\t\t\t\t\t<div><label>Representative last name</label> <input type=\"text\" name=\"MinorRepLast\"></div>
\t\t\t\t\t<div style=\"grid-column: span 2;\"><label>Relationship to minor</label><input type=\"text\" name=\"MinorRepRelationship\" placeholder=\"e.g. mother, legal guardian\"></div>
\t\t\t\t</div>
\t\t\t</div>"""
new = """\t\t\t<div class=\"wv-section\" id=\"wvMinorBlock\" style=\"display:none;\">
\t\t\t\t<div class=\"wv-minor-md\"><?= $md($tpl['MinorMarkdown']) ?></div>
\t\t\t\t<h3 style=\"margin-top: 10px;\">Guardian / Representative</h3>
\t\t\t\t<div class=\"wv-playerhdr\">
\t\t\t\t\t<div><label>Representative first name</label><input type=\"text\" name=\"MinorRepFirst\"></div>
\t\t\t\t\t<div><label>Representative last name</label> <input type=\"text\" name=\"MinorRepLast\"></div>
\t\t\t\t\t<div style=\"grid-column: span 2;\"><label>Relationship to minor</label><input type=\"text\" name=\"MinorRepRelationship\" placeholder=\"e.g. mother, legal guardian\"></div>
\t\t\t\t</div>
\t\t\t\t<?php $maxMinors = max(1, (int)($tpl['MaxMinors'] ?? 1)); ?>
\t\t\t\t<h3 style=\"margin-top: 14px;\">Minors Covered</h3>
\t\t\t\t<p class=\"wv-hint\" style=\"font-size:12px; color:#666;\">Enter the minor(s) this waiver covers. You may list up to <?= $maxMinors ?>.</p>
\t\t\t\t<div id=\"wvMinorsList\" data-max=\"<?= $maxMinors ?>\"></div>
\t\t\t\t<?php if ($maxMinors > 1): ?>
\t\t\t\t<button type=\"button\" id=\"wvMinorsAdd\">+ Add minor</button>
\t\t\t\t<?php endif; ?>
\t\t\t</div>

\t\t\t<?php if (!empty($tpl['RequiresWitness'])): ?>
\t\t\t<div class=\"wv-section\">
\t\t\t\t<h2>Witness</h2>
\t\t\t\t<div class=\"wv-playerhdr\">
\t\t\t\t\t<div style=\"grid-column: span 2;\"><label>Witness printed name</label><input type=\"text\" name=\"WitnessPrintedName\" required></div>
\t\t\t\t</div>
\t\t\t\t<?php wv_render_signature_widget('wvSigWitness', 'witness', 'Type witness full legal name'); ?>
\t\t\t</div>
\t\t\t<?php endif; ?>"""
assert old in t, 'sign minor block anchor not found'
p.write_text(t.replace(old, new, 1))
print('sign minor repeater + witness block added')
PY
```

- [ ] **Step 5: JS — minors repeater + witness signature capture + custom-field collection + submission**

Replace the final `<script>` IIFE at the bottom. Use Python:

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Waiver_sign.tpl')
t = p.read_text()
old = """<script>
(function(){
\tconst form = document.getElementById('wvSignForm');
\tif (!form) return;
\tconst isMinor = document.getElementById('wvIsMinor');
\tconst minorBlock = document.getElementById('wvMinorBlock');
\tisMinor.addEventListener('change', () => minorBlock.style.display = isMinor.checked ? '' : 'none');

\tform.addEventListener('submit', async (e) => {
\t\te.preventDefault();
\t\tconst status = document.getElementById('wvSubmitStatus');
\t\tconst fd = new FormData(form);
\t\tfd.set('SignatureType', form.querySelector('.wv-sig-type').value);
\t\tfd.set('SignatureData', form.querySelector('.wv-sig-data').value);
\t\tif (!fd.get('SignatureData')) { status.className = 'wv-status-err'; status.textContent = 'Please sign before submitting.'; return; }
\t\tstatus.className = ''; status.textContent = 'Submitting…';
\t\ttry {
\t\t\tconst r = await fetch('<?= UIR ?>WaiverAjax/submitSignature', { method: 'POST', body: fd, credentials: 'same-origin' });
\t\t\tconst j = await r.json();
\t\t\tif (j.status === 0) {
\t\t\t\twindow.location = '<?= UIR ?>Waiver/review/' + j.SignatureId;
\t\t\t} else {
\t\t\t\tstatus.className = 'wv-status-err';
\t\t\t\tstatus.textContent = j.error || 'Submit failed';
\t\t\t}
\t\t} catch (err) {
\t\t\tstatus.className = 'wv-status-err';
\t\t\tstatus.textContent = 'Network error';
\t\t}
\t});
})();
</script>"""
new = """<script>
(function(){
\tconst form = document.getElementById('wvSignForm');
\tif (!form) return;
\tconst isMinor = document.getElementById('wvIsMinor');
\tconst minorBlock = document.getElementById('wvMinorBlock');
\tisMinor.addEventListener('change', () => minorBlock.style.display = isMinor.checked ? '' : 'none');

\t// Minors repeater
\tconst minorsList = document.getElementById('wvMinorsList');
\tconst maxMinors  = minorsList ? parseInt(minorsList.dataset.max || '1', 10) : 1;
\tfunction makeMinorRow(idx) {
\t\tconst d = document.createElement('div');
\t\td.className = 'wv-minor-row wv-playerhdr';
\t\td.style.cssText = 'border:1px solid #eee; padding:8px; border-radius:4px; margin-bottom:8px;';
\t\td.innerHTML =
\t\t\t'<div><label>Legal first</label><input type=\"text\" class=\"wv-minor-field\" data-k=\"LegalFirst\"></div>' +
\t\t\t'<div><label>Legal last</label><input type=\"text\"  class=\"wv-minor-field\" data-k=\"LegalLast\"></div>' +
\t\t\t'<div><label>Preferred name</label><input type=\"text\" class=\"wv-minor-field\" data-k=\"PreferredName\"></div>' +
\t\t\t'<div><label>Persona name</label><input type=\"text\"   class=\"wv-minor-field\" data-k=\"PersonaName\"></div>' +
\t\t\t'<div><label>Date of birth</label><input type=\"date\"  class=\"wv-minor-field\" data-k=\"Dob\"></div>' +
\t\t\t(idx > 0 ? '<div style=\"align-self:center;\"><button type=\"button\" class=\"wv-minor-del\">Remove</button></div>' : '');
\t\tconst del = d.querySelector('.wv-minor-del');
\t\tif (del) del.addEventListener('click', () => d.remove());
\t\treturn d;
\t}
\tif (minorsList) minorsList.appendChild(makeMinorRow(0));
\tconst addBtn = document.getElementById('wvMinorsAdd');
\tif (addBtn) addBtn.addEventListener('click', () => {
\t\tconst count = minorsList.querySelectorAll('.wv-minor-row').length;
\t\tif (count >= maxMinors) return;
\t\tminorsList.appendChild(makeMinorRow(count));
\t});

\tfunction collectMinors() {
\t\tif (!minorsList) return [];
\t\treturn [...minorsList.querySelectorAll('.wv-minor-row')].map(row => {
\t\t\tconst o = {};
\t\t\trow.querySelectorAll('.wv-minor-field').forEach(i => { o[i.dataset.k] = i.value; });
\t\t\treturn o;
\t\t}).filter(o => (o.LegalFirst || o.LegalLast || o.PersonaName));
\t}

\tfunction collectCustom() {
\t\tconst out = {};
\t\tform.querySelectorAll('.wv-cf-row').forEach(row => {
\t\t\tconst id = row.dataset.cfId;
\t\t\tconst type = row.dataset.cfType;
\t\t\tif (type === 'checkbox') {
\t\t\t\tconst el = row.querySelector('input[type=checkbox]');
\t\t\t\tout[id] = !!(el && el.checked);
\t\t\t} else if (type === 'radio') {
\t\t\t\tconst el = row.querySelector('input[type=radio]:checked');
\t\t\t\tout[id] = el ? el.value : '';
\t\t\t} else {
\t\t\t\tconst el = row.querySelector('input, select, textarea');
\t\t\t\tout[id] = el ? el.value : '';
\t\t\t}
\t\t});
\t\treturn out;
\t}

\tfunction firstMissingRequired() {
\t\tconst els = form.querySelectorAll('[data-wv-required=\"1\"]');
\t\tfor (const el of els) {
\t\t\tif (el.type === 'checkbox' && !el.checked) return el;
\t\t\tif (el.type === 'radio') {
\t\t\t\tconst grp = form.querySelectorAll('input[type=radio][name=\"' + el.name + '\"]');
\t\t\t\tif (![...grp].some(g => g.checked)) return el;
\t\t\t\tcontinue;
\t\t\t}
\t\t\tif (el.tagName === 'SELECT' || el.type === 'text' || el.type === 'textarea' || el.type === 'date' || el.type === 'email') {
\t\t\t\tif (!(el.value || '').trim()) return el;
\t\t\t}
\t\t}
\t\treturn null;
\t}

\tform.addEventListener('submit', async (e) => {
\t\te.preventDefault();
\t\tconst status = document.getElementById('wvSubmitStatus');
\t\tconst missing = firstMissingRequired();
\t\tif (missing) {
\t\t\tstatus.className = 'wv-status-err';
\t\t\tconst label = (missing.closest('label') && missing.closest('label').textContent.trim()) || missing.name || 'a required field';
\t\t\tstatus.textContent = 'Please complete: ' + label;
\t\t\tmissing.focus();
\t\t\treturn;
\t\t}

\t\tconst fd = new FormData(form);
\t\tconst mainSig = form.querySelector('#wvSigMain');
\t\tfd.set('SignatureType', mainSig.querySelector('.wv-sig-type').value);
\t\tfd.set('SignatureData', mainSig.querySelector('.wv-sig-data').value);
\t\tif (!fd.get('SignatureData')) { status.className = 'wv-status-err'; status.textContent = 'Please sign before submitting.'; return; }

\t\tconst witSig = form.querySelector('#wvSigWitness');
\t\tif (witSig) {
\t\t\tfd.set('WitnessSignatureType', witSig.querySelector('.wv-sig-type').value);
\t\t\tfd.set('WitnessSignatureData', witSig.querySelector('.wv-sig-data').value);
\t\t}

\t\tconst minors = isMinor.checked ? collectMinors() : [];
\t\tfd.set('Minors', JSON.stringify(minors));
\t\tminors.forEach((m, i) => {
\t\t\tObject.keys(m).forEach(k => fd.append('Minors[' + i + '][' + k + ']', m[k] || ''));
\t\t});
\t\tfd.set('CustomResponsesJson', JSON.stringify(collectCustom()));

\t\tstatus.className = ''; status.textContent = 'Submitting…';
\t\ttry {
\t\t\tconst r = await fetch('<?= UIR ?>WaiverAjax/submitSignature', { method: 'POST', body: fd, credentials: 'same-origin' });
\t\t\tconst j = await r.json();
\t\t\tif (j.status === 0) {
\t\t\t\twindow.location = '<?= UIR ?>Waiver/review/' + j.SignatureId;
\t\t\t} else {
\t\t\t\tstatus.className = 'wv-status-err';
\t\t\t\tstatus.textContent = j.error || 'Submit failed';
\t\t\t}
\t\t} catch (err) {
\t\t\tstatus.className = 'wv-status-err';
\t\t\tstatus.textContent = 'Network error';
\t\t}
\t});
})();
</script>"""
assert old in t, 'sign bottom script anchor not found'
p.write_text(t.replace(old, new, 1))
print('sign submission JS rewritten')
PY
```

- [ ] **Step 6: Smoke-test**

Build (via `docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT 1"` to confirm DB up), load `http://localhost:19080/orkui/Waiver/sign/kingdom/1`. Confirm template with demographics/emergency/witness/custom fields renders correctly; toggle "signing for a minor" and confirm the minors repeater appears with up to `MaxMinors` rows; missing-required submit shows a red error strip; full valid submit redirects to `/Waiver/review/{id}`.

- [ ] **Step 7: Commit**

```bash
git add orkui/controller/controller.Waiver.php orkui/template/revised-frontend/Waiver_sign.tpl
git commit -m "Enhancement: Digital Waivers — sign page demographics, emergency, witness, minors repeater, custom fields"
```

---

## Phase 3 — Review + Print views

### Task 3.1: Waiver_review.tpl — render demographics / emergency / custom responses / minors / witness

**Files:**
- Modify: `orkui/template/revised-frontend/Waiver_review.tpl`

- [ ] **Step 1: Read the current review template**

Run `sed -n '1,200p' orkui/template/revised-frontend/Waiver_review.tpl` to inspect the current structure. (Use Read tool, not `sed`, in the actual run.)

- [ ] **Step 2: Insert new read-only blocks**

Insert a new Demographics card between the player-header render and the body-markdown render. Insert an Emergency Contact card, a Custom Responses card (iterating `Template['CustomFieldsJson']` against `Signature['CustomResponsesJson']`), a Minors card (iterating `Signature['Minors']`), and a Witness card (when `Template['RequiresWitness']`). Use the **same `wv-section` styling** as the sign page, with `display: grid; grid-template-columns: 1fr 1fr;` for label/value pairs.

Reference block (insert through Python `pathlib` replace, anchored on the existing body-render block):

```php
<?php $sig = $this->data['_wv']['signature']; $tpl = $sig['Template'] ?? []; ?>

<?php if (!empty($tpl['RequiresPreferredName']) || !empty($tpl['RequiresDob']) || !empty($tpl['RequiresGender']) || !empty($tpl['RequiresAddress']) || !empty($tpl['RequiresPhone']) || !empty($tpl['RequiresEmail'])): ?>
<div class="wv-section">
	<h2>Signer Demographics</h2>
	<dl class="wv-dl">
		<?php if (!empty($tpl['RequiresPreferredName'])): ?><dt>Preferred name</dt><dd><?= htmlspecialchars($sig['PreferredName'] ?? '') ?></dd><?php endif; ?>
		<?php if (!empty($tpl['RequiresDob'])):           ?><dt>Date of birth</dt><dd><?= htmlspecialchars($sig['Dob'] ?? '') ?></dd><?php endif; ?>
		<?php if (!empty($tpl['RequiresGender'])):        ?><dt>Gender</dt><dd><?= htmlspecialchars($sig['Gender'] ?? '') ?></dd><?php endif; ?>
		<?php if (!empty($tpl['RequiresAddress'])):       ?><dt>Address</dt><dd><?= htmlspecialchars($sig['Address'] ?? '') ?></dd><?php endif; ?>
		<?php if (!empty($tpl['RequiresPhone'])):         ?><dt>Phone</dt><dd><?= htmlspecialchars($sig['Phone'] ?? '') ?></dd><?php endif; ?>
		<?php if (!empty($tpl['RequiresEmail'])):         ?><dt>Email</dt><dd><?= htmlspecialchars($sig['Email'] ?? '') ?></dd><?php endif; ?>
	</dl>
</div>
<?php endif; ?>

<?php if (!empty($tpl['RequiresEmergencyContact'])): ?>
<div class="wv-section">
	<h2>Emergency Contact</h2>
	<dl class="wv-dl">
		<dt>Name</dt><dd><?= htmlspecialchars($sig['EmergencyContactName'] ?? '') ?></dd>
		<dt>Relationship</dt><dd><?= htmlspecialchars($sig['EmergencyContactRelationship'] ?? '') ?></dd>
		<dt>Phone</dt><dd><?= htmlspecialchars($sig['EmergencyContactPhone'] ?? '') ?></dd>
	</dl>
</div>
<?php endif; ?>

<?php
$cfTpl = json_decode($tpl['CustomFieldsJson'] ?? '[]', true) ?: [];
$cfResp = json_decode($sig['CustomResponsesJson'] ?? '{}', true) ?: [];
if (count($cfTpl) > 0): ?>
<div class="wv-section">
	<h2>Acknowledgements &amp; Additional Information</h2>
	<dl class="wv-dl">
		<?php foreach ($cfTpl as $f):
			$id = (string)($f['id'] ?? ''); $type = (string)($f['type'] ?? ''); $val = $cfResp[$id] ?? '';
			if ($type === 'checkbox') $val = !empty($val) ? 'Yes' : 'No';
			if (is_array($val)) $val = implode(', ', $val);
		?>
		<dt><?= htmlspecialchars((string)($f['label'] ?? $id)) ?></dt>
		<dd><?= htmlspecialchars((string)$val) ?></dd>
		<?php endforeach; ?>
	</dl>
</div>
<?php endif; ?>

<?php if (!empty($sig['IsMinor']) && !empty($sig['Minors'])): ?>
<div class="wv-section">
	<h2>Minors Covered</h2>
	<table class="wv-minors-tbl">
		<thead><tr><th>Legal first</th><th>Legal last</th><th>Preferred</th><th>Persona</th><th>DOB</th></tr></thead>
		<tbody>
		<?php foreach ($sig['Minors'] as $m): ?>
			<tr>
				<td><?= htmlspecialchars($m['LegalFirst'] ?? '') ?></td>
				<td><?= htmlspecialchars($m['LegalLast'] ?? '') ?></td>
				<td><?= htmlspecialchars($m['PreferredName'] ?? '') ?></td>
				<td><?= htmlspecialchars($m['PersonaName'] ?? '') ?></td>
				<td><?= htmlspecialchars($m['Dob'] ?? '') ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>

<?php if (!empty($tpl['RequiresWitness'])): ?>
<div class="wv-section">
	<h2>Witness</h2>
	<dl class="wv-dl">
		<dt>Name</dt><dd><?= htmlspecialchars($sig['WitnessPrintedName'] ?? '') ?></dd>
		<dt>Signature</dt>
		<dd>
			<?php if (($sig['WitnessSignatureType'] ?? '') === 'typed'): ?>
				<span style="font-family:'Homemade Apple', cursive; font-size: 22px;"><?= htmlspecialchars($sig['WitnessSignatureData'] ?? '') ?></span>
			<?php elseif (($sig['WitnessSignatureType'] ?? '') === 'drawn'): ?>
				<em>(drawn signature — see print view for canvas render)</em>
			<?php endif; ?>
		</dd>
	</dl>
</div>
<?php endif; ?>
```

Insert **before** the existing body-markdown render. Use anchored `old_string`/`new_string` Python replace.

- [ ] **Step 3: Add the `.wv-dl` + `.wv-minors-tbl` styles inside the existing `<style>` block**

```css
.wv-review .wv-dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; }
.wv-review .wv-dl dt { font-weight: bold; color: #555; }
.wv-review .wv-dl dd { margin: 0; }
.wv-review .wv-minors-tbl { width: 100%; border-collapse: collapse; }
.wv-review .wv-minors-tbl th, .wv-review .wv-minors-tbl td { border: 1px solid #ddd; padding: 4px 8px; text-align: left; }
.wv-review .wv-minors-tbl thead { background: #f4f4f4; }
```

(Scope prefix the agent reads from the existing template — if it is `.wv-rv` instead of `.wv-review`, match that.)

- [ ] **Step 4: Extend the Officer Verification form with ID-intake fields**

Insert before the verify/reject button row (use Read first to locate the exact markup):

```html
<div class="wv-section wv-officer-intake">
	<h3>ID Check &amp; Intake (optional)</h3>
	<div class="wv-playerhdr">
		<div>
			<label>ID type</label>
			<select name="IdType">
				<option value="">—</option>
				<option>Driver License</option>
				<option>Passport</option>
				<option>State ID</option>
				<option>Military ID</option>
				<option>Other</option>
			</select>
		</div>
		<div>
			<label>ID number (last 4 stored)</label>
			<input type="text" name="IdNumber" inputmode="numeric" maxlength="32">
		</div>
		<div>
			<label>Age bracket</label>
			<select name="AgeBracket">
				<option value="">—</option>
				<option value="18+">18+</option>
				<option value="14+">14+</option>
				<option value="under14">Under 14</option>
			</select>
		</div>
		<div>
			<label><input type="checkbox" name="ScannedPaper" value="1"> Paper copy scanned &amp; filed</label>
		</div>
	</div>
</div>
```

And in the submit JS handler, ensure these are included in the POSTed `FormData` (they will be automatically by `new FormData(form)` if the inputs are inside the same form).

- [ ] **Step 5: Smoke-test**

Load `http://localhost:19080/orkui/Waiver/review/{id}` for a signature created in Phase 2's smoke test. Confirm every inserted block renders; officer ID-intake form shows; a verify submit persists the intake fields (confirm via `docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT verifier_id_type, verifier_id_number_last4, verifier_age_bracket, verifier_scanned_paper FROM ork_waiver_signature WHERE waiver_signature_id = {id}"`).

- [ ] **Step 6: Commit**

```bash
git add orkui/template/revised-frontend/Waiver_review.tpl
git commit -m "Enhancement: Digital Waivers — review page demographics, emergency, custom, minors, witness, officer intake"
```

---

### Task 3.2: Waiver_print.tpl — mirror the review renders, minus officer form

**Files:**
- Modify: `orkui/template/revised-frontend/Waiver_print.tpl`

- [ ] **Step 1: Copy the demographic/emergency/custom/minor/witness blocks from review**

Same Python-pathlib pattern. The print template should have the new blocks BUT NOT the officer verification form. Use `page-break-inside: avoid` on each `.wv-section`.

Add CSS:
```css
@media print {
	.wv-print .wv-section { page-break-inside: avoid; }
}
```

- [ ] **Step 2: Smoke-test**

Visit `http://localhost:19080/orkui/Waiver/print/{id}`, choose browser Print Preview, confirm all new sections appear; no page-breaks split a card mid-row.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Waiver_print.tpl
git commit -m "Enhancement: Digital Waivers — print page mirrors review additions"
```

---

## Phase 4 — Final verification

### Task 4.1: Full test-suite run + manual QA checklist

**Files:** n/a (verification only)

- [ ] **Step 1: Run full PHP test suite**

```bash
docker exec -e ENVIRONMENT=DEV -w /var/www/ork.amtgard.com/tests/php ork3-php8-app php WaiverTest.php
```
Expected: all pre-amendment tests + A1-A5 PASS. Summary line shows `pass=N fail=0`.

- [ ] **Step 2: Manual QA checklist against the six real-world waivers**

Walk each of the following end-to-end (builder → sign → review → print):

- [ ] Amtgard general (CA/HI/NV): `RequiresPreferredName`, `MaxMinors=3`, custom radio "Joining/Transferring/Updating", custom initial "not sex offender"; officer form captures Age bracket 18+/14+/<14, ID type, ID last4, Scanned.
- [ ] Winter's Edge (TN): `RequiresDob`, `RequiresPhone`, `RequiresWitness`; officer form captures Form-of-ID (as ID type).
- [ ] Capitol Games (MD): base-only waiver — persona + kingdom/park + officer block; confirm nothing new is forced.
- [ ] Blackspire (OR): `RequiresDob`, `RequiresAddress`, `RequiresPhone`, `RequiresEmail`, `RequiresEmergencyContact`, `MaxMinors=4`; custom checkbox "Read Youth Policy".
- [ ] Emerald Hills (TX): `RequiresDob`, `RequiresAddress`, `RequiresPhone`, `RequiresEmail`, `RequiresEmergencyContact`.
- [ ] Northern Empire (ON): `RequiresDob`, `RequiresGender`, `RequiresAddress`, `RequiresPhone`, `RequiresEmail`, `RequiresEmergencyContact`, `RequiresWitness`.

For each: confirm the sign page renders only the toggled fields; submitting without a required demographic or custom-required field shows an inline error; review+print both show all captured data.

- [ ] **Step 3: Network/console audit**

Open DevTools Network tab during a sign submission. Confirm: no `error_log` / `logtrace` / stray `print_r` in responses; no uncaught JS errors; `submitSignature` response is JSON with `status: 0` on success.

- [ ] **Step 4: Commit any follow-up fixes from QA**

Use the existing convention:
```bash
git add <files>
git commit -m "Enhancement: Digital Waivers — QA follow-ups"
```

- [ ] **Step 5: Mark amendment complete**

No further action — the amendment plan is considered complete when all boxes above are ticked and Phase 4 verification passes. Do NOT open a PR at this step unless the user explicitly requests it (per project feedback `NEVER stage class.Authorization.php` and the rule that PRs open only on request).

---

## Self-review

**Spec coverage check against Amendment 1:**
- §A1.3.1 template columns → Task 0.1 migration + Task 0.2 SaveTemplate/shape.
- §A1.3.2 signature columns + verifier intake → Task 0.1 migration + Tasks 0.4 + 0.5.
- §A1.3.3 minors child table → Task 0.1 migration + Task 0.4 persistence + GetSignature attach.
- §A1.4 custom_fields_json schema + validator → Tasks 0.2 + 0.3.
- §A1.5 builder UX → Task 1.1.
- §A1.6 sign-page UX → Task 2.1.
- §A1.7 review/print UX → Tasks 3.1 + 3.2.
- §A1.8 data flow deltas → baked into each task's request-key list.
- §A1.9 security → `_validate_custom_fields_json` regex; ID last-4 truncation in VerifySignature; htmlspecialchars on render paths.
- §A1.10 coverage matrix → Task 4.1 manual QA.
- §A1.11 out-of-scope → nothing in this plan implements deferred items.

**Placeholder scan:** no `TODO`, `tbd`, `fill in`, "similar to Task N", or vague error-handling stubs. Each Python heredoc carries the full old→new replacement; each JS/PHP block is complete.

**Type consistency:** `RequiresDob`/`RequiresAddress`/etc. used identically in the migration, `SaveTemplate`, `_shape_template`, builder template, sign template, and review template. `Minors` is always an array of objects with `LegalFirst`, `LegalLast`, `PreferredName`, `PersonaName`, `Dob`. `CustomResponsesJson` is always a JSON-encoded string at the transport layer, a decoded map at the render layer. `VerifierIdNumberLast4` is exactly 4 digits end-to-end — the server trims so the client does not have to trust itself.
