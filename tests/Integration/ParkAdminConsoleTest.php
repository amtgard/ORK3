<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Park admin console is the kingdom console's twin, on park data.
 *
 * These are source-text assertions, the same shape as KaModalCoreTest: the
 * console is a template plus inline page script with no PHP surface to call,
 * so short of a browser the only thing checkable is that the right data is
 * read, the right labels are printed, and the shared engine is consumed rather
 * than re-implemented.
 *
 * Most of what is pinned here is a decision somebody could plausibly "tidy"
 * back into a bug:
 *   - the hero reading $AdminDashboard (kingdom-scoped, never set on this
 *     route) instead of $ParkAdminDashboard;
 *   - "Members" as the label on a number the park profile deliberately counts
 *     differently, which reads as one of the two pages being broken;
 *   - deriving filled offices as OfficeCount - VacantOffices, which is wrong
 *     the moment a hide_when_vacant position exists;
 *   - collapsing NULL QuietDays ("nothing has ever been signed in here") into
 *     0 ("somebody signed in today").
 */
final class ParkAdminConsoleTest extends TestCase
{
    private const PAGE    = 'orkui/template/revised-frontend/Admin_park.tpl';
    private const MODALS  = 'orkui/template/revised-frontend/partials/_park_admin_modals.tpl';
    private const QUEUE   = 'orkui/template/revised-frontend/partials/_ka_queue.tpl';
    private const CHROME  = 'orkui/template/revised-frontend/partials/_ka_modal_chrome.tpl';
    private const KINGDOM = 'orkui/template/revised-frontend/Admin_kingdom.tpl';
    private const EVMODAL = 'orkui/template/revised-frontend/partials/_event_create_modal.tpl';
    private const PROFILE = 'orkui/template/revised-frontend/Parknew_index.tpl';
    private const REVISED = 'orkui/template/revised-frontend/script/revised.js';

    /** Every modal id fixed by CONTRACT.md section C. */
    private const CONTRACT_MODAL_IDS = [
        'ka-details-overlay',
        'ka-heraldry-overlay',
        'ka-parkdays-overlay',
        'ka-createplayer-overlay',
        'ka-moveplayer-overlay',
        'ka-mergeplayer-overlay',
        'ka-ops-overlay',
        'ka-confirm-overlay',
        'ka-mo-overlay',
    ];

    /** The park-scoped reports the sidebar must offer, mapped to the URL shape
     *  each controller action actually accepts. Thirteen read the id from the
     *  query string; Reports::event_attendance() explodes $params on '/' and
     *  takes it as a PATH segment, so linking it the &id= way redirects home
     *  with no message. That asymmetry is exactly why the URL is pinned here
     *  and not just the report name. */
    private const PARK_REPORTS = [
        'active'                       => 'Reports/active/Park&amp;id=',
        'active_duespaid'              => 'Reports/active_duespaid/Park&amp;id=',
        'active_waivered_duespaid'     => 'Reports/active_waivered_duespaid/Park&amp;id=',
        'attendance'                   => 'Reports/attendance/Park&amp;id=',
        'dues'                         => 'Reports/dues/Park&amp;id=',
        'duespaid'                     => 'Reports/duespaid/Park&amp;id=',
        'event_attendance'             => 'Reports/event_attendance/Park/',
        'inactive'                     => 'Reports/inactive/Park&amp;id=',
        'player_status_reconciliation' => 'Reports/player_status_reconciliation/Park&amp;id=',
        'roster'                       => 'Reports/roster/Park&amp;id=',
        'suspended'                    => 'Reports/suspended/Park&amp;id=',
        'unwaivered'                   => 'Reports/unwaivered/Park&amp;id=',
        'voting_eligible'              => 'Reports/voting_eligible/Park&amp;id=',
        'waivered'                     => 'Reports/waivered/Park&amp;id=',
    ];

