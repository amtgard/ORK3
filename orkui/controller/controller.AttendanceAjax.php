<?php

class Controller_AttendanceAjax extends Controller
{
    public function park($p = null)
    {
        header('Content-Type: application/json');
        $parts   = explode('/', $p ?? '');
        $park_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $action  = $parts[1] ?? '';

        if (!valid_id($park_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid park ID']);
            exit;
        }

        if ($action === 'add') {
            $this->load_model('Attendance');
            $mundaneId = (int)($_POST['MundaneId'] ?? 0);
            $r = $this->Attendance->add_attendance(
                $this->session->token,
                $_POST['AttendanceDate'] ?? date('Y-m-d'),
                $park_id,
                null,
                $mundaneId,
                (int)($_POST['ClassId']   ?? 0),
                (float)($_POST['Credits'] ?? 1)
            );
            if ($r['Status'] == 0) {
                // Auto-reactivate the player's profile if marked inactive. Adding
                // attendance for an inactive player implicitly reactivates them.
                $reactivated = 0;
                if ($mundaneId > 0) {
                    global $DB;
                    $DB->Clear();
                    $chk = $DB->DataSet("SELECT active FROM " . DB_PREFIX . "mundane WHERE mundane_id = " . $mundaneId . " LIMIT 1");
                    if ($chk && $chk->Size() > 0 && $chk->Next() && (int)$chk->active === 0) {
                        $DB->Clear();
                        $DB->Execute("UPDATE " . DB_PREFIX . "mundane SET active = 1 WHERE mundane_id = " . $mundaneId);
                        $reactivated = 1;
                    }
                }
                if ($mundaneId) {
                    $this->load_model('Player');
                    $key = Ork3::$Lib->ghettocache->key(['MundaneId' => $mundaneId]);
                    Ork3::$Lib->ghettocache->bust('Model_Player.fetch_player_details', $key);
                }
                echo json_encode(['status' => 0, 'attendanceId' => (int)($r['Detail'] ?? 0), 'reactivated' => $reactivated]);
            } else {
                echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
            }
        } elseif ($action === 'getday') {
            $this->load_model('Attendance');
            $date = $_GET['date'] ?? date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid date']);
                exit;
            }
            $r = $this->Attendance->get_attendance_for_date($park_id, $date);
            $seen    = [];
            $entries = [];
            foreach ($r['Attendance'] ?? [] as $att) {
                $mid = (int)$att['MundaneId'];
                $aid = (int)($att['AttendanceId'] ?? $att['attendance_id'] ?? 0);
                if (isset($seen[$mid]) && $seen[$mid] >= $aid) {
                    continue;
                }
                $seen[$mid] = $aid;
                $entries[$mid] = [
                    'AttendanceId' => $aid,
                    'MundaneId'   => $mid,
                    'Persona'     => (string)($att['Persona'] ?? $att['AttendancePersona'] ?? ''),
                    'ClassId'     => (int)($att['ClassId'] ?? 0),
                    'Credits'     => (float)($att['Credits'] ?? 1),
                ];
            }
            echo json_encode(['status' => 0, 'entries' => array_values($entries)]);
        } elseif ($action === 'weather') {
            // Historic weather for /Attendance/park/{id} day pages.
            // Route: AttendanceAjax/park/{park_id}/weather/{YYYY-MM-DD}
            $date = $parts[2] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid date']);
                exit;
            }
            $wx = Ork3::$Lib->weather->archive_for_date($park_id, $date);
            echo json_encode(['status' => 0, 'weather' => $wx]);
        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
        }
        exit;
    }

    /**
     * Coord-based historic weather lookup.
     * Route: AttendanceAjax/weather_at/{lat}/{lng}/{YYYY-MM-DD}
     * Used by callers whose location isn't a park (e.g., an event with its own
     * venue coords). Same auth/cache/output shape as the park-based version.
     */
    public function weather_at($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $parts = explode('/', $p ?? '');
        $lat   = $parts[0] ?? '';
        $lng   = $parts[1] ?? '';
        $date  = $parts[2] ?? '';
        if (!is_numeric($lat) || !is_numeric($lng)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid coordinates']);
            exit;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid date']);
            exit;
        }
        $wx = Ork3::$Lib->weather->archive_for_coords((float)$lat, (float)$lng, $date);
        echo json_encode(['status' => 0, 'weather' => $wx]);
        exit;
    }

    public function attendance($p = null)
    {
        header('Content-Type: application/json');
        $parts         = explode('/', $p ?? '');
        $attendance_id = (int)($parts[0] ?? 0);
        $action        = $parts[1] ?? '';

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        if (!valid_id($attendance_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid attendance ID']);
            exit;
        }

        $this->load_model('Attendance');

        if ($action === 'edit') {
            $date      = trim($_POST['Date']      ?? '');
            $credits   = (float)($_POST['Credits'] ?? 1);
            $classId   = (int)($_POST['ClassId']   ?? 0);
            $mundaneId = (int)($_POST['MundaneId'] ?? 0);
            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid date']);
                exit;
            }
            if (!valid_id($classId)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid class']);
                exit;
            }
            $r = $this->Attendance->update_attendance($this->session->token, $attendance_id, $date, $credits, $classId, $mundaneId);
            if ($r['Status'] == 0) {
                $editorId      = (int)$this->session->user_id;
                $editorPersona = '';
                global $DB;
                $DB->Clear();
                $row = $DB->DataSet("SELECT persona FROM " . DB_PREFIX . "mundane WHERE mundane_id = " . $editorId . " LIMIT 1");
                if ($row && $row->Size() > 0 && $row->Next()) {
                    $editorPersona = $row->persona;
                }
                echo json_encode(['status' => 0, 'editor_id' => $editorId, 'editor_persona' => $editorPersona]);
            } else {
                echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
            }

        } elseif ($action === 'delete') {
            $mundaneId = (int)($_POST['MundaneId'] ?? 0);
            $r = $this->Attendance->delete_attendance($this->session->token, $attendance_id, $mundaneId);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
        }
        exit;
    }

    public function link($p = null)
    {
        header('Content-Type: application/json');
        $parts  = explode('/', $p ?? '');
        // Format: {scope}/{id}/create  where scope = park|kingdom
        $scope  = $parts[0] ?? '';
        $id     = (int)($parts[1] ?? 0);
        $action = $parts[2] ?? '';

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        // Format: {scope}/{id}/create|list  or  delete/{link_id}
        // $scope = park|kingdom|delete, $id = entity_id or link_id, $action = create|list
        $this->load_model('Attendance');

        if ($scope === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // delete/{link_id}
            if (!valid_id($id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid link ID']);
                exit;
            }
            $r = $this->Attendance->delete_attendance_link($this->session->token, $id);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'list') {
            if (!in_array($scope, ['park', 'kingdom', 'event'], true)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid scope']);
                exit;
            }
            if (!valid_id($id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid ID']);
                exit;
            }
            $ecdid = (int)($_GET['EventCalendarDetailId'] ?? 0);
            $r = $this->Attendance->get_attendance_links($this->session->token, $scope, $id, $ecdid);
            if ($r['Status'] == 0) {
                $links = array_map(function ($lnk) {
                    $lnk['Url'] = HTTP_UI_REMOTE . 'index.php?Route=SignIn/index/' . $lnk['Token'];
                    // ExpiresAt is stored as UTC. Emit ExpiresAtIso so the client can
                    // safely parse and format in the browser's local timezone instead
                    // of JS misreading the bare "Y-m-d H:i:s" as local time.
                    if (!empty($lnk['ExpiresAt'])) {
                        $lnk['ExpiresAtIso'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($lnk['ExpiresAt'] . ' UTC'));
                    }
                    return $lnk;
                }, $r['Detail'] ?? []);
                echo json_encode(['status' => 0, 'links' => $links]);
            } else {
                echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
            }

        } elseif ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!in_array($scope, ['park', 'kingdom', 'event'], true)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid scope']);
                exit;
            }
            if (!valid_id($id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid ID']);
                exit;
            }
            // Credits is required for all scopes
            if (!isset($_POST['Credits']) || $_POST['Credits'] === '' || (float)$_POST['Credits'] <= 0) {
                echo json_encode(['status' => 1, 'error' => 'Credits is required.']);
                exit;
            }
            $credits = (float)$_POST['Credits'];

            $args = ['Token' => $this->session->token, 'Credits' => $credits];
            if ($scope === 'park') {
                $args['ParkId'] = $id;
                $args['Hours'] = min(120, max(1, (int)($_POST['Hours'] ?? 3)));
            } elseif ($scope === 'kingdom') {
                $args['KingdomId'] = $id;
                $args['Hours'] = min(120, max(1, (int)($_POST['Hours'] ?? 3)));
            } else {
                $args['EventId'] = $id;
                $args['EventCalendarDetailId'] = (int)($_POST['EventCalendarDetailId'] ?? 0);
            }

            $r = $this->Attendance->create_attendance_link($args);
            if ($r['Status'] == 0) {
                $detail     = $r['Detail'];
                $token      = is_array($detail) ? ($detail['Token'] ?? '') : $detail;
                $expires_at = is_array($detail) ? ($detail['ExpiresAt'] ?? null) : null;
                // expires_at is stored as UTC — append 'UTC' so strtotime doesn't
                // mis-parse it as PHP-local time. date() then converts back to
                // the user's display TZ (server's local TZ for now).
                $expires_ts = $expires_at ? strtotime($expires_at . ' UTC') : (time() + (($args['Hours'] ?? 3) * 3600));
                $url        = HTTP_UI_REMOTE . 'index.php?Route=SignIn/index/' . $token;
                $expires    = date('D, M j g:i a T', $expires_ts);
                // linkId in the response so the client-side "Remove" button can
                // hit the existing delete endpoint without a second round-trip.
                // expires_iso lets the client render expiry in the browser's local
                // timezone via Date(...).toLocaleString instead of getting the
                // server's timezone in the legacy `expires` string.
                $linkId      = is_array($detail) ? (int)($detail['LinkId'] ?? 0) : 0;
                $expires_iso = gmdate('Y-m-d\TH:i:s\Z', $expires_ts);
                echo json_encode(['status' => 0, 'url' => $url, 'token' => $token, 'expires' => 'Expires ' . $expires, 'expires_iso' => $expires_iso, 'linkId' => $linkId]);
            } else {
                echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
            }
        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
        }
        exit;
    }

    public function myclass($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $this->load_model('Attendance');
        $class_id = (int)$this->Attendance->get_player_last_class((int)$this->session->user_id);
        echo json_encode(['status' => 0, 'class_id' => $class_id]);
        exit;
    }

}
