<?php

// tests/cms-tenancy/tenancy_test.php — run: php tests/cms-tenancy/tenancy_test.php
//
// Coverage for the MULTI-TENANT boundary in CmsMedia: the code where a bug is not
// a cosmetic defect but one kingdom reaching another kingdom's data, or one org
// exhausting shared storage for everyone. Three adversarial review passes read
// this code and could not break it; nothing had ever executed it. This file does.
//
// Under test:
//   - FilterOwnedIds  — the scope filter behind bulk media delete. Must drop ids
//                       the caller does not own, never trust a client-supplied id
//                       as SQL, and fail CLOSED rather than open.
//   - ScopeUsageBytes — the per-org storage meter. Must count LIVE rows only and
//                       must call Next() before reading (YapoResultSet starts
//                       unfetched; forgetting it silently yields 0, which would
//                       make every quota check pass).
//   - Upload quota    — must reject before any decode or file write, and must
//                       report WHY so the caller can tell "out of space" from
//                       "not an image".
//
// Same plain-PHP check() harness as the sibling suites. CmsMedia extends CmsBase
// extends Ork3 and talks to a shared global $DB, none of which is bootstrapped in
// a bare `php` run, so the minimum surface is stubbed below.

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'ork_');
}

class Ork3
{
    public function __construct()
    {
    }
}

/**
 * Faithful stand-in for YapoResultSet — deliberately NOT the looser fake used by
 * tests/cms-site/site_test.php.
 *
 * The real cursor starts BEFORE the first row: CurrentFieldSet() is null until a
 * Next() fetches something, and Next() both advances and populates the field
 * properties. Modelling that exactly is the entire point here — a stub that
 * pre-positions on row 0 would let a ScopeUsageBytes() that forgot its Next()
 * pass the test while returning 0 in production, which is precisely the failure
 * this suite exists to catch.
 */
#[AllowDynamicProperties]
class FakeResultSet
{
    private $rows;
    private $i = -1;
    private $fieldset = null;

    public function __construct($rows)
    {
        $this->rows = array_values($rows);
    }

    public function Next()
    {
        $this->i++;
        if (!isset($this->rows[$this->i])) {
            return false;
        }
        $this->fieldset = $this->rows[$this->i];
        foreach ($this->rows[$this->i] as $k => $v) {
            $this->$k = $v;
        }
        return true;
    }

    public function CurrentFieldSet()
    {
        return $this->fieldset;
    }
}

class FakeDB
{
    public $binds    = array();
    public $queue    = array();   // FIFO: one row-list per DataSet() call
    public $sql      = array();   // every SQL string passed to DataSet()
    public $bindLog  = array();   // binds captured at each DataSet() call

    public function Clear()
    {
        $this->binds = array();
    }
    public function __set($k, $v)
    {
        $this->binds[$k] = $v;
    }
    public function __get($k)
    {
        return isset($this->binds[$k]) ? $this->binds[$k] : null;
    }
    public function DataSet($sql)
    {
        $this->sql[]     = $sql;
        $this->bindLog[] = $this->binds;
        $rows = count($this->queue) ? array_shift($this->queue) : array();
        return new FakeResultSet($rows);
    }
    public function Execute($sql)
    {
        $this->sql[] = $sql;
        return true;
    }
}

$GLOBALS['DB'] = new FakeDB();
$DB = &$GLOBALS['DB'];

require __DIR__ . '/../../system/lib/ork3/class.CmsBase.php';
require __DIR__ . '/../../system/lib/ork3/class.CmsMedia.php';

$fails = 0;
function check($label, $cond)
{
    global $fails;
    if ($cond) {
        echo "PASS  $label\n";
    } else {
        echo "FAIL  $label\n";
        $fails++;
    }
}

/** Reset the fake between cases so one case's binds can't satisfy the next. */
function freshDb()
{
    $GLOBALS['DB'] = new FakeDB();
    return $GLOBALS['DB'];
}

