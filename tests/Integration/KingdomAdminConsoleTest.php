<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Kingdom admin console -- the surface Admin::kingdom() and
 * Admin::resetwaivers() both render.
 *
 * Source-text assertions, the same shape as ParkAdminConsoleTest: the console is
 * a template plus inline page script with no PHP surface to call, so short of a
 * browser the only thing checkable is that the right data is assembled, the right
 * things are gated, and the shared partials are consumed rather than re-inlined.
 *
 * Everything pinned here is something the park console already got right and the
 * kingdom console did not, or something a plausible "tidy-up" would put back:
 *   - a second route that assembles the console's data by hand and misses half
 *     of it;
 *   - a destructive tile rendered to people the server will refuse;
 *   - a tile that is deliberately inert still wearing a clickable affordance.
 */
final class KingdomAdminConsoleTest extends TestCase
{
    private const PAGE       = 'orkui/template/revised-frontend/Admin_kingdom.tpl';
    private const MODALS     = 'orkui/template/revised-frontend/partials/_kingdom_admin_modals.tpl';
    private const EVMODAL    = 'orkui/template/revised-frontend/partials/_event_create_modal.tpl';
    private const CONTROLLER = 'orkui/controller/controller.Admin.php';
    private const CSS        = 'orkui/template/revised-frontend/style/admin-console.css';

    private static function read(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($path, $rel . ' is missing');
        return (string)file_get_contents($path);
    }

