<?php
	$passwordExpired = strtotime($Player['PasswordExpires']) - time() <= 0;
	$passwordExpiring = $passwordExpired ? 'Expired' : date('Y-m-j', strtotime($Player['PasswordExpires']));
	$recError = isset($_GET['rec_error']) ? htmlspecialchars(urldecode($_GET['rec_error'])) : '';

	$can_delete_recommendation = false;
	if($this->__session->user_id) {
		if (isset($this->__session->park_id)) {
			if (Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_PARK, $this->__session->park_id, AUTH_EDIT)) {
				$can_delete_recommendation = true;
			}
		} else if (isset($this->__session->kingdom_id)) {
			if (Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_KINGDOM, $this->__session->kingdom_id, AUTH_EDIT)) {
				$can_delete_recommendation = true;
			}
		}
	}

	$isSuspended = ($Player['Suspended'] == 1);
	$isActive = ($Player['Active'] == 1 && !$isSuspended);
	$pronounDisplay = (!empty($Player['PronounCustomText'])) ? $Player['PronounCustomText'] : $Player['PronounText'];
	$heraldryUrl = $Player['HasHeraldry'] > 0 ? $Player['Heraldry'] : HTTP_PLAYER_HERALDRY . '000000.jpg';
	$imageUrl = $Player['HasImage'] > 0 ? $Player['Image'] : HTTP_PLAYER_HERALDRY . '000000.jpg';

	$knightAwardIds = array(17, 18, 19, 20, 245);
	$isKnight = false;
	if (is_array($Details['Awards'])) {
		foreach ($Details['Awards'] as $a) {
			if (in_array((int)$a['AwardId'], $knightAwardIds)) {
				$isKnight = true;
				break;
			}
		}
	}
	$beltIconUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/assets/images/belt.svg';

	// Auth helpers
	$isOwnProfile  = isset($this->__session->user_id) && (int)$this->__session->user_id === (int)$Player['MundaneId'];
	$canEditAdmin  = isset($this->__session->user_id) && Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_PARK, $Player['ParkId'], AUTH_EDIT);
	$canEditImages  = $isOwnProfile || $canEditAdmin;
	$canEditAccount = $isOwnProfile || $canEditAdmin;
?>

<style>:root { --pn-hero-bg: <?= $isSuspended ? '#9b2c2c' : '#2c5282' ?>; }</style>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css">

<!-- =============================================
     ZONE 1: Profile Hero Header
     ============================================= -->
<div class="pn-hero">
	<div class="pn-hero-bg" style="background-image: url('<?= $heraldryUrl ?>')"></div>
	<div class="pn-hero-content">
		<?php if ($canEditImages): ?>
		<div class="pn-avatar pn-editable-img">
			<img class="heraldry-img" src="<?= $imageUrl ?>" alt="<?= htmlspecialchars($Player['Persona']) ?>" />
			<button class="pn-img-edit-btn" onclick="pnOpenImgModal('photo')" title="Update player photo"><i class="fas fa-pencil-alt"></i></button>
		</div>
		<?php else: ?>
		<div class="pn-avatar">
			<img class="heraldry-img" src="<?= $imageUrl ?>" alt="<?= htmlspecialchars($Player['Persona']) ?>" />
		</div>
		<?php endif; ?>
		<div class="pn-hero-info">
			<h1 class="pn-persona">
				<?= htmlspecialchars($Player['Persona']) ?>
				<?php if ($isKnight): ?>
					<img class="pn-belt-icon" src="<?= $beltIconUrl ?>" alt="Knight" title="Belted Knight" />
				<?php endif; ?>
			</h1>
			<?php if (strlen($Player['GivenName']) > 0 || strlen($Player['Surname']) > 0): ?>
				<div class="pn-real-name"><?= htmlspecialchars(trim($Player['GivenName'] . ' ' . $Player['Surname'])) ?></div>
			<?php endif; ?>
			<?php if (!empty($pronounDisplay)): ?>
				<div class="pn-pronouns"><?= htmlspecialchars($pronounDisplay) ?></div>
			<?php endif; ?>
			<div class="pn-breadcrumb">
				<?php if (valid_id($this->__session->kingdom_id)): ?>
					<a href="<?= UIR ?>Kingdom/index/<?= $this->__session->kingdom_id ?>"><?= htmlspecialchars($this->__session->kingdom_name) ?></a>
					<span class="pn-sep"><i class="fas fa-chevron-right" style="font-size:10px"></i></span>
					<a href="<?= UIR ?>Park/index/<?= $this->__session->park_id ?>"><?= htmlspecialchars($this->__session->park_name) ?></a>
				<?php endif; ?>
			</div>
			<div class="pn-badges">
				<?php if ($isActive): ?>
					<span class="pn-badge pn-badge-green"><i class="fas fa-check-circle"></i> Active</span>
				<?php else: ?>
					<span class="pn-badge pn-badge-gray"><i class="fas fa-minus-circle"></i> Inactive</span>
				<?php endif; ?>
				<?php if ($isSuspended): ?>
					<span class="pn-badge pn-badge-red"><i class="fas fa-ban"></i> Suspended</span>
				<?php endif; ?>
				<?php if ($Player['Waivered'] == 1): ?>
					<span class="pn-badge pn-badge-blue"><i class="fas fa-file-signature"></i> Waivered</span>
				<?php endif; ?>
				<?php if ($Player['Restricted'] == 1): ?>
					<span class="pn-badge pn-badge-orange"><i class="fas fa-exclamation-triangle"></i> Restricted</span>
				<?php endif; ?>
				<?php if ($Player['DuesThrough'] != 0): ?>
					<span class="pn-badge pn-badge-green"><i class="fas fa-receipt"></i> Dues Paid</span>
				<?php else: ?>
					<span class="pn-badge pn-badge-gray"><i class="fas fa-receipt"></i> Dues Lapsed</span>
				<?php endif; ?>
				<?php if (!empty($OfficerRoles)): ?>
					<?php foreach ($OfficerRoles as $office): ?>
						<span class="pn-badge pn-badge-gold"><i class="fas fa-crown"></i> <?= htmlspecialchars($office['entity_type']) ?> <?= htmlspecialchars($office['role']) ?></span>
					<?php endforeach; ?>
				<?php endif; ?>
				<?php if ($IsOrkAdmin): ?>
					<span class="pn-badge pn-badge-purple"><i class="fas fa-cog"></i> ORK Administrator</span>
				<?php endif; ?>
			</div>
			<?php if ($isSuspended): ?>
				<div class="pn-suspended-detail">
					<i class="fas fa-info-circle"></i>
					Suspended <?= $Player['SuspendedAt'] ?> &mdash; Until <?= $Player['SuspendedUntil'] ?>
					<?php if (!empty($Player['Suspension'])): ?>
						&mdash; <?= htmlspecialchars($Player['Suspension']) ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<div class="pn-hero-actions">
			<?php if ($LoggedIn): ?>
				<button class="pn-btn pn-btn-white" id="pn-recommend-btn"><i class="fas fa-award"></i> Recommend Award</button>
				<a class="pn-btn pn-btn-outline" href="<?= UIR ?>Admin/player/<?= $Player['MundaneId'] ?>"><i class="fas fa-cog"></i> Admin Panel</a>
				<?php if ($canEditAdmin): ?>
				<button class="pn-btn pn-btn-ghost pn-hero-btn" onclick="pnOpenMovePlayerModal()"><i class="fas fa-arrows-alt"></i> Move</button>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php if (strlen($Error) > 0): ?>
	<div class='error-message' style="margin-bottom: 14px;"><?= $Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0): ?>
	<div class='success-message' style="margin-bottom: 14px;"><?= $Message ?></div>
<?php endif; ?>

<!-- =============================================
     ZONE 2: Dashboard Stats
     ============================================= -->
