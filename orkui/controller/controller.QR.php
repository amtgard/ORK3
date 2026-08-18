<?php

class Controller_QR extends Controller
{
    /**
     * Route: QR/link/{token}
     * Returns JSON { status: 0, data: "<base64 PNG>" }
     */
    public function link($token = null)
    {
        $token = preg_replace('/[^a-f0-9]/', '', (string)($token ?? ''));
        if (strlen($token) !== 48) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/json');
            echo json_encode(['status' => 1, 'error' => 'Invalid token']);
            exit;
        }

        $this->load_model('Attendance');
        $info = $this->Attendance->get_attendance_link_info($token);
        if (($info['Status'] ?? 1) != 0) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: application/json');
            echo json_encode(['status' => 1, 'error' => 'Link not found']);
            exit;
        }

        $url = HTTP_UI_REMOTE . 'index.php?Route=SignIn/index/' . $token;

        require_once(DIR_LIB . 'phpqrcode/phpqrcode.php');

        $tmpfile = tempnam(sys_get_temp_dir(), 'ork_qr_') . '.png';
        QRcode::png($url, $tmpfile, QR_ECLEVEL_M, 6, 2);
        $png = (file_exists($tmpfile) && filesize($tmpfile) > 0) ? file_get_contents($tmpfile) : false;
        @unlink($tmpfile);

        // Discard any stray output (PHP notices, logtrace, etc.) before sending JSON
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json');

        if (!$png) {
            echo json_encode(['status' => 1, 'error' => 'QR generation failed']);
            exit;
        }

        echo json_encode(['status' => 0, 'data' => base64_encode($png)]);
        exit;
    }

}
