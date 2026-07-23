<?php

class Controller_UnitAjax extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct();
    }

    public function banner($p = null)
    {
        header('Content-Type: application/json');

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        $params  = explode('/', $p ?? '');
        $unit_id = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
        $action  = $params[1] ?? '';

        if (!valid_id($unit_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid Unit ID.']);
            exit;
        }

        $this->load_model('Banner');
        $this->Banner->handle_ajax(
            'Unit',
            $action,
            $unit_id,
            $this->session->token,
            $_POST,
            $_FILES,
        );
    }

}
