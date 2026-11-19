# Tournament Export Results Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an "Export Results" button to the Tournament Standings page that downloads a multi-tab `.xlsx` workbook (Summary & Standings tab first, then one tab per bracket, formatted by tournament type).

**Architecture:** A new self-contained single-file `.xlsx` writer (`SimpleXlsx`, using a pure-PHP stored-ZIP packer — no `ext-zip`) lives in `system/lib/vendor/`. A new read-only builder class `TournamentExport` (in `system/lib/ork3/`) gathers data via existing `Tournament` / `TournamentReport` / `Report` library methods and composes the workbook. A thin model pass-through and a controller download endpoint (mirroring the existing `.ics` export pattern) wire it to a public route. The Standings template gets a download link.

**Tech Stack:** PHP 8 (Docker container `ork3-php8-app`, app mounted at `/var/www/html`), MariaDB (`ork3-php8-db`), the `ext-zip` extension, the existing `Ork3::$Lib` lazy-loading autoloader, and the `APIModel` magic-forwarding model layer.

---

## Testing note (read first)

This repo has **no PHP unit-test framework** (no PHPUnit, no Composer). The established verification practice — documented in the header of `system/lib/ork3/class.TournamentReport.php` — is **CLI smoke checks** run inside the Docker container.

**Confirmed environment (pre-flight already run):**
- Container `ork3-php8-app` is up; app root is **`/var/www/ork.amtgard.com`** (this is the bind-mounted repo; both `/var/www/ork.amtgard.com` and `/var/www/html` resolve to it). DB container is `ork3-php8-db` (MariaDB, `root`/`root`, db `ork`).
- **CLI bootstrap incantation** (builds `Ork3::$Lib`):
  ```
  docker exec -i ork3-php8-app php -r 'chdir("/var/www/ork.amtgard.com"); require "/var/www/ork.amtgard.com/startup.php"; ...'
  ```
- **The container has NO `ext-zip`** (`ZipArchive` is unavailable) — this is why `SimpleXlsx` uses its own pure-PHP stored-ZIP writer (`buildZip()`). `crc32()` is core and always present. Do NOT reintroduce `ZipArchive`.
- **Seed data (use these `$TID` values):**
  - `162` → single + double + ironman + points (7 brackets) — the primary multi-type fixture.
  - `173` → round-robin (6 brackets).
  - `161` / `160` → single + double + round-robin mix.
  Substitute the real integer for `$TID` in commands below.

So every task replaces "write a failing unit test" with a concrete **CLI verification command and its expected output**. This is the codebase reality and overrides the writing-plans skill's default pytest/TDD shape. Commit after each task passes its verification.

To inspect a generated workbook from the host, write it into the mounted repo root (e.g. `copy($r["Path"], "/var/www/ork.amtgard.com/tnexport_test.xlsx")`) — it appears in the repo working dir on the host as `tnexport_test.xlsx`. Always `rm` these scratch files before committing (and never `git add` them).

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `system/lib/vendor/SimpleXlsx.php` | Zero-dependency `.xlsx` writer: sheets, fixed style palette, column widths, frozen rows, auto-filter. No app knowledge. | Create |
| `system/lib/ork3/class.TournamentExport.php` | Read-only builder: gather tournament/bracket/standings/placement/match data via existing libs and compose the workbook. Returns temp-file path + download filename. | Create |
| `orkui/model/model.Tournament.php` | Add thin `export_workbook($id)` pass-through via a new `APIModel('TournamentExport')`. | Modify |
| `orkui/controller/controller.Tournament.php` | Add public `export($tournament_id)` endpoint streaming the file (mirrors the `.ics` pattern). | Modify |
| `orkui/template/revised-frontend/Tournametnew_index.tpl` | Add the "Export Results" link in the Standings tab head (and empty state). | Modify |

---

## Task 1: Create the `SimpleXlsx` writer

**Files:**
- Create: `system/lib/vendor/SimpleXlsx.php`

- [ ] **Step 1: Write the full writer file**

Create `system/lib/vendor/SimpleXlsx.php` with exactly this content:

```php
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
```

- [ ] **Step 2: Smoke-test the writer (CLI)**

Run (writes a 2-sheet workbook into the mounted repo root so the host can inspect it):

```bash
docker exec -i ork3-php8-app php -r '
require "/var/www/ork.amtgard.com/system/lib/vendor/SimpleXlsx.php";
$x = new SimpleXlsx();
$x->addSheet("Summary", [
  [["v"=>"Demo Tournament","s"=>SimpleXlsx::S_TITLE]],
  [["v"=>"Brackets","s"=>SimpleXlsx::S_LABEL], 2],
  [],
  [["v"=>"Bracket","s"=>SimpleXlsx::S_HEADER],["v"=>"Champion","s"=>SimpleXlsx::S_HEADER]],
  ["Open Longsword", ["v"=>"Sir Test","s"=>SimpleXlsx::S_GOLD]],
], ["colWidths"=>[24,22]]);
$x->addSheet("Open Longsword", [
  [["v"=>"Rank","s"=>SimpleXlsx::S_HEADER],["v"=>"Competitor","s"=>SimpleXlsx::S_HEADER],["v"=>"Points","s"=>SimpleXlsx::S_HEADER]],
  [1,"Sir Test",["v"=>9.5,"s"=>SimpleXlsx::S_NUM2,"t"=>"n"]],
], ["freezeRow"=>1, "autoFilterRef"=>"A1:C2", "colWidths"=>[8,24,10]]);
$x->writeToFile("/var/www/ork.amtgard.com/simplexlsx_smoke.xlsx");
echo "wrote\n";'
```

Expected: prints `wrote`.

- [ ] **Step 3: Validate the file is a well-formed xlsx**

