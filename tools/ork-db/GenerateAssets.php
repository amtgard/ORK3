<?php

declare(strict_types=1);

namespace OrkDb;

final class GenerateAssets
{
    private const SHIELD_PATH = 'M128 16 L216 48 L216 140 Q216 196 128 240 Q40 196 40 140 L40 48 Z';
    private const OUTPUT_SIZE = 256;
    private const JPEG_QUALITY = 80;

    /** @var (callable(string, string): void)|null */
    private $svgConverter;

    /** @var array<string, mixed> */
    private array $kingdomDesigns;

    /** @var array<string, mixed> */
    private array $parkRules;

    /**
     * @param (callable(string, string): void)|null $svgConverter
     */
    public function __construct(
        private readonly string $toolRoot,
        private readonly Render $render,
        $svgConverter = null,
    ) {
        $this->svgConverter = $svgConverter;
        $this->kingdomDesigns = Json5::decodeFile($toolRoot . '/templates/heraldry/kingdoms.json5');
        $this->parkRules = Json5::decodeFile($toolRoot . '/templates/heraldry/park-rules.json5');
    }

    /**
     * @param array{seed?: int|null, output_root?: string|null} $options
     * @return array{
     *   output_root: string,
     *   kingdom_count: int,
     *   park_count: int,
     *   player_count: int,
     *   files: list<string>
     * }
     */
    public function run(array $options = []): array
    {
        $seed = (int) ($options['seed'] ?? $this->renderSeedDefault());
        $outputRoot = $options['output_root'] ?? $this->toolRoot . '/generated-assets';
        $files = [];

        $this->ensureDirectory($outputRoot . '/kingdom');
        $this->ensureDirectory($outputRoot . '/park');
        $this->ensureDirectory($outputRoot . '/player');
        $this->ensureDirectory($outputRoot . '/players');

        foreach ($this->kingdomDesigns as $kingdomId => $design) {
            if (!is_array($design)) {
                continue;
            }
            $path = $outputRoot . '/kingdom/' . $kingdomId . '.jpg';
            $this->writeJpeg($this->buildKingdomSvg($design), $path);
            $files[] = $path;
        }

        $parks = $this->render->parkLayoutForSeed($seed);
        foreach ($parks as $park) {
            $kingdomId = (string) $park['kingdom_id'];
            $design = $this->kingdomDesigns[$kingdomId] ?? null;
            if (!is_array($design)) {
                throw new ValidationException("Missing heraldry design for kingdom {$kingdomId}");
            }

            $path = $outputRoot . '/park/' . $park['park_id'] . '.jpg';
            $this->writeJpeg(
                $this->buildParkSvg($design, (string) $park['abbreviation']),
                $path
            );
            $files[] = $path;
        }

        $playerSvg = $this->readPlayerPhoenixSvg();
        $defaultBasename = IdNamespace::PLAYER_HERALDRY_DEFAULT_BASENAME;
        $defaultHeraldryPath = $outputRoot . '/player/' . $defaultBasename . '.png';
        $defaultPortraitPath = $outputRoot . '/players/' . $defaultBasename . '.png';
        $this->writePng($playerSvg, $defaultHeraldryPath);
        $this->writePng($playerSvg, $defaultPortraitPath);
        $files[] = $defaultHeraldryPath;
        $files[] = $defaultPortraitPath;

        foreach ($this->render->fakeMundaneHeraldryIdsForSeed($seed) as $mundaneId) {
            $heraldryPath = $outputRoot . '/player/' . $this->playerAssetBasename($mundaneId) . '.jpg';
            $this->writeJpeg($playerSvg, $heraldryPath);
            $files[] = $heraldryPath;
        }

        foreach ($this->realPlayerIds() as $mundaneId) {
            $heraldryPath = $outputRoot . '/player/' . $this->playerAssetBasename($mundaneId) . '.jpg';
            $this->writeJpeg($playerSvg, $heraldryPath);
            $files[] = $heraldryPath;
        }

        $manifest = [];
        foreach ($files as $file) {
            $hash = hash_file('sha256', $file);
            if ($hash === false) {
                throw new \RuntimeException("Failed to hash generated asset: {$file}");
            }
            $manifest[str_replace($outputRoot . '/', '', $file)] = 'sha256:' . $hash;
        }

        $manifestPath = $this->toolRoot . '/manifests/asset-manifest.json5';
        $encoded = json_encode(['files' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode asset manifest');
        }
        if (file_put_contents($manifestPath, $encoded . "\n") === false) {
            throw new \RuntimeException("Failed to write asset manifest: {$manifestPath}");
        }

        return [
            'output_root' => $outputRoot,
            'kingdom_count' => count($this->kingdomDesigns),
            'park_count' => count($parks),
            'player_count' => count(array_filter(
                $files,
                static fn (string $path): bool => str_contains($path, '/player/') && !str_contains($path, '/players/')
            )),
            'files' => $files,
        ];
    }

    /** @param array<string, mixed> $design */
    public function buildKingdomSvg(array $design): string
    {
        $field = (string) ($design['field'] ?? '#666666');
        $charge = (string) ($design['charge'] ?? 'cross');
        $chargeColor = (string) ($design['charge_color'] ?? '#FFD700');
        $border = isset($design['border']) ? (string) $design['border'] : null;

        return $this->wrapShield($field, $this->chargeMarkup($charge, $chargeColor), $border);
    }

    /** @param array<string, mixed> $design */
    public function buildParkSvg(array $design, string $abbreviation): string
    {
        $field = (string) ($design['field'] ?? '#666666');
        $badgeColor = (string) ($this->parkRules['badge_color'] ?? '#FFD700');
        $initial = strtoupper(substr($abbreviation, 0, 1));
        if ($initial === '') {
            $initial = 'P';
        }

        $badge = sprintf(
            '<text x="128" y="148" text-anchor="middle" font-size="96" font-family="Georgia, serif" font-weight="700" fill="%s">%s</text>',
            htmlspecialchars($badgeColor, ENT_QUOTES),
            htmlspecialchars($initial, ENT_QUOTES)
        );

        return $this->wrapShield($field, $badge);
    }

    public function readPlayerPhoenixSvg(): string
    {
        $path = $this->toolRoot . '/templates/heraldry/player-phoenix.svg';
        if (!is_readable($path)) {
            throw new ValidationException('Missing player phoenix template: ' . $path);
        }

        return trim((string) file_get_contents($path));
    }

    private function playerAssetBasename(int $mundaneId): string
    {
        return sprintf('%06d', $mundaneId);
    }

    private function renderSeedDefault(): int
    {
        $fingerprints = Json5::decodeFile($this->toolRoot . '/manifests/fingerprints.json5');

        return (int) ($fingerprints['content_seed'] ?? $fingerprints['render_seed_default'] ?? 42);
    }

    /** @return list<int> */
    private function realPlayerIds(): array
    {
        $path = $this->toolRoot . '/extracted/mundane_real.json';
        if (!is_readable($path)) {
            return [];
        }

        $bundle = json_decode((string) file_get_contents($path), true);
        if (!is_array($bundle)) {
            return [];
        }

        $ids = [];
        foreach ($bundle['players'] ?? [] as $player) {
            $mundane = $player['mundane'] ?? null;
            if (!is_array($mundane) || !isset($mundane['mundane_id'])) {
                continue;
            }
            $ids[] = (int) $mundane['mundane_id'];
        }

        return $ids;
    }

    private function wrapShield(string $fieldColor, string $overlay, ?string $borderColor = null): string
    {
        $border = $borderColor !== null
            ? sprintf(
                '<path d="%s" fill="none" stroke="%s" stroke-width="8"/>',
                self::SHIELD_PATH,
                htmlspecialchars($borderColor, ENT_QUOTES)
            )
            : '';

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256">'
            . '<path d="%s" fill="%s"/>'
            . '%s%s'
            . '</svg>',
            self::SHIELD_PATH,
            htmlspecialchars($fieldColor, ENT_QUOTES),
            $overlay,
            $border
        );
    }

