# Inventory Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an officer-only, per-org (Kingdom/Park) durable-goods register with quantity-stack items, condition/value/location/custody, a "Remove from Inventory" disposal pathway, soft-delete + audit trail, summary cards + two charts + CSV export — reached as a standalone tool from the Admin Tasks tab next to Treasury.

**Architecture:** Standard ORK3 three layers, mirroring the just-shipped Treasury module. DB logic in `system/lib/ork3/class.Inventory.php` (YAPO + raw `$DB` reads, auth via `IsAuthorized`/`HasAuthority`, returns `Success()`/`NoAuthorization()`). Thin `orkui/model/model.Inventory.php` (`APIModel` pass-through). A page controller `controller.Inventory.php` renders `Inventory_index.tpl`; an AJAX controller `controller.InventoryAjax.php` serves JSON CRUD. Total value is always computed (`Σ quantity × unit_value` over active items), never stored.

**Tech Stack:** PHP 8 / MariaDB, YAPO ORM, plain-PHP `.tpl` templates (`extract()`+include), Highcharts (CDN), no PHPUnit — verification via `php -l` lint + curl-auth integration against the Docker app (`ork3-php8-app`) + synthetic rows + real-browser walk.

**Spec:** `docs/superpowers/specs/2026-06-07-inventory-module-design.md`

**Reference implementation (mirror these exactly):**
- `system/lib/ork3/class.Treasury.php`
- `orkui/model/model.Treasury.php`
- `orkui/controller/controller.Treasury.php`
- `orkui/controller/controller.TreasuryAjax.php`
- `orkui/template/revised-frontend/Treasury_index.tpl`
- `db-migrations/2026-06-06-treasury.sql`

**Conventions (project memory — honor all):**
- `.tpl` = plain PHP (`<?php ?>`/`<?= ?>`), never Smarty.
- Always `$DB->Clear()` before raw Execute/DataSet.
- AJAX URLs: UIR ends in `?Route=` so `cfg.ajax` already has a `?`; append ALL query params with `&`, never `?`. **Test filter/pagination/edit/remove/export in a REAL browser** (curl hides this bug).
- yapo drops `null` on UPDATE/INSERT → clear a column with `''`/`0`, never `null`; clearable columns are `NOT NULL DEFAULT`.
- No native `confirm()/alert()` → `tnConfirm()`. No native `title` tooltips → `data-tip`. No native datetime → flatpickr `altInput`/`altFormat`.
- Dark-mode compatible proactively. Reset global h1–h6 gray-box on any custom heading.
- Debug output → browser console / `die(json_encode(...))`, never `error_log`.
- Edit PHP normalize-first: `awk '/^\t/{c++} END{print c+0}' <file>` → 0 = clean (Edit tool ok); else run `php tools/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php <file>` first.
- Stage files explicitly — never `git add -A`/`.`; never stage `class.Authorization.php`. End commit messages with `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- `condition` is SQL-reserved — backtick-quote it in every query.

---

## File Map

| File | Responsibility | New/Modify |
|---|---|---|
| `db-migrations/2026-06-07-inventory.sql` | The two tables | Create |
| `system/lib/ork3/class.Inventory.php` | All DB + auth + value/summary/lifecycle logic | Create |
| `orkui/model/model.Inventory.php` | Thin APIModel pass-through | Create |
| `orkui/controller/controller.Inventory.php` | Page controller (gates, loads, renders) | Create |
| `orkui/controller/controller.InventoryAjax.php` | JSON CRUD/remove/restore/export endpoints | Create |
| `orkui/template/revised-frontend/Inventory_index.tpl` | The tool UI (inlined CSS/JS, `inv-` prefix) | Create |
| `orkui/template/revised-frontend/Parknew_index.tpl` | Inventory link in Admin Tasks tab | Modify (~line 1156) |
| `orkui/template/revised-frontend/Kingdomnew_index.tpl` | Inventory link in Admin Tasks tab | Modify (~line 836) |

**Category keys (authoritative; labels in PHP):**
`weapons`, `armor`, `shields`, `garb_regalia`, `banners`, `tentage`, `archery_siege`, `event_equipment`, `electronics`, `inventory_other`.

**Removal-reason keys (authoritative; labels in PHP):**
`sold`, `donated`, `unrepairable`, `lost`, `consumed`, `transferred`, `removal_other`.

---

## Task 1: Database Migration

**Files:**
- Create: `db-migrations/2026-06-07-inventory.sql`

- [ ] **Step 1: Write the migration SQL**

```sql
-- Inventory module: per-org durable-goods register (Kingdom/Park)
-- 2026-06-07

