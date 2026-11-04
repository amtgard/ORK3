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
$orOffices = is_array($Rolls['Offices'] ?? null) ? $Rolls['Offices'] : [];
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
foreach ($orOffices as $o) {
    if ((int) ($o['TermCount'] ?? 0) > 0) {
        $orMain[] = $o;
    } else {
        $orFolded[] = $o;
    }
}

$orDate = function ($d) {
    if ($d === null || $d === '' || $d === '0000-00-00' || ($ts = strtotime((string) $d)) === false) {
        return '';
    }
    return date('M j, Y', $ts);
};
// The holder as shown: linked players link to their profile, plain-text names are plain text.
$orHolder = function (array $t) use ($orH) {
    $mid = (int) ($t['MundaneId'] ?? 0);
    if ($mid > 0) {
        $name = (string) ($t['Persona'] ?? '') !== '' ? $t['Persona'] : 'Player #' . $mid;
        return '<a class="or-holder-link" href="' . $orH(UIR . 'Player/profile/' . $mid) . '">' . $orH($name) . '</a>';
    }
    $name = trim((string) ($t['HolderName'] ?? ''));
    return $name !== ''
        ? '<span class="or-holder-text">' . $orH($name) . '</span>'
        : '<span class="or-holder-unknown">Unknown</span>';
};
$orHolderName = function (array $t) {
    return (int) ($t['MundaneId'] ?? 0) > 0 ? (string) ($t['Persona'] ?? '') : trim((string) ($t['HolderName'] ?? ''));
};
// The period label, with a pill in place of the word "present" on a current term.
$orPeriod = function (array $t) use ($orH) {
    $label = (string) ($t['PeriodLabel'] ?? '');
    if (!empty($t['IsCurrent'])) {
        $label = preg_replace('/\s*–\s*present$/u', '', $label);
        return $orH($label) . ' <span class="or-dash">–</span> <span class="or-present">present</span>';
    }
    return $orH($label);
};
$orExact = function (array $t) use ($orDate) {
    $s = $orDate($t['StartDate'] ?? null);
    $e = $orDate($t['EndDate'] ?? null);
    if ($s === '' && $e === '') {
        return '';
    }
    return 'Exact: ' . ($s !== '' ? $s : '?') . ' – ' . ($e !== '' ? $e : (!empty($t['IsCurrent']) ? 'present' : '?'));
};
// The period as the ledger's period field shows it for editing ("Spring 2023 – Fall 2023").
$orPeriodText = function (array $t) {
    $side = function ($s, $y) {
        return ($s !== null && $s !== '' && $y !== null && $y !== '') ? ucfirst((string) $s) . ' ' . (int) $y : '';
    };
    $start = $side($t['StartSeason'] ?? null, $t['StartYear'] ?? null);
    $end   = $side($t['EndSeason'] ?? null, $t['EndYear'] ?? null);
    if (!empty($t['IsCurrent']) || $start === '') {
        return $start;
    }
    if ($end === '') {
        return $start . ' –';
    }
    return $end === $start ? $start : $start . ' – ' . $end;
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

$orViewBody = function (array $o) use ($orH, $orHolder, $orPeriod, $orExact) {
    $out = '<ol class="or-list">';
    foreach ($o['Terms'] as $t) {
        $exact = $orExact($t);
        $out .= '<li class="or-item' . (!empty($t['IsCurrent']) ? ' or-item-current' : '') . '">'
            . '<span class="or-holder">' . $orHolder($t) . '</span>'
            . '<span class="or-period">' . $orPeriod($t)
            . ($exact !== '' ? '<span class="or-exact">' . $orH($exact) . '</span>' : '')
            . '</span></li>';
        if (($t['FlagAfter']['Type'] ?? '') === 'gap') {
            $span = preg_replace('/^Gap:\s*/', '', (string) $t['FlagAfter']['Label']);
            $out .= '<li class="or-item or-item-gap"><span class="or-holder">Unrecorded</span>'
                . '<span class="or-period">' . $orH($span) . '</span></li>';
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

$orEditBody = function (array $o) use ($orH, $orHolder, $orHolderName, $orPeriod, $orExact, $orPeriodText, $orHolderField) {
    $out = '<div class="or-ledger-wrap"><table class="or-ledger">'
        . '<thead><tr><th class="or-col-holder">Holder</th><th class="or-col-period">Period</th>'
        . '<th class="or-col-note">Note</th><th class="or-col-actions"><span class="or-sr">Actions</span></th></tr></thead><tbody>';
    foreach ($o['Terms'] as $t) {
        $hid       = (int) ($t['OfficerHistoryId'] ?? 0);
        $current   = !empty($t['IsCurrent']);
        $synthetic = !empty($t['Synthetic']) || $hid === 0;
        $plain     = (int) ($t['MundaneId'] ?? 0) <= 0;
        $exact     = $orExact($t);
        $out .= '<tr class="or-row' . ($current ? ' or-row-current' : '') . ($synthetic ? ' or-row-synthetic' : '') . '"'
            . ' data-history-id="' . $hid . '"'
            . ' data-holder="' . $orH($orHolderName($t)) . '"'
            . ' data-mundane-id="' . (int) ($t['MundaneId'] ?? 0) . '"'
            . ' data-period="' . $orH($orPeriodText($t)) . '"'
            . ' data-note="' . $orH($t['Note'] ?? '') . '"'
            . ' data-start-date="' . $orH($t['StartDate'] ?? '') . '"'
            . ' data-end-date="' . $orH($t['EndDate'] ?? '') . '"'
            . ' data-current="' . ($current ? '1' : '0') . '">';
        $out .= '<td class="or-col-holder" data-label="Holder">' . $orHolder($t);
        if ($current) {
            $out .= ' <span class="or-lock" data-tip="Set from Manage Officers"><i class="fas fa-lock" aria-hidden="true"></i><span class="or-sr">Set from Manage Officers</span></span>';
        }
        $out .= '</td>';
        $out .= '<td class="or-col-period" data-label="Period">' . $orPeriod($t)
            . ($exact !== '' ? '<span class="or-exact">' . $orH($exact) . '</span>' : '') . '</td>';
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
            . '<td class="or-col-period" data-label="Period"><input type="text" class="or-input or-period-input" autocomplete="off" spellcheck="false"'
            . ' placeholder="Period, e.g. Spring–Fall 23" aria-label="Period">'
            . '<div class="or-readback" aria-live="polite"></div></td>'
            . '<td class="or-col-note" data-label="Note"><input type="text" class="or-input or-note-input" maxlength="500" placeholder="Note" aria-label="Note"></td>'
            . '<td class="or-col-actions"><button type="button" class="or-btn-add" data-or-act="add" data-tip="Add this term (Enter)">'
            . '<i class="fas fa-plus" aria-hidden="true"></i> Add</button></td></tr>';
    } else {
        $out .= '<tr class="or-entry-none"><td colspan="4">This office is no longer in the registry, so new terms are not added here.</td></tr>';
    }
    return $out . '</tbody></table></div>';
};

$orSection = function (array $o, bool $open) use ($orH, $orSummary, $orViewBody, $orEditBody, $orEdit) {
    return '<details class="or-office" data-key="' . $orH($o['Key'] ?? '') . '" data-position-id="' . (int) ($o['PositionId'] ?? 0) . '"'
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
            . 'Other offices (' . count($orFolded) . ')<span class="or-folded-hint">no terms recorded yet</span></summary>'
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
