<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A Legend's profile: what each kind of viewer may do on it, and the memorial
 * card and Memory Wall as rendered, including hostile names and memories.
 */
final class LegendProfileTest extends TestCase
{
    private const BANNER_TAIL = ' passed beyond this realm in 2019. Their spirit lives on as a legend of Amtgard and they will be missed.';
    private const FORM_COPY   = 'Please remain respectful. This wall honors a member of our community and is read by friends and family. Inappropriate content is subject to removal.';

    private LegendFixture $fixture;
    private int $park = 0;
    private int $kingdom = 0;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        Legend::clearMemo();
        $this->fixture = LegendFixture::create();
        $this->park    = $this->fixture->firstParkId();
        $this->kingdom = $this->fixture->kingdomIdForPark($this->park);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    private function view(int $uid, int $mundaneId): array
    {
        return Legend::profileView($uid, $mundaneId, $this->park);
    }

    /** A park officer who can edit and also grant awards, as a sitting park officer can. */
    private function parkOfficerWhoGrants(): int
    {
        $officer = $this->fixture->addParkOfficer($this->park);
        $this->fixture->addAuthorization($officer, ['park_id' => $this->park], AUTH_CREATE);

        return $officer;
    }

    /** Render a template partial with these variables in scope, as include does from the page. */
    private function render(string $partial, array $vars): string
    {
        extract($vars);
        ob_start();
        include DIR_TEMPLATE . 'revised-frontend/' . $partial;

        return (string) ob_get_clean();
    }

    private function wallVars(array $overrides = []): array
    {
        return $overrides + [
            'Player'          => ['Persona' => 'Aldric', 'MundaneId' => 77],
            'Memories'        => [],
            'LoggedIn'        => true,
            'CanManageLegend' => false,
            'ViewerPersona'   => 'Wren',
        ];
    }

    public function test_profile_data_for_legend_viewer_roles(): void
    {
        $legend  = $this->fixture->addPlayer($this->park, 'legend', 2019)['mundane_id'];
        $regular = $this->fixture->addPlayer($this->park, 'regular')['mundane_id'];
        $officer = $this->parkOfficerWhoGrants();
        $monarch = $this->fixture->addKingdomOfficer($this->kingdom, $this->park, 'kofficer', AUTH_CREATE);
        $admin   = $this->fixture->addOrkAdmin($this->park);
        $this->fixture->addMemory($legend, $regular);

        //                                 manage view   edit   awards
        $expected = [
            'logged out'      => [0,        false, false, false, false],
            'regular player'  => [$regular, false, false, false, false],
            'park officer'    => [$officer, false, true,  false, false],
            'kingdom officer' => [$monarch, true,  true,  true,  true],
            'ORK admin'       => [$admin,   true,  true,  true,  true],
        ];
        foreach ($expected as $who => [$uid, $manage, $viewAdmin, $editAdmin, $editAwards]) {
            $view = $this->view($uid, $legend);
            $this->assertTrue($view['IsLegend'], $who);
            $this->assertSame($manage, $view['CanManageLegend'], $who . ' CanManageLegend');
            $this->assertSame($viewAdmin, $view['CanViewAdmin'], $who . ' CanViewAdmin');
            $this->assertSame($editAdmin, $view['CanEditAdmin'], $who . ' CanEditAdmin');
            $this->assertSame($editAwards, $view['CanEditAwards'], $who . ' CanEditAwards');
            $this->assertCount(1, $view['Memories'], $who);
        }
    }

    public function test_view_flags_equal_edit_flags_on_a_living_player(): void
    {
        $living  = $this->fixture->addPlayer($this->park, 'living')['mundane_id'];
        $officer = $this->parkOfficerWhoGrants();
        $regular = $this->fixture->addPlayer($this->park, 'regular')['mundane_id'];

        $forOfficer = $this->view($officer, $living);
        $this->assertFalse($forOfficer['IsLegend']);
        $this->assertTrue($forOfficer['CanEditAdmin']);
        $this->assertSame($forOfficer['CanEditAdmin'], $forOfficer['CanViewAdmin']);
        $this->assertTrue($forOfficer['CanEditAwards']);
        $this->assertSame([], $forOfficer['Memories']);

        $forRegular = $this->view($regular, $living);
        $this->assertFalse($forRegular['CanViewAdmin']);
        $this->assertFalse($forRegular['CanEditAdmin']);
        $this->assertFalse($forRegular['CanEditAwards']);
    }