    private static function read(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($path, $rel . ' is missing');
        return (string)file_get_contents($path);
    }

    /**
     * Source with COMMENTS REMOVED -- PHP block/line comments and HTML
     * comments. Several assertions below are "this file must not mention X",
     * and this console's own comments explain at length what it deliberately
     * does NOT read. Scanning the raw text would fail on the explanation
     * rather than on the mistake.
     */
    private static function code(string $rel): string
    {
        $src = self::read($rel);
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        // HTML comments, and the /* ... */ blocks inside <style> (both arrive
        // as T_INLINE_HTML, so the tokenizer leaves them alone).
        $out = preg_replace('/<!--.*?-->/s', '', $out);
        return (string)preg_replace('#/\*.*?\*/#s', '', $out);
    }

    /* ───────────────────────── data source ───────────────────────── */

    /** The console reads the PARK dashboard. $AdminDashboard is kingdom-scoped
     *  and is not set on the Admin/park route at all -- reading it would render
     *  a hero of silent zeros. */
    public function testHeroReadsTheParkDashboard(): void
    {
        $code = self::code(self::PAGE);
        $this->assertStringContainsString(
            '$ParkAdminDashboard',
            $code,
            'the park console does not read $ParkAdminDashboard'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$AdminDashboard\s*[\[\?]/',
            $code,
            'the park console reads $AdminDashboard -- that array is kingdom-scoped '
                . 'and Admin::park() never sets it'
        );
    }

    /** The three hero labels, spelled out. "Members" alone is banned: the number
     *  counts every ork_mundane row with this park_id, while the park profile's
     *  roster filters to active + unsuspended, so the two legitimately differ and
     *  an unqualified label invites "one of these pages is broken". */
    public static function heroLabelProvider(): array
    {
        return [
            'member records' => ['Member records'],
            'active window'  => ['Active &middot; 26 wk'],
            'attendance ytd' => ['Attendance &middot; YTD'],
        ];
    }

    /** @dataProvider heroLabelProvider */
    public function testHeroStatLabel(string $label): void
    {
        $this->assertStringContainsString(
            '<span class="ka-hero-stat-lbl">' . $label . '</span>',
            self::read(self::PAGE),
            'hero stat label "' . $label . '" is missing or reworded'
        );
    }

    public function testHeroNeverLabelsMemberRecordsAsMembers(): void
    {
        $this->assertStringNotContainsString(
            '<span class="ka-hero-stat-lbl">Members</span>',
            self::read(self::PAGE),
            'the membership-row count is labelled "Members"; it must say "Member records" '
                . 'so it cannot be read as disagreeing with the park profile roster'
        );
    }

    /** Filled offices may never be computed by subtraction: a hide_when_vacant
     *  position counts toward OfficeCount but never appears in VacantOfficeNames,
     *  so OfficeCount - VacantOffices is not "filled". */
    public function testNoFilledOfficeCountBySubtraction(): void
    {
        $code = self::code(self::PAGE);
        // The gap allows for the array-index noise either side of the operator
        // ("$_w['OfficeCount'] ?? 0) - (int)($_q['VacantOffices'..."), which a \W
        // class cannot cross because of the digits in `?? 0`.
        $this->assertDoesNotMatchRegularExpression(
            '/OfficeCount.{0,60}?-.{0,60}?VacantOffice/s',
            $code,
            'the console derives a filled-office count by subtraction; hide_when_vacant '
                . 'positions make that wrong. Use VacantOfficeNames only.'
        );
        $this->assertStringContainsString(
            "VacantOfficeNames",
            $code,
            'the vacancy card does not read VacantOfficeNames'
        );
    }

    /* ───────────────────── the NULL QuietDays state ───────────────────── */

    /** NULL QuietDays is its OWN card state. It means the park has no attendance
     *  rows at all, which is a different statement from "0 days since the last
     *  signin" -- and rendering it as the green all-clear would report an
     *  all-clear for a question nobody answered. */
    public function testNullQuietDaysRendersItsOwnState(): void
    {
        $code = self::code(self::PAGE);
        $this->assertMatchesRegularExpression(
            '/\$_quietDays\s*(!==|===)\s*null/',
            $code,
            'the console never distinguishes a NULL QuietDays from a numeric one'
        );
        $this->assertStringContainsString(
            'No signins on record',
            $code,
            'the NULL-QuietDays card has no copy of its own'
        );

        // And the card renderer has to have somewhere to put it.
        $queue = self::code(self::QUEUE);
        $this->assertStringContainsString(
            'ka-ts-card-unknown',
            $queue,
            '_ka_queue() has no unknown state, so a NULL count would render as the '
                . 'green "all clear" card'
        );
    }

    /** The unknown state must be visually distinct from the clear state in BOTH
     *  themes -- a dark-mode rule that only redefines one of them leaves the two
     *  pixel-identical, which is the bug this branch has already shipped once. */
    public function testUnknownQueueStateIsStyledInBothThemes(): void
    {
        $css = self::read('orkui/template/revised-frontend/style/admin-console.css');
        $this->assertMatchesRegularExpression(
            '/^\.ka-ts-card-unknown /m',
            $css,
            'no light-mode rule for .ka-ts-card-unknown'
        );
        $this->assertMatchesRegularExpression(
            '/^html\[data-theme="dark"\] \.ka-ts-card(\.ka-ts-card-unknown| \.ka-ts-card-unknown)/m',
            $css,
            'no dark-mode rule for .ka-ts-card-unknown -- it would collapse into the '
                . 'ordinary card colours'
        );
    }

    /* ───────────────────────── the modal layer ───────────────────────── */

    /** @dataProvider contractModalIdProvider */
    public function testContractModalExists(string $id): void
    {
        $this->assertStringContainsString(
            'id="' . $id . '"',
            self::read(self::MODALS),
            $id . ' is named in CONTRACT.md section C but is not rendered by the park '
                . 'modal partial'
        );
    }

    public static function contractModalIdProvider(): array
    {
        return array_map(static fn ($id) => [$id], self::CONTRACT_MODAL_IDS);
    }

    /** The park partial CONSUMES the shared engine. This is the assertion that
     *  fails the moment someone pastes the engine back in. */
    public static function engineInternalProvider(): array
    {
        return array_map(static fn ($n) => [$n], [
            'kaOpenModal', 'kaCloseModal', 'kaConfirm', 'kaCloseConfirm',
            'kaFeedback', 'kaClearFeedback', 'kaMarkClean', 'kaIsDirty',
            'kaSnapshot', 'kaResetFields', 'kaFocusFirst', 'kaFocusables',
            'kaRestoreFocus', 'kaOpenerNow', 'kaBoxOf', 'kaTop',
            'kaRegisterModal',
        ]);
    }

    /** @dataProvider engineInternalProvider */
    public function testParkDoesNotRedefineEngine(string $name): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+' . $name . '\s*\(/',
            self::read(self::MODALS),
            $name . '() is defined in the park partial -- it belongs to the shared '
                . 'engine in _ka_modal_core.tpl'
        );
    }

