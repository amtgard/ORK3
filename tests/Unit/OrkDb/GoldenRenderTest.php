<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Render;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class GoldenRenderTest extends TestCase
{
    private string $toolRoot;

    protected function setUp(): void
    {
        $this->toolRoot = sys_get_temp_dir() . '/ork-db-golden-' . uniqid('', true);
        $this->copyTree(ORK3_ROOT . '/tools/ork-db', $this->toolRoot);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->toolRoot);
    }

    /**
     * The half of the render that comes only from files git tracks.
     *
     * ork.sql, templates/schema/*, every migration MigrationClassifier renders, and
     * templates/catalogs/* — nothing here reads tools/ork-db/extracted/, so this hash is the
     * same on every machine and after every production reload. It is the assertion that
     * catches what a golden render is for: a migration changing the schema the sandbox is
     * built from. This one is safe to gate.
     */
    public function testCommittedSchemaAndCatalogsMatchGoldenSha256(): void
    {
        $sql = $this->renderDeterministic();
        $prefix = $this->committedSourcePrefix($sql);
        $hash = 'sha256:' . hash('sha256', $prefix);

        $goldenPath = ORK3_ROOT . '/tests/fixtures/ork-db/golden-schema-catalogs.sha256';
        $this->assertFileExists($goldenPath);
        $expected = trim((string) file_get_contents($goldenPath));

        $this->assertSame(
            $expected,
            $hash,
            'Rendered schema/catalog drift — a migration, ork.sql, templates/schema/* or '
            . 'templates/catalogs/* changed. Re-record '
            . 'tests/fixtures/ork-db/golden-schema-catalogs.sha256 if intentional.'
        );
        $this->assertStringContainsString('-- migration: 2026-05-17-add-entity-banners.sql', $prefix);
    }

    /**
     * The WHOLE render, including the sections built from tools/ork-db/extracted/.
     *
     * Everything after the catalogs section is derived from extracted/mundane_real.json,
     * configuration.sql and events.json — a .gitignored extract of the production mirror —
     * so this hash is not reproducible across machines and cannot be made so without
     * committing real player data. That is why it is grouped `mirror-data` and excluded from
     * bin/run-unit-tests.sh: a prod reload that touches sampled configuration, the sampled
     * events, or a real player's persona legitimately moves it, and gating it would recreate
     * exactly the permanently-red check that --allow-catalog-drift was invented to step
     * around.
     *
     * Per-session credential state is masked out before hashing (see
     * maskVolatileRealPlayerState). It is not schema and it is not generated content — it is
     * whatever the four sampled accounts' `token` / `token_expires` / `password_salt` /
     * `xtoken` happened to be at extract time. Unmasked, this hash moved twice in twenty
     * minutes on a machine where nothing in the repository changed, each time on one
     * ork_mundane line, because someone logged into the mirror-backed local app. Masking it
     * removes churn that carries no information; the rest of the render — every fake player,
     * officer, event, attendance row, kingdom award and configuration row — is still pinned
     * byte for byte.
     *
     * Re-record when you re-extract.
     */
    #[Group('mirror-data')]
    public function testDeterministicRenderMatchesGoldenSha256(): void
    {
        $sql = $this->maskVolatileRealPlayerState($this->renderDeterministic());
        $hash = 'sha256:' . hash('sha256', $sql);

        $goldenPath = ORK3_ROOT . '/tests/fixtures/ork-db/golden-sandbox.sha256';
        $this->assertFileExists($goldenPath);
        $expected = trim((string) file_get_contents($goldenPath));

        $this->assertSame($expected, $hash, 'Golden render hash drift — update tests/fixtures/ork-db/golden-sandbox.sha256 if intentional');
        $this->assertStringContainsString('-- migration: 2026-05-17-add-entity-banners.sql', $sql);
    }

    /**
     * Blank the production session state carried by the four sampled real accounts.
     *
     * Scoped strictly to the "real players" section — the 32-hex credential literals and
     * datetimes elsewhere in the render (attendance, events, awards) are generated content
     * and stay pinned.
     */
    private function maskVolatileRealPlayerState(string $sql): string
    {
        $start = strpos($sql, '-- Section: real players');
        $end = strpos($sql, '-- Section: fake players');
        if ($start === false || $end === false || $end <= $start) {
            return $sql;
        }

        $block = substr($sql, $start, $end - $start);
        $masked = preg_replace(
            ["/'[0-9a-f]{32}'/", "/'\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}'/"],
            ["'<hex32>'", "'<datetime>'"],
            $block
        ) ?? $block;

        return substr($sql, 0, $start) . $masked . substr($sql, $end);
    }

    private function renderDeterministic(): string
    {
        $render = new Render($this->toolRoot, ORK3_ROOT);
        $output = $this->toolRoot . '/rendered/sandbox.sql';
        $render->run([
            'anchor_date' => '2026-07-07',
            'seed' => 42,
            'output' => $output,
            'deterministic' => true,
        ]);

        $sql = file_get_contents($output);
        $this->assertIsString($sql);

        return $sql;
    }

    /** Header, boilerplate, schema and catalogs — everything before the first generated section. */
    private function committedSourcePrefix(string $sql): string
    {
        $marker = '-- Section: kingdoms';
        $position = strpos($sql, $marker);
        $this->assertIsInt($position, 'Render is missing the "-- Section: kingdoms" boundary');

        return substr($sql, 0, $position);
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
            throw new \RuntimeException("Failed to create directory: {$destination}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new \RuntimeException("Failed to create directory: {$target}");
                }
                continue;
            }

            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            copy($item->getPathname(), $target);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
