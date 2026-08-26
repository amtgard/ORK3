<?php
/* -----------------------------------------------------------------------
   Kingdom Admin -- modal layer and page script.

   Extracted from Admin_kingdom.tpl unchanged, to keep the console template
   readable: the page itself is now ~375 lines instead of 2,504. Included from
   the page, so it runs in the page's variable scope: $kid, $AdminInfo,
   $AdminConfig, $AdminAwards, $SystemAwards, $park_edit_lookup and the
   Can* flags must all be set before the include.
   ----------------------------------------------------------------------- */
?>

<!-- =============================================
     MODALS
     ============================================= -->

<!-- ---- Edit Details ---- -->
<div class="ka-overlay" id="ka-details-overlay">
	<div class="ka-modal-box" style="width:560px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-edit" style="margin-right:8px;color:#2b6cb0"></i>Edit Kingdom Details</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-details-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-details-feedback"></div>
			<div class="ka-field">
				<label for="ka-details-name">Kingdom Name</label>
				<input type="text" id="ka-details-name" value="<?= htmlspecialchars($AdminInfo['Name'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Name'] ?? '') ?>">
			</div>
			<div class="ka-field">
				<label for="ka-details-abbr">Abbreviation <span class="ka-hint">(letters &amp; numbers only)</span></label>
				<input type="text" id="ka-details-abbr" value="<?= htmlspecialchars($AdminInfo['Abbreviation'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Abbreviation'] ?? '') ?>" maxlength="8">
				<div id="ka-details-abbr-warn" style="display:none;color:#c05621;font-size:12px;margin-top:4px"></div>
			</div>
			<div class="ka-field">
				<label for="ka-details-description">Description <span class="ka-hint">(optional &mdash; Markdown supported)</span></label>
				<textarea id="ka-details-description" rows="4" style="resize:vertical" data-original="<?= htmlspecialchars($AdminInfo['Description'] ?? '') ?>"><?= htmlspecialchars($AdminInfo['Description'] ?? '') ?></textarea>
			</div>
			<div class="ka-field">
				<label for="ka-details-url">Website URL <span class="ka-hint">(optional)</span></label>
				<input type="url" id="ka-details-url" value="<?= htmlspecialchars($AdminInfo['Url'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Url'] ?? '') ?>" placeholder="https://">
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-details-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-details-save"><i class="fas fa-save"></i> Save Details</button>
		</div>
	</div>
</div>

<!-- ---- Configuration ---- -->
<div class="ka-overlay" id="ka-config-overlay">
	<div class="ka-modal-box" style="width:560px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-sliders-h" style="margin-right:8px;color:#2b6cb0"></i>Configuration</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-config-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-config-feedback"></div>
			<div class="ka-field" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid #e2e8f0;margin-bottom:12px">
				<div>
					<div style="font-size:13px;font-weight:600;color:#2d3748">Recommendation Visibility</div>
					<div style="font-size:12px;color:#718096;margin-top:3px">When Private, only the monarchy and submitters can see recommendations.</div>
				</div>
				<select id="ka-config-recs-public" style="font-size:13px;border:1.5px solid #e2e8f0;border-radius:6px;padding:5px 8px;flex-shrink:0">
					<option value="1" <?= !empty($AwardRecsPublic) ? 'selected' : '' ?>>Public</option>
					<option value="0" <?= empty($AwardRecsPublic) ? 'selected' : '' ?>>Private (monarchy and submitters only)</option>
				</select>
			</div>
			<div id="ka-config-rows">
				<!-- Built by JS from KaConfig.adminConfig -->
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-config-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-config-save"><i class="fas fa-save"></i> Save Configuration</button>
		</div>
	</div>
</div>

<!-- ---- Heraldry ---- -->
<div class="ka-overlay" id="ka-heraldry-overlay">
	<div class="ka-modal-box" style="width:460px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-image" style="margin-right:8px;color:#6b46c1"></i>Kingdom Heraldry</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-heraldry-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-heraldry-feedback"></div>
			<div style="text-align:center;margin-bottom:16px">
				<img id="ka-heraldry-preview" src="<?= htmlspecialchars($heraldryUrl) ?>" style="max-width:200px;max-height:200px;border-radius:8px;border:1px solid #e2e8f0">
			</div>
			<div class="ka-field">
				<label>Upload New Heraldry <span class="ka-hint">(PNG, JPG, or GIF)</span></label>
				<input type="file" id="ka-heraldry-file" accept="image/png,image/jpeg,image/gif">
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-heraldry-overlay')">Cancel</button>
			<?php if ($hasHeraldry): ?>
			<button class="adm-btn adm-btn-danger" id="ka-heraldry-remove"><i class="fas fa-trash"></i> Remove</button>
			<?php endif; ?>
			<button class="adm-btn adm-btn-primary" id="ka-heraldry-upload" disabled><i class="fas fa-upload"></i> Upload</button>
		</div>
	</div>
</div>

<!-- ---- Park Titles ---- -->
<div class="ka-overlay" id="ka-parktitles-overlay">
	<div class="ka-modal-box" style="width:700px;max-width:calc(100vw - 32px)">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-flag" style="margin-right:8px;color:#2b6cb0"></i>Park Titles</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-parktitles-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-titles-feedback"></div>
			<div class="ka-admin-table-wrap">
				<table class="ka-admin-table" id="ka-titles-table">
					<thead>
						<tr>
							<th>Title</th>
							<th>Class</th>
							<th>Min Att.</th>
							<th>Cutoff</th>
							<th>Period</th>
							<th>Len.</th>
							<th></th>
						</tr>
					</thead>
					<tbody id="ka-titles-tbody">
						<!-- Built by JS -->
					</tbody>
					<tfoot>
						<tr>
							<td><input type="text" data-field="Title" placeholder="New title..."></td>
							<td><input type="number" data-field="Class" value="0" min="0"></td>
							<td><input type="number" data-field="MinimumAttendance" value="0" min="0"></td>
							<td><input type="number" data-field="MinimumCutoff" value="0" min="0"></td>
							<td>
								<select data-field="Period">
									<option value="month">Month</option>
									<option value="week">Week</option>
								</select>
							</td>
							<td><input type="number" data-field="Length" value="1" min="1"></td>
							<td></td>
						</tr>
					</tfoot>
				</table>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-parktitles-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-titles-save"><i class="fas fa-save"></i> Save Park Titles</button>
		</div>
	</div>
</div>

<!-- ---- Edit Parks ---- -->
<div class="ka-overlay" id="ka-editparks-overlay">
	<div class="ka-modal-box" style="width:700px;max-width:calc(100vw - 32px)">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-map-marker-alt" style="margin-right:8px;color:#276749"></i>Edit Parks</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-editparks-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-parks-feedback"></div>
			<div class="ka-admin-table-wrap">
				<table class="ka-admin-table">
					<thead>
						<tr>
							<th>Park Name</th>
							<th>Title</th>
							<th>Abbr</th>
							<th style="text-align:center">Active</th>
							<th></th>
						</tr>
					</thead>
					<tbody id="ka-parks-tbody">
						<!-- Built by JS -->
					</tbody>
				</table>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-editparks-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-parks-save"><i class="fas fa-save"></i> Save Parks</button>
		</div>
	</div>
</div>

