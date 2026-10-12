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
// The rollout gate (CanCreateSite -> KingdomSitesEnabled) reads ork_configuration
// through CFG_SERVICE, which common.php defines and a bare `php` run does not.
if (!defined('CFG_SERVICE')) {
    define('CFG_SERVICE', 'Service');
}
// UIR is the route prefix the seeded CTA hrefs are built on. Defined here rather
// than left to _sitePageHref()'s own fallback so the seeded hrefs in this run
// read exactly as they do in the app.
if (!defined('UIR')) {
    define('UIR', 'index.php?Route=');
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
    public $execBinds = array(); // binds as they stood at each Execute() (binds are Clear()ed after)

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
        // A queue entry may be a CLOSURE instead of a fixed row list, so a read
        // can answer with what an earlier write in the SAME call actually bound.
        // ReplaceBlocks() verifies its own write by reading the rows back inside
        // its transaction and ROLLBACKs on a mismatch; a fixed fixture could only
        // model that by hand-restating every byte of the intended row.
        if ($rows instanceof Closure) {
            $rows = call_user_func($rows, $this->binds, $this);
        }
        return new FakeResult($rows);
    }
    public function Execute($sql)
    {
        $this->executed[]  = $sql;
        $this->execBinds[] = $this->binds;
        return true;
    }
}

$GLOBALS['DB'] = new FakeDB();
$DB = &$GLOBALS['DB'];

// _seedOrgTheme() (below) probes a heraldry file path off DIR_HERALDRY, and
// _heraldryPath() really does stat the filesystem, so point the constant at a
// throwaway tree we control and seed it with files named EXACTLY the way the
// real asset store names them (see the pad-width test further down). Torn down
// at the end of the run.
$heraldryFixtureDir = sys_get_temp_dir() . '/ogre-site-test-heraldry-' . getmypid();
@mkdir($heraldryFixtureDir . '/kingdom', 0777, true);
@mkdir($heraldryFixtureDir . '/park', 0777, true);
if (!defined('DIR_HERALDRY')) {
    define('DIR_HERALDRY', $heraldryFixtureDir);
}

require __DIR__ . '/../../system/lib/ork3/class.CmsBase.php';
// The starter registry sanitizes its authored bodies, merges the block
// registry's starter_fields, and seeds the kingdom_parks block's own ceiling —
// all three are real collaborators of the seed, so load them rather than let the
// registry silently fall through its class_exists() guards to a different answer
// than the app would produce. (CmsRenderCache arrives with CmsBase above, and
// class.CmsSite.php require_once's class.CmsStarterContent.php itself.)
require __DIR__ . '/../../system/lib/ork3/class.CmsSanitizer.php';
require __DIR__ . '/../../system/lib/ork3/class.CmsBlockRegistry.php';
require __DIR__ . '/../../system/lib/ork3/class.CmsSite.php';
// BackfillSeedContent() reads through CmsPage::GetBlocksForEditor() and writes
// through CmsPage::ReplaceBlocks() — the storage choke point that owns the
// sanitize pass, the page-cache bust, the owner stamp and the revision
// snapshot. It is a real collaborator of the runner, not a stub.
require __DIR__ . '/../../system/lib/ork3/class.CmsPage.php';
// _seedOrgTheme() consumes both of these — CmsTheme pulls in CmsThemeTokens
// itself via its own require_once.
require __DIR__ . '/../../system/lib/ork3/class.CmsHeraldryColor.php';
require __DIR__ . '/../../system/lib/ork3/class.CmsTheme.php';
// _heraldryPath() reads its zero-pad widths from Heraldry::PAD_LENGTHS rather
// than re-typing them. Only the static side is touched, so the class loads fine
// against the stub Ork3 above without the framework.
require __DIR__ . '/../../system/lib/ork3/class.Heraldry.php';

/**
 * A CmsSite whose CURRENT starter-content version really does differ from
 * version 0 — the seam the backfill's safety property can actually be tested
 * through.
 *
 * Every SHIPPED version builds identical copy right now (_parkV1 delegates to
 * _parkV0), so a backfill test written against the real versions compares each
 * field to itself: the write loop can never fire, and "it left the officer's
 * words alone" passes whether the byte-match gate exists or not. This double
 * overrides the one protected seam CmsSite exposes for it and rewrites named
 * fields in versions >= 1 ONLY, leaving version 0 as the genuine historical
 * copy the runner re-derives and compares against.
 */
class VersionedCmsSite extends CmsSite
{
    /** @var array "pageSlug|blockType|order" => [field => new value] */
    public $overrides = array();

    protected function _starterPageDefs($scopeType, $scopeId, $orgName = null, $version = null)
    {
        $registry = parent::_starterPageDefs($scopeType, $scopeId, $orgName, $version);
        $v = ($version === null) ? self::CURRENT_SEED_VERSION : (int) $version;
        if ($v < 1 || empty($this->overrides)) {
            return $registry;
        }
        foreach ($registry as $slug => $def) {
            foreach ($def['blocks'] as $i => $block) {
                $key = $slug . '|' . $block['type'] . '|' . (int) $block['order'];
                if (!isset($this->overrides[$key])) {
                    continue;
                }
                foreach ($this->overrides[$key] as $field => $value) {
                    $registry[$slug]['blocks'][$i]['fields'][$field] = $value;
                }
            }
        }
        return $registry;
    }
}

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
check('derive transliterates accents deterministically', $site->DeriveSlug('Créconom') === 'creconom');
check('derive transliterates ash (AE)', $site->DeriveSlug('Ælfwine') === 'aelfwine');
check('derive transliterates thorn (TH)', $site->DeriveSlug('Þorvald') === 'thorvald');
check('derive transliterates slashed O', $site->DeriveSlug('Øresund') === 'oresund');
check('derive transliterates eszett (ss)', $site->DeriveSlug('Straße') === 'strasse');
// Decomposed (NFD) input must land on the SAME slug as the precomposed form —
// a macOS paste stores "e"+U+0301, and the byte encoding of a kingdom's name
// must not change its public URL. Written as explicit bytes so the assertion
// cannot be silently normalized by an editor.
check(
    'derive converges NFD and NFC on one slug',
    $site->DeriveSlug("Cre\xcc\x81conom") === 'creconom'
        && $site->DeriveSlug("Cr\xc3\xa9conom") === 'creconom'
);
check('derive handles NFD ring + umlaut', $site->DeriveSlug("A\xcc\x8angstro\xcc\x88m") === 'angstrom');
check('derive empty stays empty', $site->DeriveSlug('   ') === '');

// An apostrophe sits INSIDE a word, so the generic punctuation sweep would cut
// the word in half ("Angler's Rift" -> "angler-s-rift"), burying a bare "s" in a
// public URL. 139 parks on this instance have one in their name. Written as
// explicit bytes for the non-ASCII spellings so an editor cannot silently
// normalize the assertion into the ASCII one and hide a regression.
check('derive deletes an ASCII apostrophe, not hyphenates it', $site->DeriveSlug("Angler's Rift") === 'anglers-rift');
check(
    'derive converges every apostrophe spelling on one slug',
    $site->DeriveSlug("Angler's Rift") === 'anglers-rift'                 // U+0027 ASCII
        && $site->DeriveSlug("Angler\xe2\x80\x99s Rift") === 'anglers-rift' // U+2019 curly (Word/iOS)
        && $site->DeriveSlug("Angler\xca\xbcs Rift") === 'anglers-rift'     // U+02BC modifier letter
);
check('derive handles a trailing-plural apostrophe', $site->DeriveSlug("Angels' Dusk") === 'angels-dusk');
check('derive still hyphenates punctuation that separates words', $site->DeriveSlug('Rock & Roll') === 'rock-roll');
check('derive of apostrophes alone is empty, not a hyphen run', $site->DeriveSlug("'''") === '');

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
// The ROLLOUT GATE runs first and fails CLOSED: EnsureSite() -> CanCreateSite()
// -> KingdomSitesEnabled() -> _configFlag() reads ork_configuration, and an
// absent row means "kingdoms may not build sites", so EnsureSite returns null
// before it ever reaches the mint branch. Every EnsureSite fixture below must
// therefore feed that read first, or it asserts against a null that never
// exercised the code the check is named after.
$sitesEnabled = array(array('value' => '1')); // Service/0/CmsKingdomSitesEnabled = on

// Fresh org: rollout gate (on) -> GetSiteForScope (empty) -> _uniqueSlug
// ValidateSlug (empty) -> INSERT -> readback (returns the new row).
$DB->executed = array();
$newRow = array('site_id' => 42, 'scope_type' => 'kingdom', 'scope_id' => 7, 'slug' => 'kingdom-7', 'status' => 'unbuilt');
$DB->queue = array(
    $sitesEnabled,         // CanCreateSite -> KingdomSitesEnabled
    array(),               // GetSiteForScope -> none
    array(),               // ValidateSlug uniqueness -> unique
    array($newRow),        // readback after INSERT ($created)
    array($newRow),        // re-read after starter-template seed (home_page_id refresh)
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
$DB->queue = array($sitesEnabled, array($newRow));   // rollout gate, then GetSiteForScope -> existing
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
$DB->executed  = array();
$DB->execBinds = array();
// Three reads, in call order: the pre-write oldSlug capture, ValidateSlug's
// uniqueness probe (empty -> unique), and the post-write read-back that verifies
// the slug actually landed (must echo the stored value back).
$DB->queue = array(array(), array(), array(array('slug' => 'my-kingdom')));
$upd = $site->UpdateSite(42, array('slug' => 'My Kingdom'), 99);
check('UpdateSite accepts a spaced name, hyphenating it', $upd === true);
// The read-back Clear()s $DB->binds, so assert on the binds recorded at the
// moment the UPDATE was executed rather than on whatever survives afterwards.
check('UpdateSite stores the hyphenated slug (my-kingdom)', (function () use ($DB) {
    foreach ($DB->executed as $i => $sql) {
        if (stripos($sql, 'UPDATE') !== false) {
            return isset($DB->execBinds[$i]['slug']) && $DB->execBinds[$i]['slug'] === 'my-kingdom';
        }
    }
    return false;
})());
check('UpdateSite executed an UPDATE', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'UPDATE') !== false) {
            return true;
        }
    }
    return false;
})());

// --- Starter template is SCOPE-AWARE -------------------------------------
// A park site used to be seeded with the kingdom template verbatim: kingdom_*
// dynamic blocks (which correctly render NOTHING outside a kingdom scope), an
// "Our Parks" page for an org with no parks, and copy calling the park a
// kingdom. The blocks and the Add-block chooser were already scope-correct, so
// the only thing wrong was this registry — and the failure was silent.
// setAccessible() is a no-op (and deprecated) on PHP 8.1+; the reflection
// handle alone is enough to invoke a private method.
$defs = new ReflectionMethod('CmsSite', '_starterPageDefs');

/** Flatten every block type a starter registry would seed. */
// A registry entry may be NAV-ONLY (the News item, which links the org's
// existing blog route and has no page of its own), so 'blocks' is optional.
$blockTypes = function ($registry) {
    $out = array();
    foreach ($registry as $def) {
        foreach ((isset($def['blocks']) ? $def['blocks'] : array()) as $b) {
            $out[] = $b['type'];
        }
    }
    return $out;
};
/** Every string value anywhere in the registry, for copy assertions. */
$allCopy = function ($registry) {
    $flat = '';
    array_walk_recursive($registry, function ($v) use (&$flat) {
        if (is_string($v)) {
            $flat .= ' ' . $v;
        }
    });
    return $flat;
};

