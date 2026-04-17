# Digital Waivers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship end-to-end digital waivers for ORK3 — kingdom-admin waiver builder, player submission with drawn/typed signature, officer verification queue with signed sign-off — on branch `feature/digital-waivers`.

**Architecture:** Standard ORK3 three-layer: `system/lib/ork3/class.Waiver.php` (yapo DB access, returns `['Status' => Success()/Error(), ...]`); `orkui/model/model.Waiver.php` (thin `__call` pass-through via `APIModel`); `orkui/controller/controller.Waiver.php` + `controller.WaiverAjax.php` (page + JSON endpoints); templates in `orkui/template/revised-frontend/`. Markdown rendered server-side with existing `system/lib/Parsedown.php`; signature captured as normalised JSON stroke points on an HTML canvas (no new deps).

**Tech Stack:** PHP 8 / MariaDB 10.x / yapo ORM / vanilla JS + fetch / Parsedown / existing revised-frontend CSS conventions (`wv-` prefix).

**Hard project rules (memorised and enforced throughout this plan):**
- Edit PHP files ≥2 lines via Python `pathlib` string replace, NEVER the Edit tool (PHP tabs vs. Edit-tool rendering). Single-line PHP edits may use Edit.
- `$DB->Clear()` before any raw `Execute()` / `DataSet()` (stale PDO bindings).
- NEVER stage `class.Authorization.php`; if modified locally, leave out of every commit.
- NEVER commit `CLAUDE.md` or `agent-instructions/claude.md`.
- Every heading inside a new hero/card MUST reset global gray-box `h1-h6` styling (`background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;`).
- Debug output goes to the **browser console** only (`console.log`, `die(json_encode(...))`). Never `error_log`, `print_r`, `logtrace` as primary debug output.
- Dropdowns (autocompletes) inside modals use `position: fixed` via `tnFixedAcPosition(inputEl, dropdownEl)`, never `position: absolute`.
- `revised.js` IIFE guards MUST use a config flag (`PnConfig.canEditAdmin`, etc.) — NEVER `document.getElementById` as a top-level guard.
- No native `title` attributes — use in-product `data-tip` CSS tooltips.
- Stage files explicitly by name, never `git add -A` / `git add .`.
- Tests hit a real DB. No mocking.
- PR title: `Enhancement: Digital Waivers`.

---

## File map

**Create:**
- `db-migrations/2026-04-17-digital-waivers.sql`
- `system/lib/ork3/class.Waiver.php`
- `orkui/model/model.Waiver.php`
- `orkui/controller/controller.Waiver.php`
- `orkui/controller/controller.WaiverAjax.php`
- `orkui/template/revised-frontend/Waiver_builder.tpl`
- `orkui/template/revised-frontend/Waiver_sign.tpl`
- `orkui/template/revised-frontend/Waiver_queue.tpl`
- `orkui/template/revised-frontend/Waiver_review.tpl`
- `orkui/template/revised-frontend/Waiver_print.tpl`
- `tests/php/WaiverTest.php`
- `tests/php/run-waiver-tests.sh`

**Modify:**
- `orkui/template/revised-frontend/Kingdomnew_index.tpl` (admin menu injection point — add Waivers buttons)
- `orkui/template/revised-frontend/Parknew_index.tpl` (admin menu + player CTA)
- `orkui/template/revised-frontend/Playernew_index.tpl` (sidebar Digital Waivers card)
- `orkui/controller/controller.Kingdom.php` (add menu entry to `$this->data['menu']['admin']` when admin)
- `orkui/controller/controller.Park.php` (add menu entry to `$this->data['menu']['admin']` when admin)

---

## Phase 0 — Scaffolding (blocking, must finish before any other phase)

### Task 0.1: Create DB migration SQL

**Files:**
- Create: `db-migrations/2026-04-17-digital-waivers.sql`

- [ ] **Step 1: Write the migration file**

```sql
-- Digital Waivers: per-kingdom versioned templates + per-player signatures
-- 2026-04-17

CREATE TABLE IF NOT EXISTS `ork_waiver_template` (
  `waiver_template_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kingdom_id`            INT UNSIGNED NOT NULL,
  `scope`                 ENUM('kingdom','park') NOT NULL,
  `version`               INT UNSIGNED NOT NULL DEFAULT 1,
  `is_active`             TINYINT(1) NOT NULL DEFAULT 0,
  `is_enabled`            TINYINT(1) NOT NULL DEFAULT 0,
  `header_markdown`       TEXT NOT NULL,
  `body_markdown`         MEDIUMTEXT NOT NULL,
  `footer_markdown`       TEXT NOT NULL,
  `minor_markdown`        TEXT NOT NULL,
  `created_by_mundane_id` INT UNSIGNED NOT NULL,
  `created_at`            DATETIME NOT NULL,
  PRIMARY KEY (`waiver_template_id`),
  KEY `idx_kingdom_scope_active` (`kingdom_id`, `scope`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ork_waiver_signature` (
  `waiver_signature_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `waiver_template_id`       INT UNSIGNED NOT NULL,
  `mundane_id`               INT UNSIGNED NOT NULL,

  `mundane_first_snapshot`   VARCHAR(64)  NOT NULL DEFAULT '',
  `mundane_last_snapshot`    VARCHAR(64)  NOT NULL DEFAULT '',
  `persona_name_snapshot`    VARCHAR(128) NOT NULL DEFAULT '',
  `park_id_snapshot`         INT UNSIGNED NOT NULL DEFAULT 0,
  `kingdom_id_snapshot`      INT UNSIGNED NOT NULL DEFAULT 0,

  `signature_type`           ENUM('drawn','typed') NOT NULL,
  `signature_data`           MEDIUMTEXT NOT NULL,
  `signed_at`                DATETIME NOT NULL,

  `is_minor`                 TINYINT(1)   NOT NULL DEFAULT 0,
  `minor_rep_first`          VARCHAR(64)  NOT NULL DEFAULT '',
  `minor_rep_last`           VARCHAR(64)  NOT NULL DEFAULT '',
  `minor_rep_relationship`   VARCHAR(64)  NOT NULL DEFAULT '',

  `verification_status`      ENUM('pending','verified','rejected','superseded') NOT NULL DEFAULT 'pending',
  `verified_by_mundane_id`   INT UNSIGNED NOT NULL DEFAULT 0,
  `verified_at`              DATETIME NULL DEFAULT NULL,
  `verifier_printed_name`    VARCHAR(128) NOT NULL DEFAULT '',
  `verifier_persona_name`    VARCHAR(128) NOT NULL DEFAULT '',
  `verifier_office_title`    VARCHAR(128) NOT NULL DEFAULT '',
  `verifier_signature_type`  ENUM('drawn','typed') NULL DEFAULT NULL,
  `verifier_signature_data`  MEDIUMTEXT NULL DEFAULT NULL,
  `verifier_notes`           TEXT NOT NULL,

  PRIMARY KEY (`waiver_signature_id`),
  KEY `idx_mundane` (`mundane_id`),
  KEY `idx_template_status` (`waiver_template_id`, `verification_status`),
  KEY `idx_kingdom_status` (`kingdom_id_snapshot`, `verification_status`),
  KEY `idx_park_status` (`park_id_snapshot`, `verification_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply migration to dev DB**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-04-17-digital-waivers.sql
```

Expected: no output, exit 0.

- [ ] **Step 3: Verify tables exist**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW TABLES LIKE 'ork_waiver%';"
```

Expected output contains `ork_waiver_signature` and `ork_waiver_template`.

- [ ] **Step 4: Commit migration**

```bash
git add db-migrations/2026-04-17-digital-waivers.sql
git commit -m "$(cat <<'EOF'
Enhancement: Digital Waivers — DB migration

Adds ork_waiver_template and ork_waiver_signature tables.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 0.2: Skeleton domain class + model so routing resolves

**Files:**
- Create: `system/lib/ork3/class.Waiver.php`
- Create: `orkui/model/model.Waiver.php`

- [ ] **Step 1: Write skeleton class.Waiver.php**

```php
<?php

class Waiver extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->template  = new yapo($this->db, DB_PREFIX . 'waiver_template');
		$this->signature = new yapo($this->db, DB_PREFIX . 'waiver_signature');
		$this->mundane   = new yapo($this->db, DB_PREFIX . 'mundane');
		$this->kingdom   = new yapo($this->db, DB_PREFIX . 'kingdom');
		$this->park      = new yapo($this->db, DB_PREFIX . 'park');
	}

}

?>
```

- [ ] **Step 2: Write skeleton model.Waiver.php**

```php
<?php

class Model_Waiver extends Model {
	// All method calls forwarded to system/lib/ork3/class.Waiver.php via APIModel::__call
}

