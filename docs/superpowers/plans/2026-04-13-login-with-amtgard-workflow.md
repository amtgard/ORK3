# Login with Amtgard Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Streamline Sign in with Amtgard so first-time users auto-link or claim an existing ORK profile in one screen, and returning users sign in with one click (or zero clicks via opt-in cookie).

**Architecture:** Add an ORK-side claim flow that runs *after* the existing OAuth callback. `AuthorizeIdp` is refactored to return a status code instead of erroring on no-link, and the controller dispatches into either dashboard login or the new claim form. Auto-link tries `ork_mundane.email` first; password fallback reuses `Authorization::Authorize`; magic-link fallback writes a token row and sends an email via the existing `Mail` helper. Successful claims mirror back to bastion-idp via a new confidential-client endpoint, with a retry job for failures.

**Tech Stack:** PHP 8 / MariaDB / Smarty templates / Yapo ORM / OAuth2 PKCE. No PHPUnit — tests are manual browser walkthroughs against the local `bastion-idp` dev container at `http://localhost:37080`.

---

## File Structure

**Files created**
- `db-migrations/2026-04-13-idp-claim-flow.sql` — magic-link token table + mirror status columns
- `orkui/model/model.AmtgardIdpLink.php` — confidential-client write-back to bastion-idp
- `orkui/template/revised-frontend/Login_claim.tpl` — claim form (password + magic-link request)
- `cron/idp-mirror-retry.php` — hourly retry job for failed mirror calls
- `docs/integrations/bastion-idp-link-endpoint.md` — spec for the bastion-idp PR

**Files modified**
- `system/lib/ork3/class.Authorization.php` — refactor `AuthorizeIdp`, add `tryAutoLinkByEmail`, `verifyClaimCredentials`, `issueClaimMagicLink`, `consumeMagicLink`, `mirrorLinkToIdp`
- `orkui/controller/controller.Login.php` — refactor `oauth_callback`, add `claim_profile`, `claim_submit`, `claim_request_magic_link`, `claim_magic_link`
- `orkui/template/revised-frontend/Login_index.tpl` — promote IDP button, add legacy disclosure, add autoredirect cookie script
- `orkui/template/default/Login_index.tpl` — same promotion + script

**Hard rules carried into every task** (from project memory)
- **Multi-line PHP edits MUST use Python**, never the Edit tool. Tabs render identically to spaces in the Edit tool, and PHP files use tabs — Edit calls will fail repeatedly. Use this pattern: `python3 -c "import pathlib; p=pathlib.Path('file'); t=p.read_text(); print('found:', 'NEEDLE' in t); p.write_text(t.replace(OLD, NEW, 1))"`.
- **`$DB->Clear()` before every raw `Execute`** — stale PDO bindings cause silent insert failures.
- **`die()` debug placement**: AFTER the insert/update being investigated, never before.
- **Never stage `class.Authorization.php` with `git add -A`** — there's a `true ||` login bypass hack at lines 327/330. Always stage explicit files only.
- **All debug output goes to browser console**, not error_log, when adding new debug lines (use `die(json_encode([...]))` pattern). Keep existing `error_log` lines as-is.
- **The migration is run via** `docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/<file>.sql` (container is MariaDB, not MySQL).

---

## Task 1: Database migration

**Files:**
- Create: `db-migrations/2026-04-13-idp-claim-flow.sql`

- [ ] **Step 1: Write the migration SQL**

```sql
-- Magic-link tokens for the password-fallback claim path
CREATE TABLE IF NOT EXISTS `ork_idp_claim_token` (
    `token` CHAR(64) NOT NULL,
    `idp_user_id` VARCHAR(255) NOT NULL,
    `idp_email` VARCHAR(255) NOT NULL,
    `mundane_id` INT(11) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `consumed_at` DATETIME NULL,
    PRIMARY KEY (`token`),
    KEY `idx_mundane` (`mundane_id`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- IDP mirror retry tracking on existing IDP auth table
ALTER TABLE `ork_idp_auth`
    ADD COLUMN `idp_mirror_status` ENUM('pending','synced','failed') NOT NULL DEFAULT 'pending',
    ADD COLUMN `idp_mirror_last_attempt` DATETIME NULL;
```

- [ ] **Step 2: Run the migration**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-04-13-idp-claim-flow.sql
```

Expected output: silent success (no errors).

- [ ] **Step 3: Verify the schema**

```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "DESCRIBE ork_idp_claim_token; DESCRIBE ork_idp_auth;"
```

Expected: `ork_idp_claim_token` has 7 columns; `ork_idp_auth` now has `idp_mirror_status` and `idp_mirror_last_attempt`.

- [ ] **Step 4: Confirm rollback works on a copy**

```bash
docker exec ork3-php8-db mariadb -u root -proot ork -e "DROP TABLE ork_idp_claim_token; ALTER TABLE ork_idp_auth DROP COLUMN idp_mirror_status, DROP COLUMN idp_mirror_last_attempt;"
```

Then re-run Step 2 to leave the schema in the migrated state.

- [ ] **Step 5: Commit**

```bash
git add db-migrations/2026-04-13-idp-claim-flow.sql
git commit -m "Migration: Add IDP claim token table and mirror status columns"
```

---

## Task 2: AmtgardIdpLink model (write-back to bastion-idp)

**Files:**
- Create: `orkui/model/model.AmtgardIdpLink.php`

- [ ] **Step 1: Write the model file**

Use the Write tool (it's a new file, not an edit).

```php
<?php

class Model_AmtgardIdpLink extends Model {
    function __construct() {
        parent::__construct();
    }

    /**
     * Mirror an ORK profile link back to bastion-idp.
     * Confidential-client authenticated POST to /resources/link-ork-profile.
     * Returns true on 2xx, false on any other outcome.
     */
    public function linkOrkProfile($idpUserId, $mundaneId)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, IDP_API_URL . '/resources/link-ork-profile');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode(IDP_CLIENT_ID . ':' . IDP_CLIENT_SECRET),
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'idp_user_id' => $idpUserId,
            'mundane_id'  => (int)$mundaneId,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'AmtgardORK/1.0');
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($http >= 200 && $http < 300) {
            return true;
        }
        error_log("Model_AmtgardIdpLink::linkOrkProfile failed http=$http err=$err body=$body");
        return false;
    }
}
```

- [ ] **Step 2: Smoke-test the file loads**

```bash
docker exec ork3-php8 php -r "require '/var/www/orkui/model/Model.php'; require '/var/www/orkui/model/model.AmtgardIdpLink.php'; \$m = new Model_AmtgardIdpLink(); echo get_class(\$m) . \"\\n\";"
```

Expected output: `Model_AmtgardIdpLink`. If that class path is wrong (the autoloader uses different glue), instead just verify the file parses:

```bash
docker exec ork3-php8 php -l /var/www/orkui/model/model.AmtgardIdpLink.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add orkui/model/model.AmtgardIdpLink.php
git commit -m "Enhancement: AmtgardIdpLink model for mirroring claims back to bastion-idp"
```

---

## Task 3: Refactor `AuthorizeIdp` to dispatch on status

**Files:**
- Modify: `system/lib/ork3/class.Authorization.php` (lines 384–465)

**Why this task exists:** `AuthorizeIdp` currently returns a `NoAuthorization` error when the IDP user can't be auto-linked. We want it to return a structured status code (`LOGGED_IN` or `NEEDS_CLAIM`) so the controller can route into the new claim form. The match-by-`mundane_id`-from-userinfo branch in `linkIdpAuthorization()` is removed entirely — that linking now happens through the explicit claim flow.

- [ ] **Step 1: Read the current file to confirm exact text**

```bash
sed -n '384,465p' system/lib/ork3/class.Authorization.php
```

You should see the existing `AuthorizeIdp`, `idpAuthorize`, `linkIdpAuthorization`, `createIdpLink` methods. Confirm the line numbers haven't drifted.

- [ ] **Step 2: Replace the four methods with the new dispatch + helper**

Use Python (not Edit) — multi-line PHP changes per project rule.

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Authorization.php')
t = p.read_text()

old = '''	public function AuthorizeIdp()
	{
		$request = [
			'IdpUserId' => $_SESSION['Session_Vars']['IdpUserId'],
			'Email' => $_SESSION['Session_Vars']['Email'],
			'MundaneId' => $_SESSION['Session_Vars']['MundaneId'],
			'AccessToken' => $_SESSION['Session_Vars']['AccessToken'],
			'RefreshToken' => $_SESSION['Session_Vars']['RefreshToken'],
			'ExpiresAt' => $_SESSION['Session_Vars']['ExpiresAt'],
		];

		error_log("AuthorizeIdp: Request: " . print_r($request, true));
		$this->idp_auth->clear();
		$this->idp_auth->idp_user_id = $request['IdpUserId'];

		if ($this->idp_auth->find()) {
			return $this->idpAuthorize($request);
		}

		return $this->linkIdpAuthorization($request);
	}'''

new = '''	const IDP_RESULT_LOGGED_IN  = 'logged_in';
	const IDP_RESULT_NEEDS_CLAIM = 'needs_claim';

	public function AuthorizeIdp()
	{
		$request = [
			'IdpUserId' => $_SESSION['Session_Vars']['IdpUserId'],
			'Email' => $_SESSION['Session_Vars']['Email'],
			'MundaneId' => $_SESSION['Session_Vars']['MundaneId'],
			'AccessToken' => $_SESSION['Session_Vars']['AccessToken'],
			'RefreshToken' => $_SESSION['Session_Vars']['RefreshToken'],
			'ExpiresAt' => $_SESSION['Session_Vars']['ExpiresAt'],
		];

		error_log("AuthorizeIdp: Request: " . print_r($request, true));
		$this->idp_auth->clear();
		$this->idp_auth->idp_user_id = $request['IdpUserId'];

		if ($this->idp_auth->find()) {
			$resp = $this->idpAuthorize($request);
			$resp['IdpResult'] = self::IDP_RESULT_LOGGED_IN;
			return $resp;
		}

		// No existing link. Try the email auto-link path.
		$autoLinked = $this->tryAutoLinkByEmail($request);
		if ($autoLinked !== false) {
			$autoLinked['IdpResult'] = self::IDP_RESULT_LOGGED_IN;
			return $autoLinked;
		}

		// No auto-link possible: caller should redirect into the claim flow.
		return [
			'Status' => Success(),
			'IdpResult' => self::IDP_RESULT_NEEDS_CLAIM,
		];
	}'''

assert old in t, 'AuthorizeIdp block not found verbatim'
p.write_text(t.replace(old, new, 1))
print('AuthorizeIdp replaced')
PY
```

