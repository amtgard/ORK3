/* ===========================
   HTML escape helper
   =========================== */
function escHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/* ===========================
   Shared constants
   =========================== */
var AUTOCOMPLETE_DEBOUNCE_MS = 250;
var AWARD_NOTE_MAX_CHARS     = 400;

/* ===========================
   Autocomplete keyboard nav
   =========================== */
/**
 * Wire up arrow-key + Enter navigation for an autocomplete input/results pair.
 * @param {HTMLElement} inputEl   - the text input
 * @param {HTMLElement} resultsEl - the results container
 * @param {string}      openClass - CSS class used to show the results (e.g. 'pn-ac-open')
 * @param {string}      itemSel   - selector for individual result items (e.g. '.pn-ac-item')
 */
function acKeyNav(inputEl, resultsEl, openClass, itemSel) {
    inputEl.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowDown') {
            var first = resultsEl.querySelector(itemSel + '[tabindex]');
            if (first) { e.preventDefault(); first.focus(); }
        } else if (e.key === 'Escape') {
            if (resultsEl.classList.contains(openClass)) {
                e.stopPropagation();
                resultsEl.classList.remove(openClass);
            }
        }
    });
    resultsEl.addEventListener('keydown', function(e) {
        var item = e.target.closest ? e.target.closest(itemSel) : null;
        if (!item) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            var next = item.nextElementSibling;
            while (next && !next.matches(itemSel)) next = next.nextElementSibling;
            if (next) next.focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            var prev = item.previousElementSibling;
            while (prev && !prev.matches(itemSel)) prev = prev.previousElementSibling;
            if (prev) prev.focus(); else inputEl.focus();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            item.click();
        } else if (e.key === 'Escape') {
            e.stopPropagation();
            resultsEl.classList.remove(openClass);
            inputEl.focus();
        }
    });
}

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

/* ===========================
   Generic confirm dialog
   opts: { title, message, confirmText, danger }
   =========================== */
(function() {
    var _inited = false;
    function _init() {
        if (_inited) return;
        _inited = true;
        var el = document.createElement('div');
        el.innerHTML = '<div class="pn-overlay" id="pn-confirm-overlay">' +
            '<div class="pn-modal-box" style="width:380px;max-width:calc(100vw - 40px);">' +
                '<div class="pn-modal-header">' +
                    '<h3 class="pn-modal-title" id="pn-confirm-title"></h3>' +
                    '<button class="pn-modal-close-btn" id="pn-confirm-close-btn" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="pn-modal-body"><p id="pn-confirm-message" style="margin:0;font-size:14px;color:#4a5568"></p></div>' +
                '<div class="pn-modal-footer">' +
                    '<button class="pn-btn pn-btn-secondary" id="pn-confirm-cancel">Cancel</button>' +
                    '<button class="pn-btn" id="pn-confirm-ok">Confirm</button>' +
                '</div>' +
            '</div>' +
        '</div>';
        document.body.appendChild(el.firstChild);
        document.getElementById('pn-confirm-close-btn').addEventListener('click', _close);
        document.getElementById('pn-confirm-cancel').addEventListener('click', _close);
        document.getElementById('pn-confirm-overlay').addEventListener('click', function(e) {
            if (e.target === this) _close();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('pn-confirm-overlay').classList.contains('pn-open'))
                _close();
        });
    }
    function _close() {
        document.getElementById('pn-confirm-overlay').classList.remove('pn-open');
        document.body.style.overflow = '';
    }
    /** @type {function({title?:string,message?:string,confirmText?:string,danger?:boolean}|string, function):void} */
    window.pnConfirm = function(opts, onConfirm) {
        if (typeof opts === 'string') opts = { message: opts };
        _init();
        document.getElementById('pn-confirm-title').textContent   = opts.title   || 'Confirm';
        document.getElementById('pn-confirm-message').textContent = opts.message || '';
        var okBtn = document.getElementById('pn-confirm-ok');
        okBtn.textContent        = opts.confirmText || 'Confirm';
        okBtn.style.background   = opts.danger !== false ? '#c53030' : '';
        okBtn.style.color        = opts.danger !== false ? '#fff'    : '';
        // Replace node to clear previous listeners
        var fresh = okBtn.cloneNode(true);
        okBtn.parentNode.replaceChild(fresh, okBtn);
        fresh.addEventListener('click', function() { _close(); onConfirm(); });
        document.getElementById('pn-confirm-overlay').classList.add('pn-open');
        document.body.style.overflow = 'hidden';
    };
})();

function pnSetPageSize(tableId, size) {
    var $table = $('#' + tableId);
    if (!$table.length) return;
    $table.data('pagesize', parseInt(size));
    pnPaginate($table, 1);
}

function pnAwardSearch(q) {
    q = q.trim().toLowerCase();
    var table = document.getElementById('pn-awards-table');
    var noResults = document.getElementById('pn-award-search-empty');
    if (!table) return;
    var rows = table.querySelectorAll('tbody tr');
    if (!q) {
        rows.forEach(function(r) { r.style.display = ''; });
        if (noResults) noResults.style.display = 'none';
        if (typeof pnPaginate === 'function') pnPaginate($('#pn-awards-table'), 1);
        return;
    }
    var matchCount = 0;
    rows.forEach(function(r) {
        var match = r.textContent.toLowerCase().indexOf(q) !== -1;
        r.style.display = match ? '' : 'none';
        if (match) matchCount++;
    });
    var pg = table.nextElementSibling;
    while (pg && !pg.classList.contains('pn-pagination')) { pg = pg.nextElementSibling; }
    if (pg) pg.style.display = 'none';
    if (noResults) noResults.style.display = matchCount === 0 ? '' : 'none';
}

function pnPaginate($table, page) {
    var pageSize = parseInt($table.data('pagesize')) || 10;
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
    var $pnTab = $('.pn-tab-nav li[data-tab="' + tab + '"]');
    $pnTab.addClass('pn-tab-active');
    $('.pn-tab-panel').hide();
    $('#pn-tab-' + tab).show();
    var pnLabel = $pnTab.find('.pn-tab-label').text().trim();
    if (pnLabel) $('#pn-active-tab-label').text(pnLabel);
}

// ---- Custom Recommendation Modal (global — called from inline onclick) ----
function pnOpenModal() {
    $('#pn-rec-overlay').addClass('pn-open');
    $('body').css('overflow', 'hidden');
}
function pnCloseModal() {
    $('#pn-rec-overlay').removeClass('pn-open');
    $('body').css('overflow', '');
    $('#pn-rec-error').hide().empty();
}

$(document).ready(function() {
    if (typeof PnConfig === 'undefined') return;

    // ---- Tab Switching ----
    $('.pn-tab-nav li').on('click', function() {
        pnActivateTab($(this).data('tab'));
    });

    // ---- Ladder tile → filter awards ----
    $('.pn-ladder-item[data-ladname]').on('click', function() {
        var name = $(this).data('ladname');
        pnActivateTab('awards');
        var $input = $('#pn-award-search');
        $input.val(name);
        pnAwardSearch(name);
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

        var thresholds = [5, 12, 21, 34];
        var badge = '';
        if (credits >= 53) {
            badge = 'max';
        } else {
            for (var i = 0; i < thresholds.length; i++) {
                var t = thresholds[i];
                if (credits === t) { badge = 'leveled'; break; }
                else if (credits === t - 1 || credits === t - 2) { badge = 'soon'; break; }
            }
        }
        var $nameCell = $(this).find('td:first');
        $nameCell.find('.pn-level-badge').remove();
        if (badge === 'max') {
            $nameCell.append('<span class="pn-level-badge pn-level-badge-max"><i class="fas fa-star"></i> Level 6</span>');
        } else if (badge === 'leveled') {
            $nameCell.append('<span class="pn-level-badge pn-level-badge-up"><i class="fas fa-arrow-up"></i> Leveled Up!</span>');
        } else if (badge === 'soon') {
            $nameCell.append('<span class="pn-level-badge pn-level-badge-soon"><i class="fas fa-hourglass-half"></i> Soon to Level!</span>');
        }
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
        gtag('event', 'recommendation_add');
        $('#pn-recommend-form').submit();
    });

    // Character counter
    $('#pn-rec-reason').on('input', function() {
        var remaining = AWARD_NOTE_MAX_CHARS - $(this).val().length;
        $('#pn-rec-char-count')
            .text(remaining + ' character' + (remaining !== 1 ? 's' : '') + ' remaining')
            .toggleClass('pn-char-warn', remaining < 50);
    });


    // Rank bubbles for recommend dialog (reuses player's preloaded award ranks)
    var pnAwardRanks = PnConfig.awardRanks;
    function buildRecRankPills(awardId) {
        var row   = document.getElementById('pn-rec-rank-row');
        var wrap  = document.getElementById('pn-rec-rank-pills');
        var input = document.getElementById('pn-rec-rank-val');
        wrap.innerHTML = '';
        input.value = '';
        row.style.display = 'none';
        if (!awardId) return;
        var opt = document.querySelector('#pn-rec-award option[value="' + awardId + '"]');
        if (!opt || opt.getAttribute('data-is-ladder') !== '1') return;
        row.style.display = '';
        var baseAwardId = parseInt(opt.getAttribute('data-award-id')) || 0;
        var maxRank   = /zodiac/i.test(opt.textContent) ? 12 : 10;
        var held      = pnAwardRanks[baseAwardId] || 0;
        var suggested = Math.min(held + 1, maxRank);
        for (var r = 1; r <= maxRank; r++) {
            var pill = document.createElement('div');
            pill.className = 'pn-rank-pill';
            if (r <= held)       pill.className += ' pn-rank-held';
            if (r === suggested) pill.className += ' pn-rank-suggested';
            pill.textContent  = r;
            pill.dataset.rank = r;
            wrap.appendChild(pill);
        }
        var suggestedPill = wrap.querySelector('[data-rank="' + suggested + '"]');
        if (suggestedPill) { suggestedPill.classList.add('pn-rank-selected'); input.value = suggested; }
    }
    var pnRecRankPillsEl = document.getElementById('pn-rec-rank-pills');
    if (pnRecRankPillsEl) pnRecRankPillsEl.addEventListener('click', function(e) {
        var p = e.target.closest ? e.target.closest('.pn-rank-pill') : (e.target.classList.contains('pn-rank-pill') ? e.target : null);
        if (!p) return;
        var input = document.getElementById('pn-rec-rank-val');
        this.querySelectorAll('.pn-rank-pill').forEach(function(x) { x.classList.remove('pn-rank-selected'); });
        p.classList.add('pn-rank-selected');
        input.value = p.dataset.rank;
    });
    $('#pn-rec-award').on('change', function() {
        buildRecRankPills($(this).val());
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
                    gtag('event', imgType === 'photo' ? 'player_photo_upload' : 'player_heraldry_upload', { status: 'success' });
                    showStep('pn-img-step-success');
                    setTimeout(function() { window.location.reload(); }, 1400);
                })
                .catch(function(err) {
                    gtag('event', imgType === 'photo' ? 'player_photo_upload' : 'player_heraldry_upload', { status: 'failed' });
                    showStep('pn-img-step-select');
                    showError('Upload failed: ' + err.message);
                });
        }

        // Remove button — update label on open, handle click
        var origOpen = window.pnOpenImgModal;
        window.pnOpenImgModal = function(type) {
            origOpen(type);
            var lbl = gid('pn-img-remove-label');
            if (lbl) lbl.textContent = (type === 'photo') ? 'Remove Photo' : 'Remove Heraldry';
        };
        var removeBtn = gid('pn-img-remove-btn');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                var label = (imgType === 'photo') ? 'player photo' : 'heraldry';
                if (!confirm('Remove the ' + label + '? This cannot be undone.')) return;
                removeBtn.disabled = true;
                var action = (imgType === 'photo') ? 'removeimage' : 'removeheraldry';
                fetch(PnConfig.uir + 'PlayerAjax/player/' + PnConfig.playerId + '/' + action, { method: 'POST' })
                    .then(function(r) { return r.json(); })
                    .then(function(result) {
                        if (result && result.status === 0) {
                            showStep('pn-img-step-success');
                            setTimeout(function() { window.location.reload(); }, 1400);
                        } else {
                            removeBtn.disabled = false;
                            showError((result && result.error) ? result.error : 'Remove failed.');
                        }
                    })
                    .catch(function() {
                        removeBtn.disabled = false;
                        showError('Request failed.');
                    });
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

        (function() {
            var emailInput = gid('pn-acct-email');
            var emailWarn  = gid('pn-acct-email-warn');
            emailInput.addEventListener('input', function() {
                var v = emailInput.value.trim();
                var ok = !v || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
                emailWarn.style.display = ok ? 'none' : '';
            });
        })();

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
                var val = (el.type === 'date' && el.value === '') ? '0000-00-00' : el.value;
                fd.append(el.name, val);
            });

            var btn = gid('pn-acct-save');
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

        setupPronounPicker({
            toggleId: 'pn-pronoun-custom-btn',  panelId: 'pn-pronoun-picker',
            previewId: 'pn-pronoun-preview',     applyId: 'pn-pronoun-apply',
            clearId:   'pn-pronoun-clear',       hiddenId: 'pn-pronoun-custom-val',
            standardSelId: 'pn-acct-pronouns',
            subjectId: 'pn-p-subject', objectId: 'pn-p-object', possId: 'pn-p-possessive',
            posspId: 'pn-p-possessivepronoun', reflexId: 'pn-p-reflexive',
            existingJson: (function() { var el = document.getElementById('pn-pronoun-custom-val'); return el ? el.value : ''; })(),
        });
    })();

    // ---- Add Dues Modal ----
    (function() {
        if (!PnConfig.canEditAdmin) return;
        var DUES_URL = PnConfig.uir + 'Admin/player/' + PnConfig.playerId + '/adddues';

        function gid(id) { return document.getElementById(id); }

        var duesPeriodType = (typeof PnConfig !== 'undefined' && PnConfig.duesPeriodType) || 'month';
        var duesPeriod     = (typeof PnConfig !== 'undefined' && PnConfig.duesPeriod)     || 6;

        // Add N months or weeks to a YYYY-MM-DD string, returns YYYY-MM-DD
        function addPeriod(dateStr, n, unit) {
            var p = dateStr.split('-');
            if (p.length !== 3) return '';
            var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
            if (unit === 'week') {
                d.setDate(d.getDate() + n * 7);
            } else {
                d.setMonth(d.getMonth() + n);
            }
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
            var from = gid('pn-dues-from').value;
            var n    = parseInt(gid('pn-dues-months').value, 10);
            if (!from || isNaN(n) || n < 1) { el.textContent = ''; return; }
            var until = addPeriod(from, n, duesPeriodType);
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
            gid('pn-dues-months').value = duesPeriod;
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

        // Live preview wiring — if date is cleared, restore to today
        gid('pn-dues-from').addEventListener('change', function() {
            if (!this.value) {
                var t = new Date();
                this.value = t.getFullYear() + '-' +
                    String(t.getMonth() + 1).padStart(2, '0') + '-' +
                    String(t.getDate()).padStart(2, '0');
            }
            updateDuesPreview();
        });
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

    // ---- Dues History Modal (read-only, logged-in non-admins) ----
    (function() {
        if (!document.getElementById('pn-dues-history-overlay')) return;
        function gid(id) { return document.getElementById(id); }
        window.pnOpenDuesHistoryModal = function() {
            gid('pn-dues-history-overlay').classList.add('pn-open');
            document.body.style.overflow = 'hidden';
        };
        function close() {
            gid('pn-dues-history-overlay').classList.remove('pn-open');
            document.body.style.overflow = '';
        }
        gid('pn-dues-history-close-btn').addEventListener('click', close);
        gid('pn-dues-history-cancel').addEventListener('click', close);
        gid('pn-dues-history-overlay').addEventListener('click', function(e) {
            if (e.target === this) close();
        });
        document.addEventListener('keydown', function(e) {
            if ((e.key === 'Escape' || e.keyCode === 27) && gid('pn-dues-history-overlay').classList.contains('pn-open'))
                close();
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
                var val = (el.type === 'date' && el.value === '') ? '0000-00-00' : el.value;
                fd.append(el.name, val);
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
        var pnNoBadgeAwards = ['griffon', 'griffin', 'hydra', 'jovious', 'jovius', 'mask', 'zodiac', 'walker'];
        gid('pn-award-select').addEventListener('change', function() {
            var opt      = this.options[this.selectedIndex];
            var isLadder = (opt.getAttribute('data-is-ladder') == '1');
            var awardId  = parseInt(opt.getAttribute('data-award-id')) || 0;
            var isCustom = (opt.text.indexOf('Custom Award') !== -1);
            var optName  = opt.text.toLowerCase();
            var showBadge = isLadder && !pnNoBadgeAwards.some(function(n) { return optName.indexOf(n) !== -1; });

            gid('pn-award-custom-row').style.display  = isCustom ? '' : 'none';
            gid('pn-award-info-line').innerHTML        = showBadge
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
            var opt      = document.querySelector('#pn-award-select option[data-award-id="' + awardId + '"]');
            var maxRank  = /zodiac/i.test(opt ? opt.textContent : '') ? 12 : 10;
            var held      = playerRanks[awardId] || 0;
            var suggested = Math.min(held + 1, maxRank);
            var html = '';
            for (var i = 1; i <= maxRank; i++) {
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
                            return '<div class="pn-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                                + escHtml(p.Persona)
                                + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr || '') + ':' + escHtml(p.PAbbr || '') + ')</span>'
                                + '</div>';
                        }).join('');
                    }
                    results.classList.add('pn-ac-open');
                }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
            }, AUTOCOMPLETE_DEBOUNCE_MS);
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
                var today = new Date().toISOString().slice(0, 10);
                var url = SEARCH_URL + '?Action=Search%2FLocation&name=' + encodeURIComponent(term) + '&date=' + today + '&limit=8';
                fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                    var results = gid('pn-award-givenat-results');
                    if (!data || !data.length) {
                        results.innerHTML = '<div class="pn-ac-no-results">No locations found</div>';
                    } else {
                        results.innerHTML = data.map(function(loc) {
                            return '<div class="pn-ac-item" tabindex="-1"'
                                + ' data-park="' + (parseInt(loc.ParkId) || 0) + '"'
                                + ' data-kingdom="' + (parseInt(loc.KingdomId) || 0) + '"'
                                + ' data-event="' + (parseInt(loc.EventId) || 0) + '"'
                                + ' data-name="' + encodeURIComponent(loc.ShortName || loc.LocationName || '') + '">'
                                + escHtml(loc.LocationName || '') + '</div>';
                        }).join('');
                    }
                    results.classList.add('pn-ac-open');
                }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
            }, AUTOCOMPLETE_DEBOUNCE_MS);
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

        // Keyboard navigation for givenBy and givenAt autocompletes
        acKeyNav(gid('pn-award-givenby-text'), gid('pn-award-givenby-results'), 'pn-ac-open', '.pn-ac-item');
        acKeyNav(gid('pn-award-givenat-text'), gid('pn-award-givenat-results'), 'pn-ac-open', '.pn-ac-item');

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
            var rem = AWARD_NOTE_MAX_CHARS - this.value.length;
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
        var pnAwardsDirty = false;
        window.pnCloseAwardModal = function() {
            gid('pn-award-overlay').classList.remove('pn-open');
            document.body.style.overflow = '';
            if (pnAwardsDirty) { pnAwardsDirty = false; window.location.reload(); }
        };

        // Pre-populate the award modal from a recommendation row
        window.pnGiveFromRec = function(rec) {
            pnOpenAwardModal('awards');
            var sel = gid('pn-award-select');
            sel.value = String(rec.KingdomAwardId || '');
            sel.dispatchEvent(new Event('change'));
            if (rec.Rank) {
                setTimeout(function() { selectRankPill(parseInt(rec.Rank)); }, 0);
            }
            if (rec.Reason) {
                var noteEl = gid('pn-award-note');
                noteEl.value = rec.Reason;
                var rem = AWARD_NOTE_MAX_CHARS - rec.Reason.length;
                var cc = gid('pn-award-char-count');
                if (cc) {
                    cc.textContent = rem + ' characters remaining';
                    cc.classList.toggle('pn-char-warn', rem < 50);
                }
            }
        };
        document.addEventListener('click', function(e) {
            var link = e.target.closest ? e.target.closest('.pn-rec-give-link') : null;
            if (!link) return;
            e.preventDefault();
            try { window.pnGiveFromRec(JSON.parse(link.getAttribute('data-rec') || '{}')); } catch (ex) {}
        });

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
            gid('pn-award-givenat-text').value       = PnConfig.parkName;
            gid('pn-award-park-id').value            = String(PnConfig.parkId);
            gid('pn-award-kingdom-id').value         = '0';
            gid('pn-award-event-id').value           = '0';
            gid('pn-award-givenat-results').classList.remove('pn-ac-open');
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
            pnDoSave(function() { pnAwardsDirty = true; pnShowSuccess(); pnClearAward(); });
        });
    })();

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
var knMapLocations;
if (typeof KnConfig !== 'undefined') {
    knMapLocations = KnConfig.mapLocations;
}
var knMapLoaded = false;
var knCalLoaded = false;
var knCalendar  = null;
var knFilters   = { 'kingdom-event': true, 'park-event': true, 'park-day': false };
var knCalCache  = {}; // raw events keyed by "startISO|endISO" — avoids re-fetching on filter toggle

function knIsMobile() { return window.innerWidth <= 768; }

function knSetEventsView(view) {
    // Calendar view is disabled on mobile — always fall back to list
    if (view === 'calendar' && knIsMobile()) view = 'list';
    if (view === 'calendar') {
        $('#kn-events-list-view').hide();
        $('#kn-events-cal-wrap').show();
        $('#kn-ev-view-cal').addClass('kn-view-active');
        $('#kn-ev-view-list').removeClass('kn-view-active');
        knInitCalendar();
    } else {
        $('#kn-events-cal-wrap').hide();
        $('#kn-events-list-view').show();
        $('#kn-ev-view-list').addClass('kn-view-active');
        $('#kn-ev-view-cal').removeClass('kn-view-active');
    }
    try { localStorage.setItem('kn_events_view', view); } catch(e) {}
}

function knToggleFilter(btn, type) {
    knFilters[type] = !knFilters[type];
    var isOn = knFilters[type];
    $(btn).toggleClass('kn-filter-on', isOn);
    $('#kn-events-table').find('tr[data-type="' + type + '"]').css('display', isOn ? '' : 'none');
    knPaginate($('#kn-events-table'), 1);
    // [TOURNAMENTS HIDDEN] knPaginate($('#kn-tournaments-table'), 1);
    // Sync calendar — refetch re-runs our events function which re-applies knFilters from cache (no extra HTTP request)
    if (knCalendar) knCalendar.refetchEvents();
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

    var CALENDAR_URL = KnConfig.uir + 'KingdomAjax/calendar/' + KnConfig.kingdomId;

    knCalendar = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,listMonth'
        },
        height: 'auto',
        lazyFetching: true,
        loading: function(isLoading) {
            var spinner = document.getElementById('kn-cal-loading');
            if (spinner) spinner.style.display = isLoading ? 'flex' : 'none';
        },
        events: function(fetchInfo, successCallback, failureCallback) {
            var cacheKey = fetchInfo.startStr + '|' + fetchInfo.endStr;
            var raw = knCalCache[cacheKey];
            if (raw) {
                // Re-apply filter from cache — no HTTP request needed
                successCallback(raw.filter(function(e) {
                    if (e.type === 'park-day') return knFilters['park-day'];
                    if (e.type === 'park-event') return knFilters['park-event'];
                    return knFilters['kingdom-event'];
                }));
                return;
            }
            $.getJSON(CALENDAR_URL, { start: fetchInfo.startStr.slice(0, 10), end: fetchInfo.endStr.slice(0, 10) },
                function(data) {
                    if (data && data.status === 0) {
                        knCalCache[cacheKey] = data.events || [];
                        successCallback((data.events || []).filter(function(e) {
                            if (e.type === 'park-day') return knFilters['park-day'];
                            if (e.type === 'park-event') return knFilters['park-event'];
                            return knFilters['kingdom-event'];
                        }));
                    } else {
                        failureCallback((data && data.error) ? data.error : 'Failed to load events');
                    }
                }
            ).fail(function() { failureCallback('Network error loading events'); });
        },
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            if (info.event.url) window.location.href = info.event.url;
        },
        dayCellDidMount: function(info) {
            if (typeof KnConfig === 'undefined' || !KnConfig.loggedIn) return;
            var top = info.el.querySelector('.fc-daygrid-day-top');
            if (!top) return;
            var btn = document.createElement('button');
            btn.className = 'kn-cal-add-btn';
            btn.title = 'Create event';
            btn.innerHTML = '<i class="fas fa-plus"></i>';
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                e.preventDefault();
                var ds = info.dateStr || (function() {
                    var d = info.date;
                    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
                })();
                if (window.knOpenEventModal) window.knOpenEventModal(ds);
            });
            top.appendChild(btn);
        }
    });
    knCalendar.render();
}

