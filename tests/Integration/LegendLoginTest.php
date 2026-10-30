<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A Legend's account is closed: no login by password, token, app key or IdP
 * link, no reset mail, no claim code, no sign-in link. Each route is tried
 * against a real Legend and, where it matters, against the same player before
 * conversion so the refusal is the Legend flag and nothing else.
 */
final class LegendLoginTest extends TestCase
{
    private const PASSWORD = 'Sw0rd-and-Board';

    private LegendFixture $fixture;
    private Authorization $auth;
    private int $park = 0;
    private int $kingdom = 0;
    private int $officer = 0;
    /** @var list<int> application rows to remove */
    private array $appIds = [];
    /** @var list<string> idp user ids to remove */
    private array $idpIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        Legend::clearMemo();
        $this->fixture = LegendFixture::create();
        $this->auth    = new Authorization();
        $this->park    = $this->fixture->firstParkId();
        $this->kingdom = $this->fixture->kingdomIdForPark($this->park);
        $this->officer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        if (isset($this->fixture)) {
            $pdo = $this->fixture->pdo();
            foreach ($this->appIds as $id) {
                $pdo->exec('DELETE FROM ' . DB_PREFIX . 'application WHERE application_id = ' . $id);
            }
            foreach ($this->idpIds as $id) {
                $pdo->exec('DELETE FROM ' . DB_PREFIX . "idp_mailbox_challenge WHERE idp_user_id = '" . $id . "'");
                $pdo->exec('DELETE FROM ' . DB_PREFIX . "idp_auth WHERE idp_user_id = '" . $id . "'");
            }
            $this->fixture->cleanup();
        }
    }

    /** A player who can sign in with a password. */
    private function player(string $suffix = 'login'): int
    {
        $id = $this->fixture->addPlayer($this->park, $suffix)['mundane_id'];
        $username = 'legend_' . $suffix . '_' . $id;
        $salt     = md5('salt' . $id);
        $this->fixture->pdo()->prepare(
            'UPDATE ' . DB_PREFIX . 'mundane SET username = ?, password_salt = ?, email = ?, password_expires = ? WHERE mundane_id = ?'
        )->execute([$username, $salt, $username . '@example.test', date('Y-m-d H:i:s', time() + 86400 * 365), $id]);
        Authorization::SaltPassword($salt, strtoupper($username) . self::PASSWORD, time() + 86400 * 365);

        return $id;
    }

    private function username(int $id): string
    {
        return (string) $this->fixture->value('mundane', 'username', 'mundane_id = ' . $id);
    }

    private function convert(int $id): void
    {
        $r = Legend::convert($this->officer, $id, 2020);
        $this->assertSame(0, $r['Status'], json_encode($r));
        Legend::clearMemo();
    }

    // ---- Password and token sign-in --------------------------------------

    public function test_password_login_refused(): void
    {
        $id = $this->player();
        $request = ['UserName' => $this->username($id), 'Password' => self::PASSWORD, 'Token' => null];

        $before = $this->auth->Authorize_h($request);
        $this->assertSame(0, $before['Status']['Status'], 'control: a plain player signs in');

        $this->convert($id);
        $after = $this->auth->Authorize_h($request);

        $this->assertSame(5, $after['Status']['Status']);
        $this->assertArrayNotHasKey('Token', $after);
        $this->assertSame(0, $this->fixture->count('session', 'mundane_id = ' . $id));
    }

    public function test_token_reauth_refused(): void
    {
        $id    = $this->player();
        $token = $this->fixture->addSession($id);
        $this->convert($id);
        // purgeSessions already cleared it; put a live one back to prove the flag alone refuses.
        $live = $this->fixture->addSession($id);

        $after = $this->auth->Authorize_h(['UserName' => '', 'Password' => '', 'Token' => $live]);

        $this->assertSame(5, $after['Status']['Status']);
        $this->assertArrayNotHasKey('Token', $after);
        $this->assertNotSame($token, $live);
    }

    public function test_app_auth_refused(): void
    {
        $id = $this->player();
        $appKey = 'legendapp' . $id;
        $salt   = md5('appsalt' . $id);
        $pdo    = $this->fixture->pdo();
        $pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . "application (name, description, url, mundane_id, appid, app_salt, appid_expires)
             VALUES ('Legend test app', '', '', ?, ?, ?, NOW() + INTERVAL 1 DAY)"
        )->execute([$id, $appKey, $salt]);
        $appId = (int) $pdo->lastInsertId();
        $this->appIds[] = $appId;
        Authorization::SaltPassword($salt, $appKey . 'secret', time() + 86400 * 365);
        $authKey = md5('authkey' . $id);
        $pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . "application_auth (application_id, mundane_id, approved, appauthkey, token, token_expires)
             VALUES (?, ?, 'approved', ?, '', NOW())"
        )->execute([$appId, $id, $authKey]);
        $request = ['AppId' => $appKey, 'AppSecret' => 'secret', 'Token' => '', 'ApplicationAuthorizationKey' => $authKey];

        $control = $this->auth->Authorize_app($request);
        $this->assertSame(0, $control['Status']['Status'], 'control: a plain player gets a token minted');

        $this->convert($id);
        $after = $this->auth->Authorize_app($request);

        $this->assertSame(5, $after['Status']['Status']);
        $this->assertArrayNotHasKey('Token', $after);
        $this->assertSame(
            '',
            (string) $this->fixture->value('application_auth', 'token', 'mundane_id = ' . $id),
            'the blanked app token stays blank'
        );
    }

    public function test_is_authorized_returns_zero_for_a_live_session_after_convert(): void
    {
        $id    = $this->player();
        $token = $this->fixture->addSession($id);
        $this->assertSame($id, $this->auth->IsAuthorized_h($token), 'control: the session is live before conversion');

        $this->convert($id);
        $this->assertSame(0, $this->auth->IsAuthorized_h($token), 'a memoised answer must not outlive conversion');

        $again = $this->fixture->addSession($id);
        $this->assertSame(0, $this->auth->IsAuthorized_h($again), 'even a session that still validates is refused');
    }

    public function test_skip_listed_controller_session_ends_for_legend(): void
    {
        $id = $this->player();
        $session = new stdClass();
        $session->user_id = $id;
        $session->token   = $this->fixture->addSession($id);
        $_SESSION['is_authorized_mundane_id'] = $id;

        $this->assertFalse(Legend::endSessionIfLegend($session), 'control: a plain player keeps the session');
        $this->assertSame($id, $session->user_id);

        $this->convert($id);

        $this->assertTrue(Legend::endSessionIfLegend($session));
        $this->assertFalse(isset($session->user_id));
        $this->assertFalse(isset($session->token));
        $this->assertArrayNotHasKey('is_authorized_mundane_id', $_SESSION);
    }

    public function test_reset_password_sends_nothing(): void
    {
        $legend = $this->player('gone');
        $this->convert($legend);
        $saltBefore = $this->fixture->value('mundane', 'password_expires', 'mundane_id = ' . $legend);
        $resetsBefore = (int) $this->fixture->pdo()->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'credential WHERE resetrequest = 1')->fetchColumn();

        $r = $this->auth->ResetPassword(['UserName' => $this->username($legend), 'Email' => $this->username($legend) . '@example.test']);

        $this->assertSame(0, $r['Status'], 'it answers as a normal reset does, so it reveals nothing');
        $this->assertSame($saltBefore, $this->fixture->value('mundane', 'password_expires', 'mundane_id = ' . $legend));
        $this->assertSame(
            $resetsBefore,
            (int) $this->fixture->pdo()->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'credential WHERE resetrequest = 1')->fetchColumn(),
            'no temporary password was minted'
        );
    }

    // ---- IdP --------------------------------------------------------------

    public function test_idp_claim_and_link_refused(): void
    {
        $id = $this->player();
        $idp = 'legend-idp-' . $id;
        $this->idpIds[] = $idp;
        $code = 'ABCD2345';
        $this->fixture->pdo()->prepare(
            'INSERT INTO ' . DB_PREFIX . "idp_mailbox_challenge
             (id, purpose, idp_user_id, mundane_id, code_hash, sent_to_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW() + INTERVAL 1 HOUR, NOW())"
        )->execute([md5('challenge' . $id), 'claim_ork', $idp, $id, password_hash($code, PASSWORD_DEFAULT), hash('sha256', 'x')]);
        $claim = ['IdpUserId' => $idp, 'MundaneId' => $id, 'Code' => $code];
        $this->convert($id);

        $r = (new IdpIntegration())->ValidateClaimOrkCode($claim);

        $this->assertSame(5, $r['Status']['Status'] ?? $r['Status']);
        $this->assertNull(
            $this->fixture->pdo()->query('SELECT consumed_at FROM ' . DB_PREFIX . "idp_mailbox_challenge WHERE idp_user_id = '" . $idp . "'")->fetchColumn() ?: null,
            'the challenge stays unconsumed'
        );

        $link = new ReflectionMethod(Authorization::class, 'createIdpLink');
        $link->setAccessible(true);
        $auth = new Authorization();
        $auth->mundane->clear();
        $auth->mundane->mundane_id = $id;
        $this->assertTrue((bool) $auth->mundane->find());
        $quiet = ini_set('error_log', '/dev/null');
        $linked = $link->invoke($auth, ['IdpUserId' => $idp, 'AccessToken' => 'a', 'RefreshToken' => 'r', 'ExpiresAt' => time() + 3600]);
        ini_set('error_log', (string) $quiet);

        $this->assertSame(5, $linked['Status']['Status'] ?? $linked['Status']);
        $this->assertSame(0, $this->fixture->count('idp_auth', "idp_user_id = '" . $idp . "'"), 'no link row was written');
    }

    public function test_idp_claim_code_not_sent(): void
    {
        $id = $this->player();
        $idp = 'legend-idp-mail-' . $id;
        $this->idpIds[] = $idp;
        $this->convert($id);

        $send = new ReflectionMethod(IdpIntegration::class, 'mailClaimCodeForMundane');
        $send->setAccessible(true);
        $r = $send->invoke(new IdpIntegration(), $id, $idp);

        $this->assertSame(0, $r['Status'], 'answers as if it sent, so it reveals nothing');
        $this->assertSame(0, $this->fixture->count('idp_mailbox_challenge', "idp_user_id = '" . $idp . "'"), 'no claim code was minted');
    }

    // ---- Sign-in links ----------------------------------------------------

    public function test_sign_in_link_refused(): void
    {
        $id    = $this->player();
        $token = $this->fixture->addSession($id);
        $this->convert($id);
        $attendance = new Attendance();

        $use = $attendance->UseAttendanceLink(['Token' => $token, 'LinkToken' => str_repeat('a', 48)]);
        $upd = $attendance->UpdateSelfSigninClass(['Token' => $token, 'AttendanceId' => 1, 'ClassId' => 1]);

        $this->assertSame(5, $use['Status']);
        $this->assertSame(5, $upd['Status']);
    }

    // ---- Undo --------------------------------------------------------------

    public function test_undo_restores_login(): void
    {
        $id = $this->player();
        $request = ['UserName' => $this->username($id), 'Password' => self::PASSWORD, 'Token' => null];
        $this->convert($id);
        $this->assertSame(5, $this->auth->Authorize_h($request)['Status']['Status']);

        $r = Legend::undo($this->officer, $id);
        $this->assertSame(0, $r['Status'], json_encode($r));
        Legend::clearMemo();

        $this->assertSame(0, $this->auth->Authorize_h($request)['Status']['Status'], 'the password still works once the status is undone');
    }
}
