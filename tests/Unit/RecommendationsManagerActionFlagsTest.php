<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Recommendations Manager offers each row action only when the viewer passes
 * that action's server gate (Controller_Recommendations::rmCapabilities). This
 * renders _rm_row.tpl directly with a fixed group and pins that every button
 * tracks its own flag, and that a missing flag set fails closed.
 */
final class RecommendationsManagerActionFlagsTest extends TestCase
{
    private const ALL = [
        'Grant' => true, 'Court' => true, 'Snooze' => true, 'PassLocal' => true,
        'MonarchyOnly' => true, 'Dismiss' => true, 'Restore' => true,
    ];

    private const BUTTONS = [
        'Grant'        => 'rm-act-grant',
        'Court'        => 'rm-act-court',
        'Snooze'       => 'rm-act-snooze',
        'PassLocal'    => 'rm-act-passlocal',
        'MonarchyOnly' => 'rm-act-monarchy',
        'Dismiss'      => 'rm-act-dismiss',
    ];

    public static function setUpBeforeClass(): void
    {
        // Defined by controller.Recommendations.php in the app; the row template
        // only needs the formatter, not the controller.
        if (!function_exists('rmNiceDate')) {
            function rmNiceDate($date)
            {
                return (string)$date;
            }
        }
    }

    private function render(?array $can, string $context = 'kingdom', bool $dismissed = false, array $extra = []): string
    {
        $group = $extra + [
            'MundaneId' => 11, 'KingdomAwardId' => 22, 'Rank' => 3, 'IsLadder' => true,
            'Persona' => 'Test Persona', 'AwardName' => 'Order of Testing', 'ParkId' => 5,
            'MemberRecIds' => [101], 'SupportCount' => 0, 'RepRecId' => 101,
            'OldestDate' => '2026-01-01', 'OldestAgeDays' => 10,
            'IsDismissed' => $dismissed,
            'Members' => [['RecommendedByName' => 'Someone', 'DateRecommended' => '2026-01-01', 'Reason' => 'Because']],
        ];
        $Parks    = [];
        $CourtMap = [];
        $Context  = $context;
        if ($can !== null) {
            $RmCan = $can;
        }
        ob_start();
        include ORK3_ROOT . '/orkui/template/revised-frontend/_rm_row.tpl';
        return (string)ob_get_clean();
    }

    public function testEveryActionRendersWhenEveryFlagIsSet(): void
    {
        $html = $this->render(self::ALL);
        foreach (self::BUTTONS as $class) {
            $this->assertStringContainsString($class, $html);
        }
        $this->assertStringContainsString('rm-act-more', $html);
    }

    /** @return array<string, array{string}> */
    public static function flagProvider(): array
    {
        return array_combine(array_keys(self::BUTTONS), array_map(fn ($k) => [$k], array_keys(self::BUTTONS)));
    }

    #[DataProvider('flagProvider')]
    public function testEachButtonTracksOnlyItsOwnFlag(string $flag): void
    {
        $html = $this->render(array_merge(self::ALL, [$flag => false]));
        $this->assertStringNotContainsString(self::BUTTONS[$flag], $html, "$flag off must hide its button");
        foreach (self::BUTTONS as $other => $class) {
            if ($other !== $flag) {
                $this->assertStringContainsString($class, $html, "$flag off must not hide $other");
            }
        }
    }

    public function testPassLocalNeverRendersInParkContext(): void
    {
        $this->assertStringNotContainsString('rm-act-passlocal', $this->render(self::ALL, 'park'));
    }

    public function testMoreMenuOnlyWhenItHasSomethingToFold(): void
    {
        $html = $this->render(array_merge(self::ALL, ['Snooze' => false, 'PassLocal' => false, 'Dismiss' => false]));
        $this->assertStringNotContainsString('rm-act-more', $html);
    }

    public function testMissingFlagsFailClosedToAReadOnlyRow(): void
    {
        $html = $this->render(null);
        foreach (self::BUTTONS as $class) {
            $this->assertStringNotContainsString($class, $html);
        }
        $this->assertStringNotContainsString('rm-act-more', $html);
        $this->assertMatchesRegularExpression('/rm-col-act">\s*<span class="rm-empty">/', $html);
    }

    public function testRestoreTracksItsFlagOnADismissedRow(): void
    {
        $this->assertStringContainsString('rm-act-undelete', $this->render(self::ALL, 'kingdom', true));
        $html = $this->render(array_merge(self::ALL, ['Restore' => false]), 'kingdom', true);
        $this->assertStringNotContainsString('rm-act-undelete', $html);
        $this->assertStringNotContainsString('rm-act-grant', $html, 'a dismissed row offers only Restore');
    }

    /**
     * Unhide needs the viewer's level to reach the rec's STORED level: a kingdom-hidden
     * rec passed down to a park (effective level 1) still refuses a park officer.
     */
    public function testUnhideOnlyWhenTheViewerReachesTheStoredLevel(): void
    {
        $kingdomHidden = ['MonarchyOnlyAll' => true, 'MonarchyOnlyLevel' => 1, 'MonarchyOnlyUnhideLevel' => 2];
        $park = array_merge(self::ALL, ['MonarchyOnlyViewerLevel' => 1]);
        $this->assertStringNotContainsString('rm-act-monarchy', $this->render($park, 'park', false, $kingdomHidden));
        $kingdom = array_merge(self::ALL, ['MonarchyOnlyViewerLevel' => 2]);
        $this->assertStringContainsString('rm-act-monarchy', $this->render($kingdom, 'park', false, $kingdomHidden));
        // Hiding a public rec needs only the MonarchyOnly flag.
        $this->assertStringContainsString('rm-act-monarchy', $this->render($park, 'park'));
    }

    public function testRowsEndpointRendersWithTheSameFlags(): void
    {
        // rows() re-renders _rm_row.tpl outside manage(); it must hand it the flags
        // or every scrolled-in row would come back read-only.
        $src = (string)file_get_contents(ORK3_ROOT . '/orkui/controller/controller.Recommendations.php');
        $rows = substr($src, strpos($src, 'public function rows('), 4000);
        $this->assertStringContainsString('$RmCan    = $this->rmCapabilities(', $rows);
    }
}
