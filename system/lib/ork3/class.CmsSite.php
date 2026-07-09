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

        $DB->Clear();
        $DB->slug = $slug;
        return $this->_firstRow($DB->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'cms_site WHERE slug = :slug LIMIT 1'
        ));
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
     * home_page_id → the seeded home) via _seedStarterTemplate(). The seed runs
     * ONLY in the create branch below, so it can never double-seed: a later
     * EnsureSite call finds the existing row and returns early before reaching it.
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

        // Idempotency: return the existing row untouched (no INSERT).
        $existing = $this->GetSiteForScope($scopeType, $scopeId);
        if ($existing !== null) {
            return $existing;
        }

        // A site row must carry a globally-unique slug (UNIQUE key). Derive a
        // deterministic placeholder from the scope and disambiguate if taken.
        $slug = $this->_uniqueSlug($this->DeriveSlug($scopeType . '-' . $scopeId));

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $DB->slug       = $slug;
        // YapoSave null-skip rule: assign '' (not null) so the column is written.
        $DB->site_name  = '';
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

        // FIRST-CREATION ONLY: seed the starter template (pages + blocks + nav)
        // and point home_page_id at the seeded home page. This line is reached
        // exclusively when no prior row existed (the idempotency guard above
        // returns early for an existing site), so the seed runs EXACTLY once per
        // scope and can never double-seed — even if a partial failure leaves the
        // template incomplete, the now-present site row makes every later
        // EnsureSite call return early before reaching here.
        if ($created !== null && isset($created['site_id'])) {
            $this->_seedStarterTemplate((int) $created['site_id'], $scopeType, $scopeId, $uid);
            // Re-read so the returned row carries the freshly-set home_page_id.
            $created = $this->GetSiteForScope($scopeType, $scopeId);
        }

        return $created;
    }

    /**
     * Seed the starter template for a freshly-created site: five editable pages
     * (home, about, parks, officers, documents), a scoped 'marketing' nav menu
     * linking them, and home_page_id → the seeded home. All pages are DRAFTS in
     * the site's OWN scope — the site stays unpublished until an AUTH_ADMIN
     * officer publishes it (Phase 3). Everything is editable: only the home page
     * is is_system=1 (undeletable, so the site always retains a landing page);
     * the rest can be freely edited or deleted — a seed, not a cage.
     *
     * Idempotency: invoked ONLY from EnsureSite's create branch (right after the
     * INSERT), so it runs once per scope. As belt-and-suspenders, CmsPage's
     * CreatePage self-guards the UNIQUE (scope_type, scope_id, slug) tuple
     * (returns 0 on collision) — so even a defensive re-entry cannot duplicate a
     * page or clobber an officer's later edits; $makePage() recovers the existing
     * id on collision so nav + home_page_id still link.
     *
     * Content goes through the CmsPage/CmsNav libs only — NO raw SQL here.
     *
     * @param int    $siteId    the new site row id (target of home_page_id)
     * @param string $scopeType 'kingdom'|'park'
     * @param int    $scopeId
     * @param int    $uid       acting mundane_id (audit)
     * @return void
     */
    private function _seedStarterTemplate($siteId, $scopeType, $scopeId, $uid)
    {
        // Content libs must be loaded (they are, via the ork3 scandir autoload).
        // If not, leave the bare site row rather than fatal.
        if (!class_exists('CmsPage') || !class_exists('CmsNav')) {
            return;
        }

        $page = new CmsPage();
        $nav  = new CmsNav();
        $now  = date('Y-m-d H:i:s');

        // Sanitize authored HTML bodies exactly the way the editor save path does.
        $clean = function ($html) {
            return class_exists('CmsSanitizer') ? CmsSanitizer::Clean($html) : (string) $html;
        };

        // Every seeded content page (all but Home) opens with an identical centered
        // H2 heading block — only the text differs. One factory instead of four
        // copy-pasted literals; the field values below are the exact values each
        // page previously inlined (source=authored, enabled=1, order=10, level=2,
        // align=center).
        $heading = function ($text) {
            return array(
                'type' => 'heading', 'source' => 'authored', 'enabled' => 1, 'order' => 10,
                'fields' => array('text' => $text, 'level' => 2, 'align' => 'center'),
            );
        };

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
        // hard failure). On a UNIQUE-slug collision (CreatePage returns 0) recover
        // the existing id so nav + home_page_id still resolve — defensive only;
        // collisions cannot occur on the once-per-scope create path.
        $makePage = function ($attrs, $blocks) use ($page, $baseAttrs, $scopeType, $scopeId) {
            $pid = (int) $page->CreatePage(array_merge($baseAttrs, $attrs));
            if ($pid <= 0) {
                $slug = isset($attrs['slug']) ? (string) $attrs['slug'] : '';
                $row  = ($slug !== '') ? $page->GetPageBySlug($slug, $scopeType, $scopeId, false) : null;
                return ($row !== null && isset($row['page_id'])) ? (int) $row['page_id'] : 0;
            }
            if (is_array($blocks) && count($blocks) > 0) {
                $page->ReplaceBlocks('page', $pid, $blocks);
            }
            return $pid;
        };

        // ---- HOME (is_system within scope) — welcome + intro + upcoming events ----
        // NOTE: deliberately NOT hero_carousel — that block bakes in a GLOBAL
        // stats ticker (0s on a kingdom scope) and would emit an empty-src <img>
        // with no seed image. The spec cut the stats ticker; the org adds its own
        // hero imagery via the editor. Seed a clean welcome rich_text instead.
        $homeId = $makePage(
            array(
                'slug'             => 'home',
                'type'             => 'composed',
                'title'            => 'Home',
                'is_system'        => 1,
                'meta_description' => 'Welcome to our kingdom.',
            ),
            array(
                array(
                    'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 10,
                    'fields' => array(
                        'kicker'  => 'Welcome',
                        'heading' => 'Welcome to Our Kingdom',
                        'align'   => 'center',
                        'body'    => $clean('<p>Foam swords, real friendships, and a place for everyone. Find a park near you and come play &mdash; your first day on the field is always free.</p>'),
                    ),
                ),
                array(
                    'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                    'fields' => array(
                        'kicker'  => 'About Us',
                        'heading' => 'A Kingdom of Adventurers',
                        'align'   => 'center',
                        'body'    => $clean('<p>Tell visitors who you are in a sentence or two. Edit this block to introduce your kingdom, describe what a typical game day looks like, and invite newcomers to their first (always free) day on the field.</p>'),
                    ),
                ),
                array(
                    'type' => 'kingdom_events', 'source' => 'dynamic', 'enabled' => 1, 'order' => 30,
                    'fields' => array(
                        'heading' => 'Upcoming Events',
                        'kicker'  => "What's happening",
                        'limit'   => 6,
                    ),
                ),
            )
        );

        // ---- ABOUT US / HISTORY — heading + rich_text placeholder ----
        $aboutId = $makePage(
            array(
                'slug'             => 'about',
                'type'             => 'article',
                'title'            => 'About Us',
                'meta_description' => 'About our kingdom and its history.',
            ),
            array(
                $heading('About Us'),
                array(
                    'type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                    'fields' => array(
                        'kicker'  => 'Our History',
                        'heading' => 'How We Got Here',
                        'align'   => 'left',
                        'body'    => $clean('<p>Share your kingdom&rsquo;s story: when it was founded, the lands and parks it covers, and the traditions that make it yours. Replace this placeholder with your own history.</p>'),
                    ),
                ),
            )
        );

        // ---- OUR PARKS — heading + kingdom_parks (dynamic) ----
        $parksId = $makePage(
            array(
                'slug'             => 'parks',
                'type'             => 'composed',
                'title'            => 'Our Parks',
                'meta_description' => 'Find a park near you.',
            ),
            array(
                $heading('Our Parks'),
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
            )
        );

        // ---- OFFICERS — heading + kingdom_officers (dynamic) + Board roster ----
        $officersId = $makePage(
            array(
                'slug'             => 'officers',
                'type'             => 'composed',
                'title'            => 'Officers',
                'meta_description' => 'Meet the officers who keep the kingdom running.',
            ),
            array(
                $heading('Officers'),
                array(
                    'type' => 'kingdom_officers', 'source' => 'dynamic', 'enabled' => 1, 'order' => 20,
                    'fields' => array(
                        'heading' => 'Our Officers',
                        'kicker'  => 'Leadership',
                        'limit'   => 12,
                    ),
                ),
                array(
                    'type' => 'staff_roster', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                    'fields' => array(
                        'kicker'       => 'Governance',
                        'heading'      => 'Board of Directors',
                        'subheading'   => 'Add the members who govern and steward the kingdom.',
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
            )
        );

        // ---- DOCUMENTS & RESOURCES — heading + empty file_download library ----
        $documentsId = $makePage(
            array(
                'slug'             => 'documents',
                'type'             => 'media',
                'title'            => 'Documents & Resources',
                'meta_description' => 'Kingdom documents, bylaws, and resources.',
            ),
            array(
                $heading('Documents & Resources'),
                array(
                    'type' => 'file_download', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                    'fields' => array('files' => array()),
                ),
            )
        );

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
            if ($homeId > 0) {
                $this->UpdateSite($siteId, array('home_page_id' => $homeId), $uid);
            }
            return;
        }

        $navPages = array(
            array('label' => 'Home',                  'page_id' => $homeId),
            array('label' => 'About Us',              'page_id' => $aboutId),
            array('label' => 'Our Parks',             'page_id' => $parksId),
            array('label' => 'Officers',              'page_id' => $officersId),
            array('label' => 'Documents & Resources', 'page_id' => $documentsId),
        );
        $ordering = 0;
        foreach ($navPages as $navPage) {
            if ((int) $navPage['page_id'] <= 0) {
                continue; // page failed to seed — skip its nav item
            }
            $ordering += 10;
            $nav->CreateItem(array(
                'menu'       => 'marketing',
                'label'      => $navPage['label'],
                'link_type'  => 'page',
                'page_id'    => (int) $navPage['page_id'],
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

        // ---- Point the site's landing page at the seeded home ----
        if ($homeId > 0) {
            $this->UpdateSite($siteId, array('home_page_id' => $homeId), $uid);
        }
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
            . '    WHERE pg.scope_type = s.scope_type AND pg.scope_id = s.scope_id) AS pages_total,'
            . ' (SELECT COUNT(*) FROM ' . DB_PREFIX . 'cms_page pg'
            . '    WHERE pg.scope_type = s.scope_type AND pg.scope_id = s.scope_id'
            . "      AND pg.status = 'published') AS pages_published,"
            . ' (SELECT COUNT(*) FROM ' . DB_PREFIX . 'cms_post po'
            . '    WHERE po.scope_type = s.scope_type AND po.scope_id = s.scope_id) AS posts_total'
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
            . "    WHERE pg.scope_type = 'global' AND pg.scope_id = 0) AS pages_total,"
            . ' (SELECT COUNT(*) FROM ' . DB_PREFIX . 'cms_page pg'
            . "    WHERE pg.scope_type = 'global' AND pg.scope_id = 0"
            . "      AND pg.status = 'published') AS pages_published,"
            . ' (SELECT COUNT(*) FROM ' . DB_PREFIX . 'cms_post po'
            . "    WHERE po.scope_type = 'global' AND po.scope_id = 0) AS posts_total"
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
            'SELECT published_at FROM ' . DB_PREFIX . 'cms_site WHERE site_id = :site_id LIMIT 1'
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

        $DB->Clear();
        $DB->updated_by = (int)$uid;
        $DB->site_id    = $siteId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_site'
            . " SET status = 'draft', updated_by = :updated_by WHERE site_id = :site_id"
        );
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
        // Shared canonical derivation (CmsBase::_normalizeSlug) + this class's own
        // column-width clamp on top. The normalization is now identical to the one
        // CmsPage uses for page slugs.
        $slug = $this->_normalizeSlug($name);
        if (strlen($slug) > 160) {
            $slug = rtrim(substr($slug, 0, 160), '-');
        }
        return $slug;
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
        global $DB;

        $siteId = (int)$siteId;
        $pageId = (int)$pageId;
        if ($pageId <= 0) {
            return true; // caller only invokes with a non-null id, but be safe
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

        // Read the candidate page (excluding trashed rows).
        $DB->Clear();
        $DB->page_id = $pageId;
        $pageRow = $this->_firstRow($DB->DataSet(
            'SELECT scope_type, scope_id FROM ' . DB_PREFIX . 'cms_page'
            . ' WHERE page_id = :page_id AND deleted_at IS NULL LIMIT 1'
        ));
        if ($pageRow === null) {
            return 'That page no longer exists. Pick another home page.';
        }
        if ((string)$pageRow['scope_type'] !== (string)$siteRow['scope_type']
            || (int)$pageRow['scope_id'] !== (int)$siteRow['scope_id']
        ) {
            return 'The home page must be one of this site\'s own pages.';
        }
        return true;
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
        global $DB;

        $siteId  = (int)$siteId;
        $mediaId = (int)$mediaId;
        if ($mediaId <= 0) {
            return true; // caller only invokes with a non-null id, but be safe
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

        // Read the candidate media asset (excluding trashed rows).
        $DB->Clear();
        $DB->media_id = $mediaId;
        $mediaRow = $this->_firstRow($DB->DataSet(
            'SELECT scope_type, scope_id FROM ' . DB_PREFIX . 'cms_media'
            . ' WHERE media_id = :media_id AND deleted_at IS NULL LIMIT 1'
        ));
        if ($mediaRow === null) {
            return 'That image no longer exists. Pick another logo.';
        }
        if ((string)$mediaRow['scope_type'] !== (string)$siteRow['scope_type']
            || (int)$mediaRow['scope_id'] !== (int)$siteRow['scope_id']
        ) {
            return 'The logo must be one of this site\'s own images.';
        }
        return true;
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
