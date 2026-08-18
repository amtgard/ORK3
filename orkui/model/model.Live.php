<?php

class Model_Live extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Live = new JSONModel('Live');
    }

    public function stats(string $token = ''): array
    {
        return $this->Live->GetStats($token);
    }

    public function recent(string $token = ''): array
    {
        return $this->Live->GetRecent($token);
    }
}
