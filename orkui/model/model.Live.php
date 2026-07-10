<?php

class Model_Live extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Live = new JSONModel('Live');
    }

    public function stats(): array
    {
        return $this->Live->GetStats();
    }

    public function recent(): array
    {
        return $this->Live->GetRecent();
    }
}