- [ ] **Step 3: Remove the old `linkIdpAuthorization` method (the mundane-id-from-userinfo branch is gone)**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Authorization.php')
t = p.read_text()

old = '''	private function linkIdpAuthorization($request)
	{
		error_log("AuthorizeIdp: No link found. Checking for MundaneId or Email.");

		$this->mundane->clear();
		$found_mundane = false;

		// Try to find by MundaneId first if provided
		if (isset($request['MundaneId']) && $request['MundaneId'] > 0) {
			error_log("AuthorizeIdp: Trying to link by MundaneId: " . $request['MundaneId']);
			$this->mundane->mundane_id = $request['MundaneId'];
			if ($this->mundane->find()) {
				$found_mundane = true;
				error_log("AuthorizeIdp: User found by MundaneId.");
			}
		}

		if (!$found_mundane) {
			error_log("AuthorizeIdp: User not found by MundaneId or Email.");
			return ['Status' => NoAuthorization("User not found and could not be automatically linked.")];
		}

		return $this->createIdpLink($request);
	}

'''

assert old in t, 'linkIdpAuthorization block not found verbatim'
p.write_text(t.replace(old, '', 1))
print('linkIdpAuthorization removed')
PY
```

- [ ] **Step 4: Lint the file**

```bash
docker exec ork3-php8 php -l /var/www/system/lib/ork3/class.Authorization.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit (explicit file, never `-A`)**

```bash
git add system/lib/ork3/class.Authorization.php
git commit -m "Refactor: AuthorizeIdp dispatches on status, drops mundane_id-from-userinfo branch"
```

If the bypass hack is present at lines 327/330 (`true ||`), abort the commit and remove the hack first. Confirm with: `grep -n 'true ||' system/lib/ork3/class.Authorization.php` — if it returns lines, fix them before committing.

---

## Task 4: Add `tryAutoLinkByEmail`

**Files:**
- Modify: `system/lib/ork3/class.Authorization.php` (insert new private method after `AuthorizeIdp`)

- [ ] **Step 1: Insert the helper after `AuthorizeIdp`**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Authorization.php')
t = p.read_text()

# Anchor: the closing brace of AuthorizeIdp + blank line before idpAuthorize
anchor = '''		return [
			'Status' => Success(),
			'IdpResult' => self::IDP_RESULT_NEEDS_CLAIM,
		];
	}

	private function idpAuthorize($request)'''

inject = '''		return [
			'Status' => Success(),
			'IdpResult' => self::IDP_RESULT_NEEDS_CLAIM,
		];
	}

	/**
	 * Try to link this IDP identity to an existing ork_mundane row by email.
	 * Returns the standard auth response array on a successful link, or false
	 * if there were zero or multiple matches.
	 */
	private function tryAutoLinkByEmail($request)
	{
		global $DB;
		$email = trim($request['Email']);
		if (strlen($email) === 0) {
			return false;
		}

		// Case-insensitive exact match. ork_mundane.email is varchar(165) MUL.
		$DB->Clear();
		$rows = $DB->DataSet(
			"SELECT m.mundane_id FROM " . DB_PREFIX . "mundane m " .
			"LEFT JOIN " . DB_PREFIX . "idp_auth ia ON ia.mundane_id = m.mundane_id " .
			"WHERE LOWER(m.email) = LOWER(?) AND ia.authorization_id IS NULL",
			array($email)
		);

		if (!is_array($rows) || count($rows) !== 1) {
			error_log("AuthorizeIdp: tryAutoLinkByEmail matched " . (is_array($rows) ? count($rows) : 0) . " rows for $email");
			return false;
		}

		$mundaneId = (int)$rows[0]['mundane_id'];
		$this->mundane->clear();
		$this->mundane->mundane_id = $mundaneId;
		if (!$this->mundane->find()) {
			return false;
		}
		if ($this->mundane->penalty_box == 1 || $this->mundane->suspended == 1) {
			return false;
		}

		// Reuse createIdpLink, which writes ork_idp_auth + issues the ORK token.
		$linked = $this->createIdpLink($request);

		// Mirror to bastion-idp (best-effort).
		$this->mirrorLinkToIdp($request['IdpUserId'], $mundaneId);

		return $linked;
	}

	private function idpAuthorize($request)'''

assert anchor in t, 'AuthorizeIdp closing anchor not found'
p.write_text(t.replace(anchor, inject, 1))
print('tryAutoLinkByEmail injected')
PY
```

- [ ] **Step 2: Lint**

```bash
docker exec ork3-php8 php -l /var/www/system/lib/ork3/class.Authorization.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Authorization.php
git commit -m "Enhancement: Auto-link IDP identity to ORK mundane by exact email match"
```

---

## Task 5: Add `verifyClaimCredentials`

**Files:**
- Modify: `system/lib/ork3/class.Authorization.php` (insert after `tryAutoLinkByEmail`)

- [ ] **Step 1: Insert the method**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Authorization.php')
t = p.read_text()

anchor = '''		return $linked;
	}

	private function idpAuthorize($request)'''

inject = '''		return $linked;
	}

	/**
	 * Verify ORK credentials and finalize the IDP link in one shot.
	 * $claim is the session-stashed callback context: idp_user_id, email,
	 * access_token, refresh_token, expires_at. $username/$password are what
	 * the user typed into the claim form.
	 *
	 * Returns the standard auth response array on success, or
	 * ['Status' => NoAuthorization(...)] on credential failure.
	 */
	public function verifyClaimCredentials($username, $password, $claim)
	{
		// Reuse the existing password verification path.
		$auth = $this->Authorize(['UserName' => $username, 'Password' => $password, 'Token' => null]);
		if (!isset($auth['Status']) || $auth['Status']['Status'] !== 0) {
			return ['Status' => NoAuthorization("Username or password incorrect")];
		}

		$mundaneId = $auth['UserId'];

		// Refuse if this ORK profile is already linked to a different IDP id.
		$this->idp_auth->clear();
		$this->idp_auth->mundane_id = $mundaneId;
		if ($this->idp_auth->find() && $this->idp_auth->idp_user_id !== $claim['IdpUserId']) {
			return ['Status' => NoAuthorization("This ORK profile is already linked to another Amtgard account. Contact support if you need to transfer it.")];
		}

		// Build the request shape createIdpLink expects.
		$this->mundane->clear();
		$this->mundane->mundane_id = $mundaneId;
		$this->mundane->find();

		$request = [
			'IdpUserId'    => $claim['IdpUserId'],
			'Email'        => $claim['Email'],
			'AccessToken'  => $claim['AccessToken'],
			'RefreshToken' => $claim['RefreshToken'],
			'ExpiresAt'    => $claim['ExpiresAt'],
		];
		$linked = $this->createIdpLink($request);

		// Mirror to bastion-idp (best-effort).
		$this->mirrorLinkToIdp($claim['IdpUserId'], $mundaneId);

		return $linked;
	}

	private function idpAuthorize($request)'''

assert anchor in t, 'Anchor not found'
p.write_text(t.replace(anchor, inject, 1))
print('verifyClaimCredentials injected')
PY
```

