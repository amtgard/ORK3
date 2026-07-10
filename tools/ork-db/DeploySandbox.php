<?php

declare(strict_types=1);

namespace OrkDb;

final class DeploySandbox
{
    public function __construct(
        private readonly DeploymentTier $tier,
        private readonly Wiring $wiring,
        private readonly Validate $validate,
        private readonly Init $init,
        private readonly Bootstrap $bootstrap,
        private readonly Extract $extract,
        private readonly Render $render,
        private readonly Apply $apply,
        private readonly UseProfile $useProfile,
        private readonly DeployAssets $deployAssets,
        private readonly string $toolRoot,
        private readonly ?\DateTimeImmutable $clock = null,
    ) {
    }

    /**
     * @param array{
     *   yes?: bool,
     *   force_refresh?: bool,
     *   skip_use_dev?: bool
     * } $options
     * @return array{lines: list<string>, exit_code: int}
     */
    public function run(array $options = []): array
    {
        $lines = [];
        $yes = (bool) ($options['yes'] ?? false);
        $forceRefresh = (bool) ($options['force_refresh'] ?? false);
        $skipUseDev = (bool) ($options['skip_use_dev'] ?? false);

        $preflight = $this->preflight();
        foreach ($preflight['lines'] as $line) {
            $lines[] = $line;
        }
        if (!$preflight['passed']) {
            $lines[] = 'Deploy:       ABORT — preflight failed';

            return ['lines' => $lines, 'exit_code' => 2];
        }

        if (!$this->validate->testCanaryPresent()) {
            $lines[] = 'Deploy:       running init (test canary missing)';
            $this->init->run();
            $lines[] = 'Deploy:       init complete';
        } else {
            $lines[] = 'Deploy:       init skipped (test canary present)';
        }

        if (!$this->validate->hasTestKingdomRows()) {
            $lines[] = 'Deploy:       running bootstrap (test kingdoms missing)';
            $bootstrapResult = $this->bootstrap->run(['yes' => $yes]);
            foreach ($bootstrapResult['lines'] as $line) {
                $lines[] = $line;
            }
            if ($bootstrapResult['exit_code'] !== 0) {
                $lines[] = 'Deploy:       ABORT — bootstrap failed';

                return ['lines' => $lines, 'exit_code' => $bootstrapResult['exit_code']];
            }
        } else {
            $lines[] = 'Deploy:       bootstrap skipped (test kingdoms present)';
        }

        $gate = $this->validate->run(Validate::MODE_PRE_APPLY);
        foreach ($gate['lines'] as $line) {
            $lines[] = $line;
        }
        if (!$gate['passed']) {
            foreach ($this->remediationHints($gate['lines']) as $hint) {
                $lines[] = $hint;
            }
            $lines[] = 'Deploy:       ABORT — validation failed';

            return ['lines' => $lines, 'exit_code' => 2];
        }

        if ($skipUseDev) {
            $lines[] = 'Deploy:       use dev skipped (--skip-use-dev)';
        } else {
            $useResult = $this->useProfile->run(UseProfile::PROFILE_DEV);
            foreach ($useResult['lines'] as $line) {
                $lines[] = $line;
            }
        }

        $anchorStale = LastRender::isStale($this->toolRoot, $forceRefresh, $this->clock);
        $heraldryDrift = !$anchorStale && $this->heraldryManifestNeedsRefresh();

        if ($anchorStale || $heraldryDrift) {
            $lines[] = $heraldryDrift && !$anchorStale
                ? 'Deploy:       heraldry drift detected — forcing SQL refresh'
                : 'Deploy:       daily refresh (render anchor stale)';
            $extractResult = $this->extract->run();
            $lines[] = 'Deploy:       extract complete from ' . $extractResult['source'];

            $renderResult = $this->render->run();
            $lines[] = 'Deploy:       render complete → ' . $renderResult['output'];

            $applyResult = $this->apply->run(['yes' => $yes]);
            foreach ($applyResult['lines'] as $line) {
                $lines[] = $line;
            }
            if ($applyResult['exit_code'] !== 0) {
                $lines[] = 'Deploy:       ABORT — daily refresh apply failed';

                return ['lines' => $lines, 'exit_code' => $applyResult['exit_code']];
            }
        } else {
            $lines[] = 'Deploy:       daily refresh skipped (render anchored today)';
        }

        try {
            $assetDeploy = $this->deployAssets->run();
            $lines[] = 'Deploy:       deploy-assets → ' . count($assetDeploy['files']) . ' files';
            $lines[] = sprintf(
                'Deploy:       assets kingdom %d, park %d, player heraldry %d, portraits %d',
                $assetDeploy['kingdom_count'],
                $assetDeploy['park_count'],
                $assetDeploy['player_heraldry_count'],
                $assetDeploy['player_portrait_count'],
            );
            if ($assetDeploy['manifest_ok'] === true) {
                $lines[] = 'Deploy:       asset manifest ok';
            }
        } catch (ValidationException $e) {
            $lines[] = 'Deploy:       deploy-assets FAIL — ' . $e->getMessage();
            $lines[] = 'Remediation:  bin/ork-db generate-assets && bin/ork-db deploy-assets';
            $lines[] = 'Deploy:       ABORT — deploy-assets failed';

            return ['lines' => $lines, 'exit_code' => 2];
        }

        $post = $this->validate->run(Validate::MODE_POST_APPLY, true);
        foreach ($post['lines'] as $line) {
            $lines[] = $line;
        }
        if (!$post['passed']) {
            foreach ($this->remediationHints($post['lines'], true) as $hint) {
                $lines[] = $hint;
            }
            $lines[] = 'Deploy:       ABORT — post-apply validation failed';

            return ['lines' => $lines, 'exit_code' => 2];
        }

        foreach ($this->statusLines() as $line) {
            $lines[] = $line;
        }

        $lines[] = 'Deploy:       complete';

        return ['lines' => $lines, 'exit_code' => 0];
    }

