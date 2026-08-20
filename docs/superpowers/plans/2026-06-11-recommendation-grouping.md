# Consolidated Recommendation Grouping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Recommendations Manager renders one row per `(recipient, kingdomaward_id, rank)` cluster instead of per-rec; granting a cluster resolves all its parallel recs and notifies every advocate, and the same cluster-resolution runs on court grants.

**Architecture:** A shared `class.Player::ResolveRecommendationCluster` finds every live rec in a cluster and resolves each by calling the existing `DeleteAwardRecommendation(Granted=1)` — which already does the Thread-B notify-before-soft-delete + seconds cascade per rec. The Manager's group-grant and `CourtAjax::grant_award` both invoke it. `controller.Recommendations::manage` groups the loaded recs server-side into `$Groups`; the template renders one row per group with a member-expand and group-level actions.

**Tech Stack:** PHP (ork3 lib classes use `global $DB; $this->db->Clear()/DataSet()/Execute()`; model passthrough convention `model.Player::x → class.Player::X`), MariaDB, plain-PHP `.tpl` with inline JS, `rm-` Manager CSS prefix.

**Verification model:** No PHP unit-test framework. Verify via `php -l` lint, plus curl-auth + DB read-back against the local DB (recommendations + seconds + the 3,657 real clusters exist locally; `ork_notification` exists; Docker is up). The court-grant call site is verified by lint + inspection (court grant needs court data); the cluster resolver it shares is exercised via the Manager path.

**Reuses (already shipped this branch):** `DeleteAwardRecommendation($request)` honors `$request['Granted']` (fires `notifyRecommendationGranted` before soft-delete) and cascades seconds. `notifyRecommendationGranted` excludes the granter. The Manager's `rmDoGrant` and the "already on a court" 3-way modal exist.

---

## File Structure

- **Modify** `system/lib/ork3/class.Player.php` — add `ResolveRecommendationCluster`.
- **Modify** `orkui/model/model.Player.php` — add `resolve_player_recommendation_cluster` passthrough.
- **Modify** `orkui/controller/controller.KingdomAjax.php` + `controller.ParkAjax.php` — `resolverecommendationcluster` AJAX action.
- **Modify** `orkui/controller/controller.CourtAjax.php` — `grant_award` uses the cluster resolver (replaces the single-rec soft-delete block).
- **Modify** `orkui/controller/controller.Recommendations.php` — build `$Groups`.
- **Modify** `orkui/template/revised-frontend/Recommendations_manage.tpl` — group rows, member expand, group actions.

---

### Task 1: `ResolveRecommendationCluster` + model passthrough

**Files:**
- Modify: `system/lib/ork3/class.Player.php` (add a method near `DeleteAwardRecommendation`)
- Modify: `orkui/model/model.Player.php` (near `delete_player_recommendation`, line ~196)

- [ ] **Step 1: Add `ResolveRecommendationCluster` to `class.Player.php`**

Add this method (it is a `$request`-array method like its neighbors; it relies on the per-rec authority + audit + notify + cascade already inside `DeleteAwardRecommendation`):

```php
	// Resolve every live recommendation in a (recipient, kingdomaward, rank) cluster
	// as "granted": each member runs through DeleteAwardRecommendation(Granted=1), which
	// notifies that rec's advocates BEFORE soft-deleting it and cascading its seconds.
	// Used by the Manager group-grant and CourtAjax::grant_award. No-ops on an empty cluster.
	public function ResolveRecommendationCluster($request) {
		$mundane_id = (int)($request['MundaneId'] ?? 0);
		$ka_id      = (int)($request['KingdomAwardId'] ?? 0);
		$rank       = (int)($request['Rank'] ?? 0);
		if (!valid_id($mundane_id) || !valid_id($ka_id)) {
			return ['Status' => 0, 'Resolved' => 0];
		}
		$this->db->Clear();
		$rs = $this->db->DataSet(
			'SELECT recommendations_id FROM ' . DB_PREFIX . 'recommendations
			  WHERE mundane_id = ' . $mundane_id . '
			    AND kingdomaward_id = ' . $ka_id . '
			    AND rank = ' . $rank . '
			    AND deleted_at IS NULL'
		);
		$ids = [];
		if ($rs) { while ($rs->Next()) { $ids[] = (int)$rs->recommendations_id; } }

		$resolved = 0;
		foreach ($ids as $rid) {
			$r = $this->DeleteAwardRecommendation([
				'Token'             => $request['Token'] ?? '',
				'RecommendationsId' => $rid,
				'RequestedBy'       => (int)($request['RequestedBy'] ?? 0),
				'Granted'           => 1,
			]);
			if ((int)($r['Status'] ?? 1) === 0) { $resolved++; }
		}
		return ['Status' => 0, 'Resolved' => $resolved];
	}
```