- [ ] **Step 2: Lint**

```bash
docker exec ork3-php8 php -l /var/www/system/lib/ork3/class.Authorization.php
```

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Authorization.php
git commit -m "Enhancement: verifyClaimCredentials links IDP identity after ORK password check"
```

---

## Task 6: Add `issueClaimMagicLink` and `consumeMagicLink`

**Files:**
- Modify: `system/lib/ork3/class.Authorization.php` (insert after `verifyClaimCredentials`)

- [ ] **Step 1: Insert both methods**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Authorization.php')
t = p.read_text()

anchor = '''		return $linked;
	}

	private function idpAuthorize($request)'''

inject = '''		return $linked;
	}

	/**
	 * Generate a magic-link token row and return the token string.
	 * The caller is responsible for sending the email.
	 * Returns false if the username has no matching ork_mundane.
	 */
	public function issueClaimMagicLink($username, $claim)
	{
		global $DB;

		$this->mundane->clear();
		$this->mundane->like('username', trim($username));
		if (!$this->mundane->find()) {
			return false;
		}
		if ($this->mundane->penalty_box == 1 || $this->mundane->suspended == 1) {
			return false;
		}

		$token = bin2hex(openssl_random_pseudo_bytes(32)); // 64 hex chars
		$expires = date('Y-m-d H:i:s', time() + 60 * 60 * 24); // 24h

		$DB->Clear();
		$DB->Execute(
			"INSERT INTO " . DB_PREFIX . "idp_claim_token " .
			"(token, idp_user_id, idp_email, mundane_id, expires_at) " .
			"VALUES (?, ?, ?, ?, ?)",
			array($token, $claim['IdpUserId'], $claim['Email'], (int)$this->mundane->mundane_id, $expires)
		);

		return [
			'token'      => $token,
			'email'      => $this->mundane->email,
			'username'   => $this->mundane->username,
			'mundane_id' => (int)$this->mundane->mundane_id,
		];
	}

	/**
	 * Consume a magic-link token. Marks the row consumed and finalizes
	 * the IDP link in one shot. Returns the standard auth response array,
	 * or ['Status' => NoAuthorization(...)] with a specific reason.
	 */
	public function consumeMagicLink($token)
	{
		global $DB;
		$token = trim((string)$token);
		if (strlen($token) !== 64) {
			return ['Status' => NoAuthorization("That link isn't valid.")];
		}

		$DB->Clear();
		$rows = $DB->DataSet(
			"SELECT * FROM " . DB_PREFIX . "idp_claim_token WHERE token = ? LIMIT 1",
			array($token)
		);
		if (!is_array($rows) || count($rows) === 0) {
			return ['Status' => NoAuthorization("That link isn't valid.")];
		}
		$row = $rows[0];

		if (!is_null($row['consumed_at'])) {
			return ['Status' => NoAuthorization("That link has already been used.")];
		}
		if (strtotime($row['expires_at']) < time()) {
			return ['Status' => NoAuthorization("That link has expired. Start over from the login page.")];
		}

		// Refuse if this ORK profile is already linked to a different IDP id.
		$this->idp_auth->clear();
		$this->idp_auth->mundane_id = (int)$row['mundane_id'];
		if ($this->idp_auth->find() && $this->idp_auth->idp_user_id !== $row['idp_user_id']) {
			return ['Status' => NoAuthorization("This ORK profile is already linked to another Amtgard account.")];
		}

		// Mark consumed BEFORE finalizing so concurrent uses fail fast.
		$DB->Clear();
		$DB->Execute(
			"UPDATE " . DB_PREFIX . "idp_claim_token SET consumed_at = NOW() WHERE token = ?",
			array($token)
		);

		// Build createIdpLink request shape. We don't have access/refresh
		// tokens at magic-link consumption time — that's fine, the next IDP
		// sign-in will refresh them via idpAuthorize().
		$this->mundane->clear();
		$this->mundane->mundane_id = (int)$row['mundane_id'];
		$this->mundane->find();

		$request = [
			'IdpUserId'    => $row['idp_user_id'],
			'Email'        => $row['idp_email'],
			'AccessToken'  => null,
			'RefreshToken' => null,
			'ExpiresAt'    => time(),
		];
		$linked = $this->createIdpLink($request);

		$this->mirrorLinkToIdp($row['idp_user_id'], (int)$row['mundane_id']);

		return $linked;
	}

	private function idpAuthorize($request)'''

assert anchor in t, 'Anchor not found'
p.write_text(t.replace(anchor, inject, 1))
print('Magic-link methods injected')
PY
```

- [ ] **Step 2: Lint**

```bash
docker exec ork3-php8 php -l /var/www/system/lib/ork3/class.Authorization.php
```

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Authorization.php
git commit -m "Enhancement: Magic-link token issue and consume for IDP claim flow"
```

---

## Task 7: Add `mirrorLinkToIdp`

**Files:**
- Modify: `system/lib/ork3/class.Authorization.php`

- [ ] **Step 1: Insert the method**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('system/lib/ork3/class.Authorization.php')
t = p.read_text()

anchor = '''	private function idpAuthorize($request)'''

inject = '''	/**
	 * Best-effort mirror of an ORK link back to bastion-idp.
	 * Updates ork_idp_auth.idp_mirror_status accordingly. Never throws.
	 */
	public function mirrorLinkToIdp($idpUserId, $mundaneId)
	{
		global $DB;

		// Lazy-load the model — Authorization doesn't normally use orkui models.
		if (!class_exists('Model_AmtgardIdpLink')) {
			require_once $_SERVER['DOCUMENT_ROOT'] . '/orkui/model/Model.php';
			require_once $_SERVER['DOCUMENT_ROOT'] . '/orkui/model/model.AmtgardIdpLink.php';
		}
		$model = new Model_AmtgardIdpLink();
		$ok = $model->linkOrkProfile($idpUserId, $mundaneId);

		$status = $ok ? 'synced' : 'failed';
		$DB->Clear();
		$DB->Execute(
			"UPDATE " . DB_PREFIX . "idp_auth SET idp_mirror_status = ?, idp_mirror_last_attempt = NOW() " .
			"WHERE idp_user_id = ? AND mundane_id = ?",
			array($status, $idpUserId, (int)$mundaneId)
		);
	}

	private function idpAuthorize($request)'''

assert anchor in t and t.count(anchor) == 1, 'Anchor not unique or missing'
p.write_text(t.replace(anchor, inject, 1))
print('mirrorLinkToIdp injected')
PY
```

- [ ] **Step 2: Lint**

