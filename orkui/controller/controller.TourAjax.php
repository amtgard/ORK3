<?php

/**
 * OrkTour progress endpoint. ork-tour.js POSTs here (form-encoded) when the
 * signed-in person finishes or turns down a tour. Validation lives in
 * Player::save_tour_progress(); this only adapts the request and the response.
 */
class Controller_TourAjax extends Controller
{
    // Every branch exits: returning would let the framework wrap the JSON in the
    // HTML page (WnAjax::dismiss has that bug; its caller ignores the response).
    public function save()
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['Status' => ['Status' => 1, 'Error' => 'Not logged in']]);
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            echo json_encode(['Status' => ['Status' => 1, 'Error' => 'POST required']]);
            exit;
        }
        $uid = (int)$this->session->user_id;
        $this->load_model('Player');
        $result = $this->Player->save_tour_progress(
            $uid,
            (string)($_POST['tour_id'] ?? ''),
            (string)($_POST['version'] ?? ''),
            (string)($_POST['status'] ?? ''),
            (string)($_POST['last_step'] ?? '')
        );
        if ((int)($result['Status'] ?? 1) === 0) {
            echo json_encode(['Status' => ['Status' => 0]]);
            exit;
        }
        echo json_encode(['Status' => ['Status' => 1, 'Error' => (string)($result['Detail'] ?? 'Could not save tour progress')]]);
        exit;
    }
}
