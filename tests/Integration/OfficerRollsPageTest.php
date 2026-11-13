<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Officer History page (Reports::officer_history): a public report with an
 * office-ledger edit mode.
 *
 * Pinned here:
 *   - the read-only report carries no edit controls and no editor script;
 *   - the Edit control reads the domain's history-write gate
 *     (OfficerPosition::can_edit_history_for), the one the writes enforce;
 *   - edit mode gives every office that can take a term an entry row, including
 *     offices with no terms yet (folded into "Other offices");
 *   - a current term (and a seated officer with no history row) can't be deleted;
 *   - both profile Reports tabs link the page, and the ajax actions behind it sit
 *     in the history permission family.
 */
final class OfficerRollsPageTest extends TestCase
{
    private const TEMPLATE = '/orkui/template/revised-frontend/Reports_officer_history.tpl';

    /**
     * A rolls Term as OfficerPosition::rollsTerm() emits it: the span fields are
     * given, and the labels, edit text and ordinals come from the domain.
     */
    private static function term(array $over): array
    {
        $t = $over + [
            'OfficerHistoryId' => 0, 'MundaneId' => 0, 'Persona' => '', 'HolderName' => '',
            'IsCurrent' => false, 'Synthetic' => false,
            'StartSeason' => null, 'StartYear' => null, 'EndSeason' => null, 'EndYear' => null,
            'StartDate' => null, 'EndDate' => null, 'Note' => '', 'FlagAfter' => null,
        ];
        $span = array_intersect_key($t, array_flip(['StartSeason', 'StartYear', 'StartDate', 'EndSeason', 'EndYear', 'EndDate']));
        $ord = OfficerTermPeriod::resolve($span);
        $span += ['StartOrdinal' => $ord['start'], 'EndOrdinal' => $ord['end']];
        return $t + [
            'PeriodLabel'     => OfficerTermPeriod::label($span, $t['IsCurrent']),
            'PeriodLabelBase' => OfficerTermPeriod::labelBase($span, $t['IsCurrent']),
            'PeriodText'      => OfficerTermPeriod::editText($span, $t['IsCurrent']),
            'StartLabel'      => OfficerTermPeriod::sideLabel($span, 'Start'),
            'EndLabel'        => OfficerTermPeriod::sideLabel($span, 'End'),
            'StartOrdinal'    => $ord['start'],
            'EndOrdinal'      => $ord['end'],
        ];
    }