```bash
docker exec ork3-php8 php -l /var/www/system/lib/ork3/class.Authorization.php
```

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Authorization.php
git commit -m "Enhancement: mirrorLinkToIdp writes ORK claims back to bastion-idp"
```

---

## Task 8: Refactor `oauth_callback` to dispatch

**Files:**
- Modify: `orkui/controller/controller.Login.php`

- [ ] **Step 1: Replace `oauth_callback` and `authorizeUser`**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Login.php')
t = p.read_text()

old = '''	public function oauth_callback()
	{
		if (!isset($_GET['code'])) {
			$this->data['error'] = 'No authorization returned from Amtgard IDP';
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}

		$token_data = $this->AmtgardIdp->exchangeAuthCodeForAccessToken($_GET['code'], $this->session->code_verifier);
		$user_data = $this->AmtgardIdp->fetchUserInfo($token_data['access_token']);

		if (isset($user_data['error'])) {
			error_log("Amtgard IDP OAuth callback: Failed to get user info: " . $user_data['response']);
			$this->data['error'] = 'Failed to get user info';
			$this->data['detail'] = $user_data['response'];
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}

		error_log("Amtgard IDP OAuth callback: User Data: " . print_r($user_data, true));

		$result = $this->authorizeUser($user_data, $token_data);

		error_log("Amtgard IDP OAuth callback: AuthorizeIdp Result: " . print_r($result, true));

		if ($result['Status']['Status'] === 0) {
			$this->session->user_id = $result['UserId'];
			$this->session->user_name = $result['UserName'];
			$this->session->token = $result['Token'];
			$this->session->timeout = $result['Timeout'];
			header('Location: ' . UIR);
		} else {
			$this->data['error'] = $result['Status']['Error'];
			$this->data['detail'] = $result['Status']['Detail'];
			$this->template = '../revised-frontend/Login_index.tpl';
		}
	}

	private function authorizeUser($userData, $tokenData)
	{
		$mundane_id = null;
		if (isset($userData['ork_profile']) && isset($userData['ork_profile']['mundane_id'])) {
			$mundane_id = $userData['ork_profile']['mundane_id'];
		}

		$this->session->IdpUserId = $userData['id'];
		$this->session->Email = $userData['email'];
		$this->session->MundaneId = $mundane_id;
		$this->session->AccessToken = $tokenData['access_token'];
		$this->session->RefreshToken = $tokenData['refresh_token'] ?? null;
		$this->session->ExpiresAt = time() + ($tokenData['expires_in'] ?? 3600);

		return $this->Login->Authorization->AuthorizeIdp();
	}
}'''

new = '''	public function oauth_callback()
	{
		if (!isset($_GET['code'])) {
			$this->data['error'] = 'IDP did not return an authorization code.';
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}

		$token_data = $this->AmtgardIdp->exchangeAuthCodeForAccessToken($_GET['code'], $this->session->code_verifier);
		$user_data = $this->AmtgardIdp->fetchUserInfo($token_data['access_token']);

		if (isset($user_data['error'])) {
			error_log("Amtgard IDP OAuth callback: Failed to get user info: " . $user_data['response']);
			$this->data['error'] = "Couldn't reach Amtgard IDP. Try again or use legacy login.";
			$this->data['detail'] = $user_data['response'];
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}

		// Stash the IDP context in the session for AuthorizeIdp + the claim flow.
		$this->session->IdpUserId    = $user_data['id'];
		$this->session->Email        = $user_data['email'] ?? '';
		$this->session->MundaneId    = isset($user_data['ork_profile']['mundane_id']) ? $user_data['ork_profile']['mundane_id'] : null;
		$this->session->AccessToken  = $token_data['access_token'];
		$this->session->RefreshToken = $token_data['refresh_token'] ?? null;
		$this->session->ExpiresAt    = time() + ($token_data['expires_in'] ?? 3600);

		$result = $this->Login->Authorization->AuthorizeIdp();

		// Auto-link / existing-link: log the user in and go to dashboard.
		if (isset($result['IdpResult']) && $result['IdpResult'] === Authorization::IDP_RESULT_LOGGED_IN
			&& isset($result['Status']['Status']) && $result['Status']['Status'] === 0) {
			$this->session->user_id  = $result['UserId'];
			$this->session->user_name = $result['UserName'];
			$this->session->token    = $result['Token'];
			$this->session->timeout  = $result['Timeout'];
			// Power-user opt-in: came in via the IDP button, set the autoredirect cookie.
			setcookie('ork_idp_autoredirect', '1', time() + 60 * 60 * 24 * 365, '/');
			header('Location: ' . UIR);
			return;
		}

		// Needs a manual claim — redirect to the claim form.
		if (isset($result['IdpResult']) && $result['IdpResult'] === Authorization::IDP_RESULT_NEEDS_CLAIM) {
			header('Location: ' . UIR . 'Login/claim_profile');
			return;
		}

		// Fallthrough: treat as failure.
		$this->data['error'] = $result['Status']['Error'] ?? 'Authentication failed';
		$this->data['detail'] = $result['Status']['Detail'] ?? '';
		$this->template = '../revised-frontend/Login_index.tpl';
	}
}'''

assert old in t, 'oauth_callback block not found verbatim'
p.write_text(t.replace(old, new, 1))
print('oauth_callback refactored')
PY
```

- [ ] **Step 2: Lint**

```bash
docker exec ork3-php8 php -l /var/www/orkui/controller/controller.Login.php
```

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.Login.php
git commit -m "Refactor: oauth_callback dispatches between dashboard login and claim flow"
```

---

## Task 9: Add `claim_profile` controller action

**Files:**
- Modify: `orkui/controller/controller.Login.php`

- [ ] **Step 1: Insert the action before the closing `}`**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Login.php')
t = p.read_text()

# Anchor: the new oauth_callback's final closing brace + class closing brace
anchor = '''		$this->data['error'] = $result['Status']['Error'] ?? 'Authentication failed';
		$this->data['detail'] = $result['Status']['Detail'] ?? '';
		$this->template = '../revised-frontend/Login_index.tpl';
	}
}'''

inject = '''		$this->data['error'] = $result['Status']['Error'] ?? 'Authentication failed';
		$this->data['detail'] = $result['Status']['Detail'] ?? '';
		$this->template = '../revised-frontend/Login_index.tpl';
	}

	public function claim_profile()
	{
		if (!isset($this->session->IdpUserId) || strlen($this->session->IdpUserId) === 0) {
			$this->data['error'] = 'Session expired — please start over.';
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}
		$this->data['idp_email'] = $this->session->Email;
		$this->template = '../revised-frontend/Login_claim.tpl';
	}
}'''

assert anchor in t, 'Anchor not found'
p.write_text(t.replace(anchor, inject, 1))
print('claim_profile added')
PY
```

- [ ] **Step 2: Lint**

```bash
docker exec ork3-php8 php -l /var/www/orkui/controller/controller.Login.php
```

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.Login.php
git commit -m "Enhancement: Login/claim_profile renders the IDP claim form"
```

---

## Task 10: Add `claim_submit` (password path)

**Files:**
- Modify: `orkui/controller/controller.Login.php`

- [ ] **Step 1: Insert the action**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Login.php')
t = p.read_text()

anchor = '''		$this->data['idp_email'] = $this->session->Email;
		$this->template = '../revised-frontend/Login_claim.tpl';
	}
}'''

inject = '''		$this->data['idp_email'] = $this->session->Email;
		$this->template = '../revised-frontend/Login_claim.tpl';
	}

	public function claim_submit()
	{
		if (!isset($this->session->IdpUserId) || strlen($this->session->IdpUserId) === 0) {
			$this->data['error'] = 'Session expired — please start over.';
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}

		$username = trim($_POST['username'] ?? '');
		$password = $_POST['password'] ?? '';
		if (strlen($username) === 0 || strlen($password) === 0) {
			$this->data['idp_email'] = $this->session->Email;
			$this->data['error'] = 'Enter both your ORK username and password.';
			$this->template = '../revised-frontend/Login_claim.tpl';
			return;
		}

		$claim = [
			'IdpUserId'    => $this->session->IdpUserId,
			'Email'        => $this->session->Email,
			'AccessToken'  => $this->session->AccessToken,
			'RefreshToken' => $this->session->RefreshToken,
			'ExpiresAt'    => $this->session->ExpiresAt,
		];

		$result = $this->Login->Authorization->verifyClaimCredentials($username, $password, $claim);

		if (isset($result['Status']['Status']) && $result['Status']['Status'] === 0) {
			$this->session->user_id   = $result['UserId'];
			$this->session->user_name = $result['UserName'];
			$this->session->token     = $result['Token'];
			$this->session->timeout   = $result['Timeout'];
			setcookie('ork_idp_autoredirect', '1', time() + 60 * 60 * 24 * 365, '/');
			header('Location: ' . UIR);
			return;
		}

		$this->data['idp_email'] = $this->session->Email;
		$this->data['error'] = $result['Status']['Error'] ?? 'Username or password incorrect';
		$this->template = '../revised-frontend/Login_claim.tpl';
	}
}'''

assert anchor in t, 'Anchor not found'
p.write_text(t.replace(anchor, inject, 1))
print('claim_submit added')
PY
```

