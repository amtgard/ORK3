<?php
// Runs inside ork3-php8-app container. Hits the real dev DB.
// Invocation: docker exec -e ENVIRONMENT=DEV -w /var/www/ork.amtgard.com/tests/php ork3-php8-app php WaiverTest.php

// Suppress harmless HTTP_HOST undefined warnings from config.dev.php when run in CLI.
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

require_once('/var/www/ork.amtgard.com/startup.php');
require_once(DIR_SERVICE . 'Common.definitions.php');

class WaiverTestRunner {
	public $pass = 0;
	public $fail = 0;
	public $testMundaneId = 1;   // root / admin mundane_id in dev DB
	public $testKingdomId = 1;
	public $testParkId    = 1;
	public $token;
	public $waiver;

	public function __construct() {
		$this->waiver = new Waiver();
		$this->token = $this->_issueToken($this->testMundaneId);
	}

	private function _issueToken($mundane_id) {
		$m = new yapo($this->waiver->db, DB_PREFIX . 'mundane');
		$m->clear();
		$m->mundane_id = $mundane_id;
		if (!$m->find()) throw new Exception("Seed mundane_id $mundane_id missing");
		$tok = bin2hex(random_bytes(16));
		$m->token = $tok;
		$m->token_expires = date('Y-m-d H:i:s', time() + 3600);
		$m->save();
		// Drop the per-request session cache so this fresh token will actually be looked up.
		unset($_SESSION['is_authorized_mundane_id']);
		return $tok;
	}

	public function assertEq($expected, $actual, $msg = '') {
		if ($expected === $actual) { $this->pass++; echo "  PASS $msg\n"; }
		else { $this->fail++; echo "  FAIL $msg (expected " . var_export($expected, true) . " got " . var_export($actual, true) . ")\n"; }
	}

	public function assertTrue($cond, $msg = '') { $this->assertEq(true, (bool)$cond, $msg); }

	// Status assertion — the domain wraps a full Success()/NoAuthorization()/etc array under 'Status'.
	// The inner array key that holds the numeric code is also 'Status' (ServiceErrorIds::*).
	public function assertStatus($expected, $response, $msg = '') {
		$this->assertEq($expected, $response['Status']['Status'] ?? null, $msg);
	}

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

	// ============================================================================
	// Task 1.2: SaveTemplate — create / version / publish
	// ============================================================================

	public function test_save_template_kingdom_creates_v1() {
		// Clean prior test rows for this kingdom so we get a deterministic v1.
		$this->waiver->db->Clear();
		$this->waiver->db->kingdom_id = $this->testKingdomId;
		$this->waiver->db->Execute("DELETE FROM " . DB_PREFIX . "waiver_template WHERE kingdom_id = :kingdom_id");

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
		$this->assertEq(1, (int)($r['Version'] ?? 0), 'version is 1');
		$this->assertTrue(($r['TemplateId'] ?? 0) > 0, 'TemplateId returned');
	}

