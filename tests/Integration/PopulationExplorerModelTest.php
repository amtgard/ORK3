<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Model_Reports must route Population Explorer calls to the domain class.
 */
final class PopulationExplorerModelTest extends TestCase
{
    private ReportsFixture $fixture;

    private Model_Reports $model;

    private int $parkId;

    /** @var array{mundane_id:int,park_id:int,kingdom_id:int,token:string} */
    private array $admin;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = ReportsFixture::create();
        $this->model = new Model_Reports();
        $kid = $this->fixture->firstKingdomId();
        $this->parkId = $this->fixture->parkIdInKingdom($kid);
        $this->admin = $this->fixture->createPlayer($this->parkId, 'pe-mdl-admin');
        $this->fixture->insertGlobalAdmin($this->admin['mundane_id']);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testPopulationRunDelegatesToDomain(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->model->population_run([
            'Token' => $this->admin['token'],
            'ScopeType' => 'Park',
            'ScopeId' => $this->parkId,
            'Tree' => ['op' => 'AND', 'children' => []],
            'Columns' => ['persona'],
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertNotEmpty($r['Rows']);
        $this->assertSame('persona', $r['Columns'][0]['id']);
    }

    public function testPopulationAuthorizeAndRegistry(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertNull($this->model->population_authorize($this->admin['token'], 'Park', $this->parkId));
        $denied = $this->model->population_authorize('not-a-token', 'Park', $this->parkId);
        $this->assertNotNull($denied);
        $this->assertNotSame(0, $denied['Status']);

        $reg = $this->model->population_registry($this->admin['token'], 'Park', $this->parkId);
        $this->assertArrayHasKey('criteria', $reg);
        $this->assertArrayHasKey('options', $reg);
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertSame([], $this->model->population_registry('not-a-token', 'Park', $this->parkId));
    }

    public function testPopulationDecodeLinkAndNormalize(): void
    {
        $q = PopulationExplorer::EncodeLink(['tree' => ['op' => 'AND', 'children' => []], 'columns' => ['persona']]);
        unset($_SESSION['is_authorized_mundane_id']);
        $d = $this->model->population_decode_link($q, $this->admin['token'], 'Park', $this->parkId);
        $this->assertTrue($d['ok']);
        $this->assertFalse($this->model->population_decode_link('!!', $this->admin['token'], 'Park', $this->parkId)['ok']);
    }

    public function testRegistryDbFailureReturnsEmptyNotAPartialRegistry(): void
    {
        require_once __DIR__ . '/../Support/PopulationExplorerProbe.php';
        $probe = new PopulationExplorerProbe();
        $probe->failPattern = '/FROM ' . DB_PREFIX . 'class\\b/';
        $model = new Model_Reports();
        $model->PopulationExplorer = $probe;
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertSame([], $model->population_registry($this->admin['token'], 'Park', $this->parkId));
    }
}
