<?php

class Model_Search extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function ranked_players(array $opts): array
    {
        return $this->_search_service()->RankedPlayers($opts);
    }

    // Grant-derived search capabilities (officer / admin / banned scope) for the viewer's token.
    public function viewer_caps($token): array
    {
        return $this->_search_service()->viewer_caps($token);
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
