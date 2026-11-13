<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Manage Officers and the Officer History page agree on a term's start, and every
 * way into the ledger says whether the viewer may edit it.
 *
 * Pinned here:
 *   - termIndex()/occupant() pass the domain's StartLabel/StartOrdinal (and the
 *     end twins) and the current row's id through to Manage Officers;
 *   - the "Start date unknown" nudge keys on the start ordinal, so a season
 *     start clears it, and its Set-it dialog takes season text (parseperiod +
 *     edithistory Period), never a date-only picker;
 *   - Correct the Rolls, the Details modal and the officer_history page all read
 *     can_edit_history_for (the gate the history writes enforce) for the edit
 *     link, and a refused &edit=1 says which permission it needs.
 */
final class OfficerRollsDiscoverabilityTest extends TestCase
{
    private const PARTIALS = '/orkui/template/revised-frontend/partials/';

    private static function src(string $rel): string
    {
        return (string) file_get_contents(ORK3_ROOT . $rel);
    }

    /** The body of one function in $src, from its signature to the next sibling. */
    private static function body(string $src, string $signature, string $stop): string
    {
        $at = strpos($src, $signature);
        self::assertNotFalse($at, $signature . ' exists');
        $end = strpos($src, $stop, $at + strlen($signature));
        return substr($src, $at, ($end === false ? strlen($src) : $end) - $at);
    }

    private static function controller(array $latest): array
    {
        require_once DIR_UI . 'controller/controller.OfficerAdminAjax.php';
        $rc = new ReflectionClass('Controller_OfficerAdminAjax');
        $c = $rc->newInstanceWithoutConstructor();
        $c->OfficerPosition = new class ($latest) {
            public function __construct(private array $rows)
            {
            }

            public function latest_term_by_holder(int $k, int $p): array
            {
                return $this->rows;
            }
        };
        return [$rc, $c];
    }

    /** A GetOfficerHistory row: the span columns go through history_term_fields. */
    private static function historyRow(int $id, bool $current, array $cols): array
    {
        $cols += ['is_current' => $current ? 1 : 0];
        return [
            'OfficerHistoryId' => $id, 'MundaneId' => 9, 'Role' => 'monarch', 'IsCurrent' => $current,
            'StartDate' => $cols['start_date'] ?? null, 'EndDate' => $cols['end_date'] ?? null,
        ] + OfficerPosition::history_term_fields($cols);
    }

    // ---------------------------------------------------------------- item 21

    public function testTermIndexPassesTheSeasonStartThrough(): void
    {
        [$rc, $c] = self::controller([
            '9|monarch' => self::historyRow(501, true, [
                'start_season' => 'spring', 'start_year' => 2024, 'start_ordinal' => 2024 * 4 + 1,
            ]),
            '10|regent' => self::historyRow(502, false, [
                'start_season' => 'fall', 'start_year' => 2020, 'start_ordinal' => 2020 * 4 + 3,
                'end_season' => 'summer', 'end_year' => 2021, 'end_ordinal' => 2021 * 4 + 2,
            ]),
        ]);
        $termIndex = $rc->getMethod('termIndex');
        $terms = $termIndex->invoke($c, 17, 0);

        $t = $terms['9|monarch'];
        self::assertSame('', $t['StartDate'], 'a season start has no date');
        self::assertSame('Spring 2024', $t['StartLabel']);
        self::assertSame(2024 * 4 + 1, $t['StartOrdinal']);
        self::assertNull($t['EndLabel']);
        self::assertNull($t['EndOrdinal']);
        self::assertSame(501, $t['OfficerHistoryId']);
        self::assertTrue($t['IsCurrent']);

        $occupant = $rc->getMethod('occupant');
        $o = $occupant->invoke($c, ['MundaneId' => 9, 'CanonicalKey' => 'monarch', 'Persona' => 'Queen Ada'], $terms);
        self::assertSame('Spring 2024', $o['TermStartLabel']);
        self::assertSame(2024 * 4 + 1, $o['TermStartOrdinal']);
        self::assertNull($o['TermEndOrdinal']);
        self::assertSame(501, $o['TermHistoryId'], 'the open row the Set-it dialog edits');
        self::assertSame('', $o['TermStartRaw']);

        // A closed latest term is not the seat's open row: no id to edit from here.
        $o = $occupant->invoke($c, ['MundaneId' => 10, 'CanonicalKey' => 'regent', 'Persona' => 'Sir Bors'], $terms);
        self::assertSame('Summer 2021', $o['TermEndLabel']);
        self::assertSame(0, $o['TermHistoryId']);

        // No history at all: every start key reads unknown.
        $o = $occupant->invoke($c, ['MundaneId' => 11, 'CanonicalKey' => 'champion', 'Persona' => 'X'], $terms);
        self::assertNull($o['TermStartOrdinal']);
        self::assertNull($o['TermStartLabel']);
        self::assertSame(0, $o['TermHistoryId']);
    }