```bash
unzip -l simplexlsx_smoke.xlsx
```

Expected listing includes: `[Content_Types].xml`, `_rels/.rels`, `xl/workbook.xml`, `xl/_rels/workbook.xml.rels`, `xl/styles.xml`, `xl/worksheets/sheet1.xml`, `xl/worksheets/sheet2.xml`.

Then confirm each XML part is well-formed:

```bash
mkdir -p /tmp/xlsxchk && unzip -o simplexlsx_smoke.xlsx -d /tmp/xlsxchk >/dev/null && \
for f in $(find /tmp/xlsxchk -name '*.xml'); do xmllint --noout "$f" && echo "OK $f"; done
```

Expected: an `OK ...` line for every XML file, no parser errors. (If `xmllint` is unavailable on the host, run it in the container: `docker exec ork3-php8-app sh -c 'apt-get -qq install -y libxml2-utils >/dev/null 2>&1; ...'` is overkill — instead open `simplexlsx_smoke.xlsx` in a spreadsheet app to confirm it opens with two tabs, the title is bold/large, the header row is gray, and the gold cell is filled.)

- [ ] **Step 4: Clean up the smoke file and commit**

```bash
rm -f simplexlsx_smoke.xlsx
git add system/lib/vendor/SimpleXlsx.php
git commit -m "Enhancement: SimpleXlsx zero-dependency .xlsx writer

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Create the `TournamentExport` builder — data gathering + orchestration

**Files:**
- Create: `system/lib/ork3/class.TournamentExport.php`

This task creates the class with the public `BuildWorkbook()` entry point and all private helpers EXCEPT the per-method sheet builders (those are stubbed to a single standings table here and fully implemented in Task 3). After this task the workbook already produces a Summary tab + one tab per bracket.

- [ ] **Step 1: Write the builder file**

Create `system/lib/ork3/class.TournamentExport.php` with this content:

```php
<?php
/**
 * class.TournamentExport.php
 *
 * Read-only builder that composes a multi-tab .xlsx workbook for a single
 * tournament: a "Summary & Standings" tab followed by one tab per bracket,
 * formatted by the bracket's method.
 *
 * Reuses existing library methods (no new SQL):
 *   Ork3::$Lib->report->TournamentReport(['TournamentId'=>..])      -> tournament header
 *   Ork3::$Lib->tournament->GetBrackets(['TournamentId'=>..])       -> brackets
 *   Ork3::$Lib->tournament->GetStandings(['BracketId'=>..])         -> ranked standings (routes to points/ironman)
 *   Ork3::$Lib->tournament->GetMatches(['BracketId'=>..])           -> match records
 *   Ork3::$Lib->tournamentreport->GetBracketPlacements(['BracketId'=>..]) -> podium 1/2/3
 *
 * Quick CLI check (from anywhere):
 *   docker exec -i ork3-php8-app php -r 'chdir("/var/www/ork.amtgard.com"); require "/var/www/ork.amtgard.com/startup.php";
 *     $r=(new TournamentExport())->BuildWorkbook(["TournamentId"=>14]);
 *     echo $r["Path"]."\n".$r["Filename"]."\n";'
 */
require_once(__DIR__ . '/../vendor/SimpleXlsx.php');

class TournamentExport {

    /** Friendly labels for bracket methods. */
    private static $METHOD_LABELS = [
        'single'      => 'Single Elimination',
        'double'      => 'Double Elimination',
        'swiss'       => 'Swiss',
        'round-robin' => 'Round Robin',
        'ironman'     => 'Ironman',
        'points'      => "Judge's Score",
    ];

    /** Generic column widths used by every bracket sheet (extra entries are ignored). */
    private static $WIDTHS = [8, 26, 18, 30, 9, 9, 9, 9, 12, 12, 12, 12, 12, 12];