    /**
     * Source with COMMENTS REMOVED -- PHP block/line comments and HTML comments.
     * Several assertions below are "this file must not do X", and the files
     * explain at length what they deliberately do NOT do. Scanning raw text would
     * fail on the explanation rather than on the mistake.
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
        $out = preg_replace('/<!--.*?-->/s', '', $out);
        return (string)preg_replace('#/\*.*?\*/#s', '', $out);
    }

    /** The body of one method of controller.Admin.php, comments stripped. */
    private static function method(string $name): string
    {
        $src = self::code(self::CONTROLLER);
        $at  = strpos($src, 'public function ' . $name . '(');
        self::assertNotFalse($at, 'Admin::' . $name . '() not found');

        $open = strpos($src, '{', $at);
        self::assertNotFalse($open, 'Admin::' . $name . '() has no body');

        $depth = 0;
        for ($i = $open, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $open, $i - $open + 1);
                }
            }
        }
        self::fail('Admin::' . $name . '() body is unbalanced');
    }

    /* ─────────────── Fix 1: resetwaivers renders a WHOLE console ───────────── */

    /**
     * Admin::resetwaivers() must DELEGATE the render, never re-assemble it.
     *
     * It used to hand-roll a subset -- kingdom_route + get_kingdom_details +
     * get_park_summary + the auth flags -- and then point $this->template at the
     * revised console. That was right while the template was the small legacy
     * page. Once the route was re-pointed at revised-frontend/Admin_kingdom.tpl it
     * became a half-built page: AdminDashboard, ActiveParkCount, ActivePlayers,
     * TotalAttendance, AdminInfo, CanManageKingdom, IsOrkAdmin and every
     * qualification-test flag are set only by load_kingdom_admin_data(), which
     * this route never called. Every read is ??-guarded, so it rendered rather
     * than fataled -- silently, with a zeroed hero and five modals that open
     * blank. The park arm had the identical shape.
     *
     * Delegating leaves exactly one assembler per console, so the next key added
     * to either cannot be missed here.
     */
    public function testResetWaiversDelegatesTheRender(): void
    {
        $body = self::method('resetwaivers');

        self::assertStringContainsString(
            '$this->kingdom($id);',
            $body,
            'the kingdom arm must delegate to Admin::kingdom(), the one method that '
                . 'assembles that console'
        );
        self::assertStringContainsString(
            '$this->park($id);',
            $body,
            'the park arm must delegate to Admin::park(), the one method that '
                . 'assembles that console'
        );
    }

    /** Corollary: if it delegates, it must not also pick the template itself --
     *  that is the tell that a hand-rolled render has come back. */
    public function testResetWaiversDoesNotPickTheTemplateItself(): void
    {
        $body = self::method('resetwaivers');

        foreach (['Admin_kingdom.tpl', 'Admin_park.tpl'] as $tpl) {
            self::assertStringNotContainsString(
                $tpl,
                $body,
                'Admin::resetwaivers() assigns ' . $tpl . ' directly. The delegate owns '
                    . 'the template; assigning it here means the data assembly has been '
                    . 'duplicated again'
            );
        }
        self::assertStringNotContainsString(
            'get_kingdom_details',
            $body,
            'Admin::resetwaivers() is loading console data again instead of delegating'
        );
        self::assertStringNotContainsString(
            'get_park_summary',
            $body,
            'Admin::resetwaivers() is loading console data again instead of delegating'
        );
    }

    /**
     * The reset's outcome must survive the delegation.
     *
     * Both delegates splat get_kingdom_details() / get_park_info() straight into
     * $this->data, so setting Message before them risks a future payload key of
     * the same name eating the notice. Capture first, re-apply after.
     */
    public function testResetWaiversCarriesItsOutcomeThroughTheDelegate(): void
    {
        $body = self::method('resetwaivers');

        $delegate = strpos($body, '$this->kingdom($id);');
        $restore  = strpos($body, "\$this->data['Message'] = \$_notice;");

        self::assertNotFalse($restore, 'the reset outcome is not re-applied after the delegate');
        self::assertNotFalse($delegate);
        self::assertGreaterThan(
            $delegate,
            $restore,
            'Message must be re-applied AFTER the delegate runs, or the delegate can '
                . 'overwrite it and the officer sees no outcome at all'
        );
        self::assertStringContainsString(
            "\$this->data['Error'] = \$_noticeError;",
            $body,
            'the failure branch of the reset is not carried through the delegate'
        );
    }

    /** ...and the kingdom console must actually RENDER it. The park console has
     *  always had this block; the kingdom one did not, so a message carried
     *  through the delegate would still have been invisible. */
    public function testKingdomConsoleRendersTheOutcomeBanner(): void
    {
        $page = self::code(self::PAGE);

        self::assertStringContainsString(
            '<div class="success-message">',
            $page,
            'the kingdom console never renders $Message, so Admin::resetwaivers() '
                . 'reports its outcome to nobody'
        );
        self::assertStringContainsString(
            '<div class="error-message">',
            $page,
            'the kingdom console never renders $Error'
        );
    }

    /* ──────────────── Fix 2: the destructive tile is gated ─────────────────── */

    /**
     * Reset Waivers is destructive and kingdom-wide, and the server refuses it for
     * anyone without AUTH_KINGDOM/AUTH_EDIT (or global admin). The legacy page
     * gated its equivalent link on $CanResetWaivers; the rebuild dropped the gate,
     * so a viewer admitted by the front door on a qualification-test capability
     * alone was shown a red destructive tile the server would then refuse.
     *
     * set_admin_kingdom_auth_flags() already computes the correctly-scoped flag.
     */
    public function testResetWaiversTileIsGated(): void
    {
        $page = self::code(self::PAGE);

        self::assertMatchesRegularExpression(
            '/if \(!empty\(\$CanResetWaivers\)\).{0,400}?kaOpenModal\(\'ka-ops-overlay\'\)/s',
            $page,
            'the Reset Waivers tile is not gated on $CanResetWaivers'
        );
    }

    /** The kingdom flag is derived at kingdom scope. Sharing the park expression
     *  would authorize the wrong entity entirely. */
    public function testKingdomResetWaiversFlagIsKingdomScoped(): void
    {
        $src = self::code(self::CONTROLLER);

        self::assertStringContainsString(
            "\$this->data['CanResetWaivers'] = \$this->admin_can_reset_waivers(\$uid, 'kingdom', \$kingdomId);",
            $src,
            'the kingdom Reset Waivers flag is no longer derived at kingdom scope'
        );
    }

    /* ─────────── Fix 3: the console can schedule an event / see tournaments ─── */

    /**
     * The legacy kingdom console offered "Schedule an Event"; the rebuild offered
     * nothing, even though partials/_event_create_modal.tpl was written to be
     * shared and the park console already used it.
     */
    public function testScheduleEventTileOpensTheSharedModal(): void
    {
        $page = self::code(self::PAGE);

        self::assertMatchesRegularExpression(
            '/<button class="ka-action-card" onclick="pkOpenEventModal\(\)">/',
            $page,
            'the kingdom console has no Schedule an Event tile'
        );
        self::assertStringNotContainsString(
            'Admin/createevent',
            $page,
            'the tile must open the shared modal, not link to the legacy full-page form'
        );
        self::assertStringNotContainsString(
            'id="pk-event-modal"',
            $page,
            'the console inlines its own copy of the modal instead of including the partial'
        );
    }

    /** The tile is gated on the capability its own endpoint checks. EventAjax/create
     *  with ParkId 0 lands in Event::CreateEvent()'s kingdom branch, which requires
     *  park.event.create at KINGDOM scope with AUTH_CREATE. */
    public function testScheduleEventTileIsGatedOnItsEndpointsCapability(): void
    {
        self::assertMatchesRegularExpression(
            '/if \(!empty\(\$CanCreateEvent\)\).{0,900}?pkOpenEventModal\(\)/s',
            self::code(self::PAGE),
            'the Schedule an Event tile is not gated on $CanCreateEvent'
        );

        self::assertStringContainsString(
            "\$this->data['CanCreateEvent']   = \$uid > 0 && \$this->Authorization->has_permission_or_authority(\$uid, 'park.event.create', 'kingdom', (int)\$id, AUTH_CREATE);",
            self::code(self::CONTROLLER),
            'CanCreateEvent must be park.event.create at KINGDOM scope with AUTH_CREATE -- '
                . 'exactly what Event::CreateEvent() checks for a kingdom-level event'
        );
    }

    /** The console hosts the SAME partial the park console and park profile use,
     *  at kingdom scope (parkId 0). Re-inlining the markup here is how the three
     *  surfaces would drift. */
    public function testTheConsoleHostsTheSharedEventModalAtKingdomScope(): void
    {
        $modals = self::read(self::MODALS);

        self::assertStringContainsString(
            "include __DIR__ . '/_event_create_modal.tpl'",
            $modals,
            'the kingdom console does not include the shared create-event partial'
        );
        self::assertStringContainsString(
            '$evParkId    = 0;',
            $modals,
            'the kingdom console must include the partial with parkId 0, which is what '
                . 'makes EventAjax/create take its kingdom branch'
        );
        self::assertStringContainsString(
            '$evCanCreate = !empty($CanCreateEvent);',
            $modals,
            'the modal markup must be gated on the same flag as the tile that opens it'
        );
    }

    /**
     * The partial's park-only pieces must gate on the SAME thing their script
     * does. "Copy from past event" fetches a park-scoped source list, so its whole
     * script block returns early when EvCreateConfig.parkId is 0 -- leaving, if
     * the markup is not gated too, an expander whose onclick handler was never
     * defined. One click, one ReferenceError.
     */
    public function testCopyFromPastEventIsParkScopeOnly(): void
    {
        $src = self::read(self::EVMODAL);

        self::assertMatchesRegularExpression(
            '/<\?php if \(\$_evParkId > 0\) : \?>\s*\n\s*<div class="pk-cfe-wrap/',
            $src,
            'the copy-from-past-event markup is not gated on park scope, but the script '
                . 'that defines its handlers is (EvCreateConfig.parkId) -- clicking the '
                . 'expander at kingdom scope would throw'
        );
        self::assertStringContainsString(
            'EvCreateConfig.parkId) return;',
            $src,
            'the copy-from-past-event script no longer guards on parkId; the markup gate '
                . 'above is mirroring a guard that has moved'
        );
    }

    /** The "assigned to" hint must name whatever the event is actually assigned
     *  to. At kingdom scope $evParkName is empty, so reading it printed
     *  "assigned to <strong></strong>". */
    public function testAssignmentHintNamesTheActualScope(): void
    {
        $src = self::read(self::EVMODAL);

        self::assertStringContainsString(
            'This event will be assigned to <strong><?= htmlspecialchars($_evScopeName) ?></strong>',
            $src,
            'the assignment hint must read the scope name, not the park name -- at '
                . 'kingdom scope the park name is empty'
        );
        self::assertStringContainsString(
            '$_evScopeName = $_evParkId > 0 ? $_evParkName : (string)($evOrgName ?? \'\');',
            $src,
            '$_evScopeName is not derived from the include scope'
        );
    }

    /**
     * The retired tournament builder. The park console carries a deliberately
     * inert tile so its absence does not read as "the ORK lost tournaments"; the
     * kingdom console offered nothing at all, which is the worse silence -- the
     * legacy kingdom page DID list Create Tournament.
     */
    public function testTournamentTileIsPresentAndInert(): void
    {
        $page = self::code(self::PAGE);

        self::assertMatchesRegularExpression(
            '/<div class="ka-action-card ka-action-card-deprecated" aria-disabled="true"/',
            $page,
            'the kingdom console has no tournament tile, or it is not an inert <div>'
        );
        self::assertStringNotContainsString(
            'Tournament/create',
            $page,
            'the legacy tournament builder is GONE; the tile must not link to it'
        );
        self::assertStringContainsString(
            '<span class="ka-dep-chip">In development</span>',
            $page,
            'a greyed tile with no label reads as a rendering bug; keep the status chip'
        );
    }

    /* ───────── Fix 4: an inert tile must not wear a clickable affordance ────── */

    /**
     * .ka-action-card sets cursor:pointer and a blue hover, which is right for the
     * <button>/<a> tiles. The deprecated tile is a non-interactive <div>: a hand
     * cursor and a hover response on it are affordances that lie.
     *
     * The hover rules are not deleted, they are neutralised -- deleting them would
     * let the generic .ka-action-card:hover repaint the inert tile blue. Both
     * themes, or the tile reads as live in whichever one was missed.
     */
    public function testInertTileHasNoClickableAffordance(): void
    {
        $css = self::read(self::CSS);

        // Selector-GROUP tolerant: the declarations are shared with .ka-action-card-orkadmin
        // (one block for both inert tiles so they cannot drift apart), so the class no longer
        // appears as a lone `.ka-action-card-deprecated {`. The invariant under test is the
        // declaration, not the text shape -- match the class anywhere in the selector list.
        //
        // Light and dark are told apart by ANCHORING (/m + ^), not by a one-character
        // lookbehind: a group-tolerant `[^{}]` run happily spans a dark selector list, so
        // `(?<!\])` passed on `html[data-theme="dark"] .ka-...` too (the preceding character
        // is a space) and the light assertion silently asserted against the dark rule the
        // moment the light block was deleted. A light rule starts its own line with the bare
        // class; a dark one cannot. `[^{}]` (not `[^{]`) keeps a selector run from crossing
        // out of its own rule. Trade-off: indenting these rules (e.g. nesting them in an
        // @media block) breaks the ^ anchor -- but it fails loud here, never silently green.
        self::assertMatchesRegularExpression(
            '/^\.ka-action-card-deprecated\s*(?:,\s*[^{}]+?)?\{[^}]*cursor:\s*default/m',
            $css,
            'the inert tile still shows a pointer cursor inherited from .ka-action-card'
        );

        foreach (
            [
                '/^\.ka-action-card-deprecated:hover\s*(?:,\s*[^{}]+?)?\{([^}]*)\}/m'      => 'light',
                '/^html\[data-theme="dark"\] \.ka-action-card-deprecated:hover\s*(?:,\s*[^{}]+?)?\{([^}]*)\}/m' => 'dark',
            ] as $re => $theme
        ) {
            self::assertMatchesRegularExpression($re, $css, "no $theme hover rule for the inert tile");
            preg_match($re, $css, $m);
            self::assertStringContainsString(
                'box-shadow: none',
                $m[1],
                "the $theme hover rule must kill the lift .ka-action-card:hover applies"
            );
            self::assertStringNotContainsString(
                '#4299e1',
                $m[1],
                "the $theme hover rule reintroduces the live-card highlight"
            );
        }
    }

    /**
     * The CSS comment explaining the deprecated tile said the feature "still WORKS
     * and stays reachable" and that hover "deliberately keeps a border change so
     * it still reads as clickable". Both stopped being true when commit 28d23853
     * made the tile inert, and a stale comment beside a correct rule is how the
     * rule gets "fixed" back.
     */
    public function testTheDeprecatedTileCommentMatchesReality(): void
    {
        $css = self::read(self::CSS);

        self::assertStringNotContainsString(
            'still WORKS and stays reachable',
            $css,
            'the deprecated-tile comment still claims the feature is reachable; the tile '
                . 'has been inert since 28d23853'
        );
        self::assertStringNotContainsString(
            'still reads as clickable',
            $css,
            'the deprecated-tile comment still describes hover as a clickability signal'
        );
    }

    /* ───────────────────────── house rules ─────────────────────────────────── */

    /** @return array<string, array{0: string}> */
    public static function kingdomSurfaces(): array
    {
        return [
            'page'   => [self::PAGE],
            'modals' => [self::MODALS],
        ];
    }

    /** .tpl files here are plain PHP. Smarty syntax renders literally. */
    #[PHPUnit\Framework\Attributes\DataProvider('kingdomSurfaces')]
    public function testIsPlainPhpNotSmarty(string $rel): void
    {
        $src = self::read($rel);
        self::assertDoesNotMatchRegularExpression(
            '/\{\$[A-Za-z_]/',
            $src,
            $rel . ' uses Smarty-style {$var}; these templates are plain PHP'
        );
    }

    /** Native dialogs freeze the automation harness. */
    #[PHPUnit\Framework\Attributes\DataProvider('kingdomSurfaces')]
    public function testNoNativeDialogs(string $rel): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_.$])(confirm|alert|prompt)\(/',
            self::code($rel),
            $rel . ' calls a native dialog'
        );
    }

    /** Templates read controller data; they never reach into the domain. */
    #[PHPUnit\Framework\Attributes\DataProvider('kingdomSurfaces')]
    public function testNoDomainSingletonInTemplates(string $rel): void
    {
        self::assertStringNotContainsString(
            'Ork3::$Lib->',
            self::read($rel),
            $rel . ' calls Ork3::$Lib-> directly'
        );
    }
}
