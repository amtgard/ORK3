<?php

declare(strict_types=1);

/**
 * Org + survey fixture for the survey integration tests. Owns
 * every row it creates and removes them in tearDownFixture(), attendance and
 * generated events included.
 */
trait SurveyOrgFixture
{
    private PDO $pdo;

    /** @var array<string, list<int>> */
    private array $fx = ['survey' => [], 'mundane' => [], 'auth' => [], 'park' => [], 'kingdom' => []];

    private function setUpFixture(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function tearDownFixture(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        $p = DB_PREFIX;
        foreach ($this->fx['survey'] as $sid) {
            $sid = (int) $sid;
            foreach ($this->pdo->query("SELECT event_id FROM {$p}survey_credit WHERE survey_id = {$sid} AND event_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                $this->pdo->exec("DELETE FROM {$p}attendance WHERE event_id = " . (int) $eid);
                $this->pdo->exec("DELETE FROM {$p}event_calendardetail WHERE event_id = " . (int) $eid);
                $this->pdo->exec("DELETE FROM {$p}event WHERE event_id = " . (int) $eid);
            }
            $this->pdo->exec("DELETE FROM {$p}attendance WHERE note = 'Survey #{$sid}'");
            $this->pdo->exec("DELETE FROM {$p}survey_credit_grant WHERE survey_id = {$sid}");
            $this->pdo->exec("DELETE FROM {$p}survey_credit WHERE survey_id = {$sid}");
            $this->pdo->exec("DELETE a FROM {$p}survey_answer a JOIN {$p}survey_response r ON r.response_id = a.response_id WHERE r.survey_id = {$sid}");
            foreach (['survey_response', 'survey_participation', 'survey_start', 'survey_draft', 'survey_dismissal', 'survey_activity'] as $t) {
                $this->pdo->exec("DELETE FROM {$p}{$t} WHERE survey_id = {$sid}");
            }
            $this->pdo->exec("DELETE o FROM {$p}survey_option o JOIN {$p}survey_question q ON q.question_id = o.question_id WHERE q.survey_id = {$sid}");
            $this->pdo->exec("DELETE FROM {$p}survey_question WHERE survey_id = {$sid}");
            $this->pdo->exec("DELETE FROM {$p}survey_page WHERE survey_id = {$sid}");
            $this->pdo->exec("DELETE FROM {$p}survey WHERE survey_id = {$sid}");
        }
        // Generated credit events no config links any more (a regression's
        // orphans) would otherwise outlive the fixture kingdoms.
        foreach ($this->fx['kingdom'] as $kid) {
            foreach ($this->pdo->query("SELECT event_id FROM {$p}event WHERE kingdom_id = " . (int) $kid
                . " AND name LIKE 'Survey Credit - T11SHARE%'")->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                $this->pdo->exec("DELETE FROM {$p}attendance WHERE event_id = " . (int) $eid);
                $this->pdo->exec("DELETE FROM {$p}event_calendardetail WHERE event_id = " . (int) $eid);
                $this->pdo->exec("DELETE FROM {$p}event WHERE event_id = " . (int) $eid);
            }
        }
        foreach ($this->fx['auth'] as $id) {
            $this->pdo->exec("DELETE FROM {$p}authorization WHERE authorization_id = " . (int) $id);
        }
        foreach ($this->fx['mundane'] as $id) {
            $this->pdo->exec("DELETE FROM {$p}attendance WHERE mundane_id = " . (int) $id);
            $this->pdo->exec("DELETE FROM {$p}session WHERE mundane_id = " . (int) $id);
            $this->pdo->exec("DELETE FROM {$p}mundane WHERE mundane_id = " . (int) $id);
        }
        foreach ($this->fx['park'] as $id) {
            $this->pdo->exec("DELETE FROM {$p}park WHERE park_id = " . (int) $id);
        }
        foreach ($this->fx['kingdom'] as $id) {
            $this->pdo->exec("DELETE FROM {$p}kingdom WHERE kingdom_id = " . (int) $id);
        }
        $this->fx = ['survey' => [], 'mundane' => [], 'auth' => [], 'park' => [], 'kingdom' => []];
    }

    private function kingdom(string $suffix, int $parentId = 0): int
    {
        $st = $this->pdo->prepare('INSERT INTO ' . DB_PREFIX . 'kingdom (name, abbreviation, parent_kingdom_id, active) VALUES (?, ?, ?, \'Active\')');
        $st->execute(['T11SHARE ' . $suffix . ' ' . bin2hex(random_bytes(3)), strtoupper(substr(bin2hex(random_bytes(2)), 0, 3)), $parentId]);
        return $this->fx['kingdom'][] = (int) $this->pdo->lastInsertId();
    }

    private function park(int $kingdomId, string $suffix): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'park
             (kingdom_id, name, abbreviation, url, address, city, province, postal_code,
              google_geocode, latitude, longitude, location, map_url, description, directions, active)
             VALUES (?, ?, ?, \'\', \'\', \'\', \'\', \'\', \'\', 0, 0, \'\', \'\', \'\', \'\', \'Active\')'
        );
        $st->execute([$kingdomId, 'T11SHARE ' . $suffix . ' ' . bin2hex(random_bytes(3)), strtoupper(substr(bin2hex(random_bytes(2)), 0, 3))]);
        return $this->fx['park'][] = (int) $this->pdo->lastInsertId();
    }

    private function player(string $suffix, int $parkId, int $kingdomId): int
    {
        $token = md5('T11SHARE' . $suffix . bin2hex(random_bytes(8)));
        $user  = strtolower('t11share_' . $suffix . '_' . substr($token, 0, 8));
        $st = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'mundane
             (given_name, surname, other_name, username, persona, email, park_id, kingdom_id, token,
              waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until,
              penalty_box, active, suspended)
             VALUES (\'Test\', ?, \'\', ?, ?, ?, ?, ?, ?, \'\', NOW(), \'\', ?, \'0000-00-00\', 0, 1, 0)'
        );
        $st->execute([$suffix, $user, 'T11SHARE ' . $suffix, $user . '@example.test', $parkId, $kingdomId, $token, md5($token)]);
        return $this->fx['mundane'][] = (int) $this->pdo->lastInsertId();
    }

    private function officer(int $mundaneId, string $type, int $scopeId, string $role = AUTH_CREATE): void
    {
        $st = $this->pdo->prepare('INSERT INTO ' . DB_PREFIX . 'authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role) VALUES (?, ?, ?, 0, 0, ?)');
        $st->execute([$mundaneId, $type === AUTH_PARK ? $scopeId : 0, $type === AUTH_KINGDOM ? $scopeId : 0, $role]);
        $this->fx['auth'][] = (int) $this->pdo->lastInsertId();
    }

    /**
     * A survey with one optional single-choice question, opened. 'ork' surveys
     * are created as kingdom surveys and re-scoped with SQL (creating one for
     * real needs an ORK admin).
     */
    private function openSurvey(int $ownerUid, string $scopeType, int $scopeId, array $sqlSet = []): int
    {
        $s = new Survey();
        $s->setActor($ownerUid);
        // For 'ork', $scopeId is the kingdom the survey is created under before re-scoping.
        $r = $s->create($ownerUid, $scopeType === 'ork' ? 'kingdom' : $scopeType, $scopeId, 'T11SHARE survey');
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        $sid = $this->fx['survey'][] = (int) $r['SurveyId'];
        $page = (int) $this->pdo->query('SELECT page_id FROM ' . DB_PREFIX . 'survey_page WHERE survey_id = ' . $sid . ' LIMIT 1')->fetchColumn();
        $q = $s->questionAdd($sid, $page, 'single', null);
        $this->assertSame(0, $q['Status'], (string) ($q['Error'] ?? ''));
        $s->questionUpdate((int) $q['Question']['question_id'], ['Prompt' => 'T11SHARE pick one']);
        $this->assertSame(0, $s->setStatus($sid, 'open')['Status']);
        if ($scopeType === 'ork') {
            $sqlSet['scope_type'] = 'ork';
            $sqlSet['scope_id'] = 0;
        }
        foreach ($sqlSet as $col => $val) {
            $st = $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'survey SET ' . $col . ' = ? WHERE survey_id = ?');
            $st->execute([$val, $sid]);
        }
        return $sid;
    }

    /**
     * Submit an empty (all-optional) response with the given consent. $told:
     * the runner's data gate showed a credit line, as it does on every gated
     * survey (spec D1); false stands for a respondent who was never told.
     */
    private function answer(int $surveyId, int $uid, string $consent, bool $told = true): array
    {
        $r = (new SurveyResponse())->submit($surveyId, $uid, [], $consent, 30, false, $told);
        $this->assertSame(0, $r['Status'], (string) ($r['Error'] ?? ''));
        return $r;
    }

    private function row(int $surveyId): array
    {
        return (new Survey())->getRow($surveyId);
    }

    private function scalar(string $sql)
    {
        return $this->pdo->query($sql)->fetchColumn();
    }
}