    public function testTheNudgeKeysOnTheStartOrdinal(): void
    {
        $tpl = self::src(self::PARTIALS . '_manage_officers.tpl');
        $line = self::body($tpl, 'function occupantLine(pos)', "\n\t}\n");
        self::assertStringNotContainsString('!o.TermStartRaw', $line, 'a season start is a known start');
        self::assertMatchesRegularExpression('/o\.TermStartOrdinal\s*==\s*null/', $line);
        self::assertStringContainsString('MoConfig.canEditHistory', $line, 'Set it is offered only to history editors');
        // A seat with no open row has nothing to edit here, and the ledger's synthetic
        // seat row only says "Set from Manage Officers": no link, just a note.
        self::assertStringNotContainsString('#office-', $line, 'no link that points back here');
        self::assertStringContainsString('no history row for this seat yet', $line);

        $term = self::body($tpl, 'function termLine(o)', "\n\t}\n");
        self::assertStringContainsString('o.TermStartLabel', $term, 'the term line shows the season label');
        self::assertStringContainsString('o.TermEndLabel', $term);
    }

    public function testSetItTakesSeasonTextNotADate(): void
    {
        $tpl = self::src(self::PARTIALS . '_manage_officers.tpl');
        self::assertStringNotContainsString("flatpickr('#mo-start-date'", $tpl, 'no date-only picker (spec anti-goal)');
        self::assertStringContainsString('id="mo-start-period"', $tpl);
        self::assertStringContainsString('id="mo-start-readback"', $tpl);
        $save = self::body($tpl, 'window.moSaveStart = function()', "\n\t};\n");
        self::assertStringContainsString("base() + 'edithistory'", $save);
        self::assertMatchesRegularExpression('/\\{\\s*OfficerHistoryId:\\s*hid,\\s*Period:\\s*text,\\s*ExpectCurrent:\\s*1\\s*\\}/', $save);
        // A refused save (e.g. the term ended since the list loaded) is shown inline.
        self::assertStringContainsString("moFormError('mo-start-error'", $save);
        $ctl = self::src('/orkui/controller/controller.OfficerAdminAjax.php');
        $edit = self::body($ctl, 'private function actionEditHistory(', "\n    }\n");
        self::assertStringContainsString("if (!empty(\$_POST['ExpectCurrent'])) {", $edit);
        self::assertStringContainsString("\$request['ExpectCurrent'] = 1;", $edit);
        self::assertStringNotContainsString('StartDate:', $save);
        self::assertStringContainsString("'parseperiod'", $tpl, 'the dialog reads the season back before saving');
        self::assertStringContainsString('Current: 1', $tpl, 'read back as a current term start');
        // The exact-day route is the ledger's Calendar mode, linked from the dialog.
        self::assertStringContainsString('id="mo-start-ledger"', $tpl);
    }

    // ---------------------------------------------------------------- item 22

    private static function renderCorrectTheRolls(bool $canEdit, int $parkId = 0): string
    {
        $mo_kingdom_id = 17;
        $mo_park_id = $parkId;
        $mo_can_edit_history = $canEdit;
        ob_start();
        include ORK3_ROOT . self::PARTIALS . '_correct_the_rolls.tpl';
        return (string) ob_get_clean();
    }

    public function testCorrectTheRollsLinksEditOnlyForHistoryEditors(): void
    {
        $editor = self::renderCorrectTheRolls(true);
        self::assertStringContainsString('Edit the rolls', $editor);
        self::assertStringContainsString('Reports/officer_history&amp;KingdomId=17&amp;edit=1', $editor);
        self::assertStringNotContainsString('View the rolls', $editor);

        $viewer = self::renderCorrectTheRolls(false, 74);
        self::assertStringContainsString('View the rolls', $viewer);
        self::assertStringNotContainsString('Edit the rolls', $viewer);
        self::assertStringNotContainsString('edit=1', $viewer);
        self::assertStringContainsString('Reports/officer_history&amp;KingdomId=17&amp;ParkId=74', $viewer);
        self::assertStringContainsString('park Officer History permission', $viewer);

        self::assertStringContainsString('kingdom Officer History permission', self::renderCorrectTheRolls(false));
    }

    public function testConsolesReadTheHistoryGateThroughTheModel(): void
    {
        $admin = self::src('/orkui/controller/controller.Admin.php');
        $kingdom = self::body($admin, 'private function load_kingdom_admin_data(', "\n    }\n");
        self::assertMatchesRegularExpression(
            "/'CanEditOfficerHistory'\\]\\s*=\\s*\\\$this->OfficerPosition->can_edit_history_for\\(\\s*\\\$uid,\\s*\\(int\\)\\\$id,\\s*0\\s*\\)/",
            $kingdom
        );
        $park = self::body($admin, 'public function park($id = null)', "\n    }\n");
        self::assertMatchesRegularExpression(
            "/'CanEditOfficerHistory'\\]\\s*=\\s*\\\$this->OfficerPosition->can_edit_history_for\\(\\s*\\\$_uid,\\s*\\\$_standingKingdomId,\\s*\\(int\\)\\\$id\\s*\\)/",
            $park
        );
        foreach (['Admin_kingdom.tpl', 'Admin_park.tpl'] as $host) {
            self::assertStringContainsString(
                '$mo_can_edit_history = !empty($CanEditOfficerHistory);',
                self::src('/orkui/template/revised-frontend/' . $host),
                $host . ' hands the gate to Manage Officers'
            );
        }
        $mo = self::src(self::PARTIALS . '_manage_officers.tpl');
        self::assertStringContainsString('canEditHistory: <?= $mo_can_edit_history ? \'true\' : \'false\' ?>', $mo);
    }

