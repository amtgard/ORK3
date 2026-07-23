<?php

class Controller_EventRsvpAjax extends Controller
{
    private function requireLogin()
    {
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
    }

    public function set($p = null)
    {
        header('Content-Type: application/json');
        $this->requireLogin();

        $detailId = (int)($_POST['EventCalendarDetailId'] ?? 0);
        $status   = (string)($_POST['Status'] ?? '');
        if ($detailId <= 0 || !Event::IsAllowedRsvpStatus($status)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid parameters']);
            exit;
        }

        $uid = (int)$this->session->user_id;
        $this->load_model('Event');
        $r = $this->Event->set_rsvp_dated($detailId, $uid, $status, (string) ($this->session->token ?? ''));
        if (!$this->Event->event_api_ok($r)) {
            $error = is_array($r['Status'] ?? null)
                ? ($r['Status']['Detail'] ?? $r['Status']['Error'] ?? 'Error')
                : ($r['Detail'] ?? $r['Error'] ?? 'Error');
            $code = is_array($r['Status'] ?? null)
                ? (int)($r['Status']['Status'] ?? 1)
                : (int)($r['Status'] ?? 1);
            echo json_encode(['status' => $code, 'error' => $error]);
            exit;
        }

        echo json_encode([
            'status'           => 0,
            'my_status'        => (string)($r['MyStatus'] ?? ''),
            'going_count'      => (int)($r['Going'] ?? 0),
            'interested_count' => (int)($r['Interested'] ?? 0),
        ]);
        exit;
    }

    public function withdraw($p = null)
    {
        header('Content-Type: application/json');
        $this->requireLogin();

        $detailId = (int)($_POST['EventCalendarDetailId'] ?? 0);
        if ($detailId <= 0) {
            echo json_encode(['status' => 1, 'error' => 'Invalid parameters']);
            exit;
        }

        $uid = (int)$this->session->user_id;
        $this->load_model('Event');
        $r = $this->Event->withdraw_rsvp_self($detailId, $uid, (string) ($this->session->token ?? ''));
        if (!$this->Event->event_api_ok($r)) {
            $error = is_array($r['Status'] ?? null)
                ? ($r['Status']['Detail'] ?? $r['Status']['Error'] ?? 'Error')
                : ($r['Detail'] ?? $r['Error'] ?? 'Error');
            $code = is_array($r['Status'] ?? null)
                ? (int)($r['Status']['Status'] ?? 1)
                : (int)($r['Status'] ?? 1);
            echo json_encode(['status' => $code, 'error' => $error]);
            exit;
        }

        echo json_encode([
            'status'           => 0,
            'my_status'        => '',
            'going_count'      => (int)($r['Going'] ?? 0),
            'interested_count' => (int)($r['Interested'] ?? 0),
        ]);
        exit;
    }
}