    /** @return array{passed: bool, lines: list<string>} */
    public function preflight(): array
    {
        $info = $this->tier->classify();
        $lines = [];
        $passed = true;

        if (!$info['sandbox_reachable']) {
            $passed = false;
            $lines[] = 'Preflight:    FAIL — sandbox unreachable';
            $lines[] = 'Remediation:  docker compose -f docker-compose.php8.yml up -d ork3testdb';
        } else {
            $lines[] = 'Preflight:    PASS — sandbox reachable';
        }

        if (!$info['mirror_reachable']) {
            $passed = false;
            $lines[] = 'Preflight:    FAIL — mirror unreachable';
            $lines[] = 'Remediation:  docker compose -f docker-compose.php8.yml up -d ork3db';
        } else {
            $lines[] = 'Preflight:    PASS — mirror reachable';
        }

        return ['passed' => $passed, 'lines' => $lines];
    }

    /**
     * @param list<string> $validateLines
     * @return list<string>
     */
    public function remediationHints(array $validateLines, bool $postApply = false): array
    {
        $text = implode("\n", $validateLines);
        $hints = [];

        if (str_contains($text, 'Connection:   FAIL')) {
            $hints[] = 'Remediation:  docker compose -f docker-compose.php8.yml up -d ork3testdb';
        }
        if (str_contains($text, 'Prod canary:  FAIL')) {
            $hints[] = 'Remediation:  ABORT — sandbox target looks like production; do not proceed';
        }
        if (str_contains($text, 'Test canary:  FAIL')) {
            $hints[] = 'Remediation:  bin/ork-db init';
        }
        if (str_contains($text, 'Kingdoms:     FAIL') || str_contains($text, 'Parks:        FAIL')) {
            $hint = $postApply
                ? 'Remediation:  bin/ork-db deploy-sandbox --force-refresh'
                : 'Remediation:  bin/ork-db bootstrap --yes';
            $hints[] = $hint;
        }
        if (str_contains($text, 'Blocklist:    FAIL')) {
            $hints[] = 'Remediation:  inspect tools/ork-db/rendered/sandbox.sql and re-run apply';
        }
        if (str_contains($text, 'Assets:       FAIL')) {
            $hints[] = 'Remediation:  bin/ork-db deploy-sandbox --force-refresh';
            $hints[] = 'Remediation:  bin/ork-db generate-assets && bin/ork-db deploy-assets';
        }

        if ($hints === []) {
            $hints[] = 'Remediation:  bin/ork-db validate --mode '
                . ($postApply ? 'post-apply' : 'pre-apply');
        }

        return $hints;
    }

    /** @return list<string> */
    private function statusLines(): array
    {
        $info = $this->tier->classify();
        $dataEnabled = $info['tier'] === DeploymentTier::LOCAL ? 'enabled' : 'disabled';
        $lines = [
            'Status:       tier ' . $info['tier'],
            'Status:       mirror ' . $this->wiring->mirrorTargetLabel()
                . ' (' . ($info['mirror_reachable'] ? 'reachable' : 'unreachable') . ')',
            'Status:       sandbox ' . $this->wiring->sandboxTargetLabel()
                . ' (' . ($info['sandbox_reachable'] ? 'reachable' : 'unreachable') . ')',
            'Status:       data commands ' . $dataEnabled,
        ];

        $metadata = LastRender::read($this->toolRoot);
        if ($metadata !== null) {
            $lines[] = 'Status:       last render anchor ' . $metadata['anchor_date'];
        }

        return $lines;
    }

    private function heraldryManifestNeedsRefresh(): bool
    {
        $metadata = LastRender::read($this->toolRoot);
        if ($metadata === null) {
            return false;
        }

        return $this->validate->heraldryManifestDrifted(
            $this->render->mundaneHeraldryIdsForSeed((int) $metadata['content_seed'])
        );
    }
}