<div class="pn-stats-row">
	<div class="pn-stat-card pn-stat-card-link" onclick="pnActivateTab('attendance')">
		<div class="pn-stat-icon"><i class="fas fa-calendar-check"></i></div>
		<div class="pn-stat-number"><?= $Stats['TotalAttendance'] ?></div>
		<div class="pn-stat-label">Attendance</div>
	</div>
	<div class="pn-stat-card pn-stat-card-link" onclick="pnActivateTab('awards')">
		<div class="pn-stat-icon"><i class="fas fa-medal"></i></div>
		<div class="pn-stat-number"><?= $Stats['TotalAwards'] ?></div>
		<div class="pn-stat-label">Awards</div>
	</div>
	<div class="pn-stat-card pn-stat-card-link" onclick="pnActivateTab('titles')">
		<div class="pn-stat-icon"><i class="fas fa-crown"></i></div>
		<div class="pn-stat-number"><?= $Stats['TotalTitles'] ?></div>
		<div class="pn-stat-label">Titles</div>
	</div>
	<div class="pn-stat-card pn-stat-card-link" onclick="pnActivateTab('classes')">
		<div class="pn-stat-icon"><i class="fas fa-shield-alt"></i></div>
		<div class="pn-stat-number"><?= $Stats['HighestClassLevel'] ?></div>
		<div class="pn-stat-label">Highest Class</div>
	</div>
</div>

<!-- =============================================
     ZONE 3: Sidebar + Main Content
     ============================================= -->
