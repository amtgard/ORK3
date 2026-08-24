<?php

// system/lib/ork3/class.CmsTheme.php
// DB persistence for CMS theme token sets. Pure computation is delegated to
// CmsThemeTokens; this class only reads/writes <prefix>cms_theme.
//
// DB idiom (matches class.CmsPage.php): shared global $DB (YapoDb); always
// Clear() before a raw DataSet()/Execute(); bind values via $DB->field = ...
// (the SQL uses :field named placeholders). lastInsertId() is unreliable on
// dup-key under ERRMODE_WARNING, so INSERTs read back by the unique tuple.

require_once __DIR__ . '/class.CmsThemeTokens.php';

class CmsTheme extends CmsBase
{
    /**
     * Per-request memo of GetActiveTheme() results, keyed "{scopeType}.{scopeId}".
     * A public org-site render asks for the same active theme twice (GetActiveCss
     * + GetActiveRootCss); this collapses that to one SELECT. Any write below
     * clears it so a same-request read never serves a stale row.
     */
    private $_activeThemeMemo = array();

    public function __construct()
    {
        parent::__construct();
    }

    /** Active theme row for a scope, or null. tokens_json decoded to 'tokens'. */
    public function GetActiveTheme($scopeType = 'global', $scopeId = 0)
    {
        global $DB;
        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;

        $memoKey = $scopeType . '.' . $scopeId;
        if (array_key_exists($memoKey, $this->_activeThemeMemo)) {
            return $this->_activeThemeMemo[$memoKey];
        }

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT id, name, tokens_json, is_active, updated_at FROM ' . DB_PREFIX . 'cms_theme'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND is_active = 1 LIMIT 1'
        ));
        if ($row === null) {
            $this->_activeThemeMemo[$memoKey] = null;
            return null;
        }
        $row['tokens'] = json_decode((string)(isset($row['tokens_json']) ? $row['tokens_json'] : ''), true);
        if (!is_array($row['tokens'])) {
            $row['tokens'] = array();
        }
        $this->_activeThemeMemo[$memoKey] = $row;
        return $row;
    }

    /**
     * The <style> inner CSS for the active theme, or '' when none.
     *
     * C5: the resolved CSS is cached in GhettoCache keyed by (scope, updated_at)
     * so every anonymous public hit no longer re-runs the token→CSS compile. The
     * key is built FROM the theme row, so the row read still happens first — it is
     * only memoized per request by GetActiveTheme(), not cached across requests.
     * The key embeds the active theme's updated_at, which MariaDB bumps (ON UPDATE
     * CURRENT_TIMESTAMP) on any SaveTheme / SetActive / ResetActive write — so a
     * theme edit self-busts the cache with no explicit invalidation. A
     * missing/deactivated theme ('' result) is not cached (cheap).
     */
    public function GetActiveCss($scopeType = 'global', $scopeId = 0)
    {
        $t = $this->GetActiveTheme($scopeType, $scopeId);
        if ($t === null) {
            return '';
        }

        $cache = $this->_ghettoCache();
        if ($cache !== null) {
            $key    = $this->_normalizeScopeType($scopeType) . '.' . (int)$scopeId
                . '.' . (string)($t['updated_at'] ?? '') . '.' . (int)($t['id'] ?? 0);
            $cached = $cache->get(__CLASS__ . '.GetActiveCss', $key, 1800);
            if ($cached !== false) {
                return (string)$cached;
            }
            $css = CmsThemeTokens::ToCss($t['tokens']);
            return (string)$cache->cache(__CLASS__ . '.GetActiveCss', $key, $css);
        }

        return CmsThemeTokens::ToCss($t['tokens']);
    }

    /**
     * The same active-theme CSS as GetActiveCss(), but scoped to :root instead of
     * .fd-page, or '' when there is no active theme.
     *
     * Standalone public org sites only. <html>/<body> sit OUTSIDE .fd-page and
     * custom properties inherit downward only, so cms-base.css's body rule can
     * never see the .fd-page token block; without a root-scoped copy a dark org
     * site paints a white body. See CmsThemeTokens::ToRootCss().
     *
     * Caching mirrors GetActiveCss() exactly — same (scope, updated_at, id) key
     * material (so the theme row is read first, per-request memoized, and a theme
     * write self-busts both compiles) — but under its OWN namespace
     * (__CLASS__ . '.GetActiveRootCss'). The namespace MUST differ from
     * GetActiveCss()'s or the two would collide and serve each other's CSS.
     */
    /**
     * The Google Fonts href for the families this scope's theme actually uses.
     *
     * default.theme used to hardcode a <link> per family for EVERY CMS page —
     * Archivo, MedievalSharp and Lexend whether the org used them or not — which
     * meant the loaded set and the pickable set were two hand-maintained lists.
     * They drifted exactly as you would expect: the seeder wrote Lexend for
     * every org site while default.theme did not link it, so every site asked
     * for a webfont that was never loaded and silently fell back to the generic
     * sans. With a 47-family catalogue that approach is not merely fragile, it
     * is unaffordable.
     *
     * Falls back to the DEFAULT families when a scope has no theme row, because
     * an unthemed front door still renders Archivo and Open Sans and still needs
     * them fetched. GetActiveTheme() is memoized per request, so this is free
     * beside the GetActiveCss() call that always accompanies it.
     *
     * @return string a css2 URL, or '' when both families are system faces
     */
    public function GetActiveFontHref($scopeType = 'global', $scopeId = 0)
    {
        $tokens = CmsThemeTokens::DefaultValues();
        $t      = $this->GetActiveTheme($scopeType, $scopeId);
        if (is_array($t) && isset($t['tokens_json'])) {
            $stored = json_decode((string)$t['tokens_json'], true);
            if (is_array($stored)) {
                // Validate, never trust: a family that is no longer in the
                // catalogue must not reach the URL builder, and Validate() drops
                // it back to nothing so the default takes over.
                $tokens = array_merge($tokens, CmsThemeTokens::Validate($stored));
            }
        }
        return CmsThemeTokens::FontHref($this->_activeFontFamilies($tokens));
    }

    /**
     * The css2 QUERY for this scope's families — origin excluded on purpose.
     *
     * default.theme writes the origin as a literal and interpolates only this,
     * so the CSS-boundary gate can still prove where the stylesheet lands (C6).
     */
    public function GetActiveFontQuery($scopeType = 'global', $scopeId = 0)
    {
        $tokens = CmsThemeTokens::DefaultValues();
        $t      = $this->GetActiveTheme($scopeType, $scopeId);
        if (is_array($t) && isset($t['tokens_json'])) {
            $stored = json_decode((string)$t['tokens_json'], true);
            if (is_array($stored)) {
                $tokens = array_merge($tokens, CmsThemeTokens::Validate($stored));
            }
        }
        return CmsThemeTokens::FontQuery($this->_activeFontFamilies($tokens));
    }

    /** The two families a resolved token set selects, heading first. */
    private function _activeFontFamilies($tokens)
    {
        return array(
            isset($tokens['--fd-font-heading']) ? $tokens['--fd-font-heading'] : '',
            isset($tokens['--fd-font-body']) ? $tokens['--fd-font-body'] : '',
        );
    }

    public function GetActiveRootCss($scopeType = 'global', $scopeId = 0)
    {
        $t = $this->GetActiveTheme($scopeType, $scopeId);
        if ($t === null) {
            return '';
        }

        $cache = $this->_ghettoCache();
        if ($cache !== null) {
            $key    = $this->_normalizeScopeType($scopeType) . '.' . (int)$scopeId
                . '.' . (string)($t['updated_at'] ?? '') . '.' . (int)($t['id'] ?? 0);
            $cached = $cache->get(__CLASS__ . '.GetActiveRootCss', $key, 1800);
            if ($cached !== false) {
                return (string)$cached;
            }
            $css = CmsThemeTokens::ToRootCss($t['tokens']);
            return (string)$cache->cache(__CLASS__ . '.GetActiveRootCss', $key, $css);
        }

        return CmsThemeTokens::ToRootCss($t['tokens']);
    }

    /**
     * The id of the theme stored under (scope, name), or 0 when there is none.
     *
     * Runs its own Clear() + binds, so it must never be called with binds already
     * staged for another statement.
     *
     * @param string $scopeType already-normalized scope_type
     * @param int    $scopeId
     * @param string $name      already-trimmed/defaulted theme name
     * @return int theme id, or 0
     */
    private function _themeIdByName($scopeType, $scopeId, $name)
    {
        global $DB;

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = (int)$scopeId;
        $DB->name       = $name;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT id FROM ' . DB_PREFIX . 'cms_theme'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND name = :name LIMIT 1'
        ));
        return $row ? (int)$row['id'] : 0;
    }

    /**
     * Upsert a theme by (scope,name); returns its id (>0) or 0 on failure.
     * Stores only validated tokens. Does NOT change active state.
     */
    public function SaveTheme($scopeType, $scopeId, $name, $tokens, $uid)
    {
        global $DB;
        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;
        $name      = trim((string)$name);
        if ($name === '') {
            $name = 'Default';
        }
        $json = json_encode(CmsThemeTokens::Validate($tokens));
        $uid  = (int)$uid;
        $this->_activeThemeMemo = array();

        // Existing (scope,name) → UPDATE in place. This probe is what CHOOSES the
        // UPDATE-vs-INSERT branch; the identical read after the INSERT below is a
        // separate, mandatory read-back. Do not collapse the two into one call.
        $id = $this->_themeIdByName($scopeType, $scopeId, $name);
        if ($id > 0) {
            $DB->Clear();
            $DB->tokens_json = $json;
            $DB->updated_by  = $uid;
            $DB->id          = $id;
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'cms_theme'
                . ' SET tokens_json = :tokens_json, updated_by = :updated_by WHERE id = :id'
            );
            return $id;
        }

        // INSERT, then read back by the unique (scope,name) tuple.
        $DB->Clear();
        $DB->scope_type  = $scopeType;
        $DB->scope_id    = $scopeId;
        $DB->name        = $name;
        $DB->tokens_json = $json;
        $DB->updated_by  = $uid;
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'cms_theme'
            . ' (scope_type, scope_id, name, tokens_json, updated_by, is_active)'
            . ' VALUES (:scope_type, :scope_id, :name, :tokens_json, :updated_by, 0)'
        );

        return $this->_themeIdByName($scopeType, $scopeId, $name);
    }

    /** Make one theme active for its scope (deactivating siblings). */
    public function SetActive($scopeType, $scopeId, $id)
    {
        global $DB;
        $id         = (int)$id;
        $scopeType  = $this->_normalizeScopeType($scopeType);
        $scopeId    = (int)$scopeId;
        $this->_activeThemeMemo = array();

        // Guard: the id must belong to this scope, otherwise IF(id=:id,1,0)
        // would silently deactivate every theme in the scope without ever
        // activating the target row.
        $DB->Clear();
        $DB->id         = $id;
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $owned = $this->_firstRow($DB->DataSet(
            'SELECT id FROM ' . DB_PREFIX . 'cms_theme'
            . ' WHERE id = :id AND scope_type = :scope_type AND scope_id = :scope_id LIMIT 1'
        ));
        if ($owned === null) {
            return false;
        }

        $DB->Clear();
        $DB->id         = $id;
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_theme'
            . ' SET is_active = IF(id = :id, 1, 0)'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id'
        );
        return true;
    }

    /** Deactivate all themes for a scope (revert to CSS defaults). */
    public function ResetActive($scopeType, $scopeId)
    {
        global $DB;
        $this->_activeThemeMemo = array();
        $DB->Clear();
        $DB->scope_type = $this->_normalizeScopeType($scopeType);
        $DB->scope_id   = (int)$scopeId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_theme'
            . ' SET is_active = 0 WHERE scope_type = :scope_type AND scope_id = :scope_id'
        );
        return true;
    }

    /** Resolve arbitrary tokens to the <style> inner CSS WITHOUT persisting (live preview). */
    public function PreviewCss($tokens)
    {
        return CmsThemeTokens::ToCss(is_array($tokens) ? $tokens : array());
    }

    /** Token catalog (name => [group,value,input]) — editor metadata. */
    public function Catalog()
    {
        return CmsThemeTokens::Defaults();
    }

    /** Vetted font families for the editor's font selects. */
    public function FontAllowlist()
    {
        return CmsThemeTokens::FontAllowlist();
    }

    /**
     * The full font catalogue (group / role / fallback / weights) so the editor
     * can group its picker, render each name in its own face, and lazily request
     * only the faces it actually shows.
     */
    public function FontCatalog()
    {
        return CmsThemeTokens::FontCatalog();
    }

    /** The families offered for one font token's picker ('heading' | 'body'). */
    public function FontsForRole($role)
    {
        return CmsThemeTokens::FontsForRole($role);
    }

    /** token => default value (editor seed baseline). */
    public function BaseValues()
    {
        return CmsThemeTokens::DefaultValues();
    }
}
