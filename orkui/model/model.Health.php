<?php

class Model_Health extends Model
{
    public function ping_db(): bool
    {
        return $this->_health()->PingDb();
    }

    private function _health(): Health
    {
        return new Health();
    }
}