    private static function renderDetailsModal(bool $canEdit, string $scope): string
    {
        $officerList = [];
        $ofScope = $scope;
        $ofOrgId = $scope === 'park' ? 74 : 17;
        $ofKingdomId = 17;
        $ofOrgName = 'Crystal Groves';
        $ofCanEditRolls = $canEdit;
        $OfficerHistoryRoleOptions = [];
        ob_start();
        include ORK3_ROOT . self::PARTIALS . '_officer_details_modal.tpl';
        return (string) ob_get_clean();
    }

    public function testDetailsModalLinksTheFullHistory(): void
    {
        $viewer = self::renderDetailsModal(false, 'kingdom');
        $toolbar = self::body($viewer, 'class="of-oh-toolbar"', '</div>');
        self::assertStringContainsString('Full officer history', $toolbar);
        self::assertStringContainsString('Reports/officer_history&amp;KingdomId=17"', $toolbar);
        self::assertStringNotContainsString('Edit the rolls', $toolbar);

        $editor = self::renderDetailsModal(true, 'park');
        $toolbar = self::body($editor, 'class="of-oh-toolbar"', '</div>');
        self::assertStringContainsString('Full officer history', $toolbar);
        self::assertStringContainsString('Reports/officer_history&amp;KingdomId=17&amp;ParkId=74"', $toolbar);
        self::assertStringContainsString('Edit the rolls', $toolbar);
        self::assertStringContainsString('Reports/officer_history&amp;KingdomId=17&amp;ParkId=74&amp;edit=1', $toolbar);
    }

    public function testProfilesReadTheHistoryGateThroughTheModel(): void
    {
        $k = self::src('/orkui/controller/controller.Kingdom.php');
        self::assertMatchesRegularExpression(
            "/'CanEditOfficerHistory'\\]\\s*=\\s*\\\$this->OfficerPosition->can_edit_history_for\\(\\s*\\\$uid,\\s*\\(int\\)\\\$kingdom_id,\\s*0\\s*\\)/",
            $k
        );
        $p = self::src('/orkui/controller/controller.Park.php');
        self::assertMatchesRegularExpression(
            "/'CanEditOfficerHistory'\\]\\s*=\\s*\\\$this->OfficerPosition->can_edit_history_for\\(/",
            $p
        );
        foreach (['Kingdomnew_index.tpl', 'Parknew_index.tpl'] as $host) {
            self::assertStringContainsString(
                '$ofCanEditRolls = !empty($CanEditOfficerHistory);',
                self::src('/orkui/template/revised-frontend/' . $host),
                $host . ' hands the gate to the Details modal'
            );
        }
    }

    private static function renderReport(bool $canEdit, bool $refused): string
    {
        $Rolls = ['Offices' => []];
        $ScopeType = 'kingdom';
        $ScopeId = 17;
        $ScopeName = 'Crystal Groves';
        $KingdomId = 17;
        $CanEditRolls = $canEdit;
        $EditMode = false;
        $EditRefused = $refused;
        ob_start();
        include ORK3_ROOT . '/orkui/template/revised-frontend/Reports_officer_history.tpl';
        return (string) ob_get_clean();
    }

    public function testARefusedEditSaysWhichPermissionItNeeds(): void
    {
        $refused = self::renderReport(false, true);
        self::assertMatchesRegularExpression('/class="rp-context[^"]*or-edit-refused/', $refused);
        self::assertStringContainsString('kingdom Officer History permission', $refused);
        self::assertStringNotContainsString('or-edit-refused', self::renderReport(false, false));

        $src = self::src('/orkui/controller/controller.Reports.php');
        $body = self::body($src, 'public function officer_history(', "\n    }\n");
        self::assertMatchesRegularExpression(
            "/'EditRefused'\\]\\s*=\\s*!empty\\(\\\$this->request->edit\\)\\s*&&\\s*!\\\$this->data\\['CanEditRolls'\\]/",
            $body
        );
    }

    /** #office-N on the ledger URL opens and scrolls to that office's section. */
    public function testTheLedgerOpensTheLinkedOffice(): void
    {
        $tpl = self::src('/orkui/template/revised-frontend/Reports_officer_history.tpl');
        self::assertStringContainsString("'&edit=1#office-'", self::body(self::src(self::PARTIALS . '_manage_officers.tpl'), 'window.moSetStart = function(pid)', "\n\t};\n"), 'the dialog\'s exact-day link');
        self::assertStringContainsString('location.hash', $tpl);
        self::assertStringContainsString('#office-(\\d+)', $tpl);
        self::assertStringContainsString('details.or-office[data-position-id="', $tpl);
    }
}
