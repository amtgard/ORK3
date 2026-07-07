<?php /*
 * Lazy-loaded inner body for the "Recommendations" tab on the Kingdom profile.
 * Rendered by Controller_Kingdom::recommendations_panel($kingdom_id) on first
 * tab activation — kept out of the initial profile() page load because rendering
 * thousands of <tr> rows inline blew up the browser's DOMContentLoaded handler.
 * Expects $AwardRecommendations, $IsLoggedIn, $CanManageKingdom, $kingdom_name,
 * $kingdom_id to be in scope.
 */ ?>
			<?php if ($IsLoggedIn): ?>
			<div class="pk-tab-toolbar">
				<button class="kn-btn kn-btn-secondary" onclick="knOpenRecModal()">
					<i class="fas fa-star"></i> Recommend an Award
				</button>
			</div>
			<?php endif; ?>
			<?php if (empty($AwardRecommendations)): ?>
			<div class="pk-recs-empty">There are no open award recommendations for <?= htmlspecialchars($kingdom_name) ?>.</div>
			<?php else: ?>
			<?php if (($CanManageKingdom ?? false) || !empty($ViewerHasCircle)): ?>
			<div class="kn-rec-filter-bar">
				<button class="kn-rec-filter-btn kn-rec-filter-active" data-filter="open">Open Recs</button>
				<button class="kn-rec-filter-btn" data-filter="below">Below Rec'd</button>
				<button class="kn-rec-filter-btn" data-filter="nonladder">Non-Ladder</button>
				<button class="kn-rec-filter-btn" data-filter="already">At or Above Rec'd</button>
				<button class="kn-rec-filter-btn" data-filter="all">All</button>
				<?php if (!empty($ViewerHasCircle)): ?>
				<button class="kn-rec-filter-btn" data-filter="mycircles"><i class="fas fa-users"></i> My Circles</button>
				<?php endif; ?>
				<span class="kn-rec-filter-info">
					<button class="kn-rec-filter-info-btn" type="button" aria-label="Filter help"><i class="fas fa-question-circle"></i></button>
					<div class="kn-rec-filter-popover">
						<h4>About These Filters</h4>
						<dl>
							<dt>Open Recs <small style="font-weight:400;color:#718096">(default)</small></dt>
							<dd>All pending recommendations &mdash; both rank-based and flat awards. Hides recs that have already been fulfilled.</dd>
							<dt>Below Rec'd</dt>
							<dd>Players who haven&rsquo;t yet reached the recommended rank. The core action list &mdash; Grant these.</dd>
							<dt>Non-Ladder</dt>
							<dd>Includes titles such as Master, Noble, or Knight, custom awards, and other non-ranked options. Grant or Delete as appropriate.</dd>
							<dt>At or Above Rec'd</dt>
							<dd>Players who already hold this award at or above the recommended rank. The rec has been fulfilled &mdash; Delete these to keep the list tidy.</dd>
							<dt>All</dt>
							<dd>Every recommendation regardless of status. Use for a full audit.</dd>
							<?php if (!empty($ViewerHasCircle)): ?>
							<dt>My Circles</dt>
							<dd>Open recommendations your peerage circle votes on &mdash; all knighthood recs (knights vote as a group) plus recs for each Paragon you hold.</dd>
							<?php endif; ?>
						</dl>
					</div>
				</span>
				<span class="kn-rec-export-btns">
					<button class="kn-rec-export-btn" type="button" onclick="knRecPrint()"><i class="fas fa-print"></i> Print</button>
					<button class="kn-rec-export-btn" type="button" onclick="knRecCsv()"><i class="fas fa-download"></i> CSV</button>
				</span>
			</div>
			<?php endif; ?>
				<div class="pk-recs-table-wrap">
				<table id="kn-rec-table" class="pk-recs-table display" data-circle-ids="<?= htmlspecialchars(json_encode($ViewerCircleAwardIds ?? array())) ?>">
					<thead>
						<tr>
							<th>Player</th>
							<th>Park</th>
							<th>Award</th>
							<th>Rank</th>
							<th data-short="Rec. By">Recommended By</th>
							<th>Date</th>
							<th>Notes</th>
							<?php if (!empty($IsLoggedIn)): ?><th style="width:1%;white-space:nowrap"></th><?php endif; ?>
						</tr>
					</thead>
					<tbody id="kn-recs-tbody">
					<?php foreach ($AwardRecommendations as $rec): ?>
					<tr class="pk-rec-row"
						data-rec-id="<?= (int)$rec['RecommendationsId'] ?>" data-award-id="<?= (int)$rec['AwardId'] ?>"
						data-filter="<?= !empty($rec['AlreadyHas']) ? 'already' : ((int)$rec['Rank'] > 0 ? 'below' : 'nonladder') ?>">
						<td><a href="<?= UIR ?>Player/profile/<?= (int)$rec['MundaneId'] ?>"><?= htmlspecialchars($rec['Persona']) ?></a></td>
						<td><?php if (!empty($rec['ParkId'])): ?><a href="<?= UIR ?>Park/profile/<?= (int)$rec['ParkId'] ?>"><?= htmlspecialchars($rec['ParkName']) ?></a><?php else: ?>&mdash;<?php endif; ?></td>
						<td><?= htmlspecialchars($rec['AwardName']) ?></td>
						<td style="white-space:nowrap">
							<?= (int)$rec['Rank'] > 0 ? (int)$rec['Rank'] : '&mdash;' ?>
							<?php if (!empty($rec['AlreadyHas'])): ?>
							<span class="pk-rec-has-tip"
								title="<?= (int)$rec['Rank'] > 0 ? 'Player is currently at rank ' . (int)$rec['CurrentRank'] . ' as of ' . htmlspecialchars($rec['CurrentRankDate'] ?? '') : 'Player already has this award (granted ' . htmlspecialchars($rec['CurrentRankDate'] ?? 'unknown date') . ')' ?>">
								<i class="fas fa-info-circle"></i>
							</span>
							<?php endif; ?>
						</td>
						<td><?php if (!empty($rec['RecommendedById'])): ?><a href="<?= UIR ?>Player/profile/<?= (int)$rec['RecommendedById'] ?>"><?= htmlspecialchars($rec['RecommendedByName']) ?></a><?php else: ?>&mdash;<?php endif; ?></td>
						<td><?= htmlspecialchars($rec['DateRecommended']) ?></td>
						<td class="pk-rec-notes"><?php if (!empty($rec['Reason'])): ?><span class="pk-rec-notes-short"><?= htmlspecialchars(mb_substr($rec['Reason'], 0, 50)) ?><?php if (mb_strlen($rec['Reason']) > 50): ?><span class="pk-rec-notes-ellipsis">&hellip; <button class="pk-rec-expand-btn" type="button">[&hellip;]</button></span><span class="pk-rec-notes-full" style="display:none"><?= htmlspecialchars(mb_substr($rec['Reason'], 50)) ?> <button class="pk-rec-expand-btn pk-rec-collapse-btn" type="button">[&laquo;]</button></span><?php endif; ?></span><?php else: ?>&mdash;<?php endif; ?>
							<?php if (!empty($rec['ViewerCanEditReason'])): ?>
							<button class="rs-edit-reason-btn" data-rec="<?= (int)$rec['RecommendationsId'] ?>" data-reason="<?= htmlspecialchars($rec['Reason'] ?? '', ENT_QUOTES) ?>" data-award="<?= htmlspecialchars($rec['AwardName'] ?? '', ENT_QUOTES) ?>" data-rstip="Edit your reason"><i class="fas fa-pen"></i></button>
							<?php endif; ?>
							<?php if (!empty($rec['Seconds']) && is_array($rec['Seconds'])): ?>
							<div class="rs-seconds">
								<?php foreach ($rec['Seconds'] as $sec): ?>
								<div class="rs-second"><i class="fas fa-thumbs-up" style="color:#48bb78;font-size:10px"></i><a class="rs-supporter" href="<?= UIR ?>Player/profile/<?= (int)$sec['SupporterMundaneId'] ?>"><?= htmlspecialchars($sec['SupporterName'] ?? '') ?></a><?php if (!empty($sec['Notes'])): $_sn = $sec['Notes']; ?><span class="rs-notes">&mdash; "<?php if (mb_strlen($_sn) > 50): ?><span class="pk-rec-notes-short"><?= htmlspecialchars(mb_substr($_sn, 0, 50)) ?><span class="pk-rec-notes-ellipsis">&hellip; <button class="pk-rec-expand-btn" type="button">[&hellip;]</button></span><span class="pk-rec-notes-full" style="display:none"><?= htmlspecialchars(mb_substr($_sn, 50)) ?> <button class="pk-rec-expand-btn pk-rec-collapse-btn" type="button">[&laquo;]</button></span></span><?php else: ?><?= htmlspecialchars($_sn) ?><?php endif; ?>"</span><?php endif; ?><?php $_canWithdrawSec = !empty($sec['IsMine']) || ($CanManageKingdom ?? false); if (!empty($sec['IsMine']) || $_canWithdrawSec): ?> <span class="rs-second-actions"><?php if (!empty($sec['IsMine'])): ?><button class="rs-second-edit" data-sid="<?= (int)$sec['RecommendationSecondsId'] ?>" data-notes="<?= htmlspecialchars($sec['Notes'] ?? '', ENT_QUOTES) ?>" data-rstip="Edit your notes"><i class="fas fa-pen"></i></button><?php endif; ?><?php if ($_canWithdrawSec): ?><button class="rs-second-withdraw" data-sid="<?= (int)$sec['RecommendationSecondsId'] ?>" data-supporter="<?= htmlspecialchars($sec['SupporterName'] ?? '', ENT_QUOTES) ?>" data-rstip="<?= !empty($sec['IsMine']) ? 'Withdraw your second' : 'Remove this second' ?>"><i class="fas fa-times"></i></button><?php endif; ?></span><?php endif; ?></div>
								<?php endforeach; ?>
							</div>
							<?php endif; ?>
						</td>
						<?php if (!empty($IsLoggedIn)): ?>
						<td class="pk-rec-actions rs-tip-right" style="white-space:nowrap;text-align:right;width:1%">
							<?php if (!empty($rec['SecondsCount'])): $_sc = (int)$rec['SecondsCount']; ?>
							<span class="rs-seconds-badge" data-rstip="<?= $_sc ?> supporting <?= $_sc === 1 ? 'second' : 'seconds' ?>"><i class="fas fa-thumbs-up"></i><?= $_sc ?></span>
							<?php endif; ?>
							<?php if (!empty($rec['ViewerCanSecond'])): ?>
							<button class="rs-action-btn" data-rec="<?= (int)$rec['RecommendationsId'] ?>" data-award="<?= htmlspecialchars($rec['AwardName'] ?? '', ENT_QUOTES) ?>" data-recipient="<?= htmlspecialchars($rec['Persona'] ?? '', ENT_QUOTES) ?>" data-rstip="Second this recommendation and add your feedback."><i class="fas fa-plus"></i></button>
							<?php endif; ?>
							<?php if ($CanManageKingdom ?? false): ?>
							<button class="pk-btn pk-btn-primary pk-rec-grant-btn"
								data-rec="<?= htmlspecialchars(json_encode(['RecommendationsId'=>(int)$rec['RecommendationsId'],'MundaneId'=>(int)$rec['MundaneId'],'Persona'=>$rec['Persona'],'KingdomAwardId'=>(int)$rec['KingdomAwardId'],'Rank'=>(int)$rec['Rank'],'Reason'=>$rec['Reason']??''])) ?>">
								<i class="fas fa-medal"></i> Grant
							</button>
							<button class="pk-rec-dismiss-btn"
								data-rec-id="<?= (int)$rec['RecommendationsId'] ?>">
								<i class="fas fa-times"></i> Delete
							</button>
							<?php endif; ?>
						</td>
						<?php endif; ?>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
			<?php if ($CanManageKingdom ?? false): ?>
			<div class="pk-deleted-recs" id="kn-deleted-recs" data-loaded="0">
				<button type="button" class="pk-deleted-recs-toggle" id="kn-deleted-recs-toggle" aria-expanded="false">
					<span class="pk-deleted-recs-caret">&#9654;</span>
					<span class="pk-deleted-recs-toggle-label">Show Deleted Recommendations</span>
					<span class="pk-deleted-recs-count" id="kn-deleted-recs-count" style="display:none">0</span>
				</button>
				<div class="pk-deleted-recs-body" id="kn-deleted-recs-body" style="display:none">
					<div class="pk-deleted-recs-loading" id="kn-deleted-recs-loading">Loading&hellip;</div>
					<div class="pk-deleted-recs-empty" id="kn-deleted-recs-empty" style="display:none">No deleted recommendations.</div>
					<div class="pk-deleted-recs-table-wrap" id="kn-deleted-recs-table-wrap" style="display:none">
						<table class="pk-deleted-recs-table">
							<thead>
								<tr>
									<th>Player</th>
									<th>Award</th>
									<th data-dt-type="num">Rank</th>
									<th>Notes</th>
									<th data-dt-type="date">Date Rec.</th>
									<th>Recommended By</th>
									<th data-dt-type="date">Deleted At</th>
									<th>Deleted By</th>
									<th class="no-export"></th>
								</tr>
							</thead>
							<tbody id="kn-deleted-recs-tbody"></tbody>
						</table>
					</div>
				</div>
			</div>
			<?php endif; ?>
