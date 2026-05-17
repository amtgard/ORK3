<?php

class Controller_UnitAjax extends Controller {

	public function __construct($call = null, $id = null) {
		parent::__construct();
	}

	public function banner($p = null) {
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

		$uid     = (int)$this->session->user_id;
		$canEdit = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_UNIT, $unit_id, AUTH_EDIT);
		if (!$canEdit) {
			echo json_encode(['status' => 1, 'error' => 'Not authorized to edit this unit.']);
			exit;
		}

		global $DB;

		if ($action === 'remove') {
			$DB->Clear();
			// Reset display toggles AND framing offsets to defaults so a future
			// upload starts fresh instead of inheriting the removed banner's
			// config.
			$DB->Execute('UPDATE ' . DB_PREFIX . 'unit SET has_banner = 0, banner_show_logo = 1, banner_vignette = 1, banner_offset_x = 50, banner_offset_y = 50 WHERE unit_id = ' . $unit_id);
			$base = DIR_UNIT_BANNER . sprintf('%05d', $unit_id);
			if (file_exists($base . '.jpg')) unlink($base . '.jpg');
			if (file_exists($base . '.png')) unlink($base . '.png');
			echo json_encode(['status' => 0]);
			exit;
		}

		if ($action === 'config') {
			// Refuse silent no-ops: config only meaningful with a banner present.
			$DB->Clear();
			$row = $DB->DataSet('SELECT has_banner FROM ' . DB_PREFIX . 'unit WHERE unit_id = ' . $unit_id);
			if (!$row || !$row->Next() || (int)$row->has_banner !== 1) {
				echo json_encode(['status' => 1, 'error' => 'Upload a banner first before saving settings.']);
				exit;
			}
			$showLogo = !empty($_POST['ShowLogo']) ? 1 : 0;
			$vignette = !empty($_POST['Vignette']) ? 1 : 0;
			$offX = max(0, min(100, (int)($_POST['OffsetX'] ?? 50)));
			$offY = max(0, min(100, (int)($_POST['OffsetY'] ?? 50)));
			$DB->Clear();
			$DB->Execute('UPDATE ' . DB_PREFIX . 'unit SET banner_show_logo = ' . $showLogo . ', banner_vignette = ' . $vignette . ', banner_offset_x = ' . $offX . ', banner_offset_y = ' . $offY . ' WHERE unit_id = ' . $unit_id);
			echo json_encode(['status' => 0]);
			exit;
		}

		if ($action === 'update') {
			if (empty($_FILES['Banner']['tmp_name'])) {
				echo json_encode(['status' => 1, 'error' => 'No file uploaded.']);
				exit;
			}
			$tmp  = $_FILES['Banner']['tmp_name'];
			$mime = $_FILES['Banner']['type'] ?? 'image/jpeg';
			// JPEG and PNG only: resolve_image_ext returns .jpg or .png and the
			// rest of the pipeline (storage filename, frontend cache-bust, banner
			// modal accept attribute) only knows those two. GIFs would land as
			// .jpg on disk with corrupt bytes.
			if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
				echo json_encode(['status' => 1, 'error' => 'Unsupported image type. Use JPG or PNG.']);
				exit;
			}
			if (!is_dir(DIR_UNIT_BANNER)) {
				@mkdir(DIR_UNIT_BANNER, 0775, true);
			}
			$ext  = ($mime === 'image/png') ? 'png' : 'jpg';
			$base = DIR_UNIT_BANNER . sprintf('%05d', $unit_id);
			// Delete any previous banner files (both extensions) before saving
			// the new one so we never leave the old image behind when the host
			// switches images. resolve_image_ext picks whichever survives.
			if (file_exists($base . '.jpg')) @unlink($base . '.jpg');
			if (file_exists($base . '.png')) @unlink($base . '.png');
			if (!@move_uploaded_file($tmp, $base . '.' . $ext)) {
				echo json_encode(['status' => 1, 'error' => 'Could not save uploaded file.']);
				exit;
			}
			$showLogo = !empty($_POST['ShowLogo']) ? 1 : 0;
			$vignette = !empty($_POST['Vignette']) ? 1 : 0;
			$offX = max(0, min(100, (int)($_POST['OffsetX'] ?? 50)));
			$offY = max(0, min(100, (int)($_POST['OffsetY'] ?? 50)));
			$DB->Clear();
			$DB->Execute('UPDATE ' . DB_PREFIX . 'unit SET has_banner = 1, banner_show_logo = ' . $showLogo . ', banner_vignette = ' . $vignette . ', banner_offset_x = ' . $offX . ', banner_offset_y = ' . $offY . ' WHERE unit_id = ' . $unit_id);
			// $DB->Execute() is void; the YapoMysql layer can silently swallow
			// failures (sql_mode=STRICT etc). Verify the update landed by
			// re-reading has_banner. If it didn't, roll back the file so we
			// don't leave an orphan whose flag is still 0.
			$DB->Clear();
			$verify = $DB->DataSet('SELECT has_banner FROM ' . DB_PREFIX . 'unit WHERE unit_id = ' . $unit_id);
			if (!$verify || !$verify->Next() || (int)$verify->has_banner !== 1) {
				@unlink($base . '.' . $ext);
				echo json_encode(['status' => 1, 'error' => 'Saved file but could not update the database. Please try again.']);
				exit;
			}
			echo json_encode(['status' => 0]);
			exit;
		}

		echo json_encode(['status' => 1, 'error' => 'Unknown action.']);
		exit;
	}

}