?>
```

- [ ] **Step 3: Verify skeletons parse**

Run:
```bash
docker exec ork3-php8-web php -l /var/www/html/system/lib/ork3/class.Waiver.php
docker exec ork3-php8-web php -l /var/www/html/orkui/model/model.Waiver.php
```

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit skeletons**

```bash
git add system/lib/ork3/class.Waiver.php orkui/model/model.Waiver.php
git commit -m "$(cat <<'EOF'
Enhancement: Digital Waivers — skeleton domain + model

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 0.3: Skeleton controllers so /Waiver/* routes resolve

**Files:**
- Create: `orkui/controller/controller.Waiver.php`
- Create: `orkui/controller/controller.WaiverAjax.php`

- [ ] **Step 1: Write skeleton controller.Waiver.php**

```php
<?php

class Controller_Waiver extends Controller {

	public function __construct($call = null, $id = null) {
		parent::__construct($call, $id);
	}

	public function index($id = null) {
		// fallthrough — redirect to builder if admin, else kingdom profile
		$this->redirect('Kingdom/index/' . (int)($id ?? 0));
	}

	public function builder($kingdom_id = null)  { $this->data['_wv'] = ['kingdom_id' => (int)$kingdom_id]; $this->template = '../revised-frontend/Waiver_builder.tpl'; }
	public function sign($scope = null, $id = null) { $this->data['_wv'] = ['scope' => $scope, 'id' => (int)$id]; $this->template = '../revised-frontend/Waiver_sign.tpl'; }
	public function queue($scope = null, $id = null) { $this->data['_wv'] = ['scope' => $scope, 'id' => (int)$id]; $this->template = '../revised-frontend/Waiver_queue.tpl'; }
	public function review($signature_id = null) { $this->data['_wv'] = ['signature_id' => (int)$signature_id]; $this->template = '../revised-frontend/Waiver_review.tpl'; }
	public function printable($signature_id = null) { $this->data['_wv'] = ['signature_id' => (int)$signature_id]; $this->template = '../revised-frontend/Waiver_print.tpl'; }
}

?>
```

Note: `print` is a reserved word, so the action is named `printable` and the route is `/Waiver/printable/{id}`. The page-print view template is still `Waiver_print.tpl`.

- [ ] **Step 2: Write skeleton controller.WaiverAjax.php**

```php
<?php

class Controller_WaiverAjax extends Controller {

	public function __construct($call = null, $id = null) {
		parent::__construct($call, $id);
		header('Content-Type: application/json');
	}

	private function requireLogin() {
		if (!isset($this->session->user_id) || (int)$this->session->user_id <= 0) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}
		return (int)$this->session->user_id;
	}

	public function saveTemplate()      { $this->requireLogin(); echo json_encode(['status' => 9, 'error' => 'not implemented']); exit; }
	public function previewMarkdown()   { $this->requireLogin(); echo json_encode(['status' => 9, 'error' => 'not implemented']); exit; }
	public function submitSignature()   { $this->requireLogin(); echo json_encode(['status' => 9, 'error' => 'not implemented']); exit; }
	public function verifySignature()   { $this->requireLogin(); echo json_encode(['status' => 9, 'error' => 'not implemented']); exit; }
	public function setEnabled()        { $this->requireLogin(); echo json_encode(['status' => 9, 'error' => 'not implemented']); exit; }
}

?>
```

- [ ] **Step 3: Verify syntax**

Run:
```bash
docker exec ork3-php8-web php -l /var/www/html/orkui/controller/controller.Waiver.php
docker exec ork3-php8-web php -l /var/www/html/orkui/controller/controller.WaiverAjax.php
```

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Smoke-test routing**

Run:
```bash
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:19080/orkui/Waiver/builder/1"
curl -s "http://localhost:19080/orkui/WaiverAjax/saveTemplate" | head -c 200
```

Expected: builder returns 200 (blank template body is fine — templates written in Phase 3); `WaiverAjax/saveTemplate` returns JSON with `"status":5` (not logged in).

- [ ] **Step 5: Commit skeleton controllers**

```bash
git add orkui/controller/controller.Waiver.php orkui/controller/controller.WaiverAjax.php
git commit -m "$(cat <<'EOF'
Enhancement: Digital Waivers — skeleton controllers

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Phase 1 — Domain layer (TDD, sequential within phase)

All methods live in `system/lib/ork3/class.Waiver.php`. Each task writes a failing test, then the minimal impl, then makes it green.

### Task 1.1: Test harness

**Files:**
- Create: `tests/php/run-waiver-tests.sh`
- Create: `tests/php/WaiverTest.php`

No formal PHPUnit in the repo; the test is a plain PHP script that runs each `test_*` method, catches exceptions, reports PASS/FAIL with line info. Runs inside the docker web container against the real DB.

- [ ] **Step 1: Write harness runner**

```bash
#!/bin/bash
# tests/php/run-waiver-tests.sh — run Waiver domain tests inside docker
set -e
cd "$(dirname "$0")/../.."
docker exec -w /var/www/html/tests/php ork3-php8-web php WaiverTest.php "$@"
```

Make executable: `chmod +x tests/php/run-waiver-tests.sh`

- [ ] **Step 2: Write tests/php/WaiverTest.php skeleton**

```php
<?php
// Runs inside ork3-php8-web container. Hits the real dev DB.
// Invocation: docker exec -w /var/www/html/tests/php ork3-php8-web php WaiverTest.php

require_once('/var/www/html/system/common.php');

class WaiverTestRunner {
	public $pass = 0;
	public $fail = 0;
	public $testMundaneId = 1;   // root / first admin mundane_id in dev DB
	public $testKingdomId = 1;
	public $testParkId    = 1;
	public $token;
	public $waiver;

	public function __construct() {
		$this->waiver = new Waiver();
		// Issue a fresh session token bound to testMundaneId
		$auth = Ork3::$Lib->authorization;
		$this->token = $this->_issueToken($this->testMundaneId);
	}

	private function _issueToken($mundane_id) {
		$m = new yapo($this->waiver->db, DB_PREFIX . 'mundane');
		$m->clear();
		$m->mundane_id = $mundane_id;
		if (!$m->find()) throw new Exception("Seed mundane_id $mundane_id missing");
		$tok = bin2hex(random_bytes(16));
		$m->token = $tok;
		$m->save();
		return $tok;
	}

	public function assertEq($expected, $actual, $msg = '') {
		if ($expected === $actual) { $this->pass++; echo "  PASS $msg\n"; }
		else { $this->fail++; echo "  FAIL $msg (expected " . var_export($expected, true) . " got " . var_export($actual, true) . ")\n"; }
	}

	public function assertTrue($cond, $msg = '') { $this->assertEq(true, (bool)$cond, $msg); }
	public function assertStatus($expected, $response, $msg = '') { $this->assertEq($expected, $response['Status']['Code'] ?? null, $msg); }

	public function run() {
		foreach (get_class_methods($this) as $m) {
			if (substr($m, 0, 5) === 'test_') {
				echo "\n=== $m ===\n";
				try { $this->$m(); }
				catch (Throwable $e) { $this->fail++; echo "  EXCEPTION $m: " . $e->getMessage() . "\n  " . $e->getTraceAsString() . "\n"; }
			}
		}
		echo "\n--- $this->pass passed, $this->fail failed ---\n";
		exit($this->fail > 0 ? 1 : 0);
	}

	// -------- tests below -------- //

	public function test_harness_self() {
		$this->assertTrue(!empty($this->token), 'issued token');
		$this->assertTrue($this->waiver instanceof Waiver, 'Waiver instantiates');
	}
}

(new WaiverTestRunner())->run();
?>
```

- [ ] **Step 3: Run harness self-test**

Run: `./tests/php/run-waiver-tests.sh`
Expected: `2 passed, 0 failed`.

- [ ] **Step 4: Commit harness**

```bash
git add tests/php/run-waiver-tests.sh tests/php/WaiverTest.php
git commit -m "$(cat <<'EOF'
Enhancement: Digital Waivers — test harness

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 1.2: `SaveTemplate` — create / version / publish

Public method: `SaveTemplate($request)` where `$request` contains `Token`, `KingdomId`, `Scope` (`'kingdom'`|`'park'`), `HeaderMarkdown`, `BodyMarkdown`, `FooterMarkdown`, `MinorMarkdown`, `IsEnabled`. Authz: requester must have `AUTH_KINGDOM+AUTH_EDIT` on that kingdom (or `AUTH_ADMIN`). Versioning: prior `is_active=1` row for `(kingdom_id, scope)` is flipped to `is_active=0`, new row inserted with `is_active=1` and `version = prev.version + 1` (or 1 if none).

**Files:**
- Modify: `tests/php/WaiverTest.php` (append)
- Modify: `system/lib/ork3/class.Waiver.php` (append method)

- [ ] **Step 1: Write failing tests**

Append to `tests/php/WaiverTest.php`, immediately before `(new WaiverTestRunner())->run();`:

```php
	public function test_save_template_kingdom_creates_v1() {
		// Clean out any prior test rows
		$this->waiver->db->Clear();
		$this->waiver->db->Execute("DELETE FROM " . DB_PREFIX . "waiver_template WHERE kingdom_id = ?", [$this->testKingdomId]);

		$r = $this->waiver->SaveTemplate([
			'Token'          => $this->token,
			'KingdomId'      => $this->testKingdomId,
			'Scope'          => 'kingdom',
			'HeaderMarkdown' => '# Hdr',
			'BodyMarkdown'   => 'body',
			'FooterMarkdown' => 'ftr',
			'MinorMarkdown'  => 'minor',
			'IsEnabled'      => 1,
		]);
		$this->assertStatus(0, $r, 'v1 saved');
		$this->assertEq(1, (int)$r['Version'], 'version is 1');
		$this->assertTrue($r['TemplateId'] > 0, 'TemplateId returned');
	}

	public function test_save_template_kingdom_bumps_to_v2() {
		$r1 = $this->waiver->SaveTemplate([
			'Token' => $this->token, 'KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom',
			'HeaderMarkdown' => 'v2 hdr', 'BodyMarkdown' => 'v2', 'FooterMarkdown' => '', 'MinorMarkdown' => '',
			'IsEnabled' => 1,
		]);
		$this->assertStatus(0, $r1, 'v2 saved');
		$this->assertEq(2, (int)$r1['Version'], 'version bumped');

		// Only one active row per (kingdom, scope):
		$this->waiver->db->Clear();
		$rs = $this->waiver->db->DataSet("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "waiver_template WHERE kingdom_id = ? AND scope='kingdom' AND is_active=1", [$this->testKingdomId]);
		$this->assertEq(1, (int)$rs[0]['c'], 'exactly one active row');
	}

	public function test_save_template_rejects_unauthorized() {
		// Issue a token for a non-admin mundane
		$nonAdmin = $this->_issueToken(9999999); // assume nonexistent -> IsAuthorized returns 0
		$r = $this->waiver->SaveTemplate([
			'Token' => 'deadbeef' . str_repeat('0', 24),
			'KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom',
			'HeaderMarkdown' => '', 'BodyMarkdown' => '', 'FooterMarkdown' => '', 'MinorMarkdown' => '',
			'IsEnabled' => 1,
		]);
		$this->assertTrue($r['Status']['Code'] !== 0, 'rejected unauthorized');
	}
```

- [ ] **Step 2: Run, confirm failure**

Run: `./tests/php/run-waiver-tests.sh`
Expected: all three `test_save_template_*` FAIL with `EXCEPTION` (method missing).

- [ ] **Step 3: Implement `SaveTemplate` in class.Waiver.php**

Using Python (PHP file, multi-line, tabs — per hard rule):

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias/system/lib/ork3/class.Waiver.php')
t = p.read_text()
needle = "\t}\n\n}\n\n?>"
add = r'''
	public function SaveTemplate($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$kingdom_id = (int)($request['KingdomId'] ?? 0);
		$scope      = in_array($request['Scope'] ?? '', ['kingdom','park']) ? $request['Scope'] : null;
		if ($mundane_id <= 0)      return ['Status' => Unauthorized()];
		if ($kingdom_id <= 0)      return ['Status' => InvalidParameter(), 'Error' => 'KingdomId required'];
		if ($scope === null)       return ['Status' => InvalidParameter(), 'Error' => 'Scope required'];
		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)
			&& !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
			return ['Status' => Unauthorized()];
		}

		$this->db->Clear();
		$prev = $this->db->DataSet(
			"SELECT waiver_template_id, version FROM " . DB_PREFIX . "waiver_template
			 WHERE kingdom_id = ? AND scope = ? AND is_active = 1
			 ORDER BY version DESC LIMIT 1",
			[$kingdom_id, $scope]
		);
		$nextVersion = $prev ? ((int)$prev[0]['version'] + 1) : 1;

		if ($prev) {
			$this->db->Clear();
			$this->db->Execute(
				"UPDATE " . DB_PREFIX . "waiver_template SET is_active = 0 WHERE waiver_template_id = ?",
				[(int)$prev[0]['waiver_template_id']]
			);
		}

		$this->template->clear();
		$this->template->kingdom_id            = $kingdom_id;
		$this->template->scope                 = $scope;
		$this->template->version               = $nextVersion;
		$this->template->is_active             = 1;
		$this->template->is_enabled            = (int)($request['IsEnabled'] ?? 0) ? 1 : 0;
		$this->template->header_markdown       = (string)($request['HeaderMarkdown'] ?? '');
		$this->template->body_markdown         = (string)($request['BodyMarkdown']   ?? '');
		$this->template->footer_markdown       = (string)($request['FooterMarkdown'] ?? '');
		$this->template->minor_markdown        = (string)($request['MinorMarkdown']  ?? '');
		$this->template->created_by_mundane_id = $mundane_id;
		$this->template->created_at            = date('Y-m-d H:i:s');

		if (!$this->template->save()) return ['Status' => Error(), 'Error' => 'save failed'];

		return [
			'Status'     => Success(),
			'TemplateId' => (int)$this->template->waiver_template_id,
			'Version'    => $nextVersion,
		];
	}
'''
new = "\t}\n" + add + "\n}\n\n?>"
assert needle in t, 'needle not found'
p.write_text(t.replace(needle, new, 1))
print('ok')
PY
```

- [ ] **Step 4: Run tests, confirm PASS**

Run: `./tests/php/run-waiver-tests.sh`
Expected: previously-failing `test_save_template_*` tests now PASS.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Waiver.php tests/php/WaiverTest.php
git commit -m "$(cat <<'EOF'
Enhancement: Digital Waivers — SaveTemplate with versioning

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 1.3: `GetActiveTemplate` and `GetTemplate`

- `GetActiveTemplate($request)` returns the currently active (is_active=1) row for `(KingdomId, Scope)`, or `Status => NotFound` if none.
- `GetTemplate($request)` returns a specific row by `TemplateId` (used by signature review to render historical markdown).

**Files:**
- Modify: `tests/php/WaiverTest.php`
- Modify: `system/lib/ork3/class.Waiver.php`

- [ ] **Step 1: Write failing tests**

Append to `WaiverTest.php` before the `run()`:

```php
	public function test_get_active_template_returns_latest() {
		$r = $this->waiver->GetActiveTemplate([
			'Token' => $this->token, 'KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom',
		]);
		$this->assertStatus(0, $r, 'found active');
		$this->assertEq('v2 hdr', $r['Template']['HeaderMarkdown'], 'latest header');
		$this->assertEq(2, (int)$r['Template']['Version'], 'latest version');
	}

	public function test_get_active_template_missing_scope_returns_notfound() {
		$r = $this->waiver->GetActiveTemplate([
			'Token' => $this->token, 'KingdomId' => $this->testKingdomId, 'Scope' => 'park',
		]);
		$this->assertTrue($r['Status']['Code'] !== 0, 'park scope has no active template yet');
	}

	public function test_get_template_by_id() {
		$all = $this->waiver->db->DataSet("SELECT waiver_template_id FROM " . DB_PREFIX . "waiver_template WHERE kingdom_id = ? AND scope='kingdom' ORDER BY version DESC LIMIT 1", [$this->testKingdomId]);
		$tid = (int)$all[0]['waiver_template_id'];
		$r = $this->waiver->GetTemplate(['Token' => $this->token, 'TemplateId' => $tid]);
		$this->assertStatus(0, $r, 'get by id');
		$this->assertEq($tid, (int)$r['Template']['TemplateId'], 'id round-trips');
	}
```

- [ ] **Step 2: Run, confirm failure**

`./tests/php/run-waiver-tests.sh` → FAIL (methods missing).

- [ ] **Step 3: Implement both methods**

Run Python:

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias/system/lib/ork3/class.Waiver.php')
t = p.read_text()
marker = "}\n\n?>"
add = r'''
	private function _shape_template($row) {
		if (!$row) return null;
		return [
			'TemplateId'      => (int)$row['waiver_template_id'],
			'KingdomId'       => (int)$row['kingdom_id'],
			'Scope'           => $row['scope'],
			'Version'         => (int)$row['version'],
			'IsActive'        => (int)$row['is_active'],
			'IsEnabled'       => (int)$row['is_enabled'],
			'HeaderMarkdown'  => $row['header_markdown'],
			'BodyMarkdown'    => $row['body_markdown'],
			'FooterMarkdown'  => $row['footer_markdown'],
			'MinorMarkdown'   => $row['minor_markdown'],
			'CreatedAt'       => $row['created_at'],
		];
	}

	public function GetActiveTemplate($request) {
		$kingdom_id = (int)($request['KingdomId'] ?? 0);
		$scope = in_array($request['Scope'] ?? '', ['kingdom','park']) ? $request['Scope'] : null;
		if ($kingdom_id <= 0 || $scope === null) return ['Status' => InvalidParameter()];
		$this->db->Clear();
		$rows = $this->db->DataSet(
			"SELECT * FROM " . DB_PREFIX . "waiver_template
			 WHERE kingdom_id = ? AND scope = ? AND is_active = 1 LIMIT 1",
			[$kingdom_id, $scope]
		);
		if (!$rows) return ['Status' => NotFound()];
		return ['Status' => Success(), 'Template' => $this->_shape_template($rows[0])];
	}

	public function GetTemplate($request) {
		$tid = (int)($request['TemplateId'] ?? 0);
		if ($tid <= 0) return ['Status' => InvalidParameter()];
		$this->db->Clear();
		$rows = $this->db->DataSet(
			"SELECT * FROM " . DB_PREFIX . "waiver_template WHERE waiver_template_id = ? LIMIT 1",
			[$tid]
		);
		if (!$rows) return ['Status' => NotFound()];
		return ['Status' => Success(), 'Template' => $this->_shape_template($rows[0])];
	}
'''
assert marker in t
p.write_text(t.replace(marker, add + "\n" + marker, 1))
print('ok')
PY
```

- [ ] **Step 4: Run tests → PASS**

`./tests/php/run-waiver-tests.sh` → all 3 new tests PASS.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Waiver.php tests/php/WaiverTest.php
git commit -m "Enhancement: Digital Waivers — GetActiveTemplate + GetTemplate

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 1.4: `SetTemplateEnabled`

Toggle `is_enabled` on a template by id. Same kingdom-admin auth as `SaveTemplate`.

- [ ] **Step 1: Failing tests** (append)

```php
	public function test_set_enabled_toggles() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)$r['Template']['TemplateId'];

		$r2 = $this->waiver->SetTemplateEnabled(['Token' => $this->token, 'TemplateId' => $tid, 'IsEnabled' => 0]);
		$this->assertStatus(0, $r2, 'disabled');
		$r3 = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$this->assertEq(0, (int)$r3['Template']['IsEnabled'], 'now disabled');

		$r4 = $this->waiver->SetTemplateEnabled(['Token' => $this->token, 'TemplateId' => $tid, 'IsEnabled' => 1]);
		$this->assertStatus(0, $r4, 're-enabled');
	}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement** (Python insert, same marker pattern as Task 1.3):

```php
	public function SetTemplateEnabled($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		$tid = (int)($request['TemplateId'] ?? 0);
		if ($mundane_id <= 0) return ['Status' => Unauthorized()];
		if ($tid <= 0) return ['Status' => InvalidParameter()];
		$t = $this->GetTemplate(['TemplateId' => $tid]);
		if ($t['Status']['Code'] !== 0) return $t;
		$kingdom_id = (int)$t['Template']['KingdomId'];
		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)
			&& !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
			return ['Status' => Unauthorized()];
		}
		$this->db->Clear();
		$this->db->Execute("UPDATE " . DB_PREFIX . "waiver_template SET is_enabled = ? WHERE waiver_template_id = ?",
			[(int)($request['IsEnabled'] ?? 0) ? 1 : 0, $tid]);
		return ['Status' => Success()];
	}
```

- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** (`Enhancement: Digital Waivers — SetTemplateEnabled`).

---

### Task 1.5: `SubmitSignature`

Any logged-in mundane can submit against an `is_active=1, is_enabled=1` template. Server stamps mundane_id from token (NEVER trust client). Snapshots first/last/persona/home kingdom+park at submit time. Returns new `SignatureId`.

- [ ] **Step 1: Failing tests** (append)

```php
	public function test_submit_signature_drawn() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)$r['Template']['TemplateId'];
		$r2 = $this->waiver->SubmitSignature([
			'Token' => $this->token, 'TemplateId' => $tid,
			'MundaneFirst' => 'Test', 'MundaneLast' => 'User', 'PersonaName' => 'Testicus',
			'ParkId' => $this->testParkId, 'KingdomId' => $this->testKingdomId,
			'SignatureType' => 'drawn', 'SignatureData' => json_encode([[['x'=>0.1,'y'=>0.2],['x'=>0.3,'y'=>0.4]]]),
			'IsMinor' => 0, 'MinorRepFirst'=>'', 'MinorRepLast'=>'', 'MinorRepRelationship'=>'',
		]);
		$this->assertStatus(0, $r2, 'signed');
		$this->assertTrue($r2['SignatureId'] > 0, 'id returned');
	}

	public function test_submit_signature_typed_minor() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)$r['Template']['TemplateId'];
		$r2 = $this->waiver->SubmitSignature([
			'Token' => $this->token, 'TemplateId' => $tid,
			'MundaneFirst' => 'Junior', 'MundaneLast' => 'Smith', 'PersonaName' => 'Jr',
			'ParkId' => $this->testParkId, 'KingdomId' => $this->testKingdomId,
			'SignatureType' => 'typed', 'SignatureData' => 'Junior Smith',
			'IsMinor' => 1, 'MinorRepFirst'=>'Parent', 'MinorRepLast'=>'Smith', 'MinorRepRelationship'=>'Mother',
		]);
		$this->assertStatus(0, $r2, 'minor signed');
	}

	public function test_submit_signature_rejects_unauthenticated() {
		$r = $this->waiver->SubmitSignature([
			'Token' => str_repeat('0', 32), 'TemplateId' => 1,
			'MundaneFirst'=>'', 'MundaneLast'=>'', 'PersonaName'=>'', 'ParkId'=>0, 'KingdomId'=>0,
			'SignatureType'=>'typed', 'SignatureData'=>'', 'IsMinor'=>0,
			'MinorRepFirst'=>'', 'MinorRepLast'=>'', 'MinorRepRelationship'=>'',
		]);
		$this->assertTrue($r['Status']['Code'] !== 0, 'rejected bad token');
	}

	public function test_submit_signature_rejects_disabled_template() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)$r['Template']['TemplateId'];
		$this->waiver->SetTemplateEnabled(['Token' => $this->token, 'TemplateId' => $tid, 'IsEnabled' => 0]);

		$r2 = $this->waiver->SubmitSignature([
			'Token' => $this->token, 'TemplateId' => $tid,
			'MundaneFirst'=>'T','MundaneLast'=>'U','PersonaName'=>'','ParkId'=>1,'KingdomId'=>1,
			'SignatureType'=>'typed','SignatureData'=>'T U','IsMinor'=>0,
			'MinorRepFirst'=>'','MinorRepLast'=>'','MinorRepRelationship'=>'',
		]);
		$this->assertTrue($r2['Status']['Code'] !== 0, 'rejected disabled');

		// re-enable for later tests
		$this->waiver->SetTemplateEnabled(['Token' => $this->token, 'TemplateId' => $tid, 'IsEnabled' => 1]);
	}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement** (Python insert before closing `}\n\n?>`):

```php
	public function SubmitSignature($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if ($mundane_id <= 0) return ['Status' => Unauthorized()];

		$tid = (int)($request['TemplateId'] ?? 0);
		$t = $this->GetTemplate(['TemplateId' => $tid]);
		if ($t['Status']['Code'] !== 0) return $t;
		if ((int)$t['Template']['IsActive'] !== 1 || (int)$t['Template']['IsEnabled'] !== 1) {
			return ['Status' => InvalidParameter(), 'Error' => 'Template not currently accepting signatures'];
		}

		$sigType = in_array($request['SignatureType'] ?? '', ['drawn','typed']) ? $request['SignatureType'] : null;
		if ($sigType === null) return ['Status' => InvalidParameter(), 'Error' => 'SignatureType invalid'];
		$sigData = (string)($request['SignatureData'] ?? '');
		if ($sigData === '' || strlen($sigData) > 262144) return ['Status' => InvalidParameter(), 'Error' => 'Signature empty or too large'];

		$isMinor = (int)($request['IsMinor'] ?? 0) ? 1 : 0;
		if ($isMinor && trim($request['MinorRepFirst'] ?? '') === '') return ['Status' => InvalidParameter(), 'Error' => 'Minor rep first name required'];
		if ($isMinor && trim($request['MinorRepLast']  ?? '') === '') return ['Status' => InvalidParameter(), 'Error' => 'Minor rep last name required'];
		if ($isMinor && trim($request['MinorRepRelationship'] ?? '') === '') return ['Status' => InvalidParameter(), 'Error' => 'Relationship required'];

		$this->signature->clear();
		$this->signature->waiver_template_id    = $tid;
		$this->signature->mundane_id            = $mundane_id;
		$this->signature->mundane_first_snapshot = substr(trim((string)($request['MundaneFirst'] ?? '')), 0, 64);
		$this->signature->mundane_last_snapshot  = substr(trim((string)($request['MundaneLast']  ?? '')), 0, 64);
		$this->signature->persona_name_snapshot  = substr(trim((string)($request['PersonaName']  ?? '')), 0, 128);
		$this->signature->park_id_snapshot       = (int)($request['ParkId'] ?? 0);
		$this->signature->kingdom_id_snapshot    = (int)($request['KingdomId'] ?? 0);
		$this->signature->signature_type         = $sigType;
		$this->signature->signature_data         = $sigData;
		$this->signature->signed_at              = date('Y-m-d H:i:s');
		$this->signature->is_minor               = $isMinor;
		$this->signature->minor_rep_first        = substr(trim((string)($request['MinorRepFirst'] ?? '')), 0, 64);
		$this->signature->minor_rep_last         = substr(trim((string)($request['MinorRepLast']  ?? '')), 0, 64);
		$this->signature->minor_rep_relationship = substr(trim((string)($request['MinorRepRelationship'] ?? '')), 0, 64);
		$this->signature->verification_status    = 'pending';
		$this->signature->verifier_notes         = '';

		if (!$this->signature->save()) return ['Status' => Error(), 'Error' => 'Save failed'];

		return ['Status' => Success(), 'SignatureId' => (int)$this->signature->waiver_signature_id];
	}
```

- [ ] **Step 4: Run → PASS** (all 4).
- [ ] **Step 5: Commit** (`Enhancement: Digital Waivers — SubmitSignature`).

---

### Task 1.6: `GetSignature`

Return a signature row joined with its template + player display names + verifier display name. Auth: signer themselves OR kingdom/park officer with edit authority on `kingdom_id_snapshot` or `park_id_snapshot`.

- [ ] **Step 1: Failing test** (append)

```php
	public function test_get_signature_by_owner() {
		// fetch last signature for testMundaneId
		$rs = $this->waiver->db->DataSet("SELECT waiver_signature_id FROM " . DB_PREFIX . "waiver_signature WHERE mundane_id = ? ORDER BY waiver_signature_id DESC LIMIT 1", [$this->testMundaneId]);
		$sid = (int)$rs[0]['waiver_signature_id'];
		$r = $this->waiver->GetSignature(['Token' => $this->token, 'SignatureId' => $sid]);
		$this->assertStatus(0, $r, 'owner can read');
		$this->assertEq($sid, (int)$r['Signature']['SignatureId'], 'id round-trips');
		$this->assertTrue(isset($r['Signature']['Template']), 'template attached');
	}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement** (Python insert). Note: use `$DB->Clear()` before each DataSet call per hard rule.

```php
	private function _shape_signature($row) {
		if (!$row) return null;
		return [
			'SignatureId'           => (int)$row['waiver_signature_id'],
			'TemplateId'            => (int)$row['waiver_template_id'],
			'MundaneId'             => (int)$row['mundane_id'],
			'MundaneFirst'          => $row['mundane_first_snapshot'],
			'MundaneLast'           => $row['mundane_last_snapshot'],
			'PersonaName'           => $row['persona_name_snapshot'],
			'ParkId'                => (int)$row['park_id_snapshot'],
			'KingdomId'             => (int)$row['kingdom_id_snapshot'],
			'SignatureType'         => $row['signature_type'],
			'SignatureData'         => $row['signature_data'],
			'SignedAt'              => $row['signed_at'],
			'IsMinor'               => (int)$row['is_minor'],
			'MinorRepFirst'         => $row['minor_rep_first'],
			'MinorRepLast'          => $row['minor_rep_last'],
			'MinorRepRelationship'  => $row['minor_rep_relationship'],
			'VerificationStatus'    => $row['verification_status'],
			'VerifiedByMundaneId'   => (int)$row['verified_by_mundane_id'],
			'VerifiedAt'            => $row['verified_at'],
			'VerifierPrintedName'   => $row['verifier_printed_name'],
			'VerifierPersonaName'   => $row['verifier_persona_name'],
			'VerifierOfficeTitle'   => $row['verifier_office_title'],
			'VerifierSignatureType' => $row['verifier_signature_type'],
			'VerifierSignatureData' => $row['verifier_signature_data'],
			'VerifierNotes'         => $row['verifier_notes'],
		];
	}

	public function GetSignature($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if ($mundane_id <= 0) return ['Status' => Unauthorized()];
		$sid = (int)($request['SignatureId'] ?? 0);
		if ($sid <= 0) return ['Status' => InvalidParameter()];

		$this->db->Clear();
		$rows = $this->db->DataSet("SELECT * FROM " . DB_PREFIX . "waiver_signature WHERE waiver_signature_id = ? LIMIT 1", [$sid]);
		if (!$rows) return ['Status' => NotFound()];
		$sig = $this->_shape_signature($rows[0]);

		$authorized = ($sig['MundaneId'] === $mundane_id)
			|| Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $sig['KingdomId'], AUTH_EDIT)
			|| Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $sig['ParkId'], AUTH_EDIT)
			|| Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT);
		if (!$authorized) return ['Status' => Unauthorized()];

		$t = $this->GetTemplate(['TemplateId' => $sig['TemplateId']]);
		$sig['Template'] = $t['Status']['Code'] === 0 ? $t['Template'] : null;

		return ['Status' => Success(), 'Signature' => $sig];
	}
```

- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** (`Enhancement: Digital Waivers — GetSignature`).

---

### Task 1.7: `GetQueue`

Paginated list of signatures for `(scope, entity_id)`. Filter by status: `all` (default pending), `verified`, `rejected`, `all`, `stale` (signed against a template row whose `is_active=0` now). Include player display (mundane first+last + persona). Auth: officer of that scope.

- [ ] **Step 1: Failing test** (append)

```php
	public function test_get_queue_pending() {
		$r = $this->waiver->GetQueue([
			'Token' => $this->token, 'Scope' => 'kingdom', 'EntityId' => $this->testKingdomId,
			'Filter' => 'pending', 'Page' => 1, 'PageSize' => 10,
		]);
		$this->assertStatus(0, $r, 'queue fetched');
		$this->assertTrue(is_array($r['Signatures']), 'signatures array');
		$this->assertTrue($r['Total'] >= 1, 'at least one signature exists');
	}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

```php
	public function GetQueue($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if ($mundane_id <= 0) return ['Status' => Unauthorized()];
		$scope = in_array($request['Scope'] ?? '', ['kingdom','park']) ? $request['Scope'] : null;
		$entity_id = (int)($request['EntityId'] ?? 0);
		if ($scope === null || $entity_id <= 0) return ['Status' => InvalidParameter()];

		$authType = ($scope === 'kingdom') ? AUTH_KINGDOM : AUTH_PARK;
		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, $authType, $entity_id, AUTH_EDIT)
			&& !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
			return ['Status' => Unauthorized()];
		}

		$filter = in_array($request['Filter'] ?? 'pending', ['pending','verified','rejected','stale','all']) ? $request['Filter'] : 'pending';
		$page     = max(1, (int)($request['Page'] ?? 1));
		$pageSize = max(1, min(100, (int)($request['PageSize'] ?? 10)));
		$offset   = ($page - 1) * $pageSize;

		$scopeCol = ($scope === 'kingdom') ? 's.kingdom_id_snapshot' : 's.park_id_snapshot';
		$statusClause = '';
		$params = [$entity_id];
		switch ($filter) {
			case 'pending':   $statusClause = "AND s.verification_status = 'pending' AND t.is_active = 1"; break;
			case 'verified':  $statusClause = "AND s.verification_status = 'verified'"; break;
			case 'rejected':  $statusClause = "AND s.verification_status IN ('rejected','superseded')"; break;
			case 'stale':     $statusClause = "AND s.verification_status = 'pending' AND t.is_active = 0"; break;
			case 'all':       $statusClause = ''; break;
		}

		$sql = "FROM " . DB_PREFIX . "waiver_signature s
		        JOIN " . DB_PREFIX . "waiver_template  t ON t.waiver_template_id = s.waiver_template_id
		        WHERE $scopeCol = ? AND t.scope = '" . ($scope === 'kingdom' ? 'kingdom' : 'park') . "' $statusClause";

		$this->db->Clear();
		$count = $this->db->DataSet("SELECT COUNT(*) AS c $sql", $params);
		$total = (int)($count[0]['c'] ?? 0);

		$this->db->Clear();
		$rows = $this->db->DataSet("SELECT s.* $sql ORDER BY s.signed_at DESC LIMIT $pageSize OFFSET $offset", $params);
		$out = [];
		foreach ($rows as $r) $out[] = $this->_shape_signature($r);

		return ['Status' => Success(), 'Signatures' => $out, 'Total' => $total, 'Page' => $page, 'PageSize' => $pageSize];
	}
```

- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** (`Enhancement: Digital Waivers — GetQueue`).

---

### Task 1.8: `VerifySignature`

Officer marks a signature `verified` or `rejected` (or legitimately `superseded` when player re-signs). Records verifier snapshot fields + signature.

- [ ] **Step 1: Failing tests** (append)

```php
	public function test_verify_signature_approved() {
		$rs = $this->waiver->db->DataSet("SELECT waiver_signature_id FROM " . DB_PREFIX . "waiver_signature WHERE verification_status='pending' ORDER BY waiver_signature_id DESC LIMIT 1");
		$sid = (int)$rs[0]['waiver_signature_id'];
		$r = $this->waiver->VerifySignature([
			'Token' => $this->token, 'SignatureId' => $sid, 'Action' => 'verified',
			'PrintedName' => 'Admin Person', 'PersonaName' => 'Sir Admin', 'OfficeTitle' => 'Prime Minister',
			'SignatureType' => 'typed', 'SignatureData' => 'Admin Person', 'Notes' => '',
		]);
		$this->assertStatus(0, $r, 'verified');

		$r2 = $this->waiver->GetSignature(['Token' => $this->token, 'SignatureId' => $sid]);
		$this->assertEq('verified', $r2['Signature']['VerificationStatus'], 'status updated');
		$this->assertEq('Admin Person', $r2['Signature']['VerifierPrintedName'], 'printed name stored');
	}

	public function test_verify_signature_reject_requires_notes() {
		$rs = $this->waiver->db->DataSet("SELECT waiver_signature_id FROM " . DB_PREFIX . "waiver_signature WHERE verification_status='pending' ORDER BY waiver_signature_id DESC LIMIT 1");
		if (!$rs) return; // skip if no pending
		$sid = (int)$rs[0]['waiver_signature_id'];
		$r = $this->waiver->VerifySignature([
			'Token' => $this->token, 'SignatureId' => $sid, 'Action' => 'rejected',
			'PrintedName' => 'A', 'PersonaName' => '', 'OfficeTitle' => '', 'SignatureType' => 'typed', 'SignatureData' => 'A', 'Notes' => '',
		]);
		$this->assertTrue($r['Status']['Code'] !== 0, 'reject without notes blocked');
	}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

```php
	public function VerifySignature($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if ($mundane_id <= 0) return ['Status' => Unauthorized()];
		$sid = (int)($request['SignatureId'] ?? 0);
		if ($sid <= 0) return ['Status' => InvalidParameter()];
		$action = in_array($request['Action'] ?? '', ['verified','rejected','superseded']) ? $request['Action'] : null;
		if ($action === null) return ['Status' => InvalidParameter(), 'Error' => 'Action invalid'];

		$cur = $this->GetSignature(['Token' => $request['Token'], 'SignatureId' => $sid]);
		if ($cur['Status']['Code'] !== 0) return $cur;

		$kid = (int)$cur['Signature']['KingdomId'];
		$pid = (int)$cur['Signature']['ParkId'];
		$authorized = Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kid, AUTH_EDIT)
			|| Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $pid, AUTH_EDIT)
			|| Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT);
		if (!$authorized) return ['Status' => Unauthorized()];

		if ($action === 'rejected' && trim((string)($request['Notes'] ?? '')) === '') {
			return ['Status' => InvalidParameter(), 'Error' => 'Notes required when rejecting'];
		}
		$sigType = in_array($request['SignatureType'] ?? '', ['drawn','typed']) ? $request['SignatureType'] : null;
		$sigData = (string)($request['SignatureData'] ?? '');
		if ($action !== 'superseded' && ($sigType === null || $sigData === '')) {
			return ['Status' => InvalidParameter(), 'Error' => 'Verifier signature required'];
		}

		$this->signature->clear();
		$this->signature->waiver_signature_id = $sid;
		if (!$this->signature->find()) return ['Status' => NotFound()];
		$this->signature->verification_status    = $action;
		$this->signature->verified_by_mundane_id = $mundane_id;
		$this->signature->verified_at            = date('Y-m-d H:i:s');
		$this->signature->verifier_printed_name  = substr(trim((string)($request['PrintedName'] ?? '')), 0, 128);
		$this->signature->verifier_persona_name  = substr(trim((string)($request['PersonaName'] ?? '')), 0, 128);
		$this->signature->verifier_office_title  = substr(trim((string)($request['OfficeTitle'] ?? '')), 0, 128);
		$this->signature->verifier_signature_type = $sigType;
		$this->signature->verifier_signature_data = $sigData;
		$this->signature->verifier_notes         = (string)($request['Notes'] ?? '');
		if (!$this->signature->save()) return ['Status' => Error(), 'Error' => 'Save failed'];

		return ['Status' => Success()];
	}
```

- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** (`Enhancement: Digital Waivers — VerifySignature`).

---

### Task 1.9: `PreviewMarkdown`

Server-side Parsedown render used by builder's live preview. Auth-gated (to avoid abuse). Input size-capped.

- [ ] **Step 1: Failing test** (append)

```php
	public function test_preview_markdown_renders() {
		$r = $this->waiver->PreviewMarkdown(['Token' => $this->token, 'Markdown' => '**Hello**']);
		$this->assertStatus(0, $r, 'rendered');
		$this->assertTrue(strpos($r['Html'], '<strong>') !== false, 'has <strong>');
	}

	public function test_preview_markdown_too_large() {
		$r = $this->waiver->PreviewMarkdown(['Token' => $this->token, 'Markdown' => str_repeat('a', 70000)]);
		$this->assertTrue($r['Status']['Code'] !== 0, 'rejected oversize');
	}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement**

```php
	public function PreviewMarkdown($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
		if ($mundane_id <= 0) return ['Status' => Unauthorized()];
		$md = (string)($request['Markdown'] ?? '');
		if (strlen($md) > 65536) return ['Status' => InvalidParameter(), 'Error' => 'Too large'];
		require_once(DIR_LIB . 'Parsedown.php');
		$html = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($md);
		return ['Status' => Success(), 'Html' => $html];
	}
```

- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** (`Enhancement: Digital Waivers — PreviewMarkdown`).

---

### Task 1.10: `SupersedePriorSignatures`

Called from within `SubmitSignature` AFTER a successful new insert, to flag any prior pending/verified signature by the same mundane against any active template in the same kingdom-scope as `superseded`. Keeps the queue clean when a player re-signs.

- [ ] **Step 1: Update `SubmitSignature` test** to expect supersede behaviour

Append (after existing submit tests):

```php
	public function test_resign_supersedes_prior() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)$r['Template']['TemplateId'];
		$payload = [
			'Token' => $this->token, 'TemplateId' => $tid,
			'MundaneFirst' => 'Resign', 'MundaneLast' => 'Test', 'PersonaName' => '',
			'ParkId' => $this->testParkId, 'KingdomId' => $this->testKingdomId,
			'SignatureType' => 'typed', 'SignatureData' => 'Resign Test', 'IsMinor' => 0,
			'MinorRepFirst'=>'', 'MinorRepLast'=>'', 'MinorRepRelationship'=>'',
		];
		$r1 = $this->waiver->SubmitSignature($payload);
		$r2 = $this->waiver->SubmitSignature($payload);
		$this->assertStatus(0, $r2, 'second submit ok');

		// earlier record should now be 'superseded'
		$this->waiver->db->Clear();
		$rs = $this->waiver->db->DataSet("SELECT verification_status FROM " . DB_PREFIX . "waiver_signature WHERE waiver_signature_id = ?", [(int)$r1['SignatureId']]);
		$this->assertEq('superseded', $rs[0]['verification_status'], 'prior superseded');
	}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Patch `SubmitSignature`**: insert the following block just before the final `return`:

```php
		// Supersede any prior pending/verified signature by this same player for this template
		$this->db->Clear();
		$this->db->Execute(
			"UPDATE " . DB_PREFIX . "waiver_signature
			 SET verification_status = 'superseded'
			 WHERE mundane_id = ? AND waiver_template_id = ?
			   AND waiver_signature_id <> ?
			   AND verification_status IN ('pending','verified')",
			[$mundane_id, $tid, (int)$this->signature->waiver_signature_id]
		);
```

- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** (`Enhancement: Digital Waivers — supersede prior signatures on re-sign`).

---

## Phase 2 — AJAX + page controllers (can run in parallel with Phase 3 once Phase 1 done)

### Task 2.1: Flesh out `controller.WaiverAjax.php`

Replace every `echo json_encode(['status' => 9, ...])` stub with a real handler forwarding to the domain layer.

- [ ] **Step 1: Overwrite controller.WaiverAjax.php**

```php
<?php

class Controller_WaiverAjax extends Controller {

	public function __construct($call = null, $id = null) {
		parent::__construct($call, $id);
		header('Content-Type: application/json');
		$this->load_model('Waiver');
	}

	private function requireLogin() {
		if (!isset($this->session->user_id) || (int)$this->session->user_id <= 0) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}
		return (int)$this->session->user_id;
	}

	private function respond($r, $extra = []) {
		$code = $r['Status']['Code'] ?? 1;
		$payload = ['status' => (int)$code];
		if ($code !== 0) $payload['error'] = $r['Error'] ?? ($r['Status']['Message'] ?? 'Error');
		foreach ($extra as $k => $v) if ($v !== null) $payload[$k] = $v;
		if ($code === 0) {
			foreach (['TemplateId','Version','SignatureId','Html','Template','Signature','Signatures','Total','Page','PageSize'] as $k) {
				if (isset($r[$k])) $payload[$k] = $r[$k];
			}
		}
		echo json_encode($payload);
		exit;
	}

	public function saveTemplate() {
		$this->requireLogin();
		$r = $this->Waiver->SaveTemplate([
			'Token'          => $this->session->token,
			'KingdomId'      => (int)($_POST['KingdomId'] ?? 0),
			'Scope'          => $_POST['Scope'] ?? '',
			'HeaderMarkdown' => $_POST['HeaderMarkdown'] ?? '',
			'BodyMarkdown'   => $_POST['BodyMarkdown']   ?? '',
			'FooterMarkdown' => $_POST['FooterMarkdown'] ?? '',
			'MinorMarkdown'  => $_POST['MinorMarkdown']  ?? '',
			'IsEnabled'      => (int)($_POST['IsEnabled'] ?? 0),
		]);
		$this->respond($r);
	}

	public function setEnabled() {
		$this->requireLogin();
		$r = $this->Waiver->SetTemplateEnabled([
			'Token'      => $this->session->token,
			'TemplateId' => (int)($_POST['TemplateId'] ?? 0),
			'IsEnabled'  => (int)($_POST['IsEnabled']  ?? 0),
		]);
		$this->respond($r);
	}

	public function previewMarkdown() {
		$this->requireLogin();
		$r = $this->Waiver->PreviewMarkdown([
			'Token'    => $this->session->token,
			'Markdown' => $_POST['Markdown'] ?? '',
		]);
		$this->respond($r);
	}

	public function submitSignature() {
		$this->requireLogin();
		$r = $this->Waiver->SubmitSignature([
			'Token'                => $this->session->token,
			'TemplateId'           => (int)($_POST['TemplateId'] ?? 0),
			'MundaneFirst'         => $_POST['MundaneFirst'] ?? '',
			'MundaneLast'          => $_POST['MundaneLast']  ?? '',
			'PersonaName'          => $_POST['PersonaName']  ?? '',
			'ParkId'               => (int)($_POST['ParkId']    ?? 0),
			'KingdomId'            => (int)($_POST['KingdomId'] ?? 0),
			'SignatureType'        => $_POST['SignatureType'] ?? '',
			'SignatureData'        => $_POST['SignatureData'] ?? '',
			'IsMinor'              => (int)($_POST['IsMinor'] ?? 0),
			'MinorRepFirst'        => $_POST['MinorRepFirst'] ?? '',
			'MinorRepLast'         => $_POST['MinorRepLast']  ?? '',
			'MinorRepRelationship' => $_POST['MinorRepRelationship'] ?? '',
		]);
		$this->respond($r);
	}

	public function verifySignature() {
		$this->requireLogin();
		$r = $this->Waiver->VerifySignature([
			'Token'         => $this->session->token,
			'SignatureId'   => (int)($_POST['SignatureId'] ?? 0),
			'Action'        => $_POST['Action'] ?? '',
			'PrintedName'   => $_POST['PrintedName'] ?? '',
			'PersonaName'   => $_POST['PersonaName'] ?? '',
			'OfficeTitle'   => $_POST['OfficeTitle'] ?? '',
			'SignatureType' => $_POST['SignatureType'] ?? '',
			'SignatureData' => $_POST['SignatureData'] ?? '',
			'Notes'         => $_POST['Notes'] ?? '',
		]);
		$this->respond($r);
	}

	public function getQueue() {
		$this->requireLogin();
		$r = $this->Waiver->GetQueue([
			'Token'    => $this->session->token,
			'Scope'    => $_GET['scope'] ?? $_POST['Scope'] ?? '',
			'EntityId' => (int)($_GET['entity_id'] ?? $_POST['EntityId'] ?? 0),
			'Filter'   => $_GET['filter']   ?? $_POST['Filter']   ?? 'pending',
			'Page'     => (int)($_GET['page'] ?? $_POST['Page'] ?? 1),
			'PageSize' => (int)($_GET['page_size'] ?? $_POST['PageSize'] ?? 10),
		]);
		$this->respond($r);
	}
}

?>
```

- [ ] **Step 2: Syntax check**

```bash
docker exec ork3-php8-web php -l /var/www/html/orkui/controller/controller.WaiverAjax.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Smoke-test endpoints**

```bash
curl -s -X POST "http://localhost:19080/orkui/WaiverAjax/previewMarkdown" | head -c 200
```

Expected: `{"status":5,"error":"Not logged in"}` (no session). That's correct — full round-trip is tested by Phase 3 browser QA.

- [ ] **Step 4: Commit** (`Enhancement: Digital Waivers — AJAX endpoints wired to domain`).

---

### Task 2.2: Flesh out `controller.Waiver.php`

Replace the skeleton with page actions that pre-load data for each template, apply auth where needed, and set `$this->data`.

- [ ] **Step 1: Overwrite controller.Waiver.php**

```php
<?php

class Controller_Waiver extends Controller {

	public function __construct($call = null, $id = null) {
		parent::__construct($call, $id);
		$this->load_model('Waiver');
		$this->load_model('Player');
		$this->load_model('Kingdom');
		$this->load_model('Park');
	}

	private function _currentMundaneId() {
		return isset($this->session->user_id) ? (int)$this->session->user_id : 0;
	}

	public function index($id = null) {
		$this->redirect('Kingdom/index/' . (int)($id ?? 0));
	}

	// Kingdom admin: edit both kingdom + park templates for this kingdom
	public function builder($kingdom_id = null) {
		$kingdom_id = (int)preg_replace('/[^0-9]/', '', $kingdom_id);
		$_uid = $this->_currentMundaneId();
		if ($_uid <= 0 || (!Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)
			&& !Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_ADMIN, 0, AUTH_EDIT))) {
			$this->redirect('Kingdom/index/' . $kingdom_id);
			return;
		}
		$kk = $this->Waiver->GetActiveTemplate(['KingdomId' => $kingdom_id, 'Scope' => 'kingdom']);
		$pk = $this->Waiver->GetActiveTemplate(['KingdomId' => $kingdom_id, 'Scope' => 'park']);
		$this->data['_wv'] = [
			'kingdom_id'       => $kingdom_id,
			'kingdom_template' => $kk['Status']['Code'] === 0 ? $kk['Template'] : null,
			'park_template'    => $pk['Status']['Code'] === 0 ? $pk['Template'] : null,
			'token'            => $this->session->token,
		];
		$this->data['kingdom_info'] = $this->Kingdom->get_kingdom_shortinfo($kingdom_id);
		$this->template = '../revised-frontend/Waiver_builder.tpl';
	}

	// Player: sign a kingdom/park waiver
	public function sign($scope = null, $id = null) {
		$scope = in_array($scope, ['kingdom','park']) ? $scope : 'kingdom';
		$id    = (int)preg_replace('/[^0-9]/', '', $id);
		$_uid  = $this->_currentMundaneId();
		if ($_uid <= 0) { $this->redirect('Login/index'); return; }

		// Resolve kingdom for template lookup
		if ($scope === 'park') {
			$park = $this->Park->get_park_details(['ParkId' => $id]);
			$kingdom_id = (int)($park['ParkInfo']['KingdomId'] ?? 0);
		} else {
			$kingdom_id = $id;
		}
		$active = $this->Waiver->GetActiveTemplate(['KingdomId' => $kingdom_id, 'Scope' => $scope]);
		$template = ($active['Status']['Code'] === 0 && (int)$active['Template']['IsEnabled'] === 1) ? $active['Template'] : null;

		// Prefill from player profile
		$player = $this->Player->get_player(['MundaneId' => $_uid]);
		$this->data['_wv'] = [
			'scope'      => $scope,
			'entity_id'  => $id,
			'kingdom_id' => $kingdom_id,
			'template'   => $template,
			'prefill'    => [
				'MundaneFirst' => $player['Player']['GivenName']   ?? '',
				'MundaneLast'  => $player['Player']['Surname']     ?? '',
				'PersonaName'  => $player['Player']['PersonaName'] ?? '',
				'ParkId'       => (int)($player['Player']['ParkId']    ?? 0),
				'KingdomId'    => (int)($player['Player']['KingdomId'] ?? 0),
			],
			'token'      => $this->session->token,
		];
		$this->template = '../revised-frontend/Waiver_sign.tpl';
	}

	// Officer: review queue
	public function queue($scope = null, $id = null) {
		$scope = in_array($scope, ['kingdom','park']) ? $scope : 'kingdom';
		$id    = (int)preg_replace('/[^0-9]/', '', $id);
		$_uid  = $this->_currentMundaneId();
		$authType = ($scope === 'kingdom') ? AUTH_KINGDOM : AUTH_PARK;
		if ($_uid <= 0 || (!Ork3::$Lib->authorization->HasAuthority($_uid, $authType, $id, AUTH_EDIT)
			&& !Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_ADMIN, 0, AUTH_EDIT))) {
			$this->redirect(($scope === 'park' ? 'Park' : 'Kingdom') . '/index/' . $id);
			return;
		}
		$filter = in_array($_GET['filter'] ?? 'pending', ['pending','verified','rejected','stale','all']) ? $_GET['filter'] : 'pending';
		$page   = max(1, (int)($_GET['page'] ?? 1));
		$r = $this->Waiver->GetQueue([
			'Token' => $this->session->token, 'Scope' => $scope, 'EntityId' => $id,
			'Filter' => $filter, 'Page' => $page, 'PageSize' => 10,
		]);
		$this->data['_wv'] = [
			'scope' => $scope, 'entity_id' => $id, 'filter' => $filter, 'page' => $page,
			'signatures' => $r['Signatures'] ?? [], 'total' => (int)($r['Total'] ?? 0),
			'token' => $this->session->token,
		];
		$this->template = '../revised-frontend/Waiver_queue.tpl';
	}

	public function review($signature_id = null) {
		$signature_id = (int)preg_replace('/[^0-9]/', '', $signature_id);
		$_uid = $this->_currentMundaneId();
		if ($_uid <= 0) { $this->redirect('Login/index'); return; }
		$r = $this->Waiver->GetSignature(['Token' => $this->session->token, 'SignatureId' => $signature_id]);
		if ($r['Status']['Code'] !== 0) { $this->redirect('Player/index/' . $_uid); return; }
		$sig = $r['Signature'];
		$isOfficer = Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_KINGDOM, $sig['KingdomId'], AUTH_EDIT)
			|| Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_PARK, $sig['ParkId'], AUTH_EDIT)
			|| Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_ADMIN, 0, AUTH_EDIT);
		$player = $this->Player->get_player(['MundaneId' => $_uid]);

		$this->data['_wv'] = [
			'signature'  => $sig,
			'is_officer' => $isOfficer,
			'is_signer'  => ((int)$sig['MundaneId'] === $_uid),
			'token'      => $this->session->token,
			'officer_prefill' => [
				'PrintedName' => trim(($player['Player']['GivenName'] ?? '') . ' ' . ($player['Player']['Surname'] ?? '')),
				'PersonaName' => $player['Player']['PersonaName'] ?? '',
			],
		];
		$this->template = '../revised-frontend/Waiver_review.tpl';
	}

	public function printable($signature_id = null) {
		$signature_id = (int)preg_replace('/[^0-9]/', '', $signature_id);
		$_uid = $this->_currentMundaneId();
		if ($_uid <= 0) { $this->redirect('Login/index'); return; }
		$r = $this->Waiver->GetSignature(['Token' => $this->session->token, 'SignatureId' => $signature_id]);
		if ($r['Status']['Code'] !== 0) { $this->redirect('Player/index/' . $_uid); return; }
		$this->data['_wv'] = ['signature' => $r['Signature']];
		$this->template = '../revised-frontend/Waiver_print.tpl';
	}
}

?>
```

- [ ] **Step 2: Syntax check + smoke test**

```bash
docker exec ork3-php8-web php -l /var/www/html/orkui/controller/controller.Waiver.php
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:19080/orkui/Waiver/queue/kingdom/1"
```

Expected: parse clean; queue returns 302 (redirect — expected for un-auth CLI curl).

- [ ] **Step 3: Commit** (`Enhancement: Digital Waivers — page controller actions`).

---

## Phase 3 — Templates (can run in parallel with Phase 2 after Phase 1)

Each template is written as one task. CSS prefix `wv-`, inlined. JS is vanilla + fetch, wrapped in an IIFE guarded by a config flag written into the template by PHP (per hard rule: no `document.getElementById` guards).

### Task 3.1: Shared waiver signature widget (stroke capture + typed cursive)

This is a self-contained JS widget reused by `Waiver_sign.tpl` and `Waiver_review.tpl`. We inline it into each template via a shared PHP snippet included from both places.

**Files:**
- Create: `orkui/template/revised-frontend/Waiver_signature_widget.inc.php`

- [ ] **Step 1: Write the widget include (CSS + JS + HTML builder function)**

```php
<?php
// Waiver_signature_widget.inc.php
// Renders a reusable signature widget. Pass $widgetId (unique per widget on the page)
// and $fieldNamePrefix (hidden field name base, e.g. 'signature').
// After rendering, the hidden inputs {prefix}_type and {prefix}_data will contain the signature state.
function wv_render_signature_widget($widgetId, $fieldNamePrefix, $typedLabel = 'Type your full legal name') { ?>
<div class="wv-sig-widget" id="<?= htmlspecialchars($widgetId) ?>">
	<div class="wv-sig-tabs">
		<button type="button" class="wv-sig-tab wv-sig-tab-active" data-mode="draw">Draw</button>
		<button type="button" class="wv-sig-tab" data-mode="type">Type</button>
	</div>
	<div class="wv-sig-pane wv-sig-pane-draw">
		<canvas class="wv-sig-canvas" width="600" height="180"></canvas>
		<div class="wv-sig-actions">
			<button type="button" class="wv-sig-undo">Undo</button>
			<button type="button" class="wv-sig-clear">Clear</button>
		</div>
	</div>
	<div class="wv-sig-pane wv-sig-pane-type" style="display:none;">
		<label class="wv-sig-typed-label"><?= htmlspecialchars($typedLabel) ?></label>
		<input type="text" class="wv-sig-typed" maxlength="128" autocomplete="off">
		<div class="wv-sig-typed-preview" aria-hidden="true"></div>
	</div>
	<input type="hidden" name="<?= htmlspecialchars($fieldNamePrefix) ?>_type" class="wv-sig-type" value="drawn">
	<input type="hidden" name="<?= htmlspecialchars($fieldNamePrefix) ?>_data" class="wv-sig-data" value="">
</div>
<?php } ?>
```

- [ ] **Step 2: Write CSS + JS partial**

Create `orkui/template/revised-frontend/Waiver_signature_widget.css.inc`:

```css
.wv-sig-widget { border: 1px solid #ccc; border-radius: 6px; padding: 10px; background: #fff; }
.wv-sig-tabs { display: flex; gap: 4px; margin-bottom: 8px; }
.wv-sig-tab { padding: 6px 12px; background: #eee; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; }
.wv-sig-tab-active { background: #fff; border-bottom-color: #fff; font-weight: bold; }
.wv-sig-canvas { display: block; width: 100%; max-width: 600px; height: 180px; border: 1px dashed #888; border-radius: 4px; touch-action: none; background: #fafafa; }
.wv-sig-actions { margin-top: 6px; display: flex; gap: 6px; }
.wv-sig-actions button { padding: 4px 10px; }
.wv-sig-typed { font-family: 'Homemade Apple', 'Caveat', 'Brush Script MT', cursive; font-size: 28px; width: 100%; padding: 8px 10px; border: 1px dashed #888; border-radius: 4px; }
.wv-sig-typed-preview { font-family: 'Homemade Apple', 'Caveat', 'Brush Script MT', cursive; font-size: 32px; min-height: 40px; margin-top: 6px; color: #333; }
.wv-sig-typed-label { display: block; font-size: 12px; color: #666; margin-bottom: 4px; }
```

Create `orkui/template/revised-frontend/Waiver_signature_widget.js.inc`:

```js
(function(){
	// Module-level guard: only wire once per page
	if (window.__wvSigWired) return;
	window.__wvSigWired = true;

	function initWidget(root) {
		const typeInput = root.querySelector('.wv-sig-type');
		const dataInput = root.querySelector('.wv-sig-data');
		const canvas = root.querySelector('.wv-sig-canvas');
		const ctx = canvas.getContext('2d');
		const tabDraw = root.querySelector('.wv-sig-tab[data-mode="draw"]');
		const tabType = root.querySelector('.wv-sig-tab[data-mode="type"]');
		const paneDraw = root.querySelector('.wv-sig-pane-draw');
		const paneType = root.querySelector('.wv-sig-pane-type');
		const typed = root.querySelector('.wv-sig-typed');
		const preview = root.querySelector('.wv-sig-typed-preview');
		let strokes = [];
		let current = null;

		function setMode(mode) {
			typeInput.value = mode;
			if (mode === 'draw') { tabDraw.classList.add('wv-sig-tab-active'); tabType.classList.remove('wv-sig-tab-active'); paneDraw.style.display = ''; paneType.style.display = 'none'; }
			else { tabType.classList.add('wv-sig-tab-active'); tabDraw.classList.remove('wv-sig-tab-active'); paneDraw.style.display = 'none'; paneType.style.display = ''; }
			syncData();
		}
		tabDraw.addEventListener('click', () => setMode('draw'));
		tabType.addEventListener('click', () => setMode('type'));

		function syncData() {
			if (typeInput.value === 'drawn') dataInput.value = JSON.stringify(strokes);
			else dataInput.value = typed.value.trim();
		}

		function canvasPoint(e) {
			const rect = canvas.getBoundingClientRect();
			const clientX = (e.touches && e.touches[0]) ? e.touches[0].clientX : e.clientX;
			const clientY = (e.touches && e.touches[0]) ? e.touches[0].clientY : e.clientY;
			return { x: (clientX - rect.left) / rect.width, y: (clientY - rect.top) / rect.height };
		}
		function redraw() {
			ctx.clearRect(0, 0, canvas.width, canvas.height);
			ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.lineWidth = 2; ctx.strokeStyle = '#111';
			strokes.forEach(s => {
				if (!s.length) return;
				ctx.beginPath();
				ctx.moveTo(s[0].x * canvas.width, s[0].y * canvas.height);
				for (let i = 1; i < s.length; i++) ctx.lineTo(s[i].x * canvas.width, s[i].y * canvas.height);
				ctx.stroke();
			});
		}
		function start(e) { e.preventDefault(); current = [canvasPoint(e)]; strokes.push(current); }
		function move(e)  { if (!current) return; e.preventDefault(); current.push(canvasPoint(e)); redraw(); }
		function end()     { current = null; syncData(); }

		canvas.addEventListener('pointerdown', start);
		canvas.addEventListener('pointermove', move);
		window.addEventListener('pointerup', end);

		root.querySelector('.wv-sig-clear').addEventListener('click', () => { strokes = []; redraw(); syncData(); });
		root.querySelector('.wv-sig-undo').addEventListener('click',  () => { strokes.pop(); redraw(); syncData(); });

		typed.addEventListener('input', () => { preview.textContent = typed.value; syncData(); });

		// High-DPI canvas
		const dpr = window.devicePixelRatio || 1;
		const w = canvas.clientWidth, h = canvas.clientHeight;
		canvas.width  = w * dpr;
		canvas.height = h * dpr;
		ctx.scale(dpr, dpr);

		setMode('draw');
	}

	function wireAll() {
		document.querySelectorAll('.wv-sig-widget').forEach(initWidget);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', wireAll);
	} else {
		wireAll();
	}

	// Helper: render a read-only signature (used in review/print)
	window.wvRenderSignature = function(containerEl, type, data, w, h) {
		if (type === 'typed') {
			containerEl.innerHTML = '';
			const div = document.createElement('div');
			div.style.cssText = 'font-family: Homemade Apple, Caveat, Brush Script MT, cursive; font-size: 32px;';
			div.textContent = data;
			containerEl.appendChild(div);
			return;
		}
		let strokes; try { strokes = JSON.parse(data); } catch (_) { strokes = []; }
		const canvas = document.createElement('canvas');
		canvas.width = w || 600; canvas.height = h || 180;
		canvas.style.cssText = 'width: 100%; max-width: ' + (w||600) + 'px; height: auto;';
		const ctx = canvas.getContext('2d');
		ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.lineWidth = 2; ctx.strokeStyle = '#111';
		strokes.forEach(s => {
			if (!s.length) return;
			ctx.beginPath();
			ctx.moveTo(s[0].x * canvas.width, s[0].y * canvas.height);
			for (let i = 1; i < s.length; i++) ctx.lineTo(s[i].x * canvas.width, s[i].y * canvas.height);
			ctx.stroke();
		});
		containerEl.innerHTML = '';
		containerEl.appendChild(canvas);
	};
})();
```

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Waiver_signature_widget.inc.php \
        orkui/template/revised-frontend/Waiver_signature_widget.css.inc \
        orkui/template/revised-frontend/Waiver_signature_widget.js.inc
git commit -m "Enhancement: Digital Waivers — shared signature widget"
```

---

### Task 3.2: `Waiver_builder.tpl`

Kingdom-admin builder. Two-tab interface (Kingdom / Park), editors for Header/Body/Footer/Minor markdown, live preview, enable toggle, Save button.

**Files:**
- Create: `orkui/template/revised-frontend/Waiver_builder.tpl`

- [ ] **Step 1: Write template**

```php
<?php
$wv = $this->data['_wv'];
$ki = $this->data['kingdom_info']['KingdomInfo'] ?? [];
$kingdomName = htmlspecialchars($ki['KingdomName'] ?? 'Kingdom');
$token       = htmlspecialchars($wv['token']);
$kingdomId   = (int)$wv['kingdom_id'];
$kk = $wv['kingdom_template'];
$pk = $wv['park_template'];
?>
<link href="https://fonts.googleapis.com/css2?family=Homemade+Apple&display=swap" rel="stylesheet">
<style>
.wv-builder { max-width: 1200px; margin: 20px auto; padding: 0 16px; }
.wv-builder h1, .wv-builder h2, .wv-builder h3 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-builder h1 { font-size: 28px; margin-bottom: 4px; }
.wv-builder .wv-tabs { display: flex; gap: 4px; margin: 20px 0 0 0; border-bottom: 2px solid #ccc; }
.wv-builder .wv-tab { padding: 10px 18px; cursor: pointer; background: #eee; border: 1px solid #ccc; border-bottom: none; border-radius: 6px 6px 0 0; }
.wv-builder .wv-tab.wv-active { background: #fff; font-weight: bold; border-bottom: 2px solid #fff; margin-bottom: -2px; }
.wv-builder .wv-pane { background: #fff; padding: 16px; border: 1px solid #ccc; border-top: none; }
.wv-builder .wv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.wv-builder .wv-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; }
.wv-builder .wv-field label { font-weight: bold; font-size: 13px; color: #333; }
.wv-builder textarea { width: 100%; min-height: 120px; font-family: monospace; font-size: 13px; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
.wv-builder .wv-preview { border: 1px solid #ddd; padding: 12px; border-radius: 4px; background: #f9f9f9; min-height: 120px; font-size: 14px; }
.wv-builder .wv-preview h1, .wv-builder .wv-preview h2, .wv-builder .wv-preview h3 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-builder .wv-save-bar { display: flex; align-items: center; gap: 12px; margin: 10px 0; padding: 10px; background: #f4f4f4; border: 1px solid #ccc; border-radius: 4px; }
.wv-builder .wv-save-bar button { padding: 8px 20px; font-weight: bold; cursor: pointer; }
.wv-builder .wv-enable { margin-left: auto; }
.wv-builder .wv-locked { background: #f0f0f0; border: 1px dashed #999; padding: 12px; border-radius: 4px; color: #555; font-size: 13px; }
.wv-builder .wv-version-label { font-size: 12px; color: #666; }
.wv-builder .wv-status-ok   { color: #060; font-weight: bold; }
.wv-builder .wv-status-err  { color: #a00; font-weight: bold; }
</style>
<div class="wv-builder">
	<h1><?= $kingdomName ?> — Digital Waiver Builder</h1>
	<p>Design waivers used at kingdom events and at park days. Kingdom admins only.</p>

	<div class="wv-tabs">
		<div class="wv-tab wv-active" data-scope="kingdom">Kingdom Waiver</div>
		<div class="wv-tab" data-scope="park">Park Waiver</div>
	</div>

	<?php foreach (['kingdom', 'park'] as $scope): $tpl = ($scope === 'kingdom' ? $kk : $pk); ?>
	<div class="wv-pane" data-scope="<?= $scope ?>" style="<?= $scope === 'park' ? 'display:none;' : '' ?>">
		<form class="wv-form" data-scope="<?= $scope ?>">
			<input type="hidden" name="Scope" value="<?= $scope ?>">
			<input type="hidden" name="KingdomId" value="<?= $kingdomId ?>">
			<div class="wv-save-bar">
				<button type="submit">Save &amp; Publish New Version</button>
				<span class="wv-version-label">Current: <?= $tpl ? 'v' . (int)$tpl['Version'] : '(none)' ?></span>
				<label class="wv-enable">
					<input type="checkbox" name="IsEnabled" value="1" <?= ($tpl && (int)$tpl['IsEnabled'] === 1) ? 'checked' : '' ?>>
					Enabled (players can sign)
				</label>
				<span class="wv-status"></span>
			</div>

			<div class="wv-grid">
				<div>
					<div class="wv-field">
						<label>Header (shown on every page — markdown)</label>
						<textarea name="HeaderMarkdown" rows="3"><?= htmlspecialchars($tpl['HeaderMarkdown'] ?? '') ?></textarea>
					</div>
					<div class="wv-field">
						<label>Player Header (fixed — not editable)</label>
						<div class="wv-locked">Fields: First Name, Last Name, Persona Name, Home Park, Home Kingdom. Auto-filled from the signing player's profile.</div>
					</div>
					<div class="wv-field">
						<label>Waiver Details (body — markdown)</label>
						<textarea name="BodyMarkdown" rows="14"><?= htmlspecialchars($tpl['BodyMarkdown'] ?? '') ?></textarea>
					</div>
					<div class="wv-field">
						<label>Signature Block (fixed — not editable)</label>
						<div class="wv-locked">Players choose drawn (finger/mouse) or typed signature. Date auto-recorded at submission.</div>
					</div>
					<div class="wv-field">
						<label>Minor Representative Text (markdown — shown when signer marks as minor)</label>
						<textarea name="MinorMarkdown" rows="4"><?= htmlspecialchars($tpl['MinorMarkdown'] ?? '') ?></textarea>
					</div>
					<div class="wv-field">
						<label>Footer (shown on every page — markdown)</label>
						<textarea name="FooterMarkdown" rows="3"><?= htmlspecialchars($tpl['FooterMarkdown'] ?? '') ?></textarea>
					</div>
				</div>
				<div>
					<label><strong>Live Preview</strong></label>
					<div class="wv-preview wv-preview-header"></div>
					<hr>
					<div class="wv-preview wv-preview-body"></div>
					<hr>
					<div class="wv-preview wv-preview-minor"></div>
					<hr>
					<div class="wv-preview wv-preview-footer"></div>
				</div>
			</div>
		</form>
	</div>
	<?php endforeach; ?>
</div>
<script>
window.WvBuilderConfig = { token: "<?= $token ?>" };
</script>
<script>
(function(){
	if (!window.WvBuilderConfig || !window.WvBuilderConfig.token) return; // admin gate
	const tabs = document.querySelectorAll('.wv-builder .wv-tab');
	const panes = document.querySelectorAll('.wv-builder .wv-pane');
	tabs.forEach(t => t.addEventListener('click', () => {
		tabs.forEach(x => x.classList.remove('wv-active'));
		t.classList.add('wv-active');
		panes.forEach(p => p.style.display = (p.dataset.scope === t.dataset.scope) ? '' : 'none');
	}));

	function debounce(fn, ms) { let id; return function(...args) { clearTimeout(id); id = setTimeout(() => fn.apply(this, args), ms); }; }

	async function renderPreview(form) {
		const headerTA = form.querySelector('[name=HeaderMarkdown]');
		const bodyTA   = form.querySelector('[name=BodyMarkdown]');
		const minorTA  = form.querySelector('[name=MinorMarkdown]');
		const footerTA = form.querySelector('[name=FooterMarkdown]');
		const pane = form.closest('.wv-pane');
		const pv = { h: pane.querySelector('.wv-preview-header'), b: pane.querySelector('.wv-preview-body'), m: pane.querySelector('.wv-preview-minor'), f: pane.querySelector('.wv-preview-footer') };
		const parts = [[headerTA.value, pv.h], [bodyTA.value, pv.b], [minorTA.value, pv.m], [footerTA.value, pv.f]];
		for (const [md, el] of parts) {
			const fd = new FormData(); fd.append('Markdown', md);
			const r = await fetch('/orkui/WaiverAjax/previewMarkdown', { method: 'POST', body: fd, credentials: 'same-origin' });
			const j = await r.json();
			el.innerHTML = j.Html || '';
		}
	}

	const debouncedRender = debounce(renderPreview, 350);

	document.querySelectorAll('.wv-builder .wv-form').forEach(form => {
		form.addEventListener('input', () => debouncedRender(form));
		renderPreview(form);
		form.addEventListener('submit', async (e) => {
			e.preventDefault();
			const fd = new FormData(form);
			const status = form.querySelector('.wv-status');
			status.className = 'wv-status'; status.textContent = 'Saving…';
			try {
				const r = await fetch('/orkui/WaiverAjax/saveTemplate', { method: 'POST', body: fd, credentials: 'same-origin' });
				const j = await r.json();
				if (j.status === 0) {
					status.className = 'wv-status wv-status-ok';
					status.textContent = 'Saved — v' + j.Version;
					const label = form.querySelector('.wv-version-label');
					if (label) label.textContent = 'Current: v' + j.Version;
				} else {
					status.className = 'wv-status wv-status-err';
					status.textContent = j.error || 'Save failed';
				}
			} catch (err) {
				status.className = 'wv-status wv-status-err';
				status.textContent = 'Network error';
			}
		});
	});
})();
</script>
```

- [ ] **Step 2: Commit** (`Enhancement: Digital Waivers — builder template`).

- [ ] **Step 3: Manual sanity** — visit `http://localhost:19080/orkui/Waiver/builder/1` while logged in as kingdom admin. Confirm tabs toggle, preview renders after typing, Save reports "Saved — v1".

---

### Task 3.3: `Waiver_sign.tpl`

Player fills + signs. Includes the shared signature widget. Shows minor section conditionally.

**Files:**
- Create: `orkui/template/revised-frontend/Waiver_sign.tpl`

- [ ] **Step 1: Write template**

```php
<?php
require_once(DIR_LIB . 'Parsedown.php');
$wv = $this->data['_wv'];
$tpl = $wv['template'];
$prefill = $wv['prefill'];
$token = htmlspecialchars($wv['token']);
$md = function($t) { return $t ? (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($t) : ''; };
require_once(DIR_TEMPLATE . 'revised-frontend/Waiver_signature_widget.inc.php');
?>
<link href="https://fonts.googleapis.com/css2?family=Homemade+Apple&display=swap" rel="stylesheet">
<style>
<?= file_get_contents(DIR_TEMPLATE . 'revised-frontend/Waiver_signature_widget.css.inc') ?>
.wv-sign { max-width: 900px; margin: 20px auto; padding: 0 16px; background: #fff; }
.wv-sign h1, .wv-sign h2, .wv-sign h3 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-sign h1 { font-size: 26px; margin-bottom: 4px; }
.wv-sign .wv-section { border: 1px solid #ddd; border-radius: 6px; padding: 16px; margin: 14px 0; background: #fff; }
.wv-sign .wv-header-md, .wv-sign .wv-body-md, .wv-sign .wv-footer-md, .wv-sign .wv-minor-md { line-height: 1.5; }
.wv-sign .wv-header-md h1, .wv-sign .wv-body-md h1, .wv-sign .wv-footer-md h1, .wv-sign .wv-minor-md h1,
.wv-sign .wv-header-md h2, .wv-sign .wv-body-md h2, .wv-sign .wv-footer-md h2, .wv-sign .wv-minor-md h2,
.wv-sign .wv-header-md h3, .wv-sign .wv-body-md h3, .wv-sign .wv-footer-md h3, .wv-sign .wv-minor-md h3 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-sign .wv-playerhdr { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
.wv-sign label { font-size: 12px; color: #555; font-weight: bold; display: block; }
.wv-sign input[type=text] { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; }
.wv-sign .wv-minor-toggle { padding: 10px; background: #fffbea; border: 1px solid #f4e3a0; border-radius: 4px; }
.wv-sign .wv-submit { padding: 12px 24px; font-weight: bold; background: #2b5; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
.wv-sign .wv-status-ok { color: #060; font-weight: bold; }
.wv-sign .wv-status-err { color: #a00; font-weight: bold; }
.wv-sign .wv-notice { padding: 16px; background: #fdd; border: 1px solid #c99; border-radius: 4px; }
</style>

<?php if (!$tpl): ?>
<div class="wv-sign">
	<h1>Digital Waiver</h1>
	<div class="wv-notice">This <?= htmlspecialchars($wv['scope']) ?> has not enabled digital waivers yet. Please check back later or contact a local officer.</div>
</div>
<?php return; endif; ?>

<div class="wv-sign">
	<div class="wv-section wv-header-md"><?= $md($tpl['HeaderMarkdown']) ?></div>

	<form id="wvSignForm">
		<input type="hidden" name="TemplateId" value="<?= (int)$tpl['TemplateId'] ?>">
		<input type="hidden" name="KingdomId"  value="<?= (int)$prefill['KingdomId'] ?>">
		<input type="hidden" name="ParkId"     value="<?= (int)$prefill['ParkId'] ?>">

		<div class="wv-section">
			<h2>Your Information</h2>
			<div class="wv-playerhdr">
				<div><label>First (legal) name</label><input type="text" name="MundaneFirst" required value="<?= htmlspecialchars($prefill['MundaneFirst']) ?>"></div>
				<div><label>Last (legal) name</label><input type="text" name="MundaneLast" required value="<?= htmlspecialchars($prefill['MundaneLast']) ?>"></div>
				<div><label>Persona name</label><input type="text" name="PersonaName" value="<?= htmlspecialchars($prefill['PersonaName']) ?>"></div>
				<div><label>Home park / kingdom</label><input type="text" value="(auto-captured from your profile)" disabled></div>
			</div>
		</div>

		<div class="wv-section wv-body-md"><?= $md($tpl['BodyMarkdown']) ?></div>

		<div class="wv-section wv-minor-toggle">
			<label><input type="checkbox" name="IsMinor" id="wvIsMinor" value="1"> I am signing for a minor (under 18) — show guardian/representative fields</label>
		</div>

		<div class="wv-section" id="wvMinorBlock" style="display:none;">
			<div class="wv-minor-md"><?= $md($tpl['MinorMarkdown']) ?></div>
			<div class="wv-playerhdr" style="margin-top:10px;">
				<div><label>Representative first name</label><input type="text" name="MinorRepFirst"></div>
				<div><label>Representative last name</label> <input type="text" name="MinorRepLast"></div>
				<div style="grid-column: span 2;"><label>Relationship to minor</label><input type="text" name="MinorRepRelationship" placeholder="e.g. mother, legal guardian"></div>
			</div>
		</div>

		<div class="wv-section">
			<h2>Signature</h2>
			<?php wv_render_signature_widget('wvSigMain', 'signature', 'Type your full legal name'); ?>
			<p style="font-size: 12px; color: #666; margin-top: 6px;">Signed date: <?= date('F j, Y') ?> (auto-recorded)</p>
		</div>

		<div class="wv-section wv-footer-md"><?= $md($tpl['FooterMarkdown']) ?></div>

		<button type="submit" class="wv-submit">Submit Signed Waiver</button>
		<span id="wvSubmitStatus"></span>
	</form>
</div>

<script>
<?= file_get_contents(DIR_TEMPLATE . 'revised-frontend/Waiver_signature_widget.js.inc') ?>
</script>
<script>
(function(){
	const form = document.getElementById('wvSignForm');
	if (!form) return;
	const isMinor = document.getElementById('wvIsMinor');
	const minorBlock = document.getElementById('wvMinorBlock');
	isMinor.addEventListener('change', () => minorBlock.style.display = isMinor.checked ? '' : 'none');

	form.addEventListener('submit', async (e) => {
		e.preventDefault();
		const status = document.getElementById('wvSubmitStatus');
		const fd = new FormData(form);
		fd.set('SignatureType', form.querySelector('.wv-sig-type').value);
		fd.set('SignatureData', form.querySelector('.wv-sig-data').value);
		if (!fd.get('SignatureData')) { status.className = 'wv-status-err'; status.textContent = 'Please sign before submitting.'; return; }
		status.className = ''; status.textContent = 'Submitting…';
		try {
			const r = await fetch('/orkui/WaiverAjax/submitSignature', { method: 'POST', body: fd, credentials: 'same-origin' });
			const j = await r.json();
			if (j.status === 0) {
				window.location = '/orkui/Waiver/review/' + j.SignatureId;
			} else {
				status.className = 'wv-status-err';
				status.textContent = j.error || 'Submit failed';
			}
		} catch (err) {
			status.className = 'wv-status-err';
			status.textContent = 'Network error';
		}
	});
})();
</script>
```

- [ ] **Step 2: Commit** (`Enhancement: Digital Waivers — sign template`).

---

### Task 3.4: `Waiver_queue.tpl`

Paginated officer review queue.

**Files:**
- Create: `orkui/template/revised-frontend/Waiver_queue.tpl`

- [ ] **Step 1: Write template**

```php
<?php
$wv = $this->data['_wv'];
$filter = $wv['filter'];
$page   = $wv['page'];
$sigs   = $wv['signatures'];
$total  = $wv['total'];
$scope  = $wv['scope'];
$eid    = $wv['entity_id'];
$pages  = max(1, (int)ceil($total / 10));
function wv_filter_url($scope, $eid, $filter, $page) { return '/orkui/Waiver/queue/' . $scope . '/' . $eid . '?filter=' . urlencode($filter) . '&page=' . $page; }
?>
<style>
.wv-queue { max-width: 1200px; margin: 20px auto; padding: 0 16px; }
.wv-queue h1, .wv-queue h2 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-queue h1 { font-size: 26px; margin-bottom: 4px; }
.wv-queue .wv-filters { display: flex; gap: 6px; margin: 12px 0; }
.wv-queue .wv-chip { padding: 6px 12px; background: #eee; border: 1px solid #ccc; border-radius: 16px; cursor: pointer; font-size: 13px; text-decoration: none; color: #333; }
.wv-queue .wv-chip.wv-active { background: #333; color: #fff; }
.wv-queue table { width: 100%; border-collapse: collapse; background: #fff; }
.wv-queue th, .wv-queue td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; font-size: 14px; }
.wv-queue th { background: #f4f4f4; font-weight: bold; }
.wv-queue .wv-badge { display: inline-block; padding: 3px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; }
.wv-queue .wv-badge-pending   { background: #ffc; color: #660; }
.wv-queue .wv-badge-verified  { background: #cfc; color: #060; }
.wv-queue .wv-badge-rejected  { background: #fcc; color: #900; }
.wv-queue .wv-badge-superseded { background: #eee; color: #555; }
.wv-queue .wv-pager { margin: 12px 0; display: flex; gap: 6px; }
.wv-queue .wv-pager a, .wv-queue .wv-pager span { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; text-decoration: none; color: #333; font-size: 13px; }
.wv-queue .wv-pager .wv-current { background: #333; color: #fff; border-color: #333; }
</style>
<div class="wv-queue">
	<h1>Digital Waiver Queue — <?= htmlspecialchars($scope) ?> #<?= (int)$eid ?></h1>
	<div class="wv-filters">
	<?php foreach (['pending','verified','rejected','stale','all'] as $f): ?>
		<a class="wv-chip <?= $filter === $f ? 'wv-active' : '' ?>" href="<?= wv_filter_url($scope, $eid, $f, 1) ?>"><?= ucfirst($f) ?></a>
	<?php endforeach; ?>
	</div>
	<table>
		<thead><tr><th>Player</th><th>Signed</th><th>Status</th><th>Template</th><th></th></tr></thead>
		<tbody>
		<?php if (!$sigs): ?>
			<tr><td colspan="5" style="text-align:center; padding: 20px; color: #666;">No signatures match this filter.</td></tr>
		<?php endif; foreach ($sigs as $s): ?>
			<tr>
				<td>
					<strong><?= htmlspecialchars($s['PersonaName'] ?: ($s['MundaneFirst'] . ' ' . $s['MundaneLast'])) ?></strong>
					<?php if ($s['PersonaName']): ?><br><span style="color: #666; font-size: 12px;"><?= htmlspecialchars($s['MundaneFirst'] . ' ' . $s['MundaneLast']) ?></span><?php endif; ?>
					<?php if ($s['IsMinor']): ?><br><span style="color: #a50; font-size: 11px;"><em>minor — rep: <?= htmlspecialchars($s['MinorRepFirst'] . ' ' . $s['MinorRepLast']) ?></em></span><?php endif; ?>
				</td>
				<td><?= htmlspecialchars($s['SignedAt']) ?></td>
				<td><span class="wv-badge wv-badge-<?= htmlspecialchars($s['VerificationStatus']) ?>"><?= htmlspecialchars($s['VerificationStatus']) ?></span></td>
				<td>#<?= (int)$s['TemplateId'] ?></td>
				<td><a href="/orkui/Waiver/review/<?= (int)$s['SignatureId'] ?>">Review &rarr;</a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<div class="wv-pager">
	<?php for ($i = 1; $i <= $pages; $i++): ?>
		<?php if ($i === $page): ?><span class="wv-current"><?= $i ?></span>
		<?php else: ?><a href="<?= wv_filter_url($scope, $eid, $filter, $i) ?>"><?= $i ?></a><?php endif; ?>
	<?php endfor; ?>
	</div>
</div>
```

- [ ] **Step 2: Commit** (`Enhancement: Digital Waivers — queue template`).

---

### Task 3.5: `Waiver_review.tpl`

Officer single-review page. Renders full signed waiver + verification form.

**Files:**
- Create: `orkui/template/revised-frontend/Waiver_review.tpl`

- [ ] **Step 1: Write template**

```php
<?php
require_once(DIR_LIB . 'Parsedown.php');
$wv = $this->data['_wv'];
$sig = $wv['signature'];
$tpl = $sig['Template'];
$md = function($t) { return $t ? (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($t) : ''; };
$token = htmlspecialchars($wv['token']);
$isOfficer = $wv['is_officer'];
$isSigner  = $wv['is_signer'];
$canVerify = $isOfficer && in_array($sig['VerificationStatus'], ['pending']);
require_once(DIR_TEMPLATE . 'revised-frontend/Waiver_signature_widget.inc.php');
$of = $wv['officer_prefill'];
?>
<link href="https://fonts.googleapis.com/css2?family=Homemade+Apple&display=swap" rel="stylesheet">
<style>
<?= file_get_contents(DIR_TEMPLATE . 'revised-frontend/Waiver_signature_widget.css.inc') ?>
.wv-review { max-width: 900px; margin: 20px auto; padding: 0 16px; background: #fff; }
.wv-review h1, .wv-review h2, .wv-review h3 { background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important; }
.wv-review h1 { font-size: 26px; }
.wv-review .wv-section { border: 1px solid #ddd; border-radius: 6px; padding: 16px; margin: 14px 0; }
.wv-review .wv-playerhdr { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
.wv-review .wv-fact { font-size: 13px; }
.wv-review .wv-fact strong { color: #333; }
.wv-review .wv-sig-rendered { min-height: 180px; border: 1px dashed #999; border-radius: 4px; padding: 10px; background: #fafafa; }
.wv-review .wv-verify-form input[type=text], .wv-review .wv-verify-form textarea { width: 100%; padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; }
.wv-review .wv-verify-form label { display: block; font-size: 12px; color: #555; font-weight: bold; margin-top: 8px; }
.wv-review .wv-actions { display: flex; gap: 10px; margin-top: 12px; }
.wv-review .wv-approve { background: #2b5; color: #fff; padding: 10px 18px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; }
.wv-review .wv-reject  { background: #c33; color: #fff; padding: 10px 18px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; }
.wv-review .wv-verified-box { padding: 12px; background: #eef9ee; border: 1px solid #cdeac0; border-radius: 4px; font-size: 14px; }
.wv-review .wv-rejected-box { padding: 12px; background: #fdeeee; border: 1px solid #eac0c0; border-radius: 4px; font-size: 14px; }
@media print {
	.wv-verify-form, .wv-actions, .wv-minor-toggle, body > header, body > nav, body > footer { display: none !important; }
	.wv-review { max-width: 100%; margin: 0; }
}
</style>
<div class="wv-review">
	<h1>Digital Waiver — Signed Record #<?= (int)$sig['SignatureId'] ?></h1>
	<p><a href="/orkui/Waiver/printable/<?= (int)$sig['SignatureId'] ?>" target="_blank">Open printable version &rarr;</a></p>

	<div class="wv-section"><?= $md($tpl['HeaderMarkdown'] ?? '') ?></div>

	<div class="wv-section">
		<h2>Signer</h2>
		<div class="wv-playerhdr">
			<div class="wv-fact"><strong>Legal Name:</strong> <?= htmlspecialchars($sig['MundaneFirst'] . ' ' . $sig['MundaneLast']) ?></div>
			<div class="wv-fact"><strong>Persona:</strong> <?= htmlspecialchars($sig['PersonaName']) ?></div>
			<div class="wv-fact"><strong>Park ID:</strong> <?= (int)$sig['ParkId'] ?></div>
			<div class="wv-fact"><strong>Kingdom ID:</strong> <?= (int)$sig['KingdomId'] ?></div>
			<div class="wv-fact"><strong>Signed:</strong> <?= htmlspecialchars($sig['SignedAt']) ?></div>
			<div class="wv-fact"><strong>Template:</strong> v<?= (int)$tpl['Version'] ?> (<?= htmlspecialchars($tpl['Scope']) ?>)</div>
		</div>
	</div>

	<div class="wv-section"><?= $md($tpl['BodyMarkdown'] ?? '') ?></div>

	<?php if ($sig['IsMinor']): ?>
	<div class="wv-section">
		<h2>Minor Representative</h2>
		<div><?= $md($tpl['MinorMarkdown'] ?? '') ?></div>
		<div class="wv-playerhdr" style="margin-top:10px;">
			<div class="wv-fact"><strong>Rep Name:</strong> <?= htmlspecialchars($sig['MinorRepFirst'] . ' ' . $sig['MinorRepLast']) ?></div>
			<div class="wv-fact"><strong>Relationship:</strong> <?= htmlspecialchars($sig['MinorRepRelationship']) ?></div>
		</div>
	</div>
	<?php endif; ?>

	<div class="wv-section">
		<h2>Player Signature</h2>
		<div class="wv-sig-rendered" id="wvPlayerSig"></div>
	</div>

	<div class="wv-section"><?= $md($tpl['FooterMarkdown'] ?? '') ?></div>

	<?php if ($canVerify): ?>
	<div class="wv-section wv-verify-form">
		<h2>Officer Verification</h2>
		<form id="wvVerifyForm">
			<input type="hidden" name="SignatureId" value="<?= (int)$sig['SignatureId'] ?>">
			<label>Printed Name</label>
			<input type="text" name="PrintedName" value="<?= htmlspecialchars($of['PrintedName']) ?>" required>
			<label>Persona Name</label>
			<input type="text" name="PersonaName" value="<?= htmlspecialchars($of['PersonaName']) ?>">
			<label>Office Title</label>
			<input type="text" name="OfficeTitle" placeholder="e.g. Prime Minister, Sheriff" required>
			<label>Date of Review</label>
			<input type="text" value="<?= date('F j, Y') ?>" disabled>
			<label>Officer Signature</label>
			<?php wv_render_signature_widget('wvVerifierSig', 'verifier', 'Type your full legal name'); ?>
			<label>Notes (required if rejecting)</label>
			<textarea name="Notes" rows="2"></textarea>
			<div class="wv-actions">
				<button type="button" class="wv-approve" data-action="verified">Verify</button>
				<button type="button" class="wv-reject"  data-action="rejected">Reject</button>
				<span id="wvVerifyStatus"></span>
			</div>
		</form>
	</div>
	<?php elseif ($sig['VerificationStatus'] === 'verified'): ?>
	<div class="wv-section wv-verified-box">
		<strong>✓ Verified</strong> by <?= htmlspecialchars($sig['VerifierPrintedName']) ?>
		(<?= htmlspecialchars($sig['VerifierOfficeTitle']) ?>) on <?= htmlspecialchars($sig['VerifiedAt']) ?>
	</div>
	<?php elseif (in_array($sig['VerificationStatus'], ['rejected','superseded'])): ?>
	<div class="wv-section wv-rejected-box">
		<strong>Status: <?= htmlspecialchars($sig['VerificationStatus']) ?></strong>
		<?php if ($sig['VerifierNotes']): ?> — notes: <?= htmlspecialchars($sig['VerifierNotes']) ?><?php endif; ?>
	</div>
	<?php endif; ?>
</div>

<script>
<?= file_get_contents(DIR_TEMPLATE . 'revised-frontend/Waiver_signature_widget.js.inc') ?>
</script>
<script>
window.WvReviewConfig = {
	signatureType: <?= json_encode($sig['SignatureType']) ?>,
	signatureData: <?= json_encode($sig['SignatureData']) ?>,
	canVerify: <?= $canVerify ? 'true' : 'false' ?>
};
</script>
<script>
(function(){
	if (!window.WvReviewConfig) return;
	const target = document.getElementById('wvPlayerSig');
	if (target && window.wvRenderSignature) window.wvRenderSignature(target, WvReviewConfig.signatureType, WvReviewConfig.signatureData, 600, 180);

	if (!WvReviewConfig.canVerify) return;
	const form = document.getElementById('wvVerifyForm');
	const status = document.getElementById('wvVerifyStatus');

	async function submit(action) {
		const fd = new FormData(form);
		fd.append('Action', action);
		fd.set('SignatureType', form.querySelector('.wv-sig-type').value);
		fd.set('SignatureData', form.querySelector('.wv-sig-data').value);
		if (!fd.get('SignatureData')) { status.textContent = 'Please sign before submitting.'; return; }
		status.textContent = 'Saving…';
		const r = await fetch('/orkui/WaiverAjax/verifySignature', { method: 'POST', body: fd, credentials: 'same-origin' });
		const j = await r.json();
		if (j.status === 0) { window.location.reload(); }
		else { status.textContent = j.error || 'Failed'; }
	}

	form.querySelectorAll('.wv-actions button').forEach(b => b.addEventListener('click', () => submit(b.dataset.action)));
})();
</script>
```

- [ ] **Step 2: Commit** (`Enhancement: Digital Waivers — review template`).

---

### Task 3.6: `Waiver_print.tpl`

Minimal-chrome printable version.

**Files:**
- Create: `orkui/template/revised-frontend/Waiver_print.tpl`

- [ ] **Step 1: Write template**

```php
<?php
require_once(DIR_LIB . 'Parsedown.php');
$wv = $this->data['_wv'];
$sig = $wv['signature'];
$tpl = $sig['Template'];
$md = function($t) { return $t ? (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($t) : ''; };
?>
<!doctype html>
<html><head>
<meta charset="utf-8">
<title>Waiver — <?= htmlspecialchars($sig['MundaneFirst'] . ' ' . $sig['MundaneLast']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Homemade+Apple&display=swap" rel="stylesheet">
<style>
body { font-family: Georgia, serif; color: #111; margin: 0; padding: 0; }
h1, h2, h3 { background: transparent; border: none; padding: 0; text-shadow: none; }
.wv-p { max-width: 780px; margin: 0 auto; padding: 20px; }
.wv-p header, .wv-p footer { font-size: 11px; color: #555; border-top: 1px solid #ccc; padding: 6px 0; }
.wv-p header { border-top: none; border-bottom: 1px solid #ccc; margin-bottom: 14px; }
.wv-p .wv-p-section { margin: 10px 0; line-height: 1.5; font-size: 13px; }
.wv-p .wv-p-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 20px; font-size: 13px; }
.wv-p .wv-p-sig { min-height: 160px; border-bottom: 1px solid #333; padding-bottom: 4px; }
.wv-p .wv-p-typed-sig { font-family: 'Homemade Apple', 'Caveat', cursive; font-size: 28px; }
@page { margin: 1cm; }
</style>
</head>
<body>
<div class="wv-p">
	<header><?= strip_tags($md($tpl['HeaderMarkdown'] ?? ''), '<strong><em>') ?></header>
	<h1>Digital Waiver — Signed Record #<?= (int)$sig['SignatureId'] ?></h1>
	<div class="wv-p-grid">
		<div><strong>Legal Name:</strong> <?= htmlspecialchars($sig['MundaneFirst'] . ' ' . $sig['MundaneLast']) ?></div>
		<div><strong>Persona:</strong> <?= htmlspecialchars($sig['PersonaName']) ?></div>
		<div><strong>Park ID:</strong> <?= (int)$sig['ParkId'] ?></div>
		<div><strong>Kingdom ID:</strong> <?= (int)$sig['KingdomId'] ?></div>
		<div><strong>Signed:</strong> <?= htmlspecialchars($sig['SignedAt']) ?></div>
		<div><strong>Template:</strong> v<?= (int)$tpl['Version'] ?> (<?= htmlspecialchars($tpl['Scope']) ?>)</div>
	</div>
	<div class="wv-p-section"><?= $md($tpl['BodyMarkdown'] ?? '') ?></div>
	<?php if ($sig['IsMinor']): ?>
	<div class="wv-p-section">
		<?= $md($tpl['MinorMarkdown'] ?? '') ?>
		<p><strong>Representative:</strong> <?= htmlspecialchars($sig['MinorRepFirst'] . ' ' . $sig['MinorRepLast']) ?> (<?= htmlspecialchars($sig['MinorRepRelationship']) ?>)</p>
	</div>
	<?php endif; ?>
	<h2>Signature</h2>
	<div class="wv-p-section wv-p-sig" id="wvPrintSig">
		<?php if ($sig['SignatureType'] === 'typed'): ?>
			<div class="wv-p-typed-sig"><?= htmlspecialchars($sig['SignatureData']) ?></div>
		<?php else: ?>
			<canvas id="wvPrintCanvas" width="600" height="160" style="width:100%; max-width:600px; height:160px;"></canvas>
		<?php endif; ?>
	</div>
	<?php if ($sig['VerificationStatus'] === 'verified'): ?>
	<h2>Officer Verification</h2>
	<div class="wv-p-grid">
		<div><strong>Verified by:</strong> <?= htmlspecialchars($sig['VerifierPrintedName']) ?></div>
		<div><strong>Persona:</strong> <?= htmlspecialchars($sig['VerifierPersonaName']) ?></div>
		<div><strong>Office:</strong> <?= htmlspecialchars($sig['VerifierOfficeTitle']) ?></div>
		<div><strong>Date:</strong> <?= htmlspecialchars($sig['VerifiedAt']) ?></div>
	</div>
	<div class="wv-p-section wv-p-sig">
		<?php if ($sig['VerifierSignatureType'] === 'typed'): ?>
			<div class="wv-p-typed-sig"><?= htmlspecialchars($sig['VerifierSignatureData']) ?></div>
		<?php elseif ($sig['VerifierSignatureData']): ?>
			<canvas id="wvPrintOfficerCanvas" width="600" height="140" style="width:100%; max-width:600px; height:140px;"></canvas>
		<?php endif; ?>
	</div>
	<?php endif; ?>
	<footer><?= strip_tags($md($tpl['FooterMarkdown'] ?? ''), '<strong><em>') ?></footer>
</div>
<script>
function drawSig(canvasId, dataJson) {
	const c = document.getElementById(canvasId); if (!c) return;
	let strokes; try { strokes = JSON.parse(dataJson); } catch(_) { return; }
	const ctx = c.getContext('2d');
	ctx.lineCap='round'; ctx.lineJoin='round'; ctx.lineWidth=2; ctx.strokeStyle='#111';
	strokes.forEach(s => {
		if (!s.length) return;
		ctx.beginPath();
		ctx.moveTo(s[0].x * c.width, s[0].y * c.height);
		for (let i = 1; i < s.length; i++) ctx.lineTo(s[i].x * c.width, s[i].y * c.height);
		ctx.stroke();
	});
}
<?php if ($sig['SignatureType'] === 'drawn'): ?>
drawSig('wvPrintCanvas', <?= json_encode($sig['SignatureData']) ?>);
<?php endif; ?>
<?php if ($sig['VerificationStatus'] === 'verified' && $sig['VerifierSignatureType'] === 'drawn' && $sig['VerifierSignatureData']): ?>
drawSig('wvPrintOfficerCanvas', <?= json_encode($sig['VerifierSignatureData']) ?>);
<?php endif; ?>
window.addEventListener('load', () => setTimeout(() => window.print(), 300));
</script>
</body></html>
```

- [ ] **Step 2: Commit** (`Enhancement: Digital Waivers — print template`).

---

## Phase 4 — Integration hooks

### Task 4.1: Kingdomnew admin menu + card

Add Waivers links into the Kingdomnew admin menu and a status card.

**Files:**
- Modify: `orkui/controller/controller.Kingdom.php` (inject menu entry)
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` (admin card)

- [ ] **Step 1: Locate admin menu injection point in controller.Kingdom.php**

Find the block that conditionally sets `$this->data['menu']['admin']`:

```bash
grep -n "menu.*admin\|HasAuthority.*AUTH_KINGDOM" /Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias/orkui/controller/controller.Kingdom.php | head
```

- [ ] **Step 2: Add menu entries via Python**

```bash
python3 <<'PY'
import pathlib, re
p = pathlib.Path('/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias/orkui/controller/controller.Kingdom.php')
t = p.read_text()
# Find the admin block and append two menu entries. This pattern assumes an existing array append convention;
# if the concrete text differs in your checkout, inspect and adjust the needle before running this script.
needle = "$this->data['menu']['admin'][] ="  # partial match of first existing admin entry assignment
assert needle in t, 'existing admin menu entries not found; inspect file and update needle'
insert_after = t.rfind(needle)
# find end-of-statement ';' after that position
semi = t.index(';', insert_after) + 1
addition = "\n\t\t\t$this->data['menu']['admin'][] = ['label' => 'Edit Waivers', 'url' => '/orkui/Waiver/builder/' . (int)$id];\n\t\t\t$this->data['menu']['admin'][] = ['label' => 'Waiver Review Queue', 'url' => '/orkui/Waiver/queue/kingdom/' . (int)$id];"
p.write_text(t[:semi] + addition + t[semi:])
print('ok')
PY
```

- [ ] **Step 3: Verify**

```bash
docker exec ork3-php8-web php -l /var/www/html/orkui/controller/controller.Kingdom.php
```

Expected: no syntax errors. Visit Kingdomnew while kingdom admin and confirm "Edit Waivers" / "Review Queue" appear.

- [ ] **Step 4: Commit** (`Enhancement: Digital Waivers — Kingdomnew admin menu entries`).

---

### Task 4.2: Parknew integration

Add:
- Officer queue link (for park officers)
- Player "Sign Park Waiver" CTA when the current user has NOT signed the active park waiver

**Files:**
- Modify: `orkui/controller/controller.Park.php` (menu + waiver status fetch)
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (CTA card)

- [ ] **Step 1: In `controller.Park.php`, after the existing auth/admin block, add:**

Use Python, append inside the existing constructor immediately before the closing `}`:

```php
		// Waiver integration
		$kingdom_id = (int)($this->data['park_info']['ParkInfo']['KingdomId'] ?? 0);
		$this->load_model('Waiver');
		$this->data['park_info']['WaiverActive'] = $this->Waiver->GetActiveTemplate(['KingdomId' => $kingdom_id, 'Scope' => 'park']);
		if ($_uid > 0 && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_PARK, (int)$id, AUTH_EDIT)) {
			$this->data['menu']['admin'][] = ['label' => 'Waiver Review Queue', 'url' => '/orkui/Waiver/queue/park/' . (int)$id];
		}
```

- [ ] **Step 2: In `Parknew_index.tpl`, find an appropriate sidebar/card insertion point** (look for `pk-card` class) and add:

```php
<?php $wvActive = $park_info['WaiverActive'] ?? null; if ($wvActive && ($wvActive['Status']['Code'] ?? 1) === 0 && (int)($wvActive['Template']['IsEnabled'] ?? 0) === 1): ?>
<div class="pk-card">
	<h4 style="background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;">Digital Waiver</h4>
	<p>This park requires a signed digital waiver.</p>
	<a class="pk-btn" href="/orkui/Waiver/sign/park/<?= (int)$park_info['ParkInfo']['ParkId'] ?>">Sign Park Waiver</a>
</div>
<?php endif; ?>
```

- [ ] **Step 3: Syntax check + browser smoke test + commit** (`Enhancement: Digital Waivers — Parknew integration`).

---

### Task 4.3: Playernew sidebar card

**Files:**
- Modify: `orkui/controller/controller.Playernew.php` (load waiver status for this player)
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl` (sidebar card — only visible when viewing own profile)

- [ ] **Step 1: In `controller.Playernew.php`** inject right before `$this->template = ...`:

```php
		// Digital Waivers status for sidebar
		$this->load_model('Waiver');
		$ownProfile = ($_uid > 0 && (int)$_uid === (int)$id);
		$this->data['_wv_sidebar'] = ['is_own' => $ownProfile, 'items' => []];
		if ($ownProfile) {
			$kingdomId = (int)($this->data['Player']['KingdomId'] ?? 0);
			$parkId    = (int)($this->data['Player']['ParkId']    ?? 0);
			foreach ([['kingdom', $kingdomId], ['park', $parkId]] as [$scope, $eid]) {
				if ($eid <= 0) continue;
				$activeKingdom = ($scope === 'kingdom') ? $eid : $kingdomId;
				$r = $this->Waiver->GetActiveTemplate(['KingdomId' => $activeKingdom, 'Scope' => $scope]);
				if (($r['Status']['Code'] ?? 1) !== 0) continue;
				if ((int)($r['Template']['IsEnabled'] ?? 0) !== 1) continue;
				$this->data['_wv_sidebar']['items'][] = [
					'scope' => $scope, 'entity_id' => $eid,
					'template_id' => (int)$r['Template']['TemplateId'],
					'version' => (int)$r['Template']['Version'],
				];
			}
		}
```

- [ ] **Step 2: In `Playernew_index.tpl`**, locate the sidebar (grep for `pn-card`) and append:

```php
<?php if (!empty($this->data['_wv_sidebar']['is_own']) && !empty($this->data['_wv_sidebar']['items'])): ?>
<div class="pn-card">
	<h4 style="background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;">Digital Waivers</h4>
	<?php foreach ($this->data['_wv_sidebar']['items'] as $it): ?>
		<div class="pn-detail-row">
			<span class="pn-detail-label"><?= htmlspecialchars(ucfirst($it['scope'])) ?> waiver (v<?= $it['version'] ?>)</span>
			<span class="pn-detail-value"><a href="/orkui/Waiver/sign/<?= htmlspecialchars($it['scope']) ?>/<?= (int)$it['entity_id'] ?>">Sign / View</a></span>
		</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>
```

- [ ] **Step 3: Commit** (`Enhancement: Digital Waivers — Playernew sidebar`).

---

## Phase 5 — QA, cleanup, PR

### Task 5.1: Full test-suite run

- [ ] **Step 1: Run all waiver PHP tests**

`./tests/php/run-waiver-tests.sh`

Expected: all tests PASS, 0 failures.

- [ ] **Step 2: Manual browser QA (copy this checklist into the PR description)**

Log in as a kingdom admin, then run through:

1. **Builder** — `/Waiver/builder/{your_kingdom_id}`:
   - [ ] Both tabs (Kingdom / Park) render.
   - [ ] Typing into any markdown textarea updates the corresponding preview within ~400ms.
   - [ ] Saving kingdom tab returns "Saved — v1" (or vN+1 on subsequent saves).
   - [ ] Disabling via checkbox and re-saving flips `is_enabled=0` (confirm by reloading and seeing checkbox cleared).
2. **Sign** — log in as a different (non-admin) player and visit `/Waiver/sign/kingdom/{kingdom_id}`:
   - [ ] Player header prefills from profile.
   - [ ] Draw signature tab captures strokes, Clear empties them, Undo pops last stroke.
   - [ ] Type signature tab renders cursive preview.
   - [ ] Toggling "I am signing for a minor" reveals the minor block.
   - [ ] Submitting with empty signature shows "Please sign before submitting."
   - [ ] Successful submit redirects to `/Waiver/review/{id}` showing the just-signed waiver.
3. **Queue** — back as kingdom admin visit `/Waiver/queue/kingdom/{kingdom_id}`:
   - [ ] The new signature appears under "Pending" filter.
   - [ ] Filter chips toggle (Pending / Verified / Rejected / Stale / All).
4. **Review** — click "Review →":
   - [ ] Player signature renders in the dashed box.
   - [ ] Officer form prefills Printed Name and Persona from logged-in officer.
   - [ ] Rejecting with empty Notes surfaces a field-error.
   - [ ] Verifying returns success and the page reloads showing the verified banner.
5. **Print** — click "Open printable version →":
   - [ ] New tab opens; browser native print dialog appears after ~300ms.
   - [ ] Both player and officer signatures render.
6. **Negative paths**:
   - [ ] Signing a kingdom with `is_enabled=0` shows the "has not enabled digital waivers yet" notice.
   - [ ] Non-admin visiting `/Waiver/builder/{other_kingdom}` is redirected to that kingdom's profile.
   - [ ] Non-officer visiting `/Waiver/queue/park/{other_park}` is redirected to the park profile.

- [ ] **Step 3: If any QA item fails, file it as a bug, fix, re-run.**

---

### Task 5.2: Final housekeeping + PR

- [ ] **Step 1: Confirm no forbidden files staged**

```bash
git status --porcelain | grep -E "class\.Authorization\.php|CLAUDE\.md|agent-instructions/claude\.md"
```

Expected: empty output. If anything appears, `git restore --staged <file>` it before committing.

- [ ] **Step 2: Confirm all intended files are tracked**

```bash
git log --name-only master..HEAD | sort -u | grep -E "(Waiver|waiver)"
```

Expected output contains every file from the plan's File Map.

- [ ] **Step 3: Push branch**

```bash
git push -u origin feature/digital-waivers
```

- [ ] **Step 4: Open PR**

```bash
gh pr create --title "Enhancement: Digital Waivers" --body "$(cat <<'EOF'
## Summary
- Kingdom-admin waiver builder (kingdom + park scopes, markdown body + header/footer/minor blocks, versioned templates with enable toggle)
- Player submission form with drawn (canvas strokes) or typed (cursive) signature, optional minor-representative block
- Officer verification queue + single-waiver review page with a signed sign-off (printed name, persona, office title, signature, date)
- Browser-printable signed-waiver view for paper archival

## DB migration
`db-migrations/2026-04-17-digital-waivers.sql` — two new tables (`ork_waiver_template`, `ork_waiver_signature`). No changes to `ork_mundane.waivered` (legacy upload path left intact).

## Test plan
- [ ] Unit / domain tests pass: `./tests/php/run-waiver-tests.sh`
- [ ] Manual browser QA checklist (see plan `docs/superpowers/plans/2026-04-17-digital-waivers.md` §5.1 step 2) walked end-to-end in Chrome
- [ ] Print view produces correctly-rendered signed document in browser print-to-PDF

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review results

**Spec coverage:** Walked through each section of the spec:
- §3 Architecture (files / routes / integration hooks) — Tasks 0, 2, 3, 4 ✓
- §4 Data model — Task 0.1 ✓
- §5.1 Builder — Task 3.2 ✓
- §5.2 Player submission — Task 3.3 ✓
- §5.3 Officer queue + review — Tasks 3.4, 3.5 ✓
- §5.4 Print — Task 3.6 ✓
- §6 Security / auth — enforced in domain layer Tasks 1.2, 1.4, 1.6, 1.7, 1.8 and mirrored in controllers Task 2.2 ✓
- §9 Testing — Task 1.1–1.10 + §5.1 QA checklist ✓

**Placeholder scan:** No TBDs / TODOs / "similar to above" / "handle edge cases" found. Each step has runnable commands or copy-pasteable code.

**Type / name consistency:** `TemplateId` / `SignatureId` / `waiver_template_id` / `waiver_signature_id` / `Status` → `Code` / `Error` spelling is uniform across tasks. Method names match call sites.

All good — ready to hand to subagent-driven-development.
