<?php

class Inventory extends Ork3
{
    public static $CATEGORIES = [
        'weapons'         => 'Weapons',
        'loaner_weapons'  => 'Loaner Weapons',
        'armor'           => 'Armor',
        'shields'         => 'Shields',
        'garb'            => 'Garb / Loaner Garb',
        'regalia'         => 'Regalia (Crowns, Thrones, Chains)',
        'banners'         => 'Banners / Heraldry',
        'tentage'         => 'Pavilions / Tentage',
        'archery_siege'   => 'Archery / Siege',
        'event_equipment' => 'Event Equipment',
        'feast_kitchen'   => 'Feast / Kitchen',
        'awards_supplies' => 'Award & Scroll Supplies',
        'furniture'       => 'Furniture',
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

    public static $CONDITION_LABELS = [
        'new' => 'New', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor', 'needs_repair' => 'Needs Repair',
    ];

    public static $AUDIT_ACTIONS = ['create', 'edit', 'remove', 'restore', 'delete', 'undelete', 'split', 'verify'];

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

    /** Shared owner + filter WHERE for GetSummary/GetItems/VerifyItems. Invalid category/condition
     *  values are ignored; q matches name/location/held_by/notes (LIKE wildcards escaped); location /
     *  held_by are exact matches (held_by ignored when held_by_player_id > 0); stale=1 keeps items not
     *  verified in the last 365 days. Status is left to callers. */
    private function buildWhere($owner_type, $owner_id, $filters, $alias = '')
    {
        $p = $alias !== '' ? $alias . '.' : '';
        $where = "{$p}owner_type='" . $this->normType($owner_type) . "' AND {$p}owner_id=" . (int)$owner_id;
        $cat  = (string)($filters['category'] ?? '');
        $cond = (string)($filters['condition'] ?? '');
        $q    = (string)($filters['q'] ?? '');
        $loc  = (string)($filters['location'] ?? '');
        $hbId = (int)($filters['held_by_player_id'] ?? 0);
        $hb   = (string)($filters['held_by'] ?? '');
        if ($cat !== '' && $this->validCategory($cat))    { $where .= " AND {$p}category = '$cat'"; }
        if ($cond !== '' && $this->validCondition($cond)) { $where .= " AND {$p}`condition` = '$cond'"; }
        if ($q !== '') {
            $like = "'%" . addslashes(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q)) . "%'";
            $where .= " AND ({$p}name LIKE $like OR {$p}location LIKE $like OR {$p}held_by LIKE $like OR {$p}notes LIKE $like)";
        }
        if ($loc !== '' && mb_strlen($loc) <= 255) { $where .= " AND {$p}location = '" . addslashes($loc) . "'"; }
        if ($hbId > 0) {
            $where .= " AND {$p}held_by_player_id = $hbId";
        } elseif ($hb !== '' && mb_strlen($hb) <= 255) {
            $where .= " AND {$p}held_by = '" . addslashes($hb) . "'";
        }
        if ((string)($filters['stale'] ?? '') === '1') {
            $where .= " AND ({$p}last_verified_at IS NULL OR {$p}last_verified_at < NOW() - INTERVAL 365 DAY)";
        }
        return $where;
    }

