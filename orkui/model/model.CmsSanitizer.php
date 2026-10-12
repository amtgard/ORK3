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
 *
 * Calling convention: call the snake_case wrapper where one exists; a
 * PascalCase reach-around via Model::__call is the sanctioned form for lib
 * methods that have no wrapper.
 */
class Model_CmsSanitizer extends Model
{
    public function clean($html)
    {
        return $this->CmsSanitizer->Clean($html);
    }
}
