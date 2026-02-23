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

<style type="text/css">
/* ========================================
   Eventcreate — New Occurrence Form
   All classes prefixed ec- to avoid collisions
   ======================================== */

.ec-wrap, .ec-banner, .ec-form-card, .ec-action-bar { box-sizing: border-box; }

/* ---- Context banner ---- */
.ec-banner {
	position: relative;
	border-radius: 10px 10px 0 0;
	overflow: hidden;
	background: #2d3748;
	padding: 22px 30px;
	display: flex;
	align-items: center;
	gap: 20px;
	margin-bottom: 0;
}
.ec-banner-bg {
	position: absolute;
	inset: -10px;
	background-size: cover;
	background-position: center;
	opacity: 0.12;
	filter: blur(6px);
	pointer-events: none;
}
.ec-banner-heraldry {
	width: 64px;
	height: 64px;
	border-radius: 6px;
	border: 2px solid rgba(255,255,255,0.7);
	overflow: hidden;
	flex-shrink: 0;
	background: rgba(0,0,0,0.2);
	display: flex;
	align-items: center;
	justify-content: center;
	position: relative;
}
.ec-banner-heraldry img {
	width: 100%;
	height: 100%;
	object-fit: contain;
}
.ec-banner-info { position: relative; flex: 1; min-width: 0; }
.ec-banner-label {
	font-size: 10px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.08em;
	color: rgba(255,255,255,0.55);
	margin-bottom: 3px;
}
.ec-banner-name {
	font-size: 22px;
	font-weight: 700;
	color: #fff;
	text-shadow: 0 1px 3px rgba(0,0,0,0.35);
	margin: 0 0 4px 0;
	line-height: 1.2;
}
.ec-banner-crumb {
	font-size: 12px;
	color: rgba(255,255,255,0.75);
}
.ec-banner-crumb a { color: #fff; font-weight: 600; text-decoration: none; }
.ec-banner-crumb a:hover { text-decoration: underline; }
.ec-banner-crumb span { margin: 0 6px; opacity: 0.4; }

/* ---- Main wrap ---- */
.ec-wrap {
	max-width: 780px;
	margin: 0 auto 80px;
}

/* ---- Form card ---- */
.ec-form-card {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-top: none;
	border-radius: 0 0 8px 8px;
	padding: 0;
}

/* ---- Error banner ---- */
.ec-error {
	background: #fff5f5;
	border-bottom: 1px solid #feb2b2;
	color: #c53030;
	padding: 12px 24px;
	font-size: 13px;
}

/* ---- Sections ---- */
.ec-section {
	padding: 20px 24px;
	border-bottom: 1px solid #f0f4f8;
}
.ec-section:last-child { border-bottom: none; }
.ec-section-title {
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.07em;
	color: #718096;
	margin: 0 0 14px 0;
	padding-bottom: 8px;
	border-bottom: 1px solid #e2e8f0;
	display: flex;
	align-items: center;
	gap: 7px;
}
.ec-section-title i { color: #a0aec0; }

/* ---- Field rows ---- */
.ec-row { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 12px; }
.ec-row:last-child { margin-bottom: 0; }
.ec-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1;
	min-width: 160px;
}
.ec-field.ec-full { flex-basis: 100%; min-width: 100%; }
.ec-field.ec-sm   { max-width: 120px; flex: none; }
.ec-field.ec-md   { max-width: 200px; }

.ec-field label {
	font-size: 11px;
	font-weight: 600;
	color: #718096;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}
.ec-field input[type="text"],
.ec-field input[type="number"],
.ec-field input[type="datetime-local"],
.ec-field textarea {
	padding: 8px 10px;
	border: 1px solid #cbd5e0;
	border-radius: 5px;
	font-size: 13px;
	background: #fff;
	color: #2d3748;
	width: 100%;
	box-sizing: border-box;
	transition: border-color 0.15s, box-shadow 0.15s;
}
.ec-field input:focus,
.ec-field textarea:focus {
	outline: none;
	border-color: #63b3ed;
	box-shadow: 0 0 0 2px rgba(66,153,225,0.15);
}
.ec-field textarea { resize: vertical; min-height: 120px; font-family: inherit; line-height: 1.5; }
.ec-field-hint { font-size: 11px; color: #a0aec0; margin-top: 2px; }

.ec-required { color: #e53e3e; margin-left: 2px; }

/* ---- Action bar ---- */
.ec-action-bar {
	position: sticky;
	bottom: 0;
	background: #fff;
	border-top: 1px solid #e2e8f0;
	padding: 14px 24px;
	display: flex;
	align-items: center;
	justify-content: space-between;
	box-shadow: 0 -2px 8px rgba(0,0,0,0.06);
	border-radius: 0 0 8px 8px;
	z-index: 10;
}
.ec-action-bar-left { font-size: 12px; color: #a0aec0; }
.ec-action-bar-right { display: flex; gap: 10px; align-items: center; }
.ec-btn-cancel {
	background: #e2e8f0;
	color: #4a5568;
	border: none;
	border-radius: 5px;
	padding: 9px 18px;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
	text-decoration: none;
	display: inline-block;
}
.ec-btn-cancel:hover { background: #cbd5e0; }
.ec-btn-submit {
	background: #276749;
	color: #fff;
	border: none;
	border-radius: 5px;
	padding: 9px 22px;
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
	display: inline-flex;
	align-items: center;
	gap: 7px;
}
.ec-btn-submit:hover { background: #22543d; }
.ec-btn-submit:disabled { opacity: 0.5; cursor: default; }

/* ---- Held At autocomplete ---- */
.ec-ac-wrap { position: relative; }
.ec-ac-results {
	position: absolute;
	top: 100%;
	left: 0; right: 0;
	background: #fff;
	border: 1px solid #cbd5e0;
	border-top: none;
	border-radius: 0 0 5px 5px;
	box-shadow: 0 4px 12px rgba(0,0,0,0.1);
	z-index: 100;
	max-height: 240px;
	overflow-y: auto;
}
.ec-ac-item {
	padding: 8px 12px;
	cursor: pointer;
	display: flex;
	align-items: center;
	gap: 10px;
	font-size: 13px;
	border-bottom: 1px solid #f0f4f8;
}
.ec-ac-item:last-child { border-bottom: none; }
.ec-ac-item:hover, .ec-ac-item.focused { background: #ebf8ff; }
.ec-ac-item-name { font-weight: 600; color: #2d3748; }
.ec-ac-item-sub { font-size: 11px; color: #718096; }
.ec-ac-badge {
	font-size: 10px;
	font-weight: 700;
	padding: 2px 6px;
	border-radius: 3px;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	flex-shrink: 0;
}
.ec-ac-badge-park { background: #e6fffa; color: #234e52; }
.ec-ac-badge-kingdom { background: #ebf4ff; color: #2c5282; }
.ec-ac-no-results { padding: 10px 12px; font-size: 12px; color: #a0aec0; text-align: center; }
.ec-held-at-optional { font-weight: 400; color: #a0aec0; font-size: 10px; text-transform: none; letter-spacing: 0; margin-left: 4px; }
</style>

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
(function() {
	// ---- Hero dominant-color tint ----
	function ecApplyBannerColor() {
		var img = document.getElementById('ec-heraldry-img');
		if (!img) return;
		function extract() {
			try {
				var c = document.createElement('canvas');
				c.width = 32; c.height = 32;
				var ctx = c.getContext('2d');
				ctx.drawImage(img, 0, 0, 32, 32);
				var d = ctx.getImageData(0, 0, 32, 32).data;
				var r=0, g=0, b=0, count=0;
				for (var i=0; i<d.length; i+=4) {
					if (d[i+3]>30) { r+=d[i]; g+=d[i+1]; b+=d[i+2]; count++; }
				}
				if (!count) return;
				r=Math.round(r/count); g=Math.round(g/count); b=Math.round(b/count);
				var max=Math.max(r,g,b)/255, min=Math.min(r,g,b)/255;
				var l=(max+min)/2;
				var s=max===min?0:(l<0.5?(max-min)/(max+min):(max-min)/(2-max-min));
				var h=0;
				if (max!==min) {
					var d2=(max-min)/255;
					if (max===r/255) h=(g/255-b/255)/d2+(g<b?6:0);
					else if (max===g/255) h=(b/255-r/255)/d2+2;
					else h=(r/255-g/255)/d2+4;
					h*=60;
				}
				var banner = document.getElementById('ec-banner');
				if (banner) banner.style.backgroundColor = 'hsl('+Math.round(h)+','+Math.round(s*55)+'%,18%)';
			} catch(e){}
		}
		if (img.complete && img.naturalWidth > 0) { extract(); }
		else { img.addEventListener('load', extract); }
	}
	ecApplyBannerColor();

	// ---- Auto-fill end date when start date changes ----
	var startInput = document.getElementById('ec-StartDate');
	var endInput   = document.getElementById('ec-EndDate');
	if (startInput && endInput) {
		startInput.addEventListener('change', function() {
			if (!endInput.value && this.value) {
				// Default end = start + 2 days
				var d = new Date(this.value);
				d.setDate(d.getDate() + 2);
				var pad = function(n) { return String(n).padStart(2,'0'); };
				endInput.value = d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())
					+'T'+pad(d.getHours())+':'+pad(d.getMinutes());
			}
		});
	}

	// ---- Prevent double-submit ----
	document.getElementById('ec-form').addEventListener('submit', function() {
		var btn = document.getElementById('ec-submit-btn');
		if (btn) {
			btn.disabled = true;
			btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating…';
		}
	});
	// ---- Held At autocomplete ----
	(function() {
		var textEl   = document.getElementById('ec-heldat-text');
		var hiddenEl = document.getElementById('ec-heldat-parkid');
		var results  = document.getElementById('ec-ac-results');
		if (!textEl || !hiddenEl || !results) return;

		var delay, activeIndex = -1;
		var SEARCH_URL = '<?= HTTP_SERVICE ?>Search/SearchService.php';

		function esc(s) {
			return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
		}

		function showResults(items) {
			results.innerHTML = '';
			activeIndex = -1;
			if (!items.length) {
				results.innerHTML = '<div class="ec-ac-no-results">No results found</div>';
				results.style.display = 'block';
				return;
			}
			items.forEach(function(item) {
				var div = document.createElement('div');
				div.className = 'ec-ac-item';
				var badgeCls = item.type === 'park' ? 'ec-ac-badge-park' : 'ec-ac-badge-kingdom';
				var badge    = item.type === 'park' ? 'Park' : 'Kingdom';
				div.innerHTML =
					'<span class="ec-ac-badge ' + badgeCls + '">' + badge + '</span>' +
					'<span>' +
						'<span class="ec-ac-item-name">' + esc(item.name) + '</span>' +
						(item.sub ? '<br><span class="ec-ac-item-sub">' + esc(item.sub) + '</span>' : '') +
					'</span>';
				div.addEventListener('mousedown', function(e) {
					e.preventDefault();
					select(item);
				});
				results.appendChild(div);
			});
			results.style.display = 'block';
		}

		function select(item) {
			textEl.value   = item.name;
			hiddenEl.value = item.parkId != null ? item.parkId : '';
			results.style.display = 'none';
			activeIndex = -1;
		}

		function closeResults() {
			results.style.display = 'none';
			activeIndex = -1;
		}

		function search(q) {
			if (q.length < 2) { closeResults(); return; }
			var parkUrl = SEARCH_URL + '?Action=Search%2FPark&name=' + encodeURIComponent(q) + '&limit=8';
			var kingUrl = SEARCH_URL + '?Action=Search%2FKingdom&name=' + encodeURIComponent(q) + '&limit=4';
			Promise.all([
				fetch(parkUrl).then(function(r) { return r.json(); }).catch(function() { return []; }),
				fetch(kingUrl).then(function(r) { return r.json(); }).catch(function() { return []; })
			]).then(function(all) {
				var parks    = (all[0] || []).map(function(p) {
					return { type: 'park', name: p.Name, parkId: p.ParkId, sub: null };
				});
				var kingdoms = (all[1] || []).map(function(k) {
					return { type: 'kingdom', name: k.Name, parkId: null };
				});
				showResults(parks.concat(kingdoms));
			});
		}

		textEl.addEventListener('input', function() {
			clearTimeout(delay);
			hiddenEl.value = '';
			var q = this.value.trim();
			delay = setTimeout(function() { search(q); }, 220);
		});

		textEl.addEventListener('keydown', function(e) {
			var items = results.querySelectorAll('.ec-ac-item');
			if (!items.length) return;
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				if (activeIndex < items.length - 1) activeIndex++;
				items.forEach(function(el, i) { el.classList.toggle('focused', i === activeIndex); });
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				if (activeIndex > 0) activeIndex--;
				items.forEach(function(el, i) { el.classList.toggle('focused', i === activeIndex); });
			} else if (e.key === 'Enter' && activeIndex >= 0) {
				e.preventDefault();
				items[activeIndex].dispatchEvent(new MouseEvent('mousedown'));
			} else if (e.key === 'Escape') {
				closeResults();
			}
		});

		textEl.addEventListener('blur', function() {
			setTimeout(closeResults, 150);
		});
	})();

})();
</script>
