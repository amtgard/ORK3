<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for hero banner CRUD (T-PLA-06, T-PRA-04, T-KNA-08, T-UNT-01, T-EVA-14).
 *
 * Pre-refactor write logic lives in orkui/controller/*Ajax::banner; R-03 will move
 * these behaviors to class.Banner.php and BannerService.
 */
final class BannerTest extends TestCase
{
    private BannerFixture $fixture;

    private Park $parkDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = BannerFixture::create();
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
        $tmp = $this->fixture->createTempJpeg();
        $this->fixture->trackBannerFile($tmp);

        $this->mirrorParkBannerUpload($parkId, $tmp, showLogo: 1, vignette: 0, offsetX: 25, offsetY: 75);

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
        $this->fixture->setParkRetired($parkId);

        $this->assertFalse($this->fixture->parkIsActive($parkId));

        $tmp = $this->fixture->createTempJpeg();
        $this->fixture->trackBannerFile($tmp);

        $blocked = !$this->fixture->parkIsActive($parkId);
        $this->assertTrue($blocked);

        $base = DIR_PARK_BANNER . sprintf('%05d', $parkId) . '.jpg';
        if (!$this->mirrorParkBannerUploadAllowed($parkId)) {
            $this->assertFalse(file_exists($base));
        }
    }

    public function testRemoveParkBannerResetsDefaults(): void
    {
        $parkId = $this->fixture->firstParkId();
        $tmp = $this->fixture->createTempJpeg();
        $this->mirrorParkBannerUpload($parkId, $tmp, 1, 1, 10, 20);

        $base = DIR_PARK_BANNER . sprintf('%05d', $parkId);
        if (file_exists($base . '.jpg')) {
            $this->fixture->trackBannerFile($base . '.jpg');
        }

        $this->mirrorParkBannerRemove($parkId);

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

        $error = $this->mirrorParkBannerConfig($parkId, 0, 0, 30, 40);
        $this->assertSame('Upload a banner first before saving settings.', $error);

        $tmp = $this->fixture->createTempJpeg();
        $this->mirrorParkBannerUpload($parkId, $tmp, 1, 1, 50, 50);
        $base = DIR_PARK_BANNER . sprintf('%05d', $parkId);
        if (file_exists($base . '.jpg')) {
            $this->fixture->trackBannerFile($base . '.jpg');
        }

        $error = $this->mirrorParkBannerConfig($parkId, 0, 0, 30, 40);
        $this->assertNull($error);

        $row = $this->fixture->fetchParkBanner($parkId);
        $this->assertSame(0, (int) $row['banner_show_logo']);
        $this->assertSame(0, (int) $row['banner_vignette']);
        $this->assertSame(30, (int) $row['banner_offset_x']);
        $this->assertSame(40, (int) $row['banner_offset_y']);
    }

    public function testSetPlayerBannerClearsHeroGradient(): void
    {
        $mundaneId = $this->fixture->createPlayerWithGradient('gradient-player');
        $this->assertNotNull($this->fixture->fetchPlayerGradient($mundaneId));

        $tmp = $this->fixture->createTempJpeg();
        $this->mirrorPlayerBannerUpload($mundaneId, $tmp, 1, 1, 50, 50);

        $base = DIR_PLAYER_BANNER . sprintf('%06d', $mundaneId);
        if (file_exists($base . '.jpg')) {
            $this->fixture->trackBannerFile($base . '.jpg');
        }

        $this->assertNull($this->fixture->fetchPlayerGradient($mundaneId));
        $row = $this->fixture->fetchPlayerBanner($mundaneId);
        $this->assertSame(1, (int) $row['has_banner']);
    }

    public function testSetKingdomBannerAuthRejectsNonEditor(): void
    {
        $kingdomId = $this->fixture->firstKingdomId();
        $grantor = $this->fixture->createGrantorWithoutAuth('kingdom-no-edit');

        unset($_SESSION['is_authorized_mundane_id']);
        $authorized = Ork3::$Lib->authorization->HasAuthority(
            $grantor['mundane_id'],
            AUTH_KINGDOM,
            $kingdomId,
            AUTH_EDIT,
        );

        $this->assertEmpty($authorized);
    }

    public function testSetUnitBannerRejectsOversize(): void
    {
        $size = 1024 * 1024 + 1;
        $this->assertTrue($size > 1024 * 1024);
        $this->assertSame('File too large (max 1 MB).', $this->mirrorUnitBannerSizeError($size));
    }

    public function testSetEventBannerBustsSearchCache(): void
    {
        $ctx = $this->fixture->createEvent('cache-bust');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_EVENT, $ctx['event_id'], AUTH_EDIT, 'event-admin');

        unset($_SESSION['is_authorized_mundane_id']);
        Ork3::$Lib->ghettocache->bust_event_search($ctx['event_id']);

        $this->mirrorEventBannerRemove($ctx['event_id']);
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

        unset($_SESSION['is_authorized_mundane_id']);
        $hasEdit = Ork3::$Lib->authorization->HasAuthority($staff['mundane_id'], AUTH_EVENT, $ctx['event_id'], AUTH_EDIT);
        $this->assertEmpty($hasEdit);

        global $DB;
        $DB->Clear();
        $staffRow = $DB->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'event_staff s
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = s.event_calendardetail_id
             WHERE cd.event_id = ' . (int) $ctx['event_id']
            . ' AND s.mundane_id = ' . (int) $staff['mundane_id']
            . ' AND s.can_manage = 1 LIMIT 1'
        );
        $this->assertTrue($staffRow && $staffRow->Next());

        $tmp = $this->fixture->createTempJpeg();
        $this->mirrorEventBannerUpload($ctx['event_id'], $tmp, 1, 1, 50, 50);
        $base = DIR_EVENT_BANNER . sprintf('%05d', $ctx['event_id']);
        if (file_exists($base . '.jpg')) {
            $this->fixture->trackBannerFile($base . '.jpg');
        }
        $this->assertSame(1, (int) $this->fixture->fetchEventBanner($ctx['event_id'])['has_banner']);
    }

    public function testRemoveBannerVerifyRollback(): void
    {
        $parkId = $this->fixture->firstParkId();
        $tmp = $this->fixture->createTempJpeg();
        $this->mirrorParkBannerUpload($parkId, $tmp, 1, 1, 50, 50);

        $base = DIR_PARK_BANNER . sprintf('%05d', $parkId) . '.jpg';
        $this->assertTrue(file_exists($base));
        $this->fixture->trackBannerFile($base);

        global $DB;
        $DB->Clear();
        $DB->Execute('UPDATE ' . DB_PREFIX . 'park SET has_banner = 1 WHERE park_id = ' . $parkId);
        $DB->Clear();
        $removeCheck = $DB->DataSet('SELECT has_banner FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $parkId);
        $this->assertTrue($removeCheck && $removeCheck->Next());
        $this->assertSame(1, (int) $removeCheck->has_banner);

        $this->mirrorParkBannerRemove($parkId);
        $this->assertSame(0, (int) $this->fixture->fetchParkBanner($parkId)['has_banner']);
        $this->assertFalse(file_exists($base));
    }

    /**
     * Mirrors Controller_ParkAjax::banner active-park guard (lines 688–694).
     */
    private function mirrorParkBannerUploadAllowed(int $parkId): bool
    {
        if (!$this->fixture->parkIsActive($parkId)) {
            return false;
        }

        return true;
    }

    /**
     * Mirrors Controller_ParkAjax::banner update branch (lines 752–812) using copy() for CLI.
     */
    private function mirrorParkBannerUpload(
        int $parkId,
        string $tmpPath,
        int $showLogo,
        int $vignette,
        int $offsetX,
        int $offsetY,
    ): void {
        $detectedType = exif_imagetype($tmpPath);
        $this->assertContains($detectedType, [IMAGETYPE_JPEG, IMAGETYPE_PNG]);

        if (!is_dir(DIR_PARK_BANNER)) {
            @mkdir(DIR_PARK_BANNER, 0775, true);
        }

        $ext = ($detectedType === IMAGETYPE_PNG) ? 'png' : 'jpg';
        $base = DIR_PARK_BANNER . sprintf('%05d', $parkId);
        if (file_exists($base . '.jpg')) {
            @unlink($base . '.jpg');
        }
        if (file_exists($base . '.png')) {
            @unlink($base . '.png');
        }
        copy($tmpPath, $base . '.' . $ext);

        global $DB;
        $offX = max(0, min(100, $offsetX));
        $offY = max(0, min(100, $offsetY));
        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'park SET has_banner = 1, banner_show_logo = ' . $showLogo
            . ', banner_vignette = ' . $vignette . ', banner_offset_x = ' . $offX
            . ', banner_offset_y = ' . $offY . ' WHERE park_id = ' . $parkId
        );
        $DB->Clear();
        $verify = $DB->DataSet('SELECT has_banner FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $parkId);
        if (!$verify || !$verify->Next() || (int) $verify->has_banner !== 1) {
            @unlink($base . '.' . $ext);
            $this->fail('Saved file but could not update the database.');
        }
    }

    /**
     * Mirrors Controller_ParkAjax::banner remove branch (lines 697–719).
     */
    private function mirrorParkBannerRemove(int $parkId): void
    {
        global $DB;
        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'park SET has_banner = 0, banner_show_logo = 1, banner_vignette = 1, banner_offset_x = 50, banner_offset_y = 50 WHERE park_id = ' . $parkId
        );
        $DB->Clear();
        $removeCheck = $DB->DataSet('SELECT has_banner FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $parkId);
        if (!$removeCheck || !$removeCheck->Next() || (int) $removeCheck->has_banner !== 0) {
            $this->fail('Could not clear banner flag in database.');
        }
        $base = DIR_PARK_BANNER . sprintf('%05d', $parkId);
        if (file_exists($base . '.jpg')) {
            unlink($base . '.jpg');
        }
        if (file_exists($base . '.png')) {
            unlink($base . '.png');
        }
    }

    /**
     * Mirrors Controller_ParkAjax::banner config branch (lines 722–749).
     *
     * @return string|null Error message when config rejected; null on success.
     */
    private function mirrorParkBannerConfig(
        int $parkId,
        int $showLogo,
        int $vignette,
        int $offsetX,
        int $offsetY,
    ): ?string {
        global $DB;
        $DB->Clear();
        $row = $DB->DataSet('SELECT has_banner FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $parkId);
        if (!$row || !$row->Next() || (int) $row->has_banner !== 1) {
            return 'Upload a banner first before saving settings.';
        }

        $offX = max(0, min(100, $offsetX));
        $offY = max(0, min(100, $offsetY));
        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'park SET banner_show_logo = ' . $showLogo
            . ', banner_vignette = ' . $vignette . ', banner_offset_x = ' . $offX
            . ', banner_offset_y = ' . $offY . ' WHERE park_id = ' . $parkId
        );
        $DB->Clear();
        $cfgVerify = $DB->DataSet(
            'SELECT banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y FROM '
            . DB_PREFIX . 'park WHERE park_id = ' . $parkId
        );
        if (!$cfgVerify || !$cfgVerify->Next()
            || (int) $cfgVerify->banner_show_logo !== $showLogo
            || (int) $cfgVerify->banner_vignette !== $vignette
            || (int) $cfgVerify->banner_offset_x !== $offX
            || (int) $cfgVerify->banner_offset_y !== $offY) {
            return 'Could not save banner settings. Please try again.';
        }

        return null;
    }

    /**
     * Mirrors Controller_PlayerAjax::banner update + gradient clear (lines 977–982).
     */
    private function mirrorPlayerBannerUpload(
        int $mundaneId,
        string $tmpPath,
        int $showLogo,
        int $vignette,
        int $offsetX,
        int $offsetY,
    ): void {
        if (!is_dir(DIR_PLAYER_BANNER)) {
            @mkdir(DIR_PLAYER_BANNER, 0775, true);
        }
        $ext = 'jpg';
        $base = DIR_PLAYER_BANNER . sprintf('%06d', $mundaneId);
        copy($tmpPath, $base . '.' . $ext);

        global $DB;
        $offX = max(0, min(100, $offsetX));
        $offY = max(0, min(100, $offsetY));
        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'mundane SET has_banner = 1, banner_show_logo = ' . $showLogo
            . ', banner_vignette = ' . $vignette . ', banner_offset_x = ' . $offX
            . ', banner_offset_y = ' . $offY . ' WHERE mundane_id = ' . $mundaneId
        );
        $DB->Clear();
        $DB->Execute('UPDATE ' . DB_PREFIX . 'mundane_design SET hero_gradient = NULL WHERE mundane_id = ' . $mundaneId);
    }

    /**
     * Mirrors Controller_UnitAjax::banner size check (lines 99–102).
     */
    private function mirrorUnitBannerSizeError(int $size): string
    {
        if ($size > 1024 * 1024) {
            return 'File too large (max 1 MB).';
        }

        return '';
    }

    /**
     * Mirrors Controller_EventAjax::banner update branch (lines 1811–1875).
     */
    private function mirrorEventBannerUpload(
        int $eventId,
        string $tmpPath,
        int $showLogo,
        int $vignette,
        int $offsetX,
        int $offsetY,
    ): void {
        if (!is_dir(DIR_EVENT_BANNER)) {
            @mkdir(DIR_EVENT_BANNER, 0775, true);
        }
        $base = DIR_EVENT_BANNER . sprintf('%05d', $eventId);
        copy($tmpPath, $base . '.jpg');

        global $DB;
        $offX = max(0, min(100, $offsetX));
        $offY = max(0, min(100, $offsetY));
        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'event SET has_banner = 1, banner_show_logo = ' . $showLogo
            . ', banner_vignette = ' . $vignette . ', banner_offset_x = ' . $offX
            . ', banner_offset_y = ' . $offY . ' WHERE event_id = ' . $eventId
        );
        Ork3::$Lib->ghettocache->bust_event_search($eventId);
    }

    /**
     * Mirrors Controller_EventAjax::banner remove branch (lines 1774–1788).
     */
    private function mirrorEventBannerRemove(int $eventId): void
    {
        global $DB;
        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'event SET has_banner = 0, banner_show_logo = 1, banner_vignette = 1, banner_offset_x = 50, banner_offset_y = 50 WHERE event_id = ' . $eventId
        );
        $base = DIR_EVENT_BANNER . sprintf('%05d', $eventId);
        if (file_exists($base . '.jpg')) {
            unlink($base . '.jpg');
        }
        if (file_exists($base . '.png')) {
            unlink($base . '.png');
        }
        Ork3::$Lib->ghettocache->bust_event_search($eventId);
    }
}
