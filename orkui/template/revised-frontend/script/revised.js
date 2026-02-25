/* ===========================
   Player Profile (PnConfig)
   =========================== */
// ---- Pagination Helpers ----
function pnPageRange(current, total) {
	var pages = [];
	if (total <= 7) {
		for (var p = 1; p <= total; p++) pages.push(p);
	} else {
		pages.push(1);
		if (current > 3) pages.push(-1);
		var s = Math.max(2, current - 1);
		var e = Math.min(total - 1, current + 1);
		for (var p = s; p <= e; p++) pages.push(p);
		if (current < total - 2) pages.push(-1);
		pages.push(total);
	}
	return pages;
}

function pnPaginate($table, page) {
	var pageSize = 10;
	var $rows = $table.find('tbody tr');
	var total = $rows.length;
	if (total === 0) return;
	var totalPages = Math.max(1, Math.ceil(total / pageSize));
	page = Math.max(1, Math.min(page, totalPages));
	$table.data('pn-page', page);
	$rows.each(function(i) {
		$(this).toggle(i >= (page - 1) * pageSize && i < page * pageSize);
	});
	var $pg = $table.next('.pn-pagination');
	if ($pg.length === 0) $pg = $('<div class="pn-pagination"></div>').insertAfter($table);
	if (total <= pageSize) { $pg.empty().hide(); return; }
	$pg.show();
	var start = (page - 1) * pageSize + 1;
	var end = Math.min(page * pageSize, total);
	var html = '<span class="pn-pagination-info">Showing ' + start + '\u2013' + end + ' of ' + total + '</span>';
	html += '<div class="pn-pagination-controls">';
	html += '<button class="pn-page-btn pn-page-prev"' + (page === 1 ? ' disabled' : '') + '>&#8249;</button>';
	var range = pnPageRange(page, totalPages);
	for (var ri = 0; ri < range.length; ri++) {
		if (range[ri] === -1) {
			html += '<span class="pn-page-ellipsis">&hellip;</span>';
		} else {
			html += '<button class="pn-page-btn pn-page-num' + (range[ri] === page ? ' pn-page-active' : '') + '" data-page="' + range[ri] + '">' + range[ri] + '</button>';
		}
	}
	html += '<button class="pn-page-btn pn-page-next"' + (page === totalPages ? ' disabled' : '') + '>&#8250;</button>';
	html += '</div>';
	$pg.html(html);
}

function pnSortDesc($table, colIndex, sortType) {
	if (!$table.length) return;
	$table.find('thead th').removeClass('sort-asc sort-desc');
	$table.find('thead th').eq(colIndex).addClass('sort-desc');
	var $tbody = $table.find('tbody');
	var rows = $tbody.find('tr').get();
	rows.sort(function(a, b) {
		var aVal = $(a).find('td').eq(colIndex).text().trim();
		var bVal = $(b).find('td').eq(colIndex).text().trim();
		var cmp = 0;
		if (sortType === 'numeric') {
			cmp = (parseFloat(aVal) || 0) - (parseFloat(bVal) || 0);
		} else if (sortType === 'date') {
			cmp = (new Date(aVal).getTime() || 0) - (new Date(bVal).getTime() || 0);
		} else {
			cmp = aVal.localeCompare(bVal);
		}
		return -cmp;
	});
	$.each(rows, function(i, row) { $tbody.append(row); });
}

function pnActivateTab(tab) {
	$('.pn-tab-nav li').removeClass('pn-tab-active');
	$('.pn-tab-nav li[data-tab="' + tab + '"]').addClass('pn-tab-active');
	$('.pn-tab-panel').hide();
	$('#pn-tab-' + tab).show();
	$('html, body').animate({ scrollTop: $('.pn-tabs').offset().top - 20 }, 250);
}

