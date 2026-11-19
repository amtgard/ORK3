<?php
/* -----------------------------------------------------------------------
   Park Admin -- modal layer and page script.

   The park counterpart of partials/_kingdom_admin_modals.tpl, built on the
   SAME two shared pieces so the two consoles cannot drift apart:
     partials/_ka_modal_chrome.tpl  the .ka-* modal CSS
     partials/_ka_modal_core.tpl    the overlay stack, focus trap, dirty
                                    guard, save-or-discard prompt, kaConfirm
   Nothing in this file reimplements either. Every modal that needs
   reset-on-open, an open hook or a save hook registers it through
   kaRegisterModal(); the rest run on the engine's defaults.

   Included from Admin_park.tpl, so it runs in that page's variable scope:
   $parkId, $parkName, $kingdomId, $hasHeraldry, $heraldryUrl, $pkDetails,
   $pkDays, $ParkAdminDashboard, $CanResetWaivers and $mo_* must all be set
   before the include.

   Every write goes to an endpoint that already exists -- ParkAjax/park/{id}/*
   and PlayerAjax -- so the officer stays on the console instead of being
   bounced to the legacy Admin/editpark form and back.
   ----------------------------------------------------------------------- */

/* Blast radius for Reset Waivers. Park::GetAdminDashboard() counts ork_mundane
   rows with park_id = this park AND waivered = 1, with NO active filter --
   exactly the scope of the UPDATE Player::ResetWaivers runs -- so this is the
   true number of players the button clears, not an estimate.

   null (rather than 0) when the dashboard never supplied the key: "0 players"
   and "we could not work it out" are different statements, and only the first
   of them justifies disabling the button. */
$_pkaQueue    = is_array($ParkAdminDashboard['Queue'] ?? null) ? $ParkAdminDashboard['Queue'] : [];
$_pkaWaivered = array_key_exists('WaiveredMembers', $_pkaQueue) ? (int)$_pkaQueue['WaiveredMembers'] : null;

/* Park::GetParkDetails() runs Description and Directions through nl2br(), so
   what comes back has <br /> tags interleaved with the newlines that produced
   them. A textarea has to show the SOURCE: leave the tags in and every save
   round-trips another layer of them into the column. Same strip the park
   profile does with the same three spellings. */
$_pkaPlain = static function ($s) {
    return trim(str_replace(['<br />', '<br/>', '<br>'], '', (string)$s));
};

/* Park-day display helpers. Kept here rather than in the JS so the list is
   readable in the page source and correct with scripting off. */
$_pkaOrdinal = static function ($n) {
    $n = (int)$n;
    $suffix = 'th';
    if ($n % 100 < 11 || $n % 100 > 13) {
        $suffix = [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th';
    }
    return $n . $suffix;
};
$_pkaPurposes = [
    'park-day'         => 'Regular Park Day',
    'fighter-practice' => 'Fighter Practice',
    'arts-day'         => 'A&S Day',
    'other'            => 'Other',
];
$_pkaWhen = static function (array $d) use ($_pkaOrdinal) {
    switch ((string)($d['Recurrence'] ?? '')) {
        case 'weekly':
            return 'Every ' . (string)($d['WeekDay'] ?? '');
        case 'week-of-month':
            return 'Every ' . $_pkaOrdinal($d['WeekOfMonth'] ?? 0) . ' ' . (string)($d['WeekDay'] ?? '');
        case 'monthly':
            return 'Monthly on the ' . $_pkaOrdinal($d['MonthDay'] ?? 0);
        case 'every-x-weeks':
            $n = max(2, (int)($d['WeekInterval'] ?? 0));
            // '1000-01-01' is the NOT-NULL sentinel non-interval rows carry.
            $sd = substr((string)($d['StartDate'] ?? ''), 0, 10);
            $from = ($sd !== '' && strpos($sd, '1000-01-01') === false)
                ? ' from ' . date('M j, Y', strtotime($sd))
                : '';
            return 'Every ' . $n . ' weeks' . $from;
    }
    return 'Unscheduled';
};
/* Times are stored as a TIME column ('14:00:00'). Never print that at an
   officer: house rule is human-readable date and time everywhere. */
$_pkaTime = static function ($t) {
    $t = trim((string)$t);
    if ($t === '' || $t === '00:00:00') {
        return 'No set time';
    }
    $ts = strtotime('1970-01-01 ' . $t);
    return $ts === false ? $t : date('g:i A', $ts);
};
?>

<?php
/* The shared .ka-* modal chrome. Same block the kingdom console emits, from
   the same file, at the same point in the body -- see the partial's own header
   for why it stays a <style> element rather than moving into admin-console.css. */
include __DIR__ . '/_ka_modal_chrome.tpl';
?>

<style>
/* =============================================
   PARK DAYS -- the one thing on this console with no kingdom counterpart
   =============================================
   Two rules, both for the recurrence form's conditional rows. Everything else
   this modal wears (.ka-inset-panel, .ka-admin-table, .ka-table-empty,
   .ka-field-row, .ka-add-btn) already exists in admin-console.css. */

/* The recurrence picker swaps which fields apply -- a weekly day needs a
   weekday, a monthly one needs a day of the month. Rows are shown/hidden by
   the script; this only supplies the grid so three short controls sit on one
   line instead of stacking to three full-width rows. */
.ka-pd-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
@media (max-width: 600px) { .ka-pd-grid { grid-template-columns: 1fr; } }
</style>

<!-- =============================================
     MODALS
     ============================================= -->

<!-- ---- Edit Park Details ---- -->
<div class="ka-overlay" id="ka-details-overlay">
	<div class="ka-modal-box ka-modal-box-lg">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-edit" style="margin-right:8px;color:#2b6cb0"></i>Edit Park Details</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-details-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-details-feedback"></div>
			<!-- Name, abbreviation, title and active status are NOT here. Those are
			     kingdom-level decisions -- they live on the kingdom console's Edit
			     Parks modal, and Park::SetParkDetails does not accept them. Saying so
			     beats leaving an officer hunting this modal for a rename button. -->
			<p class="ka-form-hint">
				The park&rsquo;s name, abbreviation, title and active status are set by the kingdom, under
				<strong>Edit Parks</strong> on the kingdom console. Everything below is yours.
			</p>
			<div class="ka-field">
				<label for="ka-details-url">Website URL <span class="ka-hint">(optional)</span></label>
				<input type="url" id="ka-details-url" placeholder="https://"
					value="<?= htmlspecialchars((string)($pkDetails['Url'] ?? '')) ?>"
					data-original="<?= htmlspecialchars((string)($pkDetails['Url'] ?? '')) ?>">
			</div>
			<div class="ka-field">
				<label for="ka-details-address">Street Address</label>
				<input type="text" id="ka-details-address" placeholder="123 Main St"
					value="<?= htmlspecialchars((string)($pkDetails['Address'] ?? '')) ?>"
					data-original="<?= htmlspecialchars((string)($pkDetails['Address'] ?? '')) ?>">
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label for="ka-details-city">City</label>
					<input type="text" id="ka-details-city"
						value="<?= htmlspecialchars((string)($pkDetails['City'] ?? '')) ?>"
						data-original="<?= htmlspecialchars((string)($pkDetails['City'] ?? '')) ?>">
				</div>
				<div class="ka-field">
					<label for="ka-details-province">State / Province</label>
					<input type="text" id="ka-details-province"
						value="<?= htmlspecialchars((string)($pkDetails['Province'] ?? '')) ?>"
						data-original="<?= htmlspecialchars((string)($pkDetails['Province'] ?? '')) ?>">
				</div>
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label for="ka-details-postal">Postal Code</label>
					<input type="text" id="ka-details-postal"
						value="<?= htmlspecialchars((string)($pkDetails['PostalCode'] ?? '')) ?>"
						data-original="<?= htmlspecialchars((string)($pkDetails['PostalCode'] ?? '')) ?>">
				</div>
				<div class="ka-field">
					<label for="ka-details-mapurl">Map URL <span class="ka-hint">(optional)</span></label>
					<input type="url" id="ka-details-mapurl" placeholder="Google Maps link"
						value="<?= htmlspecialchars((string)($pkDetails['MapUrl'] ?? '')) ?>"
						data-original="<?= htmlspecialchars((string)($pkDetails['MapUrl'] ?? '')) ?>">
				</div>
			</div>
			<div class="ka-field">
				<label for="ka-details-description">Description <span class="ka-hint">(optional &mdash; Markdown supported)</span></label>
				<textarea id="ka-details-description" rows="4" style="resize:vertical"
					data-original="<?= htmlspecialchars($_pkaPlain($pkDetails['Description'] ?? '')) ?>"><?= htmlspecialchars($_pkaPlain($pkDetails['Description'] ?? '')) ?></textarea>
			</div>
			<div class="ka-field">
				<label for="ka-details-directions">Directions <span class="ka-hint">(optional &mdash; Markdown supported)</span></label>
				<textarea id="ka-details-directions" rows="3" style="resize:vertical"
					data-original="<?= htmlspecialchars($_pkaPlain($pkDetails['Directions'] ?? '')) ?>"><?= htmlspecialchars($_pkaPlain($pkDetails['Directions'] ?? '')) ?></textarea>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-details-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-details-save"><i class="fas fa-save"></i> Save Details</button>
		</div>
	</div>
</div>

<!-- ---- Heraldry ---- -->
<div class="ka-overlay" id="ka-heraldry-overlay">
	<div class="ka-modal-box ka-modal-box-sm">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-image ka-icon-brand" style="margin-right:8px"></i>Park Heraldry</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-heraldry-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-heraldry-feedback"></div>
			<figure class="ka-her-figure">
				<!-- alt + caption distinguish "this is your heraldry" from "this is the
				     placeholder every park without heraldry shows". They looked
				     identical before, which made Remove feel safe when it was not. -->
				<img id="ka-heraldry-preview" class="ka-her-preview"
					src="<?= htmlspecialchars($heraldryUrl) ?>"
					alt="<?= $hasHeraldry ? 'Current park heraldry' : 'Generic placeholder shield &mdash; no heraldry uploaded' ?>">
				<figcaption class="ka-her-caption ka-muted" id="ka-heraldry-caption"><?= $hasHeraldry ? 'Current heraldry' : 'No heraldry uploaded &mdash; the generic shield is shown' ?></figcaption>
				<?php if ($hasHeraldry): ?>
				<!-- Remove deletes the file outright with no copy kept anywhere, so
				     this link is what makes Remove a survivable action. -->
				<div class="ka-her-actions">
					<a href="<?= htmlspecialchars($heraldryUrl) ?>" download target="_blank" rel="noopener"><i class="fas fa-download" aria-hidden="true"></i> Download current image</a>
				</div>
				<?php endif; ?>
			</figure>
			<div class="ka-field">
				<label for="ka-heraldry-file">Upload New Heraldry <span class="ka-hint">(PNG, JPG or GIF)</span></label>
				<input type="file" id="ka-heraldry-file" accept="image/png,image/jpeg,image/gif">
				<div class="ka-hint" id="ka-heraldry-resize" role="status" aria-live="polite" style="display:none"></div>
			</div>
			<ul class="ka-her-facts ka-muted">
				<li>PNG, JPG and GIF are accepted.</li>
				<li>Anything over about 340&nbsp;KB is scaled down in your browser before it is sent, so a large photo uploads fine &mdash; it just arrives smaller.</li>
				<li>A square image works best: the profile badge and the banner behind this console both crop to a square.</li>
				<li>Transparent edges are trimmed off a PNG, so a device on a transparent background is cropped tight to the artwork.</li>
				<li>An animated GIF is saved as a single still frame.</li>
			</ul>
		</div>
		<div class="ka-modal-footer">
			<?php if ($hasHeraldry): ?>
			<button class="adm-btn adm-btn-danger ka-footer-left" id="ka-heraldry-remove"><i class="fas fa-trash"></i> Remove</button>
			<?php endif; ?>
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-heraldry-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-heraldry-upload" disabled><i class="fas fa-upload"></i> Upload</button>
		</div>
	</div>
</div>

<!-- ---- Park Days ---- -->
<!-- The park-only screen. A park day is a RECURRENCE RULE, not a date: it is
     what puts this park on the public map, in "play Amtgard near me" and in the
     park profile's schedule, so the list is rendered server-side (readable with
     scripting off) and the editor sits inside the same modal rather than
     stacking a second overlay on top of it. -->
