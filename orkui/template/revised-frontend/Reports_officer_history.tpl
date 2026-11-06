<?php
/*
 * Officer History (Reports::officer_history): the officer rolls of a kingdom or
 * park, one collapsible section per office, newest term first.
 *
 * View mode is the public report: Holder · Period, a "present" pill on the
 * current term, quiet "Unrecorded" rows for gaps. Edit mode (&edit=1, only for
 * CanEditRolls) turns each section into an office ledger with an entry row;
 * script/officer-rolls.js drives it and re-renders a section from the rolls
 * ajax, so its renderSection() must keep this markup in step.
 *
 * Offices with no terms are folded into one "Other offices (n)" group at the
 * bottom of the ledger (so a park isn't padded with every registry office) and
 * left out of the public report.
 */
$Rolls     = (isset($Rolls) && is_array($Rolls)) ? $Rolls : ['Offices' => []];
$orOffices = is_array($Rolls['Offices'] ?? null) ? array_values($Rolls['Offices']) : [];
$orIsPark  = ($ScopeType ?? '') === 'park';
$orScopeId = (int) ($ScopeId ?? 0);
$orKid     = (int) ($KingdomId ?? 0);
$orEdit    = !empty($EditMode) && !empty($CanEditRolls);
$orName    = (string) ($ScopeName ?? '');

$orH = function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$orProfileUrl = UIR . ($orIsPark ? 'Park' : 'Kingdom') . '/profile/' . $orScopeId;
$orPageUrl    = UIR . 'Reports/officer_history&KingdomId=' . $orKid . ($orIsPark ? '&ParkId=' . $orScopeId : '');

$orMain   = [];
$orFolded = [];
foreach ($orOffices as $orI => $o) {
    $o['__order'] = (int) $orI;
    if ((int) ($o['TermCount'] ?? 0) > 0) {
        $orMain[] = $o;
    } else {
        $orFolded[] = $o;
    }
}

// The holder as shown. HolderName is the name the term was recorded under; a
// linked holder shows it linked to the profile, with a muted "now known as"
// when today's persona differs (a blank persona falls back to it). Plain-text
// names are plain text. script/officer-rolls.js holderNode() mirrors this.
$orHolderName = function (array $t) {
    $recorded = trim((string) ($t['HolderName'] ?? ''));
    if ($recorded !== '' || (int) ($t['MundaneId'] ?? 0) <= 0) {
        return $recorded;
    }
    return trim((string) ($t['Persona'] ?? ''));
};
$orHolder = function (array $t) use ($orH, $orHolderName) {
    $mid = (int) ($t['MundaneId'] ?? 0);
    $name = $orHolderName($t);
    if ($mid > 0) {
        $persona = trim((string) ($t['Persona'] ?? ''));
        $out = '<a class="or-holder-link" href="' . $orH(UIR . 'Player/profile/' . $mid) . '">'
            . ($name !== '' ? $orH($name) : '<span class="or-holder-unknown">Unknown</span>') . '</a>';
        if ($persona !== '' && mb_strtolower($persona) !== mb_strtolower($name)) {
            $out .= ' <span class="or-holder-aka">now known as ' . $orH($persona) . '</span>';
        }
        return $out;
    }
    return $name !== ''
        ? '<span class="or-holder-text">' . $orH($name) . '</span>'
        : '<span class="or-holder-unknown">Unknown</span>';
};
// The period label, with a pill in place of the word "present" on a current term.
$orPeriod = function (array $t) use ($orH) {
    if (!empty($t['IsCurrent'])) {
        return $orH($t['PeriodLabelBase'] ?? '') . ' <span class="or-dash">–</span> <span class="or-present">present</span>';
    }
    return $orH($t['PeriodLabel'] ?? '');
};
// The public report's period. An unknown start reads "Start unknown" and an
// unknown end a muted "end unrecorded"; the ledger keeps "?", which editors
// read as "fill this in". Labels come from the term's stored ordinals.
$orViewPeriod = function (array $t) use ($orH, $orPeriod) {
    $start = $t['StartLabel'] ?? null;
    $end   = $t['EndLabel'] ?? null;
    $current = !empty($t['IsCurrent']);
    if ($current && $end !== null) {
        return $orPeriod($t);
    }
    $startHtml = $start !== null ? $orH($start) : '<span class="or-period-unknown">Start unknown</span>';
    if ($current) {
        return $startHtml . ' <span class="or-dash">–</span> <span class="or-present">present</span>';
    }
    if ($start !== null && $start === $end) {
        return $startHtml;
    }
    return $startHtml . ' <span class="or-dash">–</span> '
        . ($end !== null ? $orH($end) : '<span class="or-period-unknown">end unrecorded</span>');
};
$orPlural = function ($n, $one, $many) {
    return (int) $n . ' ' . ((int) $n === 1 ? $one : $many);
};

