<?php

/**
 * 2026-09-20-cms-kingdom-seed-copy-repair.php
 *
 * Removes AUTHOR INSTRUCTIONS from the public copy of KINGDOM sites that were
 * already seeded with the old starter template.
 *
 * Three seeded strings were written at the officer, not at a visitor — and
 * every starter page is seeded status='published' behind a one-click site
 * Publish, so on any org that published before editing they went straight onto
 * the open web:
 *
 *   1. Home's second rich_text body ("Tell visitors who you are in a sentence
 *      or two. Edit this block to introduce your kingdom…").
 *   2. The About page's only content body ("Share your kingdom's story… Replace
 *      this placeholder with your own history.").
 *   3. The Officers staff_roster subheading ("Add the members who govern and
 *      steward the kingdom.").
 *
 * Home's welcome body compounded it with a price promise ("your first day on
 * the field is always free") that a kingdom makes on behalf of parks it does
 * not control.
 *
 * The park branch of the seed was rebuilt to remove exactly this class of copy
 * (see the comment in CmsSite::_starterPageDefs()), but the fix lived inside
 * the park early return, so the kingdom template that taught the lesson kept
 * shipping it. The seeder is fixed going forward; this repairs the sites
 * already seeded.
 *
 * DELIBERATELY CONSERVATIVE, same rule as 2026-08-10-cms-park-seed-repair.php:
 * a field is rewritten ONLY when it still matches the seeded string EXACTLY.
 * Any copy an officer has edited — a word, a character — is left alone. The
 * expected strings are reconstructed by running the ORIGINAL seed literals back
 * through CmsSanitizer::Clean(), which is how they were stored, so the
 * comparison is byte-exact rather than a guess at what the sanitizer did.
 *
 * Bodies that were instructions are REPLACED with the evergreen prose the fixed
 * seeder now writes, not emptied. rich_text.tpl only self-suppresses when EVERY
 * field is empty, and both of these blocks keep their kicker and heading, so an
 * empty body would leave a published, nav-linked page rendering a headline over
 * blank space — and would not fire fdEmptyBlockNotice() in preview either. The
 * About replacement carries the kingdom's own name, exactly as the seeder
 * composes it, so it is not byte-identical across every org.
 *
 * The roster SUBHEADING is the one field that really is cleared: staff_roster.tpl
 * bails on an empty people list regardless of its kicker or heading, so a roster
 * nobody has filled in genuinely renders nothing.
 *
 * Creates nothing, deletes nothing, touches no pages or nav. Re-run safe: every
 * step is a no-op once applied.
 *
 * Run: php db-migrations/2026-09-20-cms-kingdom-seed-copy-repair.php
 */

require_once __DIR__ . '/_cms_cli_bootstrap.php';

global $DB;

/** The seed sanitizes every authored body; reproduce that exactly. */
$clean = function ($html) {
    return class_exists('CmsSanitizer') ? CmsSanitizer::Clean($html) : (string) $html;
};

// Every org-unit noun the seed could have written. OrgUnitNoun() returns
// 'Kingdom' or 'Principality'; 'Group' is its own unresolvable fallback.
$NOUNS = array('kingdom', 'principality', 'group');

// ---- rich_text bodies: exact seeded string => replacement -----------------
$BODY_FIXES = array();

// Home, block 1 — noun-independent. Drops the "always free" price claim.
$BODY_FIXES[$clean(
    '<p>Foam swords, real friendships, and a place for everyone. Find a park near you and come play'
    . ' &mdash; your first day on the field is always free.</p>'
)] = $clean(
    '<p>Foam swords, real friendships, and a place for everyone. Find a park near you and come play.</p>'
);

foreach ($NOUNS as $nounLower) {
    // Home, block 2 — author instructions -> the evergreen paragraph the
    // seeder now writes (true of every org, on every day, with nobody typing).
    $BODY_FIXES[$clean(
        '<p>Tell visitors who you are in a sentence or two. Edit this block to introduce your '
        . $nounLower . ', describe what a typical game day looks like, and invite newcomers to their'
        . ' first (always free) day on the field.</p>'
    )] = $clean(
        '<p>We are a ' . $nounLower . ' of Amtgard &mdash; an all-ages foam-combat and '
        . 'medieval hobby played outdoors in public parks. Our parks welcome newcomers with '
        . 'no experience and teach the game right there on the field. Find the park nearest '
        . 'you and come see what a game day looks like.</p>'
    );
}

// ---- About body: exact seeded strings, replacement composed PER SITE -------
// Unlike the fixes above, this replacement is not a constant: the seeder weaves
// the org's own display name into it, so the new body has to be built per row
// from the owning kingdom. Keyed as a set of the strings the old seed could
// have written; the value is computed below.
$ABOUT_BODY_MATCHES = array();
foreach ($NOUNS as $nounLower) {
    $ABOUT_BODY_MATCHES[$clean(
        '<p>Share your ' . $nounLower . '&rsquo;s story: when it was founded, the lands and parks it'
        . ' covers, and the traditions that make it yours. Replace this placeholder with your own'
        . ' history.</p>'
    )] = true;
}

/**
 * The evergreen About body the fixed seeder writes, for one kingdom.
 * MUST stay byte-identical to CmsSite::_starterPageDefs()'s 'about' body, so a
 * repaired site and a freshly-seeded one read the same.
 */
