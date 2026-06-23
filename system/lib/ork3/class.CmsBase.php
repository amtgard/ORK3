<?php

/*************************************************************************
 * CmsBase — shared helpers for the CMS library classes.
 *
 * CmsPage, CmsPost, CmsMedia, CmsNav, and CmsAuth all extend this class
 * instead of Ork3 directly. It exists solely to de-duplicate the three
 * private helpers that were byte-for-byte identical across every CMS lib.
 *
 * LOAD-ORDER NOTE: class.CmsAuth.php sorts before class.CmsBase.php in
 * scandir() / alphabetical order, so CmsAuth adds an explicit
 * require_once at its top. The other four CMS classes (CmsMedia, CmsNav,
 * CmsPage, CmsPost) all sort after CmsBase and require nothing extra.
 *************************************************************************/

class CmsBase extends Ork3
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Return the first row of a result set as an assoc array, or null.
     *
     * YapoDb::DataSet() pre-advances to the first row, but that pre-fetch is
     * unreliable on PDO's unbuffered MySQL cursor (and Size()/rowCount() lies
     * for SELECTs). So we drive everything off Next()'s boolean and the
     * captured field set.
     *
     * @param mixed $r YapoDb result or false/null
     * @return array|null
     */
    protected function _firstRow($r)
    {
        foreach ($this->_eachRow($r) as $row) {
            return $row;
        }
        return null;
    }

    /**
     * Yield each result row as an assoc array. Emits the pre-fetched first row
     * (if present) then advances with Next(); never trusts Size().
     *
     * @param mixed $r YapoDb result or false/null
     * @return array list of assoc rows (materialized; small result sets)
     */
    protected function _eachRow($r)
    {
        $rows = array();
        if ($r === false || $r === null) {
            return $rows;
        }
        $first = $r->CurrentFieldSet();
        if (!empty($first)) {
            $rows[] = $first;
        }
        while ($r->Next()) {
            $row = $r->CurrentFieldSet();
            if (!empty($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Clamp an arbitrary scope-type string to the supported enum.
     *
     * @param string $scopeType
     * @return string 'global'|'kingdom'|'park'
     */
    protected function _normalizeScopeType($scopeType)
    {
        $scopeType = (string)$scopeType;
        if ($scopeType === 'kingdom' || $scopeType === 'park') {
            return $scopeType;
        }
        return 'global';
    }
}