    private function chargeMarkup(string $charge, string $color): string
    {
        $escaped = htmlspecialchars($color, ENT_QUOTES);

        return match ($charge) {
            'eagle' => '<path d="M128 72 L148 108 L128 98 L108 108 Z M128 98 L128 156 M108 132 L148 132" '
                . 'fill="none" stroke="' . $escaped . '" stroke-width="8" stroke-linecap="round"/>',
            'lion' => '<circle cx="128" cy="128" r="36" fill="none" stroke="' . $escaped . '" stroke-width="8"/>'
                . '<path d="M128 92 L128 72 M108 108 L92 92 M148 108 L164 92" fill="none" stroke="' . $escaped . '" stroke-width="8" stroke-linecap="round"/>',
            'crescent' => '<path d="M148 128 A40 40 0 1 1 128 88 A28 28 0 1 0 148 128 Z" fill="' . $escaped . '"/>'
                . '<circle cx="168" cy="96" r="8" fill="' . $escaped . '"/>',
            'bear' => '<circle cx="128" cy="132" r="34" fill="' . $escaped . '"/>'
                . '<circle cx="108" cy="104" r="12" fill="' . $escaped . '"/>'
                . '<circle cx="148" cy="104" r="12" fill="' . $escaped . '"/>',
            'cross' => '<rect x="116" y="88" width="24" height="80" fill="' . $escaped . '"/>'
                . '<rect x="92" y="112" width="72" height="24" fill="' . $escaped . '"/>',
            default => '<circle cx="128" cy="128" r="28" fill="' . $escaped . '"/>',
        };
    }