// Defined globally so Google Maps API callback can find it
function knRenderMapSidebar(loc) {
    var hue = (getComputedStyle(document.documentElement).getPropertyValue('--kn-hue') || '210').trim();
    var sat = (getComputedStyle(document.documentElement).getPropertyValue('--kn-sat') || '60%').trim();
    var locLine = [loc.city, loc.province].filter(Boolean).join(', ');

    var profileUrl = '?Route=Park/profile/' + loc.id;
    var heraldryHtml = loc.heraldry
        ? '<a href="' + profileUrl + '"><img src="' + loc.heraldry + '" class="kn-park-heraldry" alt="' + loc.name + ' heraldry"></a>'
        : '<a href="' + profileUrl + '"><div class="kn-park-heraldry-placeholder"><i class="fas fa-shield-alt"></i></div></a>';
    var heroHtml = heraldryHtml
        + '<a href="' + profileUrl + '" class="kn-park-hero-name" style="text-decoration:none">' + escHtml(loc.name) + '</a>'
        + (locLine ? '<div class="kn-park-hero-location"><i class="fas fa-map-marker-alt" style="font-size:10px"></i>' + escHtml(locLine) + '</div>' : '');

    var bodyHtml = '';
    if (loc.dir) {
        bodyHtml += '<div class="kn-park-section">'
            + '<div class="kn-park-section-label"><i class="fas fa-directions" style="margin-right:4px"></i>Directions</div>'
            + '<p class="kn-park-section-text">' + loc.dir.replace(/\n/g, '<br>') + '</p>'
            + '</div>';
    }
    if (loc.desc) {
        if (bodyHtml) bodyHtml += '<hr class="kn-park-divider">';
        bodyHtml += '<div class="kn-park-section">'
            + '<div class="kn-park-section-label"><i class="fas fa-info-circle" style="margin-right:4px"></i>About</div>'
            + '<p class="kn-park-section-text">' + loc.desc.replace(/\n/g, '<br>') + '</p>'
            + '</div>';
    }
    bodyHtml += '<a href="?Route=Park/profile/' + loc.id + '" class="kn-park-profile-btn">'
        + '<i class="fas fa-external-link-alt"></i>View Park Profile</a>';

    var heroEl = document.getElementById('kn-park-hero');
    heroEl.innerHTML = heroHtml;
    heroEl.style.background = 'linear-gradient(135deg, hsl(' + hue + ',' + sat + ',28%), hsl(' + hue + ',' + sat + ',40%))';
    document.getElementById('kn-park-body').innerHTML = bodyHtml;

    document.getElementById('kn-map-sidebar-empty').style.display = 'none';
    var parkEl = document.getElementById('kn-map-sidebar-park');
    parkEl.style.display = 'flex';
}

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
            var pinGlyph = new PinElement({ scale: 0.7, background: '#8B1A1A', borderColor: '#B8860B', glyphColor: '#FFD700' });
            var marker = new google.maps.marker.AdvancedMarkerElement({
                position: new google.maps.LatLng(loc.lat, loc.lng),
                map: map,
                title: loc.name,
                content: pinGlyph.element
            });
            google.maps.event.addListener(marker, 'click', function() {
                var locLine = [loc.city, loc.province].filter(Boolean).join(', ');
                var tipHtml = '<b><a href="' + KnConfig.uir + 'Park/profile/' + loc.id + '" style="color:#2b6cb0">' + loc.name + '</a></b>'
                    + (locLine ? '<div style="font-size:12px;color:#718096;margin-top:3px"><i class="fas fa-map-marker-alt" style="font-size:10px;margin-right:3px"></i>' + locLine + '</div>' : '');
                infowindow.setContent(tipHtml);
                infowindow.open(map, marker);
                knRenderMapSidebar(loc);
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
    if (tab === 'recommendations') gtag('event', 'recommendation_view', { section: 'kingdom' });
    $('.kn-tab-nav li').removeClass('kn-tab-active');
    var $activeTab = $('.kn-tab-nav li[data-kntab="' + tab + '"]');
    $activeTab.addClass('kn-tab-active');
    $('.kn-tab-panel').hide();
    $('#kn-tab-' + tab).show();
    // Sync the mobile active-tab label below the icon strip
    var labelText = $activeTab.find('.kn-tab-label').text().trim();
    if (labelText) $('#kn-active-tab-label').text(labelText);
    if (tab === 'map' && !knMapLoaded && knMapLocations.length > 0) {
        knMapLoaded = true;
        var s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key=AIzaSyB_hIughnMCuRdutIvw_M_uwQUCREhHuI8&callback=knInitMap&v=weekly&libraries=marker';
        document.head.appendChild(s);
    }
    if (tab === 'events' && knCalendar) {
        knCalendar.updateSize();
    }
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
        var hDeg   = Math.round(h * 360);
        var sPct   = Math.round(finalS * 100);
        document.documentElement.style.setProperty('--kn-hue', hDeg);
        document.documentElement.style.setProperty('--kn-sat', sPct + '%');
        var heroEl = document.querySelector('.kn-hero');
        if (heroEl) {
            heroEl.style.backgroundColor =
                'hsl(' + hDeg + ',' + sPct + '%,18%)';
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


function knPaginate($table, page) {
    var pageSize = 25;
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

    // ---- Auto-activate tab from URL ?tab= param ----
    (function() {
        var urlTab = new URLSearchParams(window.location.search).get('tab');
        if (urlTab && $('.kn-tab-nav li[data-kntab="' + urlTab + '"]').length) {
            knActivateTab(urlTab);
        }
    })();

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

    // If viewport shrinks to mobile while calendar is visible, force list
    $(window).on('resize.knEventsView', function() {
        if (knIsMobile() && $('#kn-events-cal-wrap').is(':visible')) {
            knSetEventsView('list');
        }
    });

    // ---- Events filter toggles ----
    $(document).on('click', '.kn-filter-toggle', function() {
        knToggleFilter(this, $(this).data('filter'));
    });
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

    knSortAsc($('#kn-events-table'), 0, 'date');
    knPaginate($('#kn-events-table'), 1);

    // [TOURNAMENTS HIDDEN] knSortDesc($('#kn-tournaments-table'), 0, 'date');
    // [TOURNAMENTS HIDDEN] knPaginate($('#kn-tournaments-table'), 1);

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
    var knPlayerRanks = {};

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
        var baseAwardId = parseInt(opt.getAttribute('data-award-id')) || 0;
        var maxRank   = /zodiac/i.test(opt.textContent) ? 12 : 10;
        var held      = knPlayerRanks[baseAwardId] || 0;
        var suggested = Math.min(held + 1, maxRank);
        for (var r = 1; r <= maxRank; r++) {
            var pill = document.createElement('button');
            pill.type      = 'button';
            pill.className = 'kn-rank-pill';
            if (r <= held)       pill.className += ' kn-rank-held';
            if (r === suggested) pill.className += ' kn-rank-suggested';
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
        var suggestedPill = wrap.querySelector('[data-rank="' + suggested + '"]');
        if (suggestedPill) { suggestedPill.classList.add('kn-rank-selected'); input.value = suggested; }
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

    // Player search autocomplete (kingdom members prioritized)
    gid('kn-award-player-text').addEventListener('input', function() {
        gid('kn-award-player-id').value = '';
        checkRequired();
        var term = this.value.trim();
        if (term.length < 2) { gid('kn-award-player-results').classList.remove('kn-ac-open'); return; }
        clearTimeout(playerTimer);
        playerTimer = setTimeout(function() {
            var url = UIR_JS + 'KingdomAjax/playersearch/' + KINGDOM_ID + '&q=' + encodeURIComponent(term);
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('kn-award-player-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(p) {
                        return '<div class="kn-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                            + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr||'') + ':' + escHtml(p.PAbbr||'') + ')</span></div>';
                    }).join('')
                    : '<div class="kn-ac-item" style="color:#a0aec0;cursor:default">No players found</div>';
                el.classList.add('kn-ac-open');
            }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
        }, AUTOCOMPLETE_DEBOUNCE_MS);
    });
    gid('kn-award-player-results').addEventListener('click', function(e) {
        var item = e.target.closest('.kn-ac-item[data-id]');
        if (!item) return;
        gid('kn-award-player-text').value = decodeURIComponent(item.dataset.name);
        gid('kn-award-player-id').value   = item.dataset.id;
        this.classList.remove('kn-ac-open');
        checkRequired();
        knPlayerRanks = {};
        var pid = item.dataset.id;
        fetch(UIR_JS + 'PlayerAjax/player/' + pid + '/awardranks')
            .then(function(r) { return r.json(); })
            .then(function(ranks) {
                knPlayerRanks = ranks || {};
                var curAward = gid('kn-award-select').value;
                if (curAward) buildRankPills(curAward);
            }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
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
                        return '<div class="kn-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                            + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr||'') + ':' + escHtml(p.PAbbr||'') + ')</span></div>';
                    }).join('')
                    : '<div class="kn-ac-item" style="color:#a0aec0;cursor:default">No results</div>';
                el.classList.add('kn-ac-open');
            }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
        }, AUTOCOMPLETE_DEBOUNCE_MS);
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
            var today = new Date().toISOString().slice(0, 10);
            var url = SEARCH_URL + '?Action=Search%2FLocation&name=' + encodeURIComponent(term) + '&date=' + today + '&limit=6';
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('kn-award-givenat-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(loc) {
                        return '<div class="kn-ac-item" tabindex="-1" data-pid="' + (loc.ParkId||0) + '" data-kid="' + (loc.KingdomId||0) + '" data-eid="' + (loc.EventId||0) + '" data-name="' + encodeURIComponent(loc.LocationName||loc.ShortName||'') + '">'
                            + escHtml(loc.LocationName || loc.ShortName || '') + '</div>';
                    }).join('')
                    : '<div class="kn-ac-item" style="color:#a0aec0;cursor:default">No locations found</div>';
                el.classList.add('kn-ac-open');
            }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
        }, AUTOCOMPLETE_DEBOUNCE_MS);
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

    // Keyboard navigation for givenBy and givenAt autocompletes
    acKeyNav(gid('kn-award-player-text'),  gid('kn-award-player-results'),  'kn-ac-open', '.kn-ac-item');
    acKeyNav(gid('kn-award-givenby-text'), gid('kn-award-givenby-results'), 'kn-ac-open', '.kn-ac-item');
    acKeyNav(gid('kn-award-givenat-text'), gid('kn-award-givenat-results'), 'kn-ac-open', '.kn-ac-item');

    // Note char counter
    gid('kn-award-note').addEventListener('input', function() {
        var rem = AWARD_NOTE_MAX_CHARS - this.value.length;
        var el  = gid('kn-award-char-count');
        el.textContent = rem + ' character' + (rem !== 1 ? 's' : '') + ' remaining';
    });

    gid('kn-award-select').addEventListener('change', checkRequired);
    gid('kn-award-date').addEventListener('change', checkRequired);
    gid('kn-award-date').addEventListener('input',  checkRequired);

    // ---- Open / Close ----
    window.knOpenAwardModal = function() {
        var today = new Date();
        knPlayerRanks = {};
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
        gtag('event', 'award_entry_open', { section: 'kingdom' });
        gid('kn-award-overlay').classList.add('kn-open');
        document.body.style.overflow = 'hidden';
        gid('kn-award-player-text').focus();
    };
    window.knCloseAwardModal = function() {
        gid('kn-award-overlay').classList.remove('kn-open');
        document.body.style.overflow = '';
        knActiveRecId = null;
    };

    // Track the recommendation that triggered the current award modal open
    var knActiveRecId = null;

    // Pre-populate award modal from a recommendation row
    window.knGiveFromRec = function(rec) {
        gtag('event', 'recommendation_give');
        knOpenAwardModal();
        if (rec.Persona || rec.MundaneId) {
            gid('kn-award-player-text').value = rec.Persona || '';
            gid('kn-award-player-id').value   = String(rec.MundaneId || '');
        }
        if (rec.KingdomAwardId) {
            var sel = gid('kn-award-select');
            sel.value = String(rec.KingdomAwardId);
            sel.dispatchEvent(new Event('change'));
        }
        if (rec.Rank) {
            setTimeout(function() {
                var pill = document.querySelector('#kn-rank-pills .kn-rank-pill[data-rank="' + rec.Rank + '"]');
                if (pill) pill.click();
            }, 0);
        }
        if (rec.Reason) {
            var noteEl = gid('kn-award-note');
            noteEl.value = rec.Reason;
            var rem = AWARD_NOTE_MAX_CHARS - rec.Reason.length;
            var cc = gid('kn-award-char-count');
            if (cc) { cc.textContent = rem + ' characters remaining'; cc.classList.toggle('kn-char-warn', rem < 50); }
        }
        checkRequired();
        knActiveRecId = rec.RecommendationsId || null;
    };

    function knAutoDismissRec() {
        var id = knActiveRecId;
        knActiveRecId = null;
        if (!id) return;
        var row = document.querySelector('#kn-recs-tbody .pk-rec-row[data-rec-id="' + id + '"]');
        var fd = new FormData();
        fd.append('RecommendationsId', id);
        fetch(KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/dismissrecommendation', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.status === 0 && row) { row.classList.add('pk-rec-dismissed'); setTimeout(function() { row.remove(); }, 600); }
            })
            .catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] autoDismissRec failed:', err); });
    }

    // Recommendations tab: grant + dismiss
    document.addEventListener('click', function(e) {
        var grantBtn = e.target.closest ? e.target.closest('.pk-rec-grant-btn') : null;
        if (grantBtn && grantBtn.closest('#kn-tab-recommendations')) {
            try { window.knGiveFromRec(JSON.parse(grantBtn.getAttribute('data-rec') || '{}')); } catch(ex) {}
            return;
        }
        var dimBtn = e.target.closest ? e.target.closest('.pk-rec-dismiss-btn') : null;
        if (dimBtn && dimBtn.closest('#kn-tab-recommendations')) {
            if (!dimBtn.dataset.confirm) {
                dimBtn.dataset.confirm = '1';
                dimBtn.textContent = 'Confirm Dismiss?';
                dimBtn.classList.add('pk-rec-dismiss-confirm');
                dimBtn._confirmTimer = setTimeout(function() {
                    dimBtn.dataset.confirm = '';
                    dimBtn.textContent = 'Dismiss';
                    dimBtn.classList.remove('pk-rec-dismiss-confirm');
                }, 3000);
                return;
            }
            clearTimeout(dimBtn._confirmTimer);
            dimBtn.dataset.confirm = '';
            var recId = dimBtn.getAttribute('data-rec-id');
            var row   = dimBtn.closest('.pk-rec-row');
            var fd = new FormData();
            fd.append('RecommendationsId', recId);
            fetch(KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/dismissrecommendation', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.status === 0) {
                        gtag('event', 'recommendation_dismiss', { section: 'kingdom' });
                        if (row) { row.classList.add('pk-rec-dismissed'); setTimeout(function() { row.remove(); }, 600); }
                    } else {
                        alert(d.error || 'Failed to dismiss recommendation.');
                    }
                })
                .catch(function() { alert('Network error.'); });
        }
    });

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
                btnNew.innerHTML  = '<i class="fas fa-plus"></i> <span class="award-btn-prefix">Add + </span>New Player';
                btnSame.innerHTML = '<i class="fas fa-plus"></i> <span class="award-btn-prefix">Add + </span>Same Player';
                checkRequired();
            });
    }

    // "Add + New Player" — clear player + award/rank/note, keep date/giver/location
    gid('kn-award-save-new').addEventListener('click', function() {
        knDoSave(function() { knAutoDismissRec(); knShowSuccess(); knClearPlayer(); knClearAward(); gid('kn-award-player-text').focus(); });
    });
    // "Add + Same Player" — clear only award/rank/note, keep player + date/giver/location
    gid('kn-award-save-same').addEventListener('click', function() {
        knDoSave(function() {
            knAutoDismissRec(); knShowSuccess(); knClearAward();
            var pid = gid('kn-award-player-id').value;
            if (pid) {
                knPlayerRanks = {};
                fetch(UIR_JS + 'PlayerAjax/player/' + pid + '/awardranks')
                    .then(function(r) { return r.json(); })
                    .then(function(ranks) {
                        knPlayerRanks = ranks || {};
                        var curAward = gid('kn-award-select').value;
                        if (curAward) buildRankPills(curAward);
                    }).catch(function() {});
            }
            gid('kn-award-select').focus();
        });
    });
})();
(function() {
    if (!document.getElementById('kn-rec-overlay')) return;
    var UIR_JS     = KnConfig.uir;
    var KINGDOM_ID = KnConfig.kingdomId;
    var playerTimer;
    var knRecRanks = {};

    function gid(id) { return document.getElementById(id); }

    function checkRequired() {
        var ok = !!gid('kn-rec-player-id').value
              && !!gid('kn-rec-award-select').value
              && !!gid('kn-rec-reason').value.trim();
        gid('kn-rec-submit').disabled = !ok;
    }

    function buildRecRankPills(awardId) {
        var row   = gid('kn-rec-rank-row');
        var wrap  = gid('kn-rec-rank-pills');
        var input = gid('kn-rec-rank-val');
        wrap.innerHTML = '';
        input.value = '';
        row.style.display = 'none';
        if (!awardId) return;
        var opt = gid('kn-rec-award-select').querySelector('option[value="' + awardId + '"]');
        if (!opt || opt.getAttribute('data-is-ladder') !== '1') return;
        row.style.display = '';
        var baseAwardId = parseInt(opt.getAttribute('data-award-id')) || 0;
        var maxRank   = /zodiac/i.test(opt.textContent) ? 12 : 10;
        var held      = knRecRanks[baseAwardId] || 0;
        var suggested = Math.min(held + 1, maxRank);
        for (var r = 1; r <= maxRank; r++) {
            var pill = document.createElement('button');
            pill.type = 'button';
            pill.className = 'pk-rank-pill';
            if (r <= held)       pill.className += ' pk-rank-held';
            if (r === suggested) pill.className += ' pk-rank-suggested';
            pill.textContent = r;
            pill.dataset.rank = r;
            pill.addEventListener('click', (function(rank, el) {
                return function() {
                    wrap.querySelectorAll('.pk-rank-pill').forEach(function(p) { p.classList.remove('pk-rank-selected'); });
                    el.classList.add('pk-rank-selected');
                    input.value = rank;
                };
            })(r, pill));
            wrap.appendChild(pill);
        }
        var suggestedPill = wrap.querySelector('[data-rank="' + suggested + '"]');
        if (suggestedPill) { suggestedPill.classList.add('pk-rank-selected'); input.value = suggested; }
    }

    gid('kn-rec-award-select').addEventListener('change', function() {
        buildRecRankPills(this.value);
        checkRequired();
    });

    gid('kn-rec-player-text').addEventListener('input', function() {
        gid('kn-rec-player-id').value = '';
        checkRequired();
        var term = this.value.trim();
        clearTimeout(playerTimer);
        if (term.length < 2) { gid('kn-rec-player-results').classList.remove('pk-ac-open'); return; }
        playerTimer = setTimeout(function() {
            var url = UIR_JS + 'KingdomAjax/playersearch/' + KINGDOM_ID + '&q=' + encodeURIComponent(term);
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('kn-rec-player-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(p) {
                        return '<div class="pk-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                            + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr||'') + ':' + escHtml(p.PAbbr||'') + ')</span></div>';
                    }).join('')
                    : '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No players found</div>';
                el.classList.add('pk-ac-open');
            }).catch(function() {});
        }, 300);
    });
    gid('kn-rec-player-results').addEventListener('click', function(e) {
        var item = e.target.closest('.pk-ac-item[data-id]');
        if (!item) return;
        gid('kn-rec-player-text').value = decodeURIComponent(item.dataset.name);
        gid('kn-rec-player-id').value   = item.dataset.id;
        this.classList.remove('pk-ac-open');
        knRecRanks = {};
        fetch(UIR_JS + 'PlayerAjax/player/' + item.dataset.id + '/awardranks')
            .then(function(r) { return r.json(); })
            .then(function(ranks) {
                knRecRanks = ranks || {};
                var cur = gid('kn-rec-award-select').value;
                if (cur) buildRecRankPills(cur);
            }).catch(function() {});
        checkRequired();
    });
    acKeyNav(gid('kn-rec-player-text'), gid('kn-rec-player-results'), 'pk-ac-open', '.pk-ac-item[data-id]');

    gid('kn-rec-reason').addEventListener('input', function() {
        var rem = 400 - this.value.length;
        gid('kn-rec-char-count').textContent = rem + ' characters remaining';
        checkRequired();
    });

    window.knOpenRecModal = function() {
        gid('kn-rec-error').style.display   = 'none';
        gid('kn-rec-success').style.display = 'none';
        gid('kn-rec-player-text').value     = '';
        gid('kn-rec-player-id').value       = '';
        gid('kn-rec-player-results').classList.remove('pk-ac-open');
        gid('kn-rec-award-select').value    = '';
        gid('kn-rec-rank-row').style.display = 'none';
        gid('kn-rec-rank-val').value        = '';
        gid('kn-rec-rank-pills').innerHTML  = '';
        gid('kn-rec-reason').value          = '';
        gid('kn-rec-char-count').textContent = '400 characters remaining';
        knRecRanks = {};
        checkRequired();
        gid('kn-rec-overlay').classList.add('kn-open');
        document.body.style.overflow = 'hidden';
        gid('kn-rec-player-text').focus();
    };
    function knCloseRecModal() {
        gid('kn-rec-overlay').classList.remove('kn-open');
        document.body.style.overflow = '';
    }

    gid('kn-rec-close-btn').addEventListener('click', knCloseRecModal);
    gid('kn-rec-cancel').addEventListener('click',    knCloseRecModal);
    gid('kn-rec-overlay').addEventListener('click', function(e) { if (e.target === this) knCloseRecModal(); });

    gid('kn-rec-submit').addEventListener('click', function() {
        var errEl = gid('kn-rec-error');
        var btn   = this;
        errEl.style.display = 'none';
        var fd = new FormData();
        fd.append('MundaneId',      gid('kn-rec-player-id').value);
        fd.append('KingdomAwardId', gid('kn-rec-award-select').value);
        fd.append('Reason',         gid('kn-rec-reason').value.trim());
        var rank = gid('kn-rec-rank-val').value;
        if (rank) fd.append('Rank', rank);
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        fetch(UIR_JS + 'KingdomAjax/kingdom/' + KINGDOM_ID + '/addrecommendation', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0) {
                    gid('kn-rec-success').style.display = '';
                    gid('kn-rec-player-text').value = '';
                    gid('kn-rec-player-id').value   = '';
                    gid('kn-rec-award-select').value = '';
                    gid('kn-rec-rank-row').style.display = 'none';
                    gid('kn-rec-rank-val').value = '';
                    gid('kn-rec-rank-pills').innerHTML = '';
                    gid('kn-rec-reason').value = '';
                    gid('kn-rec-char-count').textContent = '400 characters remaining';
                    knRecRanks = {};
                    setTimeout(function() { gid('kn-rec-success').style.display = 'none'; }, 3000);
                } else {
                    errEl.textContent = data.error || 'Save failed.';
                    errEl.style.display = '';
                }
            })
            .catch(function() {
                errEl.textContent = 'Request failed. Please try again.';
                errEl.style.display = '';
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Recommendation';
                checkRequired();
            });
    });
})();
(function() {
    if (typeof KnConfig === 'undefined') return;

    var CREATE_URL = KnConfig.uir + 'EventAjax/create';

    function knEvFeedback(msg) {
        var el = document.getElementById('kn-emod-feedback');
        el.textContent = msg; el.style.display = '';
    }

    window.knOpenEventModal = function(dateStr) {
        var modal = document.getElementById('kn-event-modal');
        modal.dataset.presetDate = dateStr || '';
        document.getElementById('kn-event-name').value     = '';
        document.getElementById('kn-event-park-name').value = '';
        document.getElementById('kn-event-park-id').value   = '';
        document.getElementById('kn-emod-feedback').style.display = 'none';
        document.getElementById('kn-emod-go-btn').disabled  = true;
        var dateRow  = document.getElementById('kn-emod-date-row');
        var dateText = document.getElementById('kn-emod-date-text');
        if (dateRow && dateText) {
            if (dateStr) {
                var d = new Date(dateStr + 'T00:00:00');
                dateText.textContent = d.toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric' });
                dateRow.style.display = '';
            } else {
                dateRow.style.display = 'none';
            }
        }
        modal.classList.add('kn-emod-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { document.getElementById('kn-event-name').focus(); }, 50);
    };

    window.knCloseEventModal = function() {
        document.getElementById('kn-event-modal').classList.remove('kn-emod-open');
        document.body.style.overflow = '';
    };

    window.knCreateEvent = function() {
        var name   = document.getElementById('kn-event-name').value.trim();
        var parkId = parseInt(document.getElementById('kn-event-park-id').value) || 0;
        if (!name) return;
        var btn = document.getElementById('kn-emod-go-btn');
        btn.disabled = true;
        $.post(CREATE_URL, { Name: name, KingdomId: KnConfig.kingdomId, ParkId: parkId },
            function(r) {
                if (r && r.status === 0) {
                    var presetDate = document.getElementById('kn-event-modal').dataset.presetDate || '';
                    var url = KnConfig.uir + 'Event/create/' + r.eventId;
                    if (parkId > 0) url += '/' + parkId;
                    if (presetDate) url += '&date=' + encodeURIComponent(presetDate);
                    window.location.href = url;
                } else {
                    knEvFeedback((r && r.error) ? r.error : 'Failed to create event.');
                    btn.disabled = false;
                }
            }, 'json'
        ).fail(function() { knEvFeedback('Request failed. Please try again.'); btn.disabled = false; });
    };

    $(document).ready(function() {
        $('#kn-event-name').on('input', function() {
            document.getElementById('kn-emod-go-btn').disabled = !this.value.trim();
        }).on('keydown', function(e) {
            if (e.key === 'Enter' && !document.getElementById('kn-emod-go-btn').disabled) knCreateEvent();
        });

        $('#kn-event-park-name').autocomplete({
            source: function(req, res) {
                $.getJSON(KnConfig.httpService + 'Search/SearchService.php',
                    { Action: 'Search/Park', name: req.term, kingdom_id: KnConfig.kingdomId, limit: 8 },
                    function(data) { res($.map(data || [], function(v) { return { label: v.Name, value: v.ParkId }; })); }
                );
            },
            focus:  function(e, ui) { $('#kn-event-park-name').val(ui.item.label); return false; },
            select: function(e, ui) { $('#kn-event-park-name').val(ui.item.label); $('#kn-event-park-id').val(ui.item.value); return false; },
            change: function(e, ui) { if (!ui.item) $('#kn-event-park-id').val(''); return false; },
            delay: 250, minLength: 2
        });

        var knEvtOverlay = document.getElementById('kn-event-modal');
        if (knEvtOverlay) {
            knEvtOverlay.addEventListener('click', function(e) { if (e.target === this) knCloseEventModal(); });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('kn-event-modal') &&
                document.getElementById('kn-event-modal').classList.contains('kn-emod-open')) knCloseEventModal();
        });
    });
})();

// ---- Add Park Modal ----
(function() {
    if (typeof KnConfig === 'undefined') return;
    if (!KnConfig.canAddPark) return;

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
        var addWarn = gid('kn-addpark-abbr-warn');
        if (addWarn) addWarn.style.display = 'none';
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
                        window.location.href = KnConfig.uir + 'Park/profile/' + r.parkId;
                    }, 1000);
                } else {
                    knAddParkShowFeedback((r && r.error) ? r.error : 'Creation failed. Please try again.', false);
                }
            }, 'json').fail(function() {
                btn.disabled = false;
                knAddParkShowFeedback('Request failed. Please try again.', false);
            });
        });

        var addAbbrTimer = null;
        var addAbbrEl = gid('kn-addpark-abbr');
        if (addAbbrEl) {
            addAbbrEl.addEventListener('input', function() {
                var warn = gid('kn-addpark-abbr-warn');
                clearTimeout(addAbbrTimer);
                var abbr = this.value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
                if (!abbr) { if (warn) warn.style.display = 'none'; return; }
                addAbbrTimer = setTimeout(function() {
                    $.post(KnConfig.uir + 'ParkAjax/kingdom/' + KnConfig.kingdomId + '/checkabbr',
                        { Abbreviation: abbr },
                        function(r) {
                            if (warn) {
                                warn.style.display = r.taken ? '' : 'none';
                                if (r.taken) warn.textContent = '\u26a0\ufe0f "' + abbr + '" is already used by another park in this kingdom.';
                            }
                        }, 'json');
                }, 400);
            });
        }

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
    var editParkCurrentId = 0;

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

        editParkCurrentId = park.ParkId;
        idEl.value = park.ParkId;
        gid('kn-editpark-name').value  = park.Name;
        gid('kn-editpark-abbr').value  = park.Abbreviation;
        gid('kn-editpark-title').value = park.ParkTitleId;
        gid('kn-editpark-active').checked = (park.Active === 'Active');
        var editWarn = gid('kn-editpark-abbr-warn');
        if (editWarn) editWarn.style.display = 'none';
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

        var editAbbrTimer = null;
        var editAbbrEl = gid('kn-editpark-abbr');
        if (editAbbrEl) {
            editAbbrEl.addEventListener('input', function() {
                var warn = gid('kn-editpark-abbr-warn');
                clearTimeout(editAbbrTimer);
                var abbr = this.value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
                if (!abbr) { if (warn) warn.style.display = 'none'; return; }
                editAbbrTimer = setTimeout(function() {
                    $.post(KnConfig.uir + 'ParkAjax/kingdom/' + KnConfig.kingdomId + '/checkabbr',
                        { Abbreviation: abbr, ExcludeParkId: editParkCurrentId },
                        function(r) {
                            if (warn) {
                                warn.style.display = r.taken ? '' : 'none';
                                if (r.taken) warn.textContent = '\u26a0\ufe0f "' + abbr + '" is already used by another park in this kingdom.';
                            }
                        }, 'json');
                }, 400);
            });
        }

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
                    pnConfirm({
                        title: 'Vacate Position?',
                        message: 'Remove the current ' + r + '?',
                        confirmText: 'Vacate',
                        danger: true
                    }, function() {
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
        if (ok) {
            clearTimeout(el._hideTimer);
            el._hideTimer = setTimeout(function() { el.style.display = 'none'; }, 5000);
        }
    }
    function clearFeedback(elId) {
        var el = gid(elId);
        if (el) { el.style.display = 'none'; el.textContent = ''; }
    }

    // ── Open / Close ──────────────────────────────────────────
    var _knDirty = false;

    window.knOpenAdminModal = function() {
        var overlay = gid('kn-admin-overlay');
        if (!overlay) return;
        _knDirty = false;
        buildConfig();
        buildTitles();
        buildAwards();
        buildParks();
        overlay.classList.add('kn-open');
        document.body.style.overflow = 'hidden';
    };
    function knCloseAdminModal() {
        var overlay = gid('kn-admin-overlay');
        if (!overlay) return;
        overlay.classList.remove('kn-open');
        document.body.style.overflow = '';
        if (_knDirty) { _knDirty = false; setTimeout(function() { location.reload(); }, 0); }
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

        function checkDetailsReady() {
            var name = (gid('kn-admin-name').value || '').trim();
            var abbr = (gid('kn-admin-abbr').value || '').replace(/[^A-Za-z0-9]/g, '');
            btn.disabled = !(name && abbr);
        }
        gid('kn-admin-name').addEventListener('input', checkDetailsReady);
        gid('kn-admin-abbr').addEventListener('input', checkDetailsReady);

        var abbrWarnTimer = null;
        var abbrInput = gid('kn-admin-abbr');
        if (abbrInput) {
            abbrInput.addEventListener('input', function() {
                var warn = gid('kn-admin-abbr-warn');
                clearTimeout(abbrWarnTimer);
                var abbr = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
                if (!abbr) { if (warn) warn.style.display = 'none'; return; }
                abbrWarnTimer = setTimeout(function() {
                    var fd = new FormData();
                    fd.append('Abbreviation', abbr);
                    fd.append('ExcludeKingdomId', KnConfig.kingdomId);
                    fetch(KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/checkabbr', { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(r) {
                            if (!warn) return;
                            if (r.taken) {
                                warn.textContent = '\u26a0\ufe0f "' + abbr + '" is already used by ' + r.name + '.';
                                warn.style.display = '';
                            } else {
                                warn.style.display = 'none';
                            }
                        });
                }, 400);
            });
        }

        btn.addEventListener('click', function() {
            clearFeedback('kn-admin-details-feedback');
            var name = (gid('kn-admin-name').value || '').trim();
            var abbr = (gid('kn-admin-abbr').value || '').replace(/[^A-Za-z0-9]/g, '');
            if (!name) { feedback('kn-admin-details-feedback', 'Kingdom name is required.', false); return; }
            if (!abbr) { feedback('kn-admin-details-feedback', 'Abbreviation is required.', false); return; }

            var fd = new FormData();
            fd.append('Name',         name);
            fd.append('Abbreviation', abbr);

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
                        _knDirty = true;
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
            var keyLabels = { 'AwardRecsPublic': 'Award Recommendations Visibility' };
            lbl.textContent = keyLabels[cfg.Key] || cfg.Key;
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
                var inp;
                if (cfg.Key === 'AwardRecsPublic') {
                    inp = document.createElement('select');
                    inp.className = 'kn-admin-config-input kn-admin-tselect';
                    var optPublic  = document.createElement('option');
                    optPublic.value = '1'; optPublic.textContent = 'Public (anyone can see)';
                    var optPrivate = document.createElement('option');
                    optPrivate.value = '0'; optPrivate.textContent = 'Officers only';
                    if (String(val) === '1') optPublic.selected = true;
                    else optPrivate.selected = true;
                    inp.appendChild(optPublic);
                    inp.appendChild(optPrivate);
                } else {
                    inp = document.createElement('input');
                    inp.type  = (cfg.Type === 'color')  ? 'color'
                              : (cfg.Type === 'number') ? 'number' : 'text';
                    inp.value = (val !== null && val !== undefined) ? val : '';
                    if (cfg.Type === 'number') inp.style.width = '80px';
                }
                inp.className        = 'kn-admin-config-input';
                inp.dataset.configId = cfg.ConfigurationId;
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
            var recsEl = gid('kn-admin-recs-public');
            var recsVal = recsEl ? recsEl.value : null;
            if (!Object.keys(data).length && recsVal === null) { feedback('kn-admin-config-feedback', 'No configuration fields found.', false); return; }
            btn.disabled = true;
            function saveRecs(cb) {
                if (recsVal === null) { cb(true, null); return; }
                $.post(BASE_URL + 'setrecsvisibility', { Value: recsVal }, function(r2) {
                    cb(r2 && r2.status === 0, (r2 && r2.error) ? r2.error : 'Visibility save failed.');
                }, 'json').fail(function() { cb(false, 'Visibility request failed.'); });
            }
            if (Object.keys(data).length) {
                $.post(BASE_URL + 'setconfig', data, function(r) {
                    if (r && r.status === 0) {
                        saveRecs(function(ok, err) {
                            btn.disabled = false;
                            if (ok) feedback('kn-admin-config-feedback', 'Configuration saved!', true);
                            else feedback('kn-admin-config-feedback', err, false);
                        });
                    } else {
                        btn.disabled = false;
                        feedback('kn-admin-config-feedback', (r && r.error) ? r.error : 'Save failed.', false);
                    }
                }, 'json').fail(function() { btn.disabled = false; feedback('kn-admin-config-feedback', 'Request failed.', false); });
            } else {
                saveRecs(function(ok, err) {
                    btn.disabled = false;
                    if (ok) feedback('kn-admin-config-feedback', 'Configuration saved!', true);
                    else feedback('kn-admin-config-feedback', err, false);
                });
            }
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
                    _knDirty = true;
                    document.querySelectorAll('#kn-admin-titles-table tfoot [data-field]').forEach(function(inp) {
                        inp.value = (inp.dataset.field === 'Length') ? '1' : (inp.type === 'number' ? '0' : '');
                    });
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

    // ── Section: Operations ──────────────────────────────────
    function wireOps() {
        var btn = gid('kn-admin-reset-waivers-btn');
        if (!btn) return;
        btn.addEventListener('click', function() {
            knConfirm(
                'This will reset all waivers for this kingdom. This action cannot be undone.',
                function() {
                    btn.disabled = true;
                    $.post(BASE_URL + 'resetwaivers', {}, function(r) {
                        btn.disabled = false;
                        if (r && r.status === 0) {
                            feedback('kn-admin-ops-feedback', r.message || 'Waivers reset.', true);
                        } else {
                            feedback('kn-admin-ops-feedback', (r && r.error) ? r.error : 'Reset failed.', false);
                        }
                    }, 'json').fail(function() {
                        btn.disabled = false;
                        feedback('kn-admin-ops-feedback', 'Request failed.', false);
                    });
                },
                'Reset Waivers'
            );
        });
    }

    // ── Section: Edit Parks ──────────────────────────────────
    var parksBuilt = false;
    function buildParks() {
        if (parksBuilt) return;
        parksBuilt = true;
        var tbody = gid('kn-admin-parks-tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        var parks = Object.values(KnConfig.parkEditLookup || {});
        parks.sort(function(a, b) { return (a.Name || '').localeCompare(b.Name || ''); });
        parks.forEach(function(park) {
            tbody.appendChild(makeParkRow(park));
        });
    }

    function makeParkRow(park) {
        var tr = document.createElement('tr');
        tr.dataset.parkId = park.ParkId;
        if (park.Active !== 'Active') tr.classList.add('kn-admin-park-retired');

        // Name
        var nameTd  = document.createElement('td');
        var nameInp = document.createElement('input');
        nameInp.type      = 'text';
        nameInp.className = 'kn-admin-tinput kn-admin-park-name';
        nameInp.value     = park.Name || '';
        nameInp.dataset.field = 'ParkName';
        nameTd.appendChild(nameInp);

        // Title select
        var titleTd = document.createElement('td');
        var sel     = document.createElement('select');
        sel.className       = 'kn-admin-tselect';
        sel.dataset.field   = 'ParkTitle';
        var opts = KnConfig.parkTitleOptions || {};
        Object.keys(opts).forEach(function(tid) {
            var o = document.createElement('option');
            o.value = tid; o.textContent = opts[tid];
            if (parseInt(tid) === park.ParkTitleId) o.selected = true;
            sel.appendChild(o);
        });
        titleTd.appendChild(sel);

        // Abbreviation
        var abbrTd  = document.createElement('td');
        var abbrInp = document.createElement('input');
        abbrInp.type      = 'text';
        abbrInp.className = 'kn-admin-tinput kn-admin-park-abbr';
        abbrInp.value     = park.Abbreviation || '';
        abbrInp.maxLength = 3;
        abbrInp.dataset.field = 'Abbreviation';
        var abbrWarn = document.createElement('span');
        abbrWarn.textContent = '\u26a0\ufe0f';
        abbrWarn.style.cssText = 'visibility:hidden;color:#c05621;margin-left:4px;cursor:default';
        abbrWarn.title = '';
        abbrTd.appendChild(abbrInp);
        abbrTd.appendChild(abbrWarn);
        (function(inp, warn, pid) {
            var t = null;
            inp.addEventListener('input', function() {
                clearTimeout(t);
                var abbr = inp.value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
                if (!abbr) { warn.style.visibility = 'hidden'; return; }
                t = setTimeout(function() {
                    $.post(KnConfig.uir + 'ParkAjax/kingdom/' + KnConfig.kingdomId + '/checkabbr',
                        { Abbreviation: abbr, ExcludeParkId: pid },
                        function(r) {
                            warn.style.visibility = r.taken ? 'visible' : 'hidden';
                            warn.title = r.taken ? '"' + abbr + '" is already used by another park in this kingdom.' : '';
                        }, 'json');
                }, 400);
            });
        })(abbrInp, abbrWarn, park.ParkId);

        // Active toggle
        var activeTd  = document.createElement('td');
        activeTd.style.textAlign = 'center';
        var label   = document.createElement('label');
        label.className = 'kn-admin-toggle';
        var chk     = document.createElement('input');
        chk.type    = 'checkbox';
        chk.checked = (park.Active === 'Active');
        chk.dataset.field = 'Active';
        chk.addEventListener('change', function() {
            tr.classList.toggle('kn-admin-park-retired', !chk.checked);
        });
        var track   = document.createElement('span');
        track.className = 'kn-admin-toggle-track';
        label.appendChild(chk);
        label.appendChild(track);
        activeTd.appendChild(label);

        // View link
        var viewTd  = document.createElement('td');
        var viewA   = document.createElement('a');
        viewA.href  = KnConfig.uir + 'Park/profile/' + park.ParkId;
        viewA.target = '_blank';
        viewA.className = 'kn-admin-park-view';
        viewA.title = 'View ' + (park.Name || '');
        viewA.innerHTML = '<i class="fas fa-external-link-alt"></i>';
        viewTd.appendChild(viewA);

        tr.appendChild(nameTd);
        tr.appendChild(titleTd);
        tr.appendChild(abbrTd);
        tr.appendChild(activeTd);
        tr.appendChild(viewTd);
        return tr;
    }

    function wireParks() {
        var btn = gid('kn-admin-parks-save');
        if (!btn) return;
        btn.addEventListener('click', function() {
            clearFeedback('kn-admin-parks-feedback');
            var parks = [];
            document.querySelectorAll('#kn-admin-parks-tbody tr').forEach(function(row) {
                var pid = parseInt(row.dataset.parkId, 10);
                if (!pid) return;
                var p = { ParkId: pid };
                row.querySelectorAll('[data-field]').forEach(function(inp) {
                    p[inp.dataset.field] = (inp.type === 'checkbox') ? (inp.checked ? 'YES' : '') : inp.value;
                });
                parks.push(p);
            });
            if (!parks.length) { feedback('kn-admin-parks-feedback', 'No data to save.', false); return; }
            btn.disabled = true;
            $.ajax({
                url: BASE_URL + 'updateparks',
                type: 'POST',
                data: { ParksJson: JSON.stringify(parks) },
                dataType: 'json',
                success: function(r) {
                    btn.disabled = false;
                    if (r && r.status === 0) {
                        feedback('kn-admin-parks-feedback', 'Parks saved!', true);
                        _knDirty = true;
                    } else {
                        feedback('kn-admin-parks-feedback', (r && r.error) ? r.error : 'Save failed.', false);
                    }
                },
                error: function() { btn.disabled = false; feedback('kn-admin-parks-feedback', 'Request failed.', false); }
            });
        });
    }

    // ── Section: Principality (ORK Admins only) ──────────────
    function wirePrinz() {
        if (!KnConfig.isOrkAdmin) return;
        wireToggle('kn-admin-hdr-prinz', 'kn-admin-body-prinz', 'kn-admin-chev-prinz');

        // Autocomplete for parent kingdom search
        var parentId = KnConfig.adminInfo.ParentKingdomId || 0;
        var nameInput = gid('kn-admin-prinz-parent-name');
        var hiddenInput = gid('kn-admin-prinz-parent-id');
        if (nameInput) {
            $(nameInput).autocomplete({
                source: function(req, res) {
                    $.getJSON(KnConfig.httpService + 'Search/SearchService.php',
                        { Action: 'Search/Kingdom', name: req.term },
                        function(data) {
                            res($.map(data || [], function(k) {
                                return { label: k.Name + (k.Abbreviation ? ' (' + k.Abbreviation + ')' : ''), value: k.KingdomId };
                            }));
                        }
                    );
                },
                focus: function(e, ui) { $(nameInput).val(ui.item.label); return false; },
                select: function(e, ui) {
                    $(nameInput).val(ui.item.label);
                    parentId = ui.item.value;
                    if (hiddenInput) hiddenInput.value = parentId;
                    var demoteBtn = gid('kn-admin-prinz-demote');
                    if (demoteBtn) demoteBtn.disabled = false;
                    return false;
                },
                change: function(e, ui) {
                    if (!ui.item) {
                        parentId = 0;
                        if (hiddenInput) hiddenInput.value = 0;
                        var demoteBtn = gid('kn-admin-prinz-demote');
                        if (demoteBtn) demoteBtn.disabled = true;
                    }
                    return false;
                },
                delay: 250, minLength: 2
            });
        }

        function doSetParent(newParentId, successMsg) {
            var fd = new FormData();
            fd.append('ParentKingdomId', newParentId);
            fetch(BASE_URL + 'setparent', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(r) {
                    if (r && r.status === 0) {
                        feedback('kn-admin-prinz-feedback', successMsg, true);
                        _knDirty = true;
                    } else {
                        feedback('kn-admin-prinz-feedback', (r && r.error) ? r.error : 'Save failed.', false);
                    }
                })
                .catch(function() { feedback('kn-admin-prinz-feedback', 'Request failed.', false); });
        }

        // Change sponsor (principality mode)
        var sponsorSaveBtn = gid('kn-admin-prinz-sponsor-save');
        if (sponsorSaveBtn) {
            sponsorSaveBtn.addEventListener('click', function() {
                clearFeedback('kn-admin-prinz-feedback');
                var newParentId = parseInt(gid('kn-admin-prinz-parent-id').value || '0', 10);
                if (!newParentId) { feedback('kn-admin-prinz-feedback', 'Please select a sponsor kingdom.', false); return; }
                doSetParent(newParentId, 'Sponsor kingdom updated.');
            });
        }

        // Convert to kingdom
        var promoteBtn = gid('kn-admin-prinz-promote');
        if (promoteBtn) {
            promoteBtn.addEventListener('click', function() {
                knConfirm('Remove this principality\'s sponsor and make it a full kingdom?', function() {
                    clearFeedback('kn-admin-prinz-feedback');
                    doSetParent(0, 'Converted to kingdom. Reload to see updated status.');
                }, 'Convert to Kingdom');
            });
        }

    }

    // ── Section: Active Status (ORK Admins only) ──────────────
    function wireStatus() {
        var btn = gid('kn-admin-status-toggle');
        if (!btn) return;
        btn.addEventListener('click', function() {
            var isActive = btn.dataset.active === '1';
            var newActive = isActive ? 'Retired' : 'Active';
            var label = isActive ? 'mark this as inactive' : 'restore this to active';
            knConfirm('Are you sure you want to ' + label + '?', function() {
                clearFeedback('kn-admin-ops-feedback');
                var fd = new FormData();
                fd.append('Active', newActive);
                fetch(BASE_URL + 'setstatus', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(r) {
                        if (r && r.status === 0) {
                            btn.dataset.active = newActive === 'Active' ? '1' : '0';
                            gid('kn-admin-status-label').textContent = newActive === 'Active' ? 'Active' : 'Inactive';
                            if (newActive === 'Active') {
                                btn.innerHTML = '<i class="fas fa-ban"></i> Mark Inactive';
                                btn.classList.add('kn-admin-ops-btn-danger');
                            } else {
                                btn.innerHTML = '<i class="fas fa-check-circle"></i> Restore to Active';
                                btn.classList.remove('kn-admin-ops-btn-danger');
                            }
                            feedback('kn-admin-ops-feedback', newActive === 'Active' ? 'Restored to active.' : 'Marked inactive.', true);
                            _knDirty = true;
                        } else {
                            feedback('kn-admin-ops-feedback', (r && r.error) ? r.error : 'Request failed.', false);
                        }
                    })
                    .catch(function() { feedback('kn-admin-ops-feedback', 'Request failed.', false); });
            }, newActive === 'Active' ? 'Restore' : 'Mark Inactive');
        });
    }

    // ── Wire everything in ready() ────────────────────────────
    $(document).ready(function() {
        wireToggle('kn-admin-hdr-details', 'kn-admin-body-details', 'kn-admin-chev-details');
        wireToggle('kn-admin-hdr-config',  'kn-admin-body-config',  'kn-admin-chev-config');
        wireToggle('kn-admin-hdr-titles',  'kn-admin-body-titles',  'kn-admin-chev-titles');
        wireToggle('kn-admin-hdr-awards',  'kn-admin-body-awards',  'kn-admin-chev-awards');
        wireToggle('kn-admin-hdr-parks',   'kn-admin-body-parks',   'kn-admin-chev-parks');
        wireToggle('kn-admin-hdr-ops',     'kn-admin-body-ops',     'kn-admin-chev-ops');

        wireDetails();
        wirePrinz();
        wireStatus();
        wireConfig();
        wireTitles();
        wireAwards();
        wireOps();
        wireParks();

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
            if (e.key === 'Escape' && gid('kn-admin-overlay') && gid('kn-admin-overlay').classList.contains('kn-open')) {
                var wasDirty = _knDirty;
                knCloseAdminModal();
                if (wasDirty) setTimeout(function() { location.reload(); }, 0);
            }
        });
    });
})();

