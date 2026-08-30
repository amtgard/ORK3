<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * Nothing may reference a retired officer write surface.
 *
 * Plan 1 shipped a commit deleting an action the console's only crown Vacate
 * button still called; every test stayed green. This is the check that would
 * have caught it.
 */
final class OfficerSurfaceRemovalTest extends TestCase
{
    /** @return list<array{0:string}> */
    public static function retiredTokenProvider(): array
    {
        return array_map(static fn ($t) => [$t], [
            'kn-editoff', 'pk-editoff',
            'setkingdomofficers', 'setparkofficers',
            'addofficerhistory', 'editofficerhistory', 'deleteofficerhistory',
        ]);
    }

    /** @dataProvider retiredTokenProvider */
    public function testNoLiveReferenceSurvives(string $token): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        $rii  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/orkui'));
        foreach ($rii as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (!preg_match('/\.(php|tpl|js|css)$/', $file->getFilename())) {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                // Comments may still explain the removal; executable references may not.
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (str_contains($line, $token)) {
                    $hits[] = str_replace($root . '/', '', $file->getPathname()) . ':' . ($n + 1);
                }
            }
        }
        self::assertSame([], $hits, "retired surface '{$token}' still referenced in executable code");
    }

    public function testSetOccupantActionIsGone(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        self::assertStringNotContainsString(
            "case 'setoccupant':",
            $src,
            'the wizard subsumes it; the domain method SetOccupant stays as an API verb'
        );
    }
}
