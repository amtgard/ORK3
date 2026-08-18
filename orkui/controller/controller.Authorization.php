<?php

class Controller_Authorization extends Controller
{
    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        $this->Authorization = new APIModel('Authorization');
    }

    public function index($action = null)
    {

    }

    /**
     * HTTP Route always passes a path segment string. Auth mutations belong on
     * Admin/authorization (and AJAX peers) with a full request array — never call
     * AddAuthorization with a bare id string (PHP 8 TypeError → 500).
     */
    public function add_auth($request = null)
    {
        if (!is_array($request)) {
            header('Location: ' . UIR . 'Admin/authorization');
            exit;
        }
        return $this->Authorization->AddAuthorization($request);
    }

    public function del_auth($request = null)
    {
        if (!is_array($request)) {
            header('Location: ' . UIR . 'Admin/authorization');
            exit;
        }
        return $this->Authorization->RemoveAuthorization($request);
    }
}
