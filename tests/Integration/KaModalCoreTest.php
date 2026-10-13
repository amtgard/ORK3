<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * The admin-console modal engine lives in exactly ONE place.
 *
 * partials/_ka_modal_core.tpl carries the generic half of what used to be the
 * top of partials/_kingdom_admin_modals.tpl: the overlay stack, the focus trap,
 * the dirty guard, the save-or-discard prompt and kaConfirm. The Park console
 * (Admin_park.tpl) drives the same engine, so a second copy of that logic is
 * the failure mode this file exists to prevent -- along with its cheaper
 * cousin, "the kingdom partial quietly grew its own kaOpenModal back".
 *
 * These are source-text assertions on purpose. The engine is inline page
 * script with no PHP surface to call, so the only thing that can be checked
 * without a browser is that the code is where it is supposed to be, and that
 * every modal the kingdom console renders is still registered with the engine.
 */
final class KaModalCoreTest extends TestCase
{
    private const CORE    = 'orkui/template/revised-frontend/partials/_ka_modal_core.tpl';
    private const KINGDOM = 'orkui/template/revised-frontend/partials/_kingdom_admin_modals.tpl';

    /** Overlays that are deliberately NOT registered with kaRegisterModal, and why.
     *  A modal only needs a registration when it wants reset-on-open, an open hook
     *  or a save hook; the rest open and close on the engine's defaults. Listing
     *  them explicitly is the point -- a NEW overlay that needs one but does not
     *  have one fails testEveryOverlayIsRegisteredOrListed() until someone decides
     *  which bucket it is in. */
    private const UNREGISTERED_OVERLAYS = [
        // Fields carry data-original stamps and are re-stamped after a save, so
        // the dirty guard reads the saved value as the baseline; no reset needed.
        'ka-details-overlay'    => 'plain form, data-original baseline',
        // Read-mostly pickers rebuilt from KaConfig every open by their own IIFE.
        'ka-parktitles-overlay' => 'rebuilt by its own open handler',
        'ka-editparks-overlay'  => 'rebuilt by its own open handler',
        'ka-claimpark-overlay'  => 'rebuilt by its own open handler',
        'ka-ops-overlay'        => 'no fields to reset -- action buttons only',
        'ka-prinz-overlay'      => 'rebuilt by its own open handler',
        // The engine's own confirm dialog. It is driven by kaConfirm(), never by
        // kaOpenModal(), so a registration would be meaningless.
        'ka-confirm-overlay'    => 'driven by kaConfirm, not kaOpenModal',
    ];

    private static function read(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($path, $rel . ' is missing');
        return (string)file_get_contents($path);
    }

    /** The shared partial exists and is a real script, not a stub. */
    public function testCorePartialExists(): void
    {
        $core = self::read(self::CORE);
        $this->assertStringContainsString('<script>', $core, 'core partial has no script block');
        $this->assertGreaterThan(
            200,
            substr_count($core, "\n"),
            'core partial is suspiciously short -- the engine should be ~330 lines'
        );
    }

    /** Plain PHP, never Smarty: `{$var}` / `{if}` render literally in this stack. */
    public function testCorePartialIsPlainPhp(): void
    {
        $core = self::read(self::CORE);
        $this->assertDoesNotMatchRegularExpression(
            '/\{\$[A-Za-z_]/',
            $core,
            'core partial uses Smarty-style {$var}; .tpl files here are plain PHP'
        );
    }