$orSummary = function (array $o) use ($orH, $orPlural, $orEdit) {
    $out = '<summary class="or-office-summary">'
        . '<i class="fas fa-chevron-right or-chevron" aria-hidden="true"></i>'
        . '<span class="or-office-title">' . $orH($o['Title'] !== '' ? $o['Title'] : 'Office') . '</span>';
    if (!empty($o['Retired'])) {
        $out .= '<span class="or-tag or-tag-retired">former office</span>';
    }
    $out .= '<span class="or-office-meta">'
        . '<span class="or-count">' . $orPlural($o['TermCount'] ?? 0, 'term', 'terms') . '</span>';
    if ($orEdit && (int) ($o['GapCount'] ?? 0) > 0) {
        $out .= '<span class="or-tag or-tag-gap">' . $orPlural($o['GapCount'], 'gap', 'gaps') . '</span>';
    }
    if ($orEdit && (int) ($o['OverlapCount'] ?? 0) > 0) {
        $out .= '<span class="or-tag or-tag-overlap">' . $orPlural($o['OverlapCount'], 'overlap', 'overlaps') . '</span>';
    }
    return $out . '</span></summary>';
};

$orViewBody = function (array $o) use ($orH, $orHolder, $orViewPeriod) {
    $out = '<ol class="or-list">';
    foreach ($o['Terms'] as $t) {
        $out .= '<li class="or-item' . (!empty($t['IsCurrent']) ? ' or-item-current' : '') . '">'
            . '<span class="or-holder">' . $orHolder($t) . '</span>'
            . '<span class="or-period">' . $orViewPeriod($t) . '</span></li>';
        if (($t['FlagAfter']['Type'] ?? '') === 'gap') {
            $out .= '<li class="or-item or-item-gap"><span class="or-holder">Unrecorded</span>'
                . '<span class="or-period">' . $orH($t['FlagAfter']['Span'] ?? '') . '</span></li>';
        }
    }
    return $out . '</ol>';
};

$orHolderField = function () {
    return '<div class="or-ac-wrap">'
        . '<input type="text" class="or-input or-holder-input" autocomplete="off" spellcheck="false" maxlength="120"'
        . ' placeholder="Holder" aria-label="Holder: search players or type a name">'
        . '<input type="hidden" class="or-holder-id" value="">'
        . '<div class="kn-ac-results or-ac-results" role="listbox"></div>'
        . '<div class="or-hint" hidden>plain text: not linked to a player</div>'
        . '</div>';
};

// The entry row's period field: a Season | Calendar toggle left of the inputs.
// Season (the default) is the period text with read-back; Calendar is exact
// Start/End dates. script/officer-rolls.js periodField() builds the same markup.
$orModeTips = [
    'season'   => 'Season Entry - use to indicate the general term if you do not know the specific dates.',
    'calendar' => 'Calendar Entry - use to enter in specific start and end dates when known.',
];
$orPeriodField = function () use ($orH, $orModeTips) {
    return '<div class="or-period-field" data-mode="season">'
        . '<div class="or-mode" role="group" aria-label="Entry mode">'
        . '<button type="button" class="or-mode-btn" data-or-mode="season" aria-pressed="true" tabindex="0" aria-label="Season entry"'
        . ' data-tip="' . $orH($orModeTips['season']) . '"><i class="fas fa-sun" aria-hidden="true"></i></button>'
        . '<button type="button" class="or-mode-btn" data-or-mode="calendar" aria-pressed="false" tabindex="-1" aria-label="Calendar entry"'
        . ' data-tip="' . $orH($orModeTips['calendar']) . '"><i class="fas fa-calendar-alt" aria-hidden="true"></i></button>'
        . '</div>'
        . '<div class="or-period-inputs">'
        . '<div class="or-pane-season"><input type="text" class="or-input or-period-input" autocomplete="off" spellcheck="false"'
        . ' placeholder="Period, e.g. Spring–Fall 23" aria-label="Period"></div>'
        . '<div class="or-pane-calendar" hidden><div class="or-date-pair">'
        . '<input type="text" class="or-input or-date-input" data-side="start" autocomplete="off" placeholder="Start date" aria-label="Start date">'
        . '<input type="text" class="or-input or-date-input" data-side="end" autocomplete="off" placeholder="End date (blank: unknown)" aria-label="End date, blank if unknown">'
        . '</div></div>'
        . '<div class="or-readback" aria-live="polite"></div>'
        . '</div></div>';
};

