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
require __DIR__ . '/../../system/lib/ork3/class.CmsSite.php';
// _seedOrgTheme() consumes both of these — CmsTheme pulls in CmsThemeTokens
// itself via its own require_once.
require __DIR__ . '/../../system/lib/ork3/class.CmsHeraldryColor.php';
require __DIR__ . '/../../system/lib/ork3/class.CmsTheme.php';
// _heraldryPath() reads its zero-pad widths from Heraldry::PAD_LENGTHS rather
// than re-typing them. Only the static side is touched, so the class loads fine
// against the stub Ork3 above without the framework.
require __DIR__ . '/../../system/lib/ork3/class.Heraldry.php';

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
$blockTypes = function ($registry) {
    $out = array();
    foreach ($registry as $def) {
        foreach ($def['blocks'] as $b) {
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
    foreach ($def['blocks'] as $b) {
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
check(
    '_seedOrgTheme() has exactly one call site, feeding that same completion path',
    substr_count($classSrc, '$this->_seedOrgTheme(') === 1
);

// Behavioral guard: the shared completion tail itself really does seed a
// theme BEFORE it stamps the marker — exercised directly via reflection so
// it is provable independent of which branch reaches it.
$finishSeed = new ReflectionMethod('CmsSite', '_finishSeed');

$DB->executed = array();
$DB->queue    = array(
    array(),                                       // _heraldryPath: no device on file
    array(),                                       // _parentKingdomIdForPark: no parent
    array(),                                       // CmsTheme::_themeIdByName probe: no existing row -> INSERT
    array(),                                       // CmsTheme::_themeIdByName readback (post-INSERT)
    array(array('Field' => 'template_seeded_at')), // _stampTemplateSeeded's SHOW COLUMNS probe: column exists
);
$finishSeed->invoke($site, 42, 'park', 1049, 99, 0); // homeId=0 -> _setSeededHomePage is a no-op, no extra DB call

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