    /** No native dialogs anywhere in the engine -- they freeze the automation harness. */
    public function testCoreUsesNoNativeDialogs(): void
    {
        $core = self::read(self::CORE);
        // Lookbehind so kaConfirm( / kaCloseConfirm( do not read as the native call.
        $this->assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_.$])(confirm|alert|prompt)\(/',
            $core,
            'core partial calls a native dialog -- they freeze the harness; use kaConfirm'
        );
    }

    /** The four documented window exports (CONTRACT.md section B) plus the two
     *  the console markup calls straight from inline onclick. */
    public static function windowExportProvider(): array
    {
        return array_map(
            static fn ($n) => [$n],
            ['kaOpenModal', 'kaCloseModal', 'kaConfirm', 'kaRegisterModal']
        );
    }

    /** @dataProvider windowExportProvider */
    public function testCoreExportsOnWindow(string $name): void
    {
        $core = self::read(self::CORE);
        $this->assertMatchesRegularExpression(
            '/window\.' . $name . '\s*=/',
            $core,
            $name . ' is not exported on window by the core partial'
        );
    }

    /** The kingdom partial must CONSUME the engine, not redefine it. This is the
     *  assertion that fails the moment someone re-inlines the engine. */
    public static function engineInternalProvider(): array
    {
        return array_map(static fn ($n) => [$n], [
            'kaOpenModal', 'kaCloseModal', 'kaConfirm', 'kaCloseConfirm',
            'kaFeedback', 'kaClearFeedback', 'kaMarkClean', 'kaIsDirty',
            'kaSnapshot', 'kaResetFields', 'kaFocusFirst', 'kaFocusables',
            'kaRestoreFocus', 'kaOpenerNow', 'kaBoxOf', 'kaTop',
        ]);
    }

    /** @dataProvider engineInternalProvider */
    public function testKingdomDoesNotRedefineEngine(string $name): void
    {
        $kingdom = self::read(self::KINGDOM);
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+' . $name . '\s*\(/',
            $kingdom,
            $name . '() is defined in the kingdom partial again -- it belongs to '
                . 'the shared engine in _ka_modal_core.tpl'
        );
    }

    /** The old hardcoded registries are gone from BOTH files: they are what the
     *  registration API replaced, and a re-introduced literal would silently take
     *  precedence over nothing at all (the engine reads its own empty objects). */
    public function testHardcodedRegistriesAreGone(): void
    {
        foreach ([self::CORE, self::KINGDOM] as $rel) {
            $this->assertDoesNotMatchRegularExpression(
                '/var\s+KA_RESET_ON_OPEN\s*=\s*\{\s*\'/',
                self::read($rel),
                $rel . ' still carries a hardcoded KA_RESET_ON_OPEN literal'
            );
        }
        // The engine owns KA_MODAL_SAVE; consumers must go through kaRegisterModal().
        $this->assertDoesNotMatchRegularExpression(
            '/KA_MODAL_SAVE\s*\[/',
            self::read(self::KINGDOM),
            'kingdom partial writes KA_MODAL_SAVE directly -- register through kaRegisterModal()'
        );
    }

    /** The kingdom partial pulls the core in, exactly once, before its own script. */
    public function testKingdomIncludesTheCoreBeforeItsOwnScript(): void
    {
        $kingdom = self::read(self::KINGDOM);
        $this->assertSame(
            1,
            substr_count($kingdom, "include __DIR__ . '/_ka_modal_core.tpl';"),
            'kingdom partial must include the modal core exactly once'
        );
        $includeAt = strpos($kingdom, "_ka_modal_core.tpl");
        $usesAt    = strpos($kingdom, 'window.kaRegisterModal');
        $this->assertIsInt($includeAt);
        $this->assertIsInt($usesAt);
        $this->assertLessThan(
            $usesAt,
            $includeAt,
            'the core must be included BEFORE the console script that consumes it'
        );
    }

    /** The core guards against a double include -- two copies would double-bind
     *  every document-level listener (Escape would close two modals at once). */
    public function testCoreIsIncludeGuarded(): void
    {
        $core = self::read(self::CORE);
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*defined\s*\(\s*[\'"]KA_MODAL_CORE_RENDERED[\'"]/',
            $core,
            'core partial has no include guard'
        );
        $this->assertMatchesRegularExpression(
            '/define\s*\(\s*[\'"]KA_MODAL_CORE_RENDERED[\'"]/',
            $core,
            'core partial never sets its include guard'
        );
    }

    /** Every .ka-overlay the kingdom console renders is either registered with the
     *  engine or listed above as deliberately unregistered. */
    public function testEveryOverlayIsRegisteredOrListed(): void
    {
        $kingdom = self::read(self::KINGDOM);

        preg_match_all('/id="(ka-[a-z0-9-]+-overlay)"/', $kingdom, $m);
        $overlays = array_values(array_unique($m[1]));
        $this->assertNotEmpty($overlays, 'no ka-*-overlay ids found -- the scan is broken');

        preg_match_all('/kaRegisterModal\(\s*\'([^\']+)\'/', $kingdom, $r);
        $registered = array_values(array_unique($r[1]));
        // kaOnOpen() is the console's own one-line wrapper over kaRegisterModal.
        preg_match_all('/kaOnOpen\(\s*\'([^\']+)\'/', $kingdom, $o);
        $registered = array_unique(array_merge($registered, $o[1]));

        foreach ($overlays as $id) {
            // ka-mo-overlay is class ka-mo-overlay, not ka-overlay -- the Manage
            // Officers host is its own layer and never enters the ka stack.
            if ($id === 'ka-mo-overlay') {
                continue;
            }
            $this->assertTrue(
                in_array($id, $registered, true) || isset(self::UNREGISTERED_OVERLAYS[$id]),
                $id . ' is neither registered via kaRegisterModal() nor listed in '
                    . 'KaModalCoreTest::UNREGISTERED_OVERLAYS with a reason'
            );
        }

        // And the reverse: nothing may be registered for an overlay that no longer exists.
        foreach ($registered as $id) {
            $this->assertContains(
                $id,
                $overlays,
                $id . ' is registered with the modal engine but no such overlay is rendered'
            );
        }
    }

    /** The exact registrations the kingdom console had before the extraction,
     *  spelled out so a dropped or altered one fails loudly rather than showing up
     *  as "the Move Player submit button is enabled with nothing selected". */
    public static function kingdomRegistrationProvider(): array
    {
        return [
            'move player'   => ['ka-moveplayer-overlay',   "{ resetOnOpen: ['ka-mp-submit'] }"],
            'merge players' => ['ka-mergeplayer-overlay',  "{ resetOnOpen: ['ka-mgp-submit'] }"],
            'create player' => ['ka-createplayer-overlay', '{ resetOnOpen: true }'],
            'heraldry'      => ['ka-heraldry-overlay',     "{ resetOnOpen: ['ka-heraldry-upload'] }"],
            'configuration' => ['ka-config-overlay',       '{ resetOnOpen: true }'],
        ];
    }

    /** @dataProvider kingdomRegistrationProvider */
    public function testKingdomResetRegistrationsSurvived(string $id, string $opts): void
    {
        $kingdom = self::read(self::KINGDOM);
        $needle  = "kaRegisterModal('" . $id . "',";
        $this->assertStringContainsString($needle, $kingdom, $id . ' lost its registration');

        $at   = strpos($kingdom, $needle);
        $line = substr($kingdom, (int)$at, (int)strpos($kingdom, "\n", (int)$at) - (int)$at);
        $this->assertStringContainsString(
            $opts,
            $line,
            $id . ' registered with different options than it had before the extraction'
        );
    }

    /** Open hooks and the one bulk-save hook. */
    public function testKingdomHooksSurvived(): void
    {
        $kingdom = self::read(self::KINGDOM);
        foreach ([
            'ka-heraldry-overlay',
            'ka-awards-overlay',
            'ka-createplayer-overlay',
            'ka-mergeplayer-overlay',
        ] as $id) {
            $this->assertStringContainsString(
                "kaOnOpen('" . $id . "'",
                $kingdom,
                $id . ' lost its on-open hook'
            );
        }
        $this->assertStringContainsString(
            "kaRegisterModal('ka-awards-overlay', { save:",
            $kingdom,
            'Award Management lost the bulk save hook that makes the unsaved-changes '
                . 'prompt offer Save Changes instead of only Discard'
        );
    }

    /** The dirty check must keep ignoring the console's transient search boxes,
     *  or every modal with a filter reads as dirty the moment someone types in it. */
    public function testTransientFiltersStayOutOfTheDirtyCheck(): void
    {
        $kingdom = self::read(self::KINGDOM);
        $this->assertMatchesRegularExpression(
            '/kaIgnoreDirtyFields\(\[[^\]]*\'ka-alias-search\'[^\]]*\'ka-awards-search\''
                . '[^\]]*\'ka-parks-search\'[^\]]*\]\)/',
            $kingdom,
            'the three transient filter ids are no longer excluded from the dirty check'
        );
    }
}