- [ ] **Step 2: Lint**

```bash
docker exec ork3-php8 php -l /var/www/orkui/controller/controller.Login.php
```

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.Login.php
git commit -m "Enhancement: Login/claim_submit verifies ORK creds and finalizes IDP link"
```

---

## Task 11: Add `claim_request_magic_link` (issue + email)

**Files:**
- Modify: `orkui/controller/controller.Login.php`

- [ ] **Step 1: Insert the action**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Login.php')
t = p.read_text()

anchor = '''		$this->data['idp_email'] = $this->session->Email;
		$this->data['error'] = $result['Status']['Error'] ?? 'Username or password incorrect';
		$this->template = '../revised-frontend/Login_claim.tpl';
	}
}'''

inject = '''		$this->data['idp_email'] = $this->session->Email;
		$this->data['error'] = $result['Status']['Error'] ?? 'Username or password incorrect';
		$this->template = '../revised-frontend/Login_claim.tpl';
	}

	public function claim_request_magic_link()
	{
		if (!isset($this->session->IdpUserId) || strlen($this->session->IdpUserId) === 0) {
			$this->data['error'] = 'Session expired — please start over.';
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}

		$username = trim($_POST['username'] ?? '');
		if (strlen($username) === 0) {
			$this->data['idp_email'] = $this->session->Email;
			$this->data['error'] = 'Enter your ORK username so we know where to send the link.';
			$this->template = '../revised-frontend/Login_claim.tpl';
			return;
		}

		$claim = [
			'IdpUserId' => $this->session->IdpUserId,
			'Email'     => $this->session->Email,
		];

		$issued = $this->Login->Authorization->issueClaimMagicLink($username, $claim);

		// Always show the same banner (no info disclosure on whether the username exists).
		$this->data['idp_email'] = $this->session->Email;
		$this->data['notice']    = 'If that username has an ORK account, we just emailed a one-time link to the address on file. Open it in this same browser to finish linking.';

		if ($issued !== false) {
			$link = UIR . 'Login/claim_magic_link?token=' . $issued['token'];
			$m = new Mail('smtp', AMAZON_SES_HOST, AMAZON_SES_USERNAME, AMAZON_SES_PASSWORD, 587);
			$m->setTo($issued['email']);
			$m->setFrom('ork3@amtgard.com');
			$m->setSender('ork3@amtgard.com');
			$m->setSubject('Connect your Amtgard ORK profile (link expires in 24 hours)');
			$m->setHtml(
				'<h2>Connect your ORK profile</h2>' .
				'You requested a one-time link to connect your ORK profile <b>' . htmlspecialchars($issued['username']) . '</b> ' .
				'to your Amtgard IDP account (' . htmlspecialchars($claim['Email']) . ').' .
				'<p><a href="' . $link . '">Click here to finish linking</a> — this link expires in 24 hours and works only once.' .
				'<p>If you did not request this, you can safely ignore this email.' .
				'<p>Regards,<br>-ORK Team'
			);
			$m->send();
		}

		$this->template = '../revised-frontend/Login_claim.tpl';
	}
}'''

assert anchor in t, 'Anchor not found'
p.write_text(t.replace(anchor, inject, 1))
print('claim_request_magic_link added')
PY
```

- [ ] **Step 2: Lint**

```bash
docker exec ork3-php8 php -l /var/www/orkui/controller/controller.Login.php
```

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.Login.php
git commit -m "Enhancement: Login/claim_request_magic_link issues and emails a one-time link"
```

---

## Task 12: Add `claim_magic_link` (consume token)

**Files:**
- Modify: `orkui/controller/controller.Login.php`

- [ ] **Step 1: Insert the action**

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/controller/controller.Login.php')
t = p.read_text()

anchor = '''		$this->template = '../revised-frontend/Login_claim.tpl';
	}
}'''

inject = '''		$this->template = '../revised-frontend/Login_claim.tpl';
	}

	public function claim_magic_link()
	{
		$token = $_GET['token'] ?? '';
		$result = $this->Login->Authorization->consumeMagicLink($token);

		if (isset($result['Status']['Status']) && $result['Status']['Status'] === 0) {
			$this->session->user_id   = $result['UserId'];
			$this->session->user_name = $result['UserName'];
			$this->session->token     = $result['Token'];
			$this->session->timeout   = $result['Timeout'];
			setcookie('ork_idp_autoredirect', '1', time() + 60 * 60 * 24 * 365, '/');
			header('Location: ' . UIR);
			return;
		}

		$this->data['error'] = $result['Status']['Error'] ?? "That link isn't valid.";
		$this->template = '../revised-frontend/Login_index.tpl';
	}
}'''

# anchor must be unique in the file at this point - this is the last anchor we're modifying
# It's the closing of claim_request_magic_link followed by the class closing brace
# To make it unique, include preceding context
broader_anchor = '''		$this->template = '../revised-frontend/Login_claim.tpl';
	}
}'''
# Count occurrences
n = t.count(broader_anchor)
assert n == 1, f'anchor matches {n} times, expected 1'
p.write_text(t.replace(broader_anchor, inject, 1))
print('claim_magic_link added')
PY
```

- [ ] **Step 2: Lint**