    /**
     * Build the workbook for a tournament.
     * @return array ['Status'=>..., 'Path'=>tmpfile|null, 'Filename'=>name|null]
     */
    public function BuildWorkbook($request) {
        $tid = (int)($request['TournamentId'] ?? 0);
        if (!valid_id($tid)) {
            return ['Status' => InvalidParameter('TournamentId required'), 'Path' => null, 'Filename' => null];
        }

        $tr = Ork3::$Lib->report->TournamentReport(['TournamentId' => $tid]);
        $t  = $tr['Tournaments'][0] ?? null;
        if (!$t) {
            return ['Status' => InvalidParameter('Tournament not found'), 'Path' => null, 'Filename' => null];
        }

        $br       = Ork3::$Lib->tournament->GetBrackets(['TournamentId' => $tid]);
        $brackets = $br['Detail'] ?? [];

        $bundles = [];
        foreach ($brackets as $b) {
            $bid    = (int)$b['BracketId'];
            $method = (string)($b['Method'] ?? '');
            $st     = Ork3::$Lib->tournament->GetStandings(['BracketId' => $bid]);
            $pl     = Ork3::$Lib->tournamentreport->GetBracketPlacements(['BracketId' => $bid]);
            $mt     = Ork3::$Lib->tournament->GetMatches(['BracketId' => $bid]);
            $bundles[] = [
                'Bracket'    => $b,
                'Method'     => $method,
                'IsTeam'     => (($b['Participants'] ?? 'individual') === 'team'),
                'Standings'  => $st['Detail'] ?? [],
                'Placements' => $pl['Placements'] ?? [],
                'Matches'    => $mt['Detail'] ?? [],
            ];
        }

        $xlsx = new SimpleXlsx();
        $this->addSummarySheet($xlsx, $t, $bundles);
        foreach ($bundles as $bundle) {
            $this->addBracketSheet($xlsx, $bundle);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tnxlsx');
        $xlsx->writeToFile($tmp);

        return ['Status' => Success(), 'Path' => $tmp, 'Filename' => $this->fileName($t)];
    }

    // ---- Summary sheet ---------------------------------------------------

    private function addSummarySheet($xlsx, $t, $bundles) {
        $rows = [];
        $rows[] = [['v' => (string)($t['Name'] ?? 'Tournament'), 's' => SimpleXlsx::S_TITLE]];

        $date = $this->fmtDate($t['DateTime'] ?? ($t['Date'] ?? ''));
        if ($date !== '') $rows[] = [['v' => 'Date', 's' => SimpleXlsx::S_LABEL], $date];

        $loc = (string)($t['KingdomName'] ?? '');
        if (!empty($t['ParkName'])) $loc = trim($loc . ' / ' . $t['ParkName']);
        if ($loc !== '') $rows[] = [['v' => 'Location', 's' => SimpleXlsx::S_LABEL], $loc];

        if (!empty($t['EventName'])) $rows[] = [['v' => 'Event', 's' => SimpleXlsx::S_LABEL], (string)$t['EventName']];

        $totParts = 0; $totMatches = 0;
        foreach ($bundles as $b) { $totParts += count($b['Standings']); $totMatches += count($b['Matches']); }
        $rows[] = [['v' => 'Brackets', 's' => SimpleXlsx::S_LABEL], (int)count($bundles)];
        $rows[] = [['v' => 'Participants', 's' => SimpleXlsx::S_LABEL], (int)$totParts];
        $rows[] = [['v' => 'Matches', 's' => SimpleXlsx::S_LABEL], (int)$totMatches];
        $rows[] = [];

        $rows[] = $this->styleHeader(['Bracket', 'Format', 'Type', 'Participants', 'Matches', 'Status', 'Champion', '2nd', '3rd']);
        foreach ($bundles as $b) {
            $bk   = $b['Bracket'];
            $pods = $this->podium($b['Placements']);
            $rows[] = [
                (string)$this->bracketLabel($bk),
                (string)$this->methodLabel($b['Method']),
                $b['IsTeam'] ? 'Team' : 'Individual',
                (int)count($b['Standings']),
                (int)count($b['Matches']),
                ucfirst((string)($bk['Status'] ?? '')),
                ['v' => $pods[1] ?? '', 's' => SimpleXlsx::S_GOLD],
                ['v' => $pods[2] ?? '', 's' => SimpleXlsx::S_SILVER],
                ['v' => $pods[3] ?? '', 's' => SimpleXlsx::S_BRONZE],
            ];
        }

        $xlsx->addSheet('Summary & Standings', $rows, [
            'colWidths' => [26, 18, 12, 13, 9, 12, 22, 22, 22],
        ]);
    }

    // ---- Per-bracket dispatch (per-method builders land in Task 3) -------

    private function addBracketSheet($xlsx, $bundle) {
        $bk     = $bundle['Bracket'];
        $method = $bundle['Method'];
        $isTeam = $bundle['IsTeam'];

        $rows = [];
        $rows[] = [['v' => $this->bracketLabel($bk), 's' => SimpleXlsx::S_TITLE]];
        $meta   = $this->methodLabel($method) . ' · ' . ($isTeam ? 'Team' : 'Individual');
        if (!empty($bk['Style'])) $meta .= ' · ' . $bk['Style'];
        $rows[] = [['v' => $meta, 's' => SimpleXlsx::S_LABEL]];
        $rows[] = [];

        if (empty($bundle['Standings']) && empty($bundle['Matches'])) {
            $rows[] = [['v' => 'No matches recorded yet.', 's' => SimpleXlsx::S_DEFAULT]];
            $xlsx->addSheet($this->bracketLabel($bk), $rows, ['colWidths' => self::$WIDTHS]);
            return;
        }

        // Task 3 replaces this block with per-method builders. For now, one
        // generic standings table so the workbook is already usable end-to-end.
        $info = $this->buildStandingsRows($rows, $bundle, $isTeam, $method);

        $opts = ['colWidths' => self::$WIDTHS];
        if ($info && !empty($info['headerRow'])) {
            $opts['freezeRow'] = $info['headerRow'];
            if (!empty($info['autoFilter'])) {
                $opts['autoFilterRef'] = 'A' . $info['headerRow'] . ':'
                    . SimpleXlsx::colName($info['cols']) . $info['lastRow'];
            }
        }
        $xlsx->addSheet($this->bracketLabel($bk), $rows, $opts);
    }

    /**
     * Generic ranked standings table. Appends to $rows by reference.
     * @return array ['headerRow'=>int, 'lastRow'=>int, 'cols'=>int, 'autoFilter'=>bool]
     */
    private function buildStandingsRows(&$rows, $bundle, $isTeam, $method) {
        $includeByes = ($method === 'round-robin' || $method === 'ironman' || $method === '');
        $header = ['Rank', 'Competitor', 'Park'];
        if ($isTeam) $header[] = 'Members';
        $header = array_merge($header, ['W', 'L', 'T']);
        if ($includeByes) $header[] = 'Byes';
        $header[] = 'Points';

        $headerRow = count($rows) + 1;
        $rows[] = $this->styleHeader($header);
        foreach ($bundle['Standings'] as $s) {
            $rank = (int)($s['Rank'] ?? 0);
            $row = [$rank, (string)($s['Alias'] ?? ''), (string)($s['ParkName'] ?? '')];
            if ($isTeam) $row[] = $this->teamMembers($s);
            $row[] = (int)($s['Wins'] ?? 0);
            $row[] = (int)($s['Losses'] ?? 0);
            $row[] = (int)($s['Ties'] ?? 0);
            if ($includeByes) $row[] = (int)($s['Byes'] ?? 0);
            $row[] = (int)($s['Points'] ?? 0);
            $rows[] = $this->tintRow($row, $rank);
        }
        return ['headerRow' => $headerRow, 'lastRow' => count($rows), 'cols' => count($header), 'autoFilter' => true];
    }

    // ---- shared helpers --------------------------------------------------

    private function styleHeader(array $labels) {
        $out = [];
        foreach ($labels as $l) $out[] = ['v' => $l, 's' => SimpleXlsx::S_HEADER];
        return $out;
    }

    /** Tint the rank + competitor cells of a row for podium ranks 1-3. */
    private function tintRow(array $row, $rank) {
        $fill = $this->podiumFill($rank);
        if ($fill === null) return $row;
        foreach ([0, 1] as $i) {
            if (!array_key_exists($i, $row)) continue;
            $c = $row[$i];
            if (is_array($c)) { $c['s'] = $fill; }
            else { $c = ['v' => $c, 's' => $fill, 't' => (is_int($c) || is_float($c)) ? 'n' : 's']; }
            $row[$i] = $c;
        }
        return $row;
    }

    private function podiumFill($rank) {
        if ($rank === 1) return SimpleXlsx::S_GOLD;
        if ($rank === 2) return SimpleXlsx::S_SILVER;
        if ($rank === 3) return SimpleXlsx::S_BRONZE;
        return null;
    }

    /** First-occurrence alias for places 1..3. */
    private function podium($placements) {
        $out = [];
        foreach ($placements as $p) {
            $place = (int)($p['Place'] ?? 0);
            if ($place >= 1 && $place <= 3 && !isset($out[$place])) {
                $out[$place] = (string)($p['Alias'] ?? '');
            }
        }
        return $out;
    }

    private function teamMembers($s) {
        $m = $s['Members'] ?? [];
        if (!is_array($m) || !$m) return '';
        $names = [];
        foreach ($m as $mem) {
            if (is_array($mem)) {
                $names[] = (string)($mem['Alias'] ?? $mem['MundaneName'] ?? $mem['Name'] ?? '');
            } else {
                $names[] = (string)$mem;
            }
        }
        return implode(', ', array_filter($names));
    }

    private function bracketLabel($bk) {
        $name = trim((string)($bk['Name'] ?? ''));
        if ($name !== '') return $name;
        $style = trim((string)($bk['Style'] ?? ''));
        return $style !== '' ? $style : ('Bracket ' . (int)($bk['BracketId'] ?? 0));
    }

    private function methodLabel($m) {
        if (isset(self::$METHOD_LABELS[$m])) return self::$METHOD_LABELS[$m];
        return $m !== '' ? ucfirst($m) : '—';
    }

    private function fmtDate($raw) {
        $raw = (string)$raw;
        if ($raw === '' || strpos($raw, '0000-00-00') === 0) return '';
        $ts = strtotime($raw);
        return $ts ? date('F j, Y', $ts) : $raw;
    }

    private function fileName($t) {
        $base = (string)($t['Name'] ?? 'tournament');
        $safe = preg_replace('/[^a-z0-9]/i', '-', $base);
        $safe = trim(preg_replace('/-+/', '-', $safe), '-');
        if ($safe === '') $safe = 'tournament';
        return $safe . '-results.xlsx';
    }
}
```

- [ ] **Step 2: Verify the lib autoloads via the lowercase magic path**

This confirms `Ork3::$Lib->tournamentexport` resolves `class.TournamentExport.php` (the same lowercase-property mechanism `APIModel` uses), which the controller/model will rely on:

```bash
docker exec -i ork3-php8-app php -r 'chdir("/var/www/ork.amtgard.com"); require "/var/www/ork.amtgard.com/startup.php";
  $o = Ork3::$Lib->tournamentexport;
  echo get_class($o) . "\n";'
```

Expected: prints `TournamentExport`. If it errors with "class not found", the autoloader is case-sensitive — fall back to instantiating `new TournamentExport()` directly in the model (Task 6) instead of via `APIModel`, and note it.

- [ ] **Step 3: Build a workbook for a real tournament (CLI)**

```bash
docker exec -i ork3-php8-app php -r 'chdir("/var/www/ork.amtgard.com"); require "/var/www/ork.amtgard.com/startup.php";
  $r = (new TournamentExport())->BuildWorkbook(["TournamentId"=>$TID]);
  echo $r["Filename"] . "\n";
  copy($r["Path"], "/var/www/ork.amtgard.com/tnexport_test.xlsx");
  @unlink($r["Path"]);
  echo "ok\n";'
```

(Substitute the real `$TID`.) Expected: prints `<name>-results.xlsx` then `ok`.

- [ ] **Step 4: Validate structure (one tab per bracket + summary)**

```bash
unzip -l tnexport_test.xlsx | grep worksheets
```

Expected: `sheet1.xml` (Summary) plus one `sheetN.xml` per bracket in `$TID`. Confirm count = brackets + 1 (compare with the bracket count from the pre-flight query). Then open `tnexport_test.xlsx` in a spreadsheet app and confirm: first tab is "Summary & Standings" with the roster table and podium fills; each bracket tab has a title, a bold gray header row, frozen header, and podium-tinted rows.

- [ ] **Step 5: Inspect a team-bracket standings row shape (if a team bracket exists)**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
  "SELECT bracket_id FROM ork_bracket WHERE participants='team' LIMIT 1;"
# if one exists, with BID = that id:
docker exec -i ork3-php8-app php -r 'chdir("/var/www/ork.amtgard.com"); require "/var/www/ork.amtgard.com/startup.php";
  $r = Ork3::$Lib->tournament->GetStandings(["BracketId"=>BID]);
  echo json_encode($r["Detail"][0]["Members"] ?? "none") . "\n";'
```

Expected: confirms the `Members[]` element shape (each member exposing `Alias`/`MundaneName`/`Name`). `teamMembers()` already handles all three keys; no change needed unless the keys differ — if so, update `teamMembers()` accordingly.

- [ ] **Step 6: Clean up and commit**

```bash
rm -f tnexport_test.xlsx
git add system/lib/ork3/class.TournamentExport.php
git commit -m "Enhancement: TournamentExport workbook builder (summary + per-bracket)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Per-method bracket sheet formatting

**Files:**
- Modify: `system/lib/ork3/class.TournamentExport.php`

Replace the generic-only dispatch with method-specific builders.

- [ ] **Step 1: Rewrite `addBracketSheet()` to dispatch by method**

In `class.TournamentExport.php`, replace the entire `addBracketSheet()` method with:

```php
    private function addBracketSheet($xlsx, $bundle) {
        $bk     = $bundle['Bracket'];
        $method = $bundle['Method'];
        $isTeam = $bundle['IsTeam'];

        $rows = [];
        $rows[] = [['v' => $this->bracketLabel($bk), 's' => SimpleXlsx::S_TITLE]];
        $meta   = $this->methodLabel($method) . ' · ' . ($isTeam ? 'Team' : 'Individual');
        if (!empty($bk['Style'])) $meta .= ' · ' . $bk['Style'];
        $rows[] = [['v' => $meta, 's' => SimpleXlsx::S_LABEL]];
        $rows[] = [];

        if (empty($bundle['Standings']) && empty($bundle['Matches'])) {
            $rows[] = [['v' => 'No matches recorded yet.', 's' => SimpleXlsx::S_DEFAULT]];
            $xlsx->addSheet($this->bracketLabel($bk), $rows, ['colWidths' => self::$WIDTHS]);
            return;
        }

        if ($method === 'points') {
            $info = $this->buildPointsRows($rows, $bundle, $isTeam);
        } elseif ($method === 'ironman') {
            $info = $this->buildIronmanRows($rows, $bundle, $isTeam);
        } elseif ($method === 'single' || $method === 'double') {
            $info = $this->buildEliminationRows($rows, $bundle, $isTeam);
        } else { // round-robin, swiss, and any unknown method
            $info = $this->buildStandingsRows($rows, $bundle, $isTeam, $method);
            if ($method === 'round-robin') {
                $rows[] = [];
                $this->buildRoundRobinGrid($rows, $bundle);
            }
        }

        $opts = ['colWidths' => self::$WIDTHS];
        if ($info && !empty($info['headerRow'])) {
            $opts['freezeRow'] = $info['headerRow'];
            if (!empty($info['autoFilter'])) {
                $opts['autoFilterRef'] = 'A' . $info['headerRow'] . ':'
                    . SimpleXlsx::colName($info['cols']) . $info['lastRow'];
            }
        }
        $xlsx->addSheet($this->bracketLabel($bk), $rows, $opts);
    }
```

- [ ] **Step 2: Add the points / ironman / elimination / round-robin builders**

In `class.TournamentExport.php`, immediately AFTER the `buildStandingsRows()` method, add these four methods:

```php
    /** Points / Judge's Score: Place, Competitor, [Members], R1..Rn, Total. */
    private function buildPointsRows(&$rows, $bundle, $isTeam) {
        $st = $bundle['Standings'];
        $nr = 0;
        foreach ($st as $s) { $nr = max($nr, count($s['RoundScores'] ?? [])); }

        $header = ['Place', 'Competitor'];
        if ($isTeam) $header[] = 'Members';
        for ($i = 1; $i <= $nr; $i++) $header[] = 'R' . $i;
        $header[] = 'Total';

        $headerRow = count($rows) + 1;
        $rows[] = $this->styleHeader($header);
        foreach ($st as $s) {
            $place = $s['Place'] ?? null;
            $row = [($place === null) ? '' : (int)$place, (string)($s['Alias'] ?? '')];
            if ($isTeam) $row[] = $this->teamMembers($s);
            $scores = $s['RoundScores'] ?? [];
            for ($i = 0; $i < $nr; $i++) {
                $v = $scores[$i] ?? null;
                $row[] = ($v === null || $v === '') ? '' : ['v' => (float)$v, 's' => SimpleXlsx::S_NUM2, 't' => 'n'];
            }
            $row[] = ['v' => (float)($s['Total'] ?? 0), 's' => SimpleXlsx::S_NUM2, 't' => 'n'];
            $rows[] = $this->tintRow($row, is_int($place) ? $place : 0);
        }
        return ['headerRow' => $headerRow, 'lastRow' => count($rows), 'cols' => count($header), 'autoFilter' => true];
    }