$orEditBody = function (array $o) use ($orH, $orHolder, $orHolderName, $orPeriod, $orHolderField, $orPeriodField) {
    $out = '<div class="or-ledger-wrap"><table class="or-ledger">'
        . '<thead><tr><th class="or-col-holder">Holder</th><th class="or-col-period">Period</th>'
        . '<th class="or-col-note">Note</th><th class="or-col-actions"><span class="or-sr">Actions</span></th></tr></thead><tbody>';
    foreach ($o['Terms'] as $t) {
        $hid       = (int) ($t['OfficerHistoryId'] ?? 0);
        $current   = !empty($t['IsCurrent']);
        $synthetic = !empty($t['Synthetic']) || $hid === 0;
        $plain     = (int) ($t['MundaneId'] ?? 0) <= 0;
        $out .= '<tr class="or-row' . ($current ? ' or-row-current' : '') . ($synthetic ? ' or-row-synthetic' : '') . '"'
            . ' data-history-id="' . $hid . '"'
            . ' data-holder="' . $orH($orHolderName($t)) . '"'
            . ' data-mundane-id="' . (int) ($t['MundaneId'] ?? 0) . '"'
            . ' data-period="' . $orH($t['PeriodText'] ?? '') . '"'
            . ' data-note="' . $orH($t['Note'] ?? '') . '"'
            . ' data-start-date="' . $orH($t['StartDate'] ?? '') . '"'
            . ' data-start-season="' . ((($t['StartSeason'] ?? '') !== '' && ($t['StartYear'] ?? null) !== null) ? '1' : '0') . '"'
            . ' data-end-date="' . $orH($t['EndDate'] ?? '') . '"'
            . ' data-current="' . ($current ? '1' : '0') . '">';
        $out .= '<td class="or-col-holder" data-label="Holder">' . $orHolder($t);
        if ($current) {
            $out .= ' <span class="or-lock" data-tip="Set from Manage Officers"><i class="fas fa-lock" aria-hidden="true"></i><span class="or-sr">Set from Manage Officers</span></span>';
        }
        $out .= '</td>';
        $out .= '<td class="or-col-period" data-label="Period">' . $orPeriod($t) . '</td>';
        $out .= '<td class="or-col-note" data-label="Note">' . $orH($t['Note'] ?? '') . '</td>';
        $out .= '<td class="or-col-actions">';
        if ($synthetic) {
            $out .= '<span class="or-tag or-tag-quiet" data-tip="Seated in Manage Officers, with no history row yet. Set from Manage Officers.">seated</span>';
        } else {
            $out .= '<button type="button" class="or-icon-btn" data-or-act="edit" aria-label="Edit"'
                . ' data-tip="' . ($current ? 'Edit the start and note. The holder and end are set from Manage Officers.' : 'Edit') . '">'
                . '<i class="fas fa-pen" aria-hidden="true"></i></button>';
            if (!$current && $plain) {
                $out .= '<button type="button" class="or-icon-btn" data-or-act="link" aria-label="Link to player" data-tip="Link to player">'
                    . '<i class="fas fa-link" aria-hidden="true"></i></button>';
            }
            if (!$current) {
                $out .= '<button type="button" class="or-icon-btn or-icon-danger" data-or-act="delete" aria-label="Delete" data-tip="Delete">'
                    . '<i class="fas fa-trash-alt" aria-hidden="true"></i></button>';
            }
        }
        $out .= '</td></tr>';
        $flag = $t['FlagAfter'] ?? null;
        if (is_array($flag) && isset($flag['Type'])) {
            $out .= '<tr class="or-flag-row or-flag-' . ($flag['Type'] === 'gap' ? 'gap' : 'overlap') . '"><td colspan="4">'
                . '<i class="fas fa-' . ($flag['Type'] === 'gap' ? 'ellipsis-v' : 'exclamation-triangle') . '" aria-hidden="true"></i> '
                . $orH($flag['Label'] ?? '') . '</td></tr>';
        }
    }
    if ((int) ($o['PositionId'] ?? 0) > 0) {
        $out .= '<tr class="or-entry" data-or-entry data-position-id="' . (int) $o['PositionId'] . '">'
            . '<td class="or-col-holder" data-label="Holder">' . $orHolderField() . '</td>'
            . '<td class="or-col-period" data-label="Period">' . $orPeriodField() . '</td>'
            . '<td class="or-col-note" data-label="Note"><input type="text" class="or-input or-note-input" maxlength="500" placeholder="Note" aria-label="Note"></td>'
            . '<td class="or-col-actions"><button type="button" class="or-btn-add" data-or-act="add" data-tip="Add this term (Enter)">'
            . '<i class="fas fa-plus" aria-hidden="true"></i> Add</button></td></tr>';
    } else {
        $out .= '<tr class="or-entry-none"><td colspan="4">This office is no longer in the registry, so new terms are not added here.</td></tr>';
    }
    return $out . '</tbody></table></div>';
};

