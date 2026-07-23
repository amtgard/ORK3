<?php

class Model_Award extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Award = new APIModel('Award');
        $this->Kingdom = new APIModel('Kingdom');
    }

    public function fetch_award_option_list($kingdom_id = 0, $officer_role = null)
    {
        return $this->_award()->GetAwardOptionListHtml((int) $kingdom_id, $officer_role);
    }

    private function _award(): Award
    {
        return new Award();
    }
}