$site       = new CmsSite();
$aboutCache = array();
$aboutBody  = function ($kingdomId) use ($clean, $site, &$aboutCache) {
    $kingdomId = (int) $kingdomId;
    if (isset($aboutCache[$kingdomId])) {
        return $aboutCache[$kingdomId];
    }

    $noun = $site->OrgUnitNoun('kingdom', $kingdomId);
    if ($noun === '') {
        $noun = 'Group';
    }
    $nounLower = strtolower($noun);

    $orgName = (isset(Ork3::$Lib) && is_object(Ork3::$Lib)
        && isset(Ork3::$Lib->kingdom) && is_object(Ork3::$Lib->kingdom))
        ? trim((string) Ork3::$Lib->kingdom->GetName($kingdomId))
        : '';
    $orgLabelStart = ($orgName !== '') ? $orgName : 'Our ' . $noun;

    $aboutCache[$kingdomId] = $clean(
        '<p>' . htmlspecialchars($orgLabelStart, ENT_QUOTES, 'UTF-8')
        . ' is part of Amtgard &mdash; a medieval and fantasy combat game played '
        . 'outdoors with padded weapons, alongside the arts, crafts and service that '
        . 'grew up around it. What the ' . $nounLower . ' is today was built by the '
        . 'players who came before: volunteers founded the parks listed on this site, '
        . 'and the officers who run the ' . $nounLower . ' are elected from among its '
        . 'own members.</p>'
        . '<p>That work carries on every game day. Newcomers are welcome at any of our '
        . 'parks &mdash; most keep loaner weapons on hand and will teach you the rules '
        . 'right there on the field.</p>'
    );
    return $aboutCache[$kingdomId];
};

// ---- staff_roster subheadings: exact seeded string => replacement ----------
// Stored verbatim (never sanitized — it is a plain-text field).
$SUBHEADING_FIXES = array();
foreach ($NOUNS as $nounLower) {
    $SUBHEADING_FIXES['Add the members who govern and steward the ' . $nounLower . '.'] = '';
}

// ---- Read every live block on a KINGDOM-scoped page -----------------------
// Buffer the whole result BEFORE issuing any write: the shared $DB handle is
// single-cursor, so writing mid-iteration would drop the rest of the rows.
$DB->Clear();
$rows = array();
$rs = $DB->DataSet(
    'SELECT b.block_id, b.type, b.fields_json, b.owner_id, p.scope_id'
    . ' FROM ' . DB_PREFIX . 'cms_block b'
    . ' JOIN ' . DB_PREFIX . "cms_page p ON p.page_id = b.owner_id AND b.owner_type = 'page'"
    . " WHERE p.scope_type = 'kingdom' AND p.deleted_at IS NULL"
    . " AND b.type IN ('rich_text', 'staff_roster')"
);
while ($rs && $rs->Next()) {
    $rows[] = array(
        'block_id'    => (int) $rs->block_id,
        'type'        => (string) $rs->type,
        'fields_json' => (string) $rs->fields_json,
        'owner_id'    => (int) $rs->owner_id,
        'scope_id'    => (int) $rs->scope_id,
    );
}

$nBodies      = 0;
$nSubheadings = 0;

foreach ($rows as $row) {
    $fields = json_decode($row['fields_json'], true);
    if (!is_array($fields)) {
        continue;
    }
    $before = json_encode($fields);

    // EXACT match only — an edited body keeps the officer's wording.
    if (isset($fields['body']) && is_string($fields['body'])
        && array_key_exists($fields['body'], $BODY_FIXES)
    ) {
        $fields['body'] = $BODY_FIXES[$fields['body']];
        $nBodies++;
        echo "  block {$row['block_id']}: seeded author-instruction body repaired (page {$row['owner_id']})\n";
    } elseif (isset($fields['body']) && is_string($fields['body'])
        && isset($ABOUT_BODY_MATCHES[$fields['body']])
    ) {
        // About: same exact-match rule, but the replacement is composed from
        // the owning kingdom so it matches what the seeder would write today.
        $fields['body'] = $aboutBody($row['scope_id']);
        $nBodies++;
        echo "  block {$row['block_id']}: seeded About body replaced with evergreen copy"
            . " (page {$row['owner_id']})\n";
    }

    if (isset($fields['subheading']) && is_string($fields['subheading'])
        && array_key_exists($fields['subheading'], $SUBHEADING_FIXES)
    ) {
        $fields['subheading'] = $SUBHEADING_FIXES[$fields['subheading']];
        $nSubheadings++;
        echo "  block {$row['block_id']}: seeded roster subheading cleared (page {$row['owner_id']})\n";
    }

    if (json_encode($fields) === $before) {
        continue;
    }

    $DB->Clear();
    $DB->block_id    = $row['block_id'];
    $DB->fields_json = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $DB->Execute(
        'UPDATE ' . DB_PREFIX . 'cms_block SET fields_json = :fields_json WHERE block_id = :block_id'
    );
}

echo "\nKingdom seed copy repair complete.\n";
echo "  rich_text bodies repaired       : {$nBodies}\n";
echo "  roster subheadings cleared      : {$nSubheadings}\n";
echo "\nNote: only fields still matching the seeded string EXACTLY were touched.\n"
    . "Any copy an officer had already edited was left as they wrote it.\n";
