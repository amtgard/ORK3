<?php

// system/lib/ork3/class.CmsSite.php
// The "site" concept for the CMS Multi-Site feature: owns the ork_cms_site
// lifecycle (unbuilt -> draft -> published), addressability (slug), identity
// (name/logo), and the slug derive/validate rules. All page/block/nav/theme
// content still lives in the existing scope-keyed ork_cms_* tables; this class
// only adds the addressable, publishable site row on top of them.
//
// DB idiom (matches class.CmsPage.php): shared global $DB (YapoDb); always
// Clear() before a raw DataSet()/Execute(); bind values via $DB->field = ...
// (the SQL uses :field named placeholders). lastInsertId() is unreliable on
// dup-key under ERRMODE_WARNING, so INSERTs read back by the unique tuple.
//
// CmsSite sorts AFTER class.CmsBase.php alphabetically, so no explicit
// require_once of the base is needed (autoload/scandir loads CmsBase first).
//
// CmsStarterContent sorts AFTER this file, though, so it IS required explicitly
// (same idiom as class.CmsTheme.php -> class.CmsThemeTokens.php). It holds the
// hand-authored starter-website copy this class used to carry inline.

require_once __DIR__ . '/class.CmsStarterContent.php';

class CmsSite extends CmsBase
{
    /**
     * Reserved slugs — every real top-level route plus the pretty-URL prefixes
     * (k, p) and the site controller itself. A site slug that collided with one
     * of these would shadow (or be shadowed by) real routing. Compared
     * case-insensitively against the lowercased slug.
     */
    private const RESERVED_SLUGS = array(
        // pretty-URL prefixes + this feature's own controller
        'k', 'p', 'site',
        // real top-level controllers (orkui/controller/controller.*.php)
        'admin', 'adminajax', 'atlas', 'attendance', 'attendanceajax',
        'authorization', 'award', 'blog', 'calendaritemajax', 'cms', 'cmsajax',
        'directory', 'eraphoenice', 'event', 'eventajax', 'eventembed',
        'eventrsvpajax', 'heraldry', 'kingdom', 'kingdomajax', 'live', 'login',
        'page', 'park', 'parkajax', 'player', 'playerajax', 'principality',
        'qr', 'qualtest', 'qualtestajax', 'recap',
        'releasenotes', 'reports', 'search', 'searchajax', 'selfreg', 'signin',
        'tournament', 'unit', 'unitajax', 'weather', 'wnajax',
        // common infrastructure paths worth reserving defensively
        'api', 'assets', 'static', 'index', 'orkui', 'orkservice', 'www',
    );

    /**
     * Which version of CmsStarterContent a seed writes TODAY, stamped into
     * ork_cms_site.seed_version alongside template_seeded_at.
     *
     * template_seeded_at on its own is a one-way "seeded ever" marker with no
     * version in it, so a site seeded a year ago and a site seeded this morning
     * are indistinguishable — and every copy or layout improvement needed its
     * own bespoke hand-written migration to reach the sites already out there
     * (db-migrations/2026-09-20-cms-kingdom-seed-copy-repair.php is the 162-line
     * example). Recording the version turns that into one generic, repeatable
     * operation: BackfillSeedContent().
     *
     * Deliberately a SITE-level version, not a per-block starter_key. Per-block
     * provenance would be more convenient at backfill time, but it would mean
     * opening CmsPage's block-persistence core (_fetchBlocks / _normalizeBlocks
     * / _upsertKnownBlocks / _insertNewBlocks / _verifyBlockCount /
     * _snapshotRevision) to carry and preserve a new column, and re-deriving the
     * old version's content from CmsStarterContent gives the same answer without
     * touching any of it.
     *
     * Version 0 is "seeded before versioning existed", which is what every site
     * seeded to date received and what the column's DEFAULT 0 (and its ABSENCE,
     * pre-migration) both read as.
     *
     * Version 2 is the kingdom starter redesign (CmsStarterContent::_kingdomV2):
     * a crest-led kingdom_hero and a parks teaser on Home, a 'New to Amtgard?'
     * page, an officer-corps roster in place of the Board of Directors, and a
     * visitor-facing nav with News and an About dropdown. Carrying it to the
     * already-seeded kingdoms is BackfillSeedContent()'s job — no bespoke repair
     * migration — and the runner only rewrites fields nobody has edited.
     */
    public const CURRENT_SEED_VERSION = 2;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Clamp a scope-type to the site enum. Unlike the base helper (which allows
     * 'global'), a site is only ever 'kingdom' or 'park'; anything else falls
     * back to 'kingdom'.
     *
     * @param string $scopeType
     * @return string 'kingdom'|'park'
     */
    private function _normalizeSiteScopeType($scopeType)
    {
        return ((string)$scopeType === 'park') ? 'park' : 'kingdom';
    }

    /**
     * Bust the GetSiteBySlug cross-request cache for a single slug. Called from
     * every site mutator that can change (or newly claim) a slug so the public
     * /k/{slug} resolver never serves a stale row. A no-op when the slug is empty
     * or the cache layer isn't wired up.
     *
     * @param string $slug already-stored slug (case as persisted)
     * @return void
     */
    private function _bustSlugCache($slug)
    {
        $slug = (string)$slug;
        if ($slug === '') {
            return;
        }
        $cache = $this->_ghettoCache();
        if ($cache !== null) {
            $cache->bust(__CLASS__ . '.GetSiteBySlug', $slug);
        }
    }

