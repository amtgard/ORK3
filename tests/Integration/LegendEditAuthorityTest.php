<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Who may edit, move and merge a Legend. A kingdom officer or an ORK admin may
 * edit one; a park officer may not (but still edits a plain player); only an
 * ORK admin may move one; and a merge can never swallow a Legend. Nobody
 * changes a Legend's login fields or Active flag.
 */
final class LegendEditAuthorityTest extends TestCase
{
    private LegendFixture $fixture;
    private int $park = 0;
    private int $kingdom = 0;
    private int $admin = 0;
    private int $kingdomOfficer = 0;
    private int $parkOfficer = 0;
    private int $legend = 0;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        Legend::clearMemo();
        $this->fixture        = LegendFixture::create();
        $this->park           = $this->fixture->firstParkId();
        $this->kingdom        = $this->fixture->kingdomIdForPark($this->park);
        $this->admin          = $this->fixture->addOrkAdmin($this->park);
        $this->kingdomOfficer = $this->fixture->addKingdomOfficer($this->kingdom, $this->park, 'kofficer', AUTH_CREATE);
        $this->parkOfficer    = $this->fixture->addParkOfficer($this->park);
        $this->legend         = $this->fixture->addPlayer($this->park, 'legend', 1999)['mundane_id'];
        foreach (['admin' => $this->admin, 'king' => $this->kingdomOfficer, 'park' => $this->parkOfficer] as $k => $uid) {
            $this->tokens[$k] = $this->fixture->addSession($uid);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $pdo = $this->fixture->pdo();
            $pdo->exec('DELETE FROM ' . DB_PREFIX . 'awards WHERE mundane_id = ' . (int) $this->legend);
            $pdo->exec('DELETE FROM ' . DB_PREFIX . 'mundane_note WHERE mundane_id = ' . (int) $this->legend);
            $this->fixture->cleanup();
        }
    }

    private function plain(string $suffix = 'plain'): int
    {
        return $this->fixture->addPlayer($this->park, $suffix)['mundane_id'];
    }

    private function assertRefused(array $r, string $message = ''): void
    {
        $status = is_array($r['Status'] ?? null) ? $r['Status']['Status'] : ($r['Status'] ?? 0);
        $this->assertNotSame(0, $status, $message ?: 'the call must not report success');
        $this->assertSame(Legend::REFUSED, $r['Detail'] ?? $r['Error'] ?? null, $message);
    }

    private function persona(int $id): string
    {
        return (string) $this->fixture->value('mundane', 'persona', 'mundane_id = ' . $id);
    }

    /** A full UpdatePlayer request: null means "leave alone". */
    private function update(string $who, int $id, array $set): array
    {
        $keys = ['GivenName', 'Surname', 'OtherName', 'UserName', 'Persona', 'PronounId', 'PronounCustom', 'Email', 'Password',
            'Restricted', 'BasicFonts', 'DyslexiaFonts', 'Active', 'ParkMemberSince', 'Heraldry', 'DuesDate', 'DuesSemesters',
            'Waivered', 'ReeveQualified', 'ReeveQualifiedUntil', 'CorporaQualified', 'CorporaQualifiedUntil', 'AboutPersona',
            'AboutStory', 'NamePrefix', 'NameSuffix', 'PronunciationGuide', 'ColorPrimary', 'ColorAccent', 'ColorSecondary',
            'HeroGradient', 'HeroOverlay', 'SuffixComma', 'PhotoFocusX', 'PhotoFocusY', 'PhotoFocusSize', 'ShowBeltline',
            'ShowFeastPrefs', 'ShowMundaneFirst', 'ShowMundaneLast', 'ShowEmail', 'MilestoneConfig', 'NameFont', 'NameShadow',
            'BeltDisplay', 'RemoveDues'];
        $request = array_fill_keys($keys, null);
        unset($request['HeroGradient'], $request['NameShadow']);

        return ['Token' => $this->tokens[$who], 'MundaneId' => $id] + $set + $request;
    }

    private function player(): Player
    {
        return Ork3::$Lib->player;
    }

    // ---- Editing -----------------------------------------------------------

    public function test_park_officer_cannot_update_legend(): void
    {
        $before = $this->persona($this->legend);

        $r = $this->player()->UpdatePlayer($this->update('park', $this->legend, ['Persona' => 'Park Edit']));

        $this->assertRefused($r);
        $this->assertSame($before, $this->persona($this->legend));
    }

    public function test_kingdom_officer_can_update_legend(): void
    {
        $r = $this->player()->UpdatePlayer($this->update('king', $this->legend, ['Persona' => 'Kingdom Edit']));

        $this->assertSame(0, $r['Status'], json_encode($r));
        $this->assertSame('Kingdom Edit', $this->persona($this->legend));
    }

    public function test_ork_admin_can_update_legend(): void
    {
        $r = $this->player()->UpdatePlayer($this->update('admin', $this->legend, ['Persona' => 'Admin Edit']));

        $this->assertSame(0, $r['Status'], json_encode($r));
        $this->assertSame('Admin Edit', $this->persona($this->legend));
    }

    public function test_park_officer_still_edits_a_normal_player(): void
    {
        $plain = $this->plain();

        $r = $this->player()->UpdatePlayer($this->update('park', $plain, ['Persona' => 'Plain Edit']));

        $this->assertSame(0, $r['Status'], json_encode($r));
        $this->assertSame('Plain Edit', $this->persona($plain));
    }

    public function test_nobody_changes_legend_login_fields_or_active(): void
    {
        $plain = $this->plain('control');
        $row = $this->fixture->pdo()->query('SELECT username, email, active FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $this->legend)->fetch(PDO::FETCH_ASSOC);
        $control = $this->player()->UpdatePlayer($this->update('admin', $plain, ['UserName' => 'ctl_' . $plain, 'Email' => 'ctl@example.invalid']));
        $this->assertSame(0, $control['Status'], 'control: the same login-field change works for a plain player');

        foreach (['king', 'admin'] as $who) {
            foreach ([['UserName' => 'renamed_legend'], ['UserName' => ''], ['Email' => 'x@example.invalid'], ['Password' => 'NewPassw0rd!'], ['Active' => $row['active'] ? 0 : 1]] as $change) {
                $this->assertRefused($this->player()->UpdatePlayer($this->update($who, $this->legend, $change + ['Persona' => 'Edited Legend'])), "$who: " . json_encode($change));
            }
        }
        $after = $this->fixture->pdo()->query('SELECT username, email, active FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $this->legend)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($row, $after);
    }

    public function test_kingdom_officer_may_resubmit_unchanged_login_fields(): void
    {
        $row = $this->fixture->pdo()->query('SELECT username, email, active FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $this->legend)->fetch(PDO::FETCH_ASSOC);

        $r = $this->player()->UpdatePlayer($this->update('king', $this->legend, [
            'UserName' => $row['username'], 'Email' => $row['email'], 'Active' => (int) $row['active'], 'Persona' => 'Resubmitted',
        ]));

        $this->assertSame(0, $r['Status'], json_encode($r));
        $this->assertSame('Resubmitted', $this->persona($this->legend));
    }

    public function test_park_officer_cannot_change_image_heraldry_banner_notes_waiver_or_dues(): void
    {
        $pdo = $this->fixture->pdo();
        $pdo->exec('UPDATE ' . DB_PREFIX . 'mundane SET has_image = 1, has_heraldry = 1, has_banner = 1 WHERE mundane_id = ' . $this->legend);
        $noteId = $this->fixture->pdo()->exec('INSERT INTO ' . DB_PREFIX . "mundane_note (mundane_id, note, description, given_by, date, date_complete) VALUES ({$this->legend}, 'n', 'd', 'x', CURDATE(), CURDATE())");
        $noteId = (int) $pdo->lastInsertId();
        $duesId = $this->fixture->addDues($this->legend, $this->kingdom, $this->park);
        $base = ['Token' => $this->tokens['park'], 'MundaneId' => $this->legend];

        $this->assertRefused($this->player()->SetImage($base + ['Image' => '', 'ImageMimeType' => '']));
        $this->assertRefused($this->player()->RemoveImage($base));
        $this->assertRefused($this->player()->SetHeraldry($base + ['Heraldry' => '', 'HeraldryMimeType' => '']));
        $this->assertRefused($this->player()->RemoveHeraldry($base));
        $this->assertRefused($this->player()->SetWaiver($base + ['Waivered' => 1, 'Waiver' => '', 'WaiverMimeType' => '']));
        $this->assertRefused($this->player()->SetRestriction($base + ['Restricted' => 1]));
        $this->assertRefused($this->player()->AddNote($base + ['Note' => 'x', 'Description' => 'y', 'GivenBy' => 'z', 'Date' => '', 'DateComplete' => '']));
        $this->assertRefused($this->player()->EditNote($base + ['NotesId' => $noteId, 'Note' => 'x', 'Description' => 'y', 'Date' => '', 'DateComplete' => '']));
        $this->assertRefused($this->player()->RemoveNote($base + ['NotesId' => $noteId]));
        $this->assertRefused($this->player()->ClearNotes($base));
        $this->assertRefused($this->player()->RevokeDues($base + ['DuesId' => $duesId]));
        $this->assertRefused($this->player()->SetPlayerReconciledCredits($base + ['Reconcile' => []]));
        $this->assertRefused((new Banner())->RemoveBanner($base + ['Type' => 'Player', 'Id' => $this->legend]));
        $this->assertRefused(Ork3::$Lib->treasury->RemoveLastDuesPaid($base));

        $this->assertSame(1, $this->fixture->count('mundane_note', 'mundane_note_id = ' . $noteId), 'note intact');
        $this->assertSame(0, (int) $this->fixture->value('dues', 'revoked', 'dues_id = ' . $duesId), 'dues intact');
        $this->assertSame(1, (int) $this->fixture->value('mundane', 'has_banner', 'mundane_id = ' . $this->legend), 'banner intact');
        $this->assertSame(1, (int) $this->fixture->value('mundane', 'has_image', 'mundane_id = ' . $this->legend), 'image intact');
        $this->assertSame(1, (int) $this->fixture->value('mundane', 'has_heraldry', 'mundane_id = ' . $this->legend), 'heraldry intact');

        // Controls: a kingdom officer may do the same things, and a park officer may on a plain player.
        $plain = $this->plain('ctl');
        $this->assertSame(0, $this->player()->SetRestriction(['Token' => $this->tokens['park'], 'MundaneId' => $plain, 'Restricted' => 1])['Status']);
        $this->assertSame(0, $this->player()->SetRestriction(['Token' => $this->tokens['king']] + $base + ['Restricted' => 1])['Status']);
        $this->assertSame(0, $this->player()->EditNote(['Token' => $this->tokens['king']] + $base + ['NotesId' => $noteId, 'Note' => 'k', 'Description' => 'y', 'Date' => '', 'DateComplete' => ''])['Status']);
        $this->assertSame(0, $this->player()->RevokeDues(['Token' => $this->tokens['king']] + $base + ['DuesId' => $duesId])['Status']);
        $pdo->exec('DELETE FROM ' . DB_PREFIX . 'mundane_note WHERE mundane_note_id = ' . $noteId);
    }

    // ---- Awards ------------------------------------------------------------

    private function award(string $who, int $recipient): array
    {
        $ka = (int) $this->fixture->value('kingdomaward', 'kingdomaward_id', 'kingdom_id = ' . $this->kingdom . ' ORDER BY kingdomaward_id');

        return $this->player()->AddAward([
            'Token' => $this->tokens[$who], 'RecipientId' => $recipient, 'KingdomAwardId' => $ka, 'AwardId' => 0, 'Rank' => 1,
            'Date' => date('Y-m-d'), 'GivenById' => $this->admin, 'CustomName' => '', 'Note' => 'legend fixture',
            'ParkId' => $this->park, 'KingdomId' => 0, 'EventId' => 0,
        ]);
    }

    /** @return array<string, mixed> a SetAttendance or RemoveAttendance request by this officer */
    private function attendanceRow(string $who, int $attendanceId, int $owner, int $credits): array
    {
        return [
            'Token' => $this->tokens[$who], 'AttendanceId' => $attendanceId, 'MundaneId' => $owner,
            'ClassId' => (int) $this->fixture->value('attendance', 'class_id', 'attendance_id = ' . $attendanceId),
            'Date' => '2020-01-04', 'Credits' => $credits, 'ParkId' => $this->park, 'KingdomId' => $this->kingdom,
            'EventCalendarDetailId' => 0, 'EventId' => 0,
        ];
    }

    public function test_park_officer_cannot_edit_or_remove_legend_attendance(): void
    {
        $id = $this->fixture->addAttendance($this->park, $this->kingdom, $this->legend);
        $credits = $this->fixture->value('attendance', 'credits', 'attendance_id = ' . $id);

        $this->assertRefused((new Attendance())->SetAttendance($this->attendanceRow('park', $id, $this->legend, 3)));
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertRefused((new Attendance())->RemoveAttendance($this->attendanceRow('park', $id, $this->legend, 3)));

        $this->assertSame($credits, $this->fixture->value('attendance', 'credits', 'attendance_id = ' . $id));
        $this->assertSame(1, $this->fixture->count('attendance', 'attendance_id = ' . $id));
    }

    public function test_kingdom_officer_corrects_legend_attendance(): void
    {
        $id = $this->fixture->addAttendance($this->park, $this->kingdom, $this->legend);

        $set = (new Attendance())->SetAttendance($this->attendanceRow('king', $id, $this->legend, 3));
        $this->assertSame(0, $set['Status']['Status'] ?? $set['Status']);
        $this->assertSame(3, (int) $this->fixture->value('attendance', 'credits', 'attendance_id = ' . $id));

        unset($_SESSION['is_authorized_mundane_id']);
        $removed = (new Attendance())->RemoveAttendance($this->attendanceRow('king', $id, $this->legend, 3));
        $this->assertSame(0, $removed['Status']['Status'] ?? $removed['Status']);
        $this->assertSame(0, $this->fixture->count('attendance', 'attendance_id = ' . $id));
    }

    public function test_park_officer_still_edits_a_living_players_attendance(): void
    {
        $plain = $this->plain('att');
        $id = $this->fixture->addAttendance($this->park, $this->kingdom, $plain);

        $r = (new Attendance())->SetAttendance($this->attendanceRow('park', $id, $plain, 3));

        $this->assertSame(0, $r['Status']['Status'] ?? $r['Status']);
        unset($_SESSION['is_authorized_mundane_id']);
        $removed = (new Attendance())->RemoveAttendance($this->attendanceRow('park', $id, $plain, 3));
        $this->assertSame(0, $removed['Status']['Status'] ?? $removed['Status']);
    }

    public function test_park_officer_can_grant_new_award_to_legend(): void
    {
        $this->fixture->addAuthorization($this->parkOfficer, ['park_id' => $this->park], AUTH_CREATE);
        $r = $this->award('park', $this->legend);

        $this->assertSame(0, (int) $r['Status'], json_encode($r));
        $this->assertSame(1, $this->fixture->count('awards', 'mundane_id = ' . $this->legend));
    }

    public function test_park_officer_cannot_change_or_remove_legend_award(): void
    {
        $this->assertSame(0, (int) $this->award('king', $this->legend)['Status']);
        $awardId = (int) $this->fixture->value('awards', 'awards_id', 'mundane_id = ' . $this->legend);
        $base = ['Token' => $this->tokens['park'], 'AwardsId' => $awardId];

        $update = $this->player()->UpdateAward($base + ['Rank' => 5, 'Date' => date('Y-m-d'), 'GivenById' => $this->admin, 'Note' => 'changed', 'ParkId' => $this->park, 'KingdomId' => 0, 'EventId' => 0]);
        $this->assertRefused($update);
        $this->assertRefused($this->player()->ReconcileAward($base + ['KingdomAwardId' => 0, 'Rank' => 5, 'Date' => date('Y-m-d'), 'Note' => 'x', 'ParkId' => 0, 'KingdomId' => 0, 'EventId' => 0]));
        $this->assertRefused($this->player()->RevokeAward($base + ['Revocation' => 'no']));
        $this->assertRefused($this->player()->RevokeAllAwards(['Token' => $this->tokens['park'], 'MundaneId' => $this->legend, 'Revocation' => 'no']));
        $remove = $this->player()->RemoveAward($base);
        $this->assertRefused(is_array($remove) ? $remove : ['Status' => 0]);

        $this->assertSame(1, $this->fixture->count('awards', 'awards_id = ' . $awardId . " AND note = 'legend fixture' AND rank = 1 AND revoked = 0"), 'award untouched');

        // Control: the kingdom officer may change the same award.
        $ok = $this->player()->UpdateAward(['Token' => $this->tokens['king']] + $base + ['Rank' => 2, 'Date' => date('Y-m-d'), 'GivenById' => $this->admin, 'Note' => 'changed', 'ParkId' => $this->park, 'KingdomId' => 0, 'EventId' => 0]);
        $this->assertSame(0, (int) ($ok['Status']['Status'] ?? $ok['Status']), json_encode($ok));
    }

    // ---- Moving ------------------------------------------------------------

    private function otherPark(): int
    {
        return (int) $this->fixture->pdo()->query(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE active = 'Active' AND park_id <> {$this->park} ORDER BY park_id LIMIT 1"
        )->fetchColumn();
    }

    private function move(string $who, int $id, int $toPark): array
    {
        return $this->player()->MovePlayer(['Token' => $this->tokens[$who], 'MundaneId' => $id, 'ParkId' => $toPark, 'Waivered' => 0]);
    }

    public function test_move_refused_for_kingdom_and_park_officers(): void
    {
        $dest = $this->otherPark();
        $this->fixture->addAuthorization($this->parkOfficer, ['park_id' => $dest], AUTH_CREATE);
        $this->fixture->addAuthorization($this->parkOfficer, ['park_id' => $this->park], AUTH_CREATE);

        $plain = $this->plain('mover');
        $control = $this->move('king', $plain, $dest);
        $this->assertSame(0, $control['Status'], 'control: a kingdom officer moves a plain player');

        foreach (['king', 'park'] as $who) {
            $this->assertRefused($this->move($who, $this->legend, $dest), $who);
        }
        $this->assertSame($this->park, (int) $this->fixture->value('mundane', 'park_id', 'mundane_id = ' . $this->legend));
    }

    public function test_move_allowed_for_ork_admin(): void
    {
        $dest = $this->otherPark();

        $r = $this->move('admin', $this->legend, $dest);

        $this->assertSame(0, $r['Status'], json_encode($r));
        $this->assertSame($dest, (int) $this->fixture->value('mundane', 'park_id', 'mundane_id = ' . $this->legend));
    }

    // ---- Merging -----------------------------------------------------------

    private function merge(string $who, int $from, int $to): array
    {
        return $this->player()->MergePlayer(['Token' => $this->tokens[$who], 'FromMundaneId' => $from, 'ToMundaneId' => $to]);
    }

    public function test_merge_legend_with_living_refused(): void
    {
        $plainA = $this->plain('living_a');
        $plainB = $this->plain('living_b');
        $control = $this->merge('admin', $plainA, $plainB);
        $this->assertSame(0, $control['Status'], 'control: two living players merge');
        $living = $this->plain('living_c');
        $this->fixture->addMemory($this->legend, $living);

        $this->assertRefused($this->merge('admin', $this->legend, $living), 'legend into living');
        $this->assertRefused($this->merge('admin', $living, $this->legend), 'living into legend');

        $this->assertSame(1, $this->fixture->count('mundane', 'mundane_id = ' . $this->legend));
        $this->assertSame(1, $this->fixture->count('mundane', 'mundane_id = ' . $living));
        $this->assertSame(1, $this->fixture->count('legend_memory', 'mundane_id = ' . $this->legend));
    }

    public function test_merge_two_legends_keeps_survivor_year_and_rehomes_memories(): void
    {
        $survivor = $this->fixture->addPlayer($this->park, 'survivor', 2005)['mundane_id'];
        $author = $this->plain('author');
        $this->fixture->addMemory($this->legend, $author);
        $this->fixture->pdo()->exec('INSERT INTO ' . DB_PREFIX . "legend_log (mundane_id, action, legend_year, prior_active, cleared_json, actor_id, created) VALUES ({$this->legend}, 'convert', 1999, 1, '[]', {$this->admin}, NOW())");

        $r = $this->merge('king', $this->legend, $survivor);

        $this->assertSame(0, $r['Status'], json_encode($r));
        $this->assertSame(0, $this->fixture->count('mundane', 'mundane_id = ' . $this->legend));
        $this->assertSame(2005, (int) $this->fixture->value('mundane', 'legend_year', 'mundane_id = ' . $survivor));
        $this->assertSame(1, (int) $this->fixture->value('mundane', 'is_legend', 'mundane_id = ' . $survivor));
        $this->assertSame(1, $this->fixture->count('legend_memory', 'mundane_id = ' . $survivor));
        $this->assertSame(0, $this->fixture->count('legend_memory', 'mundane_id = ' . $this->legend));
        $this->assertSame(1, $this->fixture->count('legend_log', 'mundane_id = ' . $survivor));
        $this->assertSame(0, $this->fixture->count('legend_log', 'mundane_id = ' . $this->legend));
        $this->fixture->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'legend_log WHERE mundane_id = ' . $survivor);
    }

    public function test_park_officer_cannot_merge_a_legend(): void
    {
        $other = $this->fixture->addPlayer($this->park, 'other_legend', 2001)['mundane_id'];
        $plainA = $this->plain('pa');
        $plainB = $this->plain('pb');
        $control = $this->merge('park', $plainA, $plainB);
        $this->assertSame(0, $control['Status'], 'control: a park officer merges two living players');

        $this->assertRefused($this->merge('park', $this->legend, $other));

        $this->assertSame(2, $this->fixture->count('mundane', 'mundane_id IN (' . $this->legend . ',' . $other . ')'));
    }

    // ---- Edges -------------------------------------------------------------

    public function test_legend_with_park_id_zero_does_not_break_gate(): void
    {
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'mundane SET park_id = 0 WHERE mundane_id = ' . $this->legend);
        Legend::clearMemo();

        $this->assertTrue(Legend::blocksEdit($this->parkOfficer, $this->legend));
        $this->assertFalse(Legend::blocksEdit($this->kingdomOfficer, $this->legend));
        $this->assertFalse(Legend::blocksEdit($this->admin, $this->legend));
        $this->assertRefused($this->player()->UpdatePlayer($this->update('park', $this->legend, ['Persona' => 'Zero'])));
    }

    // ---- Fix round 1 -------------------------------------------------------

    public function test_active_status_report_call_refused_for_a_legend(): void
    {
        $this->fixture->addAuthorization($this->parkOfficer, ['park_id' => $this->park], AUTH_CREATE);
        $plain = $this->plain('act');
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id IN (' . $plain . ',' . $this->legend . ')');
        $report = new Report();

        $control = $report->SetPlayerActiveStatus(['Token' => $this->tokens['park'], 'MundaneId' => $plain, 'Active' => 1]);
        $this->assertSame(0, $control['Status'], 'control: a park officer reactivates a plain player');
        $this->assertSame(1, (int) $this->fixture->value('mundane', 'active', 'mundane_id = ' . $plain));

        foreach (['park', 'king', 'admin'] as $who) {
            $this->assertRefused($report->SetPlayerActiveStatus(['Token' => $this->tokens[$who], 'MundaneId' => $this->legend, 'Active' => 1]), $who);
        }
        $this->assertSame(0, (int) $this->fixture->value('mundane', 'active', 'mundane_id = ' . $this->legend));
    }

    public function test_reset_waivers_skips_legends(): void
    {
        $plain = $this->plain('waiver');
        $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'mundane SET waivered = 1 WHERE mundane_id IN (' . $plain . ',' . $this->legend . ')');

        $r = $this->player()->ResetWaivers(['Token' => $this->tokens['park'], 'ParkId' => $this->park]);

        $this->assertSame(0, $r['Status'], json_encode($r));
        $this->assertSame(0, (int) $this->fixture->value('mundane', 'waivered', 'mundane_id = ' . $plain), 'control: a plain player is reset');
        $this->assertSame(1, (int) $this->fixture->value('mundane', 'waivered', 'mundane_id = ' . $this->legend), 'the Legend keeps its waiver');
    }

    public function test_remove_last_dues_paid_refused_for_park_officer_but_not_kingdom_officer(): void
    {
        $base = ['MundaneId' => $this->legend];

        $this->assertRefused(Ork3::$Lib->treasury->RemoveLastDuesPaid(['Token' => $this->tokens['park']] + $base));
        $king = Ork3::$Lib->treasury->RemoveLastDuesPaid(['Token' => $this->tokens['king']] + $base);

        $this->assertNotSame(Legend::REFUSED, $king['Detail'] ?? null, 'a kingdom officer is not refused by the Legend gate');
    }

    public function test_undo_after_merging_two_legends_uses_the_survivors_own_snapshot(): void
    {
        $pdo = $this->fixture->pdo();
        $survivor = $this->plain('surv');
        $source = $this->plain('src');
        $pdo->exec('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id = ' . $survivor);
        $pdo->exec('UPDATE ' . DB_PREFIX . 'mundane SET active = 1 WHERE mundane_id = ' . $source);
        $this->assertSame(0, Legend::convert($this->admin, $survivor, 2001)['Status']);
        $this->assertSame(0, Legend::convert($this->admin, $source, 2002)['Status']);

        $r = $this->merge('admin', $source, $survivor);
        $this->assertSame(0, $r['Status'], json_encode($r));
        Legend::clearMemo();
        $undo = Legend::undo($this->admin, $survivor);

        $this->assertSame(0, $undo['Status'], json_encode($undo));
        $this->assertSame(0, (int) $this->fixture->value('mundane', 'active', 'mundane_id = ' . $survivor), "restored to the survivor's own prior state, not the source's");
        $pdo->exec('DELETE FROM ' . DB_PREFIX . 'legend_log WHERE mundane_id = ' . $survivor);
    }

    public function test_merge_recredits_memories_a_merged_away_living_player_wrote(): void
    {
        $gone = $this->plain('gone');
        $keeper = $this->plain('keeper');
        $memory = $this->fixture->addMemory($this->legend, $gone);

        $r = $this->merge('admin', $gone, $keeper);

        $this->assertSame(0, $r['Status'], json_encode($r));
        $this->assertSame($keeper, (int) $this->fixture->value('legend_memory', 'author_id', 'legend_memory_id = ' . $memory));
        $this->assertSame($this->legend, (int) $this->fixture->value('legend_memory', 'mundane_id', 'legend_memory_id = ' . $memory));
    }
}