<div class="pn-layout">

	<!-- ========== SIDEBAR ========== -->
	<div class="pn-sidebar">

		<!-- Player Details -->
		<div class="pn-card">
			<h4><i class="fas fa-user"></i> Player Details<?php if ($canEditAccount): ?><button class="pn-card-edit-btn" onclick="pnOpenAccountModal()" title="Edit account details"><i class="fas fa-pencil-alt"></i></button><?php endif; ?></h4>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Given Name</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['GivenName']) ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Surname</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['Surname']) ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Persona</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['Persona']) ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Username</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['UserName']) ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Password Expires</span>
				<span class="pn-detail-value"><?= $passwordExpiring ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Member Since</span>
				<span class="pn-detail-value"><?= $Player['ParkMemberSince'] ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Last Sign-In</span>
				<span class="pn-detail-value"><?= ($Player['LastSignInDate'] ? $Player['LastSignInDate'] : 'N/A') ?></span>
			</div>
		</div>

		<!-- Heraldry -->
		<div class="pn-card">
			<h4><i class="fas fa-image"></i> Heraldry</h4>
			<div style="text-align: center;">
				<?php if ($canEditImages): ?>
				<div class="pn-editable-img" style="border-radius:4px;max-width:100%;">
					<img class="heraldry-img" src="<?= $heraldryUrl ?>" alt="Heraldry" style="max-width: 100%; max-height: 160px; border-radius: 4px; object-fit: contain; display: block;" />
					<button class="pn-img-edit-btn" onclick="pnOpenImgModal('heraldry')" title="Update heraldry"><i class="fas fa-pencil-alt"></i></button>
				</div>
				<?php else: ?>
				<img class="heraldry-img" src="<?= $heraldryUrl ?>" alt="Heraldry" style="max-width: 100%; max-height: 160px; border-radius: 4px; object-fit: contain;" />
				<?php endif; ?>
			</div>
		</div>

		<!-- Qualifications -->
		<div class="pn-card">
			<h4><i class="fas fa-certificate"></i> Qualifications<?php if ($canEditAdmin): ?><button class="pn-card-edit-btn" onclick="pnOpenQualModal()" title="Edit qualifications"><i class="fas fa-pencil-alt"></i></button><?php endif; ?></h4>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Reeve</span>
				<span class="pn-detail-value">
					<?php if ($Player['ReeveQualified'] != 0): ?>
						<span class="pn-badge pn-badge-green">Until <?= $Player['ReeveQualifiedUntil'] ?></span>
					<?php else: ?>
						<span class="pn-badge pn-badge-gray">No</span>
					<?php endif; ?>
				</span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Corpora</span>
				<span class="pn-detail-value">
					<?php if ($Player['CorporaQualified'] != 0): ?>
						<span class="pn-badge pn-badge-green">Until <?= $Player['CorporaQualifiedUntil'] ?></span>
					<?php else: ?>
						<span class="pn-badge pn-badge-gray">No</span>
					<?php endif; ?>
				</span>
			</div>
		</div>

		<!-- Dues -->
		<div class="pn-card">
			<h4><i class="fas fa-receipt"></i> Dues<?php if ($canEditAdmin): ?><button class="pn-card-edit-btn" onclick="pnOpenDuesModal()" title="Add dues entry"><i class="fas fa-pencil-alt"></i></button><?php endif; ?></h4>
			<?php if (is_array($Dues) && count($Dues) > 0): ?>
				<table class="pn-mini-table">
					<thead>
						<tr>
							<th>Park</th>
							<th>Paid Until</th>
							<th>Lifetime</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($Dues as $d): ?>
							<tr>
								<td><?= $d['ParkName'] ?></td>
								<td>
									<?php if ($d['DuesForLife'] == 1): ?>
										<span class="pn-dues-life">Lifetime</span>
									<?php else: ?>
										<?= $d['DuesUntil'] ?>
									<?php endif; ?>
								</td>
								<td>
									<?php if ($d['DuesForLife'] == 1): ?>
										<span class="pn-dues-life">Yes</span>
									<?php else: ?>
										No
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else: ?>
				<div class="pn-empty">No dues records</div>
			<?php endif; ?>
		</div>

		<!-- Companies & Households -->
		<div class="pn-card">
			<h4><i class="fas fa-users"></i> Companies &amp; Households</h4>
			<?php
				$unitList = (is_array($Units['Units'])) ? $Units['Units'] : array();
			?>
			<?php if (count($unitList) > 0): ?>
				<?php foreach ($unitList as $unit): ?>
					<div class="pn-unit-row">
						<a class="pn-unit-link" href="<?= UIR ?>Unit/index/<?= $unit['UnitId'] ?>"><?= htmlspecialchars($unit['Name']) ?></a>
						<span class="pn-unit-type"><?= ucfirst($unit['Type']) ?></span>
						<?php if ($canEditAdmin || $isOwnProfile): ?>
						<span class="pn-delete-cell pn-unit-quit-cell">
							<a class="pn-delete-link pn-confirm-quit-unit" href="#" title="Leave unit">&times;</a>
							<span class="pn-delete-confirm">
								Leave?&nbsp;
								<button class="pn-delete-yes" data-href="<?= UIR ?>Player/profile/<?= (int)$Player['MundaneId'] ?>/quitunit/<?= $unit['UnitMundaneId'] ?>">Yes</button>
								&nbsp;<button class="pn-delete-no">No</button>
							</span>
						</span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php else: ?>
				<div class="pn-empty">No memberships</div>
			<?php endif; ?>
		</div>

	</div>

	<!-- ========== MAIN CONTENT (Tabbed) ========== -->
	<div class="pn-main">
		<div class="pn-tabs">
			<ul class="pn-tab-nav">
				<li class="pn-tab-active" data-tab="awards">
					<i class="fas fa-medal"></i> Awards <span class="pn-tab-count">(<?= $Stats['TotalAwards'] ?>)</span>
				</li>
				<li data-tab="titles">
					<i class="fas fa-crown"></i> Titles <span class="pn-tab-count">(<?= $Stats['TotalTitles'] ?>)</span>
				</li>
				<li data-tab="attendance">
					<i class="fas fa-calendar-check"></i> Attendance <span class="pn-tab-count">(<?= $Stats['TotalAttendance'] ?>)</span>
				</li>
				<li data-tab="recommendations">
					<i class="fas fa-star"></i> Recommendations <span class="pn-tab-count">(<?= is_array($AwardRecommendations) ? count($AwardRecommendations) : 0 ?>)</span>
				</li>
				<li data-tab="history">
					<i class="fas fa-history"></i> Historical <span class="pn-tab-count">(<?= is_array($Notes) ? count($Notes) : 0 ?>)</span>
				</li>
				<li data-tab="classes">
					<i class="fas fa-shield-alt"></i> Class Levels <span class="pn-tab-count">(<?= is_array($Details['Classes']) ? count($Details['Classes']) : 0 ?>)</span>
				</li>
			</ul>

			<!-- Awards Tab -->
			<div class="pn-tab-panel" id="pn-tab-awards">
				<?php if ($canEditAdmin): ?>
				<div class="pn-tab-toolbar">
					<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="pnOpenAwardModal('awards')"><i class="fas fa-plus"></i> Add Award</button>
				</div>
				<?php endif; ?>
				<?php
					$awardsList = is_array($Details['Awards']) ? $Details['Awards'] : array();
					$filteredAwards = array();
					foreach ($awardsList as $a) {
						if (in_array($a['OfficerRole'], ['none', null]) && $a['IsTitle'] != 1) {
							$filteredAwards[] = $a;
						}
					}

					// Build ladder progress: AwardId -> {Name, Short, MaxRank, HasMaster}
					// Static map: Order award_id => Master award_id(s)
					$pnOrderToMaster = [
						21  => [1],       // Order of the Rose      → Master Rose
						22  => [2],       // Order of the Smith      → Master Smith
						23  => [3],       // Order of the Lion       → Master Lion
						24  => [4],       // Order of the Owl        → Master Owl
						25  => [5],       // Order of the Dragon     → Master Dragon
						26  => [6],       // Order of the Garber     → Master Garber
						27  => [36, 12],  // Order of the Warrior    → Weaponmaster / Warlord
						28  => [7],       // Order of the Jovius     → Master Jovius
						29  => [9],       // Order of the Mask       → Master Mask
						30  => [8],       // Order of the Zodiac     → Master Zodiac
						32  => [10],      // Order of the Hydra      → Master Hydra
						33  => [11],      // Order of the Griffin    → Master Griffin
						239 => [240],     // Order of the Crown      → Master Crown
						243 => [244],     // Order of Battle         → Battlemaster
					];
					// Index all award_ids the player holds (including titles)
					$pnHeldAwardIds = [];
					foreach ($awardsList as $a) {
						$aid = (int)$a['AwardId'];
						if ($aid > 0) $pnHeldAwardIds[$aid] = true;
					}
					$pnLadderProgress = [];
					foreach ($awardsList as $a) {
						if ((int)$a['IsLadder'] !== 1) continue;
						$aid  = (int)$a['AwardId'];
						$rank = (int)$a['Rank'];
						if ($aid <= 0 || $rank <= 0) continue;
						$displayName = trimlen($a['CustomAwardName']) > 0 ? $a['CustomAwardName']
							: (trimlen($a['KingdomAwardName']) > 0 ? $a['KingdomAwardName'] : $a['Name']);
						// Strip "Order of the " / "Order of " prefix to save space
						$shortName = preg_replace('/^Order of (the )?/i', '', $displayName);
						// Check if player holds the corresponding Master title
						$hasMaster = false;
						if (isset($pnOrderToMaster[$aid])) {
							foreach ($pnOrderToMaster[$aid] as $masterId) {
								if (isset($pnHeldAwardIds[$masterId])) { $hasMaster = true; break; }
							}
						}
						if (!isset($pnLadderProgress[$aid]) || $rank > $pnLadderProgress[$aid]['Rank']) {
							$pnLadderProgress[$aid] = ['Name' => $displayName, 'Short' => $shortName, 'Rank' => $rank, 'HasMaster' => $hasMaster];
						}
					}
					uasort($pnLadderProgress, function($a, $b) { return strcmp($a['Name'], $b['Name']); });
				?>
				<?php if (!empty($pnLadderProgress)): ?>
					<div class="pn-ladder-grid">
						<?php foreach ($pnLadderProgress as $lp): ?>
							<?php $pct = min(100, round($lp['Rank'] / 10 * 100)); ?>
							<div class="pn-ladder-item" title="<?= htmlspecialchars($lp['Name']) ?>">
								<div class="pn-ladder-header">
									<span class="pn-ladder-name"><?= htmlspecialchars($lp['Short']) ?></span>
									<span style="display:flex;align-items:center;gap:4px;flex-shrink:0">
										<?php if ($lp['HasMaster']): ?>
											<span class="pn-ladder-master" title="Master title earned"><i class="fas fa-star"></i> M</span>
										<?php endif; ?>
										<span class="pn-ladder-rank"><strong><?= $lp['Rank'] ?></strong> / 10</span>
									</span>
								</div>
								<div class="pn-ladder-bar-track">
									<div class="pn-ladder-bar-fill<?= $lp['Rank'] >= 10 ? ' pn-ladder-max' : '' ?>"
									     style="width:<?= $pct ?>%"></div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<?php if (count($filteredAwards) === 0): ?>
					<div class="pn-empty">No awards recorded</div>
				<?php else: ?>
				<table class="pn-table pn-sortable" id="pn-awards-table">
					<thead>
						<tr>
							<th data-sorttype="text">Award</th>
							<th data-sorttype="numeric">Rank</th>
							<th data-sorttype="date">Date</th>
							<th data-sorttype="text">Given By</th>
							<th data-sorttype="text">Given At</th>
							<th data-sorttype="text">Note</th>
							<th data-sorttype="text">Entered By</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($filteredAwards as $detail): ?>
							<tr>
								<td class="pn-col-nowrap">
									<?php $displayName = trimlen($detail['CustomAwardName']) > 0 ? $detail['CustomAwardName'] : $detail['KingdomAwardName']; ?>
									<?= $displayName ?>
									<?php if ($displayName != $detail['Name']): ?><span class="pn-award-base">[<?= $detail['Name'] ?>]</span><?php endif; ?>
								</td>
								<td class="pn-col-numeric"><?= valid_id($detail['Rank']) ? $detail['Rank'] : '' ?></td>
								<td class="pn-col-nowrap"><?= strtotime($detail['Date']) > 0 ? $detail['Date'] : '' ?></td>
								<td class="pn-col-nowrap"><a href="<?= UIR ?>Player/index/<?= $detail['GivenById'] ?>"><?= substr($detail['GivenBy'], 0, 30) ?></a></td>
								<td><?php if (valid_id($detail['EventId'])) echo $detail['EventName']; else echo (trimlen($detail['ParkName']) > 0) ? $detail['ParkName'] . ', ' . $detail['KingdomName'] : $detail['KingdomName']; ?></td>
								<td><?= $detail['Note'] ?></td>
								<td><a href="<?= UIR ?>Player/index/<?= $detail['EnteredById'] ?>"><?= $detail['EnteredBy'] ?></a></td>
								<?php if ($canEditAdmin): ?>
								<td class="pn-award-actions-cell">
									<?php $awardData = json_encode([
										'AwardsId'   => (int)$detail['AwardsId'],
										'displayName'=> ($detail['CustomAwardName'] !== '' ? $detail['CustomAwardName'] : $detail['KingdomAwardName']),
										'Name'       => $detail['Name'],
										'IsLadder'   => (int)$detail['IsLadder'],
										'Rank'       => (int)$detail['Rank'],
										'Date'       => $detail['Date'],
										'GivenBy'    => $detail['GivenBy'],
										'GivenById'  => (int)$detail['GivenById'],
										'Note'       => $detail['Note'],
										'ParkId'     => (int)$detail['ParkId'],
										'ParkName'   => $detail['ParkName'],
										'KingdomId'  => (int)$detail['KingdomId'],
										'KingdomName'=> $detail['KingdomName'],
										'EventId'    => (int)$detail['EventId'],
										'EventName'  => $detail['EventName'],
									], JSON_HEX_QUOT | JSON_HEX_APOS); ?>
									<button class="pn-award-action-btn pn-award-edit-btn"
									        data-awards-id="<?= (int)$detail['AwardsId'] ?>"
									        data-award="<?= htmlspecialchars($awardData, ENT_QUOTES) ?>"
									        title="Edit award"><i class="fas fa-pencil-alt"></i></button>
									<button class="pn-award-action-btn pn-award-del-btn"
									        data-awards-id="<?= (int)$detail['AwardsId'] ?>"
									        title="Delete award"><i class="fas fa-trash"></i></button>
									<button class="pn-award-action-btn pn-award-revoke-btn"
									        data-awards-id="<?= (int)$detail['AwardsId'] ?>"
									        data-award="<?= htmlspecialchars($awardData, ENT_QUOTES) ?>"
									        title="Revoke award"><i class="fas fa-ban"></i></button>
								</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>

			<!-- Titles Tab -->
			<div class="pn-tab-panel" id="pn-tab-titles" style="display:none">
				<?php if ($canEditAdmin): ?>
				<div class="pn-tab-toolbar">
					<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="pnOpenAwardModal('officers')"><i class="fas fa-plus"></i> Add Title</button>
				</div>
				<?php endif; ?>
				<?php
					$filteredTitles = array();
					foreach ($awardsList as $a) {
						if (!in_array($a['OfficerRole'], ['none', null]) || $a['IsTitle'] == 1) {
							$filteredTitles[] = $a;
						}
					}
				?>
				<?php if (count($filteredTitles) > 0): ?>
					<table class="pn-table pn-sortable" id="pn-titles-table">
						<thead>
							<tr>
								<th data-sorttype="text">Title</th>
								<th data-sorttype="numeric">Rank</th>
								<th data-sorttype="date">Date</th>
								<th data-sorttype="text">Given By</th>
								<th data-sorttype="text">Given At</th>
								<th data-sorttype="text">Note</th>
								<th data-sorttype="text">Entered By</th>
								<?php if ($canEditAdmin): ?><th style="width:52px;min-width:52px"></th><?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($filteredTitles as $detail): ?>
								<tr>
									<td class="pn-col-nowrap">
										<?= trimlen($detail['CustomAwardName']) > 0 ? $detail['CustomAwardName'] : $detail['KingdomAwardName'] ?>
										<?php
											$displayName = trimlen($detail['CustomAwardName']) > 0 ? $detail['CustomAwardName'] : $detail['KingdomAwardName'];
											if ($displayName != $detail['Name']): ?>
												<span class="pn-award-base">[<?= $detail['Name'] ?>]</span>
										<?php endif; ?>
									</td>
									<td class="pn-col-numeric"><?= valid_id($detail['Rank']) ? $detail['Rank'] : '' ?></td>
									<td class="pn-col-nowrap"><?= strtotime($detail['Date']) > 0 ? $detail['Date'] : '' ?></td>
									<td class="pn-col-nowrap"><a href="<?= UIR ?>Player/index/<?= $detail['GivenById'] ?>"><?= substr($detail['GivenBy'], 0, 30) ?></a></td>
									<td>
										<?php
											if (valid_id($detail['EventId'])) {
												echo $detail['EventName'];
											} else {
												echo (trimlen($detail['ParkName']) > 0) ? $detail['ParkName'] . ', ' . $detail['KingdomName'] : $detail['KingdomName'];
											}
										?>
									</td>
									<td><?= $detail['Note'] ?></td>
									<td><a href="<?= UIR ?>Player/index/<?= $detail['EnteredById'] ?>"><?= $detail['EnteredBy'] ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty">No titles recorded</div>
				<?php endif; ?>
			</div>

			<!-- Attendance Tab -->
			<div class="pn-tab-panel" id="pn-tab-attendance" style="display:none">
				<?php $attendanceList = is_array($Details['Attendance']) ? $Details['Attendance'] : array(); ?>
				<?php if (count($attendanceList) > 0): ?>
					<table class="pn-table pn-sortable" id="pn-attendance-table">
						<thead>
							<tr>
								<th data-sorttype="date">Date</th>
								<th data-sorttype="text">Kingdom</th>
								<th data-sorttype="text">Park</th>
								<th data-sorttype="text">Event</th>
								<th data-sorttype="text">Class</th>
								<th data-sorttype="numeric">Credits</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($attendanceList as $detail): ?>
								<tr>
									<td class="pn-col-nowrap">
										<a href="<?= UIR ?>Attendance/<?= $detail['ParkId'] > 0 ? 'park' : 'event' ?>/<?= (($detail['ParkId'] > 0) ? ($detail['ParkId'] . '&AttendanceDate=' . $detail['Date']) : ($detail['EventId'] . '/' . $detail['EventCalendarDetailId'])) ?>"><?= $detail['Date'] ?></a>
									</td>
									<td><a href="<?= UIR ?>Kingdom/index/<?= $detail['KingdomId'] ?>"><?= $detail['KingdomName'] ?></a></td>
									<td><a href="<?= UIR ?>Park/index/<?= $detail['ParkId'] ?>"><?= $detail['ParkName'] ?></a></td>
									<td><a href="<?= UIR ?>Attendance/event/<?= $detail['EventId'] ?>/<?= $detail['EventCalendarDetailId'] ?>"><?= $detail['EventName'] ?></a></td>
									<td><?= trimlen($detail['Flavor']) > 0 ? $detail['Flavor'] : $detail['ClassName'] ?></td>
									<td class="pn-col-numeric"><?= $detail['Credits'] ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty">No attendance records</div>
				<?php endif; ?>
			</div>

			<!-- Recommendations Tab -->
			<div class="pn-tab-panel" id="pn-tab-recommendations" style="display:none">
				<?php $recList = is_array($AwardRecommendations) ? $AwardRecommendations : array(); ?>
				<?php if (count($recList) > 0): ?>
					<table class="pn-table" id="pn-rec-table">
						<thead>
							<tr>
								<th>Award</th>
								<th>Rank</th>
								<th>Date</th>
								<th>Sent By</th>
								<th>Reason</th>
								<?php if ($this->__session->user_id): ?>
									<th>Actions</th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($recList as $rec): ?>
								<tr>
									<td><?= $rec['AwardName'] ?></td>
									<td class="pn-col-numeric"><?= valid_id($rec['Rank']) ? $rec['Rank'] : '' ?></td>
									<td class="pn-col-nowrap"><?= $rec['DateRecommended'] ?></td>
									<td><a href="<?= UIR ?>Player/index/<?= $rec['RecommendedById'] ?>"><?= $rec['RecommendedByName'] ?></a></td>
									<td><?= htmlspecialchars($rec['Reason']) ?></td>
									<?php if ($this->__session->user_id): ?>
										<td>
											<?php if ($can_delete_recommendation || $this->__session->user_id == $rec['RecommendedById'] || $this->__session->user_id == $rec['MundaneId']): ?>
												<span class="pn-delete-cell">
												<a class="pn-delete-link pn-confirm-delete-rec" href="#"><i class="fas fa-trash-alt"></i> Delete</a>
												<span class="pn-delete-confirm">
													Delete?&nbsp;
													<button class="pn-delete-yes" data-href="<?= UIR ?>Player/profile/<?= $rec['MundaneId'] ?>/deleterecommendation/<?= $rec['RecommendationsId'] ?>">Yes</button>
													&nbsp;<button class="pn-delete-no">No</button>
												</span>
											</span>
											<?php endif; ?>
										</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty">No recommendations</div>
				<?php endif; ?>
			</div>

			<!-- Historical Imports Tab -->
			<div class="pn-tab-panel" id="pn-tab-history" style="display:none">
				<?php $notesList = is_array($Notes) ? $Notes : array(); ?>
				<?php if ($canEditAdmin): ?>
				<div class="pn-notes-toolbar">
					<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="pnOpenAddNoteModal()"><i class="fas fa-plus"></i> Add Note</button>
				</div>
				<?php endif; ?>
				<?php if (count($notesList) > 0): ?>
					<table class="pn-table" id="pn-history-table">
						<thead>
							<tr>
								<th>Note</th>
								<th>Description</th>
								<th>Date</th>
								<?php if ($canEditAdmin): ?><th style="width:30px"></th><?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($notesList as $note): ?>
								<tr data-notes-id="<?= (int)($note['NotesId'] ?? 0) ?>">
									<td><?= $note['Note'] ?></td>
									<td><?= $note['Description'] ?></td>
									<td class="pn-col-nowrap"><?= $note['Date'] . (strtotime($note['DateComplete']) > 0 ? (' - ' . $note['DateComplete']) : '') ?></td>
									<?php if ($canEditAdmin): ?>
									<td><button class="pn-note-del-btn" data-notes-id="<?= (int)($note['NotesId'] ?? 0) ?>" title="Delete note"><i class="fas fa-times"></i></button></td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty" id="pn-history-empty">No historical imports</div>
				<?php endif; ?>
			</div>

			<!-- Class Levels Tab -->
			<div class="pn-tab-panel" id="pn-tab-classes" style="display:none">
				<?php
					$classList = is_array($Details['Classes']) ? $Details['Classes'] : array();
					// class_id → Paragon award_id
					$pnClassToParagon = [
						1  => 37,  // Anti-Paladin → Paragon Anti-Paladin
						2  => 38,  // Archer       → Paragon Archer
						3  => 39,  // Assassin     → Paragon Assassin
						4  => 40,  // Barbarian    → Paragon Barbarian
						5  => 41,  // Bard         → Paragon Bard
						6  => 241, // Color        → Paragon Color
						7  => 42,  // Druid        → Paragon Druid
						8  => 43,  // Healer       → Paragon Healer
						9  => 44,  // Monk         → Paragon Monk
						10 => 45,  // Monster      → Paragon Monster
						11 => 46,  // Paladin      → Paragon Paladin
						12 => 47,  // Peasant      → Paragon Peasant
						14 => 242, // Reeve        → Paragon Reeve
						15 => 49,  // Scout        → Paragon Scout
						16 => 50,  // Warrior      → Paragon Warrior
						17 => 51,  // Wizard       → Paragon Wizard
					];
					// $pnHeldAwardIds is built in the Awards tab block above
					$pnHeldAwardIds = isset($pnHeldAwardIds) ? $pnHeldAwardIds : [];
				?>
				<?php if (count($classList) > 0): ?>
					<table class="pn-table" id="pn-classes-table">
						<thead>
							<tr>
								<th data-sorttype="text">Class</th>
								<th data-sorttype="numeric" class="pn-col-numeric">Credits</th>
								<th data-sorttype="numeric" class="pn-col-numeric">Level</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($classList as $detail): ?>
								<?php
									$totalCredits = $detail['Credits'] + (isset($Player_index) ? $Player_index['Class_' . $detail['ClassId']] : $detail['Reconciled']);
									$paragonAwardId = $pnClassToParagon[$detail['ClassId']] ?? null;
									$hasParagon = $paragonAwardId && isset($pnHeldAwardIds[$paragonAwardId]);
								?>
								<tr>
									<td>
										<?= htmlspecialchars($detail['ClassName']) ?>
										<?php if ($hasParagon): ?>
											<span class="pn-paragon-badge" title="Paragon title earned"><i class="fas fa-crown"></i> Paragon</span>
										<?php endif; ?>
									</td>
									<td class="pn-col-numeric pn-credits"><?= $totalCredits ?></td>
									<td class="pn-col-numeric pn-level">-</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty">No class records</div>
				<?php endif; ?>
			</div>

		</div>
	</div>