    /** A gap flag as OfficerPosition::flag_rolls_terms() emits it. */
    private static function gap(int $from, int $to): array
    {
        $span = OfficerTermPeriod::spanLabel($from, $to);
        return ['Type' => 'gap', 'Label' => 'Gap: ' . $span, 'Span' => $span];
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
                    'StartSeason' => 'spring', 'StartYear' => 2026]),
                self::term(['OfficerHistoryId' => 502, 'HolderName' => 'Old <King>', 'StartSeason' => 'spring', 'StartYear' => 2023,
                    'EndSeason' => 'fall', 'EndYear' => 2023,
                    'FlagAfter' => self::gap(2022 * 4 + 1, 2023 * 4)]),
                self::term(['OfficerHistoryId' => 503, 'MundaneId' => 10, 'Persona' => 'Sir Bors', 'StartSeason' => 'fall',
                    'StartYear' => 2021, 'EndSeason' => 'winter', 'EndYear' => 2022]),
                // A date-only term: the label renders the exact date.
                self::term(['OfficerHistoryId' => 505, 'HolderName' => 'A. Steele', 'StartDate' => '2020-08-09']),
                // Seasons with an exact start date also recorded: the date side shows the date.
                self::term(['OfficerHistoryId' => 506, 'HolderName' => 'B. Seasonal', 'StartSeason' => 'spring', 'StartYear' => 2019,
                    'EndSeason' => 'fall', 'EndYear' => 2019, 'StartDate' => '2019-03-30']),
                // Linked terms keep the name they were recorded under.
                self::term(['OfficerHistoryId' => 507, 'MundaneId' => 21, 'Persona' => 'Sir Robert', 'HolderName' => 'Squire Bob',
                    'StartSeason' => 'spring', 'StartYear' => 1997, 'EndSeason' => 'fall', 'EndYear' => 1997]),
                self::term(['OfficerHistoryId' => 508, 'MundaneId' => 22, 'Persona' => '', 'HolderName' => 'Dame Departed',
                    'StartSeason' => 'spring', 'StartYear' => 1996, 'EndSeason' => 'fall', 'EndYear' => 1996]),
                self::term(['OfficerHistoryId' => 509, 'MundaneId' => 23, 'Persona' => 'Same Name', 'HolderName' => 'same name',
                    'StartSeason' => 'spring', 'StartYear' => 1995, 'EndSeason' => 'fall', 'EndYear' => 1995]),
            ]),
            self::office('position:12', 12, 'Regent', [
                self::term(['MundaneId' => 12, 'Persona' => 'Lady Seated', 'IsCurrent' => true, 'Synthetic' => true]),
            ]),
            self::office('position:13', 13, 'Herald', []),
            self::office('role:castellan', 0, 'Castellan', [
                self::term(['OfficerHistoryId' => 504, 'HolderName' => 'Ancient', 'StartSeason' => 'spring', 'StartYear' => 1995]),
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
            "/'CanEditRolls'\\]\\s*=\\s*\\\$this->OfficerPosition->can_edit_history_for\\(\\s*\\\$uid,\\s*\\\$kingdom_id,\\s*\\\$park_id\\s*\\);/",
            $body,
            'CanEditRolls must read the domain gate the history writes enforce'
        );
        self::assertStringNotContainsString('has_permission_or_authority', $body, 'no second, inline copy of the gate');
        // The writes authorize through the same gate.
        $lib = (string) file_get_contents(ORK3_ROOT . '/system/lib/ork3/class.OfficerPosition.php');
        foreach (['AddHistoryTerm', 'EditHistoryTerm', 'DeleteHistoryTerm'] as $fn) {
            $at = strpos($lib, 'public function ' . $fn . '(');
            self::assertNotFalse($at, $fn . ' exists');
            $fnBody = substr($lib, $at, strpos($lib, "\n    }\n", $at) - $at);
            self::assertStringContainsString('$this->can_edit_history_for(', $fnBody, $fn . ' authorizes through can_edit_history_for');
        }
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
        self::assertStringContainsString('Other offices <span class="or-folded-count">(1)</span>', $html);
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

    /** A dated side renders its date in the label itself; there is no separate exact-dates line. */
    public function testDatedLabelsRenderTheDate(): void
    {
        foreach ([false, true] as $edit) {
            $html = self::render(true, $edit);
            self::assertStringContainsString($edit ? 'Aug 9, 2020 – ?' : 'Aug 9, 2020 <span class="or-dash">–</span>', $html);
            self::assertStringContainsString($edit ? 'Mar 30, 2019 – Fall 2019' : 'Mar 30, 2019 <span class="or-dash">–</span> Fall 2019', $html, 'the dated start shows its date, the seasonal end its season');
            self::assertStringNotContainsString('Exact:', $html);
            self::assertStringNotContainsString('or-exact', $html);
        }
        $js = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/script/officer-rolls.js');
        self::assertStringNotContainsString('or-exact', $js);
        self::assertStringNotContainsString('ExactLabel', $js);
    }

    /**
     * The public report words unknown ends: a current term with no recorded start
     * is "Start unknown – present", a past term with no end ends in a muted "end
     * unrecorded", a winter names both its years. The edit ledger keeps "?".
     */
    public function testViewModeWordsUnknownEnds(): void
    {
        $view = self::render(false, false);
        self::assertStringContainsString('<span class="or-period-unknown">Start unknown</span> <span class="or-dash">–</span> <span class="or-present">present</span>', $view, 'the seated officer with no history row');
        self::assertStringContainsString('Aug 9, 2020 <span class="or-dash">–</span> <span class="or-period-unknown">end unrecorded</span>', $view);
        self::assertStringContainsString('Spring 1995 <span class="or-dash">–</span> <span class="or-period-unknown">end unrecorded</span>', $view);
        self::assertStringContainsString('Fall 2021 <span class="or-dash">–</span> Winter 2021–22', $view, 'a winter is named by both its years');
        self::assertStringContainsString('Spring 2026 <span class="or-dash">–</span> <span class="or-present">present</span>', $view);
        self::assertStringNotContainsString('? –', $view);
        self::assertStringNotContainsString('– ?', $view);

        $ledger = self::render(true, true);
        self::assertStringContainsString('Aug 9, 2020 – ?', $ledger, 'the ledger keeps "?" for editors to fill in');
        self::assertStringContainsString('? <span class="or-dash">–</span> <span class="or-present">present</span>', $ledger);
        self::assertStringNotContainsString('end unrecorded', $ledger);

        $css = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/style/officer-rolls.css');
        self::assertMatchesRegularExpression('/\.or-period-unknown\s*\{[^}]*var\(--rp-text-hint\)/', $css, 'muted with the shell token, which follows dark mode');
    }

    /** Season | Calendar toggle in every entry row, left of the inputs, with the house tooltips. */
    public function testEntryRowsHaveTheSeasonCalendarToggle(): void
    {
        $html = self::render(true, true);
        self::assertSame(3, substr_count($html, '<div class="or-period-field" data-mode="season">'));
        $season = 'data-tip="Season Entry - use to indicate the general term if you do not know the specific dates."';
        $calendar = 'data-tip="Calendar Entry - use to enter in specific start and end dates when known."';
        self::assertSame(3, substr_count($html, $season));
        self::assertSame(3, substr_count($html, $calendar));
        self::assertMatchesRegularExpression('/data-or-mode="season" aria-pressed="true" tabindex="0"[^>]*><i class="fas fa-sun"/', $html);
        self::assertMatchesRegularExpression('/data-or-mode="calendar" aria-pressed="false" tabindex="-1"[^>]*><i class="fas fa-calendar-alt"/', $html);
        // Toggle first, then the inputs: Tab reaches it before the field.
        $field = substr($html, (int) strpos($html, '<div class="or-period-field"'), 2000);
        self::assertLessThan(strpos($field, 'or-period-input'), strpos($field, 'data-or-mode="season"'));
        self::assertMatchesRegularExpression('/<div class="or-pane-calendar" hidden>.*data-side="start".*data-side="end"/s', $field);
        self::assertStringNotContainsString(' title=', $field);
        // Not in the public report.
        self::assertStringNotContainsString('data-or-mode', self::render(true, false));
    }

    /** Escape closes an open date picker before it can cancel a row edit; toggle glyphs are 14px. */
    public function testDatePickerEscapeAndToggleGlyphSize(): void
    {
        $js = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/script/officer-rolls.js');
        self::assertMatchesRegularExpression("/document\\.addEventListener\\('keydown',[\\s\\S]*?_flatpickr\\.isOpen[\\s\\S]*?stopPropagation\\(\\)[\\s\\S]*?\\.close\\(\\)[\\s\\S]*?\\}, true\\);/", $js);
        // Refocus before closing (Flatpickr opens on focus), then close again next tick.
        self::assertMatchesRegularExpression('/alt\\.focus\\(\\);[^\\n]*\\n\\s*fp\\.close\\(\\);[\\s\\S]*?setTimeout\\(function \\(\\) \\{ if \\(fp\\.isOpen\\) \\{ fp\\.close\\(\\); \\} \\}, 0\\);/', $js);
        $css = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/style/officer-rolls.css');
        self::assertMatchesRegularExpression('/\\.or-mode-btn i \\{\\s*font-size\\s*:\\s*14px;/', $css);
        self::assertMatchesRegularExpression('/\\.or-mode-btn \\{[^}]*width\\s*:\\s*28px;[^}]*height\\s*:\\s*28px;/', $css);
    }

    /**
     * Calendar mode with allowInput: Flatpickr commits typed alt text on blur or in
     * its own Enter handler, which may run after the row's Enter handler, so add and
     * save commit the typed text themselves before reading the date values.
     */
    public function testTypedDatesAreCommittedBeforeSaving(): void
    {
        $js = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/script/officer-rolls.js');
        $c = strpos($js, 'function commitTypedDates(field) {');
        self::assertNotFalse($c, 'commitTypedDates exists');
        $commit = substr($js, $c, strpos($js, "\n    }\n", $c) - $c);
        self::assertStringContainsString('fp.altInput.value', $commit);
        self::assertStringContainsString('fp.setDate(typed, true, fp.config.altFormat)', $commit, 'typed text differs: commit it as Flatpickr would');
        self::assertStringContainsString('fp.clear()', $commit, 'typed text emptied: clear the committed value');
        foreach (['addTerm', 'saveEdit'] as $fn) {
            $start = strpos($js, 'function ' . $fn . '(tr)');
            self::assertNotFalse($start, $fn . ' exists');
            $body = substr($js, $start, strpos($js, "\n    }\n", $start) - $start);
            $commit = strpos($body, 'commitTypedDates(field)');
            self::assertNotFalse($commit, $fn . ' commits typed dates');
            self::assertLessThan(strpos($body, "querySelectorAll('.or-date-input')"), $commit, $fn . ' commits before reading the dates');
        }
    }

    /**
     * The public Details modal keeps the server's order (GetOfficerHistory sorts on
     * the rolls key -- see OfficerRollsReaderTest::testGetOfficerHistoryOrdersOnTheRollsKey)
     * and shows the domain's side labels rather than rebuilding them.
     */
    public function testDetailsModalSortsOnTheRollsKey(): void
    {
        $tpl = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/partials/_officer_details_modal.tpl');
        $start = strpos($tpl, 'function fillPanel(panel, indices, rows)');
        self::assertNotFalse($start);
        $body = substr($tpl, $start, strpos($tpl, "\n\t}\n", $start) - $start);
        self::assertStringNotContainsString('.sort(', $body, 'fillPanel keeps the server order');
        self::assertStringNotContainsString('function cmpTerm', $tpl);
        self::assertStringNotContainsString('function termSide', $tpl);
        self::assertStringContainsString('h.StartLabel', $tpl);
        self::assertStringContainsString('h.EndLabel', $tpl);
    }

    /** Manage Officers drops the dead bridges and points a missing open term at the Officer History page. */
    public function testManageOfficersPointsAtTheOfficerHistoryPage(): void
    {
        $tpl = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/partials/_manage_officers.tpl');
        self::assertStringNotContainsString('moGetOfficeList', $tpl);
        self::assertStringNotContainsString('moEscAttr', $tpl);
        self::assertStringContainsString('window.moSearchUrl', $tpl, 'still used by the transition wizard');
        self::assertStringNotContainsString('Use Correct the Rolls to fix it directly', $tpl);
        self::assertStringContainsString('Officer History page', $tpl);
    }

    /** An unknown kingdom or park (null or empty name) is not found, so the page redirects. */
    public function testUnknownScopeIsNotFound(): void
    {
        require_once ORK3_ROOT . '/orkui/controller/controller.Reports.php';
        self::assertFalse(Controller_Reports::officer_history_scope_found(99999, null));
        self::assertFalse(Controller_Reports::officer_history_scope_found(99999, ''));
        self::assertFalse(Controller_Reports::officer_history_scope_found(0, 'Crystal Groves'));
        self::assertTrue(Controller_Reports::officer_history_scope_found(17, 'Crystal Groves'));
    }

    public function testReportsTabsLinkTheNewPage(): void
    {
        $kingdom = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/Kingdomnew_index.tpl');
        $park = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/Parknew_index.tpl');
        self::assertStringContainsString('Reports/officer_history&KingdomId=', $kingdom);
        self::assertStringContainsString('Reports/officer_history&KingdomId=', $park);
        self::assertMatchesRegularExpression('/Reports\/officer_history&KingdomId=<\?= \$kingdom_id \?>&ParkId=<\?= \$park_id \?>/', $park);
    }

    /**
     * A linked holder shows the name the term was recorded under, linked to the
     * profile, with a muted "now known as" when the persona differs. A blank
     * persona falls back to the recorded name, never "Player #id".
     */
    public function testLinkedHoldersShowTheirRecordedName(): void
    {
        foreach ([false, true] as $edit) {
            $html = self::render(true, $edit);
            self::assertStringContainsString(
                '<a class="or-holder-link" href="' . UIR . 'Player/profile/21">Squire Bob</a> <span class="or-holder-aka">now known as Sir Robert</span>',
                $html
            );
            self::assertStringContainsString('<a class="or-holder-link" href="' . UIR . 'Player/profile/22">Dame Departed</a>', $html);
            self::assertStringContainsString('<a class="or-holder-link" href="' . UIR . 'Player/profile/23">same name</a>', $html);
            self::assertStringNotContainsString('now known as Same Name', $html, 'a case-only difference is the same name');
            self::assertStringNotContainsString('Player #', $html);
            // A linked holder with no recorded name still shows the persona.
            self::assertStringContainsString('>Sir Bors</a>', $html);
        }
        $ledger = self::render(true, true);
        self::assertStringContainsString('data-holder="Squire Bob"', self::row($ledger, 507), 'the editor starts from the recorded name');
        self::assertStringContainsString('data-holder="Sir Bors"', self::row($ledger, 503));

        $js = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/script/officer-rolls.js');
        self::assertStringNotContainsString("'Player #'", $js);
        self::assertStringContainsString('now known as ', $js);
        $modal = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/partials/_officer_details_modal.tpl');
        self::assertStringContainsString('now known as ', $modal);
        self::assertStringNotContainsString('h.Persona || h.HolderName', $modal, 'the modal leads with the recorded name');
        $css = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/style/officer-rolls.css');
        self::assertMatchesRegularExpression('/\.or-holder-aka\s*\{[^}]*var\(--rp-text-hint\)/', $css);
    }

    /**
     * Enter in the holder field never saves typed text as plain text while a
     * matching player is listed: a match is highlighted, an open list with no
     * highlight is closed by the first Enter, and a search in flight is waited for.
     */
    public function testEnterInTheHolderDropdownDoesNotDropAMatch(): void
    {
        $js = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/script/officer-rolls.js');
        self::assertStringContainsString('function acBestMatch(', $js);
        self::assertStringContainsString('_orPending', $js, 'a pending search is tracked');
        self::assertStringContainsString('_orEnterQueued', $js, 'an Enter during a search waits for it');
        $k = strpos($js, "root.addEventListener('keydown'");
        $keydown = substr($js, $k, strpos($js, "
    });
", $k) - $k);
        self::assertDoesNotMatchRegularExpression('/acPick\(wrap, focused\); return; \}\s*acClose\(wrap\);\s*\}/', $keydown, 'Enter must not close the list and fall through to a save');
        $hi = strpos($js, 'function holderInput(input) {');
        $holderInput = substr($js, $hi, strpos($js, "\n    }\n", $hi) - $hi);
        self::assertMatchesRegularExpression('/clearTimeout\(input\._orTimer\);\s*input\._orEnterQueued = false;/', $holderInput, 'a new keystroke cancels an Enter queued before it');
    }

    /** The ledger sends the picked persona as the name of record on add. */
    public function testAddSendsTheHolderNameItWasRecordedUnder(): void
    {
        $js = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/script/officer-rolls.js');
        $a = strpos($js, 'function addTerm(tr) {');
        $add = substr($js, $a, strpos($js, "\n    }\n", $a) - $a);
        self::assertStringContainsString('HolderName: name,', $add);
        self::assertStringNotContainsString("HolderName: id ? '' : name", $add);
    }

    /** A save re-renders one office from the write's own response: no full-org rolls refetch. */
    public function testASaveRefreshesOneOffice(): void
    {
        $html = self::render(true, true);
        self::assertMatchesRegularExpression('/<details class="or-office"[^>]*data-key="position:11"[^>]*data-order="0"/', $html);
        self::assertMatchesRegularExpression('/<details class="or-office"[^>]*data-key="position:13"[^>]*data-order="2"/', $html);
        $js = (string) file_get_contents(ORK3_ROOT . '/orkui/template/revised-frontend/script/officer-rolls.js');
        self::assertStringNotContainsString("post('rolls', {})", $js, 'never the whole org');
        self::assertStringNotContainsString('previousSpan(text)', $js, 'the add answers with PreviousSpan');
        $src = (string) file_get_contents(ORK3_ROOT . '/orkui/controller/controller.OfficerAdminAjax.php');
        self::assertStringContainsString("'PreviousSpan'", $src);
        self::assertStringContainsString("'Office'", $src);
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