<!-- ---- Awards ---- -->
<div class="ka-overlay" id="ka-awards-overlay">
	<div class="ka-modal-box" style="width:760px;max-width:calc(100vw - 32px)">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-medal" style="margin-right:8px;color:#6b46c1"></i>Manage Awards</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-awards-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-awards-feedback"></div>
			<div class="ka-admin-table-wrap">
				<table class="ka-admin-table">
					<thead>
						<tr>
							<th>Award Name</th>
							<th>Reign</th>
							<th>Month</th>
							<th>Title?</th>
							<th>Class</th>
							<th></th>
						</tr>
					</thead>
					<tbody id="ka-awards-tbody">
						<!-- Built by JS -->
					</tbody>
				</table>
			</div>
			<!-- Add Award Alias form -->
			<div id="ka-add-award-wrap" style="display:none" class="ka-add-award-wrap">
				<div class="ka-add-award-title">Add Award Alias</div>
				<p class="ka-form-hint">An award alias lets you create additional variations on existing system awards and titles.</p>
				<div class="ka-field">
					<label>System Award</label>
					<div class="ka-alias-picker-wrap">
						<input type="hidden" id="ka-new-award-id">
						<button type="button" class="ka-alias-trigger" id="ka-alias-trigger">
							<span class="ka-alias-label" id="ka-alias-label">Select a system award&hellip;</span>
							<i class="fas fa-chevron-down" style="font-size:11px;opacity:.5"></i>
						</button>
						<div class="ka-alias-dropdown" id="ka-alias-dropdown" style="display:none">
							<input type="text" class="ka-alias-search" id="ka-alias-search" placeholder="Search awards..." autocomplete="off">
							<div class="ka-alias-list" id="ka-alias-list"></div>
						</div>
					</div>
				</div>
				<div class="ka-award-row-fields">
					<div class="ka-field ka-field-grow">
						<label>Kingdom Name <span class="ka-hint">(your kingdom&rsquo;s name for this award)</span></label>
						<input type="text" id="ka-new-award-name" placeholder="e.g. Order of the Warrior">
					</div>
					<div class="ka-field">
						<label>Reign Limit</label>
						<input type="number" id="ka-new-reign" min="0" value="0" style="width:64px">
					</div>
					<div class="ka-field">
						<label>Month Limit</label>
						<input type="number" id="ka-new-month" min="0" value="0" style="width:64px">
					</div>
					<div class="ka-field ka-field-center">
						<label>Title?</label>
						<input type="checkbox" id="ka-new-istitle">
					</div>
					<div class="ka-field">
						<label>Title Class</label>
						<input type="number" id="ka-new-tclass" min="0" value="0" style="width:64px" disabled>
					</div>
				</div>
				<div style="display:flex;gap:8px;margin-top:10px">
					<button class="ka-save-btn" id="ka-new-award-save"><i class="fas fa-plus"></i> Add Award Alias</button>
					<button class="adm-btn adm-btn-ghost" id="ka-new-award-cancel" style="font-size:13px">Cancel</button>
				</div>
			</div>
			<!-- Add Kingdom-Specific Award form -->
			<div id="ka-add-custom-wrap" style="display:none" class="ka-add-award-wrap">
				<div class="ka-add-award-title">Add Kingdom-Specific Award</div>
				<p class="ka-form-hint">A kingdom-specific award allows you to add awards only given out in your kingdom.</p>
				<div class="ka-award-row-fields">
					<div class="ka-field ka-field-grow">
						<label>Award Name</label>
						<input type="text" id="ka-custom-name" placeholder="e.g. Kingdom Spotlight">
					</div>
					<div class="ka-field">
						<label>Reign Limit</label>
						<input type="number" id="ka-custom-reign" min="0" value="0" style="width:64px">
					</div>
					<div class="ka-field">
						<label>Month Limit</label>
						<input type="number" id="ka-custom-month" min="0" value="0" style="width:64px">
					</div>
					<div class="ka-field ka-field-center">
						<label>Title?</label>
						<input type="checkbox" id="ka-custom-istitle">
					</div>
					<div class="ka-field">
						<label>Title Class</label>
						<input type="number" id="ka-custom-tclass" min="0" value="0" style="width:64px" disabled>
					</div>
				</div>
				<div style="display:flex;gap:8px;margin-top:10px">
					<button class="ka-save-btn" id="ka-custom-save"><i class="fas fa-plus"></i> Add Award</button>
					<button class="adm-btn adm-btn-ghost" id="ka-custom-cancel" style="font-size:13px">Cancel</button>
				</div>
			</div>
			<div class="ka-award-add-btns" id="ka-award-add-btns">
				<button class="ka-add-btn" id="ka-awards-add-btn"><i class="fas fa-plus"></i> Add Award Alias</button>
				<button class="ka-add-btn" id="ka-custom-add-btn"><i class="fas fa-plus"></i> Add Kingdom-Specific Award</button>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-awards-overlay')">Done</button>
		</div>
	</div>
</div>

<!-- ---- Create Player ---- -->
<div class="ka-overlay" id="ka-createplayer-overlay">
	<div class="ka-modal-box" style="width:560px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Create Player</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-createplayer-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-cp-feedback"></div>
			<div class="ka-field">
				<label>Home Park <span style="color:#e53e3e">*</span></label>
				<select id="ka-cp-park">
					<option value="">-- select park --</option>
					<?php foreach ($park_edit_lookup ?? [] as $p): if ($p['Active'] !== 'Active') continue; ?>
					<option value="<?= (int)$p['ParkId'] ?>"><?= htmlspecialchars($p['Name']) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label>Persona <span style="color:#e53e3e">*</span></label>
					<input type="text" id="ka-cp-persona" placeholder="In-game name">
				</div>
				<div class="ka-field">
					<label>Email</label>
					<input type="email" id="ka-cp-email" placeholder="email@example.com">
				</div>
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label>Given Name</label>
					<input type="text" id="ka-cp-given" placeholder="First name">
				</div>
				<div class="ka-field">
					<label>Surname</label>
					<input type="text" id="ka-cp-surname" placeholder="Last name">
				</div>
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label>Username <span style="color:#e53e3e">*</span></label>
					<input type="text" id="ka-cp-username" autocomplete="new-password" placeholder="min. 4 characters">
				</div>
				<div class="ka-field">
					<label>Password</label>
					<input type="password" id="ka-cp-password" autocomplete="new-password" placeholder="optional">
				</div>
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label>Restricted</label>
					<div class="ka-radio-group">
						<label><input type="radio" name="ka-cp-restricted" value="0" checked> No</label>
						<label><input type="radio" name="ka-cp-restricted" value="1"> Yes</label>
					</div>
				</div>
				<div class="ka-field">
					<label>Waivered</label>
					<div class="ka-radio-group">
						<label><input type="radio" name="ka-cp-waivered" value="0" checked> No</label>
						<label><input type="radio" name="ka-cp-waivered" value="1"> Yes</label>
					</div>
				</div>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-createplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-cp-submit"><i class="fas fa-user-plus"></i> Create Player</button>
		</div>
	</div>
</div>

<!-- ---- Move Player ---- -->
<div class="ka-overlay" id="ka-moveplayer-overlay">
	<div class="ka-modal-box" style="width:520px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-people-arrows" style="margin-right:8px;color:#2b6cb0"></i>Move Player</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-moveplayer-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-mp-feedback"></div>
			<div class="ka-field ka-field-ac">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ka-mp-player-name" autocomplete="off" placeholder="Search players...">
				<input type="hidden" id="ka-mp-player-id">
				<div class="kn-ac-results" id="ka-mp-player-results"></div>
			</div>
			<div class="ka-field ka-field-ac" style="margin-top:12px">
				<label>New Home Park <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ka-mp-park-name" autocomplete="off" placeholder="Search all parks...">
				<input type="hidden" id="ka-mp-park-id">
				<div class="kn-ac-results" id="ka-mp-park-results"></div>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-moveplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-mp-submit" disabled><i class="fas fa-arrow-right"></i> Move Player</button>
		</div>
	</div>
</div>

<!-- ---- Merge Players ---- -->
<div class="ka-overlay" id="ka-mergeplayer-overlay">
	<div class="ka-modal-box" style="width:560px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-compress-alt" style="margin-right:8px;color:#c53030"></i>Merge Players</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-mergeplayer-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-mgp-feedback"></div>
			<div class="ka-warning">
				<i class="fas fa-exclamation-triangle"></i>
				<div><strong>This action is permanent and cannot be undone.</strong><br>
				The <em>Remove</em> player's account will be deleted. All their awards, attendance, officer history, and unit memberships transfer to the <em>Keep</em> player.</div>
			</div>
			<div class="ka-field ka-field-ac">
				<label>Player to Keep <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ka-mgp-keep-name" autocomplete="off" placeholder="Search for player to keep...">
				<input type="hidden" id="ka-mgp-keep-id">
				<div class="kn-ac-results" id="ka-mgp-keep-results"></div>
			</div>
			<div class="ka-field ka-field-ac" style="margin-top:12px">
				<label>Player to Remove &mdash; <span style="color:#c53030;font-size:12px"><i class="fas fa-skull-crossbones"></i> permanently deleted</span> <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ka-mgp-remove-name" autocomplete="off" placeholder="Search for player to remove...">
				<input type="hidden" id="ka-mgp-remove-id">
				<div class="kn-ac-results" id="ka-mgp-remove-results"></div>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-mergeplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-danger" id="ka-mgp-submit" disabled><i class="fas fa-compress-alt"></i> Merge Players</button>
		</div>
	</div>
</div>

<!-- ---- Claim Park ---- -->
<div class="ka-overlay" id="ka-claimpark-overlay">
	<div class="ka-modal-box" style="width:460px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-flag" style="margin-right:8px;color:#276749"></i>Claim Park</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-claimpark-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body" style="padding:20px">
			<p style="font-size:14px;color:#2d3748;margin:0 0 10px">To claim a park, please submit documentation, including Althing results if possible, authorizing the move to:</p>
			<p style="font-size:15px;font-weight:600;margin:0 0 14px">
				<a href="mailto:Contracts@amtgard.com?subject=<?= rawurlencode('Park Claim Request - ' . ($AdminInfo['Name'] ?? '')) ?>&body=<?= rawurlencode("Kingdom: " . ($AdminInfo['Name'] ?? '') . "\nPark Name: \nAlthing Results: \nReason for Claim: ") ?>">Contracts@amtgard.com</a>
			</p>
			<p style="font-size:12px;color:#718096;margin:0">Include the park name, your kingdom, and any supporting documentation.</p>
		</div>
		<div class="ka-modal-footer" style="justify-content:flex-end">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-claimpark-overlay')">Close</button>
		</div>
	</div>
</div>