CREATE TABLE `ork_inventory_item` (
  `id`                int(11)       NOT NULL AUTO_INCREMENT,
  `owner_type`        enum('kingdom','park') NOT NULL,
  `owner_id`          int(11)       NOT NULL,
  `name`              varchar(255)  NOT NULL,
  `category`          varchar(64)   NOT NULL,
  `quantity`          int(11)       NOT NULL DEFAULT 1,
  `condition`         enum('new','good','fair','poor','needs_repair') NOT NULL DEFAULT 'good',
  `unit_value`        decimal(12,2) NOT NULL DEFAULT 0.00,
  `location`          varchar(255)  NOT NULL DEFAULT '',
  `held_by`           varchar(255)  NOT NULL DEFAULT '',
  `held_by_player_id` int(11)       NOT NULL DEFAULT 0,
  `acquired_date`     date          DEFAULT NULL,
  `notes`             varchar(500)  NOT NULL DEFAULT '',
  `removed_at`        datetime      DEFAULT NULL,
  `removal_reason`    varchar(32)   NOT NULL DEFAULT '',
  `removal_note`      varchar(500)  NOT NULL DEFAULT '',
  `deleted_at`        datetime      DEFAULT NULL,
  `created_by`        int(11)       NOT NULL,
  `created_at`        datetime      NOT NULL,
  `updated_at`        datetime      DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_owner_cat` (`owner_type`,`owner_id`,`category`),
  KEY `ix_deleted` (`deleted_at`),
  KEY `ix_removed` (`removed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ork_inventory_audit` (
  `id`          int(11)  NOT NULL AUTO_INCREMENT,
  `item_id`     int(11)  NOT NULL,
  `action`      enum('create','edit','remove','restore','delete') NOT NULL,
  `changed_by`  int(11)  NOT NULL,
  `changed_at`  datetime NOT NULL,
  `before_json` text     DEFAULT NULL,
  `after_json`  text     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply the migration**

Run: `docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-06-07-inventory.sql`
Expected: no error output.

- [ ] **Step 3: Verify tables exist**

Run: `docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SHOW TABLES LIKE 'ork_inventory%'; DESCRIBE ork_inventory_item;"`
Expected: two tables listed; `ork_inventory_item` shows all columns above.

- [ ] **Step 4: Commit**

```bash
git add db-migrations/2026-06-07-inventory.sql
git commit -m "Inventory: add item + audit tables

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Inventory lib — scaffold, category/reason maps, auth helper, value+summary computation

**Files:**
- Create: `system/lib/ork3/class.Inventory.php`

Mirror `class.Treasury.php` (constructor, YAPO, `Ork3::$Lib->authorization`, `Success()/NoAuthorization()/InvalidParameter()`). Lib methods take a `$token` first arg, resolve `$mundane_id`, then `HasAuthority(..., AUTH_EDIT)`.

- [ ] **Step 1: Write the scaffold + maps + auth helper + computations**

```php
<?php

class Inventory extends Ork3
{
    public static $CATEGORIES = [
        'weapons'         => 'Weapons',
        'armor'           => 'Armor',
        'shields'         => 'Shields',
        'garb_regalia'    => 'Garb / Regalia',
        'banners'         => 'Banners / Heraldry',
        'tentage'         => 'Pavilions / Tentage',
        'archery_siege'   => 'Archery / Siege',
        'event_equipment' => 'Event Equipment',
        'electronics'     => 'Electronics / AV',
        'inventory_other' => 'Other',
    ];

    public static $REMOVAL_REASONS = [
        'sold'          => 'Sold',
        'donated'       => 'Donated / Gifted',
        'unrepairable'  => 'Damaged Beyond Repair',
        'lost'          => 'Lost / Stolen',
        'consumed'      => 'Consumed / Used Up',
        'transferred'   => 'Transferred to Another Org',
        'removal_other' => 'Other',
    ];

    public static $CONDITIONS = ['new', 'good', 'fair', 'poor', 'needs_repair'];

    public function __construct()
    {
        parent::__construct();
        $this->item  = new yapo($this->db, DB_PREFIX . 'inventory_item');
        $this->audit = new yapo($this->db, DB_PREFIX . 'inventory_audit');
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

    private function validCategory($cat) { return isset(self::$CATEGORIES[$cat]); }
    private function validReason($r)     { return isset(self::$REMOVAL_REASONS[$r]); }
    private function validCondition($c)   { return in_array($c, self::$CONDITIONS, true); }

    /** Display name of the org (park/kingdom) by id; also returns KingdomId for player-search scope. */
    public function GetOwnerName($token, $owner_type, $owner_id)
    {
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
        return Success(['Name' => $name, 'KingdomId' => (int)$kingdom_id]);
    }

    /** Cheap change-signal for polling: COUNT/MAX over items (no full scan). */
    public function GetRevision($token, $owner_type, $owner_id)
    {
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        global $DB;
        $owner_type = $this->normType($owner_type);
        $owner_id = (int)$owner_id;
        $DB->Clear();
        $rs = $DB->DataSet("SELECT COUNT(*) n, COALESCE(MAX(id),0) mx,
            COALESCE(UNIX_TIMESTAMP(MAX(GREATEST(created_at, COALESCE(updated_at, created_at),
              COALESCE(removed_at, created_at), COALESCE(deleted_at, created_at)))),0) ts
            FROM " . DB_PREFIX . "inventory_item WHERE owner_type='$owner_type' AND owner_id=$owner_id");
        $n = 0; $mx = 0; $ts = 0;
        if ($rs && $rs->Next()) { $n = (int)$rs->n; $mx = (int)$rs->mx; $ts = (int)$rs->ts; }
        return Success(['Rev' => $n . '-' . $mx . '-' . $ts]);
    }

    /** Summary over ACTIVE items: total value, total units, line items, # needs repair,
     *  value-by-category, count-by-condition. $filters narrows the same way GetItems does
     *  (category, condition, q) but NOT status — summary is always over active items. */
    public function GetSummary($token, $owner_type, $owner_id, $filters = [])
    {
        global $DB;
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        $owner_type = $this->normType($owner_type); $owner_id = (int)$owner_id;
        $where = "owner_type='$owner_type' AND owner_id=$owner_id AND deleted_at IS NULL AND removed_at IS NULL";
        if (!empty($filters['category']))  { $where .= " AND category = '" . addslashes($filters['category']) . "'"; }
        if (!empty($filters['condition'])) { $where .= " AND `condition` = '" . addslashes($filters['condition']) . "'"; }
        if (!empty($filters['q']))         { $where .= " AND name LIKE '%" . addslashes($filters['q']) . "%'"; }

        $DB->Clear();
        $rs = $DB->DataSet("SELECT
            COALESCE(SUM(quantity * unit_value),0) AS total_value,
            COALESCE(SUM(quantity),0)              AS total_units,
            COUNT(*)                               AS line_items,
            COALESCE(SUM(CASE WHEN `condition`='needs_repair' THEN 1 ELSE 0 END),0) AS needs_repair
            FROM " . DB_PREFIX . "inventory_item WHERE $where");
        $tv = 0.0; $tu = 0; $li = 0; $nr = 0;
        if ($rs && $rs->Next()) {
            $tv = (float)$rs->total_value; $tu = (int)$rs->total_units;
            $li = (int)$rs->line_items;    $nr = (int)$rs->needs_repair;
        }

        $DB->Clear();
        $rs2 = $DB->DataSet("SELECT category, COALESCE(SUM(quantity * unit_value),0) AS v
            FROM " . DB_PREFIX . "inventory_item WHERE $where GROUP BY category");
        $byCat = [];
        while ($rs2 && $rs2->Next()) { $byCat[$rs2->category] = round((float)$rs2->v, 2); }

        $DB->Clear();
        $rs3 = $DB->DataSet("SELECT `condition` AS c, COUNT(*) AS n
            FROM " . DB_PREFIX . "inventory_item WHERE $where GROUP BY `condition`");
        $byCond = [];
        while ($rs3 && $rs3->Next()) { $byCond[$rs3->c] = (int)$rs3->n; }

        return Success([
            'TotalValue'  => round($tv, 2),
            'TotalUnits'  => $tu,
            'LineItems'   => $li,
            'NeedsRepair' => $nr,
            'ByCategory'  => $byCat,
            'ByCondition' => $byCond,
        ]);
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l system/lib/ork3/class.Inventory.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Inventory.php
git commit -m "Inventory lib: scaffold, category/reason maps, summary computation

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Inventory lib — item CRUD + audit

**Files:**
- Modify: `system/lib/ork3/class.Inventory.php`

- [ ] **Step 1: Add audit writer, row serializer, SaveItem, GetItem**

Insert these methods inside the class (before the closing brace):

```php
    private function writeAudit($item_id, $action, $mundane_id, $before, $after)
    {
        $this->audit->clear();
        $this->audit->item_id    = (int)$item_id;
        $this->audit->action     = $action;
        $this->audit->changed_by = (int)$mundane_id;
        $this->audit->changed_at = date('Y-m-d H:i:s');
        // yapo drops null fields from INSERT; '' clears the column instead of leaving it stale.
        $this->audit->before_json = $before === null ? '' : json_encode($before);
        $this->audit->after_json  = $after  === null ? '' : json_encode($after);
        $this->audit->save();
    }

    private function itemToArray()
    {
        return [
            'id'                => $this->item->id,
            'owner_type'        => $this->item->owner_type,
            'owner_id'          => $this->item->owner_id,
            'name'              => $this->item->name,
            'category'          => $this->item->category,
            'quantity'          => $this->item->quantity,
            'condition'         => $this->item->condition,
            'unit_value'        => $this->item->unit_value,
            'location'          => $this->item->location,
            'held_by'           => $this->item->held_by,
            'held_by_player_id' => $this->item->held_by_player_id,
            'acquired_date'     => $this->item->acquired_date,
            'notes'             => $this->item->notes,
            'removed_at'        => $this->item->removed_at,
            'removal_reason'    => $this->item->removal_reason,
            'removal_note'      => $this->item->removal_note,
            'deleted_at'        => $this->item->deleted_at,
        ];
    }

    /** Load an owned, non-deleted item into $this->item; false if not found / not owned. */
    private function loadOwnedItem($id, $owner_type, $owner_id)
    {
        $this->item->clear();
        $this->item->id = (int)$id;
        if (!$this->item->find() || $this->item->deleted_at !== null
            || (int)$this->item->owner_id !== (int)$owner_id
            || $this->item->owner_type !== $this->normType($owner_type)) {
            return false;
        }
        return true;
    }

    /** Create or edit. $data: owner_type, owner_id, [id], name, category, quantity, condition,
     *  unit_value, location, held_by, held_by_player_id, acquired_date, notes. */
    public function SaveItem($token, $data)
    {
        $mundane_id = $this->authFor($token, $data['owner_type'] ?? '', $data['owner_id'] ?? 0);
        if (!$mundane_id) { return NoAuthorization(); }

        $name      = trim((string)($data['name'] ?? ''));
        $cat       = (string)($data['category'] ?? '');
        $qty       = (int)($data['quantity'] ?? 0);
        $cond      = (string)($data['condition'] ?? 'good');
        $unitValue = round((float)($data['unit_value'] ?? 0), 2);
        $acquired  = (string)($data['acquired_date'] ?? '');

        if ($name === '')                  { return InvalidParameter('Name is required.'); }
        if (!$this->validCategory($cat))   { return InvalidParameter('Unknown category.'); }
        if ($qty < 1)                      { return InvalidParameter('Quantity must be at least 1.'); }
        if (!$this->validCondition($cond)) { return InvalidParameter('Unknown condition.'); }
        if ($acquired !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $acquired)) {
            return InvalidParameter('Invalid acquired date.');
        }

        $isEdit = !empty($data['id']);
        $before = null;
        if ($isEdit) {
            if (!$this->loadOwnedItem($data['id'], $data['owner_type'], $data['owner_id'])) {
                return InvalidParameter('Item not found.');
            }
            $before = $this->itemToArray();
        } else {
            $this->item->clear();
            $this->item->owner_type = $this->normType($data['owner_type']);
            $this->item->owner_id   = (int)$data['owner_id'];
            $this->item->created_by = $mundane_id;
            $this->item->created_at = date('Y-m-d H:i:s');
            // new items start active
            $this->item->removal_reason = '';
            $this->item->removal_note   = '';
        }
        $this->item->name       = $name;
        $this->item->category   = $cat;
        $this->item->quantity   = $qty;
        $this->item->condition  = $cond;
        $this->item->unit_value = $unitValue;
        // yapo drops null fields; assign '' / 0 to clear an optional column rather than leave it stale.
        $this->item->location   = (string)($data['location'] ?? '');
        $this->item->held_by    = (string)($data['held_by'] ?? '');
        $this->item->held_by_player_id = (isset($data['held_by_player_id']) && (int)$data['held_by_player_id'] > 0)
            ? (int)$data['held_by_player_id'] : 0;
        $this->item->notes      = (string)($data['notes'] ?? '');
        // acquired_date is genuinely nullable in the schema; '' must become NULL.
        $this->item->acquired_date = $acquired !== '' ? $acquired : null;
        if ($isEdit) { $this->item->updated_at = date('Y-m-d H:i:s'); }
        $this->item->save();

        $id = (int)$this->item->id;
        $this->writeAudit($id, $isEdit ? 'edit' : 'create', $mundane_id, $before, $this->itemToArray());
        return Success(['Id' => $id]);
    }

    public function GetItem($token, $owner_type, $owner_id, $id)
    {
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        if (!$this->loadOwnedItem($id, $owner_type, $owner_id)) {
            return InvalidParameter('Item not found.');
        }
        return Success($this->itemToArray());
    }
```

Note: `acquired_date` is the one optional column that is genuinely `NULL`-able in the schema (a date, no sensible empty sentinel), so it is assigned `null` to clear — this is the deliberate exception to the yapo-null rule. Verify in Task 13 that clearing an acquired date persists as `NULL`.

- [ ] **Step 2: Lint** — `php -l system/lib/ork3/class.Inventory.php` → `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Inventory.php
git commit -m "Inventory lib: item create/edit/get with audit log

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Inventory lib — remove/restore/delete + GetItems list

**Files:**
- Modify: `system/lib/ork3/class.Inventory.php`

- [ ] **Step 1: Add RemoveItem, RestoreItem, DeleteItem, GetItems**

```php
    /** Disposal: mark no longer owned, with a required reason + optional note. */
    public function RemoveItem($token, $owner_type, $owner_id, $id, $reason, $note = '')
    {
        $mundane_id = $this->authFor($token, $owner_type, $owner_id);
        if (!$mundane_id) { return NoAuthorization(); }
        if (!$this->validReason($reason)) { return InvalidParameter('A removal reason is required.'); }
        if (!$this->loadOwnedItem($id, $owner_type, $owner_id)) { return InvalidParameter('Item not found.'); }
        if ($this->item->removed_at !== null) { return InvalidParameter('Item is already removed.'); }
        $before = $this->itemToArray();
        $this->item->removed_at     = date('Y-m-d H:i:s');
        $this->item->removal_reason = $reason;
        $this->item->removal_note   = trim((string)$note) !== '' ? trim((string)$note) : '';
        $this->item->save();
        $this->writeAudit((int)$id, 'remove', $mundane_id, $before, $this->itemToArray());
        return Success();
    }

    /** Un-remove: return a disposed item to active inventory. */
    public function RestoreItem($token, $owner_type, $owner_id, $id)
    {
        $mundane_id = $this->authFor($token, $owner_type, $owner_id);
        if (!$mundane_id) { return NoAuthorization(); }
        if (!$this->loadOwnedItem($id, $owner_type, $owner_id)) { return InvalidParameter('Item not found.'); }
        if ($this->item->removed_at === null) { return InvalidParameter('Item is not removed.'); }
        $before = $this->itemToArray();
        $this->item->removed_at     = null;
        $this->item->removal_reason = '';
        $this->item->removal_note   = '';
        $this->item->save();
        $this->writeAudit((int)$id, 'restore', $mundane_id, $before, $this->itemToArray());
        return Success();
    }

    /** Soft-delete (mis-entry correction): remove from all views. */
    public function DeleteItem($token, $owner_type, $owner_id, $id)
    {
        $mundane_id = $this->authFor($token, $owner_type, $owner_id);
        if (!$mundane_id) { return NoAuthorization(); }
        if (!$this->loadOwnedItem($id, $owner_type, $owner_id)) { return InvalidParameter('Item not found.'); }
        $before = $this->itemToArray();
        $this->item->deleted_at = date('Y-m-d H:i:s');
        $this->item->save();
        $this->writeAudit((int)$id, 'delete', $mundane_id, $before, null);
        return Success();
    }

    /** Paged/filtered item list. $filters: category, condition, q, status(active|removed),
     *  sort, dir, page, per. Active by default. */
    public function GetItems($token, $owner_type, $owner_id, $filters = [])
    {
        global $DB;
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        $owner_type = $this->normType($owner_type); $owner_id = (int)$owner_id;

        $status = ($filters['status'] ?? 'active') === 'removed' ? 'removed' : 'active';
        $where  = "i.owner_type='$owner_type' AND i.owner_id=$owner_id AND i.deleted_at IS NULL";
        $where .= $status === 'removed' ? " AND i.removed_at IS NOT NULL" : " AND i.removed_at IS NULL";
        if (!empty($filters['category']))  { $where .= " AND i.category = '" . addslashes($filters['category']) . "'"; }
        if (!empty($filters['condition'])) { $where .= " AND i.`condition` = '" . addslashes($filters['condition']) . "'"; }
        if (!empty($filters['q']))         { $where .= " AND i.name LIKE '%" . addslashes($filters['q']) . "%'"; }

        // Whitelist sortable columns; default name ASC.
        $sortMap = [
            'name' => 'i.name', 'category' => 'i.category', 'quantity' => 'i.quantity',
            'condition' => "FIELD(i.`condition`,'new','good','fair','poor','needs_repair')",
            'unit_value' => 'i.unit_value', 'total_value' => '(i.quantity*i.unit_value)',
            'location' => 'i.location',
        ];
        $sortKey = $filters['sort'] ?? 'name';
        $sortCol = $sortMap[$sortKey] ?? 'i.name';
        $dir     = (strtolower($filters['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';

        $DB->Clear();
        $cnt = $DB->DataSet("SELECT COUNT(*) AS n FROM " . DB_PREFIX . "inventory_item i WHERE $where");
        $total = ($cnt && $cnt->Next()) ? (int)$cnt->n : 0;

        $per  = max(1, (int)($filters['per'] ?? 25));
        $page = max(1, (int)($filters['page'] ?? 1));
        $off  = ($page - 1) * $per;

        // LEFT JOIN mundane for the held-by display name (only when a player id is set).
        $DB->Clear();
        $rs = $DB->DataSet("SELECT i.id, i.name, i.category, i.quantity, i.`condition` AS cond,
            i.unit_value, i.location, i.held_by, i.held_by_player_id,
            i.acquired_date, i.notes, i.removed_at, i.removal_reason, i.removal_note,
            TRIM(CONCAT(COALESCE(m.given_name,''),' ',COALESCE(m.surname,''))) AS player_name
            FROM " . DB_PREFIX . "inventory_item i
            LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = i.held_by_player_id AND i.held_by_player_id > 0
            WHERE $where ORDER BY $sortCol $dir, i.id ASC LIMIT $off, $per");

        $rows = [];
        while ($rs && $rs->Next()) {
            $heldName = $rs->held_by_player_id > 0 && trim($rs->player_name) !== ''
                ? $rs->player_name : (string)$rs->held_by;
            $rows[] = [
                'Id'             => (int)$rs->id,
                'Name'           => $rs->name,
                'Category'       => $rs->category,
                'Quantity'       => (int)$rs->quantity,
                'Condition'      => $rs->cond,
                'UnitValue'      => (float)$rs->unit_value,
                'TotalValue'     => round((float)$rs->unit_value * (int)$rs->quantity, 2),
                'Location'       => $rs->location,
                'HeldBy'         => $heldName,
                'HeldByPlayerId' => (int)$rs->held_by_player_id,
                'AcquiredDate'   => $rs->acquired_date,
                'Notes'          => $rs->notes,
                'RemovedAt'      => $rs->removed_at,
                'RemovalReason'  => $rs->removal_reason,
                'RemovalNote'    => $rs->removal_note,
            ];
        }
        return Success(['Rows' => $rows, 'Total' => $total, 'Page' => $page, 'Per' => $per, 'Status' => $status]);
    }
```

> Note: confirm the `mundane` table's name columns are `given_name` / `surname` (they are in this codebase — see `controller.Playernew.php` raw SQL). If a JOIN against `mundane` is awkward, the held-by free-text (`held_by`) already carries a display string written at save time, so the JOIN is an enhancement, not a correctness dependency.

- [ ] **Step 2: Lint** — `php -l system/lib/ork3/class.Inventory.php` → no errors.

- [ ] **Step 3: Commit**

```bash
git add system/lib/ork3/class.Inventory.php
git commit -m "Inventory lib: remove/restore/delete + paged item list

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Model layer

**Files:**
- Create: `orkui/model/model.Inventory.php`

- [ ] **Step 1: Write the thin model**

```php
<?php

class Model_Inventory extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Inventory = new APIModel('Inventory');
    }

    public function get_owner_name($token, $ot, $oid)                 { return $this->Inventory->GetOwnerName($token, $ot, $oid); }
    public function get_revision($token, $ot, $oid)                   { return $this->Inventory->GetRevision($token, $ot, $oid); }
    public function get_summary($token, $ot, $oid, $f)               { return $this->Inventory->GetSummary($token, $ot, $oid, $f); }
    public function get_items($token, $ot, $oid, $f)                 { return $this->Inventory->GetItems($token, $ot, $oid, $f); }
    public function get_item($token, $ot, $oid, $id)                 { return $this->Inventory->GetItem($token, $ot, $oid, $id); }
    public function save_item($token, $data)                         { return $this->Inventory->SaveItem($token, $data); }
    public function remove_item($token, $ot, $oid, $id, $r, $n)      { return $this->Inventory->RemoveItem($token, $ot, $oid, $id, $r, $n); }
    public function restore_item($token, $ot, $oid, $id)            { return $this->Inventory->RestoreItem($token, $ot, $oid, $id); }
    public function delete_item($token, $ot, $oid, $id)            { return $this->Inventory->DeleteItem($token, $ot, $oid, $id); }
}
```

- [ ] **Step 2: Lint** — `php -l orkui/model/model.Inventory.php` → no errors.

- [ ] **Step 3: Commit**

```bash
git add orkui/model/model.Inventory.php
git commit -m "Inventory: thin model pass-through

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: AJAX controller

**Files:**
- Create: `orkui/controller/controller.InventoryAjax.php`

Mirror `controller.TreasuryAjax.php` exactly: single `handle($p)` dispatch parsing `Route=InventoryAjax/handle/<owner_type>/<owner_id>/<action>`. Session-presence guard; lib enforces authority; map lib `Status` to JSON via `out()`.

- [ ] **Step 1: Write the controller**

```php
<?php

class Controller_InventoryAjax extends Controller
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

        $this->load_model('Inventory');
        $tok = $this->session->token;

        switch ($action) {
            case 'items':
                return $this->out($this->Inventory->get_items($tok, $owner_type, $owner_id, $this->itemFilters()));
            case 'summary':
                return $this->out($this->Inventory->get_summary($tok, $owner_type, $owner_id, [
                    'category' => $_GET['category'] ?? null, 'condition' => $_GET['condition'] ?? null,
                    'q' => $_GET['q'] ?? null,
                ]));
            case 'rev':
                return $this->out($this->Inventory->get_revision($tok, $owner_type, $owner_id));
            case 'getitem':
                return $this->out($this->Inventory->get_item($tok, $owner_type, $owner_id, (int)($_GET['id'] ?? 0)));
            case 'additem':
            case 'edititem':
                $data = $this->itemData($owner_type, $owner_id);
                if ($action === 'edititem') { $data['id'] = (int)($_POST['id'] ?? 0); }
                return $this->out($this->Inventory->save_item($tok, $data));
            case 'removeitem':
                return $this->out($this->Inventory->remove_item($tok, $owner_type, $owner_id,
                    (int)($_POST['id'] ?? 0), $_POST['removal_reason'] ?? '', $_POST['removal_note'] ?? ''));
            case 'restoreitem':
                return $this->out($this->Inventory->restore_item($tok, $owner_type, $owner_id, (int)($_POST['id'] ?? 0)));
            case 'deleteitem':
                return $this->out($this->Inventory->delete_item($tok, $owner_type, $owner_id, (int)($_POST['id'] ?? 0)));
            case 'export':
                return $this->exportCsv($tok, $owner_type, $owner_id);
            default:
                echo json_encode(['status' => 4, 'error' => 'Unknown action']); exit;
        }
    }

    private function itemFilters()
    {
        return [
            'category' => $_GET['category'] ?? null, 'condition' => $_GET['condition'] ?? null,
            'q' => $_GET['q'] ?? null, 'status' => $_GET['status'] ?? 'active',
            'sort' => $_GET['sort'] ?? 'name', 'dir' => $_GET['dir'] ?? 'asc',
            'page' => $_GET['page'] ?? 1, 'per' => $_GET['per'] ?? 25,
        ];
    }

    private function itemData($owner_type, $owner_id)
    {
        return [
            'owner_type' => $owner_type, 'owner_id' => $owner_id,
            'name' => $_POST['name'] ?? '', 'category' => $_POST['category'] ?? '',
            'quantity' => $_POST['quantity'] ?? 1, 'condition' => $_POST['condition'] ?? 'good',
            'unit_value' => $_POST['unit_value'] ?? 0, 'location' => $_POST['location'] ?? '',
            'held_by' => $_POST['held_by'] ?? '', 'held_by_player_id' => $_POST['held_by_player_id'] ?? 0,
            'acquired_date' => $_POST['acquired_date'] ?? '', 'notes' => $_POST['notes'] ?? '',
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
        $filters = $this->itemFilters();
        $filters['per'] = 100000; $filters['page'] = 1;
        $res = $this->Inventory->get_items($tok, $owner_type, $owner_id, $filters);
        if (($res['Status'] ?? 4) !== 0) { echo json_encode(['status' => $res['Status'] ?? 4, 'error' => 'Denied']); exit; }
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="inventory_' . $owner_type . '_' . $owner_id . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name', 'Category', 'Quantity', 'Condition', 'Unit Value', 'Total Value',
            'Location', 'Held By', 'Acquired', 'Notes', 'Status', 'Removal Reason', 'Removal Note']);
        foreach ($res['Detail']['Rows'] as $r) {
            fputcsv($out, [$r['Name'], $r['Category'], $r['Quantity'], $r['Condition'],
                number_format($r['UnitValue'], 2, '.', ''), number_format($r['TotalValue'], 2, '.', ''),
                $r['Location'], $r['HeldBy'], $r['AcquiredDate'], $r['Notes'],
                $r['RemovedAt'] ? 'Removed' : 'Active', $r['RemovalReason'], $r['RemovalNote']]);
        }
        fclose($out); exit;
    }
}
```

- [ ] **Step 2: Lint** — `php -l orkui/controller/controller.InventoryAjax.php` → no errors.

- [ ] **Step 3: Integration test (curl-auth)** — using the cookie-jar login pattern (project memory `reference_local_curl_auth_session.md`), in ONE block: login via `Login/login`, seed two synthetic rows (below), then hit `index.php?Route=InventoryAjax/handle/park/1/items` and `.../summary`.

Seed:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e "
INSERT INTO ork_inventory_item (owner_type,owner_id,name,category,quantity,\`condition\`,unit_value,location,created_by,created_at)
 VALUES ('park',1,'Boffer longsword','weapons',20,'good',15.00,'Kingdom shed',1,NOW()),
        ('park',1,'Royal pavilion','tentage',1,'needs_repair',400.00,'Storage unit',1,NOW());"
```
Expected: `items` returns `status:0` with 2 rows; `summary` returns `TotalValue` 700.00 (20×15 + 1×400), `TotalUnits` 21, `LineItems` 2, `NeedsRepair` 1. Check `docker logs ork3-php8-app` if 500.

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.InventoryAjax.php
git commit -m "Inventory: AJAX CRUD/remove/restore/export endpoints

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Page controller

**Files:**
- Create: `orkui/controller/controller.Inventory.php`

Mirror `controller.Treasury.php`: `kingdom($id)`/`park($id)` gate with `HasAuthority`, redirect unauthorized to Login; private `render()` loads the data and sets the template.

- [ ] **Step 1: Write the controller**

```php
<?php

class Controller_Inventory extends Controller
{
    public function kingdom($id = null) { $this->render('kingdom', (int)preg_replace('/[^0-9]/', '', $id)); }
    public function park($id = null)    { $this->render('park', (int)preg_replace('/[^0-9]/', '', $id)); }

    private function render($owner_type, $owner_id)
    {
        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        $authType = $owner_type === 'park' ? AUTH_PARK : AUTH_KINGDOM;
        if (!valid_id($owner_id)) { header('Location: ' . UIR); exit; }
        if (!$uid || !Ork3::$Lib->authorization->HasAuthority($uid, $authType, $owner_id, AUTH_EDIT)) {
            header('Location: ' . UIR . 'Login/login/Inventory/' . $owner_type . '/' . $owner_id); exit;
        }

        $this->template = '../revised-frontend/Inventory_index.tpl';
        $this->load_model('Inventory');
        $tok = $this->session->token;

        $this->data['owner_type']      = $owner_type;
        $this->data['owner_id']        = $owner_id;
        $this->data['categories']      = Inventory::$CATEGORIES;
        $this->data['removal_reasons'] = Inventory::$REMOVAL_REASONS;
        $this->data['conditions']      = Inventory::$CONDITIONS;

        $nameRes = $this->Inventory->get_owner_name($tok, $owner_type, $owner_id);
        $this->data['org_name']   = ($nameRes['Status'] ?? 4) === 0 ? $nameRes['Detail']['Name'] : '';
        $this->data['kingdom_id'] = ($nameRes['Status'] ?? 4) === 0 ? (int)$nameRes['Detail']['KingdomId'] : 0;

        $sum = $this->Inventory->get_summary($tok, $owner_type, $owner_id, []);
        $this->data['summary'] = ($sum['Status'] ?? 4) === 0 ? $sum['Detail']
            : ['TotalValue' => 0, 'TotalUnits' => 0, 'LineItems' => 0, 'NeedsRepair' => 0, 'ByCategory' => [], 'ByCondition' => []];

        $items = $this->Inventory->get_items($tok, $owner_type, $owner_id, ['page' => 1, 'per' => 25, 'status' => 'active']);
        $this->data['items'] = ($items['Status'] ?? 4) === 0 ? $items['Detail'] : ['Rows' => [], 'Total' => 0];
    }
}
```

- [ ] **Step 2: Lint** — `php -l orkui/controller/controller.Inventory.php` → no errors.

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.Inventory.php
git commit -m "Inventory: page controller (kingdom/park gates + data load)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Template — scaffold, summary cards, table shell, empty state, JS config

**Files:**
- Create: `orkui/template/revised-frontend/Inventory_index.tpl`

**Read `orkui/template/revised-frontend/Treasury_index.tpl` first and mirror its structure** (head, inlined `<style>`, summary cards, toolbar, table, `TrConfig`-style JS config object, dark-mode `[data-theme="dark"]` selectors, heading gray-box reset, Highcharts CDN, flatpickr usage, `tnConfirm`, the counterparty playersearch). Inventory uses the `inv-` prefix and an `InvConfig` object. Plain-PHP template; variables available: `$owner_type, $owner_id, $categories, $removal_reasons, $conditions, $org_name, $kingdom_id, $summary, $items`.

- [ ] **Step 1: Write the scaffold** (header, four summary cards, charts containers, toolbar with filters, table shell, empty-state, `InvConfig`)

```php
<?php
$uir = UIR;
$ajaxBase = $uir . 'InventoryAjax/handle/' . $owner_type . '/' . $owner_id . '/';
$fmt = fn($n) => '$' . number_format((float)$n, 2);
$condLabels = ['new' => 'New', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor', 'needs_repair' => 'Needs Repair'];
?>
<script src="https://code.highcharts.com/highcharts.js"></script>
<style>
/* inv- prefixed; dark-mode via [data-theme=dark] selectors */
.inv-wrap { max-width: 1100px; margin: 0 auto; padding: 16px; }
.inv-hero h1 { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; }
.inv-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 12px 0; }
.inv-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px; }
.inv-card-lbl { font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
.inv-card-val { font-size:22px; font-weight:700; margin-top:4px; }
[data-theme="dark"] .inv-card { background:#1e293b; border-color:#334155; color:#e2e8f0; }
[data-theme="dark"] .inv-card-lbl { color:#94a3b8; }
.inv-charts { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin:12px 0; }
.inv-num { text-align:right; }
/* ...table, toolbar, modal, segmented control, badges — mirror Treasury_index.tpl... */
</style>

<div class="inv-wrap" id="inv-app"
     data-ajax="<?= htmlspecialchars($ajaxBase) ?>"
     data-kingdom="<?= (int)$kingdom_id ?>">
  <div class="inv-hero"><h1>Inventory — <?= htmlspecialchars($org_name) ?></h1></div>

  <div class="inv-cards">
    <div class="inv-card"><div class="inv-card-lbl">Total Value</div>
      <div class="inv-card-val" id="inv-total-value"><?= $fmt($summary['TotalValue']) ?></div></div>
    <div class="inv-card"><div class="inv-card-lbl">Total Units</div>
      <div class="inv-card-val" id="inv-total-units"><?= (int)$summary['TotalUnits'] ?></div></div>
    <div class="inv-card"><div class="inv-card-lbl">Line Items</div>
      <div class="inv-card-val" id="inv-line-items"><?= (int)$summary['LineItems'] ?></div></div>
    <div class="inv-card"><div class="inv-card-lbl">Needs Repair</div>
      <div class="inv-card-val" id="inv-needs-repair"><?= (int)$summary['NeedsRepair'] ?></div></div>
  </div>

  <div class="inv-charts">
    <div id="inv-chart-category" style="height:260px"></div>
    <div id="inv-chart-condition" style="height:260px"></div>
  </div>

  <div class="inv-toolbar">
    <input type="text" id="inv-f-q" placeholder="Search name…">
    <select id="inv-f-cat"><option value="">All categories</option>
      <?php foreach ($categories as $k => $lbl): ?><option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?>
    </select>
    <select id="inv-f-cond"><option value="">Any condition</option>
      <?php foreach ($condLabels as $k => $lbl): ?><option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?>
    </select>
    <select id="inv-f-status"><option value="active">Active</option><option value="removed">Removed</option></select>
    <button class="inv-btn" id="inv-add">+ Add Item</button>
    <a class="inv-btn" id="inv-export" href="<?= htmlspecialchars($ajaxBase) ?>export">Export CSV</a>
  </div>

  <table class="inv-table" id="inv-table">
    <thead><tr>
      <th data-sort="name">Name</th><th data-sort="category">Category</th>
      <th class="inv-num" data-sort="quantity">Qty</th><th data-sort="condition">Condition</th>
      <th class="inv-num" data-sort="unit_value">Unit Value</th><th class="inv-num" data-sort="total_value">Total Value</th>
      <th data-sort="location">Location</th><th>Held By</th><th></th>
    </tr></thead>
    <tbody id="inv-table-body"><!-- rendered by JS --></tbody>
  </table>
  <div class="inv-empty" id="inv-empty" style="display:none">No items yet — add your first item.</div>
  <div class="inv-pager" id="inv-pager"></div>
</div>

<script>
window.InvConfig = {
  ajax: '<?= $ajaxBase ?>',
  kingdomId: <?= (int)$kingdom_id ?>,
  categories: <?= json_encode($categories) ?>,
  removalReasons: <?= json_encode($removal_reasons) ?>,
  conditionLabels: <?= json_encode($condLabels) ?>,
  summary: <?= json_encode($summary) ?>,
  initialItems: <?= json_encode($items) ?>
};
</script>
```

- [ ] **Step 2: Verify it renders** — browser login (project bypass), open `index.php?Route=Inventory/park/1`. Expected: hero, four cards (Total Value $700.00 from Task 6 synthetic rows), chart containers, toolbar, JS-pending table body. Check console for errors.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Inventory_index.tpl
git commit -m "Inventory template: scaffold, summary cards, table shell, config

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: Template — item render JS, add/edit modal (with held-by playersearch)

**Files:**
- Modify: `orkui/template/revised-frontend/Inventory_index.tpl`

- [ ] **Step 1: Append the JS app** — fetch/render items, sortable headers, pager, add/edit modal, submit via `fetch` POST (form-encoded). **Mirror `Treasury_index.tpl`'s ledger JS, modal, flatpickr, and counterparty playersearch verbatim**, renamed for inventory. Required specifics:

- `loadItems()` builds the query with `&`-joined params (`cfg.ajax + 'items?' + params`) — never `?`.
- Row render columns: Name, Category (label via `cfg.categories`), Qty, Condition (label via `cfg.conditionLabels`, "Needs Repair" badge-styled), Unit Value (money), Total Value (money), Location, Held By; action cell: **Edit**, **Remove** (active status) / **Restore** (removed status), and a quiet **Delete**.
- Empty state: show `#inv-empty` and hide the table when `Rows.length === 0`.
- Add/Edit modal fields: name (text, required), category (grouped/plain `<select>`), quantity (number, min 1), condition (segmented control: New/Good/Fair/Poor/Needs Repair), unit value (number, step 0.01), location (text), **held-by** (free-text input wired to the scoped `kn-ac-results` playersearch — copy Treasury's counterparty implementation: endpoint `KingdomAjax/playersearch/<cfg.kingdomId>&scope=own&include_inactive=1&q=…`, `&q=` not `?q=`, 2-char min, `tnFixedAcPosition(input,dropdown)` before every `classList.add('kn-ac-open')`, selecting a player sets a hidden `held_by_player_id` + the visible name; typing free-text clears the hidden id), acquired date (flatpickr `altInput:true, altFormat:'F j, Y'`), notes (textarea).
- On submit POST to `additem`/`edititem`; for edit, prefill via `getitem`. On success: close modal, reload items + summary + charts.

Key money + escape helpers (copy from Treasury):
```javascript
var money = function (n) { return '$' + (Number(n)).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
function escapeHtml(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
```

- [ ] **Step 2: Verify CRUD in browser** — add an item (name, weapons, qty 5, good, $10, location, a held-by player via the dropdown), confirm it appears with Total Value $50.00 and the held-by name; edit it; confirm DB row + `create`/`edit` audit rows via `docker exec ... mariadb`. Confirm the held-by dropdown returns rows and positions correctly inside the modal.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Inventory_index.tpl
git commit -m "Inventory template: item render, add/edit modal, held-by playersearch

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: Template — remove / restore / delete flows

**Files:**
- Modify: `orkui/template/revised-frontend/Inventory_index.tpl`

- [ ] **Step 1: Add the remove/restore/delete wiring.**
  - **Remove** (active rows): opens a modal — removal-reason `<select>` populated from `cfg.removalReasons` (required) + optional note textarea → POST `removeitem` with `id`, `removal_reason`, `removal_note`. Block submit if no reason chosen. On success: reload items + summary + charts.
  - **Restore** (removed rows, shown when the status filter = Removed): `tnConfirm({title:'Restore item', ...})` → POST `restoreitem`. On success: reload.
  - **Delete** (quiet correction action): `tnConfirm({title:'Delete entry', body:'This removes a mis-entered item entirely. Use “Remove from Inventory” for items the org disposed of.', danger:true})` → POST `deleteitem`. On success: reload.
  - When status filter = Removed, render the reason label + note + removed date per row and swap the action cell to Restore.

- [ ] **Step 2: Verify** — on `park/1`: Remove the pavilion (reason "Damaged Beyond Repair", a note); confirm it leaves the Active list, Total Value drops by 400 to e.g. $50, and it appears under the Removed filter with the reason. Restore it; confirm it returns to Active and value recovers. Delete a throwaway item; confirm it vanishes from both Active and Removed and a `delete` audit row exists. Confirm `remove`/`restore`/`delete` audit rows in the DB.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Inventory_index.tpl
git commit -m "Inventory template: remove / restore / delete flows

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 11: Template — charts + auto-refresh heartbeat

**Files:**
- Modify: `orkui/template/revised-frontend/Inventory_index.tpl`

- [ ] **Step 1: Add Highcharts init + rev poll** (mirror Treasury's chart + `rev` heartbeat):
  - `#inv-chart-category`: donut/pie of value-by-category from `cfg.summary.ByCategory`, labels via `cfg.categories`.
  - `#inv-chart-condition`: column of count-by-condition from `cfg.summary.ByCondition`, labels via `cfg.conditionLabels`, fixed condition order (new→needs_repair).
  - Use `backgroundColor:'transparent'`, `credits:{enabled:false}`, the `_isDark` (`document.documentElement.dataset.theme==='dark'`) tooltip/text-color pattern from Treasury.
  - Re-render both after any CRUD by refetching `summary` (a `loadSummary()` that updates the four cards AND both charts).
  - Auto-refresh: poll `rev` every ~25s + on `focus`; pause when a modal is open or `document.hidden`; when `Rev` changes, refetch items + summary. Copy Treasury's implementation.

- [ ] **Step 2: Verify** — charts render from synthetic data; add/remove an item and confirm both charts + cards update without reload; toggle dark mode and confirm legibility (tooltips, axis labels, donut labels). Open the page in two tabs, change one, confirm the other refreshes within ~25s.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Inventory_index.tpl
git commit -m "Inventory template: category + condition charts, auto-refresh heartbeat

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 12: Admin Tasks tab integration

**Files:**
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (~line 1155, after the Treasury `<li>`)
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` (~line 835, after the Treasury `<li>`)

- [ ] **Step 1: Add the Inventory link** immediately after the existing Treasury `<li>` in each template's Admin Tasks tab "Park"/"Kingdom" group.

Parknew (after line 1155):
```php
							<li><a href="<?= UIR ?>Inventory/park/<?= $park_id ?>">Inventory</a></li>
```

Kingdomnew (after line 835):
```php
						<li><a href="<?= UIR ?>Inventory/kingdom/<?= $kingdom_id ?>">Inventory</a></li>
```

- [ ] **Step 2: Verify** — as a park officer, the Parknew Admin Tasks tab "Park" group shows an Inventory link landing on the tool; same for Kingdomnew. As a non-officer, the Admin tab/link isn't shown and a direct URL redirects to Login.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/revised-frontend/Parknew_index.tpl orkui/template/revised-frontend/Kingdomnew_index.tpl
git commit -m "Inventory: Admin Tasks tab links (kingdom/park)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 13: End-to-end verification + conventions walk

**Files:** none (verification only)

- [ ] **Step 1: Auth boundary** — confirm every AJAX action denies an unauthorized session (curl with a non-officer cookie jar): each returns `status:5`/`4`. Confirm a forged `owner_id` the officer lacks authority over is denied by the lib.
- [ ] **Step 2: Full lifecycle** on a clean org — add several items across categories/conditions, edit one, set + clear a held-by player, set + clear an acquired date (confirm it persists as `NULL`), remove one (with reason), restore it, soft-delete one (verify it vanishes from Active AND Removed but the row + audit persist), export CSV (open the file, confirm rows + total value + status/reason columns match the UI).
- [ ] **Step 3: Money/count correctness** — verify Total Value = `Σ qty × unit_value` over active items only; removed/deleted excluded; `# Needs Repair` matches; `decimal(12,2)` precision (no float drift). Cross-check against a hand calculation.
- [ ] **Step 4: AJAX-URL `&` audit** — grep the template for `cfg.ajax + '` and confirm EVERY built URL appends params with `&`, never `?`. Exercise filter + pagination + sort + edit + remove + export **in a real browser** (this is where the `?`-vs-`&` bug hides from curl).
- [ ] **Step 5: Dark-mode + conventions walk** — toggle dark mode; verify cards, table, modals (add/edit, remove), segmented control, charts, badges, empty state all legible. Confirm: no native `confirm/alert` (only `tnConfirm`), no native `title` tooltips (only `data-tip`), flatpickr human-readable dates, custom heading has the h1–h6 gray-box reset, the held-by dropdown positions correctly inside the modal.
- [ ] **Step 6: Final lint sweep** — `php -l` on all five new PHP files.

---

## Self-Review Notes (author)

- **Spec coverage:** ownership/independent register (Task 1 schema + Task 2 `authFor`), quantity-stack model (Task 1 `quantity` + Task 3 validation), fixed categories + removal reasons (Task 2 maps), optional fields incl. held-by custody (Task 1 columns + Task 3 save + Task 9 playersearch), total-value computation active-only (Task 2 `GetSummary` + Task 4 `GetItems`), remove/restore disposal pathway (Task 4 + Task 10), soft-delete + audit (Task 3/4 + audit writer), summary cards + two charts + CSV (Task 2/6/8/11), officers-only auth (Task 2/7/6 gates), Admin-tab placement (Task 12), no reconciliation/attachments/loan-log (out of scope, not built). All spec sections map to a task.
- **Type consistency:** lib returns `Rows` with keys `Id/Name/Category/Quantity/Condition/UnitValue/TotalValue/Location/HeldBy/HeldByPlayerId/AcquiredDate/Notes/RemovedAt/RemovalReason/RemovalNote` (Task 4) — the template (Task 8/9/10) and CSV (Task 6) consume exactly these. Summary keys `TotalValue/TotalUnits/LineItems/NeedsRepair/ByCategory/ByCondition` (Task 2) consumed identically in Task 7/8/11. Model method names (Task 5) match AJAX calls (Task 6).
- **yapo-null exception:** `acquired_date` is the single deliberately-`null`-cleared column (genuine DATE NULL, no sentinel) — called out in Task 3 and re-verified in Task 13 Step 2. All other clearable columns use `''`/`0` sentinels per the gotcha.
- **`condition` reserved word:** backtick-quoted in every query (Task 2 summary, Task 4 list/sort). Verified in lint + the Task 6 curl test (a 500 here would surface a missing backtick).
- **Held-by JOIN caveat (Task 4):** the `mundane` JOIN for the player display name is an enhancement; `held_by` free-text already carries a display string, so a column-name mismatch degrades gracefully rather than breaking the list. Verify the `given_name`/`surname` column names against `controller.Playernew.php` during Task 4.
