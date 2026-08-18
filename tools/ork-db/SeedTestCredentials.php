<?php

declare(strict_types=1);

namespace OrkDb;

use PDO;
use PDOException;

/**
 * Enforce known local-docker login passwords for fuzzy / Playwright / manual smoke.
 *
 * Hash algorithm matches system/lib/ork3/class.Authorization.php::CryptStrip512 +
 * Authorize_h new-style check: KeyExists(salt, UPPER(username) . password).
 *
 * Does NOT boot the ORK3 web runtime — PDO only against wiring.json5 endpoints.
 */
final class SeedTestCredentials
{
    public const TARGET_SANDBOX = 'sandbox';
    public const TARGET_MIRROR = 'mirror';
    public const TARGET_BOTH = 'both';

    public const EXPIRATION = '2030-01-01 00:00:00';

    /** Canonical accounts for each target (local docker only — never production). */
    private const ACCOUNTS = [
        self::TARGET_SANDBOX => [
            ['username' => 'megiddo', 'password' => 'test-db-player'],
            ['username' => 'admin', 'password' => 'password'],
        ],
        self::TARGET_MIRROR => [
            ['username' => 'admin', 'password' => 'password'],
        ],
    ];

    /** @var (callable(string): PDO)|null */
    private $pdoFactory;

    /**
     * @param (callable(string $target): PDO)|null $pdoFactory
     */
    public function __construct(
        private readonly Wiring $wiring,
        ?callable $pdoFactory = null,
    ) {
        $this->pdoFactory = $pdoFactory;
    }

    /**
     * Match Authorization::CryptStrip512 (public for unit tests).
     *
     * Note: glibc SHA-512 crypt truncates the salt token to 16 chars, so the returned
     * hash does not start with the full input salt string. Authorization substr-slices
     * at strlen('$6$rounds=5000$'.$salt.'$') anyway — we must keep that exact behavior
     * or login keys will not match production/mirror credential rows.
     */
    public static function cryptStrip512(string $string, string $salt): string
    {
        $fullSalt = '$6$rounds=5000$' . $salt . '$';
        $hash = crypt($fullSalt . $string, $fullSalt);
        if (!is_string($hash) || $hash === '' || str_starts_with($hash, '*')) {
            throw new ValidationException('crypt() failed for credential seed (need SHA-512 crypt support)');
        }

        return substr($hash, strlen($fullSalt));
    }

    /**
     * Credential row key used by Authorization::KeyExists for a username/password pair.
     */
    public static function credentialKey(string $username, string $password, string $salt): string
    {
        $material = trim($salt) . strtoupper(trim($username)) . trim($password);

        return self::cryptStrip512($material, $salt);
    }

    /**
     * @param array{target?: string} $options
     * @return array{lines: list<string>, exit_code: int}
     */
    public function run(array $options = []): array
    {
        $target = $options['target'] ?? self::TARGET_BOTH;
        if (!in_array($target, [self::TARGET_SANDBOX, self::TARGET_MIRROR, self::TARGET_BOTH], true)) {
            return [
                'lines' => ["Seed credentials: unknown target '{$target}' (use sandbox|mirror|both)"],
                'exit_code' => 2,
            ];
        }

        $lines = [];
        $exit = 0;
        $targets = $target === self::TARGET_BOTH
            ? [self::TARGET_SANDBOX, self::TARGET_MIRROR]
            : [$target];

        foreach ($targets as $t) {
            $result = $this->seedTarget($t);
            foreach ($result['lines'] as $line) {
                $lines[] = $line;
            }
            if ($result['exit_code'] !== 0) {
                $exit = $result['exit_code'];
            }
        }

        return ['lines' => $lines, 'exit_code' => $exit];
    }

