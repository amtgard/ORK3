<?php

class Errors {
    const SUCCESS = 0;
    const GENERAL_ERROR = 1;
    const CLASS_ERROR = 2;
    
    const NO_AUTH = 3;
    const INVALID_PARAMETER = 4;
    
    public static function Success($detail = null, $value = null) {
        return Errors::Message(Errors::SUCCESS, $detail, $value);
    }
    
    public static function NoAuthorization($detail = null) {
        return Errors::Message(Errors::NO_AUTH, $detail);
    }
    
    public static function InvalidParameter($detail = null) {
        return Errors::Message(Errors::INVALID_PARAMETER, $detail);
    }
    
    private static function Message($code, $detail = null, $value = '') {
        return array( 'Result' => $detail, 'Status' => $code==Errors::SUCCESS, 'Code' => $code, 'Value' => $value );
    }
    
    public static function IsSuccess($error) {
        return $error['Status'];
    }
}

?>