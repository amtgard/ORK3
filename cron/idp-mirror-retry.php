<?php
/**
 * Hourly retry job for failed bastion-idp mirror writes.
 * Picks up ork_idp_auth rows where idp_mirror_status != 'synced'
 * and idp_mirror_last_attempt is null or older than 30 minutes.
 *
 * Run via system cron:
 *   0 * * * * php /var/www/cron/idp-mirror-retry.php
 */

putenv('ENVIRONMENT=' . (getenv('ENVIRONMENT') ?: 'DEV'));
require_once dirname(__DIR__) . '/startup.php';

global $DB;
$DB->Clear();
$rows = $DB->DataSet(
    "SELECT idp_user_id, mundane_id FROM " . DB_PREFIX . "idp_auth " .
    "WHERE idp_mirror_status IN ('pending','failed') " .
    "AND (idp_mirror_last_attempt IS NULL OR idp_mirror_last_attempt < DATE_SUB(NOW(), INTERVAL 30 MINUTE)) " .
    "LIMIT 100"
);

if (!is_array($rows) || count($rows) === 0) {
    echo "No rows to retry.\n";
    exit(0);
}

require_once dirname(__DIR__) . '/orkui/model/Model.php';
require_once dirname(__DIR__) . '/orkui/model/model.AmtgardIdpLink.php';

$model = new Model_AmtgardIdpLink();
$ok = 0;
$fail = 0;
foreach ($rows as $row) {
    $success = $model->linkOrkProfile($row['idp_user_id'], $row['mundane_id']);
    $status = $success ? 'synced' : 'failed';
    $DB->Clear();
    $DB->Execute(
        "UPDATE " . DB_PREFIX . "idp_auth SET idp_mirror_status = ?, idp_mirror_last_attempt = NOW() " .
        "WHERE idp_user_id = ? AND mundane_id = ?",
        array($status, $row['idp_user_id'], (int)$row['mundane_id'])
    );
    if ($success) { $ok++; } else { $fail++; }
}
echo "Mirror retry: $ok synced, $fail failed.\n";