</div>

<!-- =============================================
     Image Upload Modal
     ============================================= -->
<?php if ($canEditImages): ?>
<div class="pn-overlay" id="pn-img-overlay">
	<div class="pn-modal-box pn-img-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title" id="pn-img-modal-title"><i class="fas fa-image" style="margin-right:8px;color:#2c5282"></i>Update Image</h3>
			<button class="pn-modal-close-btn" id="pn-img-close-btn" aria-label="Close">&times;</button>
		</div>

		<!-- Step: file select -->
		<div class="pn-modal-body" id="pn-img-step-select">
			<label class="pn-upload-area" for="pn-img-file-input">
				<i class="fas fa-cloud-upload-alt pn-upload-icon"></i>
				Click to choose an image
				<small>JPG, GIF, PNG &middot; Max 340&nbsp;KB (larger images auto-resized)</small>
			</label>
			<input type="file" id="pn-img-file-input" accept=".jpg,.jpeg,.gif,.png,image/jpeg,image/gif,image/png" style="display:none;" />
			<div id="pn-img-resize-notice" style="font-size:12px;color:#888;min-height:16px;"></div>
			<div class="pn-form-error" id="pn-img-error"></div>
		</div>

		<!-- Step: crop -->
		<div class="pn-modal-body" id="pn-img-step-crop" style="display:none;">
			<p style="margin:0 0 10px;font-size:13px;color:#718096;">Drag inside the crop box to reposition it, or drag the corner handles to resize.</p>
			<div class="pn-crop-wrap">
				<canvas id="pn-crop-canvas"></canvas>
			</div>
			<div class="pn-img-step-actions">
				<button class="pn-btn pn-btn-secondary" id="pn-img-back-btn"><i class="fas fa-arrow-left"></i> Choose Different</button>
				<button class="pn-btn pn-btn-primary" id="pn-img-upload-btn"><i class="fas fa-upload"></i> Upload</button>
			</div>
		</div>

		<!-- Step: uploading -->
		<div class="pn-modal-body" id="pn-img-step-uploading" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-spinner fa-spin" style="font-size:32px;color:#4299e1;"></i>
			<p style="margin-top:12px;color:#718096;">Uploading&hellip;</p>
		</div>

		<!-- Step: success -->
		<div class="pn-modal-body" id="pn-img-step-success" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-check-circle" style="font-size:32px;color:#48bb78;"></i>
			<p style="margin-top:12px;color:#48bb78;font-weight:600;">Image updated! Refreshing&hellip;</p>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Update Account Modal
     ============================================= -->
