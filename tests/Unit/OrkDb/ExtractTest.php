<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Extract;
use OrkDb\Json5;
use OrkDb\ValidationException;
use OrkDb\Wiring;
use PDO;
use PHPUnit\Framework\TestCase;

final class ExtractTest extends TestCase
{
    private string $toolRoot;

    protected function setUp(): void
    {
        $this->toolRoot = sys_get_temp_dir() . '/ork-db-extract-' . uniqid('', true);
        mkdir($this->toolRoot . '/manifests', 0775, true);
        copy(
            ORK3_ROOT . '/tools/ork-db/manifests/wiring.json5',
            $this->toolRoot . '/manifests/wiring.json5'
        );
        copy(
            ORK3_ROOT . '/tools/ork-db/manifests/extract-sources.json5',
            $this->toolRoot . '/manifests/extract-sources.json5'
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->toolRoot);
    }

    public function testRunRejectsUnknownTableName(): void
    {
        $extract = new Extract(new Wiring($this->toolRoot), $this->toolRoot, fn (): PDO => $this->makeSqlitePdo());

        $this->expectException(ValidationException::class);
        $extract->run(['table' => 'not_allowed']);
    }

    public function testRunExtractsSingleVerbatimTable(): void
    {
        $pdo = $this->makeSqlitePdo();
        $pdo->exec(
            'CREATE TABLE ork_award (
                award_id INTEGER PRIMARY KEY,
                name TEXT,
                is_ladder INTEGER,
                is_title INTEGER,
                title_class INTEGER,
                peerage TEXT
            )'
        );
        $pdo->exec("INSERT INTO ork_award VALUES (1, 'Rose', 0, 0, 1, 'None')");

        $extract = new Extract(new Wiring($this->toolRoot), $this->toolRoot, fn (): PDO => $pdo);
        $result = $extract->run(['table' => 'award']);