// OrgUnitNoun() hits the DB for kingdom scope (parent_kingdom_id lookup).
// Queue a no-parent row so the kingdom registry resolves the noun "Kingdom".
$DB->queue = array(array(array('parent_kingdom_id' => 0)));
$kingdomDefs  = $defs->invoke($site, 'kingdom', 7);
$kingdomTypes = $blockTypes($kingdomDefs);

$parkDefs  = $defs->invoke($site, 'park', 1049);
$parkTypes = $blockTypes($parkDefs);

check('kingdom starter seeds the parks page', isset($kingdomDefs['parks']));
check('kingdom starter seeds kingdom_officers', in_array('kingdom_officers', $kingdomTypes, true));
check('kingdom starter seeds kingdom_events', in_array('kingdom_events', $kingdomTypes, true));
check('kingdom starter seeds kingdom_parks + map', in_array('kingdom_parks', $kingdomTypes, true)
    && in_array('kingdom_parks_map', $kingdomTypes, true));

check('park starter seeds NO kingdom_* block at all', count(array_filter(
    $parkTypes,
    function ($t) {
        return strpos($t, 'kingdom_') === 0;
    }
)) === 0);
check('park starter drops the "Our Parks" page', !isset($parkDefs['parks']));
check('park starter seeds park_meeting', in_array('park_meeting', $parkTypes, true));
check('park starter seeds park_officers', in_array('park_officers', $parkTypes, true));
check('park starter seeds park_events', in_array('park_events', $parkTypes, true));

$parkCopy = $allCopy($parkDefs);
check('park starter copy never calls the park a kingdom', stripos($parkCopy, 'kingdom') === false);
// Task 8 rewrite: the park template is now its own bespoke three-page design
// (home / new-players / contact), not a $noun-templated trim of the kingdom
// copy — so the old literal "Welcome to Our Park" string this check looked
// for no longer exists anywhere in the seed. The check's actual intent (the
// seeded copy is contextually about a PARK, not a generic org) still holds
// and is asserted here against the new copy instead of silently dropped.
check('park starter copy is park-aware (mentions "park")', stripos($parkCopy, 'park') !== false);

// Seeded pages must NOT open with a heading block repeating their own title:
// Site_shell already promotes the page title to the page <h1>, so such a block
// rendered the page name twice, one line under the other.
$dupTitleHeading = false;
foreach (array_merge($kingdomDefs, $parkDefs) as $def) {
    $title = isset($def['attrs']['title']) ? $def['attrs']['title'] : '';
    foreach ((isset($def['blocks']) ? $def['blocks'] : array()) as $b) {
        if ($b['type'] === 'heading' && trim((string) ($b['fields']['text'] ?? '')) === trim($title)) {
            $dupTitleHeading = true;
        }
    }
}
check('no seeded page repeats its title as a heading block', $dupTitleHeading === false);

// Every seeded block type must be one the renderer actually has a partial for.
$partialDir = __DIR__ . '/../../orkui/template/default/frontdoor/blocks/';
$missing = array();
foreach (array_unique(array_merge($kingdomTypes, $parkTypes)) as $t) {
    if (!file_exists($partialDir . $t . '.tpl')) {
        $missing[] = $t;
    }
}
check('every seeded block type has a render partial (' . implode(',', $missing) . ')', $missing === array());

// --- Seeded theme row -----------------------------------------------------
// A new site used to seed NO theme row at all, so every org inherited whatever
// the CSS defaulted to — which was MedievalSharp. The seeder must now always
// create and ACTIVATE a row, and its --fd-primary must come from the org's own
// device so no two of the 342 parks look alike.
$seedTheme = new ReflectionMethod('CmsSite', '_seedOrgTheme');

$DB->executed = array();
$DB->queue    = array(array());        // no existing theme row
$primary = $seedTheme->invoke($site, 'park', 1049, 99);

check('_seedOrgTheme returns a hex primary', preg_match('/^#[0-9a-f]{6}$/', $primary) === 1);
check('_seedOrgTheme never returns the empty string', $primary !== '');
check('_seedOrgTheme wrote a theme row', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'cms_theme') !== false) {
            return true;
        }
    }
    return false;
})());

// --- Fix round 1: a stamping path can no longer skip the theme seed -------
// _seedStarterTemplate() has TWO completion branches (the early return when
// the nav menu is found already non-empty under the row lock, and the normal
// end-of-method path). Only the normal path originally called
// _seedOrgTheme() before stamping template_seeded_at — a PERMANENT one-way
// marker — so a site taking the other branch was stamped seeded and could
// NEVER be re-seeded with a theme. Fixed by hoisting both branches onto one
// shared _finishSeed() tail.
//
// Structural guard: assert there is exactly ONE call site for
// _stampTemplateSeeded() in the whole class. Two hand-kept call sites are
// exactly what drifted apart and caused this bug — collapsing to one makes
// that drift structurally impossible to reintroduce, so this check would
// fail immediately if a future edit added a second direct stamp call outside
// _finishSeed().
$classSrc = file_get_contents(__DIR__ . '/../../system/lib/ork3/class.CmsSite.php');
check(
    '_stampTemplateSeeded() has exactly one call site (no drift-prone duplicate)',
    substr_count($classSrc, '$this->_stampTemplateSeeded(') === 1
);
// Scoped to _finishSeed()'s own body, not the whole class: the public
// EnsureOrgTheme() wrapper is a legitimate second caller (the supported entry
// point for repairing a theme outside the seed path). What must stay unique is
// the call INSIDE the completion tail, so a future edit cannot re-introduce a
// second seed-path call that drifts from the stamp.
$finishSeedRef  = new ReflectionMethod('CmsSite', '_finishSeed');
$classLines     = file(__DIR__ . '/../../system/lib/ork3/class.CmsSite.php');
$finishSeedBody = implode('', array_slice(
    $classLines,
    $finishSeedRef->getStartLine() - 1,
    $finishSeedRef->getEndLine() - $finishSeedRef->getStartLine() + 1
));
check(
    '_seedOrgTheme() has exactly one call site inside _finishSeed(), feeding that same completion path',
    substr_count($finishSeedBody, '$this->_seedOrgTheme(') === 1
);

// Behavioral guard: the shared completion tail itself really does seed a
// theme BEFORE it stamps the marker — exercised directly via reflection so
// it is provable independent of which branch reaches it.
$finishSeed = new ReflectionMethod('CmsSite', '_finishSeed');

// homeId MUST be > 0 here: the completion tail only stamps the one-way marker
// once the is_system landing page actually landed, so a homeId of 0 exercises
// the withheld-stamp branch instead (covered separately below).
$DB->executed = array();
$DB->queue    = array(
    array(),                                       // _setSeededHomePage: site has no landing page yet
    array(array('slug' => 'test-park')),           // UpdateSite -> _slugForSite (cache-bust key)
    array(array('scope_type' => 'park', 'scope_id' => 1049)), // _validateHomePage: the site's scope
    array(array('scope_type' => 'park', 'scope_id' => 1049)), // _validateHomePage: the page's scope (matches)
    array(),                                       // _heraldryPath: no device on file
    array(),                                       // _parentKingdomIdForPark: no parent
    array(),                                       // CmsTheme::_themeIdByName probe: no existing row -> INSERT
    array(),                                       // CmsTheme::_themeIdByName readback (post-INSERT)
    array(array('Field' => 'template_seeded_at')), // _stampTemplateSeeded's SHOW COLUMNS probe: column exists
);
$finishSeed->invoke($site, 42, 'park', 1049, 99, 77); // homeId=77 -> the landing page seeded

$themeIdx = null;
$stampIdx = null;
foreach ($DB->executed as $i => $sql) {
    if ($themeIdx === null && stripos($sql, 'cms_theme') !== false) {
        $themeIdx = $i;
    }
    if ($stampIdx === null && stripos($sql, 'template_seeded_at') !== false) {
        $stampIdx = $i;
    }
}
check('_finishSeed() seeds a theme row', $themeIdx !== null);
check('_finishSeed() stamps template_seeded_at', $stampIdx !== null);
check(
    '_finishSeed() seeds the theme BEFORE stamping the one-way marker',
    $themeIdx !== null && $stampIdx !== null && $themeIdx < $stampIdx
);

// Behavioral guard for the OTHER half of the same gate: when the is_system
// landing page did not seed (homeId <= 0) the marker must be WITHHELD, so the
// next EnsureSite re-enters its repair branch instead of leaving the site
// permanently stamped with a NULL home_page_id and a "being built" front page.
// The withheld stamp has to leave a trail, so an audit row is part of the
// contract, not a nicety.
$auditMemo   = new ReflectionProperty('CmsBase', '_tableExistsMemo');
$memoBefore  = $auditMemo->getValue(); // capture, don't assume — see the restore below
$auditMemo->setValue(null, array(DB_PREFIX . 'cms_audit' => true)); // skip the SHOW TABLES probe

$DB->executed  = array();
$DB->execBinds = array();
$DB->queue     = array(
    array(), // _heraldryPath: no device on file
    array(), // _parentKingdomIdForPark: no parent
    array(), // CmsTheme::_themeIdByName probe: no existing row -> INSERT
    array(), // CmsTheme::_themeIdByName readback (post-INSERT)
    // The SHOW COLUMNS probe row MUST be queued, counter-intuitive as that looks.
    // FakeDB::DataSet() does not append to $executed (only Execute() does), so
    // the probe can never show up in the $stampIdx0 scan anyway. Leaving it
    // UNqueued made _stampTemplateSeeded() bail on a null column read and skip
    // the UPDATE for the wrong reason — the assertion below then passed against
    // the old unconditional-stamp code too, guarding nothing. With the row
    // present, the homeId gate is the ONLY thing that can stop the UPDATE.
    array(array('Field' => 'template_seeded_at')),
);
$finishSeed->invoke($site, 42, 'park', 1049, 99, 0); // homeId=0 -> incomplete seed

$stampIdx0 = null;
$themeIdx0 = null;
$auditActions = array();
foreach ($DB->executed as $i => $sql) {
    if ($themeIdx0 === null && stripos($sql, 'cms_theme') !== false) {
        $themeIdx0 = $i;
    }
    if ($stampIdx0 === null && stripos($sql, 'template_seeded_at') !== false) {
        $stampIdx0 = $i;
    }
    // The theme step writes audit rows of its own, so match on the action bind
    // rather than on the first INSERT that happens to hit cms_audit.
    if (stripos($sql, 'cms_audit') !== false && isset($DB->execBinds[$i]['action'])) {
        $auditActions[] = $DB->execBinds[$i]['action'];
    }
}
check('_finishSeed() still seeds the theme when the home page is missing', $themeIdx0 !== null);
check(
    '_finishSeed() WITHHOLDS template_seeded_at when homeId <= 0 (site stays repairable)',
    $stampIdx0 === null
);
check(
    '_finishSeed() audits the incomplete seed as seed_incomplete_unstamped',
    in_array('seed_incomplete_unstamped', $auditActions, true)
);

// The gate returns before consuming the probe row; drain the queue so the next
// block (which does not reassign it) doesn't eat a leftover.
$DB->queue = array();
$auditMemo->setValue(null, $memoBefore); // restore what the run actually had, not array()