```bash
docker exec ork3-php8 php -l /var/www/orkui/controller/controller.Login.php
```

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.Login.php
git commit -m "Enhancement: Login/claim_magic_link consumes one-time link to finalize IDP link"
```

---

## Task 13: Create `Login_claim.tpl`

**Files:**
- Create: `orkui/template/revised-frontend/Login_claim.tpl`

- [ ] **Step 1: Read an existing revised-frontend template to confirm the layout wrapper**

```bash
sed -n '1,40p' orkui/template/revised-frontend/Login_index.tpl
```

Note the page wrapper / Smarty conventions used (e.g. `{include file=...}` headers, body class). Mirror them in the new template.

- [ ] **Step 2: Write the template**

Use the Write tool. Match the wrapping conventions from `Login_index.tpl` — the structure below shows the *content area only*; place it inside the same surrounding markup that `Login_index.tpl` uses (header include, footer include, body class). All headings inside the card explicitly reset the global `h1–h6` styles per the project memory rule.

```html
<style>
.lc-card { max-width: 480px; margin: 60px auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); padding: 32px; }
.lc-card h2 { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; margin: 0 0 8px 0; font-size: 22px; }
.lc-card .lc-sub { color: #666; margin-bottom: 24px; font-size: 14px; }
.lc-card label { display: block; font-weight: 600; margin: 12px 0 4px; }
.lc-card input[type=text], .lc-card input[type=password] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 15px; box-sizing: border-box; }
.lc-card .lc-primary { width: 100%; margin-top: 20px; padding: 12px; background: #b22222; color: #fff; border: none; border-radius: 4px; font-size: 16px; font-weight: 600; cursor: pointer; }
.lc-card .lc-primary:hover { background: #8b0000; }
.lc-card .lc-secondary { display: block; margin-top: 16px; text-align: center; color: #555; font-size: 13px; }
.lc-card .lc-footer { margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; color: #777; font-size: 13px; }
.lc-card .lc-error { background: #fee; color: #900; padding: 10px 12px; border-radius: 4px; margin-bottom: 16px; font-size: 14px; }
.lc-card .lc-notice { background: #eef7ee; color: #2a5a2a; padding: 10px 12px; border-radius: 4px; margin-bottom: 16px; font-size: 14px; }
.lc-tabs { display: flex; gap: 0; margin-bottom: 16px; border-bottom: 1px solid #ddd; }
.lc-tab { flex: 1; padding: 10px; text-align: center; cursor: pointer; color: #666; border-bottom: 2px solid transparent; }
.lc-tab.lc-active { color: #b22222; border-bottom-color: #b22222; font-weight: 600; }
.lc-pane { display: none; }
.lc-pane.lc-active { display: block; }
</style>

<div class="lc-card">
    <h2>Connect your ORK profile</h2>
    <div class="lc-sub">You're signed in as <b>{$idp_email|escape}</b> via Amtgard IDP. Connect your existing ORK profile to finish.</div>

    {if $error}<div class="lc-error">{$error|escape}</div>{/if}
    {if $notice}<div class="lc-notice">{$notice|escape}</div>{/if}

    <div class="lc-tabs">
        <div class="lc-tab lc-active" data-tab="pwd">Use my password</div>
        <div class="lc-tab" data-tab="email">Email me a link</div>
    </div>

    <div class="lc-pane lc-active" data-pane="pwd">
        <form method="post" action="{$UIR}Login/claim_submit">
            <label for="lc-username">ORK username</label>
            <input type="text" id="lc-username" name="username" autocomplete="username" required>
            <label for="lc-password">ORK password</label>
            <input type="password" id="lc-password" name="password" autocomplete="current-password" required>
            <button type="submit" class="lc-primary">Connect ORK profile</button>
        </form>
    </div>

    <div class="lc-pane" data-pane="email">
        <form method="post" action="{$UIR}Login/claim_request_magic_link">
            <label for="lc-username-email">ORK username</label>
            <input type="text" id="lc-username-email" name="username" autocomplete="username" required>
            <button type="submit" class="lc-primary">Email me a one-time link</button>
            <div class="lc-secondary">We'll send the link to the email address on file for that username.</div>
        </form>
    </div>

    <div class="lc-footer">
        Don't have an ORK profile yet? Ask your park's Prime Minister to create one for you, then come back here.
    </div>
</div>

<script>
(function() {
    var tabs = document.querySelectorAll('.lc-card .lc-tab');
    var panes = document.querySelectorAll('.lc-card .lc-pane');
    tabs.forEach(function(t) {
        t.addEventListener('click', function() {
            tabs.forEach(function(x) { x.classList.remove('lc-active'); });
            panes.forEach(function(x) { x.classList.remove('lc-active'); });
            t.classList.add('lc-active');
            var name = t.getAttribute('data-tab');
            document.querySelector('.lc-card .lc-pane[data-pane="' + name + '"]').classList.add('lc-active');
        });
    });
})();
</script>
```

**Note:** Wrap this content in whatever header/footer includes `Login_index.tpl` uses. If the existing template is just naked content with the layout coming from a controller-level wrapper, leave the content as-is.

- [ ] **Step 3: Smoke check the template renders by visiting the URL**

Navigate to `http://localhost:19080/orkui/Login/claim_profile` in a browser. If you get *"Session expired — please start over"* that's expected (you have no IDP session). The template parsed successfully if you see the styled card + that error.

To force-render the form for visual testing, temporarily comment out the session guard in `claim_profile`, hit the URL, then restore the guard. Don't commit the commented-out version.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Login_claim.tpl
git commit -m "Enhancement: Login_claim template for IDP profile claim form"
```

---

## Task 14: Promote IDP button on `Login_index.tpl` (revised-frontend)

**Files:**
- Modify: `orkui/template/revised-frontend/Login_index.tpl`

- [ ] **Step 1: Read the current button area**

```bash
grep -n -A 3 -B 1 "Sign in with Amtgard" orkui/template/revised-frontend/Login_index.tpl
```

Note the surrounding markup — the button currently sits at line ~382 per spec exploration.

- [ ] **Step 2: Promote the IDP button to primary, demote the legacy form to a `<details>` disclosure**

The current layout (lines ~366–389) is:
1. Welcome heading
2. Username/password form (primary button)
3. "or" divider
4. "Sign in with Amtgard" button (secondary)
5. Forgot password link

Swap so the IDP button is primary, then a divider, then the legacy form wrapped in a disclosure. Run this Python (it preserves tabs):

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Login_index.tpl')
t = p.read_text()

old = '''		<form action="<?= UIR ?>Login/login" method="POST">
			<div class="lg-field">
				<label class="lg-label" for="lg-username">Username</label>
				<input class="lg-input" type="text" id="lg-username" name="username" autocomplete="username" autofocus />
			</div>
			<div class="lg-field">
				<label class="lg-label" for="lg-password">Password</label>
				<input class="lg-input" type="password" id="lg-password" name="password" autocomplete="current-password" value="<?= htmlspecialchars($_GET['pw'] ?? '') ?>" />
			</div>
			<button type="submit" class="lg-btn-primary">
				<i class="fas fa-sign-in-alt" style="margin-right:7px"></i> Sign In
			</button>
		</form>

		<div class="lg-divider">or</div>

		<button type="button" class="lg-btn-oauth" onclick="window.location='<?= UIR ?>Login/login_oauth'">
			<img src="<?= HTTP_ASSETS ?>images/amtgard_idp_favicon.png" alt="Amtgard IDP" />
			Sign in with Amtgard
		</button>

		<div class="lg-links">
			<a href="<?= UIR ?>Login/forgotpassword"><i class="fas fa-key" style="margin-right:4px;opacity:0.6"></i>Forgot your password?</a>
		</div>'''

new = '''		<button type="button" class="lg-btn-oauth lg-btn-oauth-primary" onclick="window.location='<?= UIR ?>Login/login_oauth'">
			<img src="<?= HTTP_ASSETS ?>images/amtgard_idp_favicon.png" alt="Amtgard IDP" />
			Sign in with Amtgard
		</button>
		<div class="lg-oauth-sub">One sign-in shared across Amtgard apps. New here? Pick Google, Discord, or Facebook on the next screen.</div>

		<details class="lg-legacy-disclosure">
			<summary>Use legacy ORK login</summary>
			<form action="<?= UIR ?>Login/login" method="POST">
				<div class="lg-field">
					<label class="lg-label" for="lg-username">Username</label>
					<input class="lg-input" type="text" id="lg-username" name="username" autocomplete="username" />
				</div>
				<div class="lg-field">
					<label class="lg-label" for="lg-password">Password</label>
					<input class="lg-input" type="password" id="lg-password" name="password" autocomplete="current-password" value="<?= htmlspecialchars($_GET['pw'] ?? '') ?>" />
				</div>
				<button type="submit" class="lg-btn-primary">
					<i class="fas fa-sign-in-alt" style="margin-right:7px"></i> Sign In
				</button>
			</form>
			<div class="lg-links">
				<a href="<?= UIR ?>Login/forgotpassword"><i class="fas fa-key" style="margin-right:4px;opacity:0.6"></i>Forgot your password?</a>
				<a href="#" id="lc-different-account" style="margin-left:12px"><i class="fas fa-user-times" style="margin-right:4px;opacity:0.6"></i>Sign in with a different account</a>
			</div>
		</details>'''

assert old in t, 'Old markup not found verbatim — re-grep and adjust'
p.write_text(t.replace(old, new, 1))
print('Login_index.tpl button promotion applied')
PY
```

- [ ] **Step 2b: Add styles for the new primary OAuth button + disclosure**

Append these styles inside the existing `<style>` block (right before `</style>` near line 344). Run:

```bash
python3 <<'PY'
import pathlib
p = pathlib.Path('orkui/template/revised-frontend/Login_index.tpl')
t = p.read_text()

old = '''@media (max-width: 700px) {
	.lg-wrap {
		flex-direction: column;
		max-width: 100%;
		border-radius: 8px;
	}
	.lg-form-panel {
		flex: unset;
		padding: 32px 24px 28px;
	}
	.lg-features-panel {
		padding: 28px 24px;
	}
}
</style>'''

new = '''@media (max-width: 700px) {
	.lg-wrap {
		flex-direction: column;
		max-width: 100%;
		border-radius: 8px;
	}
	.lg-form-panel {
		flex: unset;
		padding: 32px 24px 28px;
	}
	.lg-features-panel {
		padding: 28px 24px;
	}
}
.lg-btn-oauth-primary {
	width: 100%;
	padding: 14px;
	font-size: 16px;
	font-weight: 600;
	background: #b22222;
	color: #fff;
	border: none;
	border-radius: 6px;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 10px;
	margin-top: 8px;
}
.lg-btn-oauth-primary:hover { background: #8b0000; }
.lg-btn-oauth-primary img { height: 22px; width: 22px; }
.lg-oauth-sub {
	margin-top: 10px;
	color: #666;
	font-size: 13px;
	text-align: center;
}
.lg-legacy-disclosure {
	margin-top: 28px;
	padding-top: 16px;
	border-top: 1px solid #eee;
}
.lg-legacy-disclosure summary {
	cursor: pointer;
	color: #555;
	font-size: 14px;
	padding: 6px 0;
}
.lg-legacy-disclosure[open] summary { margin-bottom: 12px; }
</style>'''

assert old in t, 'Style anchor not found'
p.write_text(t.replace(old, new, 1))
print('Promoted OAuth button styles added')
PY
```

- [ ] **Step 3: Add the autoredirect script at the bottom of the template (before any closing layout tag)**

```html
<script>
(function() {
    // Honor the opt-in autoredirect cookie: if set, jump straight to the IDP.
    if (document.cookie.indexOf('ork_idp_autoredirect=1') !== -1) {
        window.location = '{$UIR}Login/login_oauth';
        return;
    }
    // "Sign in with a different account" escape hatch.
    var diff = document.getElementById('lc-different-account');
    if (diff) {
        diff.addEventListener('click', function(e) {
            e.preventDefault();
            document.cookie = 'ork_idp_autoredirect=0; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
            location.reload();
        });
    }
})();
</script>
```

Per project memory: do **not** use `document.getElementById(...)` as an IIFE guard — it's used here only for the click handler, which is fine because we're inside the IIFE body, not guarding it.

- [ ] **Step 4: Visual smoke test**

Open `http://localhost:19080/orkui/Login` in a normal browser tab. Verify:
- "Sign in with Amtgard" button is the primary visual element
- Legacy username/password collapses behind a disclosure
- No autoredirect happens (cookie not set)

Then in the browser console: `document.cookie = 'ork_idp_autoredirect=1; path=/'` and reload. Verify the page immediately redirects to `/orkui/Login/login_oauth`. Then clear the cookie: `document.cookie = 'ork_idp_autoredirect=0; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'`.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Login_index.tpl
git commit -m "Enhancement: Promote Sign in with Amtgard as primary login, add autoredirect cookie"
```

---

## Task 15: Same promotion for `default/Login_index.tpl`

**Files:**
- Modify: `orkui/template/default/Login_index.tpl`

- [ ] **Step 1: Apply the same promotion + script as Task 14**

This template is the legacy fallback. Same intent, same script, same cookie name. Use the same Python-based surgery approach.

- [ ] **Step 2: Verify it parses (Smarty doesn't have a CLI lint; just navigate to the page if a route serves it)**

If the default template isn't used by any current route, it's still worth keeping in sync — the `default/` and `revised-frontend/` templates are paired in this project.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/default/Login_index.tpl
git commit -m "Enhancement: Mirror IDP button promotion to default Login template"
```

---

## Task 16: Cron retry for failed mirror calls

**Files:**
- Create: `cron/idp-mirror-retry.php`

**Note:** No `cron/` directory currently exists. This task creates it.

- [ ] **Step 1: Create the directory and write the script**

```bash
mkdir -p cron
```

Then use the Write tool to create `cron/idp-mirror-retry.php`:

```php
<?php
/**
 * Hourly retry job for failed bastion-idp mirror writes.
 * Picks up ork_idp_auth rows where idp_mirror_status != 'synced'
 * and idp_mirror_last_attempt is null or older than 30 minutes.
 *
 * Run via system cron:
 *   0 * * * * php /var/www/cron/idp-mirror-retry.php
 */

require_once dirname(__DIR__) . '/system/lib/system.php';

global $DB;
$DB->Clear();
$rows = $DB->DataSet(
    "SELECT idp_user_id, mundane_id FROM " . DB_PREFIX . "idp_auth " .
    "WHERE idp_mirror_status IN ('pending','failed') " .
    "AND (idp_mirror_last_attempt IS NULL OR idp_mirror_last_attempt < DATE_SUB(NOW(), INTERVAL 30 MINUTE)) " .
    "LIMIT 100"
);

if (!is_array($rows) || count($rows) === 0) {
    echo "No rows to retry.\n";
    exit(0);
}

require_once dirname(__DIR__) . '/orkui/model/Model.php';
require_once dirname(__DIR__) . '/orkui/model/model.AmtgardIdpLink.php';

$model = new Model_AmtgardIdpLink();
$ok = 0;
$fail = 0;
foreach ($rows as $row) {
    $success = $model->linkOrkProfile($row['idp_user_id'], $row['mundane_id']);
    $status = $success ? 'synced' : 'failed';
    $DB->Clear();
    $DB->Execute(
        "UPDATE " . DB_PREFIX . "idp_auth SET idp_mirror_status = ?, idp_mirror_last_attempt = NOW() " .
        "WHERE idp_user_id = ? AND mundane_id = ?",
        array($status, $row['idp_user_id'], (int)$row['mundane_id'])
    );
    if ($success) { $ok++; } else { $fail++; }
}
echo "Mirror retry: $ok synced, $fail failed.\n";
```

- [ ] **Step 2: Lint**

```bash
docker exec ork3-php8 php -l /var/www/cron/idp-mirror-retry.php
```

Expected: `No syntax errors detected`. The require paths assume the script is run from inside the docker container. If not, the script will produce a clear error and the implementer can adjust the bootstrap include.

- [ ] **Step 3: Smoke run (will be a no-op on a fresh DB)**

```bash
docker exec ork3-php8 php /var/www/cron/idp-mirror-retry.php
```

Expected output: `No rows to retry.`

- [ ] **Step 4: Commit**

```bash
git add cron/idp-mirror-retry.php
git commit -m "Enhancement: Hourly cron job to retry failed bastion-idp mirror writes"
```

---

## Task 17: Documentation for the bastion-idp PR

**Files:**
- Create: `docs/integrations/bastion-idp-link-endpoint.md`

This documents what needs to happen in the *other* repo (`amtgard/amtgard-bastion-idp`). It is **not** a PR — it's the spec we hand to whoever owns that repo.

- [ ] **Step 1: Write the doc**

```markdown
# bastion-idp: `POST /resources/link-ork-profile`

Companion endpoint required by ORK's streamlined "Sign in with Amtgard" claim flow. ORK calls this after a user has successfully proven ownership of an ORK profile, so that the IDP's `userinfo` response includes `ork_profile.mundane_id` for that user from then on (which lets other Amtgard apps that trust the IDP see the link too).

## Endpoint

`POST /resources/link-ork-profile`

## Authentication

Confidential client basic auth. Use the existing `ORK_CLIENT_ID` / `ORK_CLIENT_SECRET` pair already configured for the ORK confidential client. No bearer token — this is a server-to-server call, not on behalf of an end user.

## Request

```http
POST /resources/link-ork-profile HTTP/1.1
Host: idp.amtgard.com
Authorization: Basic base64(ORK_CLIENT_ID:ORK_CLIENT_SECRET)
Content-Type: application/json

{
  "idp_user_id": "abc123...",
  "mundane_id": 12345
}
```

## Response

- `204 No Content` on success
- `400 Bad Request` if either field is missing or malformed
- `401 Unauthorized` if client credentials are missing/invalid
- `403 Forbidden` if the calling client is not allowed to write IDP→ORK links (only the ORK confidential client should be)
- `404 Not Found` if `idp_user_id` is unknown to the IDP

## Idempotency

Re-posting the same `(idp_user_id, mundane_id)` pair is a no-op. Posting a new `mundane_id` for an `idp_user_id` that already has a different one **MUST** either reject (409 Conflict) or update — the IDP team should pick the policy that matches their existing data model. ORK currently treats all non-2xx as a retryable failure.

## Behavior

The endpoint should update whatever join table the IDP uses to associate `users` with `ork_profile`. After this call, `GET /resources/userinfo` for the same access token should return `ork_profile.mundane_id = 12345`.

## Why ORK needs this

Today, an unlinked IDP user who signs into ORK gets *"User not found and could not be automatically linked"* and bounces. The new ORK claim flow lets users prove ownership of an ORK profile through ORK itself (password or magic link). After they do, ORK has the link locally — but other Amtgard apps that hit the IDP for `userinfo` won't see it until the IDP is told. This endpoint closes that loop.

## ORK-side caller (for reference)

`orkui/model/model.AmtgardIdpLink.php::linkOrkProfile($idpUserId, $mundaneId)` — POSTs the JSON above. On any non-2xx, ORK marks `ork_idp_auth.idp_mirror_status = 'failed'` and an hourly cron retries.
```

- [ ] **Step 2: Commit**

```bash
mkdir -p docs/integrations
git add docs/integrations/bastion-idp-link-endpoint.md
git commit -m "Docs: bastion-idp link-ork-profile endpoint spec for upstream PR"
```

---

## Task 18: End-to-end manual browser walkthroughs

**Files:**
- None (manual testing — no test harness in this project)

This task is the project's primary verification mechanism. Walk every path; record outcomes in the PR description.

**Prerequisite:** A local `bastion-idp` dev container running at `http://localhost:37080`. If unavailable, mock the endpoint or pause this task and arrange a real one.

- [ ] **Step 1: Path 1 — Auto-link by email (happy path)**

  1. Pick a test ORK profile with a known `email`. Ensure no `ork_idp_auth` row exists for it: `docker exec ork3-php8-db mariadb -u root -proot ork -e "DELETE FROM ork_idp_auth WHERE mundane_id = <id>;"`
  2. In a fresh private browser window, go to `http://localhost:19080/orkui/Login`
  3. Click "Sign in with Amtgard"
  4. On the IDP, sign in with a Google/test account whose email **exactly matches** the ORK profile's email
  5. Expected: redirected straight to the ORK dashboard, logged in
  6. Verify: `docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT * FROM ork_idp_auth WHERE mundane_id = <id>\\G"` shows a fresh row with `idp_mirror_status = 'synced'` (or `'failed'` if bastion-idp doesn't yet have the endpoint — that's expected and OK for now)
  7. Verify: `document.cookie` in the dashboard's console contains `ork_idp_autoredirect=1`

- [ ] **Step 2: Path 2 — Claim by password (no email match)**

  1. Pick another test ORK profile, change its email to something the IDP test account does **not** match. Delete any `ork_idp_auth` row.
  2. Sign in via "Sign in with Amtgard" with the IDP test account
  3. Expected: redirected to `/orkui/Login/claim_profile`, see the claim card titled *"Connect your ORK profile"* with the IDP email displayed
  4. On the *"Use my password"* tab, enter the ORK username + password
  5. Click "Connect ORK profile"
  6. Expected: redirected to dashboard, logged in
  7. Verify the row in `ork_idp_auth`

- [ ] **Step 3: Path 2b — Wrong password**

  1. Repeat Step 2 setup
  2. Enter the right username + a wrong password
  3. Expected: claim form re-renders with *"Username or password incorrect"*

- [ ] **Step 4: Path 2c — Magic link**

  1. Repeat Step 2 setup
  2. Click *"Email me a link"* tab
  3. Enter the ORK username
  4. Click "Email me a one-time link"
  5. Expected: notice banner *"If that username has an ORK account, we just emailed..."*
  6. Check the on-file email inbox (or your local mail trap) — there should be a fresh email with the link
  7. Click the link **in the same browser**
  8. Expected: redirected to dashboard, logged in. `ork_idp_auth` row exists. `ork_idp_claim_token` row has `consumed_at` set.
  9. Click the same link again — expected: *"That link has already been used."*

- [ ] **Step 5: Path 2d — Multi-match email**

  1. Set up two ORK profiles with the same email (artificially: `UPDATE ork_mundane SET email = 'shared@example.com' WHERE mundane_id IN (X, Y);`). Delete any `ork_idp_auth` rows for both.
  2. Sign in with an IDP account whose email is `shared@example.com`
  3. Expected: lands on the claim form (auto-link skipped due to ambiguity). Optionally check that `error_log` shows `tryAutoLinkByEmail matched 2 rows for shared@example.com`.
  4. Restore the original emails after the test.

- [ ] **Step 6: Path 3 — Returning user one-click**

  1. With a profile already linked from Step 1 or Step 2, log out
  2. Clear the `ork_idp_autoredirect` cookie: in console, `document.cookie = 'ork_idp_autoredirect=0; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'`
  3. Land on `/orkui/Login`. Verify the IDP button is the primary visual.
  4. Click "Sign in with Amtgard" (one click)
  5. Expected: silent IDP round-trip, lands on dashboard

- [ ] **Step 7: Path 4 — Returning user zero-click**

  1. With the autoredirect cookie set (it was set in Step 1/2/6), log out
  2. Land on `/orkui/Login`
  3. Expected: page immediately redirects to `/orkui/Login/login_oauth` without any click. Lands on dashboard.

- [ ] **Step 8: Path 4 escape hatch**

  1. With the autoredirect cookie set, navigate directly to `/orkui/Login?msg=session_replaced` (or any URL that should land on the login page)
  2. The page will redirect — that's the bug we want to handle. Open a fresh window and clear the cookie via DevTools instead.
  3. Open the legacy disclosure → click *"Sign in with a different account"* → verify cookie cleared and page reloads without redirecting.

- [ ] **Step 9: Path 5 — Legacy login still works**

  1. Click the *"Use legacy ORK login"* disclosure
  2. Enter ORK username + password
  3. Verify it still works (no regression)

- [ ] **Step 10: Stale claim session**

  1. Sign in with IDP, land on `/orkui/Login/claim_profile`
  2. In the browser console: `document.cookie.split(';').forEach(c => console.log(c))` to see session cookie name; clear it.
  3. Submit the form
  4. Expected: *"Session expired — please start over."* banner on the login page

- [ ] **Step 11: Already-linked profile, attempt to re-link from a different IDP id**

  1. Profile X has `idp_user_id = A` in `ork_idp_auth`
  2. Sign in with IDP using a different account → `idp_user_id = B`
  3. On the claim form, enter Profile X's ORK credentials
  4. Expected: *"This ORK profile is already linked to another Amtgard account."*

- [ ] **Step 12: Migration rollback drill**

  1. Take a DB snapshot: `docker exec ork3-php8-db mariadb-dump -u root -proot ork > /tmp/ork-pre-rollback.sql`
  2. Run: `docker exec ork3-php8-db mariadb -u root -proot ork -e "DROP TABLE ork_idp_claim_token; ALTER TABLE ork_idp_auth DROP COLUMN idp_mirror_status, DROP COLUMN idp_mirror_last_attempt;"`
  3. Verify the old `AuthorizeIdp` call paths (existing-link sign-in) still work for previously linked users
  4. Re-run the migration to restore: `docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-04-13-idp-claim-flow.sql`

- [ ] **Step 13: Record results in the PR description**

When opening the PR, paste the outcome of each path as a checklist. Note any path that couldn't be tested (e.g., bastion-idp endpoint not yet shipped — `idp_mirror_status` will stay `failed` until the upstream PR merges).

---

## Plan complete

After Task 18, the feature is end-to-end testable on a local stack. The bastion-idp PR (Task 17's spec) is a parallel work item that doesn't block this feature from shipping — ORK's local link is the source of truth for ORK login, and the cron job will catch up other Amtgard apps once the upstream endpoint exists.

### Self-review checklist (run before opening PR)

- [ ] No `true ||` bypass present in `class.Authorization.php` lines 327/330
- [ ] `class.Authorization.php` was staged explicitly each commit, never via `git add -A`
- [ ] Migration is reversible (Task 1 Step 4 verified)
- [ ] `php -l` clean on all modified PHP files
- [ ] All five user paths walked end-to-end (Task 18)
- [ ] PR title starts with `Enhancement:` per project convention (e.g. `Enhancement: Streamlined Login with Amtgard claim flow`)
- [ ] PR description links to the spec (`docs/superpowers/specs/2026-04-13-login-with-amtgard-workflow-design.md`) and the bastion-idp endpoint doc