<div class="ka-overlay" id="ka-parkdays-overlay">
	<div class="ka-modal-box ka-modal-box-xl" style="max-width:calc(100vw - 32px)">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-calendar-week" style="margin-right:8px;color:#276749"></i>Park Days</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-parkdays-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<details class="ka-help">
				<summary><i class="fas fa-circle-info" aria-hidden="true"></i> What a park day is, and where it shows up</summary>
				<div class="ka-help-body">
					<p>A park day is a <strong>recurring rule</strong> &mdash; &ldquo;every Saturday at 2pm&rdquo; &mdash; not a single date. One-off gatherings are events, not park days.</p>
					<dl>
						<dt>Weekly</dt>
						<dd>The same weekday, every week. The usual choice.</dd>
						<dt>Every X weeks</dt>
						<dd>A fortnightly or three-weekly cadence. It needs a <strong>start date</strong>, because that first occurrence is what sets the rhythm &mdash; the weekday is read from it.</dd>
						<dt>Week of month</dt>
						<dd>&ldquo;The 2nd Sunday&rdquo;. Pick both the week and the weekday.</dd>
						<dt>Monthly</dt>
						<dd>The same calendar date every month.</dd>
					</dl>
					<div class="ka-help-note">
						<strong>These are public.</strong> Park days feed the park profile, the kingdom calendar and the public &ldquo;Amtgard near me&rdquo; search, so an out-of-date one sends a newcomer to an empty field. Prune the ones you no longer run.
					</div>
				</div>
			</details>
			<div class="ka-feedback" id="ka-pd-feedback"></div>

			<div class="ka-admin-table-wrap">
				<table class="ka-admin-table" id="ka-pd-table"<?= count($pkDays) === 0 ? ' style="display:none"' : '' ?>>
					<thead>
						<tr>
							<th>When</th>
							<th>Time</th>
							<th>Type</th>
							<th>Where</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
<?php foreach ($pkDays as $_d):
    $_dayId  = (int)($_d['ParkDayId'] ?? 0);
    $_online = (int)($_d['Online'] ?? 0) === 1;
    $_alt    = !$_online && (int)($_d['AlternateLocation'] ?? 0) === 1;
    // Escaped here rather than at the echo: the park's-own-location string is
    // markup we wrote (it carries an &rsquo;), the other two are user data.
    if ($_online) {
        $_where = 'Online';
    } elseif ($_alt) {
        $_where = trim(implode(', ', array_filter([(string)($_d['Address'] ?? ''), (string)($_d['City'] ?? '')])));
        $_where = htmlspecialchars($_where === '' ? 'Alternate location' : $_where);
    } else {
        $_where = 'The park&rsquo;s own location';
    }
    $_sd = substr((string)($_d['StartDate'] ?? ''), 0, 10);
    if (strpos($_sd, '1000-01-01') !== false) {
        $_sd = '';
    }
?>
						<tr>
							<td><?= htmlspecialchars($_pkaWhen($_d)) ?></td>
							<td><?= htmlspecialchars($_pkaTime($_d['Time'] ?? '')) ?></td>
							<td><?= htmlspecialchars($_pkaPurposes[(string)($_d['Purpose'] ?? '')] ?? 'Other') ?></td>
							<td>
								<?= $_where ?>
								<?php if (trim((string)($_d['Description'] ?? '')) !== ''): ?>
								<div class="ka-hint"><?= htmlspecialchars((string)$_d['Description']) ?></div>
								<?php endif; ?>
							</td>
							<td style="white-space:nowrap;text-align:right">
								<button type="button" class="ka-tsave ka-pd-edit"
									data-day-id="<?= $_dayId ?>"
									data-recurrence="<?= htmlspecialchars((string)($_d['Recurrence'] ?? 'weekly')) ?>"
									data-weekday="<?= htmlspecialchars((string)($_d['WeekDay'] ?? 'Saturday')) ?>"
									data-weekof="<?= (int)($_d['WeekOfMonth'] ?? 1) ?>"
									data-monthday="<?= (int)($_d['MonthDay'] ?? 1) ?>"
									data-interval="<?= (int)($_d['WeekInterval'] ?? 0) ?>"
									data-startdate="<?= htmlspecialchars($_sd) ?>"
									data-time="<?= htmlspecialchars(substr((string)($_d['Time'] ?? ''), 0, 5)) ?>"
									data-purpose="<?= htmlspecialchars((string)($_d['Purpose'] ?? 'park-day')) ?>"
									data-desc="<?= htmlspecialchars((string)($_d['Description'] ?? '')) ?>"
									data-online="<?= $_online ? '1' : '0' ?>"
									data-altloc="<?= $_alt ? '1' : '0' ?>"
									data-address="<?= htmlspecialchars((string)($_d['Address'] ?? '')) ?>"
									data-city="<?= htmlspecialchars((string)($_d['City'] ?? '')) ?>"
									data-province="<?= htmlspecialchars((string)($_d['Province'] ?? '')) ?>"
									data-postal="<?= htmlspecialchars((string)($_d['PostalCode'] ?? '')) ?>"
									data-mapurl="<?= htmlspecialchars((string)($_d['MapUrl'] ?? '')) ?>"
									data-locurl="<?= htmlspecialchars((string)($_d['LocationUrl'] ?? '')) ?>"
									data-label="<?= htmlspecialchars($_pkaWhen($_d) . ' at ' . $_pkaTime($_d['Time'] ?? '')) ?>"
									data-tip="Edit this park day" aria-label="Edit this park day">
									<i class="fas fa-pencil-alt" aria-hidden="true"></i>
								</button>
								<button type="button" class="ka-tdel ka-pd-delete"
									data-day-id="<?= $_dayId ?>"
									data-label="<?= htmlspecialchars($_pkaWhen($_d) . ' at ' . $_pkaTime($_d['Time'] ?? '')) ?>"
									data-tip="Delete this park day" aria-label="Delete this park day">
									<i class="fas fa-trash" aria-hidden="true"></i>
								</button>
							</td>
						</tr>