// --- Park starter is its own template, not a trimmed kingdom one ----------
$parkDefs2  = $defs->invoke($site, 'park', 1049);
$parkSlugs  = array_keys($parkDefs2);
$parkTypes2 = $blockTypes($parkDefs2);
$parkCopy2  = $allCopy($parkDefs2);

check('park seeds exactly three pages', count($parkSlugs) === 3);
check(
    'park pages are home / new-players / contact',
    $parkSlugs === array('home', 'new-players', 'contact')
);
check('park no longer seeds an About page', !isset($parkDefs2['about']));
check('park no longer seeds a Documents page', !isset($parkDefs2['documents']));
check(
    'park seeds no staff_roster (parks have no board)',
    !in_array('staff_roster', $parkTypes2, true)
);
check('park home leads with park_hero', $parkDefs2['home']['blocks'][0]['type'] === 'park_hero');
check('park home carries park_meeting', in_array('park_meeting', $parkTypes2, true));
check('park home carries the first-day steps', in_array('steps', $parkTypes2, true));
check(
    'new-players carries the FAQ accordion',
    in_array('accordion', array_map(function ($b) {
        return $b['type'];
    }, $parkDefs2['new-players']['blocks']), true)
);
check(
    'contact carries park_officers',
    in_array('park_officers', array_map(function ($b) {
        return $b['type'];
    }, $parkDefs2['contact']['blocks']), true)
);
check(
    'no seeded copy contains author instructions',
    stripos($parkCopy2, 'replace this placeholder') === false
    && stripos($parkCopy2, 'describe your park') === false
    && stripos($parkCopy2, 'tell visitors who you are') === false
);
check(
    'no seeded copy hard-codes a weekday',
    !preg_match('/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b/i', $parkCopy2)
);
check('no seeded copy promises a price', stripos($parkCopy2, '$') === false);
check('nav labels are Home / New Players / Contact', array_map(
    function ($d) {
        return $d['nav_label'];
    },
    $parkDefs2
) === array(
    'home' => 'Home', 'new-players' => 'New Players', 'contact' => 'Contact'));

// --- Seeded Home paragraph never publishes a schedule ---------------------
// _parkIntroBody() SNAPSHOTS ork_park.description into an authored block and
// never refreshes it, so a description that states a day or a time freezes that
// claim on the public page forever. Half the real descriptions do exactly that.
$introBody = new ReflectionMethod('CmsSite', '_parkIntroBody');
$intro = function ($desc) use ($introBody, $site) {
    global $DB;
    $DB->queue = array(array(array('description' => $desc)));
    return $introBody->invoke($site, 1049);
};
$evergreen = 'local chapter of Amtgard';

check('a safe description is kept verbatim', strpos($intro('We are a friendly chapter with a strong arts and sciences tradition.'), 'arts and sciences') !== false);
check('weekday description falls through to evergreen', strpos($intro('We meet every Saturday at Lents Family Park.'), $evergreen) !== false);
check('plural weekday falls through', strpos($intro('Ironwood meets Sundays down by the river.'), $evergreen) !== false);
check('clock time falls through', strpos($intro('Come find us at 1:00 PM in Centennial Park.'), $evergreen) !== false);
check('bare am/pm falls through', strpos($intro('Games run 11am til we are done.'), $evergreen) !== false);
check('spelled-out time falls through', strpos($intro('Fun and Battlegames noon to five-ish!'), $evergreen) !== false);
check('empty description falls through', strpos($intro(''), $evergreen) !== false);
check(
    'the evergreen fallback itself states no day and no time',
    !preg_match('/\b(mon|tues?|wed(nes)?|thurs?|fri|satur|sun)(day)?s?\b/i', $intro(''))
        && !preg_match('/\b(noon|midnight|o.?clock)\b/i', $intro(''))
        && !preg_match('/\d{1,2}\s*(:\d{2})?\s*(am|pm)/i', $intro(''))
);

// --- Closing CTA band leads with the Tier 1 ask ---------------------------
// The social link is a LOWER-commitment action than showing up, so it must never
// be the only button (or the first one) in the band that closes the page.
$ctaFields = new ReflectionMethod('CmsSite', '_parkCtaFields');
$ctaFor = function ($url) use ($ctaFields, $site) {
    global $DB;
    $DB->queue = array(array(array('url' => $url)));
    $f = $ctaFields->invoke($site, 1049);
    return isset($f['ctas']) && is_array($f['ctas']) ? $f['ctas'] : array();
};

$ctasFb   = $ctaFor('https://www.facebook.com/groups/somepark');
$ctasNone = $ctaFor('');

check('CTA slot 1 is the park-day ask, not the social link', ($ctasFb[0]['label'] ?? '') === 'Come to a park day');
check('CTA slot 1 points at the on-page meeting block', ($ctasFb[0]['href'] ?? '') === '#pk-meet');
check('CTA slot 1 is SOLID (gold), the primary button style', ($ctasFb[0]['style'] ?? '') === 'gold');
check('the social link stays a ghost, below Tier 1', ($ctasFb[1]['style'] ?? '') === 'ghost' && strpos($ctasFb[1]['label'] ?? '', 'Facebook') !== false);
check('exactly one solid button in the band', count(array_filter($ctasFb, function ($c) {
    return ($c['style'] ?? '') === 'gold';
})) === 1);
check('a URL-less park still gets a real button', ($ctasNone[0]['label'] ?? '') === 'Come to a park day');
check('the deliberately-empty editor slot is LAST', trim((string) (end($ctasNone)['label'] ?? 'x')) === ''
    && trim((string) (end($ctasFb)['label'] ?? 'x')) === '');

// --- Starter content lives in CmsStarterContent, byte-for-byte -------------
// The ~370 lines of hand-authored public website copy moved out of CmsSite
// (which owns the site lifecycle, addressability and identity) into its own
// pure-content class, so editing a sentence of marketing copy no longer means
// editing — and re-reviewing — a security- and lifecycle-critical class.
//
// The extraction was required to be BEHAVIOR-PRESERVING, so the registry for a
// given (scopeType, scopeId, orgName) must be byte-identical to what the
// inlined version produced. serialize() is the strongest available statement of
// that: it compares values, types, key order and array order in one assertion.
check('CmsStarterContent is loaded by class.CmsSite.php itself', class_exists('CmsStarterContent'));
check(
    'CmsStarterContent::LATEST_VERSION agrees with CmsSite::CURRENT_SEED_VERSION',
    CmsStarterContent::LATEST_VERSION === CmsSite::CURRENT_SEED_VERSION
);

// Version 0 is what every already-seeded site received; version 2 is the
// kingdom starter redesign. The KINGDOM content forks at v2, so v0 and the
// current version must now DIFFER — and version 1 must still be byte-identical
// to version 0, because that is the historical record BackfillSeedContent()
// re-derives to decide what is still unedited. Mutating an old version is the
// one change that silently breaks the upgrade path for every seeded site, so it
// is asserted, not assumed.
$DB->queue = array(array(array('parent_kingdom_id' => 0)));
$kingdomV0 = $defs->invoke($site, 'kingdom', 7, 'Kingdom of the Burning Lands', 0);
$DB->queue = array(array(array('parent_kingdom_id' => 0)));
$kingdomV1 = $defs->invoke($site, 'kingdom', 7, 'Kingdom of the Burning Lands', 1);
$DB->queue = array(array(array('parent_kingdom_id' => 0)));
$kingdomVN = $defs->invoke($site, 'kingdom', 7, 'Kingdom of the Burning Lands', CmsSite::CURRENT_SEED_VERSION);
check(
    'kingdom version 1 is still byte-identical to version 0 (historical record intact)',
    serialize($kingdomV0) === serialize($kingdomV1)
);
check(
    'kingdom starter content really does fork at the current version',
    serialize($kingdomV0) !== serialize($kingdomVN)
);

// --- Version 2: the kingdom starter redesign ------------------------------
// v0/v1 published a kingdom home page whose own copy said "find a park near
// you" and then offered no way to do it: two centred grey text bands, an events
// list, and the nav bar. v2 rebuilds it around that one action.
$khBlocks = $kingdomVN['home']['blocks'];
$khTypes  = array_map(function ($b) {
    return $b['type'];
}, $khBlocks);
check('kingdom home leads with the crest hero', ($khBlocks[0]['type'] ?? '') === 'kingdom_hero');
check(
    'kingdom home runs hero -> parks teaser -> first day -> events -> CTA',
    $khTypes === array('kingdom_hero', 'kingdom_parks', 'steps', 'kingdom_events', 'cta_band')
);
$khTeaser = $khBlocks[1]['fields'];
check(
    'the home parks block is a TEASER with heraldry and a way through to the full list',
    (int) $khTeaser['limit'] === 6
        && !empty($khTeaser['show_heraldry'])
        && $khTeaser['more_href'] === UIR . 'Page/view/parks'
);
check(
    'the hero CTA points at this site\'s own Parks page',
    ($khBlocks[0]['fields']['cta_label'] ?? '') !== ''
        && ($khBlocks[0]['fields']['cta_href'] ?? '') === UIR . 'Page/view/parks'
);
// The block must be addable, kingdom-scoped and renderable, or the seed plants
// something an officer can neither edit nor re-add.
$khDef = CmsBlockRegistry::BlockDefs();
check(
    'kingdom_hero is registered: dynamic, addable, kingdom-scoped',
    isset($khDef['kingdom_hero'])
        && !empty($khDef['kingdom_hero']['dynamic'])
        && !empty($khDef['kingdom_hero']['addable'])
        && $khDef['kingdom_hero']['scopes'] === array('kingdom')
);
check(
    'kingdom_officers no longer sends officers off to invent a Board of Directors',
    stripos($khDef['kingdom_officers']['description'], 'board of directors') === false
        && stripos($khDef['kingdom_officers']['description'], 'non-ORK roles') !== false
);

// The new-player page: a kingdom is what an "Amtgard <state>" search surfaces,
// and v0 answered "what is this and should I try it" nowhere at all.
check('kingdom seeds a new-player page', isset($kingdomVN['new-players']));
$knpTypes = array_map(function ($b) {
    return $b['type'];
}, $kingdomVN['new-players']['blocks']);
check('the new-player page carries the FAQ accordion', in_array('accordion', $knpTypes, true));
// SHARED with the park starter, not a second copy that can drift.
$DB->queue = array(array(array('description' => 'x')), array(array('url' => '')));
$parkCurrent = $defs->invoke($site, 'park', 1049, null, CmsSite::CURRENT_SEED_VERSION);
$parkFaq = null;
foreach ($parkCurrent['new-players']['blocks'] as $b) {
    if ($b['type'] === 'accordion') {
        $parkFaq = $b['fields']['items'];
    }
}
$kingdomFaq = null;
foreach ($kingdomVN['new-players']['blocks'] as $b) {
    if ($b['type'] === 'accordion') {
        $kingdomFaq = $b['fields']['items'];
    }
}
check('the kingdom FAQ IS the park FAQ, byte for byte', serialize($parkFaq) === serialize($kingdomFaq));
// A kingdom must not route a newcomer away from its own parks — that is the one
// thing its site exists to deliver. (A single park legitimately may be too far,
// which is why the park template closes on the Atlas instead.)
$knpCopy = $allCopy($kingdomVN['new-players']);
check('the kingdom new-player page closes on its own parks, not the Atlas', strpos($knpCopy, UIR . 'Page/view/parks') !== false
    && strpos($knpCopy, UIR . 'Atlas') === false);

