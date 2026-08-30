<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * Nothing may reference a retired officer write surface, AND every action the
 * frontend still posts to OfficerAdminAjax must resolve to both a permission
 * map entry and a dispatch case.
 *
 * Plan 1 shipped a commit deleting an action the console's only crown Vacate
 * button still called; every test stayed green. testEveryPostedActionHasMapEntry()
 * / testEveryPostedActionHasDispatchCase() below are the checks that would have
 * caught it -- the retired-token blocklist above them cannot: it can only ever
 * catch a token someone remembered to list, never "a future commit deletes a
 * still-called action and forgets to update this file too."
 */
final class OfficerSurfaceRemovalTest extends TestCase
{
    /** Directories under gate. Widened beyond orkui/ -- system/lib/ork3/ (the
     *  domain layer, per this project's layering rules) previously had zero
     *  coverage from this test. */
    private const SCAN_DIRS = ['orkui', 'system/lib/ork3'];

    /** @var array<string, list<string>>|null relative path => stripped lines */
    private static ?array $strippedFiles = null;

    /** @return list<array{0:string}> */
    public static function retiredTokenProvider(): array
    {
        return array_map(static fn ($t) => [$t], [
            // --- original 7 ---
            'kn-editoff', 'pk-editoff',
            'setkingdomofficers', 'setparkofficers',
            'addofficerhistory', 'editofficerhistory', 'deleteofficerhistory',

            // --- Admin controller / model surfaces ---
            'setofficers', 'vacateofficer',
            'vacatekingdomofficer', 'vacateparkofficer',
            'setoccupant', 'Admin_setofficers',

            // --- Edit Officers pad open/close (kn+pk both caught per token) ---
            'EditOfficersModal',

            // --- Officer History Add/Edit modals (kn+pk, open/save/close all
            //     caught per token -- e.g. knOpenOhBackfillModal, knSaveOhBackfill,
            //     knCloseOhBackfillModal, and the pk-/kn- mirrors) ---
            'OhBackfill', 'oh-backfill-overlay',
            'OhEdit', 'oh-edit-overlay',
            'DeleteOhRecord', 'RenderOhTable', 'LoadOfficerHistory',
            'knFormatDate(', 'pkFormatDate(',

            // --- shared partial's retired write controls ---
            'of-oh-add-btn', 'of-oh-edit-btn', 'of-oh-del-btn',
            'ofCanEditHistory', 'hostCall', 'actionBtn',

            // --- mo-occ- assign modal (moOpenOcc had zero callers even before
            //     removal; kept as a regression guard). NOTE: "mo-occ-" is NOT
            //     used as a blanket prefix -- .mo-occ-name is a LIVE, unrelated
            //     class (current-occupant display), so each retired id/class is
            //     listed individually to avoid a false positive on that survivor.
            'moOpenOcc', 'moCloseOcc', 'moSaveOcc', 'moAcClose', 'moStartFp', 'moEndFp',
            'mo-occ-overlay', 'mo-occ-form', 'mo-occ-heading', 'mo-occ-title',
            'mo-occ-note', 'mo-occ-error', 'mo-occ-start', 'mo-occ-end',
            'mo-occ-player-id', 'mo-occ-player-text', 'mo-occ-player-results',
            'mo-occ-pos-id', 'mo-occ-save-btn',
        ]);
    }

    /**
     * Mask every quoted/backtick string literal with same-length 'x' filler so
     * a comment-start sequence living INSIDE a string (a URL's "//", a CSS
     * content: value, etc.) is never mistaken for a real comment. Length is
     * preserved so callers can still use byte offsets from the masked text
     * against the original text.
     */
    private static function maskStrings(string $s): string
    {
        return preg_replace_callback(
            '/"(?:\\\\.|[^"\\\\\n])*"|\'(?:\\\\.|[^\'\\\\\n])*\'|`(?:\\\\.|[^`\\\\\n])*`/s',
            static fn ($m) => str_repeat('x', strlen($m[0])),
            $s
        ) ?? $s;
    }

