<style>
/* Dark mode overrides for inline-styled disclaimer modal */
html[data-theme="dark"] #uc-legacy-confirm > div { background: #2d3748 !important; box-shadow: 0 4px 24px rgba(0,0,0,0.5) !important; color: #e2e8f0 !important; }
html[data-theme="dark"] #uc-legacy-confirm > div > div { border-color: #4a5568 !important; color: #e2e8f0 !important; }
html[data-theme="dark"] #uc-legacy-confirm p { color: #cbd5e0 !important; }
html[data-theme="dark"] #uc-legacy-confirm-back { background: #374151 !important; border-color: #4a5568 !important; color: #e2e8f0 !important; }
html[data-theme="dark"] .info-container > div[style*="background:#ebf8ff"] { background: rgba(66,153,225,0.1) !important; border-color: #4299e1 !important; color: #90cdf4 !important; }
</style>
<div class='info-container'>
	<h3>Create Company or Household</h3>
<?php if (strlen($Error) > 0) : ?>
	<div class='error-message'><?=$Error ?></div>
<?php endif; ?>
	<div style="background:#ebf8ff;border:1px solid #bee3f8;border-radius:4px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:flex-start;gap:8px;font-size:13px;color:#2c5282;line-height:1.5;">
		<i class="fas fa-info-circle" style="margin-top:2px;flex-shrink:0;color:#3182ce;"></i>
		<span>This creates a <strong>brand new</strong> Company or Household with you as the manager. To join an existing unit, ask its manager to add you.</span>
	</div>
	<form class='form-container' method='post' action='<?=UIR ?>Unit/create/<?=$MundaneId ?>&Action=create' enctype='multipart/form-data' id='uc-legacy-form'>
		<div>
			<span>Heraldry:</span>
			<span>
				<img class='heraldry-img' src='<?=$Unit['Details']['Unit']['HasHeraldry']?$Unit_heraldryurl['Url']:(HTTP_UNIT_HERALDRY.'00000.jpg') ?>' />
				<input type='file' name='Heraldry' class='restricted-image-type' id='Heraldry' />
			</span>
		</div>
		<div>
			<span>Name:</span>
			<span><input type='text' class='name-field required-field' value='<?=htmlentities($Unit['Details']['Unit']['Name'], ENT_QUOTES) ?>' name='Name' /></span>
		</div>
		<div>
			<span>Type:</span>
			<span>
				<select name='Type' class='required-field'>
					<option>Company</option>
					<option>Household</option>
					<option>Event</option>
				</select>
			</span>
		</div>
		<div>
			<span>Url:</span>
			<span><input type='text' value='<?=htmlentities($Unit['Details']['Unit']['Url'], ENT_QUOTES) ?>' name='Url' /></span>
		</div>
		<div>
			<span>Description:</span>
			<span class='form-informational-field'><textarea name='Description' rows=10 cols=50><?=$Unit['Details']['Unit']['Description'] ?></textarea></span>
		</div>
		<div>
			<span>History:</span>
			<span class='form-informational-field'><textarea name='History' rows=10 cols=50><?=$Unit['Details']['Unit']['History'] ?></textarea></span>
		</div>
		<div>
			<span></span>
			<span><input type='button' value='Create Unit' id='uc-legacy-submit-btn' /></span>
		</div>
	</form>
</div>

<div id="uc-legacy-confirm" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
	<div style="background:#fff;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,0.18);width:400px;max-width:calc(100vw - 40px);overflow:hidden;">
		<div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:8px;">
			<i class="fas fa-shield-alt" style="color:#3182ce;"></i>
			<strong style="font-size:15px;">Confirm Creation</strong>
		</div>
		<div style="padding:20px;">
			<p style="margin:0 0 8px;font-size:14px;">You are about to create a new <strong id="uc-legacy-confirm-type"></strong> named <strong id="uc-legacy-confirm-name"></strong>.</p>
			<p style="margin:0;font-size:13px;color:#718096;">You will become its manager. Other players must be added by a manager — they cannot join on their own.</p>
		</div>
		<div style="padding:12px 20px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:8px;">
			<button type="button" id="uc-legacy-confirm-back" style="padding:7px 16px;border:1px solid #e2e8f0;border-radius:6px;background:#f7fafc;cursor:pointer;font-size:13px;">Go Back</button>
			<button type="button" id="uc-legacy-confirm-yes" style="padding:7px 16px;border:none;border-radius:6px;background:#3182ce;color:#fff;cursor:pointer;font-size:13px;font-weight:600;"><i class="fas fa-check"></i> Yes, Create It</button>
		</div>
	</div>
</div>
<script>
(function() {
	var submitBtn  = document.getElementById('uc-legacy-submit-btn');
	var confirmBox = document.getElementById('uc-legacy-confirm');
	var backBtn    = document.getElementById('uc-legacy-confirm-back');
	var yesBtn     = document.getElementById('uc-legacy-confirm-yes');
	var form       = document.getElementById('uc-legacy-form');
	if (!submitBtn || !form) return;
	submitBtn.addEventListener('click', function() {
		var nameEl = form.querySelector('input[name="Name"]');
		var typeEl = form.querySelector('select[name="Type"]');
		var name   = nameEl ? nameEl.value.trim() : '';
		var type   = typeEl ? typeEl.value : 'unit';
		if (!name) { if (nameEl) nameEl.focus(); return; }
		document.getElementById('uc-legacy-confirm-name').textContent = name;
		document.getElementById('uc-legacy-confirm-type').textContent = type;
		confirmBox.style.display = 'flex';
	});
	backBtn.addEventListener('click', function() { confirmBox.style.display = 'none'; });
	yesBtn.addEventListener('click', function() {
		confirmBox.style.display = 'none';
		var hidden = document.createElement('input');
		hidden.type = 'hidden'; hidden.name = 'CreateUnit'; hidden.value = '1';
		form.appendChild(hidden);
		form.submit();
	});
	document.addEventListener('keydown', function(e) {
		if ((e.key === 'Escape' || e.keyCode === 27) && confirmBox.style.display === 'flex')
			confirmBox.style.display = 'none';
	});
}());
</script>