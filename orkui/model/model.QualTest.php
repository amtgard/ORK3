<?php

class Model_QualTest extends Model
{
    public function can_manage(int $mundaneId, int $kingdomId): bool
    {
        return $this->_qual_test()->canManage($mundaneId, $kingdomId);
    }

    public function has_takeable_version(int $kingdomId, string $testType): bool
    {
        return $this->_qual_test()->hasTakeableVersion($kingdomId, $testType);
    }

    public function player_results(int $mundaneId, int $kingdomId): array
    {
        return $this->_qual_test()->getPlayerResults($mundaneId, $kingdomId);
    }

    public function config(int $kingdomId, string $testType): array
    {
        return $this->_qual_test()->getConfig($kingdomId, $testType);
    }

    public function test_results(int $kingdomId, string $testType): array
    {
        return $this->_qual_test()->getTestResults($kingdomId, $testType);
    }

    public function report_stats(int $kingdomId, string $testType): array
    {
        return $this->_qual_test()->getTestReportStats($kingdomId, $testType);
    }

    public function kingdom_name(int $kingdomId): string
    {
        return $this->_qual_test()->getKingdomName($kingdomId);
    }

    private function _qual_test(): QualTest
    {
        return new QualTest();
    }
}
