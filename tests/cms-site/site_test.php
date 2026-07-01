<?php

// tests/cms-site/site_test.php — run: php tests/cms-site/site_test.php
//
// Unit coverage for CmsSite slug derivation/validation (charset, reserved
// words, uniqueness) and EnsureSite idempotency. Follows the same plain-PHP
// check() harness as tests/cms-theme/tokens_test.php.
//
// CmsSite extends CmsBase extends Ork3 and talks to a shared global $DB
// (YapoDb). The framework is not bootstrapped in a bare `php` run, so we stub
// the minimum surface CmsSite touches: an empty Ork3 base, DB_PREFIX, and a
// programmable fake $DB whose DataSet() results are driven from a FIFO queue.
// This keeps the pure logic honest while letting the DB-backed branches
// (uniqueness, EnsureSite) run deterministically without a container.

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'ork_');
}

// Minimal base so `class CmsBase extends Ork3` loads without the framework.
class Ork3
{
    public function __construct()
    {
    }
}

// Fake YapoDb result: CurrentFieldSet() + Next(), matching CmsBase::_eachRow.
class FakeResult
{
    private $rows;
    private $i = 0;
    public function __construct($rows)
    {
        $this->rows = array_values($rows);
    }
    public function CurrentFieldSet()
    {
        return isset($this->rows[$this->i]) ? $this->rows[$this->i] : array();
    }
    public function Next()
    {
        $this->i++;
        return isset($this->rows[$this->i]);
    }
}

// Fake YapoDb: records binds + executed statements; DataSet() shifts one
// pre-loaded row-set off $queue per call.
class FakeDB
{
    public $binds = array();
    public $queue = array();     // FIFO: each entry is the row list one DataSet() returns
    public $executed = array();  // list of executed SQL strings

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
        $rows = count($this->queue) ? array_shift($this->queue) : array();
        return new FakeResult($rows);
    }
    public function Execute($sql)
    {
        $this->executed[] = $sql;
        return true;
    }
}

$GLOBALS['DB'] = new FakeDB();
$DB = &$GLOBALS['DB'];

require __DIR__ . '/../../system/lib/ork3/class.CmsBase.php';
require __DIR__ . '/../../system/lib/ork3/class.CmsSite.php';

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

/** Count executed INSERT statements so idempotency is observable. */
function insertCount($db)
{
    $n = 0;
    foreach ($db->executed as $sql) {
        if (stripos($sql, 'INSERT INTO') !== false) {
            $n++;
        }
    }
    return $n;
}

$site = new CmsSite();

// --- DeriveSlug (pure) ---
check('derive lowercases + hyphenates', $site->DeriveSlug('Kingdom of the Burning Lands') === 'kingdom-of-the-burning-lands');
check('derive collapses runs + strips punctuation', $site->DeriveSlug('  Foo & Bar!!  Baz  ') === 'foo-bar-baz');
check('derive trims leading/trailing hyphens', $site->DeriveSlug('--Neverwinter--') === 'neverwinter');
check('derive drops accented/non-ascii to hyphen', $site->DeriveSlug('Créconom') === 'cr-conom');
check('derive empty stays empty', $site->DeriveSlug('   ') === '');

// --- ValidateSlug: charset (pure, returns before DB) ---
check('reject uppercase', is_string($site->ValidateSlug('Foo')));
check('reject spaces', is_string($site->ValidateSlug('foo bar')));
check('reject underscores', is_string($site->ValidateSlug('foo_bar')));
check('reject leading hyphen', is_string($site->ValidateSlug('-foo')));
check('reject trailing hyphen', is_string($site->ValidateSlug('foo-')));
check('reject empty', is_string($site->ValidateSlug('')));