    /**
     * Language-aware comment stripping. Replaces the old per-line "starts
     * with // * /* #" heuristic, which had three proven holes:
     *   - CSS id/universal selectors (`#foo{}`, `*{}`) look identical to a
     *     PHP-style comment-start and were silently skipped as "comments" --
     *     the exact form of the 13 retired kn-editoff-overlay-family
     *     selectors, invisible to the old walker in a .css file.
     *   - A block comment that shares its line with real code afterwards
     *     (`/* note *\/ .kn-editoff-x{}`) caused the WHOLE line, code
     *     included, to be treated as a comment.
     *   - CSS has no `//` or `#` line-comment syntax at all; JS has no `#`.
     *     Both were being stripped from every file regardless of extension.
     *
     * Block comments (`/* ... *\/`) exist in all four extensions here and are
     * found against the STRING-MASKED text (so a "/*" inside a string is
     * never mistaken for one), then blanked in the real content with spaces
     * -- never deleted -- so line numbers never shift and any code sharing
     * the line survives for scanning. Line comments differ per language:
     * CSS has none, JS has only `//`, PHP/TPL have `//` and `#`.
     */
    private static function stripComments(string $content, string $ext): string
    {
        $masked = self::maskStrings($content);

        if (preg_match_all('/\/\*.*?\*\//s', $masked, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $match) {
                [$text, $offset] = $match;
                $blank   = preg_replace('/[^\n]/', ' ', $text);
                $content = substr_replace($content, $blank, $offset, strlen($text));
                $masked  = substr_replace($masked, $blank, $offset, strlen($text));
            }
        }

        $starters = $ext === 'css' ? [] : ($ext === 'js' ? ['//'] : ['//', '#']);
        if ($starters !== []) {
            $contentLines = explode("\n", $content);
            $maskedLines  = explode("\n", $masked);
            foreach ($contentLines as $i => $line) {
                $ml  = $maskedLines[$i] ?? '';
                $cut = null;
                foreach ($starters as $starter) {
                    $pos = strpos($ml, $starter);
                    if ($pos !== false && ($cut === null || $pos < $cut)) {
                        $cut = $pos;
                    }
                }
                if ($cut !== null) {
                    $contentLines[$i] = substr($line, 0, $cut);
                }
            }
            $content = implode("\n", $contentLines);
        }

        return $content;
    }

