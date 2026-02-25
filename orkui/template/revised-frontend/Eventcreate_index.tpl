<?php
	// ---- Normalize data ----
	$info     = $EventInfo    ?? [];
	$details  = $EventDetails ?? [];
	$eventId  = (int)($event_id ?? 0);

	$eventName   = htmlspecialchars($details['Name'] ?? $info['Name'] ?? 'Event');
	$hasHeraldry = !empty($details['HasHeraldry']);
	$heraldryUrl = $hasHeraldry
		? HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf('%05d', $eventId))
		: HTTP_EVENT_HERALDRY . '00000.jpg';

	$kingdomId   = (int)($info['KingdomId'] ?? 0);
	$kingdomName = htmlspecialchars($info['KingdomName'] ?? '');
	$parkId      = (int)($info['ParkId'] ?? 0);
	$parkName    = htmlspecialchars($info['ParkName'] ?? '');
	$atParkId    = (int)($AtParkId ?? 0);
	$atParkName  = htmlspecialchars($AtParkName ?? '');
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css">

<div class="ec-wrap">

	<?php // ---- Context banner ---- ?>
	<div class="ec-banner" id="ec-banner">
		<?php if ($heraldryUrl): ?>
		<div class="ec-banner-bg"
			style="background-image:url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
		<?php endif; ?>

		<div class="ec-banner-heraldry">
			<img id="ec-heraldry-img"
				src="<?= htmlspecialchars($heraldryUrl) ?>"
				onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
				alt="<?= $eventName ?> heraldry"
				crossorigin="anonymous">
		</div>

		<div class="ec-banner-info">
			<div class="ec-banner-label"><i class="fas fa-plus-circle" style="margin-right:4px"></i>New Scheduled Occurrence</div>
			<h1 class="ec-banner-name"><?= $eventName ?></h1>
			<div class="ec-banner-crumb">
				<a href="<?= UIR ?>Event/template/<?= $eventId ?>"><?= $eventName ?></a>
				<?php if ($kingdomId): ?>
					<span>›</span>
					<a href="<?= UIR ?>Kingdom/profile/<?= $kingdomId ?>"><?= $kingdomName ?></a>
				<?php endif; ?>
				<?php if ($parkId): ?>
					<span>›</span>
					<a href="<?= UIR ?>Park/profile/<?= $parkId ?>"><?= $parkName ?></a>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php // ---- Form card ---- ?>
	<div class="ec-form-card">

		<?php if (!empty($Error)): ?>
		<div class="ec-error">
			<i class="fas fa-exclamation-triangle" style="margin-right:6px"></i><?= $Error ?>
		</div>
		<?php endif; ?>

		<form method="post" action="<?= UIR ?>Event/create/<?= $eventId ?>" id="ec-form">

			<?php // ---- Section: Dates & Pricing ---- ?>
			<div class="ec-section">
				<h4 class="ec-section-title">
					<i class="fas fa-calendar-alt"></i> Dates &amp; Pricing
				</h4>
				<div class="ec-row">
					<div class="ec-field">
						<label>Start Date &amp; Time <span class="ec-required">*</span></label>
						<input type="datetime-local" name="StartDate" id="ec-StartDate" required>
					</div>
					<div class="ec-field">
						<label>End Date &amp; Time</label>
						<input type="datetime-local" name="EndDate" id="ec-EndDate">
					</div>
					<div class="ec-field ec-sm">
						<label>Price ($)</label>
						<input type="number" name="Price" min="0" step="0.01" value="0.00">
						<span class="ec-field-hint">Leave 0 for free</span>
					</div>
				</div>
			</div>

			<?php // ---- Section: Description ---- ?>
			<div class="ec-section">
				<h4 class="ec-section-title">
					<i class="fas fa-align-left"></i> Description
				</h4>
				<div class="ec-row">
					<div class="ec-field ec-full">
						<label>Event Description</label>
						<textarea name="Description" rows="6" placeholder="Describe the event — activities, schedule, what to bring, camping info, etc."></textarea>
						<span class="ec-field-hint">HTML is supported.</span>
					</div>
				</div>
			</div>

			<?php // ---- Section: Held At ---- ?>
			<div class="ec-section">
				<h4 class="ec-section-title">
					<i class="fas fa-map-marker-alt"></i> Held At
					<span class="ec-held-at-optional">(optional)</span>
				</h4>
				<div class="ec-row">
					<div class="ec-field ec-full">
						<label>Park or Kingdom</label>
						<div class="ec-ac-wrap">
							<input type="text" id="ec-heldat-text"
								autocomplete="off"
								placeholder="Search parks and kingdoms..."
								value="<?= $atParkName ?>">
							<div class="ec-ac-results" id="ec-ac-results" style="display:none"></div>
						</div>
						<input type="hidden" name="AtParkId" id="ec-heldat-parkid" value="<?= $atParkId ?: '' ?>">
						<span class="ec-field-hint">Where this occurrence takes place. Leave blank if hosted at the kingdom level.</span>
					</div>
				</div>
			</div>

			<?php // ---- Section: Location ---- ?>
			<div class="ec-section">
				<h4 class="ec-section-title">
					<i class="fas fa-map-marker-alt"></i> Location
				</h4>
				<div class="ec-row">
					<div class="ec-field ec-full">
						<label>Street Address</label>
						<input type="text" name="Address" placeholder="123 Main St">
					</div>
				</div>
				<div class="ec-row">
					<div class="ec-field">
						<label>City</label>
						<input type="text" name="City" placeholder="Anytown">
					</div>
					<div class="ec-field ec-md">
						<label>Province / State</label>
						<input type="text" name="Province" placeholder="TX">
					</div>
					<div class="ec-field ec-sm">
						<label>Postal Code</label>
						<input type="text" name="PostalCode" placeholder="78201">
					</div>
					<div class="ec-field ec-md">
						<label>Country</label>
						<input type="text" name="Country" value="USA">
					</div>
				</div>
			</div>

			<?php // ---- Section: Links ---- ?>
			<div class="ec-section">
				<h4 class="ec-section-title">
					<i class="fas fa-link"></i> Links
				</h4>
				<div class="ec-row">
					<div class="ec-field">
						<label>Website URL</label>
						<input type="text" name="Url" placeholder="https://…">
					</div>
					<div class="ec-field">
						<label>Website Link Text</label>
						<input type="text" name="UrlName" placeholder="Event Website">
					</div>
				</div>
				<div class="ec-row">
					<div class="ec-field">
						<label>Map URL</label>
						<input type="text" name="MapUrl" placeholder="https://maps.google.com/…">
					</div>
					<div class="ec-field">
						<label>Map Link Text</label>
						<input type="text" name="MapUrlName" placeholder="Campsite Map">
					</div>
				</div>
			</div>

			<?php // ---- Action bar ---- ?>
			<div class="ec-action-bar">
				<div class="ec-action-bar-left">
					<i class="fas fa-info-circle" style="margin-right:4px"></i>
					You can edit any of these details after creation.
				</div>
				<div class="ec-action-bar-right">
					<a class="ec-btn-cancel" href="<?= UIR ?>Event/template/<?= $eventId ?>">
						Cancel
					</a>
					<button type="submit" class="ec-btn-submit" id="ec-submit-btn">
						<i class="fas fa-calendar-plus"></i> Create Occurrence
					</button>
				</div>
			</div>

		</form>
	</div><!-- /.ec-form-card -->

</div><!-- /.ec-wrap -->

<script>
var EcConfig = {
	httpService: '<?= HTTP_SERVICE ?>',
};
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js"></script>
