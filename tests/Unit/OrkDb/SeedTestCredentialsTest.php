<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\SeedTestCredentials;
use OrkDb\Wiring;
use PDO;
use PHPUnit\Framework\TestCase;

final class SeedTestCredentialsTest extends TestCase
{
    public function testCredentialKeyMatchesAuthorizationNewStyle(): void
    {
        $salt = '2b2090950d6be61261cea0b925299244';
        $key = SeedTestCredentials::credentialKey('admin', 'password', $salt);

        // Known hash previously computed against Authorization::CryptStrip512 for this salt.
        $this->assertSame(
            'nbUnYp1z1jdL/YV/ezlwNkrceWLkcURLp3GhPIdt0gT2LhcjZCGvo1EptpkPHy7zR7P1z1',
            $key
        );
    }

    public function testRunSeedsMissingCredentialIdempotently(): void
    {
        $pdo = $this->makeSqlitePdo();
        $pdo->exec(
            "INSERT INTO ork_mundane (mundane_id, username, password_salt, password_expires)
             VALUES (1, 'admin', '2b2090950d6be61261cea0b925299244', '2020-01-01 00:00:00')"
        );

        $seed = new SeedTestCredentials(
            new Wiring(ORK3_ROOT . '/tools/ork-db'),
            static fn (): PDO => $pdo
        );

        $first = $seed->run(['target' => SeedTestCredentials::TARGET_MIRROR]);
        $this->assertSame(0, $first['exit_code'], implode("\n", $first['lines']));
        $this->assertStringContainsString('SET', implode("\n", $first['lines']));

        $count = (int) $pdo->query('SELECT COUNT(*) FROM ork_credential')->fetchColumn();
        $this->assertSame(1, $count);

        $second = $seed->run(['target' => SeedTestCredentials::TARGET_MIRROR]);
        $this->assertSame(0, $second['exit_code'], implode("\n", $second['lines']));
        $this->assertStringContainsString('unchanged', implode("\n", $second['lines']));
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM ork_credential')->fetchColumn());

        $exp = $pdo->query("SELECT password_expires FROM ork_mundane WHERE username='admin'")->fetchColumn();
        $this->assertSame(SeedTestCredentials::EXPIRATION, $exp);
    }

    public function testRunCreatesSaltWhenMissing(): void
    {
        $pdo = $this->makeSqlitePdo();
        $pdo->exec(
            "INSERT INTO ork_mundane (mundane_id, username, password_salt, password_expires)
             VALUES (9, 'admin', '', NULL)"
        );

        $seed = new SeedTestCredentials(
            new Wiring(ORK3_ROOT . '/tools/ork-db'),
            static fn (): PDO => $pdo
        );
        $result = $seed->run(['target' => SeedTestCredentials::TARGET_MIRROR]);

        $this->assertSame(0, $result['exit_code'], implode("\n", $result['lines']));
        $salt = (string) $pdo->query("SELECT password_salt FROM ork_mundane WHERE username='admin'")->fetchColumn();
        $this->assertNotSame('', $salt);
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM ork_credential')->fetchColumn());
    }

    private function makeSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // SQLite lacks NOW(); map to CURRENT_TIMESTAMP for the seed SQL.
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $pdo->exec(
            'CREATE TABLE ork_mundane (
                mundane_id INTEGER PRIMARY KEY,
                username TEXT,
                password_salt TEXT,
                password_expires TEXT
            );
            CREATE TABLE ork_credential (
                `key` TEXT PRIMARY KEY,
                expiration TEXT,
                resetrequest INTEGER
            );'
        );

        return $pdo;
    }
}
