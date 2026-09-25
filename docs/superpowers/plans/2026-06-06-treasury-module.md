# Treasury Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an officer-only, per-org (Kingdom/Park) financial ledger with credits/debits, a computed running balance, snapshot reconciliation, soft-delete + audit trail, summary + charts + CSV export — reached as a standalone tool from the Admin menu.

**Architecture:** Standard ORK3 three layers. DB logic in `system/lib/ork3/class.Treasury.php` (YAPO + raw `$DB` reads, auth via `IsAuthorized`/`HasAuthority`, returns `Success()`/`NoAuthorization()`). Thin `orkui/model/model.Treasury.php` (`APIModel` pass-through). A page controller `controller.Treasury.php` renders `Treasury_index.tpl`; an AJAX controller `controller.TreasuryAjax.php` serves JSON CRUD. Running balance is always computed (never stored), anchored to the opening reconciliation.

**Tech Stack:** PHP 8 / MariaDB, YAPO ORM, plain-PHP `.tpl` templates (`extract()`+include), Highcharts (CDN), no PHPUnit — verification via `php -l` lint + curl-auth integration against the Docker app (`ork3-php8-app`) + synthetic rows.

**Spec:** `docs/superpowers/specs/2026-06-06-treasury-module-design.md`

**Conventions (project memory — honor all):**
- `.tpl` = plain PHP (`<?php ?>`/`<?= ?>`), never Smarty.
- Always `$DB->Clear()` before raw Execute/DataSet.
- No native `confirm()/alert()` → `tnConfirm()`. No native `title` tooltips → `data-tip`. No native datetime → flatpickr `altInput`/`altFormat`.
- Dark-mode compatible proactively. Reset global h1–h6 gray-box on any custom heading.
- Debug output → browser console / `die(json_encode(...))`, never `error_log`.
- Edit PHP normalize-first: `awk '/^\t/{c++} END{print c+0}' <file>` → 0 = clean (Edit tool ok); else run `php tools/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php <file>` first.
- Stage files explicitly — never `git add -A`/`.`; never stage `class.Authorization.php`.

---

## File Map

| File | Responsibility | New/Modify |
|---|---|---|
| `db-migrations/2026-06-06-treasury.sql` | The four tables | Create |
| `system/lib/ork3/class.Treasury.php` | All DB + auth + balance/reconcile logic | Create |
| `orkui/model/model.Treasury.php` | Thin APIModel pass-through | Create |
| `orkui/controller/controller.Treasury.php` | Page controller (gates, loads, renders) | Create |
| `orkui/controller/controller.TreasuryAjax.php` | JSON CRUD/reconcile/export endpoints | Create |
| `orkui/template/revised-frontend/Treasury_index.tpl` | The tool UI (inlined CSS/JS, `tr-` prefix) | Create |
| `orkui/controller/controller.Kingdom.php` | Add Treasury link to admin menu | Modify (~line 30) |
| `orkui/controller/controller.Park.php` | Add Treasury link to admin menu | Modify (~line 38) |
| `orkui/template/default/Admin_kingdom.tpl` | Treasury sidebar link | Modify |
| `orkui/template/default/Admin_park.tpl` | Treasury sidebar link | Modify |

**Category keys (authoritative; labels in PHP):**
Income: `dues`, `fundraiser`, `donation`, `event_revenue`, `income_other`.
Expense: `supplies`, `equipment`, `site_rental`, `awards_regalia`, `reimbursement`, `expense_other`.

---

## Task 1: Database Migration

**Files:**
- Create: `db-migrations/2026-06-06-treasury.sql`

- [ ] **Step 1: Write the migration SQL**