    /**
     * Read the currently-stored slug for a site id (empty string when the row is
     * missing). Used by the mutators to know which cache key to bust.
     *
     * @param int $siteId
     * @return string
     */
    private function _slugForSite($siteId)
    {
        global $DB;

        $siteId = (int)$siteId;
        if ($siteId <= 0) {
            return '';
        }
        $DB->Clear();
        $DB->site_id = $siteId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT slug FROM ' . DB_PREFIX . 'cms_site WHERE site_id = :site_id LIMIT 1'
        ));
        return ($row !== null && isset($row['slug'])) ? (string)$row['slug'] : '';
    }

    /**
     * Public resolver: the site row for a slug, or null. Used by the public
     * router to map /k/{slug} -> (scope_type, scope_id).
     *
     * @param string $slug
     * @return array|null
     */
    public function GetSiteBySlug($slug)
    {
        global $DB;

        // Normalize to the slug charset so a caller can't smuggle anything
        // beyond [a-z0-9-] into the lookup.
        $slug = preg_replace('/[^a-z0-9\-]+/', '', strtolower((string)$slug));
        if ($slug === '') {
            return null;
        }

        // Cross-request GhettoCache keyed by slug. This is the public
        // /k/{slug} router hot path (one lookup per anonymous pageview), yet the
        // row changes only on an officer mutation. Cache the resolved row and let
        // the mutators (UpdateSite/SetPublished/SetDraft/EnsureSite) bust the key.
        // Only POSITIVE hits are cached — a miss (unknown slug) is the 404 path
        // and stays uncached so a later provision is seen immediately; is_array()
        // distinguishes a cached row from memcached's false-on-miss.
        $cache = $this->_ghettoCache();
        if ($cache !== null) {
            $cached = $cache->get(__CLASS__ . '.GetSiteBySlug', $slug, 1800);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $DB->Clear();
        $DB->slug = $slug;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'cms_site WHERE slug = :slug LIMIT 1'
        ));

        if ($cache !== null && is_array($row)) {
            $cache->cache(__CLASS__ . '.GetSiteBySlug', $slug, $row);
        }
        return $row;
    }

    /**
     * Admin lookup: the single site row for an org scope, or null.
     *
     * @param string $scopeType 'kingdom'|'park'
     * @param int    $scopeId
     * @return array|null
     */
    public function GetSiteForScope($scopeType, $scopeId)
    {
        global $DB;

        $scopeType = $this->_normalizeSiteScopeType($scopeType);
        $scopeId   = (int)$scopeId;

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        return $this->_firstRow($DB->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'cms_site'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id LIMIT 1'
        ));
    }

    /**
     * Lazily create the org's site row (status='unbuilt') if none exists, then
     * return the row. Idempotent — a second call returns the existing row and
     * performs no INSERT.
     *
     * On FIRST creation it also seeds the starter template (home + About/History
     * + Our Parks + Officers + Documents pages, a scoped 'marketing' nav menu, and
     * home_page_id → the seeded home) via _seedStarterTemplate($isRepair=false).
     *
     * The seed can ALSO re-enter on an existing row, but only through the repair
     * gate below: it runs when template_seeded_at IS NULL, i.e. the row has no
     * record of a completed seed. That call passes $isRepair=true, which makes the
     * seed non-destructive — deliberately-trashed starter pages stay trashed and an
     * org's chosen home page is never re-pointed. A row whose marker is set (or
     * whose column is absent, pre-migration) is treated as seeded and skips it, so
     * emptying your nav or trashing a starter page can no longer resurrect them.
     * The seed is fully editable/deletable — a seed, not a cage.
     *
     * @param string $scopeType 'kingdom'|'park'
     * @param int    $scopeId
     * @param int    $uid       acting mundane_id (audit)
     * @return array|null the site row (existing or freshly created), or null on failure
     */
    public function EnsureSite($scopeType, $scopeId, $uid)
    {
        global $DB;

        $scopeType = $this->_normalizeSiteScopeType($scopeType);
        $scopeId   = (int)$scopeId;
        $uid       = (int)$uid;

        // Refuse to mint a site for an unresolved scope — a 0/blank scope id
        // would otherwise create a junk ('kingdom', 0) row (slug 'kingdom-0')
        // that occupies the unique scope slot. Callers must resolve scope first.
        if ($scopeId <= 0) {
            return null;
        }

        // Rollout policy. Refuse to MINT a new site for a level that has not been
        // enabled — but only for the mint path: the existing-row branch below is
        // reached first for any site that already exists, so switching a toggle
        // back off never disturbs a site that is already built or published.
        if (!$this->CanCreateSite($scopeType, $scopeId)
            && $this->GetSiteForScope($scopeType, $scopeId) === null
        ) {
            return null;
        }

        // Finer-grained idempotency. A site row can exist while its starter
        // template is only PARTIALLY seeded — a first-run that died mid-seed, or a
        // pre-seed legacy row — leaving the nav menu empty and/or home_page_id
        // unset. Rather than short-circuit on "row exists" (which permanently
        // strands such a site with dead nav and a "being built" home), re-run the
        // seed for the missing pieces.
        //
        // The repair MUST be gated on an EXPLICIT "was this site ever seeded?"
        // marker, never inferred from live content state. Inferring it from
        // "nav menu is empty" / "home_page_id is unset" cannot tell "never
        // seeded" apart from "the officers deliberately emptied it", so an org
        // that deleted the five seeded 'marketing' nav links got them silently
        // re-inserted (CreateItem is not UNIQUE-guarded) on the next dashboard
        // load or Publish. ork_cms_site.template_seeded_at
        // (db-migrations/2026-08-09-cms-site-seed-marker.sql) is that marker:
        // stamped once at the end of a successful seed, so a seeded-then-emptied
        // site is never re-seeded. When the column is not present yet (migration
        // not run), treat the site as seeded — never re-seed on a guess.
        $existing = $this->GetSiteForScope($scopeType, $scopeId);
        if ($existing !== null) {
            $existingId = isset($existing['site_id']) ? (int)$existing['site_id'] : 0;
            $seeded     = !array_key_exists('template_seeded_at', $existing)
                || !empty($existing['template_seeded_at']);
            if ($existingId > 0 && !$seeded) {
                $this->_seedStarterTemplate($existingId, $scopeType, $scopeId, $uid, true);
                $existing = $this->GetSiteForScope($scopeType, $scopeId);
                // Only the repair branch can change the cached row; the plain
                // "row already exists and is seeded" path is a pure READ, and
                // busting there evicted this org's public /k/{slug} entry on
                // every CMS-dashboard load.
                $this->_bustSlugCache(
                    ($existing !== null && isset($existing['slug'])) ? (string)$existing['slug'] : ''
                );
            }
            return $existing;
        }

        // A site row must carry a globally-unique slug (UNIQUE key). Prefer the
        // org's REAL display name for both the site name and the slug — a brand
        // new site should read "Kingdom of the Burning Lands" / /k/kingdom-of-the-
        // burning-lands, not a blank name at /k/kingdom-42. Mint-new-row path
        // ONLY — an existing site's slug is never touched here.
        //
        // A COLLISION IS NOT A REASON TO ABANDON THE NAME. This used to drop
        // straight to the scope placeholder whenever the name-derived candidate
        // failed ValidateSlug() — and the overwhelmingly likely reason for that
        // failure is that ANOTHER org already holds the slug, which two orgs
        // sharing a display name (a rename, a revived kingdom, a generic park
        // name) is not exotic. The second one was then stranded at a permanent,
        // unrecognizable /k/kingdom-57, because nothing anywhere ever re-derives
        // an existing site's slug. Running the candidate through _uniqueSlug()
        // yields 'kingdom-of-the-north-wind-2' instead: still the org's own
        // name, still unique, still readable.
        //
        // The scope placeholder is reserved for the one case it is actually the
        // right answer — NO name could be resolved at all (or it slugified to
        // nothing, e.g. a name that is entirely punctuation).
        $orgName = $this->_orgDisplayName($scopeType, $scopeId);
        $slug    = '';
        if ($orgName !== '') {
            $candidate = $this->DeriveSlug($orgName);
            if ($candidate !== '') {
                $slug = $this->_uniqueSlug($candidate);
            }
        }
        if ($slug === '') {
            $slug = $this->_uniqueSlug($this->DeriveSlug($scopeType . '-' . $scopeId));
        }

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $DB->slug       = $slug;
        // YapoSave null-skip rule: assign '' (not null) so the column is written.
        $DB->site_name  = $orgName;
        $DB->created_by = $uid;
        $DB->updated_by = $uid;
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'cms_site'
            . ' (scope_type, scope_id, slug, site_name, status, created_by, updated_by)'
            . " VALUES (:scope_type, :scope_id, :slug, :site_name, 'unbuilt', :created_by, :updated_by)"
        );

        // Read back by the unique (scope_type, scope_id) tuple rather than
        // trusting lastInsertId() (unreliable on dup-key under ERRMODE_WARNING).
        $created = $this->GetSiteForScope($scopeType, $scopeId);

        // FIRST-CREATION path: seed the starter template (pages + blocks + nav)
        // and point home_page_id at the seeded home page. This line is reached
        // exclusively when no prior row existed. It is NOT the only seed call —
        // the repair branch above re-enters the seed when template_seeded_at is
        // still NULL — so $isRepair is passed FALSE here: on a first-ever seed a
        // pre-existing trashed page at a starter slug is not a decision about
        // seeded content (there was no prior seed to remove from), and the page
        // must still be created. UNIQUE(scope_type, scope_id, slug_live) permits
        // the new live row alongside the trashed ones.
        if ($created !== null && isset($created['site_id'])) {
            $this->_seedStarterTemplate((int) $created['site_id'], $scopeType, $scopeId, $uid, false);
            // Re-read so the returned row carries the freshly-set home_page_id.
            $created = $this->GetSiteForScope($scopeType, $scopeId);
        }

        // Newly-claimed slug: bust any negative-lookup absence a racing reader
        // might otherwise re-cache (defensive — misses aren't cached today).
        if ($created !== null && isset($created['slug'])) {
            $this->_bustSlugCache((string)$created['slug']);
        }

        return $created;
    }

    /**
     * Seed the starter template for a site: five editable pages (home, about,
     * parks, officers, documents), a scoped 'marketing' nav menu linking them,
     * and home_page_id → the seeded home. Pages are seeded PUBLISHED in the
     * site's OWN scope (see $baseAttrs) — the SITE-level status
     * (unbuilt→draft→published) is the real go-live gate, so nothing is public
     * until an AUTH_ADMIN officer publishes the site (Phase 3). Everything is
     * editable: only the home page is is_system=1 (undeletable, so the site
     * always retains a landing page); the rest can be freely edited or deleted —
     * a seed, not a cage.
     *
     * Invoked from BOTH EnsureSite branches: the create branch (right after the
     * INSERT) and the repair branch (an existing row whose
     * template_seeded_at is still NULL). $isRepair tells the two apart, because
     * "the org deliberately trashed this starter page" is only meaningful
     * relative to a seed that already happened:
     *
     *   - $isRepair = true  — a TRASHED page at a starter slug is a removal
     *     decision about previously-seeded content: leave it trashed, create no
     *     replacement, and drop its nav item.
     *   - $isRepair = false — first-ever seed: a trashed row at that slug
     *     predates any seed and is NOT such a decision, so the page is created
     *     normally (UNIQUE(scope_type, scope_id, slug_live) allows the live row
     *     alongside trashed ones).
     *
     * Idempotency: the marker gate in EnsureSite keeps the repair to at most one
     * pass per site. As belt-and-suspenders, CmsPage's CreatePage self-guards the
     * live-slug uniqueness tuple (returns 0 on collision) — so even a defensive
     * re-entry cannot duplicate a page or clobber an officer's later edits;
     * $makePage() recovers the existing id on collision so nav + home_page_id
     * still link.
     *
     * Content goes through the CmsPage/CmsNav libs only — NO raw SQL here.
     *
     * @param int    $siteId    the site row id (target of home_page_id)
     * @param string $scopeType 'kingdom'|'park'
     * @param int    $scopeId
     * @param int    $uid       acting mundane_id (audit)
     * @param bool   $isRepair  true only from EnsureSite's repair branch
     * @return void
     */
    private function _seedStarterTemplate($siteId, $scopeType, $scopeId, $uid, $isRepair = false)
    {
        // Content libs must be loaded (they are, via the ork3 scandir autoload).
        // If not, leave the bare site row rather than fatal.
        if (!class_exists('CmsPage') || !class_exists('CmsNav')) {
            return;
        }

        $page = new CmsPage();
        $now  = date('Y-m-d H:i:s');

        // The starter page registry — ONE declaration driving both the page seed
        // below and the nav menu further down, so the two cannot drift apart.
        // Built once here: array order IS nav order.
        $starters = $this->_starterPageDefs($scopeType, $scopeId);

        // Attributes shared by every seeded page. Seed as PUBLISHED: the
        // site-level status (unbuilt→draft→published) is the real go-live gate —
        // nothing is public until an AUTH_ADMIN officer publishes the SITE — and
        // the public renderer only shows published pages, so draft starter pages
        // would leave a just-published site showing "being built" with dead nav
        // links. Published starter pages make go-live coherent; an officer can
        // unpublish any individual page they aren't ready to show.
        $baseAttrs = array(
            'status'       => 'published',
            'published_at' => $now,
            'scope_type'   => $scopeType,
            'scope_id'     => $scopeId,
            'created_by'   => $uid,
            'updated_by'   => $uid,
            'created_at'   => $now,
            'updated_at'   => $now,
        );

        // Create one page + attach its blocks; returns the new page_id (0 on
        // hard failure, and — on a REPAIR pass only — 0 when a prior copy of this
        // starter page was TRASHED).
        //
        // The pre-check looks up the slug INCLUDING soft-deleted rows. The live
        // uniqueness key is UNIQUE(scope_type, scope_id, slug_live) and slug_live
        // is NULL for a trashed row (2026-07-08-cms-slug-live-and-integrity.sql),
        // so CreatePage's collision guard does NOT fire against a trashed page —
        // without this check a repair pass would mint a BRAND NEW published page
        // full of seed placeholder copy for a page the kingdom deliberately
        // deleted.
        //
        // That skip is gated on $isRepair. On a FIRST-EVER seed there was no
        // prior seed, so a trashed row at a starter slug is just pre-existing
        // content (trashed 'about'/'documents' rows accumulate under a scope as
        // soon as officers use the CMS) — skipping would permanently strand the
        // new site with dead nav and, for 'home', a NULL home_page_id that the
        // now-stamped marker makes unrepairable. So on the create path we fall
        // through to CreatePage exactly as before.
        //
        // A LIVE match always yields its existing id (either path) so nav +
        // home_page_id still resolve.
        $makePage = function ($attrs, $blocks) use ($page, $baseAttrs, $scopeType, $scopeId, $isRepair) {
            $slug = isset($attrs['slug']) ? (string) $attrs['slug'] : '';
            if ($slug !== '') {
                $prior = $this->_anyPageBySlug($slug, $scopeType, $scopeId);
                if ($prior !== null) {
                    if (!empty($prior['deleted_at'])) {
                        if ($isRepair) {
                            return 0; // deliberately trashed since the seed — never re-create it
                        }
                        // First-ever seed: not a removal decision — create it.
                    } else {
                        return isset($prior['page_id']) ? (int) $prior['page_id'] : 0;
                    }
                }
            }
            $pid = (int) $page->CreatePage(array_merge($baseAttrs, $attrs));
            if ($pid <= 0) {
                $row = ($slug !== '') ? $page->GetPageBySlug($slug, $scopeType, $scopeId, false) : null;
                return ($row !== null && isset($row['page_id'])) ? (int) $row['page_id'] : 0;
            }
            if (is_array($blocks) && count($blocks) > 0) {
                $page->ReplaceBlocks('page', $pid, $blocks);
            }
            return $pid;
        };

        // Seed every starter page in registry order. $pageIds is slug-keyed so
        // the nav loop below can look each id up by slug (0 = didn't seed).
        $pageIds = array();
        foreach ($starters as $starterSlug => $starterDef) {
            // A NAV-ONLY registry entry (no 'attrs') seeds a menu item pointing
            // at a route this site already has rather than a page of its own —
            // the News item on the org's existing blog route. It has nothing to
            // create here, and it must not be audited as a failed page seed.
            if (!isset($starterDef['attrs'])) {
                continue;
            }
            $pageIds[$starterSlug] = $makePage($starterDef['attrs'], $starterDef['blocks']);

            // AUDIT EVERY SEED OUTCOME, not just the theme's. A page that fails
            // to create leaves no trace anywhere — CreatePage returns falsy
            // rather than throwing under PDO::ERRMODE_WARNING — so an operator
            // investigating a half-seeded site previously had no log line and no
            // lever but raw SQL. The slug rides in the action string because
            // ork_cms_audit has no free-text column.
            //
            // On a REPAIR pass a 0 can also mean "this starter page was
            // deliberately trashed since the seed, so it was intentionally not
            // re-created" — a different event, hence a different verb.
            if ((int) $pageIds[$starterSlug] <= 0) {
                $this->_cmsAudit(
                    (int) $uid,
                    ($isRepair ? 'seed_page_skipped:' : 'seed_page_failed:') . $starterSlug,
                    'page',
                    0,
                    $scopeType,
                    $scopeId
                );
            }
        }
        $homeId = isset($pageIds['home']) ? (int) $pageIds['home'] : 0;

        // ---- Scoped nav menu ('marketing' — the key org_header.tpl reads) ----
        if (!$this->_seedNavMenu($siteId, $scopeType, $scopeId, $starters, $pageIds)) {
            // Site row vanished under the lock — nothing to finish. Audited
            // because the abort is otherwise completely silent (it returns false
            // and writes nothing), and it leaves the site with pages but no nav.
            $this->_cmsAudit((int) $uid, 'seed_nav_aborted', 'site', (int) $siteId, $scopeType, $scopeId);
            return;
        }

        $this->_finishSeed($siteId, $scopeType, $scopeId, $uid, $homeId, $isRepair);
    }

    /**
     * The nav critical section of _seedStarterTemplate(), extracted so the
     * transaction/row-lock discipline lives in one small method of its own.
     *
     * link_type='page' so items follow slug changes; org_header re-points the
     * resolved Page/view href onto this site's own /Site/page/ route. Same
     * scope as the pages so CmsNav's scope-bound page join resolves the slug.
     *
     * CreateItem is NOT UNIQUE-guarded (unlike CreatePage), so the previous
     * check-then-insert guard on an empty menu was a TOCTOU: two concurrent
     * first-load EnsureSite calls could BOTH read the menu empty and BOTH seed
     * it, duplicating every nav row. Close the race with a real concurrency
     * guard. Both racing calls resolve to the SAME site row (the DB
     * UNIQUE(scope) key collapses their site INSERTs to one), so serialize them
     * on that row: open a transaction and SELECT ... FOR UPDATE the site row
     * BEFORE the empty-menu check. The first seeder holds the lock, finds the
     * menu empty, inserts, and COMMITs (releasing the lock); the second then
     * acquires the lock, sees the now-non-empty menu, and skips.
     *
     * Only the nav critical section is transacted — NOT the CmsPage seed in the
     * caller, which issues its own COMMITs (ReplaceBlocks) that would
     * prematurely release an outer lock. The nav inserts (CmsNav::CreateItem)
     * run plain Execute()s with no inner transaction, so they nest cleanly here.
     *
     * @param int    $siteId
     * @param string $scopeType 'kingdom'|'park'
     * @param int    $scopeId
     * @param array  $starters  the starter-page registry (array order = nav order)
     * @param array  $pageIds   slug-keyed seeded page ids (0 = didn't seed)
     * @return bool false only when the site row vanished under the lock
     */
    private function _seedNavMenu($siteId, $scopeType, $scopeId, $starters, $pageIds)
    {
        global $DB;

        $nav = new CmsNav();

        $DB->Clear();
        $DB->Execute('START TRANSACTION');

        // Defensive: anything throwing between here and the COMMIT would
        // otherwise leave this transaction — and the FOR UPDATE row lock below —
        // open for the rest of the request. Roll back explicitly, then rethrow.
        try {
            // Row-lock the site row so concurrent first-loads serialize at this point.
            $DB->Clear();
            $DB->site_id = (int) $siteId;
            $lockRow = $this->_firstRow($DB->DataSet(
                'SELECT site_id FROM ' . DB_PREFIX . 'cms_site WHERE site_id = :site_id LIMIT 1 FOR UPDATE'
            ));
            if ($lockRow === null) {
                // Site row vanished (shouldn't happen on the create path) — abort the
                // seed cleanly rather than insert orphaned nav rows.
                $DB->Clear();
                $DB->Execute('ROLLBACK');
                return false;
            }

            // Now-safe empty-menu check: we hold the row lock, so no racing seeder can
            // interleave between this read and our inserts+COMMIT below.
            $existingNav = $nav->ListItems('marketing', $scopeType, $scopeId);
            if (is_array($existingNav) && count($existingNav) > 0) {
                // Menu already seeded (or hand-edited) — leave it untouched. Release the
                // lock before UpdateSite (its own statement) still sets home_page_id.
                $DB->Clear();
                $DB->Execute('COMMIT');
                return true;
            }

            // Same registry, same order. TWO PASSES, because a child's row needs
            // its parent's nav_id and CreateItem hands that back only after the
            // parent is inserted. Top-level items keep the 10, 20, 30… ordering
            // they always had; children are ordered 10, 20… within their parent,
            // which is the scale CmsNav::ReorderItems() uses per level.
            //
            // A registry entry may declare:
            //   'nav_parent' => '<registry key>'  seed me under that item
            //   'nav_link'   => array('link_type' => …, 'url' => …)
            //                                     a nav item with no page of its
            //                                     own (the News blog route)
            // Anything else is the page link the seed has always written.
            $navIds   = array();
            $ordering = 0;
            $navRow = function ($starterDef, $navPageId) use ($scopeType, $scopeId) {
                $link = isset($starterDef['nav_link']) && is_array($starterDef['nav_link'])
                    ? $starterDef['nav_link'] : array();
                return array(
                    'menu'       => 'marketing',
                    'label'      => $starterDef['nav_label'],
                    'link_type'  => isset($link['link_type']) ? (string) $link['link_type'] : 'page',
                    'page_id'    => ($navPageId > 0) ? $navPageId : null,
                    'url'        => isset($link['url']) ? (string) $link['url'] : null,
                    'enabled'    => 1,
                    'scope_type' => $scopeType,
                    'scope_id'   => $scopeId,
                );
            };
            // A nav item is seedable when it has a page that really landed, or
            // when it carries its own link and needs no page at all. A starter
            // page that failed to seed still gets no menu row.
            $navSeedable = function ($starterSlug, $starterDef) use ($pageIds) {
                if (isset($starterDef['nav_link'])) {
                    return true;
                }
                return isset($pageIds[$starterSlug]) && (int) $pageIds[$starterSlug] > 0;
            };

            foreach ($starters as $starterSlug => $starterDef) {
                if (!empty($starterDef['nav_parent']) || !$navSeedable($starterSlug, $starterDef)) {
                    continue;
                }
                $ordering += 10;
                $navIds[$starterSlug] = (int) $nav->CreateItem($navRow(
                    $starterDef,
                    isset($pageIds[$starterSlug]) ? (int) $pageIds[$starterSlug] : 0
                ) + array('parent_id' => null, 'ordering' => $ordering));
            }

            $childOrdering = array();
            foreach ($starters as $starterSlug => $starterDef) {
                if (empty($starterDef['nav_parent']) || !$navSeedable($starterSlug, $starterDef)) {
                    continue;
                }
                $parentKey = (string) $starterDef['nav_parent'];
                // A parent that never landed (its own page failed to seed) would
                // orphan the child into an invisible branch — CmsNav renders only
                // items whose parent resolves — so promote it to top level rather
                // than lose it.
                $parentId = isset($navIds[$parentKey]) ? (int) $navIds[$parentKey] : 0;
                if ($parentId <= 0) {
                    $ordering += 10;
                    $navIds[$starterSlug] = (int) $nav->CreateItem($navRow(
                        $starterDef,
                        isset($pageIds[$starterSlug]) ? (int) $pageIds[$starterSlug] : 0
                    ) + array('parent_id' => null, 'ordering' => $ordering));
                    continue;
                }
                if (!isset($childOrdering[$parentKey])) {
                    $childOrdering[$parentKey] = 0;
                }
                $childOrdering[$parentKey] += 10;
                $navIds[$starterSlug] = (int) $nav->CreateItem($navRow(
                    $starterDef,
                    isset($pageIds[$starterSlug]) ? (int) $pageIds[$starterSlug] : 0
                ) + array('parent_id' => $parentId, 'ordering' => $childOrdering[$parentKey]));
            }

            // Commit the nav inserts (releasing the row lock) BEFORE the home_page_id
            // write so the lock isn't held across UpdateSite's own UPDATE.
            $DB->Clear();
            $DB->Execute('COMMIT');
        } catch (Throwable $e) {
            $DB->Clear();
            $DB->Execute('ROLLBACK');
            throw $e;
        }

        return true;
    }

    /**
     * The single shared tail of every _seedStarterTemplate() completion path:
     * point the site at its seeded home page, seed+activate its palette, THEN
     * stamp the one-way "seeded" marker.
     *
     * _seedStarterTemplate() has TWO completion branches — the early return
     * taken when the nav menu is found already non-empty under the row lock
     * (a TOCTOU race between two concurrent first-loads, or a legacy
     * pre-migration row being repaired), and the normal end-of-method path
     * after this method's own nav inserts. Both funnel through here, in this
     * order: home page, theme, marker. That is what keeps the class of drift
     * out: there is exactly ONE call site for _stampTemplateSeeded() in the
     * whole class, so the PERMANENT one-way marker can never be stamped
     * without _seedOrgTheme() having run immediately before it, which would
     * leave a site seeded with no theme row and no way to re-seed one.
     *
     * CONDITIONAL: the marker is stamped only when the seed actually produced
     * the page that matters — the is_system landing page ($homeId > 0). The
     * marker is PERMANENT and EnsureSite's repair branch is gated exclusively on
     * template_seeded_at IS NULL, so stamping a seed whose home page never
     * landed left the site instantly and permanently unrepairable, with
     * home_page_id NULL and a "being built" landing page no amount of dashboard
     * loads could fix. Withholding the stamp costs one retry on the next
     * EnsureSite; stamping a broken seed costs raw SQL.
     *
     * ACCEPTED COST of withholding it: while the home page keeps failing to
     * seed, every dashboard GET re-runs the whole seed (row-locked nav
     * transaction and theme step included) and writes two ork_cms_audit rows.
     * That is the intended retry, and it cannot be provoked by an org deleting
     * its home page — CmsPage refuses to trash an is_system page — so it only
     * runs on a genuinely broken database, where the retry is what you want.
     *
     * The THEME step is deliberately NOT part of that condition — a failing
     * theme write would otherwise re-run the entire starter seed (row lock
     * included) on every dashboard load for as long as it kept failing. It is
     * audited instead (see _seedOrgTheme).
     *
     * @param int    $siteId
     * @param string $scopeType 'kingdom'|'park'
     * @param int    $scopeId
     * @param int    $uid       acting mundane_id (audit)
     * @param int    $homeId    seeded home page_id (0 when it didn't seed)
     * @param bool   $isRepair  true only from EnsureSite's repair branch
     * @return void
     */
    private function _finishSeed($siteId, $scopeType, $scopeId, $uid, $homeId, $isRepair = false)
    {
        // ---- Point the site's landing page at the seeded home ----
        $this->_setSeededHomePage($siteId, $homeId, $uid);

        // Palette before the marker: a site that fails mid-seed should not be
        // stamped as seeded, and the theme is part of "seeded". A failed theme
        // write does NOT withhold the marker, though — that would re-run the
        // whole starter seed (row lock included) on every dashboard load for as
        // long as the write kept failing; it is audited instead (see
        // _seedOrgTheme).
        $this->_seedOrgTheme($scopeType, $scopeId, $uid, $isRepair);

        // The landing page is the one piece the site cannot be left without:
        // without it home_page_id stays NULL and the public site is a permanent
        // "being built" interstitial. Leave template_seeded_at NULL so the next
        // EnsureSite re-enters the repair, and leave a trail saying why.
        if ((int) $homeId <= 0) {
            $this->_cmsAudit((int) $uid, 'seed_incomplete_unstamped', 'site', (int) $siteId, $scopeType, $scopeId);
            return;
        }

        // Seed complete — stamp the marker so this site is never re-seeded, no
        // matter how much of the seeded content the org later deletes.
        $this->_stampTemplateSeeded($siteId);
    }

    /**
     * The starter-page registry for _seedStarterTemplate(): a slug-keyed list of
     * ['nav_label', 'attrs', 'blocks'] in the order the pages are seeded AND the
     * order their nav items appear. ARRAY ORDER IS LOAD-BEARING.
     *
     * Single source of truth: the seed loop and the nav loop both read this, so
     * the page list and the menu can no longer drift apart.
     *
     * A THIN ADAPTER, not the copy itself. The authored HTML bodies, FAQ items,
     * page list and CTA wording all live in CmsStarterContent — see that file's
     * header for why public marketing copy does not belong inside the class that
     * owns the site lifecycle, and for how to change it. This method's whole job
     * is to resolve the RUNTIME-dependent values that copy needs (the org's noun
     * and display name, the park's own description and URL, the sanitizer, the
     * block registry's starter_fields, the stable self-href form, the parks-list
     * ceiling) and hand them over.
     *
     * SCOPE-AWARE, and it must stay that way. A park scope gets its OWN
     * three-page registry built on the park_* blocks (including park_meeting,
     * the most useful block on a park page) and no parks page, rather than
     * sharing the kingdom template: the kingdom-scoped dynamic blocks
     * (kingdom_events, kingdom_parks, kingdom_parks_map, kingdom_officers)
     * each correctly render NOTHING outside a kingdom scope, so seeding them
     * into a park site fails SILENTLY — blank pages, no error anywhere. The two
     * scopes resolve DIFFERENT runtime values, which is why the park branch
     * returns before any of the kingdom scaffolding is built.
     *
     * Copy uses CmsSite::OrgUnitNoun() so a principality reads "Principality" and a
     * park reads "Park" instead of every org being told it is a kingdom.
     *
     * NOT a static const: the authored HTML bodies must pass through
     * CmsSanitizer::Clean() exactly the way the editor save path does, which is a
     * runtime call. 'attrs' carries only the per-page attributes — the shared
     * ones (status/published_at/scope/audit stamps) are merged in by $makePage.
     *
     * @param string $scopeType 'kingdom' | 'park'
     * @param int    $scopeId   owning org id
     * @param string|null $orgName the org's resolved display name, so the
     *   kingdom meta descriptions and the About body can name the org. NO CALLER
     *   PASSES IT TODAY — the only call site is _seedStarterTemplate(), which
     *   does not have it, so it resolves here via _orgDisplayName(). The
     *   parameter exists for a caller that already holds the name (EnsureSite
     *   does, at its mint branch) and wants to save the re-query.
     * @param int|null $version which CmsStarterContent version to build. null
     *   means CURRENT_SEED_VERSION — what a seed writes today. BackfillSeedContent()
     *   is the only caller that passes an older one, to re-derive what a site
     *   was originally seeded with.
     *
     * PROTECTED, not private, and only for one reason: it is the seam the
     * backfill's own test suite injects a genuinely-different content version
     * through. Today every shipped version builds identical copy (V1 delegates
     * to V0), so a test that used the real versions could not tell a working
     * byte-match gate from a missing one — both write nothing. A test double
     * overrides this method to make the NEW version's copy actually differ, and
     * then asserts both directions: an untouched seeded field IS upgraded, an
     * officer-edited one is NOT. Nothing in the application subclasses CmsSite.
     *
     * @return array slug => array{nav_label:string, attrs:array, blocks:array}
     */
    protected function _starterPageDefs($scopeType, $scopeId, $orgName = null, $version = null)
    {
        $scopeType = (string) $scopeType;
        $isPark    = ($scopeType === 'park');
        $version   = ($version === null) ? self::CURRENT_SEED_VERSION : (int) $version;

        // Sanitize authored HTML bodies exactly the way the editor save path does.
        $clean = function ($html) {
            return class_exists('CmsSanitizer') ? CmsSanitizer::Clean($html) : (string) $html;
        };

        // A park is not a small kingdom — it gets its OWN three-page design, and
        // its own runtime inputs. Returns early: the kingdom scaffolding below
        // (noun, org label, meta clamp, starter_fields) must never be built for
        // a park, which is exactly why the two scopes never shared a code path.
        if ($isPark) {
            $uir = defined('UIR') ? UIR : 'index.php?Route=';

            // The steps CTA on the park home page links to this SAME site's own
            // 'new-players' page. A bare relative 'new-players' href 404s:
            // Controller_Page::view() is hard-coded to scope_type='global', so it
            // can never resolve a park-scoped page on its own.
            //
            // _sitePageHref() deliberately does NOT bake in this site's current
            // slug here at seed time — an earlier version did, and it went stale
            // (dead 404) the instant an officer renamed their site via
            // UpdateSite(), because nothing re-visits already-seeded block
            // content on a rename. Instead it seeds the SAME global
            // 'Page/view/{pageSlug}' form CmsNav already resolves page links to.
            // steps.tpl then rewrites that at RENDER time, using the CURRENT
            // $SiteSlug (fdSiteInternalHref() in frontdoor/_helpers.tpl) — the
            // exact mechanism org_header.tpl's $orgHref already uses to keep nav
            // links working across a rename. A slug rename now fixes this link
            // everywhere at once, same guarantee nav already had.
            $newPlayersHref = $this->_sitePageHref('new-players');

            // Both park lookups hit the DB, in this order, and both are seed-time
            // SNAPSHOTS of live ORK data — see each method's docblock for the
            // invariants they enforce before letting that data onto a public page.
            return CmsStarterContent::Registry('park', array(
                'clean'            => $clean,
                'new_players_href' => $newPlayersHref,
                'atlas_href'       => $uir . 'Atlas',
                'park_intro_body'  => $this->_parkIntroBody($scopeId),
                'park_cta_fields'  => $this->_parkCtaFields($scopeId),
            ), $version);
        }

        // "Kingdom" / "Principality" / "Park" — the org's own word for itself.
        // Falls back to a neutral noun rather than "Kingdom" if the lookup can't
        // resolve, so a seed can never hard-code the wrong org type.
        //
        // Computed HERE, after the park branch's early return, not up front:
        // park copy never reads $noun/$nounLower (OrgUnitNoun('park', ...)
        // returns the literal 'Park' with no DB touch, but the call and its
        // result were still built and then discarded on every park seed). Only
        // the kingdom content below uses it.
        $noun = $this->OrgUnitNoun($scopeType, (int) $scopeId);
        if ($noun === '') {
            $noun = 'Group';
        }
        $nounLower = strtolower($noun);

        // ---- Per-org meta descriptions -------------------------------------
        // These strings are LIVE: Controller_Site::view() publishes
        // meta_description into <meta name="description"> and feeds og_desc, so
        // they are also the preview text of every Slack/Discord/Facebook share.
        // They used to be noun-only placeholders ("Welcome to our kingdom."),
        // byte-identical across every sibling site on the network — the
        // duplicate-content signature that suppresses a whole network at once.
        // The org's REAL name is already resolved on the mint path, so name the
        // org. Nothing in the copy invents a fact (park counts, states) that
        // isn't already on hand in this class.
        if ($orgName === null) {
            $orgName = $this->_orgDisplayName($scopeType, (int) $scopeId);
        }
        $orgName = trim((string) $orgName);
        // Two forms so the name can lead a sentence or sit inside one, and the
        // copy still reads correctly when the name can't be resolved.
        $orgLabel      = ($orgName !== '') ? $orgName : 'our ' . $nounLower;
        $orgLabelStart = ($orgName !== '') ? $orgName : 'Our ' . $noun;

        // Search engines truncate around 155 characters; a long org name must
        // not push the distinguishing half of a description past the cut.
        $meta = function ($text) {
            $text = trim((string) $text);
            if (function_exists('mb_strlen') && mb_strlen($text) > 155) {
                return rtrim(mb_substr($text, 0, 152)) . '…';
            }
            return $text;
        };

        return CmsStarterContent::Registry('kingdom', array(
            'clean'           => $clean,
            'meta'            => $meta,
            'noun'            => $noun,
            'noun_lower'      => $nounLower,
            'org_label'       => $orgLabel,
            'org_label_start' => $orgLabelStart,
            'parks_limit'     => CmsRenderCache::PARKS_LIMIT_MAX,
            // The kingdom starter's own internal links (the hero CTA, the parks
            // teaser's "All parks", both closing CTA bands, the steps block's
            // guide link). STABLE 'Page/view/{slug}' form, never a baked-in
            // /k/{siteSlug}/ route — see _sitePageHref() for why a rename would
            // otherwise 404 every one of them. Pure string builders: no DB.
            'parks_href'       => $this->_sitePageHref('parks'),
            'new_players_href' => $this->_sitePageHref('new-players'),
            'starter_fields'  => function ($type, array $overrides = array()) {
                return $this->_starterFields($type, $overrides);
            },
        ), $version);
    }

    /**
     * A seeded block's fields, built from the block type's OWN declared
     * starter_fields plus page-specific overrides.
     *
     * A block type's field contract used to be hand-written twice with nothing
     * connecting the two: once in CmsBlockRegistry::BlockDefs()['starter_fields']
     * (what an officer gets when they add the block) and again in
     * _starterPageDefs() (what provisioning seeds). Neither was checked against
     * what the .tpl partial actually consumes, so a field the template needed
     * could be missing from both — harmless in BlockDefs, where the starter is
     * empty, but a visibly broken block in a POPULATED seeded row.
     *
     * Merging instead of re-declaring means the seed inherits the registry's
     * contract and states only what it genuinely changes. CmsBlockRegistry is
     * read-only from here. Falls back to the overrides alone when the registry
     * isn't loaded or doesn't know the type.
     *
     * MEASURE OF WHAT THIS BUYS TODAY, so nobody over-reads it: for
     * file_download the registry starter is exactly array('files' => array()),
     * which the overrides replace outright — the merge contributes nothing but
     * the link to the contract. For staff_roster it contributes 'subheading'
     * and an empty 'people'. It does NOT supply the fields neither side
     * declares (staff_roster's show_mundane is missing from the registry too);
     * the seeded roster was fixed by dropping its fabricated blank person row,
     * not by this merge. The value here is structural — one declaration to
     * update when a block's contract changes — not a bug already caught.
     *
     * @param string $type      block type key
     * @param array  $overrides fields this starter page sets differently
     * @return array
     */
    private function _starterFields($type, array $overrides = array())
    {
        $base = array();
        if (class_exists('CmsBlockRegistry')) {
            $defs = CmsBlockRegistry::BlockDefs();
            if (isset($defs[(string) $type]['starter_fields'])
                && is_array($defs[(string) $type]['starter_fields'])
            ) {
                $base = $defs[(string) $type]['starter_fields'];
            }
        }
        return array_merge($base, $overrides);
    }

    /**
     * The STABLE public href for one of THIS site's own pages, to be seeded into
     * authored block content (e.g. a CTA field).
     *
     * Deliberately builds the GLOBAL 'Page/view/{pageSlug}' form — the same form
     * CmsNav already resolves page links to — and NEVER an already-scoped
     * 'Site/page/{siteSlug}/{pageSlug}' href. Officers can rename a site's slug
     * (CmsSite::UpdateSite() accepts 'slug' as an editable field) and nothing
     * re-visits already-seeded block content on a rename, so a baked-in scoped
     * href would 404 the moment they do.
     *
     * The counterpart to this is at RENDER time: frontdoor/_helpers.tpl's
     * fdSiteInternalHref() re-points the 'Page/view/' form seeded here onto this
     * site's CURRENT 'Site/page/{slug}/' route, using the live $SiteSlug for
     * that render — the exact mechanism org_header.tpl's nav rewrite uses, which
     * is why nav survives a slug rename for free. Seeding the stable form and
     * resolving it live gives block-authored hrefs that same guarantee.
     *
     * No DB access needed — resolution happens at render time, not seed time,
     * so this is a pure string builder.
     *
     * @param string $pageSlug the target page's own slug (flat, e.g. 'new-players')
     * @return string
     */
    private function _sitePageHref($pageSlug)
    {
        $uir = defined('UIR') ? UIR : 'index.php?Route=';
        return $uir . 'Page/view/' . rawurlencode((string) $pageSlug);
    }

    /**
     * Home's "who we are" body. Uses the park's own ORK description when it has one
     * (246 of 342 do), so three quarters of parks get a genuinely local paragraph
     * with nobody typing anything. The fallback is a sentence true of every Amtgard
     * park — never an instruction to the officer, because whatever this seeds is
     * published to the open web as-is.
     */
    private function _parkIntroBody($parkId)
    {
        global $DB;
        $DB->Clear();
        $DB->park_id = (int) $parkId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT description FROM ' . DB_PREFIX . 'park WHERE park_id = :park_id LIMIT 1'
        ));
        $desc = trim((string) ($row['description'] ?? ''));

        // HARD INVARIANT: seeded copy may never state a time or a place.
        //
        // This snapshots ork_park.description into an AUTHORED rich_text block —
        // a one-time copy that is never refreshed when the park edits its ORK
        // record. 123 of the 246 park descriptions contain a weekday name or a
        // clock time, so half of them would publish a meeting time frozen at the
        // moment the site was seeded. The failure mode is not a stale web page,
        // it is a newcomer driving to an empty field on a Saturday because the
        // paragraph still says Saturday. The live park_meeting block and the
        // sticky strip are the only things allowed to state when we meet, because
        // they read the schedule at render time.
        //
        // So: a description that reads like a schedule is dropped in favour of the
        // evergreen paragraph, which is true of every park on every day.
        // Deliberately over-eager. A false positive costs a park its bespoke
        // paragraph in favour of an accurate generic one; a false negative sends
        // someone to a field on the wrong day. Those are not comparable, so the
        // patterns lean toward diverting. 'noon' and friends are in here because
        // a real description reads "Fun and Battlegames noon to five-ish!" — a
        // published meeting time with no digits and no weekday in it anywhere.
        $looksScheduled = ($desc !== '') && (
            preg_match('/\b(mon|tues?|wed(nes)?|thurs?|fri|satur|sun)(day)?s?\b/i', $desc)
            || preg_match('/\b\d{1,2}\s*(:\s*\d{2})?\s*(am|pm|a\.m\.|p\.m\.)/i', $desc)
            || preg_match('/\b\d{1,2}:\d{2}\b/', $desc)
            || preg_match('/\b(noon|midday|midnight|o.?clock)\b/i', $desc)
        );

        if ($desc !== '' && mb_strlen($desc) <= 800 && !$looksScheduled) {
            return '<p>' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        return '<p>We’re a local chapter of Amtgard — an all-ages foam-combat and medieval '
            . 'hobby group that meets outdoors in a public park. Nothing to buy, nothing to '
            . 'sign up for, no experience needed. Show up, borrow a sword, and we’ll teach '
            . 'you the rest.</p>';
    }

    /**
     * Closing CTA. Two tiers: showing up (always true, needs no data, and the only
     * honest ask — there is no self-service signup to point at) plus the park's one
     * external URL, LABELLED BY WHAT IT ACTUALLY IS. Of 204 parks with a URL, 148 are
     * Facebook; a generic "visit our website" wastes the reassurance a social link
     * carries, since a newcomer can see the group is active and lurk before committing.
     *
     * The LAST slot is left EMPTY on purpose. ork_park has exactly one url column, which is
     * why Discord appears only 5 times — the most public thing wins the slot. An empty
     * CTA renders nothing publicly and prompts loudly in the editor, so officers get an
     * obvious home for a Discord invite at zero data-model cost.
     */
    private function _parkCtaFields($parkId)
    {
        global $DB;
        $DB->Clear();
        $DB->park_id = (int) $parkId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT url FROM ' . DB_PREFIX . 'park WHERE park_id = :park_id LIMIT 1'
        ));
        $url = trim((string) ($row['url'] ?? ''));

        // TIER 1 ALWAYS COMES FIRST, and it is solid ('gold' is the primary button
        // in cta_band.tpl). The design puts "come to a park day" in the hero AND
        // again at the foot of the page, because the closing band is where a
        // visitor who has just read the whole page decides. Without it the band
        // was social-link-only for the 204 parks that have a URL — a ghost button
        // to a lower-commitment action, i.e. exactly the "social must never
        // outweigh Tier 1" inversion — and completely EMPTY for the other 138.
        // #pk-meet is the park_meeting block's own id on this same page
        // (park_meeting.tpl), so it needs no data and cannot 404.
        $ctas = array(
            array('label' => 'Come to a park day', 'href' => '#pk-meet', 'style' => 'gold'),
        );
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $label = 'Visit our page';
            if (preg_match('#(facebook\.com|fb\.com|fb\.me)#i', $url)) {
                $label = 'Ask us on Facebook';
            } elseif (stripos($url, 'discord') !== false) {
                $label = 'Join our Discord';
            }
            // Ghost, never solid: a social link is a LOWER-commitment action than
            // showing up and will out-click the real goal if given equal weight.
            $ctas[] = array('label' => $label, 'href' => $url, 'style' => 'ghost');
        }
        $ctas[] = array('label' => '', 'href' => '', 'style' => 'ghost');

        return array(
            'heading' => 'Come Find Us',
            'subcopy' => 'Still have a question? Ask before you come out — there is no dumb '
                . 'question about a hobby where adults hit each other with foam. And if you’d '
                . 'rather just turn up unannounced and see what’s going on, do that instead. '
                . 'Both work.',
            'logo'  => array(),
            'ctas'  => $ctas,
            'links' => '',
        );
    }

    /**
     * Point a freshly-seeded site at its seeded home page — but ONLY when the
     * site has no landing page yet. A repair pass must never re-point an org's
     * chosen home back at the seeded 'home' page.
     *
     * @param int $siteId
     * @param int $homeId seeded home page_id (0 when it didn't seed)
     * @param int $uid    acting mundane_id (audit)
     * @return void
     */
    private function _setSeededHomePage($siteId, $homeId, $uid)
    {
        global $DB;

        $siteId = (int)$siteId;
        if ($siteId <= 0 || (int)$homeId <= 0) {
            return;
        }

        $DB->Clear();
        $DB->site_id = $siteId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT home_page_id FROM ' . DB_PREFIX . 'cms_site WHERE site_id = :site_id LIMIT 1'
        ));
        if ($row !== null && isset($row['home_page_id']) && (int)$row['home_page_id'] > 0) {
            return; // already has a landing page — leave the org's choice alone
        }

        $this->UpdateSite($siteId, array('home_page_id' => (int)$homeId), $uid);
    }

    /**
     * Does ork_cms_site carry this column yet?
     *
     * The _firstRow-based SHOW COLUMNS probe idiom (same as CmsBase::
     * _tableExists()) that _stampTemplateSeeded() has always used, lifted out so
     * the seed_version reads share it. PDO runs under ERRMODE_WARNING here
     * (YapoMysql/YapoDb), so a statement naming an unknown column does not
     * throw — execute() just returns false and raises a PHP Warning on every
     * request that runs it. Probing skips the statement, and the warning, instead.
     *
     * @param string $column
     * @return bool
     */
    private function _siteColumnExists($column)
    {
        global $DB;

        $column = preg_replace('/[^a-z0-9_]+/', '', strtolower((string) $column));
        if ($column === '') {
            return false;
        }

        $DB->Clear();
        $row = $this->_firstRow($DB->DataSet(
            'SHOW COLUMNS FROM ' . DB_PREFIX . "cms_site LIKE '" . $column . "'"
        ));
        return ($row !== null);
    }

    /**
     * Stamp ork_cms_site.template_seeded_at — and, on the same statement,
     * seed_version — once, at the end of a successful starter-template seed.
     * template_seeded_at is the explicit "this site HAS been seeded" marker
     * EnsureSite gates its repair on (see the repair block there); seed_version
     * records WHICH version of CmsStarterContent it was seeded with, so
     * BackfillSeedContent() can later re-derive exactly that content. Written
     * only when the marker is still NULL (re-entrant).
     *
     * ONE STATEMENT, ONE GATE, on purpose: a seed that withholds the marker must
     * withhold the version too. A site stamped with a version it was never fully
     * seeded at would be skipped by the backfill AND (once the marker is
     * withheld) re-seeded by EnsureSite's repair, which is the worst of both.
     * Keeping both columns on the single guarded UPDATE makes the two impossible
     * to disagree.
     *
     * Called from exactly one place, _finishSeed(), and only when that seed
     * produced a landing page — a partially-failed seed is deliberately left
     * UNSTAMPED so the next EnsureSite retries it. See _finishSeed's docblock.
     *
     * Pre-migration DBs are handled by PROBING for each column, not by a
     * try/catch — see _siteColumnExists(). The outcome is safe either way: with
     * template_seeded_at absent EnsureSite treats the site as seeded and never
     * re-seeds, and with seed_version absent the backfill treats every site as
     * current and never re-derives.
     *
     * @param int $siteId
     * @return void
     */
    private function _stampTemplateSeeded($siteId)
    {
        global $DB;

        $siteId = (int)$siteId;
        if ($siteId <= 0) {
            return;
        }

        if (!$this->_siteColumnExists('template_seeded_at')) {
            return; // migration not run yet — nothing to stamp
        }
        // Independent probe: the two columns arrived in two different
        // migrations, so a DB can legitimately have the marker and not the
        // version. Naming a column that isn't there would fail the WHOLE
        // UPDATE, losing the marker as well.
        $setVersion = $this->_siteColumnExists('seed_version');

        $DB->Clear();
        $DB->site_id = $siteId;
        if ($setVersion) {
            $DB->seed_version = self::CURRENT_SEED_VERSION;
        }
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_site SET template_seeded_at = NOW()'
            . ($setVersion ? ', seed_version = :seed_version' : '')
            . ' WHERE site_id = :site_id AND template_seeded_at IS NULL'
        );

        // The marker rides on the cached GetSiteBySlug row (see the cache
        // contract at the top of this class) — bust it like every other mutator
        // so a row cached before the stamp can't serve template_seeded_at = NULL
        // for up to 1800s.
        $this->_bustSlugCache($this->_slugForSite($siteId));
    }

    /**
     * Clear ONE site's seed marker so the NEXT EnsureSite() re-enters the
     * starter seed through its repair branch. The supported, audited
     * alternative to the raw SQL that used to be the only lever.
     *
     * WHY THIS IS SAFE TO EXPOSE, and it rests entirely on the repair pass
     * already being non-destructive: re-entering with $isRepair = true does NOT
     * resurrect a starter page the org deliberately trashed, does NOT re-point a
     * home page the org has chosen, and CreatePage self-guards the live-slug
     * uniqueness tuple so an existing page is recovered rather than duplicated.
     * So the worst a re-run can do to a finished site is CREATE the starter
     * pages and nav items that are genuinely missing — which is exactly the
     * "a page is missing / the nav is empty" report this exists to answer.
     * It is emphatically NOT a "reset to factory" and must never become one.
     *
     * Deliberately does NOT call EnsureSite itself. Clearing the marker and
     * acting on it are two decisions: the caller owns authorization (this is a
     * super-admin action — it rewrites content on a site that may be public),
     * and owns reporting what the subsequent seed actually did.
     *
     * @param int $siteId
     * @param int $actorId acting mundane_id, recorded in the audit trail
     * @return bool true when the marker was cleared (or was already NULL);
     *              false when the site is unknown or the column is absent
     */
    public function ClearSeedMarker($siteId, $actorId = 0)
    {
        global $DB;

        $siteId  = (int)$siteId;
        $actorId = (int)$actorId;
        if ($siteId <= 0) {
            return false;
        }

        // Same fails-OPEN convention every other reader of this column uses: an
        // ABSENT column means the seed-marker migration has not run, and a site
        // on a pre-migration DB is treated as seeded. There is nothing to clear
        // and nothing to re-run, so say so rather than pretending it worked.
        if (!$this->_siteColumnExists('template_seeded_at')) {
            return false;
        }

        // seed_version is probed independently: the two columns arrived in two
        // different migrations, so naming one that isn't there would fail the
        // WHOLE update. Reset it alongside the marker — a site about to be
        // re-seeded is about to receive CURRENT_SEED_VERSION content, so leaving
        // a stale version behind would make a later backfill re-derive against
        // the wrong baseline and silently skip every field.
        $clearVersion = $this->_siteColumnExists('seed_version');

        $DB->Clear();
        $DB->site_id = $siteId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_site SET template_seeded_at = NULL'
            . ($clearVersion ? ', seed_version = 0' : '')
            . ' WHERE site_id = :site_id'
        );

        $scope = $this->_scopeForSite($siteId);
        $this->_cmsAudit(
            $actorId,
            'site.seed_marker_cleared',
            'site',
            $siteId,
            ($scope !== null) ? $scope['scope_type'] : 'global',
            ($scope !== null) ? (int)$scope['scope_id'] : 0
        );

        // The marker rides on the cached GetSiteBySlug row — bust it, or a row
        // cached moments ago keeps serving the OLD stamped value and EnsureSite
        // reads it as seeded, silently skipping the repair we just enabled.
        $this->_bustSlugCache($this->_slugForSite($siteId));

        return true;
    }

    /**
     * The (scope_type, scope_id) a site row belongs to, for audit attribution.
     *
     * @param int $siteId
     * @return array|null ['scope_type' => string, 'scope_id' => int]
     */
    private function _scopeForSite($siteId)
    {
        global $DB;

        $siteId = (int)$siteId;
        if ($siteId <= 0) {
            return null;
        }

        $DB->Clear();
        $DB->site_id = $siteId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT scope_type, scope_id FROM ' . DB_PREFIX . 'cms_site WHERE site_id = :site_id LIMIT 1'
        ));
        if ($row === null) {
            return null;
        }

        return array(
            'scope_type' => isset($row['scope_type']) ? (string)$row['scope_type'] : 'global',
            'scope_id'   => isset($row['scope_id']) ? (int)$row['scope_id'] : 0,
        );
    }

    /**
     * Bring ONE site's UNEDITED starter content up to CURRENT_SEED_VERSION.
     *
     * THE SAFETY PROPERTY, which is the whole point of this method: a stored
     * block field is replaced ONLY when it still BYTE-MATCHES what the site's
     * recorded seed version would have written into it. Anything an officer has
     * touched — one word, one character, one reordered array element — no longer
     * byte-matches, so it is left completely alone. The code below enforces that
     * with a single `===` against the re-derived old value and has no other way
     * to write: there is no fuzzy match, no prefix match, no "looks like the
     * placeholder" heuristic anywhere in it.
     *
     * That is the rule the two bespoke repair scripts
     * (db-migrations/2026-09-20-cms-kingdom-seed-copy-repair.php and
     * 2026-08-10-cms-park-seed-repair.php) each hand-implemented over a
     * hand-typed copy of the old strings. This generalizes them: the old strings
     * come back from CmsStarterContent at the version the site records, so no
     * future copy change needs a script of its own.
     *
     * RE-DERIVATION IS APPROXIMATE IN ONE DIRECTION, and it matters. The old
     * version's builder is a historical record of the COPY, but it is replayed
     * against TODAY'S runtime inputs — CmsRenderCache::PARKS_LIMIT_MAX, the
     * block registry's starter_fields, the org's live display name, the park's
     * live ORK description. Change any of those and a field that really was
     * seeded stops byte-matching. That fails SAFE (no match, no write, the
     * officer's page is untouched), but it is a skip, not an error, and nothing
     * reports it. See CmsStarterContent's header for what version 0 does and
     * does not cover — in particular, a site seeded BEFORE this wave needs
     * db-migrations/2026-09-20-cms-kingdom-seed-copy-repair.php run first for
     * its stored copy to line up with what v0 re-derives.
     *
     * THE WRITE IS NOT HAND-ROLLED. Every change goes back through
     * CmsPage::ReplaceBlocks() — the same choke point _seedStarterTemplate()
     * writes the seed through — so the rewrite gets the sanitizer, the
     * GetPageWithBlocks cache bust, the owner-row updated_at/updated_by stamp
     * and a revision snapshot (the only undo) from the code that owns them.
     *
     * IT CAN ALSO RESTRUCTURE, under one condition that never bends. Field-level
     * retouching alone could not carry a version that changes a block's TYPE,
     * inserts a block, or adds a page — every such block key is absent from one
     * of the two versions and is skipped — so a redesign reached nobody. Three
     * structural moves are therefore allowed, each gated on "nothing here has
     * been edited":
     *   - PAGE-LEVEL UPGRADE: when EVERY block on a page is still exactly what
     *     the site's recorded version seeded (same types, same orders, same
     *     enabled flags, every seeded field byte-matching) AND the new version's
     *     block LAYOUT for that page differs, the page's block list is replaced
     *     wholesale with the new version's, through ReplaceBlocks(). One edited
     *     character anywhere on the page and the page is NOT restructured: the
     *     run falls back to the field-level pass. See _pageIsPristineSeed().
     *   - MISSING PAGE: a page the new version INTRODUCES (in the new registry,
     *     absent from the old) is created when the scope has no page at that
     *     slug — and NOT when it has a TRASHED one, which is a deliberate
     *     removal, exactly as the seed's own repair pass treats it.
     *   - MISSING NAV ROW: a nav item the new version introduces is appended
     *     only when the stored menu still matches, row for row and in order,
     *     what the OLD version seeded. A menu an officer has rearranged,
     *     relabelled, disabled or added to gets nothing injected into it.
     *
     * WHAT IT DOES NOT DO, deliberately:
     *   - It never DELETES a page, a block or a nav item, and never re-creates
     *     one the org trashed.
     *   - It never restructures, relabels or re-orders anything an officer has
     *     edited. Every structural move above is gated on the surrounding
     *     content still being byte-identical to the seed.
     *   - It never touches a page the site does not still have (beyond the
     *     narrow "the new version adds it" case above), or a block whose type or
     *     order the org has changed.
     *   - It does not RENAME or RE-PARENT nav rows both versions seed: a version
     *     that rewords an existing menu label leaves already-seeded sites on the
     *     old label. Only genuinely new rows are added.
     *   - It never runs on its own. No render path calls it; the production
     *     entry point is db-migrations/2026-09-20-cms-seed-backfill.php. A seed
     *     that was never completed (template_seeded_at still NULL) is skipped
     *     outright and left to EnsureSite's repair.
     *
     * IDEMPOTENT: the second run sees seed_version already at
     * CURRENT_SEED_VERSION and returns 'current' without reading a single block.
     *
     * FAILS OPEN on a pre-migration database, the same convention
     * template_seeded_at's absence already follows: no seed_version column means
     * every site is treated as current and nothing is ever re-derived on a guess.
     *
     * @param int $siteId site to upgrade
     * @param int $uid    acting mundane_id (audit)
     * @return array{status:string, from:int, to:int, blocks:int, fields:int, reason:string,
     *   pages_restructured:int, pages_created:int, nav_added:int, nav_reason:string}
     *   status: 'updated' (version advanced), 'current' (nothing to do) or
     *   'skipped' (couldn't safely act — see reason). 'to' is the version the
     *   site is on AFTERWARDS, so on 'skipped' it equals 'from': nothing landed.
     */
    public function BackfillSeedContent($siteId, $uid)
    {
        global $DB;

        $siteId = (int) $siteId;
        $uid    = (int) $uid;
        // 'to' is the version the site ACTUALLY ends up on, so it starts at
        // 'from' and only advances where a version really lands. Reporting
        // CURRENT_SEED_VERSION on a 'skipped' run told an operator the site had
        // been brought forward when nothing was written at all.
        $result = array(
            'status' => 'skipped', 'from' => 0, 'to' => 0,
            'blocks' => 0, 'fields' => 0, 'reason' => '',
            // The STRUCTURAL half of the upgrade, counted separately from the
            // field retouches so an operator can see which kind of change landed.
            'pages_restructured' => 0, 'pages_created' => 0, 'nav_added' => 0,
            'nav_reason' => '',
            // Pages that REACHED the restructure gate and were refused because
            // the page is no longer pristine seed. Counted separately because
            // the version is stamped either way, so without this an operator
            // cannot tell "this site had nothing to restructure" from "this
            // site declined the restructure" — and once stamped, there is no
            // query left that can find the declined sites.
            'pages_declined' => 0, 'declined_slugs' => array(),
            'failed' => 0,
        );

        if ($siteId <= 0) {
            return $this->_backfillSkip($result, 'no_site');
        }
        if (!$this->_siteColumnExists('seed_version')) {
            // Fail OPEN — see the docblock.
            return $this->_backfillSkip($result, 'no_seed_version_column');
        }

        $DB->Clear();
        $DB->site_id = $siteId;
        $site = $this->_firstRow($DB->DataSet(
            'SELECT site_id, scope_type, scope_id, slug, seed_version, template_seeded_at'
            . ' FROM ' . DB_PREFIX . 'cms_site WHERE site_id = :site_id LIMIT 1'
        ));
        if ($site === null) {
            return $this->_backfillSkip($result, 'no_site');
        }

        // An unseeded (or half-seeded) site has no starter content to re-derive
        // against: EnsureSite's repair owns that case, and stamping a version
        // here would take the site out of its reach forever.
        if (empty($site['template_seeded_at'])) {
            return $this->_backfillSkip($result, 'never_seeded');
        }

        $scopeType = $this->_normalizeSiteScopeType($site['scope_type']);
        $scopeId   = (int) $site['scope_id'];
        $from      = (int) $site['seed_version'];
        $result['from'] = $from;

        if ($from >= self::CURRENT_SEED_VERSION) {
            $result['status'] = 'current';
            $result['to']     = $from;
            $result['reason'] = 'already_current';
            return $result;
        }

        // The write goes through CmsPage::ReplaceBlocks() — the class that owns
        // block storage, the sanitizer choke point, the page-cache bust and the
        // revision history. Without it there is no safe way to write, so skip.
        if (!class_exists('CmsPage')) {
            return $this->_backfillSkip($result, 'no_cms_page');
        }
        $page = new CmsPage();

        // Re-derive BOTH versions for this exact org, so the comparison is
        // against what this site was really given — org name, noun, park
        // description and all — not against a generic template.
        $old = $this->_starterPageDefs($scopeType, $scopeId, null, $from);
        $new = $this->_starterPageDefs($scopeType, $scopeId, null, self::CURRENT_SEED_VERSION);

        // Key both by (page slug, block type, block order) — the identity a
        // seeded block keeps across a version, and the only one an officer
        // cannot silently change out from under us without also changing the
        // thing we would have matched on.
        //
        // BOTH SIDES GO THROUGH THE SANITIZER, because the seed did: the
        // registry value is not what landed in fields_json — ReplaceBlocks()
        // ran it through _normalizeBlocks() -> _sanitizeBlockFields() first
        // (CmsSanitizer::Clean on HTML fields, SafeHrefOrHash on URL fields).
        // Comparing the RAW registry value against sanitized storage made the
        // gate unmatchable for any field the sanitizer rewrites — the park home
        // CTA band's empty editor slot, whose href the sanitizer maps '' -> '#',
        // could never be upgraded by any future version. This is the same step
        // db-migrations/2026-09-20-cms-kingdom-seed-copy-repair.php spells out:
        // reconstruct the expected strings the way they were STORED, so the
        // comparison is byte-exact rather than a guess at what the sanitizer did.
        $oldByKey = $this->_starterFieldsByKey($page, $old);
        $newByKey = $this->_starterFieldsByKey($page, $new);

        // The SAME sanitized starter blocks, grouped per page instead of keyed
        // per block — the input to the STRUCTURAL half of the upgrade below. The
        // field-level gate can only ever retouch a block BOTH versions declare at
        // the same (slug|type|order), so before this a version that changed a
        // block's TYPE, inserted a block, or added a whole page reached nobody:
        // every one of those keys is absent from one side and skipped.
        $oldBySlug = $this->_starterBlocksBySlug($page, $old);
        $newBySlug = $this->_starterBlocksBySlug($page, $new);

        // Buffer every candidate page BEFORE issuing any write: the shared $DB
        // handle is single-cursor, so writing mid-iteration drops the rest of
        // the result set.
        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $pages = $this->_eachRow($DB->DataSet(
            'SELECT page_id, slug FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id'
            . ' AND deleted_at IS NULL'
        ));

        $failed = 0;
        $liveSlugs = array();
        foreach ($pages as $pageRow) {
            $pageId = (int) $pageRow['page_id'];
            $slug   = (string) $pageRow['slug'];
            if ($pageId <= 0 || $slug === '') {
                continue;
            }
            $liveSlugs[$slug] = $pageId;

            // Read the owner's CURRENT blocks the way the editor does —
            // including disabled ones, so a block an officer switched off
            // survives the write-back instead of being deleted by it.
            $blocks = $page->GetBlocksForEditor('page', $pageId);

            // ---- PAGE-LEVEL UPGRADE ------------------------------------
            // The only way a version can RESTRUCTURE a page, and it is allowed
            // exactly when the page is still 100% seed: same blocks, same types
            // in the same sequence, same enabled flags, and every seeded field
            // still byte-matching what this site's recorded version wrote. (The
            // absolute order NUMBERS are deliberately not compared — see
            // _pageIsPristineSeed.) One edited
            // character anywhere on the page and _pageIsPristineSeed() is false,
            // so the run falls through to the field-level pass below and this
            // page is never restructured. That is the safety property, unchanged.
            //
            // Gated on the block LAYOUT really differing (type/order list), so a
            // version that only rewords fields keeps taking the narrower,
            // field-by-field path and its per-field accounting.
            $layoutDiffers = isset($oldBySlug[$slug], $newBySlug[$slug])
                && $this->_seedBlockKeys($oldBySlug[$slug]) !== $this->_seedBlockKeys($newBySlug[$slug]);
            if ($layoutDiffers && $this->_pageIsPristineSeed($blocks, $oldBySlug[$slug])) {
                $replacement = (isset($new[$slug]['blocks']) && is_array($new[$slug]['blocks']))
                    ? $new[$slug]['blocks'] : array();
                if ($replacement !== array()) {
                    // Same canonical write as every other path here: sanitizer,
                    // cache bust, owner stamp and a revision snapshot (the undo).
                    if ((int) $page->ReplaceBlocks('page', $pageId, $replacement, $uid) === -1) {
                        $failed++;
                        continue;
                    }
                    $result['blocks'] += count($newBySlug[$slug]);
                    $result['pages_restructured']++;
                    continue;
                }
            } elseif ($layoutDiffers) {
                // The new version WANTED to restructure this page and the gate
                // refused it. Record which page, so the sites that silently
                // declined remain findable after the version is stamped.
                $result['pages_declined']++;
                $result['declined_slugs'][] = $slug;
                $this->_cmsAudit(
                    (int) $uid,
                    'seed_backfill_page_declined:' . $slug,
                    'page',
                    $pageId,
                    $scopeType,
                    (int) $scopeId
                );
            }

            $touched = 0;
            $changed = 0;
            foreach ($blocks as $i => $block) {
                $key = $slug . '|' . (string) $block['type'] . '|' . (int) $block['order'];
                if (!isset($oldByKey[$key]) || !isset($newByKey[$key])) {
                    continue; // a block one of the two versions doesn't seed here
                }
                $fields = (isset($block['fields']) && is_array($block['fields'])) ? $block['fields'] : array();

                $n = 0;
                foreach ($oldByKey[$key] as $field => $seededValue) {
                    // THE byte-match gate. array_key_exists (not isset) so a
                    // field seeded as null is still comparable, and === so an
                    // array field must match element for element, in order, by
                    // type. There is no other way to write from here: no fuzzy
                    // match, no prefix match, no "looks like the placeholder".
                    if (!array_key_exists($field, $fields) || $fields[$field] !== $seededValue) {
                        continue; // absent, or the officer's own wording — hands off
                    }
                    if (!array_key_exists($field, $newByKey[$key])
                        || $newByKey[$key][$field] === $seededValue
                    ) {
                        continue; // this version doesn't change that field
                    }
                    $fields[$field] = $newByKey[$key][$field];
                    $n++;
                }
                if ($n > 0) {
                    $blocks[$i]['fields'] = $fields;
                    $changed += $n;
                    $touched++;
                }
            }
            if ($changed === 0) {
                continue; // nothing on this page is still untouched seed copy
            }

            // THE CANONICAL WRITE. Hand the whole (mutated) set back to the same
            // method _seedStarterTemplate() itself writes through, rather than
            // UPDATEing fields_json behind its back. ReplaceBlocks() owns four
            // things a hand-rolled UPDATE silently skipped: the sanitize choke
            // point, the GetPageWithBlocks cache bust (PWB_CACHE_TTL is 1800s,
            // keyed on the page's updated_at — a direct UPDATE re-primed the
            // STALE payload for half an hour after the operator was told the
            // backfill had landed), the owner-row updated_at/updated_by stamp,
            // and a revision snapshot, which is the only undo for content this
            // runner rewrote on a published page.
            if ((int) $page->ReplaceBlocks('page', $pageId, $blocks, $uid) === -1) {
                // Verified partial write — ReplaceBlocks already ROLLBACKed.
                $failed++;
                continue;
            }
            $result['blocks'] += $touched;
            $result['fields'] += $changed;
        }

        // ---- PAGES THE NEW VERSION ADDS --------------------------------
        // Strictly pages the new version INTRODUCES (present in $new, absent
        // from $old). A starter page both versions declare and this site does
        // not have was removed on purpose — or never seeded — and is left alone.
        $created = $this->_backfillNewPages($page, $old, $new, $scopeType, $scopeId, $uid, $liveSlugs, $result);

        // ---- NAV ROWS THE NEW VERSION ADDS -----------------------------
        // Only when the menu still matches, row for row and in order, what the
        // OLD version seeded. An officer who rearranged their nav gets nothing
        // injected into it.
        $this->_backfillNewNav($old, $new, $scopeType, $scopeId, $liveSlugs + $created, $result);

        // _backfillNewPages() reports its own write failures through the result;
        // fold them in so a failed new-page write withholds the stamp too.
        $failed += (int) $result['failed'];
        $result['failed'] = $failed;

        if ($failed > 0) {
            // Do NOT stamp the version: leaving the site behind is what makes a
            // re-run retry the pages that failed. Every write is idempotent
            // (an already-upgraded field no longer byte-matches the old value),
            // so a retry can only finish the job.
            return $this->_backfillSkip($result, 'partial_write');
        }

        // Stamp the new version even when nothing changed: the site HAS been
        // re-derived at this version, and everything still seeded-looking is now
        // the current copy. Re-running would only re-do the same no-op work.
        $DB->Clear();
        $DB->site_id      = $siteId;
        $DB->seed_version = self::CURRENT_SEED_VERSION;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_site SET seed_version = :seed_version'
            . ' WHERE site_id = :site_id AND seed_version < :seed_version'
        );

        // seed_version rides on the cached GetSiteBySlug row; the nav tree's
        // content version covers the menus. The rendered block payload is
        // ReplaceBlocks()' own concern and is busted there, per page.
        $this->_bustSlugCache(isset($site['slug']) ? (string) $site['slug'] : '');
        $this->_bumpContentVersion($scopeType, $scopeId);

        $this->_cmsAudit($uid, 'seed_backfill', 'site', $siteId, $scopeType, $scopeId);

        $result['status'] = 'updated';
        $result['to']     = self::CURRENT_SEED_VERSION;
        $result['reason'] = 'v' . $from . '_to_v' . self::CURRENT_SEED_VERSION;
        return $result;
    }

    /**
     * The one way BackfillSeedContent() reports "I did not act": status stays
     * 'skipped' and 'to' collapses onto 'from', because the site is still on the
     * version it arrived with. Centralized so no skip path can drift back into
     * claiming a version it never landed on.
     *
     * @param array  $result partially-filled result array
     * @param string $reason machine-readable reason code
     * @return array the result, ready to return
     */
    private function _backfillSkip(array $result, $reason)
    {
        $result['status'] = 'skipped';
        $result['to']     = (int) $result['from'];
        $result['reason'] = (string) $reason;
        return $result;
    }

    /**
     * Flatten a starter registry to (page slug|block type|block order) => fields.
     *
     * The key is a seeded block's stable identity across content versions, and
     * the only one BackfillSeedContent() can safely match a STORED block on: a
     * block_id is not in the registry, and matching on position alone would
     * follow an officer's re-ordering onto the wrong block.
     *
     * THE FIELDS COME BACK SANITIZED, which is the whole reason this takes a
     * CmsPage. SanitizeBlocksForRender() is the public face of the exact
     * _normalizeBlocks() -> _sanitizeBlockFields() pass ReplaceBlocks() ran the
     * registry through when the site was seeded, so what this returns is what
     * the seed really STORED — not the pre-sanitizer registry text, which for
     * any Clean()ed or SafeHrefOrHash()ed field is a different string and could
     * never byte-match the stored row.
     *
     * @param CmsPage $page     the sanitize choke point (pure call — no DB)
     * @param array   $registry as returned by _starterPageDefs()
     * @return array key => fields array, as the seed would have stored them
     */
    private function _starterFieldsByKey($page, array $registry)
    {
        $out = array();
        foreach ($registry as $slug => $def) {
            if (!isset($def['blocks']) || !is_array($def['blocks'])) {
                continue;
            }
            foreach ($page->SanitizeBlocksForRender($def['blocks']) as $block) {
                if (!is_array($block['fields'])) {
                    continue;
                }
                $key = (string) $slug . '|' . (string) $block['type'] . '|' . (int) $block['order'];
                $out[$key] = $block['fields'];
            }
        }
        return $out;
    }

    /**
     * The same sanitized starter blocks _starterFieldsByKey() flattens, grouped
     * per PAGE and kept in registry order.
     *
     * The structural half of BackfillSeedContent() has to reason about a page's
     * whole block list at once — "is every block on this page still exactly what
     * the seed wrote?" and "does the new version lay this page out differently?"
     * — which a (slug|type|order)-keyed flat map cannot answer: it has no notion
     * of how many blocks a page has, nor of their order.
     *
     * Same sanitize pass, same reason (see _starterFieldsByKey): what the seed
     * STORED is the registry after CmsPage's clean, not the raw registry text.
     *
     * @param CmsPage $page     the sanitize choke point (pure call — no DB)
     * @param array   $registry as returned by _starterPageDefs()
     * @return array page slug => ordered list of sanitized blocks
     */
    private function _starterBlocksBySlug($page, array $registry)
    {
        $out = array();
        foreach ($registry as $slug => $def) {
            if (!isset($def['blocks']) || !is_array($def['blocks'])) {
                continue;
            }
            $out[(string) $slug] = $page->SanitizeBlocksForRender($def['blocks']);
        }
        return $out;
    }

    /**
     * A page's block LAYOUT, as the ordered list of 'type|order' keys.
     *
     * This is the comparison that decides whether a version change is
     * STRUCTURAL (a block's type changed, a block was added or removed, an order
     * moved) or merely a rewording. A rewording keeps the narrower field-level
     * path and its per-field accounting; only a structural change earns a
     * wholesale replace.
     *
     * @param array $blocks sanitized starter blocks for one page
     * @return array list of 'type|order' strings, in order
     */
    private function _seedBlockKeys(array $blocks)
    {
        $keys = array();
        foreach ($blocks as $block) {
            $keys[] = (string) $block['type'] . '|' . (int) $block['order'];
        }
        return $keys;
    }

    /**
     * Is this page still 100% untouched seed content at the given version?
     *
     * THE GATE FOR EVERY RESTRUCTURE. It is deliberately stricter than the
     * field-level gate, because a wholesale replace throws away whatever is
     * there: the stored page must have the SAME NUMBER of blocks, of the same
     * types IN THE SAME SEQUENCE, with the same enabled flags, and every single
     * field the seed wrote must still byte-match (array_key_exists + ===, the
     * same test the field loop uses). Anything else — one reworded sentence, one
     * block switched off, one block the officer added — makes this false, and
     * the caller then leaves the page's structure exactly as the officer left it.
     *
     * NOT "at the same orders", and do not re-tighten it to that. The absolute
     * order numbers are explicitly NOT compared; see the comment on the sort in
     * the body for why, and tests/cms-site/site_test.php pins BOTH halves of
     * that decision. Restoring an order comparison here silently stops the
     * upgrade reaching every kingdom seeded before this branch.
     *
     * Fields the seed did NOT write are not examined: a later code path that
     * adds a default key to a stored block must not make an untouched page
     * permanently unupgradable.
     *
     * @param array $stored  blocks as CmsPage::GetBlocksForEditor() returns them
     * @param array $seeded  sanitized starter blocks for the same page
     * @return bool true only when nothing on the page has been edited
     */
    private function _pageIsPristineSeed($stored, array $seeded)
    {
        if (!is_array($stored) || count($stored) !== count($seeded)) {
            return false;
        }
        // Compare by SEQUENCE, not by absolute order value. Both sides are
        // sorted by order first (GetBlocksForEditor already returns them that
        // way; the seed side is sorted defensively), so the resulting sequence
        // of types is what proves the officer did not re-order anything.
        //
        // The absolute order NUMBERS are deliberately NOT compared, and that is
        // load-bearing rather than lax. Pre-branch kingdoms were seeded with
        // kingdom_events at order 30 where the v0 registry declares 40 — every
        // type, every enabled flag and (after the copy-repair migration) every
        // field byte-matches, and the ONLY thing that made them fail this gate
        // was 30 !== 40. That one clause was the difference between this upgrade
        // reaching prod's seeded kingdoms and reaching none of them. Nothing is
        // weakened by dropping it: order values are sort keys, the sequence
        // already carries the ordering information, and a restructure replaces
        // the whole list with the new version's orders anyway.
        $sortByOrder = function ($a, $b) {
            return (int) (isset($a['order']) ? $a['order'] : 0)
                <=> (int) (isset($b['order']) ? $b['order'] : 0);
        };
        $stored = array_values($stored);
        $seeded = array_values($seeded);
        usort($stored, $sortByOrder);
        usort($seeded, $sortByOrder);

        foreach ($seeded as $i => $seedBlock) {
            $have = $stored[$i];
            if ((string) $have['type'] !== (string) $seedBlock['type']
                || !empty($have['enabled']) !== !empty($seedBlock['enabled'])
            ) {
                return false;
            }
            $fields = (isset($have['fields']) && is_array($have['fields'])) ? $have['fields'] : array();
            foreach ($seedBlock['fields'] as $field => $seededValue) {
                if (!array_key_exists($field, $fields) || $fields[$field] !== $seededValue) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Create the starter pages the NEW content version introduces.
     *
     * Only pages present in the new registry and ABSENT from the old one: a page
     * both versions declare and the site does not have was either removed on
     * purpose or never seeded, and re-minting it here would resurrect content an
     * officer deleted. A TRASHED row at the slug stops the create outright, for
     * the same reason and by the same rule _seedStarterTemplate()'s repair pass
     * already follows — a soft-deleted page does not collide with the live
     * uniqueness key, so without this check a brand-new published page of
     * template copy would appear at a slug the org deliberately emptied.
     *
     * Pages are created PUBLISHED, exactly as the seed creates them: the site's
     * own published/draft status is the real go-live gate, and a draft page
     * behind a nav row is a dead link.
     *
     * @param CmsPage $page      storage choke point
     * @param array   $old       the old version's registry
     * @param array   $new       the new version's registry
     * @param string  $scopeType 'kingdom'|'park'
     * @param int     $scopeId
     * @param int     $uid       acting mundane_id (audit + owner stamp)
     * @param array   $liveSlugs slug => page_id for the pages the site already has
     * @param array   $result    run report, mutated in place
     * @return array slug => page_id for the pages this call created
     */
    private function _backfillNewPages($page, array $old, array $new, $scopeType, $scopeId, $uid, array $liveSlugs, array &$result)
    {
        $created = array();
        $now     = date('Y-m-d H:i:s');

        foreach ($new as $slug => $def) {
            $slug = (string) $slug;
            // 'attrs' absent = a NAV-ONLY registry entry: it has no page to make.
            if (!isset($def['attrs']) || !is_array($def['attrs'])) {
                continue;
            }
            if (isset($old[$slug]) || isset($liveSlugs[$slug])) {
                continue; // not new, or already here
            }
            $prior = $this->_anyPageBySlug($slug, $scopeType, (int) $scopeId);
            if ($prior !== null) {
                // Live (raced in under us) or TRASHED (a deliberate removal) —
                // either way this runner does not create a page here.
                continue;
            }

            $pid = (int) $page->CreatePage(array_merge(array(
                'status'       => 'published',
                'published_at' => $now,
                'scope_type'   => $scopeType,
                'scope_id'     => (int) $scopeId,
                'created_by'   => (int) $uid,
                'updated_by'   => (int) $uid,
                'created_at'   => $now,
                'updated_at'   => $now,
            ), $def['attrs']));
            if ($pid <= 0) {
                $this->_cmsAudit((int) $uid, 'seed_backfill_page_failed:' . $slug, 'page', 0, $scopeType, (int) $scopeId);
                continue;
            }
            if (isset($def['blocks']) && is_array($def['blocks']) && count($def['blocks']) > 0) {
                // Check the return like the other two write sites do. Without
                // this, a failed write left the page PUBLISHED with zero blocks
                // — a blank page on the public web — while blocks was counted as
                // if it had worked, $failed stayed 0, and the site was stamped
                // current, so nothing ever retried it.
                if ((int) $page->ReplaceBlocks('page', $pid, $def['blocks'], (int) $uid) === -1) {
                    $this->_cmsAudit((int) $uid, 'seed_backfill_page_blocks_failed:' . $slug, 'page', $pid, $scopeType, (int) $scopeId);
                    $result['failed']++;
                    continue;
                }
                $result['blocks'] += count($def['blocks']);
            }
            $created[$slug] = $pid;
            $result['pages_created']++;
            $this->_cmsAudit((int) $uid, 'seed_backfill_page_added:' . $slug, 'page', $pid, $scopeType, (int) $scopeId);
        }

        return $created;
    }

    /**
     * Append the nav rows the NEW content version introduces — and only into a
     * menu nobody has touched.
     *
     * THE GATE: the stored 'marketing' menu must still be, row for row and in
     * order, exactly what the OLD version seeded (same labels, same link types,
     * same targets, same parents, all enabled). An officer who rearranged,
     * relabelled, disabled or extended their menu has made it theirs, and a row
     * injected into it would be this runner overwriting a decision. On a
     * mismatch nothing is written and 'nav_reason' records why.
     *
     * New rows are APPENDED (after the last top-level item, or after the last
     * child of their declared parent) rather than slotted into the new version's
     * position: moving the rows around them would re-order a menu this method
     * has no mandate to re-order.
     *
     * Rows both versions seed are never renamed or re-parented here — see
     * BackfillSeedContent()'s docblock.
     *
     * @param array  $old       the old version's registry
     * @param array  $new       the new version's registry
     * @param string $scopeType 'kingdom'|'park'
     * @param int    $scopeId
     * @param array  $pageIds   slug => page_id (live pages plus any just created)
     * @param array  $result    run report, mutated in place
     * @return void
     */
    private function _backfillNewNav(array $old, array $new, $scopeType, $scopeId, array $pageIds, array &$result)
    {
        // Which entries are genuinely NEW to the menu? Computed before any DB
        // read so the common case (no new nav rows) costs nothing at all.
        $added = array();
        foreach ($new as $key => $def) {
            if (isset($old[$key]) || empty($def['nav_label'])) {
                continue;
            }
            $added[(string) $key] = $def;
        }
        if ($added === array()) {
            return;
        }
        if (!class_exists('CmsNav')) {
            $result['nav_reason'] = 'no_cms_nav';
            return;
        }

        $nav   = new CmsNav();
        $items = $nav->ListItems('marketing', $scopeType, (int) $scopeId);
        if (!is_array($items) || $items === array()) {
            // No menu at all is not a menu the old version seeded — leave it to
            // EnsureSite's repair rather than half-seed one here.
            $result['nav_reason'] = 'menu_empty';
            return;
        }

        if ($this->_navSignature($items) !== $this->_seededNavSignature($old, $pageIds)) {
            $result['nav_reason'] = 'menu_edited';
            return;
        }

        // Where a new row lands: after the last top-level item, or after the
        // last child of the parent it declares.
        $byLabel     = array();
        $maxTop      = 0;
        $maxChild    = array();
        foreach ($items as $item) {
            $byLabel[(string) $item['label']] = (int) $item['nav_id'];
            $parentId = ($item['parent_id'] === null) ? 0 : (int) $item['parent_id'];
            if ($parentId === 0) {
                $maxTop = max($maxTop, (int) $item['ordering']);
            } else {
                $maxChild[$parentId] = max(
                    isset($maxChild[$parentId]) ? $maxChild[$parentId] : 0,
                    (int) $item['ordering']
                );
            }
        }

        foreach ($added as $key => $def) {
            $link    = (isset($def['nav_link']) && is_array($def['nav_link'])) ? $def['nav_link'] : array();
            $pageId  = 0;
            if ($link === array()) {
                // A page link with no page is a dead menu row — skip it.
                $pageId = isset($pageIds[$key]) ? (int) $pageIds[$key] : 0;
                if ($pageId <= 0) {
                    continue;
                }
            }

            $parentId = 0;
            if (!empty($def['nav_parent'])) {
                $parentKey   = (string) $def['nav_parent'];
                $parentLabel = isset($new[$parentKey]['nav_label']) ? (string) $new[$parentKey]['nav_label'] : '';
                $oldLabel    = isset($old[$parentKey]['nav_label']) ? (string) $old[$parentKey]['nav_label'] : '';
                // The stored row still carries the OLD version's label (this
                // method renames nothing), so match on that first.
                foreach (array($oldLabel, $parentLabel) as $candidate) {
                    if ($candidate !== '' && isset($byLabel[$candidate])) {
                        $parentId = $byLabel[$candidate];
                        break;
                    }
                }
            }

            if ($parentId > 0) {
                $ordering = (isset($maxChild[$parentId]) ? $maxChild[$parentId] : 0) + 10;
                $maxChild[$parentId] = $ordering;
            } else {
                $maxTop  += 10;
                $ordering = $maxTop;
            }

            $navId = (int) $nav->CreateItem(array(
                'menu'       => 'marketing',
                'label'      => (string) $def['nav_label'],
                'link_type'  => isset($link['link_type']) ? (string) $link['link_type'] : 'page',
                'page_id'    => ($pageId > 0) ? $pageId : null,
                'url'        => isset($link['url']) ? (string) $link['url'] : null,
                'parent_id'  => ($parentId > 0) ? $parentId : null,
                'ordering'   => $ordering,
                'enabled'    => 1,
                'scope_type' => $scopeType,
                'scope_id'   => (int) $scopeId,
            ));
            if ($navId > 0) {
                $result['nav_added']++;
                $byLabel[(string) $def['nav_label']] = $navId;
            }
        }

        if ($result['nav_added'] > 0) {
            $result['nav_reason'] = 'appended';
        }
    }

    /**
     * The stored menu as a comparable ordered fingerprint.
     *
     * One string per row: parent label, label, link type, resolved href and the
     * enabled flag — everything an officer could have changed that would mean
     * "this menu is mine now". ListItems() orders top-level rows first, then
     * children grouped by parent, which is exactly the order _seedNavMenu()
     * inserts them in, so the two sequences are comparable element for element.
     *
     * @param array $items CmsNav::ListItems() rows
     * @return array list of fingerprint strings, in stored order
     */
    private function _navSignature(array $items)
    {
        $labelById = array();
        foreach ($items as $item) {
            $labelById[(int) $item['nav_id']] = (string) $item['label'];
        }
        $out = array();
        foreach ($items as $item) {
            $parentId    = ($item['parent_id'] === null) ? 0 : (int) $item['parent_id'];
            $parentLabel = isset($labelById[$parentId]) ? $labelById[$parentId] : '';
            $out[] = $parentLabel . '>' . (string) $item['label']
                . '|' . (string) $item['link_type']
                . '|' . (string) $item['href']
                . '|' . (!empty($item['enabled']) ? '1' : '0');
        }
        return $out;
    }

    /**
     * The same fingerprint, for the menu a given content version WOULD have
     * seeded on this site — built from the registry the way _seedNavMenu()
     * builds the real thing: top-level entries in registry order, then children
     * grouped under their parent, in registry order.
     *
     * An entry whose page never landed contributes no row, exactly as the seed's
     * own $navSeedable test decides. hrefs are built the way CmsNav resolves
     * them (page → the stable Page/view/{slug} form, dynamic → UIR + route), so
     * the comparison is against what ListItems() really returns.
     *
     * @param array $starters the registry for the version being compared against
     * @param array $pageIds  slug => page_id for the pages the site has
     * @return array list of fingerprint strings, in seeded order
     */
    private function _seededNavSignature(array $starters, array $pageIds)
    {
        $uir = defined('UIR') ? UIR : 'index.php?Route=';

        $row = function ($key, $def) use ($starters, $pageIds, $uir) {
            if (empty($def['nav_label'])) {
                return null;
            }
            $link = (isset($def['nav_link']) && is_array($def['nav_link'])) ? $def['nav_link'] : array();
            if ($link === array()) {
                if (empty($pageIds[(string) $key])) {
                    return null; // no page => the seed wrote no nav row
                }
                $linkType = 'page';
                $href     = $this->_sitePageHref((string) $key);
            } else {
                $linkType = isset($link['link_type']) ? (string) $link['link_type'] : 'page';
                $route    = isset($link['url']) ? trim((string) $link['url']) : '';
                if ($linkType === 'dynamic') {
                    $href = ($route === '') ? '#' : $uir . ltrim($route, '/');
                } elseif ($linkType === 'url') {
                    $href = ($route === '') ? '#' : $route;
                } else {
                    $href = '#';
                }
            }
            $parentLabel = '';
            if (!empty($def['nav_parent'])
                && isset($starters[(string) $def['nav_parent']]['nav_label'])
            ) {
                $parentLabel = (string) $starters[(string) $def['nav_parent']]['nav_label'];
            }
            return $parentLabel . '>' . (string) $def['nav_label'] . '|' . $linkType . '|' . $href . '|1';
        };

        // Pass 1: top level. Pass 2: children, grouped by parent in the order
        // their parents appear — the order _seedNavMenu() inserts them in, and
        // therefore the order ListItems() reads them back in.
        $top      = array();
        $promoted = array();
        $children = array();
        foreach ($starters as $key => $def) {
            $sig = $row($key, $def);
            if ($sig === null) {
                continue;
            }
            $parentKey = !empty($def['nav_parent']) ? (string) $def['nav_parent'] : '';
            if ($parentKey === '') {
                $top[] = $sig;
                continue;
            }
            // A child whose parent never seeded is PROMOTED to top level by
            // _seedNavMenu() — and promoted in its second pass, so it sits after
            // every pass-one top-level row. Mirror that or the fingerprint of a
            // site with a half-seeded menu could never match.
            if ($row($parentKey, isset($starters[$parentKey]) ? $starters[$parentKey] : array()) === null) {
                $promoted[] = $sig;
                continue;
            }
            $children[$parentKey][] = $sig;
        }
        $out = array_merge($top, $promoted);
        foreach ($starters as $key => $def) {
            if (isset($children[(string) $key])) {
                foreach ($children[(string) $key] as $sig) {
                    $out[] = $sig;
                }
            }
        }
        return $out;
    }

    /**
     * READ-ONLY: how much of a site's seeded starter content is still exactly as
     * the seed left it — the input to the pre-publish readiness panel.
     *
     * The question the panel asks is "is this site ready to show the public, or
     * is it still the template?", and the honest answer is per-field, not
     * per-page: an officer who rewrote Home and never opened About has one real
     * page and one template page, and a count of pages cannot say so.
     *
     * SAME COMPARISON AS THE BACKFILL, deliberately reused rather than re-coded.
     * "Still seeded" means the stored value BYTE-MATCHES what this site's
     * recorded seed version would have written — and what the seed wrote is the
     * registry AFTER CmsPage's sanitize pass, not the raw registry text (see
     * _starterFieldsByKey()). A second implementation of that would drift from
     * BackfillSeedContent()'s within a release, and the two would then disagree
     * about which fields the runner is allowed to touch.
     *
     * WRITES NOTHING. No ReplaceBlocks, no version stamp, no cache bust, no
     * audit row — every call here is a SELECT. That is a hard contract: this
     * runs on a dashboard GET, possibly on every load.
     *
     * 'renders_nothing' is the defect the panel exists to catch: a nav-linked
     * page whose every block would publish NOTHING (an empty authored block
     * self-suppresses, so the visitor gets a page containing only its own <h1>).
     * An enabled DYNAMIC block never counts as empty — it publishes live ORK
     * data this class cannot see from here — so the flag only ever fires on a
     * page that is authored-only and genuinely blank.
     *
     * FAILS OPEN exactly as BackfillSeedContent() does: no seed_version column
     * means every site reads as current, and an unresolvable site reads as an
     * empty report rather than an error.
     *
     * @param string $scopeType 'kingdom' | 'park'
     * @param int    $scopeId   owning org id
     * @return array{version:int, current:int, pages:array, untouched_pages:int, seeded_pages:int}
     */
    public function SeedContentStatus($scopeType, $scopeId)
    {
        global $DB;

        $scopeType = $this->_normalizeSiteScopeType($scopeType);
        $scopeId   = (int) $scopeId;

        $out = array(
            'version'         => self::CURRENT_SEED_VERSION,
            'current'         => self::CURRENT_SEED_VERSION,
            'pages'           => array(),
            'untouched_pages' => 0,
            'seeded_pages'    => 0,
        );
        if ($scopeId <= 0 || !class_exists('CmsPage')) {
            return $out;
        }

        if ($this->_siteColumnExists('seed_version')) {
            $DB->Clear();
            $DB->scope_type = $scopeType;
            $DB->scope_id   = $scopeId;
            $site = $this->_firstRow($DB->DataSet(
                'SELECT seed_version FROM ' . DB_PREFIX . 'cms_site'
                . ' WHERE scope_type = :scope_type AND scope_id = :scope_id LIMIT 1'
            ));
            if ($site === null) {
                return $out;
            }
            $out['version'] = (int) ($site['seed_version'] ?? 0);
        }

        // Re-derive what THIS org was seeded with, at the version it records —
        // org name, noun and all — and sanitize it the way the seed stored it.
        $page     = new CmsPage();
        $seeded   = $this->_starterPageDefs($scopeType, $scopeId, null, $out['version']);
        $byKey    = $this->_starterFieldsByKey($page, $seeded);

        // Buffer the page list BEFORE reading any blocks: the shared $DB handle
        // is single-cursor, so a read mid-iteration drops the rest of the set.
        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $pages = $this->_eachRow($DB->DataSet(
            'SELECT page_id, slug, title FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id'
            . ' AND deleted_at IS NULL'
        ));

        foreach ($pages as $pageRow) {
            $pageId = (int) $pageRow['page_id'];
            $slug   = (string) $pageRow['slug'];
            // Only SEEDED starter pages are reportable: a page the org wrote
            // itself has no seed to compare against, and calling it "edited"
            // would be as wrong as calling it "untouched".
            if ($pageId <= 0 || $slug === '' || !isset($seeded[$slug])) {
                continue;
            }

            $unedited = 0;
            $edited   = 0;
            $publishes = false;
            foreach ($page->GetBlocksForEditor('page', $pageId) as $block) {
                if (!empty($block['enabled'])) {
                    $publishes = $publishes || $this->_blockPublishesSomething($block);
                }
                $key = $slug . '|' . (string) $block['type'] . '|' . (int) $block['order'];
                if (!isset($byKey[$key])) {
                    continue; // not a seeded block (added, retyped or re-ordered)
                }
                $fields = (isset($block['fields']) && is_array($block['fields'])) ? $block['fields'] : array();
                foreach ($byKey[$key] as $field => $seededValue) {
                    // array_key_exists (not isset) so a field seeded as null is
                    // still comparable; === so an array field must match element
                    // for element, in order, by type. Same gate as the backfill.
                    if (array_key_exists($field, $fields) && $fields[$field] === $seededValue) {
                        $unedited++;
                    } else {
                        $edited++;
                    }
                }
            }

            // "Untouched" needs at least one field that IS still seed copy: a
            // page whose seeded blocks were all deleted has nothing matching and
            // nothing edited, and it is not a template page.
            $isUntouched = ($edited === 0 && $unedited > 0);
            $out['pages'][] = array(
                'page_id'         => $pageId,
                'slug'            => $slug,
                'title'           => (string) ($pageRow['title'] ?? ''),
                'unedited_fields' => $unedited,
                'edited_fields'   => $edited,
                'renders_nothing' => !$publishes,
                'is_untouched'    => $isUntouched,
            );
            $out['seeded_pages']++;
            if ($isUntouched) {
                $out['untouched_pages']++;
            }
        }

        return $out;
    }

    /**
     * Would this stored block put ANYTHING on the public page?
     *
     * Used only by SeedContentStatus()'s 'renders_nothing' flag, and deliberately
     * conservative in one direction: a DYNAMIC block (kingdom_events, the parks
     * teaser, the live officer grid) reads ORK data at render time that nothing
     * here can see, so it always counts as publishing. For an AUTHORED block the
     * partials agree on one rule — no visible copy anywhere in the block,
     * nothing rendered — so any field holding visible text counts.
     *
     * CONTENT ONLY. Presentation keys (align, band, limit, the show_* flags…)
     * are always populated and say nothing about whether the block has anything
     * to show; an href alone renders nothing either, because every partial gates
     * its link on the LABEL. Counting those made the flag unable to ever fire,
     * which is the whole reason they are named here.
     *
     * Pure: no DB, no cache, no I/O.
     *
     * @param array $block one row from CmsPage::GetBlocksForEditor()
     * @return bool
     */
    private function _blockPublishesSomething($block)
    {
        if ((string) ($block['source'] ?? '') === 'dynamic') {
            return true;
        }
        $skip = array(
            'align' => true, 'band' => true, 'presentation' => true, 'sort' => true,
            'limit' => true, 'style' => true, 'autoplay_ms' => true, 'filetype' => true,
            'href' => true, 'url' => true, 'src' => true, 'display' => true,
            'more_href' => true, 'media_id' => true, 'mundane_id' => true,
        );
        $hasText = function ($value) use (&$hasText, $skip) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (is_string($k) && (isset($skip[$k]) || strpos($k, 'show_') === 0)) {
                        continue;
                    }
                    if ($hasText($v)) {
                        return true;
                    }
                }
                return false;
            }
            if (!is_string($value)) {
                return false;
            }
            // strip_tags so an empty <p></p> — what a cleared rich-text body
            // leaves behind — is not mistaken for content.
            return trim(strip_tags($value)) !== '';
        };
        return $hasText(
            (isset($block['fields']) && is_array($block['fields'])) ? $block['fields'] : array()
        );
    }

    /**
     * Supported public entry point for seeding (or repairing) ONE org's theme
     * outside the normal site-seed path — e.g. a backfill migration over orgs
     * provisioned before the theme seed existed. Delegates to _seedOrgTheme()
     * and returns exactly what it returns, so callers do not have to reach the
     * private helper through reflection.
     *
     * @param string $scopeType 'kingdom' | 'park'
     * @param int    $scopeId
     * @param int    $updatedBy acting mundane_id (audit)
     * @return string the chosen '#rrggbb', or '' when no colour could be derived
     */
    public function EnsureOrgTheme($scopeType, $scopeId, $updatedBy)
    {
        return $this->_seedOrgTheme($scopeType, $scopeId, $updatedBy);
    }

    /**
     * Give a freshly-provisioned org site its own palette, derived from its own
     * heraldry, and ACTIVATE it.
     *
     * A site with no theme row makes GetActiveCss() return '', which drops the
     * org through to the raw CSS defaults (MedievalSharp and all). Seeding a row
     * is therefore not a nicety: it is the only thing that makes the org's own
     * design tokens reachable.
     *
     * Colour cascade: the org's own device, then its PARENT KINGDOM's device (a
     * park with no arms belongs to a kingdom that almost certainly has some, and
     * inheriting is meaningful rather than arbitrary), then a deterministic hash
     * of the name. Never a fixed default — that would make every deviceless park
     * identical.
     *
     * @param string $scopeType 'kingdom' | 'park'
     * @param int    $scopeId
     * @param int    $uid acting mundane_id (audit)
     * @param bool   $isRepair true only from EnsureSite's repair branch
     * @return string the chosen '#rrggbb'
     */
    private function _seedOrgTheme($scopeType, $scopeId, $uid, $isRepair = false)
    {
        $scopeType = (string) $scopeType;
        $scopeId   = (int) $scopeId;

        $primary = '';
        if (class_exists('CmsHeraldryColor')) {
            // The heraldry step decodes, resamples and buckets an image through
            // GD, INLINE in the officer's first dashboard GET. A large, slow,
            // truncated or malformed device file must never be able to hang or
            // fail site provisioning, so it is both bounded (an oversize file,
            // by bytes on disk OR by decoded pixel count, is not decoded at all
            // — see _decodableHeraldryPath) and
            // non-fatal: any Throwable drops through to the name hash below and
            // leaves an audit row. The stamp gate in _finishSeed() deliberately
            // does NOT depend on the theme, so a site that loses its palette
            // this way is still a complete, usable site.
            try {
                $primary = CmsHeraldryColor::FromFile($this->_decodableHeraldryPath($scopeType, $scopeId));

                if ($primary === '' && $scopeType === 'park') {
                    $parentKingdomId = $this->_parentKingdomIdForPark($scopeId);
                    if ($parentKingdomId > 0) {
                        $primary = CmsHeraldryColor::FromFile(
                            $this->_decodableHeraldryPath('kingdom', $parentKingdomId)
                        );
                    }
                }
            } catch (\Throwable $e) {
                $primary = '';
                $this->_cmsAudit((int) $uid, 'theme.heraldry_failed', 'theme', 0, $scopeType, $scopeId);
            }

            if ($primary === '') {
                $primary = CmsHeraldryColor::FromName($this->OrgDisplayName($scopeType, $scopeId));
            }
        }
        if ($primary === '') {
            return '';
        }

        if (!class_exists('CmsTheme')) {
            return $primary;
        }
        $theme = new CmsTheme();

        // A REPAIR pass (template_seeded_at still NULL on an existing site) must
        // never clobber a palette the org has already customized: SaveTheme
        // probes by (scope, name) and rewrites tokens_json IN PLACE, and the
        // theme editor's own default name is the same 'Default' written below.
        // The first-creation path can't collide (no dashboard load has happened
        // yet), so the guard is scoped to the repair.
        if ($isRepair && $theme->GetActiveTheme($scopeType, $scopeId) !== null) {
            return $primary;
        }

        $id = (int) $theme->SaveTheme($scopeType, $scopeId, 'Default', array(
            '--fd-primary'      => $primary,
            '--fd-font-heading' => 'Archivo',
            '--fd-font-body'    => 'Lexend',
            '--fd-radius'       => '6px',
        ), (int) $uid);

        if ($id > 0) {
            $theme->SetActive($scopeType, $scopeId, $id);
        } else {
            // The theme write silently dropped (PDO runs ERRMODE_WARNING here, so
            // nothing throws). Leave a trail rather than stamping the site seeded
            // with no evidence the palette never landed.
            $this->_cmsAudit((int) $uid, 'theme.seed_failed', 'theme', 0, $scopeType, $scopeId);
        }
        return $primary;
    }

    /**
     * The largest heraldry master this seed will hand to GD. A device is a small
     * badge; anything past this is either a mis-uploaded photo or something
     * hostile, and decoding it inline in a dashboard GET is not worth a colour.
     */
    private const MAX_HERALDRY_DECODE_BYTES = 6291456; // 6 MiB

    /**
     * The largest DECODED image this seed will hand to GD, in pixels. Bytes on
     * disk are not a bound on memory: a flat-fill 30000x30000 PNG compresses to
     * a few hundred KB and still allocates gigabytes once decoded, and a memory
     * exhaustion is an uncatchable fatal that the try/catch around FromFile()
     * cannot absorb. Heraldry masters are org-uploaded, so this is
     * attacker-influenced input on the provisioning path.
     */
    private const MAX_HERALDRY_DECODE_PIXELS = 40000000; // ~40 MP

    /**
     * _heraldryPath(), but '' for any file too large to decode inside a web
     * request — measured BOTH ways: bytes on disk, and decoded pixel count read
     * from the image header. The decode/resample happens synchronously in the
     * officer's first dashboard load, so this is the bound on that work: the
     * caller simply falls through to the next colour source (parent kingdom,
     * then the name hash) exactly as it does for an org with no device at all.
     *
     * @return string absolute path, or ''
     */
    private function _decodableHeraldryPath($scopeType, $scopeId)
    {
        $path = $this->_heraldryPath($scopeType, $scopeId);
        if ($path === '') {
            return '';
        }
        $bytes = @filesize($path);
        if ($bytes === false || $bytes > self::MAX_HERALDRY_DECODE_BYTES) {
            return '';
        }
        // Header-only probe: cheap, and the only check that sees the decode
        // bomb (see MAX_HERALDRY_DECODE_PIXELS). An unreadable header means GD
        // has nothing to decode either, so refuse that too.
        $info = @getimagesize($path);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return '';
        }
        if (((int) $info[0] * (int) $info[1]) > self::MAX_HERALDRY_DECODE_PIXELS) {
            return '';
        }
        return $path;
    }

    /**
     * Absolute path to an org's heraldry master, or '' when it has none.
     *
     * Gates on has_heraldry, NOT on a truthy URL: Heraldry::resolve_heraldry_url()
     * returns a guaranteed-404 path when no file exists, so a URL check would
     * always look positive.
     *
     * The filename's zero-pad width comes from Heraldry::PAD_LENGTHS and MUST NOT
     * be re-typed here. It is 5 for a park but 4 for a kingdom, and this method
     * originally hard-coded 5 for both — so every kingdom probe looked for a file
     * (00007.jpg) that cannot exist next to the real 0007.jpg, returned '', and
     * dropped the whole colour cascade onto the name hash. It failed silently
     * because "no file" is a legitimate answer this method has to be able to give.
     *
     * @return string absolute path, or ''
     */
    private function _heraldryPath($scopeType, $scopeId)
    {
        global $DB;
        $table = ($scopeType === 'park') ? 'park' : 'kingdom';
        $idCol = $table . '_id';

        $DB->Clear();
        $DB->org_id = (int) $scopeId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT has_heraldry FROM ' . DB_PREFIX . $table
            . ' WHERE ' . $idCol . ' = :org_id LIMIT 1'
        ));
        if ($row === null || (int) ($row['has_heraldry'] ?? 0) !== 1) {
            return '';
        }

        $name = Heraldry::BaseName($table, (int) $scopeId);
        if ($name === '') {
            return '';
        }
        $base = rtrim(DIR_HERALDRY, '/') . '/' . $table . '/' . $name;
        foreach (array('.png', '.jpg', '.jpeg', '.gif') as $ext) {
            if (is_readable($base . $ext)) {
                return $base . $ext;
            }
        }
        return '';
    }

    /** Parent kingdom of a park, or 0. */
    private function _parentKingdomIdForPark($parkId)
    {
        global $DB;
        $DB->Clear();
        $DB->park_id = (int) $parkId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = :park_id LIMIT 1'
        ));
        return ($row === null) ? 0 : (int) ($row['kingdom_id'] ?? 0);
    }

    /**
     * Look up a page by slug within a scope INCLUDING soft-deleted rows — the one
     * lookup CmsPage::GetPageBySlug deliberately cannot do (it always filters
     * deleted_at IS NULL). Used by the starter seed so a TRASHED starter page is
     * recognized as "already existed, deliberately removed" rather than re-created.
     *
     * @param string $slug
     * @param string $scopeType 'kingdom'|'park'
     * @param int    $scopeId
     * @return array|null the page row (live or trashed), or null when none exists
     */
    private function _anyPageBySlug($slug, $scopeType, $scopeId)
    {
        global $DB;

        $DB->Clear();
        $DB->slug       = (string)$slug;
        $DB->scope_type = (string)$scopeType;
        $DB->scope_id   = (int)$scopeId;
        return $this->_firstRow($DB->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE slug = :slug AND scope_type = :scope_type AND scope_id = :scope_id'
            . ' ORDER BY (deleted_at IS NULL) DESC, page_id ASC LIMIT 1'
        ));
    }

    /**
     * The single-letter public URL prefix for a CMS scope: park → 'p', everything
     * else → 'k'. Canonical home for the rule, because it is consumed from two
     * unrelated controllers (Controller_Site building public URLs and the CMS
     * dashboard linking out to them) which cannot reach each other's privates.
     *
     * @param string $scopeType 'global' | 'kingdom' | 'park'
     * @return string 'p' | 'k'
     */
    public static function UrlPrefixFor($scopeType)
    {
        return ((string)$scopeType === 'park') ? 'p' : 'k';
    }

    /**
     * The org-unit NOUN for a CMS scope: 'Kingdom', 'Principality', or 'Park'.
     *
     * Amtgard models a principality as an ork_kingdom row carrying a non-zero
     * parent_kingdom_id — there is no separate table and no separate CMS
     * scope_type, so a principality's site is already a perfectly ordinary
     * scope_type='kingdom' site and needs no schema change. What it does need is
     * to stop being CALLED a kingdom: telling the officers of a principality that
     * "this kingdom is building its website", or that only a "monarch or regent"
     * may publish it, is simply false about their org.
     *
     * Cheap and cached per request: one keyed read, memoized by scope, and only
     * ever consulted for kingdom-scoped orgs (park and global are decided without
     * touching the database).
     *
     * @param string $scopeType 'global' | 'kingdom' | 'park'
     * @param int    $scopeId
     * @return string 'Kingdom' | 'Principality' | 'Park' | '' for global/unknown
     */
    public function OrgUnitNoun($scopeType, $scopeId)
    {
        $scopeType = (string)$scopeType;
        $scopeId   = (int)$scopeId;

        if ($scopeType === 'park') {
            return 'Park';
        }
        if ($scopeType !== 'kingdom' || $scopeId <= 0) {
            return '';
        }

        static $memo = array();
        if (isset($memo[$scopeId])) {
            return $memo[$scopeId];
        }

        global $DB;
        $DB->Clear();
        $DB->kingdom_id = $scopeId;
        $r = $DB->DataSet(
            'SELECT parent_kingdom_id FROM ' . DB_PREFIX . 'kingdom'
            . ' WHERE kingdom_id = :kingdom_id LIMIT 1'
        );
        // DataSet() needs an explicit Next() before any field read.
        $parent = ($r && $r->Next()) ? (int)$r->parent_kingdom_id : 0;

        $memo[$scopeId] = ($parent > 0) ? 'Principality' : 'Kingdom';
        return $memo[$scopeId];
    }

    /**
     * Public accessor for the owning org's real ORK name (kingdom or park).
     *
     * Used as the fallback for the browser-tab identity when a site row's own
     * site_name is empty — sites created before site_name was seeded on the create
     * path have one, and their tab would otherwise read a bare "Home" with no
     * indication of whose site it is.
     *
     * @param string $scopeType 'kingdom' | 'park'
     * @param int    $scopeId
     * @return string '' when it cannot be resolved
     */
    public function OrgDisplayName($scopeType, $scopeId)
    {
        return $this->_orgDisplayName($scopeType, $scopeId);
    }

    /**
     * The org's real display name for a site scope, via the existing ORK libs
     * (Kingdom::GetName / Park::GetParkShortInfo). Returns '' when the id is
     * unknown or the libs aren't wired up.
     *
     * @param string $scopeType 'kingdom'|'park'
     * @param int    $scopeId
     * @return string
     */
    private function _orgDisplayName($scopeType, $scopeId)
    {
        $scopeId = (int)$scopeId;
        if ($scopeId <= 0 || !isset(Ork3::$Lib) || !is_object(Ork3::$Lib)) {
            return '';
        }

        if ($scopeType === 'park') {
            if (!isset(Ork3::$Lib->park) || !is_object(Ork3::$Lib->park)) {
                return '';
            }
            $r = Ork3::$Lib->park->GetParkShortInfo(array('ParkId' => $scopeId));
            return (is_array($r) && isset($r['ParkInfo']['ParkName']))
                ? trim((string)$r['ParkInfo']['ParkName'])
                : '';
        }

        if (!isset(Ork3::$Lib->kingdom) || !is_object(Ork3::$Lib->kingdom)) {
            return '';
        }
        return trim((string)Ork3::$Lib->kingdom->GetName($scopeId));
    }

    /**
     * Batch discovery map: [scope_id => slug] for every PUBLISHED site of a given
     * scope type. One query — used by the Directory to render a "Visit site" link
     * per org WITHOUT an N+1 per-row GetSiteForScope() call. The unique
     * (scope_type, scope_id) key guarantees at most one row per scope_id.
     *
     * @param string $scopeType 'kingdom'|'park'
     * @return array<int,string> map of scope_id => slug (empty when none published)
     */
    public function PublishedSlugMapByScope($scopeType)
    {
        global $DB;

        $scopeType = $this->_normalizeSiteScopeType($scopeType);

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $rs = $DB->DataSet(
            'SELECT scope_id, slug FROM ' . DB_PREFIX . 'cms_site'
            . " WHERE scope_type = :scope_type AND status = 'published'"
        );

        $map = array();
        foreach ($this->_eachRow($rs) as $row) {
            $sid  = (int) $row['scope_id'];
            $slug = (string) $row['slug'];
            if ($sid > 0 && $slug !== '') {
                $map[$sid] = $slug;
            }
        }
        return $map;
    }

    /**
     * Super-admin overview: enumerate EVERY started org site (one ork_cms_site
     * row each, any status) with its real org name and content aggregates.
     *
     * One query — the org name comes from a scope_type-gated LEFT JOIN to
     * ork_kingdom / ork_park (integer-id joins). ork_park is MyISAM/latin1 while
     * ork_kingdom/ork_cms_site are InnoDB/utf8mb4, so the COALESCE of the two name
     * columns is the collation-sensitive expression (not the join): each side is
     * CONVERT'ed to utf8mb4 so mixing them can't raise "Illegal mix of collations".
     * The page/post counts are correlated subqueries keyed on the (scope_type,
     * scope_id) tuple these sites share with ork_cms_page/_post.
     *
     * Ordered kingdoms-then-parks ('kingdom' < 'park'), then by org name, so the
     * caller can split the flat list into its two sections in order.
     *
     * org_missing flags a site whose SCOPE ORG NO LONGER EXISTS — the LEFT JOIN
     * to ork_kingdom/ork_park found nothing, because the org was merged,
     * re-keyed or deleted after the site was provisioned. Detection only, on
     * purpose: such a row still holds its UNIQUE slug forever and GetSiteBySlug()
     * still resolves /k/{slug} to a scope with no owning org, but deleting it,
     * drafting it or freeing its slug are all destructive dispositions that need
     * a human decision. OPEN QUESTION, deliberately unanswered here: what SHOULD
     * happen to an orphan — re-point it at the surviving org after a merge,
     * archive it, or release the slug? Until that is decided, an admin at least
     * has to be able to see it, and org_name being blank is indistinguishable
     * from a data glitch.
     *
     * @return array list of site rows, each carrying the base ork_cms_site
     *   columns (including the raw template_seeded_at marker) plus: org_name,
     *   pages_total, pages_published, posts_total, org_missing.
     */
    public function ListAllSites()
    {
        global $DB;

        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT s.*,'
            . ' COALESCE(CONVERT(k.name USING utf8mb4), CONVERT(p.name USING utf8mb4)) AS org_name,'
            . ' (SELECT COUNT(*) FROM ' . DB_PREFIX . 'cms_page pg'
            . '    WHERE pg.scope_type = s.scope_type AND pg.scope_id = s.scope_id'
            . '      AND pg.deleted_at IS NULL) AS pages_total,'
            // INVARIANT: pages_published counts PUBLICLY REACHABLE pages, so it
            // is gated on the OWNING SITE's own status as well as the page's.
            // The starter seed deliberately publishes all five starter pages the
            // moment EnsureSite first runs, so an ungated count reported
            // "5 published pages" for every org that had merely opened its
            // dashboard once — an admin scanning for orgs with a LIVE site could
            // not tell them from genuinely published ones. An unbuilt or draft
            // site reports 0.
            . ' CASE WHEN s.status = \'published\' THEN (SELECT COUNT(*) FROM ' . DB_PREFIX . 'cms_page pg'
            . '    WHERE pg.scope_type = s.scope_type AND pg.scope_id = s.scope_id'
            . "      AND pg.status = 'published' AND pg.deleted_at IS NULL) ELSE 0 END AS pages_published,"
            . ' (SELECT COUNT(*) FROM ' . DB_PREFIX . 'cms_post po'
            . '    WHERE po.scope_type = s.scope_type AND po.scope_id = s.scope_id'
            . '      AND po.deleted_at IS NULL) AS posts_total,'
            // 1 when neither LEFT JOIN matched — the owning org row is gone.
            . ' CASE WHEN k.kingdom_id IS NULL AND p.park_id IS NULL THEN 1 ELSE 0 END AS org_missing'
            . ' FROM ' . DB_PREFIX . 'cms_site s'
            . ' LEFT JOIN ' . DB_PREFIX . "kingdom k ON s.scope_type = 'kingdom' AND k.kingdom_id = s.scope_id"
            . ' LEFT JOIN ' . DB_PREFIX . "park    p ON s.scope_type = 'park'    AND p.park_id    = s.scope_id"
            . ' ORDER BY s.scope_type ASC, org_name ASC, s.slug ASC'
        );
        return $this->_eachRow($rs);
    }

    /**
     * Content aggregates for the GLOBAL front door (scope_type='global',
     * scope_id=0), which is NOT an ork_cms_site row — its home lives directly in
     * ork_cms_page. Powers the pinned "Amtgard International" summary card on the
     * sites overview. Mirrors the ListAllSites subquery shape for the global tuple.
     *
     * @return array{pages_total:int, pages_published:int, posts_total:int}
     */
    public function GlobalPageCounts()
    {
        global $DB;

        $DB->Clear();
        $row = $this->_firstRow($DB->DataSet(
            'SELECT'
            . ' (SELECT COUNT(*) FROM ' . DB_PREFIX . 'cms_page pg'
            . "    WHERE pg.scope_type = 'global' AND pg.scope_id = 0"
            . '      AND pg.deleted_at IS NULL) AS pages_total,'
            . ' (SELECT COUNT(*) FROM ' . DB_PREFIX . 'cms_page pg'
            . "    WHERE pg.scope_type = 'global' AND pg.scope_id = 0"
            . "      AND pg.status = 'published' AND pg.deleted_at IS NULL) AS pages_published,"
            . ' (SELECT COUNT(*) FROM ' . DB_PREFIX . 'cms_post po'
            . "    WHERE po.scope_type = 'global' AND po.scope_id = 0"
            . '      AND po.deleted_at IS NULL) AS posts_total'
        ));
        return array(
            'pages_total'     => (int)($row['pages_total'] ?? 0),
            'pages_published' => (int)($row['pages_published'] ?? 0),
            'posts_total'     => (int)($row['posts_total'] ?? 0),
        );
    }

    /**
     * Publish a site: status='published', stamping published_at (only when not
     * already set, so re-publish preserves the historical first-publish stamp).
     *
     * @param int $siteId
     * @param int $uid acting mundane_id
     * @return bool
     */
    public function SetPublished($siteId, $uid)
    {
        global $DB;

        $siteId = (int)$siteId;
        if ($siteId <= 0) {
            return false;
        }

        // Stamp published_at only if not already set.
        $DB->Clear();
        $DB->site_id = $siteId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT slug, published_at FROM ' . DB_PREFIX . 'cms_site WHERE site_id = :site_id LIMIT 1'
        ));
        if ($row === null) {
            return false;
        }
        $publishedAt = (isset($row['published_at']) && $row['published_at'] !== null && $row['published_at'] !== '')
            ? (string)$row['published_at']
            : date('Y-m-d H:i:s');

        $DB->Clear();
        $DB->published_at = $publishedAt;
        $DB->updated_by   = (int)$uid;
        $DB->site_id      = $siteId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_site'
            . " SET status = 'published', published_at = :published_at, updated_by = :updated_by"
            . ' WHERE site_id = :site_id'
        );

        // Status change alters the cached row served by the /k/{slug} router.
        $this->_bustSlugCache(isset($row['slug']) ? (string)$row['slug'] : '');
        return true;
    }

    /**
     * Return a site to draft. Leaves the historical published_at intact (so a
     * later re-publish preserves the original stamp).
     *
     * @param int $siteId
     * @param int $uid acting mundane_id
     * @return bool
     */
    public function SetDraft($siteId, $uid)
    {
        global $DB;

        $siteId = (int)$siteId;
        if ($siteId <= 0) {
            return false;
        }

        // Capture the slug before the write so we can bust its cached row.
        $slug = $this->_slugForSite($siteId);

        $DB->Clear();
        $DB->updated_by = (int)$uid;
        $DB->site_id    = $siteId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_site'
            . " SET status = 'draft', updated_by = :updated_by WHERE site_id = :site_id"
        );

        // Status change alters the cached row served by the /k/{slug} router.
        $this->_bustSlugCache($slug);
        return true;
    }

    /**
     * Update a site's editable meta. Only supplied keys are written:
     * site_name, slug, logo_media_id, home_page_id. updated_by is always
     * stamped (updated_at auto-updates via ON UPDATE CURRENT_TIMESTAMP).
     *
     * Slug edits are validated (charset/reserved/uniqueness) before the write;
     * an invalid slug returns the error STRING and no columns are written. On
     * success returns true.
     *
     * A slug that actually CHANGES also retires the old one into
     * ork_cms_site_alias (_recordSlugAlias), so links already shared to the old
     * address can be redirected instead of 404ing.
     *
     * @param int   $siteId
     * @param array $fields subset of editable columns
     * @param int   $uid    acting mundane_id
     * @return true|string true on success, or a human-readable error string
     */
    public function UpdateSite($siteId, $fields, $uid)
    {
        global $DB;

        $siteId = (int)$siteId;
        if ($siteId <= 0 || !is_array($fields)) {
            return 'Invalid site.';
        }

        // Capture the slug as currently stored BEFORE any write so we can bust its
        // cached /k/{slug} row (and, when the slug itself changes, the new one too).
        // Safe to read now — the bind-at-the-end rule below means no staged $DB
        // state is clobbered by this read.
        $oldSlug = $this->_slugForSite($siteId);

        // Gather SET clauses + bind values LOCALLY first. Slug validation calls
        // ValidateSlug(), which runs $DB->Clear() internally — so binding onto $DB
        // before that would be wiped, leaving an unbound placeholder that fails the
        // whole UPDATE silently. Bind everything at the end, right before Execute.
        $set   = array();
        $binds = array();

        if (array_key_exists('site_name', $fields)) {
            $set[] = 'site_name = :site_name';
            // YapoSave null-skip rule: coerce to a string ('' clears it), never null.
            $binds['site_name'] = (string)$fields['site_name'];
        }
        if (array_key_exists('slug', $fields)) {
            // Normalize with the same derivation used at creation so a typed
            // "My Kingdom" hyphenates to "my-kingdom" rather than being silently
            // stripped to "mykingdom"; ValidateSlug then surfaces any friendly
            // error (empty/reserved/taken) inline before the write.
            $slug = $this->DeriveSlug((string)$fields['slug']);
            $valid = $this->ValidateSlug($slug, $siteId);
            if ($valid !== true) {
                return $valid; // inline error; do not write anything
            }
            $set[] = 'slug = :slug';
            $binds['slug'] = $slug;
        }
        if (array_key_exists('logo_media_id', $fields)) {
            $logoId = ($fields['logo_media_id'] === null || $fields['logo_media_id'] === '')
                ? null : (int)$fields['logo_media_id'];
            // IDOR: a non-null logo pointer MUST reference a real (non-trashed)
            // media asset that belongs to THIS site's own scope — otherwise a site
            // manager could point the logo at a cross-scope asset id. Validated
            // inline before the write; an invalid id returns a friendly error and
            // writes nothing. (Read scope off the site row; no binds on $DB yet.)
            if ($logoId !== null) {
                $err = $this->_validateLogoMedia($siteId, $logoId);
                if ($err !== true) {
                    return $err;
                }
            }
            $set[] = 'logo_media_id = :logo_media_id';
            $binds['logo_media_id'] = $logoId;
        }
        if (array_key_exists('home_page_id', $fields)) {
            $homeId = ($fields['home_page_id'] === null || $fields['home_page_id'] === '')
                ? null : (int)$fields['home_page_id'];
            // A non-null home pointer MUST reference a real (non-trashed) page
            // that belongs to THIS site's own scope — otherwise a published site
            // could point its landing page at an unbuilt/cross-scope id and silently
            // fall through to the "being built" interstitial. Validated inline
            // before the write; an invalid id returns a friendly error and writes
            // nothing. (Read scope off the site row; no binds are on $DB yet.)
            if ($homeId !== null) {
                $err = $this->_validateHomePage($siteId, $homeId);
                if ($err !== true) {
                    return $err;
                }
            }
            $set[] = 'home_page_id = :home_page_id';
            $binds['home_page_id'] = $homeId;
        }

        if (count($set) === 0) {
            return true; // nothing to change is a successful no-op
        }

        // Always stamp the updater.
        $set[] = 'updated_by = :updated_by';
        $binds['updated_by'] = (int)$uid;
        $binds['site_id']    = $siteId;

        // Bind everything now — no intervening $DB call can clobber these.
        $DB->Clear();
        foreach ($binds as $k => $v) {
            $DB->$k = $v;
        }
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_site SET ' . implode(', ', $set)
            . ' WHERE site_id = :site_id'
        );

        // Bust the cached row under the old slug, and — when the slug changed —
        // under the new slug as well so neither key can serve stale data.
        $this->_bustSlugCache($oldSlug);
        if (isset($binds['slug'])) {
            $this->_bustSlugCache((string)$binds['slug']);
        }

        // Read-back verification for the one column with a real silent-drop path:
        // ork_cms_site.slug is UNIQUE, so a race between the ValidateSlug check
        // above and this UPDATE can hit the dup-key and be swallowed (PDO runs
        // ERRMODE_WARNING here — Execute() returns void and never throws), leaving
        // the caller reporting success on a write that never landed. ROW_COUNT()
        // can't be used for this: it is legitimately 0 whenever the UPDATE writes
        // the values the row already holds.
        if (isset($binds['slug'])) {
            $stored = $this->_slugForSite($siteId);
            if ($stored !== (string)$binds['slug']) {
                return 'That web address is already in use. Please choose another.';
            }
            // The rename landed: remember the OLD slug so every link already
            // shared to it can still be resolved (and 301'd) rather than 404ing.
            // Deliberately inside the verified-success path, alongside the cache
            // bust for both slugs — an alias for a rename that never happened
            // would shadow a live site's address.
            if ($oldSlug !== '' && $oldSlug !== $stored) {
                $this->_recordSlugAlias($siteId, $oldSlug, $stored);
            }
        }
        return true;
    }

    /**
     * Record one retired slug as an alias of a site, and retire any alias that
     * would now point at that site's CURRENT slug.
     *
     * INSERT IGNORE, because alias_slug is the table's primary key: if some
     * other site already claimed this slug as an alias, the first claimant keeps
     * it rather than having its visitors silently redirected elsewhere.
     *
     * The delete is what stops a slug aliasing to itself: an org that renames
     * a → b → a would otherwise leave an alias row 'a' pointing at a site whose
     * live slug is once again 'a', and the resolver would bounce /k/a to /k/a.
     *
     * Guarded on the table existing, the same defensive way _stampTemplateSeeded
     * probes for template_seeded_at: PDO runs under ERRMODE_WARNING here, so a
     * pre-migration write would not throw — it would just raise a PHP Warning on
     * every rename. A rename must never fail because this table is missing.
     *
     * @param int    $siteId
     * @param string $oldSlug the slug being retired
     * @param string $newSlug the slug now stored on the site row
     * @return void
     */
    private function _recordSlugAlias($siteId, $oldSlug, $newSlug)
    {
        global $DB;

        $siteId = (int)$siteId;
        if ($siteId <= 0 || (string)$oldSlug === '') {
            return;
        }
        if (!$this->_tableExists(DB_PREFIX . 'cms_site_alias')) {
            return; // migration not run yet — a rename still succeeds
        }

        // Drop any alias that now equals the live slug (including one this very
        // site retired on an earlier rename) before claiming the old one.
        if ((string)$newSlug !== '') {
            $DB->Clear();
            $DB->alias_slug = (string)$newSlug;
            $DB->Execute(
                'DELETE FROM ' . DB_PREFIX . 'cms_site_alias WHERE alias_slug = :alias_slug'
            );
        }

        $DB->Clear();
        $DB->alias_slug = (string)$oldSlug;
        $DB->site_id    = $siteId;
        $DB->created_at = date('Y-m-d H:i:s');
        $DB->Execute(
            'INSERT IGNORE INTO ' . DB_PREFIX . 'cms_site_alias (alias_slug, site_id, created_at)'
            . ' VALUES (:alias_slug, :site_id, :created_at)'
        );
    }

    /**
     * Public resolver for a RETIRED slug: the site row that used to answer to
     * this address, or null. Joins through site_id so the row returned is the
     * site's CURRENT state — its live slug, status and home page — which is what
     * a caller needs to build the 301 target.
     *
     * Deliberately separate from GetSiteBySlug(): that is the hot public router
     * path and stays a single cached lookup on the live slug. This is only
     * consulted once that lookup has MISSED, so an alias costs nothing on the
     * overwhelmingly common path.
     *
     * Returns null when the alias table does not exist yet (pre-migration) — the
     * caller simply 404s as it did before, never fatals.
     *
     * @param string $slug the (possibly retired) slug from the URL
     * @return array|null the owning site's CURRENT row, or null
     */
    public function GetSiteByAliasSlug($slug)
    {
        global $DB;

        // Same normalization as GetSiteBySlug so nothing beyond [a-z0-9-] can
        // be smuggled into the lookup.
        $slug = preg_replace('/[^a-z0-9\-]+/', '', strtolower((string)$slug));
        if ($slug === '') {
            return null;
        }
        if (!$this->_tableExists(DB_PREFIX . 'cms_site_alias')) {
            return null;
        }

        $DB->Clear();
        $DB->alias_slug = $slug;
        return $this->_firstRow($DB->DataSet(
            'SELECT s.* FROM ' . DB_PREFIX . 'cms_site_alias a'
            . ' JOIN ' . DB_PREFIX . 'cms_site s ON s.site_id = a.site_id'
            . ' WHERE a.alias_slug = :alias_slug LIMIT 1'
        ));
    }

    /**
     * Turn an org name into a slug: lowercase, non-alphanumerics -> hyphen,
     * runs collapsed, trimmed, clamped to the column width (160).
     *
     * @param string $name
     * @return string
     */
    public function DeriveSlug($name)
    {
        // Shared canonical derivation (CmsBase::_normalizeSlug), including the
        // ork_cms_site.slug column-width clamp and its rtrim-the-trailing-hyphen
        // pass, so a site slug is derived byte-identically to a page/post slug.
        // $emptyFallback stays null so an unslugifiable name still returns '',
        // which callers treat as "no slug derived".
        return $this->_normalizeSlug($name, 160);
    }

    /**
     * Validate a slug for use/save. Returns true when acceptable, or a
     * human-readable error string. Pure-computation checks (empty / charset /
     * reserved) run BEFORE any DB access so they are unit-testable without a
     * database; the uniqueness check is the final step.
     *
     * The DB UNIQUE(slug) key is the hard guard; this pre-check exists for a
     * friendly inline error.
     *
     * @param int|string ...  $slug
     * @param int $exceptSiteId site to exclude from the uniqueness check (self)
     * @return true|string
     */
    public function ValidateSlug($slug, $exceptSiteId = 0)
    {
        global $DB;

        $slug = (string)$slug;

        if ($slug === '') {
            return 'Please enter a web address.';
        }
        if (strlen($slug) > 160) {
            return 'That web address is too long (160 characters max).';
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return 'The web address may contain only lowercase letters, numbers, and hyphens.';
        }
        if ($slug[0] === '-' || substr($slug, -1) === '-') {
            return 'The web address cannot start or end with a hyphen.';
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return 'That web address is reserved. Please choose another.';
        }

        // Uniqueness (hard guard is the DB UNIQUE key; this is the friendly check).
        $exceptSiteId = (int)$exceptSiteId;
        $DB->Clear();
        $DB->slug         = $slug;
        $DB->except_id    = $exceptSiteId;
        $existing = $this->_firstRow($DB->DataSet(
            'SELECT site_id FROM ' . DB_PREFIX . 'cms_site'
            . ' WHERE slug = :slug AND site_id != :except_id LIMIT 1'
        ));
        if ($existing !== null) {
            return 'That web address is already in use. Please choose another.';
        }

        return true;
    }

    /**
     * IDOR guard shared by every "this site may only point at its OWN rows"
     * validator: the referenced row must exist, not be trashed, and carry the
     * site's exact (scope_type, scope_id). Returns true when acceptable, or a
     * human-readable error string supplied by the caller.
     *
     * Both scopes are compared RAW (fail-closed): a row carrying a scope_type
     * outside the enum matches nothing rather than being clamped onto the site.
     *
     * $table/$pkCol are CODE-SUPPLIED LITERALS (they are concatenated into the
     * statement, not bound); only $id is ever caller-influenced and it is cast to
     * int. Each read runs its own Clear() because both call sites deliberately
     * invoke this with no binds staged on $DB.
     *
     * @param int    $siteId
     * @param string $table       table name WITHOUT the DB prefix (e.g. 'cms_page')
     * @param string $pkCol       primary-key column of $table (e.g. 'page_id')
     * @param int    $id          candidate row id (<= 0 short-circuits to true)
     * @param string $missingMsg  error when the row is absent/trashed
     * @param string $mismatchMsg error when the row belongs to another scope
     * @return true|string
     */
    private function _validateSameScopeRef($siteId, $table, $pkCol, $id, $missingMsg, $mismatchMsg)
    {
        global $DB;

        $siteId = (int)$siteId;
        $id     = (int)$id;
        if ($id <= 0) {
            return true; // callers only invoke with a non-null id, but be safe
        }

        // Read this site's scope (no binds are staged on $DB at the call site).
        $DB->Clear();
        $DB->site_id = $siteId;
        $siteRow = $this->_firstRow($DB->DataSet(
            'SELECT scope_type, scope_id FROM ' . DB_PREFIX . 'cms_site'
            . ' WHERE site_id = :site_id LIMIT 1'
        ));
        if ($siteRow === null) {
            return 'Invalid site.';
        }

        // Read the candidate row (excluding trashed rows).
        $DB->Clear();
        $DB->ref_id = $id;
        $refRow = $this->_firstRow($DB->DataSet(
            'SELECT scope_type, scope_id FROM ' . DB_PREFIX . $table
            . ' WHERE ' . $pkCol . ' = :ref_id AND deleted_at IS NULL LIMIT 1'
        ));
        if ($refRow === null) {
            return $missingMsg;
        }
        if ((string)$refRow['scope_type'] !== (string)$siteRow['scope_type']
            || (int)$refRow['scope_id'] !== (int)$siteRow['scope_id']
        ) {
            return $mismatchMsg;
        }
        return true;
    }

    /**
     * Validate a proposed home_page_id for a site. The page must exist, not
     * be trashed, and share the site's exact (scope_type, scope_id) so a public
     * visitor can never be pointed at a cross-scope or missing page. Returns true
     * when acceptable, or a human-readable error string.
     *
     * @param int $siteId
     * @param int $pageId
     * @return true|string
     */
    private function _validateHomePage($siteId, $pageId)
    {
        return $this->_validateSameScopeRef(
            $siteId,
            'cms_page',
            'page_id',
            $pageId,
            'That page no longer exists. Pick another home page.',
            'The home page must be one of this site\'s own pages.'
        );
    }

    /**
     * IDOR guard: validate a proposed logo_media_id for a site. The media asset
     * must exist, not be trashed, and share the site's exact (scope_type,
     * scope_id) so a manager can never point a site's logo at a cross-scope
     * asset. Returns true when acceptable, or a human-readable error string.
     *
     * @param int $siteId
     * @param int $mediaId
     * @return true|string
     */
    private function _validateLogoMedia($siteId, $mediaId)
    {
        return $this->_validateSameScopeRef(
            $siteId,
            'cms_media',
            'media_id',
            $mediaId,
            'That image no longer exists. Pick another logo.',
            'The logo must be one of this site\'s own images.'
        );
    }

    /**
     * Disambiguate a base slug against existing sites by appending -2, -3, ...
     * until ValidateSlug accepts it. Used by EnsureSite's placeholder slug AND,
     * since the mint branch stopped abandoning a colliding org name, by the
     * name-derived candidate — which is where the width clamp below earns its
     * keep: DeriveSlug() clamps to exactly 160, the full width of
     * ork_cms_site.slug, so appending a suffix to a maximal name-derived slug
     * would overflow the column (silent truncation, or a failed INSERT under
     * strict sql_mode). The BASE is therefore re-clamped to leave room for the
     * suffix, and the trailing hyphen is trimmed so the cut can't produce
     * 'foo--2' or a slug ending in '-' (which ValidateSlug rejects outright).
     *
     * @param string $base already-derived slug
     * @return string a slug that currently passes ValidateSlug
     */
    private function _uniqueSlug($base)
    {
        $base = (string)$base;
        if ($base === '') {
            $base = 'site';
        }
        if ($this->ValidateSlug($base, 0) === true) {
            return $base;
        }
        // Fit $base . $suffix inside the 160-char column.
        $fit = function ($suffix) use ($base) {
            $room = 160 - strlen($suffix);
            if ($room < 1) {
                return $suffix;   // unreachable with the suffixes below; never return ''
            }
            return rtrim(substr($base, 0, $room), '-') . $suffix;
        };
        for ($i = 2; $i < 1000; $i++) {
            $candidate = $fit('-' . $i);
            if ($this->ValidateSlug($candidate, 0) === true) {
                return $candidate;
            }
        }
        // Extremely unlikely fallback — keep it unique-ish without a DB round trip.
        return $fit('-' . time());
    }

    /* ------------------------------------------------------------------ *
     * Site-creation policy (the three rollout toggles)
     *
     * Whether an org LEVEL may have OGRE sites at all, stored in the existing
     * ork_configuration EAV table:
     *
     *   Service / 0 / CmsKingdomSitesEnabled   ORK Admins — kingdoms may build sites
     *   Service / 0 / CmsParkSitesEnabled      ORK Admins — parks may build sites
     *   Kingdom / K / CmsAllowParkSites        the kingdom — ITS parks may build sites
     *
     * The chain is AND-ed downward: a park site needs BOTH the global park
     * switch AND its own kingdom's permission. A kingdom site needs only the
     * global kingdom switch.
     *
     * These gate CREATION ONLY. A site that already exists keeps working — its
     * admin surfaces and its published public pages both — so turning a switch
     * back off is a rollout control, never a destructive act that would 404 an
     * org's live website. CanCreateSite() is therefore called on the
     * provisioning path, and nowhere in the read/render path.
     *
     * Absent config reads as OFF (fail-closed): a level is enabled only once
     * somebody deliberately turns it on.
     * ------------------------------------------------------------------ */

    /** ork_configuration keys owned by this policy. */
    public const CFG_KINGDOM_SITES = 'CmsKingdomSitesEnabled';
    public const CFG_PARK_SITES    = 'CmsParkSitesEnabled';
    public const CFG_ALLOW_PARKS   = 'CmsAllowParkSites';

    /**
     * Read one ork_configuration flag as a boolean.
     *
     * Type-tolerant on purpose. The same logical flag reads back as int 1 from a
     * backfill migration (bare scalar value) and as string '1' after a UI save
     * (json_encode'd), so a strict comparison silently fails for one of them.
     *
     * @param string $type CFG_SERVICE | CFG_KINGDOM | CFG_PARK
     * @param int    $id   scope owner id (0 for the service-wide row)
     * @param string $key
     * @return bool
     */
    private function _configFlag($type, $id, $key)
    {
        global $DB;

        $DB->Clear();
        $DB->type = (string)$type;
        $DB->id   = (int)$id;
        $DB->key  = (string)$key;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT value FROM ' . DB_PREFIX . 'configuration'
            . ' WHERE type = :type AND id = :id AND `key` = :key LIMIT 1'
        ));
        if ($row === null) {
            return false;
        }
        // json_decode handles the '"1"' form; a bare '1' decodes to int 1. Cast
        // through int so both shapes — and a stray bool — land on the same answer.
        $decoded = json_decode((string)$row['value']);
        return (int)$decoded === 1;
    }

    /**
     * Write one ork_configuration flag. Inserts the row when absent.
     *
     * Stores the json_encode'd form the rest of the app's UI saves use, so the
     * value reads identically through Common::get_configs.
     *
     * @return void
     */
    private function _setConfigFlag($type, $id, $key, $on)
    {
        global $DB;

        $value = json_encode($on ? '1' : '0');

        $DB->Clear();
        $DB->type = (string)$type;
        $DB->id   = (int)$id;
        $DB->key  = (string)$key;
        $existing = $this->_firstRow($DB->DataSet(
            'SELECT configuration_id FROM ' . DB_PREFIX . 'configuration'
            . ' WHERE type = :type AND id = :id AND `key` = :key LIMIT 1'
        ));

        $DB->Clear();
        if ($existing !== null) {
            $DB->configuration_id = (int)$existing['configuration_id'];
            $DB->value = $value;
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'configuration SET value = :value'
                . ' WHERE configuration_id = :configuration_id'
            );
            return;
        }
        $DB->type  = (string)$type;
        $DB->id    = (int)$id;
        $DB->key   = (string)$key;
        $DB->value = $value;
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'configuration'
            . ' (type, id, `key`, value, user_setting, allowed_values, var_type)'
            . " VALUES (:type, :id, :key, :value, 0, 'null', 'fixed')"
        );
    }

    /** Global switch: may KINGDOMS build OGRE sites? (ORK Admins own this.) */
    public function KingdomSitesEnabled()
    {
        return $this->_configFlag(CFG_SERVICE, 0, self::CFG_KINGDOM_SITES);
    }

    /** Global switch: may PARKS build OGRE sites? (ORK Admins own this.) */
    public function ParkSitesEnabled()
    {
        return $this->_configFlag(CFG_SERVICE, 0, self::CFG_PARK_SITES);
    }

    /**
     * Does THIS kingdom let its parks build sites? Meaningful only while the
     * global park switch is on — CanCreateSite() AND-s the two, so this alone
     * never admits a park.
     */
    public function KingdomAllowsParkSites($kingdomId)
    {
        $kingdomId = (int)$kingdomId;
        if ($kingdomId <= 0) {
            return false;
        }
        return $this->_configFlag(CFG_KINGDOM, $kingdomId, self::CFG_ALLOW_PARKS);
    }

    /** Set a global switch. The CALLER must confirm the actor is an ORK Admin. */
    public function SetKingdomSitesEnabled($on)
    {
        $this->_setConfigFlag(CFG_SERVICE, 0, self::CFG_KINGDOM_SITES, $on);
    }

    /** Set a global switch. The CALLER must confirm the actor is an ORK Admin. */
    public function SetParkSitesEnabled($on)
    {
        $this->_setConfigFlag(CFG_SERVICE, 0, self::CFG_PARK_SITES, $on);
    }

    /** Set one kingdom's park permission. The CALLER must authorize the actor. */
    public function SetKingdomAllowsParkSites($kingdomId, $on)
    {
        $kingdomId = (int)$kingdomId;
        if ($kingdomId <= 0) {
            return;
        }
        $this->_setConfigFlag(CFG_KINGDOM, $kingdomId, self::CFG_ALLOW_PARKS, $on);
    }

    /**
     * May a NEW site be provisioned for this scope right now?
     *
     * Which ork_configuration flag governs each scope, so a caller can tell the
     * officer WHO has to turn it on:
     *
     *   kingdom → Service/0/CmsKingdomSitesEnabled (an ORK Admin).
     *   park    → Service/0/CmsParkSitesEnabled (an ORK Admin) AND
     *             Kingdom/{K}/CmsAllowParkSites (the park's own kingdom).
     *   global  → always true; the front door is not a provisioned org site.
     *
     * Always a real boolean, for every scope type, and cheap enough to call
     * twice in one request: each flag is one keyed ork_configuration read, and
     * absent config reads as OFF (fail-closed). Public and side-effect free —
     * the controller calls it to decide whether to offer provisioning at all,
     * and EnsureSite calls it again on the mint path.
     *
     * @param string $scopeType
     * @param int    $scopeId
     * @return bool
     */
    public function CanCreateSite($scopeType, $scopeId)
    {
        $scopeType = $this->_normalizeSiteScopeType($scopeType);
        $scopeId   = (int)$scopeId;

        if ($scopeType === 'kingdom') {
            return $this->KingdomSitesEnabled();
        }
        if ($scopeType === 'park') {
            if (!$this->ParkSitesEnabled()) {
                return false;
            }
            // A park's permission comes from the kingdom it belongs to.
            return $this->KingdomAllowsParkSites($this->_parkKingdomId($scopeId));
        }
        return true;
    }

    /**
     * The kingdom a park belongs to (0 when unknown). Used only by the policy
     * chain above, so it stays private to it.
     *
     * @param int $parkId
     * @return int
     */
    private function _parkKingdomId($parkId)
    {
        global $DB;

        $parkId = (int)$parkId;
        if ($parkId <= 0) {
            return 0;
        }
        $DB->Clear();
        $DB->park_id = $parkId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = :park_id LIMIT 1'
        ));
        return $row === null ? 0 : (int)$row['kingdom_id'];
    }
}