$(document).ready(function() {
	if (typeof PnConfig === 'undefined') return;

	// ---- Tab Switching ----
	$('.pn-tab-nav li').on('click', function() {
		pnActivateTab($(this).data('tab'));
	});

	// ---- Class Level Calculation ----
	$('#pn-classes-table tbody tr').each(function() {
		var credits = Number($(this).find('.pn-credits').text());
		var level = 1;
		if (credits >= 53) level = 6;
		else if (credits >= 34) level = 5;
		else if (credits >= 21) level = 4;
		else if (credits >= 12) level = 3;
		else if (credits >= 5) level = 2;
		$(this).find('.pn-level').text(level);
	});

	// ---- Sortable Tables ----
	$('.pn-sortable').each(function() {
		var table = $(this);
		table.find('thead th').on('click', function() {
			var columnIndex = $(this).index();
			var sortType = $(this).data('sorttype') || 'text';
			var isAscending = !$(this).hasClass('sort-asc');

			table.find('thead th').removeClass('sort-asc sort-desc');
			$(this).addClass(isAscending ? 'sort-asc' : 'sort-desc');

			var tbody = table.find('tbody');
			var rows = tbody.find('tr').get();

			rows.sort(function(a, b) {
				var aText = $(a).find('td').eq(columnIndex).text().trim();
				var bText = $(b).find('td').eq(columnIndex).text().trim();
				var cmp = 0;

				if (sortType === 'numeric') {
					cmp = (parseFloat(aText) || 0) - (parseFloat(bText) || 0);
				} else if (sortType === 'date') {
					cmp = (new Date(aText).getTime() || 0) - (new Date(bText).getTime() || 0);
				} else {
					cmp = aText.localeCompare(bText);
				}
				return isAscending ? cmp : -cmp;
			});

			$.each(rows, function(i, row) {
				tbody.append(row);
			});
			pnPaginate(table, 1);
		});
	});


	// ---- Custom Recommendation Modal ----
	function pnOpenModal() {
		$('#pn-rec-overlay').addClass('pn-open');
		$('body').css('overflow', 'hidden');
	}
	function pnCloseModal() {
		$('#pn-rec-overlay').removeClass('pn-open');
		$('body').css('overflow', '');
		$('#pn-rec-error').hide().empty();
	}

	$('#pn-recommend-btn').on('click', function(e) {
		e.preventDefault();
		pnOpenModal();
	});
	// Auto-open modal and show error if redirected back after a failed submission
if (PnConfig.recError) {
	(function() {
		$('#pn-rec-error').show();
		pnOpenModal();
	})();
}
	$('#pn-modal-close-btn, #pn-rec-cancel').on('click', function() {
		pnCloseModal();
	});
	// Close on backdrop click
	$('#pn-rec-overlay').on('click', function(e) {
		if (e.target === this) pnCloseModal();
	});
	// Escape key
	$(document).on('keydown', function(e) {
		if ((e.key === 'Escape' || e.keyCode === 27) && $('#pn-rec-overlay').hasClass('pn-open')) {
			pnCloseModal();
		}
	});

	// Submit with validation
	$('#pn-rec-submit').on('click', function() {
		var award  = $('#pn-rec-award').val();
		var reason = $.trim($('#pn-rec-reason').val());
		if (!award || !reason) {
			$('#pn-rec-error').text('Please select an award and provide a reason.').show();
			return;
		}
		$('#pn-rec-error').hide();
		$('#pn-rec-submit').prop('disabled', true).text('Submitting…');
		$('#pn-recommend-form').submit();
	});

	// Character counter
	$('#pn-rec-reason').on('input', function() {
		var remaining = 400 - $(this).val().length;
		$('#pn-rec-char-count')
			.text(remaining + ' character' + (remaining !== 1 ? 's' : '') + ' remaining')
			.toggleClass('pn-char-warn', remaining < 50);
	});


	// Auto-fill rank for ladder awards based on player's existing ranks
	var pnAwardRanks = PnConfig.awardRanks;
	$('#pn-rec-award').on('change', function() {
		var $opt     = $(this).find('option:selected');
		var isLadder = $opt.data('is-ladder') == 1;
		var baseId   = parseInt($opt.data('award-id')) || 0;
		if (!$(this).val()) {
			$('#pn-rec-rank').val('');
		} else if (isLadder && baseId) {
			var currentRank = pnAwardRanks[baseId] || 0;
			$('#pn-rec-rank').val(String(Math.min(currentRank + 1, 6)));
		} else {
			$('#pn-rec-rank').val('');
		}
	});

	// ---- Inline Delete Confirmation ----
	$(document).on('click', '.pn-confirm-delete-rec', function(e) {
		e.preventDefault();
		var $cell = $(this).closest('.pn-delete-cell');
		$(this).addClass('pn-hidden');
		$cell.find('.pn-delete-confirm').addClass('pn-active');
	});
	$(document).on('click', '.pn-confirm-quit-unit', function(e) {
		e.preventDefault();
		var $cell = $(this).closest('.pn-delete-cell');
		$(this).addClass('pn-hidden');
		$cell.find('.pn-delete-confirm').addClass('pn-active');
	});
	$(document).on('click', '.pn-delete-yes', function() {
		window.location.href = $(this).data('href');
	});
	$(document).on('click', '.pn-delete-no', function() {
		var $cell = $(this).closest('.pn-delete-cell');
		$cell.find('.pn-delete-link').removeClass('pn-hidden');
		$cell.find('.pn-delete-confirm').removeClass('pn-active');
	});

	// ---- Pagination: page button handlers ----
	$(document).on('click', '.pn-page-num', function() {
		var $table = $(this).closest('.pn-pagination').prev('.pn-table');
		if ($table.length) pnPaginate($table, parseInt($(this).data('page')));
	});
	$(document).on('click', '.pn-page-prev', function() {
		if ($(this).prop('disabled')) return;
		var $table = $(this).closest('.pn-pagination').prev('.pn-table');
		if ($table.length) pnPaginate($table, ($table.data('pn-page') || 1) - 1);
	});
	$(document).on('click', '.pn-page-next', function() {
		if ($(this).prop('disabled')) return;
		var $table = $(this).closest('.pn-pagination').prev('.pn-table');
		if ($table.length) pnPaginate($table, ($table.data('pn-page') || 1) + 1);
	});


	// ---- Image Upload Modal ----
	(function() {
		if (!PnConfig.canEditImages) return;
		var UPLOAD_URL = PnConfig.uir + 'Admin/player/' + PnConfig.playerId + '/update';
		var imgType  = null;  // 'photo' | 'heraldry'
		var origImg  = null;  // HTMLImageElement (resized if needed)
		var cropBox  = null;  // {x,y,w,h} in image pixels
		var dispScale = 1;    // display scale factor
		var cropBound = null; // bound event listener refs for cleanup

		function gid(id) { return document.getElementById(id); }

		function showStep(s) {
			['pn-img-step-select','pn-img-step-crop','pn-img-step-uploading','pn-img-step-success'].forEach(function(id) {
				gid(id).style.display = (id === s) ? '' : 'none';
			});
		}

		function showError(msg) {
			var el = gid('pn-img-error');
			el.textContent = msg;
			el.style.display = '';
		}

		window.pnOpenImgModal = function(type) {
			imgType = type;
			var isPhoto = type === 'photo';
			gid('pn-img-modal-title').innerHTML =
				'<i class="fas fa-' + (isPhoto ? 'user-circle' : 'image') + '" style="margin-right:8px;color:#2c5282"></i>' +
				'Update ' + (isPhoto ? 'Player Photo' : 'Heraldry');
			gid('pn-img-file-input').value = '';
			gid('pn-img-resize-notice').textContent = '';
			var errEl = gid('pn-img-error'); errEl.style.display = 'none'; errEl.textContent = '';
			showStep('pn-img-step-select');
			gid('pn-img-overlay').classList.add('pn-open');
			document.body.style.overflow = 'hidden';
		};

		window.pnCloseImgModal = function() {
			gid('pn-img-overlay').classList.remove('pn-open');
			document.body.style.overflow = '';
		};

		gid('pn-img-overlay').addEventListener('click', function(e) {
			if (e.target === this) pnCloseImgModal();
		});
		gid('pn-img-close-btn').addEventListener('click', pnCloseImgModal);
		document.addEventListener('keydown', function(e) {
			if ((e.key === 'Escape' || e.keyCode === 27) && gid('pn-img-overlay').classList.contains('pn-open'))
				pnCloseImgModal();
		});
		gid('pn-img-back-btn').addEventListener('click', function() {
			gid('pn-img-file-input').value = '';
			gid('pn-img-resize-notice').textContent = '';
			showStep('pn-img-step-select');
		});
		gid('pn-img-upload-btn').addEventListener('click', doUploadCropped);

		// File input change — validate, auto-resize, then show cropper
		gid('pn-img-file-input').addEventListener('change', function() {
			var file = this.files && this.files[0];
			if (!file) return;
			var ext = file.name.split('.').pop().toLowerCase();
			if (['jpg','jpeg','gif','png'].indexOf(ext) < 0) {
				showError('Invalid file type. Please use JPG, GIF, or PNG.');
				this.value = '';
				return;
			}
			gid('pn-img-error').style.display = 'none';

			function loadIntoModal(blob) {
				var url = URL.createObjectURL(blob);
				var img = new Image();
				img.onload = function() {
					URL.revokeObjectURL(url);
					origImg = img;
					initCrop();
					showStep('pn-img-step-crop');
				};
				img.onerror = function() {
					URL.revokeObjectURL(url);
					showError('Could not load image. Please try a different file.');
				};
				img.src = url;
			}

			if (file.size > 348836) {
				var isPng = (file.type === 'image/png');
				gid('pn-img-resize-notice').textContent = 'Resizing\u2026';
				resizeImageToLimit(file, 348836, function(blob) {
					gid('pn-img-resize-notice').textContent = 'Auto-resized to ' + Math.round(blob.size / 1024) + '\u00a0KB';
					loadIntoModal(blob);
				}, function(errMsg) {
					showError(errMsg);
				}, isPng);
			} else {
				loadIntoModal(file);
			}
		});

		// ---- Crop tool ----
		function initCrop() {
			var canvas = gid('pn-crop-canvas');
			var img = origImg;
			var maxW = Math.min(500, window.innerWidth - 100) - 40;
			var maxH = Math.min(380, window.innerHeight - 260);
			var scale = Math.min(maxW / img.width, maxH / img.height, 1);
			canvas.width  = Math.round(img.width  * scale);
			canvas.height = Math.round(img.height * scale);
			dispScale = scale;

			// Initial crop: ~98% of image so corner handles are visible at edges
			if (imgType === 'photo') {
				var sz = Math.round(Math.min(img.width, img.height) * 0.98);
				cropBox = { x: Math.round((img.width - sz) / 2), y: Math.round((img.height - sz) / 2), w: sz, h: sz };
			} else {
				var inX = Math.round(img.width  * 0.01);
				var inY = Math.round(img.height * 0.01);
				cropBox = { x: inX, y: inY, w: img.width - inX * 2, h: img.height - inY * 2 };
			}
			drawCrop();
			bindCropEvents(canvas);
		}

		function drawCrop() {
			var canvas = gid('pn-crop-canvas');
			var ctx = canvas.getContext('2d');
			var sc = dispScale, cb = cropBox;
			var cx = Math.round(cb.x * sc), cy = Math.round(cb.y * sc);
			var cw = Math.round(cb.w * sc), ch = Math.round(cb.h * sc);

			ctx.clearRect(0, 0, canvas.width, canvas.height);
			ctx.drawImage(origImg, 0, 0, canvas.width, canvas.height);

			// Dim outside crop
			ctx.fillStyle = 'rgba(0,0,0,0.52)';
			ctx.fillRect(0, 0, canvas.width, cy);
			ctx.fillRect(0, cy + ch, canvas.width, canvas.height - cy - ch);
			ctx.fillRect(0, cy, cx, ch);
			ctx.fillRect(cx + cw, cy, canvas.width - cx - cw, ch);

			// Crop border
			ctx.strokeStyle = 'rgba(255,255,255,0.9)';
			ctx.lineWidth = 1.5;
			ctx.strokeRect(cx + 0.5, cy + 0.5, cw - 1, ch - 1);

			// Rule-of-thirds
			ctx.strokeStyle = 'rgba(255,255,255,0.3)';
			ctx.lineWidth = 1;
			ctx.beginPath();
			ctx.moveTo(cx + cw/3, cy); ctx.lineTo(cx + cw/3, cy + ch);
			ctx.moveTo(cx + 2*cw/3, cy); ctx.lineTo(cx + 2*cw/3, cy + ch);
			ctx.moveTo(cx, cy + ch/3); ctx.lineTo(cx + cw, cy + ch/3);
			ctx.moveTo(cx, cy + 2*ch/3); ctx.lineTo(cx + cw, cy + 2*ch/3);
			ctx.stroke();

			// Corner handles
			var hs = 8;
			ctx.fillStyle = '#fff';
			ctx.strokeStyle = 'rgba(0,0,0,0.25)';
			ctx.lineWidth = 1;
			[[cx, cy], [cx + cw, cy], [cx, cy + ch], [cx + cw, cy + ch]].forEach(function(pt) {
				ctx.fillRect(pt[0] - hs/2, pt[1] - hs/2, hs, hs);
				ctx.strokeRect(pt[0] - hs/2, pt[1] - hs/2, hs, hs);
			});
		}

		function getCanvasPos(canvas, e) {
			var rect = canvas.getBoundingClientRect();
			var src = e.touches ? e.touches[0] : e;
			return {
				x: (src.clientX - rect.left) * (canvas.width  / rect.width),
				y: (src.clientY - rect.top)  * (canvas.height / rect.height)
			};
		}

		function hitHandle(mx, my) {
			var sc = dispScale, cb = cropBox, hs = 12;
			var cx = cb.x * sc, cy = cb.y * sc, cw = cb.w * sc, ch = cb.h * sc;
			var corners = [
				{ name: 'nw', x: cx,      y: cy      },
				{ name: 'ne', x: cx + cw, y: cy      },
				{ name: 'sw', x: cx,      y: cy + ch },
				{ name: 'se', x: cx + cw, y: cy + ch }
			];
			for (var i = 0; i < corners.length; i++) {
				if (Math.abs(mx - corners[i].x) <= hs && Math.abs(my - corners[i].y) <= hs)
					return corners[i].name;
			}
			if (mx >= cx && mx <= cx + cw && my >= cy && my <= cy + ch) return 'move';
			return null;
		}

		function bindCropEvents(canvas) {
			if (cropBound) {
				canvas.removeEventListener('mousedown',  cropBound.down);
				canvas.removeEventListener('touchstart', cropBound.down);
				window.removeEventListener('mousemove',  cropBound.move);
				window.removeEventListener('touchmove',  cropBound.move);
				window.removeEventListener('mouseup',    cropBound.up);
				window.removeEventListener('touchend',   cropBound.up);
			}
			var ds = null;

			function onDown(e) {
				e.preventDefault();
				var pos = getCanvasPos(canvas, e);
				var hit = hitHandle(pos.x, pos.y);
				if (hit) ds = { handle: hit, startMX: pos.x, startMY: pos.y, startCrop: { x: cropBox.x, y: cropBox.y, w: cropBox.w, h: cropBox.h } };
			}

			function onMove(e) {
				if (!ds) return;
				e.preventDefault();
				var pos = getCanvasPos(canvas, e);
				var dx = (pos.x - ds.startMX) / dispScale;
				var dy = (pos.y - ds.startMY) / dispScale;
				var s = ds.startCrop, img = origImg, MIN = 20;
				var lockAspect = (imgType === 'photo');

				if (ds.handle === 'move') {
					cropBox.x = Math.max(0, Math.min(img.width  - s.w, s.x + dx));
					cropBox.y = Math.max(0, Math.min(img.height - s.h, s.y + dy));
				} else {
					var nx = s.x, ny = s.y, nw = s.w, nh = s.h;
					if (ds.handle === 'se') {
						nw = Math.max(MIN, s.w + dx); nh = lockAspect ? nw : Math.max(MIN, s.h + dy);
					} else if (ds.handle === 'sw') {
						nw = Math.max(MIN, s.w - dx); nh = lockAspect ? nw : Math.max(MIN, s.h + dy); nx = s.x + s.w - nw;
					} else if (ds.handle === 'ne') {
						nw = Math.max(MIN, s.w + dx); nh = lockAspect ? nw : Math.max(MIN, s.h - dy); ny = s.y + s.h - nh;
					} else { // nw
						nw = Math.max(MIN, s.w - dx); nh = lockAspect ? nw : Math.max(MIN, s.h - dy); nx = s.x + s.w - nw; ny = s.y + s.h - nh;
					}
					nx = Math.max(0, nx); ny = Math.max(0, ny);
					nw = Math.min(nw, img.width  - nx); nh = Math.min(nh, img.height - ny);
					cropBox.x = nx; cropBox.y = ny; cropBox.w = nw; cropBox.h = nh;
				}
				drawCrop();
			}

			function onUp() { ds = null; }

			cropBound = { down: onDown, move: onMove, up: onUp };
			canvas.addEventListener('mousedown',  onDown);
			canvas.addEventListener('touchstart', onDown, { passive: false });
			window.addEventListener('mousemove',  onMove);
			window.addEventListener('touchmove',  onMove, { passive: false });
			window.addEventListener('mouseup',    onUp);
			window.addEventListener('touchend',   onUp);
		}

		// ---- Upload ----
		function doUploadCropped() {
			var cb = cropBox;
			var outCanvas = document.createElement('canvas');
			outCanvas.width  = Math.round(cb.w);
			outCanvas.height = Math.round(cb.h);
			outCanvas.getContext('2d').drawImage(origImg, cb.x, cb.y, cb.w, cb.h, 0, 0, cb.w, cb.h);
			outCanvas.toBlob(function(blob) {
				if (blob.size > 348836) {
					resizeImageToLimit(blob, 348836, doUpload, function(err) {
						showStep('pn-img-step-select');
						showError(err);
					}, false);
				} else {
					doUpload(blob);
				}
			}, 'image/jpeg', 0.88);
		}

		function doUpload(blob) {
			showStep('pn-img-step-uploading');
			var fd = new FormData();
			fd.append('Update', 'Update Media');
			fd.append(imgType === 'photo' ? 'PlayerImage' : 'Heraldry', blob, 'image.jpg');
			fetch(UPLOAD_URL, { method: 'POST', body: fd })
				.then(function(resp) {
					if (!resp.ok) throw new Error('Server returned ' + resp.status);
					showStep('pn-img-step-success');
					setTimeout(function() { window.location.reload(); }, 1400);
				})
				.catch(function(err) {
					showStep('pn-img-step-select');
					showError('Upload failed: ' + err.message);
				});
		}
	})();

	// ---- Update Account Modal ----
	(function() {
		if (!PnConfig.canEditAccount) return;
		var SAVE_URL = PnConfig.uir + 'Admin/player/' + PnConfig.playerId + '/update';

		function gid(id) { return document.getElementById(id); }

		window.pnOpenAccountModal = function() {
			gid('pn-acct-error').style.display = 'none';
			gid('pn-acct-error').textContent = '';
			gid('pn-acct-overlay').classList.add('pn-open');
			document.body.style.overflow = 'hidden';
		};
		window.pnCloseAccountModal = function() {
			gid('pn-acct-overlay').classList.remove('pn-open');
			document.body.style.overflow = '';
		};

		gid('pn-acct-close-btn').addEventListener('click', pnCloseAccountModal);
		gid('pn-acct-cancel').addEventListener('click', pnCloseAccountModal);
		gid('pn-acct-overlay').addEventListener('click', function(e) {
			if (e.target === this) pnCloseAccountModal();
		});
		document.addEventListener('keydown', function(e) {
			if ((e.key === 'Escape' || e.keyCode === 27) && gid('pn-acct-overlay').classList.contains('pn-open'))
				pnCloseAccountModal();
		});

		gid('pn-acct-save').addEventListener('click', function() {
			var persona   = gid('pn-acct-persona').value.trim();
			var username  = gid('pn-acct-username').value.trim();
			var password  = gid('pn-acct-password').value;
			var password2 = gid('pn-acct-password2').value;
			var errEl = gid('pn-acct-error');

			// Client-side validation
			if (!persona) {
				errEl.textContent = 'Persona is required.';
				errEl.style.display = '';
				gid('pn-acct-persona').focus();
				return;
			}
			if (!username) {
				errEl.textContent = 'Username is required.';
				errEl.style.display = '';
				gid('pn-acct-username').focus();
				return;
			}
			if (password !== password2) {
				errEl.textContent = 'Passwords do not match.';
				errEl.style.display = '';
				gid('pn-acct-password').focus();
				return;
			}
			errEl.style.display = 'none';

			// Collect all fields in the modal body
			var fd = new FormData();
			fd.append('Update', 'Update Details');
			var modal = gid('pn-acct-overlay');
			modal.querySelectorAll('input[name], select[name], textarea[name]').forEach(function(el) {
				if (el.type === 'radio' && !el.checked) return;
				if (el.type === 'checkbox') {
					if (el.checked) fd.append(el.name, el.value);
					// unchecked checkboxes send nothing — controller treats missing as 0
					return;
				}
				fd.append(el.name, el.value);
			});

			var btn = gid('pn-acct-save');
			btn.disabled = true;
			btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving\u2026';

			fetch(SAVE_URL, { method: 'POST', body: fd })
				.then(function(resp) {
					if (!resp.ok) throw new Error('Server returned ' + resp.status);
					// Reload to reflect changes
					window.location.reload();
				})
				.catch(function(err) {
					errEl.textContent = 'Save failed: ' + err.message;
					errEl.style.display = '';
					btn.disabled = false;
					btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
				});
		});
	})();

	// ---- Add Dues Modal ----
	(function() {
		if (!PnConfig.canEditAdmin) return;
		var DUES_URL = PnConfig.uir + 'Admin/player/' + PnConfig.playerId + '/adddues';

		function gid(id) { return document.getElementById(id); }

		// Add N months to a YYYY-MM-DD string, returns YYYY-MM-DD
		function addMonths(dateStr, n) {
			var p = dateStr.split('-');
			if (p.length !== 3) return '';
			var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1 + n, parseInt(p[2], 10));
			return d.getFullYear() + '-' +
				String(d.getMonth() + 1).padStart(2, '0') + '-' +
				String(d.getDate()).padStart(2, '0');
		}

		function isForLife() {
			var checked = document.querySelector('#pn-dues-overlay input[name="DuesForLife"]:checked');
			return checked && checked.value === '1';
		}

		function updateDuesPreview() {
			var el = gid('pn-dues-until-preview');
			if (!el) return;
			if (isForLife()) {
				el.innerHTML = '<i class="fas fa-infinity" style="margin-right:4px"></i>Paid for life';
				return;
			}
			var from   = gid('pn-dues-from').value;
			var months = parseInt(gid('pn-dues-months').value, 10);
			if (!from || isNaN(months) || months < 1) { el.textContent = ''; return; }
			var until = addMonths(from, months);
			el.innerHTML = 'Paid through: <strong>' + until + '</strong>';
		}

		function syncMonthsRow() {
			gid('pn-dues-months-row').style.display = isForLife() ? 'none' : '';
			updateDuesPreview();
		}

		window.pnOpenDuesModal = function() {
			gid('pn-dues-error').style.display = 'none';
			gid('pn-dues-error').textContent = '';
			// Reset to defaults
			var today = new Date();
			gid('pn-dues-from').value = today.getFullYear() + '-' +
				String(today.getMonth() + 1).padStart(2, '0') + '-' +
				String(today.getDate()).padStart(2, '0');
			gid('pn-dues-months').value = '6';
			document.querySelectorAll('#pn-dues-overlay input[name="DuesForLife"]').forEach(function(r) {
				r.checked = (r.value === '0');
			});
			syncMonthsRow();
			gid('pn-dues-overlay').classList.add('pn-open');
			document.body.style.overflow = 'hidden';
		};
		window.pnCloseDuesModal = function() {
			gid('pn-dues-overlay').classList.remove('pn-open');
			document.body.style.overflow = '';
		};

		gid('pn-dues-close-btn').addEventListener('click', pnCloseDuesModal);
		gid('pn-dues-cancel').addEventListener('click', pnCloseDuesModal);
		gid('pn-dues-overlay').addEventListener('click', function(e) {
			if (e.target === this) pnCloseDuesModal();
		});
		document.addEventListener('keydown', function(e) {
			if ((e.key === 'Escape' || e.keyCode === 27) && gid('pn-dues-overlay').classList.contains('pn-open'))
				pnCloseDuesModal();
		});

		// Live preview wiring
		gid('pn-dues-from').addEventListener('input', updateDuesPreview);
		gid('pn-dues-months').addEventListener('input', updateDuesPreview);
		document.querySelectorAll('#pn-dues-overlay input[name="DuesForLife"]').forEach(function(r) {
			r.addEventListener('change', syncMonthsRow);
		});

		gid('pn-dues-save').addEventListener('click', function() {
			var duesFrom = gid('pn-dues-from').value.trim();
			var errEl    = gid('pn-dues-error');

			if (!duesFrom) {
				errEl.textContent = 'Date Paid is required.';
				errEl.style.display = '';
				gid('pn-dues-from').focus();
				return;
			}
			errEl.style.display = 'none';

			var fd = new FormData();
			var modal = gid('pn-dues-overlay');
			modal.querySelectorAll('input[name], select[name], textarea[name]').forEach(function(el) {
				if (el.type === 'radio' && !el.checked) return;
				// Skip Months when Dues For Life is selected
				if (el.name === 'Months' && isForLife()) return;
				fd.append(el.name, el.value);
			});

			var btn = gid('pn-dues-save');
			btn.disabled = true;
			btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving\u2026';

			fetch(DUES_URL, { method: 'POST', body: fd })
				.then(function(resp) {
					if (!resp.ok) throw new Error('Server returned ' + resp.status);
					window.location.reload();
				})
				.catch(function(err) {
					errEl.textContent = 'Save failed: ' + err.message;
					errEl.style.display = '';
					btn.disabled = false;
					btn.innerHTML = '<i class="fas fa-save"></i> Add Dues';
				});
		});
	})();

	// ---- Edit Qualifications Modal ----
	(function() {
		if (!PnConfig.canEditAdmin) return;
		var SAVE_URL = PnConfig.uir + 'Admin/player/' + PnConfig.playerId + '/update';

		function gid(id) { return document.getElementById(id); }

		function syncUntilRow(radioName, rowId) {
			var checked = document.querySelector('#pn-qual-overlay input[name="' + radioName + '"]:checked');
			var row = gid(rowId);
			var qualified = checked && checked.value === '1';
			row.style.opacity = qualified ? '' : '0.35';
			row.style.pointerEvents = qualified ? '' : 'none';
		}

		function syncAll() {
			syncUntilRow('ReeveQualified',   'pn-qual-reeve-until-row');
			syncUntilRow('CorporaQualified', 'pn-qual-corpora-until-row');
		}

		window.pnOpenQualModal = function() {
			gid('pn-qual-error').style.display = 'none';
			gid('pn-qual-error').textContent = '';
			syncAll();
			gid('pn-qual-overlay').classList.add('pn-open');
			document.body.style.overflow = 'hidden';
		};
		window.pnCloseQualModal = function() {
			gid('pn-qual-overlay').classList.remove('pn-open');
			document.body.style.overflow = '';
		};

		gid('pn-qual-close-btn').addEventListener('click', pnCloseQualModal);
		gid('pn-qual-cancel').addEventListener('click', pnCloseQualModal);
		gid('pn-qual-overlay').addEventListener('click', function(e) {
			if (e.target === this) pnCloseQualModal();
		});
		document.addEventListener('keydown', function(e) {
			if ((e.key === 'Escape' || e.keyCode === 27) && gid('pn-qual-overlay').classList.contains('pn-open'))
				pnCloseQualModal();
		});

		document.querySelectorAll('#pn-qual-overlay input[name="ReeveQualified"]').forEach(function(r) {
			r.addEventListener('change', function() { syncUntilRow('ReeveQualified', 'pn-qual-reeve-until-row'); });
		});
		document.querySelectorAll('#pn-qual-overlay input[name="CorporaQualified"]').forEach(function(r) {
			r.addEventListener('change', function() { syncUntilRow('CorporaQualified', 'pn-qual-corpora-until-row'); });
		});

		gid('pn-qual-save').addEventListener('click', function() {
			var errEl = gid('pn-qual-error');
			errEl.style.display = 'none';

			var fd = new FormData();
			var modal = gid('pn-qual-overlay');
			modal.querySelectorAll('input[name], select[name], textarea[name]').forEach(function(el) {
				if (el.type === 'radio' && !el.checked) return;
				fd.append(el.name, el.value);
			});

			var btn = gid('pn-qual-save');
			btn.disabled = true;
			btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving\u2026';

			fetch(SAVE_URL, { method: 'POST', body: fd })
				.then(function(resp) {
					if (!resp.ok) throw new Error('Server returned ' + resp.status);
					window.location.reload();
				})
				.catch(function(err) {
					errEl.textContent = 'Save failed: ' + err.message;
					errEl.style.display = '';
					btn.disabled = false;
					btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
				});
		});
	})();

	// ---- Add Award / Add Title Modal ----
	(function() {
		if (!PnConfig.canEditAdmin) return;
		var AWARD_URL = PnConfig.uir + 'Admin/player/' + PnConfig.playerId + '/addaward';
		var SEARCH_URL = PnConfig.httpService + 'Search/SearchService.php';
		var KINGDOM_ID = PnConfig.kingdomId;
		// Player's held award ranks: canonical AwardId => max rank
		var playerRanks = PnConfig.awardRanks;
		// Award option lists as HTML strings for swapping
		var awardOptHTML = PnConfig.awardOptHTML;
		var officerOptHTML = PnConfig.officerOptHTML;

		var currentType = 'awards';

		function gid(id) { return document.getElementById(id); }

		// ---- Award Type Toggle ----
		function setAwardType(type) {
			currentType = type;
			var isOfficer = (type === 'officers');
			gid('pn-award-type-awards').classList.toggle('pn-active', !isOfficer);
			gid('pn-award-type-officers').classList.toggle('pn-active', isOfficer);
			gid('pn-award-modal-title').innerHTML = isOfficer
				? '<i class="fas fa-crown" style="margin-right:8px;color:#553c9a"></i>Add Officer Title'
				: '<i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award';
			gid('pn-award-select').innerHTML = isOfficer ? officerOptHTML : awardOptHTML;
			gid('pn-award-rank-row').style.display   = 'none';
			gid('pn-award-custom-row').style.display  = 'none';
			gid('pn-award-info-line').innerHTML       = '';
			gid('pn-award-rank-val').value            = '';
			checkRequired();
		}
		gid('pn-award-type-awards').addEventListener('click',   function() { setAwardType('awards'); });
		gid('pn-award-type-officers').addEventListener('click', function() { setAwardType('officers'); });

		// ---- Award Select Change ----
		gid('pn-award-select').addEventListener('change', function() {
			var opt      = this.options[this.selectedIndex];
			var isLadder = (opt.getAttribute('data-is-ladder') == '1');
			var awardId  = parseInt(opt.getAttribute('data-award-id')) || 0;
			var isCustom = (opt.text.indexOf('Custom Award') !== -1);

			gid('pn-award-custom-row').style.display  = isCustom ? '' : 'none';
			gid('pn-award-info-line').innerHTML        = isLadder
				? '<span class="pn-badge-ladder"><i class="fas fa-chart-line"></i> Ladder Award</span>'
				: '';

			if (isLadder && this.value) {
				gid('pn-award-rank-row').style.display = '';
				buildRankPills(awardId);
			} else {
				gid('pn-award-rank-row').style.display = 'none';
				gid('pn-award-rank-val').value = '';
			}
			checkRequired();
		});

		// ---- Rank Pills ----
		function buildRankPills(awardId) {
			var held      = playerRanks[awardId] || 0;
			var suggested = Math.min(held + 1, 10);
			var html = '';
			for (var i = 1; i <= 10; i++) {
				var cls = 'pn-rank-pill';
				if (i <= held)       cls += ' pn-rank-held';
				if (i === suggested) cls += ' pn-rank-suggested';
				html += '<div class="' + cls + '" data-rank="' + i + '">' + i + '</div>';
			}
			var pills = gid('pn-rank-pills');
			pills.innerHTML = html;
			selectRankPill(suggested, pills);
		}
		function selectRankPill(rank, container) {
			var c = container || gid('pn-rank-pills');
			c.querySelectorAll('.pn-rank-pill').forEach(function(p) { p.classList.remove('pn-rank-selected'); });
			var target = c.querySelector('[data-rank="' + rank + '"]');
			if (target) {
				target.classList.add('pn-rank-selected');
				gid('pn-award-rank-val').value = rank;
			}
		}
		gid('pn-rank-pills').addEventListener('click', function(e) {
			var pill = e.target.closest ? e.target.closest('.pn-rank-pill') : (e.target.classList.contains('pn-rank-pill') ? e.target : null);
			if (!pill) return;
			selectRankPill(parseInt(pill.dataset.rank));
		});

		// ---- Given By: Officer quick chips ----
		document.querySelectorAll('#pn-award-officer-chips .pn-officer-chip').forEach(function(chip) {
			chip.addEventListener('click', function() {
				document.querySelectorAll('#pn-award-officer-chips .pn-officer-chip').forEach(function(c) { c.classList.remove('pn-selected'); });
				this.classList.add('pn-selected');
				gid('pn-award-givenby-text').value = this.dataset.name;
				gid('pn-award-givenby-id').value   = this.dataset.id;
				gid('pn-award-givenby-results').classList.remove('pn-ac-open');
				checkRequired();
			});
		});

		// ---- Given By: search autocomplete ----
		var givenByTimer;
		gid('pn-award-givenby-text').addEventListener('input', function() {
			clearTimeout(givenByTimer);
			gid('pn-award-givenby-id').value = '';
			document.querySelectorAll('#pn-award-officer-chips .pn-officer-chip').forEach(function(c) { c.classList.remove('pn-selected'); });
			checkRequired();
			var term = this.value.trim();
			if (term.length < 2) { gid('pn-award-givenby-results').classList.remove('pn-ac-open'); return; }
			givenByTimer = setTimeout(function() {
				var url = SEARCH_URL + '?Action=Search%2FPlayer&type=all&search=' + encodeURIComponent(term) + '&kingdom_id=' + KINGDOM_ID + '&limit=6';
				fetch(url).then(function(r) { return r.json(); }).then(function(data) {
					var results = gid('pn-award-givenby-results');
					if (!data || !data.length) {
						results.innerHTML = '<div class="pn-ac-no-results">No players found</div>';
					} else {
						results.innerHTML = data.map(function(p) {
							return '<div class="pn-ac-item" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
								+ p.Persona
								+ ' <span style="color:#a0aec0;font-size:11px">(' + (p.KAbbr || '') + ':' + (p.PAbbr || '') + ')</span>'
								+ '</div>';
						}).join('');
					}
					results.classList.add('pn-ac-open');
				}).catch(function() {});
			}, 250);
		});
		gid('pn-award-givenby-results').addEventListener('click', function(e) {
			var item = e.target.closest ? e.target.closest('.pn-ac-item') : (e.target.classList.contains('pn-ac-item') ? e.target : null);
			if (!item) return;
			gid('pn-award-givenby-text').value = decodeURIComponent(item.dataset.name);
			gid('pn-award-givenby-id').value   = item.dataset.id;
			this.classList.remove('pn-ac-open');
			document.querySelectorAll('#pn-award-officer-chips .pn-officer-chip').forEach(function(c) { c.classList.remove('pn-selected'); });
			checkRequired();
		});

		// ---- Given At: location autocomplete ----
		var givenAtTimer;
		gid('pn-award-givenat-text').addEventListener('input', function() {
			clearTimeout(givenAtTimer);
			gid('pn-award-park-id').value    = '0';
			gid('pn-award-kingdom-id').value = '0';
			gid('pn-award-event-id').value   = '0';
			var term = this.value.trim();
			if (term.length < 2) { gid('pn-award-givenat-results').classList.remove('pn-ac-open'); return; }
			givenAtTimer = setTimeout(function() {
				var url = SEARCH_URL + '?Action=Search%2FLocation&type=all&name=' + encodeURIComponent(term) + '&limit=8';
				fetch(url).then(function(r) { return r.json(); }).then(function(data) {
					var results = gid('pn-award-givenat-results');
					if (!data || !data.length) {
						results.innerHTML = '<div class="pn-ac-no-results">No locations found</div>';
					} else {
						results.innerHTML = data.map(function(loc) {
							return '<div class="pn-ac-item"'
								+ ' data-park="' + (parseInt(loc.ParkId) || 0) + '"'
								+ ' data-kingdom="' + (parseInt(loc.KingdomId) || 0) + '"'
								+ ' data-event="' + (parseInt(loc.EventId) || 0) + '"'
								+ ' data-name="' + encodeURIComponent(loc.ShortName || loc.LocationName || '') + '">'
								+ (loc.LocationName || '') + '</div>';
						}).join('');
					}
					results.classList.add('pn-ac-open');
				}).catch(function() {});
			}, 250);
		});
		gid('pn-award-givenat-results').addEventListener('click', function(e) {
			var item = e.target.closest ? e.target.closest('.pn-ac-item') : (e.target.classList.contains('pn-ac-item') ? e.target : null);
			if (!item) return;
			gid('pn-award-givenat-text').value   = decodeURIComponent(item.dataset.name);
			gid('pn-award-park-id').value         = item.dataset.park    || '0';
			gid('pn-award-kingdom-id').value      = item.dataset.kingdom || '0';
			gid('pn-award-event-id').value         = item.dataset.event   || '0';
			this.classList.remove('pn-ac-open');
		});

		// Close dropdowns when clicking elsewhere inside the overlay
		gid('pn-award-overlay').addEventListener('click', function(e) {
			var givenByInput   = gid('pn-award-givenby-text');
			var givenByResults = gid('pn-award-givenby-results');
			var givenAtInput   = gid('pn-award-givenat-text');
			var givenAtResults = gid('pn-award-givenat-results');
			if (e.target !== givenByInput && !givenByResults.contains(e.target))
				givenByResults.classList.remove('pn-ac-open');
			if (e.target !== givenAtInput && !givenAtResults.contains(e.target))
				givenAtResults.classList.remove('pn-ac-open');
		});

		// ---- Note char counter ----
		gid('pn-award-note').addEventListener('input', function() {
			var rem = 400 - this.value.length;
			var el  = gid('pn-award-char-count');
			el.textContent = rem + ' character' + (rem !== 1 ? 's' : '') + ' remaining';
			el.classList.toggle('pn-char-warn', rem < 50);
		});

		// ---- Required field check ----
		function checkRequired() {
			var ok = !!gid('pn-award-select').value && !!gid('pn-award-givenby-id').value && !!gid('pn-award-date').value;
			gid('pn-award-save-same').disabled = !ok;
		}
		gid('pn-award-select').addEventListener('change', checkRequired);
		gid('pn-award-date').addEventListener('change',   checkRequired);
		gid('pn-award-date').addEventListener('input',    checkRequired);

		// ---- Open / Close ----
		window.pnOpenAwardModal = function(type) {
			// Reset
			gid('pn-award-error').style.display   = 'none';
			gid('pn-award-error').textContent      = '';
			gid('pn-award-success').style.display  = 'none';
			gid('pn-award-note').value            = '';
			gid('pn-award-char-count').textContent = '400 characters remaining';
			gid('pn-award-char-count').classList.remove('pn-char-warn');
			gid('pn-award-givenby-text').value    = '';
			gid('pn-award-givenby-id').value      = '';
			gid('pn-award-givenby-results').classList.remove('pn-ac-open');
			gid('pn-award-givenat-text').value = PnConfig.parkName;
			gid('pn-award-park-id').value = String(PnConfig.parkId);
			gid('pn-award-kingdom-id').value      = '0';
			gid('pn-award-event-id').value        = '0';
			gid('pn-award-givenat-results').classList.remove('pn-ac-open');
			gid('pn-award-custom-name').value     = '';
			gid('pn-award-custom-row').style.display = 'none';
			gid('pn-award-rank-row').style.display   = 'none';
			gid('pn-award-rank-val').value           = '';
			gid('pn-award-info-line').innerHTML      = '';
			document.querySelectorAll('#pn-award-officer-chips .pn-officer-chip').forEach(function(c) { c.classList.remove('pn-selected'); });
			// Default date = today
			var today = new Date();
			gid('pn-award-date').value = today.getFullYear() + '-'
				+ String(today.getMonth() + 1).padStart(2, '0') + '-'
				+ String(today.getDate()).padStart(2, '0');
			// Set type and render
			setAwardType(type || 'awards');
			checkRequired();
			gid('pn-award-overlay').classList.add('pn-open');
			document.body.style.overflow = 'hidden';
		};
		window.pnCloseAwardModal = function() {
			gid('pn-award-overlay').classList.remove('pn-open');
			document.body.style.overflow = '';
		};

		gid('pn-award-close-btn').addEventListener('click', pnCloseAwardModal);
		gid('pn-award-cancel').addEventListener('click',    pnCloseAwardModal);
		gid('pn-award-overlay').addEventListener('click', function(e) {
			if (e.target === this) pnCloseAwardModal();
		});
		document.addEventListener('keydown', function(e) {
			if ((e.key === 'Escape' || e.keyCode === 27) && gid('pn-award-overlay').classList.contains('pn-open'))
				pnCloseAwardModal();
		});

		// ---- Save helpers ----
		var pnSuccessTimer = null;
		function pnShowSuccess() {
			var el = gid('pn-award-success');
			el.style.display = '';
			clearTimeout(pnSuccessTimer);
			pnSuccessTimer = setTimeout(function() { el.style.display = 'none'; }, 3000);
		}
		function pnClearAward() {
			gid('pn-award-select').value             = '';
			gid('pn-award-rank-val').value           = '';
			gid('pn-award-rank-row').style.display   = 'none';
			gid('pn-award-rank-pills').innerHTML     = '';
			gid('pn-award-note').value               = '';
			gid('pn-award-char-count').textContent   = '400 characters remaining';
			gid('pn-award-char-count').classList.remove('pn-char-warn');
			gid('pn-award-info-line').innerHTML      = '';
			gid('pn-award-custom-name').value        = '';
			gid('pn-award-custom-row').style.display = 'none';
			checkRequired();
			gid('pn-award-select').focus();
		}
		function pnDoSave(onSuccess) {
			var errEl   = gid('pn-award-error');
			var awardId = gid('pn-award-select').value;
			var giverId = gid('pn-award-givenby-id').value;
			var date    = gid('pn-award-date').value;

			errEl.style.display = 'none';
			if (!awardId) { errEl.textContent = 'Please select an award.';            errEl.style.display = ''; return; }
			if (!giverId) { errEl.textContent = 'Please select who gave this award.'; errEl.style.display = ''; return; }
			if (!date)    { errEl.textContent = 'Please enter the award date.';       errEl.style.display = ''; return; }

			var fd = new FormData();
			fd.append('KingdomAwardId', awardId);
			fd.append('GivenById',      giverId);
			fd.append('Date',           date);
			fd.append('ParkId',         gid('pn-award-park-id').value    || '0');
			fd.append('KingdomId',      gid('pn-award-kingdom-id').value || '0');
			fd.append('EventId',        gid('pn-award-event-id').value   || '0');
			fd.append('Note',           gid('pn-award-note').value       || '');
			var rank = gid('pn-award-rank-val').value;
			if (rank) fd.append('Rank', rank);
			var customName = gid('pn-award-custom-name').value.trim();
			if (customName) fd.append('AwardName', customName);

			var btnSame = gid('pn-award-save-same');
			btnSame.disabled = true;
			btnSame.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

			fetch(AWARD_URL, { method: 'POST', body: fd })
				.then(function(resp) {
					if (!resp.ok) throw new Error('Server returned ' + resp.status);
					onSuccess();
				})
				.catch(function(err) {
					errEl.textContent = 'Save failed: ' + err.message;
					errEl.style.display = '';
				})
				.finally(function() {
					btnSame.innerHTML = '<i class="fas fa-plus"></i> Add Award';
					checkRequired();
				});
		}
		// Save: add award and stay in modal for another
		gid('pn-award-save-same').addEventListener('click', function() {
			pnDoSave(function() { pnShowSuccess(); pnClearAward(); });
		});
	})();

	// ---- Default sort (date desc) + initial pagination ----

	pnSortDesc($('#pn-awards-table'), 2, 'date');
	pnPaginate($('#pn-awards-table'), 1);

	pnSortDesc($('#pn-titles-table'), 2, 'date');
	pnPaginate($('#pn-titles-table'), 1);

	pnSortDesc($('#pn-attendance-table'), 0, 'date');
	pnPaginate($('#pn-attendance-table'), 1);

	pnSortDesc($('#pn-rec-table'), 2, 'date');
	pnPaginate($('#pn-rec-table'), 1);

	pnSortDesc($('#pn-history-table'), 2, 'date');
	pnPaginate($('#pn-history-table'), 1);

	pnSortDesc($('#pn-classes-table'), 2, 'numeric');
	// Classes table: click-to-sort without pagination
	$('#pn-classes-table thead th').on('click', function() {
		var $th    = $(this);
		var $table = $('#pn-classes-table');
		var col    = $th.index();
		var stype  = $th.data('sorttype') || 'text';
		var isAsc  = !$th.hasClass('sort-asc');
		$table.find('thead th').removeClass('sort-asc sort-desc');
		$th.addClass(isAsc ? 'sort-asc' : 'sort-desc');
		var $tbody = $table.find('tbody');
		var rows   = $tbody.find('tr').get();
		rows.sort(function(a, b) {
			var av = $(a).find('td').eq(col).text().trim();
			var bv = $(b).find('td').eq(col).text().trim();
			var cmp = stype === 'numeric'
				? (parseFloat(av) || 0) - (parseFloat(bv) || 0)
				: av.localeCompare(bv);
			return isAsc ? cmp : -cmp;
		});
		$.each(rows, function(i, row) { $tbody.append(row); });
	});

});