$media = new CmsMedia();

// ---------------------------------------------------------------------------
// FilterOwnedIds — the cross-tenant filter behind bulk delete.
// ---------------------------------------------------------------------------

// The DB answers with only the ids that really belong to the scope; anything the
// caller asked for that is missing from that answer must be dropped.
$db = freshDb();
$db->queue = array(array(array('media_id' => 5), array('media_id' => 9)));
$out = $media->FilterOwnedIds(array(5, 7, 9, 11), 'kingdom', 17);
check('FilterOwnedIds drops ids the scope does not own', $out === array(5, 9));

$db = freshDb();
$db->queue = array(array(array('media_id' => 9), array('media_id' => 5)));
$out = $media->FilterOwnedIds(array(5, 9), 'kingdom', 17);
check('FilterOwnedIds preserves the caller ordering, not the DB ordering', $out === array(5, 9));

// Scope must be BOUND, never concatenated, and the live-row filter must be present
// or a soft-deleted image from another org could be resurrected through bulk ops.
$db = freshDb();
$db->queue = array(array());
$media->FilterOwnedIds(array(1, 2), 'park', 3);
$sql = isset($db->sql[0]) ? $db->sql[0] : '';
check('FilterOwnedIds binds scope_type/scope_id (no concatenation)', strpos($sql, ':scope_type') !== false && strpos($sql, ':scope_id') !== false);
check('FilterOwnedIds restricts to live rows (deleted_at IS NULL)', strpos($sql, 'deleted_at IS NULL') !== false);
check('FilterOwnedIds passes the scope through to the binds', ($db->bindLog[0]['scope_type'] ?? null) === 'park' && (int)($db->bindLog[0]['scope_id'] ?? 0) === 3);

// Client-supplied ids are interpolated into an IN() list, so they must be proven
// numeric before they get there. mysql_real_escape_string() is a no-op shim in
// this codebase — an id that survived as text would be a straight injection.
$db = freshDb();
$db->queue = array(array());
$media->FilterOwnedIds(array('5', '7 OR 1=1', 'DROP TABLE', '9'), 'kingdom', 17);
$sql = isset($db->sql[0]) ? $db->sql[0] : '';
check('FilterOwnedIds never lets a non-numeric id reach the SQL', strpos($sql, 'OR 1=1') === false && stripos($sql, 'DROP TABLE') === false);

// An all-garbage list must not produce a query at all.
$db = freshDb();
$out = $media->FilterOwnedIds(array('abc', '', null), 'kingdom', 17);
check('FilterOwnedIds returns empty (and issues no query) for a list with no valid ids', $out === array() && count($db->sql) === 0);

// Over the cap it must fail CLOSED — deny everything — not fall back to allowing
// the list through unfiltered.
$db = freshDb();
$tooMany = range(1, CmsMedia::MAX_FILTER_IDS + 1);
$out = $media->FilterOwnedIds($tooMany, 'kingdom', 17);
check('FilterOwnedIds fails CLOSED above MAX_FILTER_IDS', $out === array() && count($db->sql) === 0);

// Exactly at the cap is still allowed — an off-by-one here would deny a legitimate
// max-size selection.
$db = freshDb();
$db->queue = array(array(array('media_id' => 1)));
$out = $media->FilterOwnedIds(range(1, CmsMedia::MAX_FILTER_IDS), 'kingdom', 17);
check('FilterOwnedIds allows exactly MAX_FILTER_IDS', $out === array(1));

// ---------------------------------------------------------------------------
// ScopeUsageBytes — the storage meter behind the quota.
// ---------------------------------------------------------------------------

$db = freshDb();
$db->queue = array(array(array('used' => 1048576)));
check('ScopeUsageBytes returns the summed byte count', $media->ScopeUsageBytes('kingdom', 17) === 1048576);

