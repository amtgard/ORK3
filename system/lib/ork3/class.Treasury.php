<?php

class Treasury extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->split = new yapo($this->db, DB_PREFIX . 'split');
		$this->account = new yapo($this->db, DB_PREFIX . 'account');
		$this->transaction = new yapo($this->db, DB_PREFIX . 'transaction');
		// Treasury module (per-org ledger) yapo objects
		$this->entry = new yapo($this->db, DB_PREFIX . 'treasury_entry');
		$this->recon = new yapo($this->db, DB_PREFIX . 'treasury_reconciliation');
		$this->audit = new yapo($this->db, DB_PREFIX . 'treasury_audit');
	}

	/* =========================================================================
	 * Treasury module: per-org (Kingdom/Park) financial ledger.
	 * Officer-only; running balance is always computed from the opening anchor.
	 * (Coexists with the legacy double-entry methods above on the same class so
	 *  startup.php's autoloader keeps Ork3::$Lib->treasury / APIModel('Treasury').)
	 * ========================================================================= */

	public static $CATEGORIES = array(
		'income' => array(
			'dues'          => 'Dues',
			'fundraiser'    => 'Fundraiser',
			'donation'      => 'Donation',
			'event_revenue' => 'Event Revenue',
			'income_other'  => 'Other Income',
		),
		'expense' => array(
			'supplies'       => 'Supplies',
			'equipment'      => 'Equipment',
			'site_rental'    => 'Site / Rental',
			'awards_regalia' => 'Awards / Regalia',
			'reimbursement'  => 'Reimbursement',
			'expense_other'  => 'Other Expense',
		),
	);

	/** Resolve auth; returns mundane_id (>0) or 0 if unauthorized for this org. */
	private function authFor($token, $owner_type, $owner_id) {
		$owner_type = ($owner_type === 'park') ? 'park' : 'kingdom';
		$authType   = ($owner_type === 'park') ? AUTH_PARK : AUTH_KINGDOM;
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($token);
		if ($mundane_id > 0
			&& Ork3::$Lib->authorization->HasAuthority($mundane_id, $authType, (int)$owner_id, AUTH_EDIT)) {
			return (int)$mundane_id;
		}
		return 0;
	}

	private function normType($t) {
		return ($t === 'park') ? 'park' : 'kingdom';
	}

	/** Opening baseline: array(as_of_date, actual_balance) or null if none. */
	private function openingRecon($owner_type, $owner_id) {
		global $DB;
		$owner_type = $this->normType($owner_type);
		$owner_id   = (int)$owner_id;
		$DB->Clear();
		$rs = $DB->DataSet("SELECT as_of_date, actual_balance FROM " . DB_PREFIX . "treasury_reconciliation
			WHERE owner_type='$owner_type' AND owner_id=$owner_id AND is_opening=1 AND deleted_at IS NULL
			ORDER BY id ASC LIMIT 1");
		// $DB is YapoMysql, whose DataSet() returns the result un-advanced; Next() fetches
		// the (only) row and populates the column properties.
		if ($rs && $rs->Next()) {
			return array('as_of_date' => $rs->as_of_date, 'actual_balance' => (float)$rs->actual_balance);
		}
		return null;
	}

	/** Running balance over non-deleted entries up to (and including) $upToDate (null = all). */
	private function ComputeBalanceAsOf($owner_type, $owner_id, $upToDate = null) {
		global $DB;
		$owner_type = $this->normType($owner_type);
		$owner_id   = (int)$owner_id;
		$open       = $this->openingRecon($owner_type, $owner_id);
		$base       = $open ? $open['actual_balance'] : 0.0;
		$fromDate   = $open ? $open['as_of_date'] : null;

		$where = "owner_type='$owner_type' AND owner_id=$owner_id AND deleted_at IS NULL";
		if ($fromDate) { $where .= " AND entry_date >= '" . addslashes($fromDate) . "'"; }
		if ($upToDate) { $where .= " AND entry_date <= '" . addslashes($upToDate) . "'"; }

		$DB->Clear();
		$rs = $DB->DataSet("SELECT
			COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END),0) AS credits,
			COALESCE(SUM(CASE WHEN direction='debit'  THEN amount ELSE 0 END),0) AS debits
			FROM " . DB_PREFIX . "treasury_entry WHERE $where");
		$credits = 0.0; $debits = 0.0;
		// $DB is YapoMysql: DataSet() returns un-advanced; Next() fetches the single aggregate row.
		if ($rs && $rs->Next()) { $credits = (float)$rs->credits; $debits = (float)$rs->debits; }
		// round to cents to avoid float drift
		return round($base + $credits - $debits, 2);
	}

	public function HasOpeningBalance($token, $owner_type, $owner_id) {
		if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
		return Success(array('HasOpening' => $this->openingRecon($owner_type, $owner_id) !== null));
	}

	/** Cheap change-signal for polling: a small token that changes on any insert/edit/delete
	 *  (entries) or insert/delete (reconciliations). Two indexed COUNT/MAX queries, no full scan. */
	public function GetRevision($token, $owner_type, $owner_id) {
		if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
		global $DB;
		$owner_type = $this->normType($owner_type);
		$owner_id = (int)$owner_id;
		$DB->Clear();
		$rs = $DB->DataSet("SELECT COUNT(*) n, COALESCE(MAX(id),0) mx,
			COALESCE(UNIX_TIMESTAMP(MAX(GREATEST(created_at, COALESCE(updated_at, created_at), COALESCE(deleted_at, created_at)))),0) ts
			FROM " . DB_PREFIX . "treasury_entry WHERE owner_type='$owner_type' AND owner_id=$owner_id");
		$en = 0; $emx = 0; $ets = 0;
		if ($rs && $rs->Next()) { $en = (int)$rs->n; $emx = (int)$rs->mx; $ets = (int)$rs->ts; }
		$DB->Clear();
		$rs2 = $DB->DataSet("SELECT COUNT(*) n, COALESCE(MAX(id),0) mx
			FROM " . DB_PREFIX . "treasury_reconciliation WHERE owner_type='$owner_type' AND owner_id=$owner_id");
		$rn = 0; $rmx = 0;
		if ($rs2 && $rs2->Next()) { $rn = (int)$rs2->n; $rmx = (int)$rs2->mx; }
		return Success(array('Rev' => $en . '-' . $emx . '-' . $ets . '.' . $rn . '-' . $rmx));
	}

	/** Display name of the org (park/kingdom) by id; page is already auth-gated. */
	public function GetOwnerName($token, $owner_type, $owner_id) {
		if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
		global $DB;
		$owner_type = $this->normType($owner_type);
		$owner_id = (int)$owner_id;
		$DB->Clear();
		$name = '';
		$kingdom_id = $owner_id;
		if ($owner_type === 'park') {
			$rs = $DB->DataSet("SELECT name, kingdom_id FROM " . DB_PREFIX . "park WHERE park_id=$owner_id LIMIT 1");
			if ($rs && $rs->Next()) { $name = $rs->name; $kingdom_id = (int)$rs->kingdom_id; }
		} else {
			$rs = $DB->DataSet("SELECT name FROM " . DB_PREFIX . "kingdom WHERE kingdom_id=$owner_id LIMIT 1");
			if ($rs && $rs->Next()) { $name = $rs->name; }
		}
		return Success(array('Name' => $name, 'KingdomId' => (int)$kingdom_id));
	}

	/* ---- Treasury module: entry CRUD + audit ---- */

	private static $VALID_METHODS = array('cash', 'check', 'digital');

	private function validCategory($cat) {
		return isset(self::$CATEGORIES['income'][$cat]) || isset(self::$CATEGORIES['expense'][$cat]);
	}

	private function writeAudit($entry_id, $action, $mundane_id, $before, $after) {
		$this->audit->clear();
		$this->audit->entry_id    = (int)$entry_id;
		$this->audit->action      = $action;
		$this->audit->changed_by  = (int)$mundane_id;
		$this->audit->changed_at  = date('Y-m-d H:i:s');
		// yapo drops null fields from INSERT; '' clears the column instead of leaving it stale.
		$this->audit->before_json = $before === null ? '' : json_encode($before);
		$this->audit->after_json  = $after  === null ? '' : json_encode($after);
		$this->audit->save();
	}

	private function entryToArray() {
		return array(
			'id'             => $this->entry->id,
			'owner_type'     => $this->entry->owner_type,
			'owner_id'       => $this->entry->owner_id,
			'entry_date'     => $this->entry->entry_date,
			'direction'      => $this->entry->direction,
			'amount'         => $this->entry->amount,
			'category'       => $this->entry->category,
			'payment_method' => $this->entry->payment_method,
			'description'    => $this->entry->description,
			'counterparty'   => $this->entry->counterparty,
			'counterparty_player_id' => $this->entry->counterparty_player_id,
			'reference_no'   => $this->entry->reference_no,
			'deleted_at'     => $this->entry->deleted_at,
		);
	}

	/** Create or edit. $data: owner_type, owner_id, [id], entry_date, direction, amount,
	 *  category, payment_method, description, counterparty, reference_no. */
	public function SaveEntry($token, $data) {
		$mundane_id = $this->authFor($token, $data['owner_type'] ?? '', $data['owner_id'] ?? 0);
		if (!$mundane_id) { return NoAuthorization(); }

		$direction = ($data['direction'] ?? '') === 'debit' ? 'debit' : 'credit';
		$amount    = round((float)($data['amount'] ?? 0), 2);
		$cat       = (string)($data['category'] ?? '');
		$method    = (string)($data['payment_method'] ?? '');
		$entryDate = (string)($data['entry_date'] ?? '');

		if ($amount <= 0) { return InvalidParameter('Amount must be greater than zero.'); }
		if (!$this->validCategory($cat)) { return InvalidParameter('Unknown category.'); }
		if (!in_array($method, self::$VALID_METHODS, true)) { return InvalidParameter('Payment method is required.'); }
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) { return InvalidParameter('Invalid date.'); }

		$isEdit = !empty($data['id']);
		$before = null;
		$this->entry->clear();
		if ($isEdit) {
			$this->entry->id = (int)$data['id'];
			if (!$this->entry->find() || $this->entry->deleted_at !== null
				|| $this->entry->owner_type !== $this->normType($data['owner_type'])
				|| (int)$this->entry->owner_id !== (int)$data['owner_id']) {
				return InvalidParameter('Entry not found.');
			}
			$before = $this->entryToArray();
		} else {
			$this->entry->owner_type = $this->normType($data['owner_type']);
			$this->entry->owner_id   = (int)$data['owner_id'];
			$this->entry->created_by = $mundane_id;
			$this->entry->created_at = date('Y-m-d H:i:s');
		}
		$this->entry->entry_date     = $entryDate;
		$this->entry->direction      = $direction;
		$this->entry->amount         = $amount;
		$this->entry->category       = $cat;
		$this->entry->payment_method = $method;
		$this->entry->description    = (string)($data['description'] ?? '');
		// yapo drops null fields; assign '' to clear an optional column rather than leave it stale.
		$this->entry->counterparty   = ($data['counterparty'] ?? '') !== '' ? $data['counterparty'] : '';
		$this->entry->reference_no   = ($data['reference_no'] ?? '') !== '' ? $data['reference_no'] : '';
		$this->entry->counterparty_player_id = (isset($data['counterparty_player_id']) && (int)$data['counterparty_player_id'] > 0) ? (int)$data['counterparty_player_id'] : 0;
		if ($isEdit) { $this->entry->updated_at = date('Y-m-d H:i:s'); }
		$this->entry->save();

		$id = (int)$this->entry->id;
		$this->writeAudit($id, $isEdit ? 'edit' : 'create', $mundane_id, $before, $this->entryToArray());
		return Success(array('Id' => $id));
	}

	public function DeleteEntry($token, $owner_type, $owner_id, $id) {
		$mundane_id = $this->authFor($token, $owner_type, $owner_id);
		if (!$mundane_id) { return NoAuthorization(); }
		$this->entry->clear();
		$this->entry->id = (int)$id;
		if (!$this->entry->find() || $this->entry->deleted_at !== null
			|| (int)$this->entry->owner_id !== (int)$owner_id
			|| $this->entry->owner_type !== $this->normType($owner_type)) {
			return InvalidParameter('Entry not found.');
		}
		$before = $this->entryToArray();
		$this->entry->deleted_at = date('Y-m-d H:i:s');
		$this->entry->save();
		$this->writeAudit((int)$id, 'delete', $mundane_id, $before, null);
		return Success();
	}

	/* ---- Treasury module: ledger read, reconciliation, summary, series ---- */

	/** Paged ledger with running balance. $filters: from, to, category, direction, page, per. */
	public function GetLedger($token, $owner_type, $owner_id, $filters = array()) {
		global $DB;
		if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
		$owner_type = $this->normType($owner_type);
		$owner_id   = (int)$owner_id;

		// Opening anchor for running balance. The running balance must always be
		// computed from the opening across the full chronological set; date/category/
		// direction filters only change which rows are *displayed*, never each row's
		// true running balance.
		$open     = $this->openingRecon($owner_type, $owner_id);
		$base     = $open ? $open['actual_balance'] : 0.0;
		$openDate = $open ? $open['as_of_date'] : null;

		// Fetch ALL non-deleted entries (anchored at the opening date) in chronological
		// order so each row's running balance is correct, then apply display filters.
		$balWhere = "owner_type='$owner_type' AND owner_id=$owner_id AND deleted_at IS NULL";
		if ($openDate) { $balWhere .= " AND entry_date >= '" . addslashes($openDate) . "'"; }
		$DB->Clear();
		$rs = $DB->DataSet("SELECT id, entry_date, direction, amount, category, payment_method,
			description, counterparty, counterparty_player_id, reference_no
			FROM " . DB_PREFIX . "treasury_entry WHERE $balWhere
			ORDER BY entry_date ASC, id ASC");

		$from = !empty($filters['from'])      ? (string)$filters['from']      : null;
		$to   = !empty($filters['to'])        ? (string)$filters['to']        : null;
		$cat  = !empty($filters['category'])  ? (string)$filters['category']  : null;
		$dir  = !empty($filters['direction']) ? (string)$filters['direction'] : null;

		$rows = array();
		$bal  = $base;
		// $DB is YapoMysql: DataSet() returns un-advanced; Next() fetches each row.
		while ($rs && $rs->Next()) {
			$delta = ($rs->direction === 'credit') ? (float)$rs->amount : -(float)$rs->amount;
			$bal   = round($bal + $delta, 2);
			// Apply display filters AFTER updating the running balance so it stays correct.
			if ($from !== null && $rs->entry_date < $from) { continue; }
			if ($to   !== null && $rs->entry_date > $to)   { continue; }
			if ($cat  !== null && $rs->category  !== $cat) { continue; }
			if ($dir  !== null && $rs->direction !== $dir) { continue; }
			$rows[] = array(
				'Id'             => (int)$rs->id,
				'Date'           => $rs->entry_date,
				'Direction'      => $rs->direction,
				'Amount'         => (float)$rs->amount,
				'Category'       => $rs->category,
				'PaymentMethod'  => $rs->payment_method,
				'Description'    => $rs->description,
				'Counterparty'   => $rs->counterparty,
				'CounterpartyPlayerId' => (int)$rs->counterparty_player_id,
				'ReferenceNo'    => $rs->reference_no,
				'RunningBalance' => $bal,
			);
		}
		// Newest first for display, then page.
		$rows  = array_reverse($rows);
		$per   = max(1, (int)($filters['per'] ?? 25));
		$page  = max(1, (int)($filters['page'] ?? 1));
		$total = count($rows);
		$paged = array_slice($rows, ($page - 1) * $per, $per);
		return Success(array(
			'Rows'           => $paged,
			'Total'          => $total,
			'Page'           => $page,
			'Per'            => $per,
			'CurrentBalance' => $this->ComputeBalanceAsOf($owner_type, $owner_id),
		));
	}

	public function GetEntry($token, $owner_type, $owner_id, $id) {
		if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
		$this->entry->clear();
		$this->entry->id = (int)$id;
		if (!$this->entry->find() || $this->entry->deleted_at !== null
			|| (int)$this->entry->owner_id !== (int)$owner_id
			|| $this->entry->owner_type !== $this->normType($owner_type)) {
			return InvalidParameter('Entry not found.');
		}
		return Success($this->entryToArray());
	}

	/** Summary for a date range: current balance, period in/out, by-category totals. */
	public function GetSummary($token, $owner_type, $owner_id, $from = null, $to = null) {
		global $DB;
		if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
		$owner_type = $this->normType($owner_type);
		$owner_id   = (int)$owner_id;
		$where = "owner_type='$owner_type' AND owner_id=$owner_id AND deleted_at IS NULL";
		if ($from) { $where .= " AND entry_date >= '" . addslashes($from) . "'"; }
		if ($to)   { $where .= " AND entry_date <= '" . addslashes($to) . "'"; }
		// Anchor to the opening baseline so period totals stay consistent with CurrentBalance,
		// which excludes pre-opening entries (mirrors ComputeBalanceAsOf/GetLedger/GetBalanceSeries).
		$open = $this->openingRecon($owner_type, $owner_id);
		if ($open) { $where .= " AND entry_date >= '" . addslashes($open['as_of_date']) . "'"; }
		$DB->Clear();
		$rs = $DB->DataSet("SELECT direction, category,
			COALESCE(SUM(amount),0) AS total
			FROM " . DB_PREFIX . "treasury_entry WHERE $where GROUP BY direction, category");
		$byCat = array(); $totalIn = 0.0; $totalOut = 0.0;
		while ($rs && $rs->Next()) {
			$t = (float)$rs->total;
			$byCat[$rs->category] = ($byCat[$rs->category] ?? 0) + $t;
			if ($rs->direction === 'credit') { $totalIn += $t; } else { $totalOut += $t; }
		}
		return Success(array(
			'CurrentBalance' => $this->ComputeBalanceAsOf($owner_type, $owner_id),
			'TotalIn'        => round($totalIn, 2),
			'TotalOut'       => round($totalOut, 2),
			'ByCategory'     => $byCat,
		));
	}

	/** Monthly cumulative balance points for the line chart. */
	public function GetBalanceSeries($token, $owner_type, $owner_id) {
		global $DB;
		if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
		$owner_type = $this->normType($owner_type);
		$owner_id   = (int)$owner_id;
		$open = $this->openingRecon($owner_type, $owner_id);
		$base = $open ? $open['actual_balance'] : 0.0;
		$where = "owner_type='$owner_type' AND owner_id=$owner_id AND deleted_at IS NULL";
		if ($open) { $where .= " AND entry_date >= '" . addslashes($open['as_of_date']) . "'"; }
		$DB->Clear();
		$rs = $DB->DataSet("SELECT DATE_FORMAT(entry_date,'%Y-%m') AS ym,
			SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END) AS net
			FROM " . DB_PREFIX . "treasury_entry WHERE $where GROUP BY ym ORDER BY ym ASC");
		$points = array(); $bal = $base;
		while ($rs && $rs->Next()) {
			$bal = round($bal + (float)$rs->net, 2);
			$points[] = array('Month' => $rs->ym, 'Balance' => $bal);
		}
		return Success(array('Points' => $points));
	}

	public function GetReconciliations($token, $owner_type, $owner_id) {
		global $DB;
		if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
		$owner_type = $this->normType($owner_type);
		$owner_id   = (int)$owner_id;
		$DB->Clear();
		$rs = $DB->DataSet("SELECT id, as_of_date, actual_balance, computed_balance, variance,
			explanation, is_opening, created_at
			FROM " . DB_PREFIX . "treasury_reconciliation
			WHERE owner_type='$owner_type' AND owner_id=$owner_id AND deleted_at IS NULL
			ORDER BY as_of_date DESC, id DESC");
		$rows = array();
		while ($rs && $rs->Next()) {
			$rows[] = array(
				'Id'              => (int)$rs->id,
				'AsOfDate'        => $rs->as_of_date,
				'ActualBalance'   => (float)$rs->actual_balance,
				'ComputedBalance' => (float)$rs->computed_balance,
				'Variance'        => (float)$rs->variance,
				'Explanation'     => $rs->explanation,
				'IsOpening'       => (int)$rs->is_opening,
				'CreatedAt'       => $rs->created_at,
			);
		}
		return Success(array('Rows' => $rows));
	}

	/** Add a reconciliation. If no opening exists yet, the first one is the opening (is_opening=1). */
	public function SaveReconciliation($token, $data) {
		$mundane_id = $this->authFor($token, $data['owner_type'] ?? '', $data['owner_id'] ?? 0);
		if (!$mundane_id) { return NoAuthorization(); }
		$owner_type = $this->normType($data['owner_type'] ?? '');
		$owner_id   = (int)($data['owner_id'] ?? 0);
		$asOf   = (string)($data['as_of_date'] ?? '');
		$actual = round((float)($data['actual_balance'] ?? 0), 2);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) { return InvalidParameter('Invalid date.'); }

		$isOpening   = $this->openingRecon($owner_type, $owner_id) === null;
		$computed    = $isOpening ? 0.0 : $this->ComputeBalanceAsOf($owner_type, $owner_id, $asOf);
		$variance    = round($actual - $computed, 2);
		$explanation = trim((string)($data['explanation'] ?? ''));
		if (!$isOpening && abs($variance) >= 0.01 && $explanation === '') {
			return InvalidParameter('Explanation required when the balance does not match.');
		}

		$this->recon->clear();
		$this->recon->owner_type       = $owner_type;
		$this->recon->owner_id         = $owner_id;
		$this->recon->as_of_date       = $asOf;
		$this->recon->actual_balance   = $actual;
		// For the opening row, store the seeded actual as computed so history reads cleanly.
		$this->recon->computed_balance = $isOpening ? $actual : $computed;
		$this->recon->variance         = $isOpening ? 0.0 : $variance;
		// yapo drops null fields; assign '' to clear the optional column rather than leave it stale.
		$this->recon->explanation      = $explanation !== '' ? $explanation : '';
		$this->recon->is_opening       = $isOpening ? 1 : 0;
		$this->recon->created_by       = $mundane_id;
		$this->recon->created_at       = date('Y-m-d H:i:s');
		$this->recon->save();
		return Success(array(
			'Id'        => (int)$this->recon->id,
			'IsOpening' => $isOpening,
			'Variance'  => $isOpening ? 0.0 : $variance,
			'Computed'  => $isOpening ? $actual : $computed,
		));
	}

	public function RecordTransaction($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0) {
			$request['SplitOne']['MundaneId'] = $mundane_id;
			$request['SplitTwo']['MundaneId'] = $mundane_id;
			$this->record_split($request['SplitOne'], $request['SplitTwo']);
			return Success();
		} else {
			return NoAuthorization();
		}
	}
	
	public function RemoveTransaction($request) {
	
	}
	
	public function dues_through($mundane_id, $kingdom_id, $park_id, $startdate) {
		$sql = "
			SELECT split.dues_through
				FROM 
					`" . DB_PREFIX . "split` split 
						left join " . DB_PREFIX . "account account on split.account_id = account.account_id
				where (kingdom_id = '" . mysql_real_escape_string($kingdom_id) . "' or park_id = '" . mysql_real_escape_string($park_id) . "') and src_mundane_id = '" . mysql_real_escape_string($mundane_id) . "' and is_dues = 1
				order by dues_through desc 
				limit 1
		";
		$lastdues = $this->db->query($sql);
		if ($lastdues != false && $lastdues->size() == 1) {
			if (strtotime($lastdues->dues_through) > strtotime($startdate))
				return $lastdues->dues_through;
		}
		return $startdate;
	}
	
	public function RemoveLastDuesPaid($request) {
        logtrace('RemoveLastDuesPaid', $request);
		if (($player = Ork3::$Lib->player->player_info($request['MundaneId'])) === false)
			return InvalidParameter('Player could not be found.');
        logtrace('Found Player', $request);
				
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $player['ParkId'], AUTH_EDIT)) {
			$sql = "select 
			                s.transaction_id 
			            from " . DB_PREFIX . "split s 
			                left join " . DB_PREFIX . "transaction t on s.transaction_id = t.transaction_id
			            where 
			                src_mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "' and is_dues = 1 order by t.date_created desc limit 1";
        logtrace('Passed Security', $sql);
			$lastdues = $this->db->query($sql);
    		if ($lastdues != false && $lastdues->size() == 1) {
    			$this->remove_transaction($lastdues->transaction_id);
    			return Success('Transaction ' . $lastdues->transaction_id . ' removed.');
    		}
		}
		return NoAuthorization('You lack authoratah.');
	}
	
	public function DuesPaidToPark($request) {
        logtrace('DuesPaidToPark', $request);
		if (($player = Ork3::$Lib->player->player_info($request['MundaneId'])) === false)
			return InvalidParameter('Player could not be found.');
				
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $player['ParkId'], AUTH_EDIT)) {
				
			$park_info = Ork3::$Lib->park->GetParkShortInfo(array('ParkId'=>$player['ParkId']));
			if ($park_info['Status']['Status'] > 0)
				return InvalidParameter('Park information could not be fetched.');
				
			$configuration = Common::get_configs($park_info['ParkInfo']['KingdomId']);
			if (!isset($configuration['DuesAmount']) || !isset($configuration['KingdomDuesTake'])) 
				return ProcessingError('Kingdom is missing DuesAmount or KingdomDuesTake configuration.');
				
			$full_name = $player['GivenName'] . ' ' . $player['Surname'];
				
			if (false !== ($pointers = $this->fetch_account_pointers(AUTH_PARK, $player['ParkId']))) {
                logtrace('record_transaction is free to enter dues', null);
				$duestart = $this->dues_through($request['MundaneId'], $player['KingdomId'], $player['ParkId'], $request['TransactionDate']);
				$throughdate = date("Y-m-d H:i:s", strtotime('+' . (6 * ceil($request['Semesters'])) . ' months', strtotime($duestart)));
				$r = $this->record_transaction(
					array(
						'RecordedBy' => $mundane_id,
						'Description' => 'Dues Paid for ' . $full_name,
						'Memo' => 'Dues Paid for ' . $full_name
						),
					array(
						array(
							'AccountId' => $pointers['DuesPaid'],
							'IsDues' =>  1,
							'SrcMundaneId' => $request['MundaneId'],
							'DrCr' => TreasuryDrCr::Cr,
							'Amount' => $configuration['DuesAmount']['Value'] * $request['Semesters'],
							'DuesThrough' =>  $throughdate
							),
						array(
							'AccountId' => $pointers['Cash'],
							'IsDues' =>  0,
							'SrcMundaneId' => $request['MundaneId'],
							'DrCr' => TreasuryDrCr::Dr,
							'Amount' => $configuration['DuesAmount']['Value'] * $request['Semesters'],
							),
						array(
							'AccountId' => $pointers['DuesOwed'],
							'IsDues' =>  0,
							'SrcMundaneId' => $request['MundaneId'], 
							'DrCr' => TreasuryDrCr::Cr,
							'Amount' => $configuration['KingdomDuesTake']['Value'] * $request['Semesters']
							),
						array(
							'AccountId' => $pointers['KingdomTake'],
							'IsDues' =>  0,
							'SrcMundaneId' => $request['MundaneId'], 
							'DrCr' => TreasuryDrCr::Dr,
							'Amount' => $configuration['KingdomDuesTake']['Value'] * $request['Semesters']
							)),
						$request['TransactionDate']
				);
                logtrace('Recording info: ', $r);
                return $r;
			} else {
                logtrace('Dues not paid: -EINVAL');
				return InvalidParameter();
			}
		} else {
            logtrace('Dues not paid: no authority; ', 0);
			return NoAuthorization('You lack authoratah.');
		}
	}
	
	public function DuesPaidToKingdom($request) {
	
	}
	
	public function KingdomTithe($request) {
	
	}
	
	public function KingdomLevy($request) {
	
	}
	
	public function Donation($request) {
	
	}
	
	public function EventFee($request) {
	
	}
	
	public function PurchaseSupplies($request) {
	
	}
	
	public function EventExpense($request) {
	}
	
	public function CreateAccount($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0) {
			$this->create_account($mundane_id, $request['ParentId'], $request['AccountName'], $request['AccountType'], $request['OpeningBalance'], $request['OwnerType'], $request['Id'], $request['KingdomId']);
		} else {
			return NoAuthorization();
		}
	}
	
	public function CreateAccounts($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0) {
			$this->create_accounts($mundane_id, $request['Type'], $request['Id'], $request['KingdomId']);
		} else {
			return NoAuthorization();
		}
	}
	
	public function fetch_kingdom_details($id) {
		$config = new yapo($this->db, DB_PREFIX . 'configuration');
		$config->clear();
		$config->type = 'Kingdom';
		$config->id = $id;
		$config->in('key', "'DuesPeriod','DuesAmount','KingdomDuesTake'");
		if ($config->find()) {
			return json_decode($config->value);
		} else {
			return false;
		}
	}
	
	public function fetch_account_pointers($type, $id) {
		$config = new yapo($this->db, DB_PREFIX . 'configuration');
		$config->clear();
		$config->type = ucfirst($type);
		$config->id = $id;
		$config->key = 'AccountPointers';
		if ($config->find()) {
			return json_decode($config->value, true);
		} else {
			return false;
		}
	}
	
	public function create_accounts($mundane_id, $type, $id, $kingdom_id=0) {
		if ('park' == $type && $kingdom_id ==0) {
			return false;
		} else if ('park' == $type) {
			$pointers = $this->fetch_account_pointers('kingdom', $kingdom_id);
			if (false === $pointers) {
				return false;
			}
			$kingdom_parkdues = $pointers['ParkDues'];
		}
	
		$imbalance = $this->create_account($mundane_id, 0, 'Imbalance', TreasuryAccountType::Imbalance, 0.0, $type, $id, $kingdom_id);
		
		$equity = $this->create_account($mundane_id, 0, 'Equity', TreasuryAccountType::Equity, 0.0, $type, $id, $kingdom_id);
		
		$asset = $this->create_account($mundane_id, 0, 'Assets', TreasuryAccountType::Asset, 0.0, $type, $id, $kingdom_id);
		$cash = $this->create_account($mundane_id, $asset, 'Cash', TreasuryAccountType::Asset, 0.0, $type, $id, $kingdom_id);
		$checking = $this->create_account($mundane_id, $asset, 'Checking', TreasuryAccountType::Asset, 0.0, $type, $id, $kingdom_id);
		if ('kingdom' == $type)
			$kingdom_parkdues = $this->create_account($mundane_id, $asset, 'Park Dues', TreasuryAccountType::Asset, 0.0, $type, $id, $kingdom_id);
		
		$income = $this->create_account($mundane_id, 0, 'Income', TreasuryAccountType::Income, 0.0, $type, $id, $kingdom_id);
		$duespaid = $this->create_account($mundane_id, $income, 'Dues Paid', TreasuryAccountType::Income, 0.0, $type, $id, $kingdom_id);
		$donations = $this->create_account($mundane_id, $income, 'Donations', TreasuryAccountType::Income, 0.0, $type, $id, $kingdom_id);
		
		$expense = $this->create_account($mundane_id, 0, 'Expenses', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
		$supplies = $this->create_account($mundane_id, $expense, 'Supplies', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
		if ('kingdom' == $type)
			$kingdomtake = $this->create_account($mundane_id, $expense, 'Kingdom Take', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
		$events = $this->create_account($mundane_id, $expense, 'Events', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
		$food = $this->create_account($mundane_id, $events, 'Food', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
		$site = $this->create_account($mundane_id, $events, 'Site', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
		$misc = $this->create_account($mundane_id, $events, 'Miscellaneous', TreasuryAccountType::Expense, 0.0, $type, $id, $kingdom_id);
		
		$liability = $this->create_account($mundane_id, 0, 'Liability', TreasuryAccountType::Liability, 0.0, $type, $id, $kingdom_id);
		$duesowed = $this->create_account($mundane_id, $liability, 'Dues Owed', TreasuryAccountType::Liability, 0.0, $type, $id, $kingdom_id);
		
		$c = new Common();
		$c->add_config($mundane_id, ucfirst($type), 'mixed', $id, 'AccountPointers', array(
																			'Imbalance' => $imbalance,
																			'Equity' => $equity,
																			'Asset' => $asset,
																			'Cash' => $cash,
																			'Checking' => $checking,
																			'ParkDues' => $parkdues,
																			'Income' => $income,
																			'DuesPaid' => $duespaid,
																			'Donations' => $donations,
																			'Expense' => $expense,
																			'Supplies' => $supplies,
																			'KingdomTake' => $kingdomtake,
																			'Events' => $events,
																			'Food' => $food,
																			'Site' => $site,
																			'Miscellaneous' => $misc,
																			'Liability' => $liability,
																			'DuesOwed' => $duesowed,
																			'Kingdom_ParkDues' => $kingdom_parkdues
																		), 0);
	}
	
	public function has_account_authority($mundane_id, $account_id) {
		$this->account->clear();
		$this->account->account_id = $account_id;
		$this->account->find();
		list($type, $id) = $this->DetermineAuthType();
        logtrace('has_account_authority', array($mundane_id, $account_id, $type, $id));
		return Ork3::$Lib->authorization->HasAuthority($mundane_id, $type, $id, AUTH_EDIT);
	}
	
	private function DetermineAuthType() {
		$type = 'None';
		$id = 0;
		if ($this->account->park_id > 0) { $type = AUTH_PARK; $id = $this->account->park_id; };
		if ($this->account->event_id > 0) { $type = AUTH_EVENT; $id = $this->account->event_id; };
		if ($this->account->unit_id > 0) { $type = AUTH_UNIT; $id = $this->account->unit_id; };
		if ($type == 'None')
			if ($this->account->kingdom_id > 0) { $type = AUTH_KINGDOM; $id = $this->account->kingdom_id; };
		return array ( $type, $id );
	}
	
	public function remove_transaction($trx_id) {
	    $this->split->clear();
	    $this->split->transaction_id = $trx_id;
	    $this->split->delete();
	    $this->transaction->clear();
	    $this->transaction->transaction_id = $trx_id;
	    $this->transaction->delete();
	}
	
	public function record_transaction($trn, $splits, $trx_date = null) {
        logtrace('record_transaction', array($trn, $splits, $trx_date));
		$trx_date = is_null($trx_date)?date('Y-m-d'):date('Y-m-d',strtotime($trx_date));
        $authority = false;
		foreach ($splits as $s => $split) {
			$authority |= $this->has_account_authority($trn['RecordedBy'], $split['AccountId']);
			$this->account->clear();
			$this->account->account_id = $split['AccountId'];	
			if (!$this->account->find()) { 
    			return InvalidParameter(print_r(array($split['AccountId'],true)));
			} else {
				$splits[$s]['AccountType'] = $this->account->type;
			}
		}	
        if (!$authority) return NoAuthorization(print_r(array($trn['RecordedBy'], $split['AccountId']),true));
        
		$this->transaction->clear();
		$this->transaction->recorded_by = $trn['RecordedBy'];
		$this->transaction->date_created = date("Y-m-d H:i:s");
		$this->transaction->description = $trn['Description'];
		$this->transaction->memo = $trn['Memo'];
		$this->transaction->transaction_date = $trx_date;
		$this->transaction->save();
		
		$debit = 0.0;
		$credit = 0.0;
		
		foreach ($splits as $s => $split) {
			$this->split->clear();
			$this->split->transaction_id = $this->transaction->transaction_id;
			$this->split->account_id = $split['AccountId'];
			$this->split->is_dues = $split['IsDues'];
			$this->split->src_mundane_id = $split['SrcMundaneId'];
			$this->split->dues_through = strlen($split['DuesThrough'])>0?$split['DuesThrough']:null;
			$this->split->amount = round($split['DrCr']==$this->dr_cr_sign_convention($split['AccountType'], $split['Amount'])?$split['Amount']:-$split['Amount'],3);
			
			$this->split->save();
			
			if ($this->dr_cr_sign_convention($split['AccountType'], $split['Amount']) == TreasuryDrCr::Dr) {
				$debit += $this->split->amount;
			} else {
				$credit += $this->split->amount;
			}
		}
		
		if (abs($debit - $credit) > 0.005) {
			$this->account->clear();
			$this->account->account_id = $this->split->account_id;
			$this->account->find();
			$k = $this->account->kingdom_id;
			$idt = $this->DetermineAuthType().'_id';
			$id = $this->account->$idt;
			$this->account->clear();
			$this->account->kingdom_id = $k;
			$this->account->$idt = $id;
			$this->account->type = TreasuryAccountType::Imbalance;
			if ($this->account->find()) {
				$this->split->clear();
				$this->split->transaction_id = $this->transaction->transaction_id;
				$this->split->account_id = $split['AccountId'];
				$this->split->is_dues = 0;
				$this->split->src_mundane_id = $split['SrcMundaneId'];
				$this->split->amount = $credit - $debit;
				
				$this->split->save();
			} else {
				// crap
				return InvalidParameter('Canno record split.');
			}
		}
	}
	
	public function create_account($mundane_id, $parent_id, $account_name, $account_type, $opening_balance, $owner_type, $owner_id, $kingdom_id=0) {
		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, ucfirst($owner_type), $owner_id, AUTH_CREATE)) return false;
		$owner = $owner_type . '_id';
		$this->account->clear();
		$this->account->kingdom_id = $kingdom_id;
		$this->account->$owner = $owner_id;
		$this->account->name = $account_name;
		$this->account->parent_id = $parent_id;
		$this->account->type = $account_type;
		if ($this->account->find()) {
			// fuck off, seriously?
			$new_account = $this->account->account_id;
//			return false;
		} else {
			$this->account->clear();
			$this->account->kingdom_id = $kingdom_id;
			$this->account->$owner = $owner_id;
			$this->account->name = $account_name;
			$this->account->parent_id = $parent_id;
			$this->account->type = $account_type;
			$this->account->save();
			$new_account = $this->account->account_id;
		}
		$this->account->clear();
		$this->account->kingdom_id = $kingdom_id;
		$this->account->$owner = $owner_id;
		$this->account->name = 'Equity';
		$this->account->parent_id = 0;
		$this->account->type = 'equity';
		if (!$this->account->find()) {
			// Needs an equity account
			$this->account->clear();
			$this->account->kingdom_id = $kingdom_id;
			$this->account->$owner = $owner_id;
			$this->account->name = 'Equity';
			$this->account->parent_id = 0;
			$this->account->type = 'equity';
			$this->account->save();
		}
		return $new_account;
	}
	
	/*********************************************
	
	Account Type	Normal Balance	Increase	Decrease
	Asset			Dr				Dr			Cr
	Expense			Dr				Dr			Cr
	Draws			Dr				Dr			Cr* For completeness
	Liability		Cr				Cr			Dr
	Equity			Cr				Cr			Dr
	Revenue			Cr				Cr			Dr
	Imbalance		Cr				Cr			Dr* Treated as an equity account
	
	Dr/Cr
	
	*********************************************/
	
	
	function normalize_sign($t, $amt) {
		switch ($t) {
			case TreasuryAccountType::Asset:
			case TreasuryAccountType::Expense:
			case TreasuryAccountType::Draw:
				return $amt>=0?array(TreasuryDrCr::Dr,$amt):array(TreasuryDrCr::Cr,-$amt);
			case TreasuryAccountType::Liability:
			case TreasuryAccountType::Income:
			case TreasuryAccountType::Equity:
			case TreasuryAccountType::Imbalance:
				return $amt>=0?array(TreasuryDrCr::Cr,$amt):array(TreasuryDrCr::Dr,-$amt);
		}
	}
	
	function sign_convention($t1, $amt, $t2) {
		if (0.0 == $amt) return 0.0;
		$drcr = $this->dr_cr_sign_convention($t1, $amt);
		if ($drcr == TreasuryDrCr::Dr) {
			$drcr = TreasuryDrCr::Cr;
		} else {
			$drcr = TreasuryDrCr::Dr;
		}
		switch ($t2) {
			case TreasuryAccountType::Asset:
			case TreasuryAccountType::Expense:
			case TreasuryAccountType::Draw:
				return $drcr==TreasuryDrCr::Dr?$amt:-$amt;
			case TreasuryAccountType::Liability:
			case TreasuryAccountType::Income:
			case TreasuryAccountType::Equity:
			case TreasuryAccountType::Imbalance:
				return $drcr==TreasuryDrCr::Dr?-$amt:$amt;
		}
	}
	
	function dr_cr_sign_convention($t, $sign) {
		switch ($t) {
			case TreasuryAccountType::Asset:
			case TreasuryAccountType::Expense:
			case TreasuryAccountType::Draw:
				return $sign>=0?TreasuryDrCr::Dr:TreasuryDrCr::Cr;
			case TreasuryAccountType::Liability:
			case TreasuryAccountType::Income:
			case TreasuryAccountType::Equity:
			case TreasuryAccountType::Imbalance:
				return $sign>=0?TreasuryDrCr::Cr:TreasuryDrCr::Dr;
		}
	}
}

class TreasuryDrCr {
	const Dr = "Dr";
	const Cr = "Cr";
}

class TreasuryAccountType {
	const Income = 'Income';
	const Expense = 'Expense';
	const Draw = 'Draw';
	const Liability = 'Liability';
	const Asset = 'Asset';
	const Equity = 'Equity';
	const Imbalance = 'Imbalance';
}

?>