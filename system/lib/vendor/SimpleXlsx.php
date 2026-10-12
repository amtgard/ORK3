<?php
/**
 * SimpleXlsx — minimal, zero-dependency .xlsx writer (single self-contained file).
 *
 * No Composer; just `require_once`. Packs the .xlsx ZIP in pure PHP (crc32 + pack), no ext-zip.
 * Supports: multiple worksheets, string/number cells, a small fixed style palette
 * (bold header, podium gold/silver/bronze, 2-decimal number, title, bold label),
 * per-column widths, frozen top N rows, and a single auto-filter range per sheet.
 *
 * Cells: a cell is either a scalar (int/float -> number, string -> text) or an
 * array ['v'=>value, 's'=>styleId, 't'=>'n'|'s'] for explicit style/type.
 */
class SimpleXlsx {
    // Style ids (must match the cellXfs order in stylesXml()).
    const S_DEFAULT = 0;
    const S_HEADER  = 1; // bold, light-gray fill, thin bottom border
    const S_GOLD    = 2;
    const S_SILVER  = 3;
    const S_BRONZE  = 4;
    const S_NUM2    = 5; // number, 2 decimals
    const S_TITLE   = 6; // bold, size 14
    const S_LABEL   = 7; // bold

    private $sheets = [];

    /**
     * @param string $name  tab name (sanitized + truncated to 31 chars, de-duplicated)
     * @param array  $rows  array of rows; each row an array of cells (see class doc)
     * @param array  $opts  ['colWidths'=>[w,...], 'freezeRow'=>int, 'autoFilterRef'=>'A5:I20']
     */
    public function addSheet($name, array $rows, array $opts = []) {
        $this->sheets[] = [
            'name' => $this->sheetName($name),
            'rows' => $rows,
            'opts' => $opts,
        ];
    }