// --- ValidateSlug: reserved words (pure) ---
check('reserved: kingdom', is_string($site->ValidateSlug('kingdom')));
check('reserved: cms', is_string($site->ValidateSlug('cms')));
check('reserved: cmsajax', is_string($site->ValidateSlug('cmsajax')));
check('reserved: blog', is_string($site->ValidateSlug('blog')));
check('reserved: page', is_string($site->ValidateSlug('page')));
check('reserved: directory', is_string($site->ValidateSlug('directory')));
check('reserved: admin', is_string($site->ValidateSlug('admin')));
check('reserved: login', is_string($site->ValidateSlug('login')));
check('reserved: k prefix', is_string($site->ValidateSlug('k')));
check('reserved: p prefix', is_string($site->ValidateSlug('p')));
check('reserved: site', is_string($site->ValidateSlug('site')));
check('reserved: tournament', is_string($site->ValidateSlug('tournament')));
// a non-reserved slug that merely CONTAINS a reserved word is fine
check('kingdom-of-foo not reserved (unique, queue empty)', (function () use ($site) {
    global $DB;
    $DB->queue = array(array());   // uniqueness query -> no rows
    return $site->ValidateSlug('kingdom-of-foo') === true;
})());

// --- ValidateSlug: uniqueness (DB-backed via fake) ---
$DB->queue = array(array());                       // no matching row
check('unique slug accepted', $site->ValidateSlug('burning-lands') === true);

$DB->queue = array(array(array('site_id' => 5)));  // a collision row
check('duplicate slug rejected', is_string($site->ValidateSlug('taken-slug')));

// The "same site" exclusion (site_id != :except_id) is enforced in SQL, so the
// non-filtering fake returns what the real DB would AFTER filtering: no rows.
// Here we assert the except id is threaded into the bind and an empty (already
// self-filtered) result yields true. The filter's SQL semantics are covered by
// the integration checklist.
$DB->queue = array(array());                       // DB filtered self-row out -> no rows
$selfOk = $site->ValidateSlug('taken-slug', 5);
check('except-self: empty (filtered) result yields true', $selfOk === true);
check('except-self: except_id bound to the caller-supplied site id', (int)$DB->binds['except_id'] === 5);

// --- EnsureSite idempotency ---
// Fresh org: GetSiteForScope (empty) -> _uniqueSlug ValidateSlug (empty) ->
// INSERT -> readback (returns the new row).
$DB->executed = array();
$newRow = array('site_id' => 42, 'scope_type' => 'kingdom', 'scope_id' => 7, 'slug' => 'kingdom-7', 'status' => 'unbuilt');
$DB->queue = array(
    array(),               // GetSiteForScope -> none
    array(),               // ValidateSlug uniqueness -> unique
    array($newRow),        // readback after INSERT
);
$created = $site->EnsureSite('kingdom', 7, 99);
check('EnsureSite creates when absent (returns row)', is_array($created) && (int)$created['site_id'] === 42);
check('EnsureSite performs exactly one INSERT on create', insertCount($DB) === 1);
check('EnsureSite INSERTs status=unbuilt', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'INSERT INTO') !== false && stripos($sql, "'unbuilt'") !== false) {
            return true;
        }
    }
    return false;
})());

// Second call for the same org: GetSiteForScope returns the existing row ->
// early return, NO further INSERT.
$DB->executed = array();
$DB->queue = array(array($newRow));   // GetSiteForScope -> existing
$again = $site->EnsureSite('kingdom', 7, 99);
check('EnsureSite idempotent (returns existing row)', is_array($again) && (int)$again['site_id'] === 42);
check('EnsureSite performs NO INSERT when present', insertCount($DB) === 0);

// --- EnsureSite refuses an unresolved scope (no junk (kingdom,0) row) ---
$DB->executed = array();
$DB->queue = array();
check('EnsureSite returns null for scope_id 0', $site->EnsureSite('kingdom', 0, 99) === null);
check('EnsureSite performs NO INSERT for scope_id 0', insertCount($DB) === 0);
check('EnsureSite returns null for negative scope_id', $site->EnsureSite('kingdom', -3, 99) === null);

// --- UpdateSite normalizes a typed slug via DeriveSlug (no silent mangling) ---
$DB->executed = array();
$DB->queue = array(array());   // ValidateSlug uniqueness -> unique
$upd = $site->UpdateSite(42, array('slug' => 'My Kingdom'), 99);
check('UpdateSite accepts a spaced name, hyphenating it', $upd === true);
check('UpdateSite stores the hyphenated slug (my-kingdom)', $DB->binds['slug'] === 'my-kingdom');
check('UpdateSite executed an UPDATE', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'UPDATE') !== false) {
            return true;
        }
    }
    return false;
})());

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