// ── Shared: Styled confirmation modal (used by Kingdom + Park admin dialogs) ──
(function() {
    var _confirmCallback = null;

    window.knConfirm = function(message, onConfirm, title) {
        var overlay = document.getElementById('kn-confirm-overlay');
        if (!overlay) { if (confirm(message)) onConfirm(); return; }
        document.getElementById('kn-confirm-message').textContent = message;
        if (title) document.getElementById('kn-confirm-title').childNodes[1].textContent = ' ' + title;
        _confirmCallback = onConfirm;
        overlay.classList.add('kn-open');
        document.body.style.overflow = 'hidden';
    };

    function knCloseConfirm() {
        var overlay = document.getElementById('kn-confirm-overlay');
        if (overlay) overlay.classList.remove('kn-open');
        document.body.style.overflow = '';
        _confirmCallback = null;
    }

    $(document).ready(function() {
        var ok     = document.getElementById('kn-confirm-ok-btn');
        var cancel = document.getElementById('kn-confirm-cancel-btn');
        var close  = document.getElementById('kn-confirm-close-btn');
        var over   = document.getElementById('kn-confirm-overlay');
        if (ok)     ok.addEventListener('click',     function() { var cb = _confirmCallback; knCloseConfirm(); if (cb) cb(); });
        if (cancel) cancel.addEventListener('click', knCloseConfirm);
        if (close)  close.addEventListener('click',  knCloseConfirm);
        if (over)   over.addEventListener('click',   function(e) { if (e.target === this) knCloseConfirm(); });
    });
})();

// ---- Kingdom heraldry modal ----
(function() {
    if (typeof KnConfig === 'undefined' || !KnConfig.canManage) return;

    var UPLOAD_URL = KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/setheraldry';
    var REMOVE_URL = KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/removeheraldry';

    function gid(id) { return document.getElementById(id); }

    function closeModal() {
        var overlay = gid('kn-heraldry-overlay');
        if (overlay) overlay.classList.remove('kn-open');
    }

    window.knOpenHeraldryModal = function() {
        var overlay = gid('kn-heraldry-overlay');
        if (!overlay) return;
        var fileInput = gid('kn-heraldry-file-input');
        if (fileInput) fileInput.value = '';
        var sel     = gid('kn-heraldry-step-select');
        var upl     = gid('kn-heraldry-step-uploading');
        var done    = gid('kn-heraldry-step-done');
        var confirm = gid('kn-heraldry-remove-confirm');
        if (sel)     sel.style.display     = '';
        if (upl)     upl.style.display     = 'none';
        if (done)    done.style.display    = 'none';
        if (confirm) confirm.style.display = 'none';
        overlay.classList.add('kn-open');
    };

    window.knDoRemoveHeraldry = function() {
        fetch(REMOVE_URL, { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function(r) {
                if (r && r.status === 0) {
                    gtag('event', 'kingdom_heraldry_remove', { status: 'success' });
                    window.location.reload();
                } else {
                    alert((r && r.error) ? r.error : 'Remove failed. Please try again.');
                }
            })
            .catch(function() {
                alert('Request failed. Please try again.');
            });
    };

    document.addEventListener('DOMContentLoaded', function() {
        // File input change → auto-upload
        var fileInput = gid('kn-heraldry-file-input');
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                var file = this.files[0];
                if (!file) return;
                var sel  = gid('kn-heraldry-step-select');
                var upl  = gid('kn-heraldry-step-uploading');
                var done = gid('kn-heraldry-step-done');
                if (sel) sel.style.display = 'none';
                if (upl) upl.style.display = '';
                var fd = new FormData();
                fd.append('Heraldry', file);
                fetch(UPLOAD_URL, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(r) {
                        if (upl) upl.style.display = 'none';
                        if (r && r.status === 0) {
                            gtag('event', 'kingdom_heraldry_upload', { status: 'success' });
                            if (done) done.style.display = '';
                            setTimeout(function() { window.location.reload(); }, 1200);
                        } else {
                            gtag('event', 'kingdom_heraldry_upload', { status: 'failed' });
                            if (sel) sel.style.display = '';
                            alert((r && r.error) ? r.error : 'Upload failed. Please try again.');
                        }
                    })
                    .catch(function() {
                        gtag('event', 'kingdom_heraldry_upload', { status: 'failed' });
                        if (upl) upl.style.display = 'none';
                        if (sel) sel.style.display = '';
                        alert('Request failed. Please try again.');
                    });
            });
        }

        // Remove button toggle
        var removeBtn = gid('kn-heraldry-remove-btn');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                var confirm = gid('kn-heraldry-remove-confirm');
                if (confirm) confirm.style.display = confirm.style.display === 'none' ? '' : 'none';
            });
        }

        // Close button
        var closeBtn = gid('kn-heraldry-close-btn');
        if (closeBtn) closeBtn.addEventListener('click', closeModal);

        // Backdrop click
        var overlay = gid('kn-heraldry-overlay');
        if (overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeModal();
            });
        }

        // Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var overlay = gid('kn-heraldry-overlay');
                if (overlay && overlay.classList.contains('kn-open')) closeModal();
            }
        });
    });
})();

/* ===========================
   Park Profile (PkConfig)
   =========================== */

// ---- Events calendar data (server-rendered) ----
var pkCalEvents;
if (typeof PkConfig !== 'undefined') { pkCalEvents = PkConfig.calEvents; }
var pkCalParkDays = [];
if (typeof PkConfig !== 'undefined' && PkConfig.calParkDays) { pkCalParkDays = PkConfig.calParkDays; }
var pkFilters   = { 'event': true, 'park-day': false };
var pkCalLoaded = false;
var pkCalendar  = null;

function pkSetEventsView(view) {
    if (view === 'calendar' && knIsMobile()) view = 'list';
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
        events: function(fetchInfo, successCallback) {
            var combined = [];
            if (pkFilters['event']) {
                pkCalEvents.forEach(function(e) {
                    var ev = { title: e.title, start: e.start, color: e.color };
                    if (e.url) ev.url = e.url;
                    if (e.end) ev.end = e.end;
                    combined.push(ev);
                });
            }
            if (pkFilters['park-day']) {
                pkCalParkDays.forEach(function(e) {
                    combined.push({ title: e.title, start: e.start, color: e.color });
                });
            }
            successCallback(combined);
        },
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            if (info.event.url) window.location.href = info.event.url;
        },
        dayCellDidMount: function(info) {
            if (typeof PkConfig === 'undefined' || !PkConfig.loggedIn) return;
            var top = info.el.querySelector('.fc-daygrid-day-top');
            if (!top) return;
            var btn = document.createElement('button');
            btn.className = 'pk-cal-add-btn';
            btn.title = 'Create event';
            btn.innerHTML = '<i class="fas fa-plus"></i>';
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                e.preventDefault();
                var ds = info.dateStr || (function() {
                    var d = info.date;
                    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
                })();
                if (window.pkOpenEventModal) window.pkOpenEventModal(ds);
            });
            top.appendChild(btn);
        }
    });
    pkCalendar.render();
}

function pkToggleFilter(btn, type) {
    pkFilters[type] = !pkFilters[type];
    var isOn = pkFilters[type];
    $(btn).toggleClass('pk-filter-on', isOn);
    $('#pk-events-table').find('tr[data-type="' + type + '"]').css('display', isOn ? '' : 'none');
    if (pkCalendar) pkCalendar.refetchEvents();
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
        var hDeg   = Math.round(h * 360);
        var sPct   = Math.round(finalS * 100);
        document.documentElement.style.setProperty('--pk-hue', hDeg);
        document.documentElement.style.setProperty('--pk-sat', sPct + '%');
        var heroEl = document.querySelector('.pk-hero');
        if (heroEl) {
            heroEl.style.backgroundColor =
                'hsl(' + hDeg + ',' + sPct + '%,18%)';
        }
        document.documentElement.style.setProperty(
            '--pk-page-tint', 'rgba(' + dr + ',' + dg + ',' + db + ',0.05)'
        );
    } catch(e) { /* CORS or tainted canvas — keep default green */ }
}

