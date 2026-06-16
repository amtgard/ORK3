<?php
        $gMid    = (int)$group['MundaneId'];
        $gKaid   = (int)$group['KingdomAwardId'];
        $gRank   = (int)$group['Rank'];
        $isLad   = $gRank > 0;
        $cur     = $group['CurrentRank'];
        $elig    = !$isLad ? 'nonladder' : (($cur !== null && $cur < $gRank) ? 'below' : 'ator');
        $snoozed = !empty($group['IsSnoozed']) ? 1 : 0;
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
                'Date'    => $m['DateRecommended'] ?? '',
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
            'RepRecId'       => (int)$group['RepRecId'],
            'Reason'         => $membersFull[0]['Reason'] ?? '',
        ]), ENT_QUOTES);
        $membersJson = htmlspecialchars(json_encode($memberIds), ENT_QUOTES);
    ?>
      <tr class="rm-row" data-elig="<?= $elig ?>" data-snoozed="<?= $snoozed ?>"
          data-passlocal="<?= !empty($group['PassedToLocal']) ? 1 : 0 ?>"
          data-park="<?= $pid ?>" data-courts='<?= $courtJson ?>'
          data-recip="<?= htmlspecialchars(strtolower($group['Persona'] ?? ''), ENT_QUOTES) ?>"
          data-award="<?= htmlspecialchars(strtolower($group['AwardName'] ?? ''), ENT_QUOTES) ?>"
          data-date="<?= htmlspecialchars($group['OldestDate'] ?? '', ENT_QUOTES) ?>"
          data-supp="<?= $support ?>"
          data-rank="<?= $gRank ?>"
          data-rec='<?= $gpayload ?>'
          data-members='<?= $membersJson ?>'
          data-membersfull='<?= $membersFullJson ?>'>
        <td class="rm-col-sel"><input type="checkbox" class="rm-rowsel"></td>
        <td class="rm-col-recip">
          <a href="<?= UIR ?>Playernew/index/<?= $gMid ?>"><?= htmlspecialchars($group['Persona'] ?? '') ?></a>
          <?php if ($abbrev) { ?><a class="rm-park" href="<?= UIR ?>Park/profile/<?= $pid ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($abbrev) ?></a><?php } ?>
        </td>
        <td class="rm-col-award">
          <?= htmlspecialchars($group['AwardName'] ?? '') ?>
          <?php if ($isLad) { ?><span class="ladder-rank" data-lvl="<?= min($gRank, 10) ?>" style="margin-left:6px">Rank <?= $gRank ?></span><?php } else { ?><span class="rm-rank rm-nonladder">non-ladder</span><?php } ?>
          <?php if (!empty($group['AlreadyHas'])) { ?><span class="rm-badge rm-badge-has">already has</span><?php } ?>
          <?php if (!empty($group['PassedToLocal'])) { ?><span class="rm-badge rm-badge-passlocal" data-tip="Passed to the local park to award."><i class="fas fa-arrow-down"></i> passed to local</span><?php } ?>
          <?php if ($elig === 'below') { ?><span class="rm-badge rm-badge-below">below rec.</span><?php } ?>
        </td>
        <td class="rm-col-rec">
          <span class="rm-date"><?= htmlspecialchars($group['OldestDate'] ?? '') ?></span>
          <span class="rm-age"><?= (int)$group['OldestAgeDays'] ?>d</span>
          <?php if ($memberCount > 1) { ?><span class="rm-by"><?= $memberCount ?> recommenders</span><?php } else { ?><span class="rm-by"><?= htmlspecialchars($membersFull[0]['By'] ?? '') ?></span><?php } ?>
        </td>
        <td class="rm-col-reason">
          <?php $r0 = trim($membersFull[0]['Reason'] ?? ''); if ($r0 === '') { ?>
            <span class="rm-empty">&mdash;</span>
          <?php } else { ?>
            <span class="rm-reason-trunc"><?= htmlspecialchars($r0) ?></span>
            <button type="button" class="rm-expand-members" data-tip="Show all recommendations">&#9656;</button>
          <?php } ?>
        </td>
        <td class="rm-col-supp">
          <?php if ($support > 0) { ?>
            <button type="button" class="rm-supp-chip rm-expand-members" data-tip="Show supporters">+<?= $support ?> &#9656;</button>
          <?php } else { ?><span class="rm-empty">0</span><?php } ?>
        </td>
        <td class="rm-col-court">
          <?php if (count($gcourts)) { $c0 = $gcourts[0]; ?>
            <a class="rm-courtbadge" href="<?= UIR ?>Court/detail/<?= (int)$c0['CourtId'] ?>"><?= htmlspecialchars($c0['Name']) ?><?php if (count($gcourts) > 1) { ?> <span class="rm-courtmore">+<?= count($gcourts) - 1 ?></span><?php } ?></a>
          <?php } else { ?><span class="rm-empty">&mdash;</span><?php } ?>
        </td>
        <td class="rm-col-act">
          <button type="button" class="rm-act rm-act-grant"  data-tip="Grant now">&#9889;</button>
          <button type="button" class="rm-act rm-act-court"  data-tip="Add to court">&#65291;</button>
          <button type="button" class="rm-act rm-act-snooze" data-tip="<?= $snoozed ? 'Unsnooze' : 'Snooze' ?>"><?= $snoozed ? '&#128276;' : '&#128164;' ?></button>
          <?php if (($Context ?? '') === 'kingdom') { ?><button type="button" class="rm-act rm-act-passlocal<?= !empty($group['PassedToLocal']) ? ' rm-act-active' : '' ?>"><i class="fas fa-arrow-down"></i><span class="rm-passlocal-tip"><strong>Send to Local Park</strong>For recommendations at a higher level than the park can provide, you are granting authority for that park to award at this level.</span></button><?php } ?>
          <button type="button" class="rm-act rm-act-dismiss" data-tip="Already given out previously? No plans to award this? You can dismiss this rec.">&#10005;</button>
        </td>
      </tr>
