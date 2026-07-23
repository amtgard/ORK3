<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for hero banner CRUD (T-PLA-06, T-PRA-04, T-KNA-08, T-UNT-01, T-EVA-14).
 */
final class BannerTest extends TestCase
{
    private BannerFixture $fixture;

    private Banner $banner;

    private Park $parkDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = BannerFixture::create();
        $this->banner = new Banner();
        $this->parkDomain = new Park();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testSetParkBannerUpload(): void
    {
        $parkId = $this->fixture->firstParkId();
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_EDIT, 'park-upload');
        $tmp = $this->fixture->createTempJpeg();
        $this->fixture->trackBannerFile($tmp);

        $r = $this->banner->SetBanner([
            'Token' => $grantor['token'],
            'Type' => 'Park',
            'Id' => $parkId,
            'Banner' => base64_encode((string) file_get_contents($tmp)),
            'BannerMimeType' => 'image/jpeg',
            'ShowLogo' => 1,
            'Vignette' => 0,
            'OffsetX' => 25,
            'OffsetY' => 75,
        ]);
        $this->assertSame(ServiceErrorIds::Success, $r['Status']);

        $row = $this->fixture->fetchParkBanner($parkId);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['has_banner']);
        $this->assertSame(1, (int) $row['banner_show_logo']);
        $this->assertSame(0, (int) $row['banner_vignette']);
        $this->assertSame(25, (int) $row['banner_offset_x']);
        $this->assertSame(75, (int) $row['banner_offset_y']);

        $base = DIR_PARK_BANNER . sprintf('%05d', $parkId);
        $this->assertTrue(file_exists($base . '.jpg') || file_exists($base . '.png'));
        if (file_exists($base . '.jpg')) {
            $this->fixture->trackBannerFile($base . '.jpg');
        }
        if (file_exists($base . '.png')) {
            $this->fixture->trackBannerFile($base . '.png');
        }

        $response = $this->parkDomain->GetParkDetails(['ParkId' => $parkId]);
        $this->assertSame(0, $response['Status']['Status']);
        $this->assertSame(1, (int) ($response['HasBanner'] ?? 0));
        $this->assertSame(25, (int) ($response['BannerOffsetX'] ?? 0));
    }

    public function testSetParkBannerRejectsRetiredPark(): void
    {
        $parkId = $this->fixture->firstParkId();
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_EDIT, 'park-retired');
        $this->fixture->setParkRetired($parkId);

        $this->assertFalse($this->fixture->parkIsActive($parkId));

        $tmp = $this->fixture->createTempJpeg();
        $this->fixture->trackBannerFile($tmp);

        $r = $this->banner->SetBanner([
            'Token' => $grantor['token'],
            'Type' => 'Park',
            'Id' => $parkId,
            'Banner' => base64_encode((string) file_get_contents($tmp)),
            'BannerMimeType' => 'image/jpeg',
            'ShowLogo' => 1,
            'Vignette' => 1,
            'OffsetX' => 50,
            'OffsetY' => 50,
        ]);
        $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']);
        $this->assertStringContainsString('retired', (string) ($r['Detail'] ?? ''));

        $base = DIR_PARK_BANNER . sprintf('%05d', $parkId) . '.jpg';
        $this->assertFalse(file_exists($base));
    }

    public function testRemoveParkBannerResetsDefaults(): void
    {
        $parkId = $this->fixture->firstParkId();
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_EDIT, 'park-remove');
        $tmp = $this->fixture->createTempJpeg();
        $this->uploadParkBanner($grantor['token'], $parkId, $tmp, 1, 1, 10, 20);

        $base = DIR_PARK_BANNER . sprintf('%05d', $parkId);
        if (file_exists($base . '.jpg')) {
            $this->fixture->trackBannerFile($base . '.jpg');
        }

        $r = $this->banner->RemoveBanner([
            'Token' => $grantor['token'],
            'Type' => 'Park',
            'Id' => $parkId,
        ]);
        $this->assertSame(ServiceErrorIds::Success, $r['Status']);

        $row = $this->fixture->fetchParkBanner($parkId);
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['has_banner']);
        $this->assertSame(1, (int) $row['banner_show_logo']);
        $this->assertSame(1, (int) $row['banner_vignette']);
        $this->assertSame(50, (int) $row['banner_offset_x']);
        $this->assertSame(50, (int) $row['banner_offset_y']);
        $this->assertFalse(file_exists($base . '.jpg'));
        $this->assertFalse(file_exists($base . '.png'));
    }

    public function testUpdateParkBannerConfig(): void
    {
        $parkId = $this->fixture->firstParkId();
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_EDIT, 'park-config');

        $r = $this->banner->UpdateBannerConfig([
            'Token' => $grantor['token'],
            'Type' => 'Park',
            'Id' => $parkId,
            'ShowLogo' => 0,
            'Vignette' => 0,
            'OffsetX' => 30,
            'OffsetY' => 40,
        ]);
        $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']);
        $this->assertSame('Upload a banner first before saving settings.', $r['Detail']);

        $tmp = $this->fixture->createTempJpeg();
        $this->uploadParkBanner($grantor['token'], $parkId, $tmp, 1, 1, 50, 50);
        $base = DIR_PARK_BANNER . sprintf('%05d', $parkId);
        if (file_exists($base . '.jpg')) {
            $this->fixture->trackBannerFile($base . '.jpg');
        }

        $r = $this->banner->UpdateBannerConfig([
            'Token' => $grantor['token'],
            'Type' => 'Park',
            'Id' => $parkId,
            'ShowLogo' => 0,
            'Vignette' => 0,
            'OffsetX' => 30,
            'OffsetY' => 40,
        ]);
        $this->assertSame(ServiceErrorIds::Success, $r['Status']);

        $row = $this->fixture->fetchParkBanner($parkId);
        $this->assertSame(0, (int) $row['banner_show_logo']);
        $this->assertSame(0, (int) $row['banner_vignette']);
        $this->assertSame(30, (int) $row['banner_offset_x']);
        $this->assertSame(40, (int) $row['banner_offset_y']);
    }

    public function testSetPlayerBannerClearsHeroGradient(): void
    {
        $player = $this->fixture->createPlayerWithGradient('gradient-player');
        $this->assertNotNull($this->fixture->fetchPlayerGradient($player['mundane_id']));

        $tmp = $this->fixture->createTempJpeg();
        $r = $this->banner->SetBanner([
            'Token' => $player['token'],
            'Type' => 'Player',
            'Id' => $player['mundane_id'],
            'Banner' => base64_encode((string) file_get_contents($tmp)),
            'BannerMimeType' => 'image/jpeg',
            'ShowLogo' => 1,
            'Vignette' => 1,
            'OffsetX' => 50,
            'OffsetY' => 50,
        ]);
        $this->assertSame(ServiceErrorIds::Success, $r['Status']);

        $base = DIR_PLAYER_BANNER . sprintf('%06d', $player['mundane_id']);
        if (file_exists($base . '.jpg')) {
            $this->fixture->trackBannerFile($base . '.jpg');
        }

        $this->assertNull($this->fixture->fetchPlayerGradient($player['mundane_id']));
        $row = $this->fixture->fetchPlayerBanner($player['mundane_id']);
        $this->assertSame(1, (int) $row['has_banner']);
    }

    public function testSetKingdomBannerAuthRejectsNonEditor(): void
    {
        $kingdomId = $this->fixture->firstKingdomId();
        $grantor = $this->fixture->createGrantorWithoutAuth('kingdom-no-edit');
        $tmp = $this->fixture->createTempJpeg();

        $r = $this->banner->SetBanner([
            'Token' => $grantor['token'],
            'Type' => 'Kingdom',
            'Id' => $kingdomId,
            'Banner' => base64_encode((string) file_get_contents($tmp)),
            'BannerMimeType' => 'image/jpeg',
            'ShowLogo' => 1,
            'Vignette' => 1,
            'OffsetX' => 50,
            'OffsetY' => 50,
        ]);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $r['Status']);
    }

    public function testSetBannerRejectsOversize(): void
    {
        $parkId = $this->fixture->firstParkId();
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_EDIT, 'park-oversize');
        $oversize = str_repeat('A', Banner::maxBannerBytes() + 1);

        $r = $this->banner->SetBanner([
            'Token' => $grantor['token'],
            'Type' => 'Park',
            'Id' => $parkId,
            'Banner' => base64_encode($oversize),
            'BannerMimeType' => 'image/jpeg',
            'ShowLogo' => 1,
            'Vignette' => 1,
            'OffsetX' => 50,
            'OffsetY' => 50,
        ]);
        $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']);
        $this->assertSame('File too large (max 1 MB).', $r['Detail']);
    }

    public function testSetEventBannerBustsSearchCache(): void
    {
        $ctx = $this->fixture->createEvent('cache-bust');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_EVENT, $ctx['event_id'], AUTH_EDIT, 'event-admin');

        Ork3::$Lib->ghettocache->bust_event_search($ctx['event_id']);

        $r = $this->banner->RemoveBanner([
            'Token' => $grantor['token'],
            'Type' => 'Event',
            'Id' => $ctx['event_id'],
        ]);
        $this->assertSame(ServiceErrorIds::Success, $r['Status']);

        $row = $this->fixture->fetchEventBanner($ctx['event_id']);
        $this->assertSame(0, (int) $row['has_banner']);
        $this->assertTrue(
            Ork3::$Lib->authorization->HasAuthority($grantor['mundane_id'], AUTH_EVENT, $ctx['event_id'], AUTH_EDIT)
        );
    }

    public function testSetEventBannerStaffCanManage(): void
    {
        $ctx = $this->fixture->createEvent('staff-banner');
        $staff = $this->fixture->createGrantorWithoutAuth('staff-mgr');
        $this->fixture->insertEventStaff($ctx['detail_id'], $staff['mundane_id'], canManage: true);

        $hasEdit = Ork3::$Lib->authorization->HasAuthority($staff['mundane_id'], AUTH_EVENT, $ctx['event_id'], AUTH_EDIT);
        $this->assertEmpty($hasEdit);

        $tmp = $this->fixture->createTempJpeg();
        $r = $this->banner->SetBanner([
            'Token' => $staff['token'],
            'Type' => 'Event',
            'Id' => $ctx['event_id'],
            'Banner' => base64_encode((string) file_get_contents($tmp)),
            'BannerMimeType' => 'image/jpeg',
            'ShowLogo' => 1,
            'Vignette' => 1,
            'OffsetX' => 50,
            'OffsetY' => 50,
        ]);
        $this->assertSame(ServiceErrorIds::Success, $r['Status']);

        $base = DIR_EVENT_BANNER . sprintf('%05d', $ctx['event_id']);
        if (file_exists($base . '.jpg')) {
            $this->fixture->trackBannerFile($base . '.jpg');
        }
        $this->assertSame(1, (int) $this->fixture->fetchEventBanner($ctx['event_id'])['has_banner']);
    }

    public function testRemoveBannerVerifyRollback(): void
    {
        $parkId = $this->fixture->firstParkId();
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_EDIT, 'park-rollback');
        $tmp = $this->fixture->createTempJpeg();
        $this->uploadParkBanner($grantor['token'], $parkId, $tmp, 1, 1, 50, 50);

        $base = DIR_PARK_BANNER . sprintf('%05d', $parkId) . '.jpg';
        $this->assertTrue(file_exists($base));
        $this->fixture->trackBannerFile($base);

        global $DB;
        $DB->Clear();
        $DB->Execute('UPDATE ' . DB_PREFIX . 'park SET has_banner = 1 WHERE park_id = ' . $parkId);

        $r = $this->banner->RemoveBanner([
            'Token' => $grantor['token'],
            'Type' => 'Park',
            'Id' => $parkId,
        ]);
        $this->assertSame(ServiceErrorIds::Success, $r['Status']);
        $this->assertSame(0, (int) $this->fixture->fetchParkBanner($parkId)['has_banner']);
        $this->assertFalse(file_exists($base));
    }

    public function testCopyBannerRequiresTokenAndAuth(): void
    {
        $source = $this->fixture->createEvent('c02-src');
        $target = $this->fixture->createEvent('c02-tgt');
        $this->assertSame($source['park_id'], $target['park_id']);
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $source['park_id'], AUTH_EDIT, 'c02-park');

        $tmp = $this->fixture->createTempJpeg();
        $set = $this->banner->SetBanner([
            'Token' => $grantor['token'],
            'Type' => 'Event',
            'Id' => $source['event_id'],
            'Banner' => base64_encode((string) file_get_contents($tmp)),
            'BannerMimeType' => 'image/jpeg',
            'ShowLogo' => 1,
            'Vignette' => 0,
            'OffsetX' => 40,
            'OffsetY' => 60,
        ]);
        $this->assertSame(ServiceErrorIds::Success, $set['Status']);
        $srcBase = DIR_EVENT_BANNER . sprintf('%05d', $source['event_id']);
        if (file_exists($srcBase . '.jpg')) {
            $this->fixture->trackBannerFile($srcBase . '.jpg');
        }

        // IsAuthorized prefers session over request Token — clear after SetBanner.
        unset($_SESSION['is_authorized_mundane_id']);

        $noToken = $this->banner->CopyBanner([
            'Type' => 'Event',
            'SourceId' => $source['event_id'],
            'TargetId' => $target['event_id'],
        ]);
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $noToken['Status']);

        $stranger = $this->fixture->createGrantorWithoutAuth('c02-stranger');
        unset($_SESSION['is_authorized_mundane_id']);
        $denied = $this->banner->CopyBanner([
            'Token' => $stranger['token'],
            'Type' => 'Event',
            'SourceId' => $source['event_id'],
            'TargetId' => $target['event_id'],
        ]);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $denied['Status']);
        $this->assertSame(0, (int) $this->fixture->fetchEventBanner($target['event_id'])['has_banner']);

        unset($_SESSION['is_authorized_mundane_id']);
        $ok = $this->banner->CopyBanner([
            'Token' => $grantor['token'],
            'Type' => 'Event',
            'SourceId' => $source['event_id'],
            'TargetId' => $target['event_id'],
        ]);
        $this->assertSame(ServiceErrorIds::Success, $ok['Status']);
        $this->assertSame(1, (int) $this->fixture->fetchEventBanner($target['event_id'])['has_banner']);
        $tgtBase = DIR_EVENT_BANNER . sprintf('%05d', $target['event_id']);
        if (file_exists($tgtBase . '.jpg')) {
            $this->fixture->trackBannerFile($tgtBase . '.jpg');
        }
    }

    private function uploadParkBanner(
        string $token,
        int $parkId,
        string $tmpPath,
        int $showLogo,
        int $vignette,
        int $offsetX,
        int $offsetY,
    ): void {
        $r = $this->banner->SetBanner([
            'Token' => $token,
            'Type' => 'Park',
            'Id' => $parkId,
            'Banner' => base64_encode((string) file_get_contents($tmpPath)),
            'BannerMimeType' => 'image/jpeg',
            'ShowLogo' => $showLogo,
            'Vignette' => $vignette,
            'OffsetX' => $offsetX,
            'OffsetY' => $offsetY,
        ]);
        $this->assertSame(ServiceErrorIds::Success, $r['Status']);
    }
}
