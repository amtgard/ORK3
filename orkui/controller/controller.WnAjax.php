<?php

class Controller_WnAjax extends Controller
{
    public function dismiss()
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['Status' => ['Status' => 1, 'Error' => 'Not logged in']]);
            return;
        }
        $uid = (int)$this->session->user_id;
        $version = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['version'] ?? '');
        if (!$version) {
            echo json_encode(['Status' => ['Status' => 1, 'Error' => 'Missing version']]);
            return;
        }
        $result = Ork3::$Lib->player->DismissWhatsNew($uid, $version);
        echo json_encode(['Status' => ['Status' => (int) ($result['Status'] ?? 1)]]);
    }
}
