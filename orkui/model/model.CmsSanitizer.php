<?php

/**
 * Model_CmsSanitizer — thin pass-through to the CmsSanitizer lib.
 *
 * The base Model constructor auto-instantiates new APIModel('CmsSanitizer')
 * (because system/lib/ork3/class.CmsSanitizer.php exists), and Model::__call
 * forwards any unknown method to it. The explicit methods below mirror the
 * lib surface for clarity; all are pure forwards (no business logic here —
 * sanitization lives in the lib).
 *
 * CmsSanitizer's entry points are static, but APIModel/Model::__call routes
 * through an instance; calling them as instance methods works because PHP
 * permits invoking a static method via an object handle.
 */
class Model_CmsSanitizer extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->CmsSanitizer = new APIModel('CmsSanitizer');
    }

    public function clean($html)
    {
        return $this->CmsSanitizer->Clean($html);
    }

    public function clean_fragment($html)
    {
        return $this->CmsSanitizer->CleanFragment($html);
    }
}
