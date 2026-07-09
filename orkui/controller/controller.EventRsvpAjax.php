<?php

class Controller_EventRsvpAjax extends Controller
{
    private $eventApi;

    public function __construct()
    {
        parent::__construct();
        $this->eventApi = new APIModel('Event');
    }

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
        $r = $this->eventApi->SetRsvp([
            'EventCalendarDetailId' => $detailId,
            'MundaneId' => $uid,
            'Status' => $status,
            'EndDateGate' => 'datetime',
        ]);
        if (!$this->_eventApiOk($r)) {
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
        $r = $this->eventApi->WithdrawRsvp([
            'EventCalendarDetailId' => $detailId,
            'MundaneId' => $uid,
        ]);
        if (!$this->_eventApiOk($r)) {
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
