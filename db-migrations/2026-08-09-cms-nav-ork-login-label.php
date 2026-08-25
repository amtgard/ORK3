<?php

/**
 * Relabel the marketing nav's login entry "Record Keeper" -> "ORK Login".
 *
 * The label lives in THREE places and all three must agree, or the wording
 * changes depending on which page you are looking at:
 *
 *   1. orkui/template/default/frontdoor/site_header.tpl  — site chrome used by
 *      CMS pages and the blog (code, already updated).
 *   2. orkui/model/model.FrontDoor.php                   — the hardcoded home
 *      page defaults, used only as the fallback when no CMS home row exists
 *      (code, already updated).
 *   3. ork_cms_block.fields_json for the seeded home page's `marketing_nav`
 *      block — THIS is what actually renders on the live home page, because a
 *      CMS row does exist and so the model fallback never runs. Hence this
 *      migration: editing the PHP alone leaves the live home page unchanged.
 *
 * Only the `login.label` key is touched. The href, the `cta`, the logo and
 * every `items[]` entry are left exactly as they are.
 *
 * Idempotent: rows whose login.label is already "ORK Login" are skipped, and a
 * row whose login.label is neither the old nor the new value is reported and
 * left alone rather than being overwritten (someone edited it deliberately).
 *
 * Run:
 *   docker exec ork3-php8-app php \
 *     /var/www/ork.amtgard.com/db-migrations/2026-08-09-cms-nav-ork-login-label.php
 */

require_once __DIR__ . '/_cms_cli_bootstrap.php';

const OLD_LABEL = 'Record Keeper';
const NEW_LABEL = 'ORK Login';

global $DB;

$result = array(
    'updated' => array(),
    'already_current' => array(),
    'left_alone' => array(),
);

// Every marketing_nav block, in any scope — org sites get the same wording.
$DB->Clear();
$rows = array();
$rs = $DB->DataSet(
    'SELECT block_id, fields_json FROM ' . DB_PREFIX . 'cms_block'
    . " WHERE type = 'marketing_nav'"
);
while ($rs && $rs->Next()) {
    $rows[] = array(
        'block_id'    => (int) $rs->block_id,
        'fields_json' => (string) $rs->fields_json,
    );
}

foreach ($rows as $row) {
    $blockId = $row['block_id'];
    $fields  = json_decode($row['fields_json'], true);

    if (!is_array($fields)) {
        $result['left_alone'][] = "block $blockId (fields_json is not valid JSON)";
        continue;
    }
    if (!isset($fields['login']) || !is_array($fields['login'])) {
        $result['left_alone'][] = "block $blockId (no login field)";
        continue;
    }

    $label = isset($fields['login']['label']) ? (string) $fields['login']['label'] : '';

    if ($label === NEW_LABEL) {
        $result['already_current'][] = "block $blockId";
        continue;
    }
    if ($label !== OLD_LABEL) {
        $result['left_alone'][] = "block $blockId (login.label is '$label', not '" . OLD_LABEL . "')";
        continue;
    }

    $fields['login']['label'] = NEW_LABEL;
    $encoded = json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        $result['left_alone'][] = "block $blockId (re-encode failed, left untouched)";
        continue;
    }

    // Bound + Clear()'d: a stale PDO binding here would silently no-op the write.
    $DB->Clear();
    $DB->fields_json = $encoded;
    $DB->block_id    = $blockId;
    $DB->Execute(
        'UPDATE ' . DB_PREFIX . 'cms_block SET fields_json = :fields_json'
        . ' WHERE block_id = :block_id'
    );

    // Execute() is void under ERRMODE_WARNING, so verify by reading back.
    $DB->Clear();
    $DB->block_id = $blockId;
    $check = $DB->DataSet(
        'SELECT fields_json FROM ' . DB_PREFIX . 'cms_block WHERE block_id = :block_id LIMIT 1'
    );
    $after = ($check && $check->Next()) ? json_decode((string) $check->fields_json, true) : null;
    $ok = is_array($after)
        && isset($after['login']['label'])
        && $after['login']['label'] === NEW_LABEL;

    if ($ok) {
        $result['updated'][] = "block $blockId";
    } else {
        $result['left_alone'][] = "block $blockId (WRITE DID NOT STICK — investigate)";
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