// The empty-result path must be 0, not null — it is fed straight into arithmetic.
$db = freshDb();
$db->queue = array(array());
$u = $media->ScopeUsageBytes('kingdom', 17);
check('ScopeUsageBytes returns int 0 when the scope has no media', $u === 0);

$db = freshDb();
$db->queue = array(array(array('used' => 5)));
$media->ScopeUsageBytes('park', 3);
$sql = isset($db->sql[0]) ? $db->sql[0] : '';
check('ScopeUsageBytes counts LIVE rows only (deleted_at IS NULL)', strpos($sql, 'deleted_at IS NULL') !== false);
check('ScopeUsageBytes sums the bytes column', stripos($sql, 'SUM(bytes)') !== false);
check('ScopeUsageBytes binds its scope', ($db->bindLog[0]['scope_type'] ?? null) === 'park' && (int)($db->bindLog[0]['scope_id'] ?? 0) === 3);

// Global is the Amtgard site itself, not a tenant — it is deliberately unmetered.
check('ScopeQuotaBytes leaves global unmetered', $media->ScopeQuotaBytes('global') === 0);
check('ScopeQuotaBytes meters kingdom scope', $media->ScopeQuotaBytes('kingdom') === CmsMedia::MAX_SCOPE_BYTES);
check('ScopeQuotaBytes meters park scope', $media->ScopeQuotaBytes('park') === CmsMedia::MAX_SCOPE_BYTES);

// ---------------------------------------------------------------------------
// Upload quota gate. The check runs BEFORE image validation and before any file
// is written, so a payload that is not even an image is still rejected on quota
// first — which is exactly what makes this testable without GD or a filesystem.
// ---------------------------------------------------------------------------

$payload = base64_encode(str_repeat('x', 1024));

$db = freshDb();
$db->queue = array(array(array('used' => CmsMedia::MAX_SCOPE_BYTES)));
$r = $media->Upload($payload, 'a.png', '', 1, array('type' => 'kingdom', 'id' => 17));
check('Upload rejects when the org is already at quota', $r === null);
check('Upload reports quota_exceeded (not a generic failure)', $media->LastError === 'quota_exceeded');
check('Upload rejects on quota BEFORE writing anything', count($db->sql) === 1);

// Under quota, the quota gate must let it through — it then fails later on image
// validation, which is a DIFFERENT error and must not be reported as a quota problem.
$db = freshDb();
$db->queue = array(array(array('used' => 0)));
$r = $media->Upload($payload, 'a.png', '', 1, array('type' => 'kingdom', 'id' => 17));
check('Upload under quota passes the quota gate', $r === null && $media->LastError !== 'quota_exceeded');

// A stale LastError from a previous rejection must not be reported for a later,
// unrelated failure in the same request.
$db = freshDb();
$db->queue = array(array(array('used' => CmsMedia::MAX_SCOPE_BYTES)));
$media->Upload($payload, 'a.png', '', 1, array('type' => 'kingdom', 'id' => 17));
$db = freshDb();
$media->Upload('', 'a.png', '', 1, array('type' => 'kingdom', 'id' => 17));
check('Upload resets LastError between calls', $media->LastError !== 'quota_exceeded');

// Global scope is exempt: it must not even ask the meter.
$db = freshDb();
$media->Upload($payload, 'a.png', '', 1, array('type' => 'global', 'id' => 0));
check('Upload does not meter global scope', count($db->sql) === 0);

// An oversized payload is a size failure, not a quota failure.
$db = freshDb();
$big = base64_encode(str_repeat('x', 9 * 1024 * 1024));
$r = $media->Upload($big, 'a.png', '', 1, array('type' => 'kingdom', 'id' => 17));
check('Upload reports too_large distinctly from quota_exceeded', $r === null && $media->LastError === 'too_large');

echo "\n" . ($fails === 0 ? 'ALL PASS' : "$fails FAILED") . "\n";
exit($fails === 0 ? 0 : 1);
