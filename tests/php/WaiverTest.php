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
}

(new WaiverTestRunner())->run();
?>