// The authored roster beside the live officer grid: ORK stores five kingdom
// seats, so the guild masters and appointed officers can only come from here.
$khRoster = null;
foreach ($kingdomVN['officers']['blocks'] as $b) {
    if ($b['type'] === 'staff_roster') {
        $khRoster = $b['fields'];
    }
}
check(
    'the seeded roster asks for the officer corps every kingdom HAS',
    $khRoster['heading'] === 'Guild Masters & Appointed Officers' && $khRoster['kicker'] === 'Officer corps'
);
check('the seeded roster is persona-first (the consent gate forces it anyway)', $khRoster['presentation'] === 'amtgard');
// staff_roster.tpl drops any row whose primary name resolves empty, so a
// role-only row would publish NOTHING. An empty roster self-suppresses and
// prompts the author in preview, which is the correct empty state.
check('the seeded roster seeds no people rows that would render nothing', $khRoster['people'] === array());

// --- Seeded nav: visitor-facing, with News and a real hierarchy -----------
$navTop = array();
$navKids = array();
foreach ($kingdomVN as $slug => $def) {
    if (!empty($def['nav_parent'])) {
        $navKids[$def['nav_parent']][] = $def['nav_label'];
    } else {
        $navTop[] = $def['nav_label'];
    }
}
check(
    'nav reads the way a visitor searches',
    $navTop === array('Home', 'New to Amtgard?', 'Find a Park', 'News', 'Contact', 'About')
);
check('the two least-visited items live in one dropdown', $navKids === array('about' => array('Documents')));
// The org has had live blog routes all along and nothing in the seeded nav ever
// pointed at them, so a published post had zero internal links to it.
check(
    'News is a nav item on the existing blog route, with no page of its own',
    isset($kingdomVN['news']['nav_link'])
        && $kingdomVN['news']['nav_link']['url'] === 'Blog'
        && !isset($kingdomVN['news']['attrs'])
);

// --- v2 copy rules --------------------------------------------------------
$kingdomCopy = $allCopy($kingdomVN);
check('no seeded kingdom copy contains author instructions', !preg_match(
    '/(edit this block|add your|replace this placeholder|tell visitors|describe your)/i',
    $kingdomCopy
));
check('no seeded kingdom copy hard-codes a weekday', !preg_match(
    '/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b/i',
    $kingdomCopy
));
check('no seeded kingdom copy promises a price', strpos($kingdomCopy, '$') === false);
// A baked-in /k/{siteSlug}/ href goes stale (404) the moment an officer renames
// the site, because nothing re-visits seeded block content on a rename.
check('every seeded internal link is the rename-proof stable form', strpos($kingdomCopy, '/k/') === false
    && strpos($kingdomCopy, 'Site/page/') === false);
// rich_text.tpl only self-suppresses when EVERY field is empty, so an empty body
// under a kicker and a heading publishes a header over nothing.
$khHollowRichText = false;
foreach ($kingdomVN as $def) {
    foreach ((isset($def['blocks']) ? $def['blocks'] : array()) as $b) {
        if ($b['type'] !== 'rich_text') {
            continue;
        }
        $f = $b['fields'];
        if (trim(strip_tags((string) ($f['body'] ?? ''))) === ''
            && (trim((string) ($f['kicker'] ?? '')) !== '' || trim((string) ($f['heading'] ?? '')) !== '')
        ) {
            $khHollowRichText = true;
        }
    }
}
check('no seeded rich_text publishes a header over an empty body', $khHollowRichText === false);

// --- The copy rules run over BOTH registries, at EVERY version ------------
// The rules above were only ever asserted against whichever registry the
// section they sat in happened to build — the park one for the no-instructions
// rule, the current kingdom one for the price rule. That gap is exactly how
// author instructions survived on the kingdom branch: the park registry had
// been rebuilt specifically to remove them, and the kingdom branch went on
// publishing "Tell visitors who you are…" and "Replace this placeholder with
// your own history." to the open web on every published kingdom site. Running
// one rule set over both scopes would have gone red the moment that copy
// existed.
//
// EVERY version, not just the current one: a frozen historical version is what
// BackfillSeedContent() re-derives to decide what an officer has edited, so an
// old version is still LIVE copy on any site that has not been upgraded — and a
// future version must not be able to reintroduce the defect either.
//
// The blacklist is deliberately narrow. The seeded copy is real visitor-facing
// prose now, and the obvious broad forms collide with it: the shared FAQ answer
// "When you do want your own, most players build theirs out of foam…" is
// addressed to a VISITOR and must not be flagged, so the rule is 'with your
// own' (as in "Replace this placeholder with your own history"), not 'your
// own'. Same reasoning for 'add a ': it is spelled out as the author-directed
// objects ("add a block/page/photo/link/section") rather than the bare phrase.
$authorInstruction = '/('
    . 'edit this (block|page|text|section)'
    . '|replace this'
    // 'placeholder' bare: measured 0 hits across all six shipped registries and
    // it has no legitimate visitor-facing use, so narrowing it bought nothing
    // while letting "Placeholder: your kingdom's story goes here." through.
    . '|placeholder'
    . '|tell visitors'
    . '|add your'
    . '|add (a (block|page|photo|link|section)|an image)'
    . '|with your own|your own (words|story|history|copy|text)'
    . '|describe what|describe your'
    . '|introduce your|introduction here'
    . '|add a few|write your own'
    . '|use this space|customi[sz]e this|update this (block|page|text)'
    . ')/i';

$versions = range(0, CmsStarterContent::LATEST_VERSION);
$instructionHits = array();
$priceHits       = array();
$parkKingdomHits = array();
$loneInstruction = array();
// These two were previously asserted against the CURRENT version only, which is
// structurally the same blind spot this whole section exists to close: a
// hard-coded weekday or a rename-fragile href sitting in a FROZEN old version is
// live copy on every kingdom seeded before this branch and not yet backfilled.
$weekdayHits = array();
$fragileHref = array();
foreach ($versions as $v) {
    foreach (array('kingdom', 'park') as $scope) {
        // Each scope's runtime inputs, in the order its branch reads them:
        // kingdom = OrgUnitNoun + _orgDisplayName, park = intro + CTA probes.
        $DB->queue = ($scope === 'kingdom')
            ? array(array(array('parent_kingdom_id' => 0)))
            : array(array(array('description' => 'x')), array(array('url' => '')));
        $reg  = $defs->invoke($site, $scope, ($scope === 'park') ? 1049 : 7, null, $v);
        $copy = $allCopy($reg);
        $tag  = $scope . ' v' . $v;

        if (preg_match($authorInstruction, $copy, $m)) {
            $instructionHits[] = $tag . ': ' . $m[0];
        }
        if (strpos($copy, '$') !== false) {
            $priceHits[] = $tag;
        }
        if ($scope === 'park' && stripos($copy, 'kingdom') !== false) {
            $parkKingdomHits[] = $tag;
        }
        // A seeded weekday is a live FALSEHOOD, not merely an embarrassment:
        // no seed can know when any org meets. Meeting times come from dynamic
        // blocks reading real ORK data, never from hand-typed copy.
        if (preg_match('/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b/i', $copy, $wm)) {
            $weekdayHits[] = $tag . ': ' . $wm[0];
        }
        // Seeded internal hrefs must use the STABLE global 'Page/view/{slug}'
        // form, which _helpers.tpl re-points at render time. A baked-in scoped
        // '/k/{slug}/' or 'Site/page/' href 404s the moment an officer renames
        // the site.
        if (strpos($copy, '/k/') !== false || strpos($copy, 'Site/page/') !== false) {
            $fragileHref[] = $tag;
        }

        // A page whose ONLY block is a rich_text is that page, entirely. An
        // empty or instruction-shaped body there publishes a nav-linked page
        // that says nothing to the visitor who clicked it.
        foreach ($reg as $slug => $def) {
            $blocks = isset($def['blocks']) ? $def['blocks'] : array();
            if (count($blocks) !== 1 || $blocks[0]['type'] !== 'rich_text') {
                continue;
            }
            $body = trim(strip_tags((string) ($blocks[0]['fields']['body'] ?? '')));
            if ($body === '' || preg_match($authorInstruction, $body)) {
                $loneInstruction[] = $tag . ':' . $slug;
            }
        }
    }
}
$DB->queue = array();

check(
    'no seeded copy, in any scope at any version, names a WEEKDAY ('
        . implode('; ', $weekdayHits) . ')',
    $weekdayHits === array()
);
check(
    'no seeded copy, in any scope at any version, bakes in a scoped href ('
        . implode('; ', $fragileHref) . ')',
    $fragileHref === array()
);
check(
    'no seeded copy, in any scope at any version, instructs the AUTHOR ('
        . implode('; ', $instructionHits) . ')',
    $instructionHits === array()
);
check(
    'no seeded copy, in any scope at any version, promises a price ('
        . implode(', ', $priceHits) . ')',
    $priceHits === array()
);
check(
    'no park registry, at any version, calls the park a kingdom ('
        . implode(', ', $parkKingdomHits) . ')',
    $parkKingdomHits === array()
);
check(
    'a page whose only block is rich_text carries real prose, not a prompt ('
        . implode(', ', $loneInstruction) . ')',
    $loneInstruction === array()
);

// --- _seedNavMenu really writes that hierarchy ----------------------------
// The registry can declare a parent all it likes; until the seeder honors it,
// every item still lands at parent_id NULL. Drive the real method against the
// real CmsNav so the declaration and the INSERT are checked together.
// CmsNav is required HERE, not with the other libs at the top of this file, and
// that placement is load-bearing: _seedStarterTemplate() returns immediately
// unless BOTH CmsPage and CmsNav are loaded, and every EnsureSite fixture above
// is queued for a run in which the seed does nothing. Loading CmsNav earlier
// makes those seeds really run and silently eats their queued reads.
require __DIR__ . '/../../system/lib/ork3/class.CmsNav.php';

$seedNav = new ReflectionMethod('CmsSite', '_seedNavMenu');
$navPageIds = array(
    'home' => 11, 'new-players' => 12, 'parks' => 13,
    'officers' => 14, 'about' => 15, 'documents' => 16,
);
$navReadback = array();
for ($i = 0; $i < 10; $i++) {
    $navReadback[] = array(array('nav_id' => 900 + $i)); // CreateItem's insert verify
}
$DB->executed  = array();
$DB->execBinds = array();
$DB->queue = array_merge(
    array(
        array(array('site_id' => 42)),  // the FOR UPDATE site-row lock
        array(),                        // ListItems -> menu still empty
    ),
    $navReadback
);
$seedNav->invoke($site, 42, 'kingdom', 7, $kingdomVN, $navPageIds);
$navInserts = array();
foreach ($DB->executed as $i => $sql) {
    if (stripos($sql, 'INSERT INTO') !== false && stripos($sql, 'cms_nav_item') !== false) {
        $navInserts[] = $DB->execBinds[$i];
    }
}
check('the seeder writes one nav row per registry entry', count($navInserts) === 7);
check('top-level items keep the 10/20/30… ordering', (function () use ($navInserts) {
    $tops = array();
    foreach ($navInserts as $row) {
        if ($row['parent_id'] === null) {
            $tops[] = (int) $row['ordering'];
        }
    }
    return $tops === array(10, 20, 30, 40, 50, 60);
})());
check('the News row is seeded on the internal blog route', (function () use ($navInserts) {
    foreach ($navInserts as $row) {
        if ($row['label'] === 'News') {
            return $row['link_type'] === 'dynamic' && $row['url'] === 'Blog' && $row['page_id'] === null;
        }
    }
    return false;
})());
check('Documents is written as a CHILD of About, not another top-level item', (function () use ($navInserts) {
    // CreateItem's insert-verify read hands back 900 + the row's creation index
    // (see $navReadback), so About's nav_id is fixed by where it was written.
    $aboutId = null;
    foreach ($navInserts as $i => $row) {
        if ($row['label'] === 'About') {
            $aboutId = 900 + $i;
        }
    }
    foreach ($navInserts as $row) {
        if ($row['label'] === 'Documents') {
            return $aboutId !== null && $row['parent_id'] !== null
                && (int) $row['parent_id'] === (int) $aboutId
                && (int) $row['ordering'] === 10;
        }
    }
    return false;
})());
$DB->queue = array();