    private function writeJpeg(string $svg, string $outputPath, int $quality = self::JPEG_QUALITY): void
    {
        $this->ensureDirectory(dirname($outputPath));
        if ($this->svgConverter !== null) {
            ($this->svgConverter)($svg, $outputPath);

            return;
        }

        $tempPng = tempnam(sys_get_temp_dir(), 'ork-db-asset-');
        if ($tempPng === false) {
            throw new \RuntimeException('Failed to create temporary PNG file');
        }

        try {
            $this->writePng($svg, $tempPng);
            if (!is_readable($tempPng)) {
                throw new \RuntimeException('SVG rasterization failed for JPEG output');
            }

            $source = imagecreatefrompng($tempPng);
            if ($source === false) {
                throw new \RuntimeException('Failed to read rasterized PNG for JPEG output');
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $flat = imagecreatetruecolor($width, $height);
            if ($flat === false) {
                imagedestroy($source);
                throw new \RuntimeException('Failed to create JPEG canvas');
            }

            $white = imagecolorallocate($flat, 255, 255, 255);
            imagefill($flat, 0, 0, $white);
            imagecopy($flat, $source, 0, 0, 0, 0, $width, $height);
            imagedestroy($source);

            if (!imagejpeg($flat, $outputPath, $quality)) {
                imagedestroy($flat);
                throw new \RuntimeException("Failed to write JPEG: {$outputPath}");
            }

            imagedestroy($flat);
        } finally {
            @unlink($tempPng);
        }
    }

    private function writePng(string $svg, string $outputPath): void
    {
        $this->ensureDirectory(dirname($outputPath));
        if ($this->svgConverter !== null) {
            ($this->svgConverter)($svg, $outputPath);

            return;
        }

        $tempSvg = tempnam(sys_get_temp_dir(), 'ork-db-asset-');
        if ($tempSvg === false) {
            throw new \RuntimeException('Failed to create temporary SVG file');
        }

        try {
            if (file_put_contents($tempSvg, $svg) === false) {
                throw new \RuntimeException("Failed to write temporary SVG: {$tempSvg}");
            }

            $command = sprintf(
                'rsvg-convert -w %d -h %d %s -o %s 2>&1',
                self::OUTPUT_SIZE,
                self::OUTPUT_SIZE,
                escapeshellarg($tempSvg),
                escapeshellarg($outputPath)
            );
            exec($command, $output, $exitCode);
            if ($exitCode !== 0 || !is_readable($outputPath)) {
                $this->writePngWithGd($svg, $outputPath);
            }
        } finally {
            @unlink($tempSvg);
        }
    }

    private function writePngWithGd(string $svg, string $outputPath): void
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('SVG conversion failed and GD extension is unavailable');
        }

        $image = imagecreatetruecolor(self::OUTPUT_SIZE, self::OUTPUT_SIZE);
        if ($image === false) {
            throw new \RuntimeException('Failed to create GD image');
        }

        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        if (preg_match('/fill="#([0-9A-Fa-f]{6})"/', $svg, $matches)) {
            $rgb = sscanf($matches[1], '%2x%2x%2x');
            if ($rgb !== null) {
                $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
                imagefilledellipse($image, 128, 128, 180, 210, $color);
            }
        }

        if (!imagepng($image, $outputPath)) {
            imagedestroy($image);
            throw new \RuntimeException("Failed to write PNG: {$outputPath}");
        }

        imagedestroy($image);
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