    /** Ironman: Rank, Competitor, Park, [Members], Wins, Max Streak, Current Streak, Points. */
    private function buildIronmanRows(&$rows, $bundle, $isTeam) {
        $header = ['Rank', 'Competitor', 'Park'];
        if ($isTeam) $header[] = 'Members';
        $header = array_merge($header, ['Wins', 'Max Streak', 'Current Streak', 'Points']);

        $headerRow = count($rows) + 1;
        $rows[] = $this->styleHeader($header);
        foreach ($bundle['Standings'] as $s) {
            $rank = (int)($s['Rank'] ?? 0);
            $row = [$rank, (string)($s['Alias'] ?? ''), (string)($s['ParkName'] ?? '')];
            if ($isTeam) $row[] = $this->teamMembers($s);
            $row[] = (int)($s['Wins'] ?? 0);
            $row[] = (int)($s['MaxStreak'] ?? ($s['ImMaxStreak'] ?? 0));
            $row[] = (int)($s['CurrentStreak'] ?? ($s['ImCurStreak'] ?? 0));
            $row[] = (int)($s['Points'] ?? 0);
            $rows[] = $this->tintRow($row, $rank);
        }
        return ['headerRow' => $headerRow, 'lastRow' => count($rows), 'cols' => count($header), 'autoFilter' => true];
    }