<!-- ---- Operations ---- -->
<div class="ka-overlay" id="ka-ops-overlay">
	<div class="ka-modal-box" style="width:520px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-cogs" style="margin-right:8px;color:#c05621"></i>Operations</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-ops-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-ops-feedback"></div>
			<div class="ka-ops-row">
				<div class="ka-ops-info">
					<strong>Reset Waivers</strong>
					<p>Clears all waiver records for this <?= strtolower($entityLabel) ?>. This action cannot be undone.</p>
				</div>
				<button class="ka-ops-btn ka-ops-btn-danger" id="ka-ops-reset-waivers">
					<i class="fas fa-eraser"></i> Reset Waivers
				</button>
			</div>
			<?php if (!empty($IsOrkAdmin)):
				$isActive = ($AdminInfo['Active'] ?? 'Active') === 'Active'; ?>
			<div class="ka-ops-row">
				<div class="ka-ops-info">
					<strong>Active Status</strong>
					<p>This <?= strtolower($entityLabel) ?> is currently <strong id="ka-ops-status-label"><?= $isActive ? 'Active' : 'Inactive' ?></strong>.</p>
				</div>
				<button class="ka-ops-btn<?= $isActive ? ' ka-ops-btn-danger' : '' ?>"
					id="ka-ops-status-toggle" data-active="<?= $isActive ? '1' : '0' ?>">
					<?php if ($isActive): ?>
						<i class="fas fa-ban"></i> Mark Inactive
					<?php else: ?>
						<i class="fas fa-check-circle"></i> Restore to Active
					<?php endif; ?>
				</button>
			</div>
			<?php endif; ?>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-ops-overlay')">Done</button>
		</div>
	</div>
</div>

<!-- ---- Principality Status (ORK Admins only) ---- -->
<?php if (!empty($IsOrkAdmin) && !empty($AdminInfo['IsPrincipality'])): ?>
<div class="ka-overlay" id="ka-prinz-overlay">
	<div class="ka-modal-box" style="width:520px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-crown" style="margin-right:8px;color:#c05621"></i>Principality Status</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-prinz-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-prinz-feedback"></div>
			<p style="margin:0 0 12px;font-size:13px;color:#4a5568">
				This is a <strong>Principality</strong> sponsored by
				<strong><?= htmlspecialchars($AdminInfo['ParentKingdomName'] ?? '') ?></strong>.
			</p>
			<div class="ka-field ka-field-ac">
				<label>Change Sponsor Kingdom</label>
				<input type="text" id="ka-prinz-parent-name" autocomplete="off"
					placeholder="Search kingdoms..."
					value="<?= htmlspecialchars($AdminInfo['ParentKingdomName'] ?? '') ?>">
				<input type="hidden" id="ka-prinz-parent-id"
					value="<?= (int)($AdminInfo['ParentKingdomId'] ?? 0) ?>">
				<div class="kn-ac-results" id="ka-prinz-parent-results"></div>
			</div>
			<div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
				<button class="ka-save-btn" id="ka-prinz-sponsor-save">
					<i class="fas fa-save"></i> Save Sponsor
				</button>
				<button class="ka-save-btn" id="ka-prinz-promote"
					style="background:#c05621;border-color:#c05621">
					<i class="fas fa-crown"></i> Convert to Kingdom
				</button>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-prinz-overlay')">Done</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- ---- Confirmation Dialog ---- -->
<div class="ka-confirm-overlay" id="ka-confirm-overlay">
	<div class="ka-modal-box ka-confirm-box">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title" id="ka-confirm-title"><i class="fas fa-exclamation-triangle" style="margin-right:8px;color:#e53e3e"></i>Confirm</h3>
			<button class="ka-modal-close" id="ka-confirm-close">&times;</button>
		</div>
		<div class="ka-modal-body">
			<p id="ka-confirm-message" style="margin:0;font-size:14px;color:#2d3748;line-height:1.6"></p>
		</div>
		<div class="ka-modal-footer" style="justify-content:flex-end;gap:10px">
			<button class="adm-btn adm-btn-ghost" id="ka-confirm-cancel">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-confirm-ok">Confirm</button>
		</div>
	</div>
</div>

<?php if ($mo_can_manage): ?>
<!-- ---- Manage Officers Host Modal (--z-modal; partial sub-modals render at --z-modal-top / --z-help-overlay) ---- -->
<div class="ka-mo-overlay" id="ka-mo-overlay">
	<div class="ka-mo-box">
		<div class="ka-mo-header">
			<h3 class="ka-mo-title"><i class="fas fa-user-shield" style="color:#b7791f"></i> Manage Officers</h3>
			<button class="ka-mo-close" type="button" onclick="kaCloseManageOfficers()" aria-label="Close">&times;</button>
		</div>
		<div class="ka-mo-body">
<?php include __DIR__ . '/_manage_officers.tpl'; ?>
		</div>
	</div>
</div>
<script>
(function() {
	var overlay = document.getElementById('ka-mo-overlay');
	function openMo() {
		if (!overlay) return;
		overlay.classList.add('ka-open');
		document.body.style.overflow = 'hidden';
		if (typeof window.moRefresh === 'function') { try { window.moRefresh(); } catch (e) {} }
	}
	function closeMo() {
		if (!overlay) return;
		overlay.classList.remove('ka-open');
		document.body.style.overflow = '';
	}
	window.kaOpenManageOfficers = openMo;
	window.kaCloseManageOfficers = closeMo;
	if (overlay) {
		overlay.addEventListener('click', function(e) { if (e.target === overlay) closeMo(); });
	}
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && overlay && overlay.classList.contains('ka-open')) closeMo();
	});
})();
</script>
<?php endif; ?>

<!-- =============================================
     JAVASCRIPT
     ============================================= -->
