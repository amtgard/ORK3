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

class CmsSite extends CmsBase
{
    /**
     * Reserved slugs — every real top-level route plus the pretty-URL prefixes
     * (k, p) and the site controller itself. A site slug that collided with one
     * of these would shadow (or be shadowed by) real routing. Compared
     * case-insensitively against the lowercased slug.
     */
    private static $reservedSlugs = array(
        // pretty-URL prefixes + this feature's own controller
        'k', 'p', 'site',
        // real top-level controllers (orkui/controller/controller.*.php)
        'admin', 'adminajax', 'atlas', 'attendance', 'attendanceajax',
        'authorization', 'award', 'blog', 'calendaritemajax', 'cms', 'cmsajax',
        'directory', 'eraphoenice', 'event', 'eventajax', 'eventrsvpajax',
        'heraldry', 'kingdom', 'kingdomajax', 'live', 'login', 'page', 'park',
        'parkajax', 'player', 'playerajax', 'principality', 'qr', 'recap',
        'releasenotes', 'reports', 'search', 'searchajax', 'selfreg', 'signin',
        'tournament', 'unit', 'unitajax', 'weather', 'wnajax',
        // common infrastructure paths worth reserving defensively
        'api', 'assets', 'static', 'index', 'orkui', 'orkservice', 'www',
    );

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

        // #15: cross-request GhettoCache keyed by slug. This is the public
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