    public function test_can_manage_legend_false_for_park_officer(): void
    {
        $legend  = $this->fixture->addPlayer($this->park, 'legend', 2019)['mundane_id'];
        $living  = $this->fixture->addPlayer($this->park, 'living')['mundane_id'];
        $officer = $this->fixture->addParkOfficer($this->park);

        $this->assertFalse($this->view($officer, $legend)['CanManageLegend']);
        $this->assertFalse($this->view($officer, $living)['CanManageLegend']);

        // The same officer with kingdom authority as well qualifies.
        $this->fixture->addAuthorization($officer, ['kingdom_id' => $this->kingdom]);
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertTrue($this->view($officer, $legend)['CanManageLegend']);
    }

    public function test_can_manage_legend_false_where_conversion_is_refused(): void
    {
        $monarch = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
        $admin   = $this->fixture->addOrkAdmin($this->park);

        $this->assertFalse($this->view($monarch, $monarch)['CanManageLegend'], 'nobody marks themselves');
        $this->assertFalse($this->view($admin, 1)['CanManageLegend'], 'player 1 is refused by the domain');
    }

    public function test_template_renders_exact_banner_copy_with_escaped_persona(): void
    {
        $persona = 'Aldric <script>alert("x")</script> O\'Neil & Sons';

        $html = $this->render('_legend_memorial.tpl', ['Player' => ['Persona' => $persona], 'LegendYear' => 2019]);

        $this->assertStringContainsString('In Memoriam', $html);
        $this->assertStringContainsString(htmlspecialchars($persona, ENT_QUOTES) . self::BANNER_TAIL, $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_wall_renders_hostile_memory_as_inert_text_with_line_breaks(): void
    {
        $body = "First line\nSecond <script>alert('x')</script> \"line\"";

        $html = $this->render('_legend_wall.tpl', $this->wallVars(['Memories' => [[
            'MemoryId'      => 5,
            'AuthorId'      => 9,
            'AuthorPersona' => '<img src=x onerror=alert(1)>',
            'Body'          => $body,
            'Created'       => '2026-10-12 23:30:00',
        ]]]));

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString(htmlspecialchars($body, ENT_QUOTES), $html);
        $this->assertStringContainsString('Remembered by', $html);
        $this->assertStringContainsString('Player/profile/9"', $html);
        $this->assertStringContainsString('October 12, 2026', $html, 'the date part only, with no timezone shift');
    }

    public function test_wall_form_for_logged_in_viewer_has_exact_copy(): void
    {
        $html = $this->render('_legend_wall.tpl', $this->wallVars());

        $this->assertStringContainsString(self::FORM_COPY, $html);
        $this->assertStringContainsString('Share a Memory', $html);
        $this->assertStringContainsString('Share a memory of Aldric', $html);
        $this->assertStringContainsString('Wren', $html);
        $this->assertStringContainsString('maxlength="2000"', $html);
    }

    public function test_wall_asks_a_logged_out_visitor_to_log_in(): void
    {
        $html = $this->render('_legend_wall.tpl', $this->wallVars(['LoggedIn' => false]));

        $this->assertStringContainsString('Login/login/Player/profile/77', $html);
        $this->assertStringNotContainsString('<textarea', $html);
        $this->assertStringNotContainsString(self::FORM_COPY, $html);
    }

    public function test_remove_control_only_for_can_manage_legend(): void
    {
        $memory = ['MemoryId' => 5, 'AuthorId' => 9, 'AuthorPersona' => 'Bram', 'Body' => 'The stew.', 'Created' => '2026-10-09 08:00:00'];

        $forViewer  = $this->render('_legend_wall.tpl', $this->wallVars(['Memories' => [$memory]]));
        $forOfficer = $this->render('_legend_wall.tpl', $this->wallVars(['Memories' => [$memory], 'CanManageLegend' => true]));

        $this->assertStringNotContainsString('pn-lg-remove', $forViewer);
        $this->assertStringContainsString('pn-lg-remove', $forOfficer);
    }

    public function test_memoryless_author_and_empty_wall_still_render(): void
    {
        $empty = $this->render('_legend_wall.tpl', $this->wallVars());
        $this->assertStringContainsString('No memories have been shared yet.', $empty);

        $orphan = $this->render('_legend_wall.tpl', $this->wallVars(['Memories' => [
            ['MemoryId' => 5, 'AuthorId' => 0, 'AuthorPersona' => '', 'Body' => 'Remembered.', 'Created' => '2026-10-09 08:00:00'],
        ]]));
        $this->assertStringNotContainsString('Player/profile/0', $orphan);
        $this->assertStringContainsString('Remembered by', $orphan);
    }
}