- [ ] **Step 2: Add the model passthrough**

In `orkui/model/model.Player.php`, after `delete_player_recommendation` (line ~196-198), add:

```php
	function resolve_player_recommendation_cluster($request) {
		return $this->Player->ResolveRecommendationCluster($request);
	}
```

- [ ] **Step 3: Lint**

Run: `php -l system/lib/ork3/class.Player.php && php -l orkui/model/model.Player.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Player.php orkui/model/model.Player.php
git commit -m "Rec grouping: ResolveRecommendationCluster (resolve+notify a whole cluster)"
```

---

### Task 2: `resolverecommendationcluster` AJAX endpoint (Kingdom + Park)

**Files:**
- Modify: `orkui/controller/controller.KingdomAjax.php` (alongside the `dismissrecommendation` action)
- Modify: `orkui/controller/controller.ParkAjax.php` (same)

- [ ] **Step 1: Add the action to KingdomAjax**

Find the `} elseif ($action === 'dismissrecommendation') {` block and add this sibling branch immediately after its closing (before the next `} elseif`):

```php
		} elseif ($action === 'resolverecommendationcluster') {
			$this->load_model('Player');
			$r = $this->Player->resolve_player_recommendation_cluster([
				'Token'          => $this->session->token,
				'MundaneId'      => (int)($_POST['MundaneId']      ?? 0),
				'KingdomAwardId' => (int)($_POST['KingdomAwardId'] ?? 0),
				'Rank'           => (int)($_POST['Rank']           ?? 0),
				'RequestedBy'    => $this->session->user_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0, 'resolved' => (int)($r['Resolved'] ?? 0)])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
```

- [ ] **Step 2: Add the identical action to ParkAjax**

In `orkui/controller/controller.ParkAjax.php`, find its `dismissrecommendation` action branch and add the same `resolverecommendationcluster` branch (identical code). If ParkAjax's action dispatch differs structurally, mirror its local style but keep the same POST fields + model call.

- [ ] **Step 3: Lint**

Run: `php -l orkui/controller/controller.KingdomAjax.php && php -l orkui/controller/controller.ParkAjax.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Local end-to-end test (cluster exists locally)**

Cluster `mundane_id=16408, kingdomaward_id=752, rank=0` has 15 live recs. Find its kingdom and an officer, then via curl-auth POST `KingdomAjax/kingdom/{kid}/resolverecommendationcluster` with `MundaneId=16408&KingdomAwardId=752&Rank=0`. Expect `{"status":0,"resolved":15}`, then DB read-back: those 15 recs now have `deleted_at` set, their seconds cascaded, and `ork_notification` gained one `rec_granted` per recommender + `second_granted` per seconder.
**Caution:** this soft-deletes real local recs. Either snapshot/restore them afterward (`UPDATE ork_recommendations SET deleted_at=NULL,deleted_by=NULL WHERE ...` + same for seconds), or use a smaller throwaway cluster. Record the result; do not leave the local DB mutated.

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.KingdomAjax.php orkui/controller/controller.ParkAjax.php
git commit -m "Rec grouping: resolverecommendationcluster AJAX endpoint"
```

---