$DB->queue = array(array(array('description' => 'x')), array(array('url' => '')));
$parkV0 = $defs->invoke($site, 'park', 1049, null, 0);
$DB->queue = array(array(array('description' => 'x')), array(array('url' => '')));
$parkVN = $defs->invoke($site, 'park', 1049, null, CmsSite::CURRENT_SEED_VERSION);
check(
    'park starter content is byte-identical across v0 and the current version',
    serialize($parkV0) === serialize($parkVN)
);

// An unknown (rolled-back-to) version must still build something renderable
// rather than an empty registry.
$DB->queue = array(array(array('description' => 'x')), array(array('url' => '')));
$parkFuture = $defs->invoke($site, 'park', 1049, null, 99);
check('an unknown seed version still builds the latest registry', serialize($parkFuture) === serialize($parkVN));

// The copy really did leave CmsSite: a distinctive authored sentence must now
// live in the content file and nowhere in the lifecycle class.
$starterSrc = file_get_contents(__DIR__ . '/../../system/lib/ork3/class.CmsStarterContent.php');
check(
    'the authored FAQ copy lives in CmsStarterContent',
    strpos($starterSrc, 'Do I need to buy equipment?') !== false
);
check(
    'the authored FAQ copy no longer lives in CmsSite',
    strpos($classSrc, 'Do I need to buy equipment?') === false
);
// The comments narrate real past incidents (why the park branch is its own
// three-page design, why no leading heading block, why hero_carousel was
// rejected, why Documents seeds exactly one row). They had to travel WITH the
// code they explain — losing them would be the worst outcome of the extraction.
check('the "not hero_carousel" incident note travelled with the copy', strpos($starterSrc, 'hero_carousel') !== false);
check('the one-seeded-download note travelled with the copy', strpos($starterSrc, 'EXACTLY ONE seeded row') !== false);
check('the no-leading-heading note travelled with the copy', strpos($starterSrc, 'NO leading heading block') !== false);
check('the park three-page rationale travelled with the copy', strpos($starterSrc, 'A park is not a small kingdom') !== false);

// --- seed_version is stamped under the SAME gate as the marker -------------
// template_seeded_at alone is a one-way "seeded ever" flag with no version in
// it, so every copy improvement needed its own bespoke migration to reach the
// sites already out there. seed_version records WHICH starter content a site
// got — but a seed that withholds the marker MUST withhold the version too, or
// the site is skipped by the backfill and re-seeded by EnsureSite's repair at
// the same time. Both ride one guarded UPDATE; these two checks are that pair.
$auditMemo->setValue(null, array(DB_PREFIX . 'cms_audit' => true)); // skip the SHOW TABLES probe

$DB->executed  = array();
$DB->execBinds = array();
$DB->queue     = array(
    array(),                                       // _setSeededHomePage: no landing page yet
    array(array('slug' => 'test-park')),           // UpdateSite -> _slugForSite
    array(array('scope_type' => 'park', 'scope_id' => 1049)), // _validateHomePage: site scope
    array(array('scope_type' => 'park', 'scope_id' => 1049)), // _validateHomePage: page scope
    array(),                                       // _heraldryPath: no device
    array(),                                       // _parentKingdomIdForPark: no parent
    array(),                                       // CmsTheme probe -> INSERT
    array(),                                       // CmsTheme readback
    array(array('Field' => 'template_seeded_at')), // marker column present
    array(array('Field' => 'seed_version')),       // version column present
);
$finishSeed->invoke($site, 42, 'park', 1049, 99, 77); // homeId=77 -> complete seed

$stampSql   = '';
$stampBinds = array();
foreach ($DB->executed as $i => $sql) {
    if (stripos($sql, 'template_seeded_at') !== false) {
        $stampSql   = $sql;
        $stampBinds = $DB->execBinds[$i];
        break;
    }
}
check('_finishSeed() stamps seed_version alongside template_seeded_at', stripos($stampSql, 'seed_version') !== false);
check(
    '_finishSeed() stamps the CURRENT seed version',
    isset($stampBinds['seed_version']) && (int) $stampBinds['seed_version'] === CmsSite::CURRENT_SEED_VERSION
);
check(
    'the version rides the SAME statement as the marker (one gate, never two)',
    substr_count(strtolower($stampSql), 'update') === 1
        && stripos($stampSql, 'template_seeded_at IS NULL') !== false
);

// Withheld half: no landing page -> neither column is written.
$DB->executed  = array();
$DB->execBinds = array();
$DB->queue     = array(
    array(), // _heraldryPath
    array(), // _parentKingdomIdForPark
    array(), // CmsTheme probe -> INSERT
    array(), // CmsTheme readback
    array(array('Field' => 'template_seeded_at')),
    array(array('Field' => 'seed_version')),
);
$finishSeed->invoke($site, 42, 'park', 1049, 99, 0); // homeId=0 -> incomplete seed
$wroteVersion = false;
foreach ($DB->executed as $sql) {
    if (stripos($sql, 'cms_site') !== false && stripos($sql, 'seed_version') !== false) {
        $wroteVersion = true;
    }
}
check('_finishSeed() WITHHOLDS seed_version when it withholds the marker', $wroteVersion === false);
$DB->queue = array();

// A pre-migration database (no seed_version column) must still stamp the
// marker: naming a column that isn't there would fail the whole UPDATE and lose
// the marker too, which is why the two columns are probed independently.
$DB->executed  = array();
$DB->execBinds = array();
$DB->queue     = array(
    array(),                                       // _setSeededHomePage: no landing page yet
    array(array('slug' => 'test-park')),           // UpdateSite -> _slugForSite
    array(array('scope_type' => 'park', 'scope_id' => 1049)), // _validateHomePage: site scope
    array(array('scope_type' => 'park', 'scope_id' => 1049)), // _validateHomePage: page scope
    array(),                                       // _heraldryPath
    array(),                                       // _parentKingdomIdForPark
    array(),                                       // CmsTheme probe -> INSERT
    array(),                                       // CmsTheme readback
    array(array('Field' => 'template_seeded_at')), // marker present
    array(),                                       // seed_version NOT present
);
$finishSeed->invoke($site, 42, 'park', 1049, 99, 77);
$preMigrationStamp = '';
foreach ($DB->executed as $sql) {
    if (stripos($sql, 'template_seeded_at') !== false) {
        $preMigrationStamp = $sql;
        break;
    }
}
check(
    'pre-migration DB still stamps the marker, without naming seed_version',
    $preMigrationStamp !== '' && stripos($preMigrationStamp, 'seed_version') === false
);
$DB->queue = array();

// --- The backfill runner: BOTH directions, on copy that really differs ----
// The safety property is that a stored field is replaced ONLY when it still
// byte-matches what the site's recorded seed version wrote. Asserting that
// against the SHIPPED versions proves nothing: _parkV1() delegates to
// _parkV0(), so $old === $new for every field and the write loop can never
// fire — "it left the officer's words alone" would pass just as green with the
// byte-match gate deleted outright.
//
// So these checks run against a test double (VersionedCmsSite, above) whose
// CURRENT version's copy genuinely differs from v0, and assert BOTH
// directions: an UNTOUCHED seeded field IS upgraded, an officer-EDITED one is
// not. Delete the `$fields[$field] !== $seededValue` gate in
// BackfillSeedContent() and the "edited" group below goes red; delete the
// write and the "untouched" group does.
$pageLib  = new CmsPage();
$verSite  = new VersionedCmsSite();
// Both tables the write path touches exist (skip their SHOW TABLES probes, which
// would otherwise eat a queued read and desynchronize every fixture below).
$auditMemo->setValue(null, array(DB_PREFIX . 'cms_audit' => true, DB_PREFIX . 'cms_revision' => true));
$parkProbe = array(array(array('description' => 'x')), array(array('url' => '')));

// What the seed REALLY stored for a park block: the registry run through
// CmsPage's own sanitize pass, which is exactly what ReplaceBlocks() did to it
// at seed time. Fixtures built any other way would be testing a fiction.
$storedSeed = function ($registry, $slug, $index) use ($pageLib) {
    $blocks = $pageLib->SanitizeBlocksForRender($registry[$slug]['blocks']);
    return $blocks[$index]['fields'];
};
// One stored cms_block row in the shape _fetchBlocks() reads.
$blockRow = function ($id, $type, $ordering, $fields) {
    return array(
        'block_id' => $id, 'owner_type' => 'page', 'owner_id' => 77,
        'type' => $type, 'ordering' => $ordering, 'enabled' => 1,
        'source' => 'authored', 'fields_json' => json_encode($fields),
    );
};
// ReplaceBlocks() verifies its own write by reading the rows back before
// COMMIT. These two closures make the fake DB echo what the UPDATE bound, so a
// correct write verifies and a genuinely dropped one would not.
$verifyRows = function ($binds, $db) {
    $out = array();
    foreach ($db->executed as $i => $sql) {
        if (stripos($sql, 'UPDATE') !== false && stripos($sql, 'cms_block') !== false) {
            $b = $db->execBinds[$i];
            $out[] = array(
                'block_id' => $b['block_id'], 'type' => $b['type'], 'ordering' => $b['ordering'],
                'enabled' => $b['enabled'], 'source' => $b['source'], 'fields_json' => $b['fields_json'],
            );
        }
    }
    return $out;
};
$verifyCount = function ($binds, $db) use ($verifyRows) {
    return array(array('c' => count($verifyRows($binds, $db))));
};
/** The full read queue for one backfill run over a one-page park site. */
$backfillQueue = function ($blockRows) use ($parkProbe, $verifyCount, $verifyRows) {
    return array_merge(
        array(
            array(array('Field' => 'seed_version')),   // _siteColumnExists
            array(array(                               // the site row
                'site_id' => 42, 'scope_type' => 'park', 'scope_id' => 1049,
                'slug' => 'test-park', 'seed_version' => 0,
                'template_seeded_at' => '2026-01-01 00:00:00',
            )),
        ),
        $parkProbe,                                    // old version's park lookups
        $parkProbe,                                    // current version's park lookups
        array(
            array(array('page_id' => 77, 'slug' => 'home')), // the site's live pages
            $blockRows,                                      // GetBlocksForEditor
            array(array('block_id' => 101), array('block_id' => 107)), // _existingBlockIds
            $verifyCount,                                    // post-write COUNT(*)
            $verifyRows,                                     // post-write row read-back
        )
    );
};