<script>
var KaConfig = {
	uir:              '<?= UIR ?>',
	kingdomId:        <?= $kid ?>,
	kingdomName:      <?= json_encode($AdminInfo['Name'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	canManage:        true,
	isOrkAdmin:       <?= !empty($IsOrkAdmin) ? 'true' : 'false' ?>,
	parkTitleOptions: <?= json_encode($ParkTitleId_options ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	parkEditLookup:   <?= json_encode(array_values($park_edit_lookup ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminConfig:      <?= json_encode($AdminConfig ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminParkTitles:  <?= json_encode($AdminParkTitles ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminAwards:      <?= json_encode($AdminAwards ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	systemAwards:     <?= json_encode($SystemAwards ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminInfo:        <?= json_encode($AdminInfo ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
};
// tnFixedAcPosition comes from partials/_manage_officers.tpl, included unconditionally
// above at the Manage Officers modal — its guarded polyfill runs first, so a second
// identical copy here could never execute. revised.js (the usual home) is not loaded
// on this page.
</script>
<script>
(function() {
	if (!KaConfig.canManage) return;

	var UIR = KaConfig.uir;
	var BASE_URL = UIR + 'KingdomAjax/kingdom/' + KaConfig.kingdomId + '/';

	function gid(id) { return document.getElementById(id); }

	/* ── Modal helpers ────────────────────────────── */
	function kaOpenModal(id) {
		var el = gid(id);
		if (el) { el.classList.add('ka-open'); document.body.style.overflow = 'hidden'; }
	}
	function kaCloseModal(id) {
		var el = gid(id);
		if (!el) return;
		el.classList.remove('ka-open');
		document.body.style.overflow = '';
		el.querySelectorAll('.ka-feedback').forEach(function(f) { f.style.display = 'none'; f.innerHTML = ''; });
	}
	window.kaOpenModal  = kaOpenModal;
	window.kaCloseModal = kaCloseModal;

	// Close on backdrop click
	document.querySelectorAll('.ka-overlay').forEach(function(ov) {
		ov.addEventListener('click', function(e) { if (e.target === ov) kaCloseModal(ov.id); });
	});
	// Close on Escape
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			document.querySelectorAll('.ka-overlay.ka-open').forEach(function(ov) { kaCloseModal(ov.id); });
			kaCloseConfirm();
		}
	});

	/* ── Feedback helper ──────────────────────────── */
	function kaFeedback(id, msg, ok) {
		var el = gid(id);
		if (!el) return;
		el.className = 'ka-feedback ' + (ok ? 'ka-feedback-ok' : 'ka-feedback-err');
		el.innerHTML = msg;
		el.style.display = 'block';
		if (ok) { clearTimeout(el._t); el._t = setTimeout(function() { el.style.display = 'none'; }, 5000); }
	}
	function kaClearFeedback(id) {
		var el = gid(id); if (el) { el.style.display = 'none'; el.innerHTML = ''; }
	}

	/* ── Confirm dialog ───────────────────────────── */
	var _kaConfirmCb = null;
	function kaConfirm(message, onConfirm, title) {
		var overlay = gid('ka-confirm-overlay');
		gid('ka-confirm-message').textContent = message;
		if (title) gid('ka-confirm-title').childNodes[1].textContent = ' ' + title;
		_kaConfirmCb = onConfirm;
		overlay.classList.add('ka-open');
	}
	function kaCloseConfirm() {
		var overlay = gid('ka-confirm-overlay');
		if (overlay) overlay.classList.remove('ka-open');
		_kaConfirmCb = null;
	}
	gid('ka-confirm-ok') && gid('ka-confirm-ok').addEventListener('click', function() { var cb = _kaConfirmCb; kaCloseConfirm(); if (cb) cb(); });
	gid('ka-confirm-cancel') && gid('ka-confirm-cancel').addEventListener('click', kaCloseConfirm);
	gid('ka-confirm-close') && gid('ka-confirm-close').addEventListener('click', kaCloseConfirm);
	gid('ka-confirm-overlay') && gid('ka-confirm-overlay').addEventListener('click', function(e) { if (e.target === this) kaCloseConfirm(); });

	/* ── Autocomplete helper (kn-ac-results pattern) ── */
	function kaAc(opts) {
		var input   = gid(opts.inputId);
		var hidden  = gid(opts.hiddenId);
		var results = gid(opts.resultsId);
		if (!input || !results) return;
		var timer = null, minLen = opts.minLen || 2;

		function acClose() { results.classList.remove('kn-ac-open'); results.innerHTML = ''; }
		function acOpen(items) {
			if (!items.length) {
				results.innerHTML = '<div class="kn-ac-item" style="color:#a0aec0;pointer-events:none">No results</div>';
				if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, results);
				results.classList.add('kn-ac-open');
				return;
			}
			results.innerHTML = items.map(function(item) {
				return '<div class="kn-ac-item" tabindex="-1" data-id="' + item.id
					+ '" data-name="' + encodeURIComponent(item.label)
					+ (item.extra !== undefined ? '" data-extra="' + encodeURIComponent(item.extra) : '')
					+ '">' + item.html + '</div>';
			}).join('');
			// Fixed positioning for modals (shared helper)
			if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, results);
			results.classList.add('kn-ac-open');
		}
		function selectItem(item) {
			input.value  = decodeURIComponent(item.dataset.name);
			hidden.value = item.dataset.id;
			acClose();
			if (opts.onSelect) opts.onSelect(item.dataset.id, input.value, item.dataset.extra ? decodeURIComponent(item.dataset.extra) : '');
		}
		input.addEventListener('input', function() {
			var term = this.value.trim();
			hidden.value = '';
			clearTimeout(timer);
			if (opts.onClear) opts.onClear();
			if (term.length < minLen) { acClose(); return; }
			timer = setTimeout(function() {
				opts.fetchFn(term, function(items) { acOpen(items); });
			}, 220);
		});
		results.addEventListener('click', function(e) {
			var item = e.target.closest('.kn-ac-item[data-id]');
			if (!item) return;
			selectItem(item);
		});
		document.addEventListener('click', function(e) {
			if (!e.target.closest('#' + opts.inputId + ', #' + opts.resultsId)) acClose();
		});
		input.addEventListener('keydown', function(e) {
			var items = results.querySelectorAll('.kn-ac-item[data-id]');
			if (!items.length) return;
			var focused = results.querySelector('.kn-ac-focused');
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				var next = focused ? (focused.nextElementSibling || items[0]) : items[0];
				if (focused) focused.classList.remove('kn-ac-focused');
				if (next && next.dataset.id) next.classList.add('kn-ac-focused');
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				var prev = focused ? (focused.previousElementSibling || items[items.length - 1]) : items[items.length - 1];
				if (focused) focused.classList.remove('kn-ac-focused');
				if (prev && prev.dataset.id) prev.classList.add('kn-ac-focused');
			} else if (e.key === 'Enter' && focused) {
				e.preventDefault(); selectItem(focused);
			} else if (e.key === 'Escape') {
				acClose();
			}
		});
	}

	/* ── Search helpers ───────────────────────────── */
	function kaEsc(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

	/* Kingdom-scoped player search (house rule: player search is scoped, &q= form).
	   This page is a single kingdom's admin console, so scope=own is correct. */
	function kaSearchPlayers(q, cb) {
		fetch(UIR + 'KingdomAjax/playersearch/' + KaConfig.kingdomId + '&q=' + encodeURIComponent(q) + '&scope=own&include_inactive=1&include_suspended=1')
		.then(function(r){return r.json();}).then(function(d) {
			cb((d || []).map(function(p) {
				var inactive = Number(p.Active) === 0 ? ' <span style="color:#e53e3e;font-size:10px;font-weight:600">inactive</span>' : '';
				return { id: p.MundaneId, label: p.Persona, html: kaEsc(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + kaEsc(p.KAbbr) + ' &middot; ' + kaEsc(p.ParkName) + ')</span>' + inactive };
			}));
		}).catch(function(){cb([]);});
	}
	function kaSearchParks(q, cb) {
		fetch(UIR + 'SearchAjax/universal&focus=park&q=' + encodeURIComponent(q))
		.then(function(r){return r.json();}).then(function(d) {
			cb((d.parks || []).map(function(p) {
				return { id: p.id, label: p.name, extra: p.kingdom || '', html: kaEsc(p.name) + (p.kingdom ? ' <span style="color:#a0aec0;font-size:11px">[' + kaEsc(p.kingdom) + ']</span>' : '') };
			}));
		}).catch(function(){cb([]);});
	}
	function kaSearchKingdoms(q, cb) {
		fetch(UIR + 'SearchAjax/universal&focus=kingdom&q=' + encodeURIComponent(q))
		.then(function(r){return r.json();}).then(function(d) {
			cb((d.kingdoms || []).map(function(k) {
				return { id: k.id, label: k.name, html: kaEsc(k.name) + ' <span style="color:#a0aec0;font-size:11px">(' + kaEsc(k.abbr) + ')</span>' };
			}));
		}).catch(function(){cb([]);});
	}

	/* ── POST helper ──────────────────────────────── */
	function kaPost(url, data, btn, feedbackId, onSuccess) {
		if (btn) btn.disabled = true;
		var fd = new FormData();
		Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
		fetch(url, { method: 'POST', body: fd })
		.then(function(r) { return r.json(); })
		.then(function(r) {
			if (btn) btn.disabled = false;
			if (r.status === 0) { onSuccess(r); }
			else { kaFeedback(feedbackId, r.error || 'An error occurred.', false); }
		})
		.catch(function() { if (btn) btn.disabled = false; kaFeedback(feedbackId, 'Request failed. Please try again.', false); });
	}

	/* ══════════════════════════════════════════════
	   EDIT DETAILS
	   ══════════════════════════════════════════════ */
	(function() {
		var btn = gid('ka-details-save');
		if (!btn) return;

		// Abbreviation check
		var abbrTimer = null;
		var abbrInp = gid('ka-details-abbr');
		if (abbrInp) {
			abbrInp.addEventListener('input', function() {
				var warn = gid('ka-details-abbr-warn');
				clearTimeout(abbrTimer);
				var abbr = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
				if (!abbr) { if (warn) warn.style.display = 'none'; return; }
				abbrTimer = setTimeout(function() {
					var fd = new FormData();
					fd.append('Abbreviation', abbr);
					fd.append('ExcludeKingdomId', KaConfig.kingdomId);
					fetch(BASE_URL + 'checkabbr', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(r) {
						if (!warn) return;
						if (r.taken) { warn.textContent = '"' + abbr + '" is already used by ' + r.name + '.'; warn.style.display = ''; }
						else { warn.style.display = 'none'; }
					});
				}, 400);
			});
		}

		btn.addEventListener('click', function() {
			kaClearFeedback('ka-details-feedback');
			var name = (gid('ka-details-name').value || '').trim();
			var abbr = (gid('ka-details-abbr').value || '').replace(/[^A-Za-z0-9]/g, '');
			if (!name) { kaFeedback('ka-details-feedback', 'Kingdom name is required.', false); return; }
			if (!abbr) { kaFeedback('ka-details-feedback', 'Abbreviation is required.', false); return; }
			var fd = new FormData();
			fd.append('Name', name);
			fd.append('Abbreviation', abbr);
			fd.append('Description', (gid('ka-details-description').value || '').trim());
			fd.append('Url', (gid('ka-details-url').value || '').trim());
			btn.disabled = true;
			fetch(BASE_URL + 'setdetails', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(r) {
				btn.disabled = false;
				if (r && r.status === 0) {
					kaFeedback('ka-details-feedback', 'Details saved!', true);
					gid('ka-details-name').dataset.original = gid('ka-details-name').value;
					gid('ka-details-abbr').dataset.original = gid('ka-details-abbr').value;
					gid('ka-details-description').dataset.original = gid('ka-details-description').value;
					gid('ka-details-url').dataset.original = gid('ka-details-url').value;
				} else { kaFeedback('ka-details-feedback', (r && r.error) ? r.error : 'Save failed.', false); }
			})
			.catch(function() { btn.disabled = false; kaFeedback('ka-details-feedback', 'Request failed.', false); });
		});
	})();

	/* ══════════════════════════════════════════════
	   CONFIGURATION
	   ══════════════════════════════════════════════ */
	(function() {
		// Build config rows
		var container = gid('ka-config-rows');
		if (container) {
			(KaConfig.adminConfig || []).forEach(function(cfg) {
				var row = document.createElement('div');
				row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #edf2f7';
				var lbl = document.createElement('div');
				lbl.style.cssText = 'font-size:13px;font-weight:500;color:#2d3748';
				var keyLabels = { 'AwardRecsPublic': 'Award Recommendations Visibility' };
				lbl.textContent = keyLabels[cfg.Key] || cfg.Key;
				row.appendChild(lbl);

				var inputs = document.createElement('div');
				inputs.style.cssText = 'display:flex;gap:6px;align-items:center;flex-shrink:0';
				var val = cfg.Value;

				if (val !== null && typeof val === 'object' && !Array.isArray(val)) {
					Object.keys(val).forEach(function(subKey) {
						var sub = document.createElement('span');
						sub.style.cssText = 'font-size:11px;color:#a0aec0';
						sub.textContent = subKey + ':';
						inputs.appendChild(sub);
						var inp;
						var allowed = cfg.AllowedValues && cfg.AllowedValues[subKey];
						if (allowed && Array.isArray(allowed)) {
							inp = document.createElement('select');
							inp.style.cssText = 'padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
							allowed.forEach(function(opt) {
								var o = document.createElement('option');
								o.value = opt; o.textContent = opt;
								if (opt == val[subKey]) o.selected = true;
								inp.appendChild(o);
							});
						} else {
							inp = document.createElement('input');
							inp.type = (typeof val[subKey] === 'number') ? 'number' : 'text';
							inp.style.cssText = 'width:70px;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
							inp.value = val[subKey];
						}
						inp.dataset.configId = cfg.ConfigurationId;
						inp.dataset.configSub = subKey;
						inp.className = 'ka-config-input';
						inputs.appendChild(inp);
					});
				} else {
					var inp = document.createElement('input');
					inp.type = (cfg.Type === 'number') ? 'number' : 'text';
					inp.style.cssText = 'width:80px;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
					inp.value = (val !== null && val !== undefined) ? val : '';
					inp.dataset.configId = cfg.ConfigurationId;
					inp.className = 'ka-config-input';
					inputs.appendChild(inp);
				}
				row.appendChild(inputs);
				container.appendChild(row);
			});
		}

		var btn = gid('ka-config-save');
		if (!btn) return;
		btn.addEventListener('click', function() {
			kaClearFeedback('ka-config-feedback');
			var data = {};
			document.querySelectorAll('#ka-config-rows .ka-config-input').forEach(function(inp) {
				var cid = inp.dataset.configId;
				var sub = inp.dataset.configSub;
				if (!cid) return;
				var key = sub ? ('Config[' + cid + '][' + sub + ']') : ('Config[' + cid + ']');
				data[key] = inp.value;
			});
			var recsVal = gid('ka-config-recs-public') ? gid('ka-config-recs-public').value : null;
			btn.disabled = true;

			function saveRecs(cb) {
				if (recsVal === null) { cb(true, null); return; }
				var fd = new FormData();
				fd.append('Value', recsVal);
				fetch(BASE_URL + 'setrecsvisibility', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) { cb(r && r.status === 0, (r && r.error) ? r.error : 'Visibility save failed.'); })
				.catch(function() { cb(false, 'Visibility request failed.'); });
			}

			if (Object.keys(data).length) {
				var fd = new FormData();
				Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
				fetch(BASE_URL + 'setconfig', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					if (r && r.status === 0) {
						saveRecs(function(ok, err) {
							btn.disabled = false;
							if (ok) kaFeedback('ka-config-feedback', 'Configuration saved!', true);
							else kaFeedback('ka-config-feedback', err, false);
						});
					} else { btn.disabled = false; kaFeedback('ka-config-feedback', (r && r.error) ? r.error : 'Save failed.', false); }
				})
				.catch(function() { btn.disabled = false; kaFeedback('ka-config-feedback', 'Request failed.', false); });
			} else {
				saveRecs(function(ok, err) {
					btn.disabled = false;
					if (ok) kaFeedback('ka-config-feedback', 'Configuration saved!', true);
					else kaFeedback('ka-config-feedback', err, false);
				});
			}
		});
	})();

	/* ══════════════════════════════════════════════
	   HERALDRY
	   ══════════════════════════════════════════════ */
	(function() {
		var fileInput = gid('ka-heraldry-file');
		var uploadBtn = gid('ka-heraldry-upload');
		var removeBtn = gid('ka-heraldry-remove');

		if (fileInput) {
			fileInput.addEventListener('change', function() {
				if (uploadBtn) uploadBtn.disabled = !this.files.length;
				if (this.files.length) {
					var reader = new FileReader();
					reader.onload = function(e) { gid('ka-heraldry-preview').src = e.target.result; };
					reader.readAsDataURL(this.files[0]);
				}
			});
		}
		if (uploadBtn) {
			uploadBtn.addEventListener('click', function() {
				if (!fileInput.files.length) return;
				var fd = new FormData();
				fd.append('Heraldry', fileInput.files[0]);
				uploadBtn.disabled = true;
				fetch(BASE_URL + 'setheraldry', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					uploadBtn.disabled = false;
					if (r && r.status === 0) {
						kaFeedback('ka-heraldry-feedback', 'Heraldry updated! Refreshing...', true);
						setTimeout(function() { location.reload(); }, 1000);
					} else { kaFeedback('ka-heraldry-feedback', (r && r.error) ? r.error : 'Upload failed.', false); }
				})
				.catch(function() { uploadBtn.disabled = false; kaFeedback('ka-heraldry-feedback', 'Request failed.', false); });
			});
		}
		if (removeBtn) {
			removeBtn.addEventListener('click', function() {
				kaConfirm('Remove the kingdom heraldry?', function() {
					removeBtn.disabled = true;
					fetch(BASE_URL + 'removeheraldry', { method: 'POST', body: new FormData() })
					.then(function(r) { return r.json(); })
					.then(function(r) {
						removeBtn.disabled = false;
						if (r && r.status === 0) {
							kaFeedback('ka-heraldry-feedback', 'Heraldry removed. Refreshing...', true);
							setTimeout(function() { location.reload(); }, 1000);
						} else { kaFeedback('ka-heraldry-feedback', (r && r.error) ? r.error : 'Remove failed.', false); }
					})
					.catch(function() { removeBtn.disabled = false; kaFeedback('ka-heraldry-feedback', 'Request failed.', false); });
				}, 'Remove Heraldry');
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   PARK TITLES
	   ══════════════════════════════════════════════ */
	(function() {
		var tbody = gid('ka-titles-tbody');
		if (!tbody) return;

		function makeTitleRow(pt) {
			var tr = document.createElement('tr');
			tr.dataset.titleId = pt.ParkTitleId;
			function makeCell(type, field, val) {
				var td = document.createElement('td');
				var inp = document.createElement('input');
				inp.type = type;
						inp.style.cssText = type === 'number' ? 'width:56px;padding:4px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px;text-align:center' : 'width:100%;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
				inp.value = val;
				if (type === 'number') inp.min = '0';
				inp.dataset.field = field;
				td.appendChild(inp);
				return td;
			}
			var periodTd = document.createElement('td');
			var sel = document.createElement('select');
			sel.style.cssText = 'padding:4px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
			sel.dataset.field = 'Period';
			['month','week'].forEach(function(v) {
				var o = document.createElement('option');
				o.value = v; o.textContent = v.charAt(0).toUpperCase() + v.slice(1);
				if (v === pt.Period) o.selected = true;
				sel.appendChild(o);
			});
			periodTd.appendChild(sel);

			var delTd = document.createElement('td');
			var delBtn = document.createElement('button');
			delBtn.className = 'ka-tdel';
			delBtn.innerHTML = '<i class="fas fa-trash"></i>';
			delBtn.title = 'Delete';
			(function(row, titleName, titleId) {
				delBtn.addEventListener('click', function() {
					kaConfirm('Delete "' + titleName + '"?', function() {
						delBtn.disabled = true;
						kaPost(BASE_URL + 'deletetitle', { ParkTitleId: titleId }, null, 'ka-titles-feedback', function() {
							row.parentNode && row.parentNode.removeChild(row);
							kaFeedback('ka-titles-feedback', 'Title deleted.', true);
						});
					}, 'Delete Title');
				});
			})(tr, pt.Title, pt.ParkTitleId);
			delTd.appendChild(delBtn);

			tr.appendChild(makeCell('text', 'Title', pt.Title));
			tr.appendChild(makeCell('number', 'Class', pt.Class));
			tr.appendChild(makeCell('number', 'MinimumAttendance', pt.MinimumAttendance));
			tr.appendChild(makeCell('number', 'MinimumCutoff', pt.MinimumCutoff));
			tr.appendChild(periodTd);
			tr.appendChild(makeCell('number', 'Length', pt.Length));
			tr.appendChild(delTd);
			return tr;
		}

		// Build initial rows
		(KaConfig.adminParkTitles || []).forEach(function(pt) { tbody.appendChild(makeTitleRow(pt)); });

		var btn = gid('ka-titles-save');
		if (btn) {
			btn.addEventListener('click', function() {
				kaClearFeedback('ka-titles-feedback');
				var data = {};
				tbody.querySelectorAll('tr').forEach(function(row) {
					var id = row.dataset.titleId;
					row.querySelectorAll('[data-field]').forEach(function(inp) { data[inp.dataset.field + '[' + id + ']'] = inp.value; });
				});
				var newTitle = document.querySelector('#ka-titles-table tfoot [data-field="Title"]');
				if (newTitle && newTitle.value.trim()) {
					document.querySelectorAll('#ka-titles-table tfoot [data-field]').forEach(function(inp) { data[inp.dataset.field + '[New]'] = inp.value; });
				}
				if (!Object.keys(data).length) { kaFeedback('ka-titles-feedback', 'No data to save.', false); return; }
				btn.disabled = true;
				var fd = new FormData();
				Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
				fetch(BASE_URL + 'setparktitles', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					btn.disabled = false;
					if (r && r.status === 0) {
						kaFeedback('ka-titles-feedback', 'Park titles saved!', true);
						document.querySelectorAll('#ka-titles-table tfoot [data-field]').forEach(function(inp) {
							inp.value = (inp.dataset.field === 'Length') ? '1' : (inp.type === 'number' ? '0' : '');
						});
					} else { kaFeedback('ka-titles-feedback', (r && r.error) ? r.error : 'Save failed.', false); }
				})
				.catch(function() { btn.disabled = false; kaFeedback('ka-titles-feedback', 'Request failed.', false); });
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   EDIT PARKS
	   ══════════════════════════════════════════════ */
	(function() {
		var tbody = gid('ka-parks-tbody');
		if (!tbody) return;

		function makeParkRow(park) {
			var tr = document.createElement('tr');
			tr.dataset.parkId = park.ParkId;
			if (park.Active !== 'Active') tr.classList.add('ka-admin-park-retired');

			var nameTd = document.createElement('td');
			var nameInp = document.createElement('input');
			nameInp.type = 'text';
			nameInp.style.cssText = 'width:100%;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
			nameInp.value = park.Name || '';
			nameInp.dataset.field = 'ParkName';
			nameTd.appendChild(nameInp);

			var titleTd = document.createElement('td');
			var sel = document.createElement('select');
			sel.style.cssText = 'padding:4px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
			sel.dataset.field = 'ParkTitle';
			var opts = KaConfig.parkTitleOptions || {};
			Object.keys(opts).forEach(function(tid) {
				var o = document.createElement('option');
				o.value = tid; o.textContent = opts[tid];
				if (parseInt(tid) === park.ParkTitleId) o.selected = true;
				sel.appendChild(o);
			});
			titleTd.appendChild(sel);

			var abbrTd = document.createElement('td');
			var abbrInp = document.createElement('input');
			abbrInp.type = 'text';
			abbrInp.style.cssText = 'width:60px;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
			abbrInp.value = park.Abbreviation || '';
			abbrInp.maxLength = 3;
			abbrInp.dataset.field = 'Abbreviation';
			abbrTd.appendChild(abbrInp);

			var activeTd = document.createElement('td');
			activeTd.style.textAlign = 'center';
			var label = document.createElement('label');
			label.className = 'ka-toggle';
			var chk = document.createElement('input');
			chk.type = 'checkbox';
			chk.checked = (park.Active === 'Active');
			chk.dataset.field = 'Active';
			chk.addEventListener('change', function() { tr.classList.toggle('ka-admin-park-retired', !chk.checked); });
			var track = document.createElement('span');
			track.className = 'ka-toggle-track';
			label.appendChild(chk);
			label.appendChild(track);
			activeTd.appendChild(label);

			var viewTd = document.createElement('td');
			var viewA = document.createElement('a');
			viewA.href = UIR + 'Park/profile/' + park.ParkId;
			viewA.target = '_blank';
			viewA.title = 'View ' + (park.Name || '');
			viewA.innerHTML = '<i class="fas fa-external-link-alt" style="color:#a0aec0"></i>';
			viewTd.appendChild(viewA);

			tr.appendChild(nameTd);
			tr.appendChild(titleTd);
			tr.appendChild(abbrTd);
			tr.appendChild(activeTd);
			tr.appendChild(viewTd);
			return tr;
		}

		var parks = (KaConfig.parkEditLookup || []).slice();
		parks.sort(function(a, b) { return (a.Name || '').localeCompare(b.Name || ''); });
		parks.forEach(function(park) { tbody.appendChild(makeParkRow(park)); });

		var btn = gid('ka-parks-save');
		if (btn) {
			btn.addEventListener('click', function() {
				kaClearFeedback('ka-parks-feedback');
				var parks = [];
				tbody.querySelectorAll('tr').forEach(function(row) {
					var pid = parseInt(row.dataset.parkId, 10);
					if (!pid) return;
					var p = { ParkId: pid };
					row.querySelectorAll('[data-field]').forEach(function(inp) {
						p[inp.dataset.field] = (inp.type === 'checkbox') ? (inp.checked ? 'YES' : '') : inp.value;
					});
					parks.push(p);
				});
				if (!parks.length) { kaFeedback('ka-parks-feedback', 'No data to save.', false); return; }
				btn.disabled = true;
				var fd = new FormData();
				fd.append('ParksJson', JSON.stringify(parks));
				fetch(BASE_URL + 'updateparks', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					btn.disabled = false;
					if (r && r.status === 0) kaFeedback('ka-parks-feedback', 'Parks saved!', true);
					else kaFeedback('ka-parks-feedback', (r && r.error) ? r.error : 'Save failed.', false);
				})
				.catch(function() { btn.disabled = false; kaFeedback('ka-parks-feedback', 'Request failed.', false); });
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   AWARDS
	   ══════════════════════════════════════════════ */
	(function() {
		var tbody = gid('ka-awards-tbody');
		if (!tbody) return;

		function makeAwardRow(aw) {
			var tr = document.createElement('tr');
			function ntd(isText, val) {
				var td = document.createElement('td');
				var inp = document.createElement('input');
				inp.type = isText ? 'text' : 'number';
				inp.style.cssText = isText ? 'width:100%;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px' : 'width:56px;padding:4px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px;text-align:center';
				inp.value = val;
				if (!isText) inp.min = '0';
				td.appendChild(inp);
				return { td: td, inp: inp };
			}
			var nameCell = ntd(true, aw.KingdomAwardName);
			var sysName = aw.AwardName || '';
			if (sysName && sysName !== aw.KingdomAwardName) {
				var hint = document.createElement('span');
				hint.className = 'ka-alias-hint';
				hint.innerHTML = '<i class="fas fa-question-circle"></i>';
				hint.title = 'Alias for system award: ' + sysName;
				nameCell.td.appendChild(hint);
			}
			var reignCell = ntd(false, aw.ReignLimit);
			var monthCell = ntd(false, aw.MonthLimit);
			var titleTd = document.createElement('td');
			titleTd.style.textAlign = 'center';
			var titleCb = document.createElement('input');
			titleCb.type = 'checkbox';
			titleCb.checked = (aw.IsTitle === 1);
			titleTd.appendChild(titleCb);
			var classCell = ntd(false, aw.TitleClass);
			classCell.inp.disabled = !titleCb.checked;
			titleCb.addEventListener('change', function() { classCell.inp.disabled = !this.checked; });

			var actionsTd = document.createElement('td');
			actionsTd.style.whiteSpace = 'nowrap';
			var saveBtn = document.createElement('button');
			saveBtn.className = 'ka-tsave';
			saveBtn.innerHTML = '<i class="fas fa-save"></i>';
			saveBtn.title = 'Save';
			saveBtn.style.marginRight = '4px';
			(function(btn, nc, rc, mc, cb, cc, kawId) {
				btn.addEventListener('click', function() {
					kaClearFeedback('ka-awards-feedback');
					btn.disabled = true;
					kaPost(BASE_URL + 'setaward', {
						KingdomAwardId: kawId, KingdomAwardName: nc.value.trim(),
						ReignLimit: rc.value, MonthLimit: mc.value,
						IsTitle: cb.checked ? 1 : 0, TitleClass: cc.value
					}, null, 'ka-awards-feedback', function() {
						btn.disabled = false;
						kaFeedback('ka-awards-feedback', 'Award saved!', true);
					});
				});
			})(saveBtn, nameCell.inp, reignCell.inp, monthCell.inp, titleCb, classCell.inp, aw.KingdomAwardId);

			var delBtn = document.createElement('button');
			delBtn.className = 'ka-tdel';
			delBtn.innerHTML = '<i class="fas fa-trash"></i>';
			delBtn.title = 'Delete';
			(function(btn, row, kawId, awName) {
				btn.addEventListener('click', function() {
					kaConfirm('Delete award "' + awName + '"? This cannot be undone.', function() {
						btn.disabled = true;
						kaPost(BASE_URL + 'deleteaward', { KingdomAwardId: kawId }, null, 'ka-awards-feedback', function() {
							row.parentNode && row.parentNode.removeChild(row);
							kaFeedback('ka-awards-feedback', 'Award deleted.', true);
						});
					}, 'Delete Award');
				});
			})(delBtn, tr, aw.KingdomAwardId, aw.KingdomAwardName);

			actionsTd.appendChild(saveBtn);
			actionsTd.appendChild(delBtn);
			tr.appendChild(nameCell.td);
			tr.appendChild(reignCell.td);
			tr.appendChild(monthCell.td);
			tr.appendChild(titleTd);
			tr.appendChild(classCell.td);
			tr.appendChild(actionsTd);
			return tr;
		}

		// Group awards using same logic as model.Award.php
		function classifyAward(aw) {
			var sysName = aw.AwardName || aw.KingdomAwardName || '';
			if (aw.AwardId === 0) return 'Kingdom-Specific';
			if (sysName === 'Custom Award') return 'Kingdom-Specific';
			if (aw.IsLadder) return 'Ladder Awards';
			if (sysName === 'Defender' || sysName === 'Master') return 'Noble Titles';
			if (sysName === 'Weaponmaster') return 'Offices & Other';
			if (aw.Peerage === 'Knight') return 'Knighthoods';
			if (aw.Peerage === 'Paragon') return 'Paragons';
			if (aw.Peerage === 'Master' || (aw.IsTitle && aw.TitleClass === 10)) return 'Masterhoods';
			if (['Squire','Man-At-Arms','Page','Lords-Page'].indexOf(aw.Peerage) >= 0 || sysName === 'Apprentice') return 'Associate Titles';
			if ((aw.IsTitle && aw.TitleClass >= 30) || sysName === 'Esquire') return 'Noble Titles';
			return 'Offices & Other';
		}

		var groupOrder = ['Ladder Awards','Kingdom-Specific','Knighthoods','Masterhoods','Paragons','Noble Titles','Associate Titles','Offices & Other'];
		var groups = {};
		groupOrder.forEach(function(g) { groups[g] = []; });
		(KaConfig.adminAwards || []).forEach(function(aw) {
			var g = classifyAward(aw);
			if (!groups[g]) groups[g] = [];
			groups[g].push(aw);
		});

		groupOrder.forEach(function(groupName) {
			var items = groups[groupName];
			if (!items || !items.length) return;
			// Group header row
			var hdr = document.createElement('tr');
			hdr.className = 'ka-award-group-hdr';
			var hdrTd = document.createElement('td');
			hdrTd.colSpan = 6;
			hdrTd.innerHTML = '<i class="fas fa-chevron-down ka-award-group-chev"></i>' + groupName + '<span class="ka-award-group-count">(' + items.length + ')</span>';
			hdr.appendChild(hdrTd);
			tbody.appendChild(hdr);
			// Award rows for this group
			var rowEls = [];
			items.forEach(function(aw) {
				var row = makeAwardRow(aw);
				tbody.appendChild(row);
				rowEls.push(row);
			});
			// Toggle collapse
			hdr.addEventListener('click', function() {
				var collapsed = hdr.classList.toggle('ka-collapsed');
				rowEls.forEach(function(r) { r.style.display = collapsed ? 'none' : ''; });
			});
		});

		// Award alias / custom add forms
		var addBtn = gid('ka-awards-add-btn'), addWrap = gid('ka-add-award-wrap'), addCancel = gid('ka-new-award-cancel');
		var customBtn = gid('ka-custom-add-btn'), customWrap = gid('ka-add-custom-wrap'), customCancel = gid('ka-custom-cancel');
		var btnRow = gid('ka-award-add-btns');

		function showAliasForm() { if (addWrap) addWrap.style.display = ''; if (customWrap) customWrap.style.display = 'none'; if (btnRow) btnRow.style.display = 'none'; }
		function showCustomForm() { if (customWrap) customWrap.style.display = ''; if (addWrap) addWrap.style.display = 'none'; if (btnRow) btnRow.style.display = 'none'; }
		function showButtons() { if (addWrap) addWrap.style.display = 'none'; if (customWrap) customWrap.style.display = 'none'; if (btnRow) btnRow.style.display = ''; }

		if (addBtn) addBtn.addEventListener('click', showAliasForm);
		if (customBtn) customBtn.addEventListener('click', showCustomForm);
		if (addCancel) addCancel.addEventListener('click', showButtons);
		if (customCancel) customCancel.addEventListener('click', showButtons);

		// Title checkbox toggles
		var newIsTitleCb = gid('ka-new-istitle'), newTClassInp = gid('ka-new-tclass');
		if (newIsTitleCb && newTClassInp) newIsTitleCb.addEventListener('change', function() { newTClassInp.disabled = !this.checked; });
		var customIsTitleCb = gid('ka-custom-istitle'), customTClassInp = gid('ka-custom-tclass');
		if (customIsTitleCb && customTClassInp) customIsTitleCb.addEventListener('change', function() { customTClassInp.disabled = !this.checked; });

		// System award alias dropdown
		var trigger = gid('ka-alias-trigger'), dropdown = gid('ka-alias-dropdown'), searchInp = gid('ka-alias-search');
		var listEl = gid('ka-alias-list'), hiddenInp = gid('ka-new-award-id'), nameInp = gid('ka-new-award-name');
		var labelSpan = gid('ka-alias-label');
		var sysAwards = KaConfig.systemAwards || [];
		var aliasOpen = false;

		function buildAliasList(filter) {
			if (!listEl) return;
			listEl.innerHTML = '';
			var lc = (filter || '').toLowerCase(), count = 0;
			sysAwards.forEach(function(sa) {
				if (lc && sa.Name.toLowerCase().indexOf(lc) === -1) return;
				var div = document.createElement('div');
				div.className = 'ka-alias-item';
				div.textContent = sa.Name;
				div.addEventListener('click', function() { selectAlias(sa.AwardId, sa.Name); });
				listEl.appendChild(div);
				count++;
			});
			if (!count) { var empty = document.createElement('div'); empty.className = 'ka-alias-empty'; empty.textContent = 'No matching awards'; listEl.appendChild(empty); }
		}
		function selectAlias(id, name) {
			if (hiddenInp) hiddenInp.value = id;
			if (labelSpan) { labelSpan.textContent = name; }
			if (nameInp && !nameInp.value.trim()) nameInp.value = name;
			closeAlias();
		}
		function openAlias() { if (!dropdown || aliasOpen) return; aliasOpen = true; dropdown.style.display = ''; buildAliasList(''); if (searchInp) { searchInp.value = ''; searchInp.focus(); } }
		function closeAlias() { if (!dropdown) return; aliasOpen = false; dropdown.style.display = 'none'; }
		if (trigger) trigger.addEventListener('click', function(e) { e.preventDefault(); aliasOpen ? closeAlias() : openAlias(); });
		if (searchInp) { searchInp.addEventListener('input', function() { buildAliasList(this.value); }); searchInp.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAlias(); }); }
		document.addEventListener('click', function(e) { if (aliasOpen && trigger && dropdown && !trigger.contains(e.target) && !dropdown.contains(e.target)) closeAlias(); });

		// Save new alias
		var saveNewBtn = gid('ka-new-award-save');
		if (saveNewBtn) {
			saveNewBtn.addEventListener('click', function() {
				kaClearFeedback('ka-awards-feedback');
				var awardId = parseInt((hiddenInp ? hiddenInp.value : '0') || '0', 10);
				var name = (nameInp ? nameInp.value : '').trim();
				if (!awardId) { kaFeedback('ka-awards-feedback', 'Please select a system award.', false); return; }
				if (!name) { kaFeedback('ka-awards-feedback', 'Award name is required.', false); return; }
				saveNewBtn.disabled = true;
				kaPost(BASE_URL + 'setaward', {
					KingdomAwardId: 0, AwardId: awardId, KingdomAwardName: name,
					ReignLimit: gid('ka-new-reign').value, MonthLimit: gid('ka-new-month').value,
					IsTitle: gid('ka-new-istitle').checked ? 1 : 0, TitleClass: gid('ka-new-tclass').value
				}, null, 'ka-awards-feedback', function() {
					saveNewBtn.disabled = false;
					kaFeedback('ka-awards-feedback', 'Award alias created!', true);
					setTimeout(function() { location.reload(); }, 900);
				});
			});
		}

		// Save custom award
		var saveCustomBtn = gid('ka-custom-save');
		if (saveCustomBtn) {
			saveCustomBtn.addEventListener('click', function() {
				kaClearFeedback('ka-awards-feedback');
				var name = (gid('ka-custom-name').value || '').trim();
				if (!name) { kaFeedback('ka-awards-feedback', 'Award name is required.', false); return; }
				saveCustomBtn.disabled = true;
				kaPost(BASE_URL + 'setaward', {
					KingdomAwardId: 0, AwardId: 0, KingdomAwardName: name,
					ReignLimit: gid('ka-custom-reign').value, MonthLimit: gid('ka-custom-month').value,
					IsTitle: gid('ka-custom-istitle').checked ? 1 : 0, TitleClass: gid('ka-custom-tclass').value
				}, null, 'ka-awards-feedback', function() {
					saveCustomBtn.disabled = false;
					kaFeedback('ka-awards-feedback', 'Kingdom-specific award created!', true);
					setTimeout(function() { location.reload(); }, 900);
				});
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   CREATE PLAYER
	   ══════════════════════════════════════════════ */
	(function() {
		var btn = gid('ka-cp-submit');
		if (!btn) return;
		btn.addEventListener('click', function() {
			var parkId   = gid('ka-cp-park').value;
			var persona  = gid('ka-cp-persona').value.trim();
			var username = gid('ka-cp-username').value.trim();
			var password = gid('ka-cp-password').value;
			if (!parkId)             { kaFeedback('ka-cp-feedback', 'Please select a home park.', false); return; }
			if (!persona)            { kaFeedback('ka-cp-feedback', 'Persona is required.', false); return; }
			if (!username)           { kaFeedback('ka-cp-feedback', 'Username is required.', false); return; }
			if (username.length < 4) { kaFeedback('ka-cp-feedback', 'Username must be at least 4 characters.', false); return; }
			var restricted = document.querySelector('input[name="ka-cp-restricted"]:checked');
			var waivered   = document.querySelector('input[name="ka-cp-waivered"]:checked');
			kaPost(UIR + 'PlayerAjax/park/' + parkId + '/create', {
				Persona: persona,
				GivenName: gid('ka-cp-given').value.trim(),
				Surname: gid('ka-cp-surname').value.trim(),
				Email: gid('ka-cp-email').value.trim(),
				UserName: username,
				Password: password,
				Restricted: restricted ? restricted.value : '0',
				Waivered: waivered ? waivered.value : '0',
			}, btn, 'ka-cp-feedback', function(r) {
				window.location.href = UIR + 'Player/profile/' + r.mundaneId;
			});
		});
	})();

	/* ══════════════════════════════════════════════
	   MOVE PLAYER
	   ══════════════════════════════════════════════ */
	(function() {
		function mpCheck() {
			var pid = gid('ka-mp-player-id').value;
			var pkid = gid('ka-mp-park-id').value;
			gid('ka-mp-submit').disabled = !(pid && pkid);
		}
		kaAc({ inputId:'ka-mp-player-name', hiddenId:'ka-mp-player-id', resultsId:'ka-mp-player-results',
			fetchFn: kaSearchPlayers, onSelect: mpCheck, onClear: mpCheck });
		kaAc({ inputId:'ka-mp-park-name', hiddenId:'ka-mp-park-id', resultsId:'ka-mp-park-results',
			fetchFn: kaSearchParks, onSelect: mpCheck, onClear: mpCheck });

		var btn = gid('ka-mp-submit');
		if (!btn) return;
		btn.addEventListener('click', function() {
			var playerId = gid('ka-mp-player-id').value;
			var parkId   = gid('ka-mp-park-id').value;
			if (!playerId || !parkId) return;
			kaPost(UIR + 'PlayerAjax/player/' + playerId + '/moveplayer', { ParkId: parkId },
				btn, 'ka-mp-feedback', function() {
					kaFeedback('ka-mp-feedback',
						'Player moved successfully. <a href="' + UIR + 'Park/profile/' + parkId + '">View park</a> &middot; <a href="' + UIR + 'Player/profile/' + playerId + '">View player</a>', true);
					['ka-mp-player-name','ka-mp-park-name'].forEach(function(id){gid(id).value='';});
					['ka-mp-player-id','ka-mp-park-id'].forEach(function(id){gid(id).value='';});
					btn.disabled = true;
				});
		});
	})();

	/* ══════════════════════════════════════════════
	   MERGE PLAYERS
	   ══════════════════════════════════════════════ */
	(function() {
		function mgpCheck() {
			var keep   = gid('ka-mgp-keep-id').value;
			var remove = gid('ka-mgp-remove-id').value;
			gid('ka-mgp-submit').disabled = !(keep && remove);
		}
		kaAc({ inputId:'ka-mgp-keep-name', hiddenId:'ka-mgp-keep-id', resultsId:'ka-mgp-keep-results',
			fetchFn: kaSearchPlayers, onSelect: mgpCheck, onClear: mgpCheck });
		kaAc({ inputId:'ka-mgp-remove-name', hiddenId:'ka-mgp-remove-id', resultsId:'ka-mgp-remove-results',
			fetchFn: kaSearchPlayers, onSelect: mgpCheck, onClear: mgpCheck });

		var btn = gid('ka-mgp-submit');
		if (!btn) return;
		btn.addEventListener('click', function() {
			var keepId   = gid('ka-mgp-keep-id').value;
			var removeId = gid('ka-mgp-remove-id').value;
			if (!keepId || !removeId) return;
			if (keepId === removeId) { kaFeedback('ka-mgp-feedback', 'Cannot merge a player with themselves.', false); return; }
			kaPost(UIR + 'PlayerAjax/merge', { ToMundaneId: keepId, FromMundaneId: removeId },
				btn, 'ka-mgp-feedback', function() {
					window.location.href = UIR + 'Player/profile/' + keepId;
				});
		});
	})();

	/* ══════════════════════════════════════════════
	   OPERATIONS
	   ══════════════════════════════════════════════ */
	(function() {
		// Reset Waivers
		var resetBtn = gid('ka-ops-reset-waivers');
		if (resetBtn) {
			resetBtn.addEventListener('click', function() {
				kaConfirm('This will reset all waivers. This action cannot be undone.', function() {
					resetBtn.disabled = true;
					kaPost(BASE_URL + 'resetwaivers', {}, null, 'ka-ops-feedback', function(r) {
						resetBtn.disabled = false;
						kaFeedback('ka-ops-feedback', r.message || 'Waivers reset.', true);
					});
				}, 'Reset Waivers');
			});
		}

		// Active Status toggle
		var statusBtn = gid('ka-ops-status-toggle');
		if (statusBtn) {
			statusBtn.addEventListener('click', function() {
				var isActive = statusBtn.dataset.active === '1';
				var newActive = isActive ? 'Retired' : 'Active';
				var label = isActive ? 'mark this as inactive' : 'restore this to active';
				kaConfirm('Are you sure you want to ' + label + '?', function() {
					kaClearFeedback('ka-ops-feedback');
					var fd = new FormData();
					fd.append('Active', newActive);
					fetch(BASE_URL + 'setstatus', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(r) {
						if (r && r.status === 0) {
							statusBtn.dataset.active = newActive === 'Active' ? '1' : '0';
							gid('ka-ops-status-label').textContent = newActive === 'Active' ? 'Active' : 'Inactive';
							if (newActive === 'Active') {
								statusBtn.innerHTML = '<i class="fas fa-ban"></i> Mark Inactive';
								statusBtn.classList.add('ka-ops-btn-danger');
							} else {
								statusBtn.innerHTML = '<i class="fas fa-check-circle"></i> Restore to Active';
								statusBtn.classList.remove('ka-ops-btn-danger');
							}
							kaFeedback('ka-ops-feedback', newActive === 'Active' ? 'Restored to active.' : 'Marked inactive.', true);
						} else { kaFeedback('ka-ops-feedback', (r && r.error) ? r.error : 'Request failed.', false); }
					})
					.catch(function() { kaFeedback('ka-ops-feedback', 'Request failed.', false); });
				}, newActive === 'Active' ? 'Restore' : 'Mark Inactive');
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   PRINCIPALITY STATUS
	   ══════════════════════════════════════════════ */
	(function() {
		if (!KaConfig.isOrkAdmin) return;

		kaAc({ inputId:'ka-prinz-parent-name', hiddenId:'ka-prinz-parent-id', resultsId:'ka-prinz-parent-results',
			fetchFn: kaSearchKingdoms });

		function doSetParent(newParentId, successMsg) {
			var fd = new FormData();
			fd.append('ParentKingdomId', newParentId);
			fetch(BASE_URL + 'setparent', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(r) {
				if (r && r.status === 0) kaFeedback('ka-prinz-feedback', successMsg, true);
				else kaFeedback('ka-prinz-feedback', (r && r.error) ? r.error : 'Save failed.', false);
			})
			.catch(function() { kaFeedback('ka-prinz-feedback', 'Request failed.', false); });
		}

		var sponsorBtn = gid('ka-prinz-sponsor-save');
		if (sponsorBtn) {
			sponsorBtn.addEventListener('click', function() {
				kaClearFeedback('ka-prinz-feedback');
				var newParentId = parseInt(gid('ka-prinz-parent-id').value || '0', 10);
				if (!newParentId) { kaFeedback('ka-prinz-feedback', 'Please select a sponsor kingdom.', false); return; }
				doSetParent(newParentId, 'Sponsor kingdom updated.');
			});
		}

		var promoteBtn = gid('ka-prinz-promote');
		if (promoteBtn) {
			promoteBtn.addEventListener('click', function() {
				kaConfirm('Remove this principality\'s sponsor and make it a full kingdom?', function() {
					kaClearFeedback('ka-prinz-feedback');
					doSetParent(0, 'Converted to kingdom. Reload to see updated status.');
				}, 'Convert to Kingdom');
			});
		}
	})();

})();
</script>