/* ===========================
   Kingdom Profile (KnConfig)
   =========================== */
// ---- Map data (server-rendered) ----
var knMapLocations, knCalEvents;
if (typeof KnConfig !== 'undefined') {
	knMapLocations = KnConfig.mapLocations;
	knCalEvents    = KnConfig.calEvents;
}
var knMapLoaded   = false;
var knCalLoaded   = false;
var knCalendar    = null;
var knCalShowPark = false; // mirrors the park-toggle button state

function knSetEventsView(view) {
	if (view === 'calendar') {
		$('#kn-events-list-view').hide();
		$('#kn-events-cal').show();
		$('#kn-ev-view-cal').addClass('kn-view-active');
		$('#kn-ev-view-list').removeClass('kn-view-active');
		$('#kn-park-toggle').hide();
		knInitCalendar();
	} else {
		$('#kn-events-cal').hide();
		$('#kn-events-list-view').show();
		$('#kn-ev-view-list').addClass('kn-view-active');
		$('#kn-ev-view-cal').removeClass('kn-view-active');
		$('#kn-park-toggle').show();
	}
	try { localStorage.setItem('kn_events_view', view); } catch(e) {}
}

function knInitCalendar() {
	if (knCalendar) {
		knCalendar.updateSize(); // fix sizing after hidden-tab init
		return;
	}
	if (knCalLoaded) return; // JS loading in progress
	knCalLoaded = true;

	// Lazy-load FullCalendar CSS + JS from CDN
	var link = document.createElement('link');
	link.rel = 'stylesheet';
	link.href = 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css';
	document.head.appendChild(link);

	var script = document.createElement('script');
	script.src = 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js';
	script.onload = function() { knRenderCalendar(); };
	document.head.appendChild(script);
}

function knRenderCalendar() {
	var el = document.getElementById('kn-events-cal');
	if (!el || typeof FullCalendar === 'undefined') return;

	// Build event list, filtering park events per current toggle state
	var events = knCalEvents.filter(function(e) {
		return !e.isPark || knCalShowPark;
	}).map(function(e) {
		return { title: e.title, start: e.start, url: e.url, color: e.color };
	});

	knCalendar = new FullCalendar.Calendar(el, {
		initialView: 'dayGridMonth',
		headerToolbar: {
			left:   'prev,next today',
			center: 'title',
			right:  'dayGridMonth,listMonth'
		},
		height: 'auto',
		events: events,
		eventClick: function(info) {
			info.jsEvent.preventDefault();
			if (info.event.url) window.location.href = info.event.url;
		}
	});
	knCalendar.render();
}

// Defined globally so Google Maps API callback can find it
window.knInitMap = async function() {
	if (!document.getElementById('kn-map')) return;
	document.getElementById('kn-map-loading').style.display = 'none';
	document.getElementById('kn-map-container').style.display = 'block';

	const { Map } = await google.maps.importLibrary("maps");
	const { AdvancedMarkerElement, PinElement } = await google.maps.importLibrary("marker");

	var map = new google.maps.Map(document.getElementById('kn-map'), {
		center: {lat: 0, lng: 0},
		zoom: 2,
		mapId: 'ORK3_MAP_ID'
	});

	var LatLngList = [];
	for (var i = 0; i < knMapLocations.length; i++) {
		LatLngList.push(new google.maps.LatLng(knMapLocations[i].lat, knMapLocations[i].lng));
	}
	if (LatLngList.length > 0) {
		var bounds = new google.maps.LatLngBounds();
		for (var i = 0; i < LatLngList.length; i++) bounds.extend(LatLngList[i]);
		map.fitBounds(bounds);
		// fitBounds zoom used as-is (no pullback)
	}

	var infowindow = new google.maps.InfoWindow();
	for (var i = 0; i < knMapLocations.length; i++) {
		(function(loc) {
			var pinGlyph = new PinElement({ scale: 0.7 });
			var marker = new google.maps.marker.AdvancedMarkerElement({
				position: new google.maps.LatLng(loc.lat, loc.lng),
				map: map,
				title: loc.name,
				content: pinGlyph.element
			});
			google.maps.event.addListener(marker, 'click', function() {
				infowindow.setContent(
					"<b><a href='" + KnConfig.uir + "Park/index/" + loc.id + "'>" + loc.name + "</a></b>" +
					"<div style='margin-top:8px;max-width:260px;font-size:12px'>" + loc.info + "</div>"
				);
				infowindow.open(map, marker);
				document.getElementById('kn-directions-title').innerHTML = '<i class="fas fa-directions"></i> ' + loc.name;
				document.getElementById('kn-map-directions').innerHTML = loc.info;
			});
		})(knMapLocations[i]);
	}
};

// ---- Player avatar image fallback ----
function knAvatarFallback(img, initial) {
	img.style.display = 'none';
	img.parentElement.innerText = initial;
}

// ---- Load More: card view (reveals next hidden period block) ----
function knLoadMoreCards(group, btn) {
	var $wrap = $(btn).closest('.kn-load-more-wrap');
	var next  = parseInt($wrap.attr('data-next') || '1');
	var $block = $('#' + group + '-block-' + next);
	if ($block.length) {
		$block.show();
		var newNext = next + 1;
		$wrap.attr('data-next', newNext);
		if (!$('#' + group + '-block-' + newNext).length) {
			$wrap.hide();
		}
	} else {
		$wrap.hide();
	}
}

// ---- Load More: list view (appends template rows to table, re-paginates) ----
function knLoadMoreList(tableId, tmplBase, btn) {
	var $wrap  = $(btn).closest('.kn-load-more-wrap');
	var next   = parseInt($wrap.attr('data-next') || '1');
	var tmpl   = document.getElementById(tmplBase + '-' + next);
	if (tmpl) {
		var $tbody = $('#' + tableId + ' tbody');
		var frag = document.importNode(tmpl.content, true);
		$(frag.querySelectorAll('tr')).appendTo($tbody);
		var newNext = next + 1;
		$wrap.attr('data-next', newNext);
		knPaginate($('#' + tableId), 1);
		if (!document.getElementById(tmplBase + '-' + newNext)) {
			$wrap.hide();
		}
	} else {
		$wrap.hide();
	}
}

// ---- Activate a tab by name (used by buttons + links) ----
function knActivateTab(tab) {
	$('.kn-tab-nav li').removeClass('kn-tab-active');
	$('.kn-tab-nav li[data-kntab="' + tab + '"]').addClass('kn-tab-active');
	$('.kn-tab-panel').hide();
	$('#kn-tab-' + tab).show();
	if (tab === 'map' && !knMapLoaded && knMapLocations.length > 0) {
		knMapLoaded = true;
		var s = document.createElement('script');
		s.src = 'https://maps.googleapis.com/maps/api/js?key=AIzaSyB_hIughnMCuRdutIvw_M_uwQUCREhHuI8&callback=knInitMap&v=weekly&libraries=marker';
		document.head.appendChild(s);
	}
	if (tab === 'events' && knCalendar) {
		knCalendar.updateSize();
	}
	$('html, body').animate({ scrollTop: $('.kn-tabs').offset().top - 20 }, 250);
}

// ---- Hero color from heraldry ----
// Samples the heraldry image via Canvas to find its dominant non-white, non-black
// color and applies a darkened version as the hero background.
function knApplyHeroColor(img) {
	var canvas = document.createElement('canvas');
	canvas.width = 60; canvas.height = 60;
	var ctx = canvas.getContext('2d');
	try {
		ctx.drawImage(img, 0, 0, 60, 60);
		var px = ctx.getImageData(0, 0, 60, 60).data;
		var buckets = {};
		for (var i = 0; i < px.length; i += 4) {
			var r = px[i], g = px[i+1], b = px[i+2], a = px[i+3];
			if (a < 120) continue;                          // skip transparent
			if (r > 215 && g > 215 && b > 215) continue;   // skip near-white
			if (r < 25  && g < 25  && b < 25)  continue;   // skip near-black
			// Bucket colors into 16-step bins so similar shades merge
			var key = (r >> 4) + ',' + (g >> 4) + ',' + (b >> 4);
			buckets[key] = (buckets[key] || 0) + 1;
		}
		var best = null, bestN = 0;
		for (var k in buckets) { if (buckets[k] > bestN) { bestN = buckets[k]; best = k; } }
		if (!best) return;

		// Reconstruct mid-point of bucket
		var parts = best.split(',');
		var dr = parseInt(parts[0]) * 16 + 8;
		var dg = parseInt(parts[1]) * 16 + 8;
		var db = parseInt(parts[2]) * 16 + 8;

		// Convert to HSL
		var rf = dr/255, gf = dg/255, bf = db/255;
		var max = Math.max(rf,gf,bf), min = Math.min(rf,gf,bf);
		var h = 0, s = 0, l = (max+min)/2;
		if (max !== min) {
			var d = max - min;
			s = l > 0.5 ? d/(2-max-min) : d/(max+min);
			if      (max === rf) h = (gf-bf)/d + (gf < bf ? 6 : 0);
			else if (max === gf) h = (bf-rf)/d + 2;
			else                 h = (rf-gf)/d + 4;
			h /= 6;
		}

		// Clamp: keep hue, boost saturation if washed out, fix lightness for dark bg
		var finalS = Math.max(s, 0.28);
		var heroEl = document.querySelector('.kn-hero');
		if (heroEl) {
			heroEl.style.backgroundColor =
				'hsl(' + Math.round(h*360) + ',' + Math.round(finalS*100) + '%,18%)';
		}
	} catch(e) { /* CORS or tainted canvas — keep default green */ }
}

// ---- Pagination helpers ----
function knPageRange(current, total) {
	var pages = [];
	if (total <= 7) {
		for (var p = 1; p <= total; p++) pages.push(p);
	} else {
		pages.push(1);
		if (current > 3) pages.push(-1);
		var s = Math.max(2, current - 1);
		var e = Math.min(total - 1, current + 1);
		for (var p = s; p <= e; p++) pages.push(p);
		if (current < total - 2) pages.push(-1);
		pages.push(total);
	}
	return pages;
}

function knToggleParkItems(btn) {
	var isOn = !$('#kn-events-table').data('show-park');
	$('#kn-events-table').data('show-park', isOn);
	$('#kn-tournaments-table').data('show-park', isOn);
	knPaginate($('#kn-events-table'), 1);
	knPaginate($('#kn-tournaments-table'), 1);
	$(btn).css({ background: isOn ? '#276749' : '#fff', color: isOn ? '#fff' : '#718096', 'border-color': isOn ? '#276749' : '#cbd5e0' });
	$('#kn-park-toggle-label').text(isOn ? 'ON' : 'OFF').css('color', isOn ? 'rgba(255,255,255,0.75)' : '#a0aec0');
	// Keep calendar park-event state in sync
	knCalShowPark = isOn;
	if (knCalendar) {
		var filteredEvents = knCalEvents.filter(function(e) { return !e.isPark || knCalShowPark; })
			.map(function(e) { return { title: e.title, start: e.start, url: e.url, color: e.color }; });
		knCalendar.removeAllEvents();
		knCalendar.addEventSource(filteredEvents);
	}
}

function knPaginate($table, page) {
	var pageSize = 25;
	// Enforce park-row visibility before counting
	var showPark = !!$table.data('show-park');
	$table.find('tbody tr.kn-park-row').css('display', showPark ? '' : 'none');
	var $rows = $table.find('tbody tr').filter(function() { return $(this).css('display') !== 'none'; });
	var total = $rows.length;
	if (total === 0) { $table.next('.kn-pagination').empty().hide(); return; }
	var totalPages = Math.max(1, Math.ceil(total / pageSize));
	page = Math.max(1, Math.min(page, totalPages));
	$table.data('kn-page', page);
	$rows.each(function(i) {
		$(this).toggle(i >= (page - 1) * pageSize && i < page * pageSize);
	});
	var $pg = $table.next('.kn-pagination');
	if ($pg.length === 0) $pg = $('<div class="kn-pagination"></div>').insertAfter($table);
	if (total <= pageSize) { $pg.empty().hide(); return; }
	$pg.show();
	var start = (page - 1) * pageSize + 1;
	var end   = Math.min(page * pageSize, total);
	var html  = '<span class="kn-pagination-info">Showing ' + start + '\u2013' + end + ' of ' + total + '</span>';
	html += '<div class="kn-pagination-controls">';
	html += '<button class="kn-page-btn kn-page-prev"' + (page === 1 ? ' disabled' : '') + '>&#8249;</button>';
	var range = knPageRange(page, totalPages);
	for (var ri = 0; ri < range.length; ri++) {
		if (range[ri] === -1) {
			html += '<span class="kn-page-ellipsis">&hellip;</span>';
		} else {
			html += '<button class="kn-page-btn kn-page-num' + (range[ri] === page ? ' kn-page-active' : '') + '" data-page="' + range[ri] + '">' + range[ri] + '</button>';
		}
	}
	html += '<button class="kn-page-btn kn-page-next"' + (page === totalPages ? ' disabled' : '') + '>&#8250;</button>';
	html += '</div>';
	$pg.html(html);
}

function knSortDesc($table, colIndex, sortType) {
	if (!$table.length) return;
	$table.find('thead th').removeClass('sort-asc sort-desc');
	$table.find('thead th').eq(colIndex).addClass('sort-desc');
	var $tbody = $table.find('tbody');
	var rows = $tbody.find('tr').get();
	rows.sort(function(a, b) {
		var aVal = $(a).find('td').eq(colIndex).text().trim();
		var bVal = $(b).find('td').eq(colIndex).text().trim();
		var cmp = 0;
		if (sortType === 'numeric')   cmp = (parseFloat(aVal) || 0) - (parseFloat(bVal) || 0);
		else if (sortType === 'date') cmp = (new Date(aVal).getTime() || 0) - (new Date(bVal).getTime() || 0);
		else                          cmp = aVal.localeCompare(bVal);
		return -cmp;
	});
	$.each(rows, function(i, row) { $tbody.append(row); });
}

function knSortAsc($table, colIndex, sortType) {
	if (!$table.length) return;
	$table.find('thead th').removeClass('sort-asc sort-desc');
	$table.find('thead th').eq(colIndex).addClass('sort-asc');
	var $tbody = $table.find('tbody');
	var rows = $tbody.find('tr').get();
	rows.sort(function(a, b) {
		var aVal = $(a).find('td').eq(colIndex).text().trim();
		var bVal = $(b).find('td').eq(colIndex).text().trim();
		var cmp = 0;
		if (sortType === 'numeric')   cmp = (parseFloat(aVal) || 0) - (parseFloat(bVal) || 0);
		else if (sortType === 'date') cmp = (new Date(aVal).getTime() || 0) - (new Date(bVal).getTime() || 0);
		else                          cmp = aVal.localeCompare(bVal);
		return cmp;
	});
	$.each(rows, function(i, row) { $tbody.append(row); });
}