// Re-derive v0's stored park home fields for the two blocks under test: the
// intro rich_text (which the sanitizer leaves alone) and the closing CTA band
// (whose empty editor slot the sanitizer rewrites, href '' -> '#').
$DB->queue    = $parkProbe;
$parkV0Reg    = $defs->invoke($site, 'park', 1049, null, 0);
$seededIntro  = $storedSeed($parkV0Reg, 'home', 3);
$seededCtas   = $storedSeed($parkV0Reg, 'home', 6);
$DB->queue    = array();

// The v1 copy the double will "ship": a rewritten intro body AND a rewritten
// CTA label inside the cta_band's ctas array.
$newBody = '<p>Version two of the intro copy.</p>';
$newCtas = $seededCtas['ctas'];
$newCtas[0]['label'] = 'Come find us on the field';
$verSite->overrides = array(
    'home|rich_text|40' => array('body' => $newBody),
    'home|cta_band|70'  => array('ctas' => $newCtas),
);

// (1) UNTOUCHED seeded fields — both blocks must be upgraded.
$DB->executed  = array();
$DB->execBinds = array();
$DB->queue     = $backfillQueue(array(
    $blockRow(101, 'rich_text', 40, $seededIntro),
    $blockRow(107, 'cta_band', 70, $seededCtas),
));
$upgraded = $verSite->BackfillSeedContent(42, 99);

check('backfill upgrades a field no officer has touched', $upgraded['status'] === 'updated'
    && (int) $upgraded['blocks'] === 2 && (int) $upgraded['fields'] === 2);
$writtenJson = '';
foreach ($DB->executed as $i => $sql) {
    if (stripos($sql, 'UPDATE') !== false && stripos($sql, 'cms_block') !== false) {
        $writtenJson .= ' ' . $DB->execBinds[$i]['fields_json'];
    }
}
check('backfill actually WROTE the new body', strpos($writtenJson, 'Version two of the intro copy.') !== false);
// The sanitizer regression that made this field permanently unupgradable: the
// seed stored the cta_band's empty editor slot with href '#', not the registry's
// '', so comparing against the RAW registry value could never match here.
check(
    'backfill can upgrade a field the SANITIZER rewrote at seed time (cta_band ctas)',
    strpos($writtenJson, 'Come find us on the field') !== false
);
// The canonical write path is what busts the page cache, stamps the owner row
// and snapshots a revision — a hand-rolled fields_json UPDATE did none of it.
check('backfill writes through ReplaceBlocks (owner row stamped, cache re-keyed)', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'UPDATE') !== false && stripos($sql, 'cms_page') !== false
            && stripos($sql, 'updated_at') !== false
        ) {
            return true;
        }
    }
    return false;
})());
// ...and a revision snapshot, which is the only undo for content the runner
// rewrote on somebody's published page.
check('backfill leaves an undoable revision behind', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'INSERT INTO') !== false && stripos($sql, 'cms_revision') !== false) {
            return true;
        }
    }
    return false;
})());
check('backfill reports the version it came from', (int) $upgraded['from'] === 0);
check('backfill reports the version it lands on', (int) $upgraded['to'] === CmsSite::CURRENT_SEED_VERSION);
check('backfill stamps the new seed_version', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'cms_site') !== false && stripos($sql, 'seed_version') !== false) {
            return true;
        }
    }
    return false;
})());
check('backfill audits the run', (function () use ($DB) {
    foreach ($DB->executed as $i => $sql) {
        if (stripos($sql, 'cms_audit') !== false
            && isset($DB->execBinds[$i]['action']) && $DB->execBinds[$i]['action'] === 'seed_backfill'
        ) {
            return true;
        }
    }
    return false;
})());

// (2) OFFICER-EDITED fields — same version change, same blocks, one character
// of the officer's own in each. Nothing may be written.
$DB->executed  = array();
$DB->execBinds = array();
$editedIntro = $seededIntro;
$editedIntro['body'] .= '<p>And we have been here since 1998.</p>';
$editedCtas  = $seededCtas;
$editedCtas['ctas'][0]['label'] = 'Our own words';
$DB->queue = $backfillQueue(array(
    $blockRow(101, 'rich_text', 40, $editedIntro),
    $blockRow(107, 'cta_band', 70, $editedCtas),
));
$respected = $verSite->BackfillSeedContent(42, 99);

check('backfill left the officer-edited body ALONE', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'cms_block') !== false && stripos($sql, 'UPDATE') !== false) {
            return false;
        }
    }
    return true;
})());
check('backfill rewrote no block at all here', (int) $respected['blocks'] === 0
    && (int) $respected['fields'] === 0);
// It still advances the version: every field was re-derived and considered.
check('backfill still advances the version when nothing matched', $respected['status'] === 'updated');

// (3) A block whose ORDER the officer changed is a different block as far as
// the (slug|type|order) identity goes, and must never be matched onto.
$DB->executed  = array();
$DB->execBinds = array();
$DB->queue = $backfillQueue(array(
    $blockRow(101, 'rich_text', 45, $seededIntro),   // moved down the page
    $blockRow(107, 'cta_band', 70, $editedCtas),
));
$reordered = $verSite->BackfillSeedContent(42, 99);
check(
    'backfill never follows a re-ordered block onto the wrong seed entry',
    (int) $reordered['blocks'] === 0
);

$verSite->overrides = array();
$DB->queue = array();


// --- The STRUCTURAL half of the backfill ----------------------------------
// Field-level retouching alone cannot carry a redesign: a block whose TYPE
// changed, a block that was added, and a page that did not exist before are all
// absent from one side of the (slug|type|order) key map and are skipped. That
// is why version 2 of the kingdom starter reached zero already-seeded kingdoms.
// These checks drive the three structural moves and — just as importantly —
// prove each one refuses to act on content an officer has touched.
//
// They run against their own double, whose v0 and v1+ layouts genuinely differ:
// with the SHIPPED park versions ($old === $new) no structural path can fire at
// all, so a test written against them would pass with every one of these
// branches deleted.
class StructuralCmsSite extends CmsSite
{
    /** @var bool also declare a page (+ nav row) that ONLY the new version has */
    public $addPage = false;

    /**
     * This double's OWN registry at a given version. Needed because
     * ReflectionMethod('CmsSite', '_starterPageDefs')->invoke() dispatches to
     * the method as DECLARED on CmsSite — it does not find this override — so a
     * fixture built through it would be the real kingdom registry, and every
     * byte-match below would compare against copy this double never seeds.
     */
    public function defsAt($version)
    {
        return $this->_starterPageDefs('kingdom', 7, null, $version);
    }

    protected function _starterPageDefs($scopeType, $scopeId, $orgName = null, $version = null)
    {
        $v = ($version === null) ? self::CURRENT_SEED_VERSION : (int) $version;

        $reg = array(
            'home' => array(
                'nav_label' => 'Home',
                'attrs' => array('slug' => 'home', 'type' => 'composed', 'title' => 'Home', 'is_system' => 1),
                'blocks' => array(array(
                    'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 10,
                    'fields' => array('body' => '<p>The old grey band.</p>'),
                )),
            ),
        );
        if ($v < 1) {
            return $reg;
        }
        // The new Home: the rich_text is GONE, replaced by a different block
        // type at a different order — exactly the shape the field-level gate
        // can never carry.
        $reg['home']['blocks'] = array(
            array(
                'type' => 'kingdom_hero', 'source' => 'dynamic', 'enabled' => 1, 'order' => 20,
                'fields' => array('heading' => '', 'cta_label' => 'Find a park near you'),
            ),
            array(
                'type' => 'cta_band', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                'fields' => array('heading' => 'Come play'),
            ),
        );
        if ($this->addPage) {
            $reg['new-players'] = array(
                'nav_label' => 'New to Amtgard?',
                'attrs' => array('slug' => 'new-players', 'type' => 'composed', 'title' => 'New to Amtgard?'),
                'blocks' => array(array(
                    'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 10,
                    'fields' => array('body' => '<p>Start here.</p>'),
                )),
            );
        }
        return $reg;
    }
}

$structSite = new StructuralCmsSite();
// What v0 really STORED for that home page (registry through CmsPage's clean).
$structV0      = $structSite->defsAt(0);
$seededOldHome = $storedSeed($structV0, 'home', 0);

// ReplaceBlocks verifies a write by reading the rows back; a restructure writes
// NEW rows (new types, new ids), so the fake has to echo what the INSERT bound.
$insertedRows = function ($binds, $db) {
    $out = array();
    foreach ($db->executed as $i => $sql) {
        if (stripos($sql, 'INSERT INTO') === false || stripos($sql, 'cms_block') === false) {
            continue;
        }
        $b = $db->execBinds[$i];
        $j = 0;
        while (isset($b['type_' . $j])) {
            $out[] = array(
                'block_id' => 900 + $j, 'type' => $b['type_' . $j], 'ordering' => $b['ord_' . $j],
                'enabled' => $b['en_' . $j], 'source' => $b['src_' . $j], 'fields_json' => $b['fj_' . $j],
            );
            $j++;
        }
    }
    return $out;
};
$insertedCount = function ($binds, $db) use ($insertedRows) {
    return array(array('c' => count($insertedRows($binds, $db))));
};
/** The site row every structural run below starts from: a v0 kingdom. */
$structHead = array(
    array(array('Field' => 'seed_version')),
    array(array(
        'site_id' => 55, 'scope_type' => 'kingdom', 'scope_id' => 7,
        'slug' => 'test-kingdom', 'seed_version' => 0,
        'template_seeded_at' => '2026-01-01 00:00:00',
    )),
    array(array('page_id' => 77, 'slug' => 'home')),
);

// (1) A page that is still 100% seed IS rebuilt to the new layout.
$DB->executed  = array();
$DB->execBinds = array();
$DB->queue     = array_merge($structHead, array(
    array($blockRow(101, 'rich_text', 10, $seededOldHome)), // GetBlocksForEditor
    array(array('block_id' => 101)),                        // _existingBlockIds
    $insertedCount,                                         // post-write COUNT(*)
    $insertedRows,                                          // post-write read-back
));
$rebuilt = $structSite->BackfillSeedContent(55, 99);

check(
    'backfill REBUILDS a page whose every block is still untouched seed',
    $rebuilt['status'] === 'updated' && (int) $rebuilt['pages_restructured'] === 1
);
check('the rebuild writes the new version\'s block types', (function () use ($DB) {
    foreach ($DB->executed as $i => $sql) {
        if (stripos($sql, 'INSERT INTO') !== false && stripos($sql, 'cms_block') !== false) {
            $b = $DB->execBinds[$i];
            return isset($b['type_0'], $b['type_1'])
                && $b['type_0'] === 'kingdom_hero' && $b['type_1'] === 'cta_band';
        }
    }
    return false;
})());
check('the rebuild goes through ReplaceBlocks (revision snapshot survives)', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'INSERT INTO') !== false && stripos($sql, 'cms_revision') !== false) {
            return true;
        }
    }
    return false;
})());

