<?php

class Model_Search extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function scoped_player_search(array $request): array
    {
        return $this->_search_service()->ScopedPlayerSearch($request);
    }

    public function universal_search(array $request): array
    {
        return $this->_search_service()->UniversalSearch($request);
    }

    public function get_unit_activity_counts(array $unitIds): array
    {
        return $this->_search_service()->GetUnitActivityCounts($unitIds);
    }

    private function _search_service(): SearchService
    {
        return new SearchService();
    }
}