    /** Single/Double elimination: podium block, final standings, then bracket results. */
    private function buildEliminationRows(&$rows, $bundle, $isTeam) {
        $pods = $this->podium($bundle['Placements']);
        if ($pods) {
            $rows[] = [['v' => 'Podium', 's' => SimpleXlsx::S_LABEL]];
            $labels = [1 => '1st', 2 => '2nd', 3 => '3rd'];
            $fills  = [1 => SimpleXlsx::S_GOLD, 2 => SimpleXlsx::S_SILVER, 3 => SimpleXlsx::S_BRONZE];
            foreach ([1, 2, 3] as $p) {
                if (isset($pods[$p])) {
                    $rows[] = [['v' => $labels[$p], 's' => $fills[$p]], ['v' => $pods[$p], 's' => $fills[$p]]];
                }
            }
            $rows[] = [];
        }

        $rows[] = [['v' => 'Final Standings', 's' => SimpleXlsx::S_LABEL]];
        $header = ['Rank', 'Competitor', 'Park'];
        if ($isTeam) $header[] = 'Members';
        $header = array_merge($header, ['W', 'L', 'Byes', 'Points']);
        $headerRow = count($rows) + 1;
        $rows[] = $this->styleHeader($header);
        foreach ($bundle['Standings'] as $s) {
            $rank = (int)($s['Rank'] ?? 0);
            $row = [$rank, (string)($s['Alias'] ?? ''), (string)($s['ParkName'] ?? '')];
            if ($isTeam) $row[] = $this->teamMembers($s);
            $row[] = (int)($s['Wins'] ?? 0);
            $row[] = (int)($s['Losses'] ?? 0);
            $row[] = (int)($s['Byes'] ?? 0);
            $row[] = (int)($s['Points'] ?? 0);
            $rows[] = $this->tintRow($row, $rank);
        }

        $rows[] = [];
        $rows[] = [['v' => 'Bracket Results', 's' => SimpleXlsx::S_LABEL]];
        $rows[] = $this->styleHeader(['Round', 'Side', 'Competitor 1', 'Competitor 2', 'Winner']);
        foreach ($bundle['Matches'] as $m) {
            $p1 = (string)($m['Participant1Alias'] ?? '');
            $p2 = (string)($m['Participant2Alias'] ?? '');
            if ($p1 === '') $p1 = ((int)($m['Participant1Id'] ?? 0) > 0) ? ('#' . (int)$m['Participant1Id']) : 'BYE';
            if ($p2 === '') $p2 = ((int)($m['Participant2Id'] ?? 0) > 0) ? ('#' . (int)$m['Participant2Id']) : 'BYE';
            $rows[] = [
                (string)($m['Round'] ?? ''),
                $this->sideLabel((string)($m['BracketSide'] ?? '')),
                $p1,
                $p2,
                $this->winnerLabel($m),
            ];
        }
        // autoFilter is omitted: this sheet has multiple tables (standings + results)
        // so a single filter range would wrongly span both. Freeze the standings header only.
        return ['headerRow' => $headerRow, 'lastRow' => count($rows), 'cols' => count($header), 'autoFilter' => false];
    }

