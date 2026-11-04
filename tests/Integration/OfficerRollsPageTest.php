<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Officer History page (Reports::officer_history): a public report with an
 * office-ledger edit mode.
 *
 * Pinned here:
 *   - the read-only report carries no edit controls and no editor script;
 *   - the Edit control is computed with the same key, scope and legacy role as
 *     the server gate (OfficerPosition::PermissionKeyFor('history'), AUTH_EDIT);
 *   - edit mode gives every office that can take a term an entry row, including
 *     offices with no terms yet (folded into "Other offices");
 *   - a current term (and a seated officer with no history row) can't be deleted;
 *   - both profile Reports tabs link the page, and the ajax actions behind it sit
 *     in the history permission family.
 */
final class OfficerRollsPageTest extends TestCase
{
    private const TEMPLATE = '/orkui/template/revised-frontend/Reports_officer_history.tpl';

    private static function term(array $over): array
    {
        return $over + [
            'OfficerHistoryId' => 0, 'MundaneId' => 0, 'Persona' => '', 'HolderName' => '',
            'IsCurrent' => false, 'Synthetic' => false,
            'StartSeason' => null, 'StartYear' => null, 'EndSeason' => null, 'EndYear' => null,
            'StartDate' => null, 'EndDate' => null, 'PeriodLabel' => '? – ?', 'Note' => '',
            'StartOrdinal' => null, 'EndOrdinal' => null, 'FlagAfter' => null,
        ];
    }

    private static function office(string $key, int $positionId, string $title, array $terms, bool $retired = false): array
    {
        $gaps = 0;
        $overlaps = 0;
        foreach ($terms as $t) {
            if (($t['FlagAfter']['Type'] ?? '') === 'gap') {
                $gaps++;
            } elseif (($t['FlagAfter']['Type'] ?? '') === 'overlap') {
                $overlaps++;
            }
        }
        return [
            'Key' => $key, 'PositionId' => $positionId, 'Title' => $title, 'Classification' => 'crown',
            'Retired' => $retired, 'TermCount' => count($terms), 'GapCount' => $gaps, 'OverlapCount' => $overlaps,
            'Terms' => $terms,
        ];
    }

    /** Four offices: a full one, a seated-but-unrecorded one, an empty one, a legacy role-only one. */
    private static function rolls(): array
    {
        return ['Offices' => [
            self::office('position:11', 11, 'Monarch', [
                self::term(['OfficerHistoryId' => 501, 'MundaneId' => 9, 'Persona' => 'Queen Ada', 'IsCurrent' => true,
                    'StartSeason' => 'spring', 'StartYear' => 2026, 'PeriodLabel' => 'Spring 2026 – present']),
                self::term(['OfficerHistoryId' => 502, 'HolderName' => 'Old <King>', 'StartSeason' => 'spring', 'StartYear' => 2023,
                    'EndSeason' => 'fall', 'EndYear' => 2023, 'PeriodLabel' => 'Spring 2023 – Fall 2023',
                    'FlagAfter' => ['Type' => 'gap', 'Label' => 'Gap: Spring 2022 – Winter 2023']]),
                self::term(['OfficerHistoryId' => 503, 'MundaneId' => 10, 'Persona' => 'Sir Bors', 'StartSeason' => 'fall',
                    'StartYear' => 2021, 'EndSeason' => 'winter', 'EndYear' => 2022, 'PeriodLabel' => 'Fall 2021 – Winter 2022']),
            ]),
            self::office('position:12', 12, 'Regent', [
                self::term(['MundaneId' => 12, 'Persona' => 'Lady Seated', 'IsCurrent' => true, 'Synthetic' => true,
                    'PeriodLabel' => '? – present']),
            ]),
            self::office('position:13', 13, 'Herald', []),
            self::office('role:castellan', 0, 'Castellan', [
                self::term(['OfficerHistoryId' => 504, 'HolderName' => 'Ancient', 'PeriodLabel' => 'Spring 1995 – ?']),
            ], true),
        ]];
    }

    private static function render(bool $canEdit, bool $editMode): string
    {
        $Rolls        = self::rolls();
        $ScopeType    = 'kingdom';
        $ScopeId      = 17;
        $ScopeName    = 'Crystal Groves';
        $KingdomId    = 17;
        $CanEditRolls = $canEdit;
        $EditMode     = $editMode;
        ob_start();
        include ORK3_ROOT . self::TEMPLATE;
        return (string) ob_get_clean();
    }

    /** The <tr> for one history row, or '' when it isn't rendered. */
    private static function row(string $html, int $historyId, string $holder = ''): string
    {
        $pattern = '/<tr\b[^>]*data-history-id="' . $historyId . '"'
            . ($holder !== '' ? '[^>]*data-holder="' . preg_quote($holder, '/') . '"' : '')
            . '[^>]*>.*?<\/tr>/s';
        return preg_match($pattern, $html, $m) ? $m[0] : '';
    }