// (2) ONE edited character anywhere on the page and it is never restructured.
$DB->executed  = array();
$DB->execBinds = array();
$editedHome = $seededOldHome;
$editedHome['body'] .= '<p>And we have been here since 1998.</p>';
$DB->queue = array_merge($structHead, array(
    array($blockRow(101, 'rich_text', 10, $editedHome)),
));
$notRebuilt = $structSite->BackfillSeedContent(55, 99);
check('an EDITED page is never restructured', (int) $notRebuilt['pages_restructured'] === 0);
check('an edited page has no block written at all', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'cms_block') !== false) {
            return false;
        }
    }
    return true;
})());

// (3) A block the officer DISABLED is an edit too — the page keeps its shape.
$DB->executed  = array();
$DB->execBinds = array();
$offRow = $blockRow(101, 'rich_text', 10, $seededOldHome);
$offRow['enabled'] = 0;
$DB->queue = array_merge($structHead, array(array($offRow)));
$disabled = $structSite->BackfillSeedContent(55, 99);
check('a DISABLED seeded block blocks the restructure', (int) $disabled['pages_restructured'] === 0);

// (3a) The SAME untouched page, stored at DIFFERENT absolute order numbers, is
// still pristine and is still rebuilt. This is not a hypothetical: prod's
// already-seeded kingdoms carry kingdom_events at order 30 where the registry
// declares 40, every other byte matching, and an order-number comparison in
// _pageIsPristineSeed() refused all of them on 30 !== 40 alone — the difference
// between this upgrade reaching prod's seeded kingdoms and reaching none.
$DB->executed  = array();
$DB->execBinds = array();
$DB->queue     = array_merge($structHead, array(
    array($blockRow(101, 'rich_text', 30, $seededOldHome)), // seeded at 30; registry says 10
    array(array('block_id' => 101)),
    $insertedCount,
    $insertedRows,
));
$reordered = $structSite->BackfillSeedContent(55, 99);
check(
    'a page stored at different ORDER NUMBERS but the same block sequence is still rebuilt',
    $reordered['status'] === 'updated' && (int) $reordered['pages_restructured'] === 1
);

// (3b) The other half of that decision, which is in tension with (3a) and is
// the part a future reader will be tempted to "tighten" back: the block
// SEQUENCE itself — types, count and enabled flags — is still compared, and so
// is every field the seed wrote. Asserted against the gate directly so the
// sequence cases (a transposition needs two blocks; this double's v0 page has
// one) can be stated exactly.
$pristine = new ReflectionMethod('CmsSite', '_pageIsPristineSeed');
$gate = function ($stored, $seeded) use ($pristine, $site) {
    return $pristine->invoke($site, $stored, $seeded);
};
$blk = function ($type, $order, $fields = array(), $enabled = 1) {
    return array('type' => $type, 'order' => $order, 'enabled' => $enabled, 'fields' => $fields);
};
// The two blocks carry the SAME field set on purpose: a transposition then
// differs from the seed in block TYPE and in nothing else, so the case below
// can only pass while the type comparison is really there.
$seedPair = array(
    $blk('rich_text', 10, array('heading' => 'Come play')),
    $blk('cta_band', 20, array('heading' => 'Come play')),
);

check('gate: identical blocks are pristine', $gate($seedPair, $seedPair) === true);
check('gate: the same sequence at shifted order numbers is pristine', $gate(array(
    $blk('rich_text', 30, array('heading' => 'Come play')),
    $blk('cta_band', 40, array('heading' => 'Come play')),
), $seedPair) === true);
check('gate: a TRANSPOSED type sequence is not pristine', $gate(array(
    $blk('cta_band', 10, array('heading' => 'Come play')),
    $blk('rich_text', 20, array('heading' => 'Come play')),
), $seedPair) === false);
check('gate: an EXTRA block is not pristine', $gate(array_merge($seedPair, array(
    $blk('gallery', 30),
)), $seedPair) === false);
check('gate: a MISSING block is not pristine', $gate(array($seedPair[0]), $seedPair) === false);
check('gate: a DISABLED block is not pristine', $gate(array(
    $seedPair[0],
    $blk('cta_band', 20, array('heading' => 'Come play'), 0),
), $seedPair) === false);
check('gate: ONE edited field is not pristine', $gate(array(
    $blk('rich_text', 10, array('heading' => 'Come play with us')),
    $seedPair[1],
), $seedPair) === false);
check('gate: a field the seed wrote and the store lost is not pristine', $gate(array(
    $blk('rich_text', 10, array()),
    $seedPair[1],
), $seedPair) === false);

// (4) A page the new version ADDS is created, and its nav row appended.
$structSite->addPage = true;
$DB->executed  = array();
$DB->execBinds = array();
$DB->queue = array_merge($structHead, array(
    array($blockRow(101, 'rich_text', 10, $editedHome)), // edited: no restructure noise
    array(),                                    // _anyPageBySlug('new-players') — nothing there
    array(),                                    // CreatePage: live-slug dup pre-check
    array(array('rc' => 1)),                    // CreatePage: ROW_COUNT()
    array(array('id' => 88)),                   // CreatePage: authoritative read-back
    array(),                                    // ReplaceBlocks(88): _existingBlockIds
    $insertedCount,
    $insertedRows,
    // ReplaceBlocks' own tail: the revision snapshot's owner-meta read and the
    // post-commit owner-scope read for the audit row.
    array(),
    array(),
    array(array(                                // CmsNav::ListItems — the v0 menu, untouched
        'nav_id' => 500, 'label' => 'Home', 'link_type' => 'page', 'page_id' => 77,
        'post_id' => null, 'url' => null, 'parent_id' => null, 'ordering' => 10, 'enabled' => 1,
        'page_slug' => 'home', 'page_status' => 'published', 'page_published_at' => '2026-01-01 00:00:00',
        'page_deleted_at' => null,
    )),
    array(array('nav_id' => 501)),              // CreateItem's read-back verify
));
$added = $structSite->BackfillSeedContent(55, 99);
check('backfill creates a starter page the new version adds', (int) $added['pages_created'] === 1);
check('the created page is published, in scope, and carries its blocks', (function () use ($DB) {
    $sawPage = false;
    foreach ($DB->executed as $i => $sql) {
        if (stripos($sql, 'INSERT') !== false && stripos($sql, 'cms_page') !== false) {
            $b = $DB->execBinds[$i];
            $sawPage = ($b['slug'] === 'new-players' && $b['status'] === 'published'
                && $b['scope_type'] === 'kingdom' && (int) $b['scope_id'] === 7);
        }
    }
    return $sawPage;
})());
check('backfill appends the new version\'s nav row', (int) $added['nav_added'] === 1
    && $added['nav_reason'] === 'appended');
check('the appended nav row points at the page it just created', (function () use ($DB) {
    foreach ($DB->executed as $i => $sql) {
        if (stripos($sql, 'INSERT INTO') !== false && stripos($sql, 'cms_nav_item') !== false) {
            $b = $DB->execBinds[$i];
            return $b['label'] === 'New to Amtgard?' && (int) $b['page_id'] === 88
                && (int) $b['ordering'] === 20;
        }
    }
    return false;
})());

// (5) A TRASHED page at that slug is a deliberate removal — never re-created.
$DB->executed  = array();
$DB->execBinds = array();
$DB->queue = array_merge($structHead, array(
    array($blockRow(101, 'rich_text', 10, $editedHome)),
    array(array('page_id' => 66, 'slug' => 'new-players', 'deleted_at' => '2026-02-02 00:00:00')),
));
$trashed = $structSite->BackfillSeedContent(55, 99);
check('a TRASHED page at the slug is never re-created', (int) $trashed['pages_created'] === 0);
check('nothing was inserted into cms_page for a trashed slug', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'INSERT') !== false && stripos($sql, 'cms_page') !== false) {
            return false;
        }
    }
    return true;
})());

// (6) A menu the officer rearranged gets NOTHING injected into it.
$DB->executed  = array();
$DB->execBinds = array();
$DB->queue = array_merge($structHead, array(
    array($blockRow(101, 'rich_text', 10, $editedHome)),
    array(),                                    // _anyPageBySlug
    array(),                                    // CreatePage dup pre-check
    array(array('rc' => 1)),
    array(array('id' => 88)),
    array(),                                    // ReplaceBlocks: _existingBlockIds
    $insertedCount,
    $insertedRows,
    array(),                                    // ReplaceBlocks: revision owner-meta read
    array(),                                    // ReplaceBlocks: post-commit owner-scope read
    array(                                      // a menu with the officer's own row in it
        array(
            'nav_id' => 500, 'label' => 'Home', 'link_type' => 'page', 'page_id' => 77,
            'post_id' => null, 'url' => null, 'parent_id' => null, 'ordering' => 10, 'enabled' => 1,
            'page_slug' => 'home', 'page_status' => 'published', 'page_published_at' => null,
            'page_deleted_at' => null,
        ),
        array(
            'nav_id' => 502, 'label' => 'Our Feast Hall', 'link_type' => 'url', 'page_id' => null,
            'post_id' => null, 'url' => 'https://example.org/feast', 'parent_id' => null,
            'ordering' => 20, 'enabled' => 1,
        ),
    ),
));
$navEdited = $structSite->BackfillSeedContent(55, 99);
check('a menu the officer edited gets no row injected', (int) $navEdited['nav_added'] === 0
    && $navEdited['nav_reason'] === 'menu_edited');
check('no nav row was written into an edited menu', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'INSERT INTO') !== false && stripos($sql, 'cms_nav_item') !== false) {
            return false;
        }
    }
    return true;
})());

$structSite->addPage = false;
$DB->queue = array();


// --- SeedContentStatus: the pre-publish readiness read --------------------
// Read-only by contract — it runs on a dashboard GET — and it answers per
// FIELD, not per page: an officer who rewrote Home and never opened About has
// one real page and one template page, and a page count cannot say so. It
// reuses the backfill's own sanitizer-normalized comparison rather than a
// second one, which would drift from the first within a release.
$statusQueue = function ($pages, $blockRowsByPage, $version = 0) use ($parkProbe) {
    $q = array(
        array(array('Field' => 'seed_version')),        // _siteColumnExists
        array(array('seed_version' => $version)),       // the site row
    );
    $q = array_merge($q, $parkProbe);                   // re-derive that version
    $q[] = $pages;                                      // the site's live pages
    foreach ($blockRowsByPage as $rows) {
        $q[] = $rows;                                   // GetBlocksForEditor, per page
    }
    return $q;
};
$DB->queue = $parkProbe;
$parkSeedV0 = $defs->invoke($site, 'park', 1049, null, 0);
$seededHome4 = $storedSeed($parkSeedV0, 'home', 3);     // the intro rich_text
$DB->queue   = array();

$DB->executed  = array();
$DB->execBinds = array();
$editedHome4 = $seededHome4;
$editedHome4['body'] .= '<p>Our own words.</p>';
$DB->queue = $statusQueue(
    array(array('page_id' => 77, 'slug' => 'home', 'title' => 'Home')),
    array(array($blockRow(101, 'rich_text', 40, $seededHome4)))
);
$statusUntouched = $site->SeedContentStatus('park', 1049);
check('SeedContentStatus reports the site version and the current one', (int) $statusUntouched['version'] === 0
    && (int) $statusUntouched['current'] === CmsSite::CURRENT_SEED_VERSION);