    /** Round-robin results cross-table: competitors on both axes, cell = W/L/T. */
    private function buildRoundRobinGrid(&$rows, $bundle) {
        $st = $bundle['Standings'];
        if (count($st) < 2) return;

        $ids = [];
        $labels = [];
        foreach ($st as $s) {
            $pid = (int)($s['ParticipantId'] ?? 0);
            if ($pid <= 0) continue;
            $ids[] = $pid;
            $labels[$pid] = (string)($s['Alias'] ?? '');
        }

        $cell = [];
        foreach ($bundle['Matches'] as $m) {
            $p1 = (int)($m['Participant1Id'] ?? 0);
            $p2 = (int)($m['Participant2Id'] ?? 0);
            $res = (string)($m['Result'] ?? '');
            if (!$p1 || !$p2) continue;
            if ($res === '1-wins') { $cell["$p1:$p2"] = 'W'; $cell["$p2:$p1"] = 'L'; }
            elseif (in_array($res, ['2-wins', 'forfeit', 'disqualified'], true)) { $cell["$p1:$p2"] = 'L'; $cell["$p2:$p1"] = 'W'; }
            elseif ($res === 'tie') { $cell["$p1:$p2"] = 'T'; $cell["$p2:$p1"] = 'T'; }
        }

        $rows[] = [['v' => 'Results Grid', 's' => SimpleXlsx::S_LABEL]];
        $head = [['v' => '', 's' => SimpleXlsx::S_HEADER]];
        foreach ($ids as $pid) $head[] = ['v' => $labels[$pid], 's' => SimpleXlsx::S_HEADER];
        $rows[] = $head;
        foreach ($ids as $r) {
            $row = [['v' => $labels[$r], 's' => SimpleXlsx::S_HEADER]];
            foreach ($ids as $c) {
                $row[] = ($r === $c) ? ['v' => '—', 's' => SimpleXlsx::S_DEFAULT] : ($cell["$r:$c"] ?? '');
            }
            $rows[] = $row;
        }
    }

    private function sideLabel($side) {
        switch ($side) {
            case 'winners':        return 'Winners';
            case 'losers':         return 'Losers';
            case 'grand-final':    return 'Grand Final';
            case 'tiebreaker-3rd': return '3rd Place';
            default:               return $side !== '' ? ucfirst($side) : '';
        }
    }

