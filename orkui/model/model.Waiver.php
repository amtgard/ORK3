<?php

class Model_Waiver extends Model {
	// All method calls forwarded to system/lib/ork3/class.Waiver.php via APIModel::__call

	/* ── Waivers Compliance Report passthroughs ──────────────────────
	 * These delegate to the WaiverReport domain class (separate from the
	 * Waiver domain class). Auth is enforced by the controller before
	 * these are reached.
	 */
	function get_waiver_stats($type, $id) {
		return (new APIModel('WaiverReport'))->GetStats($type, $id);
	}

	function get_waiver_player_list($type, $id) {
		return (new APIModel('WaiverReport'))->GetPlayerStatusList($type, $id);
	}

	function get_waiver_monthly_series($type, $id, $months = 12) {
		return (new APIModel('WaiverReport'))->GetMonthlySeries($type, $id, $months);
	}

	function get_waiver_version_history($kingdom_id, $scope) {
		return (new APIModel('WaiverReport'))->GetVersionHistory($kingdom_id, $scope);
	}
}

?>