<?php if ($canEditAccount): ?>
<div class="pn-overlay" id="pn-acct-overlay">
	<div class="pn-modal-box" style="width:560px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-user-edit" style="margin-right:8px;color:#2c5282"></i>Update Account</h3>
			<button class="pn-modal-close-btn" id="pn-acct-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="pn-acct-modal-body">
			<div class="pn-form-error" id="pn-acct-error"></div>

			<!-- Basic profile (own + admin) -->
			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label for="pn-acct-givenname">Given Name</label>
					<input type="text" id="pn-acct-givenname" name="GivenName" value="<?= htmlspecialchars($Player['GivenName']) ?>" />
				</div>
				<div class="pn-acct-field">
					<label for="pn-acct-surname">Surname</label>
					<input type="text" id="pn-acct-surname" name="Surname" value="<?= htmlspecialchars($Player['Surname']) ?>" />
				</div>
			</div>
			<div class="pn-acct-field">
				<label for="pn-acct-persona">Persona <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pn-acct-persona" name="Persona" value="<?= htmlspecialchars($Player['Persona']) ?>" />
			</div>
			<div class="pn-acct-field">
				<label for="pn-acct-email">Email</label>
				<input type="email" id="pn-acct-email" name="Email" value="<?= htmlspecialchars($Player['Email'] ?? '') ?>" />
			</div>
			<div class="pn-acct-field">
				<label for="pn-acct-username">Username <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pn-acct-username" name="UserName" value="<?= htmlspecialchars($Player['UserName']) ?>" />
			</div>
			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label for="pn-acct-password">New Password</label>
					<input type="password" id="pn-acct-password" name="Password" autocomplete="new-password" />
					<div class="pn-acct-hint">Leave blank to keep current</div>
				</div>
				<div class="pn-acct-field">
					<label for="pn-acct-password2">Confirm Password</label>
					<input type="password" id="pn-acct-password2" name="PasswordAgain" autocomplete="new-password" />
				</div>
			</div>
			<div class="pn-acct-field">
				<label for="pn-acct-pronouns">Pronouns</label>
				<select id="pn-acct-pronouns" name="PronounId">
					<option value="">Choose&hellip;</option>
					<?= $PronounOptions ?>
				</select>
				<input type="hidden" name="PronounCustom" value="<?= htmlspecialchars($Player['PronounCustom'] ?? '') ?>" />
				<div class="pn-acct-hint">For custom pronouns, use the <a href="<?= UIR ?>Admin/player/<?= (int)$Player['MundaneId'] ?>" style="color:#3182ce">Admin Panel</a></div>
			</div>

			<?php if ($canEditAdmin): ?>
			<!-- Admin-only fields -->
			<div class="pn-acct-section-title"><i class="fas fa-shield-alt" style="margin-right:5px"></i>Administrative</div>

			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label>Status</label>
					<div class="pn-acct-radio-group">
						<label><input type="radio" name="Active" value="Active" <?= $Player['Active'] == 1 ? 'checked' : '' ?> /> Visible</label>
						<label><input type="radio" name="Active" value="Inactive" <?= $Player['Active'] != 1 ? 'checked' : '' ?> /> Retired</label>
					</div>
				</div>
				<div class="pn-acct-field">
					<label>Waiver</label>
					<div class="pn-acct-radio-group">
						<label><input type="radio" name="Waivered" value="Waivered" <?= $Player['Waivered'] == 1 ? 'checked' : '' ?> /> Waivered</label>
						<label><input type="radio" name="Waivered" value="Lawsuit Bait" <?= $Player['Waivered'] != 1 ? 'checked' : '' ?> /> No Waiver</label>
					</div>
				</div>
			</div>

			<div class="pn-acct-field">
				<label>
					<input type="checkbox" name="Restricted" value="Restricted" <?= $Player['Restricted'] == 1 ? 'checked' : '' ?> style="margin-right:6px" />
					Restricted Account
				</label>
			</div>

			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label>Reeve Qualified</label>
					<div class="pn-acct-radio-group">
						<label><input type="radio" name="ReeveQualified" value="1" <?= $Player['ReeveQualified'] == 1 ? 'checked' : '' ?> /> Yes</label>
						<label><input type="radio" name="ReeveQualified" value="0" <?= $Player['ReeveQualified'] != 1 ? 'checked' : '' ?> /> No</label>
					</div>
				</div>
				<div class="pn-acct-field">
					<label for="pn-acct-reeve-until">Reeve Until</label>
					<input type="text" id="pn-acct-reeve-until" name="ReeveQualifiedUntil" value="<?= htmlspecialchars($Player['ReeveQualifiedUntil'] ?? '') ?>" placeholder="YYYY-MM-DD" />
				</div>
			</div>

			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label>Corpora Qualified</label>
					<div class="pn-acct-radio-group">
						<label><input type="radio" name="CorporaQualified" value="1" <?= $Player['CorporaQualified'] == 1 ? 'checked' : '' ?> /> Yes</label>
						<label><input type="radio" name="CorporaQualified" value="0" <?= $Player['CorporaQualified'] != 1 ? 'checked' : '' ?> /> No</label>
					</div>
				</div>
				<div class="pn-acct-field">
					<label for="pn-acct-corpora-until">Corpora Until</label>
					<input type="text" id="pn-acct-corpora-until" name="CorporaQualifiedUntil" value="<?= htmlspecialchars($Player['CorporaQualifiedUntil'] ?? '') ?>" placeholder="YYYY-MM-DD" />
				</div>
			</div>

			<div class="pn-acct-field">
				<label for="pn-acct-member-since">Park Member Since</label>
				<input type="text" id="pn-acct-member-since" name="ParkMemberSince" value="<?= htmlspecialchars($Player['ParkMemberSince'] ?? '') ?>" placeholder="YYYY-MM-DD" />
			</div>
			<?php endif; ?>
		</div>

		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-acct-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-acct-save"><i class="fas fa-save"></i> Save Changes</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Add Dues Modal
     ============================================= -->