    public function testViewModeHasNoEditControls(): void
    {
        $html = self::render(false, false);
        self::assertStringContainsString('Officer History', $html);
        self::assertStringContainsString('Queen Ada', $html);
        self::assertStringContainsString('Old &lt;King&gt;', $html, 'plain-text holders are escaped');
        self::assertStringNotContainsString('Old <King>', $html);
        self::assertStringContainsString('or-present', $html, 'the current term carries a present pill');
        self::assertStringContainsString('Unrecorded', $html, 'gaps show as quiet unrecorded rows');
        self::assertStringNotContainsString('data-or-entry', $html);
        self::assertStringNotContainsString('data-or-act=', $html);
        self::assertStringNotContainsString('officer-rolls.js', $html);
        self::assertStringNotContainsString('Edit the rolls', $html, 'no edit control without the history permission');

        // An editor viewing the report sees the Edit control but still no ledger controls.
        $editorView = self::render(true, false);
        self::assertStringContainsString('Edit the rolls', $editorView);
        self::assertStringContainsString('Reports/officer_history&amp;KingdomId=17&amp;edit=1', $editorView);
        self::assertStringNotContainsString('data-or-entry', $editorView);
        self::assertStringNotContainsString('officer-rolls.js', $editorView);
    }

    public function testEditButtonFollowsTheHistoryGate(): void
    {
        $src = (string) file_get_contents(ORK3_ROOT . '/orkui/controller/controller.Reports.php');
        self::assertMatchesRegularExpression('/public function officer_history\(/', $src);
        $start = strpos($src, 'public function officer_history(');
        $next = strpos($src, "\n    public function ", $start + 10);
        $nextPrivate = strpos($src, "\n    private function ", $start + 10);
        $end = min(array_filter([$next, $nextPrivate, strlen($src)]));
        $body = substr($src, $start, $end - $start);

        self::assertMatchesRegularExpression(
            "/'CanEditRolls'\\]\\s*=.*has_permission_or_authority\\(\\s*\\\$uid,\\s*\\\$this->OfficerPosition->permission_key_for\\('history',\\s*\\\$park_id\\),\\s*\\\$scope,\\s*\\\$scope_id,\\s*AUTH_EDIT\\s*\\)/s",
            $body,
            'CanEditRolls must use the server gate: history key, row scope, AUTH_EDIT'
        );
        self::assertStringContainsString("'EditMode'", $body);
        self::assertMatchesRegularExpression('/EditMode.*CanEditRolls|canEdit.*edit/s', $body);
        self::assertStringContainsString("'officer_history'", substr($src, 0, (int) strpos($src, 'public function index(')), 'the report is public');
    }

    public function testEditModeRendersAnEntryRowPerOffice(): void
    {
        $html = self::render(true, true);
        // Every office with a registry position gets one, including the empty Herald.
        self::assertSame(3, substr_count($html, 'data-or-entry'));
        self::assertMatchesRegularExpression('/data-or-entry[^>]*data-position-id="13"|data-position-id="13"[^>]*data-or-entry/', $html);
        // The empty office is folded into "Other offices (1)".
        self::assertStringContainsString('Other offices (1)', $html);
        // Global player search, kn-ac dropdown.
        self::assertStringContainsString('kn-ac-results', $html);
        self::assertStringContainsString('officer-rolls.js', $html);
        self::assertStringContainsString('Done', $html);
        // The first office is open, the rest are closed.
        self::assertMatchesRegularExpression('/<details class="or-office"[^>]*data-key="position:11"[^>]*open/', $html);
        self::assertDoesNotMatchRegularExpression('/<details class="or-office"[^>]*data-key="position:12"[^>]*open/', $html);
        // Plain-text rows offer "Link to player"; linked rows don't.
        self::assertStringContainsString('data-or-act="link"', self::row($html, 502));
        self::assertStringNotContainsString('data-or-act="link"', self::row($html, 503));
    }

    public function testCurrentTermRowIsNotDeletable(): void
    {
        $html = self::render(true, true);

        $current = self::row($html, 501);
        self::assertNotSame('', $current);
        self::assertStringNotContainsString('data-or-act="delete"', $current);
        self::assertStringContainsString('data-or-act="edit"', $current, 'start and note stay editable');
        self::assertStringContainsString('Set from Manage Officers', $current);

        $past = self::row($html, 502);
        self::assertStringContainsString('data-or-act="delete"', $past);
        self::assertStringContainsString('data-or-act="edit"', $past);

        // A seated officer with no history row: shown, but no actions at all.
        $synthetic = self::row($html, 0);
        self::assertStringContainsString('Lady Seated', $synthetic);
        self::assertStringNotContainsString('data-or-act=', $synthetic);
    }

    public function testReportsTabsLinkTheNewPage(): void
    {
        $kingdom = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/Kingdomnew_index.tpl');
        $park = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/Parknew_index.tpl');
        self::assertStringContainsString('Reports/officer_history&KingdomId=', $kingdom);
        self::assertStringContainsString('Reports/officer_history&KingdomId=', $park);
        self::assertMatchesRegularExpression('/Reports\/officer_history&KingdomId=<\?= \$kingdom_id \?>&ParkId=<\?= \$park_id \?>/', $park);
    }

    public function testOfficerAdminAjaxRoutesTheNewActionsUnderHistory(): void
    {
        $src = (string) file_get_contents(ORK3_ROOT . '/orkui/controller/controller.OfficerAdminAjax.php');
        self::assertMatchesRegularExpression("/'rolls'\\s*=>\\s*'history'/", $src);
        self::assertMatchesRegularExpression("/'parseperiod'\\s*=>\\s*'history'/", $src);
        self::assertMatchesRegularExpression("/case 'rolls':/", $src);
        self::assertMatchesRegularExpression("/case 'parseperiod':/", $src);
    }
}