    /** Write the workbook to $path. Returns $path. Throws on failure. */
    public function writeToFile($path) {
        $parts = [];
        $parts['[Content_Types].xml']        = $this->contentTypes();
        $parts['_rels/.rels']                = $this->rootRels();
        $parts['xl/workbook.xml']            = $this->workbookXml();
        $parts['xl/_rels/workbook.xml.rels'] = $this->workbookRels();
        $parts['xl/styles.xml']              = $this->stylesXml();
        foreach ($this->sheets as $i => $s) {
            $parts['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->sheetXml($s);
        }
        if (file_put_contents($path, $this->buildZip($parts)) === false) {
            throw new RuntimeException('Cannot write xlsx to ' . $path);
        }
        return $path;
    }

    /**
     * Build a ZIP archive from [name => data] using STORE (no compression).
     * Pure PHP — no ext-zip required (the container lacks ZipArchive). xlsx with
     * stored entries is fully valid and opens in Excel / LibreOffice / Sheets.
     */
    private function buildZip(array $parts) {
        $local = '';
        $central = '';
        $offset = 0;
        foreach ($parts as $name => $data) {
            $crc = crc32($data);
            $len = strlen($data);
            $nameLen = strlen($name);

            $lf  = pack('V', 0x04034b50) . pack('v', 20) . pack('v', 0) . pack('v', 0)
                 . pack('v', 0) . pack('v', 0)
                 . pack('V', $crc) . pack('V', $len) . pack('V', $len)
                 . pack('v', $nameLen) . pack('v', 0) . $name . $data;
            $local .= $lf;

            $central .= pack('V', 0x02014b50) . pack('v', 20) . pack('v', 20) . pack('v', 0)
                 . pack('v', 0) . pack('v', 0) . pack('v', 0)
                 . pack('V', $crc) . pack('V', $len) . pack('V', $len)
                 . pack('v', $nameLen) . pack('v', 0) . pack('v', 0)
                 . pack('v', 0) . pack('v', 0) . pack('V', 0)
                 . pack('V', $offset) . $name;

            $offset += strlen($lf);
        }
        $eocd = pack('V', 0x06054b50) . pack('v', 0) . pack('v', 0)
              . pack('v', count($parts)) . pack('v', count($parts))
              . pack('V', strlen($central)) . pack('V', strlen($local)) . pack('v', 0);

        return $local . $central . $eocd;
    }

    /** Public so callers can build A1-style refs (e.g. for autoFilterRef). 1 => 'A'. */
    public static function colName($n) {
        $s = '';
        $n = (int)$n;
        while ($n > 0) { $n--; $s = chr(65 + ($n % 26)) . $s; $n = intdiv($n, 26); }
        return $s !== '' ? $s : 'A';
    }

    // ---- XML parts -------------------------------------------------------

    private function contentTypes() {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($this->sheets as $i => $s) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $xml .= '</Types>';
        return $xml;
    }

    private function rootRels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml() {
        $sheetsXml = '';
        foreach ($this->sheets as $i => $s) {
            $sheetsXml .= '<sheet name="' . $this->esc($s['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetsXml . '</sheets></workbook>';
    }

    private function workbookRels() {
        $rels = '';
        $n = count($this->sheets);
        foreach ($this->sheets as $i => $s) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        // styles relationship gets the id after the last sheet
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '
            . 'Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';
    }

    private function stylesXml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.00"/></numFmts>'
        . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="6">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFD966"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE0E0E0"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE6C9A0"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left/><right/><top/><bottom style="thin"><color rgb="FF999999"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="8">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="5" borderId="0" xfId="0" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
    }

    private function sheetXml($s) {
        $rows = $s['rows'];
        $opts = $s['opts'];
        $maxCols = 0;
        foreach ($rows as $r) { $maxCols = max($maxCols, count($r)); }
        if ($maxCols < 1) $maxCols = 1;
        $nRows = max(1, count($rows));
        $dim = 'A1:' . self::colName($maxCols) . $nRows;

        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<dimension ref="' . $dim . '"/>';

        $freeze = (int)($opts['freezeRow'] ?? 0);
        if ($freeze > 0) {
            $top = 'A' . ($freeze + 1);
            $xml .= '<sheetViews><sheetView workbookViewId="0">'
                  . '<pane ySplit="' . $freeze . '" topLeftCell="' . $top . '" activePane="bottomLeft" state="frozen"/>'
                  . '<selection pane="bottomLeft" activeCell="' . $top . '" sqref="' . $top . '"/>'
                  . '</sheetView></sheetViews>';
        }
        $xml .= '<sheetFormatPr defaultRowHeight="15"/>';

        if (!empty($opts['colWidths'])) {
            $xml .= '<cols>';
            foreach ($opts['colWidths'] as $idx => $w) {
                $c = $idx + 1;
                $xml .= '<col min="' . $c . '" max="' . $c . '" width="' . (float)$w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($rows as $ri => $row) {
            $rn = $ri + 1;
            $xml .= '<row r="' . $rn . '">';
            foreach ($row as $ci => $cell) {
                $xml .= $this->cellXml(self::colName($ci + 1) . $rn, $cell);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if (!empty($opts['autoFilterRef'])) {
            $xml .= '<autoFilter ref="' . $this->esc($opts['autoFilterRef']) . '"/>';
        }

        $xml .= '</worksheet>';
        return $xml;
    }

    private function cellXml($ref, $cell) {
        $v = $cell; $s = 0; $t = null;
        if (is_array($cell)) {
            $v = $cell['v'] ?? '';
            $s = (int)($cell['s'] ?? 0);
            $t = $cell['t'] ?? null;
        }
        $sAttr = $s ? ' s="' . $s . '"' : '';
        if ($v === null || $v === '') {
            return '<c r="' . $ref . '"' . $sAttr . '/>';
        }
        $isNumber = ($t === 'n') || ($t === null && (is_int($v) || is_float($v)));
        if ($isNumber) {
            return '<c r="' . $ref . '"' . $sAttr . '><v>' . $v . '</v></c>';
        }
        return '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t xml:space="preserve">'
             . $this->esc((string)$v) . '</t></is></c>';
    }

    // ---- helpers ---------------------------------------------------------

    private function esc($s) {
        // Strip XML-1.0-invalid control characters (0x00-0x1F except tab/newline/CR).
        // A stray control byte in a persona or team name otherwise produces malformed
        // XML that Excel/LibreOffice refuse to open. /u can return null on invalid
        // UTF-8, so keep the original string in that case rather than blanking it.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$s);
        if ($clean !== null) $s = $clean;
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function sheetName($name) {
        $n = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', '-', (string)$name);
        $n = trim($n);
        if ($n === '') $n = 'Sheet' . (count($this->sheets) + 1);
        if (mb_strlen($n) > 31) $n = mb_substr($n, 0, 31);
        $existing = array_map(function ($s) { return $s['name']; }, $this->sheets);
        $base = $n; $k = 2;
        while (in_array($n, $existing, true)) {
            $suf = ' (' . $k . ')';
            $n = mb_substr($base, 0, 31 - mb_strlen($suf)) . $suf;
            $k++;
        }
        return $n;
    }
}