// data-order: the office's place in the rolls (Offices order), so the ledger can
// move a section out of "Other offices" without refetching every office.
$orSection = function (array $o, bool $open) use ($orH, $orSummary, $orViewBody, $orEditBody, $orEdit) {
    return '<details class="or-office" data-key="' . $orH($o['Key'] ?? '') . '" data-position-id="' . (int) ($o['PositionId'] ?? 0) . '"'
        . ' data-order="' . (int) ($o['__order'] ?? 0) . '"'
        . ($open ? ' open' : '') . '>'
        . $orSummary($o)
        . '<div class="or-office-body">' . ($orEdit ? $orEditBody($o) : $orViewBody($o)) . '</div>'
        . '</details>';
};
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/reports.css') ?>">
<?php if ($orEdit) { ?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<?php } ?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/officer-rolls.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/officer-rolls.css') ?>">

<div class="rp-root or-root<?= $orEdit ? ' or-editing' : '' ?>" id="or-root">
  <div class="rp-header">
    <div class="rp-header-left">
      <div class="rp-header-icon-title">
        <i class="fas fa-scroll rp-header-icon"></i>
        <h1 class="rp-header-title">Officer History</h1>
      </div>
      <div class="rp-header-scope">
        <a class="rp-scope-chip" href="<?= $orH($orProfileUrl) ?>">
          <i class="fas fa-<?= $orIsPark ? 'map-marker-alt' : 'crown' ?>"></i>
          <span class="rp-scope-chip-label"><?= $orIsPark ? 'Park' : 'Kingdom' ?>:</span> <?= $orH($orName) ?>
        </a>
      </div>
    </div>
    <?php if (!empty($CanEditRolls)) { ?>
    <div class="rp-header-actions">
      <?php if ($orEdit) { ?>
        <a class="rp-btn-ghost" href="<?= $orH($orPageUrl) ?>"><i class="fas fa-check"></i> Done</a>
      <?php } else { ?>
        <a class="rp-btn-ghost" href="<?= $orH($orPageUrl . '&edit=1') ?>"><i class="fas fa-pen"></i> Edit the rolls</a>
      <?php } ?>
    </div>
    <?php } ?>
  </div>
  <?php if (!empty($EditRefused) && !$orEdit) { ?>
  <div class="rp-context or-edit-refused" role="status">
    <i class="fas fa-lock rp-context-icon" aria-hidden="true"></i>
    <span>Editing the rolls needs the <?= $orIsPark ? 'park' : 'kingdom' ?> Officer History permission, so this is the read-only report.</span>
  </div>
  <?php } ?>
  <div class="rp-context">
    <i class="fas fa-info-circle rp-context-icon"></i>
    <?php if ($orEdit) { ?>
      <span>Work down an office newest to oldest: type a holder and a period such as <strong>Spring–Fall 23</strong>, then press <strong>Enter</strong>. Leave the end blank (<strong>Spring 95 –</strong>) when it is unknown. Current terms are set from Manage Officers.</span>
    <?php } else { ?>
      <span>Who has held each office of <strong><?= $orH($orName) ?></strong>, newest first.</span>
    <?php } ?>
  </div>

  <div class="or-offices" id="or-offices">
    <?php
    $orFirst = true;
    foreach ($orMain as $o) {
        echo $orSection($o, $orFirst);
        $orFirst = false;
    }
    if ($orEdit && $orFolded) {
        echo '<details class="or-folded"' . ($orFirst ? ' open' : '') . '>'
            . '<summary class="or-folded-summary"><i class="fas fa-chevron-right or-chevron" aria-hidden="true"></i>'
            . 'Other offices <span class="or-folded-count">(' . count($orFolded) . ')</span><span class="or-folded-hint">no terms recorded yet</span></summary>'
            . '<div class="or-folded-body">';
        foreach ($orFolded as $o) {
            echo $orSection($o, false);
        }
        echo '</div></details>';
    }
    if (!$orMain && !($orEdit && $orFolded)) {
        echo '<div class="or-empty">No officer history has been recorded for ' . $orH($orName) . ' yet.</div>';
    }
    ?>
  </div>
</div>

<script>
/* #office-N (Manage Officers' "Set it on the rolls") opens that office's
   section and scrolls to it; sections are keyed by data-position-id. */
(function () {
    var m = /^#office-(\d+)$/.exec(location.hash || '');
    if (!m) { return; }
    var sec = document.querySelector('details.or-office[data-position-id="' + m[1] + '"]');
    if (!sec) { return; }
    var folded = sec.closest('details.or-folded');
    if (folded) { folded.open = true; }
    sec.open = true;
    sec.scrollIntoView({ block: 'start' });
})();
</script>
<?php if ($orEdit) { ?>
<script>
window.OrConfig = <?= json_encode([
    'uir'       => UIR,
    'kingdomId' => $orKid,
    'parkId'    => $orIsPark ? $orScopeId : 0,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/revised.js') ?>"></script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/officer-rolls.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/officer-rolls.js') ?>"></script>
<?php } ?>
