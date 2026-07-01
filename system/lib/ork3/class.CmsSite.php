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
     * Phase 1 (foundation) ONLY creates the ork_cms_site row with a unique
     * placeholder slug. It deliberately does NOT seed any pages, blocks, or nav.
     *
     * TODO(Phase 5 — starter template): on first creation, seed the starter
     * template here (home + About/History + Our Parks + Officers + Documents
     * pages, a scoped 'site' nav menu, and set home_page_id to the seeded home
     * page). See spec §"Starter template & provisioning". Keep the seed fully
     * editable/deletable — a seed, not a cage.
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
        return $this->GetSiteForScope($scopeType, $scopeId);
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

        $set = array();
        $DB->Clear();

        if (array_key_exists('site_name', $fields)) {
            $set[] = 'site_name = :site_name';
            // YapoSave null-skip rule: coerce to a string ('' clears it), never null.
            $DB->site_name = (string)$fields['site_name'];
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
            $DB->slug = $slug;
        }
        if (array_key_exists('logo_media_id', $fields)) {
            $set[] = 'logo_media_id = :logo_media_id';
            $DB->logo_media_id = ($fields['logo_media_id'] === null || $fields['logo_media_id'] === '')
                ? null : (int)$fields['logo_media_id'];
        }
        if (array_key_exists('home_page_id', $fields)) {
            $set[] = 'home_page_id = :home_page_id';
            $DB->home_page_id = ($fields['home_page_id'] === null || $fields['home_page_id'] === '')
                ? null : (int)$fields['home_page_id'];
        }

        if (count($set) === 0) {
            return true; // nothing to change is a successful no-op
        }

        // Always stamp the updater.
        $set[] = 'updated_by = :updated_by';
        $DB->updated_by = (int)$uid;

        $DB->site_id = $siteId;
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
        $slug = strtolower(trim((string)$name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
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