    private function winnerLabel($m) {
        $res = (string)($m['Result'] ?? '');
        $a1 = (string)($m['Participant1Alias'] ?? '');
        $a2 = (string)($m['Participant2Alias'] ?? '');
        if ($res === '1-wins') return $a1;
        if (in_array($res, ['2-wins', 'forfeit', 'disqualified'], true)) return $a2;
        if ($res === 'tie') return 'Tie';
        return '';
    }
```

- [ ] **Step 3: Rebuild and validate per-method formatting (CLI)**

```bash
docker exec -i ork3-php8-app php -r 'chdir("/var/www/ork.amtgard.com"); require "/var/www/ork.amtgard.com/startup.php";
  $r = (new TournamentExport())->BuildWorkbook(["TournamentId"=>$TID]);
  copy($r["Path"], "/var/www/ork.amtgard.com/tnexport_test.xlsx"); @unlink($r["Path"]); echo "ok\n";'
mkdir -p /tmp/xlsxchk2 && unzip -o tnexport_test.xlsx -d /tmp/xlsxchk2 >/dev/null && \
  for f in $(find /tmp/xlsxchk2 -name '*.xml'); do xmllint --noout "$f" || echo "BAD $f"; done && echo "xml-ok"
```

Expected: prints `ok` then `xml-ok` with no `BAD` lines. Open `tnexport_test.xlsx` and verify, per bracket type present in `$TID`:
- **single/double:** Podium block (gold/silver/bronze) → Final Standings → Bracket Results with Round/Side/Competitor 1/Competitor 2/Winner.
- **round-robin:** Standings (with Byes) → Results Grid cross-table with W/L/T and `—` on the diagonal.
- **swiss:** Standings without a grid.
- **ironman:** Wins / Max Streak / Current Streak columns.
- **points:** Place, Competitor, R1..Rn (2-decimal), Total (2-decimal).

If `$TID` lacks a given method, build a second workbook against a tournament/bracket that has it (use the pre-flight query to find one) and spot-check that tab.

- [ ] **Step 4: Clean up and commit**

```bash
rm -f tnexport_test.xlsx
git add system/lib/ork3/class.TournamentExport.php
git commit -m "Enhancement: per-tournament-type formatting for results export

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Model pass-through

**Files:**
- Modify: `orkui/model/model.Tournament.php`

- [ ] **Step 1: Register the export APIModel in the constructor**

In `orkui/model/model.Tournament.php`, the constructor currently reads:

```php
	function __construct() {
		parent::__construct();
		$this->Report     = new APIModel('Report');
		$this->Tournament = new APIModel('Tournament');
	}
```

Add the export service so it becomes:

```php
	function __construct() {
		parent::__construct();
		$this->Report          = new APIModel('Report');
		$this->Tournament      = new APIModel('Tournament');
		$this->TournamentExport = new APIModel('TournamentExport');
	}
```

- [ ] **Step 2: Add the pass-through method**

In the same file, add this method next to the other `get_*` pass-throughs (e.g. right after `get_point_standings`):

```php
	function export_workbook($tournament_id) {
		return $this->TournamentExport->BuildWorkbook(['TournamentId' => (int)$tournament_id]);
	}
```

> **Note:** This relies on `APIModel` resolving `Ork3::$Lib->tournamentexport` (verified in Task 2 Step 2). If that step showed the autoloader is case-sensitive and failed, replace the method body with a direct instantiation instead:
> ```php
> 	function export_workbook($tournament_id) {
> 		require_once(SYSTEM_PATH . 'lib/ork3/class.TournamentExport.php');
> 		return (new TournamentExport())->BuildWorkbook(['TournamentId' => (int)$tournament_id]);
> 	}
> ```
> and drop the `$this->TournamentExport` constructor line.

- [ ] **Step 3: Verify the model path resolves (CLI)**

Because the model layer needs more bootstrap than the lib, verify via the controller route in Task 5 instead. For now just lint the file:

```bash
docker exec -i ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/model/model.Tournament.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add orkui/model/model.Tournament.php
git commit -m "Enhancement: model pass-through for tournament export

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Controller download endpoint

**Files:**
- Modify: `orkui/controller/controller.Tournament.php`

- [ ] **Step 1: Add the `export()` method**

In `orkui/controller/controller.Tournament.php`, add this method after `profile()` (i.e. just before the final closing `}` of the class, around line 299–301):

```php
	public function export($tournament_id) {
		$tournament_id = (int)preg_replace('/[^0-9]/', '', $tournament_id ?? '');
		if (!valid_id($tournament_id)) {
			http_response_code(404);
			echo 'Tournament not found.';
			exit;
		}

		$res   = $this->Tournament->export_workbook($tournament_id);
		$path  = $res['Path'] ?? null;
		$fname = $res['Filename'] ?? 'tournament-results.xlsx';

		if (!$path || !file_exists($path)) {
			http_response_code(500);
			echo 'Unable to generate export.';
			exit;
		}

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="' . $fname . '"');
		header('Content-Length: ' . filesize($path));
		header('Cache-Control: no-cache, must-revalidate');
		readfile($path);
		@unlink($path);
		exit;
	}
```

- [ ] **Step 2: Lint the controller**

```bash
docker exec -i ork3-php8-app php -l /var/www/ork.amtgard.com/orkui/controller/controller.Tournament.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Hit the route end-to-end (curl)**

```bash
curl -s -D - "http://localhost:19080/orkui/index.php?Route=Tournament/export/$TID" -o /tmp/export_route.xlsx | \
  grep -iE 'HTTP/|Content-Type|Content-Disposition'
```

Expected headers: `200`, `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, and `Content-Disposition: attachment; filename="<name>-results.xlsx"`.

Then confirm the downloaded bytes are a valid workbook:

```bash
unzip -l /tmp/export_route.xlsx | grep worksheets && echo "route-ok"
```

Expected: the worksheet list (summary + per-bracket) and `route-ok`. (Note: the route uses `index.php?Route=...`, not a clean URL — nginx 404s clean URLs in local dev.)

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.Tournament.php
git commit -m "Enhancement: Tournament/export download endpoint

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Standings page "Export Results" button

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl`

