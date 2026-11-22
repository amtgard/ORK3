<?php

/**
 * Dues expiry banner (default.theme, class.DuesNotice.php): dismiss for this
 * session. The key names the notice (state + dues-until date), so a dismissal
 * stops applying as soon as the dues date changes or the notice turns lapsed.
 */
class Controller_DuesAjax extends Controller
{
    public function dismiss()
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['Status' => ['Status' => 1, 'Error' => 'Not logged in']]);
            return;
        }
        $key = preg_replace('/[^a-z0-9:\-]/', '', (string)($_POST['key'] ?? ''));
        if ($key === '') {
            echo json_encode(['Status' => ['Status' => 1, 'Error' => 'Missing key']]);
            return;
        }
        $this->session->dues_notice_dismissed = $key;
        echo json_encode(['Status' => ['Status' => 0]]);
        // Plain JSON: without this the router wraps the page theme around the response.
        exit;
    }
}