$(document).ready(function() {
	if (typeof KnConfig === 'undefined') return;

	// ---- Hero color from heraldry ----
	var knHeraldryImg = document.querySelector('.kn-heraldry-frame img');
	if (knHeraldryImg) {
		if (knHeraldryImg.complete && knHeraldryImg.naturalWidth) {
			knApplyHeroColor(knHeraldryImg);
		} else {
			knHeraldryImg.addEventListener('load', function() { knApplyHeroColor(this); });
		}
	}

	// ---- Tab switching ----
	$('.kn-tab-nav li').on('click', function() {
		knActivateTab($(this).attr('data-kntab'));
	});

	// ---- Parks view toggle (tiles / list) ----
	function knSetParksView(view) {
		if (view === 'list') {
			$('#kn-parks-tiles').hide();
			$('#kn-parks-list-view').show();
			$('#kn-view-list').addClass('kn-view-active');
			$('#kn-view-tiles').removeClass('kn-view-active');
		} else {
			$('#kn-parks-list-view').hide();
			$('#kn-parks-tiles').show();
			$('#kn-view-tiles').addClass('kn-view-active');
			$('#kn-view-list').removeClass('kn-view-active');
		}
		try { localStorage.setItem('kn_parks_view', view); } catch(e) {}
	}
	$('#kn-view-tiles').on('click', function() { knSetParksView('tiles'); });
	$('#kn-view-list').on('click',  function() { knSetParksView('list'); });
	// Restore preference, defaulting to tiles
	try {
		knSetParksView(localStorage.getItem('kn_parks_view') || 'tiles');
	} catch(e) {
		knSetParksView('tiles');
	}

	// ---- Events view toggle (list / calendar) ----
	$('#kn-ev-view-list').on('click', function() { knSetEventsView('list'); });
	$('#kn-ev-view-cal').on('click',  function() { knSetEventsView('calendar'); });
	// Restore preference, defaulting to list
	try {
		knSetEventsView(localStorage.getItem('kn_events_view') || 'list');
	} catch(e) {
		knSetEventsView('list');
	}

	// ---- Sortable tables ----
	$('.kn-sortable').each(function() {
		var $table = $(this);
		$table.find('thead th').on('click', function() {
			var colIndex = $(this).index();
			var sortType = $(this).data('sorttype') || 'text';
			var isAsc = !$(this).hasClass('sort-asc');
			$table.find('thead th').removeClass('sort-asc sort-desc');
			$(this).addClass(isAsc ? 'sort-asc' : 'sort-desc');
			var $tbody = $table.find('tbody');
			var rows = $tbody.find('tr').get();
			rows.sort(function(a, b) {
				var aVal = $(a).find('td').eq(colIndex).text().trim();
				var bVal = $(b).find('td').eq(colIndex).text().trim();
				var cmp = 0;
				if (sortType === 'numeric')   cmp = (parseFloat(aVal) || 0) - (parseFloat(bVal) || 0);
				else if (sortType === 'date') cmp = (new Date(aVal).getTime() || 0) - (new Date(bVal).getTime() || 0);
				else                          cmp = aVal.localeCompare(bVal);
				return isAsc ? cmp : -cmp;
			});
			$.each(rows, function(i, row) { $tbody.append(row); });
			knPaginate($table, 1);
		});
	});

	// ---- Pagination event delegation ----
	$(document).on('click', '.kn-page-num', function() {
		var $table = $(this).closest('.kn-pagination').prev('.kn-table');
		if ($table.length) knPaginate($table, parseInt($(this).data('page')));
	});
	$(document).on('click', '.kn-page-prev', function() {
		if ($(this).prop('disabled')) return;
		var $table = $(this).closest('.kn-pagination').prev('.kn-table');
		if ($table.length) knPaginate($table, ($table.data('kn-page') || 1) - 1);
	});
	$(document).on('click', '.kn-page-next', function() {
		if ($(this).prop('disabled')) return;
		var $table = $(this).closest('.kn-pagination').prev('.kn-table');
		if ($table.length) knPaginate($table, ($table.data('kn-page') || 1) + 1);
	});

	// ---- Players: view toggle (cards / list) ----
	$('[data-knview]').on('click', function() {
		var view = $(this).attr('data-knview');
		$('[data-knview]').removeClass('kn-view-active');
		$(this).addClass('kn-view-active');
		if (view === 'list') {
			$('#kn-players-cards').hide();
			$('#kn-players-list').show();
		} else {
			$('#kn-players-list').hide();
			$('#kn-players-cards').show();
		}
	});

	// ---- Players: search (filters all .kn-player-card across all periods) ----
	$('#kn-player-search').on('input', function() {
		var q = $(this).val().trim().toLowerCase();
		if (q === '') {
			$('.kn-period-block').show();
			$('.kn-player-card').show();
		} else {
			// Show all period blocks first so cards inside are visible/searchable
			$('.kn-period-block').show();
			$('.kn-player-card').each(function() {
				var name = $(this).find('.kn-player-name').text().toLowerCase();
				$(this).toggle(name.indexOf(q) !== -1);
			});
			// Hide period blocks with no visible cards
			$('.kn-period-block').each(function() {
				var hasVisible = $(this).find('.kn-player-card:visible').length > 0;
				$(this).toggle(hasVisible);
			});
		}
	});

	// ---- Default sort + initial pagination ----

	knSortAsc($('#kn-parks-table'), 0, 'text');
	knPaginate($('#kn-parks-table'), 1);

	knSortDesc($('#kn-events-table'), 0, 'date');
	knPaginate($('#kn-events-table'), 1);

	knSortDesc($('#kn-tournaments-table'), 0, 'date');
	knPaginate($('#kn-tournaments-table'), 1);

	knPaginate($('#kn-players-table'), 1);

});
(function() {
	if (!document.getElementById('kn-award-overlay')) return;
	var UIR_JS = KnConfig.uir;
	var SEARCH_URL = KnConfig.httpService + 'Search/SearchService.php';
	var KINGDOM_ID = KnConfig.kingdomId;
	var awardOptHTML = KnConfig.awardOptHTML;
	var officerOptHTML = KnConfig.officerOptHTML;
	var currentType = 'awards';
	var givenByTimer, givenAtTimer, playerTimer;

	function gid(id) { return document.getElementById(id); }

	function checkRequired() {
		var ok = !!gid('kn-award-player-id').value
		      && !!gid('kn-award-select').value
		      && !!gid('kn-award-givenby-id').value
		      && !!gid('kn-award-date').value;
		gid('kn-award-save-new').disabled  = !ok;
		gid('kn-award-save-same').disabled = !ok;
	}

	function setAwardType(type) {
		currentType = type;
		var isOfficer = type === 'officers';
		gid('kn-award-modal-title').innerHTML = isOfficer
			? '<i class="fas fa-crown" style="margin-right:8px;color:#553c9a"></i>Add Officer Title'
			: '<i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award';
		gid('kn-award-select').innerHTML = isOfficer ? officerOptHTML : awardOptHTML;
		gid('kn-award-rank-row').style.display   = 'none';
		gid('kn-award-rank-val').value           = '';
		gid('kn-award-info-line').innerHTML      = '';
		gid('kn-award-type-awards').classList.toggle('kn-active', !isOfficer);
		gid('kn-award-type-officers').classList.toggle('kn-active', isOfficer);
		checkRequired();
	}

	gid('kn-award-type-awards').addEventListener('click',   function() { setAwardType('awards'); });
	gid('kn-award-type-officers').addEventListener('click', function() { setAwardType('officers'); });

	function buildRankPills(awardId) {
		var row   = gid('kn-award-rank-row');
		var wrap  = gid('kn-rank-pills');
		var input = gid('kn-award-rank-val');
		wrap.innerHTML = '';
		input.value = '';
		row.style.display = 'none';
		if (!awardId) return;
		var opt = gid('kn-award-select').querySelector('option[value="' + awardId + '"]');
		if (!opt || opt.getAttribute('data-is-ladder') !== '1') return;
		row.style.display = '';
		for (var r = 1; r <= 10; r++) {
			var pill = document.createElement('button');
			pill.type      = 'button';
			pill.className = 'kn-rank-pill';
			pill.textContent = r;
			pill.dataset.rank = r;
			pill.addEventListener('click', (function(rank, el) {
				return function() {
					document.querySelectorAll('#kn-rank-pills .kn-rank-pill').forEach(function(p) { p.classList.remove('kn-rank-selected'); });
					el.classList.add('kn-rank-selected');
					input.value = rank;
				};
			})(r, pill));
			wrap.appendChild(pill);
		}
	}

	gid('kn-award-select').addEventListener('change', function() {
		var awardId = this.value;
		var isCustom = this.options[this.selectedIndex] && this.options[this.selectedIndex].text.toLowerCase().indexOf('custom') >= 0;
		gid('kn-award-custom-row').style.display = isCustom ? '' : 'none';
		buildRankPills(awardId);
		var infoEl = gid('kn-award-info-line');
		if (awardId) {
			var opt = this.querySelector('option[value="' + awardId + '"]');
			infoEl.innerHTML = opt && opt.getAttribute('data-is-ladder') === '1'
				? '<span class="kn-badge-ladder"><i class="fas fa-layer-group"></i> Ladder Award</span>'
				: '';
		} else { infoEl.innerHTML = ''; }
		checkRequired();
	});

	// Player search autocomplete
	gid('kn-award-player-text').addEventListener('input', function() {
		gid('kn-award-player-id').value = '';
		checkRequired();
		var term = this.value.trim();
		if (term.length < 2) { gid('kn-award-player-results').classList.remove('kn-ac-open'); return; }
		clearTimeout(playerTimer);
		playerTimer = setTimeout(function() {
			var url = SEARCH_URL + '?Action=Search%2FPlayer&type=all&search=' + encodeURIComponent(term) + '&kingdom_id=' + KINGDOM_ID + '&limit=8';
			fetch(url).then(function(r) { return r.json(); }).then(function(data) {
				var el = gid('kn-award-player-results');
				el.innerHTML = (data && data.length)
					? data.map(function(p) {
						return '<div class="kn-ac-item" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
							+ p.Persona + ' <span style="color:#a0aec0;font-size:11px">(' + (p.KAbbr||'') + ':' + (p.PAbbr||'') + ')</span></div>';
					}).join('')
					: '<div class="kn-ac-item" style="color:#a0aec0;cursor:default">No players found</div>';
				el.classList.add('kn-ac-open');
			}).catch(function() {});
		}, 250);
	});
	gid('kn-award-player-results').addEventListener('click', function(e) {
		var item = e.target.closest('.kn-ac-item[data-id]');
		if (!item) return;
		gid('kn-award-player-text').value = decodeURIComponent(item.dataset.name);
		gid('kn-award-player-id').value   = item.dataset.id;
		this.classList.remove('kn-ac-open');
		checkRequired();
	});

	// Given By — officer chips + search

	document.querySelectorAll('#kn-award-officer-chips .kn-officer-chip').forEach(function(btn) {
		btn.addEventListener('click', function() {
			document.querySelectorAll('#kn-award-officer-chips .kn-officer-chip').forEach(function(c) { c.classList.remove('kn-selected'); });
			this.classList.add('kn-selected');
			gid('kn-award-givenby-text').value = this.dataset.name;
			gid('kn-award-givenby-id').value   = this.dataset.id;
			gid('kn-award-givenby-results').classList.remove('kn-ac-open');
			checkRequired();
		});
	});

	gid('kn-award-givenby-text').addEventListener('input', function() {
		gid('kn-award-givenby-id').value = '';
		document.querySelectorAll('#kn-award-officer-chips .kn-officer-chip').forEach(function(c) { c.classList.remove('kn-selected'); });
		checkRequired();
		var term = this.value.trim();
		if (term.length < 2) { gid('kn-award-givenby-results').classList.remove('kn-ac-open'); return; }
		clearTimeout(givenByTimer);
		givenByTimer = setTimeout(function() {
			var url = SEARCH_URL + '?Action=Search%2FPlayer&type=all&search=' + encodeURIComponent(term) + '&kingdom_id=' + KINGDOM_ID + '&limit=6';
			fetch(url).then(function(r) { return r.json(); }).then(function(data) {
				var el = gid('kn-award-givenby-results');
				el.innerHTML = (data && data.length)
					? data.map(function(p) {
						return '<div class="kn-ac-item" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
							+ p.Persona + ' <span style="color:#a0aec0;font-size:11px">(' + (p.KAbbr||'') + ':' + (p.PAbbr||'') + ')</span></div>';
					}).join('')
					: '<div class="kn-ac-item" style="color:#a0aec0;cursor:default">No results</div>';
				el.classList.add('kn-ac-open');
			}).catch(function() {});
		}, 250);
	});
	gid('kn-award-givenby-results').addEventListener('click', function(e) {
		var item = e.target.closest('.kn-ac-item[data-id]');
		if (!item) return;
		gid('kn-award-givenby-text').value = decodeURIComponent(item.dataset.name);
		gid('kn-award-givenby-id').value   = item.dataset.id;
		this.classList.remove('kn-ac-open');
		checkRequired();
	});

	// Given At — location search
	gid('kn-award-givenat-text').addEventListener('input', function() {
		var term = this.value.trim();
		if (term.length < 2) { gid('kn-award-givenat-results').classList.remove('kn-ac-open'); return; }
		clearTimeout(givenAtTimer);
		givenAtTimer = setTimeout(function() {
			var url = SEARCH_URL + '?Action=Search%2FLocation&search=' + encodeURIComponent(term) + '&kingdom_id=' + KINGDOM_ID + '&limit=6';
			fetch(url).then(function(r) { return r.json(); }).then(function(data) {
				var el = gid('kn-award-givenat-results');
				el.innerHTML = (data && data.length)
					? data.map(function(loc) {
						return '<div class="kn-ac-item" data-pid="' + (loc.ParkId||0) + '" data-kid="' + (loc.KingdomId||0) + '" data-eid="' + (loc.EventId||0) + '" data-name="' + encodeURIComponent(loc.LocationName||loc.ShortName||'') + '">'
							+ (loc.LocationName || loc.ShortName || '') + '</div>';
					}).join('')
					: '<div class="kn-ac-item" style="color:#a0aec0;cursor:default">No locations found</div>';
				el.classList.add('kn-ac-open');
			}).catch(function() {});
		}, 250);
	});
	gid('kn-award-givenat-results').addEventListener('click', function(e) {
		var item = e.target.closest('.kn-ac-item');
		if (!item || !item.dataset.name) return;
		gid('kn-award-givenat-text').value    = decodeURIComponent(item.dataset.name);
		gid('kn-award-park-id').value         = item.dataset.pid || '0';
		gid('kn-award-kingdom-id').value      = item.dataset.kid || '0';
		gid('kn-award-event-id').value        = item.dataset.eid || '0';
		this.classList.remove('kn-ac-open');
	});

	// Note char counter
	gid('kn-award-note').addEventListener('input', function() {
		var rem = 400 - this.value.length;
		var el  = gid('kn-award-char-count');
		el.textContent = rem + ' character' + (rem !== 1 ? 's' : '') + ' remaining';
	});

	gid('kn-award-select').addEventListener('change', checkRequired);
	gid('kn-award-date').addEventListener('change', checkRequired);
	gid('kn-award-date').addEventListener('input',  checkRequired);

	// ---- Open / Close ----
	window.knOpenAwardModal = function() {
		var today = new Date();
		gid('kn-award-error').style.display      = 'none';
		gid('kn-award-error').textContent        = '';
		gid('kn-award-success').style.display    = 'none';
		gid('kn-award-player-text').value        = '';
		gid('kn-award-player-id').value          = '';
		gid('kn-award-player-results').classList.remove('kn-ac-open');
		gid('kn-award-note').value               = '';
		gid('kn-award-char-count').textContent   = '400 characters remaining';
		gid('kn-award-givenby-text').value       = '';
		gid('kn-award-givenby-id').value         = '';
		gid('kn-award-givenby-results').classList.remove('kn-ac-open');
		gid('kn-award-givenat-text').value = KnConfig.kingdomName;
		gid('kn-award-park-id').value            = '0';
		gid('kn-award-kingdom-id').value = String(KnConfig.kingdomId);
		gid('kn-award-event-id').value           = '0';
		gid('kn-award-givenat-results').classList.remove('kn-ac-open');
		gid('kn-award-custom-name').value        = '';
		gid('kn-award-custom-row').style.display = 'none';
		gid('kn-award-rank-row').style.display   = 'none';
		gid('kn-award-rank-val').value           = '';
		gid('kn-award-info-line').innerHTML      = '';
		document.querySelectorAll('#kn-award-officer-chips .kn-officer-chip').forEach(function(c) { c.classList.remove('kn-selected'); });
		gid('kn-award-date').value = today.getFullYear() + '-'
			+ String(today.getMonth() + 1).padStart(2, '0') + '-'
			+ String(today.getDate()).padStart(2, '0');
		setAwardType('awards');
		checkRequired();
		gid('kn-award-overlay').classList.add('kn-open');
		document.body.style.overflow = 'hidden';
		gid('kn-award-player-text').focus();
	};
	window.knCloseAwardModal = function() {
		gid('kn-award-overlay').classList.remove('kn-open');
		document.body.style.overflow = '';
	};

	gid('kn-award-close-btn').addEventListener('click', knCloseAwardModal);
	gid('kn-award-cancel').addEventListener('click',    knCloseAwardModal);
	gid('kn-award-overlay').addEventListener('click', function(e) {
		if (e.target === this) knCloseAwardModal();
	});
	document.addEventListener('keydown', function(e) {
		if ((e.key === 'Escape' || e.keyCode === 27) && gid('kn-award-overlay').classList.contains('kn-open'))
			knCloseAwardModal();
	});

	// ---- Save helpers ----
	var knSuccessTimer = null;
	function knShowSuccess() {
		var el = gid('kn-award-success');
		el.style.display = '';
		clearTimeout(knSuccessTimer);
		knSuccessTimer = setTimeout(function() { el.style.display = 'none'; }, 3000);
	}
	function knClearPlayer() {
		gid('kn-award-player-text').value = '';
		gid('kn-award-player-id').value   = '';
		gid('kn-award-player-results').classList.remove('kn-ac-open');
	}
	function knClearAward() {
		gid('kn-award-select').value             = '';
		gid('kn-award-rank-val').value           = '';
		gid('kn-award-rank-row').style.display   = 'none';
		gid('kn-rank-pills').innerHTML           = '';
		gid('kn-award-note').value               = '';
		gid('kn-award-char-count').textContent   = '400 characters remaining';
		gid('kn-award-info-line').innerHTML      = '';
		gid('kn-award-custom-name').value        = '';
		gid('kn-award-custom-row').style.display = 'none';
		checkRequired();
	}
	function knDoSave(onSuccess) {
		var errEl    = gid('kn-award-error');
		var playerId = gid('kn-award-player-id').value;
		var awardId  = gid('kn-award-select').value;
		var giverId  = gid('kn-award-givenby-id').value;
		var date     = gid('kn-award-date').value;

		errEl.style.display = 'none';
		if (!playerId) { errEl.textContent = 'Please select a player.';             errEl.style.display = ''; return; }
		if (!awardId)  { errEl.textContent = 'Please select an award.';             errEl.style.display = ''; return; }
		if (!giverId)  { errEl.textContent = 'Please select who gave this award.';  errEl.style.display = ''; return; }
		if (!date)     { errEl.textContent = 'Please enter the award date.';        errEl.style.display = ''; return; }

		var fd = new FormData();
		fd.append('KingdomAwardId', awardId);
		fd.append('GivenById',      giverId);
		fd.append('Date',           date);
		fd.append('ParkId',         gid('kn-award-park-id').value    || '0');
		fd.append('KingdomId',      gid('kn-award-kingdom-id').value || '0');
		fd.append('EventId',        gid('kn-award-event-id').value   || '0');
		fd.append('Note',           gid('kn-award-note').value       || '');
		var rank = gid('kn-award-rank-val').value;
		if (rank) fd.append('Rank', rank);
		var customName = gid('kn-award-custom-name') ? gid('kn-award-custom-name').value.trim() : '';
		if (customName) fd.append('AwardName', customName);

		var btnNew  = gid('kn-award-save-new');
		var btnSame = gid('kn-award-save-same');
		btnNew.disabled = btnSame.disabled = true;
		btnNew.innerHTML  = '<i class="fas fa-spinner fa-spin"></i>';
		btnSame.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

		var saveUrl = UIR_JS + 'Admin/player/' + playerId + '/addaward';
		fetch(saveUrl, { method: 'POST', body: fd })
			.then(function(resp) {
				if (!resp.ok) throw new Error('Server returned ' + resp.status);
				onSuccess();
			})
			.catch(function(err) {
				errEl.textContent = 'Save failed: ' + err.message;
				errEl.style.display = '';
			})
			.finally(function() {
				btnNew.innerHTML  = '<i class="fas fa-plus"></i> Add + New Player';
				btnSame.innerHTML = '<i class="fas fa-plus"></i> Add + Same Player';
				checkRequired();
			});
	}

	// "Add + New Player" — clear player + award/rank/note, keep date/giver/location
	gid('kn-award-save-new').addEventListener('click', function() {
		knDoSave(function() { knShowSuccess(); knClearPlayer(); knClearAward(); gid('kn-award-player-text').focus(); });
	});
	// "Add + Same Player" — clear only award/rank/note, keep player + date/giver/location
	gid('kn-award-save-same').addEventListener('click', function() {
		knDoSave(function() { knShowSuccess(); knClearAward(); gid('kn-award-select').focus(); });
	});
})();
(function() {
	if (typeof KnConfig === 'undefined') return;
	var knEventKingdomId = KnConfig.kingdomId;
	var knEventUIR = KnConfig.uir;

	window.knOpenEventModal = function() {
		var sel = document.getElementById('kn-template-select');
		var btn = document.getElementById('kn-emod-go-btn');
		sel.innerHTML = '<option value="">Loading…</option>';
		btn.disabled = true;

		$.getJSON(KnConfig.httpService + 'Search/SearchService.php',
			{ Action: 'Search/Event', kingdom_id: knEventKingdomId, limit: 50 },
			function(data) {
				if (!data || !data.length) {
					sel.innerHTML = '<option value="">No templates found for this kingdom</option>';
					return;
				}
				sel.innerHTML = '<option value="">— Select a template —</option>';
				$.each(data, function(i, v) {
					var label = v.Name + (v.ParkName ? ' (' + v.ParkName + ')' : '');
					var opt = document.createElement('option');
					opt.value = v.EventId;
					opt.textContent = label;
					sel.appendChild(opt);
				});
			}
		).fail(function() {
			sel.innerHTML = '<option value="">Error loading templates</option>';
		});

		sel.addEventListener('change', function() {
			btn.disabled = !this.value;
		});

		document.getElementById('kn-event-modal').classList.add('kn-emod-open');
		document.body.style.overflow = 'hidden';
	};

	window.knCloseEventModal = function() {
		document.getElementById('kn-event-modal').classList.remove('kn-emod-open');
		document.body.style.overflow = '';
	};

	window.knGoToEventCreate = function() {
		var v = document.getElementById('kn-template-select').value;
		if (v) window.location.href = knEventUIR + 'Event/create/' + v;
	};

	$(document).ready(function() {
		var knEvtOverlay = document.getElementById('kn-event-modal');
		if (knEvtOverlay) {
			knEvtOverlay.addEventListener('click', function(e) {
				if (e.target === this) knCloseEventModal();
			});
		}
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' && document.getElementById('kn-event-modal')) knCloseEventModal();
		});
	});
})();