<?php if ($canEditAdmin): ?>
<div class="pn-overlay" id="pn-dues-overlay">
	<div class="pn-modal-box" style="width:460px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-receipt" style="margin-right:8px;color:#2c5282"></i>Add Dues Entry</h3>
			<button class="pn-modal-close-btn" id="pn-dues-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="pn-acct-modal-body">
			<div class="pn-form-error" id="pn-dues-error"></div>

			<!-- Current dues -->
			<div class="pn-dues-modal-current">
				<div class="pn-dues-modal-current-title"><i class="fas fa-history" style="margin-right:5px"></i>Current Active Dues</div>
				<?php if (is_array($Dues) && count($Dues) > 0): ?>
				<table class="pn-dues-modal-table">
					<thead><tr><th>Park</th><th>Paid Through</th><th>Lifetime</th><?php if ($canEditAdmin): ?><th></th><?php endif; ?></tr></thead>
					<tbody>
					<?php foreach ($Dues as $d): ?>
						<tr>
							<td><?= htmlspecialchars($d['ParkName']) ?></td>
							<td><?= $d['DuesForLife'] == 1 ? '<span class="pn-dues-life">Lifetime</span>' : htmlspecialchars($d['DuesUntil']) ?></td>
							<td><?= $d['DuesForLife'] == 1 ? 'Yes' : 'No' ?></td>
							<?php if ($canEditAdmin): ?><td><button class="pn-dues-revoke-btn" data-dues-id="<?= (int)$d['DuesId'] ?>">Revoke</button></td><?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php else: ?>
				<div class="pn-dues-modal-empty">No active dues on record</div>
				<?php endif; ?>
			</div>

			<div class="pn-acct-field">
				<label for="pn-dues-from">Date Paid <span style="color:#e53e3e">*</span></label>
				<input type="date" id="pn-dues-from" name="DuesFrom" value="<?= date('Y-m-d') ?>" />
			</div>

			<div class="pn-acct-field" id="pn-dues-months-row">
				<label for="pn-dues-months">Months</label>
				<input type="number" id="pn-dues-months" name="Months" value="6" min="1" max="120" style="width:100px" />
				<div class="pn-dues-until-preview" id="pn-dues-until-preview"></div>
			</div>

			<div class="pn-acct-field">
				<label>Dues For Life</label>
				<div class="pn-acct-radio-group">
					<label><input type="radio" name="DuesForLife" value="1" /> Yes</label>
					<label><input type="radio" name="DuesForLife" value="0" checked /> No</label>
				</div>
			</div>

			<input type="hidden" name="MundaneId" value="<?= (int)$Player['MundaneId'] ?>" />
			<input type="hidden" name="ParkId"    value="<?= (int)$Player['ParkId'] ?>" />
			<input type="hidden" name="KingdomId" value="<?= (int)$KingdomId ?>" />
		</div>

		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-dues-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-dues-save"><i class="fas fa-save"></i> Add Dues</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Qualifications Modal
     ============================================= -->
<?php if ($canEditAdmin): ?>
<div class="pn-overlay" id="pn-qual-overlay">
	<div class="pn-modal-box" style="width:480px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-certificate" style="margin-right:8px;color:#2c5282"></i>Edit Qualifications</h3>
			<button class="pn-modal-close-btn" id="pn-qual-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="pn-acct-modal-body">
			<div class="pn-form-error" id="pn-qual-error"></div>

			<div class="pn-acct-section-title"><i class="fas fa-gavel" style="margin-right:5px"></i>Reeve Certification</div>
			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label>Reeve Qualified</label>
					<div class="pn-acct-radio-group">
						<label><input type="radio" name="ReeveQualified" value="1" <?= $Player['ReeveQualified'] == 1 ? 'checked' : '' ?> /> Yes</label>
						<label><input type="radio" name="ReeveQualified" value="0" <?= $Player['ReeveQualified'] != 1 ? 'checked' : '' ?> /> No</label>
					</div>
				</div>
				<div class="pn-acct-field pn-qual-until-row" id="pn-qual-reeve-until-row">
					<label for="pn-qual-reeve-until">Qualified Until</label>
					<input type="date" id="pn-qual-reeve-until" name="ReeveQualifiedUntil" value="<?= htmlspecialchars($Player['ReeveQualifiedUntil'] ?? '') ?>" />
				</div>
			</div>

			<div class="pn-acct-section-title" style="margin-top:14px"><i class="fas fa-book" style="margin-right:5px"></i>Corpora Certification</div>
			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label>Corpora Qualified</label>
					<div class="pn-acct-radio-group">
						<label><input type="radio" name="CorporaQualified" value="1" <?= $Player['CorporaQualified'] == 1 ? 'checked' : '' ?> /> Yes</label>
						<label><input type="radio" name="CorporaQualified" value="0" <?= $Player['CorporaQualified'] != 1 ? 'checked' : '' ?> /> No</label>
					</div>
				</div>
				<div class="pn-acct-field pn-qual-until-row" id="pn-qual-corpora-until-row">
					<label for="pn-qual-corpora-until">Qualified Until</label>
					<input type="date" id="pn-qual-corpora-until" name="CorporaQualifiedUntil" value="<?= htmlspecialchars($Player['CorporaQualifiedUntil'] ?? '') ?>" />
				</div>
			</div>

			<!-- Passthrough: preserve all non-qual player fields so Update Details doesn't overwrite them -->
			<input type="hidden" name="Update" value="Update Details" />
			<input type="hidden" name="GivenName"      value="<?= htmlspecialchars($Player['GivenName'] ?? '') ?>" />
			<input type="hidden" name="Surname"        value="<?= htmlspecialchars($Player['Surname'] ?? '') ?>" />
			<input type="hidden" name="Persona"        value="<?= htmlspecialchars($Player['Persona'] ?? '') ?>" />
			<input type="hidden" name="PronounId"      value="<?= (int)($Player['PronounId'] ?? 0) ?>" />
			<input type="hidden" name="PronounCustom"  value="<?= htmlspecialchars($Player['PronounCustom'] ?? '') ?>" />
			<input type="hidden" name="UserName"       value="<?= htmlspecialchars($Player['UserName'] ?? '') ?>" />
			<input type="hidden" name="Email"          value="<?= htmlspecialchars($Player['Email'] ?? '') ?>" />
			<input type="hidden" name="Password"       value="" />
			<input type="hidden" name="PasswordAgain"  value="" />
			<input type="hidden" name="Active"         value="<?= $Player['Active'] == 1 ? 'Active' : 'Inactive' ?>" />
			<input type="hidden" name="Restricted"     value="<?= $Player['Restricted'] == 1 ? 'Restricted' : '' ?>" />
			<input type="hidden" name="ParkMemberSince" value="<?= htmlspecialchars($Player['ParkMemberSince'] ?? '') ?>" />
			<input type="hidden" name="Waivered"       value="<?= $Player['Waivered'] == 1 ? 'Waivered' : 'Lawsuit Bait' ?>" />
		</div>

		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-qual-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-qual-save"><i class="fas fa-save"></i> Save Changes</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Add Award / Add Title Modal
     ============================================= -->