```sql
-- Treasury module: per-org financial ledger (Kingdom/Park)
-- 2026-06-06

CREATE TABLE `ork_treasury_entry` (
  `id`             int(11)       NOT NULL AUTO_INCREMENT,
  `owner_type`     enum('kingdom','park') NOT NULL,
  `owner_id`       int(11)       NOT NULL,
  `entry_date`     date          NOT NULL,
  `direction`      enum('credit','debit') NOT NULL,
  `amount`         decimal(12,2) NOT NULL,
  `category`       varchar(64)   NOT NULL,
  `payment_method` enum('cash','check','digital') NOT NULL,
  `description`    varchar(255)  NOT NULL DEFAULT '',
  `counterparty`   varchar(255)  DEFAULT NULL,
  `reference_no`   varchar(64)   DEFAULT NULL,
  `deleted_at`     datetime      DEFAULT NULL,
  `created_by`     int(11)       NOT NULL,
  `created_at`     datetime      NOT NULL,
  `updated_at`     datetime      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_owner_date` (`owner_type`,`owner_id`,`entry_date`),
  KEY `ix_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ork_treasury_reconciliation` (
  `id`               int(11)       NOT NULL AUTO_INCREMENT,
  `owner_type`       enum('kingdom','park') NOT NULL,
  `owner_id`         int(11)       NOT NULL,
  `as_of_date`       date          NOT NULL,
  `actual_balance`   decimal(12,2) NOT NULL,
  `computed_balance` decimal(12,2) NOT NULL,
  `variance`         decimal(12,2) NOT NULL,
  `explanation`      varchar(500)  DEFAULT NULL,
  `is_opening`       tinyint(1)    NOT NULL DEFAULT 0,
  `deleted_at`       datetime      DEFAULT NULL,
  `created_by`       int(11)       NOT NULL,
  `created_at`       datetime      NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_owner_asof` (`owner_type`,`owner_id`,`as_of_date`),
  KEY `ix_owner_opening` (`owner_type`,`owner_id`,`is_opening`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ork_treasury_audit` (
  `id`          int(11)  NOT NULL AUTO_INCREMENT,
  `entry_id`    int(11)  NOT NULL,
  `action`      enum('create','edit','delete') NOT NULL,
  `changed_by`  int(11)  NOT NULL,
  `changed_at`  datetime NOT NULL,
  `before_json` text     DEFAULT NULL,
  `after_json`  text     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_entry` (`entry_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply the migration**

Run: `docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-06-06-treasury.sql`
Expected: no error output.

- [ ] **Step 3: Verify tables exist**

Run: `docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW TABLES LIKE 'ork_treasury%'; DESCRIBE ork_treasury_entry;"`
Expected: three tables listed; `ork_treasury_entry` shows all columns above.

- [ ] **Step 4: Commit**

```bash
git add db-migrations/2026-06-06-treasury.sql
git commit -m "Treasury: add ledger/reconciliation/audit tables"
```

---

## Task 2: Treasury lib — class scaffold, categories, balance computation

**Files:**
- Create: `system/lib/ork3/class.Treasury.php`

Mirror `class.Park.php` (constructor, YAPO, `Ork3::$Lib->authorization`, `Success()/NoAuthorization()/InvalidParameter()`). Lib methods take a `$token` first arg, resolve `$mundane_id = Ork3::$Lib->authorization->IsAuthorized($token)`, then `HasAuthority($mundane_id, $authType, $owner_id, AUTH_EDIT)` where `$authType = ($owner_type==='park') ? AUTH_PARK : AUTH_KINGDOM`.

- [ ] **Step 1: Write the scaffold + category map + auth helper + balance computation**

```php
<?php

class Treasury extends Ork3
{
    public static $CATEGORIES = [
        'income' => [
            'dues'          => 'Dues',
            'fundraiser'    => 'Fundraiser',
            'donation'      => 'Donation',
            'event_revenue' => 'Event Revenue',
            'income_other'  => 'Other Income',
        ],
        'expense' => [
            'supplies'      => 'Supplies',
            'equipment'     => 'Equipment',
            'site_rental'   => 'Site / Rental',
            'awards_regalia'=> 'Awards / Regalia',
            'reimbursement' => 'Reimbursement',
            'expense_other' => 'Other Expense',
        ],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->entry  = new yapo($this->db, DB_PREFIX . 'treasury_entry');
        $this->recon  = new yapo($this->db, DB_PREFIX . 'treasury_reconciliation');
        $this->audit  = new yapo($this->db, DB_PREFIX . 'treasury_audit');
    }

    /** Resolve auth; returns mundane_id (>0) or 0 if unauthorized for this org. */
    private function authFor($token, $owner_type, $owner_id)
    {
        $owner_type = ($owner_type === 'park') ? 'park' : 'kingdom';
        $authType   = ($owner_type === 'park') ? AUTH_PARK : AUTH_KINGDOM;
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($token);
        if ($mundane_id > 0 && Ork3::$Lib->authorization->HasAuthority($mundane_id, $authType, (int)$owner_id, AUTH_EDIT)) {
            return (int)$mundane_id;
        }
        return 0;
    }

    private function normType($t) { return ($t === 'park') ? 'park' : 'kingdom'; }

    /** Opening baseline: [as_of_date, actual_balance] or null if none. */
    private function openingRecon($owner_type, $owner_id)
    {
        global $DB;
        $owner_type = $this->normType($owner_type);
        $owner_id   = (int)$owner_id;
        $DB->Clear();
        $rs = $DB->DataSet("SELECT as_of_date, actual_balance FROM " . DB_PREFIX . "treasury_reconciliation
            WHERE owner_type='$owner_type' AND owner_id=$owner_id AND is_opening=1 AND deleted_at IS NULL
            ORDER BY id ASC LIMIT 1");
        if ($rs && $rs->Next()) {
            return ['as_of_date' => $rs->as_of_date, 'actual_balance' => (float)$rs->actual_balance];
        }
        return null;
    }

    /** Running balance over non-deleted entries up to (and including) $upToDate (null = all). */
    public function ComputeBalanceAsOf($owner_type, $owner_id, $upToDate = null)
    {
        global $DB;
        $owner_type = $this->normType($owner_type);
        $owner_id   = (int)$owner_id;
        $open       = $this->openingRecon($owner_type, $owner_id);
        $base       = $open ? $open['actual_balance'] : 0.0;
        $fromDate   = $open ? $open['as_of_date'] : null;

        $where = "owner_type='$owner_type' AND owner_id=$owner_id AND deleted_at IS NULL";
        if ($fromDate)   { $where .= " AND entry_date >= '" . addslashes($fromDate) . "'"; }
        if ($upToDate)   { $where .= " AND entry_date <= '" . addslashes($upToDate) . "'"; }

        $DB->Clear();
        $rs = $DB->DataSet("SELECT
            COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END),0) AS credits,
            COALESCE(SUM(CASE WHEN direction='debit'  THEN amount ELSE 0 END),0) AS debits
            FROM " . DB_PREFIX . "treasury_entry WHERE $where");
        $credits = 0.0; $debits = 0.0;
        if ($rs && $rs->Next()) { $credits = (float)$rs->credits; $debits = (float)$rs->debits; }
        // integer-cent-safe
        return round($base + $credits - $debits, 2);
    }

    public function HasOpeningBalance($token, $owner_type, $owner_id)
    {
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        return Success(['HasOpening' => $this->openingRecon($owner_type, $owner_id) !== null]);
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l system/lib/ork3/class.Treasury.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verify lib autoloads + balance works (synthetic)**

Insert two synthetic rows, then call via a one-off harness in the app container:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "
INSERT INTO ork_treasury_reconciliation (owner_type,owner_id,as_of_date,actual_balance,computed_balance,variance,is_opening,created_by,created_at)
 VALUES ('park',1,'2026-01-01',100.00,0,100.00,1,1,NOW());
INSERT INTO ork_treasury_entry (owner_type,owner_id,entry_date,direction,amount,category,payment_method,created_by,created_at)
 VALUES ('park',1,'2026-02-01','credit',50.00,'dues','cash',1,NOW()),
        ('park',1,'2026-02-02','debit',20.00,'supplies','check',1,NOW());"
```
Then exercise via curl after Task 6/AJAX exists; for now confirm autoload by lint only. Expected balance later: 100 + 50 − 20 = 130.00.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Treasury.php
git commit -m "Treasury lib: scaffold, categories, balance computation"
```

---

## Task 3: Treasury lib — entry CRUD + audit

**Files:**
- Modify: `system/lib/ork3/class.Treasury.php`

- [ ] **Step 1: Add entry CRUD + audit writer**

```php
    private const VALID_METHODS = ['cash','check','digital'];

    private function validCategory($cat)
    {
        return isset(self::$CATEGORIES['income'][$cat]) || isset(self::$CATEGORIES['expense'][$cat]);
    }

    private function writeAudit($entry_id, $action, $mundane_id, $before, $after)
    {
        $this->audit->clear();
        $this->audit->entry_id    = (int)$entry_id;
        $this->audit->action      = $action;
        $this->audit->changed_by  = (int)$mundane_id;
        $this->audit->changed_at  = date('Y-m-d H:i:s');
        $this->audit->before_json = $before === null ? null : json_encode($before);
        $this->audit->after_json  = $after  === null ? null : json_encode($after);
        $this->audit->save();
    }

    private function entryToArray()
    {
        return [
            'id' => $this->entry->id, 'owner_type' => $this->entry->owner_type,
            'owner_id' => $this->entry->owner_id, 'entry_date' => $this->entry->entry_date,
            'direction' => $this->entry->direction, 'amount' => $this->entry->amount,
            'category' => $this->entry->category, 'payment_method' => $this->entry->payment_method,
            'description' => $this->entry->description, 'counterparty' => $this->entry->counterparty,
            'reference_no' => $this->entry->reference_no, 'deleted_at' => $this->entry->deleted_at,
        ];
    }

    /** Create or edit. $data: owner_type, owner_id, [id], entry_date, direction, amount,
     *  category, payment_method, description, counterparty, reference_no. */
    public function SaveEntry($token, $data)
    {
        $mundane_id = $this->authFor($token, $data['owner_type'] ?? '', $data['owner_id'] ?? 0);
        if (!$mundane_id) { return NoAuthorization(); }

        $direction = ($data['direction'] ?? '') === 'debit' ? 'debit' : 'credit';
        $amount    = round((float)($data['amount'] ?? 0), 2);
        $cat       = (string)($data['category'] ?? '');
        $method    = (string)($data['payment_method'] ?? '');
        $entryDate = (string)($data['entry_date'] ?? '');

        if ($amount <= 0) { return InvalidParameter('Amount must be greater than zero.'); }
        if (!$this->validCategory($cat)) { return InvalidParameter('Unknown category.'); }
        if (!in_array($method, self::VALID_METHODS, true)) { return InvalidParameter('Payment method is required.'); }
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
        $this->entry->counterparty   = ($data['counterparty'] ?? '') !== '' ? $data['counterparty'] : null;
        $this->entry->reference_no   = ($data['reference_no'] ?? '') !== '' ? $data['reference_no'] : null;
        if ($isEdit) { $this->entry->updated_at = date('Y-m-d H:i:s'); }
        $this->entry->save();

        $id = (int)$this->entry->id;
        $this->writeAudit($id, $isEdit ? 'edit' : 'create', $mundane_id, $before, $this->entryToArray());
        return Success(['Id' => $id]);
    }

    public function DeleteEntry($token, $owner_type, $owner_id, $id)
    {
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
```

- [ ] **Step 2: Lint**

Run: `php -l system/lib/ork3/class.Treasury.php` → `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Treasury.php
git commit -m "Treasury lib: entry create/edit/delete with audit log"
```

---

## Task 4: Treasury lib — ledger read, reconciliation, summary, series

**Files:**
- Modify: `system/lib/ork3/class.Treasury.php`

- [ ] **Step 1: Add read/reporting + reconciliation methods**

```php
    /** Paged ledger with running balance. $filters: from, to, category, direction, page, per. */
    public function GetLedger($token, $owner_type, $owner_id, $filters = [])
    {
        global $DB;
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        $owner_type = $this->normType($owner_type); $owner_id = (int)$owner_id;
        $where = "owner_type='$owner_type' AND owner_id=$owner_id AND deleted_at IS NULL";
        if (!empty($filters['from']))      { $where .= " AND entry_date >= '" . addslashes($filters['from']) . "'"; }
        if (!empty($filters['to']))        { $where .= " AND entry_date <= '" . addslashes($filters['to']) . "'"; }
        if (!empty($filters['category']))  { $where .= " AND category = '" . addslashes($filters['category']) . "'"; }
        if (!empty($filters['direction'])) { $where .= " AND direction = '" . addslashes($filters['direction']) . "'"; }

        // Opening anchor for running balance
        $open = $this->openingRecon($owner_type, $owner_id);
        $base = $open ? $open['actual_balance'] : 0.0;
        $openDate = $open ? $open['as_of_date'] : null;

        $DB->Clear();
        $rs = $DB->DataSet("SELECT id, entry_date, direction, amount, category, payment_method,
            description, counterparty, reference_no
            FROM " . DB_PREFIX . "treasury_entry WHERE $where
            ORDER BY entry_date ASC, id ASC");
        $rows = [];
        $bal  = $base;
        // running balance must start from opening regardless of filter; recompute pre-filter offset:
        // simplest correct approach: when filters present, seed bal with balance just before first shown date.
        while ($rs && $rs->Next()) {
            $delta = ($rs->direction === 'credit') ? (float)$rs->amount : -(float)$rs->amount;
            $bal   = round($bal + $delta, 2);
            $rows[] = [
                'Id' => (int)$rs->id, 'Date' => $rs->entry_date, 'Direction' => $rs->direction,
                'Amount' => (float)$rs->amount, 'Category' => $rs->category,
                'PaymentMethod' => $rs->payment_method, 'Description' => $rs->description,
                'Counterparty' => $rs->counterparty, 'ReferenceNo' => $rs->reference_no,
                'RunningBalance' => $bal,
            ];
        }
        // pagination (post-compute so running balance stays correct), newest first for display
        $rows = array_reverse($rows);
        $per  = max(1, (int)($filters['per'] ?? 25));
        $page = max(1, (int)($filters['page'] ?? 1));
        $total = count($rows);
        $paged = array_slice($rows, ($page - 1) * $per, $per);
        return Success(['Rows' => $paged, 'Total' => $total, 'Page' => $page, 'Per' => $per,
            'CurrentBalance' => $this->ComputeBalanceAsOf($owner_type, $owner_id)]);
    }

    public function GetEntry($token, $owner_type, $owner_id, $id)
    {
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        $this->entry->clear(); $this->entry->id = (int)$id;
        if (!$this->entry->find() || $this->entry->deleted_at !== null
            || (int)$this->entry->owner_id !== (int)$owner_id
            || $this->entry->owner_type !== $this->normType($owner_type)) {
            return InvalidParameter('Entry not found.');
        }
        return Success($this->entryToArray());
    }

    /** Summary for a date range: current balance, period in/out, by-category totals. */
    public function GetSummary($token, $owner_type, $owner_id, $from = null, $to = null)
    {
        global $DB;
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        $owner_type = $this->normType($owner_type); $owner_id = (int)$owner_id;
        $where = "owner_type='$owner_type' AND owner_id=$owner_id AND deleted_at IS NULL";
        if ($from) { $where .= " AND entry_date >= '" . addslashes($from) . "'"; }
        if ($to)   { $where .= " AND entry_date <= '" . addslashes($to) . "'"; }
        $DB->Clear();
        $rs = $DB->DataSet("SELECT direction, category,
            COALESCE(SUM(amount),0) AS total
            FROM " . DB_PREFIX . "treasury_entry WHERE $where GROUP BY direction, category");
        $byCat = []; $totalIn = 0.0; $totalOut = 0.0;
        while ($rs && $rs->Next()) {
            $t = (float)$rs->total;
            $byCat[$rs->category] = ($byCat[$rs->category] ?? 0) + $t;
            if ($rs->direction === 'credit') { $totalIn += $t; } else { $totalOut += $t; }
        }
        return Success([
            'CurrentBalance' => $this->ComputeBalanceAsOf($owner_type, $owner_id),
            'TotalIn'  => round($totalIn, 2), 'TotalOut' => round($totalOut, 2),
            'ByCategory' => $byCat,
        ]);
    }

    /** Monthly cumulative balance points for the line chart. */
    public function GetBalanceSeries($token, $owner_type, $owner_id)
    {
        global $DB;
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        $owner_type = $this->normType($owner_type); $owner_id = (int)$owner_id;
        $open = $this->openingRecon($owner_type, $owner_id);
        $base = $open ? $open['actual_balance'] : 0.0;
        $where = "owner_type='$owner_type' AND owner_id=$owner_id AND deleted_at IS NULL";
        if ($open) { $where .= " AND entry_date >= '" . addslashes($open['as_of_date']) . "'"; }
        $DB->Clear();
        $rs = $DB->DataSet("SELECT DATE_FORMAT(entry_date,'%Y-%m') AS ym,
            SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END) AS net
            FROM " . DB_PREFIX . "treasury_entry WHERE $where GROUP BY ym ORDER BY ym ASC");
        $points = []; $bal = $base;
        while ($rs && $rs->Next()) { $bal = round($bal + (float)$rs->net, 2); $points[] = ['Month' => $rs->ym, 'Balance' => $bal]; }
        return Success(['Points' => $points]);
    }

    public function GetReconciliations($token, $owner_type, $owner_id)
    {
        global $DB;
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        $owner_type = $this->normType($owner_type); $owner_id = (int)$owner_id;
        $DB->Clear();
        $rs = $DB->DataSet("SELECT id, as_of_date, actual_balance, computed_balance, variance,
            explanation, is_opening, created_at
            FROM " . DB_PREFIX . "treasury_reconciliation
            WHERE owner_type='$owner_type' AND owner_id=$owner_id AND deleted_at IS NULL
            ORDER BY as_of_date DESC, id DESC");
        $rows = [];
        while ($rs && $rs->Next()) {
            $rows[] = ['Id' => (int)$rs->id, 'AsOfDate' => $rs->as_of_date,
                'ActualBalance' => (float)$rs->actual_balance, 'ComputedBalance' => (float)$rs->computed_balance,
                'Variance' => (float)$rs->variance, 'Explanation' => $rs->explanation,
                'IsOpening' => (int)$rs->is_opening, 'CreatedAt' => $rs->created_at];
        }
        return Success(['Rows' => $rows]);
    }

    /** Add a reconciliation. If no opening exists yet, the first one is the opening (is_opening=1). */
    public function SaveReconciliation($token, $data)
    {
        $mundane_id = $this->authFor($token, $data['owner_type'] ?? '', $data['owner_id'] ?? 0);
        if (!$mundane_id) { return NoAuthorization(); }
        $owner_type = $this->normType($data['owner_type']); $owner_id = (int)$data['owner_id'];
        $asOf   = (string)($data['as_of_date'] ?? '');
        $actual = round((float)($data['actual_balance'] ?? 0), 2);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) { return InvalidParameter('Invalid date.'); }

        $isOpening = $this->openingRecon($owner_type, $owner_id) === null;
        $computed  = $isOpening ? 0.0 : $this->ComputeBalanceAsOf($owner_type, $owner_id, $asOf);
        $variance  = round($actual - $computed, 2);
        $explanation = trim((string)($data['explanation'] ?? ''));
        if (!$isOpening && abs($variance) >= 0.01 && $explanation === '') {
            return InvalidParameter('Explanation required when the balance does not match.');
        }

        $this->recon->clear();
        $this->recon->owner_type       = $owner_type;
        $this->recon->owner_id         = $owner_id;
        $this->recon->as_of_date       = $asOf;
        $this->recon->actual_balance   = $actual;
        $this->recon->computed_balance = $isOpening ? $actual : $computed;
        $this->recon->variance         = $isOpening ? 0.0 : $variance;
        $this->recon->explanation      = $explanation !== '' ? $explanation : null;
        $this->recon->is_opening       = $isOpening ? 1 : 0;
        $this->recon->created_by       = $mundane_id;
        $this->recon->created_at       = date('Y-m-d H:i:s');
        $this->recon->save();
        return Success(['Id' => (int)$this->recon->id, 'IsOpening' => $isOpening,
            'Variance' => $isOpening ? 0.0 : $variance, 'Computed' => $isOpening ? $actual : $computed]);
    }
```

> Note: for `is_opening` seeding, `computed_balance` stores the seeded actual so the opening row reads cleanly in history. The opening `as_of_date` becomes the anchor used by `ComputeBalanceAsOf`/`GetLedger`/`GetBalanceSeries`.

- [ ] **Step 2: Lint** — `php -l system/lib/ork3/class.Treasury.php` → no errors.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Treasury.php
git commit -m "Treasury lib: ledger read, reconciliation, summary, balance series"
```

---

## Task 5: Model layer

**Files:**
- Create: `orkui/model/model.Treasury.php`

- [ ] **Step 1: Write the thin model**

```php
<?php

class Model_Treasury extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Treasury = new APIModel('Treasury');
    }

    public function has_opening($token, $ot, $oid)            { return $this->Treasury->HasOpeningBalance($token, $ot, $oid); }
    public function get_ledger($token, $ot, $oid, $filters)   { return $this->Treasury->GetLedger($token, $ot, $oid, $filters); }
    public function get_entry($token, $ot, $oid, $id)         { return $this->Treasury->GetEntry($token, $ot, $oid, $id); }
    public function save_entry($token, $data)                 { return $this->Treasury->SaveEntry($token, $data); }
    public function delete_entry($token, $ot, $oid, $id)      { return $this->Treasury->DeleteEntry($token, $ot, $oid, $id); }
    public function get_summary($token, $ot, $oid, $f, $t)    { return $this->Treasury->GetSummary($token, $ot, $oid, $f, $t); }
    public function get_series($token, $ot, $oid)             { return $this->Treasury->GetBalanceSeries($token, $ot, $oid); }
    public function get_reconciliations($token, $ot, $oid)    { return $this->Treasury->GetReconciliations($token, $ot, $oid); }
    public function save_reconciliation($token, $data)        { return $this->Treasury->SaveReconciliation($token, $data); }
}
```

- [ ] **Step 2: Lint** — `php -l orkui/model/model.Treasury.php` → no errors.

- [ ] **Step 3: Commit**

```bash
git add orkui/model/model.Treasury.php
git commit -m "Treasury: thin model pass-through"
```

---

## Task 6: AJAX controller

**Files:**
- Create: `orkui/controller/controller.TreasuryAjax.php`

Mirror `controller.ParkAjax.php`: a single dispatch method parsing `Route=TreasuryAjax/handle/<owner_type>/<owner_id>/<action>`. Session-presence guard; pass `$this->session->token` to the model; the lib enforces authority. Map lib `Status` to JSON.

- [ ] **Step 1: Write the controller**

```php
<?php

class Controller_TreasuryAjax extends Controller
{
    public function handle($p = null)
    {
        header('Content-Type: application/json');
        $parts      = explode('/', $p ?? '');
        $owner_type = ($parts[0] ?? '') === 'park' ? 'park' : 'kingdom';
        $owner_id   = (int)preg_replace('/[^0-9]/', '', $parts[1] ?? '');
        $action     = $parts[2] ?? '';

        if (!isset($this->session->user_id)) { echo json_encode(['status' => 5, 'error' => 'Not logged in']); exit; }
        if (!valid_id($owner_id))            { echo json_encode(['status' => 4, 'error' => 'Invalid org']); exit; }

        $this->load_model('Treasury');
        $tok = $this->session->token;

        switch ($action) {
            case 'ledger':
                $filters = [
                    'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null,
                    'category' => $_GET['category'] ?? null, 'direction' => $_GET['direction'] ?? null,
                    'page' => $_GET['page'] ?? 1, 'per' => $_GET['per'] ?? 25,
                ];
                return $this->out($this->Treasury->get_ledger($tok, $owner_type, $owner_id, $filters));
            case 'summary':
                return $this->out($this->Treasury->get_summary($tok, $owner_type, $owner_id, $_GET['from'] ?? null, $_GET['to'] ?? null));
            case 'series':
                return $this->out($this->Treasury->get_series($tok, $owner_type, $owner_id));
            case 'reconciliations':
                return $this->out($this->Treasury->get_reconciliations($tok, $owner_type, $owner_id));
            case 'getentry':
                return $this->out($this->Treasury->get_entry($tok, $owner_type, $owner_id, (int)($_GET['id'] ?? 0)));
            case 'addentry':
            case 'editentry':
                $data = $this->entryData($owner_type, $owner_id);
                if ($action === 'editentry') { $data['id'] = (int)($_POST['id'] ?? 0); }
                return $this->out($this->Treasury->save_entry($tok, $data));
            case 'deleteentry':
                return $this->out($this->Treasury->delete_entry($tok, $owner_type, $owner_id, (int)($_POST['id'] ?? 0)));
            case 'addreconciliation':
                return $this->out($this->Treasury->save_reconciliation($tok, [
                    'owner_type' => $owner_type, 'owner_id' => $owner_id,
                    'as_of_date' => $_POST['as_of_date'] ?? '', 'actual_balance' => $_POST['actual_balance'] ?? 0,
                    'explanation' => $_POST['explanation'] ?? '',
                ]));
            case 'export':
                return $this->exportCsv($tok, $owner_type, $owner_id);
            default:
                echo json_encode(['status' => 4, 'error' => 'Unknown action']); exit;
        }
    }

    private function entryData($owner_type, $owner_id)
    {
        return [
            'owner_type' => $owner_type, 'owner_id' => $owner_id,
            'entry_date' => $_POST['entry_date'] ?? '', 'direction' => $_POST['direction'] ?? 'credit',
            'amount' => $_POST['amount'] ?? 0, 'category' => $_POST['category'] ?? '',
            'payment_method' => $_POST['payment_method'] ?? '', 'description' => $_POST['description'] ?? '',
            'counterparty' => $_POST['counterparty'] ?? '', 'reference_no' => $_POST['reference_no'] ?? '',
        ];
    }

    /** Map a lib Service response to UI JSON {status,error,detail}. */
    private function out($res)
    {
        $status = isset($res['Status']) ? (int)$res['Status'] : 4;
        echo json_encode([
            'status' => $status,
            'error'  => $status === 0 ? null : ($res['Error'] ?? 'Error') . (isset($res['Detail']) && is_string($res['Detail']) ? ': ' . $res['Detail'] : ''),
            'detail' => $res['Detail'] ?? null,
        ]);
        exit;
    }

    private function exportCsv($tok, $owner_type, $owner_id)
    {
        $res = $this->Treasury->get_ledger($tok, $owner_type, $owner_id, ['per' => 100000, 'page' => 1,
            'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null,
            'category' => $_GET['category'] ?? null, 'direction' => $_GET['direction'] ?? null]);
        if (($res['Status'] ?? 4) !== 0) { echo json_encode(['status' => $res['Status'] ?? 4, 'error' => 'Denied']); exit; }
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="treasury_' . $owner_type . '_' . $owner_id . '.csv"');
        $rows = array_reverse($res['Detail']['Rows']); // chronological for export
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Direction', 'Amount', 'Category', 'Payment Method', 'Description', 'Counterparty', 'Reference', 'Running Balance']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['Date'], $r['Direction'], number_format($r['Amount'], 2, '.', ''),
                $r['Category'], $r['PaymentMethod'], $r['Description'], $r['Counterparty'], $r['ReferenceNo'],
                number_format($r['RunningBalance'], 2, '.', '')]);
        }
        fclose($out); exit;
    }
}
```

- [ ] **Step 2: Lint** — `php -l orkui/controller/controller.TreasuryAjax.php` → no errors.

- [ ] **Step 3: Integration test (curl-auth)** — using the cookie-jar login pattern (project memory `reference_local_curl_auth_session.md`), in ONE block: login via `Login/login`, then hit
`index.php?Route=TreasuryAjax/handle/park/1/ledger`.
Expected: JSON with `status:0` and a `Rows` array reflecting the synthetic rows; `CurrentBalance` 130.00. Check `docker logs ork3-php8-app` if 500.

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.TreasuryAjax.php
git commit -m "Treasury: AJAX CRUD/reconcile/export endpoints"
```

---

## Task 7: Page controller

**Files:**
- Create: `orkui/controller/controller.Treasury.php`

Two actions `kingdom($id)` / `park($id)`, each gating with `HasAuthority` and redirecting unauthorized users to Login. Both delegate to a private `render($owner_type, $owner_id)` that loads summary, first ledger page, reconciliations, `HasOpeningBalance`, the category map, and the org name/heraldry, then sets `$this->template`.

- [ ] **Step 1: Write the controller**

```php
<?php

class Controller_Treasury extends Controller
{
    public function kingdom($id = null) { $this->render('kingdom', (int)preg_replace('/[^0-9]/', '', $id)); }
    public function park($id = null)    { $this->render('park', (int)preg_replace('/[^0-9]/', '', $id)); }

    private function render($owner_type, $owner_id)
    {
        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        $authType = $owner_type === 'park' ? AUTH_PARK : AUTH_KINGDOM;
        if (!valid_id($owner_id)) { header('Location: ' . UIR); exit; }
        if (!$uid || !Ork3::$Lib->authorization->HasAuthority($uid, $authType, $owner_id, AUTH_EDIT)) {
            header('Location: ' . UIR . 'Login/login/Treasury/' . $owner_type . '/' . $owner_id); exit;
        }

        $this->template = '../revised-frontend/Treasury_index.tpl';
        $this->load_model('Treasury');
        $tok = $this->session->token;

        $this->data['owner_type'] = $owner_type;
        $this->data['owner_id']   = $owner_id;
        $this->data['categories'] = Treasury::$CATEGORIES;
        $this->data['org_name']   = $owner_type === 'park' ? $this->session->park_name : $this->session->kingdom_name;

        $hasOpen = $this->Treasury->has_opening($tok, $owner_type, $owner_id);
        $this->data['has_opening'] = ($hasOpen['Status'] ?? 4) === 0 ? (bool)$hasOpen['Detail']['HasOpening'] : false;

        $sum = $this->Treasury->get_summary($tok, $owner_type, $owner_id, null, null);
        $this->data['summary'] = ($sum['Status'] ?? 4) === 0 ? $sum['Detail'] : ['CurrentBalance' => 0, 'TotalIn' => 0, 'TotalOut' => 0, 'ByCategory' => []];

        $led = $this->Treasury->get_ledger($tok, $owner_type, $owner_id, ['page' => 1, 'per' => 25]);
        $this->data['ledger'] = ($led['Status'] ?? 4) === 0 ? $led['Detail'] : ['Rows' => [], 'Total' => 0];

        $rec = $this->Treasury->get_reconciliations($tok, $owner_type, $owner_id);
        $this->data['reconciliations'] = ($rec['Status'] ?? 4) === 0 ? $rec['Detail']['Rows'] : [];

        $ser = $this->Treasury->get_series($tok, $owner_type, $owner_id);
        $this->data['series'] = ($ser['Status'] ?? 4) === 0 ? $ser['Detail']['Points'] : [];
    }
}
```

- [ ] **Step 2: Lint** — `php -l orkui/controller/controller.Treasury.php` → no errors.

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.Treasury.php
git commit -m "Treasury: page controller (kingdom/park gates + data load)"
```

---

## Task 8: Template — page scaffold, summary, ledger table, first-run

**Files:**
- Create: `orkui/template/revised-frontend/Treasury_index.tpl`

Plain-PHP template. `tr-` prefixed CSS, dark-mode-ready (use the `data-theme` pattern), heading gray-box reset on custom headers. Variables available: `$owner_type, $owner_id, $categories, $org_name, $has_opening, $summary, $ledger, $reconciliations, $series`.

- [ ] **Step 1: Write the scaffold (header, summary cards, toolbar, ledger table, first-run banner, JS config)**

Key structural requirements (mirror `Admin_index.tpl` head pattern; load Highcharts CDN):

```php
<?php
$uir = UIR;
$catFlat = [];
foreach ($categories as $grp => $items) { foreach ($items as $k => $lbl) { $catFlat[$k] = $lbl; } }
$ajaxBase = $uir . 'TreasuryAjax/handle/' . $owner_type . '/' . $owner_id . '/';
$fmt = fn($n) => '$' . number_format((float)$n, 2);
?>
<script src="https://code.highcharts.com/highcharts.js"></script>
<style>
/* tr- prefixed; dark-mode via [data-theme=dark] selectors */
.tr-wrap { max-width: 1100px; margin: 0 auto; padding: 16px; }
.tr-hero h1 { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; }
.tr-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.tr-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px; }
[data-theme="dark"] .tr-card { background:#1e293b; border-color:#334155; color:#e2e8f0; }
/* ...ledger table, toolbar, modal, segmented control, reconcile panel... */
</style>

<div class="tr-wrap" id="tr-app"
     data-ajax="<?= htmlspecialchars($ajaxBase) ?>"
     data-hasopening="<?= $has_opening ? '1' : '0' ?>">
  <div class="tr-hero"><h1>Treasury — <?= htmlspecialchars($org_name) ?></h1></div>

  <?php if (!$has_opening): ?>
  <div class="tr-firstrun" id="tr-firstrun">
    <p>Set your starting balance to begin. Enter the current real-world balance and the date it's accurate as of.</p>
    <button class="tr-btn" id="tr-set-opening">Set Opening Balance</button>
  </div>
  <?php endif; ?>

  <div class="tr-cards">
    <div class="tr-card"><div class="tr-card-lbl">Current Balance</div>
      <div class="tr-card-val" id="tr-bal"><?= $fmt($summary['CurrentBalance']) ?></div></div>
    <div class="tr-card"><div class="tr-card-lbl">Total In</div>
      <div class="tr-card-val" id="tr-in"><?= $fmt($summary['TotalIn']) ?></div></div>
    <div class="tr-card"><div class="tr-card-lbl">Total Out</div>
      <div class="tr-card-val" id="tr-out"><?= $fmt($summary['TotalOut']) ?></div></div>
    <div class="tr-card"><div class="tr-card-lbl">Entries</div>
      <div class="tr-card-val"><?= (int)$ledger['Total'] ?></div></div>
  </div>

  <div class="tr-charts">
    <div id="tr-chart-balance" style="height:240px"></div>
    <div id="tr-chart-cats" style="height:240px"></div>
  </div>

  <div class="tr-toolbar">
    <input type="text" id="tr-f-from" placeholder="From"><input type="text" id="tr-f-to" placeholder="To">
    <select id="tr-f-cat"><option value="">All categories</option>
      <?php foreach ($catFlat as $k => $lbl): ?><option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?>
    </select>
    <select id="tr-f-dir"><option value="">In &amp; Out</option><option value="credit">In</option><option value="debit">Out</option></select>
    <button class="tr-btn" id="tr-add">+ Add Entry</button>
    <button class="tr-btn" id="tr-reconcile">Reconcile</button>
    <a class="tr-btn" id="tr-export" href="<?= htmlspecialchars($ajaxBase) ?>export">Export CSV</a>
  </div>

  <table class="tr-ledger" id="tr-ledger">
    <thead><tr><th>Date</th><th>Category</th><th>Method</th><th>Description</th><th>Counterparty</th>
      <th class="tr-num">In</th><th class="tr-num">Out</th><th class="tr-num">Balance</th><th></th></tr></thead>
    <tbody id="tr-ledger-body"><!-- rendered by JS --></tbody>
  </table>
  <div class="tr-pager" id="tr-pager"></div>
</div>

<script>
window.TrConfig = {
  ajax: '<?= $ajaxBase ?>',
  categories: <?= json_encode($catFlat) ?>,
  categoryGroups: <?= json_encode($categories) ?>,
  hasOpening: <?= $has_opening ? 'true' : 'false' ?>,
  series: <?= json_encode($series) ?>,
  byCategory: <?= json_encode($summary['ByCategory']) ?>,
  initialLedger: <?= json_encode($ledger) ?>
};
</script>
```

- [ ] **Step 2: Verify it renders** — log in via browser (project login-bypass), open `index.php?Route=Treasury/park/1`. Expected: hero, four cards (Current Balance 130.00 from synthetic data), empty/JS-pending ledger body, toolbar buttons. Check console for errors.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Treasury_index.tpl
git commit -m "Treasury template: scaffold, summary cards, ledger shell, first-run"
```

---

## Task 9: Template — ledger render JS, add/edit modal, delete

**Files:**
- Modify: `orkui/template/revised-frontend/Treasury_index.tpl`

- [ ] **Step 1: Add the JS app** (append a `<script>` block): fetch ledger, render rows, open add/edit modal, submit via `fetch` POST (form-encoded), `tnConfirm()` for delete. Payment method as a segmented control; flatpickr on date with `altInput:true, altFormat:'F j, Y'`. Use the `TrConfig` object. Render rows:

```javascript
(function () {
  var app = document.getElementById('tr-app'); if (!app) return;
  var cfg = window.TrConfig, body = document.getElementById('tr-ledger-body');
  var state = { page: 1, per: 25, from: '', to: '', category: '', direction: '' };
  var money = function (n) { return '$' + (Number(n)).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

  function renderRows(d) {
    body.innerHTML = '';
    (d.Rows || []).forEach(function (r) {
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td>' + r.Date + '</td><td>' + (cfg.categories[r.Category] || r.Category) + '</td>' +
        '<td>' + r.PaymentMethod + '</td><td>' + escapeHtml(r.Description || '') + '</td>' +
        '<td>' + escapeHtml(r.Counterparty || '') + '</td>' +
        '<td class="tr-num">' + (r.Direction === 'credit' ? money(r.Amount) : '') + '</td>' +
        '<td class="tr-num">' + (r.Direction === 'debit' ? money(r.Amount) : '') + '</td>' +
        '<td class="tr-num">' + money(r.RunningBalance) + '</td>' +
        '<td><button class="tr-link" data-edit="' + r.Id + '">Edit</button> ' +
        '<button class="tr-link" data-del="' + r.Id + '">Delete</button></td>';
      body.appendChild(tr);
    });
    document.getElementById('tr-bal').textContent = money(d.CurrentBalance);
    renderPager(d);
  }
  function escapeHtml(s){return s.replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}

  function loadLedger() {
    var q = new URLSearchParams({ page: state.page, per: state.per, from: state.from, to: state.to, category: state.category, direction: state.direction });
    fetch(cfg.ajax + 'ledger?' + q.toString(), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j.status === 0) renderRows(j.detail); });
  }
  // ...modal open/build (add + edit via getentry), submit POST, delete via tnConfirm...
  // ...filter input listeners (flatpickr on from/to), pager...
  if (cfg.initialLedger && cfg.initialLedger.Rows) renderRows(cfg.initialLedger); else loadLedger();
})();
```

Provide full modal HTML built in JS with: date (flatpickr), direction toggle, amount, category `<select>` grouped by income/expense, **mandatory** payment-method segmented control (cash/check/digital), description, counterparty, reference. On submit POST to `addentry`/`editentry`; on success reload ledger + summary + charts.

- [ ] **Step 2: Verify CRUD in browser** — add an entry (credit, dues, cash), confirm it appears with correct running balance; edit it; delete via `tnConfirm`. Confirm DB rows + audit rows via `docker exec ... mariadb`.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Treasury_index.tpl
git commit -m "Treasury template: ledger render, add/edit modal, delete"
```

---

## Task 10: Template — reconciliation panel + opening flow

**Files:**
- Modify: `orkui/template/revised-frontend/Treasury_index.tpl`

- [ ] **Step 1: Add reconcile modal + history list + opening flow.** "Reconcile" opens a modal: as-of date (flatpickr), actual balance; on input, live-compare against current computed balance and show match ✓ / mismatch ✗ + variance; explanation field shown/required when mismatch. POST to `addreconciliation`. The first-run "Set Opening Balance" button opens the same modal in "opening" mode (no explanation needed, label "Opening Balance"). Render `$reconciliations` history below the ledger (date, actual, computed, variance, explanation, opening badge). On success, if it was the opening, hide the first-run banner and refresh.

- [ ] **Step 2: Verify** — on a fresh org (e.g. `park/2`, no rows), set opening balance $200 as of a date; confirm banner disappears, current balance shows $200, history shows the opening row. Add an entry, then reconcile with a deliberately wrong actual; confirm mismatch + variance + required explanation; confirm history records it and the running balance is unchanged.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Treasury_index.tpl
git commit -m "Treasury template: reconciliation panel + opening-balance flow"
```

---

## Task 11: Template — charts

**Files:**
- Modify: `orkui/template/revised-frontend/Treasury_index.tpl`

- [ ] **Step 1: Add Highcharts init** (mirror the Attendance template pattern, dark-mode tooltip): a line chart in `#tr-chart-balance` from `cfg.series` (x = Month, y = Balance); a column/pie in `#tr-chart-cats` from `cfg.byCategory` (label via `cfg.categories`). Re-render both after any CRUD that changes data (refetch `series` + `summary`). Use `backgroundColor:'transparent'`, `credits:{enabled:false}`, the `_isDark` tooltip pattern.

- [ ] **Step 2: Verify** — charts render with synthetic data; toggle dark mode and confirm legibility.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Treasury_index.tpl
git commit -m "Treasury template: balance-over-time + category charts"
```

---

## Task 12: Admin menu integration

**Files:**
- Modify: `orkui/controller/controller.Kingdom.php` (the `if (...HasAuthority...AUTH_KINGDOM...)` block ~line 28-33)
- Modify: `orkui/controller/controller.Park.php` (the equivalent block ~line 36-42)
- Modify: `orkui/template/default/Admin_kingdom.tpl`
- Modify: `orkui/template/default/Admin_park.tpl`

> Normalize-first before editing each PHP file: `awk '/^\t/{c++} END{print c+0}' <file>` → if non-zero, run the php-cs-fixer on that one file first.

- [ ] **Step 1: Add a Treasury entry to `menulist['admin']`** in both controllers, inside the existing authority block. Kingdom:

```php
$this->data['menulist']['admin'] = array(
    array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Kingdom' ),
    array( 'url' => UIR.'Treasury/kingdom/'.$this->session->kingdom_id, 'display' => 'Treasury' ),
);
```

Park (add after the existing Park/Kingdom items):

```php
[ 'url' => UIR . 'Treasury/park/' . $this->session->park_id, 'display' => 'Treasury' ],
```

- [ ] **Step 2: Add a sidebar link** in `Admin_kingdom.tpl` and `Admin_park.tpl` mirroring the existing `<li><a ...>` items:

```php
<li><a href='<?=UIR ?>Treasury/kingdom/<?=$this->__session->kingdom_id ?>'>Treasury <i class="fas fa-coins"></i></a></li>
```
(Park variant: `Treasury/park/<?=$this->__session->park_id ?>`.)

- [ ] **Step 3: Lint** the two controllers — `php -l` each → no errors.

- [ ] **Step 4: Verify** — as a kingdom officer, the Admin page shows a Treasury link that lands on the tool; as a non-officer, no link and a direct URL redirects to Login.

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.Kingdom.php orkui/controller/controller.Park.php orkui/template/default/Admin_kingdom.tpl orkui/template/default/Admin_park.tpl
git commit -m "Treasury: Admin menu + sidebar links (kingdom/park)"
```

---

## Task 13: End-to-end verification + conventions walk

**Files:** none (verification only)

- [ ] **Step 1: Auth boundary** — confirm every AJAX action denies an unauthorized session (curl with a non-officer cookie jar): each returns `status:5`. Confirm a forged `owner_id` the officer lacks authority over is denied.
- [ ] **Step 2: Full lifecycle** on a clean org — set opening, add several credits/debits across months, edit one, soft-delete one (verify it vanishes from ledger but the row + audit persist in DB), reconcile (match + mismatch), export CSV (open the file, confirm rows + running balance match the UI).
- [ ] **Step 3: Money correctness** — verify running balance and summary totals against a hand calculation; confirm soft-deleted rows are excluded; confirm `decimal(12,2)` precision (no float drift on e.g. 0.10 × repeated).
- [ ] **Step 4: Dark-mode + conventions walk** — toggle dark mode; verify cards, table, modals, segmented control, charts, reconcile panel all legible. Confirm: no native `confirm/alert` (only `tnConfirm`), no native `title` tooltips, flatpickr human-readable dates, custom headings have the h1–h6 gray-box reset.
- [ ] **Step 5: Final lint sweep** — `php -l` on all five new/modified PHP files.

---

## Self-Review Notes (author)

- **Spec coverage:** ownership (Task 1 schema + auth helper), single balance (computed, Task 2/4), fixed categories (Task 2 map), reconciliation snapshots + opening (Task 4/10), officers-only auth (Task 2 `authFor`, Task 7/12 gating), soft-delete + audit (Task 3), no attachments / seam (schema has stable `entry_id`), summary+charts+CSV (Task 4/6/11), Admin-linked standalone tool (Task 7/12). All spec sections map to a task.
- **Running-balance-with-filters caveat (Task 4):** the simple implementation computes running balance across the full ordered set then filters client-visible rows by array slice — but the inline note about seeding `bal` for filtered date ranges must be honored: when a `from` filter is present, the first shown row's running balance must still include all prior entries. Implementer: compute the full series first (unfiltered by date for balance purposes), then apply category/direction/date filters to which rows are *displayed*, preserving each row's true running balance. This is called out so it isn't lost.
- **APIModel resolution:** `APIModel('Treasury')` must resolve to `class.Treasury.php` via the same autoload that finds `class.Park.php` (filename convention). Task 6 Step 3 curl test is the first proof it wires up; if it 500s on "class not found," check the lib autoload registration path.