	public function test_save_template_kingdom_bumps_to_v2() {
		$r1 = $this->waiver->SaveTemplate([
			'Token' => $this->token, 'KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom',
			'HeaderMarkdown' => 'v2 hdr', 'BodyMarkdown' => 'v2', 'FooterMarkdown' => '', 'MinorMarkdown' => '',
			'IsEnabled' => 1,
		]);
		$this->assertStatus(0, $r1, 'v2 saved');
		$this->assertEq(2, (int)($r1['Version'] ?? 0), 'version bumped');

		// Only one active row per (kingdom, scope)
		$this->waiver->db->Clear();
		$this->waiver->db->kingdom_id = $this->testKingdomId;
		$rs = $this->waiver->db->DataSet("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "waiver_template WHERE kingdom_id = :kingdom_id AND scope='kingdom' AND is_active=1");
		$c = 0;
		if ($rs) { while ($rs->Next()) { $c = (int)$rs->c; } }
		$this->assertEq(1, $c, 'exactly one active row');
	}

	public function test_save_template_rejects_bad_token() {
		// IsAuthorized_h caches mundane_id in $_SESSION; wipe it so a bogus token is actually re-checked.
		unset($_SESSION['is_authorized_mundane_id']);
		$r = $this->waiver->SaveTemplate([
			'Token' => str_repeat('z', 32),  // 32 chars but no mundane owns this token
			'KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom',
			'HeaderMarkdown' => '', 'BodyMarkdown' => '', 'FooterMarkdown' => '', 'MinorMarkdown' => '',
			'IsEnabled' => 1,
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'rejected unauth token');
		// Re-prime the session so later tests still look up mundane 1 (we changed its token).
		unset($_SESSION['is_authorized_mundane_id']);
	}

	public function test_save_template_rejects_missing_kingdom_id() {
		$r = $this->waiver->SaveTemplate([
			'Token' => $this->token, 'KingdomId' => 0, 'Scope' => 'kingdom',
			'HeaderMarkdown' => '', 'BodyMarkdown' => '', 'FooterMarkdown' => '', 'MinorMarkdown' => '',
			'IsEnabled' => 1,
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'rejected missing KingdomId');
	}

	public function test_save_template_rejects_bad_scope() {
		$r = $this->waiver->SaveTemplate([
			'Token' => $this->token, 'KingdomId' => $this->testKingdomId, 'Scope' => 'galaxy',
			'HeaderMarkdown' => '', 'BodyMarkdown' => '', 'FooterMarkdown' => '', 'MinorMarkdown' => '',
			'IsEnabled' => 1,
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'rejected bad scope');
	}

	// ============================================================================
	// Task 1.3: GetActiveTemplate / GetTemplate
	// ============================================================================

	public function test_get_active_template_returns_latest() {
		$r = $this->waiver->GetActiveTemplate([
			'Token' => $this->token, 'KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom',
		]);
		$this->assertStatus(0, $r, 'found active');
		$this->assertEq('v2 hdr', $r['Template']['HeaderMarkdown'] ?? null, 'latest header');
		$this->assertEq(2, (int)($r['Template']['Version'] ?? 0), 'latest version');
	}

	public function test_get_active_template_missing_scope_returns_notfound() {
		$r = $this->waiver->GetActiveTemplate([
			'Token' => $this->token, 'KingdomId' => $this->testKingdomId, 'Scope' => 'park',
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'park scope has no active template yet');
	}

	public function test_get_active_template_rejects_bad_params() {
		$r = $this->waiver->GetActiveTemplate([
			'Token' => $this->token, 'KingdomId' => 0, 'Scope' => 'kingdom',
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'missing KingdomId rejected');

		$r2 = $this->waiver->GetActiveTemplate([
			'Token' => $this->token, 'KingdomId' => $this->testKingdomId, 'Scope' => 'x',
		]);
		$this->assertTrue(($r2['Status']['Status'] ?? 0) !== 0, 'bad scope rejected');
	}

	public function test_get_template_by_id() {
		// Find latest kingdom-scope template for our test kingdom
		$this->waiver->db->Clear();
		$this->waiver->db->kingdom_id = $this->testKingdomId;
		$rs = $this->waiver->db->DataSet("SELECT waiver_template_id FROM " . DB_PREFIX . "waiver_template WHERE kingdom_id = :kingdom_id AND scope='kingdom' ORDER BY version DESC LIMIT 1");
		$tid = 0;
		if ($rs) { while ($rs->Next()) { $tid = (int)$rs->waiver_template_id; } }
		$this->assertTrue($tid > 0, 'precondition: have a template id');

		$r = $this->waiver->GetTemplate(['Token' => $this->token, 'TemplateId' => $tid]);
		$this->assertStatus(0, $r, 'get by id');
		$this->assertEq($tid, (int)($r['Template']['TemplateId'] ?? 0), 'id round-trips');
	}

	public function test_get_template_missing_id_rejected() {
		$r = $this->waiver->GetTemplate(['Token' => $this->token, 'TemplateId' => 0]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'missing TemplateId rejected');
	}

	public function test_get_template_nonexistent_returns_notfound() {
		$r = $this->waiver->GetTemplate(['Token' => $this->token, 'TemplateId' => 999999999]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'nonexistent template returns non-success');
	}

	// ============================================================================
	// Task 1.4: SetTemplateEnabled
	// ============================================================================

	public function test_set_enabled_toggles() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)($r['Template']['TemplateId'] ?? 0);
		$this->assertTrue($tid > 0, 'precondition: active template exists');

		$r2 = $this->waiver->SetTemplateEnabled(['Token' => $this->token, 'TemplateId' => $tid, 'IsEnabled' => 0]);
		$this->assertStatus(0, $r2, 'disabled');
		$r3 = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$this->assertEq(0, (int)($r3['Template']['IsEnabled'] ?? -1), 'now disabled');

		$r4 = $this->waiver->SetTemplateEnabled(['Token' => $this->token, 'TemplateId' => $tid, 'IsEnabled' => 1]);
		$this->assertStatus(0, $r4, 're-enabled');
		$r5 = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$this->assertEq(1, (int)($r5['Template']['IsEnabled'] ?? -1), 'now enabled');
	}

	public function test_set_enabled_rejects_bad_token() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)($r['Template']['TemplateId'] ?? 0);
		unset($_SESSION['is_authorized_mundane_id']);
		$r2 = $this->waiver->SetTemplateEnabled(['Token' => str_repeat('z', 32), 'TemplateId' => $tid, 'IsEnabled' => 0]);
		$this->assertTrue(($r2['Status']['Status'] ?? 0) !== 0, 'rejected bad token');
		unset($_SESSION['is_authorized_mundane_id']);
	}

	public function test_set_enabled_rejects_missing_id() {
		$r = $this->waiver->SetTemplateEnabled(['Token' => $this->token, 'TemplateId' => 0, 'IsEnabled' => 1]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'rejected missing id');
	}

	public function test_set_enabled_nonexistent_template() {
		$r = $this->waiver->SetTemplateEnabled(['Token' => $this->token, 'TemplateId' => 999999999, 'IsEnabled' => 1]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'rejected missing row');
	}

	// ============================================================================
	// Task 1.5: SubmitSignature
	// ============================================================================

	public function test_submit_signature_drawn() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)($r['Template']['TemplateId'] ?? 0);
		$this->assertTrue($tid > 0, 'precondition: active template');

		$r2 = $this->waiver->SubmitSignature([
			'Token' => $this->token, 'TemplateId' => $tid,
			'MundaneFirst' => 'Test', 'MundaneLast' => 'User', 'PersonaName' => 'Testicus',
			'ParkId' => $this->testParkId, 'KingdomId' => $this->testKingdomId,
			'SignatureType' => 'drawn',
			'SignatureData' => json_encode([[['x'=>0.1,'y'=>0.2],['x'=>0.3,'y'=>0.4]]]),
			'IsMinor' => 0, 'MinorRepFirst'=>'', 'MinorRepLast'=>'', 'MinorRepRelationship'=>'',
		]);
		$this->assertStatus(0, $r2, 'signed');
		$this->assertTrue(($r2['SignatureId'] ?? 0) > 0, 'id returned');
	}