    public function testParkIncludesTheCoreOnceBeforeItsOwnScript(): void
    {
        $src = self::read(self::MODALS);
        $this->assertSame(
            1,
            substr_count($src, "include __DIR__ . '/_ka_modal_core.tpl';"),
            'park partial must include the modal core exactly once'
        );
        $includeAt = strpos($src, '_ka_modal_core.tpl');
        $usesAt    = strpos($src, 'window.kaRegisterModal');
        $this->assertIsInt($includeAt);
        $this->assertIsInt($usesAt);
        $this->assertLessThan(
            $usesAt,
            $includeAt,
            'the core must be included BEFORE the console script that consumes it'
        );
    }

    /** The chrome is shared too, not a second copy of ~250 lines of modal CSS. */
    public function testBothConsolesShareTheModalChrome(): void
    {
        foreach ([self::MODALS, 'orkui/template/revised-frontend/partials/_kingdom_admin_modals.tpl'] as $rel) {
            $this->assertSame(
                1,
                substr_count(self::read($rel), "include __DIR__ . '/_ka_modal_chrome.tpl';"),
                $rel . ' does not include the shared modal chrome exactly once'
            );
        }
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*defined\s*\(\s*[\'"]KA_MODAL_CHROME_RENDERED[\'"]/',
            self::read(self::CHROME),
            'the chrome partial has no include guard, so a page pulling in both '
                . 'consoles would emit it twice'
        );
    }

    /** Both consoles render their queue cards through the one _ka_queue(). */
    public function testBothConsolesShareTheQueueCard(): void
    {
        foreach ([self::PAGE, self::KINGDOM] as $rel) {
            $this->assertStringContainsString(
                "partials/_ka_queue.tpl",
                self::read($rel),
                $rel . ' does not include the shared queue-card partial'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/^function _ka_queue\(/m',
                self::code($rel),
                $rel . ' defines _ka_queue() itself again'
            );
        }
        $this->assertMatchesRegularExpression(
            "/if \(!function_exists\('_ka_queue'\)\)/",
            self::read(self::QUEUE),
            'the queue partial is not guarded, so including it twice would fatal'
        );
    }

    /** Modals that must not reopen carrying an abandoned selection, spelled out
     *  so a dropped registration fails here rather than as "the Move Player
     *  submit button is enabled with nothing selected". */
    public static function registrationProvider(): array
    {
        return [
            'move player'   => ['ka-moveplayer-overlay',   "{ resetOnOpen: ['ka-mp-submit'] }"],
            'merge players' => ['ka-mergeplayer-overlay',  "{ resetOnOpen: ['ka-mgp-submit'] }"],
            'create player' => ['ka-createplayer-overlay', '{ resetOnOpen: true }'],
            'heraldry'      => ['ka-heraldry-overlay',     "{ resetOnOpen: ['ka-heraldry-upload'] }"],
            'park days'     => ['ka-parkdays-overlay',     '{ resetOnOpen: true }'],
        ];
    }

    /** @dataProvider registrationProvider */
    public function testModalRegistration(string $id, string $opts): void
    {
        $this->assertStringContainsString(
            "kaRegisterModal('" . $id . "',",
            self::read(self::MODALS),
            $id . ' is not registered with the modal engine'
        );
        $this->assertStringContainsString(
            $opts,
            self::read(self::MODALS),
            $id . ' lost its ' . $opts . ' registration options'
        );
    }

    /** The page-level guard the shared core cannot provide: including
     *  _ka_modal_core.tpl wires document-level Escape / Tab / mousedown listeners
     *  regardless of any permission check, so the guard has to sit on the console
     *  script, exactly as it does on the kingdom console. */
    public function testConsoleScriptCarriesItsOwnGuard(): void
    {
        $this->assertStringContainsString(
            'if (!PkaConfig.canManage) return;',
            self::read(self::MODALS),
            'the park console script has no canManage guard of its own'
        );
    }

    /* ───────────────────────── house rules ───────────────────────── */

    public static function parkFileProvider(): array
    {
        return [
            'console page'  => [self::PAGE],
            'modal partial' => [self::MODALS],
            'queue card'    => [self::QUEUE],
        ];
    }

    /** No native dialogs anywhere -- they freeze the automation harness. */
    /** @dataProvider parkFileProvider */
    public function testNoNativeDialogs(string $rel): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_.$])(confirm|alert|prompt)\(/',
            self::code($rel),
            $rel . ' calls a native dialog -- use kaConfirm'
        );
    }

    /** .tpl files in this stack are plain PHP; Smarty syntax renders literally. */
    /** @dataProvider parkFileProvider */
    public function testIsPlainPhpNotSmarty(string $rel): void
    {
        $src = self::read($rel);
        $this->assertDoesNotMatchRegularExpression(
            '/\{\$[A-Za-z_]/',
            $src,
            $rel . ' uses Smarty-style {$var}; these templates are plain PHP'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\{(if|foreach|else)\s/',
            $src,
            $rel . ' uses Smarty-style {if}/{foreach}; these templates are plain PHP'
        );
    }

    /** Tooltips are data-tip. A native title= attribute is a browser tooltip,
     *  which this project does not use. */
    /** @dataProvider parkFileProvider */
    public function testNoNativeTitleTooltips(string $rel): void
    {
        // Deliberately NOT anchored to the opening "<tag": a template attribute
        // list is peppered with short PHP echo tags, so any [^>]* bounded scan
        // stops at the first PHP close tag and never reaches the attribute.
        $this->assertDoesNotMatchRegularExpression(
            '/\stitle\s*=\s*["\']/i',
            self::code($rel),
            $rel . ' uses a native title= tooltip; use data-tip'
        );
    }

    /** Templates never reach into the domain singleton. */
    /** @dataProvider parkFileProvider */
    public function testNoDomainSingletonInTemplates(string $rel): void
    {
        $this->assertStringNotContainsString(
            'Ork3::$Lib->',
            self::read($rel),
            $rel . ' calls Ork3::$Lib-> directly; templates read controller data'
        );
    }

    /** The park console must never pull in the kingdom's modal layer: those
     *  modals are built from $AdminInfo / $AdminConfig / $park_edit_lookup, none
     *  of which the Admin/park route sets. */
    public function testParkPageDoesNotIncludeTheKingdomModals(): void
    {
        $this->assertStringNotContainsString(
            '_kingdom_admin_modals.tpl',
            self::read(self::PAGE),
            'the park console includes the kingdom modal partial'
        );
        $this->assertStringContainsString(
            "partials/_park_admin_modals.tpl",
            self::read(self::PAGE),
            'the park console does not include its own modal partial'
        );
    }

    /* ───────────────────────── park-specific surfaces ───────────────────── */

    /** Reset Waivers states its real blast radius from Queue.WaiveredMembers,
     *  and treats "the key was missing" as different from "zero players". */
    public function testResetWaiversQuotesItsBlastRadius(): void
    {
        $code = self::code(self::MODALS);
        $this->assertStringContainsString(
            "'WaiveredMembers'",
            $code,
            'Reset Waivers does not read Queue.WaiveredMembers, so it cannot say how '
                . 'many players it clears'
        );
        $this->assertMatchesRegularExpression(
            '/\$_pkaWaivered\s*===\s*null/',
            $code,
            '"could not be read" is collapsed into "0 players" -- only the second of '
                . 'those justifies disabling the button'
        );
        $this->assertStringContainsString(
            'ParkAjax/park/',
            $code,
            'the console does not post to the park AJAX surface'
        );
    }

    /** Park Days is the park-only surface, and it drives the three endpoints that
     *  already exist rather than bouncing to the legacy Admin/editpark form. */
    public function testParkDaysUsesTheExistingEndpoints(): void
    {
        $code = self::code(self::MODALS);
        foreach (['addparkday', 'editparkday', 'deleteparkday'] as $action) {
            $this->assertStringContainsString(
                "'" . $action . "'",
                $code,
                'the Park Days modal never calls ' . $action
            );
        }
        $this->assertStringContainsString(
            "BASE_URL + 'setdetails'",
            $code,
            'Edit Park Details does not post to the park setdetails endpoint'
        );
    }

    /** Every park-scoped report is offered, at the URL its controller accepts. */
    /** @dataProvider parkReportProvider */
    public function testSidebarLinksParkReport(string $report, string $url): void
    {
        $this->assertStringContainsString(
            $url,
            self::read(self::PAGE),
            'the Reports card does not link Reports/' . $report . ' at park scope, '
                . 'or links it at a URL its controller rejects (expected "' . $url . '")'
        );
    }

    public static function parkReportProvider(): array
    {
        $out = [];
        foreach (self::PARK_REPORTS as $report => $url) {
            $out[$report] = [$report, $url];
        }
        return $out;
    }

    /** The Reports card carries the same filter box and the same wiring as the
     *  kingdom console's, so an officer only learns it once. */
    public function testReportsFilterMatchesTheKingdomConsole(): void
    {
        $park = self::read(self::PAGE);
        foreach (['id="ka-report-filter"', 'id="ka-reports-card"', 'id="ka-report-empty"'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $park,
                'the park Reports card is missing ' . $needle . ', which the filter script drives'
            );
        }
        $this->assertStringContainsString(
            'ka-report-section-lbl',
            $park,
            'the park Reports card has no group headings for the filter to hide'
        );
    }

    /** Manage Officers is reachable BOTH from a tile and from the vacancy queue
     *  card, as it is on the kingdom console. */
    public function testOfficersReachableFromTileAndQueue(): void
    {
        $page = self::read(self::PAGE);
        $this->assertSame(
            2,
            substr_count($page, 'kaOpenManageOfficers()'),
            'Manage Officers must open from exactly two places: the Officers tile and '
                . 'the vacancy queue card'
        );
        $this->assertStringContainsString(
            'id="ka-mo-overlay"',
            self::read(self::MODALS),
            'the Manage Officers host modal is not rendered'
        );
    }

    /* ──────────────── Schedule an Event (shared with the profile) ────────────
       The tile was the last thing on this console that navigated away: a plain
       link to the legacy full-page Admin/createevent form, while the park
       PROFILE had had the modal workflow for a while. These pin the fix so it
       cannot regress back to a link, and so the two pages cannot fork. */

    /** The tile opens the modal in place. Not a link, and never again a link to
     *  the legacy form. */
    public function testScheduleEventTileOpensTheModal(): void
    {
        $code = self::code(self::PAGE);
        $this->assertMatchesRegularExpression(
            '/<button class="ka-action-card" onclick="pkOpenEventModal\(\)">/',
            $code,
            'the Schedule an Event tile is not a button that opens the shared modal'
        );
        $this->assertDoesNotMatchRegularExpression(
            '#<a[^>]*href="[^"]*Admin/createevent#',
            $code,
            'the Schedule an Event tile still links to the legacy full-page form'
        );
        $this->assertStringNotContainsString(
            'Admin/createevent',
            $code,
            'the console still references the legacy create-event route'
        );
    }

    /** Every tile on the console is now modal-or-report; nothing bounces the
     *  officer to a legacy form. (Attendance and Tournament are still links,
     *  and deliberately so -- they are whole workflows, not a dialog.) */
    public function testTheConsoleHostsTheSharedEventModal(): void
    {
        $this->assertStringContainsString(
            "include __DIR__ . '/_event_create_modal.tpl'",
            self::read(self::MODALS),
            'the console does not include the shared create-event partial'
        );
        $this->assertStringNotContainsString(
            'id="pk-event-modal"',
            self::read(self::PAGE),
            'the console inlines its own copy of the modal instead of including the partial'
        );
    }

    /** The park PROFILE consumes the SAME partial. This is the assertion that
     *  keeps the two surfaces from drifting: if somebody re-inlines the markup
     *  on either page, one of these two fails. */
    public function testTheProfileConsumesTheSamePartial(): void
    {
        $profile = self::read(self::PROFILE);
        $this->assertStringContainsString(
            "include __DIR__ . '/partials/_event_create_modal.tpl'",
            $profile,
            'the park profile no longer includes the shared create-event partial'
        );
        $this->assertStringNotContainsString(
            'class="pk-emod-overlay" id="pk-event-modal"',
            $profile,
            'the park profile has its own copy of the modal markup again'
        );
    }

    /** The console must NOT define PkConfig. Fourteen blocks in revised.js guard
     *  on that name against park-PROFILE DOM; defining it here to wake the one
     *  we want would wake all of them against markup that does not exist. */
    public function testTheConsoleNeverDefinesPkConfig(): void
    {
        foreach ([self::PAGE, self::MODALS, self::EVMODAL] as $rel) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?:var|let|const)\s+PkConfig\s*=|window\.PkConfig\s*=/',
                self::read($rel),
                $rel . ' defines a PkConfig; the shared modal is keyed off EvCreateConfig '
                    . 'precisely so the console does not have to'
            );
        }
    }

    /** The shared block is keyed off the neutral EvCreateConfig and reads
     *  nothing off PkConfig, so it activates only where it was included. */
    public function testTheSharedModalIsKeyedOffEvCreateConfig(): void
    {
        $src = self::read(self::EVMODAL);
        $this->assertStringContainsString(
            'window.EvCreateConfig = {',
            $src,
            'the shared partial does not emit EvCreateConfig'
        );
        foreach (['uir:', 'parkId:', 'kingdomId:'] as $key) {
            $this->assertStringContainsString(
                $key,
                $src,
                'EvCreateConfig is missing ' . $key . ', which the shared block reads'
            );
        }
        $this->assertSame(
            2,
            preg_match_all("/typeof EvCreateConfig === 'undefined'/", $src),
            'both blocks in the shared partial must carry the EvCreateConfig guard'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/PkConfig\s*\./',
            $src,
            'the shared partial still reads a value off PkConfig'
        );
    }

    /** revised.js gave the block up rather than keeping a second copy. */
    public function testRevisedJsNoLongerCarriesTheBlock(): void
    {
        $js = self::read(self::REVISED);
        foreach (['pkOpenEventModal', 'pkCloseEventModal', 'pkCreateEvent', 'pkCfeToggleExpander', 'pkShowCalendarItemOverlay'] as $sym) {
            $this->assertDoesNotMatchRegularExpression(
                '/window\\.' . $sym . '\\s*=/',
                $js,
                'revised.js still defines window.' . $sym . '; the shared partial owns it '
                    . 'now, and two definitions would race'
            );
        }
        // The CALL at the calendar day-cell add button stays -- that is the
        // profile asking the shared modal to open, and it is feature-detected.
        $this->assertStringContainsString(
            'if (window.pkOpenEventModal) window.pkOpenEventModal(ds);',
            $js,
            'the calendar day-cell "+" no longer opens the create-event modal'
        );
    }

    /** Every write still goes to an endpoint that already existed. */
    public function testTheSharedModalUsesExistingEndpoints(): void
    {
        $src = self::read(self::EVMODAL);
        foreach ([
            'EventAjax/create',
            'EventAjax/copy_source_list',
            'EventAjax/create_with_copy',
            'CalendarItemAjax/create',
            'CalendarItemAjax/update',
            'CalendarItemAjax/delete',
            'CalendarItemAjax/get/',
        ] as $endpoint) {
            $this->assertStringContainsString(
                $endpoint,
                $src,
                'the shared modal no longer posts to ' . $endpoint
            );
        }
    }

    /** The copy-from-past-event picker is the house kn-ac-results dropdown, and
     *  it can position itself on BOTH host pages. tnPositionAcFixed is a
     *  revised.js export; the console does not load revised.js, and without the
     *  fallback the render threw a ReferenceError that the caller's .catch()
     *  swallowed into a permanent "No matching past events". */
    public function testTheCopyFromPickerCanPositionItselfOnBothPages(): void
    {
        $src = self::read(self::EVMODAL);
        $this->assertStringContainsString(
            'kn-ac-results',
            $src,
            'the copy-from picker is not the house kn-ac dropdown'
        );
        $this->assertStringNotContainsString(
            'ui-autocomplete',
            $src,
            'the copy-from picker uses jQuery UI; this project uses kn-ac-results'
        );
        $this->assertStringContainsString(
            'window.tnPositionAcFixed || window.tnFixedAcPosition',
            $src,
            'the copy-from dropdown has no positioner fallback, so it throws on any '
                . 'page that does not load revised.js'
        );
    }

    /** House rules for the shared partial. It is NOT in parkFileProvider only
     *  because that provider is park-console-scoped; every rule it enforces is
     *  asserted here directly, including the native-title ban.
     *
     *  History: the colour swatches arrived carrying title= attributes, copied
     *  verbatim from the profile's shipped markup, because the extraction that
     *  created this partial was required to leave the profile's DOM
     *  byte-identical. That constraint applied to the MOVE, not forever. Once
     *  the move was proven, the attributes were converted to data-tip -- which
     *  needs no CSS of its own: revised.css already carries a broad
     *  `[data-tip]:not(...)` rule supplying both `position: relative` and the
     *  light/dark tooltip. Both hosts load that stylesheet, so the profile and
     *  the console pick up the change together and cannot drift. */
    /**
     * The tournament creator is deprecated pending a replacement, so its tile is
     * de-emphasised -- but deliberately NOT removed or disabled: Tournament::create()
     * still works, and taking a live capability away from park officers before the
     * replacement ships would be worse than showing a discouraged one.
     *
     * Asserts all three halves, because any one alone is a half-finished state: the
     * greying class, the visible "Deprecated" chip (styling alone is easy to miss,
     * and reads as a rendering bug rather than an intention), and the surviving href.
     */
    public function testTournamentTileIsDeprecatedButStillReachable(): void
    {
        $tpl = self::read('orkui/template/revised-frontend/Admin_park.tpl');

        self::assertMatchesRegularExpression(
            '/class="ka-action-card ka-action-card-deprecated"[^>]*?href="[^"]*Tournament\/create/s',
            $tpl,
            'the tournament tile must carry ka-action-card-deprecated AND keep its href'
        );
        self::assertStringContainsString(
            '<span class="ka-dep-chip">Deprecated</span>',
            $tpl,
            'a greyed tile with no label reads as a rendering bug; keep the Deprecated chip'
        );

        // Both themes, or the muted card reads as live in whichever one was missed --
        // the exact failure this branch already shipped once with the alert banners.
        $css = self::read('orkui/template/revised-frontend/style/admin-console.css');
        self::assertStringContainsString(
            '.ka-action-card-deprecated {',
            $css,
            'ka-action-card-deprecated has no light-theme rule'
        );
        self::assertStringContainsString(
            'html[data-theme="dark"] .ka-action-card-deprecated {',
            $css,
            'ka-action-card-deprecated has no dark-theme rule; it would look live in dark mode'
        );
    }

    public function testSharedModalHouseRules(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/\stitle\s*=\s*["\']/i',
            self::code(self::EVMODAL),
            'the shared modal uses a native title= tooltip; use data-tip'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_.$])(confirm|alert|prompt)\(/',
            self::code(self::EVMODAL),
            'the shared modal calls a native dialog -- they freeze the automation harness'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\{\$[A-Za-z_]/',
            self::read(self::EVMODAL),
            'the shared modal uses Smarty-style {$var}; these templates are plain PHP'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\{(if|foreach|else)\s/',
            self::read(self::EVMODAL),
            'the shared modal uses Smarty-style {if}; these templates are plain PHP'
        );
        $this->assertStringNotContainsString(
            'Ork3::$Lib->',
            self::read(self::EVMODAL),
            'the shared modal calls Ork3::$Lib-> directly; templates read controller data'
        );
    }
}
