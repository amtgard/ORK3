<?php

class Model_Search extends Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function scoped_player_search(array $request): array
    {
        $search = new SearchService();
        return $search->ScopedPlayerSearch($request);
    }

}