    /**
     * @return array{lines: list<string>, exit_code: int}
     */
    private function seedTarget(string $target): array
    {
        $lines = [];
        $label = $target === self::TARGET_SANDBOX
            ? $this->wiring->sandboxTargetLabel()
            : $this->wiring->mirrorTargetLabel();

        try {
            $pdo = $this->connect($target);
        } catch (ValidationException | PDOException $e) {
            $lines[] = "Seed credentials: FAIL {$target} ({$label}) — " . $e->getMessage();

            return ['lines' => $lines, 'exit_code' => 2];
        }

        $accounts = self::ACCOUNTS[$target];
        foreach ($accounts as $account) {
            $username = $account['username'];
            $password = $account['password'];
            try {
                $outcome = $this->seedAccount($pdo, $username, $password);
                $lines[] = sprintf(
                    'Seed credentials: %s %s@%s → %s/%s (%s)',
                    $outcome === 'unchanged' ? 'OK' : 'SET',
                    $target,
                    $label,
                    $username,
                    $password,
                    $outcome
                );
            } catch (ValidationException $e) {
                $lines[] = sprintf(
                    'Seed credentials: FAIL %s@%s %s — %s',
                    $target,
                    $label,
                    $username,
                    $e->getMessage()
                );

                return ['lines' => $lines, 'exit_code' => 2];
            }
        }

        return ['lines' => $lines, 'exit_code' => 0];
    }

    private function seedAccount(PDO $pdo, string $username, string $password): string
    {
        $stmt = $pdo->prepare(
            'SELECT mundane_id, password_salt FROM ork_mundane WHERE username = :u LIMIT 1'
        );
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ValidationException("mundane row missing for username '{$username}'");
        }

        $mundaneId = (int) $row['mundane_id'];
        $salt = trim((string) ($row['password_salt'] ?? ''));
        $saltCreated = false;
        if ($salt === '') {
            $salt = md5('ork3-test-seed:' . $username . ':' . $mundaneId);
            $upd = $pdo->prepare(
                'UPDATE ork_mundane SET password_salt = :salt, password_expires = :exp WHERE mundane_id = :id'
            );
            $upd->execute([
                'salt' => $salt,
                'exp' => self::EXPIRATION,
                'id' => $mundaneId,
            ]);
            $saltCreated = true;
        } else {
            $upd = $pdo->prepare(
                'UPDATE ork_mundane SET password_expires = :exp WHERE mundane_id = :id'
            );
            $upd->execute([
                'exp' => self::EXPIRATION,
                'id' => $mundaneId,
            ]);
        }

        $key = self::credentialKey($username, $password, $salt);

        $exists = $pdo->prepare(
            'SELECT 1 FROM ork_credential WHERE `key` = :k AND expiration > NOW() LIMIT 1'
        );
        $exists->execute(['k' => $key]);
        if ($exists->fetchColumn() !== false && !$saltCreated) {
            return 'unchanged';
        }

        // Remove same-key stubs (expired or duplicate) then insert fresh.
        $del = $pdo->prepare('DELETE FROM ork_credential WHERE `key` = :k');
        $del->execute(['k' => $key]);

        $ins = $pdo->prepare(
            'INSERT INTO ork_credential (`key`, expiration, resetrequest) VALUES (:k, :e, 0)'
        );
        $ins->execute(['k' => $key, 'e' => self::EXPIRATION]);

        return $saltCreated ? 'salt+credential' : 'credential';
    }

    private function connect(string $target): PDO
    {
        if ($this->pdoFactory !== null) {
            return ($this->pdoFactory)($target);
        }

        if ($target === self::TARGET_SANDBOX) {
            $cfg = $this->wiring->sandbox();
            $this->wiring->assertSandboxEndpoint(
                (string) $cfg['host'],
                (int) $cfg['port'],
                (string) $cfg['database']
            );
            $dsn = $this->wiring->sandboxDsn();
        } else {
            $cfg = $this->wiring->mirror();
            $this->wiring->assertMirrorEndpoint(
                (string) $cfg['host'],
                (int) $cfg['port'],
                (string) $cfg['database']
            );
            $dsn = $this->wiring->mirrorDsn();
        }

        $creds = $this->wiring->credentials();

        return new PDO($dsn, $creds['user'], $creds['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