// ---- Tab activation ----
function pkActivateTab(tab) {
    if (tab === 'recommendations') gtag('event', 'recommendation_view', { section: 'park' });
    $('.pk-tab-nav li').removeClass('pk-tab-active');
    var $pkTab = $('.pk-tab-nav li[data-pktab="' + tab + '"]');
    $pkTab.addClass('pk-tab-active');
    $('.pk-tab-panel').hide();
    $('#pk-tab-' + tab).show();
    var pkLabel = $pkTab.find('.pk-tab-label').text().trim();
    if (pkLabel) $('#pk-active-tab-label').text(pkLabel);
    if (tab === 'events' && pkCalendar) {
        pkCalendar.updateSize();
    }
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

    // ---- Auto-activate tab from URL ?tab= param ----
    (function() {
        var urlTab = new URLSearchParams(window.location.search).get('tab');
        if (urlTab && $('.pk-tab-nav li[data-pktab="' + urlTab + '"]').length) {
            pkActivateTab(urlTab);
        }
    })();

    // ---- Hall of Arms search ----
    $(document).on('input', '.pk-hoa-search', function() {
        var q = $(this).val().trim().toLowerCase();
        var $cards = $('#pk-hoa-grid .pk-hoa-card');
        var visible = 0;
        $cards.each(function() {
            var match = !q || ($(this).data('name') || '').indexOf(q) !== -1;
            $(this).toggle(match);
            if (match) visible++;
        });
        $('#pk-hoa-empty').toggle(visible === 0);
    });

    // ---- Events view toggle (list / calendar) ----
    $('#pk-ev-view-list').on('click', function() { pkSetEventsView('list'); });
    $('#pk-ev-view-cal').on('click',  function() { pkSetEventsView('calendar'); });
    $(document).on('click', '#pk-tab-events .pk-filter-toggle', function() {
        pkToggleFilter(this, $(this).data('filter'));
    });
    try {
        pkSetEventsView(localStorage.getItem('pk_events_view') || 'list');
    } catch(e) {
        pkSetEventsView('list');
    }
    $(window).on('resize.pkEvents', function() {
        if (knIsMobile() && $('#pk-events-cal').is(':visible')) {
            pkSetEventsView('list');
        }
    });

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

    // ---- Player search (filters cards + list rows across all periods) ----
    $('#pk-player-search').on('input', function() {
        var q = $(this).val().trim().toLowerCase();
        if (q === '') {
            $('.pk-period-block').show();
            $('.pk-player-card').show();
        } else {
            $('.pk-period-block').show();
            $('.pk-player-card').each(function() {
                var name = $(this).find('.pk-player-name').text().toLowerCase();
                $(this).toggle(name.indexOf(q) !== -1);
            });
            // Hide period blocks with no visible cards
            $('.pk-period-block').each(function() {
                var hasVisible = $(this).find('.pk-player-card:visible').length > 0;
                $(this).toggle(hasVisible);
            });
        }
        // Also filter list view rows
        $('#pk-players-table tbody tr').each(function() {
            var name = $(this).find('td:first').text().toLowerCase();
            $(this).toggle(!q || name.indexOf(q) !== -1);
        });
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

    pkSortTable($('#pk-events-table'), 1, 'date', 'asc');
    pkPaginate($('#pk-events-table'), 1);

    pkSortDesc($('#pk-tournaments-table'), 2, 'date');
    pkPaginate($('#pk-tournaments-table'), 1);

    pkPaginate($('#pk-players-table'), 1);

});
(function() {
    if (typeof PkConfig === 'undefined') return;
    if (!document.getElementById('pk-award-overlay')) return;
    var UIR_JS = PkConfig.uir;
    var SEARCH_URL = PkConfig.httpService + 'Search/SearchService.php';
    var PARK_ID = PkConfig.parkId;
    var awardOptHTML = PkConfig.awardOptHTML;
    var officerOptHTML = PkConfig.officerOptHTML;
    var currentType = 'awards';
    var givenByTimer, givenAtTimer, playerTimer;
    var pkPlayerRanks = {};

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
        var baseAwardId = parseInt(opt.getAttribute('data-award-id')) || 0;
        var maxRank   = /zodiac/i.test(opt.textContent) ? 12 : 10;
        var held      = pkPlayerRanks[baseAwardId] || 0;
        var suggested = Math.min(held + 1, maxRank);
        for (var r = 1; r <= maxRank; r++) {
            var pill = document.createElement('button');
            pill.type      = 'button';
            pill.className = 'pk-rank-pill';
            if (r <= held)       pill.className += ' pk-rank-held';
            if (r === suggested) pill.className += ' pk-rank-suggested';
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
        // Auto-select suggested rank
        var suggestedPill = wrap.querySelector('[data-rank="' + suggested + '"]');
        if (suggestedPill) { suggestedPill.classList.add('pk-rank-selected'); input.value = suggested; }
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

    // Player search autocomplete (park members only)
    gid('pk-award-player-text').addEventListener('input', function() {
        gid('pk-award-player-id').value = '';
        checkRequired();
        var term = this.value.trim();
        if (term.length < 2) { gid('pk-award-player-results').classList.remove('pk-ac-open'); return; }
        clearTimeout(playerTimer);
        playerTimer = setTimeout(function() {
            var url = UIR_JS + 'ParkAjax/park/' + PARK_ID + '/playersearch&q=' + encodeURIComponent(term);
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('pk-award-player-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(p) {
                        return '<div class="pk-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                            + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr||'') + ':' + escHtml(p.PAbbr||'') + ')</span></div>';
                    }).join('')
                    : '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No players found</div>';
                el.classList.add('pk-ac-open');
            }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
        }, AUTOCOMPLETE_DEBOUNCE_MS);
    });
    gid('pk-award-player-results').addEventListener('click', function(e) {
        var item = e.target.closest('.pk-ac-item[data-id]');
        if (!item) return;
        gid('pk-award-player-text').value = decodeURIComponent(item.dataset.name);
        gid('pk-award-player-id').value   = item.dataset.id;
        this.classList.remove('pk-ac-open');
        checkRequired();
        // Fetch this player's held ladder award ranks, then rebuild pills if an award is selected
        pkPlayerRanks = {};
        var pid = item.dataset.id;
        fetch(UIR_JS + 'PlayerAjax/player/' + pid + '/awardranks')
            .then(function(r) { return r.json(); })
            .then(function(ranks) {
                pkPlayerRanks = ranks || {};
                var curAward = gid('pk-award-select').value;
                if (curAward) buildRankPills(curAward);
            }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
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
            var url = SEARCH_URL + '?Action=Search%2FPlayer&type=PERSONA&search=' + encodeURIComponent(term) + '&park_id=' + PkConfig.parkId + '&limit=6';
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('pk-award-givenby-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(p) {
                        return '<div class="pk-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                            + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr||'') + ':' + escHtml(p.PAbbr||'') + ')</span></div>';
                    }).join('')
                    : '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No results</div>';
                el.classList.add('pk-ac-open');
            }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
        }, AUTOCOMPLETE_DEBOUNCE_MS);
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
            var today = new Date().toISOString().slice(0, 10);
            var url = SEARCH_URL + '?Action=Search%2FLocation&name=' + encodeURIComponent(term) + '&date=' + today + '&limit=6';
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('pk-award-givenat-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(loc) {
                        return '<div class="pk-ac-item" tabindex="-1" data-pid="' + (loc.ParkId||0) + '" data-kid="' + (loc.KingdomId||0) + '" data-eid="' + (loc.EventId||0) + '" data-name="' + encodeURIComponent(loc.LocationName||loc.ShortName||'') + '">'
                            + escHtml(loc.LocationName || loc.ShortName || '') + '</div>';
                    }).join('')
                    : '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No locations found</div>';
                el.classList.add('pk-ac-open');
            }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
        }, AUTOCOMPLETE_DEBOUNCE_MS);
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

    // Keyboard navigation for player, givenBy, and givenAt autocompletes
    acKeyNav(gid('pk-award-player-text'),  gid('pk-award-player-results'),  'pk-ac-open', '.pk-ac-item');
    acKeyNav(gid('pk-award-givenby-text'), gid('pk-award-givenby-results'), 'pk-ac-open', '.pk-ac-item');
    acKeyNav(gid('pk-award-givenat-text'), gid('pk-award-givenat-results'), 'pk-ac-open', '.pk-ac-item');

    // Note char counter
    gid('pk-award-note').addEventListener('input', function() {
        var rem = AWARD_NOTE_MAX_CHARS - this.value.length;
        var el  = gid('pk-award-char-count');
        el.textContent = rem + ' character' + (rem !== 1 ? 's' : '') + ' remaining';
    });

    gid('pk-award-select').addEventListener('change', checkRequired);
    gid('pk-award-date').addEventListener('change', checkRequired);
    gid('pk-award-date').addEventListener('input',  checkRequired);

    // ---- Open / Close ----
    window.pkOpenAwardModal = function() {
        var today = new Date();
        pkPlayerRanks = {};
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
        gtag('event', 'award_entry_open', { section: 'park' });
        gtag('event', 'park_awards_open', { source: 'park_info' });
        gid('pk-award-overlay').classList.add('pk-open');
        document.body.style.overflow = 'hidden';
        gid('pk-award-player-text').focus();
    };
    window.pkCloseAwardModal = function() {
        gid('pk-award-overlay').classList.remove('pk-open');
        document.body.style.overflow = '';
        pkActiveRecId = null;
    };

    // Track the recommendation that triggered the current award modal open
    var pkActiveRecId = null;

    // Pre-populate award modal from a recommendation row
    window.pkGiveFromRec = function(rec) {
        gtag('event', 'recommendation_give');
        pkOpenAwardModal();
        if (rec.Persona || rec.MundaneId) {
            gid('pk-award-player-text').value = rec.Persona || '';
            gid('pk-award-player-id').value   = String(rec.MundaneId || '');
        }
        if (rec.KingdomAwardId) {
            var sel = gid('pk-award-select');
            sel.value = String(rec.KingdomAwardId);
            sel.dispatchEvent(new Event('change'));
        }
        if (rec.Rank) {
            setTimeout(function() {
                var pill = document.querySelector('#pk-rank-pills .pk-rank-pill[data-rank="' + rec.Rank + '"]');
                if (pill) pill.click();
            }, 0);
        }
        if (rec.Reason) {
            var noteEl = gid('pk-award-note');
            noteEl.value = rec.Reason;
            var rem = AWARD_NOTE_MAX_CHARS - rec.Reason.length;
            var cc = gid('pk-award-char-count');
            if (cc) { cc.textContent = rem + ' characters remaining'; cc.classList.toggle('pk-char-warn', rem < 50); }
        }
        checkRequired();
        pkActiveRecId = rec.RecommendationsId || null;
    };

    function pkAutoDismissRec() {
        var id = pkActiveRecId;
        pkActiveRecId = null;
        if (!id) return;
        var row = document.querySelector('#pk-recs-tbody .pk-rec-row[data-rec-id="' + id + '"]');
        var fd = new FormData();
        fd.append('RecommendationsId', id);
        fetch(PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/dismissrecommendation', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.status === 0 && row) { row.classList.add('pk-rec-dismissed'); setTimeout(function() { row.remove(); }, 600); }
            })
            .catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] autoDismissRec failed:', err); });
    }

    // Recommendations tab: grant + dismiss
    document.addEventListener('click', function(e) {
        var grantBtn = e.target.closest ? e.target.closest('.pk-rec-grant-btn') : null;
        if (grantBtn && grantBtn.closest('#pk-tab-recommendations')) {
            try { window.pkGiveFromRec(JSON.parse(grantBtn.getAttribute('data-rec') || '{}')); } catch(ex) {}
            return;
        }
        var expandBtn = e.target.closest ? e.target.closest('.pk-rec-expand-btn') : null;
        if (expandBtn && expandBtn.closest('.pk-rec-notes')) {
            var notesCell = expandBtn.closest('.pk-rec-notes');
            var isCollapse = expandBtn.classList.contains('pk-rec-collapse-btn');
            notesCell.querySelector('.pk-rec-notes-ellipsis').style.display = isCollapse ? '' : 'none';
            notesCell.querySelector('.pk-rec-notes-full').style.display     = isCollapse ? 'none' : '';
            return;
        }
        var dimBtn = e.target.closest ? e.target.closest('.pk-rec-dismiss-btn') : null;
        if (dimBtn && dimBtn.closest('#pk-tab-recommendations')) {
            if (!dimBtn.dataset.confirm) {
                dimBtn.dataset.confirm = '1';
                dimBtn.textContent = 'Confirm Dismiss?';
                dimBtn.classList.add('pk-rec-dismiss-confirm');
                dimBtn._confirmTimer = setTimeout(function() {
                    dimBtn.dataset.confirm = '';
                    dimBtn.textContent = 'Dismiss';
                    dimBtn.classList.remove('pk-rec-dismiss-confirm');
                }, 3000);
                return;
            }
            clearTimeout(dimBtn._confirmTimer);
            dimBtn.dataset.confirm = '';
            var recId = dimBtn.getAttribute('data-rec-id');
            var row   = dimBtn.closest('.pk-rec-row');
            var fd = new FormData();
            fd.append('RecommendationsId', recId);
            fetch(PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/dismissrecommendation', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.status === 0) {
                        gtag('event', 'recommendation_dismiss', { section: 'park' });
                        if (row) { row.classList.add('pk-rec-dismissed'); setTimeout(function() { row.remove(); }, 600); }
                    } else {
                        alert(d.error || 'Failed to dismiss recommendation.');
                    }
                })
                .catch(function() { alert('Network error.'); });
        }
    });

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
                btnNew.innerHTML  = '<i class="fas fa-plus"></i> <span class="award-btn-prefix">Add + </span>New Player';
                btnSame.innerHTML = '<i class="fas fa-plus"></i> <span class="award-btn-prefix">Add + </span>Same Player';
                checkRequired();
            });
    }

    // "Add + New Player" — clear player + award/rank/note, keep date/giver/location
    gid('pk-award-save-new').addEventListener('click', function() {
        pkDoSave(function() { pkAutoDismissRec(); pkShowSuccess(); pkClearPlayer(); pkClearAward(); gid('pk-award-player-text').focus(); });
    });
    // "Add + Same Player" — clear only award/rank/note, keep player + date/giver/location
    gid('pk-award-save-same').addEventListener('click', function() {
        pkDoSave(function() {
            pkAutoDismissRec(); pkShowSuccess(); pkClearAward();
            var pid = gid('pk-award-player-id').value;
            if (pid) {
                pkPlayerRanks = {};
                fetch(UIR_JS + 'PlayerAjax/player/' + pid + '/awardranks')
                    .then(function(r) { return r.json(); })
                    .then(function(ranks) {
                        pkPlayerRanks = ranks || {};
                        var curAward = gid('pk-award-select').value;
                        if (curAward) buildRankPills(curAward);
                    }).catch(function() {});
            }
            gid('pk-award-select').focus();
        });
    });
})();
(function() {
    if (typeof PkConfig === 'undefined') return;
    if (!document.getElementById('pk-rec-overlay')) return;
    var UIR_JS      = PkConfig.uir;
    var PARK_ID     = PkConfig.parkId;
    var SEARCH_URL  = PkConfig.httpService + 'Search/SearchService.php';
    var playerTimer;
    var pkRecRanks  = {};

    function gid(id) { return document.getElementById(id); }

    function checkRequired() {
        var ok = !!gid('pk-rec-player-id').value
              && !!gid('pk-rec-award-select').value
              && !!gid('pk-rec-reason').value.trim();
        gid('pk-rec-submit').disabled = !ok;
    }

    function buildRecRankPills(awardId) {
        var row   = gid('pk-rec-rank-row');
        var wrap  = gid('pk-rec-rank-pills');
        var input = gid('pk-rec-rank-val');
        wrap.innerHTML = '';
        input.value = '';
        row.style.display = 'none';
        if (!awardId) return;
        var opt = gid('pk-rec-award-select').querySelector('option[value="' + awardId + '"]');
        if (!opt || opt.getAttribute('data-is-ladder') !== '1') return;
        row.style.display = '';
        var baseAwardId = parseInt(opt.getAttribute('data-award-id')) || 0;
        var maxRank  = /zodiac/i.test(opt.textContent) ? 12 : 10;
        var held     = pkRecRanks[baseAwardId] || 0;
        var suggested = Math.min(held + 1, maxRank);
        for (var r = 1; r <= maxRank; r++) {
            var pill = document.createElement('button');
            pill.type = 'button';
            pill.className = 'pk-rank-pill';
            if (r <= held)       pill.className += ' pk-rank-held';
            if (r === suggested) pill.className += ' pk-rank-suggested';
            pill.textContent = r;
            pill.dataset.rank = r;
            pill.addEventListener('click', (function(rank, el) {
                return function() {
                    wrap.querySelectorAll('.pk-rank-pill').forEach(function(p) { p.classList.remove('pk-rank-selected'); });
                    el.classList.add('pk-rank-selected');
                    input.value = rank;
                };
            })(r, pill));
            wrap.appendChild(pill);
        }
        var suggestedPill = wrap.querySelector('[data-rank="' + suggested + '"]');
        if (suggestedPill) { suggestedPill.classList.add('pk-rank-selected'); input.value = suggested; }
    }

    gid('pk-rec-award-select').addEventListener('change', function() {
        buildRecRankPills(this.value);
        checkRequired();
    });

    // Player search
    gid('pk-rec-player-text').addEventListener('input', function() {
        gid('pk-rec-player-id').value = '';
        checkRequired();
        var term = this.value.trim();
        clearTimeout(playerTimer);
        if (term.length < 2) { gid('pk-rec-player-results').classList.remove('pk-ac-open'); return; }
        playerTimer = setTimeout(function() {
            var url = UIR_JS + 'KingdomAjax/playersearch/' + PkConfig.kingdomId + '&q=' + encodeURIComponent(term);
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('pk-rec-player-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(p) {
                        return '<div class="pk-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                            + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr||'') + ':' + escHtml(p.PAbbr||'') + ')</span></div>';
                    }).join('')
                    : '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No players found</div>';
                el.classList.add('pk-ac-open');
            }).catch(function() {});
        }, 300);
    });
    gid('pk-rec-player-results').addEventListener('click', function(e) {
        var item = e.target.closest('.pk-ac-item[data-id]');
        if (!item) return;
        gid('pk-rec-player-text').value = decodeURIComponent(item.dataset.name);
        gid('pk-rec-player-id').value   = item.dataset.id;
        this.classList.remove('pk-ac-open');
        pkRecRanks = {};
        fetch(UIR_JS + 'PlayerAjax/player/' + item.dataset.id + '/awardranks')
            .then(function(r) { return r.json(); })
            .then(function(ranks) {
                pkRecRanks = ranks || {};
                var cur = gid('pk-rec-award-select').value;
                if (cur) buildRecRankPills(cur);
            }).catch(function() {});
        checkRequired();
    });
    acKeyNav(gid('pk-rec-player-text'), gid('pk-rec-player-results'), 'pk-ac-open', '.pk-ac-item[data-id]');

    gid('pk-rec-reason').addEventListener('input', function() {
        var rem = 400 - this.value.length;
        gid('pk-rec-char-count').textContent = rem + ' characters remaining';
        checkRequired();
    });

    window.pkOpenRecModal = function() {
        gid('pk-rec-error').style.display   = 'none';
        gid('pk-rec-success').style.display = 'none';
        gid('pk-rec-player-text').value     = '';
        gid('pk-rec-player-id').value       = '';
        gid('pk-rec-player-results').classList.remove('pk-ac-open');
        gid('pk-rec-award-select').value    = '';
        gid('pk-rec-rank-row').style.display = 'none';
        gid('pk-rec-rank-val').value        = '';
        gid('pk-rec-rank-pills').innerHTML  = '';
        gid('pk-rec-reason').value          = '';
        gid('pk-rec-char-count').textContent = '400 characters remaining';
        pkRecRanks = {};
        checkRequired();
        gid('pk-rec-overlay').classList.add('pk-open');
        document.body.style.overflow = 'hidden';
        gid('pk-rec-player-text').focus();
    };
    function pkCloseRecModal() {
        gid('pk-rec-overlay').classList.remove('pk-open');
        document.body.style.overflow = '';
    }

    gid('pk-rec-close-btn').addEventListener('click', pkCloseRecModal);
    gid('pk-rec-cancel').addEventListener('click',    pkCloseRecModal);
    gid('pk-rec-overlay').addEventListener('click', function(e) { if (e.target === this) pkCloseRecModal(); });

    gid('pk-rec-submit').addEventListener('click', function() {
        var errEl   = gid('pk-rec-error');
        var btn     = this;
        errEl.style.display = 'none';
        var fd = new FormData();
        fd.append('MundaneId',      gid('pk-rec-player-id').value);
        fd.append('KingdomAwardId', gid('pk-rec-award-select').value);
        fd.append('Reason',         gid('pk-rec-reason').value.trim());
        var rank = gid('pk-rec-rank-val').value;
        if (rank) fd.append('Rank', rank);
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        fetch(UIR_JS + 'ParkAjax/park/' + PARK_ID + '/addrecommendation', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0) {
                    gid('pk-rec-success').style.display = '';
                    gid('pk-rec-player-text').value = '';
                    gid('pk-rec-player-id').value   = '';
                    gid('pk-rec-award-select').value = '';
                    gid('pk-rec-rank-row').style.display = 'none';
                    gid('pk-rec-rank-val').value = '';
                    gid('pk-rec-rank-pills').innerHTML = '';
                    gid('pk-rec-reason').value = '';
                    gid('pk-rec-char-count').textContent = '400 characters remaining';
                    pkRecRanks = {};
                    setTimeout(function() { gid('pk-rec-success').style.display = 'none'; }, 3000);
                } else {
                    errEl.textContent = data.error || 'Save failed.';
                    errEl.style.display = '';
                }
            })
            .catch(function() {
                errEl.textContent = 'Request failed. Please try again.';
                errEl.style.display = '';
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Recommendation';
                checkRequired();
            });
    });
})();
(function() {
    if (typeof PkConfig === 'undefined') return;

    var CREATE_URL  = PkConfig.uir + 'EventAjax/create';

    function pkEvFeedback(msg) {
        var el = document.getElementById('pk-emod-feedback');
        el.textContent = msg; el.style.display = '';
    }

    window.pkOpenEventModal = function(dateStr) {
        var modal = document.getElementById('pk-event-modal');
        modal.dataset.presetDate = dateStr || '';
        document.getElementById('pk-event-name').value = '';
        document.getElementById('pk-emod-feedback').style.display = 'none';
        document.getElementById('pk-emod-go-btn').disabled = true;
        // Show date hint when opened from a calendar cell
        var dateRow  = document.getElementById('pk-emod-date-row');
        var dateText = document.getElementById('pk-emod-date-text');
        if (dateRow && dateText) {
            if (dateStr) {
                var d = new Date(dateStr + 'T00:00:00');
                dateText.textContent = d.toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric' });
                dateRow.style.display = '';
            } else {
                dateRow.style.display = 'none';
            }
        }
        modal.classList.add('pk-emod-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { document.getElementById('pk-event-name').focus(); }, 50);
    };

    window.pkCloseEventModal = function() {
        document.getElementById('pk-event-modal').classList.remove('pk-emod-open');
        document.body.style.overflow = '';
    };

    window.pkCreateEvent = function() {
        var name = document.getElementById('pk-event-name').value.trim();
        if (!name) return;
        var btn = document.getElementById('pk-emod-go-btn');
        btn.disabled = true;
        $.post(CREATE_URL, { Name: name, KingdomId: PkConfig.kingdomId, ParkId: PkConfig.parkId },
            function(r) {
                if (r && r.status === 0) {
                    var presetDate = document.getElementById('pk-event-modal').dataset.presetDate || '';
                    var url = PkConfig.uir + 'Event/create/' + r.eventId + '/' + PkConfig.parkId;
                    if (presetDate) url += '&date=' + encodeURIComponent(presetDate);
                    window.location.href = url;
                } else {
                    pkEvFeedback((r && r.error) ? r.error : 'Failed to create event.');
                    btn.disabled = false;
                }
            }, 'json'
        ).fail(function() { pkEvFeedback('Request failed. Please try again.'); btn.disabled = false; });
    };

    $(document).ready(function() {
        $('#pk-event-name').on('input', function() {
            document.getElementById('pk-emod-go-btn').disabled = !this.value.trim();
        }).on('keydown', function(e) {
            if (e.key === 'Enter' && !document.getElementById('pk-emod-go-btn').disabled) pkCreateEvent();
        });

        var pkEvtOverlay = document.getElementById('pk-event-modal');
        if (pkEvtOverlay) {
            pkEvtOverlay.addEventListener('click', function(e) { if (e.target === this) pkCloseEventModal(); });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('pk-event-modal') &&
                document.getElementById('pk-event-modal').classList.contains('pk-emod-open')) pkCloseEventModal();
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

        function evAttAbbr(v) {
            return (v.KAbbr && v.PAbbr) ? v.KAbbr + ':' + v.PAbbr : (v.ParkName || '');
        }

        function evUpdateAddBtn() {
            var pid  = document.getElementById('ev-MundaneId');
            var cls  = document.getElementById('ev-ClassId');
            var cred = document.getElementById('ev-Credits');
            var btn  = document.querySelector('#ev-attendance-form button[type="submit"]');
            if (!btn) return;
            btn.disabled = !(pid && parseInt(pid.value, 10) > 0 && cls && cls.value && cred && parseFloat(cred.value) > 0);
        }

        var evSubmitBtn = document.querySelector('#ev-attendance-form button[type="submit"]');
        if (evSubmitBtn) evSubmitBtn.disabled = true;

        var evClassSel = document.getElementById('ev-ClassId');
        if (evClassSel) evClassSel.addEventListener('change', evUpdateAddBtn);
        var evCredits = document.getElementById('ev-Credits');
        if (evCredits) evCredits.addEventListener('input', evUpdateAddBtn);

        function evAttendedIds() {
            var ids = {};
            document.querySelectorAll('#ev-attendance-table tbody tr[data-mundane-id]').forEach(function(tr) {
                ids[parseInt(tr.dataset.mundaneId, 10)] = true;
            });
            return ids;
        }

        $('#ev-PlayerName').autocomplete({
            source: function(req, res) {
                var attended = evAttendedIds();
                $.getJSON(EvConfig.httpService + 'Search/SearchService.php',
                    { Action: 'Search/Player', type: 'all', search: req.term, limit: 15 },
                    function(data) {
                        res($.map(data, function(v) {
                            if (attended[parseInt(v.MundaneId, 10)]) return null;
                            var abbr = evAttAbbr(v);
                            return { label: v.Persona + (abbr ? ' — ' + abbr : ''), name: v.Persona, value: v.MundaneId + '|' + v.PenaltyBox, suspended: !!(v.PenaltyBox || v.Suspended) };
                        }));
                    });
            },
            focus:  function(e,ui) { if (ui.item) $('#ev-PlayerName').val(ui.item.name); return false; },
            delay: 250, minLength: 2,
            select: function(e,ui) {
                $('#ev-PlayerName').val(ui.item.name);
                $('#ev-MundaneId').val(ui.item.value.split('|')[0]);
                evUpdateAddBtn();
                return false;
            },
            change: function(e,ui) { if(!ui.item) { $('#ev-MundaneId').val(''); evUpdateAddBtn(); } return false; }
        });
        $('#ev-PlayerName').on('input', function() {
            if (!$(this).val()) { $('#ev-MundaneId').val(''); evUpdateAddBtn(); }
        });
        $('#ev-PlayerName').data('autocomplete')._renderItem = function(ul, item) {
            var a = $('<a>');
            if (item.suspended) {
                a.addClass('pk-att-ac-suspended').html(
                    '<i class="fas fa-ban" style="margin-right:5px;font-size:11px"></i>' + $('<span>').text(item.label).html()
                );
            } else {
                a.text(item.label);
            }
            return $('<li></li>').data('item.autocomplete', item).append(a).appendTo(ul);
        };
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
        if (e.target && e.target.id === 'ev-checkin-modal') evCloseCheckinModal();
    });
    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { evCloseEditModal(); evCloseCheckinModal(); }
    });

    function evGetSavedCredits() {
        return parseFloat(localStorage.getItem('ev_credits_default')) || 1;
    }
    function evSaveCredits(val) {
        var n = parseFloat(val);
        if (n > 0) localStorage.setItem('ev_credits_default', n);
    }
    window.evOpenCheckinModal = function(mundaneId, personaName) {
        document.getElementById('ev-checkin-mundane-id').value = mundaneId;
        document.getElementById('ev-checkin-name').textContent = personaName;
        var creditsInput = document.querySelector('#ev-checkin-form [name="Credits"]');
        if (creditsInput) creditsInput.value = evGetSavedCredits();
        var overlay = document.getElementById('ev-checkin-modal');
        if (overlay) overlay.classList.add('ev-modal-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() {
            var classSelect = document.querySelector('#ev-checkin-form [name="ClassId"]');
            if (classSelect) classSelect.focus();
        }, 50);
    };
    window.evCloseCheckinModal = function() {
        var overlay = document.getElementById('ev-checkin-modal');
        if (overlay) overlay.classList.remove('ev-modal-open');
        document.body.style.overflow = '';
    };

    window.evDeleteRsvp = function(btn, mundaneId) {
        var cell = btn.parentNode;
        btn.style.display = 'none';
        var confirm = document.createElement('span');
        confirm.className = 'ev-rsvp-confirm';
        confirm.innerHTML =
            'Are you sure? ' +
            '<button class="ev-rsvp-confirm-yes" type="button">Yes, Remove</button> ' +
            '<button class="ev-rsvp-confirm-no" type="button">No, Cancel</button>';
        cell.appendChild(confirm);
        confirm.querySelector('.ev-rsvp-confirm-no').onclick = function() {
            confirm.remove();
            btn.style.display = '';
        };
        confirm.querySelector('.ev-rsvp-confirm-yes').onclick = function() {
            confirm.querySelector('.ev-rsvp-confirm-yes').disabled = true;
            confirm.querySelector('.ev-rsvp-confirm-no').disabled = true;
            var formData = new FormData();
            formData.append('MundaneId', mundaneId);
            fetch(EvConfig.uir + 'EventAjax/delete_rsvp/' + EvConfig.eventId + '/' + EvConfig.detailId, {
                method: 'POST', body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0) {
                    gtag('event', 'event_rsvp_delete');
                    var row = btn.closest('tr');
                    if (row) row.remove();
                    var cnt = document.querySelector('.ev-tab-nav li[data-tab="ev-tab-rsvp"] .ev-tab-count');
                    if (cnt) cnt.textContent = '(' + Math.max(0, (parseInt(cnt.textContent.replace(/[^0-9]/g, '')) || 0) - 1) + ')';
                } else {
                    confirm.remove();
                    btn.style.display = '';
                    alert(data.error || 'Failed to remove RSVP.');
                }
            })
            .catch(function(err) {
                confirm.remove();
                btn.style.display = '';
                alert('Request failed: ' + err.message);
            });
        };
    };

    window.evHandleCheckinSubmit = function(form) {
        var submitBtn = form.querySelector('button[type="submit"]');
        var initialBtnContent = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking In...';
        fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form) })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.status === 0) {
                evSaveCredits(form.querySelector('[name="Credits"]').value);
                var mundaneId = form.querySelector('[name="MundaneId"]').value;
                var rsvpBtn = document.querySelector('.ev-checkin-btn[data-mundane="' + mundaneId + '"]');
                if (rsvpBtn) {
                    rsvpBtn.classList.add('ev-checkin-done');
                    rsvpBtn.disabled = true;
                    rsvpBtn.removeAttribute('onclick');
                    rsvpBtn.innerHTML = '<i class="fas fa-user-check"></i> Checked In';
                }
                evCloseCheckinModal();
                if (data.attendance) {
                    var att = data.attendance;
                    var delUrl = EvConfig.uir + 'AttendanceAjax/attendance/' + att.AttendanceId + '/delete';
                    var kingCell = att.KingdomId ? '<a href="' + EvConfig.uir + 'Kingdom/profile/' + att.KingdomId + '">' + escHtml(att.KingdomName || '') + '</a>' : escHtml(att.KingdomName || '');
                    var parkCell = att.ParkId    ? '<a href="' + EvConfig.uir + 'Park/profile/'    + att.ParkId    + '">' + escHtml(att.ParkName    || '') + '</a>' : escHtml(att.ParkName    || '');
                    var newRow = '<tr data-att-id="' + att.AttendanceId + '" data-mundane-id="' + att.MundaneId + '">' +
                        '<td><a href="' + EvConfig.uir + 'Player/profile/' + att.MundaneId + '">' + escHtml(att.Persona || '') + '</a></td>' +
                        '<td>' + kingCell + '</td>' +
                        '<td>' + parkCell + '</td>' +
                        '<td>' + escHtml(att.ClassName || '') + '</td>' +
                        '<td>' + escHtml(att.Credits || '') + '</td>' +
                        '<td class="ev-del-cell"><a class="ev-del-link" title="Remove" href="#" data-del-url="' + delUrl + '" onclick="evConfirmAttDelete(event,this)">×</a></td>' +
                    '</tr>';
                    var tableBody = document.querySelector('#ev-attendance-table tbody');
                    if (tableBody) {
                        tableBody.insertAdjacentHTML('beforeend', newRow);
                    } else {
                        var emptyMsg = document.querySelector('#ev-tab-attendance .ev-empty');
                        var tableHtml = '<table class="display" id="ev-attendance-table" style="width:100%">' +
                            '<thead><tr><th>Player</th><th>Kingdom</th><th>Park</th><th>Class</th><th>Credits</th><th class="ev-del-cell"></th></tr></thead>' +
                            '<tbody>' + newRow + '</tbody></table>';
                        if (emptyMsg) { emptyMsg.outerHTML = tableHtml; }
                    }
                    var cnt = document.querySelector('.ev-tab-nav li[data-tab="ev-tab-attendance"] .ev-tab-count');
                    if (cnt) cnt.textContent = '(' + ((parseInt(cnt.textContent.replace(/[^0-9]/g, '')) || 0) + 1) + ')';
                }
            } else {
                alert(data.error || 'Check-in failed. Please try again.');
            }
        })
        .catch(function(err) { alert('Request failed: ' + err.message); })
        .finally(function() { submitBtn.disabled = false; submitBtn.innerHTML = initialBtnContent; });
    };

    window.evHandleAttendanceSubmit = function(form) {
        var submitBtn = form.querySelector('button[type="submit"]');
        var initialBtnContent = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';

        var formData = new FormData(form);
        var action = form.getAttribute('action');

        fetch(action, {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.status === 0 && data.attendance) {
                evSaveCredits(form.querySelector('[name="Credits"]').value);
                var att = data.attendance;
                var delUrl = EvConfig.uir + 'AttendanceAjax/attendance/' + att.AttendanceId + '/delete';
                var kingCell  = att.KingdomId ? '<a href="' + EvConfig.uir + 'Kingdom/profile/' + att.KingdomId + '">' + escHtml(att.KingdomName || '') + '</a>' : escHtml(att.KingdomName || '');
                var parkCell  = att.ParkId    ? '<a href="' + EvConfig.uir + 'Park/profile/'    + att.ParkId    + '">' + escHtml(att.ParkName    || '') + '</a>' : escHtml(att.ParkName    || '');
                var newRow = '<tr data-att-id="' + att.AttendanceId + '" data-mundane-id="' + att.MundaneId + '">' +
                    '<td><a href="' + EvConfig.uir + 'Player/profile/' + att.MundaneId + '">' + escHtml(att.Persona || '') + '</a></td>' +
                    '<td>' + kingCell + '</td>' +
                    '<td>' + parkCell + '</td>' +
                    '<td>' + escHtml(att.ClassName || '') + '</td>' +
                    '<td>' + escHtml(att.Credits || '') + '</td>' +
                    '<td class="ev-del-cell">' +
                        '<a class="ev-del-link" title="Remove" href="#" data-del-url="' + delUrl + '" onclick="evConfirmAttDelete(event,this)">×</a>' +
                    '</td>' +
                '</tr>';

                var tableBody = document.querySelector('#ev-attendance-table tbody');
                if (tableBody) {
                    tableBody.insertAdjacentHTML('beforeend', newRow);
                } else {
                    // Table doesn't exist yet — create it
                    var emptyMsg = document.querySelector('#ev-tab-attendance .ev-empty');
                    var tableHtml = '<table class="display" id="ev-attendance-table" style="width:100%">' +
                        '<thead><tr><th>Player</th><th>Kingdom</th><th>Park</th><th>Class</th><th>Credits</th><th class="ev-del-cell"></th></tr></thead>' +
                        '<tbody>' + newRow + '</tbody></table>';
                    if (emptyMsg) { emptyMsg.outerHTML = tableHtml; }
                }

                // Mark RSVP check-in button as checked in if this player has an RSVP
                var rsvpBtn = document.querySelector('.ev-checkin-btn[data-mundane="' + att.MundaneId + '"]');
                if (rsvpBtn) {
                    rsvpBtn.classList.add('ev-checkin-done');
                    rsvpBtn.disabled = true;
                    rsvpBtn.removeAttribute('onclick');
                    rsvpBtn.innerHTML = '<i class="fas fa-user-check"></i> Checked In';
                }

                form.reset();
                var creditsField = form.querySelector('[name="Credits"]');
                if (creditsField) creditsField.value = evGetSavedCredits();
                $('#ev-PlayerName').val('');
                $('#ev-MundaneId').val('');

                var tabCount = document.querySelector('[data-tab="ev-tab-attendance"] .ev-tab-count');
                if (tabCount) { tabCount.textContent = '(' + ((parseInt(tabCount.textContent.replace(/[^0-9]/g, '')) || 0) + 1) + ')'; }
            } else {
                var errorDiv = document.querySelector('.ev-att-form .ev-error') || document.createElement('div');
                errorDiv.className = 'ev-error';
                errorDiv.style.display = 'block';
                errorDiv.innerHTML = '<i class="fas fa-exclamation-triangle" style="margin-right:6px"></i>' + (data.error || 'An unknown error occurred.');
                if (!errorDiv.parentNode) { form.parentNode.insertBefore(errorDiv, form.nextSibling); }
            }
        })
        .catch(function(error) {
            console.error('Add attendance failed:', error);
        })
        .finally(function() {
            submitBtn.innerHTML = initialBtnContent;
            var pid  = document.getElementById('ev-MundaneId');
            var cls  = document.getElementById('ev-ClassId');
            var cred = document.getElementById('ev-Credits');
            submitBtn.disabled = !(pid && parseInt(pid.value, 10) > 0 && cls && cls.value && cred && parseFloat(cred.value) > 0);
        });
    };
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
$(document).ready(function() {
    if (typeof PkConfig === 'undefined') return;
    if (!document.getElementById('pk-att-overlay')) return;

    var ADD_URL    = PkConfig.uir + 'AttendanceAjax/park/' + PkConfig.parkId + '/add';
    var GETDAY_URL = PkConfig.uir + 'AttendanceAjax/park/' + PkConfig.parkId + '/getday';
    var SEARCH_URL = PkConfig.httpService + 'Search/SearchService.php';

    // MundaneIds already entered for the selected date
    var pkAttEntered = {};

    // Last class per MundaneId — seeded from recentAttendees, supplemented from getday
    var pkLastClass = {};
    (PkConfig.recentAttendees || []).forEach(function(a) {
        if (a.ClassId) pkLastClass[a.MundaneId] = a.ClassId;
    });

    // ClassId → ClassName lookup
    var pkClassNames = {};
    (PkConfig.classes || []).forEach(function(c) { pkClassNames[c.ClassId] = c.ClassName; });

    function gid(id) { return document.getElementById(id); }

    // --- Entered-today table helpers ---
    function pkEnteredRow(entry) {
        var className = pkClassNames[entry.ClassId] || '';
        var tr = document.createElement('tr');
        tr.dataset.attendanceId = entry.AttendanceId || 0;
        tr.dataset.mundaneId = entry.MundaneId || 0;
        var td1 = document.createElement('td'); td1.textContent = entry.Persona;
        var td2 = document.createElement('td'); td2.textContent = className;
        var td3 = document.createElement('td'); td3.textContent = entry.Credits || 1;
        var td4 = document.createElement('td');
        if (entry.AttendanceId) {
            var btn = document.createElement('button');
            btn.className = 'pk-att-del-btn'; btn.title = 'Remove';
            btn.innerHTML = '<i class="fas fa-times"></i>';
            btn.addEventListener('click', function() { pkDeleteEnteredRow(btn); });
            td4.appendChild(btn);
        }
        tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3); tr.appendChild(td4);
        return tr;
    }

    window.pkDeleteEnteredRow = function(btn) {
        var tr  = btn.closest('tr');
        var aid = parseInt(tr.dataset.attendanceId, 10);
        var mid = parseInt(tr.dataset.mundaneId, 10);
        if (!aid) return;
        btn.disabled = true;
        $.post(PkConfig.uir + 'AttendanceAjax/attendance/' + aid + '/delete', {}, function(r) {
            if (r && r.status === 0) {
                if (mid) delete pkAttEntered[mid];
                tr.remove();
                var tbody = gid('pk-att-entered-tbody');
                if (tbody && !tbody.rows.length) {
                    gid('pk-att-entered-table').style.display = 'none';
                    gid('pk-att-entered-empty').style.display = '';
                }
                pkRefreshEnteredCount();
                pkUpdateQuickAddEntered();
            } else {
                btn.disabled = false;
                pkAttShowFeedback((r && r.error) ? r.error : 'Delete failed.', false);
            }
        }, 'json').fail(function() {
            btn.disabled = false;
            pkAttShowFeedback('Request failed.', false);
        });
    };

    function pkRefreshEnteredCount() {
        var tbody   = gid('pk-att-entered-tbody');
        var countEl = gid('pk-att-entered-count');
        if (!countEl || !tbody) return;
        var n = tbody.rows.length;
        countEl.textContent = n > 0 ? '(' + n + ')' : '';
    }

    // --- Credits memory ---
    function pkGetSavedCredits() { return parseFloat(localStorage.getItem('pk_att_credits') || '1') || 1; }
    function pkSaveCredits(n) { var v = parseFloat(n); if (v > 0) localStorage.setItem('pk_att_credits', v); }
    function pkSyncCredits(val) {
        var n = parseFloat(val); if (!(n > 0)) return;
        var srch = gid('pk-att-search-credits');
        if (srch && srch !== document.activeElement) srch.value = n;
        pkSaveCredits(n);
    }

    // --- Fetch and display entries already on the registry for a given date ---
    function pkFetchDayEntered(date, cb) {
        var tbody   = gid('pk-att-entered-tbody');
        var table   = gid('pk-att-entered-table');
        var empty   = gid('pk-att-entered-empty');
        var countEl = gid('pk-att-entered-count');
        $.getJSON(GETDAY_URL, { date: date }, function(r) {
            pkAttEntered = {};
            pkLastClass = {};
            (PkConfig.recentAttendees || []).forEach(function(a) {
                if (a.ClassId) pkLastClass[a.MundaneId] = a.ClassId;
            });
            tbody.innerHTML = '';
            if (r && r.status === 0 && r.entries && r.entries.length) {
                r.entries.forEach(function(e) {
                    pkAttEntered[e.MundaneId] = true;
                    if (e.ClassId && !pkLastClass[e.MundaneId]) pkLastClass[e.MundaneId] = e.ClassId;
                    tbody.appendChild(pkEnteredRow(e));
                });
                table.style.display = '';
                empty.style.display = 'none';
                if (countEl) countEl.textContent = '(' + r.entries.length + ')';
            } else {
                table.style.display = 'none';
                empty.style.display = '';
                if (countEl) countEl.textContent = '';
            }
            pkUpdateQuickAddEntered();
            if (cb) cb();
        }).fail(function() {
            pkAttEntered = {};
            pkUpdateQuickAddEntered();
            if (cb) cb();
        });
    }

    // --- Hide quick-add rows for players already entered on the current date ---
    function pkUpdateQuickAddEntered() {
        var tbody = gid('pk-att-qa-tbody');
        if (!tbody) return;
        var visible = 0;
        Array.prototype.forEach.call(tbody.querySelectorAll('tr[data-mundane-id]'), function(tr) {
            var mid = parseInt(tr.dataset.mundaneId, 10);
            if (pkAttEntered[mid]) {
                tr.style.display = 'none';
            } else {
                tr.style.display = '';
                visible++;
                // Restore + button if it was replaced with a ✓ after a successful add
                if (tr.classList.contains('pk-att-done')) {
                    tr.classList.remove('pk-att-done');
                    var addedBtn = tr.querySelector('.pk-att-qa-added');
                    if (addedBtn) {
                        addedBtn.disabled = false;
                        addedBtn.textContent = '+';
                        addedBtn.classList.remove('pk-att-qa-added');
                    }
                }
            }
        });
        var empty = gid('pk-att-qa-empty');
        if (empty) empty.style.display = (tbody.dataset.built && visible === 0) ? '' : 'none';
    }

    // --- Inline calendar ---
    var pkCalViewYear, pkCalViewMonth;
    var MONTHS_LONG = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var DAYS_SHORT  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

    function pkDateFromValue(val) {
        var p = val.split('-');
        return new Date(+p[0], +p[1] - 1, +p[2]);
    }
    function pkToIso(d) {
        return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    }
    function pkFormatDateDisplay(val) {
        var d = pkDateFromValue(val);
        return DAYS_SHORT[d.getDay()] + ', ' + MONTHS_LONG[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }
    function pkRenderCal() {
        var selected = gid('pk-att-date').value;
        var todayIso = pkToIso(new Date());
        gid('pk-att-cal-month').textContent = MONTHS_LONG[pkCalViewMonth] + ' ' + pkCalViewYear;
        var days = gid('pk-att-cal-days');
        days.innerHTML = '';
        var firstDay = new Date(pkCalViewYear, pkCalViewMonth, 1).getDay();
        var daysInMonth = new Date(pkCalViewYear, pkCalViewMonth + 1, 0).getDate();
        for (var i = 0; i < firstDay; i++) {
            var blank = document.createElement('span');
            blank.className = 'pk-att-cal-day pk-cal-other';
            days.appendChild(blank);
        }
        for (var d = 1; d <= daysInMonth; d++) {
            var iso = pkCalViewYear + '-' + String(pkCalViewMonth+1).padStart(2,'0') + '-' + String(d).padStart(2,'0');
            var cell = document.createElement('span');
            cell.className = 'pk-att-cal-day';
            if (iso === selected) cell.classList.add('pk-cal-selected');
            else if (iso === todayIso) cell.classList.add('pk-cal-today');
            cell.textContent = d;
            cell.dataset.iso = iso;
            days.appendChild(cell);
        }
    }
    function pkOpenCal() {
        var val = gid('pk-att-date').value;
        var d = val ? pkDateFromValue(val) : new Date();
        pkCalViewYear  = d.getFullYear();
        pkCalViewMonth = d.getMonth();
        pkRenderCal();
        gid('pk-att-cal').style.display = '';
        gid('pk-att-date-display').classList.add('pk-cal-open');
        gid('pk-att-date-display').setAttribute('aria-expanded', 'true');
    }
    function pkCloseCal() {
        gid('pk-att-cal').style.display = 'none';
        gid('pk-att-date-display').classList.remove('pk-cal-open');
        gid('pk-att-date-display').setAttribute('aria-expanded', 'false');
    }
    function pkSetDate(val, cb) {
        gid('pk-att-date').value = val;
        gid('pk-att-date-label').textContent = pkFormatDateDisplay(val);
        pkCloseCal();
        pkAttEntered = {};
        var qaTbody = gid('pk-att-qa-tbody');
        if (qaTbody) { qaTbody.innerHTML = ''; delete qaTbody.dataset.built; }
        if (gid('pk-att-panel-recent') && gid('pk-att-panel-recent').style.display !== 'none') {
            pkBuildQuickAddRows();
        }
        pkFetchDayEntered(val, cb);
    }

    // --- Open / Close ---
    window.pkOpenAttendanceModal = function() {
        var today = pkToIso(new Date());
        pkCloseCal();
        gid('pk-att-search-credits').value = pkGetSavedCredits();
        pkBuildClassOptions();
        gid('pk-att-player-name').value = '';
        gid('pk-att-player-id').value   = '';
        pkAttHideFeedback();
        pkAttUpdateAddBtn();
        // Reset to Search tab and clear quick-add rows so they rebuild fresh
        document.querySelectorAll('#pk-att-overlay .pk-att-tab').forEach(function(t) { t.classList.remove('pk-att-tab-active'); });
        document.querySelectorAll('#pk-att-overlay .pk-att-tab-panel').forEach(function(p) { p.style.display = 'none'; });
        var searchTab = gid('pk-att-tab-search');
        if (searchTab) searchTab.classList.add('pk-att-tab-active');
        var searchPanel = gid('pk-att-panel-search');
        if (searchPanel) searchPanel.style.display = '';
        // Reset scope to park (default)
        pkSetScope('park');
        var nameInput = gid('pk-att-player-name');
        if (nameInput) nameInput.placeholder = 'Search within your park\u2026';
        var qaTbody = gid('pk-att-qa-tbody');
        if (qaTbody) { qaTbody.innerHTML = ''; delete qaTbody.dataset.built; }
        gtag('event', 'park_attendance_open', { source: 'park_info' });
        gid('pk-att-overlay').classList.add('pk-att-open');
        document.body.style.overflow = 'hidden';
        pkSetDate(today);
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
            btn.className = 'pk-att-qa-add'; btn.textContent = '+';
            btn.addEventListener('click', function() { pkQuickAdd(tr, a, btn); });
            td4.appendChild(btn); tr.appendChild(td4);

            tbody.appendChild(tr);
        });
        tbody.dataset.built = '1';
        // Apply any already-entered state (if fetch completed first)
        pkUpdateQuickAddEntered();
    }

    // --- Quick-add submit ---
    function pkQuickAdd(tr, attendee, btn) {
        var classId = parseInt(tr.querySelector('.pk-att-qa-select').value, 10);
        var credits = parseFloat(tr.querySelector('.pk-att-qa-credits').value) || 1;
        if (!classId) { pkAttShowFeedback('Select a class for ' + attendee.Persona + '.', false); return; }
        btn.disabled = true; btn.textContent = '\u2026';
        pkSubmit(
            { AttendanceDate: gid('pk-att-date').value, MundaneId: attendee.MundaneId, ClassId: classId, Credits: credits },
            function(ok, err, aid) {
                if (ok) {
                    pkAttEntered[attendee.MundaneId] = true;
                    tr.classList.add('pk-att-done');
                    btn.disabled = true; btn.textContent = '\u2713'; btn.classList.add('pk-att-qa-added');
                    pkAttRecorded({ AttendanceId: aid, MundaneId: attendee.MundaneId, Persona: attendee.Persona, ClassId: classId, Credits: credits });
                    pkAttHideFeedback();
                } else {
                    btn.disabled = false; btn.textContent = '+';
                    pkAttShowFeedback(err, false);
                }
            }
        );
    }

    // --- Search-add submit ---
    function pkAttUpdateAddBtn() {
        var pid  = gid('pk-att-player-id').value;
        var cls  = gid('pk-att-class-select').value;
        var cred = parseFloat(gid('pk-att-search-credits').value);
        gid('pk-att-add-btn').disabled = !(pid && cls && cred > 0);
    }
    gid('pk-att-add-btn').disabled = true;
    gid('pk-att-class-select').addEventListener('change', pkAttUpdateAddBtn);
    gid('pk-att-search-credits').addEventListener('input', pkAttUpdateAddBtn);

    gid('pk-att-add-btn').addEventListener('click', function() {
        var pid  = gid('pk-att-player-id').value;
        var name = gid('pk-att-player-name').value.trim();
        var cls  = parseInt(gid('pk-att-class-select').value, 10);
        var cred = parseFloat(gid('pk-att-search-credits').value) || 1;
        if (!pid)  { pkAttShowFeedback('Search for and select a player.', false); return; }
        if (!cls)  { pkAttShowFeedback('Select a class.', false); return; }
        var btn = gid('pk-att-add-btn');
        btn.disabled = true;
        pkSubmit(
            { AttendanceDate: gid('pk-att-date').value, MundaneId: pid, ClassId: cls, Credits: cred },
            function(ok, err, aid) {
                if (ok) {
                    var midInt = parseInt(pid, 10);
                    pkAttEntered[midInt] = true;
                    pkLastClass[midInt]  = cls;
                    pkAttHideFeedback();
                    pkAttRecorded({ AttendanceId: aid, MundaneId: midInt, Persona: name, ClassId: cls, Credits: cred });
                    gid('pk-att-player-name').value = '';
                    gid('pk-att-player-id').value   = '';
                    pkUpdateQuickAddEntered();
                } else {
                    pkAttShowFeedback(err, false);
                }
                pkAttUpdateAddBtn();
            }
        );
    });

    // --- Core AJAX ---
    function pkSubmit(data, cb) {
        $.post(ADD_URL, data, function(r) {
            if (r && r.status === 0) { gtag('event', 'attendance_add', { method: 'quick_add' }); cb(true, null, r.attendanceId || 0); }
            else                     cb(false, (r && r.error) ? r.error : 'Submission failed.', 0);
        }, 'json').fail(function() { cb(false, 'Request failed. Please try again.', 0); });
    }

    // --- Feedback helpers ---
    function pkAttShowFeedback(msg, ok) {
        var el = gid('pk-att-feedback');
        el.textContent = msg;
        el.className = 'pk-att-feedback ' + (ok ? 'pk-att-ok' : 'pk-att-err');
        el.style.display = '';
    }
    function pkAttHideFeedback() { gid('pk-att-feedback').style.display = 'none'; }
    function pkAttRecorded(entry) {
        var tbody = gid('pk-att-entered-tbody');
        var table = gid('pk-att-entered-table');
        var empty = gid('pk-att-entered-empty');
        tbody.insertBefore(pkEnteredRow(entry), tbody.firstChild);
        table.style.display = '';
        empty.style.display = 'none';
        pkRefreshEnteredCount();
        pkSaveCredits(entry.Credits);
    }

    // --- Tab switching ---
    Array.prototype.forEach.call(document.querySelectorAll('#pk-att-overlay .pk-att-tab'), function(tab) {
        tab.addEventListener('click', function() {
            document.querySelectorAll('#pk-att-overlay .pk-att-tab').forEach(function(t) { t.classList.remove('pk-att-tab-active'); });
            document.querySelectorAll('#pk-att-overlay .pk-att-tab-panel').forEach(function(p) { p.style.display = 'none'; });
            tab.classList.add('pk-att-tab-active');
            gid(tab.dataset.panel).style.display = '';
            $('#pk-att-player-name').autocomplete('close');
            if (tab.dataset.panel === 'pk-att-panel-recent') pkBuildQuickAddRows();
        });
    });

    // --- Credits: save to localStorage on change ---
    gid('pk-att-search-credits').addEventListener('input', function() { pkSyncCredits(this.value); });

    // --- Inline calendar events ---
    gid('pk-att-date-display').addEventListener('click', function(e) {
        e.stopPropagation();
        if (gid('pk-att-cal').style.display === 'none') pkOpenCal(); else pkCloseCal();
    });
    gid('pk-att-cal-prev').addEventListener('click', function(e) {
        e.stopPropagation();
        pkCalViewMonth--; if (pkCalViewMonth < 0) { pkCalViewMonth = 11; pkCalViewYear--; }
        pkRenderCal();
    });
    gid('pk-att-cal-next').addEventListener('click', function(e) {
        e.stopPropagation();
        pkCalViewMonth++; if (pkCalViewMonth > 11) { pkCalViewMonth = 0; pkCalViewYear++; }
        pkRenderCal();
    });
    gid('pk-att-cal-days').addEventListener('click', function(e) {
        var cell = e.target.closest('.pk-att-cal-day');
        if (!cell || cell.classList.contains('pk-cal-other') || !cell.dataset.iso) return;
        pkSetDate(cell.dataset.iso);
    });
    gid('pk-att-cal-today').addEventListener('click', function(e) {
        e.stopPropagation();
        pkSetDate(pkToIso(new Date()));
    });
    // Close calendar when clicking outside
    document.addEventListener('click', function(e) {
        if (!gid('pk-att-cal') || gid('pk-att-cal').style.display === 'none') return;
        if (!gid('pk-att-cal').contains(e.target) && e.target !== gid('pk-att-date-display')) {
            pkCloseCal();
        }
    });

    // --- Search scope buttons (park / kingdom / global) ---
    var pkAttScope = 'park';
    var pkScopePlaceholders = {
        'park':    'Search within your park\u2026',
        'kingdom': 'Search within your kingdom\u2026',
        'global':  'Search by name or KD:PK\u2026'
    };
    function pkSetScope(scope) {
        pkAttScope = scope;
        ['park', 'kingdom', 'global'].forEach(function(s) {
            var btn = gid('pk-att-scope-' + s);
            if (btn) btn.classList.toggle('pk-att-scope-active', s === scope);
        });
        var input = gid('pk-att-player-name');
        if (input) {
            input.placeholder = pkScopePlaceholders[scope];
            if (input.value.trim().length >= 2) {
                $(input).autocomplete('search', input.value);
            }
        }
    }
    (function() {
        ['park', 'kingdom', 'global'].forEach(function(s) {
            var btn = gid('pk-att-scope-' + s);
            if (btn) btn.addEventListener('click', function() { pkSetScope(s); });
        });
    }());

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

    // --- Player autocomplete (grouped: park → kingdom → other, excluding already-entered) ---
    function pkAttAbbr(v) {
        return (v.KAbbr && v.PAbbr) ? v.KAbbr + ':' + v.PAbbr : (v.ParkName || '');
    }
    var pkAttAC = $('#pk-att-player-name').autocomplete({
        source: function(req, res) {
            var s = req.term;
            function toItems(list) {
                var items = [];
                $.each(list || [], function(i, v) {
                    if (pkAttEntered[parseInt(v.MundaneId, 10)]) return;
                    items.push({ label: v.Persona + ' \u2014 ' + pkAttAbbr(v), name: v.Persona, value: v.MundaneId, suspended: !!(v.PenaltyBox || v.Suspended) });
                });
                return items;
            }
            if (pkAttScope === 'park') {
                $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, park_id: PkConfig.parkId, limit: 12 })
                    .done(function(r) { res(toItems(r)); });
            } else if (pkAttScope === 'kingdom') {
                $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, kingdom_id: PkConfig.kingdomId, limit: 12 })
                    .done(function(r) { res(toItems(r)); });
            } else {
                $.when(
                    $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, park_id: PkConfig.parkId, limit: 8 }),
                    $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, kingdom_id: PkConfig.kingdomId, limit: 8 }),
                    $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, limit: 8 })
                ).done(function(parkRes, kingRes, allRes) {
                    var seen = {}, parkItems = [], kingItems = [], otherItems = [];
                    $.each(parkRes[0] || [], function(i, v) {
                        if (pkAttEntered[parseInt(v.MundaneId, 10)]) return;
                        seen[v.MundaneId] = true;
                        parkItems.push({ label: v.Persona + ' \u2014 ' + pkAttAbbr(v), name: v.Persona, value: v.MundaneId, suspended: !!(v.PenaltyBox || v.Suspended) });
                    });
                    $.each(kingRes[0] || [], function(i, v) {
                        if (pkAttEntered[parseInt(v.MundaneId, 10)]) return;
                        if (seen[v.MundaneId]) return;
                        seen[v.MundaneId] = true;
                        kingItems.push({ label: v.Persona + ' \u2014 ' + pkAttAbbr(v), name: v.Persona, value: v.MundaneId, suspended: !!(v.PenaltyBox || v.Suspended) });
                    });
                    $.each(allRes[0] || [], function(i, v) {
                        if (pkAttEntered[parseInt(v.MundaneId, 10)]) return;
                        if (seen[v.MundaneId]) return;
                        otherItems.push({ label: v.Persona + ' \u2014 ' + pkAttAbbr(v), name: v.Persona, value: v.MundaneId, suspended: !!(v.PenaltyBox || v.Suspended) });
                    });
                    var sep = { label: '', name: '', value: null, separator: true };
                    var items = parkItems;
                    if (kingItems.length) { if (items.length) items.push(sep); items = items.concat(kingItems); }
                    if (otherItems.length) { if (items.length) items.push(sep); items = items.concat(otherItems); }
                    res(items);
                });
            }
        },
        focus: function(e, ui) { if (!ui.item.value) return false; $('#pk-att-player-name').val(ui.item.name); return false; },
        select: function(e, ui) {
            if (!ui.item.value) return false;
            $('#pk-att-player-name').val(ui.item.name);
            $('#pk-att-player-id').val(ui.item.value);
            // Pre-fill class from last class map
            var lastCls = pkLastClass[parseInt(ui.item.value, 10)];
            if (lastCls) {
                pkBuildClassOptions();
                gid('pk-att-class-select').value = String(lastCls);
            }
            pkAttUpdateAddBtn();
            return false;
        },
        change: function(e, ui) { if (!ui.item) { $('#pk-att-player-id').val(''); pkAttUpdateAddBtn(); } return false; },
        delay: 250, minLength: 2,
    });
    $('#pk-att-player-name').on('input', function() {
        if (!$(this).val()) { pkAttAC.autocomplete('close'); $('#pk-att-player-id').val(''); pkAttUpdateAddBtn(); }
    });
    pkAttAC.data('autocomplete')._renderItem = function(ul, item) {
        if (item.separator) {
            return $('<li class="pk-att-ac-sep">').appendTo(ul);
        }
        var a = $('<a>');
        if (item.suspended) {
            a.addClass('pk-att-ac-suspended').html(
                '<i class="fas fa-ban" style="margin-right:5px;font-size:11px"></i>' + $('<span>').text(item.label).html()
            );
        } else {
            a.text(item.label);
        }
        return $('<li></li>').data('item.autocomplete', item).append(a).appendTo(ul);
    };
});

// ---- Shared: pronoun picker helper ----
function setupPronounPicker(cfg) {
    // cfg: { toggleId, panelId, previewId, applyId, clearId, hiddenId, standardSelId,
    //        subjectId, objectId, possId, posspId, reflexId, existingJson }
    var gid = function(id) { return document.getElementById(id); };
    var toggleBtn   = gid(cfg.toggleId);
    var panel       = gid(cfg.panelId);
    var preview     = gid(cfg.previewId);
    var applyBtn    = gid(cfg.applyId);
    var clearBtn    = gid(cfg.clearId);
    var hiddenInput = gid(cfg.hiddenId);
    var standardSel = gid(cfg.standardSelId);
    if (!toggleBtn || !panel || !hiddenInput) return;

    function getSelText(selId) {
        var sel = gid(selId);
        return sel ? Array.prototype.slice.call(sel.selectedOptions || sel.querySelectorAll('option:checked')).map(function(o) { return o.textContent; }) : [];
    }
    function getSelVals(selId) {
        var sel = gid(selId);
        return sel ? Array.prototype.slice.call(sel.selectedOptions || sel.querySelectorAll('option:checked')).map(function(o) { return parseInt(o.value, 10); }) : [];
    }
    function updatePreview() {
        var s = getSelText(cfg.subjectId), o = getSelText(cfg.objectId),
            p = getSelText(cfg.possId),   pp = getSelText(cfg.posspId), r = getSelText(cfg.reflexId);
        var any = [s,o,p,pp,r].some(function(a) { return a.length > 0; });
        if (preview) preview.textContent = any ? s.join('/') + ' [' + o.join('/') + ' ' + p.join('/') + ' ' + pp.join('/') + ' ' + r.join('/') + ']' : '';
    }
    function clearSelections() {
        [cfg.subjectId, cfg.objectId, cfg.possId, cfg.posspId, cfg.reflexId].forEach(function(sid) {
            var sel = gid(sid);
            if (sel) Array.prototype.slice.call(sel.options).forEach(function(opt) { opt.selected = false; });
        });
        hiddenInput.value = '';
        if (preview) preview.textContent = '';
    }
    function populateFromJson(json) {
        if (!json) return;
        var data; try { data = JSON.parse(json); } catch(e) { return; }
        var mapping = [[cfg.subjectId, data.s],[cfg.objectId, data.o],[cfg.possId, data.p],[cfg.posspId, data.pp],[cfg.reflexId, data.r]];
        mapping.forEach(function(pair) {
            var sel = gid(pair[0]), vals = pair[1] || [];
            if (!sel) return;
            Array.prototype.slice.call(sel.options).forEach(function(opt) {
                opt.selected = vals.indexOf(parseInt(opt.value, 10)) !== -1;
            });
        });
        updatePreview();
    }

    toggleBtn.addEventListener('click', function() {
        if (panel.style.display === 'none') {
            if (hiddenInput.value) populateFromJson(hiddenInput.value);
            panel.style.display = '';
        } else {
            panel.style.display = 'none';
        }
    });

    if (standardSel) {
        standardSel.addEventListener('change', function() {
            if (standardSel.value) clearSelections();
        });
    }

    if (applyBtn) applyBtn.addEventListener('click', function() {
        var s = getSelVals(cfg.subjectId), o = getSelVals(cfg.objectId),
            p = getSelVals(cfg.possId),   pp = getSelVals(cfg.posspId), r = getSelVals(cfg.reflexId);
        var any = [s,o,p,pp,r].some(function(a) { return a.length > 0; });
        if (any) {
            hiddenInput.value = JSON.stringify({
                s:  s.length  ? s  : [0], o:  o.length  ? o  : [0],
                p:  p.length  ? p  : [0], pp: pp.length ? pp : [0], r: r.length ? r : [0]
            });
            if (standardSel) standardSel.value = '';
            updatePreview();
        }
        panel.style.display = 'none';
    });

    if (clearBtn) clearBtn.addEventListener('click', function() {
        clearSelections();
        panel.style.display = 'none';
    });

    [cfg.subjectId, cfg.objectId, cfg.possId, cfg.posspId, cfg.reflexId].forEach(function(sid) {
        var sel = gid(sid);
        if (sel) sel.addEventListener('change', updatePreview);
    });

    // Pre-populate preview from existing value
    if (cfg.existingJson) populateFromJson(cfg.existingJson);

    return { reset: function() { clearSelections(); if (standardSel) standardSel.value = ''; } };
}

