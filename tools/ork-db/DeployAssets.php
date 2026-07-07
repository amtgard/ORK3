<?php

declare(strict_types=1);

namespace OrkDb;

final class DeployAssets
{
    /** @var array<string, array{0: string, 1: string}> */
    private const COPY_MAP = [
        'kingdom' => ['kingdom', 'heraldry/kingdom'],
        'park' => ['park', 'heraldry/park'],
        'player' => ['player', 'heraldry/player'],
        'players' => ['players', 'players'],
    ];

    public function __construct(
        private readonly string $toolRoot,
        private readonly string $repoRoot,
    ) {
    }

    /**
     * @param array{
     *   source_root?: string|null,
     *   assets_root?: string|null,
     *   verify_manifest?: bool
     * } $options
     * @return array{
     *   assets_root: string,
     *   source_root: string,
     *   kingdom_count: int,
     *   park_count: int,
     *   player_heraldry_count: int,
     *   player_portrait_count: int,
     *   files: list<string>,
     *   manifest_ok: bool|null
     * }
     */
    public function run(array $options = []): array
    {
        $sourceRoot = $options['source_root'] ?? $this->toolRoot . '/generated-assets';
        $assetsRoot = $options['assets_root'] ?? $this->repoRoot . '/assets';
        $verifyManifest = (bool) ($options['verify_manifest'] ?? true);

        if (!is_dir($sourceRoot)) {
            throw new ValidationException(
                'Generated assets missing — run bin/ork-db generate-assets first'
            );
        }

        $files = [];
        $counts = [
            'kingdom_count' => 0,
            'park_count' => 0,
            'player_heraldry_count' => 0,
            'player_portrait_count' => 0,
        ];

        foreach (self::COPY_MAP as $key => [$sourceSubdir, $destSubdir]) {
            $sourceDir = rtrim($sourceRoot, '/') . '/' . $sourceSubdir;
            $destDir = rtrim($assetsRoot, '/') . '/' . $destSubdir;
            if (!is_dir($sourceDir)) {
                continue;
            }

            $this->ensureDirectory($destDir);
            $copiedInCategory = 0;
            foreach (glob($sourceDir . '/*.{png,jpg}', GLOB_BRACE) ?: [] as $sourcePath) {
                $filename = basename($sourcePath);
                $destPath = $destDir . '/' . $filename;
                if (!copy($sourcePath, $destPath)) {
                    throw new \RuntimeException("Failed to copy asset: {$sourcePath} → {$destPath}");
                }
                $files[] = $destPath;
                $copiedInCategory++;
            }

            match ($key) {
                'kingdom' => $counts['kingdom_count'] = $copiedInCategory,
                'park' => $counts['park_count'] = $copiedInCategory,
                'player' => $counts['player_heraldry_count'] = $copiedInCategory,
                'players' => $counts['player_portrait_count'] = $copiedInCategory,
                default => null,
            };
        }

        if ($files === []) {
            throw new ValidationException(
                'No generated asset files found — run bin/ork-db generate-assets first'
            );
        }

        $manifestOk = null;
        if ($verifyManifest) {
            $manifestOk = $this->verifyManifest($sourceRoot, $assetsRoot);
            if (!$manifestOk) {
                throw new ValidationException(
                    'Deployed asset checksum mismatch — run bin/ork-db generate-assets && bin/ork-db deploy-assets'
                );
            }
        }

        return [
            'assets_root' => $assetsRoot,
            'source_root' => $sourceRoot,
            'kingdom_count' => $counts['kingdom_count'],
            'park_count' => $counts['park_count'],
            'player_heraldry_count' => $counts['player_heraldry_count'],
            'player_portrait_count' => $counts['player_portrait_count'],
            'files' => $files,
            'manifest_ok' => $manifestOk,
        ];
    }

    private function verifyManifest(string $sourceRoot, string $assetsRoot): bool
    {
        $manifestPath = $this->toolRoot . '/manifests/asset-manifest.json5';
        if (!is_readable($manifestPath)) {
            return true;
        }

        $manifest = Json5::decodeFile($manifestPath);
        $entries = $manifest['files'] ?? [];
        if (!is_array($entries) || $entries === []) {
            return true;
        }

        foreach ($entries as $relativePath => $expectedHash) {
            if (!is_string($relativePath) || !is_string($expectedHash)) {
                continue;
            }

            $destPath = $this->resolveManifestDestPath($relativePath, $sourceRoot, $assetsRoot);
            if ($destPath === null || !is_readable($destPath)) {
                return false;
            }

            $hash = hash_file('sha256', $destPath);
            if ($hash === false) {
                return false;
            }

            $normalizedExpected = str_starts_with($expectedHash, 'sha256:')
                ? substr($expectedHash, strlen('sha256:'))
                : $expectedHash;
            if (!hash_equals($normalizedExpected, $hash)) {
                return false;
            }
        }

        return true;
    }

    private function resolveManifestDestPath(string $relativePath, string $sourceRoot, string $assetsRoot): ?string
    {
        if (str_starts_with($relativePath, 'players/')) {
            return rtrim($assetsRoot, '/') . '/' . $relativePath;
        }

        if (preg_match('#^(kingdom|park|player)/#', $relativePath) === 1) {
            return rtrim($assetsRoot, '/') . '/heraldry/' . $relativePath;
        }

        return rtrim($sourceRoot, '/') . '/' . $relativePath;
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException("Failed to create directory: {$path}");
        }
    }
}
