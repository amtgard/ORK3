<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once DIR_UI . 'controller/controller.Reports.php';

/**
 * Controller_Reports Population Explorer wire contract: the JSON shape, the
 * not-logged-in / expired-session guard, export HTTP codes and the same-origin
 * check. The actions themselves end in exit(), so the decisions they make are
 * private helpers exercised here directly (constructor skipped: it needs a full
 * request context).
 */
final class PopulationExplorerControllerTest extends TestCase
{
    private Controller_Reports $ctl;

    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->ctl = (new ReflectionClass(Controller_Reports::class))->newInstanceWithoutConstructor();
        $this->ctl->session = (object) ['user_id' => 1, 'token' => 'tok'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_HOST'] = 'ork.example:8080';
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_SEC_FETCH_SITE']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    private function call(string $method, ...$args)
    {
        $m = new ReflectionMethod(Controller_Reports::class, $method);

        return $m->invoke($this->ctl, ...$args);
    }

    public function testHelpersAreNotRoutable(): void
    {
        foreach (['_pe_preflight', '_pe_same_origin', '_pe_json_error', '_pe_export_failure'] as $m) {
            $this->assertTrue((new ReflectionMethod(Controller_Reports::class, $m))->isPrivate(), $m);
        }
    }

    public function testPreflightRequiresLoginAndPost(): void
    {
        $this->assertNull($this->call('_pe_preflight'));

        $this->ctl->session = (object) [];
        $this->assertSame([401, 5, 'Not logged in'], $this->call('_pe_preflight'));

        $this->ctl->session = (object) ['user_id' => 1, 'token' => 'tok'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertSame(405, $this->call('_pe_preflight')[0]);
    }

    public function testSameOriginCheck(): void
    {
        // Absent headers are allowed (older browsers, curl).
        $this->assertTrue($this->call('_pe_same_origin', $_SERVER));
        $this->assertTrue($this->call('_pe_same_origin', ['HTTP_HOST' => 'ork.example:8080', 'HTTP_ORIGIN' => 'http://ork.example:8080']));
        $this->assertTrue($this->call('_pe_same_origin', ['HTTP_HOST' => 'ORK.example', 'HTTP_ORIGIN' => 'https://ork.example', 'HTTP_SEC_FETCH_SITE' => 'same-origin']));
        $this->assertFalse($this->call('_pe_same_origin', ['HTTP_HOST' => 'ork.example:8080', 'HTTP_ORIGIN' => 'http://evil.example']));
        $this->assertFalse($this->call('_pe_same_origin', ['HTTP_HOST' => 'ork.example:8080', 'HTTP_ORIGIN' => 'http://ork.example:9999']));
        $this->assertFalse($this->call('_pe_same_origin', ['HTTP_HOST' => 'ork.example', 'HTTP_ORIGIN' => 'null']));
        $this->assertFalse($this->call('_pe_same_origin', ['HTTP_HOST' => 'ork.example', 'HTTP_SEC_FETCH_SITE' => 'cross-site']));

        $_SERVER['HTTP_ORIGIN'] = 'http://evil.example';
        $this->assertSame([403, 1, 'Cross-site request refused.'], $this->call('_pe_preflight'));
    }

    public function testJsonErrorShape(): void
    {
        // Expired / replaced session (status 2) gets the login prompt contract.
        $this->assertSame(['status' => 5, 'error' => 'Not logged in'], $this->call('_pe_json_error', ['Status' => BadToken()]));

        // Detail alone, no "You have set a parameter incorrectly.:" prefix; rule path kept.
        $this->assertSame(
            ['status' => 4, 'error' => 'Value must be a number', 'rule_path' => [1, 0]],
            $this->call('_pe_json_error', ['Status' => InvalidParameter('Value must be a number'), 'RulePath' => [1, 0]])
        );
        // No detail: the generic sentence.
        $this->assertSame(['status' => 5, 'error' => ServiceErrorMessages::NoAuthorization], $this->call('_pe_json_error', ['Status' => NoAuthorization()]));
        // Timeout is flagged.
        $this->assertSame(
            ['status' => 3, 'error' => PopulationExplorer::TIMEOUT_MESSAGE, 'timeout' => true],
            $this->call('_pe_json_error', ['Status' => ProcessingError(PopulationExplorer::TIMEOUT_MESSAGE), 'TimedOut' => true])
        );
    }

    public function testExportFailureCodes(): void
    {
        $this->assertSame(401, $this->call('_pe_export_failure', ['Status' => BadToken()])[0]);
        // Run / BuildExport never return NoAuthorization (AuthorizeScope only answers
        // InvalidParameter, BadToken or ProcessingError), so it is just an unknown failure.
        $this->assertSame(500, $this->call('_pe_export_failure', ['Status' => NoAuthorization()])[0]);
        $bad = $this->call('_pe_export_failure', ['Status' => InvalidParameter('Value must be a number'), 'RulePath' => [0]]);
        $this->assertSame(400, $bad[0]);
        $this->assertStringContainsString('Value must be a number', $bad[1]);
        $this->assertSame([503, PopulationExplorer::TIMEOUT_MESSAGE], $this->call('_pe_export_failure', ['Status' => ProcessingError(PopulationExplorer::TIMEOUT_MESSAGE), 'TimedOut' => true]));
        $this->assertSame(500, $this->call('_pe_export_failure', ['Status' => ProcessingError('x')])[0]);
        $this->assertSame(500, $this->call('_pe_export_failure', ['Status' => Success()])[0], 'success without a file is a failure');
    }

    /** Run the page action with a stubbed model; returns the template data. */
    private function page(array $query): array
    {
        $req = new Request('test');
        $req->Request = $query;
        $this->ctl->request = $req;
        $this->ctl->data = [];
        $this->ctl->Reports = new class () {
            public function population_authorize(string $token, string $type, int $id): ?array
            {
                return null;
            }

            public function population_registry(string $token, string $type, int $id): array
            {
                return ['criteria' => ['active' => []], 'columns' => [], 'options' => []];
            }

            public function population_decode_link(string $q, string $token, string $type, int $id): array
            {
                return PopulationExplorer::DecodeLink($q, ['class' => [], 'award' => [], 'peerage' => [], 'officer' => false]);
            }
        };
        $this->ctl->population_explorer();

        return $this->ctl->data;
    }

    public function testShareLinkIsReadFromPeNotQ(): void
    {
        // Analytics records any `q` URL parameter as a site search, so the filter travels in `pe`.
        $this->assertSame('pe', PopulationExplorer::LINK_PARAM);
        $link = PopulationExplorer::EncodeLink(['tree' => ['op' => 'AND', 'children' => [['c' => 'active', 'o' => 'is', 'v' => 'yes']]], 'columns' => ['persona']]);

        $d = $this->page(['KingdomId' => 1, 'pe' => $link]);
        $this->assertSame('pe', $d['pe_link_param']);
        $this->assertNull($d['pe_link_error']);
        $this->assertSame('active', $d['pe_initial']['tree']['children'][0]['c'] ?? null);

        $d = $this->page(['KingdomId' => 1, 'q' => $link]);
        $this->assertNull($d['pe_initial'], 'the old q parameter is not read');
        $this->assertNull($d['pe_link_error']);

        $d = $this->page(['KingdomId' => 1, 'pe' => 'not a link!']);
        $this->assertNull($d['pe_initial']);
        $this->assertNotEmpty($d['pe_link_error']);
    }
}