    /** @return array<string, list<string>> relative path => comment-stripped lines */
    private static function scannedFiles(): array
    {
        if (self::$strippedFiles !== null) {
            return self::$strippedFiles;
        }

        $root = dirname(__DIR__, 2);
        $out  = [];
        foreach (self::SCAN_DIRS as $dir) {
            $full = $root . '/' . $dir;
            if (!is_dir($full)) {
                continue;
            }
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $full,
                FilesystemIterator::SKIP_DOTS
            ));
            foreach ($rii as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                if (!preg_match('/\.(php|tpl|js|css)$/', $file->getFilename(), $extM)) {
                    continue;
                }
                $ext      = strtolower($extM[1]);
                $content  = file_get_contents($file->getPathname());
                $stripped = self::stripComments($content, $ext);
                $rel      = str_replace($root . '/', '', $file->getPathname());
                $out[$rel] = explode("\n", $stripped);
            }
        }

        return self::$strippedFiles = $out;
    }

    /** @dataProvider retiredTokenProvider */
    public function testNoLiveReferenceSurvives(string $token): void
    {
        $hits = [];
        foreach (self::scannedFiles() as $rel => $lines) {
            foreach ($lines as $i => $line) {
                if ($line !== '' && str_contains($line, $token)) {
                    $hits[] = $rel . ':' . ($i + 1);
                }
            }
        }
        self::assertSame([], $hits, "retired surface '{$token}' still referenced in executable code");
    }

    public function testSetOccupantActionIsGone(): void
    {
        $src = self::officerAdminAjaxSource();
        self::assertStringNotContainsString(
            "case 'setoccupant':",
            $src,
            'the wizard subsumes it; the domain method SetOccupant stays as an API verb'
        );
    }

    // ========================================================================
    // Action-coverage invariant: every action literal the templates post to
    // OfficerAdminAjax must resolve to BOTH a permission-map entry and a
    // dispatch case, and the map/dispatch case sets must match each other
    // exactly (a map entry may have no template caller -- e.g. vacate /
    // vacateholder, whose only caller is a console not covered here today --
    // but a CALLER may never lack a map entry or a dispatch case).
    // ========================================================================

    private const ACTION_TEMPLATES = [
        'orkui/template/revised-frontend/partials/_manage_officers.tpl',
        'orkui/template/revised-frontend/partials/_correct_the_rolls.tpl',
        'orkui/template/revised-frontend/partials/_officer_transition.tpl',
        'orkui/template/revised-frontend/Kingdomnew_index.tpl',
        'orkui/template/revised-frontend/Parknew_index.tpl',
    ];

    private static function officerAdminAjaxSource(): string
    {
        return file_get_contents(
            dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php'
        );
    }

    /** @return list<string> keys of the $action_kind permission map */
    private static function actionKindMapKeys(): array
    {
        $src = self::officerAdminAjaxSource();
        if (!preg_match('/\$action_kind\s*=\s*\[(.*?)\n\s*\];/s', $src, $m)) {
            self::fail('Could not locate $action_kind map in controller.OfficerAdminAjax.php -- has it moved/been renamed?');
        }
        preg_match_all("/'([a-zA-Z]+)'\s*=>/", $m[1], $km);
        return $km[1];
    }

    /** @return list<string> action names with a `case 'x':` in officer()'s switch */
    private static function dispatchCaseActions(): array
    {
        $src = self::officerAdminAjaxSource();
        preg_match_all("/case\s+'([a-zA-Z]+)':/", $src, $cm);
        return $cm[1];
    }

    /**
     * @return list<string> every action literal the five templates post/get
     *                       to OfficerAdminAjax, deduped.
     */
    private static function templatePostedActions(): array
    {
        $root    = dirname(__DIR__, 2);
        $actions = [];

        foreach (self::ACTION_TEMPLATES as $rel) {
            $path = $root . '/' . $rel;
            self::assertFileExists($path, "action-coverage template moved/renamed: {$rel}");
            $stripped = self::stripComments(file_get_contents($path), 'tpl');

            // moPost('x', ...) / window.moPost('x', ...)
            if (preg_match_all("/\\bmoPost\\(\\s*'([a-zA-Z]+)'/", $stripped, $m)) {
                foreach ($m[1] as $a) {
                    $actions[$a] = true;
                }
            }

            // $.getJSON(base() + 'x' ...)  -- read-only actions (list/roles/permissions)
            if (preg_match_all("/\\\$\\.getJSON\\(\\s*base\\(\\)\\s*\\+\\s*'([a-zA-Z]+)'/", $stripped, $m)) {
                foreach ($m[1] as $a) {
                    $actions[$a] = true;
                }
            }

            // The one ternary-resolved form: var action = cond ? 'editposition' : 'createposition';
            // then $.post(base() + action, ...). Both literal branches count as posted.
            if (preg_match_all(
                "/var\\s+action\\s*=\\s*[^;]*?\\?\\s*'([a-zA-Z]+)'\\s*:\\s*'([a-zA-Z]+)'/",
                $stripped,
                $m,
                PREG_SET_ORDER
            )) {
                foreach ($m as $pair) {
                    $actions[$pair[1]] = true;
                    $actions[$pair[2]] = true;
                }
            }
        }

        return array_keys($actions);
    }

    public function testEveryPostedActionHasMapEntry(): void
    {
        $mapKeys = self::actionKindMapKeys();
        $missing = array_values(array_diff(self::templatePostedActions(), $mapKeys));
        self::assertSame(
            [],
            $missing,
            'action(s) posted by a template have NO $action_kind map entry in '
            . 'controller.OfficerAdminAjax.php: ' . implode(', ', $missing)
        );
    }

    public function testEveryPostedActionHasDispatchCase(): void
    {
        $cases   = self::dispatchCaseActions();
        $missing = array_values(array_diff(self::templatePostedActions(), $cases));
        self::assertSame(
            [],
            $missing,
            'action(s) posted by a template have NO dispatch case in '
            . "controller.OfficerAdminAjax.php's officer(): " . implode(', ', $missing)
        );
    }

    /**
     * The park nameplate's Admin control must open the standalone Park admin
     * console (Admin/park/{id}) -- the same move the kingdom nameplate already
     * made -- not the pk-admin-overlay edit pad it used to open. That modal is
     * the park's version of the edit pad this work replaced.
     *
     * The visibility gate matters as much as the destination: it must be
     * $CanManagePark (park.details.edit @AUTH_EDIT), which is exactly the first
     * disjunct of Admin::park()'s front door, NOT $CanAdminPark
     * (park.officer.set @AUTH_CREATE). Gating on the latter would show the link
     * to an officer the destination bounces silently to the home page.
     */
    public function testParkNameplateAdminOpensTheStandaloneConsole(): void
    {
        $tpl = file_get_contents(dirname(__DIR__, 2) . '/orkui/template/revised-frontend/Parknew_index.tpl');

        self::assertMatchesRegularExpression(
            '/if\s*\(!empty\(\$CanManagePark\)\)\s*:\s*\?>\s*<a\b[^>]*href="[^"]*Admin\/park\//s',
            $tpl,
            'the park nameplate Admin control must be a link to Admin/park/{id}, gated on $CanManagePark'
        );

        self::assertStringNotContainsString(
            'pkOpenAdminModal()',
            $tpl,
            'the park nameplate must not reopen the pk-admin-overlay edit pad -- '
            . 'admin lives on the standalone console now'
        );
    }

    /**
     * Guards the gate choice specifically. $CanManagePark and $CanAdminPark are
     * DIFFERENT permissions in controller.Park.php, and swapping them here is a
     * silent dead-control regression rather than a visible failure.
     */
    public function testParkAdminLinkIsNotGatedOnTheOfficerSetPermission(): void
    {
        $tpl = file_get_contents(dirname(__DIR__, 2) . '/orkui/template/revised-frontend/Parknew_index.tpl');

        self::assertDoesNotMatchRegularExpression(
            '/if\s*\(!empty\(\$CanAdminPark\)\)\s*:\s*\?>\s*<a\b[^>]*href="[^"]*Admin\/park\//s',
            $tpl,
            '$CanAdminPark is park.officer.set, but Admin::park() gates on park.details.edit -- '
            . 'gate the console link on $CanManagePark or it becomes a dead control'
        );
    }

    public function testActionMapAndDispatchCasesMatchExactly(): void
    {
        $mapKeys = self::actionKindMapKeys();
        $cases   = self::dispatchCaseActions();
        sort($mapKeys);
        sort($cases);

        $mapOnly  = array_values(array_diff($mapKeys, $cases));
        $caseOnly = array_values(array_diff($cases, $mapKeys));

        self::assertSame([], $mapOnly, '$action_kind entries with NO dispatch case: ' . implode(', ', $mapOnly));
        self::assertSame([], $caseOnly, "dispatch case(s) with NO \$action_kind entry: " . implode(', ', $caseOnly));
    }
}