        // #116: finer-grained idempotency. A site row can exist while its starter
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
            }
            $this->_bustSlugCache(($existing !== null && isset($existing['slug'])) ? (string)$existing['slug'] : '');
            return $existing;
        }

        // A site row must carry a globally-unique slug (UNIQUE key). Prefer the
        // org's REAL display name for both the site name and the slug — a brand
        // new site should read "Kingdom of the Burning Lands" / /k/kingdom-of-the-
        // burning-lands, not a blank name at /k/kingdom-42. Fall back to the
        // deterministic scope placeholder only when the name lookup comes back
        // empty or its derived slug is already taken. Mint-new-row path ONLY —
        // an existing site's slug is never touched here.
        $orgName = $this->_orgDisplayName($scopeType, $scopeId);
        $slug    = '';
        if ($orgName !== '') {
            $candidate = $this->DeriveSlug($orgName);
            if ($candidate !== '' && $this->ValidateSlug($candidate, 0) === true) {
                $slug = $candidate;
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
     * INSERT) and the #116 repair branch (an existing row whose
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
        $nav  = new CmsNav();
        $now  = date('Y-m-d H:i:s');

        // The starter page registry — ONE declaration driving both the page seed
        // below and the nav menu further down (they used to be two hand-kept lists
        // that could drift). Built once here: array order IS nav order.
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
            $pageIds[$starterSlug] = $makePage($starterDef['attrs'], $starterDef['blocks']);
        }
        $homeId = isset($pageIds['home']) ? (int) $pageIds['home'] : 0;

        // ---- Scoped nav menu ('marketing' — the key org_header.tpl reads) ----
        // link_type='page' so items follow slug changes; org_header re-points the
        // resolved Page/view href onto this site's own /Site/page/ route. Same
        // scope as the pages so CmsNav's scope-bound page join resolves the slug.
        //
        // CreateItem is NOT UNIQUE-guarded (unlike CreatePage), so the previous
        // check-then-insert guard on an empty menu was a TOCTOU: two concurrent
        // first-load EnsureSite calls could BOTH read the menu empty and BOTH seed
        // it, duplicating every nav row. Close the race with a real concurrency
        // guard. Both racing calls resolve to the SAME site row (the DB
        // UNIQUE(scope) key collapses their site INSERTs to one), so serialize them
        // on that row: open a transaction and SELECT ... FOR UPDATE the site row
        // BEFORE the empty-menu check. The first seeder holds the lock, finds the
        // menu empty, inserts, and COMMITs (releasing the lock); the second then
        // acquires the lock, sees the now-non-empty menu, and skips.
        //
        // Only the nav critical section is transacted — NOT the CmsPage seed above,
        // which issues its own COMMITs (ReplaceBlocks) that would prematurely
        // release an outer lock. The nav inserts (CmsNav::CreateItem) run plain
        // Execute()s with no inner transaction, so they nest cleanly here.
        global $DB;

        $DB->Clear();
        $DB->Execute('START TRANSACTION');

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
            return;
        }

        // Now-safe empty-menu check: we hold the row lock, so no racing seeder can
        // interleave between this read and our inserts+COMMIT below.
        $existingNav = $nav->ListItems('marketing', $scopeType, $scopeId);
        if (is_array($existingNav) && count($existingNav) > 0) {
            // Menu already seeded (or hand-edited) — leave it untouched. Release the
            // lock before UpdateSite (its own statement) still sets home_page_id.
            $DB->Clear();
            $DB->Execute('COMMIT');
            $this->_finishSeed($siteId, $scopeType, $scopeId, $uid, $homeId);
            return;
        }

        // Same registry, same order: Home/About/Parks/Officers/Documents at
        // ordering 10, 20, 30, 40, 50.
        $ordering = 0;
        foreach ($starters as $starterSlug => $starterDef) {
            $navPageId = isset($pageIds[$starterSlug]) ? (int) $pageIds[$starterSlug] : 0;
            if ($navPageId <= 0) {
                continue; // page failed to seed — skip its nav item
            }
            $ordering += 10;
            $nav->CreateItem(array(
                'menu'       => 'marketing',
                'label'      => $starterDef['nav_label'],
                'link_type'  => 'page',
                'page_id'    => $navPageId,
                'parent_id'  => null,
                'ordering'   => $ordering,
                'enabled'    => 1,
                'scope_type' => $scopeType,
                'scope_id'   => $scopeId,
            ));
        }

        // Commit the nav inserts (releasing the row lock) BEFORE the home_page_id
        // write so the lock isn't held across UpdateSite's own UPDATE.
        $DB->Clear();
        $DB->Execute('COMMIT');

        $this->_finishSeed($siteId, $scopeType, $scopeId, $uid, $homeId);
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
     * after this method's own nav inserts. Both USED to hand-call
     * _setSeededHomePage()/_stampTemplateSeeded() independently; only the
     * normal path was updated to also call _seedOrgTheme() when the theme
     * seed was added, so a site taking the early-return branch got
     * template_seeded_at stamped — a PERMANENT one-way marker — with no
     * theme row, and could never be re-seeded with one. Hoisting both
     * branches onto this one shared tail makes that class of drift
     * structurally impossible: there is now exactly one call site for
     * _stampTemplateSeeded() in the whole class, and it can never run
     * without _seedOrgTheme() having already run immediately before it.
     *
     * @param int    $siteId
     * @param string $scopeType 'kingdom'|'park'
     * @param int    $scopeId
     * @param int    $uid       acting mundane_id (audit)
     * @param int    $homeId    seeded home page_id (0 when it didn't seed)
     * @return void
     */
    private function _finishSeed($siteId, $scopeType, $scopeId, $uid, $homeId)
    {
        // ---- Point the site's landing page at the seeded home ----
        $this->_setSeededHomePage($siteId, $homeId, $uid);

        // Palette before the marker: a site that fails mid-seed should not be
        // stamped as seeded, and the theme is part of "seeded".
        $this->_seedOrgTheme($scopeType, $scopeId, $uid);

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
     * SCOPE-AWARE. This registry used to be a scope-blind constant, so a PARK site
     * was seeded with the kingdom template verbatim: three kingdom-scoped dynamic
     * blocks (kingdom_events, kingdom_parks, kingdom_parks_map, kingdom_officers)
     * that each correctly render NOTHING outside a kingdom scope, an "Our Parks"
     * page for an org that has no parks, and copy calling the park a kingdom. The
     * blocks and the Add-block chooser were both already scope-correct — only this
     * seeder was not — so the failure was silent: a brand-new park site opened with
     * three blank pages and no error anywhere. Park scope now seeds the park_*
     * counterparts (including park_meeting, the most useful block on a park page)
     * and drops the parks page entirely.
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
     * @return array slug => array{nav_label:string, attrs:array, blocks:array}
     */
    private function _starterPageDefs($scopeType, $scopeId)
    {
        $scopeType = (string) $scopeType;
        $isPark    = ($scopeType === 'park');

        // Sanitize authored HTML bodies exactly the way the editor save path does.
        $clean = function ($html) {
            return class_exists('CmsSanitizer') ? CmsSanitizer::Clean($html) : (string) $html;
        };

        // A park is not a small kingdom — it gets its OWN three-page design,
        // not a trimmed copy of the kingdom template. Returns early: none of
        // the shared $officersBlock/$eventsBlock/$defs scaffolding below is
        // kingdom-scoped anymore, it never runs for a park.
        //
        // Three pages only (Home, New Players, Contact) against the kingdom's
        // five. About Us is gone because its seeded body published author
        // instructions to the open web; Documents & Resources is gone because
        // a park has no library to put behind it; the Board of Directors
        // roster is gone because parks have no board and it published a
        // fabricated person. No Events page either: 26 of 342 parks have an
        // upcoming event, so a nav item to an empty page would tell a
        // prospective newcomer the club is dead before they clicked — Events
        // stays as a block on Home, where the honest empty state reads as
        // "nothing beyond our regular park days".
        //
        // Every time/place/officer claim below comes from a dynamic block —
        // never hand-typed — so it can never contradict the live ORK data.
        if ($isPark) {
            $uir = defined('UIR') ? UIR : 'index.php?Route=';

            // The steps CTA below links to this SAME site's own 'new-players'
            // page. A bare relative 'new-players' href 404s: Controller_Page::
            // view() is hard-coded to scope_type='global', so it can never
            // resolve a park-scoped page on its own.
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

            return array(
                'home' => array(
                    'nav_label' => 'Home',
                    'attrs' => array(
                        'slug' => 'home', 'type' => 'composed', 'title' => 'Home', 'is_system' => 1,
                        'meta_description' => 'A local Amtgard chapter — foam combat and medieval hobby, all ages, no experience or equipment needed. See when and where we meet, and what to expect on your first day.',
                    ),
                    'blocks' => array(
                        array('type' => 'park_hero', 'source' => 'dynamic', 'enabled' => 1, 'order' => 10,
                            'fields' => array('kicker' => '', 'heading' => '', 'show_weather' => 1,
                                'cta_label' => 'Plan your first visit', 'cta_href' => '#pk-meet')),
                        array('type' => 'park_meeting', 'source' => 'dynamic', 'enabled' => 1, 'order' => 20,
                            'fields' => array('kicker' => 'When can I show up?', 'heading' => 'When & Where We Meet',
                                'show_map' => 1, 'show_directions' => 1, 'limit' => 6)),
                        array('type' => 'steps', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                            'fields' => array(
                                'kicker' => 'New here? Start here', 'heading' => 'Your First Day, Start to Finish',
                                'band' => 'light',
                                'cta' => array('label' => 'More questions? Read the new player guide', 'href' => $newPlayersHref),
                                'steps' => array(
                                    array('title' => 'Just show up.', 'body' => 'You don’t need to email anyone, register, or bring anything but water. Come to the time and place above. Ten minutes early is perfect. An hour late is also fine — we’ll still be out there.'),
                                    array('title' => 'Say the words "I’m new."', 'body' => 'Walk up to anyone and say it. That is the entire process. They’ll point you at whoever is running the day. Every person on that field said the same sentence once.'),
                                    array('title' => 'Borrow a sword.', 'body' => 'We keep loaner weapons and shields for exactly this reason. They’re foam over a flexible core. Someone will walk you through the safety basics — what counts as a hit, what’s off-limits — in about five minutes.'),
                                    array('title' => 'Play, or just watch.', 'body' => 'Jump into a game whenever you’re ready. If you’d rather stand on the sideline your whole first day and figure out what’s going on, that is completely normal and nobody will push you.'),
                                ))),
                        array('type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 40,
                            'fields' => array(
                                'kicker' => 'What is this, exactly?', 'heading' => 'Who We Are', 'align' => 'left',
                                'body' => $clean($this->_parkIntroBody($scopeId)))),
                        array('type' => 'park_events', 'source' => 'dynamic', 'enabled' => 1, 'order' => 50,
                            'fields' => array('kicker' => 'What’s coming up?', 'heading' => 'Upcoming Events', 'limit' => 3)),
                        array('type' => 'park_officers', 'source' => 'dynamic', 'enabled' => 1, 'order' => 60,
                            'fields' => array('kicker' => 'Who do I talk to?', 'heading' => 'Our Officers', 'limit' => 12)),
                        array('type' => 'cta_band', 'source' => 'authored', 'enabled' => 1, 'order' => 70,
                            'fields' => $this->_parkCtaFields($scopeId)),
                    ),
                ),

                'new-players' => array(
                    'nav_label' => 'New Players',
                    'attrs' => array(
                        'slug' => 'new-players', 'type' => 'article', 'title' => 'New Players',
                        'meta_description' => 'Everything you need for your first day of Amtgard: what to wear, what it costs, whether it’s safe, and what actually happens at a park day.',
                    ),
                    'blocks' => array(
                        array('type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 10,
                            'fields' => array(
                                'kicker' => 'Never played?', 'heading' => 'Start Here', 'align' => 'left',
                                'body' => $clean('<p>Amtgard is a foam-combat and medieval hobby that meets outdoors in a public park. There is no tryout, no membership to buy, and no experience required. Turn up, borrow a sword, and someone will teach you the rest.</p>'))),
                        array('type' => 'accordion', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                            'fields' => array('items' => array(
                                array('q' => 'What should I wear?', 'a' => $clean('<p>Clothes you can run in and closed-toe shoes you don’t mind getting grass on. That’s genuinely it — you do not need a costume, armor, or anything medieval, and plenty of regulars play in gym shorts and a t-shirt. Bring water. Sunscreen if it’s that kind of day.</p>')),
                                array('q' => 'Do I need to buy equipment?', 'a' => $clean('<p>No. We have loaner weapons and shields, and you’re welcome to use them as long as you want — weeks or months, nobody’s counting. When you do want your own, most players build theirs out of foam, tape, and a bit of patience, and someone here will happily show you how. This hobby is much cheaper than it looks.</p>')),
                                array('q' => 'Does it cost anything?', 'a' => $clean('<p>Coming out and playing doesn’t. Amtgard is run entirely by volunteers — nobody here is paid and nobody is selling you anything. Some groups ask their regular members for small dues later on to keep loaner gear stocked, but nobody is going to ask you for money on your first day.</p>')),
                                array('q' => 'What actually happens at a park day?', 'a' => $clean('<p>People trickle in, gear gets laid out and safety-checked, and someone starts calling games — team battles, last-one-standing, capture the flag with foam swords. In between, people sit in the shade and talk, work on armor and costume, or practice. You can play as hard or as gently as you like; there’s no fitness requirement and no minimum. Come late, leave early, take breaks whenever you want.</p>')),
                                array('q' => 'Is it safe? Will I get hurt?', 'a' => $clean('<p>Every weapon is foam over a flexible core and gets checked before it’s used. Intentional hits to the head are against the rules, and so is swinging harder than it takes to feel a hit. You may pick up a bruise, the way you would in any sport — real injuries are rare. If someone is playing too hard, tell an officer. That’s what they’re there for.</p>')),
                                array('q' => 'Will I be the only new person?', 'a' => $clean('<p>Maybe, maybe not — some days there are three newcomers and some days there’s just you. Either way, you won’t be the only person who has ever been new: every single player out there walked up once without knowing anybody. Showing up alone is the normal way to start.</p>')),
                                array('q' => 'How old do you have to be?', 'a' => $clean('<p>Amtgard is all ages, and most groups have players from grade-schoolers to retirees. If you’re under 18, bring a parent or guardian along the first time — they may need to sign a waiver, and they’ll probably enjoy watching more than they expect.</p>')),
                                array('q' => 'Do I have to role-play or be in character?', 'a' => $clean('<p>No. Some players have an elaborate persona and a name they go by out here; plenty of others just use their own first name and hit people with foam. Both are completely normal. Nobody is going to make you do an accent.</p>')),
                            ))),
                        array('type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                            'fields' => array(
                                'kicker' => 'Not near us?', 'heading' => 'Find Another Group', 'align' => 'left',
                                'body' => $clean('<p>Amtgard has hundreds of chapters. If we’re too far away, the Atlas will find the one nearest you.</p>'),
                                'cta' => array('label' => 'Find another Amtgard group', 'href' => $uir . 'Atlas'))),
                    ),
                ),

                'contact' => array(
                    'nav_label' => 'Contact',
                    'attrs' => array(
                        'slug' => 'contact', 'type' => 'composed', 'title' => 'Contact & Officers',
                        'meta_description' => 'The volunteers who run this Amtgard chapter, and how to reach us.',
                    ),
                    'blocks' => array(
                        array('type' => 'park_officers', 'source' => 'dynamic', 'enabled' => 1, 'order' => 10,
                            'fields' => array('kicker' => 'Who do I talk to?', 'heading' => 'Our Officers', 'limit' => 12)),
                        array('type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                            'fields' => array(
                                'heading' => 'Visiting from another park?', 'align' => 'left',
                                'body' => $clean('<p>You’re welcome at any of our park days — just come as you are. If you need to reach someone before you travel, any of the officers above can help.</p>'))),
                    ),
                ),
            );
        }

        // "Kingdom" / "Principality" / "Park" — the org's own word for itself.
        // Falls back to a neutral noun rather than "Kingdom" if the lookup can't
        // resolve, so a seed can never hard-code the wrong org type.
        //
        // Computed HERE, after the park branch's early return, not up front:
        // park copy below never reads $noun/$nounLower (OrgUnitNoun('park', ...)
        // returns the literal 'Park' with no DB touch, but the call and its
        // result were still built and then discarded on every park seed). Only
        // the kingdom scaffolding below uses it.
        $noun = $this->OrgUnitNoun($scopeType, (int) $scopeId);
        if ($noun === '') {
            $noun = 'Group';
        }
        $nounLower = strtolower($noun);

        // The org's live "who holds office" block. Both partials take the same
        // fields; only the scope they read differs.
        // NOTE: reached by KINGDOM scope only — the park branch above returns
        // before this point, so kingdom_officers is the only type ever used
        // here.
        $officersBlock = array(
            'type' => 'kingdom_officers',
            'source' => 'dynamic', 'enabled' => 1, 'order' => 20,
            'fields' => array(
                'heading' => 'Our Officers',
                'kicker'  => 'Leadership',
                'limit'   => 12,
            ),
        );

        // The org's live upcoming-events block, same story (kingdom scope only).
        $eventsBlock = array(
            'type' => 'kingdom_events',
            'source' => 'dynamic', 'enabled' => 1, 'order' => 40,
            'fields' => array(
                'heading' => 'Upcoming Events',
                'kicker'  => "What's happening",
                'limit'   => 6,
            ),
        );

        // NOTE: seeded pages deliberately carry NO leading heading block. Site_shell
        // already promotes the page title to the page's <h1> whenever no content
        // block supplies one, so a heading block repeating that title rendered the
        // page name twice, one directly under the other.
        $defs = array(
            // ---- HOME (is_system within scope) — welcome + intro + upcoming events ----
            // NOTE: deliberately NOT hero_carousel — that block bakes in a GLOBAL
            // stats ticker (0s on a kingdom scope) and would emit an empty-src <img>
            // with no seed image. The spec cut the stats ticker; the org adds its own
            // hero imagery via the editor. Seed a clean welcome rich_text instead.
            'home' => array(
                'nav_label' => 'Home',
                'attrs' => array(
                    'slug'             => 'home',
                    'type'             => 'composed',
                    'title'            => 'Home',
                    'is_system'        => 1,
                    'meta_description' => 'Welcome to our ' . $nounLower . '.',
                ),
                'blocks' => array_values(array_filter(array(
                    array(
                        'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 10,
                        'fields' => array(
                            'kicker'  => 'Welcome',
                            'heading' => 'Welcome to Our ' . $noun,
                            'align'   => 'center',
                            // NOTE: reached by KINGDOM scope only — the park branch above
                            // returns before this point.
                            'body'    => $clean(
                                '<p>Foam swords, real friendships, and a place for everyone. Find a park near you and come play &mdash; your first day on the field is always free.</p>'
                            ),
                        ),
                    ),
                    array(
                        'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                        'fields' => array(
                            'kicker'  => 'About Us',
                            'heading' => 'A ' . $noun . ' of Adventurers',
                            'align'   => 'center',
                            'body'    => $clean('<p>Tell visitors who you are in a sentence or two. Edit this block to introduce your ' . $nounLower . ', describe what a typical game day looks like, and invite newcomers to their first (always free) day on the field.</p>'),
                        ),
                    ),
                    // Kingdoms have no meeting-time equivalent — their meeting times
                    // live at the park level. (A park's own home page carries a
                    // park_meeting block instead — see the park branch above.)
                    $eventsBlock,
                ))),
            ),

            // ---- ABOUT US / HISTORY — heading + rich_text placeholder ----
            'about' => array(
                'nav_label' => 'About Us',
                'attrs' => array(
                    'slug'             => 'about',
                    'type'             => 'article',
                    'title'            => 'About Us',
                    'meta_description' => 'About our ' . $nounLower . ' and its history.',
                ),
                'blocks' => array(
                    array(
                        'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                        'fields' => array(
                            'kicker'  => 'Our History',
                            'heading' => 'How We Got Here',
                            'align'   => 'left',
                            // NOTE: reached by KINGDOM scope only — the park branch above
                            // returns before this point (and no longer has an About page).
                            'body'    => $clean(
                                '<p>Share your ' . $nounLower . '&rsquo;s story: when it was founded, the lands and parks it covers, and the traditions that make it yours. Replace this placeholder with your own history.</p>'
                            ),
                        ),
                    ),
                ),
            ),

            // ---- OUR PARKS — kingdom_parks_map + kingdom_parks (both dynamic) ----
            // KINGDOM SCOPE ONLY. A park has no parks of its own, and both blocks
            // here are kingdom-scoped, so on a park site this page seeded as a
            // permanently empty "Our Parks" entry in the nav. Filtered out below.
            'parks' => array(
                'nav_label' => 'Our Parks',
                'attrs' => array(
                    'slug'             => 'parks',
                    'type'             => 'composed',
                    'title'            => 'Our Parks',
                    'meta_description' => 'Find a park near you.',
                ),
                'blocks' => array(
                    array(
                        'type' => 'kingdom_parks_map', 'source' => 'dynamic', 'enabled' => 1, 'order' => 20,
                        'fields' => array(
                            'heading' => 'Find a Park Near You',
                            'kicker'  => 'Our Parks',
                        ),
                    ),
                    array(
                        'type' => 'kingdom_parks', 'source' => 'dynamic', 'enabled' => 1, 'order' => 30,
                        'fields' => array(
                            'heading' => 'Where We Play',
                            'kicker'  => '',
                            'sort'    => 'city',
                            'show_heraldry' => 1,
                            'limit'   => 24,
                        ),
                    ),
                ),
            ),

            // ---- OFFICERS — heading + kingdom_officers (dynamic) + Board roster ----
            'officers' => array(
                'nav_label' => 'Officers',
                'attrs' => array(
                    'slug'             => 'officers',
                    'type'             => 'composed',
                    'title'            => 'Officers',
                    'meta_description' => 'Meet the officers who keep the ' . $nounLower . ' running.',
                ),
                'blocks' => array(
                    $officersBlock,
                    array(
                        'type' => 'staff_roster', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                        'fields' => array(
                            'kicker'       => 'Governance',
                            'heading'      => 'Board of Directors',
                            'subheading'   => 'Add the members who govern and steward the ' . $nounLower . '.',
                            'presentation' => 'mundane',
                            'people'       => array(
                                array(
                                    'image'        => array(),
                                    'persona_name' => '',
                                    'mundane_name' => 'Add a board member',
                                    'role'         => 'Role / title',
                                    'bio'          => '',
                                    'mundane_id'   => 0,
                                    'href'         => '',
                                ),
                            ),
                        ),
                    ),
                ),
            ),

            // ---- DOCUMENTS & RESOURCES — heading + empty file_download library ----
            'documents' => array(
                'nav_label' => 'Documents & Resources',
                'attrs' => array(
                    'slug'             => 'documents',
                    'type'             => 'media',
                    'title'            => 'Documents & Resources',
                    'meta_description' => $noun . ' documents, bylaws, and resources.',
                ),
                'blocks' => array(
                    array(
                        'type' => 'file_download', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                        'fields' => array('files' => array()),
                    ),
                ),
            ),
        );

        // NOTE: reached by KINGDOM scope only — the park branch above returns its
        // own three-page array before this point, so there is no park-side "drop
        // the Our Parks page" step here anymore.
        return $defs;
    }

    /**
     * The STABLE public href for one of THIS site's own pages, to be seeded into
     * authored block content (e.g. a CTA field).
     *
     * Deliberately builds the GLOBAL 'Page/view/{pageSlug}' form — the same form
     * CmsNav already resolves page links to — rather than resolving this site's
     * slug and baking an already-scoped 'Site/page/{siteSlug}/{pageSlug}' href in
     * at seed time. An earlier version did the latter and it went stale: officers
     * can rename a site's slug (CmsSite::UpdateSite() accepts 'slug' as an
     * editable field), and nothing re-visits already-seeded block content on a
     * rename, so the baked-in href 404s the moment they do.
     *
     * The counterpart to this is at RENDER time: frontdoor/_helpers.tpl's
     * fdSiteInternalHref() re-points the 'Page/view/' form seeded here onto this
     * site's CURRENT 'Site/page/{slug}/' route, using the live $SiteSlug for
     * that render — the exact mechanism org_header.tpl's nav rewrite already
     * uses, which is why nav already survives a slug rename for free. Seeding
     * the stable form and resolving it live, instead of resolving once at seed
     * time, gives block-authored hrefs that same guarantee.
     *
     * No DB access needed — this is a pure string builder now that resolution
     * happens at render time, not seed time.
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
     * park — never an instruction to the officer, which is what the old seed
     * published to the open web.
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
        if ($desc !== '' && mb_strlen($desc) <= 800) {
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
     * Slot 2 is left EMPTY on purpose. ork_park has exactly one url column, which is
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

        $ctas = array();
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
     * Stamp ork_cms_site.template_seeded_at once, at the end of a successful
     * starter-template seed. This is the explicit "this site HAS been seeded"
     * marker EnsureSite gates its repair on — see the #116 block there. Written
     * only when still NULL (re-entrant).
     *
     * Pre-migration DBs are handled by PROBING for the column, not by a
     * try/catch: PDO runs under ERRMODE_WARNING here (YapoMysql/YapoDb), so an
     * unknown-column UPDATE does not throw — execute() just returns false and
     * raises a PHP Warning on every new-site creation. The probe skips the write
     * (and the warning) instead. The outcome is safe either way: with the column
     * absent EnsureSite treats the site as seeded and never re-seeds.
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

        // Same _firstRow-based probe idiom as CmsBase::_tableExists().
        $DB->Clear();
        $column = $this->_firstRow($DB->DataSet(
            'SHOW COLUMNS FROM ' . DB_PREFIX . "cms_site LIKE 'template_seeded_at'"
        ));
        if ($column === null) {
            return; // migration not run yet — nothing to stamp
        }

        $DB->Clear();
        $DB->site_id = $siteId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_site SET template_seeded_at = NOW()'
            . ' WHERE site_id = :site_id AND template_seeded_at IS NULL'
        );

        // The marker rides on the cached GetSiteBySlug row (see the cache
        // contract at the top of this class) — bust it like every other mutator
        // so a row cached before the stamp can't serve template_seeded_at = NULL
        // for up to 1800s.
        $this->_bustSlugCache($this->_slugForSite($siteId));
    }

    /**
     * Give a freshly-provisioned org site its own palette, derived from its own
     * heraldry, and ACTIVATE it.
     *
     * Before this, a new site seeded no theme row at all, so GetActiveCss()
     * returned '' and every org fell through to the raw CSS defaults — which is
     * how all 342 parks ended up rendering in MedievalSharp. Seeding a row is
     * therefore not a nicety: it is the only thing that makes the org's own
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
     * @return string the chosen '#rrggbb'
     */
    private function _seedOrgTheme($scopeType, $scopeId, $uid)
    {
        $scopeType = (string) $scopeType;
        $scopeId   = (int) $scopeId;

        $primary = '';
        if (class_exists('CmsHeraldryColor')) {
            $primary = CmsHeraldryColor::FromFile($this->_heraldryPath($scopeType, $scopeId));

            if ($primary === '' && $scopeType === 'park') {
                $parentKingdomId = $this->_parentKingdomIdForPark($scopeId);
                if ($parentKingdomId > 0) {
                    $primary = CmsHeraldryColor::FromFile(
                        $this->_heraldryPath('kingdom', $parentKingdomId)
                    );
                }
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
        $id = (int) $theme->SaveTheme($scopeType, $scopeId, 'Default', array(
            '--fd-primary'      => $primary,
            '--fd-font-heading' => 'Archivo',
            '--fd-font-body'    => 'Lexend',
            '--fd-radius'       => '6px',
        ), (int) $uid);

        if ($id > 0) {
            $theme->SetActive($scopeType, $scopeId, $id);
        }
        return $primary;
    }

    /**
     * Absolute path to an org's heraldry master, or '' when it has none.
     *
     * Gates on has_heraldry, NOT on a truthy URL: Heraldry::resolve_heraldry_url()
     * returns a guaranteed-404 path when no file exists, so a URL check would
     * always look positive.
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

        $base = rtrim(DIR_HERALDRY, '/') . '/' . $table . '/' . sprintf('%05d', (int) $scopeId);
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
     * @return array list of site rows, each carrying the base ork_cms_site
     *   columns plus: org_name, pages_total, pages_published, posts_total.
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
            . ' (SELECT COUNT(*) FROM ' . DB_PREFIX . 'cms_page pg'
            . '    WHERE pg.scope_type = s.scope_type AND pg.scope_id = s.scope_id'
            . "      AND pg.status = 'published' AND pg.deleted_at IS NULL) AS pages_published,"
            . ' (SELECT COUNT(*) FROM ' . DB_PREFIX . 'cms_post po'
            . '    WHERE po.scope_type = s.scope_type AND po.scope_id = s.scope_id'
            . '      AND po.deleted_at IS NULL) AS posts_total'
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

        // #15: status change alters the cached row served by the /k/{slug} router.
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

        // #15: status change alters the cached row served by the /k/{slug} router.
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
            // C30: a non-null home pointer MUST reference a real (non-trashed) page
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

        // #15: bust the cached row under the old slug, and — when the slug changed —
        // under the new slug as well so neither key can serve stale data.
        $this->_bustSlugCache($oldSlug);
        if (isset($binds['slug'])) {
            $this->_bustSlugCache((string)$binds['slug']);
        }
        return true;
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
        // ork_cms_site.slug column-width clamp — the base helper applies the exact
        // same rtrim-the-trailing-hyphen clamp this method used to re-implement.
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
        if (in_array($slug, self::$reservedSlugs, true)) {
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
     * C30: validate a proposed home_page_id for a site. The page must exist, not
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
     * until ValidateSlug accepts it. Used by EnsureSite's placeholder slug.
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
        for ($i = 2; $i < 1000; $i++) {
            $candidate = $base . '-' . $i;
            if ($this->ValidateSlug($candidate, 0) === true) {
                return $candidate;
            }
        }
        // Extremely unlikely fallback — keep it unique-ish without a DB round trip.
        return $base . '-' . time();
    }
}