        $this->assertCount(1, $result['files']);
        $this->assertStringContainsString('INSERT INTO `ork_award`', file_get_contents($result['files'][0]));
        $this->assertSame('127.0.0.1:19306/ork', $result['source']);
    }

    public function testRunFullExtractWritesCatalogAndManifestFiles(): void
    {
        $pdo = $this->makeSqlitePdo();
        foreach (['award', 'class', 'parktitle', 'pronoun', 'configuration'] as $table) {
            $pdo->exec("CREATE TABLE ork_{$table} (id INTEGER PRIMARY KEY, name TEXT)");
            $pdo->exec("INSERT INTO ork_{$table} VALUES (1, 'sample')");
        }
        $pdo->exec('CREATE TABLE ork_event (event_id INTEGER PRIMARY KEY, name TEXT, kingdom_id INTEGER, park_id INTEGER, mundane_id INTEGER, unit_id INTEGER, has_heraldry INTEGER, modified TEXT)');
        $pdo->exec("INSERT INTO ork_event VALUES (1, 'Spring War Demo', 1, 1, 1, 0, 0, '2026-01-01')");
        $pdo->exec(
            'CREATE TABLE ork_event_calendardetail (
                event_calendardetail_id INTEGER PRIMARY KEY,
                event_id INTEGER,
                current INTEGER,
                price REAL,
                description TEXT,
                url TEXT,
                url_name TEXT,
                address TEXT,
                province TEXT,
                postal_code TEXT,
                city TEXT,
                country TEXT,
                map_url TEXT,
                map_url_name TEXT,
                google_geocode TEXT,
                location TEXT
            )'
        );
        $pdo->exec("INSERT INTO ork_event_calendardetail VALUES (1, 1, 1, 0, 'desc', '', '', '', '', '', '', '', '', '', '', '')");
        $pdo->exec(
            'CREATE TABLE ork_mundane (
                mundane_id INTEGER PRIMARY KEY,
                given_name TEXT,
                surname TEXT,
                other_name TEXT,
                username TEXT,
                persona TEXT,
                email TEXT,
                park_id INTEGER,
                kingdom_id INTEGER,
                token TEXT,
                modified TEXT,
                restricted INTEGER,
                waivered INTEGER,
                waiver_ext TEXT,
                has_heraldry INTEGER,
                has_image INTEGER,
                company_id INTEGER,
                token_expires TEXT,
                password_expires TEXT,
                password_salt TEXT,
                xtoken TEXT,
                penalty_box INTEGER,
                active INTEGER
            )'
        );
        $pdo->exec(
            "INSERT INTO ork_mundane VALUES (
                1, 'Admin', 'User', '', 'admin', 'Admin', 'admin@test.local',
                0, 0, 'abc', '2026-01-01 00:00:00', 0, 0, '', 0, 0, 0,
                '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'salt', 'xt', 0, 1
            )"
        );

        $extract = new Extract(new Wiring($this->toolRoot), $this->toolRoot, fn (): PDO => $pdo);
        $result = $extract->run();

        $this->assertGreaterThanOrEqual(7, count($result['files']));
        $this->assertFileExists($this->toolRoot . '/extracted/manifest.json');
        $this->assertFileExists($this->toolRoot . '/extracted/configuration.sql');
    }

    public function testRunPlayersOnlyWritesMundaneRealJson(): void
    {
        $pdo = $this->makeSqlitePdo();
        $pdo->exec(
            'CREATE TABLE ork_mundane (
                mundane_id INTEGER PRIMARY KEY,
                given_name TEXT,
                surname TEXT,
                other_name TEXT,
                username TEXT,
                persona TEXT,
                email TEXT,
                park_id INTEGER,
                kingdom_id INTEGER,
                token TEXT,
                modified TEXT,
                restricted INTEGER,
                waivered INTEGER,
                waiver_ext TEXT,
                has_heraldry INTEGER,
                has_image INTEGER,
                company_id INTEGER,
                token_expires TEXT,
                password_expires TEXT,
                password_salt TEXT,
                xtoken TEXT,
                penalty_box INTEGER,
                active INTEGER
            )'
        );
        $pdo->exec(
            "INSERT INTO ork_mundane VALUES (
                1, 'Admin', 'User', '', 'admin', 'Admin', 'admin@test.local',
                0, 0, 'abc', '2026-01-01 00:00:00', 0, 0, '', 0, 0, 0,
                '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'salt', 'xt', 0, 1
            )"
        );
        $pdo->exec(
            'CREATE TABLE ork_credential (`key` TEXT PRIMARY KEY, expiration TEXT, resetrequest INTEGER)'
        );
        $pdo->exec("INSERT INTO ork_credential VALUES ('admin', '2026-01-01 00:00:00', 0)");
        $pdo->exec(
            'CREATE TABLE ork_authorization (
                authorization_id INTEGER PRIMARY KEY,
                mundane_id INTEGER,
                park_id INTEGER,
                kingdom_id INTEGER,
                event_id INTEGER,
                unit_id INTEGER,
                role TEXT,
                modified TEXT
            )'
        );
        $pdo->exec("INSERT INTO ork_authorization VALUES (1, 1, 0, 0, 0, 0, 'admin', '2026-01-01')");
        $pdo->exec('CREATE TABLE ork_mundane_design (mundane_id INTEGER PRIMARY KEY, about_persona TEXT)');
        $pdo->exec("INSERT INTO ork_mundane_design VALUES (1, 'About admin')");

        $extract = new Extract(new Wiring($this->toolRoot), $this->toolRoot, fn (): PDO => $pdo);
        $result = $extract->run(['players_only' => true]);

        $this->assertCount(1, $result['files']);
        $payload = json_decode((string) file_get_contents($result['files'][0]), true);
        $this->assertSame('admin', $payload['players'][0]['key']);
        $this->assertSame('admin', $payload['players'][0]['credential']['key']);
        $this->assertCount(1, $payload['players'][0]['authorization']);
        $this->assertSame('About admin', $payload['players'][0]['mundane_design']['about_persona']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testRunExtractsKingdomAwardCloneWhenConfigured(): void
    {
        $sources = Json5::decodeFile(ORK3_ROOT . '/tools/ork-db/manifests/extract-sources.json5');
        $sources['kingdomaward_clone_source_kingdom_id'] = 1;
        file_put_contents(
            $this->toolRoot . '/manifests/extract-sources.json5',
            json_encode($sources, JSON_PRETTY_PRINT) . "\n"
        );

        $pdo = $this->makeSqlitePdo();
        foreach (['award', 'class', 'parktitle', 'pronoun'] as $table) {
            $pdo->exec("CREATE TABLE ork_{$table} (id INTEGER PRIMARY KEY, name TEXT)");
            $pdo->exec("INSERT INTO ork_{$table} VALUES (1, 'sample')");
        }
        $pdo->exec('CREATE TABLE ork_configuration (configuration_id INTEGER PRIMARY KEY, name TEXT, `key` TEXT)');
        $pdo->exec("INSERT INTO ork_configuration VALUES (1, 'sample', 'sample')");
        $pdo->exec(
            'CREATE TABLE ork_kingdomaward (
                kingdomaward_id INTEGER PRIMARY KEY,
                kingdom_id INTEGER,
                award_id INTEGER,
                name TEXT
            )'
        );
        $pdo->exec('INSERT INTO ork_kingdomaward VALUES (1, 1, 10, "Custom")');
        $pdo->exec(
            'CREATE TABLE ork_mundane (
                mundane_id INTEGER PRIMARY KEY, given_name TEXT, surname TEXT, other_name TEXT,
                username TEXT, persona TEXT, email TEXT, park_id INTEGER, kingdom_id INTEGER,
                token TEXT, modified TEXT, restricted INTEGER, waivered INTEGER, waiver_ext TEXT,
                has_heraldry INTEGER, has_image INTEGER, company_id INTEGER, token_expires TEXT,
                password_expires TEXT, password_salt TEXT, xtoken TEXT, penalty_box INTEGER, active INTEGER
            )'
        );
        $pdo->exec(
            "INSERT INTO ork_mundane VALUES (
                1, 'Admin', 'User', '', 'admin', 'Admin', 'admin@test.local',
                0, 0, 'abc', '2026-01-01 00:00:00', 0, 0, '', 0, 0, 0,
                '2026-01-01 00:00:00', '2026-01-01 00:00:00', 'salt', 'xt', 0, 1
            )"
        );

        $extract = new Extract(new Wiring($this->toolRoot), $this->toolRoot, fn (): PDO => $pdo);
        $extract->run();

        $this->assertFileExists($this->toolRoot . '/extracted/kingdomaward.sql');
        $this->assertStringContainsString('ork_kingdomaward', file_get_contents($this->toolRoot . '/extracted/kingdomaward.sql'));
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table"
        );
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function testRunFailsWhenVerbatimTableMissing(): void
    {
        $pdo = $this->makeSqlitePdo();
        $extract = new Extract(new Wiring($this->toolRoot), $this->toolRoot, fn (): PDO => $pdo);

        $this->expectException(ValidationException::class);
        $extract->run(['table' => 'award']);
    }

    public function testRunRejectsInvalidMirrorCanaryMarker(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE _ork_canary_prod (id INTEGER PRIMARY KEY, marker TEXT, created_at TEXT);'
            . "INSERT INTO _ork_canary_prod VALUES (1, 'WRONG', '2026-01-01');"
        );

        $extract = new Extract(new Wiring($this->toolRoot), $this->toolRoot, fn (): PDO => $pdo);

        $this->expectException(ValidationException::class);
        $extract->run(['table' => 'award']);
    }

    public function testRunRejectsMissingMirrorCanary(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $extract = new Extract(new Wiring($this->toolRoot), $this->toolRoot, fn (): PDO => $pdo);

        $this->expectException(ValidationException::class);
        $extract->run(['table' => 'award']);
    }

    public function testCatalogSqlHashReturnsSha256Digest(): void
    {
        $pdo = $this->makeSqlitePdo();
        $pdo->exec(
            'CREATE TABLE ork_class (
                class_id INTEGER PRIMARY KEY,
                name TEXT
            )'
        );
        $pdo->exec("INSERT INTO ork_class VALUES (1, 'Warrior')");

        $extract = new Extract(new Wiring($this->toolRoot), $this->toolRoot, fn (): PDO => $pdo);
        $hash = $extract->catalogSqlHash($pdo, 'class');

        $this->assertSame($hash, $extract->catalogSqlHash($pdo, 'class'));
        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $hash);
    }

    private function makeSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE _ork_canary_prod (id INTEGER PRIMARY KEY, marker TEXT, created_at TEXT);'
            . "INSERT INTO _ork_canary_prod VALUES (1, 'ORK3_PROD_CANARY_v1', '2026-01-01');"
        );

        return $pdo;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeTree($full);
            } else {
                unlink($full);
            }
        }

        rmdir($path);
    }
}