// ---- Add Park Modal ----
(function() {
	if (typeof KnConfig === 'undefined') return;
	if (!KnConfig.canManage) return;

	var CREATE_URL = KnConfig.uir + 'ParkAjax/kingdom/' + KnConfig.kingdomId + '/create';

	function gid(id) { return document.getElementById(id); }

	function knCloseAddParkModal() {
		var overlay = gid('kn-addpark-overlay');
		if (overlay) overlay.classList.remove('kn-open');
		document.body.style.overflow = '';
	}

	function knAddParkShowFeedback(msg, ok) {
		var el = gid('kn-addpark-feedback');
		if (!el) return;
		el.textContent = msg;
		el.className = ok ? 'kn-addpark-ok' : 'kn-addpark-err';
		el.style.display = '';
	}
	function knAddParkHideFeedback() {
		var el = gid('kn-addpark-feedback');
		if (!el) return;
		el.style.display = 'none';
		el.className = '';
	}

	window.knOpenAddParkModal = function() {
		var nameEl = gid('kn-addpark-name');
		if (!nameEl) return;
		nameEl.value = '';
		gid('kn-addpark-abbr').value  = '';
		gid('kn-addpark-title').value = '';
		knAddParkHideFeedback();
		gid('kn-addpark-overlay').classList.add('kn-open');
		document.body.style.overflow = 'hidden';
		setTimeout(function() { nameEl.focus(); }, 50);
	};

	$(document).ready(function() {
		var submitBtn = gid('kn-addpark-submit');
		if (!submitBtn) return;

		submitBtn.addEventListener('click', function() {
			var name    = gid('kn-addpark-name').value.trim();
			var abbr    = gid('kn-addpark-abbr').value.trim().replace(/[^A-Za-z0-9]/g, '');
			var titleId = gid('kn-addpark-title').value;

			if (!name)    { knAddParkShowFeedback('Park must have a name.', false); return; }
			if (!abbr)    { knAddParkShowFeedback('Park must have an abbreviation.', false); return; }
			if (!titleId) { knAddParkShowFeedback('Parks must have a title.', false); return; }

			var btn = gid('kn-addpark-submit');
			btn.disabled = true;

			$.post(CREATE_URL, { Name: name, Abbreviation: abbr, ParkTitleId: titleId }, function(r) {
				btn.disabled = false;
				if (r && r.status === 0) {
					knAddParkShowFeedback('Park created! Redirecting\u2026', true);
					setTimeout(function() {
						window.location.href = KnConfig.uir + 'Park/index/' + r.parkId;
					}, 1000);
				} else {
					knAddParkShowFeedback((r && r.error) ? r.error : 'Creation failed. Please try again.', false);
				}
			}, 'json').fail(function() {
				btn.disabled = false;
				knAddParkShowFeedback('Request failed. Please try again.', false);
			});
		});

		var cancelBtn = gid('kn-addpark-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', knCloseAddParkModal);
		var closeBtn = gid('kn-addpark-close-btn');
		if (closeBtn) closeBtn.addEventListener('click', knCloseAddParkModal);
		var overlay = gid('kn-addpark-overlay');
		if (overlay) {
			overlay.addEventListener('click', function(e) {
				if (e.target === this) knCloseAddParkModal();
			});
		}
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' && gid('kn-addpark-overlay') && gid('kn-addpark-overlay').classList.contains('kn-open'))
				knCloseAddParkModal();
		});
	});
})();

// ---- Edit Park Modal ----
(function() {
	if (typeof KnConfig === 'undefined') return;
	if (!KnConfig.canManage) return;

	var EDIT_URL = KnConfig.uir + 'ParkAjax/kingdom/' + KnConfig.kingdomId + '/editpark';

	// Index lookup by ParkId
	var parkIndex = {};
	(KnConfig.parkEditLookup || []).forEach(function(p) { parkIndex[p.ParkId] = p; });

	function gid(id) { return document.getElementById(id); }

	function knCloseEditParkModal() {
		var overlay = gid('kn-editpark-overlay');
		if (overlay) overlay.classList.remove('kn-open');
		document.body.style.overflow = '';
	}

	function knEditParkShowFeedback(msg, ok) {
		var el = gid('kn-editpark-feedback');
		if (!el) return;
		el.textContent = msg;
		el.className = ok ? 'kn-editpark-ok' : 'kn-editpark-err';
		el.style.display = '';
	}
	function knEditParkHideFeedback() {
		var el = gid('kn-editpark-feedback');
		if (!el) return;
		el.style.display = 'none';
		el.className = '';
	}

	window.knOpenEditParkModal = function(parkId) {
		var idEl = gid('kn-editpark-id');
		if (!idEl) return;
		var park = parkIndex[parkId];
		if (!park) return;

		idEl.value = park.ParkId;
		gid('kn-editpark-name').value  = park.Name;
		gid('kn-editpark-abbr').value  = park.Abbreviation;
		gid('kn-editpark-title').value = park.ParkTitleId;
		gid('kn-editpark-active').checked = (park.Active === 'Active');
		knEditParkHideFeedback();
		gid('kn-editpark-overlay').classList.add('kn-open');
		document.body.style.overflow = 'hidden';
		setTimeout(function() { gid('kn-editpark-name').focus(); }, 50);
	};

	$(document).ready(function() {
		var submitBtn = gid('kn-editpark-submit');
		if (!submitBtn) return;

		submitBtn.addEventListener('click', function() {
			var parkId  = gid('kn-editpark-id').value;
			var name    = gid('kn-editpark-name').value.trim();
			var abbr    = gid('kn-editpark-abbr').value.trim().replace(/[^A-Za-z0-9]/g, '');
			var titleId = gid('kn-editpark-title').value;
			var active  = gid('kn-editpark-active').checked ? 'Active' : 'Retired';

			if (!name)    { knEditParkShowFeedback('Park must have a name.', false); return; }
			if (!abbr)    { knEditParkShowFeedback('Park must have an abbreviation.', false); return; }
			if (!titleId) { knEditParkShowFeedback('Parks must have a title.', false); return; }

			var btn = gid('kn-editpark-submit');
			btn.disabled = true;

			$.post(EDIT_URL, { ParkId: parkId, Name: name, Abbreviation: abbr, ParkTitleId: titleId, Active: active }, function(r) {
				btn.disabled = false;
				if (r && r.status === 0) {
					knEditParkShowFeedback('Park updated!', true);
					if (parkIndex[parseInt(parkId)]) {
						parkIndex[parseInt(parkId)].Name         = name;
						parkIndex[parseInt(parkId)].Abbreviation = abbr;
						parkIndex[parseInt(parkId)].ParkTitleId  = parseInt(titleId);
						parkIndex[parseInt(parkId)].Active       = active;
					}
					setTimeout(function() { knCloseEditParkModal(); location.reload(); }, 800);
				} else {
					knEditParkShowFeedback((r && r.error) ? r.error : 'Update failed. Please try again.', false);
				}
			}, 'json').fail(function() {
				btn.disabled = false;
				knEditParkShowFeedback('Request failed. Please try again.', false);
			});
		});

		var cancelBtn = gid('kn-editpark-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', knCloseEditParkModal);
		var closeBtn = gid('kn-editpark-close-btn');
		if (closeBtn) closeBtn.addEventListener('click', knCloseEditParkModal);
		var overlay = gid('kn-editpark-overlay');
		if (overlay) {
			overlay.addEventListener('click', function(e) {
				if (e.target === this) knCloseEditParkModal();
			});
		}
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' && gid('kn-editpark-overlay') && gid('kn-editpark-overlay').classList.contains('kn-open'))
				knCloseEditParkModal();
		});
	});
})();

// ---- Edit Officers Modal ----
(function() {
	if (typeof KnConfig === 'undefined' || !KnConfig.canManage) return;

	var OFFICER_ROLES = ['Monarch', 'Regent', 'Prime Minister', 'Champion', 'GMR'];
	var SET_URL    = KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/setofficers';
	var VACATE_URL = KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/vacateofficer';
	var SEARCH_URL = KnConfig.httpService + 'Search/SearchService.php';

	function gid(id) { return document.getElementById(id); }
	function roleSlug(role) { return role.replace(/ /g, '_'); }

	function buildOfficerMap() {
		var map = {};
		(KnConfig.officerList || []).forEach(function(o) { map[o.OfficerRole] = o; });
		return map;
	}

	function showFeedback(msg, ok) {
		var el = gid('kn-editoff-feedback');
		if (!el) return;
		el.textContent = msg;
		el.className = 'kn-editoff-feedback ' + (ok ? 'kn-editoff-ok' : 'kn-editoff-err');
		el.style.display = '';
	}
	function hideFeedback() {
		var el = gid('kn-editoff-feedback');
		if (el) { el.style.display = 'none'; el.textContent = ''; }
	}

	// --- Open / Close ---
	window.knOpenEditOfficersModal = function() {
		var overlay = gid('kn-editoff-overlay');
		if (!overlay) return;
		buildRows();
		hideFeedback();
		overlay.classList.add('kn-open');
		document.body.style.overflow = 'hidden';
	};

	function knCloseEditOfficersModal() {
		var overlay = gid('kn-editoff-overlay');
		if (!overlay) return;
		overlay.classList.remove('kn-open');
		document.body.style.overflow = '';
	}

	// --- Build rows (once; refresh values on re-open) ---
	var rowsBuilt = false;
	function buildRows() {
		var officerMap = buildOfficerMap();
		if (rowsBuilt) {
			// Refresh current holder values without rebuilding DOM
			OFFICER_ROLES.forEach(function(role) {
				var slug    = roleSlug(role);
				var o       = officerMap[role];
				var nameEl  = gid('kn-editoff-name-' + slug);
				var idEl    = gid('kn-editoff-id-'   + slug);
				var vacBtn  = gid('kn-editoff-vacate-' + slug);
				if (nameEl && idEl) {
					nameEl.value = (o && o.MundaneId > 0) ? o.Persona   : '';
					idEl.value   = (o && o.MundaneId > 0) ? o.MundaneId : '';
				}
				if (vacBtn) vacBtn.style.display = (o && o.MundaneId > 0) ? '' : 'none';
			});
			return;
		}
		rowsBuilt = true;
		var container = gid('kn-editoff-rows');
		if (!container) return;
		container.innerHTML = '';

		OFFICER_ROLES.forEach(function(role) {
			var slug     = roleSlug(role);
			var o        = officerMap[role];
			var occupied = o && o.MundaneId > 0;

			var row = document.createElement('div');
			row.className = 'kn-editoff-row';

			// Role label
			var label = document.createElement('div');
			label.className = 'kn-editoff-role-label';
			label.textContent = role;
			row.appendChild(label);

			// Player wrap (autocomplete input + hidden id)
			var wrap = document.createElement('div');
			wrap.className = 'kn-editoff-player-wrap';

			var nameInput = document.createElement('input');
			nameInput.type          = 'text';
			nameInput.id            = 'kn-editoff-name-' + slug;
			nameInput.className     = 'kn-editoff-name-input';
			nameInput.autocomplete  = 'off';
			nameInput.placeholder   = 'Search players\u2026';
			if (occupied) nameInput.value = o.Persona;
			wrap.appendChild(nameInput);

			var hiddenInput = document.createElement('input');
			hiddenInput.type  = 'hidden';
			hiddenInput.id    = 'kn-editoff-id-' + slug;
			if (occupied) hiddenInput.value = o.MundaneId;
			wrap.appendChild(hiddenInput);
			row.appendChild(wrap);

			// Vacate button
			var vacateBtn = document.createElement('button');
			vacateBtn.type      = 'button';
			vacateBtn.id        = 'kn-editoff-vacate-' + slug;
			vacateBtn.className = 'kn-editoff-vacate-btn';
			vacateBtn.textContent       = 'Vacate';
			vacateBtn.style.display     = occupied ? '' : 'none';
			(function(r, btn, ni, hi) {
				btn.addEventListener('click', function() {
					if (!confirm('Remove the current ' + r + '?')) return;
					btn.disabled    = true;
					btn.textContent = '\u2026';
					$.post(VACATE_URL, { Role: r }, function(result) {
						if (result && result.status === 0) {
							ni.value = '';
							hi.value = '';
							btn.style.display = 'none';
							btn.disabled      = false;
							btn.textContent   = 'Vacate';
							(KnConfig.officerList || []).forEach(function(off) {
								if (off.OfficerRole === r) { off.MundaneId = 0; off.Persona = ''; }
							});
							showFeedback(r + ' vacated.', true);
						} else {
							btn.disabled    = false;
							btn.textContent = 'Vacate';
							showFeedback((result && result.error) ? result.error : 'Vacate failed.', false);
						}
					}, 'json').fail(function() {
						btn.disabled    = false;
						btn.textContent = 'Vacate';
						showFeedback('Request failed.', false);
					});
				});
			})(role, vacateBtn, nameInput, hiddenInput);
			row.appendChild(vacateBtn);

			container.appendChild(row);

			// Autocomplete (kingdom-scoped search)
			(function(ni, hi, vb) {
				$(ni).autocomplete({
					source: function(req, res) {
						$.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: req.term, kingdom_id: KnConfig.kingdomId, limit: 12 },
							function(data) {
								res($.map(data || [], function(v) { return { label: v.Persona, value: v.MundaneId }; }));
							}
						);
					},
					focus:  function(e, ui) { $(ni).val(ui.item.label); return false; },
					select: function(e, ui) {
						$(ni).val(ui.item.label);
						hi.value          = ui.item.value;
						vb.style.display  = '';
						return false;
					},
					change: function(e, ui) { if (!ui.item) hi.value = ''; return false; },
					delay: 250, minLength: 2,
				});
			})(nameInput, hiddenInput, vacateBtn);
		});
	}

	// --- Event listeners (in ready() to guard against null elements) ---
	$(document).ready(function() {
		var submitBtn = gid('kn-editoff-submit');
		if (submitBtn) {
			submitBtn.addEventListener('click', function() {
				var data   = {};
				var hasAny = false;
				OFFICER_ROLES.forEach(function(role) {
					var idEl = gid('kn-editoff-id-' + roleSlug(role));
					var mid  = idEl ? parseInt(idEl.value, 10) : 0;
					if (mid > 0) { data[roleSlug(role) + 'Id'] = mid; hasAny = true; }
				});
				if (!hasAny) { showFeedback('No officers selected. Use Vacate to remove officers.', false); return; }
				submitBtn.disabled = true;
				$.post(SET_URL, data, function(result) {
					submitBtn.disabled = false;
					if (result && result.status === 0) {
						showFeedback('Officers saved!', true);
						setTimeout(function() { location.reload(); }, 900);
					} else {
						showFeedback((result && result.error) ? result.error : 'Save failed.', false);
					}
				}, 'json').fail(function() {
					submitBtn.disabled = false;
					showFeedback('Request failed.', false);
				});
			});
		}

		var cancelBtn = gid('kn-editoff-cancel');
		if (cancelBtn) cancelBtn.addEventListener('click', knCloseEditOfficersModal);

		var closeBtn = gid('kn-editoff-close-btn');
		if (closeBtn) closeBtn.addEventListener('click', knCloseEditOfficersModal);

		var overlay = gid('kn-editoff-overlay');
		if (overlay) {
			overlay.addEventListener('click', function(e) {
				if (e.target === this) knCloseEditOfficersModal();
			});
		}

		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' && gid('kn-editoff-overlay') && gid('kn-editoff-overlay').classList.contains('kn-open'))
				knCloseEditOfficersModal();
		});
	});
})();