<?php if ($canEditAdmin): ?>
<div class="pn-overlay" id="pn-award-overlay">
	<div class="pn-modal-box" style="width:540px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title" id="pn-award-modal-title"><i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award</h3>
			<button class="pn-modal-close-btn" id="pn-award-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-acct-modal-body">
			<div class="pn-award-success" id="pn-award-success" style="display:none">
				<i class="fas fa-check-circle"></i> <span id="pn-award-success-msg">Award saved!</span>
			</div>
			<div class="pn-form-error" id="pn-award-error"></div>

			<!-- Award Type Toggle -->
			<div class="pn-award-type-row">
				<button type="button" class="pn-award-type-btn pn-active" id="pn-award-type-awards">
					<i class="fas fa-medal" style="margin-right:5px"></i>Awards
				</button>
				<button type="button" class="pn-award-type-btn" id="pn-award-type-officers">
					<i class="fas fa-crown" style="margin-right:5px"></i>Officer Titles
				</button>
			</div>

			<!-- Award Select -->
			<div class="pn-acct-field">
				<label for="pn-award-select">Award <span style="color:#e53e3e">*</span></label>
				<select id="pn-award-select" name="KingdomAwardId">
					<option value="">Select award…</option>
					<?= $AwardOptions ?>
				</select>
				<div class="pn-award-info-line" id="pn-award-info-line"></div>
			</div>

			<!-- Custom Award Name (only for "Custom Award") -->
			<div class="pn-acct-field" id="pn-award-custom-row" style="display:none">
				<label for="pn-award-custom-name">Custom Award Name</label>
				<input type="text" name="AwardName" id="pn-award-custom-name" maxlength="64" placeholder="Enter custom award name…" />
			</div>

			<!-- Rank Picker (only for ladder awards) -->
			<div class="pn-acct-field" id="pn-award-rank-row" style="display:none">
				<label>Rank <span style="color:#a0aec0;font-weight:400;font-size:11px">— click to select; blue = already held, green border = suggested next</span></label>
				<div class="pn-rank-pills-wrap" id="pn-rank-pills"></div>
				<input type="hidden" name="Rank" id="pn-award-rank-val" value="" />
			</div>

			<!-- Date -->
			<div class="pn-acct-field">
				<label for="pn-award-date">Date <span style="color:#e53e3e">*</span></label>
				<input type="date" name="Date" id="pn-award-date" />
			</div>

			<!-- Given By -->
			<div class="pn-acct-field">
				<label>Given By <span style="color:#e53e3e">*</span></label>
				<?php if (!empty($PreloadOfficers)): ?>
				<div class="pn-officer-chips" id="pn-award-officer-chips">
					<?php foreach ($PreloadOfficers as $officer): ?>
					<button type="button" class="pn-officer-chip"
					        data-id="<?= (int)$officer['MundaneId'] ?>"
					        data-name="<?= htmlspecialchars($officer['Persona']) ?>">
						<?= htmlspecialchars($officer['Persona']) ?> <span>(<?= htmlspecialchars($officer['Role']) ?>)</span>
					</button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<input type="text" id="pn-award-givenby-text" placeholder="Or search by persona…" autocomplete="off" />
				<input type="hidden" name="GivenById" id="pn-award-givenby-id" value="" />
				<div class="pn-ac-results" id="pn-award-givenby-results"></div>
			</div>

			<!-- Given At -->
			<div class="pn-acct-field">
				<label for="pn-award-givenat-text">Given At <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<input type="text" id="pn-award-givenat-text"
				       placeholder="Search park, kingdom, or event…"
				       autocomplete="off"
				       value="<?= htmlspecialchars($this->__session->park_name ?? '') ?>" />
				<div class="pn-ac-results" id="pn-award-givenat-results"></div>
				<input type="hidden" name="ParkId" id="pn-award-park-id" value="<?= (int)$Player['ParkId'] ?>" />
				<input type="hidden" name="KingdomId" id="pn-award-kingdom-id" value="0" />
				<input type="hidden" name="EventId" id="pn-award-event-id" value="0" />
			</div>

			<!-- Note -->
			<div class="pn-acct-field">
				<label for="pn-award-note">Note <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<textarea name="Note" id="pn-award-note" rows="3" maxlength="400"
				          placeholder="What was this award given for?"></textarea>
				<span class="pn-char-count" id="pn-award-char-count">400 characters remaining</span>
			</div>
		</div>
		<div class="pn-modal-footer" style="display:flex;align-items:center;justify-content:space-between">
			<button class="pn-btn pn-btn-ghost" id="pn-award-cancel">Close</button>
			<div style="display:flex;gap:8px">
				<button class="pn-btn pn-btn-primary" id="pn-award-save-same" disabled>
					<i class="fas fa-plus"></i> Add Award
				</button>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Award Edit Modal
     ============================================= -->
<?php if ($canEditAdmin): ?>
<div class="pn-overlay" id="pn-award-edit-overlay">
	<div class="pn-modal-box" style="width:520px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-pencil-alt" style="margin-right:8px;color:#2c5282"></i>Edit Award</h3>
			<button class="pn-modal-close-btn" id="pn-edit-award-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div id="pn-edit-award-feedback" style="display:none"></div>

			<div class="pn-acct-field">
				<label>Award</label>
				<div class="pn-edit-award-name-display" id="pn-edit-award-name"></div>
			</div>

			<div class="pn-acct-field" id="pn-edit-rank-row" style="display:none">
				<label>Rank <span style="color:#a0aec0;font-weight:400;font-size:11px">— click to select</span></label>
				<div class="pn-rank-pills-wrap" id="pn-edit-rank-pills"></div>
				<input type="hidden" id="pn-edit-rank-val" value="" />
			</div>

			<div class="pn-acct-field">
				<label for="pn-edit-award-date">Date <span style="color:#e53e3e">*</span></label>
				<input type="date" id="pn-edit-award-date" />
			</div>

			<div class="pn-acct-field">
				<label>Given By <span style="color:#e53e3e">*</span></label>
				<?php if (!empty($PreloadOfficers)): ?>
				<div class="pn-officer-chips" id="pn-edit-award-officer-chips">
					<?php foreach ($PreloadOfficers as $officer): ?>
					<button type="button" class="pn-officer-chip"
					        data-id="<?= (int)$officer['MundaneId'] ?>"
					        data-name="<?= htmlspecialchars($officer['Persona']) ?>">
						<?= htmlspecialchars($officer['Persona']) ?> <span>(<?= htmlspecialchars($officer['Role']) ?>)</span>
					</button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<input type="text" id="pn-edit-givenby-text" placeholder="Or search by persona…" autocomplete="off" />
				<input type="hidden" id="pn-edit-givenby-id" value="" />
			</div>

			<div class="pn-acct-field">
				<label>Given At <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<input type="text" id="pn-edit-givenat-text" placeholder="Search park, kingdom, or event…" autocomplete="off" />
				<input type="hidden" id="pn-edit-park-id"    value="" />
				<input type="hidden" id="pn-edit-kingdom-id" value="" />
				<input type="hidden" id="pn-edit-event-id"   value="" />
			</div>

			<div class="pn-acct-field">
				<label for="pn-edit-award-note">Note <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<textarea id="pn-edit-award-note" rows="3" maxlength="400" placeholder="What was this award given for?"></textarea>
				<span class="pn-char-count" id="pn-edit-award-char-count">400 characters remaining</span>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-ghost" id="pn-edit-award-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-edit-award-save"><i class="fas fa-save"></i> Save Changes</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Recommendation Modal
     ============================================= -->