<?php endforeach; ?>
					</tbody>
				</table>
				<div class="ka-table-empty" id="ka-pd-empty"<?= count($pkDays) > 0 ? ' style="display:none"' : '' ?>>
					<i class="fas fa-calendar-week"></i>
					<strong>No park days scheduled</strong>
					<div class="ka-table-empty-hint">Nothing is published for this park, so it does not appear in the public &ldquo;find a game near me&rdquo; search.</div>
				</div>
			</div>

			<div class="ka-award-add-btns">
				<button type="button" class="ka-add-btn" id="ka-pd-open-add"><i class="fas fa-plus" aria-hidden="true"></i> Add a park day</button>
			</div>

			<!-- Add / edit form. One panel serving both, the way the park profile's
			     own schedule editor does: the fields are identical and a second copy
			     would be one more thing to keep in step. -->
			<div class="ka-inset-panel" id="ka-pd-form" style="display:none">
				<div class="ka-inset-panel-title" id="ka-pd-form-title">Add a park day</div>
				<input type="hidden" id="ka-pd-id" value="0">

				<div class="ka-field-row">
					<div class="ka-field">
						<label for="ka-pd-purpose">Type</label>
						<select id="ka-pd-purpose">
							<option value="park-day">Regular Park Day</option>
							<option value="fighter-practice">Fighter Practice</option>
							<option value="arts-day">A&amp;S Day</option>
							<option value="other">Other</option>
						</select>
					</div>
					<div class="ka-field">
						<label for="ka-pd-recurrence">Recurrence</label>
						<select id="ka-pd-recurrence">
							<option value="weekly">Weekly</option>
							<option value="every-x-weeks">Every X weeks</option>
							<option value="week-of-month">Week of month</option>
							<option value="monthly">Monthly</option>
						</select>
					</div>
				</div>

				<div class="ka-pd-grid">
					<div class="ka-field" id="ka-pd-weekday-row">
						<label for="ka-pd-weekday">Day of week</label>
						<select id="ka-pd-weekday">
							<option value="Monday">Monday</option>
							<option value="Tuesday">Tuesday</option>
							<option value="Wednesday">Wednesday</option>
							<option value="Thursday">Thursday</option>
							<option value="Friday">Friday</option>
							<option value="Saturday">Saturday</option>
							<option value="Sunday">Sunday</option>
						</select>
					</div>
					<div class="ka-field" id="ka-pd-weekof-row" style="display:none">
						<label for="ka-pd-weekof">Week of month</label>
						<select id="ka-pd-weekof">
							<option value="1">1st</option>
							<option value="2">2nd</option>
							<option value="3">3rd</option>
							<option value="4">4th</option>
							<option value="5">5th</option>
						</select>
					</div>
					<div class="ka-field" id="ka-pd-monthday-row" style="display:none">
						<label for="ka-pd-monthday">Day of month</label>
						<input type="number" id="ka-pd-monthday" min="1" max="31" value="1">
					</div>
					<div class="ka-field" id="ka-pd-interval-row" style="display:none">
						<label for="ka-pd-interval">Interval</label>
						<select id="ka-pd-interval">
							<option value="2">Every 2 weeks</option>
							<option value="3">Every 3 weeks</option>
							<option value="4">Every 4 weeks</option>
						</select>
					</div>
					<div class="ka-field" id="ka-pd-startdate-row" style="display:none">
						<label for="ka-pd-startdate">Start date <span class="ka-req" aria-hidden="true">*</span></label>
						<input type="date" id="ka-pd-startdate">
						<div class="ka-hint">The first occurrence. It sets both the cadence and the weekday.</div>
					</div>
					<div class="ka-field">
						<label for="ka-pd-time">Time <span class="ka-req" aria-hidden="true">*</span></label>
						<input type="time" id="ka-pd-time">
					</div>
				</div>

				<div class="ka-field">
					<label for="ka-pd-desc">Description <span class="ka-hint">(optional)</span></label>
					<input type="text" id="ka-pd-desc" maxlength="200" placeholder="e.g. Ditch practice, bring a blue">
				</div>

				<fieldset class="ka-fieldset">
					<legend class="ka-legend">Location</legend>
					<div class="ka-radio-group">
						<label for="ka-pd-loc-park"><input type="radio" id="ka-pd-loc-park" name="ka-pd-loc" value="0" checked> The park&rsquo;s own location</label>
						<label for="ka-pd-loc-alt"><input type="radio" id="ka-pd-loc-alt" name="ka-pd-loc" value="1"> Somewhere else</label>
						<label for="ka-pd-loc-online"><input type="radio" id="ka-pd-loc-online" name="ka-pd-loc" value="online"> Online</label>
					</div>
				</fieldset>

				<div id="ka-pd-altloc" style="display:none">
					<div class="ka-field">
						<label for="ka-pd-address">Address</label>
						<input type="text" id="ka-pd-address" maxlength="100">
					</div>
					<div class="ka-field-row">
						<div class="ka-field">
							<label for="ka-pd-city">City</label>
							<input type="text" id="ka-pd-city" maxlength="60">
						</div>
						<div class="ka-field">
							<label for="ka-pd-province">State / Province</label>
							<input type="text" id="ka-pd-province" maxlength="40">
						</div>
					</div>
					<div class="ka-field-row">
						<div class="ka-field">
							<label for="ka-pd-postal">Postal Code</label>
							<input type="text" id="ka-pd-postal" maxlength="12">
						</div>
						<div class="ka-field">
							<label for="ka-pd-mapurl">Map URL <span class="ka-hint">(optional)</span></label>
							<input type="url" id="ka-pd-mapurl" placeholder="Google Maps link">
						</div>
					</div>
				</div>

				<div class="ka-field">
					<label for="ka-pd-locurl">Information URL <span class="ka-hint">(optional)</span></label>
					<input type="url" id="ka-pd-locurl" placeholder="https://">
				</div>

				<div class="ka-award-add-btns">
					<button class="adm-btn adm-btn-primary" id="ka-pd-save"><i class="fas fa-save"></i> <span id="ka-pd-save-label">Add Park Day</span></button>
					<button class="adm-btn adm-btn-ghost" id="ka-pd-cancel">Cancel</button>
				</div>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-parkdays-overlay')">Done</button>
		</div>
	</div>
</div>

<!-- ---- Create Player ---- -->
<div class="ka-overlay" id="ka-createplayer-overlay">
	<div class="ka-modal-box ka-modal-box-lg">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Create Player</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-createplayer-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-cp-feedback"></div>
			<!-- No home-park picker: this console administers ONE park, and the
			     endpoint takes the park from the URL. A select with one option is a
			     question with no answer. -->
			<p class="ka-form-hint">The new player&rsquo;s home park will be <strong><?= $parkName ?></strong>.</p>
			<div class="ka-field-row">
				<div class="ka-field">
					<label for="ka-cp-persona">Persona <span class="ka-req" aria-hidden="true">*</span></label>
					<input type="text" id="ka-cp-persona" placeholder="In-game name" required aria-required="true">
				</div>
				<div class="ka-field">
					<label for="ka-cp-email">Email</label>
					<input type="email" id="ka-cp-email" placeholder="email@example.com">
				</div>
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label for="ka-cp-given">Given Name</label>
					<input type="text" id="ka-cp-given" placeholder="First name">
				</div>
				<div class="ka-field">
					<label for="ka-cp-surname">Surname</label>
					<input type="text" id="ka-cp-surname" placeholder="Last name">
				</div>
			</div>
			<div class="ka-field">
				<label for="ka-cp-username">Username <span class="ka-req" aria-hidden="true">*</span></label>
				<input type="text" id="ka-cp-username" autocomplete="new-password" placeholder="min. 4 characters" required aria-required="true">
			</div>

			<!-- Password is an explicit CHOICE, never a blank box.
			     Player::CreatePlayer() stores no credential at all when Password is
			     empty, because hashing salt + USERNAME + '' would let the account in
			     with an empty password box. That is deliberate, but as an unexplained
			     "optional" field it produced accounts nobody could sign in to and no
			     screen said so. The two branches below name both outcomes. -->
			<fieldset class="ka-fieldset">
				<legend class="ka-legend">Password</legend>
				<div class="ka-choice">
					<label class="ka-choice-opt">
						<input type="radio" name="ka-cp-pwmode" id="ka-cp-pwmode-self" value="self" checked>
						<span>
							<span class="ka-choice-title">Player sets their own password</span>
							<span class="ka-choice-help">No password is created now. The account exists but <strong>cannot be signed in to</strong> until the player sets one from the login page&rsquo;s &ldquo;forgot password&rdquo; link, so give them an email address they can receive.</span>
						</span>
					</label>
					<label class="ka-choice-opt">
						<input type="radio" name="ka-cp-pwmode" id="ka-cp-pwmode-temp" value="temp">
						<span>
							<span class="ka-choice-title">I&rsquo;ll set a temporary password</span>
							<span class="ka-choice-help">Hand it to the player in person and ask them to change it. Minimum 8 characters &mdash; the same floor self-registration enforces.</span>
						</span>
					</label>
				</div>
				<div class="ka-field ka-choice-detail" id="ka-cp-pw-wrap" style="display:none">
					<label for="ka-cp-password">Temporary Password <span class="ka-req" aria-hidden="true">*</span> <span class="ka-hint">(at least 8 characters)</span></label>
					<input type="password" id="ka-cp-password" autocomplete="new-password" minlength="8" placeholder="at least 8 characters">
				</div>
			</fieldset>

			<div class="ka-field-row">
				<fieldset class="ka-fieldset">
					<legend class="ka-legend">Restrict Mundane Name Visibility</legend>
					<div class="ka-radio-group">
						<label for="ka-cp-restricted-no"><input type="radio" id="ka-cp-restricted-no" name="ka-cp-restricted" value="0" checked> No</label>
						<label for="ka-cp-restricted-yes"><input type="radio" id="ka-cp-restricted-yes" name="ka-cp-restricted" value="1"> Yes</label>
					</div>
					<div class="ka-cfg-help ka-legend-help">Hides the player&rsquo;s real name from searches and public displays. Use it for members who prefer their mundane identity kept private.</div>
				</fieldset>
				<fieldset class="ka-fieldset">
					<legend class="ka-legend">Waivered</legend>
					<div class="ka-radio-group">
						<label for="ka-cp-waivered-no"><input type="radio" id="ka-cp-waivered-no" name="ka-cp-waivered" value="0" checked> No</label>
						<label for="ka-cp-waivered-yes"><input type="radio" id="ka-cp-waivered-yes" name="ka-cp-waivered" value="1"> Yes</label>
					</div>
					<div class="ka-notice-warn ka-legend-help"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><span>Only set this to Yes once the signed waiver is actually in hand. It is a legal record, not a convenience flag.</span></div>
				</fieldset>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-createplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-cp-submit"><i class="fas fa-user-plus"></i> Create Player</button>
		</div>
	</div>
</div>

<!-- ---- Move Player ---- -->
<!-- One direction, deliberately: this is the park console's version of the
     legacy Admin/claimplayer/park/{id} page, which also fixed the destination
     to this park and only ever asked which player. Sending one of your own
     members away is done from their profile, where the person doing it can see
     what they are moving. -->
<div class="ka-overlay" id="ka-moveplayer-overlay">
	<div class="ka-modal-box ka-modal-box-md">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-people-arrows" style="margin-right:8px;color:#2b6cb0"></i>Move Player Here</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-moveplayer-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-mp-feedback"></div>
			<p class="ka-form-hint">
				Changes a player&rsquo;s <strong>home park</strong> to <strong><?= $parkName ?></strong>. Their awards, attendance
				and officer history all travel with them &mdash; nothing is copied or deleted. Players already
				here are left out of the search, because there is nothing to move.
			</p>
			<div class="ka-field ka-field-ac">
				<label for="ka-mp-player-name">Player <span class="ka-req" aria-hidden="true">*</span></label>
				<input type="text" id="ka-mp-player-name" autocomplete="off" placeholder="Search players&hellip;" aria-required="true">
				<input type="hidden" id="ka-mp-player-id">
				<div class="kn-ac-results" id="ka-mp-player-results"></div>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-moveplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-mp-submit" disabled><i class="fas fa-arrow-right"></i> Move Player Here</button>
		</div>
	</div>