// ---- Add Player Modal (Kingdomnew) ----
(function() {
    if (typeof KnConfig === 'undefined' || !KnConfig.canManage) return;

    var CREATE_URL = KnConfig.uir + 'PlayerAjax/park/';

    function gid(id) { return document.getElementById(id); }
    function showFeedback(el, msg, ok) {
        el.textContent = msg;
        el.className = 'plr-feedback ' + (ok ? 'plr-ok' : 'plr-err');
        el.style.display = '';
    }
    function hideFeedback(el) { el.style.display = 'none'; }

    window.knOpenAddPlayerModal = function() {
        var ov = gid('kn-addplayer-overlay');
        if (!ov) return;
        gid('kn-addplayer-persona').value  = '';
        gid('kn-addplayer-given').value    = '';
        gid('kn-addplayer-surname').value  = '';
        gid('kn-addplayer-email').value    = '';
        gid('kn-addplayer-username').value = '';
        gid('kn-addplayer-password').value = '';
        gid('kn-addplayer-waiver-row').style.display = 'none';
        ov.querySelectorAll('input[type=radio]').forEach(function(r) { if (r.value === '0') r.checked = true; });
        hideFeedback(gid('kn-addplayer-feedback'));
        // Populate park dropdown once
        var sel = gid('kn-addplayer-park');
        if (!sel.dataset.built) {
            (KnConfig.parkEditLookup || []).forEach(function(p) {
                if (p.Active !== 'Active') return;
                var opt = document.createElement('option');
                opt.value = p.ParkId;
                opt.textContent = p.Name;
                sel.appendChild(opt);
            });
            sel.dataset.built = '1';
        }
        sel.value = '';
        ov.classList.add('kn-addplayer-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { gid('kn-addplayer-persona').focus(); }, 50);
    };

    window.knCloseAddPlayerModal = function() {
        var ov = gid('kn-addplayer-overlay');
        if (ov) ov.classList.remove('kn-addplayer-open');
        document.body.style.overflow = '';
    };

    $(document).ready(function() {
        if (!gid('kn-addplayer-overlay')) return;

        gid('kn-addplayer-close-btn').addEventListener('click', knCloseAddPlayerModal);
        gid('kn-addplayer-cancel').addEventListener('click',    knCloseAddPlayerModal);
        gid('kn-addplayer-overlay').addEventListener('click', function(e) {
            if (e.target === this) knCloseAddPlayerModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('kn-addplayer-overlay') && gid('kn-addplayer-overlay').classList.contains('kn-addplayer-open'))
                knCloseAddPlayerModal();
        });

        gid('kn-addplayer-submit').addEventListener('click', function() {
            var feedback = gid('kn-addplayer-feedback');
            var parkId   = gid('kn-addplayer-park').value;
            var persona  = gid('kn-addplayer-persona').value.trim();
            var username = gid('kn-addplayer-username').value.trim();
            var password = gid('kn-addplayer-password').value;
            if (!parkId)             { showFeedback(feedback, 'Please select a park.', false);                       return; }
            if (!persona)            { showFeedback(feedback, 'Persona is required.', false);                        return; }
            if (!username)           { showFeedback(feedback, 'Username is required.', false);                       return; }
            if (username.length < 4) { showFeedback(feedback, 'Username must be at least 4 characters.', false);    return; }
            if (!password)           { showFeedback(feedback, 'Password is required.', false);                       return; }

            var btn = gid('kn-addplayer-submit');
            btn.disabled = true;

            var fd = new FormData();
            fd.append('Persona',   persona);
            fd.append('GivenName', gid('kn-addplayer-given').value.trim());
            fd.append('Surname',   gid('kn-addplayer-surname').value.trim());
            fd.append('Email',     gid('kn-addplayer-email').value.trim());
            fd.append('UserName',  username);
            fd.append('Password',  password);
            var restricted = document.querySelector('input[name="kn-addplayer-restricted"]:checked');
            var waivered   = document.querySelector('input[name="kn-addplayer-waivered"]:checked');
            fd.append('Restricted', restricted ? restricted.value : '0');
            fd.append('Waivered',   waivered   ? waivered.value   : '0');
            var waiverFile = gid('kn-addplayer-waiver');
            if (waiverFile && waiverFile.files[0]) fd.append('Waiver', waiverFile.files[0]);

            $.ajax({
                url:         CREATE_URL + parkId + '/create',
                type:        'POST',
                data:        fd,
                processData: false,
                contentType: false,
                dataType:    'json',
                success: function(r) {
                    if (r && r.status === 0 && r.mundaneId) {
                        window.location.href = KnConfig.uir + 'Player/profile/' + r.mundaneId;
                    } else {
                        btn.disabled = false;
                        showFeedback(feedback, (r && r.error) ? r.error : 'Create failed.', false);
                    }
                },
                error: function() {
                    btn.disabled = false;
                    showFeedback(feedback, 'Request failed. Please try again.', false);
                }
            });
        });
    });

})();

// ---- Add Player Modal (Parknew) ----
(function() {
    if (typeof PkConfig === 'undefined' || !PkConfig.canManage) return;

    var CREATE_URL = PkConfig.uir + 'PlayerAjax/park/' + PkConfig.parkId + '/create';

    function gid(id) { return document.getElementById(id); }
    function showFeedback(el, msg, ok) {
        el.textContent = msg;
        el.className = 'plr-feedback ' + (ok ? 'plr-ok' : 'plr-err');
        el.style.display = '';
    }
    function hideFeedback(el) { el.style.display = 'none'; }

    window.pkOpenAddPlayerModal = function() {
        var ov = gid('pk-addplayer-overlay');
        if (!ov) return;
        gid('pk-addplayer-persona').value  = '';
        gid('pk-addplayer-given').value    = '';
        gid('pk-addplayer-surname').value  = '';
        gid('pk-addplayer-email').value    = '';
        gid('pk-addplayer-username').value = '';
        gid('pk-addplayer-password').value = '';
        gid('pk-addplayer-waiver-row').style.display = 'none';
        ov.querySelectorAll('input[type=radio]').forEach(function(r) { if (r.value === '0') r.checked = true; });
        hideFeedback(gid('pk-addplayer-feedback'));
        ov.classList.add('pk-addplayer-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { gid('pk-addplayer-persona').focus(); }, 50);
    };

    window.pkCloseAddPlayerModal = function() {
        var ov = gid('pk-addplayer-overlay');
        if (ov) ov.classList.remove('pk-addplayer-open');
        document.body.style.overflow = '';
    };

    $(document).ready(function() {
        if (!gid('pk-addplayer-overlay')) return;

        gid('pk-addplayer-close-btn').addEventListener('click', pkCloseAddPlayerModal);
        gid('pk-addplayer-cancel').addEventListener('click',    pkCloseAddPlayerModal);
        gid('pk-addplayer-overlay').addEventListener('click', function(e) {
            if (e.target === this) pkCloseAddPlayerModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('pk-addplayer-overlay') && gid('pk-addplayer-overlay').classList.contains('pk-addplayer-open'))
                pkCloseAddPlayerModal();
        });

        gid('pk-addplayer-submit').addEventListener('click', function() {
            var feedback = gid('pk-addplayer-feedback');
            var persona  = gid('pk-addplayer-persona').value.trim();
            var username = gid('pk-addplayer-username').value.trim();
            var password = gid('pk-addplayer-password').value;
            if (!persona)            { showFeedback(feedback, 'Persona is required.', false);                        return; }
            if (!username)           { showFeedback(feedback, 'Username is required.', false);                       return; }
            if (username.length < 4) { showFeedback(feedback, 'Username must be at least 4 characters.', false);    return; }
            if (!password)           { showFeedback(feedback, 'Password is required.', false);                       return; }

            var btn = gid('pk-addplayer-submit');
            btn.disabled = true;

            var fd = new FormData();
            fd.append('Persona',   persona);
            fd.append('GivenName', gid('pk-addplayer-given').value.trim());
            fd.append('Surname',   gid('pk-addplayer-surname').value.trim());
            fd.append('Email',     gid('pk-addplayer-email').value.trim());
            fd.append('UserName',  username);
            fd.append('Password',  password);
            var restricted = document.querySelector('input[name="pk-addplayer-restricted"]:checked');
            var waivered   = document.querySelector('input[name="pk-addplayer-waivered"]:checked');
            fd.append('Restricted', restricted ? restricted.value : '0');
            fd.append('Waivered',   waivered   ? waivered.value   : '0');
            var waiverFile = gid('pk-addplayer-waiver');
            if (waiverFile && waiverFile.files[0]) fd.append('Waiver', waiverFile.files[0]);

            $.ajax({
                url:         CREATE_URL,
                type:        'POST',
                data:        fd,
                processData: false,
                contentType: false,
                dataType:    'json',
                success: function(r) {
                    if (r && r.status === 0 && r.mundaneId) {
                        window.location.href = PkConfig.uir + 'Player/profile/' + r.mundaneId;
                    } else {
                        btn.disabled = false;
                        showFeedback(feedback, (r && r.error) ? r.error : 'Create failed.', false);
                    }
                },
                error: function() {
                    btn.disabled = false;
                    showFeedback(feedback, 'Request failed. Please try again.', false);
                }
            });
        });
    });

})();

// ---- Playernew: Award Edit + Delete ----
(function() {
    if (typeof PnConfig === 'undefined' || !PnConfig.canEditAdmin) return;

    function gid(id) { return document.getElementById(id); }

    var currentAwardsId = 0;
    var currentAwardIsHistorical = false;

    function buildEditRankPills(isLadder, currentRank, awardName) {
        var wrap    = gid('pn-edit-rank-pills');
        var rankRow = gid('pn-edit-rank-row');
        if (!wrap) return;
        wrap.innerHTML = '';
        if (!isLadder) {
            if (rankRow) rankRow.style.display = 'none';
            gid('pn-edit-rank-val') && (gid('pn-edit-rank-val').value = '');
            return;
        }
        var maxRank = /zodiac/i.test(awardName || '') ? 12 : 10;
        if (rankRow) rankRow.style.display = '';
        for (var i = 1; i <= maxRank; i++) {
            var pill = document.createElement('button');
            pill.type        = 'button';
            pill.className   = 'pn-rank-pill' + (i == currentRank ? ' pn-rank-selected' : '');
            pill.textContent = i;
            pill.dataset.rank = i;
            (function(p, rank) {
                p.addEventListener('click', function() {
                    wrap.querySelectorAll('.pn-rank-pill').forEach(function(el) { el.classList.remove('pn-rank-selected'); });
                    p.classList.add('pn-rank-selected');
                    gid('pn-edit-rank-val').value = rank;
                });
            })(pill, i);
            wrap.appendChild(pill);
        }
        gid('pn-edit-rank-val') && (gid('pn-edit-rank-val').value = currentRank || '');
    }

    window.pnOpenAwardEditModal = function(awardsId, data) {
        console.log('[EditAward] Opening modal — awardsId:', awardsId, 'data:', data);
        currentAwardsId = awardsId;
        currentAwardIsHistorical = data.IsHistorical === 1
            || (data.GivenById == 0 && data.ParkId == 0 && data.KingdomId == 0 && data.EventId == 0);

        /* reconcile banner — show only for historical (unreconciled) records */
        var banner = gid('pn-edit-reconcile-banner');
        if (banner) banner.style.display = currentAwardIsHistorical ? '' : 'none';
        var rcCheck  = gid('pn-edit-reconcile-check');
        var rcFields = gid('pn-edit-reconcile-fields');
        var rcSelect = gid('pn-edit-reconcile-award');
        var rcRankRow = gid('pn-edit-reconcile-rank-row');
        var rcRankPills = gid('pn-edit-reconcile-rank-pills');
        var rcRankVal = gid('pn-edit-reconcile-rank-val');
        if (rcCheck)  { rcCheck.checked = false; }
        if (rcFields) { rcFields.style.display = 'none'; }
        if (rcSelect) { rcSelect.value = ''; }
        if (rcRankRow) { rcRankRow.style.display = 'none'; }
        if (rcRankPills) { rcRankPills.innerHTML = ''; }
        if (rcRankVal)   { rcRankVal.value = ''; }

        /* auto-match historical award name to reconcile dropdown */
        if (currentAwardIsHistorical && rcSelect) {
            /* strip parenthetical suffix e.g. "Warrior (2nd)" → "Warrior" */
            var histName = (data.displayName || data.Name || '').replace(/\s*\(.*\)/, '').trim();
            if (histName) {
                var histLower = histName.toLowerCase();
                for (var _i = 0; _i < rcSelect.options.length; _i++) {
                    if (rcSelect.options[_i].textContent.toLowerCase().indexOf(histLower) !== -1) {
                        rcSelect.value = rcSelect.options[_i].value;
                        break;
                    }
                }
            }
        }

        var nameEl = gid('pn-edit-award-name');
        if (nameEl) nameEl.textContent = data.displayName || data.Name || '';
        buildEditRankPills(data.IsLadder == 1, data.Rank, data.displayName || data.Name || '');
        var dateEl = gid('pn-edit-award-date');
        if (dateEl) dateEl.value = data.Date || '';
        var gbText = gid('pn-edit-givenby-text');
        var gbId   = gid('pn-edit-givenby-id');
        if (gbText) gbText.value = data.GivenBy || '';
        if (gbId)   gbId.value   = data.GivenById || '';
        var gaText = gid('pn-edit-givenat-text');
        var gaPark = gid('pn-edit-park-id');
        var gaKing = gid('pn-edit-kingdom-id');
        var gaEvt  = gid('pn-edit-event-id');
        if (gaText) gaText.value = data.EventId > 0
            ? (data.EventName || '')
            : (data.ParkName ? data.ParkName + (data.KingdomName ? ', ' + data.KingdomName : '') : (data.KingdomName || ''));
        if (gaPark) gaPark.value = data.EventId > 0 ? 0 : (data.ParkId    || 0);
        if (gaKing) gaKing.value = data.EventId > 0 ? 0 : (data.KingdomId || 0);
        if (gaEvt)  gaEvt.value  = data.EventId || 0;
        var noteEl  = gid('pn-edit-award-note');
        var countEl = gid('pn-edit-award-char-count');
        if (noteEl) {
            noteEl.value = data.Note || '';
            if (countEl) countEl.textContent = (AWARD_NOTE_MAX_CHARS - noteEl.value.length) + ' characters remaining';
        }
        var fb = gid('pn-edit-award-feedback');
        if (fb) { fb.style.display = 'none'; fb.textContent = ''; }
        var overlay = gid('pn-award-edit-overlay');
        if (overlay) { overlay.classList.add('pn-open'); document.body.style.overflow = 'hidden'; }
    };

    function pnCloseAwardEditModal() {
        var overlay = gid('pn-award-edit-overlay');
        if (overlay) { overlay.classList.remove('pn-open'); document.body.style.overflow = ''; }
    }

    $(document).ready(function() {
        var SEARCH_URL = PnConfig.httpService + 'Search/SearchService.php';

        // Given By autocomplete (edit modal)
        var editGbText    = gid('pn-edit-givenby-text');
        var editGbId      = gid('pn-edit-givenby-id');
        var editGbResults = gid('pn-edit-givenby-results');
        if (editGbText && editGbId && editGbResults) {
            var editGbTimer;
            editGbText.addEventListener('input', function() {
                clearTimeout(editGbTimer);
                editGbId.value = '';
                console.log('[EditAward] Given By text changed — id cleared, text now:', this.value);
                var term = this.value.trim();
                if (term.length < 2) { editGbResults.classList.remove('pn-ac-open'); return; }
                editGbTimer = setTimeout(function() {
                    var url = SEARCH_URL + '?Action=Search%2FPlayer&type=all&search=' + encodeURIComponent(term) + '&kingdom_id=' + PnConfig.kingdomId + '&limit=8';
                    fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                        if (!data || !data.length) {
                            editGbResults.innerHTML = '<div class="pn-ac-no-results">No players found</div>';
                        } else {
                            editGbResults.innerHTML = data.map(function(p) {
                                return '<div class="pn-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                                    + escHtml(p.Persona)
                                    + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr || '') + ':' + escHtml(p.PAbbr || '') + ')</span>'
                                    + '</div>';
                            }).join('');
                        }
                        editGbResults.classList.add('pn-ac-open');
                    }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
                }, AUTOCOMPLETE_DEBOUNCE_MS);
            });
            editGbResults.addEventListener('click', function(e) {
                var item = e.target.closest ? e.target.closest('.pn-ac-item') : (e.target.classList.contains('pn-ac-item') ? e.target : null);
                if (!item) return;
                editGbText.value = decodeURIComponent(item.dataset.name);
                editGbId.value   = item.dataset.id;
                console.log('[EditAward] Given By selected — id:', editGbId.value, 'name:', editGbText.value);
                editGbResults.classList.remove('pn-ac-open');
            });
            acKeyNav(editGbText, editGbResults, 'pn-ac-open', '.pn-ac-item');
        }

        // Given At autocomplete (edit modal)
        var editGaText    = gid('pn-edit-givenat-text');
        var editGaPark    = gid('pn-edit-park-id');
        var editGaKing    = gid('pn-edit-kingdom-id');
        var editGaEvt     = gid('pn-edit-event-id');
        var editGaResults = gid('pn-edit-givenat-results');
        if (editGaText && editGaResults) {
            var editGaTimer;
            editGaText.addEventListener('input', function() {
                clearTimeout(editGaTimer);
                if (editGaPark) editGaPark.value = '0';
                if (editGaKing) editGaKing.value = '0';
                if (editGaEvt)  editGaEvt.value  = '0';
                var term = this.value.trim();
                if (term.length < 2) { editGaResults.classList.remove('pn-ac-open'); return; }
                editGaTimer = setTimeout(function() {
                    var today = new Date().toISOString().slice(0, 10);
                    var url = SEARCH_URL + '?Action=Search%2FLocation&name=' + encodeURIComponent(term) + '&date=' + today + '&limit=8';
                    fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                        if (!data || !data.length) {
                            editGaResults.innerHTML = '<div class="pn-ac-no-results">No locations found</div>';
                        } else {
                            editGaResults.innerHTML = data.map(function(loc) {
                                return '<div class="pn-ac-item" tabindex="-1"'
                                    + ' data-park="' + (parseInt(loc.ParkId) || 0) + '"'
                                    + ' data-kingdom="' + (parseInt(loc.KingdomId) || 0) + '"'
                                    + ' data-event="' + (parseInt(loc.EventId) || 0) + '"'
                                    + ' data-name="' + encodeURIComponent(loc.ShortName || loc.LocationName || '') + '">'
                                    + escHtml(loc.LocationName || '') + '</div>';
                            }).join('');
                        }
                        editGaResults.classList.add('pn-ac-open');
                    }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
                }, AUTOCOMPLETE_DEBOUNCE_MS);
            });
            editGaResults.addEventListener('click', function(e) {
                var item = e.target.closest ? e.target.closest('.pn-ac-item') : (e.target.classList.contains('pn-ac-item') ? e.target : null);
                if (!item) return;
                editGaText.value = decodeURIComponent(item.dataset.name);
                if (editGaPark) editGaPark.value = item.dataset.park    || '0';
                if (editGaKing) editGaKing.value = item.dataset.kingdom || '0';
                if (editGaEvt)  editGaEvt.value  = item.dataset.event   || '0';
                editGaResults.classList.remove('pn-ac-open');
            });
            acKeyNav(editGaText, editGaResults, 'pn-ac-open', '.pn-ac-item');
        }

        // Officer chip clicks inside edit modal — also close autocomplete dropdowns
        $(document).on('click', '#pn-award-edit-overlay .pn-officer-chip', function() {
            var id   = $(this).data('id');
            var name = $(this).data('name');
            var gbT  = gid('pn-edit-givenby-text');
            var gbI  = gid('pn-edit-givenby-id');
            var gbR  = gid('pn-edit-givenby-results');
            if (gbT) gbT.value = name;
            if (gbI) gbI.value = id;
            if (gbR) gbR.classList.remove('pn-ac-open');
            $('#pn-award-edit-overlay .pn-officer-chip').removeClass('pn-chip-active');
            $(this).addClass('pn-chip-active');
        });

        // Close edit modal autocomplete dropdowns on outside click
        var editOverlay = gid('pn-award-edit-overlay');
        if (editOverlay) {
            editOverlay.addEventListener('click', function(e) {
                var gbT = gid('pn-edit-givenby-text'),  gbR = gid('pn-edit-givenby-results');
                var gaT = gid('pn-edit-givenat-text'),  gaR = gid('pn-edit-givenat-results');
                if (gbR && e.target !== gbT && !gbR.contains(e.target)) gbR.classList.remove('pn-ac-open');
                if (gaR && e.target !== gaT && !gaR.contains(e.target)) gaR.classList.remove('pn-ac-open');
            });
        }

        // Note char counter
        var noteEl  = gid('pn-edit-award-note');
        var countEl = gid('pn-edit-award-char-count');
        if (noteEl && countEl) {
            noteEl.addEventListener('input', function() {
                countEl.textContent = (AWARD_NOTE_MAX_CHARS - this.value.length) + ' characters remaining';
            });
        }

        // Reconcile checkbox toggle
        var rcCheck  = gid('pn-edit-reconcile-check');
        var rcFields = gid('pn-edit-reconcile-fields');
        if (rcCheck && rcFields) {
            rcCheck.addEventListener('change', function() {
                rcFields.style.display = this.checked ? '' : 'none';
                if (this.checked) {
                    var sel = gid('pn-edit-reconcile-award');
                    if (sel && sel.value) sel.dispatchEvent(new Event('change'));
                }
            });
        }

        // Reconcile award select — show rank pills if ladder, suggest next rank
        var rcSelect   = gid('pn-edit-reconcile-award');
        var rcRankRow  = gid('pn-edit-reconcile-rank-row');
        var rcRankPills = gid('pn-edit-reconcile-rank-pills');
        var rcRankVal  = gid('pn-edit-reconcile-rank-val');
        if (rcSelect) {
            rcSelect.addEventListener('change', function() {
                if (!rcRankRow || !rcRankPills || !rcRankVal) return;
                var opt = this.options[this.selectedIndex];
                var isLadder = opt && opt.getAttribute('data-is-ladder') === '1';
                var awardId  = opt ? (parseInt(opt.getAttribute('data-award-id')) || 0) : 0;
                var awardName = opt ? (opt.textContent || '') : '';
                rcRankRow.style.display = isLadder ? '' : 'none';
                rcRankPills.innerHTML   = '';
                rcRankVal.value         = '';
                if (!isLadder) return;

                /* suggest next rank = max held rank + 1, capped at maxRank */
                var heldMax    = (PnConfig.awardRanks && awardId) ? (PnConfig.awardRanks[awardId] || 0) : 0;
                var suggested  = heldMax + 1;
                var maxRank    = /zodiac/i.test(awardName) ? 12 : 10;
                if (suggested > maxRank) suggested = 0;

                for (var i = 1; i <= maxRank; i++) {
                    var pill = document.createElement('button');
                    pill.type      = 'button';
                    pill.className = 'pn-rank-pill'
                        + (i <= heldMax    ? ' pn-rank-held'     : '')
                        + (i === suggested ? ' pn-rank-suggested' : '');
                    pill.textContent  = i;
                    pill.dataset.rank = i;
                    (function(p, rank) {
                        p.addEventListener('click', function() {
                            rcRankPills.querySelectorAll('.pn-rank-pill').forEach(function(el) { el.classList.remove('pn-rank-selected'); });
                            p.classList.add('pn-rank-selected');
                            rcRankVal.value = rank;
                        });
                    })(pill, i);
                    rcRankPills.appendChild(pill);
                }
                /* auto-select the suggested rank */
                if (suggested > 0) {
                    var sugPill = rcRankPills.querySelector('[data-rank="' + suggested + '"]');
                    if (sugPill) { sugPill.classList.add('pn-rank-selected'); rcRankVal.value = suggested; }
                }
            });
        }

        // Save
        var saveBtn = gid('pn-edit-award-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                var date = gid('pn-edit-award-date') ? gid('pn-edit-award-date').value : '';
                var fb   = gid('pn-edit-award-feedback');
                function showFb(msg, cls) {
                    if (!fb) return;
                    fb.textContent = msg;
                    fb.style.display = '';
                    fb.className = cls;
                    fb.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                if (!date) {
                    showFb('Date is required.', 'pn-form-error');
                    return;
                }

                /* validate Given By — text entered but no player selected from autocomplete */
                var gbText = gid('pn-edit-givenby-text');
                var gbId   = gid('pn-edit-givenby-id');
                if (gbText && gbText.value.trim() && gbId && !gbId.value) {
                    showFb('Please select a player from the search dropdown for "Given By".', 'pn-form-error');
                    gbText.focus();
                    return;
                }

                /* check if reconcile conversion is requested */
                var doReconcile   = currentAwardIsHistorical
                    && gid('pn-edit-reconcile-check')
                    && gid('pn-edit-reconcile-check').checked;
                var kingdomAwardId = gid('pn-edit-reconcile-award') ? gid('pn-edit-reconcile-award').value : '';
                if (doReconcile && !kingdomAwardId) {
                    showFb('Please select a target award to convert to.', 'pn-form-error');
                    return;
                }

                saveBtn.disabled = true;
                var fd = new FormData();
                console.log('[EditAward] Save clicked — currentAwardsId:', currentAwardsId,
                    'date:', gid('pn-edit-award-date') ? gid('pn-edit-award-date').value : 'N/A',
                    'givenByText:', gid('pn-edit-givenby-text') ? gid('pn-edit-givenby-text').value : 'N/A',
                    'givenById:', gid('pn-edit-givenby-id') ? gid('pn-edit-givenby-id').value : 'N/A',
                    'note:', gid('pn-edit-award-note') ? gid('pn-edit-award-note').value : 'N/A',
                    'parkId:', gid('pn-edit-park-id') ? gid('pn-edit-park-id').value : 'N/A',
                    'kingdomId:', gid('pn-edit-kingdom-id') ? gid('pn-edit-kingdom-id').value : 'N/A',
                    'eventId:', gid('pn-edit-event-id') ? gid('pn-edit-event-id').value : 'N/A',
                    'rank:', gid('pn-edit-rank-val') ? gid('pn-edit-rank-val').value : 'N/A',
                    'doReconcile:', typeof doReconcile !== 'undefined' ? doReconcile : false
                );
                fd.append('Date',      date);
                fd.append('GivenById', gid('pn-edit-givenby-id')  ? gid('pn-edit-givenby-id').value  : '');
                fd.append('Note',      gid('pn-edit-award-note')   ? gid('pn-edit-award-note').value  : '');
                fd.append('ParkId',    gid('pn-edit-park-id')      ? gid('pn-edit-park-id').value     : 0);
                fd.append('KingdomId', gid('pn-edit-kingdom-id')   ? gid('pn-edit-kingdom-id').value  : 0);
                fd.append('EventId',   gid('pn-edit-event-id')     ? gid('pn-edit-event-id').value    : 0);

                var endpoint;
                if (doReconcile) {
                    fd.append('KingdomAwardId', kingdomAwardId);
                    fd.append('Rank', gid('pn-edit-reconcile-rank-val') ? (gid('pn-edit-reconcile-rank-val').value || 0) : 0);
                    endpoint = PnConfig.uir + 'Admin/player/' + PnConfig.playerId + '/reconcileaward/' + currentAwardsId;
                } else {
                    fd.append('Rank', gid('pn-edit-rank-val') ? gid('pn-edit-rank-val').value : '');
                    endpoint = PnConfig.uir + 'Admin/player/' + PnConfig.playerId + '/updateaward/' + currentAwardsId;
                }

                console.log('[EditAward] POST to endpoint:', endpoint);
                fetch(endpoint, {
                    method: 'POST', body: fd
                }).then(function(r) {
                    console.log('[EditAward] Response — status:', r.status, 'ok:', r.ok, 'url:', r.url);
                    return r.clone().text().then(function(body) {
                        console.log('[EditAward] Response body (first 500 chars):', body.substring(0, 500));
                        saveBtn.disabled = false;
                        if (r.ok) {
                            if (doReconcile) gtag('event', 'reconcile_submit', { type: 'award' });
                            var msg = doReconcile ? 'Award reconciled!' : 'Award updated!';
                            showFb(msg, 'pn-award-edit-success');
                            setTimeout(function() { location.reload(); }, 900);
                        } else {
                            showFb('Save failed (server error ' + r.status + ').', 'pn-form-error');
                        }
                    });
                }).catch(function() {
                    saveBtn.disabled = false;
                    showFb('Request failed.', 'pn-form-error');
                });
            });
        }

        // Cancel / Close
        var cancelBtn = gid('pn-edit-award-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', pnCloseAwardEditModal);
        var closeBtn  = gid('pn-edit-award-close-btn');
        if (closeBtn)  closeBtn.addEventListener('click', pnCloseAwardEditModal);
        var overlay = gid('pn-award-edit-overlay');
        if (overlay) overlay.addEventListener('click', function(e) { if (e.target === this) pnCloseAwardEditModal(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('pn-award-edit-overlay') && gid('pn-award-edit-overlay').classList.contains('pn-open'))
                pnCloseAwardEditModal();
        });

        // Award Delete
        $(document).on('click', '.pn-award-del-btn', function() {
            var btn      = this;
            var row      = $(this).closest('tr');
            var awardsId = $(this).data('awards-id');
            var kind     = $(this).closest('table').is('#pn-titles-table') ? 'title' : 'award';
            if (!awardsId) return;
            pnConfirm({
                title:       'Delete ' + (kind === 'title' ? 'Title' : 'Award'),
                message:     'Delete this ' + kind + ' record? This cannot be undone.',
                confirmText: 'Delete',
                danger:      true
            }, function() {
                btn.disabled = true;
                fetch(PnConfig.uir + 'PlayerAjax/player/' + PnConfig.playerId + '/deleteaward', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'AwardsId=' + encodeURIComponent(awardsId),
                }).then(function(r) { return r.json(); }).then(function(result) {
                    if (result && result.status === 0) {
                        location.reload();
                    } else {
                        btn.disabled = false;
                        pnConfirm({ title: 'Error', message: (result && result.error) ? result.error : 'Delete failed.', confirmText: 'OK', danger: false }, function() {});
                    }
                }).catch(function() {
                    btn.disabled = false;
                    pnConfirm({ title: 'Error', message: 'Request failed.', confirmText: 'OK', danger: false }, function() {});
                });
            });
        });

        // Award Edit — open modal
        $(document).on('click', '.pn-award-edit-btn', function() {
            var awardsId = $(this).data('awards-id');
            var data     = $(this).data('award');
            if (!awardsId || !data) return;
            pnOpenAwardEditModal(awardsId, data);
        });
    });
})();

// ---- Playernew: Dues Revoke ----
(function() {
    if (typeof PnConfig === 'undefined' || !PnConfig.canEditAdmin) return;

    $(document).ready(function() {
        $(document).on('click', '.pn-dues-revoke-btn', function() {
            var btn    = this;
            var duesId = $(this).data('dues-id');
            if (!duesId) return;
            pnConfirm({ title: 'Revoke Dues', message: 'Revoke this dues record? This cannot be undone.', confirmText: 'Revoke', danger: true }, function() {
                btn.disabled = true;
                fetch(PnConfig.uir + 'Admin/player/' + PnConfig.playerId + '/revokedues/' + duesId, {
                    method: 'POST'
                }).then(function(r) {
                    if (!r.ok) throw new Error('Server returned ' + r.status);
                    window.location.reload();
                }).catch(function() {
                    btn.disabled = false;
                    alert('Request failed.');
                });
            });
        });
    });
})();