Reminder: `.tpl` files are plain PHP (`<?php ?>`/`<?= ?>`), not Smarty. The standings tab is around lines 2703–2757.

- [ ] **Step 1: Add the button in the standings head (next to "Configure Points")**

In `Tournametnew_index.tpl`, find the standings head block:

```php
					<div class="tn-standings-head">
						<h2 class="tn-standings-title">Tournament Standings</h2>
						<?php if ($canEdit): ?>
						<button type="button" class="tn-btn tn-btn-ghost tn-btn-sm" id="tn-standings-config-btn" onclick="tnOpenStandingsConfig()">
```

Immediately AFTER the closing `<?php endif; ?>` of the Configure Points button (the line right before `</div>` that closes `.tn-standings-head`), add the export link so the block ends like this:

```php
						<?php endif; ?>
						<a class="tn-btn tn-btn-sm tn-btn-ghost" href="<?= UIR ?>Tournament/export/<?= (int)$tournament['TournamentId'] ?>" data-tip="Download an .xlsx workbook of every bracket">
							<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
							Export Results
						</a>
					</div>
```

(The `data-tip` in-product tooltip pattern is used instead of a native `title` per project convention.)

- [ ] **Step 2: Add the button to the empty-standings state too**

So the export is reachable even before standings exist, find the empty branch:

```php
				<?php if (empty($standingsData)): ?>
					<div class="tn-empty">No standings yet.</div>
				<?php else: ?>
```

Replace it with:

```php
				<?php if (empty($standingsData)): ?>
					<div class="tn-empty">
						No standings yet.
						<div style="margin-top:10px;">
							<a class="tn-btn tn-btn-sm tn-btn-ghost" href="<?= UIR ?>Tournament/export/<?= (int)$tournament['TournamentId'] ?>" data-tip="Download an .xlsx workbook of every bracket">
								<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
								Export Results
							</a>
						</div>
					</div>
				<?php else: ?>
```

- [ ] **Step 3: Verify in the browser (per Chrome-usage rule: verify after implementation)**

Open `http://localhost:19080/orkui/index.php?Route=Tournament/profile/$TID`, click the **Standings** tab, and confirm the "Export Results" button renders next to "Configure Points". Click it and confirm an `.xlsx` downloads and opens with the Summary tab + one tab per bracket. Toggle dark mode and confirm the button styling (ghost button text + icon) is legible — `.tn-btn.tn-btn-ghost` is an existing dark-mode-safe class; no new CSS is added.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: Export Results button on Standings page

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: End-to-end verification sweep

**Files:** none (verification only)

- [ ] **Step 1: Verify every method type renders correctly**

Using the pre-flight query, identify tournaments covering each method (`single`, `double`, `round-robin`, `swiss`, `ironman`, `points`) and at least one team bracket. For each, run:

```bash
curl -s "http://localhost:19080/orkui/index.php?Route=Tournament/export/<TID>" -o /tmp/e2e.xlsx
unzip -l /tmp/e2e.xlsx | grep -c worksheets   # sanity: sheets present
```

Open each and confirm the per-type layout matches Task 3 Step 3. Record any discrepancy and fix in the relevant builder before declaring done.

- [ ] **Step 2: Edge cases**

- Tournament with **no brackets**: `curl .../Tournament/export/<TID_no_brackets>` → workbook opens with only the "Summary & Standings" tab (zero roster rows, totals all 0).
- Bracket with **no matches**: confirm its tab shows the title + "No matches recorded yet."
- **Invalid id**: `curl -s -D - ".../Tournament/export/0" -o /dev/null | head -1` → `404`.

- [ ] **Step 3: Confirm no stray temp files leak**

```bash
docker exec ork3-php8-app sh -c 'ls -1 $(php -r "echo sys_get_temp_dir();")/tnxlsx* 2>/dev/null | wc -l'
```

Expected: `0` (the controller `@unlink`s after streaming; the CLI checks `@unlink` their own temp file).

- [ ] **Step 4: Confirm the protected file was never staged**

```bash
git log --oneline -7 && git diff --cached --name-only
```

Expected: the seven feature commits, and `class.Authorization.php` is NOT among any of them (it carries the login-bypass hack — never stage it). Staging was always explicit per-file above, so it should be clean.

---

## Self-Review (completed during planning)

**1. Spec coverage:**
- Export button on Standings page → Task 6. ✓
- `.xlsx` workbook, one tab per bracket → Tasks 1–3, 5. ✓
- Summary & standings first tab → Task 2 (`addSummarySheet`). ✓
- Formatting sensible per tournament type → Task 3 (points/ironman/elimination/round-robin/swiss builders). ✓
- Vendored single self-contained writer, no Composer → Task 1 (`SimpleXlsx`, refinement noted: purpose-built rather than mk-j, same shape). ✓
- Public access, download via headers (ICS pattern) → Task 5. ✓
- Edge cases (no brackets / no matches / invalid id) → Task 2, Task 3, Task 7. ✓

**2. Placeholder scan:** No "TBD/TODO/handle edge cases" placeholders; every code step is complete. The only conditional is the autoload-case fallback in Task 4, which is fully specified both ways. ✓

**3. Type consistency:** Style constants (`S_*`), the `['headerRow','lastRow','cols','autoFilter']` info shape returned by every `build*Rows`, `SimpleXlsx::colName`, `addSheet($name,$rows,$opts)` with `colWidths/freezeRow/autoFilterRef`, and the `BuildWorkbook` return shape `['Status','Path','Filename']` are consistent across the builder, model, and controller. ✓
