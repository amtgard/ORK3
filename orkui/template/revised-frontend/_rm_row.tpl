<?php
        $gMid    = (int)$group['MundaneId'];
        $gKaid   = (int)$group['KingdomAwardId'];
        $gRank   = (int)$group['Rank'];
        $isLad   = $gRank > 0;
        // Eligibility from the authoritative AlreadyHas flag — CurrentRank is null
        // for every not-yet-earned rec, so the old $cur comparison never yielded 'below'.
        $elig    = !$isLad ? 'nonladder' : (!empty($group['AlreadyHas']) ? 'ator' : 'below');
        $snoozed = !empty($group['IsSnoozed']) ? 1 : 0;
        // A cluster is dismissed only when every member recommendation is; live
        // clusters never carry this even with the Show-dismissed filter on.
        $dismissed = !empty($group['IsDismissed']) ? 1 : 0;
        $pid     = (int)$group['ParkId'];
        $abbrev  = $Parks[$pid]['Abbrev'] ?? '';
        $memberIds = $group['MemberRecIds'];
        $memberCount = count($memberIds);
        $support = (int)$group['SupportCount'];
        // Court membership = union of any member's courts (CourtMap is keyed by rec id).
        $gcourts = [];
        foreach ($memberIds as $mid2) { foreach (($CourtMap[$mid2] ?? []) as $c) { $gcourts[$c['CourtAwardId']] = $c; } }
        $gcourts = array_values($gcourts);
        $courtJson = htmlspecialchars(json_encode($gcourts), ENT_QUOTES);
        // Member detail (recommender + reason + that member's seconds) for the expand.
        $membersFull = array_map(function ($m) {
            return [
                'By'      => $m['RecommendedByName'] ?? (!empty($m['IsAnonymous']) ? 'Anonymous' : ''),
                'Date'    => rmNiceDate($m['DateRecommended'] ?? ''),
                'Reason'  => $m['Reason'] ?? '',
                'Seconds' => array_map(function ($s) {
                    return ['Name' => $s['SupporterName'] ?? '', 'Notes' => $s['Notes'] ?? ''];
                }, $m['Seconds'] ?? []),
            ];
        }, $group['Members']);
        $membersFullJson = htmlspecialchars(json_encode($membersFull), ENT_QUOTES);
        // Group action payload (grant keys on recipient/award/rank; RepRecId for Add-to-Court).
        $gpayload = htmlspecialchars(json_encode([
            'MundaneId'      => $gMid,
            'KingdomAwardId' => $gKaid,
            'Rank'           => $gRank,
            'Persona'        => $group['Persona'] ?? '',
            'AwardName'      => $group['AwardName'] ?? '',
            'HeldRank'       => (int)($group['HeldRank'] ?? 0),
            'RepRecId'       => (int)$group['RepRecId'],
            // Retirement is a property of the recipient; the Grant and Add-to-Court
            // modals read this payload, so the flag has to reach them too.
            'IsRetired'      => !empty($group['IsRetired']),
            // No 'Reason' here: it is already carried in data-membersfull and the
            // client reads it from there at click time (one copy per row, not two).
        ]), ENT_QUOTES);
        $membersJson = htmlspecialchars(json_encode($memberIds), ENT_QUOTES);
        // Accessible name fragment for this row's controls. A bare glyph button or
        // an unlabelled checkbox announces as "button ▸" / "checkbox" 362 times over;
        // recipient + honor is the only thing that tells one row from the next.
        // Built by joining non-empty parts, NOT by trimming a pre-joined string: trim()'s
        // charlist is BYTES, and the em-dash is E2 80 94. A persona starting with any
        // U+2000-U+2FFF character (★, ⚔, ➤ …) would lose its E2 lead byte, leaving invalid
        // UTF-8 that htmlspecialchars() then returns as '' — a silently nameless control,
        // on exactly the rows with the most distinctive names.
        $rowParts    = array_filter([trim((string)($group['Persona'] ?? '')), trim((string)($group['AwardName'] ?? ''))], 'strlen');
        $rowLabelEsc = htmlspecialchars(implode(' — ', $rowParts), ENT_QUOTES);
    ?>
      <tr class="rm-row" data-rec-cluster="<?= (int)$group['MundaneId'] ?>:<?= (int)$group['KingdomAwardId'] ?>:<?= (int)$group['Rank'] ?>" data-snoozed="<?= $snoozed ?>" data-dismissed="<?= $dismissed ?>"
          data-passlocal="<?= !empty($group['PassedToLocal']) ? 1 : 0 ?>"
          data-courts='<?= $courtJson ?>'
          data-rec='<?= $gpayload ?>'
          data-members='<?= $membersJson ?>'
          data-membersfull='<?= $membersFullJson ?>'>
        <td class="rm-col-sel"><label><input type="checkbox" class="rm-rowsel" aria-label="Select <?= $rowLabelEsc ?>"></label></td>
        <td class="rm-col-recip">
          <a href="<?= UIR ?>Player/profile/<?= $gMid ?>"><?= htmlspecialchars($group['Persona'] ?? '') ?></a>
        </td>
        <td class="rm-col-park">
          <?php if ($abbrev) { ?><a class="rm-park" href="<?= UIR ?>Park/profile/<?= $pid ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($abbrev) ?></a><?php } else { ?><span class="rm-empty">&mdash;</span><?php } ?>
        </td>
        <td class="rm-col-award">
          <?= htmlspecialchars($group['AwardName'] ?? '') ?>
          <?php if (!empty($group['AlreadyHas'])) {
                    /* "covered by master" is NOT "already has": the recipient was never
                       given this order -- a Master peerage supersedes it. The generic
                       wording reads as "granted, nothing to do", which is the wrong call
                       when triaging a long queue. The Court Planner's picker already
                       separates the two; this keeps the screens consistent. */
                    $rmCovered = !empty($group['CoveredByMaster']); ?>
            <span class="rm-badge <?= $rmCovered ? 'rm-badge-master' : 'rm-badge-has' ?>" data-tip="<?= $rmCovered ? 'Covered by a Master peerage &mdash; the recipient was not granted this order.' : 'The recipient already holds this award.' ?>"><?= $rmCovered ? 'covered by master' : 'already has' ?></span>
          <?php } ?>
          <?php if (!empty($group['PassedToLocal'])) { ?><span class="rm-badge rm-badge-passlocal" data-tip="Passed to the local park to award."><i class="fas fa-arrow-down"></i> passed to local</span><?php } ?>
          <?php if ($elig === 'below') { ?><span class="rm-badge rm-badge-below">below rec.</span><?php } ?>
          <?php if (!empty($group['IsRetired'])) { ?><span class="rm-badge rm-badge-retired" data-tip="This recipient is retired or deceased in the ORK. Memorial honors are legitimate &mdash; just confirm before granting or staging this on a live court.">retired</span><?php } ?>
          <?php if ($dismissed) {
              // Keep the badge the size of its siblings and put the provenance in the
              // tooltip — who retired it and when is detail, not a label.
              $dBy  = trim((string)($group['DismissedByPersona'] ?? ''));
              $dAt  = rmNiceDate($group['DismissedAt'] ?? '');
              $dWho = $dBy !== '' ? ' by ' . $dBy : '';
              $dWhen = $dAt !== '' ? ' on ' . $dAt : '';
              $dTip = 'Dismissed' . $dWho . $dWhen . '. Dismissed recommendations are kept, not deleted — undelete returns this to the pending list.';
          ?><span class="rm-badge rm-badge-dismissed" data-tip="<?= htmlspecialchars($dTip) ?>">dismissed</span><?php } ?>
        </td>
        <td class="rm-col-rank">
          <?php if ($isLad) { ?><span class="ladder-rank" data-lvl="<?= min($gRank, 10) ?>"><?= $gRank ?></span><?php } else { ?><span class="rm-rank rm-nonladder">non-ladder</span><?php } ?>
        </td>
        <td class="rm-col-rec">
          <span class="rm-date"><?= htmlspecialchars(rmNiceDate($group['OldestDate'] ?? '')) ?></span>
          <span class="rm-age"><?= (int)$group['OldestAgeDays'] ?>d</span>
          <?php if ($memberCount > 1) { ?><span class="rm-by"><?= $memberCount ?> recommenders</span><?php } else { ?><span class="rm-by"><?= htmlspecialchars($membersFull[0]['By'] ?? '') ?></span><?php } ?>
        </td>
        <td class="rm-col-reason">
          <?php $r0 = trim($membersFull[0]['Reason'] ?? ''); if ($r0 === '') { ?>
            <span class="rm-empty">&mdash;</span>
          <?php } else { ?>
            <span class="rm-reason-trunc"><?= htmlspecialchars($r0) ?></span>
            <button type="button" class="rm-expand-members" data-tip="Show all recommendations" aria-label="Show all recommendations for <?= $rowLabelEsc ?>">&#9656;</button>
          <?php } ?>
        </td>
        <td class="rm-col-supp">
          <?php if ($support > 0) { ?>
            <button type="button" class="rm-supp-chip rm-expand-members" data-tip="Show supporters" aria-label="Show <?= $support ?> supporter<?= $support === 1 ? '' : 's' ?> for <?= $rowLabelEsc ?>">+<?= $support ?> &#9656;</button>
          <?php } else { ?><span class="rm-empty">0</span><?php } ?>
        </td>
        <td class="rm-col-court">
          <?php if (count($gcourts)) { $c0 = $gcourts[0]; ?>
            <a class="rm-courtbadge" href="<?= UIR ?>Court/detail/<?= (int)$c0['CourtId'] ?>"><?= htmlspecialchars($c0['Name']) ?><?php if (count($gcourts) > 1) { ?> <span class="rm-courtmore">+<?= count($gcourts) - 1 ?></span><?php } ?></a>
          <?php } else { ?><span class="rm-empty">&mdash;</span><?php } ?>
        </td>
        <td class="rm-col-act">
          <?php if ($dismissed) { ?>
          <button type="button" class="rm-act rm-act-undelete" data-tip="Undelete &mdash; return this recommendation to the pending list" aria-label="Undelete recommendation"><i class="fas fa-trash-can-arrow-up"></i></button>
          <?php } else { ?>
          <button type="button" class="rm-act rm-act-grant"  data-tip="Grant now" aria-label="Grant this award now">&#9889;</button>
          <button type="button" class="rm-act rm-act-court"  data-tip="Add to court" aria-label="Add to a court plan">&#65291;</button>
          <button type="button" class="rm-act rm-act-snooze" aria-label="<?= $snoozed ? 'Unsnooze this recommendation' : 'Snooze until the next monarchy' ?>"><span class="rm-snooze-ico" aria-hidden="true"><?= $snoozed ? '&#128276;' : '&#128164;' ?></span><span class="rm-snooze-tip" aria-hidden="true"><?php if ($snoozed) { ?><strong>Unsnooze</strong>Restore this recommendation to the active list.<?php } else { ?><strong>Snooze to Next Monarchy</strong>Temporarily dismiss this recommendation until either the Monarch or Regent officer at this level changes.<?php } ?></span></button>
          <?php if (($Context ?? '') === 'kingdom') { ?><button type="button" class="rm-act rm-act-passlocal<?= !empty($group['PassedToLocal']) ? ' rm-act-active' : '' ?>" aria-label="<?= !empty($group['PassedToLocal']) ? 'Remove the pass to the local park' : 'Pass to the local park' ?>"><i class="fas fa-arrow-down" aria-hidden="true"></i><span class="rm-passlocal-tip" aria-hidden="true"><strong>Send to Local Park</strong>For recommendations at a higher level than the park can provide, you are granting authority for that park to award at this level.</span></button><?php } ?>
          <button type="button" class="rm-act rm-act-dismiss" aria-label="Dismiss recommendation" data-tip="Already given out previously? No plans to award this? You can dismiss this rec.">&#10005;</button>
          <button type="button" class="rm-act rm-act-more" data-tip="More actions" aria-label="More actions" aria-expanded="false">&hellip;</button>
          <?php } ?>
          <div class="rm-act-help" hidden>
            <strong>Snooze</strong> sets this aside until the Monarch or Regent changes.
            <?php if (($Context ?? '') === 'kingdom') { ?><strong>Pass down</strong> grants the local park authority to award at this level. <?php } ?>
            <strong>Dismiss</strong> removes it from the pending list.
          </div>
        </td>
      </tr>