// ---- Parknew: Edit Officers ----
(function() {
    if (typeof PkConfig === 'undefined' || !PkConfig.canManage) return;

    var OFFICER_ROLES = ['Monarch', 'Regent', 'Prime Minister', 'Champion', 'GMR'];
    var SET_URL    = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/setofficers';
    var VACATE_URL = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/vacateofficer';
    var SEARCH_URL = PkConfig.httpService + 'Search/SearchService.php';

    function gid(id) { return document.getElementById(id); }
    function roleSlug(role) { return role.replace(/ /g, '_'); }

    function buildOfficerMap() {
        var map = {};
        (PkConfig.officerList || []).forEach(function(o) { map[o.OfficerRole] = o; });
        return map;
    }

    function showFeedback(msg, ok) {
        var el = gid('pk-editoff-feedback');
        if (!el) return;
        el.textContent = msg;
        el.className = 'pk-editoff-feedback ' + (ok ? 'pk-editoff-ok' : 'pk-editoff-err');
        el.style.display = '';
    }
    function hideFeedback() {
        var el = gid('pk-editoff-feedback');
        if (el) { el.style.display = 'none'; el.textContent = ''; }
    }

    window.pkOpenEditOfficersModal = function() {
        var overlay = gid('pk-editoff-overlay');
        if (!overlay) return;
        buildRows();
        hideFeedback();
        overlay.classList.add('pk-open');
        document.body.style.overflow = 'hidden';
    };

    function pkCloseEditOfficersModal() {
        var overlay = gid('pk-editoff-overlay');
        if (!overlay) return;
        overlay.classList.remove('pk-open');
        document.body.style.overflow = '';
    }

    var rowsBuilt = false;
    function buildRows() {
        var officerMap = buildOfficerMap();
        if (rowsBuilt) {
            OFFICER_ROLES.forEach(function(role) {
                var slug   = roleSlug(role);
                var o      = officerMap[role];
                var nameEl = gid('pk-editoff-name-' + slug);
                var idEl   = gid('pk-editoff-id-'   + slug);
                var vacBtn = gid('pk-editoff-vacate-' + slug);
                if (nameEl && idEl) {
                    nameEl.value = (o && o.MundaneId > 0) ? o.Persona   : '';
                    idEl.value   = (o && o.MundaneId > 0) ? o.MundaneId : '';
                }
                if (vacBtn) vacBtn.style.display = (o && o.MundaneId > 0) ? '' : 'none';
            });
            return;
        }
        rowsBuilt = true;
        var container = gid('pk-editoff-rows');
        if (!container) return;
        container.innerHTML = '';

        OFFICER_ROLES.forEach(function(role) {
            var slug     = roleSlug(role);
            var o        = officerMap[role];
            var occupied = o && o.MundaneId > 0;

            var row = document.createElement('div');
            row.className = 'pk-editoff-row';

            var label = document.createElement('div');
            label.className   = 'pk-editoff-role-label';
            label.textContent = role;
            row.appendChild(label);

            var wrap = document.createElement('div');
            wrap.className = 'pk-editoff-player-wrap';

            var nameInput = document.createElement('input');
            nameInput.type         = 'text';
            nameInput.id           = 'pk-editoff-name-' + slug;
            nameInput.className    = 'pk-editoff-name-input';
            nameInput.autocomplete = 'off';
            nameInput.placeholder  = 'Search players\u2026';
            if (occupied) nameInput.value = o.Persona;
            wrap.appendChild(nameInput);

            var hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.id   = 'pk-editoff-id-' + slug;
            if (occupied) hiddenInput.value = o.MundaneId;
            wrap.appendChild(hiddenInput);
            row.appendChild(wrap);

            var vacateBtn = document.createElement('button');
            vacateBtn.type          = 'button';
            vacateBtn.id            = 'pk-editoff-vacate-' + slug;
            vacateBtn.className     = 'pk-editoff-vacate-btn';
            vacateBtn.textContent   = 'Vacate';
            vacateBtn.style.display = occupied ? '' : 'none';
            (function(r, btn, ni, hi) {
                btn.addEventListener('click', function() {
                    pnConfirm({
                        title: 'Vacate Position?',
                        message: 'Remove the current ' + r + '?',
                        confirmText: 'Vacate',
                        danger: true
                    }, function() {
                        btn.disabled    = true;
                        btn.textContent = '\u2026';
                        $.post(VACATE_URL, { Role: r }, function(result) {
                            if (result && result.status === 0) {
                                ni.value = ''; hi.value = '';
                                btn.style.display = 'none';
                                btn.disabled      = false;
                                btn.textContent   = 'Vacate';
                                (PkConfig.officerList || []).forEach(function(off) {
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
                });
            })(role, vacateBtn, nameInput, hiddenInput);
            row.appendChild(vacateBtn);

            container.appendChild(row);

            (function(ni, hi, vb) {
                $(ni).autocomplete({
                    source: function(req, res) {
                        $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: req.term, kingdom_id: PkConfig.kingdomId, park_id: PkConfig.parkId, limit: 12 }, function(data) {
                            res($.map(data || [], function(v) { return { label: v.Persona, value: v.MundaneId }; }));
                        });
                    },
                    focus:  function(e, ui) { $(ni).val(ui.item.label); return false; },
                    select: function(e, ui) { $(ni).val(ui.item.label); hi.value = ui.item.value; vb.style.display = ''; return false; },
                    change: function(e, ui) { if (!ui.item) hi.value = ''; return false; },
                    delay: 250, minLength: 2,
                });
            })(nameInput, hiddenInput, vacateBtn);
        });
    }

    $(document).ready(function() {
        var submitBtn = gid('pk-editoff-submit');
        if (submitBtn) {
            submitBtn.addEventListener('click', function() {
                var data   = {};
                var hasAny = false;
                OFFICER_ROLES.forEach(function(role) {
                    var idEl = gid('pk-editoff-id-' + roleSlug(role));
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

        var cancelBtn = gid('pk-editoff-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', pkCloseEditOfficersModal);
        var closeBtn  = gid('pk-editoff-close-btn');
        if (closeBtn)  closeBtn.addEventListener('click', pkCloseEditOfficersModal);
        var overlay = gid('pk-editoff-overlay');
        if (overlay) overlay.addEventListener('click', function(e) { if (e.target === this) pkCloseEditOfficersModal(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('pk-editoff-overlay') && gid('pk-editoff-overlay').classList.contains('pk-open'))
                pkCloseEditOfficersModal();
        });
    });
})();

// ---- Parknew: Park Day Management ----
(function() {
    if (typeof PkConfig === 'undefined' || !PkConfig.canManage) return;

    var ADD_DAY_URL    = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/addparkday';
    var DELETE_DAY_URL = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/deleteparkday';

    function gid(id) { return document.getElementById(id); }

    function pkUpdateRecurrenceFields(recurrence) {
        var weekdayRow  = gid('pk-addday-weekday-row');
        var weekofRow   = gid('pk-addday-weekof-row');
        var monthdayRow = gid('pk-addday-monthday-row');
        if (weekdayRow)  weekdayRow.style.display  = (recurrence === 'weekly' || recurrence === 'week-of-month') ? '' : 'none';
        if (weekofRow)   weekofRow.style.display   = (recurrence === 'week-of-month') ? '' : 'none';
        if (monthdayRow) monthdayRow.style.display = (recurrence === 'monthly') ? '' : 'none';
    }

    function pkToggleAltLoc(show) {
        var block = gid('pk-addday-altloc-block');
        if (block) block.style.display = show ? '' : 'none';
    }

    function pkGetAddDayLocType() {
        var sel = document.querySelector('input[name="pk-addday-altloc"]:checked');
        return sel ? sel.value : '0'; // '0' = park, '1' = alternate, 'online' = online
    }

    function pkOnAddDayLocChange() {
        var val = pkGetAddDayLocType();
        pkToggleAltLoc(val === '1');
    }

    window.pkOpenAddDayModal = function() {
        var overlay = gid('pk-addday-overlay');
        if (!overlay) return;
        var fb = gid('pk-addday-feedback');
        if (fb) { fb.style.display = 'none'; fb.textContent = ''; }
        var timeEl = gid('pk-addday-time');
        if (timeEl) timeEl.value = '';
        var descEl = gid('pk-addday-desc');
        if (descEl) descEl.value = '';
        overlay.querySelectorAll('.pk-seg-btn[data-group="purpose"]').forEach(function(btn) {
            btn.classList.toggle('pk-seg-active', btn.dataset.val === 'fighter-practice');
        });
        var purposeHid = gid('pk-addday-purpose');
        if (purposeHid) purposeHid.value = 'fighter-practice';
        overlay.querySelectorAll('.pk-seg-btn[data-group="recurrence"]').forEach(function(btn) {
            btn.classList.toggle('pk-seg-active', btn.dataset.val === 'weekly');
        });
        var recHid = gid('pk-addday-recurrence');
        if (recHid) recHid.value = 'weekly';
        pkUpdateRecurrenceFields('weekly');
        overlay.querySelectorAll('input[name="pk-addday-altloc"]').forEach(function(radio) {
            radio.checked = radio.value === '0';
        });
        pkToggleAltLoc(false); // reset altloc block
        overlay.classList.add('pk-open');
        document.body.style.overflow = 'hidden';
    };

    function pkCloseAddDayModal() {
        var overlay = gid('pk-addday-overlay');
        if (!overlay) return;
        overlay.classList.remove('pk-open');
        document.body.style.overflow = '';
    }

    $(document).ready(function() {
        // Segmented buttons
        $(document).on('click', '.pk-seg-btn', function() {
            var group = $(this).data('group');
            var val   = $(this).data('val');
            $('.pk-seg-btn[data-group="' + group + '"]').removeClass('pk-seg-active');
            $(this).addClass('pk-seg-active');
            var hidden = gid('pk-addday-' + group);
            if (hidden) hidden.value = val;
            if (group === 'recurrence') pkUpdateRecurrenceFields(val);
        });

        // Location type toggle (park / alternate / online)
        $(document).on('change', 'input[name="pk-addday-altloc"]', function() {
            pkOnAddDayLocChange();
        });

        // Close
        var cancelBtn = gid('pk-addday-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', pkCloseAddDayModal);
        var closeBtn  = gid('pk-addday-close-btn');
        if (closeBtn)  closeBtn.addEventListener('click', pkCloseAddDayModal);
        var overlay = gid('pk-addday-overlay');
        if (overlay) overlay.addEventListener('click', function(e) { if (e.target === this) pkCloseAddDayModal(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('pk-addday-overlay') && gid('pk-addday-overlay').classList.contains('pk-open'))
                pkCloseAddDayModal();
        });

        // Add Park Day — save
        var saveBtn = gid('pk-addday-submit');
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                var recurrence = gid('pk-addday-recurrence') ? gid('pk-addday-recurrence').value : '';
                var time       = gid('pk-addday-time')       ? gid('pk-addday-time').value.trim() : '';
                var fb         = gid('pk-addday-feedback');
                if (!recurrence) { if (fb) { fb.textContent = 'Recurrence is required.'; fb.style.display = ''; fb.className = 'pk-addday-err'; } return; }
                if (!time)       { if (fb) { fb.textContent = 'Time is required.';       fb.style.display = ''; fb.className = 'pk-addday-err'; } return; }
                saveBtn.disabled = true;
                var fd = new FormData();
                fd.append('Recurrence',        recurrence);
                fd.append('Time',              time);
                fd.append('Purpose',           gid('pk-addday-purpose')  ? gid('pk-addday-purpose').value  : 'other');
                fd.append('Description',       gid('pk-addday-desc')     ? gid('pk-addday-desc').value     : '');
                fd.append('WeekDay',           gid('pk-addday-weekday')  ? gid('pk-addday-weekday').value  : '');
                fd.append('WeekOfMonth',       gid('pk-addday-weekof')   ? gid('pk-addday-weekof').value   : 0);
                fd.append('MonthDay',          gid('pk-addday-monthday') ? gid('pk-addday-monthday').value : 0);
                var locType = pkGetAddDayLocType();
                fd.append('Online',            locType === 'online' ? '1' : '0');
                fd.append('AlternateLocation', locType === '1'      ? '1' : '0');
                fd.append('Address',           gid('pk-addday-address')  ? gid('pk-addday-address').value  : '');
                fd.append('City',              gid('pk-addday-city')     ? gid('pk-addday-city').value     : '');
                fd.append('Province',          gid('pk-addday-province') ? gid('pk-addday-province').value : '');
                fd.append('PostalCode',        gid('pk-addday-postal')   ? gid('pk-addday-postal').value   : '');
                fetch(ADD_DAY_URL, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(result) {
                        saveBtn.disabled = false;
                        if (result && result.status === 0) {
                            if (fb) { fb.textContent = 'Park day added!'; fb.style.display = ''; fb.className = 'pk-addday-ok'; }
                            setTimeout(function() { location.reload(); }, 800);
                        } else {
                            if (fb) { fb.textContent = (result && result.error) ? result.error : 'Save failed.'; fb.style.display = ''; fb.className = 'pk-addday-err'; }
                        }
                    }).catch(function() {
                        saveBtn.disabled = false;
                        if (fb) { fb.textContent = 'Request failed.'; fb.style.display = ''; fb.className = 'pk-addday-err'; }
                    });
            });
        }

        // Delete park day
        $(document).on('click', '.pk-schedule-card-del', function() {
            var btn       = this;
            var card      = $(this).closest('.pk-schedule-card');
            var parkDayId = $(this).data('park-day-id');
            if (!parkDayId) return;
            knConfirm('Remove this park day? This cannot be undone.', function() {
                btn.disabled = true;
                var fd = new FormData();
                fd.append('ParkDayId', parkDayId);
                fetch(DELETE_DAY_URL, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(result) {
                        if (result && result.status === 0) {
                            card.fadeOut(300, function() { card.remove(); });
                        } else {
                            btn.disabled = false;
                            alert((result && result.error) ? result.error : 'Delete failed.');
                        }
                    }).catch(function() {
                        btn.disabled = false;
                        alert('Request failed.');
                    });
            });
        });
    });
})();


// ---- Playernew: Revoke Award ----
(function() {
    if (typeof PnConfig === 'undefined' || !PnConfig.canEditAdmin) return;

    function gid(id) { return document.getElementById(id); }

    var currentRevokeAwardsId = 0;
    var currentRevokeAwardName = '';

    window.pnOpenAwardRevokeModal = function(awardsId, awardName, isTitle) {
        currentRevokeAwardsId = awardsId;
        currentRevokeAwardName = awardName;
        var nameEl = gid('pn-revoke-award-name');
        if (nameEl) nameEl.textContent = awardName;
        var titleEl = document.querySelector('#pn-award-revoke-overlay .pn-modal-title');
        if (titleEl) titleEl.innerHTML = '<i class="fas fa-ban" style="margin-right:8px;color:#b7791f"></i>' + (isTitle ? 'Revoke Title' : 'Revoke Award');
        var saveBtn = gid('pn-revoke-award-save');
        if (saveBtn) saveBtn.innerHTML = '<i class="fas fa-ban"></i> ' + (isTitle ? 'Revoke Title' : 'Revoke Award');
        var reason = gid('pn-revoke-reason');
        if (reason) reason.value = '';
        var counter = gid('pn-revoke-char-count');
        if (counter) counter.textContent = '300 characters remaining';
        var fb = gid('pn-revoke-award-feedback');
        if (fb) { fb.style.display = 'none'; fb.textContent = ''; }
        var overlay = gid('pn-award-revoke-overlay');
        if (overlay) { overlay.classList.add('pn-open'); document.body.style.overflow = 'hidden'; }
    };

    function pnCloseAwardRevokeModal() {
        var overlay = gid('pn-award-revoke-overlay');
        if (overlay) { overlay.classList.remove('pn-open'); document.body.style.overflow = ''; }
    }

    $(document).ready(function() {
        // Close button
        $(document).on('click', '#pn-revoke-award-close-btn, #pn-revoke-award-cancel', function() {
            pnCloseAwardRevokeModal();
        });
        $(document).on('click', '#pn-award-revoke-overlay', function(e) {
            if ($(e.target).is('#pn-award-revoke-overlay')) pnCloseAwardRevokeModal();
        });

        // Char counter
        $(document).on('input', '#pn-revoke-reason', function() {
            var el = gid('pn-revoke-char-count');
            if (el) el.textContent = (300 - this.value.length) + ' characters remaining';
        });

        // Revoke award row button
        $(document).on('click', '.pn-award-revoke-btn', function() {
            var awardsId  = $(this).data('awards-id');
            var data      = $(this).data('award');
            if (typeof data === 'string') { try { data = JSON.parse(data); } catch(e) { data = {}; } }
            var name    = (data && data.displayName) ? data.displayName : ('Award #' + awardsId);
            var isTitle = !!(data && data.IsTitle);
            pnOpenAwardRevokeModal(awardsId, name, isTitle);
        });

        // Save
        $(document).on('click', '#pn-revoke-award-save', function() {
            var reason = (gid('pn-revoke-reason') || {}).value || '';
            var fb     = gid('pn-revoke-award-feedback');
            if (!reason.trim()) {
                if (fb) { fb.textContent = 'Revocation reason is required.'; fb.style.display = ''; fb.className = 'pn-form-error'; }
                return;
            }
            var btn = this;
            btn.disabled = true;
            btn.textContent = 'Revoking...';
            if (fb) fb.style.display = 'none';
            fetch(PnConfig.uir + 'PlayerAjax/player/' + PnConfig.playerId + '/revokeaward', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'AwardsId=' + encodeURIComponent(currentRevokeAwardsId) + '&Revocation=' + encodeURIComponent(reason.trim()),
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0) {
                    location.reload();
                } else {
                    if (fb) { fb.textContent = data.error || 'Error revoking award.'; fb.style.display = ''; fb.className = 'pn-form-error'; }
                    btn.disabled = false;
                    btn.textContent = 'Revoke Award';
                }
            })
            .catch(function() {
                if (fb) { fb.textContent = 'Request failed. Please try again.'; fb.style.display = ''; fb.className = 'pn-form-error'; }
                btn.disabled = false;
                btn.textContent = 'Revoke Award';
            });
        });
    });
})();

// ---- Playernew: Add / Edit / Delete Notes ----
(function() {
    if (typeof PnConfig === 'undefined' || !PnConfig.canEditAdmin) return;

    function gid(id) { return document.getElementById(id); }

    var editNoteId = 0; // 0 = add mode, >0 = edit mode

    window.pnOpenAddNoteModal = function(noteData) {
        editNoteId = (noteData && noteData.notesId) ? parseInt(noteData.notesId) : 0;
        var fb = gid('pn-addnote-feedback');
        if (fb) { fb.style.display = 'none'; fb.textContent = ''; }
        var titleEl = gid('pn-addnote-modal-title');
        if (titleEl) titleEl.textContent = editNoteId ? 'Edit Note' : 'Add Note';
        var saveBtn = gid('pn-addnote-save');
        if (saveBtn) saveBtn.innerHTML = editNoteId
            ? '<i class="fas fa-save"></i> Save Changes'
            : '<i class="fas fa-save"></i> Add Note';
        if (noteData) {
            var tf = gid('pn-note-title');        if (tf) tf.value = noteData.note || '';
            var df = gid('pn-note-desc');         if (df) df.value = noteData.desc || '';
            var dtf = gid('pn-note-date');        if (dtf) dtf.value = noteData.date || '';
            var dcf = gid('pn-note-date-complete'); if (dcf) dcf.value = noteData.dateComplete || '';
        } else {
            ['pn-note-title', 'pn-note-desc', 'pn-note-date-complete'].forEach(function(id) {
                var el = gid(id); if (el) el.value = '';
            });
            var dateEl = gid('pn-note-date');
            if (dateEl) dateEl.value = new Date().toISOString().slice(0, 10);
        }
        var overlay = gid('pn-addnote-overlay');
        if (overlay) { overlay.classList.add('pn-open'); document.body.style.overflow = 'hidden'; }
    };

    function pnCloseAddNoteModal() {
        var overlay = gid('pn-addnote-overlay');
        if (overlay) { overlay.classList.remove('pn-open'); document.body.style.overflow = ''; }
    }

    $(document).ready(function() {
        // Close
        $(document).on('click', '#pn-addnote-close-btn, #pn-addnote-cancel', function() { pnCloseAddNoteModal(); });
        $(document).on('click', '#pn-addnote-overlay', function(e) {
            if ($(e.target).is('#pn-addnote-overlay')) pnCloseAddNoteModal();
        });

        // Open edit modal from edit button
        $(document).on('click', '.pn-note-edit-btn', function() {
            var btn = this;
            pnOpenAddNoteModal({
                notesId:      $(btn).data('notes-id'),
                note:         $(btn).attr('data-note'),
                desc:         $(btn).attr('data-desc'),
                date:         $(btn).attr('data-date'),
                dateComplete: $(btn).attr('data-date-complete') || '',
            });
        });

        // Save note (add or edit)
        $(document).on('click', '#pn-addnote-save', function() {
            var title    = (gid('pn-note-title') || {}).value || '';
            var date     = (gid('pn-note-date')  || {}).value || '';
            var fb       = gid('pn-addnote-feedback');
            if (!title.trim()) {
                if (fb) { fb.textContent = 'Note title is required.'; fb.style.display = ''; fb.className = 'pn-form-error'; }
                return;
            }
            if (!date) {
                if (fb) { fb.textContent = 'Date is required.'; fb.style.display = ''; fb.className = 'pn-form-error'; }
                return;
            }
            var btn      = this;
            var isEdit   = editNoteId > 0;
            var desc     = (gid('pn-note-desc') || {}).value || '';
            var dateComp = (gid('pn-note-date-complete') || {}).value || '';
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            if (fb) fb.style.display = 'none';
            var body = 'Note=' + encodeURIComponent(title.trim())
                + '&Description=' + encodeURIComponent(desc)
                + '&Date=' + encodeURIComponent(date)
                + '&DateComplete=' + encodeURIComponent(dateComp);
            if (isEdit) body += '&NotesId=' + encodeURIComponent(editNoteId);
            fetch(PnConfig.uir + 'PlayerAjax/player/' + PnConfig.playerId + '/' + (isEdit ? 'editnote' : 'addnote'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body,
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0) {
                    pnCloseAddNoteModal();
                    var dateDisp  = date + (dateComp ? ' - ' + dateComp : '');
                    var safeTitle = $('<div>').text(title.trim()).html();
                    var safeDesc  = $('<div>').text(desc).html();
                    var safeDate  = $('<div>').text(dateDisp).html();
                    if (isEdit) {
                        // Update the existing row in place
                        var row = document.querySelector('tr[data-notes-id="' + editNoteId + '"]');
                        if (row) {
                            var cells = row.cells;
                            if (cells[0]) cells[0].innerHTML = safeTitle;
                            if (cells[1]) cells[1].innerHTML = safeDesc;
                            if (cells[2]) cells[2].innerHTML = safeDate;
                            var eb = row.querySelector('.pn-note-edit-btn');
                            if (eb) {
                                eb.setAttribute('data-note', title.trim());
                                eb.setAttribute('data-desc', desc);
                                eb.setAttribute('data-date', date);
                                eb.setAttribute('data-date-complete', dateComp);
                            }
                        }
                    } else {
                        // Prepend new row to the table
                        var tbody = document.querySelector('#pn-history-table tbody');
                        if (tbody) {
                            var tr = document.createElement('tr');
                            var newId = data.notesId || 0;
                            tr.setAttribute('data-notes-id', newId);
                            tr.innerHTML = '<td>' + safeTitle + '</td>'
                                + '<td>' + safeDesc + '</td>'
                                + '<td class="pn-col-nowrap">' + safeDate + '</td>'
                                + '<td>'
                                + '<button class="pn-note-edit-btn"'
                                + ' data-notes-id="' + newId + '"'
                                + ' data-note="' + $('<div>').text(title.trim()).html().replace(/"/g, '&quot;') + '"'
                                + ' data-desc="' + $('<div>').text(desc).html().replace(/"/g, '&quot;') + '"'
                                + ' data-date="' + $('<div>').text(date).html() + '"'
                                + ' data-date-complete="' + $('<div>').text(dateComp).html() + '"'
                                + ' title="Edit note"><i class="fas fa-pencil-alt"></i></button>'
                                + ' <button class="pn-note-del-btn" data-notes-id="' + newId + '" title="Delete note"><i class="fas fa-times"></i></button>'
                                + '</td>';
                            tbody.insertBefore(tr, tbody.firstChild);
                            var tabCount = document.querySelector('[data-tab="history"] .pn-tab-count');
                            if (tabCount) {
                                var n = parseInt(tabCount.textContent.replace(/[^0-9]/g, '')) || 0;
                                tabCount.textContent = '(' + (n + 1) + ')';
                            }
                            var table = document.getElementById('pn-history-table');
                            if (table) table.style.display = '';
                            var emptyState = document.getElementById('pn-history-empty');
                            if (emptyState) emptyState.style.display = 'none';
                        }
                    }
                } else {
                    if (fb) { fb.textContent = data.error || 'Error saving note.'; fb.style.display = ''; fb.className = 'pn-form-error'; }
                    btn.disabled = false;
                    btn.innerHTML = isEdit
                        ? '<i class="fas fa-save"></i> Save Changes'
                        : '<i class="fas fa-save"></i> Add Note';
                }
            })
            .catch(function() {
                if (fb) { fb.textContent = 'Request failed.'; fb.style.display = ''; fb.className = 'pn-form-error'; }
                btn.disabled = false;
                btn.innerHTML = isEdit
                    ? '<i class="fas fa-save"></i> Save Changes'
                    : '<i class="fas fa-save"></i> Add Note';
            });
        });

        // Delete note
        $(document).on('click', '.pn-note-del-btn', function() {
            var notesId = $(this).data('notes-id');
            var row     = $(this).closest('tr');
            if (!notesId || !confirm('Delete this note?')) return;
            var self = this;
            self.disabled = true;
            fetch(PnConfig.uir + 'PlayerAjax/player/' + PnConfig.playerId + '/deletenote', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'NotesId=' + encodeURIComponent(notesId),
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0) {
                    row.fadeOut(300, function() {
                        row.remove();
                        var tabCount = document.querySelector('[data-tab="history"] .pn-tab-count');
                        if (tabCount) {
                            var n = parseInt(tabCount.textContent.replace(/[^0-9]/g, '')) || 0;
                            tabCount.textContent = '(' + Math.max(0, n - 1) + ')';
                        }
                    });
                } else {
                    self.disabled = false;
                    alert(data.error || 'Error deleting note.');
                }
            })
            .catch(function() { self.disabled = false; alert('Request failed.'); });
        });
    });
})();

// ---- Parknew: Park Administration ----
(function() {
    if (typeof PkConfig === 'undefined' || !PkConfig.canManage) return;

    function gid(id) { return document.getElementById(id); }
    var AJAX_BASE = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/';

    function pkFeedback(id, msg, ok) {
        var el = gid(id);
        if (!el) return;
        el.textContent = msg;
        el.className = 'kn-admin-feedback ' + (ok ? 'kn-admin-ok' : 'kn-admin-err');
        el.style.display = '';
        if (ok) {
            clearTimeout(el._hideTimer);
            el._hideTimer = setTimeout(function() { el.style.display = 'none'; }, 5000);
        }
    }
    function pkClearFeedback(id) {
        var el = gid(id); if (el) { el.style.display = 'none'; el.textContent = ''; }
    }

    function wireToggle(hdrId, bodyId, chevId) {
        var hdr  = gid(hdrId), body = gid(bodyId), chev = gid(chevId);
        if (!hdr || !body) return;
        hdr.addEventListener('click', function() {
            var open = body.style.display !== 'none';
            body.style.display = open ? 'none' : '';
            if (chev) chev.classList.toggle('kn-admin-chevron-open', !open);
            hdr.setAttribute('aria-expanded', String(!open));
        });
    }

    var _pkDirty = false;

    window.pkOpenAdminModal = function() {
        var d = PkConfig.parkDetails || {};
        var fields = {
            'pk-editdetails-url':         d.url         || '',
            'pk-editdetails-address':     d.address     || '',
            'pk-editdetails-city':        d.city        || '',
            'pk-editdetails-province':    d.province    || '',
            'pk-editdetails-postalcode':  d.postalCode  || '',
            'pk-editdetails-mapurl':      d.mapUrl      || '',
            'pk-editdetails-description': d.description || '',
            'pk-editdetails-directions':  d.directions  || '',
        };
        Object.keys(fields).forEach(function(id) {
            var el = gid(id); if (el) el.value = fields[id];
        });
        pkClearFeedback('pk-admin-details-feedback');
        pkClearFeedback('pk-admin-ops-feedback');
        var overlay = gid('pk-admin-overlay');
        _pkDirty = false;
        if (overlay) { overlay.classList.add('pk-admin-open'); document.body.style.overflow = 'hidden'; }
    };

    function pkCloseAdminModal() {
        var overlay = gid('pk-admin-overlay');
        if (overlay) { overlay.classList.remove('pk-admin-open'); document.body.style.overflow = ''; }
        if (_pkDirty) { _pkDirty = false; setTimeout(function() { location.reload(); }, 0); }
    }

    $(document).ready(function() {
        wireToggle('pk-admin-hdr-details', 'pk-admin-body-details', 'pk-admin-chev-details');
        wireToggle('pk-admin-hdr-ops',     'pk-admin-body-ops',     'pk-admin-chev-ops');

        // Close buttons
        var overlay = gid('pk-admin-overlay');
        ['pk-admin-close-btn', 'pk-admin-done-btn'].forEach(function(id) {
            var el = gid(id); if (el) el.addEventListener('click', pkCloseAdminModal);
        });
        if (overlay) overlay.addEventListener('click', function(e) { if (e.target === this) pkCloseAdminModal(); });
        document.addEventListener('keydown', function(e) {
            var ov = gid('pk-admin-overlay');
            if (e.key === 'Escape' && ov && ov.classList.contains('pk-admin-open')) {
                var wasDirty = _pkDirty;
                pkCloseAdminModal();
                if (wasDirty) setTimeout(function() { location.reload(); }, 0);
            }
        });

        // Save Details
        var saveBtn = gid('pk-admin-details-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                pkClearFeedback('pk-admin-details-feedback');
                saveBtn.disabled = true;
                var body = 'Url='         + encodeURIComponent((gid('pk-editdetails-url')         || {}).value || '')
                    + '&Address='     + encodeURIComponent((gid('pk-editdetails-address')     || {}).value || '')
                    + '&City='        + encodeURIComponent((gid('pk-editdetails-city')        || {}).value || '')
                    + '&Province='    + encodeURIComponent((gid('pk-editdetails-province')    || {}).value || '')
                    + '&PostalCode='  + encodeURIComponent((gid('pk-editdetails-postalcode')  || {}).value || '')
                    + '&MapUrl='      + encodeURIComponent((gid('pk-editdetails-mapurl')      || {}).value || '')
                    + '&Description=' + encodeURIComponent((gid('pk-editdetails-description') || {}).value || '')
                    + '&Directions='  + encodeURIComponent((gid('pk-editdetails-directions')  || {}).value || '');
                fetch(AJAX_BASE + 'setdetails', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body,
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    saveBtn.disabled = false;
                    if (data.status === 0) {
                        pkFeedback('pk-admin-details-feedback', 'Details saved!', true);
                        _pkDirty = true;
                    } else {
                        pkFeedback('pk-admin-details-feedback', data.error || 'Error saving details.', false);
                    }
                })
                .catch(function() {
                    saveBtn.disabled = false;
                    pkFeedback('pk-admin-details-feedback', 'Request failed.', false);
                });
            });
        }

        // Reset Waivers
        var resetBtn = gid('pk-admin-reset-waivers-btn');
        if (resetBtn) {
            resetBtn.addEventListener('click', function() {
                knConfirm(
                    'This will reset all waivers for this park. This action cannot be undone.',
                    function() {
                        resetBtn.disabled = true;
                        fetch(AJAX_BASE + 'resetwaivers', { method: 'POST' })
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            resetBtn.disabled = false;
                            if (data.status === 0) {
                                pkFeedback('pk-admin-ops-feedback', data.message || 'Waivers reset.', true);
                            } else {
                                pkFeedback('pk-admin-ops-feedback', data.error || 'Reset failed.', false);
                            }
                        })
                        .catch(function() {
                            resetBtn.disabled = false;
                            pkFeedback('pk-admin-ops-feedback', 'Request failed.', false);
                        });
                    },
                    'Reset Waivers'
                );
            });
        }
    });
})();

// ---- Shared: autocomplete keyboard navigation ----
// Adds ArrowUp/Down/Enter key nav to an input+results dropdown pair.
// selectFn(item) is called when Enter is pressed on a focused item.
function setupAcKeyNav(inputEl, resultsEl, itemSel, focusedClass, selectFn) {
    var idx = -1;
    function items() { return resultsEl.querySelectorAll(itemSel); }
    function clearFocus(all) { if (idx >= 0 && all[idx]) all[idx].classList.remove(focusedClass); }
    inputEl.addEventListener('input', function() { idx = -1; });
    inputEl.addEventListener('keydown', function(e) {
        var all = items();
        if (!all.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            clearFocus(all);
            idx = Math.min(idx + 1, all.length - 1);
            all[idx].classList.add(focusedClass);
            all[idx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            clearFocus(all);
            idx = Math.max(idx - 1, 0);
            all[idx].classList.add(focusedClass);
            all[idx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' && idx >= 0 && all[idx]) {
            e.preventDefault();
            selectFn(all[idx]);
        }
    });
}

// ---- Playernew: Move Player ----
(function() {
    if (typeof PnConfig === 'undefined' || !PnConfig.canEditAdmin) return;

    var PARK_URL  = PnConfig.httpService + 'Search/SearchService.php';
    var MOVE_URL  = PnConfig.uir + 'PlayerAjax/player/' + PnConfig.playerId + '/moveplayer';
    var pnmpMode  = 'within';
    var mpParkTimer;

    function gid(id) { return document.getElementById(id); }

    function showFb(msg, ok) {
        var el = gid('pn-moveplayer-feedback');
        if (!el) return;
        el.textContent = msg;
        el.className = ok ? 'pn-form-success' : 'pn-form-error';
        el.style.display = '';
    }

    function setMode(mode) {
        pnmpMode = mode;
        ['within','out'].forEach(function(m) {
            var btn = gid('pn-mp-btn-' + m);
            if (btn) btn.classList.toggle('pn-mp-active', m === mode);
        });
        var parkLabel = gid('pn-moveplayer-park-label');
        if (mode === 'within') {
            if (parkLabel) parkLabel.innerHTML = 'New Home Park <span style="color:#e53e3e">*</span>';
        } else {
            if (parkLabel) parkLabel.innerHTML = 'Destination Park (outside kingdom) <span style="color:#e53e3e">*</span>';
        }
        // Reset park field
        var parkInput = gid('pn-moveplayer-park-name');
        if (parkInput) parkInput.value = '';
        var parkId = gid('pn-moveplayer-park-id');
        if (parkId) parkId.value = '';
        var parkResults = gid('pn-moveplayer-park-results');
        if (parkResults) parkResults.classList.remove('pn-ac-open');
        var btn = gid('pn-move-submit');
        if (btn) btn.disabled = true;
    }

    function closeMovePlayer() {
        var ov = gid('pn-moveplayer-overlay');
        if (ov) { ov.classList.remove('pn-open'); document.body.style.overflow = ''; }
    }

    window.pnOpenMovePlayerModal = function() {
        var ov = gid('pn-moveplayer-overlay');
        if (!ov) return;
        setMode('within');
        var curPark = gid('pn-move-current-park-name');
        if (curPark) curPark.textContent = PnConfig.playerParkName || '(unknown)';
        var fb = gid('pn-moveplayer-feedback');
        if (fb) { fb.style.display = 'none'; fb.textContent = ''; }
        ov.classList.add('pn-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() {
            var inp = gid('pn-moveplayer-park-name');
            if (inp) inp.focus();
        }, 50);
    };

    $(document).ready(function() {
        if (!gid('pn-moveplayer-overlay')) return;

        // Toggle buttons
        ['within','out'].forEach(function(m) {
            var btn = gid('pn-mp-btn-' + m);
            if (btn) btn.addEventListener('click', function() { setMode(m); });
        });

        // Close handlers
        $(document).on('click', '#pn-moveplayer-close-btn, #pn-move-cancel', function() { closeMovePlayer(); });
        $(document).on('click', '#pn-moveplayer-overlay', function(e) {
            if ($(e.target).is('#pn-moveplayer-overlay')) closeMovePlayer();
        });

        // Move Player button in Update Account modal
        $(document).on('click', '#pn-acct-move-player-btn', function() {
            // Close the acct modal first
            var acctOv = gid('pn-acct-overlay');
            if (acctOv) acctOv.classList.remove('pn-open');
            pnOpenMovePlayerModal();
        });

        // Park autocomplete
        var parkInput = gid('pn-moveplayer-park-name');
        if (parkInput) {
            parkInput.addEventListener('input', function() {
                gid('pn-moveplayer-park-id').value = '';
                var btn = gid('pn-move-submit');
                if (btn) btn.disabled = true;
                var term = this.value.trim();
                if (term.length < 2) {
                    var el = gid('pn-moveplayer-park-results');
                    if (el) el.classList.remove('pn-ac-open');
                    return;
                }
                clearTimeout(mpParkTimer);
                mpParkTimer = setTimeout(function() {
                    var params = { Action: 'Search/Park', name: term, limit: 12 };
                    if (pnmpMode === 'within') {
                        params.kingdom_id = PnConfig.kingdomId;
                    } else {
                        params.exclude_kingdom_id = PnConfig.kingdomId;
                    }
                    $.getJSON(PARK_URL, params, function(data) {
                        var el = gid('pn-moveplayer-park-results');
                        if (!el) return;
                        el.innerHTML = (data && data.length)
                            ? data.map(function(pk) {
                                var sub = pk.KingdomName ? ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(pk.KingdomName) + ')</span>' : '';
                                return '<div class="pn-ac-item" data-id="' + pk.ParkId + '" data-name="' + encodeURIComponent(pk.Name) + '">'
                                    + escHtml(pk.Name) + sub + '</div>';
                            }).join('')
                            : '<div class="pn-ac-item" style="color:#a0aec0;cursor:default">No parks found</div>';
                        el.classList.add('pn-ac-open');
                    });
                }, AUTOCOMPLETE_DEBOUNCE_MS);
            });

            gid('pn-moveplayer-park-results').addEventListener('click', function(e) {
                var item = e.target.closest('.pn-ac-item[data-id]');
                if (!item) return;
                gid('pn-moveplayer-park-name').value = decodeURIComponent(item.dataset.name);
                gid('pn-moveplayer-park-id').value   = item.dataset.id;
                this.classList.remove('pn-ac-open');
                var btn = gid('pn-move-submit');
                if (btn) btn.disabled = false;
            });
            setupAcKeyNav(parkInput, gid('pn-moveplayer-park-results'), '.pn-ac-item[data-id]', 'pn-ac-focused', function(item) { item.click(); });
        }

        // Submit
        $(document).on('click', '#pn-move-submit', function() {
            var parkId = gid('pn-moveplayer-park-id') ? gid('pn-moveplayer-park-id').value : '';
            if (!parkId) { showFb('Select a destination park.', false); return; }
            var parkName = gid('pn-moveplayer-park-name') ? gid('pn-moveplayer-park-name').value : 'the selected park';
            if (!confirm('Move this player to ' + parkName + '? Their Park Member Since date will be reset.')) return;
            var btn = this;
            btn.disabled = true;
            var fb = gid('pn-moveplayer-feedback');
            if (fb) fb.style.display = 'none';
            fetch(MOVE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ParkId=' + encodeURIComponent(parkId),
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0) {
                    location.reload();
                } else {
                    showFb(data.error || 'Error moving player.', false);
                    btn.disabled = false;
                }
            })
            .catch(function() {
                showFb('Request failed.', false);
                btn.disabled = false;
            });
        });
    });
})();