</div>

<!-- ---- Merge Players ---- -->
<div class="ka-overlay" id="ka-mergeplayer-overlay">
	<div class="ka-modal-box ka-modal-box-lg">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-compress-alt ka-icon-danger" style="margin-right:8px"></i>Merge Players</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-mergeplayer-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-mgp-feedback"></div>
			<!-- The old copy said everything "transfers". It does not: Player::MergePlayer
			     DELETEs the colliding rows in attendance / event_rsvp /
			     class_reconciliation before it re-points the rest, and ork_awards is a
			     plain UPDATE with no de-duplication. Officers were making an
			     irreversible decision against a description of a different operation. -->
			<div class="ka-warning">
				<i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
				<div>
					<strong>This permanently deletes the <em>Remove</em> player's account. It cannot be undone.</strong>
					<ul class="ka-confirm-list">
						<li><strong>Attendance is not fully merged.</strong> Any attendance the <em>Remove</em> player has on a date the <em>Keep</em> player also has a record for is <strong>deleted</strong>, not carried over. The rest transfers.</li>
						<li><strong>Event RSVPs and class reconciliations</strong> that would collide with one the <em>Keep</em> player already holds are <strong>deleted</strong> the same way.</li>
						<li><strong>Awards transfer with no de-duplication.</strong> If both players hold the same award, the surviving record ends up holding it <strong>twice</strong>, and you will have to strip the extra by hand afterwards.</li>
						<li>Officer history, unit memberships, dues, notes and recommendations transfer intact.</li>
						<li>The <em>Remove</em> player's login stops working. Only the <em>Keep</em> player's login survives.</li>
					</ul>
					<p class="ka-mgp-note">The merge is written to the Audit Log, but there is no undo button &mdash; reversing one is a manual repair.</p>
				</div>
			</div>
			<!-- Search is park-first but not park-only: a duplicate account almost
			     never sits tidily in the same park as the original. Player::MergePlayer
			     enforces the real limits -- same park needs park standing, across parks
			     needs the kingdom, across kingdoms needs ORK staff -- so the note below
			     says so rather than letting the officer find out from an error. -->
			<div class="ka-field ka-field-ac">
				<label for="ka-mgp-keep-name">Player to Keep <span class="ka-req" aria-hidden="true">*</span></label>
				<input type="text" id="ka-mgp-keep-name" autocomplete="off" placeholder="Search for player to keep&hellip;" aria-required="true">
				<input type="hidden" id="ka-mgp-keep-id">
				<div class="kn-ac-results" id="ka-mgp-keep-results"></div>
			</div>
			<div class="ka-field ka-field-ac" style="margin-top:12px">
				<label for="ka-mgp-remove-name">Player to Remove &mdash; <span class="ka-danger-note"><i class="fas fa-skull-crossbones" aria-hidden="true"></i> permanently deleted</span> <span class="ka-req" aria-hidden="true">*</span></label>
				<input type="text" id="ka-mgp-remove-name" autocomplete="off" placeholder="Search for player to remove&hellip;" aria-required="true">
				<input type="hidden" id="ka-mgp-remove-id">
				<div class="kn-ac-results" id="ka-mgp-remove-results"></div>
			</div>
			<!-- Side-by-side preview, built by JS once both sides are picked. Two
			     players with the same persona is the exact case this modal exists for,
			     so a name in a text box is not enough to tell them apart. -->
			<div class="ka-mgp-preview" id="ka-mgp-preview" style="display:none">
				<div class="ka-mgp-cards">
					<div class="ka-mgp-card ka-mgp-card-keep" id="ka-mgp-card-keep"></div>
					<button type="button" class="ka-mgp-swap" id="ka-mgp-swap"
						data-tip="Swap Keep and Remove" aria-label="Swap the Keep and Remove players">
						<i class="fas fa-exchange-alt" aria-hidden="true"></i>
					</button>
					<div class="ka-mgp-card ka-mgp-card-remove" id="ka-mgp-card-remove"></div>
				</div>
				<span class="ka-mgp-swap-hint ka-muted" id="ka-mgp-swap-hint" role="status" aria-live="polite">Picked them the wrong way round? Use the swap button.</span>
				<p class="ka-mgp-note ka-muted">Award, attendance, office and unit counts are not available from this search. Open both profiles in a new tab if you need to compare them before committing. Merging two players in different parks needs kingdom standing; in different kingdoms, ORK staff.</p>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-mergeplayer-overlay')">Cancel</button>
			<!-- Wait-to-arm rather than a type-the-name gate: the first click starts a
			     five-second countdown, the second click commits. See the .ka-arm-btn
			     contract in admin-console.css. -->
			<button class="adm-btn adm-btn-danger ka-arm-btn" id="ka-mgp-submit" disabled>
				<i class="fas fa-compress-alt" aria-hidden="true"></i> <span id="ka-mgp-submit-label">Merge Players</span>
				<span class="ka-arm-count" id="ka-mgp-arm-count" role="status" aria-live="polite" style="display:none"></span>
			</button>
		</div>
	</div>
</div>

<!-- ---- Operations ---- -->
<?php if (!empty($CanResetWaivers)): ?>
<div class="ka-overlay" id="ka-ops-overlay">
	<div class="ka-modal-box ka-modal-box-md">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-cogs ka-icon-warn" style="margin-right:8px"></i>Reset Waivers</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-ops-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-ops-feedback"></div>
			<div class="ka-ops-row">
				<div class="ka-ops-info">
					<strong>Reset Waivers</strong>
					<p>Sets every player&rsquo;s waiver flag in this park back to unsigned. The update has <strong>no active filter</strong>, so it clears inactive and retired members along with your current roster.</p>
					<?php if ($_pkaWaivered === null): ?>
					<span class="ka-ops-blast ka-notice-warn" id="ka-ops-blast"><i class="fas fa-question-circle" aria-hidden="true"></i><span>The number of players holding a waiver could not be read for this park. Check the Waivered Players report before you run this.</span></span>
					<?php elseif ($_pkaWaivered === 0): ?>
					<span class="ka-ops-blast ka-muted" id="ka-ops-blast">No players in this park currently hold a waiver, so there is nothing to clear.</span>
					<?php else: ?>
					<span class="ka-ops-blast ka-danger-note" id="ka-ops-blast"><i class="fas fa-users" aria-hidden="true"></i><span><?= number_format($_pkaWaivered) ?> player<?= $_pkaWaivered === 1 ? '' : 's' ?> in this park currently hold<?= $_pkaWaivered === 1 ? 's' : '' ?> a waiver and would be cleared.</span></span>
					<?php endif; ?>
					<p class="ka-muted">There is no undo here, but every player cleared is recorded in the Audit Log, which ORK staff can review on request.</p>
				</div>
				<button class="ka-ops-btn ka-ops-btn-danger" id="ka-ops-reset-waivers"
					data-count="<?= $_pkaWaivered === null ? '' : (int)$_pkaWaivered ?>"<?= $_pkaWaivered === 0 ? ' disabled' : '' ?>>
					<i class="fas fa-eraser" aria-hidden="true"></i> Reset Waivers
				</button>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-ops-overlay')">Done</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- ---- Confirmation Dialog ---- -->
<div class="ka-confirm-overlay" id="ka-confirm-overlay">
	<div class="ka-modal-box ka-confirm-box">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title" id="ka-confirm-title"><i class="fas fa-exclamation-triangle ka-icon-danger" style="margin-right:8px"></i>Confirm</h3>
			<button class="ka-modal-close" id="ka-confirm-close">&times;</button>
		</div>
		<div class="ka-modal-body">
			<!-- A <div>, not a <p>: kaConfirm(..., {html:true}) puts a <ul> in here to
			     spell out a blast radius, and a <ul> inside a <p> is invalid and gets
			     re-parented out of it by the browser. -->
			<div id="ka-confirm-message" class="ka-confirm-body"></div>
		</div>
		<div class="ka-modal-footer" style="justify-content:flex-end;gap:10px">
			<button class="adm-btn adm-btn-ghost" id="ka-confirm-cancel">Cancel</button>
			<button class="adm-btn adm-btn-danger" id="ka-confirm-alt" style="display:none"></button>
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

<?php
/* Schedule an Event -- the SAME modal the park profile uses, out of
   partials/_event_create_modal.tpl. The tile in Admin_park.tpl calls
   pkOpenEventModal(), which that partial exports.

   This is not a ka-* modal and deliberately does not register with
   kaRegisterModal(): it carries its own overlay, its own Escape/backdrop
   handling and its own dirty-free lifecycle, and it is the one surface the
   park profile and this console must not be allowed to drift apart on.

   $evParkName wants the RAW name -- the partial escapes it. $parkName in
   Admin_park.tpl has already been through htmlspecialchars(). */
$evParkId    = $parkId;
$evKingdomId = $kingdomId;
$evParkName  = (string)($ParkInfo['ParkName'] ?? '');
$evCanCreate = true;
include __DIR__ . '/_event_create_modal.tpl';
?>

