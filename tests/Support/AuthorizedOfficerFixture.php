<?php

declare(strict_types=1);

/**
 * Manufactures one Token-authorized officer (mundane + session + authorization
 * rows) for integration tests that exercise a Token-gated domain method.
 *
 * Extracted from EditAwardLadderTest::createAuthorizedOfficer() (Task 4) so every
 * test that needs a usable Token shares one implementation rather than re-deriving
 * it. ork_test ships with zero ork_mundane rows on this branch, so there is no
 * template row to clone from (the pattern used by EventPlanningFixture /
 * AttendanceFixture) -- every NOT NULL column is supplied explicitly instead.
 */
final class AuthorizedOfficerFixture
{
    private ?int $officerMundaneId = null;
    private ?string $token = null;

    /** @var list<int> plain (non-officer) ork_mundane rows seeded by seedRecipient() */
    private array $recipientMundaneIds = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $marker,
        private readonly int $kingdomId = 1,
    ) {
    }

    /**
     * Creates the officer (if not already created) and returns its Token.
     * role = 'create' (AUTH_CREATE) satisfies checkPermissionOrAuthority()'s
     * legacy HasAuthority() branch, scoped to kingdom_id, unconditionally.
     */
    public function createAuthorizedOfficer(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $token = md5($this->marker . bin2hex(random_bytes(8)));
        $username = strtolower($this->marker . '_' . substr($token, 0, 12));

        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES
                (:given_name, :surname, :other_name, :username, :persona, :email, 0, :kingdom_id,
                 :token, :waiver_ext, :password_expires, :password_salt, :xtoken, :reeve_qualified_until)'
        );
        $stmt->execute([
            ':given_name' => $this->marker,
            ':surname' => 'Officer',
            ':other_name' => '',
            ':username' => $username,
            ':persona' => $this->marker . ' Officer',
            ':email' => $username . '@example.test',
            ':kingdom_id' => $this->kingdomId,
            ':token' => $token,
            ':waiver_ext' => '',
            ':password_expires' => '2099-01-01 00:00:00',
            ':password_salt' => '',
            ':xtoken' => '',
            ':reeve_qualified_until' => '2000-01-01',
        ]);
        $this->officerMundaneId = (int) $this->pdo->lastInsertId();

        // NOW()/DATE_ADD() computed in SQL, not PHP date(): startup.php sets the
        // default timezone to America/Chicago, so a PHP-side date()/time() value
        // compared against the DB's (UTC) NOW() reads as already-expired.
        $this->pdo->prepare(
            'INSERT INTO ork_session (mundane_id, token, created, last_seen, expires)
             VALUES (:mundane_id, :token, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        )->execute([
            ':mundane_id' => $this->officerMundaneId,
            ':token' => $token,
        ]);

        $this->pdo->prepare(
            'INSERT INTO ork_authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             VALUES (:mundane_id, 0, :kingdom_id, 0, 0, \'create\')'
        )->execute([
            ':mundane_id' => $this->officerMundaneId,
            ':kingdom_id' => $this->kingdomId,
        ]);

        $this->token = $token;

        return $token;
    }

    /**
     * Grants the officer a direct park-scoped AUTH_CREATE row, in addition to the
     * kingdom-scoped one from createAuthorizedOfficer(). Needed by callers whose
     * authorization check is scoped to 'park' (e.g. Player::AddAward's
     * player.award.manage check), where the legacy HasAuthority() walk only
     * reaches the kingdom-scoped row via a real ork_park row's kingdom_id --
     * and ork_test ships with none. A direct park-scoped row short-circuits
     * that walk without needing an ork_park row to exist.
     */
    public function grantParkAuthority(int $parkId): void
    {
        $this->createAuthorizedOfficer();

        $this->pdo->prepare(
            'INSERT INTO ork_authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             VALUES (:mundane_id, :park_id, 0, 0, 0, \'create\')'
        )->execute([
            ':mundane_id' => $this->officerMundaneId,
            ':park_id' => $parkId,
        ]);
    }

    /**
     * Seeds one plain ork_mundane row (no session, no authorization) to receive
     * awards/recommendations, and returns its mundane_id.
     *
     * Same INSERT as createAuthorizedOfficer() above -- six test classes had
     * copy-pasted this ~30-line statement, so a new NOT NULL column on
     * ork_mundane meant editing six files in lockstep. cleanup() removes every
     * row seeded here.
     */
    public function seedRecipient(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES
                (:given_name, :surname, :other_name, :username, :persona, :email, 0, :kingdom_id,
                 :token, :waiver_ext, :password_expires, :password_salt, :xtoken, :reeve_qualified_until)'
        );
        $username = strtolower($this->marker . '_recipient_' . bin2hex(random_bytes(4)));
        $stmt->execute([
            ':given_name' => $this->marker,
            ':surname' => 'Recipient',
            ':other_name' => '',
            ':username' => $username,
            ':persona' => $this->marker . ' Recipient',
            ':email' => $username . '@example.test',
            ':kingdom_id' => $this->kingdomId,
            ':token' => md5($username),
            ':waiver_ext' => '',
            ':password_expires' => '2099-01-01 00:00:00',
            ':password_salt' => '',
            ':xtoken' => '',
            ':reeve_qualified_until' => '2000-01-01',
        ]);

        $recipientId = (int) $this->pdo->lastInsertId();
        $this->recipientMundaneIds[] = $recipientId;

        return $recipientId;
    }

    public function officerMundaneId(): int
    {
        $this->createAuthorizedOfficer();

        return $this->officerMundaneId;
    }

    public function cleanup(): void
    {
        foreach ($this->recipientMundaneIds as $recipientId) {
            $this->pdo->exec('DELETE FROM ork_recommendations WHERE mundane_id = ' . $recipientId);
            $this->pdo->exec('DELETE FROM ork_awards WHERE mundane_id = ' . $recipientId);
            $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id = ' . $recipientId);
        }
        $this->recipientMundaneIds = [];

        if ($this->officerMundaneId === null) {
            return;
        }

        $this->pdo->exec('DELETE FROM ork_session WHERE mundane_id = ' . $this->officerMundaneId);
        $this->pdo->exec('DELETE FROM ork_authorization WHERE mundane_id = ' . $this->officerMundaneId);
        $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id = ' . $this->officerMundaneId);

        $this->officerMundaneId = null;
        $this->token = null;
    }
}