// ---- Playernew: Revoke All Awards ----
(function() {
    if (typeof PnConfig === 'undefined' || !PnConfig.canEditAdmin) return;

    function gid(id) { return document.getElementById(id); }
    var countdownTimer = null;

    function startCountdown() {
        var btn = gid('pn-revoke-all-save');
        if (!btn) return;
        btn.disabled = true;
        var secs = 5;
        btn.innerHTML = '<i class="fas fa-ban"></i> Revoke All Awards (' + secs + ')';
        countdownTimer = setInterval(function() {
            secs--;
            if (secs <= 0) {
                clearInterval(countdownTimer);
                countdownTimer = null;
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-ban"></i> Revoke All Awards';
            } else {
                btn.innerHTML = '<i class="fas fa-ban"></i> Revoke All Awards (' + secs + ')';
            }
        }, 1000);
    }

    function cancelCountdown() {
        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
        var btn = gid('pn-revoke-all-save');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-ban"></i> Revoke All Awards'; }
    }

    window.pnOpenRevokeAllModal = function() {
        var fb = gid('pn-revoke-all-feedback');
        if (fb) { fb.style.display = 'none'; fb.textContent = ''; }
        var reason = gid('pn-revoke-all-reason');
        if (reason) reason.value = '';
        var counter = gid('pn-revoke-all-char-count');
        if (counter) counter.textContent = '300 characters remaining';
        var overlay = gid('pn-revoke-all-overlay');
        if (overlay) { overlay.classList.add('pn-open'); document.body.style.overflow = 'hidden'; }
        startCountdown();
    };

    function pnCloseRevokeAllModal() {
        cancelCountdown();
        var overlay = gid('pn-revoke-all-overlay');
        if (overlay) { overlay.classList.remove('pn-open'); document.body.style.overflow = ''; }
    }

    $(document).ready(function() {
        $(document).on('click', '#pn-revoke-all-close-btn, #pn-revoke-all-cancel', function() { pnCloseRevokeAllModal(); });
        $(document).on('click', '#pn-revoke-all-overlay', function(e) {
            if ($(e.target).is('#pn-revoke-all-overlay')) pnCloseRevokeAllModal();
        });
        $(document).on('input', '#pn-revoke-all-reason', function() {
            var el = gid('pn-revoke-all-char-count');
            if (el) el.textContent = (300 - this.value.length) + ' characters remaining';
        });
        $(document).on('click', '#pn-revoke-all-save', function() {
            var reason = (gid('pn-revoke-all-reason') || {}).value || '';
            var fb     = gid('pn-revoke-all-feedback');
            if (!reason.trim()) {
                if (fb) { fb.textContent = 'Revocation reason is required.'; fb.style.display = ''; fb.className = 'pn-form-error'; }
                return;
            }
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            if (fb) fb.style.display = 'none';
            fetch(PnConfig.uir + 'PlayerAjax/player/' + PnConfig.playerId + '/revokeallawards', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'Revocation=' + encodeURIComponent(reason),
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0) {
                    location.reload();
                } else {
                    if (fb) { fb.textContent = data.error || 'Error revoking awards.'; fb.style.display = ''; fb.className = 'pn-form-error'; }
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-ban"></i> Revoke All Awards';
                }
            })
            .catch(function() {
                if (fb) { fb.textContent = 'Request failed.'; fb.style.display = ''; fb.className = 'pn-form-error'; }
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-ban"></i> Revoke All Awards';
            });
        });
    });
})();

// ---- Playernew: Class Reconciliation ----
(function() {
    if (typeof PnConfig === 'undefined' || !PnConfig.canEditAdmin) return;

    function gid(id) { return document.getElementById(id); }

    window.pnOpenReconcileModal = function() {
        var fb = gid('pn-reconcile-feedback');
        if (fb) { fb.style.display = 'none'; fb.textContent = ''; }
        var tbody = gid('pn-reconcile-tbody');
        if (tbody) {
            tbody.innerHTML = '';
            (PnConfig.classList || []).forEach(function(c) {
                var tr = document.createElement('tr');
                var tdName = document.createElement('td');
                tdName.textContent = c.ClassName;
                tr.appendChild(tdName);
                var tdBase = document.createElement('td');
                tdBase.className = 'pn-col-numeric';
                tdBase.textContent = c.Credits;
                tr.appendChild(tdBase);
                var tdAdj = document.createElement('td');
                tdAdj.className = 'pn-col-numeric';
                var inp = document.createElement('input');
                inp.type = 'number'; inp.step = '1'; inp.style.cssText = 'width:64px;padding:3px 6px;border:1px solid #cbd5e0;border-radius:4px;text-align:right;font-size:13px';
                inp.value = String(c.Reconciled || 0);
                inp.dataset.classId = c.ClassId;
                inp.addEventListener('input', function() {
                    var total = (parseFloat(c.Credits) || 0) + (parseInt(this.value) || 0);
                    tr.querySelector('.pn-reconcile-total').textContent = total;
                });
                tdAdj.appendChild(inp);
                tr.appendChild(tdAdj);
                var tdTotal = document.createElement('td');
                tdTotal.className = 'pn-col-numeric pn-reconcile-total';
                tdTotal.textContent = (parseFloat(c.Credits) || 0) + (parseInt(c.Reconciled) || 0);
                tr.appendChild(tdTotal);
                tbody.appendChild(tr);
            });
        }
        gtag('event', 'reconcile_open', { type: 'class' });
        var overlay = gid('pn-reconcile-overlay');
        if (overlay) { overlay.classList.add('pn-open'); document.body.style.overflow = 'hidden'; }
    };

    function pnCloseReconcileModal() {
        var overlay = gid('pn-reconcile-overlay');
        if (overlay) { overlay.classList.remove('pn-open'); document.body.style.overflow = ''; }
    }

    $(document).ready(function() {
        $(document).on('click', '#pn-reconcile-close-btn, #pn-reconcile-cancel', function() { pnCloseReconcileModal(); });
        $(document).on('click', '#pn-reconcile-overlay', function(e) {
            if ($(e.target).is('#pn-reconcile-overlay')) pnCloseReconcileModal();
        });
        $(document).on('click', '#pn-reconcile-save', function() {
            var btn = this;
            var fb  = gid('pn-reconcile-feedback');
            var inputs = document.querySelectorAll('#pn-reconcile-tbody input[type=number]');
            var params = 'ParkId=' + encodeURIComponent(PnConfig.parkId);
            inputs.forEach(function(inp) {
                params += '&Reconciled%5B' + encodeURIComponent(inp.dataset.classId) + '%5D=' + encodeURIComponent(inp.value || '0');
            });
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            if (fb) fb.style.display = 'none';
            fetch(PnConfig.uir + 'PlayerAjax/player/' + PnConfig.playerId + '/updateclasses', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params,
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0) {
                    gtag('event', 'reconcile_submit', { type: 'class' });
                    location.reload();
                } else {
                    if (fb) { fb.textContent = data.error || 'Error saving reconciliation.'; fb.style.display = ''; fb.className = 'pn-form-error'; }
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save"></i> Save';
                }
            })
            .catch(function() {
                if (fb) { fb.textContent = 'Request failed.'; fb.style.display = ''; fb.className = 'pn-form-error'; }
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save';
            });
        });
    });
})();

// ---- Create Unit Modal (Playernew) ----
window.pnOpenUnitCreateModal = function() {
    var el = document.getElementById('pn-unit-create-overlay');
    if (!el) return;
    el.classList.add('pn-open');
    document.body.style.overflow = 'hidden';
    var nameInput = el.querySelector('input[name="Name"]');
    if (nameInput) setTimeout(function() { nameInput.focus(); }, 50);
};
window.pnCloseUnitCreateModal = function() {
    var el = document.getElementById('pn-unit-create-overlay');
    if (!el) return;
    el.classList.remove('pn-open');
    document.body.style.overflow = '';
};
(function() {
    $(document).ready(function() {
        var overlay = document.getElementById('pn-unit-create-overlay');
        if (!overlay) return;
        overlay.addEventListener('click', function(e) { if (e.target === this) pnCloseUnitCreateModal(); });
        document.addEventListener('keydown', function(e) {
            if ((e.key === 'Escape' || e.keyCode === 27) && overlay.classList.contains('pn-open'))
                pnCloseUnitCreateModal();
        });
    });
})();

// ---- Move Player Modal (Kingdomnew) ----
(function() {
    if (typeof KnConfig === 'undefined' || !KnConfig.canManage) return;

    var MOVE_URL    = KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/moveplayer';
    var PSEARCH_URL = KnConfig.uir + 'KingdomAjax/playersearch/' + KnConfig.kingdomId;
    var PARK_URL    = KnConfig.httpService + 'Search/SearchService.php';

    // Modes: 'in' | 'within' | 'out'
    var knmpMode = 'in';

    function gid(id) { return document.getElementById(id); }

    function showFb(msg, ok) {
        var el = gid('kn-moveplayer-feedback');
        el.textContent = msg;
        el.className = ok ? 'kn-editoff-feedback kn-editoff-ok' : 'kn-editoff-feedback kn-editoff-err';
        el.style.display = '';
    }

    function closeMovePlayer() {
        var ov = gid('kn-moveplayer-overlay');
        if (ov) ov.classList.remove('kn-open');
        document.body.style.overflow = '';
    }

    function knmpCheckSubmit() {
        var hasPlayer = !!gid('kn-moveplayer-player-id').value;
        var hasPark   = !!gid('kn-moveplayer-park-id').value;
        var btn = gid('kn-moveplayer-submit');
        if (btn) btn.disabled = !(hasPlayer && hasPark);
    }

    function setMode(mode) {
        knmpMode = mode;
        ['in','within','out'].forEach(function(m) {
            var btn = gid('kn-mp-btn-' + m);
            if (btn) btn.classList.toggle('kn-mp-active', m === mode);
        });
        var playerInput = gid('kn-moveplayer-player-name');
        var parkInput   = gid('kn-moveplayer-park-name');
        var playerLabel = gid('kn-moveplayer-player-label');
        var parkLabel   = gid('kn-moveplayer-park-label');
        if (mode === 'in') {
            playerInput.placeholder = 'Search by name, or KD:PK name\u2026';
            parkInput.placeholder   = 'Search parks in this kingdom\u2026';
            if (playerLabel) playerLabel.innerHTML = 'Player (outside kingdom) <span style="color:#e53e3e">*</span>';
            if (parkLabel)   parkLabel.innerHTML   = 'Destination Park (in kingdom) <span style="color:#e53e3e">*</span>';
        } else if (mode === 'within') {
            playerInput.placeholder = 'Search kingdom members\u2026';
            parkInput.placeholder   = 'Search parks in this kingdom\u2026';
            if (playerLabel) playerLabel.innerHTML = 'Player <span style="color:#e53e3e">*</span>';
            if (parkLabel)   parkLabel.innerHTML   = 'New Home Park <span style="color:#e53e3e">*</span>';
        } else {
            playerInput.placeholder = 'Search kingdom members\u2026';
            parkInput.placeholder   = 'Search parks outside this kingdom\u2026';
            if (playerLabel) playerLabel.innerHTML = 'Player <span style="color:#e53e3e">*</span>';
            if (parkLabel)   parkLabel.innerHTML   = 'Destination Park (outside kingdom) <span style="color:#e53e3e">*</span>';
        }
        // Reset fields
        playerInput.value = '';
        parkInput.value   = '';
        gid('kn-moveplayer-player-id').value = '';
        gid('kn-moveplayer-park-id').value   = '';
        gid('kn-moveplayer-player-results').classList.remove('kn-ac-open');
        gid('kn-moveplayer-park-results').classList.remove('kn-ac-open');
        gid('kn-moveplayer-feedback').style.display = 'none';
        knmpCheckSubmit();
    }

    window.knOpenMovePlayerModal = function() {
        var ov = gid('kn-moveplayer-overlay');
        if (!ov) return;
        setMode('in');
        ov.classList.add('kn-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { gid('kn-moveplayer-player-name').focus(); }, 50);
    };

    var mpPlayerTimer, mpParkTimer;

    $(document).ready(function() {
        if (!gid('kn-moveplayer-overlay')) return;

        gid('kn-moveplayer-close-btn').addEventListener('click', closeMovePlayer);
        gid('kn-moveplayer-cancel').addEventListener('click', closeMovePlayer);
        gid('kn-moveplayer-overlay').addEventListener('click', function(e) {
            if (e.target === this) closeMovePlayer();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('kn-moveplayer-overlay') && gid('kn-moveplayer-overlay').classList.contains('kn-open'))
                closeMovePlayer();
        });

        ['in','within','out'].forEach(function(m) {
            var btn = gid('kn-mp-btn-' + m);
            if (btn) btn.addEventListener('click', function() { setMode(m); });
        });

        // Player autocomplete
        gid('kn-moveplayer-player-name').addEventListener('input', function() {
            gid('kn-moveplayer-player-id').value = '';
            clearTimeout(mpPlayerTimer);
            var term = this.value.trim();
            if (term.length < 2) { gid('kn-moveplayer-player-results').classList.remove('kn-ac-open'); return; }
            mpPlayerTimer = setTimeout(function() {
                var scope = (knmpMode === 'in') ? 'exclude' : 'own';
                fetch(PSEARCH_URL + '&scope=' + scope + '&q=' + encodeURIComponent(term))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var el = gid('kn-moveplayer-player-results');
                        el.innerHTML = (data && data.length)
                            ? data.map(function(p) {
                                return '<div class="kn-ac-item" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                                    + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr || '') + ':' + escHtml(p.PAbbr || '') + ')</span></div>';
                            }).join('')
                            : '<div class="kn-ac-item" style="color:#a0aec0;cursor:default">No players found</div>';
                        el.classList.add('kn-ac-open');
                    }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
            }, AUTOCOMPLETE_DEBOUNCE_MS);
        });
        gid('kn-moveplayer-player-results').addEventListener('click', function(e) {
            var item = e.target.closest('.kn-ac-item[data-id]');
            if (!item) return;
            gid('kn-moveplayer-player-name').value = decodeURIComponent(item.dataset.name);
            gid('kn-moveplayer-player-id').value   = item.dataset.id;
            this.classList.remove('kn-ac-open');
            knmpCheckSubmit();
        });
        gid('kn-moveplayer-player-name').addEventListener('input', function() {
            if (!this.value.trim()) { gid('kn-moveplayer-player-id').value = ''; knmpCheckSubmit(); }
        });
        setupAcKeyNav(gid('kn-moveplayer-player-name'), gid('kn-moveplayer-player-results'), '.kn-ac-item[data-id]', 'kn-ac-focused', function(item) { item.click(); });

        // Park autocomplete
        gid('kn-moveplayer-park-name').addEventListener('input', function() {
            gid('kn-moveplayer-park-id').value = '';
            clearTimeout(mpParkTimer);
            var term = this.value.trim();
            if (term.length < 2) { gid('kn-moveplayer-park-results').classList.remove('kn-ac-open'); return; }
            mpParkTimer = setTimeout(function() {
                var params = { Action: 'Search/Park', name: term, limit: 10 };
                if (knmpMode === 'in' || knmpMode === 'within') {
                    params.kingdom_id = KnConfig.kingdomId;
                } else {
                    params.exclude_kingdom_id = KnConfig.kingdomId;
                }
                $.getJSON(PARK_URL, params, function(data) {
                    var el = gid('kn-moveplayer-park-results');
                    el.innerHTML = (data && data.length)
                        ? data.map(function(pk) {
                            var sub = pk.KingdomName ? ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(pk.KingdomName) + ')</span>' : '';
                            return '<div class="kn-ac-item" data-id="' + pk.ParkId + '" data-name="' + encodeURIComponent(pk.Name) + '">'
                                + escHtml(pk.Name) + sub + '</div>';
                        }).join('')
                        : '<div class="kn-ac-item" style="color:#a0aec0;cursor:default">No parks found</div>';
                    el.classList.add('kn-ac-open');
                });
            }, AUTOCOMPLETE_DEBOUNCE_MS);
        });
        gid('kn-moveplayer-park-results').addEventListener('click', function(e) {
            var item = e.target.closest('.kn-ac-item[data-id]');
            if (!item) return;
            gid('kn-moveplayer-park-name').value = decodeURIComponent(item.dataset.name);
            gid('kn-moveplayer-park-id').value   = item.dataset.id;
            this.classList.remove('kn-ac-open');
            knmpCheckSubmit();
        });
        gid('kn-moveplayer-park-name').addEventListener('input', function() {
            if (!this.value.trim()) { gid('kn-moveplayer-park-id').value = ''; knmpCheckSubmit(); }
        });
        setupAcKeyNav(gid('kn-moveplayer-park-name'), gid('kn-moveplayer-park-results'), '.kn-ac-item[data-id]', 'kn-ac-focused', function(item) { item.click(); });

        gid('kn-moveplayer-submit').addEventListener('click', function() {
            var mundaneId = gid('kn-moveplayer-player-id').value;
            var parkId    = gid('kn-moveplayer-park-id').value;
            if (!mundaneId) { showFb('Select a player.', false); return; }
            if (!parkId)    { showFb('Select a destination park.', false); return; }
            var btn = this;
            btn.disabled = true;
            $.post(MOVE_URL, { MundaneId: mundaneId, DestParkId: parkId }, function(r) {
                btn.disabled = false;
                if (r && r.status === 0) {
                    showFb('Player moved successfully.', true);
                    setTimeout(closeMovePlayer, 1200);
                } else {
                    showFb((r && r.error) ? r.error : 'Move failed.', false);
                }
            }, 'json').fail(function() {
                btn.disabled = false;
                showFb('Request failed. Please try again.', false);
            });
        });
    });
})();
// ---- Claim Park Modal (Kingdomnew) ----
(function() {
    if (typeof KnConfig === 'undefined' || !KnConfig.canManage) return;

    var CLAIM_URL = KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/claimpark';
    var PARK_URL  = KnConfig.httpService + 'Search/SearchService.php';

    function gid(id) { return document.getElementById(id); }
    function showFb(msg, ok) {
        var el = gid('kn-claimpark-feedback');
        el.textContent = msg;
        el.className = ok ? 'kn-editoff-feedback kn-editoff-ok' : 'kn-editoff-feedback kn-editoff-err';
        el.style.display = '';
    }

    function closeClaimPark() {
        var ov = gid('kn-claimpark-overlay');
        if (ov) ov.classList.remove('kn-open');
        document.body.style.overflow = '';
    }

    function cpShowSearch() {
        cpAbbrData = null;
        gid('kn-claimpark-search-panel').style.display  = '';
        gid('kn-claimpark-confirm-panel').style.display = 'none';
        gid('kn-claimpark-back').style.display          = 'none';
        gid('kn-claimpark-cancel').style.display        = '';
        var btn = gid('kn-claimpark-submit');
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Review Transfer';
        btn.disabled  = false;
    }

    function cpShowConfirm(abbr, taken, conflictName) {
        var parkName    = gid('kn-claimpark-park-name').value;
        var fromKingdom = gid('kn-claimpark-source-kingdom').value || '(unknown)';
        gid('kn-claimpark-confirm-park').textContent = parkName;
        gid('kn-claimpark-confirm-from').textContent = fromKingdom;
        gid('kn-claimpark-confirm-abbr').textContent = abbr;
        var warnEl  = gid('kn-claimpark-abbr-warning');
        var fieldEl = gid('kn-claimpark-abbr-field');
        if (taken) {
            gid('kn-claimpark-abbr-conflict-abbr').textContent = abbr;
            gid('kn-claimpark-abbr-conflict-name').textContent = conflictName;
            gid('kn-claimpark-new-abbr').value = abbr;
            warnEl.style.display  = 'flex';
            fieldEl.style.display = '';
        } else {
            warnEl.style.display  = 'none';
            fieldEl.style.display = 'none';
        }
        gid('kn-claimpark-search-panel').style.display  = 'none';
        gid('kn-claimpark-confirm-panel').style.display = '';
        gid('kn-claimpark-back').style.display          = '';
        gid('kn-claimpark-cancel').style.display        = 'none';
        var btn = gid('kn-claimpark-submit');
        btn.innerHTML = '<i class="fas fa-flag"></i> Confirm Transfer';
        btn.disabled  = false;
    }

    window.knOpenClaimParkModal = function() {
        var ov = gid('kn-claimpark-overlay');
        if (!ov) return;
        cpAbbrData = null;
        cpConfirming = false;
        gid('kn-claimpark-park-name').value      = '';
        gid('kn-claimpark-park-id').value        = '';
        gid('kn-claimpark-source-kingdom').value = '';
        gid('kn-claimpark-park-results').classList.remove('kn-ac-open');
        gid('kn-claimpark-feedback').style.display = 'none';
        cpShowSearch();
        ov.classList.add('kn-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { gid('kn-claimpark-park-name').focus(); }, 50);
    };

    var cpParkTimer;
    var cpConfirming = false;
    var cpAbbrData   = null;

    $(document).ready(function() {
        if (!gid('kn-claimpark-overlay')) return;

        acKeyNav(gid('kn-claimpark-park-name'), gid('kn-claimpark-park-results'), 'kn-ac-open', '.kn-ac-item[data-id]');

        gid('kn-claimpark-close-btn').addEventListener('click', closeClaimPark);
        gid('kn-claimpark-cancel').addEventListener('click', closeClaimPark);
        gid('kn-claimpark-back').addEventListener('click', function() { cpConfirming = false; cpShowSearch(); });
        gid('kn-claimpark-overlay').addEventListener('click', function(e) {
            if (e.target === this) closeClaimPark();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('kn-claimpark-overlay') && gid('kn-claimpark-overlay').classList.contains('kn-open'))
                closeClaimPark();
        });

        // Park autocomplete (all parks, no kingdom filter)
        gid('kn-claimpark-park-name').addEventListener('input', function() {
            gid('kn-claimpark-park-id').value        = '';
            gid('kn-claimpark-source-kingdom').value = '';
            var term = this.value.trim();
            if (term.length < 2) { gid('kn-claimpark-park-results').classList.remove('kn-ac-open'); return; }
            clearTimeout(cpParkTimer);
            cpParkTimer = setTimeout(function() {
                $.getJSON(PARK_URL, { Action: 'Search/Park', name: term, limit: 8 }, function(data) {
                    var el = gid('kn-claimpark-park-results');
                    el.innerHTML = (data && data.length)
                        ? data.map(function(p) {
                            var label = p.Name + (p.KingdomName ? ' [' + p.KingdomName + ']' : '');
                            return '<div class="kn-ac-item" tabindex="-1" data-id="' + p.ParkId
                                + '" data-name="' + encodeURIComponent(p.Name)
                                + '" data-kingdom="' + encodeURIComponent(p.KingdomName || '') + '">'
                                + label + '</div>';
                        }).join('')
                        : '<div class="kn-ac-item" style="color:#a0aec0;cursor:default">No parks found</div>';
                    el.classList.add('kn-ac-open');
                });
            }, AUTOCOMPLETE_DEBOUNCE_MS);
        });
        gid('kn-claimpark-park-results').addEventListener('click', function(e) {
            var item = e.target.closest('.kn-ac-item[data-id]');
            if (!item) return;
            gid('kn-claimpark-park-name').value      = decodeURIComponent(item.dataset.name);
            gid('kn-claimpark-park-id').value        = item.dataset.id;
            gid('kn-claimpark-source-kingdom').value = decodeURIComponent(item.dataset.kingdom || '');
            this.classList.remove('kn-ac-open');
        });

        gid('kn-claimpark-submit').addEventListener('click', function() {
            var parkId = gid('kn-claimpark-park-id').value;
            if (!cpConfirming) {
                if (!parkId) { showFb('Select a park to claim.', false); return; }
                var btn = this;
                btn.disabled  = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking…';
                var fd = new FormData();
                fd.append('ParkId', parkId);
                fetch(KnConfig.uir + 'KingdomAjax/kingdom/' + KnConfig.kingdomId + '/checkparkabbr', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.status !== 0) {
                            btn.disabled  = false;
                            btn.innerHTML = '<i class="fas fa-arrow-right"></i> Review Transfer';
                            showFb(d.error || 'Error checking abbreviation.', false);
                            return;
                        }
                        cpAbbrData   = { abbr: d.abbr, taken: d.taken, conflictName: d.conflictName };
                        cpConfirming = true;
                        cpShowConfirm(d.abbr, d.taken, d.conflictName);
                    })
                    .catch(function() {
                        btn.disabled  = false;
                        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Review Transfer';
                        showFb('Error checking abbreviation. Please try again.', false);
                    });
                return;
            }
            // Confirmed — validate abbreviation if conflict exists
            if (cpAbbrData && cpAbbrData.taken) {
                var newAbbr = gid('kn-claimpark-new-abbr').value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
                if (newAbbr.length < 2 || newAbbr.length > 3) {
                    showFb('Please enter a 2–3 character abbreviation.', false);
                    return;
                }
            }
            var postData = { ParkId: parkId, DestKingdomId: KnConfig.kingdomId };
            if (cpAbbrData && cpAbbrData.taken) {
                postData.Abbreviation = gid('kn-claimpark-new-abbr').value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
            }
            var btn = this;
            btn.disabled = true;
            $.post(CLAIM_URL, postData, function(r) {
                btn.disabled = false;
                if (r && r.status === 0) {
                    showFb('Park claimed successfully.', true);
                    setTimeout(function() { closeClaimPark(); location.reload(); }, 1200);
                } else {
                    cpConfirming = false;
                    cpShowSearch();
                    showFb((r && r.error) ? r.error : 'Claim failed.', false);
                }
            }, 'json').fail(function() {
                btn.disabled = false;
                cpConfirming = false;
                cpShowSearch();
                showFb('Request failed. Please try again.', false);
            });
        });
    });
})();

// ---- Merge Players Modal (Kingdomnew) ----
(function() {
    if (typeof KnConfig === 'undefined' || !KnConfig.canManage) return;

    var MERGE_URL  = KnConfig.uir + 'PlayerAjax/merge';
    var SEARCH_URL = KnConfig.httpService + 'Search/SearchService.php';

    function gid(id) { return document.getElementById(id); }

    function showFb(msg, ok) {
        var el = gid('kn-mergeplayer-feedback');
        el.textContent = msg;
        el.className = ok ? 'kn-editoff-feedback kn-editoff-ok' : 'kn-editoff-feedback kn-editoff-err';
        el.style.display = '';
    }

    function closeModal() {
        var ov = gid('kn-mergeplayer-overlay');
        if (ov) { ov.classList.remove('kn-open'); document.body.style.overflow = ''; }
    }

    function updateSummary() {
        var keepId   = gid('kn-merge-keep-id').value;
        var removeId = gid('kn-merge-remove-id').value;
        var summary  = gid('kn-merge-summary');
        var btn      = gid('kn-mergeplayer-submit');
        if (keepId && removeId) {
            gid('kn-merge-keep-display').textContent   = gid('kn-merge-keep-name').value.trim();
            gid('kn-merge-remove-display').textContent = gid('kn-merge-remove-name').value.trim();
            summary.style.display = '';
            btn.disabled = false;
        } else {
            summary.style.display = 'none';
            btn.disabled = true;
        }
    }

    window.knOpenMergePlayerModal = function() {
        var ov = gid('kn-mergeplayer-overlay');
        if (!ov) return;
        gid('kn-merge-keep-name').value   = '';
        gid('kn-merge-keep-id').value     = '';
        gid('kn-merge-remove-name').value = '';
        gid('kn-merge-remove-id').value   = '';
        gid('kn-merge-keep-results').classList.remove('kn-ac-open');
        gid('kn-merge-remove-results').classList.remove('kn-ac-open');
        gid('kn-merge-summary').style.display = 'none';
        gid('kn-mergeplayer-submit').disabled = true;
        gid('kn-mergeplayer-feedback').style.display = 'none';
        ov.classList.add('kn-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { gid('kn-merge-keep-name').focus(); }, 50);
    };

    function makePlayerSearch(inputId, hiddenId, resultsId, otherId) {
        var input   = gid(inputId);
        var results = gid(resultsId);
        if (!input || !results) return;
        var timer;
        input.addEventListener('input', function() {
            gid(hiddenId).value = '';
            updateSummary();
            var term = this.value.trim();
            if (term.length < 2) { results.classList.remove('kn-ac-open'); return; }
            clearTimeout(timer);
            timer = setTimeout(function() {
                var scopedReq = $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: term, limit: 10, kingdom_id: KnConfig.kingdomId });
                var globalReq = $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: term, limit: 10 });
                $.when(scopedReq, globalReq).done(function(sr, gr) {
                    var local  = sr[0] || [];
                    var global = gr[0] || [];
                    var seen = {};
                    local.forEach(function(pl) { seen[pl.MundaneId] = true; });
                    var combined = local.concat(global.filter(function(pl) { return !seen[pl.MundaneId]; }));
                    var localIds = {};
                    local.forEach(function(pl) { localIds[pl.MundaneId] = true; });
                    var otherId_val = gid(otherId) ? gid(otherId).value : '';
                    results.innerHTML = combined.length
                        ? combined.map(function(pl) {
                            var isLocal = localIds[pl.MundaneId];
                            var sub = isLocal
                                ? ' <span style="color:#68d391;font-size:11px">&#x2713; Kingdom</span>'
                                : ((pl.KAbbr && pl.PAbbr) ? ' <span style="color:#a0aec0;font-size:11px">(' + pl.KAbbr + ':' + pl.PAbbr + ')</span>' : '');
                            var same = otherId_val && String(pl.MundaneId) === String(otherId_val);
                            return '<div class="kn-ac-item' + (same ? ' kn-ac-disabled' : '') + '"'
                                + (same ? '' : ' data-id="' + pl.MundaneId + '"')
                                + ' data-name="' + encodeURIComponent(pl.Persona) + '"'
                                + (same ? ' style="opacity:0.4;cursor:not-allowed" title="Already selected"' : '')
                                + '>' + escHtml(pl.Persona) + sub + '</div>';
                        }).join('')
                        : '<div class="kn-ac-item" style="color:#a0aec0;cursor:default">No players found</div>';
                    results.classList.add('kn-ac-open');
                });
            }, AUTOCOMPLETE_DEBOUNCE_MS);
        });
        results.addEventListener('click', function(e) {
            var item = e.target.closest('.kn-ac-item[data-id]');
            if (!item) return;
            gid(inputId).value  = decodeURIComponent(item.dataset.name);
            gid(hiddenId).value = item.dataset.id;
            this.classList.remove('kn-ac-open');
            updateSummary();
        });
    }

    $(document).ready(function() {
        if (!gid('kn-mergeplayer-overlay')) return;

        // Close-on-outside-click for the gear dropdown
        document.addEventListener('click', function(e) {
            var menu = gid('kn-plr-gear-menu');
            var btn  = gid('kn-plr-gear-btn');
            if (menu && menu.classList.contains('open') && !menu.contains(e.target) && btn && !btn.contains(e.target)) {
                menu.classList.remove('open');
                if (btn) btn.setAttribute('aria-expanded', 'false');
            }
        });

        gid('kn-mergeplayer-close-btn').addEventListener('click', closeModal);
        gid('kn-mergeplayer-cancel').addEventListener('click', closeModal);
        gid('kn-mergeplayer-overlay').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('kn-mergeplayer-overlay') && gid('kn-mergeplayer-overlay').classList.contains('kn-open'))
                closeModal();
        });

        makePlayerSearch('kn-merge-keep-name',   'kn-merge-keep-id',   'kn-merge-keep-results',   'kn-merge-remove-id');
        makePlayerSearch('kn-merge-remove-name', 'kn-merge-remove-id', 'kn-merge-remove-results', 'kn-merge-keep-id');

        gid('kn-mergeplayer-submit').addEventListener('click', function() {
            var keepId     = gid('kn-merge-keep-id').value;
            var removeId   = gid('kn-merge-remove-id').value;
            var keepName   = gid('kn-merge-keep-name').value.trim();
            var removeName = gid('kn-merge-remove-name').value.trim();
            if (!keepId || !removeId) { showFb('Select both players.', false); return; }
            if (!confirm('Merge "' + removeName + '" INTO "' + keepName + '"?\n\n"' + removeName + '" will be permanently deleted and all data transferred to "' + keepName + '".\n\nThis CANNOT be undone.')) return;
            btn.disabled = true;
            $.post(MERGE_URL, { FromMundaneId: removeId, ToMundaneId: keepId }, function(r) {
                btn.disabled = false;
                if (r && r.status === 0) {
                    showFb('“' + removeName + '” has been merged into “' + keepName + '” and deleted.', true);
                    setTimeout(function() { closeModal(); location.reload(); }, 2200);
                } else {
                    showFb((r && r.error) ? r.error : 'Merge failed.', false);
                }
            }, 'json').fail(function() {
                btn.disabled = false;
                showFb('Request failed. Please try again.', false);
            });
        });
    });
})();

// ---- Parknew: Heraldry Upload Modal ----
(function() {
    if (typeof PkConfig === 'undefined' || !PkConfig.canManage) return;

    var UPLOAD_URL  = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/setheraldry';
    var REMOVE_URL  = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/removeheraldry';

    function gid(id) { return document.getElementById(id); }

    function showFb(msg, ok) {
        var el = gid('pk-heraldry-feedback');
        if (!el) return;
        el.style.display = 'block';
        el.className = ok ? 'pk-editoff-feedback pk-editoff-ok' : 'pk-editoff-feedback pk-editoff-err';
        el.textContent = msg;
    }

    function closeModal() {
        var overlay = gid('pk-heraldry-overlay');
        if (overlay) overlay.classList.remove('pk-open');
    }

    window.pkOpenHeraldryModal = function() {
        var overlay = gid('pk-heraldry-overlay');
        if (!overlay) return;
        var fileInput = gid('pk-heraldry-file-input');
        if (fileInput) fileInput.value = '';
        var sel     = gid('pk-heraldry-step-select');
        var upl     = gid('pk-heraldry-step-uploading');
        var done    = gid('pk-heraldry-step-done');
        var confirm = gid('pk-heraldry-remove-confirm');
        if (sel)     sel.style.display     = '';
        if (upl)     upl.style.display     = 'none';
        if (done)    done.style.display    = 'none';
        if (confirm) confirm.style.display = 'none';
        overlay.classList.add('pk-open');
    };

    window.pkDoRemoveHeraldry = function() {
        fetch(REMOVE_URL, { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function(r) {
                if (r && r.status === 0) {
                    gtag('event', 'park_heraldry_remove', { status: 'success' });
                    window.location.reload();
                } else {
                    alert((r && r.error) ? r.error : 'Remove failed. Please try again.');
                }
            })
            .catch(function() {
                alert('Request failed. Please try again.');
            });
    };

    document.addEventListener('DOMContentLoaded', function() {
        // File input change → auto-upload
        var fileInput = gid('pk-heraldry-file-input');
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                var file = this.files[0];
                if (!file) return;
                var sel  = gid('pk-heraldry-step-select');
                var upl  = gid('pk-heraldry-step-uploading');
                var done = gid('pk-heraldry-step-done');
                if (sel) sel.style.display = 'none';
                if (upl) upl.style.display = '';
                var fd = new FormData();
                fd.append('Heraldry', file);
                fetch(UPLOAD_URL, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(r) {
                        if (upl) upl.style.display = 'none';
                        if (r && r.status === 0) {
                            gtag('event', 'park_heraldry_upload', { status: 'success' });
                            if (done) done.style.display = '';
                            setTimeout(function() { window.location.reload(); }, 1200);
                        } else {
                            gtag('event', 'park_heraldry_upload', { status: 'failed' });
                            if (sel) sel.style.display = '';
                            alert((r && r.error) ? r.error : 'Upload failed. Please try again.');
                        }
                    })
                    .catch(function() {
                        gtag('event', 'park_heraldry_upload', { status: 'failed' });
                        if (upl) upl.style.display = 'none';
                        if (sel) sel.style.display = '';
                        alert('Request failed. Please try again.');
                    });
            });
        }

        // Remove button toggle
        var removeBtn = gid('pk-heraldry-remove-btn');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                var confirm = gid('pk-heraldry-remove-confirm');
                if (confirm) confirm.style.display = confirm.style.display === 'none' ? '' : 'none';
            });
        }

        // Close button
        var closeBtn = gid('pk-heraldry-close-btn');
        if (closeBtn) closeBtn.addEventListener('click', closeModal);

        // Backdrop click
        var overlay = gid('pk-heraldry-overlay');
        if (overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeModal();
            });
        }

        // Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var overlay = gid('pk-heraldry-overlay');
                if (overlay && overlay.classList.contains('pk-open')) closeModal();
            }
        });
    });
})();

