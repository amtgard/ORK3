<?php

/**
 * 2026-08-10-cms-park-seed-repair.php
 *
 * Repairs PARK sites that were seeded with the KINGDOM starter template.
 *
 * Before this release CmsSite::_starterPageDefs() was scope-blind, so every park
 * site got the kingdom template verbatim:
 *
 *   - kingdom_events / kingdom_officers / kingdom_parks / kingdom_parks_map
 *     blocks, each of which correctly renders NOTHING outside a kingdom scope —
 *     so the park's home page and Officers page came up blank, with no error
 *     anywhere to say why.
 *   - an "Our Parks" page for an org that has no parks.
 *   - copy calling the park a kingdom.
 *
 * The seeder is fixed going forward. This repairs the sites already created.
 *
 * DELIBERATELY CONSERVATIVE. Officers may have edited these pages since seeding,
 * and re-creating content someone deleted is exactly the failure the
 * template_seeded_at marker was introduced to stop. So this only ever:
 *
 *   1. RETYPES a kingdom_* block to its park_* counterpart in place, keeping the
 *      block's own fields, ordering and enabled flag. kingdom_officers ->
 *      park_officers and kingdom_events -> park_events read the same fields, so
 *      this is a faithful conversion, not a re-seed.
 *   2. DISABLES (never deletes) kingdom_parks / kingdom_parks_map on a park site.
 *      They have no park-scope counterpart — a park has no parks — so they can
 *      only ever render nothing. Disabled rather than dropped so an officer can
 *      still see what was there and remove it themselves.
 *   3. Rewrites the seeded copy strings ONLY where they still match the seed text
 *      exactly. An edited heading keeps the officer's wording.
 *
 * It creates no pages, touches no nav, and deletes nothing. Re-run safe: every
 * step is a no-op once applied.
 *
 * Run: php db-migrations/2026-08-10-cms-park-seed-repair.php
 */

// Same docroot exposure as its sibling backfill: this file rewrites block rows
// across every park-scoped page with nothing authenticating the caller, so it
// must never be reachable over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost:19080';
}

require_once __DIR__ . '/../startup.php';

global $DB;

// kingdom_* -> park_* where a faithful, same-fields counterpart exists.
$RETYPE = array(
    'kingdom_officers' => 'park_officers',
    'kingdom_events'   => 'park_events',
);
// No park counterpart — a park has no parks. Disable, never delete.
$DISABLE = array('kingdom_parks', 'kingdom_parks_map');

// Seed copy, verbatim. Rewritten only on an exact match.
$HEADING_FIXES = array(
    'Welcome to Our Kingdom'   => 'Welcome to Our Park',
    'A Kingdom of Adventurers' => 'A Park of Adventurers',
);
$TEXT_FIXES = array(
    'Edit this block to introduce your kingdom, describe what a typical game day looks like'
        => 'Edit this block to introduce your park, describe what a typical game day looks like',
    'Add the members who govern and steward the kingdom.'
        => 'Add the members who govern and steward the park.',
    "Share your kingdom&rsquo;s story: when it was founded, the lands and parks it covers,"
        => "Share your park&rsquo;s story: when it was founded, where it plays,",
);

// ---- Read every live block on a PARK-scoped page -------------------------
// Buffer the whole result BEFORE issuing any write: the shared $DB handle is
// single-cursor, so writing mid-iteration would drop the rest of the rows.
$DB->Clear();
$rows = array();
$rs = $DB->DataSet(
    'SELECT b.block_id, b.type, b.enabled, b.fields_json, b.owner_id'
    . ' FROM ' . DB_PREFIX . 'cms_block b'
    . ' JOIN ' . DB_PREFIX . "cms_page p ON p.page_id = b.owner_id AND b.owner_type = 'page'"
    . " WHERE p.scope_type = 'park' AND p.deleted_at IS NULL"
);
while ($rs && $rs->Next()) {
    $rows[] = array(
        'block_id'    => (int) $rs->block_id,
        'type'        => (string) $rs->type,
        'enabled'     => (int) $rs->enabled,
        'fields_json' => (string) $rs->fields_json,
        'owner_id'    => (int) $rs->owner_id,
    );
}

$nRetyped  = 0;
$nDisabled = 0;
$nCopy     = 0;

foreach ($rows as $row) {
    $id   = $row['block_id'];
    $type = $row['type'];

    if (isset($RETYPE[$type])) {
        $DB->Clear();
        $DB->block_id = $id;
        $DB->new_type = $RETYPE[$type];
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_block SET type = :new_type WHERE block_id = :block_id'
        );
        $nRetyped++;
        echo "  block {$id}: {$type} -> {$RETYPE[$type]} (page {$row['owner_id']})\n";
        continue;
    }

    if (in_array($type, $DISABLE, true)) {
        if ($row['enabled'] === 1) {
            $DB->Clear();
            $DB->block_id = $id;
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'cms_block SET enabled = 0 WHERE block_id = :block_id'
            );
            $nDisabled++;
            echo "  block {$id}: {$type} disabled — no park counterpart (page {$row['owner_id']})\n";
        }
        continue;
    }

    // ---- Copy repair, exact-match only ----
    $fields = json_decode($row['fields_json'], true);
    if (!is_array($fields)) {
        continue;
    }
    $before = json_encode($fields);

    if (isset($fields['heading']) && is_string($fields['heading'])
        && isset($HEADING_FIXES[$fields['heading']])) {
        $fields['heading'] = $HEADING_FIXES[$fields['heading']];
    }
    foreach (array('body', 'subheading', 'meta_description') as $k) {
        if (isset($fields[$k]) && is_string($fields[$k])) {
            foreach ($TEXT_FIXES as $from => $to) {
                if (strpos($fields[$k], $from) !== false) {
                    $fields[$k] = str_replace($from, $to, $fields[$k]);
                }
            }
        }
    }

    if (json_encode($fields) === $before) {
        continue;
    }

    $DB->Clear();
    $DB->block_id    = $id;
    $DB->fields_json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $DB->Execute(
        'UPDATE ' . DB_PREFIX . 'cms_block SET fields_json = :fields_json WHERE block_id = :block_id'
    );
    $nCopy++;
    echo "  block {$id}: seed copy re-worded for park scope (page {$row['owner_id']})\n";
}

echo "\nPark seed repair complete.\n";
echo "  blocks retyped to park_*      : {$nRetyped}\n";
echo "  kingdom-only blocks disabled  : {$nDisabled}\n";
echo "  seed copy strings re-worded   : {$nCopy}\n";
echo "\nNote: pages themselves are untouched. A park site seeded before this\n"
    . "release still has an 'Our Parks' page in its nav — now empty rather than\n"
    . "misleading. Nothing is auto-deleted; an officer removes it if they want.\n";