// ---- Kingdom Admin Overlay ----
(function() {
	if (typeof KnConfig === 'undefined' || !KnConfig.canManage) return;

	var BASE_URL = KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/';

	function gid(id) { return document.getElementById(id); }

	// ── Feedback helpers ──────────────────────────────────────
	function feedback(elId, msg, ok) {
		var el = gid(elId);
		if (!el) return;
		el.textContent = msg;
		el.className = 'kn-admin-feedback ' + (ok ? 'kn-admin-ok' : 'kn-admin-err');
		el.style.display = '';
	}
	function clearFeedback(elId) {
		var el = gid(elId);
		if (el) { el.style.display = 'none'; el.textContent = ''; }
	}

	// ── Open / Close ──────────────────────────────────────────
	window.knOpenAdminModal = function() {
		var overlay = gid('kn-admin-overlay');
		if (!overlay) return;
		buildConfig();
		buildTitles();
		buildAwards();
		overlay.classList.add('kn-open');
		document.body.style.overflow = 'hidden';
	};
	function knCloseAdminModal() {
		var overlay = gid('kn-admin-overlay');
		if (!overlay) return;
		overlay.classList.remove('kn-open');
		document.body.style.overflow = '';
	}

	// ── Panel toggle ─────────────────────────────────────────
	function wireToggle(hdrId, bodyId, chevId) {
		var hdr  = gid(hdrId);
		var body = gid(bodyId);
		var chev = gid(chevId);
		if (!hdr || !body) return;
		hdr.addEventListener('click', function() {
			var open = body.style.display !== 'none';
			body.style.display = open ? 'none' : '';
			if (chev) chev.classList.toggle('kn-admin-chevron-open', !open);
			hdr.setAttribute('aria-expanded', String(!open));
		});
	}

	// ── Section: Details ─────────────────────────────────────
	function wireDetails() {
		var btn = gid('kn-admin-details-save');
		if (!btn) return;
		btn.addEventListener('click', function() {
			clearFeedback('kn-admin-details-feedback');
			var name = (gid('kn-admin-name').value || '').trim();
			var abbr = (gid('kn-admin-abbr').value || '').replace(/[^A-Za-z0-9]/g, '');
			if (!name) { feedback('kn-admin-details-feedback', 'Kingdom name is required.', false); return; }
			if (!abbr) { feedback('kn-admin-details-feedback', 'Abbreviation is required.', false); return; }

			var fd = new FormData();
			fd.append('Name',         name);
			fd.append('Abbreviation', abbr);
			var fileEl = gid('kn-admin-heraldry');
			if (fileEl && fileEl.files[0]) fd.append('Heraldry', fileEl.files[0]);

			btn.disabled = true;
			$.ajax({
				url: BASE_URL + 'setdetails',
				type: 'POST',
				data: fd,
				processData: false,
				contentType: false,
				dataType: 'json',
				success: function(r) {
					btn.disabled = false;
					if (r && r.status === 0) {
						feedback('kn-admin-details-feedback', 'Details saved!', true);
						if (fileEl) fileEl.value = '';
					} else {
						feedback('kn-admin-details-feedback', (r && r.error) ? r.error : 'Save failed.', false);
					}
				},
				error: function() { btn.disabled = false; feedback('kn-admin-details-feedback', 'Request failed.', false); }
			});
		});
	}

	// ── Section: Configuration ───────────────────────────────
	var configBuilt = false;
	function buildConfig() {
		if (configBuilt) return;
		configBuilt = true;
		var container = gid('kn-admin-config-rows');
		if (!container) return;
		container.innerHTML = '';
		(KnConfig.adminConfig || []).forEach(function(cfg) {
			var row = document.createElement('div');
			row.className = 'kn-admin-config-row';

			var lbl = document.createElement('div');
			lbl.className   = 'kn-admin-config-label';
			lbl.textContent = cfg.Key;
			row.appendChild(lbl);

			var inputs = document.createElement('div');
			inputs.className = 'kn-admin-config-inputs';

			var val = cfg.Value;
			if (val !== null && typeof val === 'object' && !Array.isArray(val)) {
				// Object config (e.g. AveragePeriod: {Type, Period})
				Object.keys(val).forEach(function(subKey) {
					var sub = document.createElement('span');
					sub.className   = 'kn-admin-config-sublabel';
					sub.textContent = subKey + ':';
					inputs.appendChild(sub);

					var inp;
					var allowed = cfg.AllowedValues && cfg.AllowedValues[subKey];
					if (allowed && Array.isArray(allowed)) {
						inp = document.createElement('select');
						inp.className = 'kn-admin-config-input kn-admin-tselect';
						allowed.forEach(function(opt) {
							var o = document.createElement('option');
							o.value = opt; o.textContent = opt;
							if (opt == val[subKey]) o.selected = true;
							inp.appendChild(o);
						});
					} else {
						inp = document.createElement('input');
						inp.type      = (typeof val[subKey] === 'number') ? 'number' : 'text';
						inp.className = 'kn-admin-config-input';
						inp.value     = val[subKey];
						inp.style.width = '70px';
					}
					inp.dataset.configId  = cfg.ConfigurationId;
					inp.dataset.configSub = subKey;
					inputs.appendChild(inp);
				});
			} else {
				var inp = document.createElement('input');
				inp.type  = (cfg.Type === 'color')  ? 'color'
				          : (cfg.Type === 'number') ? 'number' : 'text';
				inp.className        = 'kn-admin-config-input';
				inp.value            = (val !== null && val !== undefined) ? val : '';
				inp.dataset.configId = cfg.ConfigurationId;
				if (cfg.Type === 'number') inp.style.width = '80px';
				inputs.appendChild(inp);
			}
			row.appendChild(inputs);
			container.appendChild(row);
		});
	}

	function wireConfig() {
		var btn = gid('kn-admin-config-save');
		if (!btn) return;
		btn.addEventListener('click', function() {
			clearFeedback('kn-admin-config-feedback');
			var data = {};
			document.querySelectorAll('#kn-admin-config-rows .kn-admin-config-input').forEach(function(inp) {
				var cid = inp.dataset.configId;
				var sub = inp.dataset.configSub;
				if (!cid) return;
				var key = sub ? ('Config[' + cid + '][' + sub + ']') : ('Config[' + cid + ']');
				data[key] = inp.value;
			});
			if (!Object.keys(data).length) { feedback('kn-admin-config-feedback', 'No configuration fields found.', false); return; }
			btn.disabled = true;
			$.post(BASE_URL + 'setconfig', data, function(r) {
				btn.disabled = false;
				if (r && r.status === 0) feedback('kn-admin-config-feedback', 'Configuration saved!', true);
				else feedback('kn-admin-config-feedback', (r && r.error) ? r.error : 'Save failed.', false);
			}, 'json').fail(function() { btn.disabled = false; feedback('kn-admin-config-feedback', 'Request failed.', false); });
		});
	}

	// ── Section: Park Titles ─────────────────────────────────
	var titlesBuilt = false;
	function buildTitles() {
		if (titlesBuilt) return;
		titlesBuilt = true;
		var tbody = gid('kn-admin-titles-tbody');
		if (!tbody) return;
		tbody.innerHTML = '';
		(KnConfig.adminParkTitles || []).forEach(function(pt) {
			tbody.appendChild(makeTitleRow(pt));
		});
	}

	function makeTitleRow(pt) {
		var tr = document.createElement('tr');
		tr.dataset.titleId = pt.ParkTitleId;

		function makeCell(type, field, val) {
			var td  = document.createElement('td');
			var inp = document.createElement('input');
			inp.type      = type;
			inp.className = (type === 'number') ? 'kn-admin-tnumeric' : 'kn-admin-tinput';
			inp.value     = val;
			if (type === 'number') inp.min = '0';
			inp.dataset.field = field;
			td.appendChild(inp);
			return td;
		}

		var periodTd = document.createElement('td');
		var sel = document.createElement('select');
		sel.className = 'kn-admin-tselect';
		sel.dataset.field = 'Period';
		['month','week'].forEach(function(v) {
			var o = document.createElement('option');
			o.value = v; o.textContent = v.charAt(0).toUpperCase() + v.slice(1);
			if (v === pt.Period) o.selected = true;
			sel.appendChild(o);
		});
		periodTd.appendChild(sel);

		var delTd  = document.createElement('td');
		var delBtn = document.createElement('button');
		delBtn.className   = 'kn-admin-tdel';
		delBtn.textContent = 'Delete';
		(function(row, titleName, titleId) {
			delBtn.addEventListener('click', function() {
				if (!confirm('Delete "' + titleName + '"? Parks using this title must be reassigned first.')) return;
				delBtn.disabled = true;
				$.post(BASE_URL + 'deletetitle', { ParkTitleId: titleId }, function(r) {
					if (r && r.status === 0) {
						row.parentNode && row.parentNode.removeChild(row);
						feedback('kn-admin-titles-feedback', 'Title deleted.', true);
					} else {
						delBtn.disabled = false;
						feedback('kn-admin-titles-feedback', (r && r.error) ? r.error : 'Delete failed.', false);
					}
				}, 'json').fail(function() { delBtn.disabled = false; feedback('kn-admin-titles-feedback', 'Request failed.', false); });
			});
		})(tr, pt.Title, pt.ParkTitleId);
		delTd.appendChild(delBtn);

		tr.appendChild(makeCell('text',   'Title',             pt.Title));
		tr.appendChild(makeCell('number', 'Class',             pt.Class));
		tr.appendChild(makeCell('number', 'MinimumAttendance', pt.MinimumAttendance));
		tr.appendChild(makeCell('number', 'MinimumCutoff',     pt.MinimumCutoff));
		tr.appendChild(periodTd);
		tr.appendChild(makeCell('number', 'Length',            pt.Length));
		tr.appendChild(delTd);
		return tr;
	}

	function wireTitles() {
		var btn = gid('kn-admin-titles-save');
		if (!btn) return;
		btn.addEventListener('click', function() {
			clearFeedback('kn-admin-titles-feedback');
			var data = {};

			document.querySelectorAll('#kn-admin-titles-tbody tr').forEach(function(row) {
				var id = row.dataset.titleId;
				row.querySelectorAll('[data-field]').forEach(function(inp) {
					data[inp.dataset.field + '[' + id + ']'] = inp.value;
				});
			});

			var newTitle = document.querySelector('#kn-admin-titles-table tfoot [data-field="Title"]');
			if (newTitle && newTitle.value.trim()) {
				document.querySelectorAll('#kn-admin-titles-table tfoot [data-field]').forEach(function(inp) {
					data[inp.dataset.field + '[New]'] = inp.value;
				});
			}

			if (!Object.keys(data).length) { feedback('kn-admin-titles-feedback', 'No data to save.', false); return; }
			btn.disabled = true;
			$.post(BASE_URL + 'setparktitles', data, function(r) {
				btn.disabled = false;
				if (r && r.status === 0) {
					feedback('kn-admin-titles-feedback', 'Park titles saved!', true);
					document.querySelectorAll('#kn-admin-titles-table tfoot [data-field]').forEach(function(inp) {
						inp.value = (inp.dataset.field === 'Length') ? '1' : (inp.type === 'number' ? '0' : '');
					});
					setTimeout(function() { location.reload(); }, 1000);
				} else {
					feedback('kn-admin-titles-feedback', (r && r.error) ? r.error : 'Save failed.', false);
				}
			}, 'json').fail(function() { btn.disabled = false; feedback('kn-admin-titles-feedback', 'Request failed.', false); });
		});
	}

	// ── Section: Awards ──────────────────────────────────────
	var awardsBuilt = false;
	function buildAwards() {
		if (awardsBuilt) return;
		awardsBuilt = true;
		var tbody = gid('kn-admin-awards-tbody');
		if (!tbody) return;
		tbody.innerHTML = '';
		(KnConfig.adminAwards || []).forEach(function(aw) {
			tbody.appendChild(makeAwardRow(aw));
		});
	}

	function makeAwardRow(aw) {
		var tr = document.createElement('tr');

		function ntd(isText, val) {
			var td  = document.createElement('td');
			var inp = document.createElement('input');
			inp.type      = isText ? 'text' : 'number';
			inp.className = isText ? 'kn-admin-tinput' : 'kn-admin-tnumeric';
			inp.value     = val;
			if (!isText) inp.min = '0';
			td.appendChild(inp);
			return { td: td, inp: inp };
		}

		var nameCell  = ntd(true,  aw.KingdomAwardName);
		var reignCell = ntd(false, aw.ReignLimit);
		var monthCell = ntd(false, aw.MonthLimit);

		var titleTd = document.createElement('td');
		titleTd.style.textAlign = 'center';
		var titleCb = document.createElement('input');
		titleCb.type    = 'checkbox';
		titleCb.checked = (aw.IsTitle === 1);
		titleTd.appendChild(titleCb);

		var classCell = ntd(false, aw.TitleClass);
		classCell.inp.disabled = !titleCb.checked;
		titleCb.addEventListener('change', function() { classCell.inp.disabled = !this.checked; });

		var actionsTd = document.createElement('td');
		actionsTd.style.whiteSpace = 'nowrap';

		var saveBtn = document.createElement('button');
		saveBtn.className   = 'kn-admin-tsave';
		saveBtn.textContent = 'Save';
		saveBtn.style.marginRight = '4px';
		(function(btn, nc, rc, mc, cb, cc, kawId) {
			btn.addEventListener('click', function() {
				clearFeedback('kn-admin-awards-feedback');
				btn.disabled = true;
				$.post(BASE_URL + 'setaward', {
					KingdomAwardId:   kawId,
					KingdomAwardName: nc.value.trim(),
					ReignLimit:       rc.value,
					MonthLimit:       mc.value,
					IsTitle:          cb.checked ? 1 : 0,
					TitleClass:       cc.value,
				}, function(r) {
					btn.disabled = false;
					if (r && r.status === 0) feedback('kn-admin-awards-feedback', 'Award saved!', true);
					else feedback('kn-admin-awards-feedback', (r && r.error) ? r.error : 'Save failed.', false);
				}, 'json').fail(function() { btn.disabled = false; feedback('kn-admin-awards-feedback', 'Request failed.', false); });
			});
		})(saveBtn, nameCell.inp, reignCell.inp, monthCell.inp, titleCb, classCell.inp, aw.KingdomAwardId);

		var delBtn = document.createElement('button');
		delBtn.className   = 'kn-admin-tdel';
		delBtn.textContent = 'Delete';
		(function(btn, row, kawId, awName) {
			btn.addEventListener('click', function() {
				if (!confirm('Delete award "' + awName + '"? This cannot be undone.')) return;
				btn.disabled = true;
				$.post(BASE_URL + 'deleteaward', { KingdomAwardId: kawId }, function(r) {
					if (r && r.status === 0) {
						row.parentNode && row.parentNode.removeChild(row);
						feedback('kn-admin-awards-feedback', 'Award deleted.', true);
					} else {
						btn.disabled = false;
						feedback('kn-admin-awards-feedback', (r && r.error) ? r.error : 'Delete failed.', false);
					}
				}, 'json').fail(function() { btn.disabled = false; feedback('kn-admin-awards-feedback', 'Request failed.', false); });
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

	function wireAwards() {
		var addBtn    = gid('kn-admin-awards-add-btn');
		var addWrap   = gid('kn-admin-add-award-wrap');
		var cancelBtn = gid('kn-admin-new-award-cancel');

		if (addBtn && addWrap) {
			addBtn.addEventListener('click', function() {
				addWrap.style.display = '';
				addBtn.style.display  = 'none';
			});
		}
		if (cancelBtn && addWrap && addBtn) {
			cancelBtn.addEventListener('click', function() {
				addWrap.style.display = 'none';
				addBtn.style.display  = '';
			});
		}

		var newIsTitleCb = gid('kn-admin-new-istitle');
		var newTClassInp = gid('kn-admin-new-tclass');
		if (newIsTitleCb && newTClassInp) {
			newIsTitleCb.addEventListener('change', function() {
				newTClassInp.disabled = !this.checked;
			});
		}

		var saveNewBtn = gid('kn-admin-new-award-save');
		if (saveNewBtn) {
			saveNewBtn.addEventListener('click', function() {
				clearFeedback('kn-admin-awards-feedback');
				var awardId = parseInt((gid('kn-admin-new-award-id').value || '0'), 10);
				var name    = (gid('kn-admin-new-award-name').value || '').trim();
				var reign   = gid('kn-admin-new-reign').value;
				var month   = gid('kn-admin-new-month').value;
				var isTitle = gid('kn-admin-new-istitle').checked ? 1 : 0;
				var tClass  = gid('kn-admin-new-tclass').value;

				if (!awardId) { feedback('kn-admin-awards-feedback', 'Canonical Award ID is required.', false); return; }
				if (!name)    { feedback('kn-admin-awards-feedback', 'Award name is required.', false); return; }

				saveNewBtn.disabled = true;
				$.post(BASE_URL + 'setaward', {
					KingdomAwardId:   0,
					AwardId:          awardId,
					KingdomAwardName: name,
					ReignLimit:       reign,
					MonthLimit:       month,
					IsTitle:          isTitle,
					TitleClass:       tClass,
				}, function(r) {
					saveNewBtn.disabled = false;
					if (r && r.status === 0) {
						feedback('kn-admin-awards-feedback', 'Award created!', true);
						setTimeout(function() { location.reload(); }, 900);
					} else {
						feedback('kn-admin-awards-feedback', (r && r.error) ? r.error : 'Create failed.', false);
					}
				}, 'json').fail(function() { saveNewBtn.disabled = false; feedback('kn-admin-awards-feedback', 'Request failed.', false); });
			});
		}
	}

	// ── Wire everything in ready() ────────────────────────────
	$(document).ready(function() {
		wireToggle('kn-admin-hdr-details', 'kn-admin-body-details', 'kn-admin-chev-details');
		wireToggle('kn-admin-hdr-config',  'kn-admin-body-config',  'kn-admin-chev-config');
		wireToggle('kn-admin-hdr-titles',  'kn-admin-body-titles',  'kn-admin-chev-titles');
		wireToggle('kn-admin-hdr-awards',  'kn-admin-body-awards',  'kn-admin-chev-awards');

		wireDetails();
		wireConfig();
		wireTitles();
		wireAwards();

		var closeBtn = gid('kn-admin-close-btn');
		if (closeBtn) closeBtn.addEventListener('click', knCloseAdminModal);

		var doneBtn = gid('kn-admin-done-btn');
		if (doneBtn) doneBtn.addEventListener('click', knCloseAdminModal);

		var overlay = gid('kn-admin-overlay');
		if (overlay) {
			overlay.addEventListener('click', function(e) {
				if (e.target === this) knCloseAdminModal();
			});
		}

		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' && gid('kn-admin-overlay') && gid('kn-admin-overlay').classList.contains('kn-open'))
				knCloseAdminModal();
		});
	});
})();

/* ===========================
   Park Profile (PkConfig)
   =========================== */

// ---- Events calendar data (server-rendered) ----
var pkCalEvents;
if (typeof PkConfig !== 'undefined') { pkCalEvents = PkConfig.calEvents; }
var pkCalLoaded = false;
var pkCalendar  = null;

function pkSetEventsView(view) {
	if (view === 'calendar') {
		$('#pk-events-list-view').hide();
		$('#pk-events-cal').show();
		$('#pk-ev-view-cal').addClass('pk-view-active');
		$('#pk-ev-view-list').removeClass('pk-view-active');
		pkInitCalendar();
	} else {
		$('#pk-events-cal').hide();
		$('#pk-events-list-view').show();
		$('#pk-ev-view-list').addClass('pk-view-active');
		$('#pk-ev-view-cal').removeClass('pk-view-active');
	}
	try { localStorage.setItem('pk_events_view', view); } catch(e) {}
}

function pkInitCalendar() {
	if (pkCalendar) {
		pkCalendar.updateSize();
		return;
	}
	if (pkCalLoaded) return;
	pkCalLoaded = true;

	var link = document.createElement('link');
	link.rel = 'stylesheet';
	link.href = 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css';
	document.head.appendChild(link);

	var script = document.createElement('script');
	script.src = 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js';
	script.onload = function() { pkRenderCalendar(); };
	document.head.appendChild(script);
}

function pkRenderCalendar() {
	var el = document.getElementById('pk-events-cal');
	if (!el || typeof FullCalendar === 'undefined') return;

	pkCalendar = new FullCalendar.Calendar(el, {
		initialView: 'dayGridMonth',
		headerToolbar: {
			left:   'prev,next today',
			center: 'title',
			right:  'dayGridMonth,listMonth'
		},
		height: 'auto',
		events: pkCalEvents.map(function(e) {
			return { title: e.title, start: e.start, url: e.url, color: e.color };
		}),
		eventClick: function(info) {
			info.jsEvent.preventDefault();
			if (info.event.url) window.location.href = info.event.url;
		}
	});
	pkCalendar.render();
}

// ---- Player avatar image fallback ----
// Called onerror on player images; hides the img and shows the initial letter instead.
function pkAvatarFallback(img, initial) {
	img.style.display = 'none';
	img.parentElement.innerText = initial;
}

// ---- Load More: card view (reveals next hidden period block) ----
// group: prefix string like 'pk-players' or 'pk-hoa'
// btn:   the button element inside .pk-load-more-wrap[data-next][data-group]
function pkLoadMoreCards(group, btn) {
	var $wrap = $(btn).closest('.pk-load-more-wrap');
	var next  = parseInt($wrap.attr('data-next') || '1');
	var $block = $('#' + group + '-block-' + next);
	if ($block.length) {
		$block.show();
		var newNext = next + 1;
		$wrap.attr('data-next', newNext);
		if (!$('#' + group + '-block-' + newNext).length) {
			$wrap.hide(); // no more periods to load
		}
	} else {
		$wrap.hide();
	}
}

// ---- Load More: list view (appends template rows to table, re-paginates) ----
// tableId:  id of the <table> element
// tmplBase: id prefix of <template> elements (e.g. 'pk-players-tmpl')
// btn:      the button element inside .pk-load-more-wrap[data-next]
function pkLoadMoreList(tableId, tmplBase, btn) {
	var $wrap  = $(btn).closest('.pk-load-more-wrap');
	var next   = parseInt($wrap.attr('data-next') || '1');
	var tmpl   = document.getElementById(tmplBase + '-' + next);
	if (tmpl) {
		var $tbody = $('#' + tableId + ' tbody');
		// Clone template content and append to tbody
		var frag = document.importNode(tmpl.content, true);
		$(frag.querySelectorAll('tr')).appendTo($tbody);
		var newNext = next + 1;
		$wrap.attr('data-next', newNext);
		// Re-paginate from page 1 with the expanded row set
		pkPaginate($('#' + tableId), 1);
		if (!document.getElementById(tmplBase + '-' + newNext)) {
			$wrap.hide();
		}
	} else {
		$wrap.hide();
	}
}

// ---- Hero color from heraldry ----
// Samples the heraldry image via Canvas to find its dominant non-white, non-black
// color and applies a darkened version as the hero background.
function pkApplyHeroColor(img) {
	var canvas = document.createElement('canvas');
	canvas.width = 60; canvas.height = 60;
	var ctx = canvas.getContext('2d');
	try {
		ctx.drawImage(img, 0, 0, 60, 60);
		var px = ctx.getImageData(0, 0, 60, 60).data;
		var buckets = {};
		for (var i = 0; i < px.length; i += 4) {
			var r = px[i], g = px[i+1], b = px[i+2], a = px[i+3];
			if (a < 120) continue;
			if (r > 215 && g > 215 && b > 215) continue;
			if (r < 25  && g < 25  && b < 25)  continue;
			var key = (r >> 4) + ',' + (g >> 4) + ',' + (b >> 4);
			buckets[key] = (buckets[key] || 0) + 1;
		}
		var best = null, bestN = 0;
		for (var k in buckets) { if (buckets[k] > bestN) { bestN = buckets[k]; best = k; } }
		if (!best) return;
		var parts = best.split(',');
		var dr = parseInt(parts[0]) * 16 + 8;
		var dg = parseInt(parts[1]) * 16 + 8;
		var db = parseInt(parts[2]) * 16 + 8;
		var rf = dr/255, gf = dg/255, bf = db/255;
		var max = Math.max(rf,gf,bf), min = Math.min(rf,gf,bf);
		var h = 0, s = 0, l = (max+min)/2;
		if (max !== min) {
			var d = max - min;
			s = l > 0.5 ? d/(2-max-min) : d/(max+min);
			if      (max === rf) h = (gf-bf)/d + (gf < bf ? 6 : 0);
			else if (max === gf) h = (bf-rf)/d + 2;
			else                 h = (rf-gf)/d + 4;
			h /= 6;
		}
		var finalS = Math.max(s, 0.28);
		var heroEl = document.querySelector('.pk-hero');
		if (heroEl) {
			heroEl.style.backgroundColor =
				'hsl(' + Math.round(h*360) + ',' + Math.round(finalS*100) + '%,18%)';
		}
	} catch(e) { /* CORS or tainted canvas — keep default green */ }
}

// ---- Tab activation ----
function pkActivateTab(tab) {
	$('.pk-tab-nav li').removeClass('pk-tab-active');
	$('.pk-tab-nav li[data-pktab="' + tab + '"]').addClass('pk-tab-active');
	$('.pk-tab-panel').hide();
	$('#pk-tab-' + tab).show();
	if (tab === 'events' && pkCalendar) {
		pkCalendar.updateSize();
	}
	$('html, body').animate({ scrollTop: $('.pk-tabs').offset().top - 20 }, 250);
}

// ---- Pagination helpers ----
function pkPageRange(current, total) {
	var pages = [];
	if (total <= 7) {
		for (var p = 1; p <= total; p++) pages.push(p);
	} else {
		pages.push(1);
		if (current > 3) pages.push(-1);
		for (var p = Math.max(2, current-1); p <= Math.min(total-1, current+1); p++) pages.push(p);
		if (current < total - 2) pages.push(-1);
		pages.push(total);
	}
	return pages;
}

function pkRenderPagination($table, current, total, containerId) {
	var $c = $('#' + containerId);
	$c.empty();
	if (total <= 1) return;
	var pages = pkPageRange(current, total);
	var prevDisabled = current === 1 ? ' pk-page-disabled' : '';
	$c.append('<span class="pk-page-btn pk-page-prev' + prevDisabled + '">&#8249;</span>');
	for (var i = 0; i < pages.length; i++) {
		if (pages[i] === -1) {
			$c.append('<span class="pk-page-ellipsis">&hellip;</span>');
		} else {
			var active = pages[i] === current ? ' pk-page-active' : '';
			$c.append('<span class="pk-page-btn pk-page-num' + active + '" data-page="' + pages[i] + '">' + pages[i] + '</span>');
		}
	}
	var nextDisabled = current === total ? ' pk-page-disabled' : '';
	$c.append('<span class="pk-page-btn pk-page-next' + nextDisabled + '">&#8250;</span>');
}

function pkPaginate($table, page) {
	var perPage = 10;
	var $rows = $table.find('tbody tr');
	var total = Math.ceil($rows.length / perPage);
	if (total <= 1) return;
	$rows.hide();
	$rows.slice((page-1)*perPage, page*perPage).show();
	var containerId = $table.attr('id') + '-pages';
	pkRenderPagination($table, page, total, containerId);
	$table.data('pk-page', page);
	$table.data('pk-total', total);
}

// ---- Sort helpers ----
function pkSortDesc($table, colIdx, type) {
	var $tbody = $table.find('tbody');
	var rows = $tbody.find('tr').toArray();
	rows.sort(function(a, b) {
		var aVal = $(a).find('td').eq(colIdx).data('sortval') || $(a).find('td').eq(colIdx).text().trim();
		var bVal = $(b).find('td').eq(colIdx).data('sortval') || $(b).find('td').eq(colIdx).text().trim();
		if (type === 'date') {
			return new Date(bVal) - new Date(aVal);
		} else if (type === 'numeric') {
			return parseFloat(bVal) - parseFloat(aVal);
		} else {
			return bVal.localeCompare(aVal);
		}
	});
	$.each(rows, function(i, row) { $tbody.append(row); });
}

function pkSortTable($table, colIdx, type, dir) {
	var $tbody = $table.find('tbody');
	var rows = $tbody.find('tr').toArray();
	rows.sort(function(a, b) {
		var aVal = $(a).find('td').eq(colIdx).data('sortval') || $(a).find('td').eq(colIdx).text().trim();
		var bVal = $(b).find('td').eq(colIdx).data('sortval') || $(b).find('td').eq(colIdx).text().trim();
		var cmp;
		if (type === 'date') {
			cmp = new Date(aVal) - new Date(bVal);
		} else if (type === 'numeric') {
			cmp = parseFloat(aVal) - parseFloat(bVal);
		} else {
			cmp = aVal.localeCompare(bVal);
		}
		return dir === 'desc' ? -cmp : cmp;
	});
	$.each(rows, function(i, row) { $tbody.append(row); });
}

$(document).ready(function() {
	if (typeof PkConfig === 'undefined') return;

	// ---- Hero color from heraldry ----
	var pkHeraldryImg = document.querySelector('.pk-heraldry-frame img');
	if (pkHeraldryImg) {
		if (pkHeraldryImg.complete && pkHeraldryImg.naturalWidth) {
			pkApplyHeroColor(pkHeraldryImg);
		} else {
			pkHeraldryImg.addEventListener('load', function() { pkApplyHeroColor(this); });
		}
	}

	// ---- Tab switching ----
	$('.pk-tab-nav li').on('click', function() {
		pkActivateTab($(this).attr('data-pktab'));
	});

	// ---- Events view toggle (list / calendar) ----
	$('#pk-ev-view-list').on('click', function() { pkSetEventsView('list'); });
	$('#pk-ev-view-cal').on('click',  function() { pkSetEventsView('calendar'); });
	try {
		pkSetEventsView(localStorage.getItem('pk_events_view') || 'list');
	} catch(e) {
		pkSetEventsView('list');
	}

	// ---- Sortable table headers ----
	$('.pk-table thead th').on('click', function() {
		var $th = $(this);
		var $table = $th.closest('table');
		var colIdx = $th.index();
		var type = $th.attr('data-sorttype') || 'text';
		var curDir = $th.data('sortdir') || 'none';
		var newDir = (curDir === 'asc') ? 'desc' : 'asc';
		$table.find('thead th').removeClass('pk-sort-asc pk-sort-desc').removeData('sortdir');
		$th.addClass('pk-sort-' + newDir).data('sortdir', newDir);
		pkSortTable($table, colIdx, type, newDir);
		var page = $table.data('pk-page') || 1;
		pkPaginate($table, 1);
	});

	// ---- Pagination click handlers ----
	$(document).on('click', '.pk-page-num', function() {
		var page = parseInt($(this).data('page'));
		var $table = $(this).closest('.pk-tab-panel').find('.pk-table');
		pkPaginate($table, page);
	});
	$(document).on('click', '.pk-page-prev:not(.pk-page-disabled)', function() {
		var $table = $(this).closest('.pk-tab-panel').find('.pk-table');
		var page = Math.max(1, ($table.data('pk-page') || 1) - 1);
		pkPaginate($table, page);
	});
	$(document).on('click', '.pk-page-next:not(.pk-page-disabled)', function() {
		var $table = $(this).closest('.pk-tab-panel').find('.pk-table');
		var total = $table.data('pk-total') || 1;
		var page = Math.min(total, ($table.data('pk-page') || 1) + 1);
		pkPaginate($table, page);
	});

	// ---- Player search (filters all .pk-hoa-card across all periods) ----
	$('#pk-player-search').on('input', function() {
		var q = $(this).val().trim().toLowerCase();
		if (q === '') {
			// Restore: show all sections and cards
			$('.pk-hoa-section').removeClass('pk-search-hidden');
			$('.pk-hoa-card').show();
		} else {
			$('.pk-hoa-card').each(function() {
				var name = $(this).find('.pk-hoa-name').text().toLowerCase();
				$(this).toggle(name.indexOf(q) !== -1);
			});
			// Hide period section headings that have no visible cards
			$('.pk-hoa-section').each(function() {
				var hasVisible = $(this).find('.pk-hoa-card:visible').length > 0;
				$(this).toggleClass('pk-search-hidden', !hasVisible);
			});
		}
	});

	// ---- Players view toggle (cards / list) ----
	$('[data-pkview]').on('click', function() {
		var view = $(this).attr('data-pkview');
		$('[data-pkview]').removeClass('pk-view-active');
		$(this).addClass('pk-view-active');
		if (view === 'list') {
			$('#pk-players-cards').hide();
			$('#pk-players-list').show();
		} else {
			$('#pk-players-list').hide();
			$('#pk-players-cards').show();
		}
	});

	// ---- Default sort + paginate ----

	pkSortDesc($('#pk-events-table'), 1, 'date');
	pkPaginate($('#pk-events-table'), 1);

	pkSortDesc($('#pk-tournaments-table'), 2, 'date');
	pkPaginate($('#pk-tournaments-table'), 1);

	pkPaginate($('#pk-players-table'), 1);

});
(function() {
	if (!document.getElementById('pk-award-overlay')) return;
	var UIR_JS = PkConfig.uir;
	var SEARCH_URL = PkConfig.httpService + 'Search/SearchService.php';
	var KINGDOM_ID = PkConfig.kingdomId;
	var PARK_ID = PkConfig.parkId;
	var awardOptHTML = PkConfig.awardOptHTML;
	var officerOptHTML = PkConfig.officerOptHTML;
	var currentType = 'awards';
	var givenByTimer, givenAtTimer, playerTimer;

	function gid(id) { return document.getElementById(id); }

	function checkRequired() {
		var ok = !!gid('pk-award-player-id').value
		      && !!gid('pk-award-select').value
		      && !!gid('pk-award-givenby-id').value
		      && !!gid('pk-award-date').value;
		gid('pk-award-save-new').disabled  = !ok;
		gid('pk-award-save-same').disabled = !ok;
	}

	function setAwardType(type) {
		currentType = type;
		var isOfficer = type === 'officers';
		gid('pk-award-modal-title').innerHTML = isOfficer
			? '<i class="fas fa-crown" style="margin-right:8px;color:#553c9a"></i>Add Officer Title'
			: '<i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award';
		gid('pk-award-select').innerHTML = isOfficer ? officerOptHTML : awardOptHTML;
		gid('pk-award-rank-row').style.display   = 'none';
		gid('pk-award-rank-val').value           = '';
		gid('pk-award-info-line').innerHTML      = '';
		gid('pk-award-type-awards').classList.toggle('pk-active', !isOfficer);
		gid('pk-award-type-officers').classList.toggle('pk-active', isOfficer);
		checkRequired();
	}

	gid('pk-award-type-awards').addEventListener('click',   function() { setAwardType('awards'); });
	gid('pk-award-type-officers').addEventListener('click', function() { setAwardType('officers'); });

	function buildRankPills(awardId) {
		var row   = gid('pk-award-rank-row');
		var wrap  = gid('pk-rank-pills');
		var input = gid('pk-award-rank-val');
		wrap.innerHTML = '';
		input.value = '';
		row.style.display = 'none';
		if (!awardId) return;
		var opt = gid('pk-award-select').querySelector('option[value="' + awardId + '"]');
		if (!opt || opt.getAttribute('data-is-ladder') !== '1') return;
		row.style.display = '';
		for (var r = 1; r <= 10; r++) {
			var pill = document.createElement('button');
			pill.type      = 'button';
			pill.className = 'pk-rank-pill';
			pill.textContent = r;
			pill.dataset.rank = r;
			pill.addEventListener('click', (function(rank, el) {
				return function() {
					document.querySelectorAll('#pk-rank-pills .pk-rank-pill').forEach(function(p) { p.classList.remove('pk-rank-selected'); });
					el.classList.add('pk-rank-selected');
					input.value = rank;
				};
			})(r, pill));
			wrap.appendChild(pill);
		}
	}

	gid('pk-award-select').addEventListener('change', function() {
		var awardId = this.value;
		var isCustom = this.options[this.selectedIndex] && this.options[this.selectedIndex].text.toLowerCase().indexOf('custom') >= 0;
		gid('pk-award-custom-row').style.display = isCustom ? '' : 'none';
		buildRankPills(awardId);
		var infoEl = gid('pk-award-info-line');
		if (awardId) {
			var opt = this.querySelector('option[value="' + awardId + '"]');
			infoEl.innerHTML = opt && opt.getAttribute('data-is-ladder') === '1'
				? '<span class="pk-badge-ladder"><i class="fas fa-layer-group"></i> Ladder Award</span>'
				: '';
		} else { infoEl.innerHTML = ''; }
		checkRequired();
	});

	// Player search autocomplete
	gid('pk-award-player-text').addEventListener('input', function() {
		gid('pk-award-player-id').value = '';
		checkRequired();
		var term = this.value.trim();
		if (term.length < 2) { gid('pk-award-player-results').classList.remove('pk-ac-open'); return; }
		clearTimeout(playerTimer);
		playerTimer = setTimeout(function() {
			var url = SEARCH_URL + '?Action=Search%2FPlayer&type=all&search=' + encodeURIComponent(term) + '&kingdom_id=' + KINGDOM_ID + '&limit=8';
			fetch(url).then(function(r) { return r.json(); }).then(function(data) {
				var el = gid('pk-award-player-results');
				el.innerHTML = (data && data.length)
					? data.map(function(p) {
						return '<div class="pk-ac-item" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
							+ p.Persona + ' <span style="color:#a0aec0;font-size:11px">(' + (p.KAbbr||'') + ':' + (p.PAbbr||'') + ')</span></div>';
					}).join('')
					: '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No players found</div>';
				el.classList.add('pk-ac-open');
			}).catch(function() {});
		}, 250);
	});
	gid('pk-award-player-results').addEventListener('click', function(e) {
		var item = e.target.closest('.pk-ac-item[data-id]');
		if (!item) return;
		gid('pk-award-player-text').value = decodeURIComponent(item.dataset.name);
		gid('pk-award-player-id').value   = item.dataset.id;
		this.classList.remove('pk-ac-open');
		checkRequired();
	});

	// Given By — officer chips + search

	document.querySelectorAll('#pk-award-officer-chips .pk-officer-chip').forEach(function(btn) {
		btn.addEventListener('click', function() {
			document.querySelectorAll('#pk-award-officer-chips .pk-officer-chip').forEach(function(c) { c.classList.remove('pk-selected'); });
			this.classList.add('pk-selected');
			gid('pk-award-givenby-text').value = this.dataset.name;
			gid('pk-award-givenby-id').value   = this.dataset.id;
			gid('pk-award-givenby-results').classList.remove('pk-ac-open');
			checkRequired();
		});
	});

	gid('pk-award-givenby-text').addEventListener('input', function() {
		gid('pk-award-givenby-id').value = '';
		document.querySelectorAll('#pk-award-officer-chips .pk-officer-chip').forEach(function(c) { c.classList.remove('pk-selected'); });
		checkRequired();
		var term = this.value.trim();
		if (term.length < 2) { gid('pk-award-givenby-results').classList.remove('pk-ac-open'); return; }
		clearTimeout(givenByTimer);
		givenByTimer = setTimeout(function() {
			var url = SEARCH_URL + '?Action=Search%2FPlayer&type=all&search=' + encodeURIComponent(term) + '&kingdom_id=' + KINGDOM_ID + '&limit=6';
			fetch(url).then(function(r) { return r.json(); }).then(function(data) {
				var el = gid('pk-award-givenby-results');
				el.innerHTML = (data && data.length)
					? data.map(function(p) {
						return '<div class="pk-ac-item" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
							+ p.Persona + ' <span style="color:#a0aec0;font-size:11px">(' + (p.KAbbr||'') + ':' + (p.PAbbr||'') + ')</span></div>';
					}).join('')
					: '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No results</div>';
				el.classList.add('pk-ac-open');
			}).catch(function() {});
		}, 250);
	});
	gid('pk-award-givenby-results').addEventListener('click', function(e) {
		var item = e.target.closest('.pk-ac-item[data-id]');
		if (!item) return;
		gid('pk-award-givenby-text').value = decodeURIComponent(item.dataset.name);
		gid('pk-award-givenby-id').value   = item.dataset.id;
		this.classList.remove('pk-ac-open');
		checkRequired();
	});

	// Given At — location search
	gid('pk-award-givenat-text').addEventListener('input', function() {
		var term = this.value.trim();
		if (term.length < 2) { gid('pk-award-givenat-results').classList.remove('pk-ac-open'); return; }
		clearTimeout(givenAtTimer);
		givenAtTimer = setTimeout(function() {
			var url = SEARCH_URL + '?Action=Search%2FLocation&search=' + encodeURIComponent(term) + '&kingdom_id=' + KINGDOM_ID + '&limit=6';
			fetch(url).then(function(r) { return r.json(); }).then(function(data) {
				var el = gid('pk-award-givenat-results');
				el.innerHTML = (data && data.length)
					? data.map(function(loc) {
						return '<div class="pk-ac-item" data-pid="' + (loc.ParkId||0) + '" data-kid="' + (loc.KingdomId||0) + '" data-eid="' + (loc.EventId||0) + '" data-name="' + encodeURIComponent(loc.LocationName||loc.ShortName||'') + '">'
							+ (loc.LocationName || loc.ShortName || '') + '</div>';
					}).join('')
					: '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No locations found</div>';
				el.classList.add('pk-ac-open');
			}).catch(function() {});
		}, 250);
	});
	gid('pk-award-givenat-results').addEventListener('click', function(e) {
		var item = e.target.closest('.pk-ac-item');
		if (!item || !item.dataset.name) return;
		gid('pk-award-givenat-text').value    = decodeURIComponent(item.dataset.name);
		gid('pk-award-park-id').value         = item.dataset.pid || '0';
		gid('pk-award-kingdom-id').value      = item.dataset.kid || '0';
		gid('pk-award-event-id').value        = item.dataset.eid || '0';
		this.classList.remove('pk-ac-open');
	});

	// Note char counter
	gid('pk-award-note').addEventListener('input', function() {
		var rem = 400 - this.value.length;
		var el  = gid('pk-award-char-count');
		el.textContent = rem + ' character' + (rem !== 1 ? 's' : '') + ' remaining';
	});

	gid('pk-award-select').addEventListener('change', checkRequired);
	gid('pk-award-date').addEventListener('change', checkRequired);
	gid('pk-award-date').addEventListener('input',  checkRequired);

	// ---- Open / Close ----
	window.pkOpenAwardModal = function() {
		var today = new Date();
		gid('pk-award-error').style.display      = 'none';
		gid('pk-award-error').textContent        = '';
		gid('pk-award-success').style.display    = 'none';
		gid('pk-award-player-text').value        = '';
		gid('pk-award-player-id').value          = '';
		gid('pk-award-player-results').classList.remove('pk-ac-open');
		gid('pk-award-note').value               = '';
		gid('pk-award-char-count').textContent   = '400 characters remaining';
		gid('pk-award-givenby-text').value       = '';
		gid('pk-award-givenby-id').value         = '';
		gid('pk-award-givenby-results').classList.remove('pk-ac-open');
		gid('pk-award-givenat-text').value = PkConfig.parkName;
		gid('pk-award-park-id').value = String(PkConfig.parkId);
		gid('pk-award-kingdom-id').value         = '0';
		gid('pk-award-event-id').value           = '0';
		gid('pk-award-givenat-results').classList.remove('pk-ac-open');
		gid('pk-award-custom-name').value        = '';
		gid('pk-award-custom-row').style.display = 'none';
		gid('pk-award-rank-row').style.display   = 'none';
		gid('pk-award-rank-val').value           = '';
		gid('pk-award-info-line').innerHTML      = '';
		document.querySelectorAll('#pk-award-officer-chips .pk-officer-chip').forEach(function(c) { c.classList.remove('pk-selected'); });
		gid('pk-award-date').value = today.getFullYear() + '-'
			+ String(today.getMonth() + 1).padStart(2, '0') + '-'
			+ String(today.getDate()).padStart(2, '0');
		setAwardType('awards');
		checkRequired();
		gid('pk-award-overlay').classList.add('pk-open');
		document.body.style.overflow = 'hidden';
		gid('pk-award-player-text').focus();
	};
	window.pkCloseAwardModal = function() {
		gid('pk-award-overlay').classList.remove('pk-open');
		document.body.style.overflow = '';
	};

	gid('pk-award-close-btn').addEventListener('click', pkCloseAwardModal);
	gid('pk-award-cancel').addEventListener('click',    pkCloseAwardModal);
	gid('pk-award-overlay').addEventListener('click', function(e) {
		if (e.target === this) pkCloseAwardModal();
	});
	document.addEventListener('keydown', function(e) {
		if ((e.key === 'Escape' || e.keyCode === 27) && gid('pk-award-overlay').classList.contains('pk-open'))
			pkCloseAwardModal();
	});

	// ---- Save helpers ----
	var pkSuccessTimer = null;
	function pkShowSuccess() {
		var el = gid('pk-award-success');
		el.style.display = '';
		clearTimeout(pkSuccessTimer);
		pkSuccessTimer = setTimeout(function() { el.style.display = 'none'; }, 3000);
	}
	function pkClearPlayer() {
		gid('pk-award-player-text').value = '';
		gid('pk-award-player-id').value   = '';
		gid('pk-award-player-results').classList.remove('pk-ac-open');
	}
	function pkClearAward() {
		gid('pk-award-select').value             = '';
		gid('pk-award-rank-val').value           = '';
		gid('pk-award-rank-row').style.display   = 'none';
		gid('pk-rank-pills').innerHTML           = '';
		gid('pk-award-note').value               = '';
		gid('pk-award-char-count').textContent   = '400 characters remaining';
		gid('pk-award-info-line').innerHTML      = '';
		gid('pk-award-custom-name').value        = '';
		gid('pk-award-custom-row').style.display = 'none';
		checkRequired();
	}
	function pkDoSave(onSuccess) {
		var errEl    = gid('pk-award-error');
		var playerId = gid('pk-award-player-id').value;
		var awardId  = gid('pk-award-select').value;
		var giverId  = gid('pk-award-givenby-id').value;
		var date     = gid('pk-award-date').value;

		errEl.style.display = 'none';
		if (!playerId) { errEl.textContent = 'Please select a player.';             errEl.style.display = ''; return; }
		if (!awardId)  { errEl.textContent = 'Please select an award.';             errEl.style.display = ''; return; }
		if (!giverId)  { errEl.textContent = 'Please select who gave this award.';  errEl.style.display = ''; return; }
		if (!date)     { errEl.textContent = 'Please enter the award date.';        errEl.style.display = ''; return; }

		var fd = new FormData();
		fd.append('KingdomAwardId', awardId);
		fd.append('GivenById',      giverId);
		fd.append('Date',           date);
		fd.append('ParkId',         gid('pk-award-park-id').value    || '0');
		fd.append('KingdomId',      gid('pk-award-kingdom-id').value || '0');
		fd.append('EventId',        gid('pk-award-event-id').value   || '0');
		fd.append('Note',           gid('pk-award-note').value       || '');
		var rank = gid('pk-award-rank-val').value;
		if (rank) fd.append('Rank', rank);
		var customName = gid('pk-award-custom-name') ? gid('pk-award-custom-name').value.trim() : '';
		if (customName) fd.append('AwardName', customName);

		var btnNew  = gid('pk-award-save-new');
		var btnSame = gid('pk-award-save-same');
		btnNew.disabled = btnSame.disabled = true;
		btnNew.innerHTML  = '<i class="fas fa-spinner fa-spin"></i>';
		btnSame.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

		var saveUrl = UIR_JS + 'Admin/player/' + playerId + '/addaward';
		fetch(saveUrl, { method: 'POST', body: fd })
			.then(function(resp) {
				if (!resp.ok) throw new Error('Server returned ' + resp.status);
				onSuccess();
			})
			.catch(function(err) {
				errEl.textContent = 'Save failed: ' + err.message;
				errEl.style.display = '';
			})
			.finally(function() {
				btnNew.innerHTML  = '<i class="fas fa-plus"></i> Add + New Player';
				btnSame.innerHTML = '<i class="fas fa-plus"></i> Add + Same Player';
				checkRequired();
			});
	}

	// "Add + New Player" — clear player + award/rank/note, keep date/giver/location
	gid('pk-award-save-new').addEventListener('click', function() {
		pkDoSave(function() { pkShowSuccess(); pkClearPlayer(); pkClearAward(); gid('pk-award-player-text').focus(); });
	});
	// "Add + Same Player" — clear only award/rank/note, keep player + date/giver/location
	gid('pk-award-save-same').addEventListener('click', function() {
		pkDoSave(function() { pkShowSuccess(); pkClearAward(); gid('pk-award-select').focus(); });
	});
})();
(function() {
	if (typeof PkConfig === 'undefined') return;
	var pkEventKingdomId = PkConfig.kingdomId;
	var pkEventParkId = PkConfig.parkId;
	var pkEventUIR = PkConfig.uir;

	window.pkOpenEventModal = function() {
		var sel = document.getElementById('pk-template-select');
		var btn = document.getElementById('pk-emod-go-btn');
		sel.innerHTML = '<option value="">Loading…</option>';
		btn.disabled = true;

		$.getJSON(PkConfig.httpService + 'Search/SearchService.php',
			{ Action: 'Search/Event', kingdom_id: pkEventKingdomId, limit: 50 },
			function(data) {
				if (!data || !data.length) {
					sel.innerHTML = '<option value="">No templates found for this kingdom</option>';
					return;
				}
				sel.innerHTML = '<option value="">— Select a template —</option>';
				$.each(data, function(i, v) {
					var label = v.Name + (v.ParkName ? ' (' + v.ParkName + ')' : '');
					var opt = document.createElement('option');
					opt.value = v.EventId;
					opt.textContent = label;
					sel.appendChild(opt);
				});
			}
		).fail(function() {
			sel.innerHTML = '<option value="">Error loading templates</option>';
		});

		sel.addEventListener('change', function() {
			btn.disabled = !this.value;
		});

		document.getElementById('pk-event-modal').classList.add('pk-emod-open');
		document.body.style.overflow = 'hidden';
	};

	window.pkCloseEventModal = function() {
		document.getElementById('pk-event-modal').classList.remove('pk-emod-open');
		document.body.style.overflow = '';
	};

	window.pkGoToEventCreate = function() {
		var v = document.getElementById('pk-template-select').value;
		if (v) window.location.href = pkEventUIR + 'Event/create/' + v + '/' + pkEventParkId;
	};

	$(document).ready(function() {
		var pkEvtOverlay = document.getElementById('pk-event-modal');
		if (pkEvtOverlay) {
			pkEvtOverlay.addEventListener('click', function(e) {
				if (e.target === this) pkCloseEventModal();
			});
		}
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape' && document.getElementById('pk-event-modal')) pkCloseEventModal();
		});
	});
})();

/* ===========================
   Event Detail (EvConfig)
   =========================== */
(function() {
	// ---- Tab switching ----
	window.evShowTab = function(li, tabId) {
		var nav    = document.getElementById('ev-tab-nav');
		var panels = document.querySelectorAll('.ev-tab-panel');
		nav.querySelectorAll('li').forEach(function(el) {
			el.classList.remove('ev-tab-active');
		});
		panels.forEach(function(p) { p.classList.remove('ev-tab-visible'); });
		li.classList.add('ev-tab-active');
		var panel = document.getElementById(tabId);
		if (panel) panel.classList.add('ev-tab-visible');
	};

	// ---- Autocompletes ----
	$(document).ready(function() {
		if (typeof EvConfig === 'undefined') return;
		function showLabel(sel, ui) {
			if (ui) $(sel).val(ui.item.label);
			return false;
		}

		$('#ev-KingdomName').autocomplete({
			source: function(req, res) {
				$.getJSON(EvConfig.httpService + 'Search/SearchService.php',
					{ Action: 'Search/Kingdom', name: req.term, limit: 6 },
					function(data) {
						res($.map(data, function(v) { return { label: v.Name, value: v.KingdomId }; }));
					});
			},
			focus:  function(e,ui) { return showLabel('#ev-KingdomName', ui); },
			delay: 250, minLength: 0,
			select: function(e,ui) { showLabel('#ev-KingdomName',ui); $('#ev-KingdomId').val(ui.item.value); return false; },
			change: function(e,ui) { if(!ui.item) { showLabel('#ev-KingdomName',null); $('#ev-KingdomId').val(''); } return false; }
		}).focus(function() { if(!this.value) $(this).trigger('keydown.autocomplete'); });

		$('#ev-ParkName').autocomplete({
			source: function(req, res) {
				$.getJSON(EvConfig.httpService + 'Search/SearchService.php',
					{ Action: 'Search/Park', name: req.term, kingdom_id: $('#ev-KingdomId').val(), limit: 6 },
					function(data) {
						res($.map(data, function(v) { return { label: v.Name, value: v.ParkId }; }));
					});
			},
			focus:  function(e,ui) { return showLabel('#ev-ParkName', ui); },
			delay: 250, minLength: 0,
			select: function(e,ui) { showLabel('#ev-ParkName',ui); $('#ev-ParkId').val(ui.item.value); return false; },
			change: function(e,ui) { if(!ui.item) { showLabel('#ev-ParkName',null); $('#ev-ParkId').val(''); } return false; }
		}).focus(function() { if(!this.value) $(this).trigger('keydown.autocomplete'); });

		$('#ev-PlayerName').autocomplete({
			source: function(req, res) {
				$.getJSON(EvConfig.httpService + 'Search/SearchService.php',
					{ Action: 'Search/Player', type: 'all', search: req.term,
					  park_id: $('#ev-ParkId').val(), kingdom_id: $('#ev-KingdomId').val(), limit: 15 },
					function(data) {
						res($.map(data, function(v) { return { label: v.Persona, value: v.MundaneId + '|' + v.PenaltyBox }; }));
					});
			},
			focus:  function(e,ui) { return showLabel('#ev-PlayerName', ui); },
			delay: 250, minLength: 0,
			select: function(e,ui) {
				showLabel('#ev-PlayerName', ui);
				$('#ev-MundaneId').val(ui.item.value.split('|')[0]);
				return false;
			},
			change: function(e,ui) { if(!ui.item) { showLabel('#ev-PlayerName',null); $('#ev-MundaneId').val(''); } return false; }
		}).focus(function() { if(!this.value) $(this).trigger('keydown.autocomplete'); });
	});

	// ---- Hero dominant-color tint ----
	function evApplyHeroColor() {
		var img = document.getElementById('ev-heraldry-img');
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
				var s = max===min ? 0 : (l<0.5 ? (max-min)/(max+min) : (max-min)/(2-max-min));
				var h=0;
				if (max!==min) {
					var d2=(max-min)/255;
					if (max===r/255) h=(g/255-b/255)/d2+(g<b?6:0);
					else if (max===g/255) h=(b/255-r/255)/d2+2;
					else h=(r/255-g/255)/d2+4;
					h*=60;
				}
				var hero = document.getElementById('ev-hero');
				if (hero) hero.style.backgroundColor = 'hsl('+Math.round(h)+','+Math.round(s*55)+'%,18%)';
			} catch(e){}
		}
		if (img.complete && img.naturalWidth > 0) { extract(); }
		else { img.addEventListener('load', extract); }
	}
	evApplyHeroColor();

	// ---- Edit modal ----
	window.evOpenEditModal = function() {
		var overlay = document.getElementById('ev-edit-modal');
		if (overlay) overlay.classList.add('ev-modal-open');
		document.body.style.overflow = 'hidden';
	};
	window.evCloseEditModal = function() {
		var overlay = document.getElementById('ev-edit-modal');
		if (overlay) overlay.classList.remove('ev-modal-open');
		document.body.style.overflow = '';
	};
	// Close on backdrop click
	document.addEventListener('click', function(e) {
		if (e.target && e.target.id === 'ev-edit-modal') evCloseEditModal();
	});
	// Close on Escape key
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') evCloseEditModal();
	});
})();