// ---- Parknew: Move Player Modal ----
(function() {
    if (typeof PkConfig === 'undefined' || !PkConfig.canManage) return;

    var MOVE_URL        = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/moveplayer';
    var PSEARCH_EXCLUDE = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/playersearch&scope=exclude&q=';
    var PSEARCH_OWN     = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/playersearch&scope=own&q=';

    var mpPlayerId = 0, mpParkId = 0;
    var mpPlayerTimer = null, mpParkTimer = null;
    var mpMode = 'in'; // 'in' = Transfer Into Your Park, 'out' = Transfer to New Park

    function gid(id) { return document.getElementById(id); }

    function showFb(msg, ok) {
        var el = gid('pk-moveplayer-feedback');
        if (!el) return;
        el.style.display = 'block';
        el.className = ok ? 'pk-editoff-feedback pk-editoff-ok' : 'pk-editoff-feedback pk-editoff-err';
        el.textContent = msg;
    }

    function closeModal() {
        var overlay = gid('pk-moveplayer-overlay');
        if (overlay) overlay.classList.remove('pk-open');
    }

    function closeDropdown(resultsId) {
        var el = gid(resultsId);
        if (el) { el.innerHTML = ''; el.classList.remove('pk-ac-open'); }
    }

    function pkmpCheckSubmit() {
        var hasPlayer = parseInt(gid('pk-moveplayer-player-id').value) > 0;
        var hasPark   = mpMode === 'in' ? true : parseInt(gid('pk-moveplayer-park-id').value) > 0;
        var btn = gid('pk-moveplayer-submit');
        if (btn) btn.disabled = !(hasPlayer && hasPark);
    }

    function setMode(mode) {
        mpMode = mode;
        var parkSection = gid('pk-moveplayer-park-section');
        var playerInput = gid('pk-moveplayer-player-name');
        var btnIn  = gid('pk-mp-btn-in');
        var btnOut = gid('pk-mp-btn-out');

        // Reset player selection
        mpPlayerId = 0;
        if (playerInput) playerInput.value = '';
        gid('pk-moveplayer-player-id').value = '';
        closeDropdown('pk-moveplayer-player-results');

        var fb = gid('pk-moveplayer-feedback');
        if (fb) { fb.style.display = 'none'; fb.textContent = ''; }

        if (mode === 'in') {
            if (btnIn)  btnIn.classList.add('pk-mp-active');
            if (btnOut) btnOut.classList.remove('pk-mp-active');
            if (parkSection) parkSection.style.display = 'none';
            if (playerInput) playerInput.placeholder = 'Search by name, or KD:PK name\u2026';
            mpParkId = PkConfig.parkId;
        } else {
            if (btnOut) btnOut.classList.add('pk-mp-active');
            if (btnIn)  btnIn.classList.remove('pk-mp-active');
            if (parkSection) parkSection.style.display = '';
            if (playerInput) playerInput.placeholder = 'Search members of this park…';
            var parkInput = gid('pk-moveplayer-park-name');
            if (parkInput) parkInput.value = '';
            gid('pk-moveplayer-park-id').value = '';
            closeDropdown('pk-moveplayer-park-results');
            mpParkId = 0;
        }
        pkmpCheckSubmit();
    }

    window.pkOpenMovePlayerModal = function() {
        var overlay = gid('pk-moveplayer-overlay');
        if (!overlay) return;
        setMode('in');
        overlay.classList.add('pk-open');
    };

    document.addEventListener('DOMContentLoaded', function() {
        var btnIn  = gid('pk-mp-btn-in');
        var btnOut = gid('pk-mp-btn-out');
        if (btnIn)  btnIn.addEventListener('click',  function() { setMode('in');  });
        if (btnOut) btnOut.addEventListener('click', function() { setMode('out'); });

        // Player autocomplete
        var playerInput = gid('pk-moveplayer-player-name');
        if (playerInput) {
            playerInput.addEventListener('input', function() {
                clearTimeout(mpPlayerTimer);
                mpPlayerId = 0;
                gid('pk-moveplayer-player-id').value = '';
                pkmpCheckSubmit();
                var term = this.value.trim();
                if (term.length < 2) { closeDropdown('pk-moveplayer-player-results'); return; }
                var searchUrl = (mpMode === 'in') ? PSEARCH_EXCLUDE : PSEARCH_OWN;
                mpPlayerTimer = setTimeout(function() {
                    fetch(searchUrl + encodeURIComponent(term))
                        .then(function(r) { return r.json(); })
                        .then(function(results) {
                            var res = gid('pk-moveplayer-player-results');
                            if (!res) return;
                            res.innerHTML = '';
                            if (!results || !results.length) { res.classList.remove('pk-ac-open'); return; }
                            results.forEach(function(player) {
                                var item = document.createElement('div');
                                item.className = 'pk-ac-item';
                                var abbr = (player.PAbbr && player.KAbbr)
                                    ? ' — ' + player.PAbbr + ' (' + player.KAbbr + ')'
                                    : (player.ParkName ? ' — ' + player.ParkName : '');
                                item.textContent = player.Persona + abbr;
                                item.addEventListener('mousedown', function(e) {
                                    e.preventDefault();
                                    mpPlayerId = player.MundaneId;
                                    gid('pk-moveplayer-player-name').value = player.Persona;
                                    gid('pk-moveplayer-player-id').value = player.MundaneId;
                                    closeDropdown('pk-moveplayer-player-results');
                                    pkmpCheckSubmit();
                                });
                                res.appendChild(item);
                            });
                            res.classList.add('pk-ac-open');
                        })
                        .catch(function() { closeDropdown('pk-moveplayer-player-results'); });
                }, 280);
            });
            playerInput.addEventListener('blur', function() {
                setTimeout(function() { closeDropdown('pk-moveplayer-player-results'); }, 200);
            });
            setupAcKeyNav(playerInput, gid('pk-moveplayer-player-results'), '.pk-ac-item', 'pk-ac-focused', function(item) {
                item.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
            });
        }

        // Park autocomplete (Transfer to New Park mode only)
        var parkInput = gid('pk-moveplayer-park-name');
        if (parkInput) {
            parkInput.addEventListener('input', function() {
                clearTimeout(mpParkTimer);
                mpParkId = 0;
                gid('pk-moveplayer-park-id').value = '';
                pkmpCheckSubmit();
                var term = this.value.trim();
                if (term.length < 2) { closeDropdown('pk-moveplayer-park-results'); return; }
                mpParkTimer = setTimeout(function() {
                    $.getJSON(PkConfig.httpService + 'Search/SearchService.php', { Action: 'Search/Park', name: term, limit: 8 }, function(data) {
                        var res = gid('pk-moveplayer-park-results');
                        if (!res) return;
                        res.innerHTML = '';
                        var parks = data.Parks || data.parks || data.results || data || [];
                        if (!parks.length) { res.classList.remove('pk-ac-open'); return; }
                        parks.forEach(function(pk) {
                            var item = document.createElement('div');
                            item.className = 'pk-ac-item';
                            var sub = pk.KingdomName ? ' (' + pk.KingdomName + ')' : '';
                            item.textContent = (pk.ParkName || pk.Name || pk.name || '') + sub;
                            item.addEventListener('mousedown', function(e) {
                                e.preventDefault();
                                mpParkId = pk.ParkId || pk.parkId || pk.id || 0;
                                gid('pk-moveplayer-park-name').value = pk.ParkName || pk.Name || pk.name || '';
                                gid('pk-moveplayer-park-id').value = mpParkId;
                                closeDropdown('pk-moveplayer-park-results');
                                pkmpCheckSubmit();
                            });
                            res.appendChild(item);
                        });
                        res.classList.add('pk-ac-open');
                    });
                }, 280);
            });
            parkInput.addEventListener('blur', function() {
                setTimeout(function() { closeDropdown('pk-moveplayer-park-results'); }, 200);
            });
            setupAcKeyNav(parkInput, gid('pk-moveplayer-park-results'), '.pk-ac-item', 'pk-ac-focused', function(item) {
                item.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
            });
        }

        // Submit
        var submitBtn = gid('pk-moveplayer-submit');
        if (submitBtn) {
            submitBtn.addEventListener('click', function() {
                mpPlayerId = parseInt(gid('pk-moveplayer-player-id').value) || 0;
                mpParkId = (mpMode === 'in') ? PkConfig.parkId : (parseInt(gid('pk-moveplayer-park-id').value) || 0);
                if (!mpPlayerId) { showFb('Please select a player.', false); return; }
                if (!mpParkId)   { showFb('Please select a destination park.', false); return; }
                var btn = this;
                btn.disabled = true;
                $.post(MOVE_URL, { MundaneId: mpPlayerId, DestParkId: mpParkId }, function(r) {
                    btn.disabled = false;
                    if (r && r.status === 0) {
                        showFb('Player moved successfully.', true);
                        mpPlayerId = 0;
                        gid('pk-moveplayer-player-name').value = '';
                        gid('pk-moveplayer-player-id').value = '';
                        if (mpMode === 'out') {
                            mpParkId = 0;
                            gid('pk-moveplayer-park-name').value = '';
                            gid('pk-moveplayer-park-id').value = '';
                        }
                        pkmpCheckSubmit();
                    } else {
                        showFb((r && r.error) ? r.error : 'Move failed.', false);
                    }
                }, 'json').fail(function() {
                    btn.disabled = false;
                    showFb('Request failed. Please try again.', false);
                });
            });
        }

        // Close buttons
        var closeBtn = gid('pk-moveplayer-close-btn');
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        var cancelBtn = gid('pk-moveplayer-cancel');
        if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

        var overlay = gid('pk-moveplayer-overlay');
        if (overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) closeModal();
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var overlay = gid('pk-moveplayer-overlay');
                if (overlay && overlay.classList.contains('pk-open')) closeModal();
            }
        });
    });
})();

/* [TOURNAMENTS HIDDEN] KN add tournament modal */

/* [TOURNAMENTS HIDDEN] PK add tournament modal */


// ---- Merge Players Modal (Parknew) ----
(function() {
    if (typeof PkConfig === 'undefined' || !PkConfig.canManage) return;

    var MERGE_URL  = PkConfig.uir + 'PlayerAjax/merge';
    var SEARCH_URL = PkConfig.httpService + 'Search/SearchService.php';

    function gid(id) { return document.getElementById(id); }

    function showFb(msg, ok) {
        var el = gid('pk-mergeplayer-feedback');
        el.textContent = msg;
        el.className = ok ? 'kn-editoff-feedback kn-editoff-ok' : 'kn-editoff-feedback kn-editoff-err';
        el.style.display = '';
    }

    function closeModal() {
        var ov = gid('pk-mergeplayer-overlay');
        if (ov) { ov.classList.remove('pk-open'); document.body.style.overflow = ''; }
    }

    function updateSummary() {
        var keepId   = gid('pk-merge-keep-id').value;
        var removeId = gid('pk-merge-remove-id').value;
        var summary  = gid('pk-merge-summary');
        var btn      = gid('pk-mergeplayer-submit');
        if (keepId && removeId) {
            gid('pk-merge-keep-display').textContent   = gid('pk-merge-keep-name').value.trim();
            gid('pk-merge-remove-display').textContent = gid('pk-merge-remove-name').value.trim();
            summary.style.display = '';
            btn.disabled = false;
        } else {
            summary.style.display = 'none';
            btn.disabled = true;
        }
    }

    window.pkOpenMergePlayerModal = function() {
        var ov = gid('pk-mergeplayer-overlay');
        if (!ov) return;
        gid('pk-merge-keep-name').value   = '';
        gid('pk-merge-keep-id').value     = '';
        gid('pk-merge-remove-name').value = '';
        gid('pk-merge-remove-id').value   = '';
        gid('pk-merge-keep-results').classList.remove('pk-ac-open');
        gid('pk-merge-remove-results').classList.remove('pk-ac-open');
        gid('pk-merge-summary').style.display = 'none';
        gid('pk-mergeplayer-submit').disabled = true;
        gid('pk-mergeplayer-feedback').style.display = 'none';
        ov.classList.add('pk-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { gid('pk-merge-keep-name').focus(); }, 50);
    };

    function makePlayerSearch(inputId, hiddenId, resultsId, otherId) {
        var input   = gid(inputId);
        var results = gid(resultsId);
        if (!input || !results) return;
        var timer;
        input.addEventListener('input', function() {
            gid(hiddenId).value = '';
            updateSummary();
            var term = this.value.trim();
            if (term.length < 2) { results.classList.remove('pk-ac-open'); return; }
            clearTimeout(timer);
            timer = setTimeout(function() {
                var scopedReq = $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: term, limit: 10, park_id: PkConfig.parkId });
                var globalReq = $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: term, limit: 10 });
                $.when(scopedReq, globalReq).done(function(sr, gr) {
                    var local  = sr[0] || [];
                    var global = gr[0] || [];
                    var seen = {};
                    local.forEach(function(pl) { seen[pl.MundaneId] = true; });
                    var combined = local.concat(global.filter(function(pl) { return !seen[pl.MundaneId]; }));
                    var localIds = {};
                    local.forEach(function(pl) { localIds[pl.MundaneId] = true; });
                    var otherId_val = gid(otherId) ? gid(otherId).value : '';
                    results.innerHTML = combined.length
                        ? combined.map(function(pl) {
                            var isLocal = localIds[pl.MundaneId];
                            var sub = isLocal
                                ? ' <span style="color:#68d391;font-size:11px">&#x2713; This Park</span>'
                                : ((pl.KAbbr && pl.PAbbr) ? ' <span style="color:#a0aec0;font-size:11px">(' + pl.KAbbr + ':' + pl.PAbbr + ')</span>' : '');
                            var same = otherId_val && String(pl.MundaneId) === String(otherId_val);
                            return '<div class="pk-ac-item' + (same ? ' pk-ac-disabled' : '') + '"'
                                + (same ? '' : ' data-id="' + pl.MundaneId + '"')
                                + ' data-name="' + encodeURIComponent(pl.Persona) + '"'
                                + (same ? ' style="opacity:0.4;cursor:not-allowed" title="Already selected"' : '')
                                + '>' + escHtml(pl.Persona) + sub + '</div>';
                        }).join('')
                        : '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No players found</div>';
                    results.classList.add('pk-ac-open');
                });
            }, AUTOCOMPLETE_DEBOUNCE_MS);
        });
        results.addEventListener('click', function(e) {
            var item = e.target.closest('.pk-ac-item[data-id]');
            if (!item) return;
            gid(inputId).value  = decodeURIComponent(item.dataset.name);
            gid(hiddenId).value = item.dataset.id;
            this.classList.remove('pk-ac-open');
            updateSummary();
        });
    }

    $(document).ready(function() {
        if (!gid('pk-mergeplayer-overlay')) return;

        // Close-on-outside-click for the gear dropdown
        document.addEventListener('click', function(e) {
            var menu = gid('pk-plr-gear-menu');
            var btn  = gid('pk-plr-gear-btn');
            if (menu && menu.classList.contains('open') && !menu.contains(e.target) && btn && !btn.contains(e.target)) {
                menu.classList.remove('open');
                if (btn) btn.setAttribute('aria-expanded', 'false');
            }
        });

        gid('pk-mergeplayer-close-btn').addEventListener('click', closeModal);
        gid('pk-mergeplayer-cancel').addEventListener('click', closeModal);
        gid('pk-mergeplayer-overlay').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('pk-mergeplayer-overlay') && gid('pk-mergeplayer-overlay').classList.contains('pk-open'))
                closeModal();
        });

        makePlayerSearch('pk-merge-keep-name',   'pk-merge-keep-id',   'pk-merge-keep-results',   'pk-merge-remove-id');
        makePlayerSearch('pk-merge-remove-name', 'pk-merge-remove-id', 'pk-merge-remove-results', 'pk-merge-keep-id');

        gid('pk-mergeplayer-submit').addEventListener('click', function() {
            var keepId     = gid('pk-merge-keep-id').value;
            var removeId   = gid('pk-merge-remove-id').value;
            var keepName   = gid('pk-merge-keep-name').value.trim();
            var removeName = gid('pk-merge-remove-name').value.trim();
            if (!keepId || !removeId) { showFb('Select both players.', false); return; }
            if (!confirm('Merge "' + removeName + '" INTO "' + keepName + '"?\n\n"' + removeName + '" will be permanently deleted and all data transferred to "' + keepName + '".\n\nThis CANNOT be undone.')) return;
            var btn = this;
            btn.disabled = true;
            $.post(MERGE_URL, { FromMundaneId: removeId, ToMundaneId: keepId }, function(r) {
                btn.disabled = false;
                if (r && r.status === 0) {
                    showFb('“' + removeName + '” has been merged into “' + keepName + '” and deleted.', true);
                    setTimeout(function() { closeModal(); location.reload(); }, 2200);
                } else {
                    showFb((r && r.error) ? r.error : 'Merge failed.', false);
                }
            }, 'json').fail(function() {
                btn.disabled = false;
                showFb('Request failed. Please try again.', false);
            });
        });
    });
})();


// ---- Player Attendance Edit/Delete (Playernew) ----
(function() {
    if (typeof PnConfig === 'undefined' || !PnConfig.canEditAdmin) return;

    var BASE_URL = PnConfig.uir + 'AttendanceAjax/attendance/';
    function gid(id) { return document.getElementById(id); }

    function buildClassOpts(selectedId) {
        var sel = gid('pn-att-edit-class');
        if (!sel) return;
        sel.innerHTML = '<option value="">Select class…</option>';
        (PnConfig.classList || []).forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c.ClassId;
            opt.textContent = c.ClassName;
            if (c.ClassId === selectedId) opt.selected = true;
            sel.appendChild(opt);
        });
    }

    function openEdit(btn) {
        var attId    = btn.dataset.attId;
        var date     = btn.dataset.date;
        var credits  = btn.dataset.credits;
        var classId  = parseInt(btn.dataset.classId, 10);
        var mundaneId = btn.dataset.mundaneId;
        gid('pn-att-edit-id').value        = attId;
        gid('pn-att-edit-mundane-id').value = mundaneId;
        gid('pn-att-edit-date').value      = date;
        gid('pn-att-edit-credits').value   = credits;
        buildClassOpts(classId);
        gid('pn-att-edit-feedback').style.display = 'none';
        gid('pn-att-edit-submit').disabled = false;
        gid('pn-att-edit-submit').innerHTML = '<i class="fas fa-save"></i> Save';
        gid('pn-att-edit-overlay').classList.add('pn-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { gid('pn-att-edit-date').focus(); }, 50);
    }

    function closeEdit() {
        var ov = gid('pn-att-edit-overlay');
        if (ov) { ov.classList.remove('pn-open'); document.body.style.overflow = ''; }
    }

    $(document).on('click', '.pn-att-edit-btn', function() { openEdit(this); });

    $(document).ready(function() {
        if (!gid('pn-att-edit-overlay')) return;

        gid('pn-att-edit-close').addEventListener('click', closeEdit);
        gid('pn-att-edit-cancel').addEventListener('click', closeEdit);
        gid('pn-att-edit-overlay').addEventListener('click', function(e) { if (e.target === this) closeEdit(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('pn-att-edit-overlay') && gid('pn-att-edit-overlay').classList.contains('pn-open'))
                closeEdit();
        });

        gid('pn-att-edit-submit').addEventListener('click', function() {
            var attId     = gid('pn-att-edit-id').value;
            var date      = gid('pn-att-edit-date').value;
            var classId   = gid('pn-att-edit-class').value;
            var credits   = gid('pn-att-edit-credits').value;
            var mundaneId = gid('pn-att-edit-mundane-id').value;
            var fb        = gid('pn-att-edit-feedback');
            fb.style.display = 'none';
            if (!date)    { fb.textContent = 'Date is required.';         fb.style.display = ''; return; }
            if (!classId) { fb.textContent = 'Please select a class.';    fb.style.display = ''; return; }
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            $.post(BASE_URL + attId + '/edit', {
                Date: date, Credits: credits, ClassId: classId, MundaneId: mundaneId
            }, function(r) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save';
                if (r && r.status === 0) {
                    closeEdit();
                    location.reload();
                } else {
                    fb.textContent = (r && r.error) ? r.error : 'Save failed.';
                    fb.className = 'pn-form-error';
                    fb.style.display = '';
                }
            }, 'json').fail(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save';
                fb.textContent = 'Request failed. Please try again.';
                fb.className = 'pn-form-error';
                fb.style.display = '';
            });
        });
    });

    $(document).on('click', '.pn-att-del-btn', function() {
        var attId = this.dataset.attId;
        var btn   = this;
        if (!confirm('Delete this attendance record?')) return;
        btn.disabled = true;
        $.post(BASE_URL + attId + '/delete', {}, function(r) {
            btn.disabled = false;
            if (r && r.status === 0) {
                location.reload();
            } else {
                alert((r && r.error) ? r.error : 'Delete failed.');
            }
        }, 'json').fail(function() {
            btn.disabled = false;
            alert('Request failed. Please try again.');
        });
    });
})();

// ---- Player Add Attendance Modal (Playernew) ----
(function() {
    if (typeof PnConfig === 'undefined' || !PnConfig.canEditAdmin) return;

    var ADD_URL    = PnConfig.uir + 'AttendanceAjax/park/';
    var SEARCH_URL = PnConfig.httpService + 'Search/SearchService.php';
    var parkTimer;

    function gid(id) { return document.getElementById(id); }

    function showFb(msg, ok) {
        var el = gid('pn-player-att-feedback');
        el.textContent = msg;
        el.className = ok ? 'pn-form-success' : 'pn-form-error';
        el.style.display = '';
    }

    function closeModal() {
        var ov = gid('pn-player-att-overlay');
        if (ov) { ov.classList.remove('pn-open'); document.body.style.overflow = ''; }
    }

    function buildClassOptions(lastClassId) {
        var sel = gid('pn-player-att-class');
        sel.innerHTML = '<option value="">Select class\u2026</option>';
        (PnConfig.classList || []).forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c.ClassId;
            opt.textContent = c.ClassName;
            if (c.ClassId === lastClassId) opt.selected = true;
            sel.appendChild(opt);
        });
    }

    window.pnOpenPlayerAttModal = function() {
        var ov = gid('pn-player-att-overlay');
        if (!ov) return;
        var today = new Date().toISOString().slice(0, 10);
        gid('pn-player-att-date').value    = today;
        gid('pn-player-att-credits').value = '1';
        gid('pn-player-att-feedback').style.display = 'none';
        var _dw = gid('pn-player-att-date-warn');
        if (_dw) _dw.style.display = (PnConfig.attendanceDates || []).indexOf(today) !== -1 ? '' : 'none';
        gid('pn-player-att-park-results').classList.remove('pn-ac-open');
        buildClassOptions(PnConfig.lastClassId || 0);
        ov.classList.add('pn-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { gid('pn-player-att-date').focus(); }, 50);
    };

    // Park autocomplete
    $(document).ready(function() {
        if (!gid('pn-player-att-overlay')) return;

        var parkInput   = gid('pn-player-att-park-name');
        var parkResults = gid('pn-player-att-park-results');

        parkInput.addEventListener('input', function() {
            gid('pn-player-att-park-id').value = '';
            var term = this.value.trim();
            if (term.length < 2) { parkResults.classList.remove('pn-ac-open'); return; }
            clearTimeout(parkTimer);
            parkTimer = setTimeout(function() {
                $.getJSON(SEARCH_URL, { Action: 'Search/Park', name: term, limit: 12 }, function(data) {
                    parkResults.innerHTML = (data && data.length)
                        ? data.map(function(pk) {
                            var sub = pk.KingdomName ? ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(pk.KingdomName) + ')</span>' : '';
                            return '<div class="pn-ac-item" data-id="' + pk.ParkId + '" data-name="' + encodeURIComponent(pk.Name) + '">'
                                + escHtml(pk.Name) + sub + '</div>';
                        }).join('')
                        : '<div class="pn-ac-item" style="color:#a0aec0;cursor:default">No parks found</div>';
                    parkResults.classList.add('pn-ac-open');
                });
            }, AUTOCOMPLETE_DEBOUNCE_MS);
        });

        parkResults.addEventListener('click', function(e) {
            var item = e.target.closest('.pn-ac-item[data-id]');
            if (!item) return;
            parkInput.value = decodeURIComponent(item.dataset.name);
            gid('pn-player-att-park-id').value = item.dataset.id;
            parkResults.classList.remove('pn-ac-open');
        });

        document.addEventListener('click', function(e) {
            if (!parkInput.contains(e.target) && !parkResults.contains(e.target))
                parkResults.classList.remove('pn-ac-open');
        });

        var attDates = new Set(PnConfig.attendanceDates || []);
        function checkAttDate() {
            var warn = gid('pn-player-att-date-warn');
            if (!warn) return;
            warn.style.display = attDates.has(gid('pn-player-att-date').value) ? '' : 'none';
        }
        gid('pn-player-att-date').addEventListener('change', checkAttDate);
        gid('pn-player-att-date').addEventListener('input',  checkAttDate);

        gid('pn-player-att-close').addEventListener('click', closeModal);
        gid('pn-player-att-cancel').addEventListener('click', closeModal);
        gid('pn-player-att-overlay').addEventListener('click', function(e) { if (e.target === this) closeModal(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('pn-player-att-overlay') && gid('pn-player-att-overlay').classList.contains('pn-open'))
                closeModal();
        });

        gid('pn-player-att-submit').addEventListener('click', function() {
            var parkId  = gid('pn-player-att-park-id').value;
            var classId = gid('pn-player-att-class').value;
            var date    = gid('pn-player-att-date').value;
            var credits = gid('pn-player-att-credits').value;
            if (!parkId)  { showFb('Please select a park.',   false); return; }
            if (!classId) { showFb('Please select a class.',  false); return; }
            if (!date)    { showFb('Please enter a date.',    false); return; }
            var btn = this;
            btn.disabled = true;
            gid('pn-player-att-feedback').style.display = 'none';
            $.post(ADD_URL + parkId + '/add', {
                AttendanceDate: date,
                MundaneId:      PnConfig.playerId,
                ClassId:        classId,
                Credits:        credits
            }, function(r) {
                btn.disabled = false;
                if (r && r.status === 0) {
                    showFb('Attendance added.', true);
                    setTimeout(function() { closeModal(); location.reload(); }, 1200);
                } else {
                    showFb((r && r.error) ? r.error : 'Failed to add attendance.', false);
                }
            }, 'json').fail(function() {
                btn.disabled = false;
                showFb('Request failed. Please try again.', false);
            });
        });
    });
})();


// ---- Event Heraldry Upload ----
(function() {
    if (typeof EvConfig === 'undefined' || !EvConfig.canManage) return;
    var UPLOAD_URL = EvConfig.uir + 'EventAjax/heraldry/' + EvConfig.eventId + '/update';
    var REMOVE_URL = EvConfig.uir + 'EventAjax/heraldry/' + EvConfig.eventId + '/remove';
    var origImg      = null;
    var origImgIsPng = false;
    var cropBox   = null;
    var dispScale = 1;
    var cropBound = null;

    function gid(id) { return document.getElementById(id); }

    function showStep(s) {
        ['ev-img-step-select','ev-img-step-crop','ev-img-step-uploading','ev-img-step-success'].forEach(function(id) {
            var el = gid(id); if (el) el.style.display = (id === s) ? '' : 'none';
        });
    }

    function showError(msg) {
        var el = gid('ev-img-error');
        if (!el) return;
        el.textContent = msg;
        el.style.display = '';
    }

    window.evOpenImgModal = function() {
        var fi = gid('ev-img-file-input'); if (fi) fi.value = '';
        var rn = gid('ev-img-resize-notice'); if (rn) rn.textContent = '';
        var er = gid('ev-img-error'); if (er) { er.style.display = 'none'; er.textContent = ''; }
        showStep('ev-img-step-select');
        var ov = gid('ev-img-overlay'); if (ov) { ov.classList.add('ev-open'); document.body.style.overflow = 'hidden'; }
    };

    window.evCloseImgModal = function() {
        var ov = gid('ev-img-overlay'); if (ov) ov.classList.remove('ev-open');
        document.body.style.overflow = '';
    };

    var ov = gid('ev-img-overlay');
    if (ov) ov.addEventListener('click', function(e) { if (e.target === this) evCloseImgModal(); });
    var cb = gid('ev-img-close-btn'); if (cb) cb.addEventListener('click', evCloseImgModal);
    document.addEventListener('keydown', function(e) {
        if ((e.key === 'Escape' || e.keyCode === 27) && gid('ev-img-overlay') && gid('ev-img-overlay').classList.contains('ev-open'))
            evCloseImgModal();
    });

    var bb = gid('ev-img-back-btn');
    if (bb) bb.addEventListener('click', function() {
        var fi = gid('ev-img-file-input'); if (fi) fi.value = '';
        var rn = gid('ev-img-resize-notice'); if (rn) rn.textContent = '';
        showStep('ev-img-step-select');
    });
    var ub = gid('ev-img-upload-btn'); if (ub) ub.addEventListener('click', doUploadCropped);

    var fi = gid('ev-img-file-input');
    if (fi) fi.addEventListener('change', function() {
        var file = this.files && this.files[0];
        if (!file) return;
        var ext = file.name.split('.').pop().toLowerCase();
        if (['jpg','jpeg','gif','png'].indexOf(ext) < 0) {
            showError('Invalid file type. Please use JPG, GIF, or PNG.');
            this.value = '';
            return;
        }
        origImgIsPng = (ext === 'png' || file.type === 'image/png');
        var er = gid('ev-img-error'); if (er) er.style.display = 'none';

        function loadIntoModal(blob) {
            var url = URL.createObjectURL(blob);
            var img = new Image();
            img.onload = function() {
                URL.revokeObjectURL(url);
                origImg = img;
                initCrop();
                showStep('ev-img-step-crop');
            };
            img.onerror = function() {
                URL.revokeObjectURL(url);
                showError('Could not load image. Please try a different file.');
            };
            img.src = url;
        }

        if (file.size > 348836) {
            var isPng = (file.type === 'image/png');
            var rn = gid('ev-img-resize-notice'); if (rn) rn.textContent = 'Resizing…';
            resizeImageToLimit(file, 348836, function(blob) {
                var rn2 = gid('ev-img-resize-notice');
                if (rn2) rn2.textContent = 'Auto-resized to ' + Math.round(blob.size / 1024) + ' KB';
                loadIntoModal(blob);
            }, function(errMsg) { showError(errMsg); }, isPng);
        } else {
            loadIntoModal(file);
        }
    });

    function initCrop() {
        var canvas = gid('ev-img-canvas');
        var img = origImg;
        var maxW = Math.min(500, window.innerWidth - 100) - 40;
        var maxH = Math.min(380, window.innerHeight - 260);
        var scale = Math.min(maxW / img.width, maxH / img.height, 1);
        canvas.width  = Math.round(img.width  * scale);
        canvas.height = Math.round(img.height * scale);
        dispScale = scale;
        var inX = Math.round(img.width  * 0.01);
        var inY = Math.round(img.height * 0.01);
        cropBox = { x: inX, y: inY, w: img.width - inX * 2, h: img.height - inY * 2 };
        drawCrop();
        bindCropEvents(canvas);
    }

    function drawCrop() {
        var canvas = gid('ev-img-canvas');
        var ctx = canvas.getContext('2d');
        var sc = dispScale, cb = cropBox;
        var cx = Math.round(cb.x * sc), cy = Math.round(cb.y * sc);
        var cw = Math.round(cb.w * sc), ch = Math.round(cb.h * sc);
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(origImg, 0, 0, canvas.width, canvas.height);
        ctx.fillStyle = 'rgba(0,0,0,0.52)';
        ctx.fillRect(0, 0, canvas.width, cy);
        ctx.fillRect(0, cy + ch, canvas.width, canvas.height - cy - ch);
        ctx.fillRect(0, cy, cx, ch);
        ctx.fillRect(cx + cw, cy, canvas.width - cx - cw, ch);
        ctx.strokeStyle = 'rgba(255,255,255,0.9)';
        ctx.lineWidth = 1.5;
        ctx.strokeRect(cx + 0.5, cy + 0.5, cw - 1, ch - 1);
        ctx.strokeStyle = 'rgba(255,255,255,0.3)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(cx + cw/3, cy); ctx.lineTo(cx + cw/3, cy + ch);
        ctx.moveTo(cx + 2*cw/3, cy); ctx.lineTo(cx + 2*cw/3, cy + ch);
        ctx.moveTo(cx, cy + ch/3); ctx.lineTo(cx + cw, cy + ch/3);
        ctx.moveTo(cx, cy + 2*ch/3); ctx.lineTo(cx + cw, cy + 2*ch/3);
        ctx.stroke();
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
            if (ds.handle === 'move') {
                cropBox.x = Math.max(0, Math.min(img.width  - s.w, s.x + dx));
                cropBox.y = Math.max(0, Math.min(img.height - s.h, s.y + dy));
            } else {
                var nx = s.x, ny = s.y, nw = s.w, nh = s.h;
                if      (ds.handle === 'se') { nw = Math.max(MIN, s.w + dx); nh = Math.max(MIN, s.h + dy); }
                else if (ds.handle === 'sw') { nw = Math.max(MIN, s.w - dx); nh = Math.max(MIN, s.h + dy); nx = s.x + s.w - nw; }
                else if (ds.handle === 'ne') { nw = Math.max(MIN, s.w + dx); nh = Math.max(MIN, s.h - dy); ny = s.y + s.h - nh; }
                else                         { nw = Math.max(MIN, s.w - dx); nh = Math.max(MIN, s.h - dy); nx = s.x + s.w - nw; ny = s.y + s.h - nh; }
                nx = Math.max(0, nx); ny = Math.max(0, ny);
                nw = Math.min(nw, img.width - nx); nh = Math.min(nh, img.height - ny);
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

    function doUploadCropped() {
        var cb = cropBox;
        var outCanvas = document.createElement('canvas');
        outCanvas.width  = Math.round(cb.w);
        outCanvas.height = Math.round(cb.h);
        outCanvas.getContext('2d').drawImage(origImg, cb.x, cb.y, cb.w, cb.h, 0, 0, cb.w, cb.h);
        var outMime = origImgIsPng ? 'image/png' : 'image/jpeg';
        var outQuality = origImgIsPng ? 1 : 0.88;
        outCanvas.toBlob(function(blob) {
            if (blob.size > 348836) {
                resizeImageToLimit(blob, 348836, doUpload, function(err) {
                    showStep('ev-img-step-select');
                    showError(err);
                }, origImgIsPng);
            } else {
                doUpload(blob);
            }
        }, outMime, outQuality);
    }

    function doUpload(blob) {
        showStep('ev-img-step-uploading');
        var fd = new FormData();
        fd.append('Heraldry', blob, 'image.jpg');
        fetch(UPLOAD_URL, { method: 'POST', body: fd })
            .then(function(resp) {
                if (!resp.ok) throw new Error('Server returned ' + resp.status);
                return resp.json();
            })
            .then(function(result) {
                if (result && result.status === 0) {
                    showStep('ev-img-step-success');
                    setTimeout(function() { window.location.reload(); }, 1400);
                } else {
                    showStep('ev-img-step-select');
                    showError((result && result.error) ? result.error : 'Upload failed.');
                }
            })
            .catch(function(err) {
                showStep('ev-img-step-select');
                showError('Upload failed: ' + err.message);
            });
    }

    var removeBtn = gid('ev-img-remove-btn');
    if (removeBtn) {
        removeBtn.addEventListener('click', function() {
            if (!confirm('Remove the event heraldry? This cannot be undone.')) return;
            removeBtn.disabled = true;
            fetch(REMOVE_URL, { method: 'POST' })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (result && result.status === 0) {
                        showStep('ev-img-step-success');
                        setTimeout(function() { window.location.reload(); }, 1400);
                    } else {
                        removeBtn.disabled = false;
                        showError((result && result.error) ? result.error : 'Remove failed.');
                    }
                })
                .catch(function() {
                    removeBtn.disabled = false;
                    showError('Request failed.');
                });
        });
    }
})();

/* [TOURNAMENTS HIDDEN] KN delete tournament buttons */
// ---- Recommendations tab filter bar (Kingdomnew) ----
(function() {
    var bar = document.querySelector('.kn-rec-filter-bar');
    if (!bar) return;

    var activeFilter = 'all';

    function applyFilter(filter) {
        activeFilter = filter;
        var rows = document.querySelectorAll('#kn-recs-tbody .pk-rec-row');
        rows.forEach(function(row) {
            row.style.display = (filter === 'all' || row.dataset.filter === filter) ? '' : 'none';
        });
        bar.querySelectorAll('.kn-rec-filter-btn').forEach(function(btn) {
            btn.classList.toggle('kn-rec-filter-active', btn.dataset.filter === filter);
        });
    }

    bar.addEventListener('click', function(e) {
        var btn = e.target.closest('.kn-rec-filter-btn');
        if (btn) applyFilter(btn.dataset.filter);
    });
})();