check('SeedContentStatus counts a page nobody has touched', (int) $statusUntouched['seeded_pages'] === 1
    && (int) $statusUntouched['untouched_pages'] === 1
    && $statusUntouched['pages'][0]['is_untouched'] === true
    && (int) $statusUntouched['pages'][0]['unedited_fields'] > 0
    && (int) $statusUntouched['pages'][0]['edited_fields'] === 0);
check('SeedContentStatus WRITES NOTHING', $DB->executed === array());

$DB->executed = array();
$DB->queue = $statusQueue(
    array(array('page_id' => 77, 'slug' => 'home', 'title' => 'Home')),
    array(array($blockRow(101, 'rich_text', 40, $editedHome4)))
);
$statusEdited = $site->SeedContentStatus('park', 1049);
check(
    'SeedContentStatus sees one edited field and stops calling the page untouched',
    (int) $statusEdited['untouched_pages'] === 0
    && $statusEdited['pages'][0]['is_untouched'] === false
    && (int) $statusEdited['pages'][0]['edited_fields'] === 1
);
check('SeedContentStatus still writes nothing on the edited path', $DB->executed === array());

// The defect the panel exists to catch: a nav-linked page whose every block
// would publish nothing, so the visitor gets a page containing only its <h1>.
$DB->queue = $statusQueue(
    array(array('page_id' => 77, 'slug' => 'home', 'title' => 'Home')),
    array(array($blockRow(101, 'rich_text', 40, array(
        'kicker' => '', 'heading' => '', 'body' => '<p></p>', 'align' => 'left',
    ))))
);
$statusBlank = $site->SeedContentStatus('park', 1049);
check('SeedContentStatus flags a page that would publish nothing', $statusBlank['pages'][0]['renders_nothing'] === true);
// A live block reads ORK data this class cannot see, so it never counts as blank.
$DB->queue = $statusQueue(
    array(array('page_id' => 77, 'slug' => 'home', 'title' => 'Home')),
    array(array(array(
        'block_id' => 102, 'owner_type' => 'page', 'owner_id' => 77,
        'type' => 'park_events', 'ordering' => 50, 'enabled' => 1,
        'source' => 'dynamic', 'fields_json' => json_encode(array('kicker' => '', 'heading' => '')),
    )))
);
$statusDynamic = $site->SeedContentStatus('park', 1049);
check('a live block is never counted as publishing nothing', $statusDynamic['pages'][0]['renders_nothing'] === false);

// A page the org wrote itself has no seed to compare against — calling it
// "edited" would be as wrong as calling it "untouched".
$DB->queue = $statusQueue(
    array(array('page_id' => 90, 'slug' => 'our-own-page', 'title' => 'Our Own Page')),
    array(array($blockRow(120, 'rich_text', 10, array('body' => '<p>Hi.</p>'))))
);
$statusOwn = $site->SeedContentStatus('park', 1049);
check('SeedContentStatus reports only SEEDED starter pages', $statusOwn['pages'] === array()
    && (int) $statusOwn['seeded_pages'] === 0);
$DB->queue = array();

// Idempotent: a site already at the current version is answered without reading
// a single block.
$DB->executed = array();
$DB->queue    = array(
    array(array('Field' => 'seed_version')),
    array(array(
        'site_id' => 42, 'scope_type' => 'park', 'scope_id' => 1049, 'slug' => 'test-park',
        'seed_version' => CmsSite::CURRENT_SEED_VERSION, 'template_seeded_at' => '2026-01-01 00:00:00',
    )),
);
$again2 = $site->BackfillSeedContent(42, 99);
check('BackfillSeedContent is idempotent (second run is a no-op)', $again2['status'] === 'current');
check('BackfillSeedContent writes nothing on the second run', $DB->executed === array());

// A site whose seed never completed belongs to EnsureSite's repair, not here —
// stamping a version on it would put it permanently out of that repair's reach.
$DB->executed = array();
$DB->queue    = array(
    array(array('Field' => 'seed_version')),
    array(array(
        'site_id' => 43, 'scope_type' => 'park', 'scope_id' => 1049, 'slug' => 'half-built',
        'seed_version' => 0, 'template_seeded_at' => null,
    )),
);
$halfBuilt = $site->BackfillSeedContent(43, 99);
check('BackfillSeedContent skips a site that was never fully seeded', $halfBuilt['status'] === 'skipped'
    && $halfBuilt['reason'] === 'never_seeded');
check('BackfillSeedContent writes nothing for a never-seeded site', $DB->executed === array());
// A skipped run must not report the version it would have landed on — nothing
// landed, so the site is still on the version it arrived with.
check('a skipped backfill reports the version the site is still on', (int) $halfBuilt['to'] === 0
    && (int) $halfBuilt['to'] === (int) $halfBuilt['from']);

// Pre-migration DB: FAIL OPEN. No seed_version column means "treat every site
// as current" — never re-derive content on a guess. Same convention
// template_seeded_at's absence already follows.
$DB->executed = array();
$DB->queue    = array(array()); // _siteColumnExists -> column absent
$noColumn = $site->BackfillSeedContent(42, 99);
check('BackfillSeedContent fails OPEN without the seed_version column', $noColumn['status'] === 'skipped'
    && $noColumn['reason'] === 'no_seed_version_column');
check('BackfillSeedContent writes nothing without the column', $DB->executed === array());

// Nothing may invoke the backfill implicitly: it is explicit-call-only until a
// later wave wires a UI to it.
check(
    'nothing in CmsSite auto-runs the backfill',
    substr_count($classSrc, '$this->BackfillSeedContent(') === 0
);

$DB->queue = array();
$auditMemo->setValue(null, $memoBefore);

// --- Mint slug: a name collision keeps the NAME ---------------------------
// EnsureSite's mint branch used to drop all the way to the scope placeholder
// whenever the org-name-derived slug failed ValidateSlug() — and the likeliest
// reason for that failure is that ANOTHER org already holds the slug. Two orgs
// sharing a display name (a rename, a revived kingdom, a generic park name) is
// not exotic, and the second one was stranded at a permanent, unrecognizable
// /k/kingdom-57 because nothing ever re-derives an existing site's slug.
$uniqueSlug = new ReflectionMethod('CmsSite', '_uniqueSlug');
$DB->queue  = array(
    array(array('site_id' => 5)), // the name-derived slug is taken
    array(),                      // '-2' is free
);
check(
    'a taken org-name slug becomes name-2, not a scope placeholder',
    $uniqueSlug->invoke($site, 'kingdom-of-the-north-wind') === 'kingdom-of-the-north-wind-2'
);
$DB->queue = array(
    array(array('site_id' => 5)),
    array(array('site_id' => 6)),
    array(),
);
check(
    'a second collision walks on to name-3',
    $uniqueSlug->invoke($site, 'kingdom-of-the-north-wind') === 'kingdom-of-the-north-wind-3'
);

// ork_cms_site.slug is VARCHAR(160) and DeriveSlug() clamps to exactly that, so
// a MAXIMAL name-derived slug has no room left for a '-2'. Now that the mint
// branch suffixes the name candidate instead of abandoning it, an over-long
// name that collides would otherwise produce a 162-character slug — silently
// truncated, or a failed INSERT under strict sql_mode, where the old code always
// succeeded. The base is re-clamped to leave room for the suffix.
$longBase = $site->DeriveSlug(str_repeat('northwind', 40));   // 360 chars in, clamped to 160
$DB->queue = array(
    array(array('site_id' => 5)), // the 160-char base is taken
    array(),                      // the clamped '-2' form is free
);
$longUnique = $uniqueSlug->invoke($site, $longBase);
check('a maximal org-name slug is re-clamped before the -2 suffix', strlen($longBase) === 160
    && strlen($longUnique) <= 160 && substr($longUnique, -2) === '-2');
check('the clamp cannot leave a doubled or trailing hyphen', strpos($longUnique, '--') === false);

// Structural: the mint branch must run the name candidate through _uniqueSlug()
// rather than testing it with ValidateSlug() and giving up on failure. The
// scope placeholder stays reachable — for the case it is actually right, where
// NO name could be resolved at all.
$ensureRef  = new ReflectionMethod('CmsSite', 'EnsureSite');
$ensureBody = implode('', array_slice(
    $classLines,
    $ensureRef->getStartLine() - 1,
    $ensureRef->getEndLine() - $ensureRef->getStartLine() + 1
));
check(
    'EnsureSite uniquifies the org-name candidate instead of abandoning it',
    strpos($ensureBody, '$this->_uniqueSlug($candidate)') !== false
        && strpos($ensureBody, 'ValidateSlug($candidate') === false
);
check(
    'EnsureSite still keeps the scope placeholder for an unresolvable name',
    strpos($ensureBody, "DeriveSlug(\$scopeType . '-' . \$scopeId)") !== false
);

// --- Heraldry filename pad width (regression guard) -----------------------
// The zero-pad width of a heraldry filename DIFFERS per scope type — 4 for a
// kingdom, 5 for a park — and _heraldryPath() once hard-coded 5 for both. That
// bug was invisible: a mis-padded probe finds no file, and "no file" is a
// legitimate answer this method must be able to return, so the kingdom colour
// extractor silently produced nothing and every kingdom site fell through to the
// name-hash palette instead of its own arms. These assertions fail loudly if the
// widths ever drift apart again.
check('kingdom heraldry pads to 4 (0007, matches assets/heraldry/kingdom/0007.jpg)', Heraldry::BaseName('kingdom', 7) === '0007');
check('park heraldry pads to 5 (01049, matches assets/heraldry/park/01049.png)', Heraldry::BaseName('park', 1049) === '01049');
check('player heraldry pads to 6', Heraldry::BaseName('player', 123) === '000123');
check('pad widths are NOT uniform across scope types', Heraldry::PadLength('kingdom') !== Heraldry::PadLength('park'));
check('unknown scope type yields no basename rather than a wrong one', Heraldry::BaseName('wombat', 7) === '');

// Behavioral: a REAL file laid down under the kingdom's true 4-wide name must be
// found. Under the old 5-wide code this returns '' and the check fails.
$kingdomFixture = DIR_HERALDRY . '/kingdom/0007.jpg';
$parkFixture    = DIR_HERALDRY . '/park/01049.png';
file_put_contents($kingdomFixture, 'not-really-a-jpeg');
file_put_contents($parkFixture, 'not-really-a-png');

$heraldryPath = new ReflectionMethod('CmsSite', '_heraldryPath');

$DB->queue = array(array(array('has_heraldry' => 1)));
check(
    '_heraldryPath() resolves a kingdom device at its 4-wide name',
    $heraldryPath->invoke($site, 'kingdom', 7) === $kingdomFixture
);

$DB->queue = array(array(array('has_heraldry' => 1)));
check(
    '_heraldryPath() resolves a park device at its 5-wide name',
    $heraldryPath->invoke($site, 'park', 1049) === $parkFixture
);

// has_heraldry is still the gate: a present file with the flag off stays unused.
$DB->queue = array(array(array('has_heraldry' => 0)));
check(
    '_heraldryPath() still gates on has_heraldry, not on the file existing',
    $heraldryPath->invoke($site, 'kingdom', 7) === ''
);

// Fixture teardown — leave the machine exactly as found.
@unlink($kingdomFixture);
@unlink($parkFixture);
@rmdir($heraldryFixtureDir . '/kingdom');
@rmdir($heraldryFixtureDir . '/park');
@rmdir($heraldryFixtureDir);
check('heraldry fixtures cleaned up', !file_exists($heraldryFixtureDir));

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