### Task 3: `CourtAjax::grant_award` uses the cluster resolver

**Files:**
- Modify: `orkui/controller/controller.CourtAjax.php` (`grant_award`, the rec-resolution block ~lines 450-471)

- [ ] **Step 1: Replace the single-rec soft-delete block with a cluster resolve**

Find this block (the integration-QA version — notify + soft-delete header + seconds cascade for the single linked rec):

```php
        // Notify the recommender + seconders BEFORE soft-deleting the recommendation
        // (the seconds query needs them still live). Non-blocking: never fail the grant.
        if ((int)$ca->recommendations_id > 0) {
            try {
                Ork3::$Lib->notification->notifyRecommendationGranted((int)$ca->recommendations_id, $uid);
            } catch (\Throwable $e) { /* notifications are best-effort */ }

            $DB->Clear();
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'recommendations
                 SET deleted_by = ' . $uid . ', deleted_at = NOW()
                 WHERE recommendations_id = ' . (int)$ca->recommendations_id
            );
            // Cascade the soft-delete to the rec's seconds (mirrors
            // class.Player::DeleteAwardRecommendation) so no orphaned live seconds remain.
            $DB->Clear();
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'recommendation_seconds
                 SET deleted_by = ' . $uid . ', deleted_at = NOW()
                 WHERE recommendations_id = ' . (int)$ca->recommendations_id . '
                   AND deleted_at IS NULL'
            );
        }
```

