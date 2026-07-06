<?php

class Controller_SignIn extends Controller
{
    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        $this->data['page_title'] = 'Sign In';
    }

    public function index($p = null)
    {
        $link_token = preg_replace('/[^a-f0-9]/', '', (string)($p ?? ''));

        // Require login — redirect back here after
        if (!isset($this->session->user_id) || !(int)$this->session->user_id) {
            $this->session->location = 'SignIn/index/' . $link_token;
            header('Location: ' . UIR . 'Login/login');
            exit;
        }

        $this->load_model('Attendance');

        // Validate the link
        $link_result = $this->Attendance->get_attendance_link_info($link_token);
        if ($link_result['Status'] != 0) {
            $this->data['error']      = $link_result['Detail'] ?? 'This sign-in link is invalid or has expired.';
            $this->data['link_token'] = $link_token;
            $this->template = 'SignIn_index.tpl';
            return;
        }

        $link = $link_result['Detail'];

        // Resolve scope name + type. scope_type drives the page header so an
        // event link doesn't read "Park Sign-in" with just the event name as
        // the subtitle.
        $scope_name = 'your group';
        $scope_type = 'park';
        if (valid_id($link['EventId'] ?? 0)) {
            $scope_type = 'event';
            global $DB;
            $DB->Clear();
            $row = $DB->DataSet('SELECT name FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int)$link['EventId'] . ' LIMIT 1');
            if ($row && $row->Next()) {
                $scope_name = $row->name ?: $scope_name;
            }
        } elseif (valid_id($link['ParkId'])) {
            $scope_type = 'park';
            $this->load_model('Park');
            $scope_name = $this->Park->get_park_name($link['ParkId']) ?: $scope_name;
        } elseif (valid_id($link['KingdomId'])) {
            $scope_type = 'kingdom';
            $this->load_model('Kingdom');
            $scope_name = $this->Kingdom->get_kingdom_name($link['KingdomId']) ?: $scope_name;
        }

        // Get available classes
        $classes_result = $this->Attendance->get_classes();
        $classes = array_filter($classes_result['Classes'] ?? [], function ($c) {
            return (int)($c['Active'] ?? 1) === 1;
        });

        // Check whether the player already has a row for this link's scope
        // (today's date at the park, or this event's calendar detail). If so,
        // the page switches to a "change my class" flow rather than rejecting
        // them outright.
        $existing = $this->Attendance->get_existing_signin((int)$this->session->user_id, $link);

        // Handle submission. Two paths:
        //   - No existing row → consume the link and INSERT a new attendance row
        //   - Existing row    → UPDATE the class on that row (no new credit)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $class_id = (int)($_POST['ClassId'] ?? 0);
            if ($existing) {
                $r = $this->Attendance->update_self_signin_class(
                    $this->session->token,
                    $existing['AttendanceId'],
                    $class_id
                );
                $success_msg = 'Class updated. No additional credit was recorded.';
            } else {
                $r = $this->Attendance->use_attendance_link(
                    $this->session->token,
                    $link_token,
                    $class_id
                );
                $success_msg = '';
            }
            if ($r['Status'] == 0) {
                header('Location: ' . UIR . 'Player/profile/' . (int)$this->session->user_id);
                exit;
            } else {
                $this->data['error'] = $r['Detail'] ?? $r['Error'] ?? 'Could not record attendance.';
            }
        }

        // Get player's last class.
        // YapoMysql::DataSet() does NOT pre-call Next() — must call it manually to advance to the first row.
        $last_class_id   = 0;
        $last_class_name = '';
        global $DB;
        $DB->Clear();
        $last_row = $DB->DataSet('SELECT class_id FROM ' . DB_PREFIX . 'attendance WHERE mundane_id = ' . (int)$this->session->user_id . ' ORDER BY date DESC, attendance_id DESC LIMIT 1');
        if ($last_row && $last_row->Next() && (int)$last_row->class_id > 0) {
            $last_class_id = (int)$last_row->class_id;
            foreach (array_values($classes) as $c) {
                if ((int)$c['ClassId'] === $last_class_id) {
                    $last_class_name = $c['Name'];
                    break;
                }
            }
        }

        // Attach per-class progression: current credits (attendance + reconciled),
        // current level, credits-to-next-level. Helps the player pick the class
        // that will get the most out of this one credit. Thresholds mirror the
        // client-side calc at Player_index.tpl:277-289 — L2=5, L3=12, L4=21,
        // L5=34, L6=53.
        $uid = (int)$this->session->user_id;
        $per_class = array();
        $DB->Clear();
        $rs = $DB->DataSet('SELECT class_id, SUM(credits) AS c FROM ' . DB_PREFIX . 'attendance WHERE mundane_id = ' . $uid . ' GROUP BY class_id');
        if ($rs) {
            while ($rs->Next()) {
                $per_class[(int)$rs->class_id] = (float)$rs->c;
            }
        }
        $DB->Clear();
        $rs = $DB->DataSet('SELECT class_id, reconciled AS c FROM ' . DB_PREFIX . 'class_reconciliation WHERE mundane_id = ' . $uid);
        if ($rs) {
            while ($rs->Next()) {
                $cid = (int)$rs->class_id;
                $per_class[$cid] = (isset($per_class[$cid]) ? $per_class[$cid] : 0) + (float)$rs->c;
            }
        }
        // Threshold at index N (0-based) = credits needed to reach Level N+2.
        // Level 1 needs 0; hitting index 0 (=5) reaches Level 2, etc.
        $LEVEL_THRESHOLDS = array(5, 12, 21, 34, 53);
        $enriched = array();
        foreach (array_values($classes) as $c) {
            $cid = (int)$c['ClassId'];
            $credits = isset($per_class[$cid]) ? $per_class[$cid] : 0.0;
            $level = 1;
            if ($credits >= 53) {
                $level = 6;
            } elseif ($credits >= 34) {
                $level = 5;
            } elseif ($credits >= 21) {
                $level = 4;
            } elseif ($credits >= 12) {
                $level = 3;
            } elseif ($credits >=  5) {
                $level = 2;
            }
            $to_next = null;
            if ($level < 6) {
                $to_next = max(0, $LEVEL_THRESHOLDS[$level - 1] - $credits);
            }
            $c['Credits'] = $credits;
            $c['Level']   = $level;
            $c['ToNext']  = $to_next;
            $enriched[] = $c;
        }
        $classes = $enriched;

        $this->data['link']            = $link;
        $this->data['scope_name']      = $scope_name;
        $this->data['scope_type']      = $scope_type;
        $this->data['link_token']      = $link_token;
        $this->data['classes']         = $classes;
        $this->data['last_class_id']   = $last_class_id;
        $this->data['last_class_name'] = $last_class_name;
        $this->data['existing']        = $existing; // null, or ['AttendanceId','ClassId','ClassName']
        $this->template = 'SignIn_index.tpl';
    }
}