/* ===========================
   Event Template (EnConfig)
   =========================== */
if (typeof EnConfig !== 'undefined') (function() {
	// ---- Tab switching ----
	var calendarInstance = null;
	window.enTab = function(el) {
		var tabId = el.getAttribute('data-tab');
		// Update nav
		var navItems = document.querySelectorAll('#en-tab-nav li');
		navItems.forEach(function(li) { li.classList.remove('en-tab-active'); });
		el.classList.add('en-tab-active');
		// Show/hide panels
		var panels = document.querySelectorAll('.en-tab-panel');
		panels.forEach(function(p) { p.style.display = 'none'; });
		var target = document.getElementById(tabId);
		if (target) target.style.display = '';
		// Init calendar lazily on first show
		if (tabId === 'en-tab-calendar' && !calendarInstance) {
			enInitCalendar();
		}
	};

	// ---- FullCalendar init ----
	function enInitCalendar() {
		var el = document.getElementById('en-calendar');
		if (!el || typeof FullCalendar === 'undefined') return;
		calendarInstance = new FullCalendar.Calendar(el, {
			initialView: 'dayGridMonth',
			initialDate: EnConfig.calInitDate,
			headerToolbar: {
				left:   'prev,next today',
				center: 'title',
				right:  'dayGridMonth,listMonth'
			},
			events: EnConfig.calEvents,
			eventClick: function(info) {
				info.jsEvent.preventDefault();
				if (info.event.url) window.location.href = info.event.url;
			},
			eventDidMount: function(info) {
				info.el.title = info.event.title;
			},
			height: 'auto',
			fixedWeekCount: false
		});
		calendarInstance.render();
	}

	// ---- Past dates toggle ----
	window.enTogglePast = function(btn) {
		var section  = document.getElementById('en-past-section');
		var chevron  = document.getElementById('en-past-chevron');
		var count = EnConfig.pastCount;
		var isHidden = section.style.display === 'none';
		section.style.display = isHidden ? '' : 'none';
		chevron.className = isHidden ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
		btn.childNodes[btn.childNodes.length - 1].textContent =
			isHidden
				? ' Hide past dates'
				: ' Show ' + count + ' past date' + (count === 1 ? '' : 's');
	};

	// ---- Hero dominant-color tint ----
	function enApplyHeroColor() {
		var img = document.getElementById('en-heraldry-img');
		if (!img) return;
		function extract() {
			try {
				var c = document.createElement('canvas');
				c.width = 32; c.height = 32;
				var ctx = c.getContext('2d');
				ctx.drawImage(img, 0, 0, 32, 32);
				var d = ctx.getImageData(0, 0, 32, 32).data;
				var r=0,g=0,b=0,count=0;
				for (var i=0;i<d.length;i+=4) {
					if (d[i+3]>30) { r+=d[i]; g+=d[i+1]; b+=d[i+2]; count++; }
				}
				if (!count) return;
				r=Math.round(r/count); g=Math.round(g/count); b=Math.round(b/count);
				var max=Math.max(r,g,b)/255, min=Math.min(r,g,b)/255;
				var l=(max+min)/2;
				var s = max===min ? 0 : (l<0.5 ? (max-min)/(max+min) : (max-min)/(2-max-min));
				var h=0;
				if (max!==min) {
					var d2=(max-min)/255;
					if (max===r/255) h=(g/255-b/255)/d2+(g<b?6:0);
					else if (max===g/255) h=(b/255-r/255)/d2+2;
					else h=(r/255-g/255)/d2+4;
					h*=60;
				}
				var hero = document.getElementById('en-hero');
				if (hero) hero.style.backgroundColor = 'hsl('+Math.round(h)+','+Math.round(s*55)+'%,18%)';
			} catch(e){}
		}
		if (img.complete && img.naturalWidth > 0) { extract(); }
		else { img.addEventListener('load', extract); }
	}
	enApplyHeroColor();

	// ---- Create Occurrence modal ----
	window.enOpenCreateModal = function() {
		var overlay = document.getElementById('en-create-modal');
		if (overlay) overlay.classList.add('en-cmod-open');
		document.body.style.overflow = 'hidden';
	};
	window.enCloseCreateModal = function() {
		var overlay = document.getElementById('en-create-modal');
		if (overlay) overlay.classList.remove('en-cmod-open');
		document.body.style.overflow = '';
	};
	// Close on backdrop click
	document.addEventListener('click', function(e) {
		var overlay = document.getElementById('en-create-modal');
		if (overlay && overlay.classList.contains('en-cmod-open') && e.target === overlay) {
			enCloseCreateModal();
		}
	});
	// Close on Escape
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') enCloseCreateModal();
	});
})();