    /** Display name of the org (park/kingdom) by id; also returns KingdomId for player-search scope
     *  and ViewerPersona (the authorized viewer's persona, or 'Player #<id>' — never the login username). */
    public function GetOwnerName($token, $owner_type, $owner_id)
    {
        $viewer_id = $this->authFor($token, $owner_type, $owner_id);
        if (!$viewer_id) { return NoAuthorization(); }
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
        $viewer = '';
        $DB->Clear();
        $vrs = $DB->DataSet("SELECT persona FROM " . DB_PREFIX . "mundane WHERE mundane_id=" . (int)$viewer_id . " LIMIT 1");
        if ($vrs && $vrs->Next()) { $viewer = trim((string)$vrs->persona); }
        if ($viewer === '') { $viewer = 'Player #' . (int)$viewer_id; }
        return Success(['Name' => $name, 'KingdomId' => (int)$kingdom_id, 'ViewerPersona' => $viewer]);
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
     *  value-by-category, count-by-condition, plus Counts (line items per status). $filters narrows
     *  the same way GetItems does but NOT status — the totals are always over active items. */
    public function GetSummary($token, $owner_type, $owner_id, $filters = [])
    {
        global $DB;
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        $base  = $this->buildWhere($owner_type, $owner_id, $filters);
        $where = $base . " AND deleted_at IS NULL AND removed_at IS NULL";

        $DB->Clear();
        $rs = $DB->DataSet("SELECT
            COALESCE(SUM(quantity * unit_value),0) AS total_value,
            COALESCE(SUM(quantity),0)              AS total_units,
            COUNT(*)                               AS line_items,
            COALESCE(SUM(CASE WHEN `condition`='needs_repair' THEN quantity ELSE 0 END),0) AS needs_repair
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
        $rs3 = $DB->DataSet("SELECT `condition` AS c, COALESCE(SUM(quantity),0) AS n
            FROM " . DB_PREFIX . "inventory_item WHERE $where GROUP BY `condition`");
        $byCond = [];
        while ($rs3 && $rs3->Next()) { $byCond[$rs3->c] = (int)$rs3->n; }

        // Line-item counts per status (same filters, independent of status) for the status toggle.
        $DB->Clear();
        $rs4 = $DB->DataSet("SELECT
            COALESCE(SUM(CASE WHEN deleted_at IS NULL AND removed_at IS NULL THEN 1 ELSE 0 END),0) AS active_n,
            COALESCE(SUM(CASE WHEN deleted_at IS NULL AND removed_at IS NOT NULL THEN 1 ELSE 0 END),0) AS removed_n,
            COALESCE(SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END),0) AS deleted_n
            FROM " . DB_PREFIX . "inventory_item WHERE $base");
        $counts = ['Active' => 0, 'Removed' => 0, 'Deleted' => 0];
        if ($rs4 && $rs4->Next()) {
            $counts = ['Active' => (int)$rs4->active_n, 'Removed' => (int)$rs4->removed_n, 'Deleted' => (int)$rs4->deleted_n];
        }

        return Success([
            'TotalValue'  => round($tv, 2),
            'TotalUnits'  => $tu,
            'LineItems'   => $li,
            'NeedsRepair' => $nr,
            'ByCategory'  => $byCat,
            'ByCondition' => $byCond,
            'Counts'      => $counts,
        ]);
    }

    private function writeAudit($item_id, $action, $mundane_id, $before, $after)
    {
        global $DB;
        $this->audit->clear();
        $this->audit->item_id    = (int)$item_id;
        $this->audit->action     = $action;
        $this->audit->changed_by = (int)$mundane_id;
        $this->audit->changed_at = date('Y-m-d H:i:s');
        // yapo drops null fields from INSERT; '' clears the column instead of leaving it stale.
        $this->audit->before_json = $before === null ? '' : json_encode($before);
        $this->audit->after_json  = $after  === null ? '' : json_encode($after);
        $changedAt = $this->audit->changed_at;
        $this->audit->save();
        // PDO errors are silent and lastInsertId() can be stale, so confirm the row by re-reading it.
        $aid = (int)$this->audit->id;
        if ($aid <= 0) { return 0; }
        $DB->Clear();
        $rs = $DB->DataSet("SELECT item_id, action, changed_by, changed_at FROM " . DB_PREFIX . "inventory_audit WHERE id=$aid LIMIT 1");
        if (!$rs || !$rs->Next() || (int)$rs->item_id !== (int)$item_id || $rs->action !== $action
            || (int)$rs->changed_by !== (int)$mundane_id || $rs->changed_at !== $changedAt) {
            return 0;
        }
        return $aid;
    }

    /** Fresh DB snapshot of an item row (all columns, keyed like itemToArray), or null. */
    private function readRow($id)
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet("SELECT * FROM " . DB_PREFIX . "inventory_item WHERE id=" . (int)$id . " LIMIT 1");
        if ($rs && $rs->Next()) { return $rs->CurrentFieldSet(); }
        return null;
    }

    /** Inside an open transaction: lock and return the item row (SELECT ... FOR UPDATE), or null. */
    private function lockRow($id)
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet("SELECT * FROM " . DB_PREFIX . "inventory_item WHERE id=" . (int)$id . " LIMIT 1 FOR UPDATE");
        if ($rs && $rs->Next()) { return $rs->CurrentFieldSet(); }
        return null;
    }

    /** True when every $expect field matches the re-read row (numeric compare for numbers, null-aware). */
    private function rowMatches($row, $expect)
    {
        if (!is_array($row)) { return false; }
        foreach ($expect as $k => $v) {
            $got = $row[$k] ?? null;
            if ($v === null || $got === null) {
                if ($v !== $got) { return false; }
            } elseif (is_int($v) || is_float($v)) {
                if (!is_numeric($got) || abs((float)$got - (float)$v) > 0.001) { return false; }
            } elseif ((string)$got !== (string)$v) {
                return false;
            }
        }
        return true;
    }

    private function beginTx()    { global $DB; $DB->Clear(); $DB->Execute('START TRANSACTION'); }
    private function commitTx()   { global $DB; $DB->Clear(); $DB->Execute('COMMIT'); }
    private function rollbackTx() { global $DB; $DB->Clear(); $DB->Execute('ROLLBACK'); }

    /** Roll back the open transaction and report a write that did not land. */
    private function failTx($msg = 'The change could not be saved. Please try again.')
    {
        $this->rollbackTx();
        return ProcessingError($msg);
    }

    /** Roll back the open transaction and report a state check that failed under the row lock. */
    private function abortTx($msg)
    {
        $this->rollbackTx();
        return InvalidParameter($msg);
    }

    private function changedMsg($qty)
    {
        return 'This item changed since you opened it (quantity is now ' . (int)$qty . '). Reopen it and try again.';
    }

    /** Validate optional-text lengths against column widths; returns an error array or null. */
    private function checkLengths($fields)
    {
        $limits = ['name' => 255, 'location' => 255, 'held_by' => 255, 'notes' => 500, 'removal_note' => 500,
            'change_note' => 500, 'delete_note' => 500, 'disposed_to' => 255];
        $labels = ['name' => 'Name', 'location' => 'Location', 'held_by' => 'Held by', 'notes' => 'Notes',
            'removal_note' => 'Removal note', 'change_note' => 'Change note', 'delete_note' => 'Delete note',
            'disposed_to' => 'Disposed to'];
        foreach ($fields as $k => $v) {
            if (mb_strlen((string)$v) > $limits[$k]) {
                return InvalidParameter($labels[$k] . ' must be ' . $limits[$k] . ' characters or fewer.');
            }
        }
        return null;
    }

    /** Decrement an active/owned row's quantity by $units, guarded on the loaded quantity; verified. */
    private function decrementQuantity($id, $fromQty, $units, $now)
    {
        global $DB;
        $DB->Clear();
        $DB->units = (int)$units;
        $DB->now   = $now;
        $DB->Execute("UPDATE " . DB_PREFIX . "inventory_item SET quantity = quantity - :units, updated_at = :now
            WHERE id = " . (int)$id . " AND quantity = " . (int)$fromQty . " AND deleted_at IS NULL");
        $row = $this->readRow($id);
        return $this->rowMatches($row, ['quantity' => (int)$fromQty - (int)$units, 'updated_at' => $now]) ? $row : null;
    }

    /** Insert a copy of $src (a readRow snapshot) with quantity $qty, keeping the source's created_by /
     *  created_at / verification stamp (the acting officer is recorded in the audit row); $removal =
     *  [removed_at, reason, note, [disposal_date, disposal_value, disposed_to]] makes it a removed row.
     *  Returns the verified new row or null. */
    private function insertCopy($src, $qty, $mundane_id, $now, $removal = null)
    {
        $this->item->clear();
        $expect = [
            'owner_type' => $src['owner_type'], 'owner_id' => (int)$src['owner_id'],
            'name' => $src['name'], 'category' => $src['category'], 'quantity' => (int)$qty,
            'condition' => $src['condition'], 'unit_value' => (float)$src['unit_value'],
            'location' => $src['location'], 'held_by' => $src['held_by'],
            'held_by_player_id' => (int)$src['held_by_player_id'], 'acquired_date' => $src['acquired_date'],
            'notes' => $src['notes'], 'created_by' => (int)$src['created_by'], 'created_at' => $src['created_at'],
            'removed_at' => $removal ? $removal[0] : null,
            'removal_reason' => $removal ? $removal[1] : '', 'removal_note' => $removal ? $removal[2] : '',
            'disposal_date' => $removal ? $removal[3][0] : null,
            'disposal_value' => $removal ? (float)$removal[3][1] : 0.0,
            'disposed_to' => $removal ? $removal[3][2] : '',
            'treasury_entry_id' => 0,
            'last_verified_at' => $src['last_verified_at'], 'last_verified_by' => (int)$src['last_verified_by'],
            'deleted_at' => null,
        ];
        foreach ($expect as $k => $v) {
            if ($v !== null) { $this->item->$k = $v; }
        }
        $this->item->save();
        $newId = (int)$this->item->id;
        if ($newId <= 0 || $newId === (int)$src['id']) { return null; }
        $row = $this->readRow($newId);
        return $this->rowMatches($row, $expect) ? $row : null;
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
            'disposal_date'     => $this->item->disposal_date,
            'disposal_value'    => $this->item->disposal_value,
            'disposed_to'       => $this->item->disposed_to,
            'treasury_entry_id' => $this->item->treasury_entry_id,
            'deleted_at'        => $this->item->deleted_at,
            'created_by'        => $this->item->created_by,
            'created_at'        => $this->item->created_at,
            'updated_at'        => $this->item->updated_at,
        ];
    }

    /** Load an owned item into $this->item; false if not found / not owned. Deleted items are
     *  excluded unless $includeDeleted (history / undelete). */
    private function loadOwnedItem($id, $owner_type, $owner_id, $includeDeleted = false)
    {
        $this->item->clear();
        $this->item->id = (int)$id;
        if (!$this->item->find() || (!$includeDeleted && $this->item->deleted_at !== null)
            || (int)$this->item->owner_id !== (int)$owner_id
            || $this->item->owner_type !== $this->normType($owner_type)) {
            return false;
        }
        return true;
    }

    /** Create or edit. $data: owner_type, owner_id, [id], name, category, quantity, condition,
     *  unit_value, location, held_by, held_by_player_id, acquired_date, notes,
     *  [change_note] (required on edit when quantity is lowered),
     *  [expected_quantity] (edit: the quantity the client loaded; if the row's quantity differs at save
     *  time the edit is rejected — defaults to the quantity read at the start of this call). */
    public function SaveItem($token, $data)
    {
        global $DB;
        $mundane_id = $this->authFor($token, $data['owner_type'] ?? '', $data['owner_id'] ?? 0);
        if (!$mundane_id) { return NoAuthorization(); }

        $name      = trim((string)($data['name'] ?? ''));
        $cat       = (string)($data['category'] ?? '');
        $qty       = (int)($data['quantity'] ?? 0);
        $cond      = (string)($data['condition'] ?? 'good');
        $rawValue  = trim((string)($data['unit_value'] ?? ''));
        $acquired  = (string)($data['acquired_date'] ?? '');
        $location  = (string)($data['location'] ?? '');
        $heldBy    = (string)($data['held_by'] ?? '');
        $notes     = (string)($data['notes'] ?? '');
        $changeNote = trim((string)($data['change_note'] ?? ''));

        if ($name === '')                  { return InvalidParameter('Name is required.'); }
        if (!$this->validCategory($cat))   { return InvalidParameter('Unknown category.'); }
        if ($qty < 1)                      { return InvalidParameter('Quantity must be at least 1.'); }
        if (!$this->validCondition($cond)) { return InvalidParameter('Unknown condition.'); }
        if ($rawValue !== '' && !is_numeric($rawValue)) { return InvalidParameter('Unit value must be a number.'); }
        $unitValue = round((float)$rawValue, 2);
        if ($unitValue < 0)                { return InvalidParameter('Unit value cannot be negative.'); }
        if ($acquired !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $acquired, $dm)
            || !checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1]))) {
            return InvalidParameter('Invalid acquired date.');
        }
        $lenErr = $this->checkLengths(['name' => $name, 'location' => $location, 'held_by' => $heldBy,
            'notes' => $notes, 'change_note' => $changeNote]);
        if ($lenErr) { return $lenErr; }

        // Only link a held-by player that actually exists; otherwise keep the free-text label with no link.
        $heldById = (int)($data['held_by_player_id'] ?? 0);
        if ($heldById > 0) {
            $DB->Clear();
            $prs = $DB->DataSet("SELECT mundane_id FROM " . DB_PREFIX . "mundane WHERE mundane_id=$heldById LIMIT 1");
            if (!$prs || !$prs->Next()) { $heldById = 0; }
        } else {
            $heldById = 0;
        }

        $isEdit = !empty($data['id']);
        $before = null;
        if ($isEdit) {
            if (!$this->loadOwnedItem($data['id'], $data['owner_type'], $data['owner_id'])) {
                return InvalidParameter('Item not found.');
            }
            if ($this->item->removed_at !== null) {
                return InvalidParameter('Item is removed; restore it before editing.');
            }
            if ($qty < (int)$this->item->quantity && $changeNote === '') {
                return InvalidParameter('Quantity was lowered — add a note explaining why, or use Remove from Inventory to record lost/sold units.');
            }
            $before = $this->itemToArray();
        }

        $now = date('Y-m-d H:i:s');
        $expect = [
            'name' => $name, 'category' => $cat, 'quantity' => $qty, 'condition' => $cond,
            'unit_value' => $unitValue, 'location' => $location, 'held_by' => $heldBy,
            'held_by_player_id' => $heldById, 'notes' => $notes,
            'acquired_date' => $acquired !== '' ? $acquired : null,
        ];

        $this->beginTx();
        if ($isEdit) {
            // Re-check under a row lock so a concurrent remove/split/edit can't be overwritten.
            $locked = $this->lockRow($data['id']);
            if (!$locked || $locked['deleted_at'] !== null) { return $this->abortTx('Item not found.'); }
            if ($locked['removed_at'] !== null) { return $this->abortTx('Item is removed; restore it before editing.'); }
            $expectedQty = (isset($data['expected_quantity']) && $data['expected_quantity'] !== '')
                ? (int)$data['expected_quantity'] : (int)$before['quantity'];
            if ((int)$locked['quantity'] !== $expectedQty) { return $this->abortTx($this->changedMsg($locked['quantity'])); }
            if ($qty < (int)$locked['quantity'] && $changeNote === '') {
                return $this->abortTx('Quantity was lowered — add a note explaining why, or use Remove from Inventory to record lost/sold units.');
            }
            $before = $locked;
        }
        if (!$isEdit) {
            $this->item->clear();
            $this->item->owner_type = $this->normType($data['owner_type']);
            $this->item->owner_id   = (int)$data['owner_id'];
            $this->item->created_by = $mundane_id;
            $this->item->created_at = $now;
            // new items start active
            $this->item->removal_reason = '';
            $this->item->removal_note   = '';
            $expect += ['owner_type' => $this->normType($data['owner_type']), 'owner_id' => (int)$data['owner_id'],
                'created_by' => $mundane_id, 'created_at' => $now, 'removed_at' => null, 'deleted_at' => null];
        } else {
            $expect['updated_at'] = $now;
        }
        $this->item->name       = $name;
        $this->item->category   = $cat;
        $this->item->quantity   = $qty;
        $this->item->condition  = $cond;
        $this->item->unit_value = $unitValue;
        // yapo drops null fields; assign '' / 0 to clear an optional column rather than leave it stale.
        $this->item->location   = $location;
        $this->item->held_by    = $heldBy;
        $this->item->held_by_player_id = $heldById;
        $this->item->notes      = $notes;
        // acquired_date is genuinely nullable in the schema; '' must become NULL.
        $this->item->acquired_date = $acquired !== '' ? $acquired : null;
        if ($isEdit) { $this->item->updated_at = $now; }
        $this->item->save();

        $id = $isEdit ? (int)$data['id'] : (int)$this->item->id;
        if ($id <= 0) { return $this->failTx(); }
        // yapo drops a `null` SET from UPDATE (isset() is false for null), so clearing
        // acquired_date through yapo silently leaves the old date. Null it via a raw UPDATE
        // on edit (mirrors the removed_at workaround in RestoreItem).
        if ($isEdit && $acquired === '') {
            $DB->Clear();
            $DB->Execute("UPDATE " . DB_PREFIX . "inventory_item SET acquired_date = NULL WHERE id = " . (int)$id);
        }
        $after = $this->readRow($id);
        if (!$this->rowMatches($after, $expect)) { return $this->failTx(); }
        if ($changeNote !== '') { $after['_change_note'] = $changeNote; }
        if (!$this->writeAudit($id, $isEdit ? 'edit' : 'create', $mundane_id, $before, $after)) { return $this->failTx(); }
        $this->commitTx();
        return Success(['Id' => $id]);
    }

    public function GetItem($token, $owner_type, $owner_id, $id)
    {
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        if (!$this->loadOwnedItem($id, $owner_type, $owner_id)) {
            return InvalidParameter('Item not found.');
        }
        $out = $this->itemToArray();
        // Player-search style label for the linked holder: "Persona (KD:PK)"; '' when unlinked.
        $out['held_by_player_label'] = '';
        $hbId = (int)$out['held_by_player_id'];
        if ($hbId > 0) {
            global $DB;
            $DB->Clear();
            $rs = $DB->DataSet("SELECT m.persona, COALESCE(k.abbreviation, '') AS kab, COALESCE(p.abbreviation, '') AS pab
                FROM " . DB_PREFIX . "mundane m
                LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
                LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
                WHERE m.mundane_id = $hbId LIMIT 1");
            if ($rs && $rs->Next()) {
                $out['held_by_player_label'] = $rs->persona . ' (' . $rs->kab . ':' . $rs->pab . ')';
            }
        }
        return Success($out);
    }

    /** Disposal: mark no longer owned, with a required reason + optional note. $units (0 = all)
     *  removes only part of the quantity: the removed units split off into their own removed row.
     *  $extra: disposal_date (Y-m-d, default today, not future), disposal_value (>= 0; > 0 required when
     *  sold), disposed_to, record_treasury ('1' = also post a Treasury income entry for a priced sale, in
     *  the same transaction), treasury_category (income key, default income_other), treasury_method. */
    public function RemoveItem($token, $owner_type, $owner_id, $id, $reason, $note = '', $units = 0, $extra = [])
    {
        global $DB;
        $mundane_id = $this->authFor($token, $owner_type, $owner_id);
        if (!$mundane_id) { return NoAuthorization(); }
        if (!$this->validReason($reason)) { return InvalidParameter('A removal reason is required.'); }
        $note = trim((string)$note);
        $extra = is_array($extra) ? $extra : [];
        $dDate = trim((string)($extra['disposal_date'] ?? ''));
        if ($dDate === '') { $dDate = date('Y-m-d'); }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dDate, $dm) || !checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
            return InvalidParameter('Invalid disposal date.');
        }
        if ($dDate > date('Y-m-d')) { return InvalidParameter("Disposal date can't be in the future."); }
        $rawValue = trim((string)($extra['disposal_value'] ?? ''));
        if ($rawValue !== '' && !is_numeric($rawValue)) { return InvalidParameter('Disposal value must be a number.'); }
        $dValue = round((float)$rawValue, 2);
        if ($dValue < 0) { return InvalidParameter('Disposal value cannot be negative.'); }
        if ($reason === 'sold' && $dValue <= 0) { return InvalidParameter('Enter the sale price.'); }
        $dTo = trim((string)($extra['disposed_to'] ?? ''));
        $recordTreasury = (string)($extra['record_treasury'] ?? '') === '1';
        $tCat    = (string)($extra['treasury_category'] ?? '');
        $tMethod = (string)($extra['treasury_method'] ?? '');
        if ($recordTreasury) {
            if ($reason !== 'sold' || $dValue <= 0) {
                return InvalidParameter('Only a sale with a price can be recorded in Treasury.');
            }
            if ($tCat === '') { $tCat = 'income_other'; }
            if (!isset(Treasury::$CATEGORIES['income'][$tCat])) { return InvalidParameter('Unknown Treasury category.'); }
            if (!in_array($tMethod, ['cash', 'check', 'digital'], true)) {
                return InvalidParameter('Choose how the sale was paid (cash, check, or digital).');
            }
        }
        $lenErr = $this->checkLengths(['removal_note' => $note, 'disposed_to' => $dTo]);
        if ($lenErr) { return $lenErr; }
        if (!$this->loadOwnedItem($id, $owner_type, $owner_id)) { return InvalidParameter('Item not found.'); }
        if ($this->item->removed_at !== null) { return InvalidParameter('Item is already removed.'); }
        $qty   = (int)$this->item->quantity;
        $units = (int)$units;
        if ($units === 0) { $units = $qty; }
        if ($units < 1 || $units > $qty) { return InvalidParameter("Units to remove must be between 1 and $qty."); }
        $before = $this->itemToArray();
        $now = date('Y-m-d H:i:s');

        $this->beginTx();
        $locked = $this->lockRow($id);
        if (!$locked || $locked['deleted_at'] !== null) { return $this->abortTx('Item not found.'); }
        if ($locked['removed_at'] !== null) { return $this->abortTx('Item is already removed.'); }
        if ((int)$locked['quantity'] !== $qty) { return $this->abortTx($this->changedMsg($locked['quantity'])); }
        $src = null;
        if ($units < $qty) {
            $src = $this->decrementQuantity($id, $qty, $units, $now);
            if (!$src) { return $this->failTx(); }
            $removed = $this->insertCopy($src, $units, $mundane_id, $now, [$now, $reason, $note, [$dDate, $dValue, $dTo]]);
            if (!$removed) { return $this->failTx(); }
            $removedId = (int)$removed['id'];
        } else {
            $this->item->removed_at     = $now;
            $this->item->removal_reason = $reason;
            $this->item->removal_note   = $note;
            $this->item->disposal_date  = $dDate;
            $this->item->disposal_value = $dValue;
            $this->item->disposed_to    = $dTo;
            $this->item->updated_at     = $now;
            $this->item->save();
            $removed = $this->readRow($id);
            if (!$this->rowMatches($removed, ['removed_at' => $now, 'removal_reason' => $reason,
                'removal_note' => $note, 'disposal_date' => $dDate, 'disposal_value' => $dValue,
                'disposed_to' => $dTo, 'updated_at' => $now])) {
                return $this->failTx();
            }
            $removedId = (int)$id;
        }

        if ($recordTreasury) {
            $desc = mb_substr('Sale of ' . $units . '× ' . $locked['name'] . ' (Inventory #' . $removedId . ')', 0, 255);
            $tres = Ork3::$Lib->treasury->SaveEntry($token, [
                'owner_type' => $this->normType($owner_type), 'owner_id' => (int)$owner_id,
                'direction' => 'credit', 'amount' => $dValue, 'category' => $tCat, 'payment_method' => $tMethod,
                'entry_date' => $dDate, 'description' => $desc, 'counterparty' => $dTo,
            ]);
            $tid = (int)($tres['Detail']['Id'] ?? 0);
            if (($tres['Status'] ?? 1) !== 0 || $tid <= 0) {
                $this->rollbackTx();
                return (($tres['Status'] ?? 1) !== 0) ? $tres : ProcessingError('The Treasury entry could not be saved.');
            }
            // Yapo sets the PK from lastInsertId even when the insert failed (on a partial removal that is
            // the inventory row's id), so re-read the entry and require every field this sale wrote.
            $DB->Clear();
            $trs = $DB->DataSet("SELECT * FROM " . DB_PREFIX . "treasury_entry WHERE id=$tid LIMIT 1");
            $trow = ($trs && $trs->Next()) ? $trs->CurrentFieldSet() : null;
            if (!$this->rowMatches($trow, ['owner_type' => $this->normType($owner_type), 'owner_id' => (int)$owner_id,
                'direction' => 'credit', 'amount' => $dValue, 'category' => $tCat, 'entry_date' => $dDate,
                'description' => $desc, 'created_by' => (int)$mundane_id, 'deleted_at' => null])) {
                return $this->failTx('The Treasury entry could not be saved.');
            }
            $DB->Clear();
            $DB->tid = $tid;
            $DB->Execute("UPDATE " . DB_PREFIX . "inventory_item SET treasury_entry_id = :tid WHERE id = $removedId");
            $removed = $this->readRow($removedId);
            if (!$this->rowMatches($removed, ['treasury_entry_id' => $tid])) { return $this->failTx(); }
        }

        $after = $src ? ['source' => $src, 'removed_row' => $removed, 'units' => $units] : $removed;
        if (!$this->writeAudit((int)$id, 'remove', $mundane_id, $before, $after)) { return $this->failTx(); }
        $this->commitTx();
        return Success(['Id' => $removedId]);
    }

    /** Split $units off an active item into a new, otherwise identical active row. */
    public function SplitItem($token, $owner_type, $owner_id, $id, $units)
    {
        $mundane_id = $this->authFor($token, $owner_type, $owner_id);
        if (!$mundane_id) { return NoAuthorization(); }
        if (!$this->loadOwnedItem($id, $owner_type, $owner_id)) { return InvalidParameter('Item not found.'); }
        if ($this->item->removed_at !== null) { return InvalidParameter('Item is removed; restore it before splitting.'); }
        $qty   = (int)$this->item->quantity;
        $units = (int)$units;
        if ($qty < 2) { return InvalidParameter('An item with a quantity of 1 cannot be split.'); }
        if ($units < 1 || $units > $qty - 1) { return InvalidParameter('Units to split off must be between 1 and ' . ($qty - 1) . '.'); }
        $before = $this->itemToArray();
        $now = date('Y-m-d H:i:s');

        $this->beginTx();
        $locked = $this->lockRow($id);
        if (!$locked || $locked['deleted_at'] !== null) { return $this->abortTx('Item not found.'); }
        if ($locked['removed_at'] !== null) { return $this->abortTx('Item is removed; restore it before splitting.'); }
        if ((int)$locked['quantity'] !== $qty) { return $this->abortTx($this->changedMsg($locked['quantity'])); }
        $src = $this->decrementQuantity($id, $qty, $units, $now);
        if (!$src) { return $this->failTx(); }
        $new = $this->insertCopy($src, $units, $mundane_id, $now);
        if (!$new) { return $this->failTx(); }
        $after = ['source' => $src, 'new_row' => $new, 'units' => $units];
        if (!$this->writeAudit((int)$id, 'split', $mundane_id, $before, $after)) { return $this->failTx(); }
        $this->commitTx();
        return Success(['Id' => (int)$new['id']]);
    }

    /** Un-remove: return a disposed item to active inventory and clear its disposal details.
     *  TreasuryEntryId in the response is the previously linked Treasury entry (0 if none) — it is
     *  NOT reversed here; the officer must adjust Treasury separately. */
    public function RestoreItem($token, $owner_type, $owner_id, $id)
    {
        global $DB;
        $mundane_id = $this->authFor($token, $owner_type, $owner_id);
        if (!$mundane_id) { return NoAuthorization(); }
        if (!$this->loadOwnedItem($id, $owner_type, $owner_id)) { return InvalidParameter('Item not found.'); }
        if ($this->item->removed_at === null) { return InvalidParameter('Item is not removed.'); }
        $before = $this->itemToArray();
        $now = date('Y-m-d H:i:s');
        $this->beginTx();
        $locked = $this->lockRow($id);
        if (!$locked || $locked['deleted_at'] !== null) { return $this->abortTx('Item not found.'); }
        if ($locked['removed_at'] === null) { return $this->abortTx('Item is not removed.'); }
        // yapo drops a `null` SET from UPDATE (isset() is false for null), so removed_at would
        // stay populated and the item never returns to active. Null it via a raw UPDATE; the
        // reason/note (NOT NULL columns) clear correctly with '' through yapo.
        $linkedTid = (int)$locked['treasury_entry_id'];
        $this->item->removal_reason = '';
        $this->item->removal_note   = '';
        $this->item->disposal_value = 0;
        $this->item->disposed_to    = '';
        $this->item->treasury_entry_id = 0;
        $this->item->updated_at     = $now;
        $this->item->save();
        $DB->Clear();
        $DB->Execute("UPDATE " . DB_PREFIX . "inventory_item SET removed_at = NULL, disposal_date = NULL WHERE id = " . (int)$id);
        $after = $this->readRow($id);
        if (!$this->rowMatches($after, ['removed_at' => null, 'removal_reason' => '', 'removal_note' => '',
            'disposal_date' => null, 'disposal_value' => 0, 'disposed_to' => '', 'treasury_entry_id' => 0,
            'updated_at' => $now])) {
            return $this->failTx();
        }
        if (!$this->writeAudit((int)$id, 'restore', $mundane_id, $before, $after)) { return $this->failTx(); }
        $this->commitTx();
        return Success(['Id' => (int)$id, 'TreasuryEntryId' => $linkedTid]);
    }

    /** Soft-delete (mis-entry correction): remove from all views. Requires a note; only for
     *  active items entered within the last 7 days — real disposals go through RemoveItem. */
    public function DeleteItem($token, $owner_type, $owner_id, $id, $note = '')
    {
        $mundane_id = $this->authFor($token, $owner_type, $owner_id);
        if (!$mundane_id) { return NoAuthorization(); }
        $note = trim((string)$note);
        if ($note === '') { return InvalidParameter('A note explaining why this record is being deleted is required.'); }
        $lenErr = $this->checkLengths(['delete_note' => $note]);
        if ($lenErr) { return $lenErr; }
        if (!$this->loadOwnedItem($id, $owner_type, $owner_id)) { return InvalidParameter('Item not found.'); }
        if ($this->item->removed_at !== null) {
            return InvalidParameter('Removed items cannot be deleted; restore first or keep the disposal record.');
        }
        // Age is judged by this row's own 'create' audit, not created_at: rows born from a split or
        // partial remove have no 'create' audit, so they can never be deleted (use Remove instead).
        global $DB;
        $cutoff = date('Y-m-d H:i:s', time() - 7 * 86400);
        $DB->Clear();
        $crs = $DB->DataSet("SELECT id FROM " . DB_PREFIX . "inventory_audit WHERE item_id=" . (int)$id
            . " AND action='create' AND changed_at >= '$cutoff' LIMIT 1");
        if (!$crs || !$crs->Next()) {
            return InvalidParameter('Only items added in the last 7 days can be deleted — use Remove from Inventory instead.');
        }
        $before = $this->itemToArray();
        $now = date('Y-m-d H:i:s');
        $this->beginTx();
        $locked = $this->lockRow($id);
        if (!$locked || $locked['deleted_at'] !== null) { return $this->abortTx('Item not found.'); }
        if ($locked['removed_at'] !== null) {
            return $this->abortTx('Removed items cannot be deleted; restore first or keep the disposal record.');
        }
        $this->item->deleted_at = $now;
        $this->item->updated_at = $now;
        $this->item->save();
        $after = $this->readRow($id);
        if (!$this->rowMatches($after, ['deleted_at' => $now, 'updated_at' => $now])) { return $this->failTx(); }
        $after['delete_note'] = $note;
        if (!$this->writeAudit((int)$id, 'delete', $mundane_id, $before, $after)) { return $this->failTx(); }
        $this->commitTx();
        return Success(['Id' => (int)$id]);
    }

    /** Reverse a soft-delete: bring a deleted item of this org back into view. */
    public function UndeleteItem($token, $owner_type, $owner_id, $id)
    {
        global $DB;
        $mundane_id = $this->authFor($token, $owner_type, $owner_id);
        if (!$mundane_id) { return NoAuthorization(); }
        if (!$this->loadOwnedItem($id, $owner_type, $owner_id, true)) { return InvalidParameter('Item not found.'); }
        if ($this->item->deleted_at === null) { return InvalidParameter('Item is not deleted.'); }
        $before = $this->itemToArray();
        $now = date('Y-m-d H:i:s');
        $this->beginTx();
        // yapo cannot SET NULL, so clear deleted_at with a raw UPDATE.
        $DB->Clear();
        $DB->now = $now;
        $DB->Execute("UPDATE " . DB_PREFIX . "inventory_item SET deleted_at = NULL, updated_at = :now WHERE id = " . (int)$id);
        $after = $this->readRow($id);
        if (!$this->rowMatches($after, ['deleted_at' => null, 'updated_at' => $now])) { return $this->failTx(); }
        if (!$this->writeAudit((int)$id, 'undelete', $mundane_id, $before, $after)) { return $this->failTx(); }
        $this->commitTx();
        return Success(['Id' => (int)$id]);
    }

    /** Count-sheet sign-off: stamp last_verified_at/by on ACTIVE items of this org. $ids (ints, max
     *  500) verifies those; only when $filters['by_filter'] === true are $ids ignored and every active
     *  item matching $filters verified (max 2000). Without by_filter, at least one valid id is required.
     *  One 'verify' audit row per item. Returns Success(['Count' => n]). */
    public function VerifyItems($token, $owner_type, $owner_id, $ids, $filters = [])
    {
        global $DB;
        $mundane_id = $this->authFor($token, $owner_type, $owner_id);
        if (!$mundane_id) { return NoAuthorization(); }
        $filters  = is_array($filters) ? $filters : [];
        $byFilter = ($filters['by_filter'] ?? false) === true;
        $ids = $byFilter ? [] : array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), function ($v) { return $v > 0; })));
        if (!$byFilter && !$ids) { return InvalidParameter('No items selected.'); }
        if (count($ids) > 500) { return InvalidParameter('Too many items selected (500 at most).'); }
        $where = $this->buildWhere($owner_type, $owner_id, $byFilter ? $filters : [])
            . " AND deleted_at IS NULL AND removed_at IS NULL";
        if ($ids) { $where .= " AND id IN (" . implode(',', $ids) . ")"; }
        $now = date('Y-m-d H:i:s');

        $this->beginTx();
        $DB->Clear();
        $rs = $DB->DataSet("SELECT id, last_verified_at FROM " . DB_PREFIX . "inventory_item WHERE $where ORDER BY id LIMIT 2001 FOR UPDATE");
        $old = [];
        while ($rs && $rs->Next()) { $old[(int)$rs->id] = $rs->last_verified_at; }
        if (count($old) > 2000) { return $this->abortTx('Too many items — narrow the filters.'); }
        if (!$old) { $this->rollbackTx(); return Success(['Count' => 0]); }
        $idList = implode(',', array_keys($old));
        $n = count($old);

        $DB->Clear();
        $DB->now = $now;
        $DB->by  = (int)$mundane_id;
        $DB->Execute("UPDATE " . DB_PREFIX . "inventory_item SET last_verified_at = :now, last_verified_by = :by
            WHERE id IN ($idList) AND deleted_at IS NULL AND removed_at IS NULL");
        $DB->Clear();
        $chk = $DB->DataSet("SELECT COUNT(*) AS n FROM " . DB_PREFIX . "inventory_item WHERE id IN ($idList)
            AND last_verified_at = '$now' AND last_verified_by = " . (int)$mundane_id);
        if (!$chk || !$chk->Next() || (int)$chk->n !== $n) { return $this->failTx(); }

        // Bulk audit insert (one row per item), then confirm every row landed (ids above the prior max, so
        // an earlier same-second verify of these items isn't counted).
        $DB->Clear();
        $mx = $DB->DataSet("SELECT COALESCE(MAX(id),0) AS mx FROM " . DB_PREFIX . "inventory_audit");
        $maxBefore = ($mx && $mx->Next()) ? (int)$mx->mx : 0;
        foreach (array_chunk($old, 250, true) as $chunk) {
            $vals = [];
            foreach ($chunk as $iid => $prev) {
                $vals[] = '(' . (int)$iid . ",'verify'," . (int)$mundane_id . ",'$now','"
                    . addslashes(json_encode(['last_verified_at' => $prev])) . "','"
                    . addslashes(json_encode(['last_verified_at' => $now])) . "')";
            }
            $DB->Clear();
            $DB->Execute("INSERT INTO " . DB_PREFIX . "inventory_audit (item_id, action, changed_by, changed_at, before_json, after_json)
                VALUES " . implode(',', $vals));
        }
        $DB->Clear();
        $ack = $DB->DataSet("SELECT COUNT(*) AS n FROM " . DB_PREFIX . "inventory_audit WHERE id > $maxBefore AND item_id IN ($idList)
            AND action = 'verify' AND changed_by = " . (int)$mundane_id . " AND changed_at = '$now'");
        if (!$ack || !$ack->Next() || (int)$ack->n !== $n) { return $this->failTx(); }
        $this->commitTx();
        return Success(['Count' => $n]);
    }

    /** Sorted distinct non-empty locations over this org's non-deleted items (filter/autocomplete). */
    public function GetLocations($token, $owner_type, $owner_id)
    {
        global $DB;
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        $owner_type = $this->normType($owner_type); $owner_id = (int)$owner_id;
        $DB->Clear();
        $rs = $DB->DataSet("SELECT DISTINCT location FROM " . DB_PREFIX . "inventory_item
            WHERE owner_type='$owner_type' AND owner_id=$owner_id AND deleted_at IS NULL AND location <> ''
            ORDER BY location ASC");
        $out = [];
        while ($rs && $rs->Next()) { $out[] = (string)$rs->location; }
        return Success(['Locations' => $out]);
    }

    private function decodeAuditJson($j)
    {
        if ($j === null || $j === '') { return null; }
        $d = json_decode($j, true);
        return is_array($d) ? $d : null;
    }

    /** Audit trail for one item of this org (deleted items included), oldest first. */
    public function GetItemHistory($token, $owner_type, $owner_id, $item_id)
    {
        global $DB;
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        $owner_type = $this->normType($owner_type); $owner_id = (int)$owner_id; $item_id = (int)$item_id;
        $DB->Clear();
        $chk = $DB->DataSet("SELECT id FROM " . DB_PREFIX . "inventory_item
            WHERE id=$item_id AND owner_type='$owner_type' AND owner_id=$owner_id LIMIT 1");
        if (!$chk || !$chk->Next()) { return InvalidParameter('Item not found.'); }

        $DB->Clear();
        $rs = $DB->DataSet("SELECT a.id, a.action, a.changed_at, a.changed_by, a.before_json, a.after_json,
            COALESCE(m.persona, '') AS persona
            FROM " . DB_PREFIX . "inventory_audit a
            LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a.changed_by
            WHERE a.item_id=$item_id ORDER BY a.changed_at ASC, a.id ASC");
        $rows = [];
        while ($rs && $rs->Next()) {
            $rows[] = [
                'Id'            => (int)$rs->id,
                'Action'        => $rs->action,
                'ChangedAt'     => $rs->changed_at,
                'ChangedBy'     => (int)$rs->changed_by,
                'ChangedByName' => (string)$rs->persona,
                'Before'        => $this->decodeAuditJson($rs->before_json),
                'After'         => $this->decodeAuditJson($rs->after_json),
            ];
        }
        return Success(['Rows' => $rows]);
    }

    /** Org-wide change log across all of this org's items (deleted included), newest first.
     *  $filters: action (one of $AUDIT_ACTIONS), page, per (1..200, default 50). */
    public function GetAudit($token, $owner_type, $owner_id, $filters = [])
    {
        global $DB;
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        $owner_type = $this->normType($owner_type); $owner_id = (int)$owner_id;
        $where = "i.owner_type='$owner_type' AND i.owner_id=$owner_id";
        $action = (string)($filters['action'] ?? '');
        if ($action !== '' && in_array($action, self::$AUDIT_ACTIONS, true)) { $where .= " AND a.action='$action'"; }

        $DB->Clear();
        $cnt = $DB->DataSet("SELECT COUNT(*) AS n FROM " . DB_PREFIX . "inventory_audit a
            JOIN " . DB_PREFIX . "inventory_item i ON i.id = a.item_id WHERE $where");
        $total = ($cnt && $cnt->Next()) ? (int)$cnt->n : 0;

        $per  = min(200, max(1, (int)($filters['per'] ?? 50)));
        $page = max(1, (int)($filters['page'] ?? 1));
        $off  = ($page - 1) * $per;

        $DB->Clear();
        $rs = $DB->DataSet("SELECT a.id, a.item_id, i.name AS item_name, a.action, a.changed_at, a.changed_by,
            a.before_json, a.after_json, COALESCE(m.persona, '') AS persona
            FROM " . DB_PREFIX . "inventory_audit a
            JOIN " . DB_PREFIX . "inventory_item i ON i.id = a.item_id
            LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a.changed_by
            WHERE $where ORDER BY a.changed_at DESC, a.id DESC LIMIT $off, $per");
        $rows = [];
        while ($rs && $rs->Next()) {
            $rows[] = [
                'Id'            => (int)$rs->id,
                'ItemId'        => (int)$rs->item_id,
                'ItemName'      => $rs->item_name,
                'Action'        => $rs->action,
                'ChangedAt'     => $rs->changed_at,
                'ChangedBy'     => (int)$rs->changed_by,
                'ChangedByName' => (string)$rs->persona,
                'Before'        => $this->decodeAuditJson($rs->before_json),
                'After'         => $this->decodeAuditJson($rs->after_json),
            ];
        }
        return Success(['Rows' => $rows, 'Total' => $total, 'Page' => $page, 'Per' => $per]);
    }

    /** Paged/filtered item list. $filters: category, condition, q, location, held_by_player_id, held_by,
     *  stale, status(active|removed|deleted|all), sort, dir, page, per. Active by default; 'all' = every non-deleted item (active + removed). */
    public function GetItems($token, $owner_type, $owner_id, $filters = [])
    {
        global $DB;
        if (!$this->authFor($token, $owner_type, $owner_id)) { return NoAuthorization(); }
        $owner_type = $this->normType($owner_type); $owner_id = (int)$owner_id;

        $status = $filters['status'] ?? 'active';
        if (!in_array($status, ['active', 'removed', 'deleted', 'all'], true)) { $status = 'active'; }
        $where  = $this->buildWhere($owner_type, $owner_id, $filters, 'i');
        $statusWhere = [
            'active'  => " AND i.deleted_at IS NULL AND i.removed_at IS NULL",
            'removed' => " AND i.deleted_at IS NULL AND i.removed_at IS NOT NULL",
            'deleted' => " AND i.deleted_at IS NOT NULL",
            'all'     => " AND i.deleted_at IS NULL",
        ];
        $where .= $statusWhere[$status];

        // Whitelist sortable columns; default name ASC.
        $sortMap = [
            'name' => 'i.name', 'category' => 'i.category', 'quantity' => 'i.quantity',
            'condition' => "FIELD(i.`condition`,'new','good','fair','poor','needs_repair')",
            'unit_value' => 'i.unit_value', 'total_value' => '(i.quantity*i.unit_value)',
            'location' => 'i.location', 'last_verified' => 'i.last_verified_at',
        ];
        $sortKey = $filters['sort'] ?? 'name';
        $sortCol = $sortMap[$sortKey] ?? 'i.name';
        $dir     = (strtolower($filters['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';

        $DB->Clear();
        $cnt = $DB->DataSet("SELECT COUNT(*) AS n FROM " . DB_PREFIX . "inventory_item i WHERE $where");
        $total = ($cnt && $cnt->Next()) ? (int)$cnt->n : 0;

        $per  = max(1, (int)($filters['per'] ?? 25));
        $page = max(1, (int)($filters['page'] ?? 1));
        $page = min($page, max(1, (int)ceil($total / $per)));
        $off  = ($page - 1) * $per;

        $DB->Clear();
        $rs = $DB->DataSet("SELECT i.id, i.name, i.category, i.quantity, i.`condition` AS cond,
            i.unit_value, i.location, i.held_by, i.held_by_player_id,
            i.acquired_date, i.notes, i.removed_at, i.removal_reason, i.removal_note, i.deleted_at,
            i.disposal_date, i.disposal_value, i.disposed_to, i.treasury_entry_id, i.last_verified_at,
            i.created_at, i.created_by, COALESCE(m.persona, '') AS created_by_name,
            COALESCE(v.persona, '') AS last_verified_by_name
            FROM " . DB_PREFIX . "inventory_item i
            LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = i.created_by
            LEFT JOIN " . DB_PREFIX . "mundane v ON v.mundane_id = i.last_verified_by AND i.last_verified_by > 0
            WHERE $where ORDER BY $sortCol $dir, i.id ASC LIMIT $off, $per");

        $rows = [];
        while ($rs && $rs->Next()) {
            // Display the held-by label exactly as stored (the persona/name the officer picked or
            // typed), mirroring Treasury's counterparty handling. held_by_player_id drives the
            // profile link; we do NOT re-resolve to the mundane legal name, which would replace the
            // selected persona (e.g. picked "Tobias" -> shown "Kirsten Ward").
            $heldName = (string)$rs->held_by;
            $rows[] = [
                'Id'             => (int)$rs->id,
                'Name'           => $rs->name,
                'Category'       => $rs->category,
                'CategoryLabel'  => self::$CATEGORIES[$rs->category] ?? $rs->category,
                'Quantity'       => (int)$rs->quantity,
                'Condition'      => $rs->cond,
                'ConditionLabel' => self::$CONDITION_LABELS[$rs->cond] ?? $rs->cond,
                'UnitValue'      => (float)$rs->unit_value,
                'TotalValue'     => round((float)$rs->unit_value * (int)$rs->quantity, 2),
                'BookValue'      => round((float)$rs->unit_value * (int)$rs->quantity, 2),
                'Location'       => $rs->location,
                'HeldBy'         => $heldName,
                'HeldByPlayerId' => (int)$rs->held_by_player_id,
                'AcquiredDate'   => $rs->acquired_date,
                'Notes'          => $rs->notes,
                'RemovedAt'      => $rs->removed_at,
                'RemovalReason'  => $rs->removal_reason,
                'RemovalReasonLabel' => $rs->removal_reason !== '' ? (self::$REMOVAL_REASONS[$rs->removal_reason] ?? $rs->removal_reason) : '',
                'RemovalNote'    => $rs->removal_note,
                'DisposalDate'   => $rs->disposal_date,
                'DisposalValue'  => (float)$rs->disposal_value,
                'DisposedTo'     => $rs->disposed_to,
                'TreasuryEntryId' => (int)$rs->treasury_entry_id,
                'LastVerifiedAt' => $rs->last_verified_at,
                'LastVerifiedByName' => (string)$rs->last_verified_by_name,
                'DeletedAt'      => $rs->deleted_at,
                'CreatedAt'      => $rs->created_at,
                'CreatedBy'      => (int)$rs->created_by,
                'CreatedByName'  => (string)$rs->created_by_name,
            ];
        }
        return Success(['Rows' => $rows, 'Total' => $total, 'Page' => $page, 'Per' => $per, 'Status' => $status]);
    }
}