Replace it with (resolve the whole cluster for the granted award's recipient/award/rank — clears ALL parallel recs + notifies every advocate; no-ops if none):

```php
        // Resolve the whole recommendation cluster for the granted award
        // (recipient + award + rank): soft-deletes every parallel rec and notifies
        // each advocate, via the shared resolver. No-ops when there are no live recs.
        // Non-blocking: a resolver/notify failure must never fail the grant.
        try {
            $this->load_model('Player');
            $this->Player->resolve_player_recommendation_cluster([
                'Token'          => $this->session->token,
                'MundaneId'      => (int)$ca->mundane_id,
                'KingdomAwardId' => (int)$ca->kingdomaward_id,
                'Rank'           => (int)$ca->rank,
                'RequestedBy'    => $uid,
            ]);
        } catch (\Throwable $e) { /* recommendation cleanup is best-effort */ }
```

- [ ] **Step 2: Lint**

Run: `php -l orkui/controller/controller.CourtAjax.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.CourtAjax.php
git commit -m "Rec grouping: court grant resolves the whole rec cluster"
```

---

### Task 4: Build `$Groups` in `controller.Recommendations::manage`

**Files:**
- Modify: `orkui/controller/controller.Recommendations.php` (after `$recs` is finalized, ~line 69)

- [ ] **Step 1: Add grouping after the `$recs` load**

Immediately after `$recs = $this->Reports->recommended_awards($req); if (!is_array($recs)) { $recs = []; }` (line ~68-69), add:

```php
        // Group parallel recommendations by (recipient, kingdomaward, rank). Non-destructive:
        // the underlying rec rows are untouched; the grid renders one row per cluster.
        $groups = [];
        foreach ($recs as $rec) {
            $mid = (int)($rec['MundaneId'] ?? 0);
            $kaid = (int)($rec['KingdomAwardId'] ?? 0);
            $rank = (int)($rec['Rank'] ?? 0);
            $key = $mid . ':' . $kaid . ':' . $rank;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'MundaneId'      => $mid,
                    'KingdomAwardId' => $kaid,
                    'Rank'           => $rank,
                    'Persona'        => $rec['Persona'] ?? '',
                    'AwardName'      => $rec['AwardName'] ?? '',
                    'ParkId'         => (int)($rec['ParkId'] ?? 0),
                    'AlreadyHas'     => !empty($rec['AlreadyHas']),
                    'CurrentRank'    => isset($rec['CurrentRank']) ? (int)$rec['CurrentRank'] : null,
                    'Members'        => [],
                    'MemberRecIds'   => [],
                    'OldestAgeDays'  => 0,
                    'OldestDate'     => $rec['DateRecommended'] ?? '',
                    'RepRecId'       => (int)($rec['RecommendationsId'] ?? 0),
                    '_advocates'     => [],
                    '_allSnoozed'    => true,
                ];
            }
            $g = &$groups[$key];
            $g['Members'][]      = $rec;
            $g['MemberRecIds'][] = (int)($rec['RecommendationsId'] ?? 0);
            $age = (int)($rec['AgeDays'] ?? 0);
            if ($age >= $g['OldestAgeDays']) {
                $g['OldestAgeDays'] = $age;
                $g['OldestDate']    = $rec['DateRecommended'] ?? '';
                $g['RepRecId']      = (int)($rec['RecommendationsId'] ?? 0); // oldest = representative
            }
            if (!empty($rec['RecommendedById'])) { $g['_advocates'][(int)$rec['RecommendedById']] = true; }
            foreach (($rec['Seconds'] ?? []) as $s) {
                if (!empty($s['SupporterMundaneId'])) { $g['_advocates'][(int)$s['SupporterMundaneId']] = true; }
            }
            if (empty($rec['IsSnoozed'])) { $g['_allSnoozed'] = false; }
            unset($g);
        }
        foreach ($groups as $k => $g) {
            unset($g['_advocates'][$g['MundaneId']]); // a self-rec advocate never counts
            $groups[$k]['SupportCount'] = count($g['_advocates']);
            $groups[$k]['IsSnoozed']    = $g['_allSnoozed']; // cluster snoozed only if every member is
            unset($groups[$k]['_advocates'], $groups[$k]['_allSnoozed']);
        }
        $this->data['Groups'] = array_values($groups);
```

- [ ] **Step 2: Lint**

Run: `php -l orkui/controller/controller.Recommendations.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add orkui/controller/controller.Recommendations.php
git commit -m "Rec grouping: build cluster groups in the Manager controller"
```

---

### Task 5: Regroup the Manager grid (`Recommendations_manage.tpl`)

**Files:**
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl`

This task converts the per-rec grid to per-group. The existing filter/sort/selection JS keys off row `data-*` attributes and `.rm-row`, so keeping the same attribute names on group rows lets that JS work unchanged. Read the current handlers you replace (grant ~line 897+, support/reason expand ~line 677-700, snooze ~847, dismiss ~864, bulk ~886-1050) before editing.

- [ ] **Step 1: Replace the row-rendering loop**

Replace the `<?php foreach ($Recommendations as $rec) { ... } ?>` block (the `<tbody>` body, ~lines 511-589) with this group-based loop. It builds the same `data-*` attributes the existing JS expects, plus `data-members` (member rec ids for group actions) and `data-membersfull` (for the member expand):

```php
    <?php foreach ($Groups as $group) {
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
        ]), ENT_QUOTES);
        $membersJson = htmlspecialchars(json_encode($memberIds), ENT_QUOTES);
    ?>
      <tr class="rm-row" data-elig="<?= $elig ?>" data-snoozed="<?= $snoozed ?>"
          data-park="<?= $pid ?>" data-courts='<?= $courtJson ?>'
          data-recip="<?= htmlspecialchars(strtolower($group['Persona'] ?? ''), ENT_QUOTES) ?>"
          data-award="<?= htmlspecialchars(strtolower($group['AwardName'] ?? ''), ENT_QUOTES) ?>"
          data-date="<?= htmlspecialchars($group['OldestDate'] ?? '', ENT_QUOTES) ?>"
          data-supp="<?= $support ?>"
          data-rec='<?= $gpayload ?>'
          data-members='<?= $membersJson ?>'
          data-membersfull='<?= $membersFullJson ?>'>
        <td class="rm-col-sel"><input type="checkbox" class="rm-rowsel"></td>
        <td class="rm-col-recip">
          <a href="<?= UIR ?>Playernew/index/<?= $gMid ?>"><?= htmlspecialchars($group['Persona'] ?? '') ?></a>
          <?php if ($abbrev) { ?><span class="rm-park"><?= htmlspecialchars($abbrev) ?></span><?php } ?>
        </td>
        <td class="rm-col-award">
          <?= htmlspecialchars($group['AwardName'] ?? '') ?>
          <?php if ($isLad) { ?><span class="rm-rank">Rank <?= $gRank ?></span><?php } else { ?><span class="rm-rank rm-nonladder">non-ladder</span><?php } ?>
          <?php if (!empty($group['AlreadyHas'])) { ?><span class="rm-badge rm-badge-has">already has</span><?php } ?>
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
          <button type="button" class="rm-act rm-act-dismiss" data-tip="Dismiss">&#10005;</button>
        </td>
      </tr>
    <?php } ?>