/* ===========================
   Event Create (EcConfig)
   =========================== */
if (typeof EcConfig !== 'undefined') (function() {
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
		var SEARCH_URL = EcConfig.httpService + 'Search/SearchService.php';

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

// ============================================================
// Parknew — Attendance Modal (pk-att-)
// ============================================================
$(document).ready(function() {
    if (!document.getElementById('pk-att-overlay')) return;

    var ADD_URL    = PkConfig.uir + 'AttendanceAjax/park/' + PkConfig.parkId + '/add';
    var SEARCH_URL = PkConfig.httpService + 'Search/SearchService.php';

    function gid(id) { return document.getElementById(id); }

    // --- Open / Close ---
    window.pkOpenAttendanceModal = function() {
        gid('pk-att-date').value = new Date().toISOString().slice(0, 10);
        gid('pk-att-credits-default').value = '1';
        gid('pk-att-search-credits').value  = '1';
        pkBuildClassOptions();
        pkBuildQuickAddRows();
        gid('pk-att-player-name').value = '';
        gid('pk-att-player-id').value   = '';
        pkAttHideFeedback();
        gid('pk-att-overlay').classList.add('pk-att-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { gid('pk-att-player-name').focus(); }, 50);
    };
    window.pkCloseAttendanceModal = function() {
        gid('pk-att-overlay').classList.remove('pk-att-open');
        document.body.style.overflow = '';
    };

    // --- Class options ---
    function pkBuildClassOptions() {
        var sel = gid('pk-att-class-select');
        if (sel.dataset.built) return;
        (PkConfig.classes || []).forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c.ClassId; opt.textContent = c.ClassName;
            sel.appendChild(opt);
        });
        sel.dataset.built = '1';
    }
    function pkMakeClassSelect(selectedId) {
        var sel = document.createElement('select');
        sel.className = 'pk-att-qa-select';
        var blank = document.createElement('option');
        blank.value = ''; blank.textContent = '— class —';
        sel.appendChild(blank);
        (PkConfig.classes || []).forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c.ClassId; opt.textContent = c.ClassName;
            if (parseInt(c.ClassId) === parseInt(selectedId)) opt.selected = true;
            sel.appendChild(opt);
        });
        return sel;
    }

    // --- Quick-add table ---
    function pkBuildQuickAddRows() {
        var tbody = gid('pk-att-qa-tbody');
        if (tbody.dataset.built) return;
        var list = PkConfig.recentAttendees || [];
        gid('pk-att-qa-empty').style.display = list.length ? 'none' : '';
        list.forEach(function(a) {
            var tr = document.createElement('tr');
            tr.dataset.mundaneId = a.MundaneId;

            var td1 = document.createElement('td'); td1.textContent = a.Persona;
            tr.appendChild(td1);

            var td2 = document.createElement('td');
            td2.appendChild(pkMakeClassSelect(a.ClassId));
            tr.appendChild(td2);

            var td3 = document.createElement('td');
            var ci = document.createElement('input');
            ci.type = 'number'; ci.min = '0.5'; ci.step = '0.5'; ci.value = '1';
            ci.className = 'pk-att-qa-credits';
            td3.appendChild(ci); tr.appendChild(td3);

            var td4 = document.createElement('td');
            var btn = document.createElement('button');
            btn.className = 'pk-att-qa-add'; btn.textContent = 'Add';
            btn.addEventListener('click', function() { pkQuickAdd(tr, a, btn); });
            td4.appendChild(btn); tr.appendChild(td4);

            tbody.appendChild(tr);
        });
        tbody.dataset.built = '1';
    }

    // --- Quick-add submit ---
    function pkQuickAdd(tr, attendee, btn) {
        var classId = tr.querySelector('.pk-att-qa-select').value;
        var credits = tr.querySelector('.pk-att-qa-credits').value;
        if (!classId) { pkAttShowFeedback('Select a class for ' + attendee.Persona + '.', false); return; }
        btn.disabled = true; btn.textContent = '\u2026';
        pkSubmit(
            { AttendanceDate: gid('pk-att-date').value, MundaneId: attendee.MundaneId, ClassId: classId, Credits: credits },
            function(ok, err) {
                if (ok) {
                    tr.classList.add('pk-att-done');
                    btn.parentNode.innerHTML = '<span class="pk-att-qa-done-mark">\u2713</span>';
                    pkAttRecorded(attendee.Persona);
                    pkAttHideFeedback();
                } else {
                    btn.disabled = false; btn.textContent = 'Add';
                    pkAttShowFeedback(err, false);
                }
            }
        );
    }

    // --- Search-add submit ---
    gid('pk-att-add-btn').addEventListener('click', function() {
        var pid  = gid('pk-att-player-id').value;
        var name = gid('pk-att-player-name').value.trim();
        var cls  = gid('pk-att-class-select').value;
        var cred = gid('pk-att-search-credits').value;
        if (!pid)  { pkAttShowFeedback('Search for and select a player.', false); return; }
        if (!cls)  { pkAttShowFeedback('Select a class.', false); return; }
        var btn = gid('pk-att-add-btn');
        btn.disabled = true;
        pkSubmit(
            { AttendanceDate: gid('pk-att-date').value, MundaneId: pid, ClassId: cls, Credits: cred },
            function(ok, err) {
                btn.disabled = false;
                if (ok) {
                    pkAttShowFeedback('Added: ' + name, true);
                    pkAttRecorded(name);
                    gid('pk-att-player-name').value = '';
                    gid('pk-att-player-id').value   = '';
                } else {
                    pkAttShowFeedback(err, false);
                }
            }
        );
    });

    // --- Core AJAX ---
    function pkSubmit(data, cb) {
        $.post(ADD_URL, data, function(r) {
            if (r && r.status === 0) cb(true,  null);
            else                     cb(false, (r && r.error) ? r.error : 'Submission failed.');
        }, 'json').fail(function() { cb(false, 'Request failed. Please try again.'); });
    }

    // --- Feedback helpers ---
    function pkAttShowFeedback(msg, ok) {
        var el = gid('pk-att-feedback');
        el.textContent = msg;
        el.className = 'pk-att-feedback ' + (ok ? 'pk-att-ok' : 'pk-att-err');
        el.style.display = '';
    }
    function pkAttHideFeedback() { gid('pk-att-feedback').style.display = 'none'; }
    function pkAttRecorded(name) {
        var ul = gid('pk-att-added-list');
        var li = document.createElement('li');
        li.textContent = name;
        ul.prepend(li);
        gid('pk-att-added-section').style.display = '';
    }

    // --- Collapsible toggle ---
    gid('pk-att-qa-toggle').addEventListener('click', function() {
        var wrap    = gid('pk-att-qa-wrap');
        var chevron = gid('pk-att-qa-chevron');
        var open    = wrap.style.display !== 'none';
        wrap.style.display = open ? 'none' : '';
        chevron.classList.toggle('pk-att-open', !open);
        this.setAttribute('aria-expanded', String(!open));
        if (!open) pkBuildQuickAddRows();
    });

    // --- Credits default sync ---
    gid('pk-att-credits-default').addEventListener('change', function() {
        gid('pk-att-search-credits').value = this.value;
    });

    // --- Close handlers ---
    gid('pk-att-close-btn').addEventListener('click', pkCloseAttendanceModal);
    gid('pk-att-done-btn').addEventListener('click',  pkCloseAttendanceModal);
    gid('pk-att-overlay').addEventListener('click', function(e) {
        if (e.target === this) pkCloseAttendanceModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && gid('pk-att-overlay').classList.contains('pk-att-open'))
            pkCloseAttendanceModal();
    });

    // --- Player autocomplete (grouped: park → kingdom → other) ---
    function pkAttAbbr(v) {
        return (v.KAbbr && v.PAbbr) ? v.KAbbr + ':' + v.PAbbr : (v.ParkName || '');
    }
    var pkAttAC = $('#pk-att-player-name').autocomplete({
        source: function(req, res) {
            var s = req.term;
            $.when(
                $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, park_id: PkConfig.parkId, limit: 8 }),
                $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, kingdom_id: PkConfig.kingdomId, limit: 8 }),
                $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, limit: 8 })
            ).done(function(parkRes, kingRes, allRes) {
                var seen = {}, parkItems = [], kingItems = [], otherItems = [];
                $.each(parkRes[0] || [], function(i, v) {
                    seen[v.MundaneId] = true;
                    parkItems.push({ label: v.Persona + ' \u2014 ' + pkAttAbbr(v), name: v.Persona, value: v.MundaneId });
                });
                $.each(kingRes[0] || [], function(i, v) {
                    if (seen[v.MundaneId]) return;
                    seen[v.MundaneId] = true;
                    kingItems.push({ label: v.Persona + ' \u2014 ' + pkAttAbbr(v), name: v.Persona, value: v.MundaneId });
                });
                $.each(allRes[0] || [], function(i, v) {
                    if (seen[v.MundaneId]) return;
                    otherItems.push({ label: v.Persona + ' \u2014 ' + pkAttAbbr(v), name: v.Persona, value: v.MundaneId });
                });
                var sep = { label: '', name: '', value: null, separator: true };
                var items = parkItems;
                if (kingItems.length) { if (items.length) items.push(sep); items = items.concat(kingItems); }
                if (otherItems.length) { if (items.length) items.push(sep); items = items.concat(otherItems); }
                res(items);
            });
        },
        focus:  function(e, ui) { if (!ui.item.value) return false; $('#pk-att-player-name').val(ui.item.name); return false; },
        select: function(e, ui) {
            if (!ui.item.value) return false;
            $('#pk-att-player-name').val(ui.item.name);
            $('#pk-att-player-id').val(ui.item.value);
            // Pre-fill class from recent attendees if available
            var recent = (PkConfig.recentAttendees || []);
            for (var ri = 0; ri < recent.length; ri++) {
                if (recent[ri].MundaneId === ui.item.value) {
                    pkBuildClassOptions();
                    gid('pk-att-class-select').value = String(recent[ri].ClassId);
                    break;
                }
            }
            return false;
        },
        change: function(e, ui) { if (!ui.item) $('#pk-att-player-id').val(''); return false; },
        delay: 250, minLength: 2,
    });
    pkAttAC.data('autocomplete')._renderItem = function(ul, item) {
        if (item.separator) {
            return $('<li class="pk-att-ac-sep">').appendTo(ul);
        }
        return $('<li></li>').data('item.autocomplete', item).append($('<a>').text(item.label)).appendTo(ul);
    };
});
