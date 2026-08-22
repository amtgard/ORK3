<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ork_session_client_label() buckets a session's user_agent / self-identified
 * client label into the short display label used by the account-menu session
 * list, the anonymous sign-in tally, and the Release Feature Utilization
 * report. The iOS cases are the treacherous ones: iOS Chrome/Firefox/Edge use
 * their own tokens (CriOS/FxiOS/EdgiOS) AND append a compat "Safari/" token,
 * so naive matching labels them all "Safari on iOS" — a false alarm in a
 * session list players are told to scan for unfamiliar entries.
 */
final class SessionClientLabelTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../system/lib/ork3/common.php';
    }

    public static function uaCases(): array
    {
        return [
            'Chrome on iOS uses CriOS token'  => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.54 Mobile/15E148 Safari/604.1', 'Chrome on iOS'],
            'Firefox on iOS uses FxiOS token' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/127.0 Mobile/15E148 Safari/605.1.15', 'Firefox on iOS'],
            'Edge on iOS uses EdgiOS token'   => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) EdgiOS/126.0 Mobile/15E148 Safari/605', 'Edge on iOS'],
            'real Safari on iOS'              => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari on iOS'],
            'iOS webview omits Safari token'  => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148', 'In-app browser on iOS'],
            'Android webview has wv marker'   => ['Mozilla/5.0 (Linux; Android 14; Pixel 8; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.6478.71 Mobile Safari/537.36', 'In-app browser on Android'],
            'Chrome on Android'               => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.71 Mobile Safari/537.36', 'Chrome on Android'],
            'Safari on Mac'                   => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Safari on Mac'],
            'desktop Edge beats Chrome token' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.2592.87', 'Edge on Windows'],
            'desktop Chrome on Windows'       => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', 'Chrome on Windows'],
            'jsork self-identified'           => ['jsork', 'jsork'],
            'jsork with version detail'       => ['jsork/1.2 embedded', 'jsork'],
            'mORK self-identified'            => ['mORK/2.4 (iOS)', 'mORK'],
            'curl'                            => ['curl/8.6.0', 'API client (curl)'],
            'unknown short label passthrough' => ['MyCustomBot 1.0', 'MyCustomBot 1.0'],
            'empty'                           => ['', 'Unknown client'],
        ];
    }

    /** @dataProvider uaCases */
    public function testLabels(string $ua, string $expected): void
    {
        $this->assertSame($expected, ork_session_client_label($ua));
    }
}
