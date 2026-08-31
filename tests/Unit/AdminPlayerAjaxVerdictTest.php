<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Admin/player/{id}/{addaward,updateaward,...} must tell an AJAX caller the truth.
 *
 * That controller path renders the full admin HTML page with HTTP 200 on FAILURE,
 * and Controller::no_authorization() likewise only sets data['Error'] and returns
 * 200. Four fetch()es in revised.js -- the Kingdom, Player and Park Add Award
 * modals and the Player edit-award modal -- branched on resp.ok alone, so an
 * unauthorised officer, a Rule-1 rankless-ladder rejection and an invalid-month
 * rejection all rendered "Award added!" with nothing written to the database.
 *
 * The controller now emits {status, error} and exits before the render -- but ONLY
 * for a POST carrying an explicit Ajax=1 body field. That narrowness is the whole
 * safety argument: the same method serves real page loads and six plain
 * <form method=post> submits from Admin_player.tpl, and a controller that stops
 * rendering its page is far worse than a modal that over-reports success. Most of
 * this file exists to pin that detector down.
 */
final class AdminPlayerAjaxVerdictTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedPost = [];
    /** @var array<string, mixed> */
    private array $savedGet = [];
    private string $savedMethod = '';

    protected function setUp(): void
    {
        require_once DIR_UI . 'controller/controller.Admin.php';
        $this->savedPost = $_POST;
        $this->savedGet = $_GET;
        $this->savedMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        $_GET = $this->savedGet;
        $_SERVER['REQUEST_METHOD'] = $this->savedMethod;
    }

    /**
     * Drive the real detector. It reads only superglobals, so an instance without
     * a constructor (the constructor wants the global session/settings wiring) is
     * enough and keeps this a unit test.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $get
     */
    private function detect(string $method, array $post, array $get = []): bool
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST = $post;
        $_GET = $get;

        $ref = new ReflectionMethod(Controller_Admin::class, 'is_ajax_player_action');
        $ref->setAccessible(true);

        return (bool) $ref->invoke((new ReflectionClass(Controller_Admin::class))->newInstanceWithoutConstructor());
    }

    public function testTheDetectorIsPrivate(): void
    {
        $ref = new ReflectionMethod(Controller_Admin::class, 'is_ajax_player_action');
        $this->assertTrue($ref->isPrivate(), 'the AJAX switch must not be reachable as a route action');
    }

    public function testAPostCarryingAjaxOneIsDetected(): void
    {
        $this->assertTrue($this->detect('POST', ['Ajax' => '1', 'KingdomAwardId' => '7249']));
    }

    /** THE REGRESSION RISK. A browser navigation must always get its page. */
    public function testAPlainPageLoadIsNeverDetected(): void
    {
        $this->assertFalse($this->detect('GET', []), 'a bare GET page load');
        $this->assertFalse($this->detect('HEAD', []), 'a HEAD probe');
    }

    /**
     * $_REQUEST merges $_GET, so reading it would let a link -- or a crafted URL
     * someone pastes to an officer -- turn a page load into a JSON response.
     */
    public function testAjaxOnTheQueryStringIsIgnored(): void
    {
        $this->assertFalse($this->detect('GET', [], ['Ajax' => '1']), 'GET ?Ajax=1');
        $this->assertFalse($this->detect('POST', [], ['Ajax' => '1']), 'POST with ?Ajax=1 in the URL only');
    }

    /** The six plain <form method=post> submits in Admin_player.tpl send no Ajax field. */
    public function testAPlainFormPostIsNotDetected(): void
    {
        $this->assertFalse($this->detect('POST', ['KingdomAwardId' => '7249', 'GivenById' => '46193']));
    }

    public function testOnlyTheExactValueOneCounts(): void
    {
        $this->assertFalse($this->detect('POST', ['Ajax' => '0']));
        $this->assertFalse($this->detect('POST', ['Ajax' => '']));
        $this->assertFalse($this->detect('POST', ['Ajax' => 'yes']));
    }

    /**
     * Not keyed on X-Requested-With on purpose: jQuery sets that header on every
     * $.ajax()/$.post(), so keying on it would silently flip callers that still
     * expect HTML over to JSON.
     */
    public function testXRequestedWithAloneDoesNotTriggerIt(): void
    {
        $saved = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? null;
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        try {
            $this->assertFalse($this->detect('POST', ['KingdomAwardId' => '7249']));
        } finally {
            if ($saved === null) {
                unset($_SERVER['HTTP_X_REQUESTED_WITH']);
            } else {
                $_SERVER['HTTP_X_REQUESTED_WITH'] = $saved;
            }
        }
    }

    /** Every AJAX exit in Admin::player() must be behind the detector. */
    public function testEveryJsonExitInPlayerIsGuardedByTheDetector(): void
    {
        $source = file_get_contents(DIR_UI . 'controller/controller.Admin.php');
        $this->assertNotFalse($source);
        $start = strpos($source, 'public function player($id)');
        $this->assertNotFalse($start);
        $end = strpos($source, 'public function player_bak(', $start);
        $this->assertNotFalse($end);
        $body = substr($source, $start, $end - $start);

        $guards = substr_count($body, 'is_ajax_player_action()');
        $encodes = substr_count($body, 'json_encode(');
        $this->assertGreaterThan(0, $guards);
        $this->assertSame(
            $guards,
            substr_count($body, "header('Content-Type: application/json');"),
            'every JSON response in player() must sit inside an is_ajax_player_action() branch'
        );
        $this->assertGreaterThanOrEqual($guards, $encodes);
    }

    /**
     * Every handler that POSTs to Admin/player/... must go through the shared
     * helpers. A handler that quietly reverts to `if (resp.ok)` is the bug
     * coming back.
     *
     * Originally four (the award modals). Four more were converted later -- the
     * Player-profile image upload, Update Account, Add Dues and Edit
     * Qualifications modals -- plus the Revoke Dues row button, which also
     * posted here and reported success on every failure. Nine in total.
     */
    public function testEveryAdminPlayerSaveHandlerReadsTheServerVerdict(): void
    {
        $js = file_get_contents(DIR_UI . 'template/revised-frontend/script/revised.js');
        $this->assertNotFalse($js);

        // Eight take an existing FormData; Revoke Dues had no body at all and
        // builds one purely to carry the marker.
        $this->assertSame(
            8,
            substr_count($js, 'body: tnAwardSavePayload(fd)'),
            'every Admin/player fetch body must carry the Ajax=1 marker'
        );
        $this->assertSame(
            1,
            substr_count($js, 'body: tnAwardSavePayload(new FormData())'),
            'the bodyless Revoke Dues POST must still carry the Ajax=1 marker'
        );

        // The reader is used bare by the four award callers and with a
        // caller-specific fallback message by the five later ones.
        $this->assertSame(
            9,
            substr_count($js, '.then(tnReadAwardSaveResponse)')
                + substr_count($js, 'return tnReadAwardSaveResponse(resp,'),
            'every one must branch on the server verdict, not on resp.ok'
        );

        // The marker the server switches on is written in exactly one place.
        $this->assertSame(1, substr_count($js, "fd.append('Ajax', '1')"));
    }

    /**
     * The converted handlers must not fall back to a native dialog on failure:
     * alert()/confirm()/prompt() block the page and freeze the browser
     * automation harness. Scoped to the Player-profile handlers this change
     * touched -- other surfaces in this file still carry legacy alerts.
     */
    public function testTheRevokeDuesHandlerUsesTheHouseDialog(): void
    {
        $js = file_get_contents(DIR_UI . 'template/revised-frontend/script/revised.js');
        $this->assertNotFalse($js);

        $at = strpos($js, "'/revokedues/'");
        $this->assertNotFalse($at);
        // The handler and its two continuations; stop before the next IIFE.
        $handler = substr($js, $at - 900, 1800);

        $this->assertDoesNotMatchRegularExpression(
            '/(?<![\w.])alert\s*\(/',
            $handler,
            'revoke-dues failure must not raise a native dialog'
        );
        $this->assertStringContainsString('orkAlert(', $handler, 'it must use the house one-button dialog');
    }

    /**
     * The reader must treat a non-JSON 200 (a redirect chased to the login page,
     * a fatal, a stale script) as failure. Pinned as source, since the browser
     * half has no test harness here.
     */
    public function testTheResponseReaderFailsClosedOnNonJson(): void
    {
        $js = file_get_contents(DIR_UI . 'template/revised-frontend/script/revised.js');
        $this->assertNotFalse($js);
        $at = strpos($js, 'function tnReadAwardSaveResponse(');
        $this->assertNotFalse($at);
        $fn = substr($js, $at, 1200);

        $this->assertStringContainsString('ok: false', $fn, 'a body that is not JSON must resolve ok:false');
        $this->assertStringContainsString('parseInt(d.status, 10) === 0', $fn, 'only status 0 means the row landed');
    }
}