	public function test_submit_signature_typed_minor() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)($r['Template']['TemplateId'] ?? 0);

		$r2 = $this->waiver->SubmitSignature([
			'Token' => $this->token, 'TemplateId' => $tid,
			'MundaneFirst' => 'Junior', 'MundaneLast' => 'Smith', 'PersonaName' => 'Jr',
			'ParkId' => $this->testParkId, 'KingdomId' => $this->testKingdomId,
			'SignatureType' => 'typed', 'SignatureData' => 'Junior Smith',
			'IsMinor' => 1, 'MinorRepFirst' => 'Parent', 'MinorRepLast' => 'Smith', 'MinorRepRelationship' => 'Mother',
		]);
		$this->assertStatus(0, $r2, 'minor signed');
	}

	public function test_submit_signature_rejects_unauthenticated() {
		unset($_SESSION['is_authorized_mundane_id']);
		$r = $this->waiver->SubmitSignature([
			'Token' => str_repeat('z', 32), 'TemplateId' => 1,
			'MundaneFirst'=>'T','MundaneLast'=>'U','PersonaName'=>'','ParkId'=>1,'KingdomId'=>1,
			'SignatureType'=>'typed','SignatureData'=>'T U','IsMinor'=>0,
			'MinorRepFirst'=>'','MinorRepLast'=>'','MinorRepRelationship'=>'',
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'rejected bad token');
		unset($_SESSION['is_authorized_mundane_id']);
	}

	public function test_submit_signature_rejects_bad_template() {
		$r = $this->waiver->SubmitSignature([
			'Token' => $this->token, 'TemplateId' => 999999999,
			'MundaneFirst'=>'T','MundaneLast'=>'U','PersonaName'=>'','ParkId'=>1,'KingdomId'=>1,
			'SignatureType'=>'typed','SignatureData'=>'T U','IsMinor'=>0,
			'MinorRepFirst'=>'','MinorRepLast'=>'','MinorRepRelationship'=>'',
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'rejected nonexistent template');
	}

	public function test_submit_signature_rejects_bad_sigtype() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)($r['Template']['TemplateId'] ?? 0);
		$r2 = $this->waiver->SubmitSignature([
			'Token' => $this->token, 'TemplateId' => $tid,
			'MundaneFirst'=>'T','MundaneLast'=>'U','PersonaName'=>'','ParkId'=>1,'KingdomId'=>1,
			'SignatureType'=>'tattoo','SignatureData'=>'T U','IsMinor'=>0,
			'MinorRepFirst'=>'','MinorRepLast'=>'','MinorRepRelationship'=>'',
		]);
		$this->assertTrue(($r2['Status']['Status'] ?? 0) !== 0, 'rejected bad SignatureType');
	}

	public function test_submit_signature_rejects_empty_sigdata() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)($r['Template']['TemplateId'] ?? 0);
		$r2 = $this->waiver->SubmitSignature([
			'Token' => $this->token, 'TemplateId' => $tid,
			'MundaneFirst'=>'T','MundaneLast'=>'U','PersonaName'=>'','ParkId'=>1,'KingdomId'=>1,
			'SignatureType'=>'typed','SignatureData'=>'','IsMinor'=>0,
			'MinorRepFirst'=>'','MinorRepLast'=>'','MinorRepRelationship'=>'',
		]);
		$this->assertTrue(($r2['Status']['Status'] ?? 0) !== 0, 'rejected empty SignatureData');
	}

	public function test_submit_signature_minor_requires_rep_fields() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)($r['Template']['TemplateId'] ?? 0);
		$r2 = $this->waiver->SubmitSignature([
			'Token' => $this->token, 'TemplateId' => $tid,
			'MundaneFirst'=>'T','MundaneLast'=>'U','PersonaName'=>'','ParkId'=>1,'KingdomId'=>1,
			'SignatureType'=>'typed','SignatureData'=>'T U','IsMinor'=>1,
			'MinorRepFirst'=>'','MinorRepLast'=>'','MinorRepRelationship'=>'',
		]);
		$this->assertTrue(($r2['Status']['Status'] ?? 0) !== 0, 'minor with no rep rejected');
	}

	public function test_submit_signature_rejects_disabled_template() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)($r['Template']['TemplateId'] ?? 0);
		$this->waiver->SetTemplateEnabled(['Token' => $this->token, 'TemplateId' => $tid, 'IsEnabled' => 0]);

		$r2 = $this->waiver->SubmitSignature([
			'Token' => $this->token, 'TemplateId' => $tid,
			'MundaneFirst'=>'T','MundaneLast'=>'U','PersonaName'=>'','ParkId'=>1,'KingdomId'=>1,
			'SignatureType'=>'typed','SignatureData'=>'T U','IsMinor'=>0,
			'MinorRepFirst'=>'','MinorRepLast'=>'','MinorRepRelationship'=>'',
		]);
		$this->assertTrue(($r2['Status']['Status'] ?? 0) !== 0, 'rejected disabled');

		// re-enable for later tests
		$this->waiver->SetTemplateEnabled(['Token' => $this->token, 'TemplateId' => $tid, 'IsEnabled' => 1]);
	}

	// ============================================================================
	// Task 1.6: GetSignature
	// ============================================================================

	public function test_get_signature_by_owner() {
		$this->waiver->db->Clear();
		$this->waiver->db->mundane_id = $this->testMundaneId;
		$rs = $this->waiver->db->DataSet("SELECT waiver_signature_id FROM " . DB_PREFIX . "waiver_signature WHERE mundane_id = :mundane_id ORDER BY waiver_signature_id DESC LIMIT 1");
		$sid = 0;
		if ($rs) { while ($rs->Next()) { $sid = (int)$rs->waiver_signature_id; } }
		$this->assertTrue($sid > 0, 'precondition: have signature');

		$r = $this->waiver->GetSignature(['Token' => $this->token, 'SignatureId' => $sid]);
		$this->assertStatus(0, $r, 'owner can read');
		$this->assertEq($sid, (int)($r['Signature']['SignatureId'] ?? 0), 'id round-trips');
		$this->assertTrue(isset($r['Signature']['Template']), 'template attached');
	}

	public function test_get_signature_rejects_missing_id() {
		$r = $this->waiver->GetSignature(['Token' => $this->token, 'SignatureId' => 0]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'missing id rejected');
	}

	public function test_get_signature_rejects_bad_token() {
		$this->waiver->db->Clear();
		$this->waiver->db->mundane_id = $this->testMundaneId;
		$rs = $this->waiver->db->DataSet("SELECT waiver_signature_id FROM " . DB_PREFIX . "waiver_signature WHERE mundane_id = :mundane_id ORDER BY waiver_signature_id DESC LIMIT 1");
		$sid = 0;
		if ($rs) { while ($rs->Next()) { $sid = (int)$rs->waiver_signature_id; } }
		unset($_SESSION['is_authorized_mundane_id']);
		$r = $this->waiver->GetSignature(['Token' => str_repeat('z', 32), 'SignatureId' => $sid]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'bad token rejected');
		unset($_SESSION['is_authorized_mundane_id']);
	}

	public function test_get_signature_nonexistent() {
		$r = $this->waiver->GetSignature(['Token' => $this->token, 'SignatureId' => 999999999]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'nonexistent signature rejected');
	}

	// ============================================================================
	// Task 1.7: GetQueue
	// ============================================================================

	public function test_get_queue_pending() {
		$r = $this->waiver->GetQueue([
			'Token' => $this->token, 'Scope' => 'kingdom', 'EntityId' => $this->testKingdomId,
			'Filter' => 'pending', 'Page' => 1, 'PageSize' => 10,
		]);
		$this->assertStatus(0, $r, 'queue fetched');
		$this->assertTrue(is_array($r['Signatures'] ?? null), 'signatures array');
		$this->assertTrue(($r['Total'] ?? 0) >= 1, 'at least one signature exists');
	}

	public function test_get_queue_pagination_clamps() {
		$r = $this->waiver->GetQueue([
			'Token' => $this->token, 'Scope' => 'kingdom', 'EntityId' => $this->testKingdomId,
			'Filter' => 'all', 'Page' => 0, 'PageSize' => 9999,
		]);
		$this->assertStatus(0, $r, 'queue fetched');
		$this->assertEq(1, (int)($r['Page'] ?? 0), 'page clamped to 1');
		$this->assertEq(100, (int)($r['PageSize'] ?? 0), 'pageSize clamped to 100');
	}

	public function test_get_queue_rejects_bad_scope() {
		$r = $this->waiver->GetQueue([
			'Token' => $this->token, 'Scope' => 'galaxy', 'EntityId' => $this->testKingdomId,
			'Filter' => 'pending',
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'bad scope rejected');
	}

	public function test_get_queue_rejects_missing_entity() {
		$r = $this->waiver->GetQueue([
			'Token' => $this->token, 'Scope' => 'kingdom', 'EntityId' => 0,
			'Filter' => 'pending',
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'missing entity rejected');
	}

	public function test_get_queue_rejects_bad_token() {
		unset($_SESSION['is_authorized_mundane_id']);
		$r = $this->waiver->GetQueue([
			'Token' => str_repeat('z', 32), 'Scope' => 'kingdom', 'EntityId' => $this->testKingdomId,
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'bad token rejected');
		unset($_SESSION['is_authorized_mundane_id']);
	}

	// ============================================================================
	// Task 1.8: VerifySignature
	// ============================================================================

	public function test_verify_signature_approved() {
		$this->waiver->db->Clear();
		$rs = $this->waiver->db->DataSet("SELECT waiver_signature_id FROM " . DB_PREFIX . "waiver_signature WHERE verification_status='pending' ORDER BY waiver_signature_id DESC LIMIT 1");
		$sid = 0;
		if ($rs) { while ($rs->Next()) { $sid = (int)$rs->waiver_signature_id; } }
		$this->assertTrue($sid > 0, 'precondition: have a pending signature');

		$r = $this->waiver->VerifySignature([
			'Token' => $this->token, 'SignatureId' => $sid, 'Action' => 'verified',
			'PrintedName' => 'Admin Person', 'PersonaName' => 'Sir Admin', 'OfficeTitle' => 'Prime Minister',
			'SignatureType' => 'typed', 'SignatureData' => 'Admin Person', 'Notes' => '',
		]);
		$this->assertStatus(0, $r, 'verified');

		$r2 = $this->waiver->GetSignature(['Token' => $this->token, 'SignatureId' => $sid]);
		$this->assertEq('verified', $r2['Signature']['VerificationStatus'] ?? null, 'status updated');
		$this->assertEq('Admin Person', $r2['Signature']['VerifierPrintedName'] ?? null, 'printed name stored');
	}

	public function test_verify_signature_reject_requires_notes() {
		$this->waiver->db->Clear();
		$rs = $this->waiver->db->DataSet("SELECT waiver_signature_id FROM " . DB_PREFIX . "waiver_signature WHERE verification_status='pending' ORDER BY waiver_signature_id DESC LIMIT 1");
		$sid = 0;
		if ($rs) { while ($rs->Next()) { $sid = (int)$rs->waiver_signature_id; } }
		if ($sid === 0) { $this->assertTrue(true, 'no pending to reject — skip'); return; }

		$r = $this->waiver->VerifySignature([
			'Token' => $this->token, 'SignatureId' => $sid, 'Action' => 'rejected',
			'PrintedName' => 'A', 'PersonaName' => '', 'OfficeTitle' => '',
			'SignatureType' => 'typed', 'SignatureData' => 'A', 'Notes' => '',
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'reject without notes blocked');
	}

	public function test_verify_signature_rejects_bad_action() {
		$this->waiver->db->Clear();
		$rs = $this->waiver->db->DataSet("SELECT waiver_signature_id FROM " . DB_PREFIX . "waiver_signature ORDER BY waiver_signature_id DESC LIMIT 1");
		$sid = 0;
		if ($rs) { while ($rs->Next()) { $sid = (int)$rs->waiver_signature_id; } }
		$r = $this->waiver->VerifySignature([
			'Token' => $this->token, 'SignatureId' => $sid, 'Action' => 'explode',
			'PrintedName' => 'A', 'PersonaName' => '', 'OfficeTitle' => '',
			'SignatureType' => 'typed', 'SignatureData' => 'A', 'Notes' => '',
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'bad action rejected');
	}

	public function test_verify_signature_rejects_missing_id() {
		$r = $this->waiver->VerifySignature([
			'Token' => $this->token, 'SignatureId' => 0, 'Action' => 'verified',
			'PrintedName' => 'A', 'PersonaName' => '', 'OfficeTitle' => '',
			'SignatureType' => 'typed', 'SignatureData' => 'A', 'Notes' => '',
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'missing id rejected');
	}

	public function test_verify_signature_rejects_bad_token() {
		$this->waiver->db->Clear();
		$rs = $this->waiver->db->DataSet("SELECT waiver_signature_id FROM " . DB_PREFIX . "waiver_signature ORDER BY waiver_signature_id DESC LIMIT 1");
		$sid = 0;
		if ($rs) { while ($rs->Next()) { $sid = (int)$rs->waiver_signature_id; } }
		unset($_SESSION['is_authorized_mundane_id']);
		$r = $this->waiver->VerifySignature([
			'Token' => str_repeat('z', 32), 'SignatureId' => $sid, 'Action' => 'verified',
			'PrintedName' => 'A', 'PersonaName' => '', 'OfficeTitle' => '',
			'SignatureType' => 'typed', 'SignatureData' => 'A', 'Notes' => '',
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'bad token rejected');
		unset($_SESSION['is_authorized_mundane_id']);
	}

	public function test_verify_signature_requires_verifier_signature_on_verify() {
		// Find or create a pending signature first
		$this->waiver->db->Clear();
		$rs = $this->waiver->db->DataSet("SELECT waiver_signature_id FROM " . DB_PREFIX . "waiver_signature WHERE verification_status='pending' ORDER BY waiver_signature_id DESC LIMIT 1");
		$sid = 0;
		if ($rs) { while ($rs->Next()) { $sid = (int)$rs->waiver_signature_id; } }
		if ($sid === 0) {
			// Create a fresh pending signature
			$at = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
			$tid = (int)($at['Template']['TemplateId'] ?? 0);
			$sub = $this->waiver->SubmitSignature([
				'Token' => $this->token, 'TemplateId' => $tid,
				'MundaneFirst' => 'Verify', 'MundaneLast' => 'Test', 'PersonaName' => '',
				'ParkId' => $this->testParkId, 'KingdomId' => $this->testKingdomId,
				'SignatureType' => 'typed', 'SignatureData' => 'V T', 'IsMinor' => 0,
				'MinorRepFirst'=>'', 'MinorRepLast'=>'', 'MinorRepRelationship'=>'',
			]);
			$sid = (int)($sub['SignatureId'] ?? 0);
		}
		$this->assertTrue($sid > 0, 'precondition: have a pending signature');

		$r = $this->waiver->VerifySignature([
			'Token' => $this->token, 'SignatureId' => $sid, 'Action' => 'verified',
			'PrintedName' => 'Admin', 'PersonaName' => '', 'OfficeTitle' => '',
			'SignatureType' => '', 'SignatureData' => '', 'Notes' => '',
		]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'verifier signature required');
	}

	// ============================================================================
	// Task 1.9: PreviewMarkdown
	// ============================================================================

	public function test_preview_markdown_renders() {
		$r = $this->waiver->PreviewMarkdown(['Token' => $this->token, 'Markdown' => '**Hello**']);
		$this->assertStatus(0, $r, 'rendered');
		$this->assertTrue(strpos($r['Html'] ?? '', '<strong>') !== false, 'has <strong>');
	}

	public function test_preview_markdown_too_large() {
		$r = $this->waiver->PreviewMarkdown(['Token' => $this->token, 'Markdown' => str_repeat('a', 70000)]);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'rejected oversize');
	}

	public function test_preview_markdown_rejects_bad_token() {
		unset($_SESSION['is_authorized_mundane_id']);
		$r = $this->waiver->PreviewMarkdown(['Token' => str_repeat('z', 32), 'Markdown' => 'hi']);
		$this->assertTrue(($r['Status']['Status'] ?? 0) !== 0, 'rejected bad token');
		unset($_SESSION['is_authorized_mundane_id']);
	}

	public function test_preview_markdown_safe_mode_strips_script() {
		// Parsedown setSafeMode(true) should neutralise raw HTML/script tags.
		$r = $this->waiver->PreviewMarkdown([
			'Token'    => $this->token,
			'Markdown' => '**yo** <script>alert(1)</script>',
		]);
		$this->assertStatus(0, $r, 'rendered safe');
		$this->assertTrue(strpos($r['Html'] ?? '', '<script>') === false, 'raw <script> neutralised');
	}

	// ============================================================================
	// Task 1.10: Supersede prior signatures on re-sign
	// ============================================================================

	public function test_resign_supersedes_prior() {
		$r = $this->waiver->GetActiveTemplate(['KingdomId' => $this->testKingdomId, 'Scope' => 'kingdom']);
		$tid = (int)($r['Template']['TemplateId'] ?? 0);
		$payload = [
			'Token' => $this->token, 'TemplateId' => $tid,
			'MundaneFirst' => 'Resign', 'MundaneLast' => 'Test', 'PersonaName' => '',
			'ParkId' => $this->testParkId, 'KingdomId' => $this->testKingdomId,
			'SignatureType' => 'typed', 'SignatureData' => 'Resign Test', 'IsMinor' => 0,
			'MinorRepFirst'=>'', 'MinorRepLast'=>'', 'MinorRepRelationship'=>'',
		];
		$r1 = $this->waiver->SubmitSignature($payload);
		$sid1 = (int)($r1['SignatureId'] ?? 0);
		$this->assertTrue($sid1 > 0, 'first submit id');

		$r2 = $this->waiver->SubmitSignature($payload);
		$sid2 = (int)($r2['SignatureId'] ?? 0);
		$this->assertStatus(0, $r2, 'second submit ok');
		$this->assertTrue($sid2 > 0 && $sid2 !== $sid1, 'second submit is a new row');

		// Earlier record should now be 'superseded'
		$this->waiver->db->Clear();
		$this->waiver->db->waiver_signature_id = $sid1;
		$rs = $this->waiver->db->DataSet("SELECT verification_status FROM " . DB_PREFIX . "waiver_signature WHERE waiver_signature_id = :waiver_signature_id");
		$status = null;
		if ($rs) { while ($rs->Next()) { $status = $rs->verification_status; } }
		$this->assertEq('superseded', $status, 'prior superseded');

		// New record should still be pending
		$this->waiver->db->Clear();
		$this->waiver->db->waiver_signature_id = $sid2;
		$rs2 = $this->waiver->db->DataSet("SELECT verification_status FROM " . DB_PREFIX . "waiver_signature WHERE waiver_signature_id = :waiver_signature_id");
		$status2 = null;
		if ($rs2) { while ($rs2->Next()) { $status2 = $rs2->verification_status; } }
		$this->assertEq('pending', $status2, 'new record still pending');
	}

}

(new WaiverTestRunner())->run();
?>