<script>
(function() {
	var overlay = document.getElementById('ka-mo-overlay');
	function openMo() {
		if (!overlay) return;
		overlay.classList.add('ka-open');
		document.body.style.overflow = 'hidden';
		// A prior visit may have left the transition wizard (#ot-root) visible if the
		// whole modal was dismissed (Escape / backdrop / X) while the wizard was open --
		// closeMo() only hides the overlay, it never runs the wizard's own teardown. Force
		// the officer-list view back to the front on every open so a stale wizard never
		// sits beneath the freshly-loaded #mo-cards.
		var otRoot  = document.getElementById('ot-root');
		var moCards = document.getElementById('mo-cards');
		if (otRoot)  { otRoot.style.display = 'none'; }
		if (moCards) { moCards.style.display = ''; }
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

<?php
/* The generic modal engine (overlay stack, focus trap, dirty guard, kaConfirm).
   Included AFTER the markup above -- it wires dialog semantics and backdrop
   clicks at parse time -- and BEFORE the console script below, which drives it. */
include __DIR__ . '/_ka_modal_core.tpl';
?>

<!-- =============================================
     JAVASCRIPT
     ============================================= -->
<script>
var PkaConfig = {
	uir:         '<?= UIR ?>',
	parkId:      <?= (int)$parkId ?>,
	parkName:    <?= json_encode((string)($ParkInfo['ParkName'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	kingdomId:   <?= (int)$kingdomId ?>,
	hasHeraldry: <?= $hasHeraldry ? 'true' : 'false' ?>,
	/* Admin::park()'s front door -- park.details.edit @AUTH_EDIT, or standing in
	   the park's kingdom -- has already gated this whole template, so anyone who
	   can read this script can use it. The flag stays because including
	   _ka_modal_core.tpl wires document-level Escape / Tab / mousedown listeners
	   and dialog semantics REGARDLESS of any page-level guard: the guard has to
	   live on THIS script, not on the include, exactly as it does on the kingdom
	   console. */
	canManage:   true,
};
/* Shared autocomplete positioner (kn-ac dropdowns inside a modal must escape the
   box's overflow, so they are re-anchored with position:fixed before every
   kn-ac-open). partials/_manage_officers.tpl carries a guarded copy, but it is
   included only inside `if ($mo_can_manage)`, and revised.js -- its usual home --
   is not loaded on this page. Same guard both sides, so whichever runs first wins
   and neither double-defines. */
if (typeof window.tnAcZIndex !== 'function') {
	window.tnAcZIndex = (function () {
		var cached = null;
		return function () {
			if (cached !== null) return cached;
			var top = 0;
			try {
				top = parseInt(getComputedStyle(document.documentElement)
					.getPropertyValue('--z-modal-top'), 10);
			} catch (e) { top = 0; }
			cached = (top > 0) ? (top + 1) : 10201;
			return cached;
		};
	})();
}
if (typeof window.tnFixedAcPosition !== 'function') {
	window.tnFixedAcPosition = function (input, dropdown) {
		if (!input || !dropdown) return;
		var r = input.getBoundingClientRect();
		dropdown.style.position = 'fixed';
		dropdown.style.top = (r.bottom + 2) + 'px';
		dropdown.style.left = r.left + 'px';
		dropdown.style.width = r.width + 'px';
		dropdown.style.zIndex = String(window.tnAcZIndex());
	};
}
</script>
<script>
(function() {
	if (!PkaConfig.canManage) return;

	var UIR       = PkaConfig.uir;
	var BASE_URL  = UIR + 'ParkAjax/park/' + PkaConfig.parkId + '/';
	var PARK_NAME = PkaConfig.parkName;

	function gid(id) { return document.getElementById(id); }

	/* ── Shared modal engine ──────────────────────────
	   The overlay stack, focus trap, dirty guard, save-or-discard prompt and
	   kaConfirm all live in partials/_ka_modal_core.tpl, included just above.
	   These aliases exist so the handlers below read the same way the kingdom
	   console's do. kaOpenModal / kaCloseModal are not aliased: the markup calls
	   them straight from inline onclick, i.e. off window. */
	var kaRegisterModal = window.kaRegisterModal;
	var kaConfirm       = window.kaConfirm;
	var kaFeedback      = window.kaFeedback;
	var kaClearFeedback = window.kaClearFeedback;
	function kaOnOpen(id, fn) { kaRegisterModal(id, { onOpen: fn }); }

	function kaEsc(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

	/* ── This console's modals ──────────────────────── */
	kaRegisterModal('ka-moveplayer-overlay',   { resetOnOpen: ['ka-mp-submit'] });
	kaRegisterModal('ka-mergeplayer-overlay',  { resetOnOpen: ['ka-mgp-submit'] });
	kaRegisterModal('ka-createplayer-overlay', { resetOnOpen: true });
	// Heraldry would otherwise keep an abandoned pick AND its data: URI preview
	// between opens, so the modal could reopen showing an image that was never
	// saved under a caption reading "New image — not saved yet".
	kaRegisterModal('ka-heraldry-overlay',     { resetOnOpen: ['ka-heraldry-upload'] });
	// Park Days: the add/edit panel must not reopen half-filled from the last visit.
	kaRegisterModal('ka-parkdays-overlay',     { resetOnOpen: true });

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
				results.innerHTML = '<div class="kn-ac-item ka-ac-meta" style="pointer-events:none">No results</div>';
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

	/* ── Player search ────────────────────────────────
	   Park-scoped, &q= form, through the park's own endpoint. Row shape is
	   SearchService::formatScopedPlayerRow's 'kingdom' format: MundaneId,
	   Persona, KingdomId, ParkId, KingdomName, ParkName, KAbbr, PAbbr,
	   Suspended, Active. Suspended and Active are RENDERED, and so is the id:
	   two players sharing a persona in one park is the exact case Merge Players
	   exists for, and without those the two rows are indistinguishable. */
	function kaPlayerFlags(p) {
		var out = '';
		if (Number(p.Suspended) === 1) out += ' <span class="ka-ac-flag ka-ac-flag-suspended">suspended</span>';
		if (Number(p.Active) === 0)    out += ' <span class="ka-ac-flag ka-ac-flag-inactive">inactive</span>';
		return out;
	}
	function kaSearchPlayers(scope, prioritize) {
		return function (q, cb) {
			fetch(BASE_URL + 'playersearch&q=' + encodeURIComponent(q)
					+ '&scope=' + encodeURIComponent(scope)
					+ (prioritize ? '&prioritize=1' : '')
					+ '&include_inactive=1&include_suspended=1')
			.then(function(r){return r.json();}).then(function(d) {
				cb((d || []).map(function(p) {
					return {
						id: p.MundaneId,
						label: p.Persona,
						extra: JSON.stringify(p),
						html: kaEsc(p.Persona)
							+ ' <span class="ka-ac-meta">(' + kaEsc(p.KAbbr) + ' &middot; ' + kaEsc(p.ParkName) + ' &middot; #' + kaEsc(p.MundaneId) + ')</span>'
							+ kaPlayerFlags(p)
					};
				}));
			}).catch(function(){cb([]);});
		};
	}

	/* ── POST helper ──────────────────────────────── */
	/* onFinally (optional) runs on BOTH the success and the failure path, after
	   the button is re-enabled -- without it a failed POST leaves a button stuck
	   reading "Merging…" forever. */
	function kaPost(url, data, btn, feedbackId, onSuccess, onFinally) {
		if (btn) btn.disabled = true;
		var fd = new FormData();
		Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
		fetch(url, { method: 'POST', body: fd })
		.then(function(r) { return r.json(); })
		.then(function(r) {
			if (btn) btn.disabled = false;
			if (onFinally) onFinally();
			if (r.status === 0) { onSuccess(r); }
			else { kaFeedback(feedbackId, r.error || 'An error occurred.', false); }
		})
		.catch(function() {
			if (btn) btn.disabled = false;
			if (onFinally) onFinally();
			kaFeedback(feedbackId, 'Request failed. Please try again.', false);
		});
	}

	/* ══════════════════════════════════════════════
	   EDIT PARK DETAILS
	   ══════════════════════════════════════════════ */
	(function() {
		var btn = gid('ka-details-save');
		if (!btn) return;

		var FIELDS = {
			Url:         'ka-details-url',
			Address:     'ka-details-address',
			City:        'ka-details-city',
			Province:    'ka-details-province',
			PostalCode:  'ka-details-postal',
			MapUrl:      'ka-details-mapurl',
			Description: 'ka-details-description',
			Directions:  'ka-details-directions',
		};

		/* done(ok) fires on BOTH outcomes -- kaPost's own onSuccess/onFinally pair
		   cannot express "it failed", and the engine's save hook has to be told, or
		   the unsaved-changes prompt would close the modal over edits that never
		   landed. Hence a plain fetch here rather than kaPost. */
		function saveDetails(done) {
			/* urlencoded, NOT FormData. Description and Directions are multi-line,
			   and the multipart serializer is specified to rewrite every bare LF in
			   a field value to CRLF -- so posting through FormData silently
			   rewrites the stored newlines on every save, and the console and the
			   park profile (which posts urlencoded) would write different bytes for
			   the same typed text. */
			var body = Object.keys(FIELDS).map(function(k) {
				var el = gid(FIELDS[k]);
				return encodeURIComponent(k) + '=' + encodeURIComponent(el ? (el.value || '').trim() : '');
			}).join('&');
			btn.disabled = true;
			function finish(ok, msg) {
				btn.disabled = false;
				kaFeedback('ka-details-feedback', msg, ok);
				if (ok) {
					// Re-baseline the dirty guard: these stamps are what kaBaselineOf reads.
					Object.keys(FIELDS).forEach(function(k) {
						var el = gid(FIELDS[k]);
						if (el) el.dataset.original = el.value;
					});
				}
				if (done) done(ok);
			}
			fetch(BASE_URL + 'setdetails', {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body,
			})
			.then(function(r) { return r.json(); })
			.then(function(r) {
				if (r && r.status === 0) { finish(true, 'Details saved.'); }
				else { finish(false, (r && r.error) ? r.error : 'Save failed.'); }
			})
			.catch(function() { finish(false, 'Request failed. Please try again.'); });
		}

		btn.addEventListener('click', function() { saveDetails(null); });
		/* Registering a save is what turns the unsaved-changes prompt into three
		   buttons (Go Back / Discard Changes / Save Changes) instead of offering
		   only "throw it away". */
		kaRegisterModal('ka-details-overlay', { save: saveDetails });
	})();

	/* ══════════════════════════════════════════════
	   HERALDRY
	   ══════════════════════════════════════════════ */
	(function() {
		/* Must stay identical to resizeImageToLimit()'s targets and to the
		   Common::MAX_IMAGE_BASE64_LENGTH backstop in the domain. */
		var HERALDRY_MAX_BYTES = 348836;

		var fileInput = gid('ka-heraldry-file');
		var uploadBtn = gid('ka-heraldry-upload');
		var removeBtn = gid('ka-heraldry-remove');
		var notice    = gid('ka-heraldry-resize');
		var caption   = gid('ka-heraldry-caption');
		var preview   = gid('ka-heraldry-preview');
		if (!fileInput || !uploadBtn) return;
		var uploadIdleHtml = uploadBtn.innerHTML;
		// Captured at load, while the preview still holds what the server rendered.
		var savedSrc     = preview ? preview.getAttribute('src') : '';
		var savedAlt     = preview ? (preview.getAttribute('alt') || '') : '';
		var savedCaption = caption ? caption.textContent : '';

		/* The blob actually posted: the resized output when a resize ran, the raw
		   pick otherwise. Kept alongside the input because if a browser refuses the
		   DataTransfer write-back, the upload must still send the SMALL image. */
		var pendingBlob = null;

		function note(msg, isError) {
			if (!notice) return;
			if (!msg) { notice.style.display = 'none'; notice.textContent = ''; notice.classList.remove('ka-notice-warn'); return; }
			notice.style.display = '';
			notice.textContent = msg;
			notice.classList.toggle('ka-notice-warn', !!isError);
		}
		function showLocalPreview(blob) {
			if (!preview) return;
			var reader = new FileReader();
			reader.onload = function(e) {
				preview.src = e.target.result;
				preview.alt = 'Preview of the image you are about to upload';
				if (caption) caption.textContent = 'New image — not saved yet';
			};
			reader.readAsDataURL(blob);
		}
		function busy(on) {
			uploadBtn.disabled = on;
			uploadBtn.setAttribute('aria-busy', on ? 'true' : 'false');
			uploadBtn.innerHTML = on
				? '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Uploading…'
				: uploadIdleHtml;
			// A remove firing mid-upload would race the write it is trying to delete.
			if (removeBtn) removeBtn.disabled = on;
			fileInput.disabled = on;
		}

		kaOnOpen('ka-heraldry-overlay', function() {
			pendingBlob = null;
			note('');
			fileInput.disabled = false;
			delete fileInput.dataset.resizeGen;
			if (preview) { preview.src = savedSrc; preview.alt = savedAlt; }
			if (caption) caption.textContent = savedCaption;
			busy(false);
			uploadBtn.disabled = true;
			if (removeBtn) { removeBtn.disabled = false; removeBtn.setAttribute('aria-busy', 'false'); }
		});

		fileInput.addEventListener('change', function() {
			var input = this;
			pendingBlob = null;
			note('');
			kaClearFeedback('ka-heraldry-feedback');
			uploadBtn.disabled = !input.files.length;
			if (!input.files.length) { return; }

			var file = input.files[0];
			showLocalPreview(file);
			pendingBlob = file;
			if (file.size <= HERALDRY_MAX_BYTES) return;

			/* Every other heraldry upload path in the app downscales before it
			   posts. resizeImageToLimit() is the shared global from orkui.js --
			   same threshold and the same preservePng rule as its other callers,
			   so a PNG keeps its alpha instead of being flattened onto black. */
			if (typeof resizeImageToLimit !== 'function') {
				note('This image is larger than 340 KB and the resizer did not load, so it cannot be sent as-is. Please save a smaller copy and try again.', true);
				try { input.value = ''; } catch (e) {}
				pendingBlob = null;
				uploadBtn.disabled = true;
				return;
			}

			// Resizing is async; a second pick while the first is still running must
			// not have its result written back over the newer one.
			var gen = (Number(input.dataset.resizeGen) || 0) + 1;
			input.dataset.resizeGen = String(gen);
			var isPng = (file.type === 'image/png');
			var originalKB = Math.round(file.size / 1024);

			uploadBtn.disabled = true;
			note('Resizing…');
			resizeImageToLimit(file, HERALDRY_MAX_BYTES, function(blob, newW, newH) {
				if (input.dataset.resizeGen !== String(gen)) return;
				pendingBlob = blob;
				try {
					var dt = new DataTransfer();
					dt.items.add(new File([blob], isPng ? 'heraldry.png' : 'heraldry.jpg',
						{ type: isPng ? 'image/png' : 'image/jpeg' }));
					input.files = dt.files;
				} catch (e) { /* pendingBlob still carries the resized image */ }
				showLocalPreview(blob);
				note('Resized ' + originalKB + ' KB → ' + Math.round(blob.size / 1024) + ' KB ('
					+ newW + '×' + newH + ').');
				uploadBtn.disabled = false;
			}, function(errMsg) {
				if (input.dataset.resizeGen !== String(gen)) return;
				note(errMsg || 'That image could not be resized in your browser. Please save a smaller copy and try again.', true);
				try { input.value = ''; } catch (e) {}
				pendingBlob = null;
				uploadBtn.disabled = true;
			}, isPng);
		});

		uploadBtn.addEventListener('click', function() {
			var blob = pendingBlob || (fileInput.files.length ? fileInput.files[0] : null);
			if (!blob) return;
			// ParkAjax setheraldry checks $_FILES['Heraldry']['type'], which the
			// browser fills from the blob's own type -- so a name is required for the
			// part to be treated as a file upload at all.
			var name = blob.name || (blob.type === 'image/png' ? 'heraldry.png' : 'heraldry.jpg');
			var fd = new FormData();
			fd.append('Heraldry', blob, name);
			busy(true);
			fetch(BASE_URL + 'setheraldry', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(r) {
				if (r && r.status === 0) {
					// Deliberately left busy: the page is about to reload, and
					// re-enabling for a blink invites a second submit.
					kaFeedback('ka-heraldry-feedback', 'Heraldry updated. Refreshing…', true);
					setTimeout(function() { location.reload(); }, 1000);
					return;
				}
				busy(false);
				kaFeedback('ka-heraldry-feedback', (r && r.error) ? r.error : 'Upload failed.', false);
			})
			.catch(function() { busy(false); kaFeedback('ka-heraldry-feedback', 'Request failed.', false); });
		});

		if (removeBtn) {
			removeBtn.addEventListener('click', function() {
				kaConfirm(
					'<p>This deletes the image file permanently. No copy is kept and there is no undo.</p>'
					+ '<ul class="ka-confirm-list">'
					+ '<li>The banner behind this console and the park profile badge both fall back to the generic ORK shield.</li>'
					+ '<li>Anywhere else this park’s device is shown falls back with them.</li>'
					+ '<li>If there is any chance you will want it back, cancel and use <strong>Download current image</strong> first.</li>'
					+ '</ul>',
					function() {
						removeBtn.disabled = true;
						removeBtn.setAttribute('aria-busy', 'true');
						fetch(BASE_URL + 'removeheraldry', { method: 'POST', body: new FormData() })
						.then(function(r) { return r.json(); })
						.then(function(r) {
							if (r && r.status === 0) {
								kaFeedback('ka-heraldry-feedback', 'Heraldry removed. Refreshing…', true);
								setTimeout(function() { location.reload(); }, 1000);
								return;
							}
							removeBtn.disabled = false;
							removeBtn.setAttribute('aria-busy', 'false');
							kaFeedback('ka-heraldry-feedback', (r && r.error) ? r.error : 'Remove failed.', false);
						})
						.catch(function() {
							removeBtn.disabled = false;
							removeBtn.setAttribute('aria-busy', 'false');
							kaFeedback('ka-heraldry-feedback', 'Request failed.', false);
						});
					},
					'Delete Heraldry',
					{ danger: true, html: true, okLabel: 'Delete permanently', okIcon: 'fas fa-trash' }
				);
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   PARK DAYS
	   ══════════════════════════════════════════════
	   The only screen on this console with no kingdom counterpart. Add, edit and
	   delete all go to ParkAjax/park/{id}/{add,edit,delete}parkday -- the same
	   three endpoints the park profile's schedule editor uses, so the two can
	   never disagree about what a park day is. */
	(function() {
		var panel   = gid('ka-pd-form');
		var saveBtn = gid('ka-pd-save');
		if (!panel || !saveBtn) return;

		var openBtn   = gid('ka-pd-open-add');
		var cancelBtn = gid('ka-pd-cancel');
		var titleEl   = gid('ka-pd-form-title');
		var labelEl   = gid('ka-pd-save-label');
		var idEl      = gid('ka-pd-id');

		function locMode() {
			var picked = document.querySelector('input[name="ka-pd-loc"]:checked');
			return picked ? picked.value : '0';
		}
		function syncLoc() {
			var alt = gid('ka-pd-altloc');
			if (alt) alt.style.display = (locMode() === '1') ? '' : 'none';
		}
		/* Which of the recurrence fields actually apply. For every-x-weeks the
		   weekday is derived from the start date, so the weekday select is hidden
		   in that mode rather than sitting there being ignored. */
		function syncRecurrence() {
			var r = gid('ka-pd-recurrence').value;
			var show = {
				'ka-pd-weekday-row':   (r === 'weekly' || r === 'week-of-month'),
				'ka-pd-weekof-row':    (r === 'week-of-month'),
				'ka-pd-monthday-row':  (r === 'monthly'),
				'ka-pd-interval-row':  (r === 'every-x-weeks'),
				'ka-pd-startdate-row': (r === 'every-x-weeks'),
			};
			Object.keys(show).forEach(function(id) {
				var el = gid(id);
				if (el) el.style.display = show[id] ? '' : 'none';
			});
		}
		gid('ka-pd-recurrence').addEventListener('change', syncRecurrence);
		document.querySelectorAll('input[name="ka-pd-loc"]').forEach(function(r) {
			r.addEventListener('change', syncLoc);
		});

		function setMode(isEdit) {
			if (titleEl) titleEl.textContent = isEdit ? 'Edit park day' : 'Add a park day';
			if (labelEl) labelEl.textContent = isEdit ? 'Save Changes' : 'Add Park Day';
		}
		function showPanel(isEdit) {
			setMode(isEdit);
			panel.style.display = '';
			if (openBtn) openBtn.disabled = true;
			syncRecurrence();
			syncLoc();
			try { gid('ka-pd-purpose').focus(); } catch (e) {}
		}
		function hidePanel() {
			panel.style.display = 'none';
			if (openBtn) openBtn.disabled = false;
			if (idEl) idEl.value = '0';
		}

		/* Reset-on-open clears every field; this puts the panel itself back to
		   hidden and the conditional rows back in step. It runs BEFORE the clean
		   snapshot, so nothing it changes counts as an unsaved edit. */
		kaOnOpen('ka-parkdays-overlay', function() {
			hidePanel();
			setMode(false);
			syncRecurrence();
			syncLoc();
		});

		if (openBtn) {
			openBtn.addEventListener('click', function() {
				kaClearFeedback('ka-pd-feedback');
				if (idEl) idEl.value = '0';
				['ka-pd-desc','ka-pd-time','ka-pd-startdate','ka-pd-address','ka-pd-city',
				 'ka-pd-province','ka-pd-postal','ka-pd-mapurl','ka-pd-locurl'].forEach(function(id) {
					var el = gid(id); if (el) el.value = '';
				});
				gid('ka-pd-purpose').value    = 'park-day';
				gid('ka-pd-recurrence').value = 'weekly';
				gid('ka-pd-weekday').value    = 'Saturday';
				gid('ka-pd-weekof').value     = '1';
				gid('ka-pd-monthday').value   = '1';
				gid('ka-pd-interval').value   = '2';
				gid('ka-pd-loc-park').checked = true;
				showPanel(false);
			});
		}
		if (cancelBtn) {
			cancelBtn.addEventListener('click', function() {
				kaClearFeedback('ka-pd-feedback');
				hidePanel();
			});
		}

		// Edit — fill the panel from the row's data-* attributes.
		document.querySelectorAll('.ka-pd-edit').forEach(function(b) {
			b.addEventListener('click', function() {
				var d = b.dataset;
				kaClearFeedback('ka-pd-feedback');
				if (idEl) idEl.value = d.dayId || '0';
				gid('ka-pd-purpose').value    = d.purpose    || 'park-day';
				gid('ka-pd-recurrence').value = d.recurrence || 'weekly';
				gid('ka-pd-weekday').value    = d.weekday    || 'Saturday';
				gid('ka-pd-weekof').value     = d.weekof     || '1';
				gid('ka-pd-monthday').value   = d.monthday   || '1';
				gid('ka-pd-interval').value   = (Number(d.interval) >= 2) ? d.interval : '2';
				gid('ka-pd-startdate').value  = d.startdate  || '';
				gid('ka-pd-time').value       = d.time       || '';
				gid('ka-pd-desc').value       = d.desc       || '';
				gid('ka-pd-address').value    = d.address    || '';
				gid('ka-pd-city').value       = d.city       || '';
				gid('ka-pd-province').value   = d.province   || '';
				gid('ka-pd-postal').value     = d.postal     || '';
				gid('ka-pd-mapurl').value     = d.mapurl     || '';
				gid('ka-pd-locurl').value     = d.locurl     || '';
				var mode = (d.online === '1') ? 'online' : ((d.altloc === '1') ? '1' : '0');
				document.querySelectorAll('input[name="ka-pd-loc"]').forEach(function(r) {
					r.checked = (r.value === mode);
				});
				showPanel(true);
				panel.scrollIntoView({ block: 'nearest' });
			});
		});

		// Delete — named in the prompt, because "this park day" is not enough to
		// tell two Saturday entries apart.
		document.querySelectorAll('.ka-pd-delete').forEach(function(b) {
			b.addEventListener('click', function() {
				var dayId = b.dataset.dayId;
				var label = b.dataset.label || 'this park day';
				kaConfirm(
					'<p>Remove <strong>' + kaEsc(label) + '</strong> from this park&rsquo;s schedule?</p>'
					+ '<ul class="ka-confirm-list">'
					+ '<li>It disappears from the park profile, the kingdom calendar and the public &ldquo;find a game near me&rdquo; search.</li>'
					+ '<li>Attendance already recorded is untouched &mdash; this removes the schedule entry, not the history.</li>'
					+ '</ul>',
					function() {
						b.disabled = true;
						kaPost(BASE_URL + 'deleteparkday', { ParkDayId: dayId }, null, 'ka-pd-feedback', function() {
							kaFeedback('ka-pd-feedback', 'Park day removed. Refreshing…', true);
							setTimeout(function() { location.reload(); }, 900);
						}, function() { b.disabled = false; });
					},
					'Remove Park Day',
					{ danger: true, html: true, okLabel: 'Remove', okIcon: 'fas fa-trash' }
				);
			});
		});

		saveBtn.addEventListener('click', function() {
			var recurrence = gid('ka-pd-recurrence').value;
			var time       = (gid('ka-pd-time').value || '').trim();
			var startDate  = (gid('ka-pd-startdate').value || '').trim();
			if (!time) { kaFeedback('ka-pd-feedback', 'A time is required.', false); return; }
			if (recurrence === 'every-x-weeks' && !startDate) {
				kaFeedback('ka-pd-feedback', 'A start date is required for the "every X weeks" cadence.', false);
				return;
			}
			var mode = locMode();
			var dayId = Number(idEl ? idEl.value : 0) || 0;
			var payload = {
				Recurrence:        recurrence,
				WeekDay:           gid('ka-pd-weekday').value,
				WeekOfMonth:       gid('ka-pd-weekof').value,
				MonthDay:          gid('ka-pd-monthday').value,
				StartDate:         startDate,
				WeekInterval:      gid('ka-pd-interval').value,
				Time:              time,
				Purpose:           gid('ka-pd-purpose').value,
				Description:       gid('ka-pd-desc').value,
				Online:            mode === 'online' ? '1' : '0',
				AlternateLocation: mode === '1' ? '1' : '0',
				Address:           gid('ka-pd-address').value,
				City:              gid('ka-pd-city').value,
				Province:          gid('ka-pd-province').value,
				PostalCode:        gid('ka-pd-postal').value,
				MapUrl:            gid('ka-pd-mapurl').value,
				LocationUrl:       gid('ka-pd-locurl').value,
			};
			var url = BASE_URL + 'addparkday';
			if (dayId > 0) { payload.ParkDayId = dayId; url = BASE_URL + 'editparkday'; }
			kaPost(url, payload, saveBtn, 'ka-pd-feedback', function() {
				kaFeedback('ka-pd-feedback', (dayId > 0 ? 'Park day updated.' : 'Park day added.') + ' Refreshing…', true);
				setTimeout(function() { location.reload(); }, 900);
			});
		});
	})();

	/* ══════════════════════════════════════════════
	   CREATE PLAYER
	   ══════════════════════════════════════════════ */
	(function() {
		var btn = gid('ka-cp-submit');
		if (!btn) return;

		/* Password is a two-way choice, not a box that may be left blank.
		   Player::CreatePlayer() writes NO credential row when Password is empty --
		   deliberately -- and the consequence is an account that cannot be signed
		   in to at all, which an unlabelled "optional" field never said out loud. */
		var PW_MIN  = 8;
		var pwWrap  = gid('ka-cp-pw-wrap');
		var pwInput = gid('ka-cp-password');

		function pwMode() {
			var picked = document.querySelector('input[name="ka-cp-pwmode"]:checked');
			return picked ? picked.value : 'self';
		}
		function syncPwMode() {
			var temp = pwMode() === 'temp';
			if (pwWrap) pwWrap.style.display = temp ? '' : 'none';
			if (pwInput) {
				pwInput.required = temp;
				pwInput.setAttribute('aria-required', temp ? 'true' : 'false');
				// Never carry a typed password into the "player sets their own" branch --
				// it would be posted and silently create the credential anyway.
				if (!temp) { pwInput.value = ''; pwInput.defaultValue = ''; }
			}
			document.querySelectorAll('input[name="ka-cp-pwmode"]').forEach(function(r) {
				var card = r.closest('.ka-choice-opt');
				if (card) card.classList.toggle('ka-choice-on', r.checked);
			});
		}
		document.querySelectorAll('input[name="ka-cp-pwmode"]').forEach(function(r) {
			r.addEventListener('change', syncPwMode);
		});
		// Reset-on-open restores the radios programmatically, which fires no change
		// event, so the disclosure has to be re-synced by hand each time it opens.
		kaOnOpen('ka-createplayer-overlay', syncPwMode);
		syncPwMode();

		btn.addEventListener('click', function() {
			var persona  = gid('ka-cp-persona').value.trim();
			var username = gid('ka-cp-username').value.trim();
			var temp     = pwMode() === 'temp';
			var password = temp && pwInput ? pwInput.value : '';
			if (!persona)            { kaFeedback('ka-cp-feedback', 'Persona is required.', false); return; }
			if (!username)           { kaFeedback('ka-cp-feedback', 'Username is required.', false); return; }
			if (username.length < 4) { kaFeedback('ka-cp-feedback', 'Username must be at least 4 characters.', false); return; }
			if (temp && password.length < PW_MIN) {
				kaFeedback('ka-cp-feedback', 'A temporary password must be at least ' + PW_MIN + ' characters.', false);
				if (pwInput) pwInput.focus();
				return;
			}
			var restricted = document.querySelector('input[name="ka-cp-restricted"]:checked');
			var waivered   = document.querySelector('input[name="ka-cp-waivered"]:checked');
			kaPost(UIR + 'PlayerAjax/park/' + PkaConfig.parkId + '/create', {
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
		var btn = gid('ka-mp-submit');
		if (!btn) return;

		function mpCheck() { btn.disabled = !gid('ka-mp-player-id').value; }
		/* scope=exclude -- everyone whose home park is NOT this one, ordered with
		   the nearby parks first. Offering a player who already lives here would be
		   a no-op the officer only discovers after clicking. */
		kaAc({ inputId:'ka-mp-player-name', hiddenId:'ka-mp-player-id', resultsId:'ka-mp-player-results',
			fetchFn: kaSearchPlayers('exclude', true), onSelect: mpCheck, onClear: mpCheck });

		btn.addEventListener('click', function() {
			var playerId = gid('ka-mp-player-id').value;
			if (!playerId) return;
			kaPost(BASE_URL + 'moveplayer', { MundaneId: playerId, DestParkId: PkaConfig.parkId },
				btn, 'ka-mp-feedback', function() {
					kaFeedback('ka-mp-feedback',
						'Player moved to ' + kaEsc(PARK_NAME) + '. <a href="' + UIR + 'Player/profile/' + encodeURIComponent(playerId) + '">View player</a>', true);
					gid('ka-mp-player-name').value = '';
					gid('ka-mp-player-id').value   = '';
					btn.disabled = true;
				});
		});
	})();

	/* ══════════════════════════════════════════════
	   MERGE PLAYERS
	   ══════════════════════════════════════════════ */
	(function() {
		var btn = gid('ka-mgp-submit');
		if (!btn) return;

		var previewBox = gid('ka-mgp-preview');
		var countEl    = gid('ka-mgp-arm-count');
		var labelEl    = gid('ka-mgp-submit-label');
		var swapBtn    = gid('ka-mgp-swap');

		var picked = { keep: null, remove: null };

		function statusOf(p) {
			var bits = [Number(p.Active) === 0 ? 'Inactive' : 'Active'];
			if (Number(p.Suspended) === 1) bits.push('Suspended');
			return bits.join(' &middot; ');
		}
		function row(label, value) {
			return '<div><span class="ka-mgp-dt">' + label + '</span><span class="ka-mgp-dd">' + value + '</span></div>';
		}
		function cardHtml(p, role) {
			var isKeep = (role === 'keep');
			return '<span class="ka-mgp-role">'
					+ '<i class="fas ' + (isKeep ? 'fa-shield-alt' : 'fa-skull-crossbones') + '" aria-hidden="true"></i> '
					+ (isKeep ? 'Keep' : 'Delete') + '</span>'
				+ '<span class="ka-mgp-name">' + kaEsc(p.Persona) + '</span>'
				+ '<div class="ka-mgp-dl">'
					// kaEsc() around the entity, not around the fallback: escaping
					// '&mdash;' would print the literal text "&mdash;".
					+ row('Park', p.ParkName ? kaEsc(p.ParkName) : '&mdash;')
					+ row('Kingdom', (p.KingdomName ? kaEsc(p.KingdomName) : '&mdash;') + (p.KAbbr ? ' (' + kaEsc(p.KAbbr) + ')' : ''))
					+ row('Status', statusOf(p))
					+ row('Player ID', '#' + kaEsc(p.MundaneId))
				+ '</div>'
				+ '<a class="ka-mgp-link" href="' + UIR + 'Player/profile/' + encodeURIComponent(p.MundaneId) + '" target="_blank" rel="noopener">'
					+ 'Open profile <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>';
		}
		function renderPreview() {
			if (!previewBox) return;
			if (!picked.keep || !picked.remove) { previewBox.style.display = 'none'; return; }
			gid('ka-mgp-card-keep').innerHTML   = cardHtml(picked.keep, 'keep');
			gid('ka-mgp-card-remove').innerHTML = cardHtml(picked.remove, 'remove');
			previewBox.style.display = '';
		}

		/* ---- Wait-to-arm (see the .ka-arm-btn contract in admin-console.css) ----
		   Deliberately NOT a type-the-name gate: the officer already typed the name
		   to find the player. Five seconds of an inert button is the real pause. */
		var ARM_SECONDS = 5;
		var armTimer = null, armLeft = 0, armed = false;

		function setLabel(text) { if (labelEl) labelEl.textContent = text; }
		function disarm() {
			if (armTimer) { clearInterval(armTimer); armTimer = null; }
			armed = false; armLeft = 0;
			btn.classList.remove('ka-arm-waiting', 'ka-arm-ready');
			btn.removeAttribute('aria-disabled');
			btn.style.removeProperty('--ka-arm-delay');
			if (countEl) { countEl.style.display = 'none'; countEl.textContent = ''; }
			setLabel('Merge Players');
		}
		function startArming() {
			armLeft = ARM_SECONDS;
			armed = false;
			btn.classList.remove('ka-arm-ready');
			btn.classList.add('ka-arm-waiting');
			btn.disabled = true;
			btn.setAttribute('aria-disabled', 'true');
			btn.style.setProperty('--ka-arm-delay', ARM_SECONDS + 's');
			setLabel('Merging in');
			if (countEl) { countEl.style.display = ''; countEl.textContent = String(armLeft); }
			if (armTimer) clearInterval(armTimer);
			armTimer = setInterval(function() {
				armLeft--;
				if (countEl) countEl.textContent = String(Math.max(0, armLeft));
				if (armLeft > 0) return;
				clearInterval(armTimer); armTimer = null;
				armed = true;
				btn.classList.remove('ka-arm-waiting');
				btn.classList.add('ka-arm-ready');
				btn.disabled = false;
				btn.removeAttribute('aria-disabled');
				if (countEl) countEl.style.display = 'none';
				setLabel('Confirm merge');
				try { btn.focus(); } catch (e) {}
			}, 1000);
		}

		function mgpCheck() {
			var keep   = gid('ka-mgp-keep-id').value;
			var remove = gid('ka-mgp-remove-id').value;
			// Any change to either side is a new decision: throw away the countdown.
			disarm();
			btn.disabled = !(keep && remove);
			if (!keep)   picked.keep = null;
			if (!remove) picked.remove = null;
			renderPreview();
		}
		function onPick(side) {
			return function(id, label, extra) {
				try { picked[side] = extra ? JSON.parse(extra) : null; } catch (e) { picked[side] = null; }
				mgpCheck();
			};
		}
		var mergeSearch = kaSearchPlayers('all', true);
		kaAc({ inputId:'ka-mgp-keep-name', hiddenId:'ka-mgp-keep-id', resultsId:'ka-mgp-keep-results',
			fetchFn: mergeSearch, onSelect: onPick('keep'), onClear: mgpCheck });
		kaAc({ inputId:'ka-mgp-remove-name', hiddenId:'ka-mgp-remove-id', resultsId:'ka-mgp-remove-results',
			fetchFn: mergeSearch, onSelect: onPick('remove'), onClear: mgpCheck });

		/* Picking Keep/Remove the wrong way round is the failure this whole modal
		   exists to prevent, and re-searching both boxes to fix it is exactly when
		   someone gives up and just clicks Merge. */
		if (swapBtn) {
			swapBtn.addEventListener('click', function() {
				var kn = gid('ka-mgp-keep-name'), rn = gid('ka-mgp-remove-name');
				var ki = gid('ka-mgp-keep-id'),   ri = gid('ka-mgp-remove-id');
				var t;
				t = kn.value; kn.value = rn.value; rn.value = t;
				t = ki.value; ki.value = ri.value; ri.value = t;
				t = picked.keep; picked.keep = picked.remove; picked.remove = t;
				mgpCheck();
				/* Not kaFeedback(): a success feedback re-baselines the dirty guard,
				   and swapping two picks saves nothing. Plain aria-live status. */
				var hint = gid('ka-mgp-swap-hint');
				if (hint) hint.textContent = kn.value
					? 'Swapped — ' + kn.value + ' is now the record that survives.'
					: 'Swapped.';
			});
		}

		// Reopening must not inherit a half-finished decision or a live countdown.
		var SWAP_HINT_IDLE = (gid('ka-mgp-swap-hint') || {}).textContent || '';
		kaOnOpen('ka-mergeplayer-overlay', function() {
			picked.keep = null; picked.remove = null;
			disarm();
			renderPreview();
			var hint = gid('ka-mgp-swap-hint');
			if (hint) hint.textContent = SWAP_HINT_IDLE;
		});

		btn.addEventListener('click', function() {
			var keepId   = gid('ka-mgp-keep-id').value;
			var removeId = gid('ka-mgp-remove-id').value;
			if (!keepId || !removeId) return;
			if (keepId === removeId) { kaFeedback('ka-mgp-feedback', 'Cannot merge a player with themselves.', false); return; }
			if (!armed) { startArming(); return; }
			disarm();
			setLabel('Merging…');
			kaPost(UIR + 'PlayerAjax/merge', { ToMundaneId: keepId, FromMundaneId: removeId },
				btn, 'ka-mgp-feedback',
				function() { window.location.href = UIR + 'Player/profile/' + keepId; },
				function() { setLabel('Merge Players'); });
		});
	})();

	/* ══════════════════════════════════════════════
	   OPERATIONS
	   ══════════════════════════════════════════════ */
	(function() {
		var resetBtn = gid('ka-ops-reset-waivers');
		if (!resetBtn) return;
		resetBtn.addEventListener('click', function() {
			var raw   = resetBtn.dataset.count;
			var count = (raw === '' || raw === undefined) ? null : Number(raw);
			/* The confirm carries the two facts the officer needs and did not have:
			   HOW MANY players it touches (read before the reset runs -- the answer is
			   always zero afterwards), and that the UPDATE has no active filter, so it
			   reaches inactive and retired members too. */
			var head = (count === null)
				? '<p>This clears the waiver flag for every player in this park.</p>'
				: '<p>This clears the waiver flag for <strong>' + kaEsc(count.toLocaleString())
					+ '</strong> player' + (count === 1 ? '' : 's') + ' in this park.</p>';
			kaConfirm(
				head
				+ '<ul class="ka-confirm-list">'
				+ '<li><strong>Inactive and retired members are cleared too.</strong> The update is scoped to this park and nothing else &mdash; there is no active filter.</li>'
				+ '<li>Everyone affected has to sign a new waiver before their flag can go back on.</li>'
				+ '<li>There is no undo here, but every player cleared is written to the Audit Log, so the list can be recovered.</li>'
				+ '</ul>',
				function() {
					resetBtn.disabled = true;
					kaPost(BASE_URL + 'resetwaivers', {}, null, 'ka-ops-feedback', function(r) {
						// Nothing left to clear, so the button has no work to do until
						// the page is reloaded with fresh counts.
						resetBtn.dataset.count = '0';
						var blast = gid('ka-ops-blast');
						if (blast) {
							blast.className = 'ka-ops-blast ka-muted';
							blast.textContent = 'No players in this park currently hold a waiver, so there is nothing left to clear.';
						}
						kaFeedback('ka-ops-feedback', r.message || 'Waivers reset.', true);
					}, function() { resetBtn.disabled = (resetBtn.dataset.count === '0'); });
				},
				'Reset Waivers',
				{ danger: true, html: true, okLabel: 'Reset waivers', okIcon: 'fas fa-eraser' }
			);
		});
	})();

})();
</script>
