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

        // Logged-out visitors (people and chat-app link crawlers alike) get the
        // link's own face instead of a bare bounce to Login; see require_login().
        if (!$this->require_login('SignIn/index/' . $link_token, function () use ($link_token) {
            return $this->_link_preview($link_token);
        })) {
            return;
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

        // Resolve scope name + type from enriched link info.
        $scope      = $this->_resolve_scope($link);
        $scope_name = $scope['name'];
        $scope_type = $scope['type'];

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

        $last_class_id = (int)$this->Attendance->get_player_last_class((int)$this->session->user_id);
        $last_class_name = '';
        if ($last_class_id > 0) {
            foreach (array_values($classes) as $c) {
                if ((int)$c['ClassId'] === $last_class_id) {
                    $last_class_name = $c['Name'];
                    break;
                }
            }
        }

        $classes = $this->Attendance->enrich_classes_with_progress((int)$this->session->user_id, array_values($classes));

        $this->data['link']            = $link;
        $this->data['link_online']     = (int)($link['Online'] ?? 0) === 1;
        $this->data['scope_name']      = $scope_name;
        $this->data['scope_type']      = $scope_type;
        $this->data['link_token']      = $link_token;
        $this->data['classes']         = $classes;
        $this->data['last_class_id']   = $last_class_id;
        $this->data['last_class_name'] = $last_class_name;
        $this->data['existing']        = $existing; // null, or ['AttendanceId','ClassId','ClassName']
        $this->template = 'SignIn_index.tpl';
    }

    /**
     * Scope (park / event / kingdom) behind a sign-in link, from the
     * enriched GetAttendanceLinkInfo detail. With $with_heraldry the scope's
     * heraldry URL is resolved too (only the link-preview card needs it).
     * Returns ['type','name','kingdom','image'].
     */
    private function _resolve_scope(array $link, $with_heraldry = false)
    {
        $scope = array(
            'type'    => (string)($link['ScopeType'] ?? 'park'),
            'name'    => 'your group',
            'kingdom' => '',
            'image'   => '',
        );
        if ($scope['type'] === 'event') {
            $scope['name'] = (string)($link['EventName'] ?? '') ?: $scope['name'];
            if ($with_heraldry) {
                $scope['image'] = $this->_heraldry_url(DIR_EVENT_HERALDRY, HTTP_EVENT_HERALDRY, sprintf('%05d', (int)$link['EventId']));
            }
        } elseif (valid_id($link['ParkId'])) {
            $scope['type'] = 'park';
            $this->load_model('Park');
            $pi = $this->Park->get_park_info($link['ParkId']);
            $scope['name']    = (string)($pi['ParkInfo']['ParkName'] ?? '') ?: $scope['name'];
            $scope['kingdom'] = (string)($pi['KingdomInfo']['KingdomName'] ?? '');
            if ($with_heraldry) {
                $scope['image'] = $this->_heraldry_url(DIR_PARK_HERALDRY, HTTP_PARK_HERALDRY, sprintf('%05d', (int)$link['ParkId']));
            }
        } elseif (valid_id($link['KingdomId'])) {
            $scope['type'] = 'kingdom';
            $this->load_model('Kingdom');
            $scope['name'] = $this->Kingdom->get_kingdom_name($link['KingdomId']) ?: $scope['name'];
            if ($with_heraldry) {
                $scope['image'] = $this->_heraldry_url(DIR_KINGDOM_HERALDRY, HTTP_KINGDOM_HERALDRY, sprintf('%04d', (int)$link['KingdomId']));
            }
        }
        return $scope;
    }

    /**
     * Absolute heraldry URL when the file exists, else ''. The file on disk is
     * the truth (same resolve as the event page header); the has_heraldry
     * column is only a hint and the default 00000 placeholder is not a card.
     */
    private function _heraldry_url($dir_base, $http_base, $name)
    {
        $file = Common::resolve_image_ext($dir_base, $name);
        if ($file === '' || !file_exists($dir_base . $file)) {
            return '';
        }
        return $http_base . $file . '?v=' . filemtime($dir_base . $file);
    }

    /**
     * What this link can say about itself to someone who is not logged in,
     * for Controller::require_login(): the og card for chat embeds plus the
     * heading/lines a person sees. Reveals nothing the public QR endpoint
     * does not already: scope name, credits and whether the link still works.
     */
    private function _link_preview($link_token)
    {
        $this->load_model('Attendance');
        $r   = $this->Attendance->get_attendance_link_info($link_token);
        $url = UIR . 'SignIn/index/' . $link_token;
        if (($r['Status'] ?? 1) != 0 || !is_array($r['Detail'] ?? null)) {
            $error = (string)($r['Detail'] ?? 'This sign-in link is invalid or has expired.');
            return array(
                'og'      => ork_signin_link_og(array('valid' => false, 'url' => $url, 'error' => $error)),
                'kicker'  => 'Attendance sign-in',
                'heading' => 'Sign-in link unavailable',
                'lines'   => array($error, 'Ask whoever posted it for a fresh link.'),
                'button'  => 'Log in to the ORK',
            );
        }
        $link    = $r['Detail'];
        $scope   = $this->_resolve_scope($link, true);
        $credits = (float)($link['Credits'] ?? 1);
        $kickers = array('park' => 'Park Sign-in', 'event' => 'Event Sign-in', 'kingdom' => 'Kingdom Sign-in');
        return array(
            'og' => ork_signin_link_og(array(
                'valid'        => true,
                'url'          => $url,
                'scope_type'   => $scope['type'],
                'scope_name'   => $scope['name'],
                'kingdom_name' => $scope['kingdom'],
                'expires_at'   => (string)($link['ExpiresAt'] ?? ''),
                'image'        => $scope['image'],
            )),
            'kicker'  => $kickers[$scope['type']] ?? 'Attendance Sign-in',
            'heading' => $scope['name'],
            'lines'   => array(
                rtrim(rtrim(number_format($credits, 2, '.', ''), '0'), '.') . ' credit' . ($credits == 1 ? '' : 's') . ' will be recorded to your attendance.',
                ork_signin_link_time_left($link['ExpiresAt'] ?? ''),
                'Log in to the ORK to sign yourself in.',
            ),
            'image'   => $scope['image'],
            'button'  => 'Log in to sign in',
        );
    }
}