```

- [ ] **Step 2: Update the footer count to use Groups**

Replace `<span id="rm-count"><?= count($Recommendations) ?></span> shown` with `<span id="rm-count"><?= count($Groups) ?></span> shown`.

- [ ] **Step 3: Replace the reason/support expand handlers with a single member-expand**

The old handlers (the `.rm-supp-chip` click ~line 677 and `.rm-expand-reason` click ~line 689) build detail rows from `data-seconds`/reason. Replace BOTH with one handler keyed on `.rm-expand-members` that renders the member list from `data-membersfull`. Use the existing detail-row helper pattern (the code that inserts a `tr.rm-detailrow` after the row — reuse `rmInsertDetail`/whatever it is named in the file; if the helper is inline, mirror it). Concrete handler:

```javascript
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var btn = e.target.closest('.rm-expand-members'); if (!btn) return;
    var tr = btn.closest('tr');
    // Toggle: if a detail row already follows, remove it.
    var nx = tr.nextElementSibling;
    if (nx && nx.classList.contains('rm-detailrow')) { nx.remove(); return; }
    var members = []; try { members = JSON.parse(tr.getAttribute('data-membersfull') || '[]'); } catch (x) {}
    var html = '<ul class="rm-seclist">';
    members.forEach(function (m) {
        var who = m.By ? rmEsc(m.By) : '(unknown)';
        var when = m.Date ? ' <span class="rm-age">' + rmEsc(m.Date) + '</span>' : '';
        html += '<li><strong>' + who + '</strong>' + when +
                (m.Reason ? '<div class="rm-reason-full">' + rmEsc(m.Reason) + '</div>' : '');
        if (m.Seconds && m.Seconds.length) {
            html += '<ul class="rm-seclist">';
            m.Seconds.forEach(function (s) {
                html += '<li>&#8627; ' + rmEsc(s.Name || '') + (s.Notes ? ' — ' + rmEsc(s.Notes) : ' <em>(no note)</em>') + '</li>';
            });
            html += '</ul>';
        }
        html += '</li>';
    });
    html += '</ul>';
    var dr = document.createElement('tr');
    dr.className = 'rm-detailrow';
    dr.innerHTML = '<td></td><td colspan="7">' + html + '</td>';
    tr.parentNode.insertBefore(dr, tr.nextElementSibling);
});
```

(If the file already defines `rmEsc`, reuse it; it is used by `rmUpdateCourtBadge`/`data-seconds` rendering today. Remove the now-dead `data-seconds` row attribute and the old reason/seconds expand handlers.)

- [ ] **Step 4: Group-grant — resolve the cluster instead of dismissing one rec**

In the grant flow (`rmDoGrant` and the grant click handler), the rec payload is now the GROUP payload (`MundaneId`, `KingdomAwardId`, `Rank`, `Persona`, `RepRecId`). Change the post-grant step from `dismissrecommendation` to `resolverecommendationcluster`. Replace the `rmDoGrant` body's dismiss step:

```javascript
    }).then(function () {
        var fd2 = new FormData();
        fd2.append('RecommendationsId', rec.RecommendationsId);
        fd2.append('Granted', '1');
        return rmPost(rmRecAjaxBase('dismissrecommendation'), fd2);
```

with:

```javascript
    }).then(function () {
        var fd2 = new FormData();
        fd2.append('MundaneId', rec.MundaneId);
        fd2.append('KingdomAwardId', rec.KingdomAwardId);
        fd2.append('Rank', rec.Rank || 0);
        return rmPost(rmRecAjaxBase('resolverecommendationcluster'), fd2);
```

The grant `Admin/addaward` payload already uses `rec.KingdomAwardId`/`rec.Rank`/`rec.MundaneId`, which are present in the group payload, so the award still writes once. The "already on a court" 3-way modal (`rmOpenGrantCourtModal`) keeps working — its court reconciliation (remove/leave) already operates on `data-courts` (now the group's union), and the cluster resolve runs after.

- [ ] **Step 5: Group snooze / dismiss — loop the member rec ids**

The per-row snooze (`.rm-act-snooze` ~line 847) and dismiss (`.rm-act-dismiss` ~line 864) currently act on one `RecommendationsId`. Change each to loop `data-members`. Replace the snooze handler body to post snooze/unsnooze for every member id, and the dismiss handler to post `dismissrecommendation` (no `Granted`) for every member id, then remove the row. Concrete:

```javascript
// Snooze/unsnooze every member of the group.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var sn = e.target.closest('.rm-act-snooze'); if (!sn) return;
    var tr = sn.closest('tr');
    var snoozed = tr.getAttribute('data-snoozed') === '1';
    var action = snoozed ? 'unsnoozerecommendation' : 'snoozerecommendation';
    var ids = []; try { ids = JSON.parse(tr.getAttribute('data-members') || '[]'); } catch (x) {}
    Promise.all(ids.map(function (id) {
        var fd = new FormData(); fd.append('RecommendationsId', id);
        return rmPost(rmRecAjaxBase(action), fd);
    })).then(function () {
        tr.setAttribute('data-snoozed', snoozed ? '0' : '1');
        sn.setAttribute('data-tip', snoozed ? 'Snooze' : 'Unsnooze');
        sn.innerHTML = snoozed ? '💤' : '🔔';
        rmApplyFilters();
    });
});
// Dismiss every member of the group (plain dismiss — no Granted, no notifications).
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var ds = e.target.closest('.rm-act-dismiss'); if (!ds) return;
    var tr = ds.closest('tr');
    tnConfirm({ title: 'Dismiss recommendation?', body: 'This removes the recommendation(s) from the pending list.', confirmLabel: 'Dismiss', danger: true, onConfirm: function () {
        var ids = []; try { ids = JSON.parse(tr.getAttribute('data-members') || '[]'); } catch (x) {}
        Promise.all(ids.map(function (id) {
            var fd = new FormData(); fd.append('RecommendationsId', id);
            return rmPost(rmRecAjaxBase('dismissrecommendation'), fd);
        })).then(function () { rmRemoveRow(tr); rmToast('Dismissed.'); });
    } });
});
```

Remove the old single-rec snooze/dismiss handlers they replace. (`rmApplyFilters`, `rmRemoveRow`, `rmToast`, `tnConfirm` already exist; if the snooze icon-swap helper differs, match the file's existing icon entities.)

- [ ] **Step 6: Bulk snooze/dismiss/court — loop member ids per selected group**

The bulk handlers (`.rm-bulk-snooze` ~886, `.rm-bulk-dismiss` ~903, `.rm-bulk-court` ~1054) iterate selected rows. Update each selected row's action to loop its `data-members` ids (snooze/dismiss) the same way as Step 5; for bulk Add-to-Court, use each selected group's `RepRecId` (from `data-rec`) as the rec id passed to `add_award` (one court award per group). Keep the existing sequential/toast structure; only swap the per-row body to operate on member ids / the group payload.

- [ ] **Step 7: Add-to-Court single — use the group's RepRecId**

In the single Add-to-Court flow (`rm-act-court` → `rmOpenCourtModal`), the target payload is now the group payload; pass `RepRecId` as `RecommendationsId` and `MundaneId`/`KingdomAwardId`/`Rank` from the group when calling `CourtAjax/add_award`. One court award per group.

- [ ] **Step 8: Lint**

Run: `php -l orkui/template/revised-frontend/Recommendations_manage.tpl`
Expected: `No syntax errors detected`

- [ ] **Step 9: Commit**

```bash
git add orkui/template/revised-frontend/Recommendations_manage.tpl
git commit -m "Rec grouping: Manager grid renders clusters with group actions"
```

---

### Task 6: Verification

**Files:** none.

- [ ] **Step 1: Lint sweep** — `php -l` all six changed PHP/tpl files; expect `No syntax errors detected`.

- [ ] **Step 2: Grouping renders** — curl-auth as an officer, load `Recommendations/manage/kingdom/{kid}`; confirm a known cluster (e.g. recipient 16408 / award 752) renders as ONE row with a `+N` support chip, and that expanding it lists all member recommenders + reasons + seconds.

- [ ] **Step 3: SupportCount** — pick a cluster; confirm the rendered count equals distinct advocates (recommenders ∪ seconders, recipient excluded) from a DB query.

- [ ] **Step 4: Group-grant resolves the cluster** — on a throwaway/snapshot cluster, click Grant (or POST the resolve endpoint); DB read-back: all member recs `deleted_at` set, seconds cascaded, one `ork_notification` per recommender + seconder; the award written once. Restore the cluster afterward if using real data.

- [ ] **Step 5: Dismiss group does not notify; snooze group snoozes all members** — verify via DB read-back (no new notifications on dismiss; all members' snooze fields set on snooze).

- [ ] **Step 6: Court grant resolver (inspection)** — confirm `grant_award` calls `resolve_player_recommendation_cluster` keyed on the court award's recipient/award/rank, non-blocking. Note court-data-dependent runtime as deferred.

- [ ] **Step 7: Dark-mode walk** — the regrouped grid, support chip, member-expand detail row, and modals in dark mode.

- [ ] **Step 8: Final commit** (only if verification required fixes).

---

## Self-Review

**Spec coverage:**
- Cluster key `(recipient, kingdomaward_id, rank)`, non-destructive → Task 4. ✓
- SupportCount = distinct advocates (recommenders ∪ seconders, recipient excluded) → Task 4 `_advocates`. ✓
- Shared `ResolveRecommendationCluster` reusing per-rec resolution → Task 1. ✓
- Manager group-grant via new endpoint → Tasks 2 + 5 Step 4. ✓
- Court grant uses the resolver (supersedes the QA single-rec block) → Task 3. ✓
- Group rows + member expand + group actions (grant/add-court/snooze/dismiss) + bulk → Task 5. ✓
- Add-to-Court one award per group via RepRecId → Task 5 Steps 6-7. ✓
- Filters/sort adapt (same data-* attribute names; data-supp = SupportCount) → Task 5 Step 1. ✓
- Snooze group = all members; group IsSnoozed only if all snoozed → Task 4 + Task 5 Step 5. ✓
- Non-blocking notify preserved (inherited from DeleteAwardRecommendation; court call wrapped) → Tasks 1, 3. ✓

**Placeholder scan:** No TBD/TODO. Task 5 Steps 6-7 reference existing handlers by name/anchor with the concrete change to make (loop `data-members` / use `RepRecId`) rather than restating the whole handler — the change is fully specified, and the implementer is told to read the current handler. ✓

**Type/name consistency:** `ResolveRecommendationCluster` / `resolve_player_recommendation_cluster` / `resolverecommendationcluster` used consistently across Tasks 1-3, 5. Group payload keys (`MundaneId`, `KingdomAwardId`, `Rank`, `RepRecId`) match between Task 4 (`$gpayload`), Task 5 Step 4 (grant), and Steps 6-7. `data-members` (id list) and `data-membersfull` (expand detail) are distinct and used consistently. `$Groups` set in Task 4 matches the Task 5 loop. ✓