<?php if ($LoggedIn): ?>
<div class="pn-overlay" id="pn-rec-overlay">
	<div class="pn-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-award" style="margin-right:8px;color:#2c5282"></i>Recommend an Award</h3>
			<button class="pn-modal-close-btn" id="pn-modal-close-btn" type="button">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div class="pn-form-error" id="pn-rec-error"><?= $recError ?></div>
			<form id="pn-recommend-form" method="post" action="<?= UIR ?>Player/profile/<?= $Player['MundaneId'] ?>/addrecommendation">
				<div class="pn-rec-field">
					<label for="pn-rec-award">Award <span style="color:#e53e3e">*</span></label>
					<select name="KingdomAwardId" id="pn-rec-award">
						<option value="">Select award...</option>
						<?= $AwardOptions ?>
					</select>
				</div>
				<div class="pn-rec-field">
					<label for="pn-rec-rank">Rank <span style="color:#a0aec0;font-weight:400;text-transform:none">(optional)</span></label>
					<select name="Rank" id="pn-rec-rank">
						<option value="">None</option>
						<option value="1">1st</option>
						<option value="2">2nd</option>
						<option value="3">3rd</option>
						<option value="4">4th</option>
						<option value="5">5th</option>
						<option value="6">6th</option>
						<option value="7">7th</option>
						<option value="8">8th</option>
						<option value="9">9th</option>
						<option value="10">10th</option>
					</select>
				</div>
				<div class="pn-rec-field">
					<label for="pn-rec-reason">Reason <span style="color:#e53e3e">*</span></label>
					<input type="text" name="Reason" id="pn-rec-reason" maxlength="400" placeholder="Why should this player receive this award?" />
					<span class="pn-char-count" id="pn-rec-char-count">400 characters remaining</span>
				</div>
			</form>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-rec-cancel" type="button">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-rec-submit" type="button"><i class="fas fa-paper-plane"></i> Submit Recommendation</button>
		</div>
	</div>
</div>
<?php endif; ?>

<?php
// Build KingdomAwardId => max rank held by this player (for ladder award pre-fill)
$playerAwardRanks = array();
if (is_array($Details['Awards'])) {
	foreach ($Details['Awards'] as $a) {
		$aid  = (int)$a['AwardId'];
		$rank = (int)$a['Rank'];
		if ($aid > 0 && $rank > 0) {
			if (!isset($playerAwardRanks[$aid]) || $rank > $playerAwardRanks[$aid]) {
				$playerAwardRanks[$aid] = $rank;
			}
		}
	}
}
?>

<!-- =============================================
     JavaScript
     ============================================= -->
<script>
var PnConfig = {
	uir:            '<?= UIR ?>',
	httpService:    '<?= HTTP_SERVICE ?>',
	playerId:       <?= (int)($Player['MundaneId'] ?? 0) ?>,
	parkId:         <?= (int)($Player['ParkId'] ?? 0) ?>,
	parkName:       <?= json_encode($this->__session->park_name ?? '') ?>,
	kingdomId:      <?= (int)($KingdomId ?? 0) ?>,
	recError:       <?= !empty($recError) ? 'true' : 'false' ?>,
	canEditImages:  <?= !empty($canEditImages)  ? 'true' : 'false' ?>,
	canEditAccount: <?= !empty($canEditAccount) ? 'true' : 'false' ?>,
	canEditAdmin:   <?= !empty($canEditAdmin)   ? 'true' : 'false' ?>,
	awardRanks:     <?= json_encode($playerAwardRanks) ?>,
	awardOptHTML:   <?= json_encode('<option value="">Select award...</option>' . ($AwardOptions ?? '')) ?>,
	officerOptHTML: <?= json_encode('<option value="">Select title...</option>' . ($OfficerOptions ?? '')) ?>,
	preloadOfficers:<?= json_encode($PreloadOfficers ?? []) ?>,
	playerParkName: <?= json_encode($Player['Park'] ?? $Player['ParkName'] ?? '') ?>,
};
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js"></script>

<?php if ($canEditAdmin): ?>
<!-- Revoke Award Modal -->
<div class="pn-overlay" id="pn-award-revoke-overlay">
	<div class="pn-modal-box" style="width:420px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-ban" style="margin-right:8px;color:#b7791f"></i>Revoke Award</h3>
			<button class="pn-modal-close-btn" id="pn-revoke-award-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div id="pn-revoke-award-feedback" style="display:none"></div>
			<div class="pn-revoke-award-name" id="pn-revoke-award-name"></div>
			<div class="pn-acct-field">
				<label for="pn-revoke-reason">Revocation Reason <span style="color:#e53e3e">*</span></label>
				<textarea id="pn-revoke-reason" rows="3" maxlength="300" placeholder="Why is this award being revoked?"></textarea>
				<span class="pn-char-count" id="pn-revoke-char-count">300 characters remaining</span>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-revoke-award-cancel">Cancel</button>
			<button class="pn-btn" id="pn-revoke-award-save" style="background:#c53030;color:#fff;"><i class="fas fa-ban"></i> Revoke Award</button>
		</div>
	</div>
</div>

<!-- Add Note Modal -->
<div class="pn-overlay" id="pn-addnote-overlay">
	<div class="pn-modal-box" style="width:480px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-sticky-note" style="margin-right:8px;color:#2c5282"></i>Add Note</h3>
			<button class="pn-modal-close-btn" id="pn-addnote-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div id="pn-addnote-feedback" style="display:none"></div>
			<div class="pn-acct-field">
				<label for="pn-note-title">Note Title <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pn-note-title" maxlength="200" placeholder="e.g. Promotion, Warning, Waypoint Import" />
			</div>
			<div class="pn-acct-field">
				<label for="pn-note-desc">Description</label>
				<textarea id="pn-note-desc" rows="3" maxlength="1000" placeholder="Optional additional details..."></textarea>
			</div>
			<div style="display:flex;gap:12px;">
				<div class="pn-acct-field" style="flex:1">
					<label for="pn-note-date">Date <span style="color:#e53e3e">*</span></label>
					<input type="date" id="pn-note-date" />
				</div>
				<div class="pn-acct-field" style="flex:1">
					<label for="pn-note-date-complete">Date Complete</label>
					<input type="date" id="pn-note-date-complete" />
				</div>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-addnote-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-addnote-save"><i class="fas fa-save"></i> Add Note</button>
		</div>
	</div>
</div>

<!-- Move Player Modal -->
<div class="pn-overlay" id="pn-moveplayer-overlay">
	<div class="pn-modal-box" style="width:440px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-arrows-alt" style="margin-right:8px;color:#2c5282"></i>Move Player</h3>
			<button class="pn-modal-close-btn" id="pn-moveplayer-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div id="pn-moveplayer-feedback" style="display:none"></div>
			<div class="pn-move-current-park">
				<strong>Current park:</strong> <span id="pn-move-current-park-name"></span>
			</div>
			<div class="pn-acct-field">
				<label for="pn-move-park-text">New Park <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pn-move-park-text" placeholder="Search for a park..." autocomplete="off" />
				<input type="hidden" id="pn-move-park-id" value="0" />
			</div>
			<div class="pn-move-warning">
				<i class="fas fa-exclamation-triangle"></i>
				This will change the player&rsquo;s home park and reset their Park Member Since date.
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-move-cancel">Cancel</button>
			<button class="pn-btn" id="pn-move-submit" disabled style="background:#c53030;color:#fff;"><i class="fas fa-arrows-alt"></i> Move Player</button>
		</div>
	</div>
</div>
<?php endif; ?>
