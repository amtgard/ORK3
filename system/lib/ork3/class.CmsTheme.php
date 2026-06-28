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

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT id, name, tokens_json, is_active FROM ' . DB_PREFIX . 'cms_theme'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND is_active = 1 LIMIT 1'
        ));
        if ($row === null) {
            return null;
        }
        $row['tokens'] = json_decode((string)(isset($row['tokens_json']) ? $row['tokens_json'] : ''), true);
        if (!is_array($row['tokens'])) {
            $row['tokens'] = array();
        }
        return $row;
    }

    /** The <style> inner CSS for the active theme, or '' when none. */
    public function GetActiveCss($scopeType = 'global', $scopeId = 0)
    {
        $t = $this->GetActiveTheme($scopeType, $scopeId);
        if ($t === null) {
            return '';
        }
        return CmsThemeTokens::ToCss($t['tokens']);
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

        // Existing (scope,name) → UPDATE in place.
        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $DB->name       = $name;
        $existing = $this->_firstRow($DB->DataSet(
            'SELECT id FROM ' . DB_PREFIX . 'cms_theme'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND name = :name LIMIT 1'
        ));
        if ($existing !== null) {
            $id = (int)$existing['id'];
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

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $DB->name       = $name;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT id FROM ' . DB_PREFIX . 'cms_theme'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND name = :name LIMIT 1'
        ));
        return $row ? (int)$row['id'] : 0;
    }

    /** Make one theme active for its scope (deactivating siblings). */
    public function SetActive($scopeType, $scopeId, $id)
    {
        global $DB;
        $DB->Clear();
        $DB->id         = (int)$id;
        $DB->scope_type = $this->_normalizeScopeType($scopeType);
        $DB->scope_id   = (int)$scopeId;
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
        $DB->Clear();
        $DB->scope_type = $this->_normalizeScopeType($scopeType);
        $DB->scope_id   = (int)$scopeId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_theme'
            . ' SET is_active = 0 WHERE scope_type = :scope_type AND scope_id = :scope_id'
        );
        return true;
    }
}
