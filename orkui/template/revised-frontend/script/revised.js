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
   Award descriptions (keyed by base AwardId)
   =========================== */
var AWARD_DESCRIPTIONS = {
    21: 'Awarded for service to the club not necessarily related to an elected office.',
    22: 'Awarded for organizing and running battlegames, quests, workshops, and demonstrations.',
    23: 'Awarded for going above and beyond the call of duty in executing an office, or for leadership outside of office.',
    239: 'Awarded for serving with excellence in office from the local level to the kingdom level.',
    24: 'Awarded for demonstrating ability in the construction sciences: weapons, armor, furniture, shoes, belts, etc.',
    25: 'Awarded for demonstrating ability in the arts: performance, painting, sculpting, photography, cooking, writing, etc.',
    26: 'Awarded for the creation of garb: tabards, pants, cloaks, gloves, sashes, pouches, etc.',
    27: 'Awarded for fighting prowess in tournament and battlefield combat.',
    243: 'Awarded for understanding of tactics and effectiveness as a player in class battlegaming.',
    28: 'Awarded for positive attitude and sportsmanship.',
    29: 'Awarded for excellence in roleplaying.',
    32: 'Awarded for participation in qualification events.',
    30: 'Awarded for exceptional service in a calendar month.',
    33: 'Awarded for demonstrating positive character traits that reflect well on the club.',
    34: 'Awarded for service as a group (a park, company, household, event team, etc.).'
};

/* ===========================
   Dark Mode Theme Toggle
   =========================== */
function orkInitTheme() {
    if (orkInitTheme._done) return;
    orkInitTheme._done = true;
    var stored = localStorage.getItem('ork_theme');
    if (stored === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    } else if (stored === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
    } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }

    // Wire up the toggle button
    var btn = document.getElementById('ork-theme-toggle');
    if (btn) {
        btn.addEventListener('click', function() {
            var stored = localStorage.getItem('ork_theme');
            // Cycle: auto → dark → light → auto (keyed off stored pref, not data-theme)
            if (!stored) {
                // auto → dark
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('ork_theme', 'dark');
            } else if (stored === 'dark') {
                // dark → light
                document.documentElement.setAttribute('data-theme', 'light');
                localStorage.setItem('ork_theme', 'light');
            } else {
                // light → auto: remove pref, re-apply OS preference live
                localStorage.removeItem('ork_theme');
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.setAttribute('data-theme', 'dark');
                } else {
                    document.documentElement.removeAttribute('data-theme');
                }
            }
            // Update icon
            orkUpdateThemeIcon();
            // Reapply hero color with new lightness
            var heroImg = document.querySelector('.kn-heraldry-frame img, .pk-heraldry-frame img');
            if (heroImg && heroImg.complete) {
                if (typeof knApplyHeroColor === 'function') knApplyHeroColor(heroImg);
                if (typeof pkApplyHeroColor === 'function') pkApplyHeroColor(heroImg);
                if (typeof evApplyHeroColor === 'function') evApplyHeroColor();
                if (typeof enApplyHeroColor === 'function') enApplyHeroColor();
                if (typeof ecApplyBannerColor === 'function') ecApplyBannerColor();
            }
        });
        orkUpdateThemeIcon();
    }

    // Live OS preference listener — only fires when user is in auto mode (no stored pref)
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
            if (localStorage.getItem('ork_theme')) return; // user has a manual pref, ignore
            if (e.matches) {
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.removeAttribute('data-theme');
            }
            var heroImg = document.querySelector('.kn-heraldry-frame img, .pk-heraldry-frame img');
            if (heroImg && heroImg.complete) {
                if (typeof knApplyHeroColor === 'function') knApplyHeroColor(heroImg);
                if (typeof pkApplyHeroColor === 'function') pkApplyHeroColor(heroImg);
                if (typeof evApplyHeroColor === 'function') evApplyHeroColor();
                if (typeof enApplyHeroColor === 'function') enApplyHeroColor();
                if (typeof ecApplyBannerColor === 'function') ecApplyBannerColor();
            }
        });
    }
}

function orkUpdateThemeIcon() {
    var btn = document.getElementById('ork-theme-toggle');
    if (!btn) return;
    var stored = localStorage.getItem('ork_theme');
    // Sun icon (light mode)
    var sunSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3a9 9 0 1 0 9 9A9.01 9.01 0 0 0 12 3zm0 16a7 7 0 1 1 7-7 7.008 7.008 0 0 1-7 7z"/><path d="M12 7a5 5 0 1 0 5 5 5.006 5.006 0 0 0-5-5z"/></svg>';
    // Moon icon (dark mode)
    var moonSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 11.807A9.002 9.002 0 0 1 10.049 2a9.942 9.942 0 0 0-5.12 2.735c-3.905 3.905-3.905 10.237 0 14.142 3.906 3.906 10.237 3.905 14.143 0a9.946 9.946 0 0 0 2.735-5.119A9.003 9.003 0 0 1 12 11.807z"/></svg>';
    // Auto icon (half-filled circle — left half dark, right half outline)
    var autoSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 2a10 10 0 0 1 0 20V2z" fill="currentColor"/></svg>';
    if (stored === 'dark') {
        btn.setAttribute('title', 'Dark mode (click for light)');
        btn.innerHTML = moonSvg;
    } else if (stored === 'light') {
        btn.setAttribute('title', 'Light mode (click for auto)');
        btn.innerHTML = sunSvg;
    } else {
        btn.setAttribute('title', 'Auto mode (follows OS setting, click for dark)');
        btn.innerHTML = autoSvg;
    }
}

// Run theme init on DOMContentLoaded to ensure button is in DOM
// Theme attr is applied immediately via stored value in case it was already set server-side
(function() {
    var stored = localStorage.getItem('ork_theme');
    if (stored === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    } else if (stored === 'light') {
        document.documentElement.setAttribute('data-theme', 'light');
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', orkInitTheme);
    } else {
        orkInitTheme();
    }
})();


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
   Searchable Award Dropdown
   =========================== */
(function() {
    var dropdown, searchInput, body, activeSelect, activeTrigger;

    function ensureDOM() {
        if (dropdown) return;
        dropdown = document.createElement('div');
        dropdown.className = 'aw-dropdown';
        dropdown.innerHTML =
            '<div class="aw-dropdown-search-wrap"><i class="fas fa-search"></i>' +
                '<input type="text" class="aw-dropdown-search" placeholder="Type to filter\u2026"></div>' +
            '<div class="aw-dropdown-body"></div>';
        document.body.appendChild(dropdown);
        searchInput = dropdown.querySelector('.aw-dropdown-search');
        body = dropdown.querySelector('.aw-dropdown-body');

        // Close on outside click
        document.addEventListener('mousedown', function(e) {
            if (!dropdown.classList.contains('aw-open')) return;
            if (dropdown.contains(e.target)) return;
            if (activeTrigger && activeTrigger.contains(e.target)) return;
            closeDropdown();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && dropdown.classList.contains('aw-open')) { e.stopPropagation(); closeDropdown(); }
        });

        searchInput.addEventListener('input', function() {
            var term = this.value.toLowerCase();
            var items = body.querySelectorAll('.aw-pick-item');
            for (var i = 0; i < items.length; i++)
                items[i].style.display = items[i].textContent.toLowerCase().indexOf(term) !== -1 ? '' : 'none';
            var groups = body.querySelectorAll('.aw-pick-group');
            for (var i = 0; i < groups.length; i++)
                groups[i].style.display = groups[i].querySelector('.aw-pick-item:not([style*="display: none"])') ? '' : 'none';
            var standalones = body.querySelectorAll('.aw-pick-standalone');
            for (var i = 0; i < standalones.length; i++)
                standalones[i].style.display = standalones[i].querySelector('.aw-pick-item:not([style*="display: none"])') ? '' : 'none';
            var any = body.querySelector('.aw-pick-item:not([style*="display: none"])');
            var empty = body.querySelector('.aw-pick-empty');
            if (!any && !empty) {
                var el = document.createElement('div'); el.className = 'aw-pick-empty';
                el.textContent = 'No awards match'; body.appendChild(el);
            } else if (any && empty) { empty.remove(); }
        });

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                var first = body.querySelector('.aw-pick-item:not([style*="display: none"])');
                if (first) first.focus();
            }
        });

        body.addEventListener('click', function(e) {
            var item = e.target.closest('.aw-pick-item');
            if (!item) return;
            if (!activeSelect) return;
            activeSelect.value = item.getAttribute('data-value');
            activeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            syncTrigger(activeSelect);
            closeDropdown();
        });

        body.addEventListener('keydown', function(e) {
            var item = e.target.closest('.aw-pick-item');
            if (!item) return;
            if (e.key === 'Enter') { e.preventDefault(); item.click(); }
            else if (e.key === 'ArrowDown') {
                e.preventDefault();
                var next = item.nextElementSibling;
                while (next && (!next.classList.contains('aw-pick-item') || next.style.display === 'none')) next = next.nextElementSibling;
                if (!next) { var ng = item.closest('.aw-pick-group, .aw-pick-standalone');
                    if (ng) ng = ng.nextElementSibling;
                    while (ng && !(next = ng.querySelector('.aw-pick-item:not([style*="display: none"])'))) ng = ng.nextElementSibling; }
                if (next) next.focus();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                var prev = item.previousElementSibling;
                while (prev && (!prev.classList.contains('aw-pick-item') || prev.style.display === 'none')) prev = prev.previousElementSibling;
                if (!prev) { var pg = item.closest('.aw-pick-group, .aw-pick-standalone');
                    if (pg) pg = pg.previousElementSibling;
                    while (pg && !(prev = pg.querySelector('.aw-pick-item:not([style*="display: none"]):last-child'))) pg = pg.previousElementSibling; }
                if (prev) prev.focus(); else searchInput.focus();
            }
        });
    }

    function buildHTML(sel) {
        var html = '', cur = sel.value, ch = sel.children;
        for (var i = 0; i < ch.length; i++) {
            if (ch[i].tagName === 'OPTGROUP') {
                html += '<div class="aw-pick-group"><div class="aw-pick-group-label">' + escHtml(ch[i].label) + '</div>';
                for (var j = 0; j < ch[i].children.length; j++) html += itemHTML(ch[i].children[j], cur);
                html += '</div>';
            } else if (ch[i].tagName === 'OPTION' && ch[i].value) {
                html += '<div class="aw-pick-standalone">' + itemHTML(ch[i], cur) + '</div>';
            }
        }
        return html;
    }

    function itemHTML(opt, cur) {
        var aid = parseInt(opt.getAttribute('data-award-id')) || 0;
        var isL = opt.getAttribute('data-is-ladder') === '1';
        var desc = isL && typeof AWARD_DESCRIPTIONS !== 'undefined' ? (AWARD_DESCRIPTIONS[aid] || '') : '';
        var cls = 'aw-pick-item' + (opt.value === cur && cur ? ' aw-active' : '');
        var h = '<div class="' + cls + '" data-value="' + opt.value + '" tabindex="0">';
        h += '<span class="aw-pick-name">' + escHtml(opt.textContent) + '</span>';
        if (desc) h += '<span class="aw-pick-desc">' + escHtml(desc) + '</span>';
        return h + '</div>';
    }

    function openDropdown(sel, trigger) {
        ensureDOM();
        if (activeSelect === sel && dropdown.classList.contains('aw-open')) { closeDropdown(); return; }
        activeSelect = sel; activeTrigger = trigger;
        body.innerHTML = buildHTML(sel);
        var empty = body.querySelector('.aw-pick-empty'); if (empty) empty.remove();
        searchInput.value = '';
        var rect = trigger.getBoundingClientRect();
        var vw = window.innerWidth, vh = window.innerHeight;
        var mobile = vw <= 600;
        if (mobile) {
            dropdown.style.left = '8px';
            dropdown.style.right = '8px';
            dropdown.style.width = 'auto';
            dropdown.style.bottom = '0';
            dropdown.style.top = 'auto';
            dropdown.style.maxHeight = '55vh';
            dropdown.style.borderRadius = '10px 10px 0 0';
        } else {
            var w = Math.max(rect.width, 320);
            var left = rect.left;
            if (left + w > vw - 8) left = vw - w - 8;
            if (left < 8) left = 8;
            var spaceBelow = vh - rect.bottom - 8;
            var spaceAbove = rect.top - 8;
            var maxH = 360;
            if (spaceBelow >= 200) {
                dropdown.style.top = rect.bottom + 2 + 'px';
                dropdown.style.bottom = 'auto';
                maxH = Math.min(360, spaceBelow);
            } else {
                dropdown.style.bottom = (vh - rect.top + 2) + 'px';
                dropdown.style.top = 'auto';
                maxH = Math.min(360, spaceAbove);
            }
            dropdown.style.left = left + 'px';
            dropdown.style.right = 'auto';
            dropdown.style.width = w + 'px';
            dropdown.style.maxHeight = maxH + 'px';
            dropdown.style.borderRadius = '6px';
        }
        dropdown.classList.add('aw-open');
        searchInput.focus();
        var active = body.querySelector('.aw-active');
        if (active) active.scrollIntoView({ block: 'center' });
    }

    function closeDropdown() {
        if (!dropdown) return;
        dropdown.classList.remove('aw-open');
        if (activeTrigger) activeTrigger.focus();
        activeSelect = null; activeTrigger = null;
    }

    function syncTrigger(sel) {
        var btn = sel._awTrigger; if (!btn) return;
        var opt = sel.options[sel.selectedIndex];
        var hasVal = opt && opt.value;
        btn.querySelector('.aw-trigger-label').textContent = hasVal ? opt.text : 'Select award\u2026';
        btn.classList.toggle('aw-has-value', !!hasVal);
    }

    function initPicker(sel) {
        sel.style.display = 'none';
        var btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'aw-picker-trigger';
        var opt = sel.options[sel.selectedIndex];
        var hasVal = opt && opt.value;
        btn.innerHTML = '<span class="aw-trigger-label">' + (hasVal ? escHtml(opt.text) : 'Select award\u2026') + '</span>' +
            '<i class="fas fa-chevron-down" style="color:#a0aec0;font-size:11px"></i>';
        if (hasVal) btn.classList.add('aw-has-value');
        sel.parentNode.insertBefore(btn, sel.nextSibling);
        sel._awTrigger = btn;
        btn.addEventListener('click', function() { openDropdown(sel, btn); });

        var desc = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
        Object.defineProperty(sel, 'value', {
            get: function() { return desc.get.call(this); },
            set: function(v) { desc.set.call(this, v); syncTrigger(this); }
        });
        new MutationObserver(function() { syncTrigger(sel); }).observe(sel, { childList: true, subtree: true });
    }

    window.awInitPicker = initPicker;
    window.awSyncTrigger = syncTrigger;
    window.awCloseDropdown = closeDropdown;
})();

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
                '<div class="pn-modal-body"><p id="pn-confirm-message" class="pn-confirm-message"></p></div>' +
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
    $table.data('pagesize', size === 'all' ? 99999 : parseInt(size));
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

function pnSortDesc($table, colIndex, sortType, secondaryColIndex, secondarySortType) {
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
        if (cmp === 0 && secondaryColIndex != null) {
            var aVal2 = $(a).find('td').eq(secondaryColIndex).text().trim();
            var bVal2 = $(b).find('td').eq(secondaryColIndex).text().trim();
            var st = secondarySortType || 'text';
            if (st === 'numeric') {
                cmp = (parseFloat(aVal2) || 0) - (parseFloat(bVal2) || 0);
            } else if (st === 'date') {
                cmp = (new Date(aVal2).getTime() || 0) - (new Date(bVal2).getTime() || 0);
            } else {
                cmp = aVal2.localeCompare(bVal2);
            }
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
        var hint = document.getElementById('pn-rec-rank-hint');
        if (hint) hint.textContent = baseAwardId === 0
            ? '— click to select; green border = suggested next; dark blue = selected'
            : '— click to select; light blue = already held, green border = suggested; dark blue = selected';
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
    var _recAwardEl = document.getElementById('pn-rec-award');
    if (_recAwardEl) {
        _recAwardEl.querySelectorAll('optgroup[label="Associate Titles"]').forEach(function(og) { og.parentNode.removeChild(og); });
        awInitPicker(_recAwardEl);
    }
    $('#pn-rec-award').on('change', function() {
        buildRecRankPills($(this).val());
        var aid = parseInt($(this).find(':selected').data('award-id') || 0);
        var desc = AWARD_DESCRIPTIONS[aid] || '';
        var el = document.getElementById('pn-rec-award-desc');
        if (el) { el.textContent = desc; el.style.display = desc ? '' : 'none'; }
    });

    // ---- Rec dismiss button (player page) ----
    document.addEventListener('click', function(e) {
        var dimBtn = e.target.closest ? e.target.closest('.pn-rec-dismiss-btn') : null;
        if (!dimBtn) return;
        if (!dimBtn.dataset.confirm) {
            dimBtn.dataset.confirm = '1';
            dimBtn.textContent = 'Confirm Delete?';
            dimBtn.classList.add('pk-rec-dismiss-confirm');
            dimBtn._confirmTimer = setTimeout(function() {
                dimBtn.dataset.confirm = '';
                dimBtn.innerHTML = '<i class="fas fa-times"></i> Delete';
                dimBtn.classList.remove('pk-rec-dismiss-confirm');
            }, 3000);
            return;
        }
        clearTimeout(dimBtn._confirmTimer);
        window.location.href = dimBtn.getAttribute('data-href');
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
        var origImgIsPng = false; // source was PNG — preserve alpha on output
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
            var confirmPanel = gid('pn-img-remove-confirm');
            if (confirmPanel) confirmPanel.style.display = 'none';
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
            origImgIsPng = (imgType === 'heraldry') && (ext === 'png' || file.type === 'image/png');
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
                gid('pn-img-resize-notice').textContent = 'Resizing\u2026';
                resizeImageToLimit(file, 348836, function(blob) {
                    gid('pn-img-resize-notice').textContent = 'Auto-resized to ' + Math.round(blob.size / 1024) + '\u00a0KB';
                    loadIntoModal(blob);
                }, function(errMsg) {
                    showError(errMsg);
                }, origImgIsPng);
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
            var outMime    = origImgIsPng ? 'image/png'  : 'image/jpeg';
            var outQuality = origImgIsPng ? undefined    : 0.88;
            outCanvas.toBlob(function(blob) {
                if (blob.size > 348836) {
                    resizeImageToLimit(blob, 348836, doUpload, function(err) {
                        showStep('pn-img-step-select');
                        showError(err);
                    }, origImgIsPng);
                } else {
                    doUpload(blob);
                }
            }, outMime, outQuality);
        }

        function doUpload(blob) {
            showStep('pn-img-step-uploading');
            var fd = new FormData();
            fd.append('Update', 'Update Media');
            var outName = origImgIsPng ? 'image.png' : 'image.jpg';
            fd.append(imgType === 'photo' ? 'PlayerImage' : 'Heraldry', blob, outName);
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
                var title = (imgType === 'photo') ? 'Remove Photo' : 'Remove Heraldry';
                var confirmPanel = gid('pn-img-remove-confirm');
                var confirmText  = gid('pn-img-remove-confirm-text');
                if (confirmText) confirmText.textContent = 'Remove the ' + label + '?';
                if (confirmPanel) confirmPanel.style.display = confirmPanel.style.display === 'none' ? '' : 'none';
            });
        }
        var confirmOkBtn = gid('pn-img-remove-confirm-btn');
        if (confirmOkBtn) {
            confirmOkBtn.addEventListener('click', function() {
                var confirmPanel = gid('pn-img-remove-confirm');
                if (confirmPanel) confirmPanel.style.display = 'none';
                var removeBtn = gid('pn-img-remove-btn');
                if (removeBtn) removeBtn.disabled = true;
                var action = (imgType === 'photo') ? 'removeimage' : 'removeheraldry';
                fetch(PnConfig.uir + 'PlayerAjax/player/' + PnConfig.playerId + '/' + action, { method: 'POST' })
                    .then(function(r) { return r.json(); })
                    .then(function(result) {
                        if (result && result.status === 0) {
                            showStep('pn-img-step-success');
                            setTimeout(function() { window.location.reload(); }, 1400);
                        } else {
                            if (removeBtn) removeBtn.disabled = false;
                            showError((result && result.error) ? result.error : 'Remove failed.');
                        }
                    })
                    .catch(function() {
                        if (removeBtn) removeBtn.disabled = false;
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
        if (!PnConfig.canManageAwards) return;
        var AWARD_URL = PnConfig.uir + 'Admin/player/' + PnConfig.playerId + '/addaward';
        var SEARCH_URL = PnConfig.httpService + 'Search/SearchService.php';
        var KINGDOM_ID = PnConfig.kingdomId;
        // Player's held award ranks: canonical AwardId => max rank
        var playerRanks = PnConfig.awardRanks;
        // Award option lists as HTML strings for swapping
        var awardOptHTML = PnConfig.awardOptHTML;
        var officerOptHTML = PnConfig.officerOptHTML;

        // Split awardOptHTML into: awards-only (Ladder+Custom+Other), achievements, associations
        var awardsOnlyHTML, achievementsHTML, associationsHTML;
        (function() {
            var tmp = document.createElement('select');
            tmp.innerHTML = awardOptHTML;
            var aw  = '<option value="">Select award...</option>';
            var ach = '<option value="">Select title...</option>';
            var asc = '<option value="">Select association...</option>';
            Array.from(tmp.children).forEach(function(child) {
                if (child.tagName === 'OPTION') {
                    if (child.value === '') return;
                    aw += child.outerHTML;
                } else if (child.tagName === 'OPTGROUP') {
                    var lbl = child.label;
                    if (lbl === 'Knighthoods' || lbl === 'Masterhoods' || lbl === 'Paragons' || lbl === 'Noble Titles') {
                        ach += child.outerHTML;
                    } else if (lbl === 'Associate Titles') {
                        asc += child.outerHTML;
                    } else {
                        aw += child.outerHTML;
                    }
                }
            });
            awardsOnlyHTML = aw; achievementsHTML = ach; associationsHTML = asc;
        })();

        // Hide type buttons for empty buckets
        (function() {
            var tmp = document.createElement('select');
            tmp.innerHTML = achievementsHTML;
            var hasAch = Array.from(tmp.options).some(function(o) { return o.value !== ''; });
            var btnAch = gid('pn-award-type-achievements');
            if (btnAch) btnAch.style.display = hasAch ? '' : 'none';

            tmp.innerHTML = associationsHTML;
            var hasAsc = Array.from(tmp.options).some(function(o) { return o.value !== ''; });
            var btnAsc = gid('pn-award-type-associations');
            if (btnAsc) btnAsc.style.display = hasAsc ? '' : 'none';
        })();

        var currentType = 'awards';

        function gid(id) { return document.getElementById(id); }

        // ---- Award Type Toggle ----
        var pnTypeHTML = {
            awards:       awardsOnlyHTML,
            officers:     officerOptHTML,
            achievements: achievementsHTML,
            associations: associationsHTML
        };
        var pnTypeTitles = {
            awards:       '<i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award',
            officers:     '<i class="fas fa-crown" style="margin-right:8px;color:#553c9a"></i>Add Officer Title',
            achievements: '<i class="fas fa-star" style="margin-right:8px;color:#744210"></i>Add Achievement Title',
            associations: '<i class="fas fa-handshake" style="margin-right:8px;color:#276749"></i>Add Association'
        };
        var pnSelectLabelText = { awards: 'Award', officers: 'Title', achievements: 'Title', associations: 'Association' };
        function setAwardType(type) {
            if (typeof awCloseDropdown === 'function') awCloseDropdown();
            currentType = type;
            var isAssoc = (type === 'associations');
            gid('pn-award-modal-title').innerHTML = pnTypeTitles[type] || pnTypeTitles.awards;
            gid('pn-award-select').innerHTML = pnTypeHTML[type] || awardsOnlyHTML;
            gid('pn-award-rank-row').style.display   = 'none';
            gid('pn-award-custom-row').style.display  = 'none';
            gid('pn-award-info-line').innerHTML       = '';
            gid('pn-award-rank-val').value            = '';
            var lbl = gid('pn-award-select-label');
            if (lbl) lbl.innerHTML = (pnSelectLabelText[type] || 'Award') + ' <span style="color:#e53e3e">*</span>';
            var note = gid('pn-award-givenby-note');
            if (note) note.style.display = isAssoc ? '' : 'none';
            var chips = gid('pn-award-officer-chips');
            if (chips) chips.style.display = isAssoc ? 'none' : '';
            ['awards', 'officers', 'achievements', 'associations'].forEach(function(t) {
                var btn = gid('pn-award-type-' + t);
                if (btn) btn.classList.toggle('pn-active', t === type);
            });
            checkRequired();
        }
        gid('pn-award-type-awards').addEventListener('click',       function() { setAwardType('awards'); });
        gid('pn-award-type-officers').addEventListener('click',     function() { setAwardType('officers'); });
        gid('pn-award-type-achievements').addEventListener('click', function() { setAwardType('achievements'); });
        gid('pn-award-type-associations').addEventListener('click', function() { setAwardType('associations'); });

        // ---- Award Picker init ----
        if (gid('pn-award-select')) awInitPicker(gid('pn-award-select'));

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
            var hint = gid('pn-rank-hint');
            if (hint) hint.textContent = awardId === 0
                ? '— click to select; green border = suggested next; dark blue = selected'
                : '— click to select; light blue = already held, green border = suggested; dark blue = selected';
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
                var url = PnConfig.uir + 'KingdomAjax/playersearch/' + KINGDOM_ID + '&scope=all&include_inactive=1&include_suspended=1&q=' + encodeURIComponent(term);
                fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                    var results = gid('pn-award-givenby-results');
                    if (!data || !data.length) {
                        results.innerHTML = '<div class="pn-ac-no-results">No players found</div>';
                    } else {
                        results.innerHTML = data.map(function(p) {
                            return '<div class="pn-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                                + escHtml(p.Persona)
                                + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr || '') + ':' + escHtml(p.PAbbr || '') + ')</span>'
                                + (p.Active === 0 ? ' <span style="color:#c53030;font-size:10px;font-weight:600">(Inactive)</span>' : '')
                                + (p.Suspended   ? ' <span style="color:#c53030;font-size:10px;font-weight:600">(Banned)</span>'   : '')
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
            gid('pn-award-kingdom-id').value      = String(PnConfig.kingdomId || 0);
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
            var grantBtn = e.target.closest ? e.target.closest('.pn-rec-grant-btn') : null;
            if (grantBtn) {
                try { window.pnGiveFromRec(JSON.parse(grantBtn.getAttribute('data-rec') || '{}')); } catch (ex) {}
            }
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
            gid('pn-rank-pills').innerHTML           = '';
            gid('pn-award-note').value               = '';
            gid('pn-award-char-count').textContent   = '400 characters remaining';
            gid('pn-award-char-count').classList.remove('pn-char-warn');
            gid('pn-award-info-line').innerHTML      = '';
            gid('pn-award-custom-name').value        = '';
            gid('pn-award-custom-row').style.display = 'none';
            gid('pn-award-givenat-text').value       = PnConfig.parkName;
            gid('pn-award-park-id').value            = String(PnConfig.parkId);
            gid('pn-award-kingdom-id').value         = String(PnConfig.kingdomId || 0);
            gid('pn-award-event-id').value           = '0';
            gid('pn-award-givenat-results').classList.remove('pn-ac-open');
            checkRequired();
            var _pnSelTrigger = gid('pn-award-select') && gid('pn-award-select')._awTrigger;
            if (_pnSelTrigger) _pnSelTrigger.focus();
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

function orkIsDarkMode() {
    var attr = document.documentElement.getAttribute('data-theme');
    if (attr === 'dark') return true;
    if (attr === 'light') return false;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

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
        eventDidMount: function(info) {
            var rp = info.event.extendedProps && info.event.extendedProps.royalPresence;
            if (!rp) return;
            var tip = rp === 'both'    ? 'Monarch & Regent in Attendance'
                    : rp === 'monarch' ? 'Monarch in Attendance'
                    :                    'Regent in Attendance';
            var crown = document.createElement('span');
            crown.className = 'kn-cal-royal-crown';
            crown.title = tip;
            crown.innerHTML = ' <i class="fas fa-crown"></i>';
            var titleEl = info.el.querySelector('.fc-event-title');
            if (titleEl) titleEl.appendChild(crown);
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
            + '<div class="kn-park-section-text kn-description-body">' + loc.dir + '</div>'
            + '</div>';
    }
    if (loc.desc) {
        if (bodyHtml) bodyHtml += '<hr class="kn-park-divider">';
        bodyHtml += '<div class="kn-park-section">'
            + '<div class="kn-park-section-label"><i class="fas fa-info-circle" style="margin-right:4px"></i>About</div>'
            + '<div class="kn-park-section-text kn-description-body">' + loc.desc + '</div>'
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

        // Clamp: keep hue, boost saturation proportionately for dark mode
        var _kn_dark = orkIsDarkMode();
        var finalS = _kn_dark ? Math.min(Math.max(s * 1.15, 0.35), 0.90) : Math.max(s, 0.28);
        var hDeg   = Math.round(h * 360);
        var sPct   = Math.round(finalS * 100);
        document.documentElement.style.setProperty('--kn-hue', hDeg);
        document.documentElement.style.setProperty('--kn-sat', sPct + '%');
        var heroEl = document.querySelector('.kn-hero');
        if (heroEl) {
            var heroL = getComputedStyle(document.documentElement).getPropertyValue('--ork-hero-lightness').trim() || (_kn_dark ? '22%' : '18%');
            heroEl.style.backgroundColor =
                'hsl(' + hDeg + ',' + sPct + '%,' + heroL + ')';
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
    if (typeof KnConfig === 'undefined') return;
    if (!document.getElementById('kn-award-overlay')) return;
    var UIR_JS = KnConfig.uir;
    var SEARCH_URL = KnConfig.httpService + 'Search/SearchService.php';
    var KINGDOM_ID = KnConfig.kingdomId;
    var awardOptHTML = KnConfig.awardOptHTML;
    var officerOptHTML = KnConfig.officerOptHTML;

    // Split awardOptHTML into: awards-only (Ladder+Custom+Other), achievements, associations
    var awardsOnlyHTML, achievementsHTML, associationsHTML;
    (function() {
        var tmp = document.createElement('select');
        tmp.innerHTML = awardOptHTML;
        var aw  = '<option value="">Select award...</option>';
        var ach = '<option value="">Select title...</option>';
        var asc = '<option value="">Select association...</option>';
        Array.from(tmp.children).forEach(function(child) {
            if (child.tagName === 'OPTION') {
                if (child.value === '') return;
                aw += child.outerHTML;
            } else if (child.tagName === 'OPTGROUP') {
                var lbl = child.label;
                if (lbl === 'Knighthoods' || lbl === 'Masterhoods' || lbl === 'Paragons' || lbl === 'Noble Titles') {
                    ach += child.outerHTML;
                } else if (lbl === 'Associate Titles') {
                    asc += child.outerHTML;
                } else {
                    aw += child.outerHTML;
                }
            }
        });
        awardsOnlyHTML = aw; achievementsHTML = ach; associationsHTML = asc;
    })();

    // Hide type buttons for empty buckets
    (function() {
        var tmp = document.createElement('select');
        tmp.innerHTML = achievementsHTML;
        var hasAch = Array.from(tmp.options).some(function(o) { return o.value !== ''; });
        var btnAch = gid('kn-award-type-achievements');
        if (btnAch) btnAch.style.display = hasAch ? '' : 'none';

        tmp.innerHTML = associationsHTML;
        var hasAsc = Array.from(tmp.options).some(function(o) { return o.value !== ''; });
        var btnAsc = gid('kn-award-type-associations');
        if (btnAsc) btnAsc.style.display = hasAsc ? '' : 'none';
    })();

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

    var knTypeHTML = {
        awards:       awardsOnlyHTML,
        officers:     officerOptHTML,
        achievements: achievementsHTML,
        associations: associationsHTML
    };
    var knTypeTitles = {
        awards:       '<i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award',
        officers:     '<i class="fas fa-crown" style="margin-right:8px;color:#553c9a"></i>Add Officer Title',
        achievements: '<i class="fas fa-star" style="margin-right:8px;color:#744210"></i>Add Achievement Title',
        associations: '<i class="fas fa-handshake" style="margin-right:8px;color:#276749"></i>Add Association'
    };
    var knSelectLabelText = { awards: 'Award', officers: 'Title', achievements: 'Title', associations: 'Association' };
    function setAwardType(type) {
        if (typeof awCloseDropdown === 'function') awCloseDropdown();
        currentType = type;
        var isAssoc = (type === 'associations');
        gid('kn-award-modal-title').innerHTML = knTypeTitles[type] || knTypeTitles.awards;
        gid('kn-award-select').innerHTML = knTypeHTML[type] || awardsOnlyHTML;
        gid('kn-award-rank-row').style.display   = 'none';
        gid('kn-award-custom-row').style.display = 'none';
        gid('kn-award-rank-val').value           = '';
        gid('kn-award-info-line').innerHTML      = '';
        var lbl = gid('kn-award-select-label');
        if (lbl) lbl.innerHTML = (knSelectLabelText[type] || 'Award') + ' <span style="color:#e53e3e">*</span>';
        var note = gid('kn-award-givenby-note');
        if (note) note.style.display = isAssoc ? '' : 'none';
        var chips = gid('kn-award-officer-chips');
        if (chips) chips.style.display = isAssoc ? 'none' : '';
        ['awards', 'officers', 'achievements', 'associations'].forEach(function(t) {
            var btn = gid('kn-award-type-' + t);
            if (btn) btn.classList.toggle('kn-active', t === type);
        });
        checkRequired();
    }

    gid('kn-award-type-awards').addEventListener('click',       function() { setAwardType('awards'); });
    gid('kn-award-type-officers').addEventListener('click',     function() { setAwardType('officers'); });
    gid('kn-award-type-achievements').addEventListener('click', function() { setAwardType('achievements'); });
    gid('kn-award-type-associations').addEventListener('click', function() { setAwardType('associations'); });

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
        var hint = gid('kn-rank-hint');
        if (hint) hint.textContent = baseAwardId === 0
            ? '— click to select; green border = suggested next; dark blue = selected'
            : '— click to select; blue = already held, green border = suggested next';
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

    if (gid('kn-award-select')) awInitPicker(gid('kn-award-select'));
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
            var url = UIR_JS + 'KingdomAjax/playersearch/' + KINGDOM_ID + '&include_inactive=1&q=' + encodeURIComponent(term);
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('kn-award-player-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(p) {
                        return '<div class="kn-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                            + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr||'') + ':' + escHtml(p.PAbbr||'') + ')</span>'
                            + (p.Active === 0 ? ' <span style="color:#c53030;font-size:10px;font-weight:600">(Inactive)</span>' : '') + '</div>';
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
            var url = UIR_JS + 'KingdomAjax/playersearch/' + KINGDOM_ID + '&scope=all&include_inactive=1&include_suspended=1&q=' + encodeURIComponent(term);
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('kn-award-givenby-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(p) {
                        return '<div class="kn-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                            + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr||'') + ':' + escHtml(p.PAbbr||'') + ')</span>'
                            + (p.Active === 0 ? ' <span style="color:#c53030;font-size:10px;font-weight:600">(Inactive)</span>' : '')
                            + (p.Suspended   ? ' <span style="color:#c53030;font-size:10px;font-weight:600">(Banned)</span>'   : '') + '</div>';
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
        gid('kn-award-park-id').value    = '0';
        gid('kn-award-kingdom-id').value = '0';
        gid('kn-award-event-id').value   = '0';
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
        gid('kn-award-char-count').classList.remove('kn-char-warn');
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
        knActiveRecId = null;
    };

    // Track the recommendation that triggered the current award modal open
    var knActiveRecId = null;

    // Pre-populate award modal from a recommendation row
    window.knGiveFromRec = function(rec) {
        knOpenAwardModal();
        if (rec.Persona || rec.MundaneId) {
            gid('kn-award-player-text').value = rec.Persona || '';
            gid('kn-award-player-id').value   = String(rec.MundaneId || '');
        }
        if (rec.MundaneId) {
            var pid = String(rec.MundaneId);
            fetch(UIR_JS + 'PlayerAjax/player/' + pid + '/awardranks')
                .then(function(r) { return r.json(); })
                .then(function(ranks) {
                    knPlayerRanks = ranks || {};
                    var curAward = gid('kn-award-select').value;
                    if (curAward) buildRankPills(curAward);
                }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
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
                dimBtn.textContent = 'Confirm Delete?';
                dimBtn.classList.add('pk-rec-dismiss-confirm');
                dimBtn._confirmTimer = setTimeout(function() {
                    dimBtn.dataset.confirm = '';
                    dimBtn.textContent = 'Delete';
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
                        if (row) { row.classList.add('pk-rec-dismissed'); setTimeout(function() { row.remove(); }, 600); }
                    } else {
                        alert(d.error || 'Failed to delete recommendation.');
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
        gid('kn-award-char-count').classList.remove('kn-char-warn');
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
    if (typeof KnConfig === 'undefined') return;
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
        var hint = gid('kn-rec-rank-hint');
        if (hint) hint.textContent = baseAwardId === 0
            ? '— click to select; green border = suggested next; dark blue = selected'
            : '(optional) — blue = already held, green border = suggested next';
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

    if (gid('kn-rec-award-select')) {
        gid('kn-rec-award-select').querySelectorAll('optgroup[label="Associate Titles"]').forEach(function(og) { og.parentNode.removeChild(og); });
        awInitPicker(gid('kn-rec-award-select'));
        gid('kn-rec-award-select').addEventListener('change', function() {
            buildRecRankPills(this.value);
            checkRequired();
            var aid = parseInt(this.options[this.selectedIndex].getAttribute('data-award-id') || 0);
            var desc = AWARD_DESCRIPTIONS[aid] || '';
            var el = gid('kn-rec-award-desc');
            if (el) { el.textContent = desc; el.style.display = desc ? '' : 'none'; }
        });
    }

    gid('kn-rec-player-text').addEventListener('input', function() {
        gid('kn-rec-player-id').value = '';
        checkRequired();
        var term = this.value.trim();
        clearTimeout(playerTimer);
        if (term.length < 2) { gid('kn-rec-player-results').classList.remove('pk-ac-open'); return; }
        playerTimer = setTimeout(function() {
            var url = UIR_JS + 'KingdomAjax/playersearch/' + KINGDOM_ID + '&include_inactive=1&q=' + encodeURIComponent(term);
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('kn-rec-player-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(p) {
                        return '<div class="pk-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                            + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr||'') + ':' + escHtml(p.PAbbr||'') + ')</span>'
                            + (p.Active === 0 ? ' <span style="color:#c53030;font-size:10px;font-weight:600">(Inactive)</span>' : '') + '</div>';
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
    var _knPending = new Set();

    var _knPanelNames = {
        'kn-admin-body-details': 'Kingdom Details',
        'kn-admin-body-config':  'Configuration',
        'kn-admin-body-titles':  'Park Titles',
        'kn-admin-body-awards':  'Awards',
        'kn-admin-body-parks':   'Parks',
    };

    var _knPanelSaveBtn = {
        'kn-admin-body-details': 'kn-admin-details-save',
        'kn-admin-body-config':  'kn-admin-config-save',
        'kn-admin-body-titles':  'kn-admin-titles-save',
        'kn-admin-body-parks':   'kn-admin-parks-save',
    };

    function knMarkPending(panelId) {
        _knPending.add(panelId);
        var btnId = _knPanelSaveBtn[panelId];
        var btn = btnId && gid(btnId);
        if (btn) btn.classList.add('kn-save-dirty');
    }
    function knClearPending(panelId) {
        _knPending.delete(panelId);
        var btnId = _knPanelSaveBtn[panelId];
        var btn = btnId && gid(btnId);
        if (btn) btn.classList.remove('kn-save-dirty');
    }

    function knWirePendingPanel(panelId) {
        var panel = gid(panelId);
        if (!panel) return;
        panel.addEventListener('input',  function() { knMarkPending(panelId); });
        panel.addEventListener('change', function() { knMarkPending(panelId); });
    }

    window.knOpenAdminModal = function() {
        var overlay = gid('kn-admin-overlay');
        if (!overlay) return;
        _knDirty = false;
        _knPending.clear();
        Object.values(_knPanelSaveBtn).forEach(function(btnId) {
            var btn = gid(btnId);
            if (btn) btn.classList.remove('kn-save-dirty');
        });
        // Restore details fields to their last-saved server values
        gid('kn-admin-body-details').querySelectorAll('[data-original]').forEach(function(el) {
            if (el.tagName === 'TEXTAREA') el.value = el.dataset.original;
            else el.value = el.dataset.original;
        });
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
        if (_knPending.size > 0) {
            var names = Array.from(_knPending).map(function(id) { return _knPanelNames[id] || id; });
            knConfirm('You have unsaved changes in: ' + names.join(', ') + '. Close anyway?', function() {
                _knPending.clear();
                knCloseAdminModal();
            }, 'Unsaved Changes');
            return;
        }
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

            var description = (gid('kn-admin-description').value || '').trim();

            var fd = new FormData();
            var url = (gid('kn-admin-url').value || '').trim();

            fd.append('Name',         name);
            fd.append('Abbreviation', abbr);
            fd.append('Description',  description);
            fd.append('Url',          url);

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
                        knClearPending('kn-admin-body-details');
                        gid('kn-admin-body-details').querySelectorAll('[data-original]').forEach(function(el) {
                            el.dataset.original = el.value;
                        });
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
            var keyHints = {
                'AttendanceWeeklyMinimum': 'Minimum distinct weeks with at least one sign-in in the last 6 months. Leave blank to not require this.',
                'AttendanceDailyMinimum':  'Minimum distinct days with at least one sign-in in the last 6 months. Leave blank to not require this.',
                'AttendanceCreditMinimum': 'Minimum total credits earned in the last 6 months. Leave blank to not require this.',
                'MonthlyCreditMaximum':    'Cap on credits counted per calendar month (excess is discarded). Leave blank for no cap.',
            };
            if (keyHints[cfg.Key]) {
                var hint = document.createElement('span');
                hint.className = 'kn-cfg-hint';
                hint.setAttribute('data-hint', keyHints[cfg.Key]);
                hint.textContent = '?';
                lbl.appendChild(hint);
            }
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
                            if (ok) { knClearPending('kn-admin-body-config'); feedback('kn-admin-config-feedback', 'Configuration saved!', true); }
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
        delBtn.innerHTML   = '<i class="fas fa-trash"></i>';
        delBtn.title       = 'Delete';
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
                    knClearPending('kn-admin-body-titles');
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

        // If kingdom name differs from system name, show (?) hint
        var sysName = aw.AwardName || '';
        if (sysName && sysName !== aw.KingdomAwardName) {
            var hint = document.createElement('span');
            hint.className = 'kn-admin-alias-hint';
            hint.innerHTML = '<i class="fas fa-question-circle"></i>';
            hint.setAttribute('data-tip', 'Alias for system award ' + sysName);
            nameCell.td.appendChild(hint);
        }

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
        saveBtn.innerHTML   = '<i class="fas fa-save"></i>';
        saveBtn.title       = 'Save';
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
        delBtn.innerHTML   = '<i class="fas fa-trash"></i>';
        delBtn.title       = 'Delete';
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

        var customAddBtn  = gid('kn-admin-custom-add-btn');
        var customWrap    = gid('kn-admin-add-custom-wrap');
        var customCancel  = gid('kn-admin-custom-cancel');
        var btnRow        = addBtn ? addBtn.parentNode : null;

        function showAliasForm() {
            if (addWrap) addWrap.style.display = '';
            if (customWrap) customWrap.style.display = 'none';
            if (btnRow) btnRow.style.display = 'none';
        }
        function showCustomForm() {
            if (customWrap) customWrap.style.display = '';
            if (addWrap) addWrap.style.display = 'none';
            if (btnRow) btnRow.style.display = 'none';
        }
        function showButtons() {
            if (addWrap) addWrap.style.display = 'none';
            if (customWrap) customWrap.style.display = 'none';
            if (btnRow) btnRow.style.display = '';
        }

        if (addBtn) addBtn.addEventListener('click', showAliasForm);
        if (customAddBtn) customAddBtn.addEventListener('click', showCustomForm);
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                showButtons();
                resetAliasForm();
            });
        }
        if (customCancel) {
            customCancel.addEventListener('click', function() {
                showButtons();
                resetCustomForm();
            });
        }

        var newIsTitleCb = gid('kn-admin-new-istitle');
        var newTClassInp = gid('kn-admin-new-tclass');
        if (newIsTitleCb && newTClassInp) {
            newIsTitleCb.addEventListener('change', function() {
                newTClassInp.disabled = !this.checked;
            });
        }

        // ── Searchable system award dropdown ──
        var trigger   = gid('kn-admin-alias-trigger');
        var dropdown  = gid('kn-admin-alias-dropdown');
        var searchInp = gid('kn-admin-alias-search');
        var listEl    = gid('kn-admin-alias-list');
        var hiddenInp = gid('kn-admin-new-award-id');
        var nameInp   = gid('kn-admin-new-award-name');
        var labelSpan = trigger ? trigger.querySelector('.kn-admin-alias-label') : null;
        var sysAwards = KnConfig.systemAwards || [];
        var aliasOpen = false;

        function buildAliasList(filter) {
            if (!listEl) return;
            listEl.innerHTML = '';
            var lc = (filter || '').toLowerCase();
            var count = 0;
            sysAwards.forEach(function(sa) {
                if (lc && sa.Name.toLowerCase().indexOf(lc) === -1) return;
                var div = document.createElement('div');
                div.className = 'kn-admin-alias-item';
                div.textContent = sa.Name;
                div.setAttribute('data-id', sa.AwardId);
                div.addEventListener('click', function() {
                    selectAlias(sa.AwardId, sa.Name);
                });
                listEl.appendChild(div);
                count++;
            });
            if (count === 0) {
                var empty = document.createElement('div');
                empty.className = 'kn-admin-alias-empty';
                empty.textContent = 'No matching awards';
                listEl.appendChild(empty);
            }
        }

        function selectAlias(id, name) {
            if (hiddenInp) hiddenInp.value = id;
            if (labelSpan) { labelSpan.textContent = name; labelSpan.style.color = ''; }
            if (nameInp && !nameInp.value.trim()) nameInp.value = name;
            closeAlias();
        }

        function openAlias() {
            if (!dropdown || aliasOpen) return;
            aliasOpen = true;
            dropdown.style.display = '';
            buildAliasList('');
            if (searchInp) { searchInp.value = ''; searchInp.focus(); }
        }

        function closeAlias() {
            if (!dropdown) return;
            aliasOpen = false;
            dropdown.style.display = 'none';
        }

        function resetAliasForm() {
            if (hiddenInp) hiddenInp.value = '';
            if (labelSpan) { labelSpan.textContent = 'Select a system award…'; labelSpan.style.color = ''; }
            if (nameInp) nameInp.value = '';
            gid('kn-admin-new-reign')   && (gid('kn-admin-new-reign').value = '0');
            gid('kn-admin-new-month')   && (gid('kn-admin-new-month').value = '0');
            gid('kn-admin-new-istitle') && (gid('kn-admin-new-istitle').checked = false);
            gid('kn-admin-new-tclass')  && (gid('kn-admin-new-tclass').value = '0');
            gid('kn-admin-new-tclass')  && (gid('kn-admin-new-tclass').disabled = true);
            closeAlias();
        }

        if (trigger) {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                aliasOpen ? closeAlias() : openAlias();
            });
        }

        if (searchInp) {
            searchInp.addEventListener('input', function() {
                buildAliasList(this.value);
            });
            searchInp.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeAlias();
            });
        }

        // Close dropdown on outside click
        document.addEventListener('click', function(e) {
            if (aliasOpen && trigger && dropdown && !trigger.contains(e.target) && !dropdown.contains(e.target)) {
                closeAlias();
            }
        });

        // ── Save new award alias ──
        var saveNewBtn = gid('kn-admin-new-award-save');
        if (saveNewBtn) {
            saveNewBtn.addEventListener('click', function() {
                clearFeedback('kn-admin-awards-feedback');
                var awardId = parseInt((hiddenInp ? hiddenInp.value : '0') || '0', 10);
                var name    = (nameInp ? nameInp.value : '').trim();
                var reign   = gid('kn-admin-new-reign').value;
                var month   = gid('kn-admin-new-month').value;
                var isTitle = gid('kn-admin-new-istitle').checked ? 1 : 0;
                var tClass  = gid('kn-admin-new-tclass').value;

                if (!awardId) { feedback('kn-admin-awards-feedback', 'Please select a system award.', false); return; }
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
                        feedback('kn-admin-awards-feedback', 'Award alias created!', true);
                        setTimeout(function() { location.reload(); }, 900);
                    } else {
                        feedback('kn-admin-awards-feedback', (r && r.error) ? r.error : 'Create failed.', false);
                    }
                }, 'json').fail(function() { saveNewBtn.disabled = false; feedback('kn-admin-awards-feedback', 'Request failed.', false); });
            });
        }

        // ── Custom (kingdom-specific) award ──
        var customIsTitleCb = gid('kn-admin-custom-istitle');
        var customTClassInp = gid('kn-admin-custom-tclass');
        if (customIsTitleCb && customTClassInp) {
            customIsTitleCb.addEventListener('change', function() {
                customTClassInp.disabled = !this.checked;
            });
        }

        function resetCustomForm() {
            gid('kn-admin-custom-name')    && (gid('kn-admin-custom-name').value = '');
            gid('kn-admin-custom-reign')   && (gid('kn-admin-custom-reign').value = '0');
            gid('kn-admin-custom-month')   && (gid('kn-admin-custom-month').value = '0');
            gid('kn-admin-custom-istitle') && (gid('kn-admin-custom-istitle').checked = false);
            gid('kn-admin-custom-tclass')  && (gid('kn-admin-custom-tclass').value = '0');
            gid('kn-admin-custom-tclass')  && (gid('kn-admin-custom-tclass').disabled = true);
        }

        var saveCustomBtn = gid('kn-admin-custom-save');
        if (saveCustomBtn) {
            saveCustomBtn.addEventListener('click', function() {
                clearFeedback('kn-admin-awards-feedback');
                var name    = (gid('kn-admin-custom-name').value || '').trim();
                var reign   = gid('kn-admin-custom-reign').value;
                var month   = gid('kn-admin-custom-month').value;
                var isTitle = gid('kn-admin-custom-istitle').checked ? 1 : 0;
                var tClass  = gid('kn-admin-custom-tclass').value;

                if (!name) { feedback('kn-admin-awards-feedback', 'Award name is required.', false); return; }

                saveCustomBtn.disabled = true;
                $.post(BASE_URL + 'setaward', {
                    KingdomAwardId:   0,
                    AwardId:          0,
                    KingdomAwardName: name,
                    ReignLimit:       reign,
                    MonthLimit:       month,
                    IsTitle:          isTitle,
                    TitleClass:       tClass,
                }, function(r) {
                    saveCustomBtn.disabled = false;
                    if (r && r.status === 0) {
                        feedback('kn-admin-awards-feedback', 'Kingdom-specific award created!', true);
                        setTimeout(function() { location.reload(); }, 900);
                    } else {
                        feedback('kn-admin-awards-feedback', (r && r.error) ? r.error : 'Create failed.', false);
                    }
                }, 'json').fail(function() { saveCustomBtn.disabled = false; feedback('kn-admin-awards-feedback', 'Request failed.', false); });
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
                        knClearPending('kn-admin-body-parks');
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
        wireToggle('kn-admin-hdr-parks',      'kn-admin-body-parks',      'kn-admin-chev-parks');
        wireToggle('kn-admin-hdr-signinlink', 'kn-admin-body-signinlink', 'kn-admin-chev-signinlink');
        wireToggle('kn-admin-hdr-ops',        'kn-admin-body-ops',        'kn-admin-chev-ops');

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

        // Wire unsaved-change tracking on all editable panels
        Object.keys(_knPanelNames).forEach(knWirePendingPanel);

        var overlay = gid('kn-admin-overlay');
        if (overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) knCloseAdminModal();
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('kn-admin-overlay') && gid('kn-admin-overlay').classList.contains('kn-open')) {
                knCloseAdminModal();
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

                function doUpload(blob) {
                    var fd = new FormData();
                    fd.append('Heraldry', blob, file.name);
                    fetch(UPLOAD_URL, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(r) {
                            if (upl) upl.style.display = 'none';
                            if (r && r.status === 0) {
                                setTimeout(function() { window.location.reload(); }, 1200);
                            } else {
                                if (sel) sel.style.display = '';
                                alert((r && r.error) ? r.error : 'Upload failed. Please try again.');
                            }
                        })
                        .catch(function() {
                            if (upl) upl.style.display = 'none';
                            if (sel) sel.style.display = '';
                            alert('Request failed. Please try again.');
                        });
                }

                trimTransparentEdges(file, function(trimmed) {
                    file = trimmed;
                    if (file.size > 348836) {
                        var isPng = (file.type === 'image/png');
                        resizeImageToLimit(file, 348836, doUpload, function(errMsg) {
                            if (upl) upl.style.display = 'none';
                            if (sel) sel.style.display = '';
                            alert(errMsg || 'Could not resize image. Please choose a smaller file.');
                        }, isPng);
                    } else {
                        doUpload(file);
                    }
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
        var _pk_dark = orkIsDarkMode();
        var finalS = _pk_dark ? Math.min(Math.max(s * 1.15, 0.35), 0.90) : Math.max(s, 0.28);
        var hDeg   = Math.round(h * 360);
        var sPct   = Math.round(finalS * 100);
        document.documentElement.style.setProperty('--pk-hue', hDeg);
        document.documentElement.style.setProperty('--pk-sat', sPct + '%');
        var heroEl = document.querySelector('.pk-hero');
        if (heroEl) {
            var heroL = getComputedStyle(document.documentElement).getPropertyValue('--ork-hero-lightness').trim() || (_pk_dark ? '22%' : '18%');
            heroEl.style.backgroundColor =
                'hsl(' + hDeg + ',' + sPct + '%,' + heroL + ')';
        }
        document.documentElement.style.setProperty(
            '--pk-page-tint', 'rgba(' + dr + ',' + dg + ',' + db + ',0.05)'
        );
    } catch(e) { /* CORS or tainted canvas — keep default green */ }
}

// ---- Tab activation ----
function pkActivateTab(tab) {
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
    if (!PkConfig.canAdmin) return;
    var UIR_JS = PkConfig.uir;
    var SEARCH_URL = PkConfig.httpService + 'Search/SearchService.php';
    var PARK_ID = PkConfig.parkId;
    var awardOptHTML = PkConfig.awardOptHTML;
    var officerOptHTML = PkConfig.officerOptHTML;

    // Split awardOptHTML into: awards-only (Ladder+Custom+Other), achievements, associations
    var awardsOnlyHTML, achievementsHTML, associationsHTML;
    (function() {
        var tmp = document.createElement('select');
        tmp.innerHTML = awardOptHTML;
        var aw  = '<option value="">Select award...</option>';
        var ach = '<option value="">Select title...</option>';
        var asc = '<option value="">Select association...</option>';
        Array.from(tmp.children).forEach(function(child) {
            if (child.tagName === 'OPTION') {
                if (child.value === '') return;
                aw += child.outerHTML;
            } else if (child.tagName === 'OPTGROUP') {
                var lbl = child.label;
                if (lbl === 'Knighthoods' || lbl === 'Masterhoods' || lbl === 'Paragons' || lbl === 'Noble Titles') {
                    ach += child.outerHTML;
                } else if (lbl === 'Associate Titles') {
                    asc += child.outerHTML;
                } else {
                    aw += child.outerHTML;
                }
            }
        });
        awardsOnlyHTML = aw; achievementsHTML = ach; associationsHTML = asc;
    })();

    // Hide type buttons for empty buckets
    (function() {
        var tmp = document.createElement('select');
        tmp.innerHTML = achievementsHTML;
        var hasAch = Array.from(tmp.options).some(function(o) { return o.value !== ''; });
        var btnAch = gid('pk-award-type-achievements');
        if (btnAch) btnAch.style.display = hasAch ? '' : 'none';

        tmp.innerHTML = associationsHTML;
        var hasAsc = Array.from(tmp.options).some(function(o) { return o.value !== ''; });
        var btnAsc = gid('pk-award-type-associations');
        if (btnAsc) btnAsc.style.display = hasAsc ? '' : 'none';
    })();

    var currentType = 'awards';
    var givenByTimer, givenAtTimer, playerTimer;
    var pkPlayerRanks = {};

    function gid(id) { return document.getElementById(id); }

    function pkFixedAcPosition(inputEl, dropdownEl) {
        var rect = inputEl.getBoundingClientRect();
        dropdownEl.style.top   = (rect.bottom + 2) + 'px';
        dropdownEl.style.left  = rect.left + 'px';
        dropdownEl.style.width = rect.width + 'px';
    }

    function checkRequired() {
        var ok = !!gid('pk-award-player-id').value
              && !!gid('pk-award-select').value
              && !!gid('pk-award-givenby-id').value
              && !!gid('pk-award-date').value;
        gid('pk-award-save-new').disabled  = !ok;
        gid('pk-award-save-same').disabled = !ok;
    }

    var pkTypeHTML = {
        awards:       awardsOnlyHTML,
        officers:     officerOptHTML,
        achievements: achievementsHTML,
        associations: associationsHTML
    };
    var pkTypeTitles = {
        awards:       '<i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award',
        officers:     '<i class="fas fa-crown" style="margin-right:8px;color:#553c9a"></i>Add Officer Title',
        achievements: '<i class="fas fa-star" style="margin-right:8px;color:#744210"></i>Add Achievement Title',
        associations: '<i class="fas fa-handshake" style="margin-right:8px;color:#276749"></i>Add Association'
    };
    var pkSelectLabelText = { awards: 'Award', officers: 'Title', achievements: 'Title', associations: 'Association' };
    function setAwardType(type) {
        if (typeof awCloseDropdown === 'function') awCloseDropdown();
        currentType = type;
        var isAssoc = (type === 'associations');
        gid('pk-award-modal-title').innerHTML = pkTypeTitles[type] || pkTypeTitles.awards;
        gid('pk-award-select').innerHTML = pkTypeHTML[type] || awardsOnlyHTML;
        gid('pk-award-rank-row').style.display   = 'none';
        gid('pk-award-custom-row').style.display = 'none';
        gid('pk-award-rank-val').value           = '';
        gid('pk-award-info-line').innerHTML      = '';
        var lbl = gid('pk-award-select-label');
        if (lbl) lbl.innerHTML = (pkSelectLabelText[type] || 'Award') + ' <span style="color:#e53e3e">*</span>';
        var note = gid('pk-award-givenby-note');
        if (note) note.style.display = isAssoc ? '' : 'none';
        var chips = gid('pk-award-officer-chips');
        if (chips) chips.style.display = isAssoc ? 'none' : '';
        ['awards', 'officers', 'achievements', 'associations'].forEach(function(t) {
            var btn = gid('pk-award-type-' + t);
            if (btn) btn.classList.toggle('pk-active', t === type);
        });
        checkRequired();
    }

    gid('pk-award-type-awards').addEventListener('click',       function() { setAwardType('awards'); });
    gid('pk-award-type-officers').addEventListener('click',     function() { setAwardType('officers'); });
    gid('pk-award-type-achievements').addEventListener('click', function() { setAwardType('achievements'); });
    gid('pk-award-type-associations').addEventListener('click', function() { setAwardType('associations'); });

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
        var hint = gid('pk-rank-hint');
        if (hint) hint.textContent = baseAwardId === 0
            ? '— click to select; green border = suggested next; dark blue = selected'
            : '— click to select; blue = already held, green border = suggested next';
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

    if (gid('pk-award-select')) awInitPicker(gid('pk-award-select'));
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
            var url = UIR_JS + 'ParkAjax/park/' + PARK_ID + '/playersearch&scope=all&prioritize=1&include_inactive=1&q=' + encodeURIComponent(term);
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('pk-award-player-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(p) {
                        return '<div class="pk-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                            + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr||'') + ':' + escHtml(p.PAbbr||'') + ')</span>'
                            + (p.Active === 0 ? ' <span style="color:#c53030;font-size:10px;font-weight:600">(Inactive)</span>' : '') + '</div>';
                    }).join('')
                    : '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No players found</div>';
                pkFixedAcPosition(gid('pk-award-player-text'), el);
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
            var url = UIR_JS + 'ParkAjax/park/' + PkConfig.parkId + '/playersearch&scope=all&prioritize=1&include_inactive=1&include_suspended=1&q=' + encodeURIComponent(term);
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('pk-award-givenby-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(p) {
                        return '<div class="pk-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                            + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr||'') + ':' + escHtml(p.PAbbr||'') + ')</span>'
                            + (p.Active === 0 ? ' <span style="color:#c53030;font-size:10px;font-weight:600">(Inactive)</span>' : '')
                            + (p.Suspended   ? ' <span style="color:#c53030;font-size:10px;font-weight:600">(Banned)</span>'   : '') + '</div>';
                    }).join('')
                    : '<div class="pk-ac-item" style="color:#a0aec0;cursor:default">No results</div>';
                pkFixedAcPosition(gid('pk-award-givenby-text'), el);
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
        gid('pk-award-park-id').value    = '0';
        gid('pk-award-kingdom-id').value = '0';
        gid('pk-award-event-id').value   = '0';
        givenAtTimer = setTimeout(function() {
            var today = new Date().toISOString().slice(0, 10);
            var url = SEARCH_URL + '?Action=Search%2FLocation&name=' + encodeURIComponent(term) + '&date=' + today + '&limit=6';
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('pk-award-givenat-results');
                pkFixedAcPosition(gid('pk-award-givenat-text'), el);
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
        gid('pk-award-char-count').classList.remove('pk-char-warn');
        gid('pk-award-givenby-text').value       = '';
        gid('pk-award-givenby-id').value         = '';
        gid('pk-award-givenby-results').classList.remove('pk-ac-open');
        gid('pk-award-givenat-text').value = PkConfig.parkName;
        gid('pk-award-park-id').value = String(PkConfig.parkId);
        gid('pk-award-kingdom-id').value         = String(PkConfig.kingdomId || 0);
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
        pkActiveRecId = null;
    };

    // Track the recommendation that triggered the current award modal open
    var pkActiveRecId = null;

    // Pre-populate award modal from a recommendation row
    window.pkGiveFromRec = function(rec) {
        pkOpenAwardModal();
        if (rec.Persona || rec.MundaneId) {
            gid('pk-award-player-text').value = rec.Persona || '';
            gid('pk-award-player-id').value   = String(rec.MundaneId || '');
        }
        if (rec.MundaneId) {
            var pid = String(rec.MundaneId);
            fetch(UIR_JS + 'PlayerAjax/player/' + pid + '/awardranks')
                .then(function(r) { return r.json(); })
                .then(function(ranks) {
                    pkPlayerRanks = ranks || {};
                    var curAward = gid('pk-award-select').value;
                    if (curAward) buildRankPills(curAward);
                }).catch(function(err) { if (err.name !== 'AbortError') console.warn('[revised.js] fetch failed:', err); });
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
                dimBtn.textContent = 'Confirm Delete?';
                dimBtn.classList.add('pk-rec-dismiss-confirm');
                dimBtn._confirmTimer = setTimeout(function() {
                    dimBtn.dataset.confirm = '';
                    dimBtn.textContent = 'Delete';
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
                        if (row) { row.classList.add('pk-rec-dismissed'); setTimeout(function() { row.remove(); }, 600); }
                    } else {
                        alert(d.error || 'Failed to delete recommendation.');
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
        gid('pk-award-char-count').classList.remove('pk-char-warn');
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
        var hint = gid('pk-rec-rank-hint');
        if (hint) hint.textContent = baseAwardId === 0
            ? '— click to select; green border = suggested next; dark blue = selected'
            : '(optional) — blue = already held, green border = suggested next';
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

    if (gid('pk-rec-award-select')) {
        gid('pk-rec-award-select').querySelectorAll('optgroup[label="Associate Titles"]').forEach(function(og) { og.parentNode.removeChild(og); });
        awInitPicker(gid('pk-rec-award-select'));
        gid('pk-rec-award-select').addEventListener('change', function() {
            buildRecRankPills(this.value);
            checkRequired();
            var aid = parseInt(this.options[this.selectedIndex].getAttribute('data-award-id') || 0);
            var desc = AWARD_DESCRIPTIONS[aid] || '';
            var el = gid('pk-rec-award-desc');
            if (el) { el.textContent = desc; el.style.display = desc ? '' : 'none'; }
        });
    }

    // Player search
    gid('pk-rec-player-text').addEventListener('input', function() {
        gid('pk-rec-player-id').value = '';
        checkRequired();
        var term = this.value.trim();
        clearTimeout(playerTimer);
        if (term.length < 2) { gid('pk-rec-player-results').classList.remove('pk-ac-open'); return; }
        playerTimer = setTimeout(function() {
            var url = UIR_JS + 'KingdomAjax/playersearch/' + PkConfig.kingdomId + '&include_inactive=1&q=' + encodeURIComponent(term);
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                var el = gid('pk-rec-player-results');
                el.innerHTML = (data && data.length)
                    ? data.map(function(p) {
                        return '<div class="pk-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                            + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr||'') + ':' + escHtml(p.PAbbr||'') + ')</span>'
                            + (p.Active === 0 ? ' <span style="color:#c53030;font-size:10px;font-weight:600">(Inactive)</span>' : '') + '</div>';
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
    if (typeof EvConfig === 'undefined') return;
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
        var _evAcWidget = $('#ev-PlayerName').data('autocomplete');
        if (_evAcWidget) _evAcWidget._renderItem = function(ul, item) {
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
                if (hero) {
                    var _ev_dark = orkIsDarkMode();
                    var heroL = getComputedStyle(document.documentElement).getPropertyValue('--ork-hero-lightness').trim() || (_ev_dark ? '22%' : '18%');
                    var evSPct = _ev_dark ? Math.round(s * 68) : Math.round(s * 55);
                    hero.style.backgroundColor = 'hsl('+Math.round(h)+','+evSPct+'%,'+heroL+')';
                }
            } catch(e){}
        }
        if (img.complete && img.naturalWidth > 0) { extract(); }
        else { img.addEventListener('load', extract); }
    }
    evApplyHeroColor();

    // ---- Edit modal ----
    var _evEditOriginals = {};
    var _evEditForm = document.getElementById('ev-edit-form');
    var _evEditSaveBtn = document.getElementById('ev-edit-save-btn');

    if (_evEditForm) {
        _evEditForm.querySelectorAll('input, textarea').forEach(function(el) {
            if (el.name) _evEditOriginals[el.name] = el.value;
        });
        _evEditForm.querySelectorAll('input, textarea').forEach(function(el) {
            el.addEventListener('input', evCheckEditDirty);
            el.addEventListener('change', evCheckEditDirty);
        });
    }

    function evCheckEditDirty() {
        if (!_evEditForm) return;
        var dirty = false;
        _evEditForm.querySelectorAll('input, textarea').forEach(function(el) {
            if (el.name && _evEditOriginals.hasOwnProperty(el.name) && el.value !== _evEditOriginals[el.name]) {
                dirty = true;
            }
        });
        if (_evEditSaveBtn) _evEditSaveBtn.disabled = !dirty;
    }

    function evRestoreEditForm() {
        if (!_evEditForm) return;
        _evEditForm.querySelectorAll('input, textarea').forEach(function(el) {
            if (el.name && _evEditOriginals.hasOwnProperty(el.name)) {
                el.value = _evEditOriginals[el.name];
                if (el._flatpickr) el._flatpickr.setDate(el.value, false);
            }
        });
        if (_evEditSaveBtn) _evEditSaveBtn.disabled = true;
    }

    function evActuallyCloseEditModal() {
        var overlay = document.getElementById('ev-edit-modal');
        if (overlay) overlay.classList.remove('ev-modal-open');
        document.body.style.overflow = '';
    }

    window.evOpenEditModal = function() {
        var overlay = document.getElementById('ev-edit-modal');
        if (overlay) overlay.classList.add('ev-modal-open');
        document.body.style.overflow = 'hidden';
        if (typeof evFeesReset === 'function') evFeesReset(EvConfig.fees || []);
        if (typeof evLinksReset === 'function') evLinksReset(EvConfig.links || []);
    };
    window.evCloseEditModal = function() {
        if (_evEditSaveBtn && !_evEditSaveBtn.disabled) {
            pnConfirm({ title: 'Unsaved Changes', message: 'You have unsaved changes. Discard them?', confirmText: 'Discard', danger: true }, function() {
                evRestoreEditForm();
                evActuallyCloseEditModal();
            });
            return;
        }
        evActuallyCloseEditModal();
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
    window.evOpenCheckinModal = function(mundaneId, personaName, classId) {
        document.getElementById('ev-checkin-mundane-id').value = mundaneId;
        document.getElementById('ev-checkin-name').textContent = personaName;
        var creditsInput = document.querySelector('#ev-checkin-form [name="Credits"]');
        if (creditsInput) {
            var rsvpCr = document.getElementById('ev-rsvp-credits');
            var rsvpVal = rsvpCr ? parseFloat(rsvpCr.value) : NaN;
            creditsInput.value = (rsvpVal > 0) ? rsvpVal : evGetSavedCredits();
        }
        if (classId) {
            var classSelect = document.querySelector('#ev-checkin-form [name="ClassId"]');
            if (classSelect) classSelect.value = classId;
        }
        var overlay = document.getElementById('ev-checkin-modal');
        if (overlay) overlay.classList.add('ev-modal-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() {
            var classSelect = document.querySelector('#ev-checkin-form [name="ClassId"]');
            if (classSelect) classSelect.focus();
        }, 50);
    };
    window.evQuickCheckin = function(btn, mundaneId, classId) {
        var initialHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        var fd = new FormData();
        fd.append('MundaneId', mundaneId);
        fd.append('ClassId', classId);
        fd.append('Credits', evGetSavedCredits());
        fd.append('AttendanceDate', new Date().toISOString().slice(0, 10));
        fetch(EvConfig.uir + 'EventAjax/add_attendance/' + EvConfig.eventId + '/' + EvConfig.detailId, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 0) {
                evMarkCheckedIn(mundaneId);
                if (data.attendance) { evAppendAttendanceRow(data.attendance); }
            } else {
                btn.disabled = false;
                btn.innerHTML = initialHtml;
                alert(data.error || 'Check-in failed. Please try again.');
            }
        })
        .catch(function(err) { btn.disabled = false; btn.innerHTML = initialHtml; alert('Request failed: ' + err.message); });
    };
    // Shared helper: mark all check-in buttons for a mundane as done, remove quick buttons
    function evMarkCheckedIn(mundaneId) {
        document.querySelectorAll('.ev-checkin-as-btn[data-mundane="' + mundaneId + '"]').forEach(function(b) { b.remove(); });
        document.querySelectorAll('.ev-checkin-btn[data-mundane="' + mundaneId + '"]').forEach(function(b) {
            b.classList.add('ev-checkin-done');
            b.disabled = true;
            b.removeAttribute('onclick');
            b.innerHTML = '<i class="fas fa-user-check"></i> Checked In';
        });
    }
    // Shared helper: append a row to the attendance table (creating it if needed)
    function evAppendAttendanceRow(att) {
        var delUrl = EvConfig.uir + 'AttendanceAjax/attendance/' + att.AttendanceId + '/delete';
        var kingCell = att.KingdomId ? '<a href="' + EvConfig.uir + 'Kingdom/profile/' + att.KingdomId + '">' + escHtml(att.KingdomName || '') + '</a>' : escHtml(att.KingdomName || '');
        var parkCell = att.ParkId    ? '<a href="' + EvConfig.uir + 'Park/profile/'    + att.ParkId    + '">' + escHtml(att.ParkName    || '') + '</a>' : escHtml(att.ParkName    || '');
        var newRow = '<tr data-att-id="' + att.AttendanceId + '" data-mundane-id="' + att.MundaneId + '" data-att-class="' + (att.ClassId || '') + '" data-att-date="' + escHtml(att.Date || '') + '">' +
            '<td><a href="' + EvConfig.uir + 'Player/profile/' + att.MundaneId + '">' + escHtml(att.Persona || '') + '</a></td>' +
            '<td>' + kingCell + '</td>' +
            '<td>' + parkCell + '</td>' +
            '<td class="ev-class-cell">' + escHtml(att.ClassName || '') + '</td>' +
            '<td class="ev-credits-cell">' + escHtml(att.Credits || '') + '</td>' +
            '<td class="ev-del-cell">' +
                '<button class="ev-icon-btn" title="Edit class &amp; credits" style="color:#9ca3af;border:none;background:none;padding:2px 4px;font-size:0.8rem;" onclick="evOpenAttEdit(this)"><i class="fas fa-pencil-alt"></i></button>' +
                '<a class="ev-del-link" title="Remove" href="#" data-del-url="' + delUrl + '" onclick="evConfirmAttDelete(event,this)">×</a>' +
            '</td>' +
            '</tr>';
        var tableBody = document.querySelector('#ev-attendance-table tbody');
        if (tableBody) {
            if (window._evAttDt) {
                window._evAttDt.row.add($(newRow)).draw(false);
            } else {
                tableBody.insertAdjacentHTML('beforeend', newRow);
                if (window.evInitAttDt) window.evInitAttDt();
            }
        } else {
            var emptyMsg = document.querySelector('#ev-tab-attendance .ev-empty');
            var tableHtml = '<table class="display" id="ev-attendance-table" style="width:100%">' +
                '<thead><tr><th>Player</th><th>Kingdom</th><th>Park</th><th>Class</th><th>Credits</th><th class="ev-del-cell"></th></tr></thead>' +
                '<tbody>' + newRow + '</tbody></table>';
            if (emptyMsg) emptyMsg.outerHTML = tableHtml;
            if (window.evInitAttDt) window.evInitAttDt();
        }
        var cnt = document.querySelector('.ev-tab-nav li[data-tab="ev-tab-attendance"] .ev-tab-count');
        if (cnt) cnt.textContent = '(' + ((parseInt(cnt.textContent.replace(/[^0-9]/g, '')) || 0) + 1) + ')';
    }
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
                evMarkCheckedIn(mundaneId);
                evCloseCheckinModal();
                if (data.attendance) { evAppendAttendanceRow(data.attendance); }
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
                var newRow = '<tr data-att-id="' + att.AttendanceId + '" data-mundane-id="' + att.MundaneId + '" data-att-class="' + (att.ClassId || '') + '" data-att-date="' + escHtml(att.Date || '') + '">' +
                    '<td><a href="' + EvConfig.uir + 'Player/profile/' + att.MundaneId + '">' + escHtml(att.Persona || '') + '</a></td>' +
                    '<td>' + kingCell + '</td>' +
                    '<td>' + parkCell + '</td>' +
                    '<td class="ev-class-cell">' + escHtml(att.ClassName || '') + '</td>' +
                    '<td class="ev-credits-cell">' + escHtml(att.Credits || '') + '</td>' +
                    '<td class="ev-del-cell">' +
                        '<button class="ev-icon-btn" title="Edit class &amp; credits" style="color:#9ca3af;border:none;background:none;padding:2px 4px;font-size:0.8rem;" onclick="evOpenAttEdit(this)"><i class="fas fa-pencil-alt"></i></button>' +
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

                // Mark RSVP check-in buttons as done, remove quick-checkin button
                evMarkCheckedIn(att.MundaneId);

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

    // ---- Staff Modal ----
    if (EvConfig.canManageStaff || EvConfig.canManageSchedule || EvConfig.canManageFeast) {
        var gid = function(id) { return document.getElementById(id); };
        var evStaffAcTimer = null;

        window.evOpenStaffModal = function() {
            var modal = gid('ev-staff-modal');
            if (!modal) return;
            gid('ev-staff-role').value = '';
            gid('ev-staff-player-name').value = '';
            gid('ev-staff-player-id').value = '';
            gid('ev-staff-can-manage').checked = false;
            gid('ev-staff-can-attendance').checked = false;
            if (gid('ev-staff-can-schedule')) gid('ev-staff-can-schedule').checked = false;
            if (gid('ev-staff-can-feast'))    gid('ev-staff-can-feast').checked    = false;
            gid('ev-staff-error').style.display = 'none';
            gid('ev-staff-ac').classList.remove('kn-ac-open');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            setTimeout(function() { gid('ev-staff-role').focus(); }, 50);
        };

        window.evCloseStaffModal = function() {
            var modal = gid('ev-staff-modal');
            if (modal) modal.style.display = 'none';
            document.body.style.overflow = '';
        };

        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'ev-staff-modal') evCloseStaffModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('ev-staff-modal') && gid('ev-staff-modal').style.display === 'flex') {
                evCloseStaffModal();
            }
        });

        // Player autocomplete in staff modal
        var staffAcEl  = gid('ev-staff-ac');
        var staffNameEl = gid('ev-staff-player-name');
        var staffIdEl   = gid('ev-staff-player-id');
        var OPEN_CLASS  = 'kn-ac-open';
        var ITEM_SEL    = '.kn-ac-item[data-id]';

        function escHtmlSt(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function evStaffPositionAc() {
            if (!staffNameEl || !staffAcEl) return;
            var r = staffNameEl.getBoundingClientRect();
            staffAcEl.style.top   = (r.bottom + 2) + 'px';
            staffAcEl.style.left  = r.left + 'px';
            staffAcEl.style.width = r.width + 'px';
        }

        function evStaffRenderAc(results) {
            if (!staffAcEl) return;
            if (!results || !results.length) {
                staffAcEl.classList.remove(OPEN_CLASS);
                return;
            }
            staffAcEl.innerHTML = results.map(function(pl) {
                var abbr = (pl.KAbbr && pl.PAbbr) ? ' <span style="color:#a0aec0;font-size:11px">(' + escHtmlSt(pl.KAbbr) + ':' + escHtmlSt(pl.PAbbr) + ')</span>' : '';
                return '<div class="kn-ac-item" tabindex="-1" data-id="' + pl.MundaneId + '" data-name="' + encodeURIComponent(pl.Persona) + '">'
                    + escHtmlSt(pl.Persona) + abbr + '</div>';
            }).join('');
            evStaffPositionAc();
            staffAcEl.classList.add(OPEN_CLASS);
        }

        if (staffNameEl && staffAcEl) {
            // Override CSS positioning so the dropdown escapes the modal's overflow-y:auto
            staffAcEl.style.position = 'fixed';
            staffAcEl.style.zIndex   = '9999';
            staffAcEl.style.width    = '300px';
            // Apply kn-ac-results styling class
            staffAcEl.className = 'kn-ac-results';
            staffAcEl.style.display = ''; // clear inline display:none so CSS class controls visibility

            staffNameEl.addEventListener('input', function() {
                var term = this.value.trim();
                staffIdEl.value = '';
                if (term.length < 2) { staffAcEl.classList.remove(OPEN_CLASS); return; }
                clearTimeout(evStaffAcTimer);
                evStaffAcTimer = setTimeout(function() {
                    var kid = EvConfig.kingdomId || 0;
                    if (!kid) {
                        // No kingdom on this event — search all players via SearchService
                        fetch(EvConfig.httpService + 'Search/SearchService.php?Action=Search%2FPlayer&type=all&search=' + encodeURIComponent(term) + '&limit=10')
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                var res = (d || []).map(function(pl) {
                                    return { MundaneId: pl.MundaneId, Persona: pl.Persona, KAbbr: pl.KAbbr || '', PAbbr: pl.PAbbr || '' };
                                });
                                evStaffRenderAc(res.length ? res : [{ MundaneId: -1, Persona: 'No players found' }]);
                                var ph = staffAcEl.querySelector('[data-id="-1"]');
                                if (ph) ph.removeAttribute('data-id');
                            });
                        return;
                    }
                    // Kingdom-scoped first
                    fetch(EvConfig.uir + 'KingdomAjax/playersearch/' + kid + '&q=' + encodeURIComponent(term) + '&scope=own')
                        .then(function(r) { return r.json(); })
                        .then(function(own) {
                            own = own || [];
                            if (own.length >= 5) {
                                evStaffRenderAc(own);
                            } else {
                                // Fewer than 5 kingdom results — also fetch outside kingdom and append
                                fetch(EvConfig.uir + 'KingdomAjax/playersearch/' + kid + '&q=' + encodeURIComponent(term) + '&scope=exclude')
                                    .then(function(r2) { return r2.json(); })
                                    .then(function(other) {
                                        other = (other || []).slice(0, 10 - own.length);
                                        var combined = own.concat(other);
                                        evStaffRenderAc(combined.length ? combined : [{ MundaneId: 0, Persona: 'No players found', KAbbr: '', PAbbr: '' }]);
                                        // Remove no-results placeholder from being selectable
                                        if (!combined.length) staffAcEl.querySelector('[data-id="0"]') && (staffAcEl.querySelector('[data-id="0"]').removeAttribute('data-id'));
                                    });
                            }
                        });
                }, 220);
            });

            staffAcEl.addEventListener('click', function(e) {
                var item = e.target.closest(ITEM_SEL);
                if (!item) return;
                staffNameEl.value = decodeURIComponent(item.dataset.name);
                staffIdEl.value   = item.dataset.id;
                staffAcEl.classList.remove(OPEN_CLASS);
            });

            staffNameEl.addEventListener('blur', function() {
                setTimeout(function() { staffAcEl.classList.remove(OPEN_CLASS); }, 160);
            });

            acKeyNav(staffNameEl, staffAcEl, OPEN_CLASS, ITEM_SEL);
        }

        window.evSubmitStaff = function() {
            var role       = gid('ev-staff-role').value.trim();
            var mundaneId  = gid('ev-staff-player-id').value;
            var canManage  = gid('ev-staff-can-manage').checked ? 1 : 0;
            var canAtt     = gid('ev-staff-can-attendance').checked ? 1 : 0;
            var canSched   = gid('ev-staff-can-schedule') && gid('ev-staff-can-schedule').checked ? 1 : 0;
            var canFeast   = gid('ev-staff-can-feast')    && gid('ev-staff-can-feast').checked    ? 1 : 0;
            var errEl      = gid('ev-staff-error');
            var saveBtn    = gid('ev-staff-save-btn');

            errEl.style.display = 'none';
            if (!role)       { errEl.textContent = 'Please enter a role.'; errEl.style.display = 'block'; return; }
            if (!mundaneId)  { errEl.textContent = 'Please select a player.'; errEl.style.display = 'block'; return; }

            var orig = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

            var fd = new FormData();
            fd.append('MundaneId',     mundaneId);
            fd.append('Persona',       gid('ev-staff-player-name').value.trim());
            fd.append('RoleName',      role);
            fd.append('CanManage',     canManage);
            fd.append('CanAttendance', canAtt);
            fd.append('CanSchedule',   canSched);
            fd.append('CanFeast',      canFeast);

            fetch(EvConfig.uir + 'EventAjax/add_staff/' + EvConfig.eventId + '/' + EvConfig.detailId, {
                method: 'POST', body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0 && data.staff) {
                    evCloseStaffModal();
                    var s = data.staff;
                    var chk = '<i class="fas fa-check" style="color:#276749"></i>';
                    var x   = '<i class="fas fa-times" style="color:#a0aec0"></i>';
                    var newRow = '<tr id="ev-staff-row-' + s.EventStaffId + '">' +
                        '<td><a href="' + EvConfig.uir + 'Player/profile/' + s.MundaneId + '">' + s.Persona + '</a></td>' +
                        '<td>' + s.RoleName + '</td>' +
                        '<td>' + (s.CanManage ? chk : x) + '</td>' +
                        '<td>' + (s.CanAttendance ? chk : x) + '</td>' +
                        '<td>' + (s.CanSchedule ? chk : x) + '</td>' +
                        '<td>' + (s.CanFeast ? chk : x) + '</td>' +
                        '<td class="ev-del-cell"><button class="ev-del-link" title="Remove" onclick="evRemoveStaff(this,' + s.EventStaffId + ')" style="background:none;border:none;cursor:pointer;color:#e53e3e;font-size:16px;padding:0">&times;</button></td>' +
                        '</tr>';
                    var tbody = gid('ev-staff-tbody');
                    if (tbody) {
                        tbody.insertAdjacentHTML('beforeend', newRow);
                    } else {
                        // First staff member: table doesn't exist yet, reload to render it properly
                        location.reload();
                        return;
                    }
                    var empty = gid('ev-staff-empty');
                    if (empty) empty.style.display = 'none';
                    // Update tab count badge
                    var navItems = document.querySelectorAll('#ev-tab-nav li');
                    navItems.forEach(function(li) {
                        if (li.getAttribute('data-tab') === 'ev-tab-staff') {
                            var badge = li.querySelector('.ev-tab-count');
                            if (badge) badge.textContent = parseInt(badge.textContent || '0') + 1;
                        }
                    });
                } else {
                    errEl.textContent = data.error || 'An error occurred.';
                    errEl.style.display = 'block';
                }
            })
            .catch(function(err) {
                errEl.textContent = 'Request failed: ' + err.message;
                errEl.style.display = 'block';
            })
            .finally(function() {
                allSaveBtns.forEach(function(b) { b.disabled = false; });
                saveBtn.innerHTML = orig;
                if (errEl.style.display === 'block') return; // save failed — don't reset form
                if (postAction === 'similar') {
                    gid('ev-sched-mode').value = 'add';
                    gid('ev-sched-id').value   = '';
                    gid('ev-sched-modal-title').textContent = 'Add Schedule Item';
                    if (typeof evShowScheduleSaveButtons === 'function') evShowScheduleSaveButtons('add');
                    var tEl = gid('ev-sched-title'); if (tEl) { tEl.focus(); tEl.select(); }
                } else if (postAction === 'new') {
                    if (typeof evOpenScheduleModal === 'function') evOpenScheduleModal();
                }
            });
        };

        window.evShowScheduleSaveButtons = function(mode) {
            var secondaries = document.querySelectorAll('#ev-schedule-modal .ev-sched-save-secondary');
            secondaries.forEach(function(b) { b.style.display = (mode === 'add') ? '' : 'none'; });
        };

        window.evRemoveStaff = function(btn, staffId) {
            if (!confirm('Remove this staff member?')) return;
            var fd = new FormData();
            fd.append('StaffId', staffId);
            fetch(EvConfig.uir + 'EventAjax/remove_staff/' + EvConfig.eventId + '/' + EvConfig.detailId, {
                method: 'POST', body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0) {
                    var row = gid('ev-staff-row-' + staffId);
                    if (row) row.remove();
                    var tbody = gid('ev-staff-tbody');
                    if (tbody && tbody.querySelectorAll('tr').length === 0) {
                        var table = gid('ev-staff-table');
                        if (table) {
                            table.style.display = 'none';
                            var empty = gid('ev-staff-empty');
                            if (empty) empty.style.display = '';
                        }
                    }
                    // Update tab count badge
                    var navItems = document.querySelectorAll('#ev-tab-nav li');
                    navItems.forEach(function(li) {
                        if (li.getAttribute('data-tab') === 'ev-tab-staff') {
                            var badge = li.querySelector('.ev-tab-count');
                            if (badge) {
                                var n = parseInt(badge.textContent || '1') - 1;
                                badge.textContent = Math.max(0, n);
                            }
                        }
                    });
                } else {
                    alert(data.error || 'Could not remove staff member.');
                }
            })
            .catch(function(err) { alert('Request failed: ' + err.message); });
        };

        // ---- Schedule modal ----

        // --- Schedule leads state & helpers ---
        var evSchedLeads = [];
        var evSchedLeadAcTimer = null;

        function evSchedLeadsCell(leads) {
            if (!leads || !leads.length) return '';
            return leads.map(function(l) {
                return '<a href="' + EvConfig.uir + 'Playernew/index/' + l.MundaneId + '">' + escHtmlSt(l.Persona) + '</a>';
            }).join(', ');
        }

        function evRenderSchedLeads() {
            var list = gid('ev-sched-leads-list');
            if (!list) return;
            if (!evSchedLeads.length) {
                list.innerHTML = '<span style="color:#a0aec0;font-size:12px;line-height:26px">None assigned</span>';
                return;
            }
            list.innerHTML = evSchedLeads.map(function(l) {
                return '<span style="display:inline-flex;align-items:center;gap:4px;background:#e2e8f0;border-radius:4px;padding:3px 8px;font-size:12px">' +
                    escHtmlSt(l.Persona) +
                    '<button type="button" onclick="evRemoveSchedLead(' + l.MundaneId + ')" style="background:none;border:none;cursor:pointer;color:#718096;font-size:13px;padding:0;margin-left:2px;line-height:1">&times;</button>' +
                    '</span>';
            }).join('');
        }

        window.evRemoveSchedLead = function(mundaneId) {
            evSchedLeads = evSchedLeads.filter(function(l) { return l.MundaneId !== mundaneId; });
            evRenderSchedLeads();
            evRefreshStaffQuickAdd();
        };

        function evRefreshStaffQuickAdd() {
            var qaRow  = gid('ev-sched-staff-quickadd-row');
            var qaList = gid('ev-sched-staff-qa-list');
            if (!qaRow || !qaList) return;
            var staff = (EvConfig.staffList || []).filter(function(s) {
                return !evSchedLeads.some(function(l) { return l.MundaneId === s.MundaneId; });
            });
            if (!staff.length) { qaRow.style.display = 'none'; return; }
            qaRow.style.display = '';
            qaList.innerHTML = staff.map(function(s) {
                return '<div style="display:flex;align-items:center;justify-content:space-between;padding:7px 10px;border-bottom:1px solid #f0f0f0;font-size:13px">' +
                    '<span>' + escHtmlSt(s.Persona) + '</span>' +
                    '<button type="button" onclick="evStaffQuickAddLead(' + s.MundaneId + ',\'' + encodeURIComponent(s.Persona) + '\')" ' +
                    'style="background:#276749;color:#fff;border:none;border-radius:4px;padding:3px 10px;cursor:pointer;font-size:12px">+ Add</button>' +
                    '</div>';
            }).join('');
        }

        window.evToggleStaffQuickAdd = function() {
            var list    = gid('ev-sched-staff-qa-list');
            var chevron = gid('ev-sched-staff-qa-chevron');
            if (!list) return;
            var open = list.style.display !== 'none';
            list.style.display = open ? 'none' : '';
            if (chevron) chevron.style.transform = open ? '' : 'rotate(90deg)';
        };

        window.evStaffQuickAddLead = function(mundaneId, encodedName) {
            var name = decodeURIComponent(encodedName);
            if (!evSchedLeads.some(function(l) { return l.MundaneId === mundaneId; })) {
                evSchedLeads.push({ MundaneId: mundaneId, Persona: name });
                evRenderSchedLeads();
                evRefreshStaffQuickAdd();
            }
        };

        // Lead player autocomplete
        var leadAcEl    = gid('ev-sched-lead-ac');
        var leadInputEl = gid('ev-sched-lead-input');
        if (leadInputEl && leadAcEl) {
            leadAcEl.style.position = 'fixed';
            leadAcEl.style.zIndex   = '9999';
            leadAcEl.style.display  = '';
            leadAcEl.className      = 'kn-ac-results';

            function evLeadPositionAc() {
                var r = leadInputEl.getBoundingClientRect();
                leadAcEl.style.top   = (r.bottom + 2) + 'px';
                leadAcEl.style.left  = r.left + 'px';
                leadAcEl.style.width = r.width + 'px';
            }

            function evLeadRenderAc(results) {
                if (!results || !results.length) { leadAcEl.classList.remove(OPEN_CLASS); return; }
                leadAcEl.innerHTML = results.map(function(pl) {
                    var abbr = (pl.KAbbr && pl.PAbbr) ? ' <span style="color:#a0aec0;font-size:11px">(' + escHtmlSt(pl.KAbbr) + ':' + escHtmlSt(pl.PAbbr) + ')</span>' : '';
                    return '<div class="kn-ac-item" tabindex="-1" data-id="' + pl.MundaneId + '" data-name="' + encodeURIComponent(pl.Persona) + '">' + escHtmlSt(pl.Persona) + abbr + '</div>';
                }).join('');
                evLeadPositionAc();
                leadAcEl.classList.add(OPEN_CLASS);
            }

            leadInputEl.addEventListener('input', function() {
                var term = this.value.trim();
                if (term.length < 2) { leadAcEl.classList.remove(OPEN_CLASS); return; }
                clearTimeout(evSchedLeadAcTimer);
                evSchedLeadAcTimer = setTimeout(function() {
                    var kid = EvConfig.kingdomId || 0;
                    if (!kid) {
                        fetch(EvConfig.httpService + 'Search/SearchService.php?Action=Search%2FPlayer&type=all&search=' + encodeURIComponent(term) + '&limit=10')
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                var res = (d || []).map(function(pl) { return { MundaneId: pl.MundaneId, Persona: pl.Persona, KAbbr: pl.KAbbr || '', PAbbr: pl.PAbbr || '' }; });
                                evLeadRenderAc(res.length ? res : [{ MundaneId: -1, Persona: 'No players found' }]);
                                var ph = leadAcEl.querySelector('[data-id="-1"]');
                                if (ph) ph.removeAttribute('data-id');
                            });
                        return;
                    }
                    fetch(EvConfig.uir + 'KingdomAjax/playersearch/' + kid + '&q=' + encodeURIComponent(term) + '&scope=own')
                        .then(function(r) { return r.json(); })
                        .then(function(own) {
                            own = own || [];
                            if (own.length >= 5) {
                                evLeadRenderAc(own);
                            } else {
                                fetch(EvConfig.uir + 'KingdomAjax/playersearch/' + kid + '&q=' + encodeURIComponent(term) + '&scope=exclude')
                                    .then(function(r2) { return r2.json(); })
                                    .then(function(other) {
                                        other = (other || []).slice(0, 10 - own.length);
                                        var combined = own.concat(other);
                                        evLeadRenderAc(combined.length ? combined : [{ MundaneId: 0, Persona: 'No players found' }]);
                                        if (!combined.length && leadAcEl.querySelector('[data-id="0"]')) leadAcEl.querySelector('[data-id="0"]').removeAttribute('data-id');
                                    });
                            }
                        });
                }, 220);
            });

            leadAcEl.addEventListener('click', function(e) {
                var item = e.target.closest(ITEM_SEL);
                if (!item) return;
                var mid  = parseInt(item.dataset.id);
                var name = decodeURIComponent(item.dataset.name);
                leadAcEl.classList.remove(OPEN_CLASS);
                leadInputEl.value = '';
                if (!mid || evSchedLeads.some(function(l) { return l.MundaneId === mid; })) return;
                evSchedLeads.push({ MundaneId: mid, Persona: name });
                evRenderSchedLeads();
            });

            leadInputEl.addEventListener('blur', function() {
                setTimeout(function() { leadAcEl.classList.remove(OPEN_CLASS); }, 160);
            });

            acKeyNav(leadInputEl, leadAcEl, OPEN_CLASS, ITEM_SEL);
        }


        var EV_CATEGORIES = {
            'Administrative':    { icon: 'fa-clipboard-list', color: '#546e7a', bg: '#eceff1' },
            'Tournament':        { icon: 'fa-trophy',          color: '#b8860b', bg: '#fffde7' },
            'Battlegame':        { icon: 'fa-shield-alt',      color: '#c0392b', bg: '#fdecea' },
            'Arts and Sciences': { icon: 'fa-palette',         color: '#7b1fa2', bg: '#f3e5f5' },
            'Class':             { icon: 'fa-graduation-cap',  color: '#1565c0', bg: '#e3f2fd' },
            'Feast and Food':    { icon: 'fa-utensils',        color: '#e65100', bg: '#fff3e0' },
            'Court':             { icon: 'fa-crown',           color: '#4e342e', bg: '#efebe9' },
            'Meeting':           { icon: 'fa-users',           color: '#276749', bg: '#f0fff4' },
            'Other':             { icon: 'fa-star',            color: '#757575', bg: '#fafafa' }
        };

        window.evOpenScheduleModal = function() {
            var modal = gid('ev-schedule-modal');
            if (!modal) return;
            gid('ev-sched-mode').value         = 'add';
            gid('ev-sched-id').value           = '';
            gid('ev-sched-modal-title').textContent = 'Add Schedule Item';
            gid('ev-sched-save-label').textContent  = 'Save and Close';
            if (typeof evShowScheduleSaveButtons === 'function') evShowScheduleSaveButtons('add');
            gid('ev-sched-category').value           = 'Other';
            gid('ev-sched-secondary-category').value = '';
            gid('ev-sched-title').value       = '';
            gid('ev-sched-location').value     = '';
            gid('ev-sched-description').value  = '';
            gid('ev-sched-error').style.display = 'none';
            evSchedLeads = [];
            evRenderSchedLeads();
            // Collapse staff quick-add and refresh
            var qaList = gid('ev-sched-staff-qa-list');
            var qaChevron = gid('ev-sched-staff-qa-chevron');
            if (qaList) { qaList.style.display = 'none'; }
            if (qaChevron) { qaChevron.style.transform = ''; }
            evRefreshStaffQuickAdd();
            // Apply event bounds as min/max and default start to event start
            var startEl = gid('ev-sched-start');
            var endEl   = gid('ev-sched-end');
            if (EvConfig.eventStart) { startEl.min = EvConfig.eventStart; endEl.min = EvConfig.eventStart; }
            if (EvConfig.eventEnd)   { startEl.max = EvConfig.eventEnd;   endEl.max = EvConfig.eventEnd; }
            // Default start to event start, end to start + 1hr
            startEl.value = EvConfig.eventStart || '';
            if (EvConfig.eventStart) {
                var pad = function(n) { return String(n).padStart(2, '0'); };
                var ts = new Date(EvConfig.eventStart);
                ts.setHours(ts.getHours() + 1);
                endEl.value = ts.getFullYear() + '-' + pad(ts.getMonth()+1) + '-' + pad(ts.getDate()) +
                              'T' + pad(ts.getHours()) + ':' + pad(ts.getMinutes());
            } else {
                endEl.value = '';
            }
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            setTimeout(function() { gid('ev-sched-title').focus(); }, 50);
        };

        window.evCloseScheduleModal = function() {
            var modal = gid('ev-schedule-modal');
            if (modal) modal.style.display = 'none';
            document.body.style.overflow = '';
        };

        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'ev-schedule-modal') evCloseScheduleModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && gid('ev-schedule-modal') && gid('ev-schedule-modal').style.display === 'flex') {
                evCloseScheduleModal();
            }
        });

        // Auto-set end = start + 1hr whenever start changes
        var schedStartEl = gid('ev-sched-start');
        if (schedStartEl) {
            schedStartEl.addEventListener('change', function() {
                var endEl = gid('ev-sched-end');
                if (!endEl) return;
                var ts = new Date(this.value);
                if (isNaN(ts)) return;
                ts.setHours(ts.getHours() + 1);
                var pad = function(n) { return String(n).padStart(2, '0'); };
                endEl.value = ts.getFullYear() + '-' + pad(ts.getMonth()+1) + '-' + pad(ts.getDate()) +
                              'T' + pad(ts.getHours()) + ':' + pad(ts.getMinutes());
            });
        }

        function evFmtDayHeader(dateStr) {
            var d = new Date(dateStr + 'T12:00:00');
            var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            return days[d.getDay()] + ', ' + months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        }

        function evFmtTime(dtStr) {
            var d = new Date(dtStr.replace(' ', 'T'));
            if (isNaN(d)) return dtStr;
            var h = d.getHours(), m = d.getMinutes(), ampm = h >= 12 ? 'pm' : 'am';
            h = h % 12 || 12;
            return h + ':' + String(m).padStart(2,'0') + ampm;
        }

        function escHtmlSch(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        window.evOpenScheduleEditModal = function(scheduleId, btn) {
            var modal = gid('ev-schedule-modal');
            if (!modal) return;
            var row = btn.closest('tr');
            gid('ev-sched-mode').value         = 'edit';
            gid('ev-sched-id').value           = scheduleId;
            gid('ev-sched-modal-title').textContent = 'Edit Schedule Item';
            gid('ev-sched-save-label').textContent  = 'Save Changes';
            if (typeof evShowScheduleSaveButtons === 'function') evShowScheduleSaveButtons('edit');
            gid('ev-sched-category').value           = row.getAttribute('data-category') || 'Other';
            gid('ev-sched-secondary-category').value = row.getAttribute('data-secondary-category') || '';
            gid('ev-sched-title').value       = row.getAttribute('data-title') || '';
            gid('ev-sched-location').value     = row.getAttribute('data-location') || '';
            gid('ev-sched-description').value  = row.getAttribute('data-description') || '';
            gid('ev-sched-error').style.display = 'none';
            try { evSchedLeads = JSON.parse(row.getAttribute('data-leads') || '[]'); } catch(e) { evSchedLeads = []; }
            evRenderSchedLeads();
            // Collapse staff quick-add and refresh
            var qaList = gid('ev-sched-staff-qa-list');
            var qaChevron = gid('ev-sched-staff-qa-chevron');
            if (qaList) { qaList.style.display = 'none'; }
            if (qaChevron) { qaChevron.style.transform = ''; }
            evRefreshStaffQuickAdd();
            var startEl = gid('ev-sched-start');
            var endEl   = gid('ev-sched-end');
            if (EvConfig.eventStart) { startEl.min = EvConfig.eventStart; endEl.min = EvConfig.eventStart; }
            if (EvConfig.eventEnd)   { startEl.max = EvConfig.eventEnd;   endEl.max = EvConfig.eventEnd; }
            startEl.value = row.getAttribute('data-start') || '';
            endEl.value   = row.getAttribute('data-end')   || '';
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            setTimeout(function() { gid('ev-sched-title').focus(); }, 50);
        };

        window.evSubmitSchedule = function(postAction) {
            postAction = postAction || 'close';
            var title   = gid('ev-sched-title').value.trim();
            var start   = gid('ev-sched-start').value;
            var end     = gid('ev-sched-end').value;
            var loc     = gid('ev-sched-location').value.trim();
            var desc    = gid('ev-sched-description').value.trim();
            var errEl   = gid('ev-sched-error');
            var activeBtnId = postAction === 'similar' ? 'ev-sched-save-similar-btn'
                            : postAction === 'new'     ? 'ev-sched-save-new-btn'
                            : 'ev-sched-save-btn';
            var saveBtn = gid(activeBtnId) || gid('ev-sched-save-btn');
            var allSaveBtns = document.querySelectorAll('#ev-schedule-modal .ev-sched-save-any');

            errEl.style.display = 'none';
            if (!title) { errEl.textContent = 'Please enter a title.'; errEl.style.display = 'block'; return; }
            if (!start) { errEl.textContent = 'Please enter a start time.'; errEl.style.display = 'block'; return; }
            if (!end)   { errEl.textContent = 'Please enter an end time.'; errEl.style.display = 'block'; return; }
            if (new Date(end) < new Date(start)) {
                errEl.textContent = 'End time cannot be before start time.'; errEl.style.display = 'block'; return;
            }

            var orig = saveBtn.innerHTML;
            allSaveBtns.forEach(function(b) { b.disabled = true; });
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

            var cat    = gid('ev-sched-category').value || 'Other';
            var secCat = gid('ev-sched-secondary-category').value || '';
            var fd = new FormData();
            fd.append('Category',          cat);
            fd.append('SecondaryCategory', secCat);
            fd.append('Title',       title);
            fd.append('StartTime',   start.replace('T', ' '));
            fd.append('EndTime',     end.replace('T', ' '));
            fd.append('Location',    loc);
            fd.append('Description', desc);
            fd.append('Leads',       JSON.stringify(evSchedLeads));

            var isEdit = gid('ev-sched-mode').value === 'edit';
            var schedId = gid('ev-sched-id').value;
            var url = isEdit
                ? EvConfig.uir + 'EventAjax/update_schedule/' + EvConfig.eventId + '/' + EvConfig.detailId
                : EvConfig.uir + 'EventAjax/add_schedule/' + EvConfig.eventId + '/' + EvConfig.detailId;
            if (isEdit) fd.append('ScheduleId', schedId);

            fetch(url, {
                method: 'POST', body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0 && data.schedule) {
                    if (postAction === 'close') evCloseScheduleModal();
                    var s = data.schedule;
                    var startCell = escHtmlSch(evFmtTime(s.StartTime));
                    var endCell   = escHtmlSch(evFmtTime(s.EndTime));
                    var catCfg = EV_CATEGORIES[s.Category] || EV_CATEGORIES['Other'];
                    var glyphHtml = (function(cat, secCat) {
                        var cfg    = EV_CATEGORIES[cat]    || EV_CATEGORIES['Other'];
                        var secCfg = secCat ? (EV_CATEGORIES[secCat] || EV_CATEGORIES['Other']) : null;
                        var p = '<i class="fas fa-fw ' + cfg.icon + '" style="color:' + cfg.color + '" title="' + escHtmlSch(cat) + '"></i>';
                        var s2 = secCfg
                            ? '<i class="fas fa-fw ' + secCfg.icon + '" style="color:' + secCfg.color + ';margin-right:4px" title="' + escHtmlSch(secCat) + '"></i>'
                            : '<span style="display:inline-block;width:1.25em;margin-right:4px"></span>';
                        return p + s2;
                    })(s.Category, s.SecondaryCategory || '');
                    var actionCells = '<td class="ev-del-cell">' +
                        '<button class="ev-edit-link" title="Edit" onclick="evOpenScheduleEditModal(' + s.EventScheduleId + ',this)" style="background:none;border:none;cursor:pointer;color:#666;font-size:13px;padding:0 5px 0 0"><i class="fas fa-pencil-alt"></i></button>' +
                        '<button class="ev-del-link" title="Remove" onclick="evRemoveSchedule(this,' + s.EventScheduleId + ')" style="background:none;border:none;cursor:pointer;color:#e53e3e;font-size:16px;padding:0">&times;</button>' +
                        '</td>';
                    if (isEdit) {
                        var row = gid('ev-schedule-row-' + s.EventScheduleId);
                        if (row) {
                            row.setAttribute('data-title',       s.Title);
                            row.setAttribute('data-start',       s.StartTime.replace(' ', 'T').substring(0, 16));
                            row.setAttribute('data-end',         s.EndTime.replace(' ', 'T').substring(0, 16));
                            row.setAttribute('data-location',    s.Location);
                            row.setAttribute('data-description', s.Description);
                            row.setAttribute('data-category',           s.Category);
                            row.setAttribute('data-secondary-category', s.SecondaryCategory || '');
                            row.setAttribute('data-leads',              JSON.stringify(s.Leads || []));
                            row.style.background = catCfg.bg;
                            row.cells[0].innerHTML = startCell;
                            row.cells[1].innerHTML = endCell;
                            row.cells[2].innerHTML = glyphHtml + escHtmlSch(s.Title);
                            row.cells[3].textContent = s.Location;
                            row.cells[4].innerHTML = evSchedLeadsCell(s.Leads || []);
                            row.cells[5].textContent = s.Description;
                            if (row.cells[6]) row.cells[6].innerHTML = actionCells.replace(/^<td[^>]*>/, '').replace(/<\/td>$/, '');
                        }
                    } else {
                        var newRow = '<tr id="ev-schedule-row-' + s.EventScheduleId + '"' +
                            ' data-title="' + s.Title.replace(/&/g,'&amp;').replace(/"/g,'&quot;') + '"' +
                            ' data-start="' + s.StartTime.replace(' ','T').substring(0,16) + '"' +
                            ' data-end="'   + s.EndTime.replace(' ','T').substring(0,16) + '"' +
                            ' data-location="' + s.Location.replace(/&/g,'&amp;').replace(/"/g,'&quot;') + '"' +
                            ' data-description="' + s.Description.replace(/&/g,'&amp;').replace(/"/g,'&quot;') + '"' +
                            ' data-category="' + escHtmlSch(s.Category) + '"' +
                            ' data-secondary-category="' + escHtmlSch(s.SecondaryCategory || '') + '"' +
                            ' data-leads="' + JSON.stringify(s.Leads || []).replace(/"/g,'&quot;') + '"' +
                            ' style="background:' + catCfg.bg + '">' +
                            '<td style="white-space:nowrap">' + startCell + '</td>' +
                            '<td style="white-space:nowrap">' + endCell + '</td>' +
                            '<td>' + glyphHtml + escHtmlSch(s.Title) + '</td>' +
                            '<td>' + escHtmlSch(s.Location) + '</td>' +
                            '<td>' + evSchedLeadsCell(s.Leads || []) + '</td>' +
                            '<td>' + escHtmlSch(s.Description) + '</td>' +
                            actionCells +
                            '</tr>';
                        var dateKey = s.StartTime.substring(0, 10).replace(/-/g, '');
                        var tbody = gid('ev-schedule-tbody-' + dateKey);
                        if (!tbody) {
                            // Build a new day section and insert in chronological order
                            var dateStr = s.StartTime.substring(0, 10);
                            var dayLabel = evFmtDayHeader(dateStr);
                            var delTh = EvConfig.canManageSchedule ? '<th class="ev-del-cell"></th>' : '';
                            var delCol = EvConfig.canManageSchedule ? '<col style="width:56px">' : '';
                            var newSection = '<div class="ev-sched-day-section" data-date="' + dateStr + '">' +
                                '<div class="ev-sched-day-header">' + dayLabel + '</div>' +
                                '<table class="ev-table ev-sched-table" id="ev-schedule-table-' + dateKey + '">' +
                                '<colgroup><col style="width:90px"><col style="width:90px"><col style="width:22%"><col style="width:15%"><col style="width:18%"><col>' + delCol + '</colgroup>' +
                                '<thead><tr><th>Start</th><th>End</th><th>Title</th><th>Location</th><th>Lead(s)</th><th>Description</th>' + delTh + '</tr></thead>' +
                                '<tbody id="ev-schedule-tbody-' + dateKey + '"></tbody>' +
                                '</table></div>';
                            var container = gid('ev-schedule-container');
                            if (!container) { location.reload(); return; }
                            var sections = container.querySelectorAll('.ev-sched-day-section');
                            var inserted = false;
                            sections.forEach(function(sec) {
                                if (!inserted && sec.getAttribute('data-date') > dateStr) {
                                    sec.insertAdjacentHTML('beforebegin', newSection);
                                    inserted = true;
                                }
                            });
                            if (!inserted) container.insertAdjacentHTML('beforeend', newSection);
                            tbody = gid('ev-schedule-tbody-' + dateKey);
                        }
                        if (tbody) {
                            tbody.insertAdjacentHTML('beforeend', newRow);
                        } else {
                            location.reload(); return;
                        }
                        var empty = gid('ev-schedule-empty');
                        if (empty) empty.style.display = 'none';
                        var navItems = document.querySelectorAll('#ev-tab-nav li');
                        navItems.forEach(function(li) {
                            if (li.getAttribute('data-tab') === 'ev-tab-schedule') {
                                var badge = li.querySelector('.ev-tab-count');
                                if (badge) badge.textContent = parseInt(badge.textContent || '0') + 1;
                            }
                        });
                        evBuildScheduleFilters();
                    }
                } else {
                    errEl.textContent = data.error || 'An error occurred.';
                    errEl.style.display = 'block';
                }
            })
            .catch(function(err) {
                errEl.textContent = 'Request failed: ' + err.message;
                errEl.style.display = 'block';
            })
            .finally(function() {
                saveBtn.disabled = false;
                saveBtn.innerHTML = orig;
            });
        };

        window.evBuildScheduleFilters = function() {
            var container = gid('ev-sched-filters');
            if (!container) return;
            var rows = document.querySelectorAll('[id^="ev-schedule-tbody-"] tr');
            var present = {};
            rows.forEach(function(row) {
                var cat = row.getAttribute('data-category') || 'Other';
                present[cat] = true;
                var sec = row.getAttribute('data-secondary-category') || '';
                if (sec) present[sec] = true;
            });
            var order = ['Administrative','Tournament','Battlegame','Arts and Sciences','Class','Feast and Food','Court','Meeting','Other'];
            container.innerHTML = '';
            var count = 0;
            order.forEach(function(cat) {
                if (!present[cat]) return;
                count++;
                var cfg = EV_CATEGORIES[cat] || EV_CATEGORIES['Other'];
                var pill = document.createElement('button');
                pill.className = 'ev-sched-pill ev-sched-pill-active';
                pill.setAttribute('data-cat', cat);
                pill.style.background = cfg.bg;
                pill.style.borderColor = cfg.color;
                pill.style.color = '#333';
                pill.innerHTML = '<i class="fas ' + cfg.icon + '" style="color:' + cfg.color + ';margin-right:5px"></i>' + escHtmlSch(cat);
                pill.addEventListener('click', function() { evToggleScheduleFilter(cat); });
                container.appendChild(pill);
            });
            container.style.display = count >= 2 ? 'flex' : 'none';
        };

        window.evToggleScheduleFilter = function(cat) {
            var pill = document.querySelector('#ev-sched-filters [data-cat="' + cat + '"]');
            if (!pill) return;
            var isActive = pill.classList.contains('ev-sched-pill-active');
            var cfg = EV_CATEGORIES[cat] || EV_CATEGORIES['Other'];
            if (isActive) {
                pill.classList.remove('ev-sched-pill-active');
                pill.classList.add('ev-sched-pill-inactive');
            } else {
                pill.classList.remove('ev-sched-pill-inactive');
                pill.classList.add('ev-sched-pill-active');
                pill.style.background = cfg.bg;
                pill.style.borderColor = cfg.color;
                pill.style.color = '#333';
                var icon = pill.querySelector('i');
                if (icon) icon.style.color = cfg.color;
            }
            document.querySelectorAll('[id^="ev-schedule-tbody-"] tr').forEach(function(row) {
                var primary = row.getAttribute('data-category') || 'Other';
                var secondary = row.getAttribute('data-secondary-category') || '';
                if (primary !== cat && secondary !== cat) return;
                // Re-evaluate visibility: show if any of the row's categories has an active pill
                var primPill = document.querySelector('#ev-sched-filters [data-cat="' + primary + '"]');
                var secPill  = secondary ? document.querySelector('#ev-sched-filters [data-cat="' + secondary + '"]') : null;
                var primActive = primPill ? primPill.classList.contains('ev-sched-pill-active') : true;
                var secActive  = secPill  ? secPill.classList.contains('ev-sched-pill-active')  : false;
                row.style.display = (primActive || secActive) ? '' : 'none';
            });
        };

        window.evRemoveSchedule = function(btn, scheduleId) {
            if (!confirm('Remove this schedule item?')) return;
            var fd = new FormData();
            fd.append('ScheduleId', scheduleId);
            fetch(EvConfig.uir + 'EventAjax/remove_schedule/' + EvConfig.eventId + '/' + EvConfig.detailId, {
                method: 'POST', body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.status === 0) {
                    var row = gid('ev-schedule-row-' + scheduleId);
                    if (row) row.remove();
                    var daySection = row ? row.closest('.ev-sched-day-section') : null;
                    if (daySection) {
                        var tbody = daySection.querySelector('tbody');
                        if (tbody && tbody.querySelectorAll('tr').length === 0) {
                            daySection.remove();
                        }
                    }
                    var container = gid('ev-schedule-container');
                    if (container && container.querySelectorAll('.ev-sched-day-section').length === 0) {
                        var empty = gid('ev-schedule-empty');
                        if (empty) empty.style.display = '';
                    }
                    var navItems = document.querySelectorAll('#ev-tab-nav li');
                    navItems.forEach(function(li) {
                        if (li.getAttribute('data-tab') === 'ev-tab-schedule') {
                            var badge = li.querySelector('.ev-tab-count');
                            if (badge) {
                                var n = parseInt(badge.textContent || '1') - 1;
                                badge.textContent = Math.max(0, n);
                            }
                        }
                    });
                    evBuildScheduleFilters();
                } else {
                    alert(data.error || 'Could not remove schedule item.');
                }
            })
            .catch(function(err) { alert('Request failed: ' + err.message); });
        };
        // Initialize schedule filters on page load
        evBuildScheduleFilters();
    }
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
                if (hero) {
                    var _en_dark = orkIsDarkMode();
                    var heroL = getComputedStyle(document.documentElement).getPropertyValue('--ork-hero-lightness').trim() || (_en_dark ? '22%' : '18%');
                    var enSPct = _en_dark ? Math.round(s * 68) : Math.round(s * 55);
                    hero.style.backgroundColor = 'hsl('+Math.round(h)+','+enSPct+'%,'+heroL+')';
                }
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
                if (banner) {
                    var _ec_dark = orkIsDarkMode();
                    var heroL = getComputedStyle(document.documentElement).getPropertyValue('--ork-hero-lightness').trim() || (_ec_dark ? '22%' : '18%');
                    var ecSPct = _ec_dark ? Math.round(s * 68) : Math.round(s * 55);
                    banner.style.backgroundColor = 'hsl('+Math.round(h)+','+ecSPct+'%,'+heroL+')';
                }
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
        tr.dataset.mundaneId    = entry.MundaneId || 0;
        tr.dataset.classId      = entry.ClassId || 0;
        tr.dataset.credits      = entry.Credits || 1;
        var td1 = document.createElement('td');
        var td1Link = document.createElement('a');
        td1Link.href = PkConfig.uir + 'Player/profile/' + (entry.MundaneId || 0);
        td1Link.target = '_blank';
        td1Link.rel = 'noopener';
        td1Link.textContent = entry.Persona;
        var td1Icon = document.createElement('i');
        td1Icon.className = 'fas fa-external-link-alt';
        td1Icon.style.cssText = 'margin-left:5px;font-size:10px;color:#a0aec0;vertical-align:middle';
        td1Link.appendChild(td1Icon);
        td1.appendChild(td1Link);
        var td2 = document.createElement('td'); td2.className = 'pk-att-class-cell';  td2.textContent = className;
        var td3 = document.createElement('td'); td3.className = 'pk-att-credits-cell'; td3.textContent = entry.Credits || 1;
        var td4 = document.createElement('td'); td4.className = 'pk-att-actions-cell';
        if (entry.AttendanceId) {
            var editBtn = document.createElement('button');
            editBtn.className = 'pk-att-edit-btn'; editBtn.title = 'Edit';
            editBtn.innerHTML = '<i class="fas fa-pencil-alt"></i>';
            editBtn.addEventListener('click', function() { pkInlineEditRow(tr); });
            var delBtn = document.createElement('button');
            delBtn.className = 'pk-att-del-btn'; delBtn.title = 'Remove';
            delBtn.innerHTML = '<i class="fas fa-times"></i>';
            delBtn.addEventListener('click', function() { pkDeleteEnteredRow(delBtn); });
            td4.appendChild(editBtn);
            td4.appendChild(delBtn);
        }
        tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3); tr.appendChild(td4);
        return tr;
    }

    function pkInlineEditRow(tr) {
        // Already in edit mode?
        if (tr.classList.contains('pk-att-editing')) return;
        tr.classList.add('pk-att-editing');

        var td2 = tr.querySelector('.pk-att-class-cell');
        var td3 = tr.querySelector('.pk-att-credits-cell');
        var td4 = tr.querySelector('.pk-att-actions-cell');

        var origClass   = tr.dataset.classId;
        var origCredits = tr.dataset.credits;

        // Replace class cell with select
        var sel = document.createElement('select');
        sel.className = 'pk-att-inline-select';
        (PkConfig.classes || []).forEach(function(c) {
            var opt = document.createElement('option');
            opt.value = c.ClassId; opt.textContent = c.ClassName;
            if (String(c.ClassId) === String(origClass)) opt.selected = true;
            sel.appendChild(opt);
        });
        td2.innerHTML = ''; td2.appendChild(sel);

        // Replace credits cell with input
        var inp = document.createElement('input');
        inp.type = 'number'; inp.min = '0.5'; inp.step = '0.5';
        inp.value = origCredits; inp.className = 'pk-att-inline-credits';
        td3.innerHTML = ''; td3.appendChild(inp);

        // Replace action buttons with save/cancel
        td4.innerHTML = '';
        var saveBtn = document.createElement('button');
        saveBtn.className = 'pk-att-save-btn'; saveBtn.title = 'Save';
        saveBtn.innerHTML = '<i class="fas fa-check"></i>';
        var cancelBtn = document.createElement('button');
        cancelBtn.className = 'pk-att-cancel-btn'; cancelBtn.title = 'Cancel';
        cancelBtn.innerHTML = '<i class="fas fa-times"></i>';
        td4.appendChild(saveBtn); td4.appendChild(cancelBtn);

        cancelBtn.addEventListener('click', function() {
            tr.classList.remove('pk-att-editing');
            td2.innerHTML = ''; td2.textContent = pkClassNames[origClass] || '';
            td3.innerHTML = ''; td3.textContent = origCredits;
            td4.innerHTML = '';
            var editBtn = document.createElement('button');
            editBtn.className = 'pk-att-edit-btn'; editBtn.title = 'Edit';
            editBtn.innerHTML = '<i class="fas fa-pencil-alt"></i>';
            editBtn.addEventListener('click', function() { pkInlineEditRow(tr); });
            var delBtn = document.createElement('button');
            delBtn.className = 'pk-att-del-btn'; delBtn.title = 'Remove';
            delBtn.innerHTML = '<i class="fas fa-times"></i>';
            delBtn.addEventListener('click', function() { pkDeleteEnteredRow(delBtn); });
            td4.appendChild(editBtn); td4.appendChild(delBtn);
        });

        saveBtn.addEventListener('click', function() {
            var newClass   = sel.value;
            var newCredits = parseFloat(inp.value) || 1;
            var aid        = tr.dataset.attendanceId;
            var mid        = tr.dataset.mundaneId;
            var date       = gid('pk-att-date').value;
            saveBtn.disabled = true;
            $.post(PkConfig.uir + 'AttendanceAjax/attendance/' + aid + '/edit',
                { Date: date, Credits: newCredits, ClassId: newClass, MundaneId: mid },
                function(r) {
                    if (r && r.status === 0) {
                        tr.dataset.classId = newClass;
                        tr.dataset.credits = newCredits;
                        tr.classList.remove('pk-att-editing');
                        td2.innerHTML = ''; td2.textContent = pkClassNames[newClass] || '';
                        td3.innerHTML = ''; td3.textContent = newCredits;
                        td4.innerHTML = '';
                        var editBtn = document.createElement('button');
                        editBtn.className = 'pk-att-edit-btn'; editBtn.title = 'Edit';
                        editBtn.innerHTML = '<i class="fas fa-pencil-alt"></i>';
                        editBtn.addEventListener('click', function() { pkInlineEditRow(tr); });
                        var delBtn = document.createElement('button');
                        delBtn.className = 'pk-att-del-btn'; delBtn.title = 'Remove';
                        delBtn.innerHTML = '<i class="fas fa-times"></i>';
                        delBtn.addEventListener('click', function() { pkDeleteEnteredRow(delBtn); });
                        td4.appendChild(editBtn); td4.appendChild(delBtn);
                        pkLastClass[mid] = newClass;
                    } else {
                        pkAttShowFeedback((r && r.error) ? r.error : 'Save failed.', false);
                        saveBtn.disabled = false;
                    }
                }, 'json'
            ).fail(function() {
                pkAttShowFeedback('Request failed. Please try again.', false);
                saveBtn.disabled = false;
            });
        });

        sel.focus();
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
        pkAttSelectedInactive = false;
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
        var wasInactive = pkAttSelectedInactive;
        pkSubmit(
            { AttendanceDate: gid('pk-att-date').value, MundaneId: pid, ClassId: cls, Credits: cred },
            function(ok, err, aid, reactivated) {
                if (ok) {
                    var midInt = parseInt(pid, 10);
                    pkAttEntered[midInt] = true;
                    pkLastClass[midInt]  = cls;
                    if (reactivated || wasInactive) {
                        pkAttShowFeedback(name + ' reactivated and attendance added.', true);
                    } else {
                        pkAttHideFeedback();
                    }
                    pkAttRecorded({ AttendanceId: aid, MundaneId: midInt, Persona: name, ClassId: cls, Credits: cred });
                    gid('pk-att-player-name').value = '';
                    gid('pk-att-player-id').value   = '';
                    pkAttSelectedInactive = false;
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
            if (r && r.status === 0) {  cb(true, null, r.attendanceId || 0, !!r.reactivated); }
            else                     cb(false, (r && r.error) ? r.error : 'Submission failed.', 0, false);
        }, 'json').fail(function() { cb(false, 'Request failed. Please try again.', 0, false); });
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
    // Tracks whether the currently selected player in the search field is inactive.
    // When true, clicking Add triggers a reactivation confirm dialog before submit.
    var pkAttSelectedInactive = false;

    function pkAttMakeItem(v) {
        return {
            label: v.Persona + ' \u2014 ' + pkAttAbbr(v),
            name: v.Persona,
            value: v.MundaneId,
            suspended: !!(v.PenaltyBox || v.Suspended),
            inactive: (typeof v.Active !== 'undefined') ? (parseInt(v.Active, 10) === 0) : false
        };
    }
    var pkAttAC = $('#pk-att-player-name').autocomplete({
        source: function(req, res) {
            var s = req.term;
            function splitToItems(list, seen) {
                var active = [], inactive = [];
                $.each(list || [], function(i, v) {
                    var mid = parseInt(v.MundaneId, 10);
                    if (pkAttEntered[mid]) return;
                    if (seen && seen[mid]) return;
                    if (seen) seen[mid] = true;
                    var item = pkAttMakeItem(v);
                    if (item.inactive) inactive.push(item); else active.push(item);
                });
                return { active: active, inactive: inactive };
            }
            function assemble(groups, inactiveItems) {
                var sep = { label: '', name: '', value: null, separator: true };
                var items = [];
                groups.forEach(function(g) {
                    if (!g || !g.length) return;
                    if (items.length) items.push(sep);
                    items = items.concat(g);
                });
                if (inactiveItems && inactiveItems.length) {
                    if (items.length) items.push(sep);
                    items = items.concat(inactiveItems);
                }
                return items;
            }
            if (pkAttScope === 'park') {
                $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, park_id: PkConfig.parkId, limit: 12 })
                    .done(function(r) {
                        var split = splitToItems(r);
                        res(assemble([split.active], split.inactive));
                    });
            } else if (pkAttScope === 'kingdom') {
                $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, kingdom_id: PkConfig.kingdomId, limit: 12 })
                    .done(function(r) {
                        var split = splitToItems(r);
                        res(assemble([split.active], split.inactive));
                    });
            } else {
                $.when(
                    $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, park_id: PkConfig.parkId, limit: 8 }),
                    $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, kingdom_id: PkConfig.kingdomId, limit: 8 }),
                    $.getJSON(SEARCH_URL, { Action: 'Search/Player', type: 'all', search: s, limit: 8 })
                ).done(function(parkRes, kingRes, allRes) {
                    var seen = {};
                    var parkSplit  = splitToItems(parkRes[0],  seen);
                    var kingSplit  = splitToItems(kingRes[0],  seen);
                    var otherSplit = splitToItems(allRes[0],   seen);
                    var inactiveAll = parkSplit.inactive
                        .concat(kingSplit.inactive)
                        .concat(otherSplit.inactive);
                    res(assemble([parkSplit.active, kingSplit.active, otherSplit.active], inactiveAll));
                });
            }
        },
        focus: function(e, ui) { if (!ui.item.value) return false; $('#pk-att-player-name').val(ui.item.name); return false; },
        select: function(e, ui) {
            if (!ui.item.value) return false;
            $('#pk-att-player-name').val(ui.item.name);
            $('#pk-att-player-id').val(ui.item.value);
            pkAttSelectedInactive = !!ui.item.inactive;
            // Pre-fill class from last class map
            var lastCls = pkLastClass[parseInt(ui.item.value, 10)];
            if (lastCls) {
                pkBuildClassOptions();
                gid('pk-att-class-select').value = String(lastCls);
            }
            pkAttUpdateAddBtn();
            return false;
        },
        change: function(e, ui) {
            if (!ui.item) {
                $('#pk-att-player-id').val('');
                pkAttSelectedInactive = false;
                pkAttUpdateAddBtn();
            }
            return false;
        },
        delay: 250, minLength: 2,
    });
    $('#pk-att-player-name').on('input', function() {
        if (!$(this).val()) {
            pkAttAC.autocomplete('close');
            $('#pk-att-player-id').val('');
            pkAttSelectedInactive = false;
            pkAttUpdateAddBtn();
        }
    });
    pkAttAC.data('autocomplete')._renderItem = function(ul, item) {
        if (item.separator) {
            return $('<li class="pk-att-ac-sep">').appendTo(ul);
        }
        var a = $('<a>');
        if (item.inactive) {
            a.addClass('pk-att-ac-inactive').html(
                $('<span>').text(item.label).html() +
                '<span class="pk-att-ac-inactive-badge">inactive</span>'
            );
        } else if (item.suspended) {
            a.addClass('pk-att-ac-suspended').html(
                '<i class="fas fa-ban" style="margin-right:5px;font-size:11px"></i>' + $('<span>').text(item.label).html()
            );
        } else {
            a.text(item.label);
        }
        return $('<li></li>').data('item.autocomplete', item).append(a).appendTo(ul);
    };

    // Capture-phase click interceptor on the Add button: if the selected player
    // is inactive, confirm before the regular submit handler fires. The backend
    // auto-reactivates on successful add; this dialog makes the side effect
    // visible to the user.
    (function() {
        var addBtn = gid('pk-att-add-btn');
        if (!addBtn) return;
        addBtn.addEventListener('click', function(e) {
            if (!pkAttSelectedInactive) return;
            var ok = confirm('You have selected a player whose record has been marked inactive. By adding attendance for this player, the ORK will automatically reactivate their profile. Proceed?');
            if (!ok) {
                e.stopImmediatePropagation();
                e.preventDefault();
            }
        }, true);
    }());
});


// ============================================================
// Shared QR modal helper
function orkOpenQrModal(overlayId, imgId, downloadId, expiresId, token, expiresText, uir) {
    var imgEl  = document.getElementById(imgId);
    var dlEl   = document.getElementById(downloadId);
    var overlay = document.getElementById(overlayId);
    if (expiresId) document.getElementById(expiresId).textContent = expiresText || '';
    imgEl.src = '';
    dlEl.href = '#';
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    $.get(uir + 'QR/link/' + token, function(r) {
        if (!r || r.status !== 0) { console.error('QR error', r); return; }
        var dataUri = 'data:image/png;base64,' + r.data;
        imgEl.src = dataUri;
        // Build a blob URL for the download link
        try {
            var bytes = atob(r.data);
            var arr = new Uint8Array(bytes.length);
            for (var i = 0; i < bytes.length; i++) arr[i] = bytes.charCodeAt(i);
            var blob = new Blob([arr], {type: 'image/png'});
            dlEl.href = URL.createObjectURL(blob);
        } catch(e) {
            dlEl.href = dataUri;
        }
    }, 'json').fail(function(xhr) { console.error('QR request failed', xhr.status, xhr.responseText); });
}
function orkCloseQrModal(overlayId) {
    var overlay = document.getElementById(overlayId);
    overlay.style.display = 'none';
    document.body.style.overflow = '';
}

// ============================================================
// Shared clipboard helper
function orkCopyToClipboard(text, successEl, successHtml, resetHtml) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            successEl.innerHTML = successHtml;
            setTimeout(function() { successEl.innerHTML = resetHtml; }, 2000);
        }).catch(function() { orkCopyFallback(text, successEl, successHtml, resetHtml); });
    } else {
        orkCopyFallback(text, successEl, successHtml, resetHtml);
    }
}
function orkCopyFallback(text, successEl, successHtml, resetHtml) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px';
    document.body.appendChild(ta); ta.focus(); ta.select();
    try { document.execCommand('copy'); successEl.innerHTML = successHtml; setTimeout(function() { successEl.innerHTML = resetHtml; }, 2000); } catch(e) {}
    document.body.removeChild(ta);
}

// ============================================================
// Parknew — Sign-in Link tab (pk-att-panel-link)
$(document).ready(function() {
    if (typeof PkConfig === 'undefined') return;
    var genBtn  = document.getElementById('pk-att-link-gen-btn');
    var copyBtn = document.getElementById('pk-att-link-copy-btn');
    if (!genBtn) return;   // only managers see the tab

    // Active links panel state
    var pkLinksLoaded = false;
    var pkLinksOpen   = false;
    var pkCurrentToken = '';
    var pkCurrentExpires = '';

    window.pkCloseQrModal = function() { orkCloseQrModal('pk-qr-overlay'); };

    genBtn.addEventListener('click', function() {
        var hours   = Math.max(1, Math.min(96, parseInt(document.getElementById('pk-att-link-hours').value, 10) || 3));
        var credits = Math.max(0.5, Math.min(10, parseFloat(document.getElementById('pk-att-link-credits').value) || 1));
        genBtn.disabled = true;
        genBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating\u2026';
        document.getElementById('pk-att-link-result').style.display = 'none';
        $.post(PkConfig.uir + 'AttendanceAjax/link/park/' + PkConfig.parkId + '/create',
            { Hours: hours, Credits: credits },
            function(r) {
                genBtn.disabled = false;
                genBtn.innerHTML = '<i class="fas fa-link"></i> Generate';
                if (r && r.status === 0) {
                    pkCurrentToken   = r.token;
                    pkCurrentExpires = r.expires || '';
                    document.getElementById('pk-att-link-url').value = r.url;
                    document.getElementById('pk-att-link-expires').textContent = r.expires;
                    document.getElementById('pk-att-link-result').style.display = '';
                    // Auto-copy and reset the copy button
                    orkCopyToClipboard(r.url, copyBtn,
                        '<i class="fas fa-check"></i> Copied!',
                        '<i class="fas fa-copy"></i> Copy');
                    // Reload active links list if it was open
                    pkLinksLoaded = false;
                    if (pkLinksOpen) pkLoadActiveLinks();
                } else {
                    pkAttShowFeedback((r && r.error) ? r.error : 'Could not generate link.', false);
                }
            }, 'json'
        ).fail(function() {
            genBtn.disabled = false;
            genBtn.innerHTML = '<i class="fas fa-link"></i> Generate';
            pkAttShowFeedback('Request failed.', false);
        });
    });

    copyBtn.addEventListener('click', function() {
        var url = document.getElementById('pk-att-link-url').value;
        if (!url) return;
        orkCopyToClipboard(url, copyBtn,
            '<i class="fas fa-check"></i> Copied!',
            '<i class="fas fa-copy"></i> Copy');
    });

    var qrBtn = document.getElementById('pk-att-link-qr-btn');
    if (qrBtn) {
        qrBtn.addEventListener('click', function() {
            if (!pkCurrentToken) return;
            orkOpenQrModal('pk-qr-overlay', 'pk-qr-img', 'pk-qr-download', 'pk-qr-expires',
                pkCurrentToken, pkCurrentExpires, PkConfig.uir);
        });
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') { var o = document.getElementById('pk-qr-overlay'); if (o && o.style.display !== 'none') pkCloseQrModal(); }
    });

    // Active links collapsible
    var toggleBtn = document.getElementById('pk-att-links-toggle');
    var chevron   = document.getElementById('pk-att-links-chevron');
    var body      = document.getElementById('pk-att-links-body');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            pkLinksOpen = !pkLinksOpen;
            body.style.display = pkLinksOpen ? '' : 'none';
            chevron.style.transform = pkLinksOpen ? 'rotate(90deg)' : '';
            if (pkLinksOpen && !pkLinksLoaded) pkLoadActiveLinks();
        });
    }

    function pkLoadActiveLinks() {
        pkLinksLoaded = true;
        document.getElementById('pk-att-links-loading').style.display = '';
        document.getElementById('pk-att-links-empty').style.display   = 'none';
        document.getElementById('pk-att-links-table').style.display   = 'none';
        $.get(PkConfig.uir + 'AttendanceAjax/link/park/' + PkConfig.parkId + '/list', function(r) {
            document.getElementById('pk-att-links-loading').style.display = 'none';
            if (!r || r.status !== 0 || !r.links.length) {
                document.getElementById('pk-att-links-empty').style.display = '';
                document.getElementById('pk-att-links-count').textContent = '';
                return;
            }
            document.getElementById('pk-att-links-count').textContent = '(' + r.links.length + ')';
            var tbody = document.getElementById('pk-att-links-tbody');
            tbody.innerHTML = '';
            r.links.forEach(function(lnk) {
                var exp = new Date(lnk.ExpiresAt.replace(' ', 'T'));
                var expStr = exp.toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
                var tr = document.createElement('tr');
                tr.dataset.linkId = lnk.LinkId;
                tr.innerHTML =
                    '<td style="padding:4px 6px;color:#4a5568">' + expStr + '</td>' +
                    '<td style="padding:4px 6px;color:#4a5568">' + lnk.Credits + '</td>' +
                    '<td style="padding:4px 6px;text-align:right;white-space:nowrap">' +
                        '<button class="pk-btn pk-links-copy" data-url="' + lnk.Url + '" style="font-size:11px;padding:2px 8px;margin-right:4px;background:#edf2f7;border:1px solid #cbd5e0;color:#4a5568"><i class="fas fa-copy"></i> Copy</button>' +
                        '<button class="pk-btn pk-links-revoke" data-id="' + lnk.LinkId + '" style="font-size:11px;padding:2px 8px;background:#fed7d7;border-color:#fc8181;color:#c53030"><i class="fas fa-times"></i> Revoke</button>' +
                    '</td>';
                tbody.appendChild(tr);
            });
            document.getElementById('pk-att-links-table').style.display = '';
            // Copy buttons
            tbody.querySelectorAll('.pk-links-copy').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    orkCopyToClipboard(this.dataset.url, this,
                        '<i class="fas fa-check"></i> Copied!',
                        '<i class="fas fa-copy"></i> Copy');
                });
            });
            // Revoke buttons
            tbody.querySelectorAll('.pk-links-revoke').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var lid = this.dataset.id;
                    var row = this.closest('tr');
                    this.disabled = true;
                    $.post(PkConfig.uir + 'AttendanceAjax/link/delete/' + lid, function(r) {
                        if (r && r.status === 0) {
                            row.remove();
                            var remaining = tbody.querySelectorAll('tr').length;
                            if (!remaining) {
                                document.getElementById('pk-att-links-table').style.display = 'none';
                                document.getElementById('pk-att-links-empty').style.display = '';
                                document.getElementById('pk-att-links-count').textContent = '';
                            } else {
                                document.getElementById('pk-att-links-count').textContent = '(' + remaining + ')';
                            }
                        }
                    }, 'json');
                });
            });
        }, 'json').fail(function() {
            document.getElementById('pk-att-links-loading').style.display = 'none';
            document.getElementById('pk-att-links-empty').style.display = '';
        });
    }
});


// ============================================================
// Eventnew — Sign-in Link & QR (event-scoped)
window.evOpenSigninLinkModal = function() {
    var ov = document.getElementById('ev-signin-link-overlay');
    if (!ov) return;
    ov.classList.add('ev-open');
    document.body.style.overflow = 'hidden';
};
window.evCloseSigninLinkModal = function() {
    var ov = document.getElementById('ev-signin-link-overlay');
    if (!ov) return;
    ov.classList.remove('ev-open');
    document.body.style.overflow = '';
};
$(document).ready(function() {
    if (typeof EvConfig === 'undefined') return;
    if (!EvConfig.canManageAttendance || !EvConfig.checkinOpen) return;
    var genBtn  = document.getElementById('ev-signin-gen-btn');
    var copyBtn = document.getElementById('ev-signin-copy-btn');
    var creditsEl = document.getElementById('ev-signin-credits');
    if (!genBtn || !creditsEl) return;
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var ov = document.getElementById('ev-signin-link-overlay');
            if (ov && ov.classList.contains('ev-open')) evCloseSigninLinkModal();
        }
    });

    var evCurrentToken   = '';
    var evCurrentExpires = '';
    var evLinksLoaded    = false;
    var evLinksOpen      = false;

    function evSyncGenBtn() {
        var v = parseFloat(creditsEl.value);
        genBtn.disabled = !(v > 0);
    }
    creditsEl.addEventListener('input', evSyncGenBtn);
    evSyncGenBtn();

    genBtn.addEventListener('click', function() {
        var credits = parseFloat(creditsEl.value);
        if (!(credits > 0)) { creditsEl.focus(); return; }
        genBtn.disabled = true;
        var origHtml = '<i class="fas fa-link"></i> Generate';
        genBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating\u2026';
        document.getElementById('ev-signin-link-result').style.display = 'none';
        $.post(EvConfig.uir + 'AttendanceAjax/link/event/' + EvConfig.eventId + '/create',
            { Credits: credits, EventCalendarDetailId: EvConfig.detailId },
            function(r) {
                genBtn.innerHTML = origHtml;
                evSyncGenBtn();
                if (r && r.status === 0) {
                    evCurrentToken   = r.token;
                    evCurrentExpires = r.expires || '';
                    document.getElementById('ev-signin-link-url').value = r.url;
                    document.getElementById('ev-signin-link-expires').textContent = r.expires;
                    document.getElementById('ev-signin-link-result').style.display = '';
                    orkCopyToClipboard(r.url, copyBtn,
                        '<i class="fas fa-check"></i> Copied!',
                        '<i class="fas fa-copy"></i> Copy');
                    evLinksLoaded = false;
                    if (evLinksOpen) evLoadActiveLinks();
                } else {
                    alert((r && r.error) ? r.error : 'Could not generate link.');
                }
            }, 'json'
        ).fail(function() {
            genBtn.innerHTML = origHtml;
            evSyncGenBtn();
            alert('Request failed.');
        });
    });

    copyBtn.addEventListener('click', function() {
        var url = document.getElementById('ev-signin-link-url').value;
        if (!url) return;
        orkCopyToClipboard(url, copyBtn,
            '<i class="fas fa-check"></i> Copied!',
            '<i class="fas fa-copy"></i> Copy');
    });

    var qrBtn = document.getElementById('ev-signin-qr-btn');
    if (qrBtn) {
        qrBtn.addEventListener('click', function() {
            if (!evCurrentToken) return;
            orkOpenQrModal('ev-qr-overlay', 'ev-qr-img', 'ev-qr-download', 'ev-qr-expires',
                evCurrentToken, evCurrentExpires, EvConfig.uir);
        });
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var o = document.getElementById('ev-qr-overlay');
            if (o && o.style.display !== 'none') { if (typeof evCloseQrModal === 'function') evCloseQrModal(); }
        }
    });

    var toggleBtn = document.getElementById('ev-signin-links-toggle');
    var chevron   = document.getElementById('ev-signin-links-chevron');
    var body      = document.getElementById('ev-signin-links-body');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            evLinksOpen = !evLinksOpen;
            body.style.display = evLinksOpen ? '' : 'none';
            chevron.style.transform = evLinksOpen ? 'rotate(90deg)' : '';
            if (evLinksOpen && !evLinksLoaded) evLoadActiveLinks();
        });
    }

    function evLoadActiveLinks() {
        evLinksLoaded = true;
        document.getElementById('ev-signin-links-loading').style.display = '';
        document.getElementById('ev-signin-links-empty').style.display   = 'none';
        document.getElementById('ev-signin-links-table').style.display   = 'none';
        $.get(EvConfig.uir + 'AttendanceAjax/link/event/' + EvConfig.eventId + '/list',
            { EventCalendarDetailId: EvConfig.detailId }, function(r) {
            document.getElementById('ev-signin-links-loading').style.display = 'none';
            if (!r || r.status !== 0 || !r.links.length) {
                document.getElementById('ev-signin-links-empty').style.display = '';
                document.getElementById('ev-signin-links-count').textContent = '';
                return;
            }
            document.getElementById('ev-signin-links-count').textContent = '(' + r.links.length + ')';
            var tbody = document.getElementById('ev-signin-links-tbody');
            tbody.innerHTML = '';
            r.links.forEach(function(lnk) {
                var exp = new Date(lnk.ExpiresAt.replace(' ', 'T'));
                var expStr = exp.toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
                var tr = document.createElement('tr');
                tr.dataset.linkId = lnk.LinkId;
                tr.innerHTML =
                    '<td style="padding:4px 6px;color:#4a5568">' + expStr + '</td>' +
                    '<td style="padding:4px 6px;color:#4a5568">' + lnk.Credits + '</td>' +
                    '<td style="padding:4px 6px;text-align:right;white-space:nowrap">' +
                        '<button type="button" class="ev-icon-btn ev-signin-links-copy" data-url="' + lnk.Url + '" style="font-size:11px;padding:2px 8px;margin-right:4px"><i class="fas fa-copy"></i> Copy</button>' +
                        '<button type="button" class="ev-icon-btn ev-signin-links-revoke" data-id="' + lnk.LinkId + '" style="font-size:11px;padding:2px 8px;background:#fed7d7;border-color:#fc8181;color:#c53030"><i class="fas fa-times"></i> Revoke</button>' +
                    '</td>';
                tbody.appendChild(tr);
            });
            document.getElementById('ev-signin-links-table').style.display = '';
            tbody.querySelectorAll('.ev-signin-links-copy').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    orkCopyToClipboard(this.dataset.url, this,
                        '<i class="fas fa-check"></i> Copied!',
                        '<i class="fas fa-copy"></i> Copy');
                });
            });
            tbody.querySelectorAll('.ev-signin-links-revoke').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var lid = this.dataset.id;
                    var row = this.closest('tr');
                    this.disabled = true;
                    $.post(EvConfig.uir + 'AttendanceAjax/link/delete/' + lid, function(r) {
                        if (r && r.status === 0) {
                            row.remove();
                            var remaining = tbody.querySelectorAll('tr').length;
                            if (!remaining) {
                                document.getElementById('ev-signin-links-table').style.display = 'none';
                                document.getElementById('ev-signin-links-empty').style.display = '';
                                document.getElementById('ev-signin-links-count').textContent = '';
                            } else {
                                document.getElementById('ev-signin-links-count').textContent = '(' + remaining + ')';
                            }
                        }
                    }, 'json');
                });
            });
        }, 'json').fail(function() {
            document.getElementById('ev-signin-links-loading').style.display = 'none';
            document.getElementById('ev-signin-links-empty').style.display = '';
        });
    }
});


// ============================================================
// Kingdomnew — Sign-in Link (inline in Admin Tasks panel)
$(document).ready(function() {
    if (typeof KnConfig === 'undefined') return;
    var genBtn = document.getElementById('kn-signinlink-gen-btn');
    if (!genBtn) return;

    var knLinksLoaded  = false;
    var knLinksOpen    = false;
    var knCurrentToken   = '';
    var knCurrentExpires = '';

    window.knCloseQrModal = function() { orkCloseQrModal('kn-qr-overlay'); };

    var copyBtn = document.getElementById('kn-signinlink-copy-btn');

    // Park autocomplete
    var parkNameEl = document.getElementById('kn-signinlink-park-name');
    var parkIdEl   = document.getElementById('kn-signinlink-park-id');
    var parkAcEl   = document.getElementById('kn-signinlink-park-results');
    var parkTimer;
    if (parkNameEl) {
        parkNameEl.addEventListener('input', function() {
            parkIdEl.value = '';
            clearTimeout(parkTimer);
            var term = this.value.trim();
            if (term.length < 2) { parkAcEl.classList.remove('kn-ac-open'); return; }
            parkTimer = setTimeout(function() {
                $.getJSON(KnConfig.uir + 'SearchAjax/search', { Action: 'Search/Park', name: term, kingdom_id: KnConfig.kingdomId, limit: 10 }, function(data) {
                    parkAcEl.innerHTML = (data && data.length)
                        ? data.map(function(pk) {
                            return '<div class="kn-ac-item" data-id="' + pk.ParkId + '" data-name="' + encodeURIComponent(pk.Name) + '">' + escHtml(pk.Name) + '</div>';
                        }).join('')
                        : '<div class="kn-ac-item" style="color:#a0aec0;cursor:default">No parks found</div>';
                    // Position fixed so dropdown escapes the scrolling admin panel body
                    var rect = parkNameEl.getBoundingClientRect();
                    parkAcEl.style.position = 'fixed';
                    parkAcEl.style.top  = (rect.bottom) + 'px';
                    parkAcEl.style.left = rect.left + 'px';
                    parkAcEl.style.width = rect.width + 'px';
                    parkAcEl.style.zIndex = '9999';
                    parkAcEl.classList.add('kn-ac-open');
                });
            }, AUTOCOMPLETE_DEBOUNCE_MS || 220);
        });
        parkAcEl.addEventListener('click', function(e) {
            var item = e.target.closest('.kn-ac-item[data-id]');
            if (!item) return;
            parkNameEl.value = decodeURIComponent(item.dataset.name);
            parkIdEl.value   = item.dataset.id;
            parkAcEl.classList.remove('kn-ac-open');
            // Reset result when park changes
            document.getElementById('kn-signinlink-result').style.display = 'none';
            knLinksLoaded = false;
        });
        parkNameEl.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { parkAcEl.classList.remove('kn-ac-open'); }
        });
        // Clear hidden id if text is cleared
        parkNameEl.addEventListener('change', function() {
            if (!this.value.trim()) parkIdEl.value = '';
        });
        setupAcKeyNav(parkNameEl, parkAcEl, '.kn-ac-item[data-id]', 'kn-ac-focused', function(item) { item.click(); });
    }

    genBtn.addEventListener('click', function() {
        var btn     = this;
        var errEl   = document.getElementById('kn-signinlink-error');
        var hours   = Math.max(1, Math.min(96, parseInt(document.getElementById('kn-signinlink-hours').value, 10) || 3));
        var credits = Math.max(0.5, Math.min(10, parseFloat(document.getElementById('kn-signinlink-credits').value) || 1));

        // Scope: use park if one is selected, otherwise kingdom
        var selectedParkId = parkIdEl ? parkIdEl.value.trim() : '';
        var postUrl = selectedParkId
            ? KnConfig.uir + 'AttendanceAjax/link/park/' + selectedParkId + '/create'
            : KnConfig.uir + 'AttendanceAjax/link/kingdom/' + KnConfig.kingdomId + '/create';

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating\u2026';
        errEl.style.display = 'none';
        document.getElementById('kn-signinlink-result').style.display = 'none';
        $.post(postUrl, { Hours: hours, Credits: credits }, function(r) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-link"></i> Generate';
            if (r && r.status === 0) {
                knCurrentToken   = r.token;
                knCurrentExpires = r.expires || '';
                document.getElementById('kn-signinlink-url').value = r.url;
                document.getElementById('kn-signinlink-expires').textContent = r.expires;
                document.getElementById('kn-signinlink-result').style.display = '';
                orkCopyToClipboard(r.url, copyBtn,
                    '<i class="fas fa-check"></i> Copied!',
                    '<i class="fas fa-copy"></i> Copy');
                knLinksLoaded = false;
                if (knLinksOpen) knLoadActiveLinks();
            } else {
                errEl.textContent = (r && r.error) ? r.error : 'Could not generate link.';
                errEl.style.display = '';
            }
        }, 'json').fail(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-link"></i> Generate';
            errEl.textContent = 'Request failed.';
            errEl.style.display = '';
        });
    });

    copyBtn.addEventListener('click', function() {
        var url = document.getElementById('kn-signinlink-url').value;
        if (!url) return;
        orkCopyToClipboard(url, copyBtn,
            '<i class="fas fa-check"></i> Copied!',
            '<i class="fas fa-copy"></i> Copy');
    });

    var knQrBtn = document.getElementById('kn-signinlink-qr-btn');
    if (knQrBtn) {
        knQrBtn.addEventListener('click', function() {
            if (!knCurrentToken) return;
            orkOpenQrModal('kn-qr-overlay', 'kn-qr-img', 'kn-qr-download', 'kn-qr-expires',
                knCurrentToken, knCurrentExpires, KnConfig.uir);
        });
    }

    // Active links collapsible — lists all kingdom links (park and kingdom-wide)
    var knToggleBtn = document.getElementById('kn-signinlink-links-toggle');
    var knChevron   = document.getElementById('kn-signinlink-links-chevron');
    var knBody      = document.getElementById('kn-signinlink-links-body');
    if (knToggleBtn) {
        knToggleBtn.addEventListener('click', function() {
            knLinksOpen = !knLinksOpen;
            knBody.style.display = knLinksOpen ? '' : 'none';
            knChevron.style.transform = knLinksOpen ? 'rotate(90deg)' : '';
            if (knLinksOpen && !knLinksLoaded) knLoadActiveLinks();
        });
    }

    function knLoadActiveLinks() {
        knLinksLoaded = true;
        document.getElementById('kn-signinlink-links-loading').style.display = '';
        document.getElementById('kn-signinlink-links-empty').style.display   = 'none';
        document.getElementById('kn-signinlink-links-table').style.display   = 'none';
        $.get(KnConfig.uir + 'AttendanceAjax/link/kingdom/' + KnConfig.kingdomId + '/list', function(r) {
            document.getElementById('kn-signinlink-links-loading').style.display = 'none';
            if (!r || r.status !== 0 || !r.links.length) {
                document.getElementById('kn-signinlink-links-empty').style.display = '';
                document.getElementById('kn-signinlink-links-count').textContent = '';
                return;
            }
            document.getElementById('kn-signinlink-links-count').textContent = '(' + r.links.length + ')';
            var tbody = document.getElementById('kn-signinlink-links-tbody');
            tbody.innerHTML = '';
            r.links.forEach(function(lnk) {
                var exp = new Date(lnk.ExpiresAt.replace(' ', 'T'));
                var expStr = exp.toLocaleString([], {month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
                var scope = lnk.ParkId > 0 ? (escHtml(lnk.ParkName || 'Park')) : 'Kingdom';
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td style="padding:4px 6px;color:#4a5568;font-size:11px">' + scope + '</td>' +
                    '<td style="padding:4px 6px;color:#4a5568">' + expStr + '</td>' +
                    '<td style="padding:4px 6px;color:#4a5568">' + lnk.Credits + '</td>' +
                    '<td style="padding:4px 6px;text-align:right;white-space:nowrap">' +
                        '<button class="kn-btn kn-links-copy" data-url="' + lnk.Url + '" style="font-size:11px;padding:2px 8px;margin-right:4px;background:#edf2f7;border:1px solid #cbd5e0;color:#4a5568"><i class="fas fa-copy"></i> Copy</button>' +
                        '<button class="kn-btn kn-links-revoke" data-id="' + lnk.LinkId + '" style="font-size:11px;padding:2px 8px;background:#fed7d7;border-color:#fc8181;color:#c53030"><i class="fas fa-times"></i> Revoke</button>' +
                    '</td>';
                tbody.appendChild(tr);
            });
            document.getElementById('kn-signinlink-links-table').style.display = '';
            tbody.querySelectorAll('.kn-links-copy').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    orkCopyToClipboard(this.dataset.url, this,
                        '<i class="fas fa-check"></i> Copied!',
                        '<i class="fas fa-copy"></i> Copy');
                });
            });
            tbody.querySelectorAll('.kn-links-revoke').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var lid = this.dataset.id;
                    var row = this.closest('tr');
                    this.disabled = true;
                    $.post(KnConfig.uir + 'AttendanceAjax/link/delete/' + lid, function(r) {
                        if (r && r.status === 0) {
                            row.remove();
                            var remaining = tbody.querySelectorAll('tr').length;
                            if (!remaining) {
                                document.getElementById('kn-signinlink-links-table').style.display = 'none';
                                document.getElementById('kn-signinlink-links-empty').style.display = '';
                                document.getElementById('kn-signinlink-links-count').textContent = '';
                            } else {
                                document.getElementById('kn-signinlink-links-count').textContent = '(' + remaining + ')';
                            }
                        }
                    }, 'json');
                });
            });
        }, 'json').fail(function() {
            document.getElementById('kn-signinlink-links-loading').style.display = 'none';
            document.getElementById('kn-signinlink-links-empty').style.display = '';
        });
    }
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
    if (typeof PkConfig === 'undefined' || !PkConfig.canAdmin) return;

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

// ---- Self-Registration QR Modal (Parknew) ----
(function() {
    if (typeof PkConfig === 'undefined' || !PkConfig.canAdmin) return;

    var SELFREG_URL = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/selfreg_link';
    var selfregTimer = null;
    var selfregExpiresAt = null;
    var selfregUrl = '';

    function gid(id) { return document.getElementById(id); }

    function showSelfRegFeedback(msg, ok) {
        var el = gid('pk-selfreg-feedback');
        if (!el) return;
        el.textContent = msg;
        el.className = 'plr-feedback ' + (ok ? 'plr-ok' : 'plr-err');
        el.style.display = '';
    }
    function hideSelfRegFeedback() {
        var el = gid('pk-selfreg-feedback');
        if (el) el.style.display = 'none';
    }

    function fetchAndRenderQR() {
        var qrEl = gid('pk-selfreg-qr');
        if (!qrEl) return;
        qrEl.innerHTML = '<div style="padding:40px;color:#a0aec0;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
        hideSelfRegFeedback();
        gid('pk-selfreg-regen-btn').style.display = 'none';
        var badge = gid('pk-selfreg-expired-badge');
        if (badge) badge.style.display = 'none';
        $.ajax({
            url: SELFREG_URL,
            type: 'POST',
            dataType: 'json',
            success: function(r) {
                if (r && r.status === 0 && r.token) {
                    selfregUrl = PkConfig.uir + 'SelfReg/form/' + r.token;
                    qrEl.innerHTML = '';
                    new QRCode(qrEl, {
                        text: selfregUrl,
                        width: 220,
                        height: 220,
                        correctLevel: QRCode.CorrectLevel.M
                    });
                    // A3: Use seconds_remaining instead of absolute timestamp
                    selfregExpiresAt = Date.now() + (r.seconds_remaining * 1000);
                    startTimer();
                } else {
                    qrEl.innerHTML = '';
                    showSelfRegFeedback((r && r.error) ? r.error : 'Could not generate QR code.', false);
                }
            },
            error: function() {
                qrEl.innerHTML = '';
                showSelfRegFeedback('Request failed. Please try again.', false);
            }
        });
    }

    function startTimer() {
        stopTimer();
        updateTimer();
        selfregTimer = setInterval(updateTimer, 1000);
    }

    function stopTimer() {
        if (selfregTimer) { clearInterval(selfregTimer); selfregTimer = null; }
    }

    function updateTimer() {
        var timerEl = gid('pk-selfreg-timer');
        if (!timerEl || !selfregExpiresAt) return;

        var remaining = Math.max(0, Math.floor((selfregExpiresAt - Date.now()) / 1000));
        if (remaining <= 0) {
            timerEl.textContent = 'Expired';
            timerEl.parentElement.classList.add('pk-selfreg-timer-expired');
            gid('pk-selfreg-regen-btn').style.display = '';
            stopTimer();
            // A18: Gray out QR and show expired badge
            var qrEl = gid('pk-selfreg-qr');
            if (qrEl) qrEl.style.opacity = '0.3';
            var badge = gid('pk-selfreg-expired-badge');
            if (badge) badge.style.display = '';
        } else {
            var min = Math.floor(remaining / 60);
            var sec = remaining % 60;
            timerEl.textContent = min + ':' + (sec < 10 ? '0' : '') + sec;
            timerEl.parentElement.classList.remove('pk-selfreg-timer-expired');
        }
    }

    // A7: Focus management
    window.pkOpenSelfRegModal = function() {
        // Close Add Player modal first
        if (typeof pkCloseAddPlayerModal === 'function') pkCloseAddPlayerModal();

        var ov = gid('pk-selfreg-overlay');
        if (!ov) return;
        hideSelfRegFeedback();
        ov.classList.add('pk-selfreg-open');
        document.body.style.overflow = 'hidden';
        fetchAndRenderQR();
        // A7: Focus close button on open
        setTimeout(function() { var cb = gid('pk-selfreg-close-btn'); if (cb) cb.focus(); }, 50);
    };

    window.pkCloseSelfRegModal = function() {
        var ov = gid('pk-selfreg-overlay');
        if (ov) ov.classList.remove('pk-selfreg-open');
        document.body.style.overflow = '';
        stopTimer();
        // A7: Return focus to Add Player button
        var addBtn = document.querySelector('[onclick*="pkOpenAddPlayerModal"]');
        if (addBtn) addBtn.focus();
    };

    // Anti-copy protections + event listeners
    $(document).ready(function() {
        var wrap = gid('pk-selfreg-qr-wrap');
        if (wrap) {
            wrap.addEventListener('contextmenu', function(e) { e.preventDefault(); });
            wrap.addEventListener('dragstart', function(e) { e.preventDefault(); });
        }

        var shield = gid('pk-selfreg-shield');
        if (shield) {
            shield.addEventListener('contextmenu', function(e) { e.preventDefault(); });
        }

        if (gid('pk-selfreg-close-btn'))
            gid('pk-selfreg-close-btn').addEventListener('click', pkCloseSelfRegModal);
        if (gid('pk-selfreg-cancel'))
            gid('pk-selfreg-cancel').addEventListener('click', pkCloseSelfRegModal);

        var overlay = gid('pk-selfreg-overlay');
        if (overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) pkCloseSelfRegModal();
            });
        }

        if (gid('pk-selfreg-regen-btn')) {
            gid('pk-selfreg-regen-btn').addEventListener('click', function() {
                var qrEl = gid('pk-selfreg-qr');
                if (qrEl) qrEl.style.opacity = '1';
                fetchAndRenderQR();
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && overlay && overlay.classList.contains('pk-selfreg-open'))
                pkCloseSelfRegModal();
        });
    });

})();

// ---- Playernew: Award Edit + Delete ----
(function() {
    if (typeof PnConfig === 'undefined' || !PnConfig.canManageAwards) return;

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
                    // SearchService already returns inactive and suspended players; sort
                    // them last and badge them so users can knowingly attribute historical
                    // awards to a now-banned officer during reconciliation.
                    var url = SEARCH_URL + '?Action=Search%2FPlayer&type=all&search=' + encodeURIComponent(term) + '&kingdom_id=' + PnConfig.kingdomId + '&limit=15';
                    fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                        if (!data || !data.length) {
                            editGbResults.innerHTML = '<div class="pn-ac-no-results">No players found</div>';
                        } else {
                            function rank(p) {
                                var banned   = !!(parseInt(p.Suspended, 10) || parseInt(p.PenaltyBox, 10));
                                var inactive = parseInt(p.Active, 10) === 0;
                                if (banned)   return 2;
                                if (inactive) return 1;
                                return 0;
                            }
                            data.sort(function(a, b) {
                                var ra = rank(a), rb = rank(b);
                                if (ra !== rb) return ra - rb;
                                return (a.Persona || '').localeCompare(b.Persona || '');
                            });
                            editGbResults.innerHTML = data.map(function(p) {
                                var inactive = parseInt(p.Active, 10) === 0;
                                var banned   = !!(parseInt(p.Suspended, 10) || parseInt(p.PenaltyBox, 10));
                                return '<div class="pn-ac-item" tabindex="-1" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                                    + escHtml(p.Persona)
                                    + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr || '') + ':' + escHtml(p.PAbbr || '') + ')</span>'
                                    + (inactive ? ' <span style="color:#c53030;font-size:10px;font-weight:600">(Inactive)</span>' : '')
                                    + (banned   ? ' <span style="color:#c53030;font-size:10px;font-weight:600">(Banned)</span>'   : '')
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
                            if (doReconcile)                            var msg = doReconcile ? 'Award reconciled!' : 'Award updated!';
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
    if (typeof PkConfig === 'undefined' || !PkConfig.canAdmin) return;

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
    if (typeof PkConfig === 'undefined' || !PkConfig.canAdmin) return;

    var ADD_DAY_URL    = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/addparkday';
    var EDIT_DAY_URL   = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/editparkday';
    var DELETE_DAY_URL = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/deleteparkday';
    var _editingDayId  = 0;

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
        _editingDayId = 0;
        gid('pk-addday-id').value = 0;
        pkSetModalMode(false);
        overlay.classList.add('pk-open');
        document.body.style.overflow = 'hidden';
    };

    function pkCloseAddDayModal() {
        var overlay = gid('pk-addday-overlay');
        if (!overlay) return;
        overlay.classList.remove('pk-open');
        document.body.style.overflow = '';
        _editingDayId = 0;
    }

    function pkSetModalMode(isEdit) {
        var titleIcon = gid('pk-addday-title-icon');
        var titleText = gid('pk-addday-title-text');
        var submitIcon = gid('pk-addday-submit-icon');
        var submitText = gid('pk-addday-submit-text');
        var delSection = gid('pk-addday-delete-section');
        if (isEdit) {
            if (titleIcon) titleIcon.className = 'fas fa-pencil-alt';
            if (titleText) titleText.textContent = 'Edit Park Day';
            if (submitIcon) submitIcon.className = 'fas fa-save';
            if (submitText) submitText.textContent = 'Save Changes';
            if (delSection) delSection.style.display = '';
        } else {
            if (titleIcon) titleIcon.className = 'fas fa-calendar-plus';
            if (titleText) titleText.textContent = 'Add Park Day';
            if (submitIcon) submitIcon.className = 'fas fa-calendar-plus';
            if (submitText) submitText.textContent = 'Add Park Day';
            if (delSection) delSection.style.display = 'none';
        }
    }

    function pkOpenEditDayModal(card) {
        var overlay = gid('pk-addday-overlay');
        if (!overlay) return;
        _editingDayId = parseInt(card.dataset.dayId, 10) || 0;
        gid('pk-addday-id').value = _editingDayId;

        var fb = gid('pk-addday-feedback');
        if (fb) { fb.style.display = 'none'; fb.textContent = ''; }

        // Purpose
        var purpose = card.dataset.purpose || 'fighter-practice';
        overlay.querySelectorAll('.pk-seg-btn[data-group="purpose"]').forEach(function(btn) {
            btn.classList.toggle('pk-seg-active', btn.dataset.val === purpose);
        });
        var purposeHid = gid('pk-addday-purpose');
        if (purposeHid) purposeHid.value = purpose;

        // Recurrence
        var recurrence = card.dataset.recurrence || 'weekly';
        overlay.querySelectorAll('.pk-seg-btn[data-group="recurrence"]').forEach(function(btn) {
            btn.classList.toggle('pk-seg-active', btn.dataset.val === recurrence);
        });
        var recHid = gid('pk-addday-recurrence');
        if (recHid) recHid.value = recurrence;
        pkUpdateRecurrenceFields(recurrence);

        // Weekday, week-of-month, month-day
        var weekdayEl = gid('pk-addday-weekday');
        if (weekdayEl) weekdayEl.value = card.dataset.weekday || 'Monday';
        var weekofEl = gid('pk-addday-weekof');
        if (weekofEl) weekofEl.value = card.dataset.weekof || '1';
        var monthdayEl = gid('pk-addday-monthday');
        if (monthdayEl) monthdayEl.value = card.dataset.monthday || '1';

        // Time
        var timeEl = gid('pk-addday-time');
        if (timeEl) timeEl.value = card.dataset.time || '';

        // Description
        var descEl = gid('pk-addday-desc');
        if (descEl) descEl.value = card.dataset.desc || '';

        // Location
        var isOnline = card.dataset.online === '1';
        var isAltLoc = card.dataset.altloc === '1';
        var locVal = isOnline ? 'online' : (isAltLoc ? '1' : '0');
        overlay.querySelectorAll('input[name="pk-addday-altloc"]').forEach(function(radio) {
            radio.checked = radio.value === locVal;
        });
        pkToggleAltLoc(locVal === '1');

        // Alternate location fields
        var addrEl = gid('pk-addday-address');
        if (addrEl) addrEl.value = card.dataset.address || '';
        var cityEl = gid('pk-addday-city');
        if (cityEl) cityEl.value = card.dataset.city || '';
        var provEl = gid('pk-addday-province');
        if (provEl) provEl.value = card.dataset.province || '';
        var postalEl = gid('pk-addday-postal');
        if (postalEl) postalEl.value = card.dataset.postal || '';

        pkSetModalMode(true);
        overlay.classList.add('pk-open');
        document.body.style.overflow = 'hidden';
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
                var url = ADD_DAY_URL;
                var successMsg = 'Park day added!';
                if (_editingDayId) {
                    fd.append('ParkDayId', _editingDayId);
                    url = EDIT_DAY_URL;
                    successMsg = 'Park day updated!';
                }
                fetch(url, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(result) {
                        saveBtn.disabled = false;
                        if (result && result.status === 0) {
                            if (fb) { fb.textContent = successMsg; fb.style.display = ''; fb.className = 'pk-addday-ok'; }
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

        // Edit park day — open modal with card data
        $(document).on('click', '.pk-schedule-card-edit', function() {
            var card = $(this).closest('.pk-schedule-card')[0];
            if (card) pkOpenEditDayModal(card);
        });

        // Delete park day from modal
        var delBtn = gid('pk-addday-delete');
        if (delBtn) {
            delBtn.addEventListener('click', function() {
                if (!_editingDayId) return;
                knConfirm('Delete this park day? This cannot be undone.', function() {
                    delBtn.disabled = true;
                    var fd = new FormData();
                    fd.append('ParkDayId', _editingDayId);
                    fetch(DELETE_DAY_URL, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(result) {
                            delBtn.disabled = false;
                            if (result && result.status === 0) {
                                pkCloseAddDayModal();
                                var card = document.querySelector('.pk-schedule-card[data-day-id="' + _editingDayId + '"]');
                                if (card) $(card).fadeOut(300, function() { $(card).remove(); });
                            } else {
                                alert((result && result.error) ? result.error : 'Delete failed.');
                            }
                        }).catch(function() {
                            delBtn.disabled = false;
                            alert('Request failed.');
                        });
                });
            });
        }
    });
})();


// ---- Playernew: Revoke Award ----
(function() {
    if (typeof PnConfig === 'undefined' || !PnConfig.canManageAwards) return;

    function gid(id) { return document.getElementById(id); }

    var currentRevokeAwardsId = 0;
    var currentRevokeAwardName = '';
    var revokeCountdownTimer = null;

    window.pnOpenAwardRevokeModal = function(awardsId, awardName, isTitle) {
        currentRevokeAwardsId = awardsId;
        currentRevokeAwardName = awardName;
        var nameEl = gid('pn-revoke-award-name');
        if (nameEl) nameEl.textContent = awardName;
        var titleEl = document.querySelector('#pn-award-revoke-overlay .pn-modal-title');
        if (titleEl) titleEl.innerHTML = '<i class="fas fa-ban" style="margin-right:8px;color:#b7791f"></i>' + (isTitle ? 'Revoke Title' : 'Revoke Award');
        var revokeLabel = isTitle ? 'Revoke Title' : 'Revoke Award';
        var saveBtn = gid('pn-revoke-award-save');
        var reason = gid('pn-revoke-reason');
        if (reason) reason.value = '';
        var counter = gid('pn-revoke-char-count');
        if (counter) counter.textContent = '300 characters remaining';
        var fb = gid('pn-revoke-award-feedback');
        if (fb) { fb.style.display = 'none'; fb.textContent = ''; }
        // 5-second countdown on confirm button
        if (revokeCountdownTimer) clearInterval(revokeCountdownTimer);
        if (saveBtn) {
            var remaining = 5;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-ban"></i> ' + revokeLabel + ' (' + remaining + ')';
            revokeCountdownTimer = setInterval(function() {
                remaining--;
                if (remaining > 0) {
                    saveBtn.innerHTML = '<i class="fas fa-ban"></i> ' + revokeLabel + ' (' + remaining + ')';
                } else {
                    clearInterval(revokeCountdownTimer);
                    revokeCountdownTimer = null;
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-ban"></i> ' + revokeLabel;
                }
            }, 1000);
        }
        var overlay = gid('pn-award-revoke-overlay');
        if (overlay) { overlay.classList.add('pn-open'); document.body.style.overflow = 'hidden'; }
    };

    function pnCloseAwardRevokeModal() {
        if (revokeCountdownTimer) { clearInterval(revokeCountdownTimer); revokeCountdownTimer = null; }
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
    if (typeof PkConfig === 'undefined' || !PkConfig.canAdmin) return;

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
    var _pkDetailsPending = false;

    function pkMarkDetailsDirty() {
        _pkDetailsPending = true;
        var btn = gid('pk-admin-details-save');
        if (btn) btn.classList.add('kn-save-dirty');
    }

    function pkClearDetailsDirty() {
        _pkDetailsPending = false;
        var btn = gid('pk-admin-details-save');
        if (btn) btn.classList.remove('kn-save-dirty');
    }

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
        pkClearDetailsDirty();
        pkClearFeedback('pk-admin-details-feedback');
        pkClearFeedback('pk-admin-ops-feedback');
        var overlay = gid('pk-admin-overlay');
        _pkDirty = false;
        if (overlay) { overlay.classList.add('pk-admin-open'); document.body.style.overflow = 'hidden'; }
    };

    function pkActuallyClose() {
        var overlay = gid('pk-admin-overlay');
        if (overlay) { overlay.classList.remove('pk-admin-open'); document.body.style.overflow = ''; }
        if (_pkDirty) { _pkDirty = false; setTimeout(function() { location.reload(); }, 0); }
    }

    function pkCloseAdminModal() {
        if (_pkDetailsPending) {
            knConfirm('You have unsaved changes in Details. Close anyway?', function() {
                pkClearDetailsDirty();
                pkActuallyClose();
            }, 'Unsaved Changes');
            return;
        }
        pkActuallyClose();
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
                pkCloseAdminModal();
            }
        });

        // Dirty tracking on Details fields
        ['pk-editdetails-url', 'pk-editdetails-address', 'pk-editdetails-city',
         'pk-editdetails-province', 'pk-editdetails-postalcode', 'pk-editdetails-mapurl',
         'pk-editdetails-description', 'pk-editdetails-directions'].forEach(function(id) {
            var el = gid(id); if (el) el.addEventListener('input', pkMarkDetailsDirty);
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
                        pkClearDetailsDirty();
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
    if (typeof PnConfig === 'undefined' || !PnConfig.canManageAwards) return;

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
    if (typeof PnConfig === 'undefined' || !PnConfig.canManageAwards) return;

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

        // ---- Confirmation dialog ----
        var confirmOverlay = document.getElementById('pn-unit-confirm-overlay');
        var submitBtn      = document.getElementById('pn-unit-create-submit-btn');
        var confirmBack    = document.getElementById('pn-unit-confirm-back');
        var confirmYes     = document.getElementById('pn-unit-confirm-yes');
        var nameInput      = document.getElementById('pn-unit-create-name');
        var typeSelect     = document.getElementById('pn-unit-create-type');

        if (submitBtn && confirmOverlay) {
            submitBtn.addEventListener('click', function() {
                var form = document.getElementById('pn-unit-create-form');
                if (form && !form.reportValidity()) return;
                var name = nameInput ? nameInput.value.trim() : '';
                var type = typeSelect ? typeSelect.value : 'unit';
                document.getElementById('pn-unit-confirm-name').textContent = name || '(unnamed)';
                document.getElementById('pn-unit-confirm-type').textContent = type;
                overlay.classList.remove('pn-open');
                confirmOverlay.classList.add('pn-open');
            });
        }
        if (confirmBack && confirmOverlay) {
            confirmBack.addEventListener('click', function() {
                confirmOverlay.classList.remove('pn-open');
                overlay.classList.add('pn-open');
            });
        }
        if (confirmYes) {
            confirmYes.addEventListener('click', function() {
                confirmOverlay.classList.remove('pn-open');
                document.body.style.overflow = '';
                document.getElementById('pn-unit-create-form').submit();
            });
        }
        if (confirmOverlay) {
            confirmOverlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    confirmOverlay.classList.remove('pn-open');
                    overlay.classList.add('pn-open');
                }
            });
        }
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
                fetch(PSEARCH_URL + '&scope=' + scope + '&include_inactive=1&q=' + encodeURIComponent(term))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var el = gid('kn-moveplayer-player-results');
                        el.innerHTML = (data && data.length)
                            ? data.map(function(p) {
                                var inactive = p.Active === 0 ? ' <span style="color:#e53e3e;font-size:10px;font-weight:600">inactive</span>' : '';
                                return '<div class="kn-ac-item" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                                    + escHtml(p.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + escHtml(p.KAbbr || '') + ':' + escHtml(p.PAbbr || '') + ')</span>' + inactive + '</div>';
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
    var ov = document.getElementById('kn-claimpark-overlay');
    if (!ov) return;
    function closeClaimPark() { ov.classList.remove('kn-open'); document.body.style.overflow = ''; }
    document.getElementById('kn-claimpark-close-btn').addEventListener('click', closeClaimPark);
    document.getElementById('kn-claimpark-cancel').addEventListener('click', closeClaimPark);
    ov.addEventListener('click', function(e) { if (e.target === ov) closeClaimPark(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && ov.classList.contains('kn-open')) closeClaimPark(); });
    window.knOpenClaimParkModal = function() { ov.classList.add('kn-open'); document.body.style.overflow = 'hidden'; };
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
                                + (same ? '' : ' data-id="' + pl.MundaneId + '" tabindex="-1"')
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
        acKeyNav(gid('kn-merge-keep-name'),   gid('kn-merge-keep-results'),   'kn-ac-open', '.kn-ac-item[data-id]');
        acKeyNav(gid('kn-merge-remove-name'), gid('kn-merge-remove-results'), 'kn-ac-open', '.kn-ac-item[data-id]');

        gid('kn-mergeplayer-submit').addEventListener('click', function() {
            var btn        = gid('kn-mergeplayer-submit');
            var keepId     = gid('kn-merge-keep-id').value;
            var removeId   = gid('kn-merge-remove-id').value;
            var keepName   = gid('kn-merge-keep-name').value.trim();
            var removeName = gid('kn-merge-remove-name').value.trim();
            if (!keepId || !removeId) { showFb('Select both players.', false); return; }
            knConfirm(
                'Merge “' + removeName + '” INTO “' + keepName + '”?\n\n”' + removeName + '” will be permanently deleted and all data transferred to “' + keepName + '”.\n\nThis CANNOT be undone.',
                function() {
                    btn.disabled = true;
                    $.post(MERGE_URL, { FromMundaneId: removeId, ToMundaneId: keepId }, function(r) {
                        btn.disabled = false;
                        if (r && r.status === 0) {
                            showFb('”' + removeName + '” has been merged into “' + keepName + '” and deleted.', true);
                            setTimeout(function() { closeModal(); location.reload(); }, 2200);
                        } else {
                            showFb((r && r.error) ? r.error : 'Merge failed.', false);
                        }
                    }, 'json').fail(function() {
                        btn.disabled = false;
                        showFb('Request failed. Please try again.', false);
                    });
                },
                'Confirm Merge'
            );
        });
    });
})();

// ---- Parknew: Heraldry Upload Modal ----
(function() {
    if (typeof PkConfig === 'undefined' || !PkConfig.canAdmin) return;

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

                function doUpload(blob) {
                    var fd = new FormData();
                    fd.append('Heraldry', blob, file.name);
                    fetch(UPLOAD_URL, { method: 'POST', body: fd })
                        .then(function(r) { return r.json(); })
                        .then(function(r) {
                            if (upl) upl.style.display = 'none';
                            if (r && r.status === 0) {
                                if (done) done.style.display = '';
                                setTimeout(function() { window.location.reload(); }, 1200);
                            } else {
                                if (sel) sel.style.display = '';
                                alert((r && r.error) ? r.error : 'Upload failed. Please try again.');
                            }
                        })
                        .catch(function() {
                            if (upl) upl.style.display = 'none';
                            if (sel) sel.style.display = '';
                            alert('Request failed. Please try again.');
                        });
                }

                trimTransparentEdges(file, function(trimmed) {
                    file = trimmed;
                    if (file.size > 348836) {
                        var isPng = (file.type === 'image/png');
                        resizeImageToLimit(file, 348836, doUpload, function(errMsg) {
                            if (upl) upl.style.display = 'none';
                            if (sel) sel.style.display = '';
                            alert(errMsg || 'Could not resize image. Please choose a smaller file.');
                        }, isPng);
                    } else {
                        doUpload(file);
                    }
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
    if (typeof PkConfig === 'undefined' || !PkConfig.canAdmin) return;

    var MOVE_URL        = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/moveplayer';
    var PSEARCH_EXCLUDE = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/playersearch&scope=exclude&include_inactive=1&q=';
    var PSEARCH_OWN     = PkConfig.uir + 'ParkAjax/park/' + PkConfig.parkId + '/playersearch&scope=own&include_inactive=1&q=';

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
                                if (player.Active === 0) {
                                    var badge = document.createElement('span');
                                    badge.textContent = ' inactive';
                                    badge.style.cssText = 'color:#e53e3e;font-size:10px;font-weight:600';
                                    item.appendChild(badge);
                                }
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
    if (typeof PkConfig === 'undefined' || !PkConfig.canAdmin) return;

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
                                + (same ? '' : ' data-id="' + pl.MundaneId + '" tabindex="-1"')
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
        acKeyNav(gid('pk-merge-keep-name'),   gid('pk-merge-keep-results'),   'pk-ac-open', '.pk-ac-item[data-id]');
        acKeyNav(gid('pk-merge-remove-name'), gid('pk-merge-remove-results'), 'pk-ac-open', '.pk-ac-item[data-id]');

        gid('pk-mergeplayer-submit').addEventListener('click', function() {
            var keepId     = gid('pk-merge-keep-id').value;
            var removeId   = gid('pk-merge-remove-id').value;
            var keepName   = gid('pk-merge-keep-name').value.trim();
            var removeName = gid('pk-merge-remove-name').value.trim();
            if (!keepId || !removeId) { showFb('Select both players.', false); return; }
            var btn = this;
            knConfirm(
                'Merge “' + removeName + '” INTO “' + keepName + '”?\n\n”' + removeName + '” will be permanently deleted and all data transferred to “' + keepName + '”.\n\nThis CANNOT be undone.',
                function() {
                    btn.disabled = true;
                    $.post(MERGE_URL, { FromMundaneId: removeId, ToMundaneId: keepId }, function(r) {
                        btn.disabled = false;
                        if (r && r.status === 0) {
                            showFb('”' + removeName + '” has been merged into “' + keepName + '” and deleted.', true);
                            setTimeout(function() { closeModal(); location.reload(); }, 2200);
                        } else {
                            showFb((r && r.error) ? r.error : 'Merge failed.', false);
                        }
                    }, 'json').fail(function() {
                        btn.disabled = false;
                        showFb('Request failed. Please try again.', false);
                    });
                },
                'Confirm Merge'
            );
        });
    });
})();


// ---- Player Attendance Edit/Delete (Playernew) ----
(function() {
    if (typeof PnConfig === 'undefined' || !PnConfig.canEditAnyAttendance) return;

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
        var attId    = this.dataset.attId;
        var mundaneId = this.dataset.mundaneId || '';
        var btn   = this;
        if (!confirm('Delete this attendance record?')) return;
        btn.disabled = true;
        $.post(BASE_URL + attId + '/delete', { MundaneId: mundaneId }, function(r) {
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

// ---- Recs table export helpers (shared by Kingdom + Park) ----
function recsCellText(td) {
    // Clone so we don't mutate the live DOM; strip expand/collapse buttons and
    // the ellipsis "… […]" span — jQuery .text() still reads display:none text,
    // so the full pk-rec-notes-full content comes through automatically.
    var $c = $(td).clone();
    $c.find('button, .pk-rec-notes-ellipsis').remove();
    return $c.text().replace(/\s+/g, ' ').trim();
}
window.recsExportCsv = function(dt, filename) {
    var EXPORT_COLS = 6; // skip actions column
    var headers = [];
    dt.columns().header().each(function(h, i) {
        if (i < EXPORT_COLS) headers.push(h.textContent.trim());
    });
    var rows = [headers];
    dt.rows({search: 'applied'}).every(function() {
        var cells = [];
        $(this.node()).find('td').each(function(i) {
            if (i < EXPORT_COLS) cells.push(recsCellText(this));
        });
        rows.push(cells);
    });
    var csv = rows.map(function(r) {
        return r.map(function(v) { return '"' + v.replace(/"/g, '""') + '"'; }).join(',');
    }).join('\r\n');
    var blob = new Blob(['\ufeff' + csv], {type: 'text/csv;charset=utf-8;'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click();
    document.body.removeChild(a); URL.revokeObjectURL(url);
};
window.recsExportPrint = function(dt, title) {
    var EXPORT_COLS = 6;
    var headers = [];
    dt.columns().header().each(function(h, i) {
        if (i < EXPORT_COLS) headers.push('<th>' + h.textContent.trim() + '</th>');
    });
    var rowsHtml = '';
    dt.rows({search: 'applied'}).every(function() {
        var cells = '';
        $(this.node()).find('td').each(function(i) {
            if (i < EXPORT_COLS) cells += '<td>' + recsCellText(this) + '</td>';
        });
        rowsHtml += '<tr>' + cells + '</tr>';
    });
    var win = window.open('', '_blank');
    win.document.write('<!DOCTYPE html><html><head><title>' + title + '</title><style>' +
        'body{font-family:sans-serif;font-size:12px;padding:16px;color:#1a202c}' +
        'h2{font-size:15px;margin:0 0 12px;color:#2b6cb0}' +
        'table{border-collapse:collapse;width:100%}' +
        'th,td{border:1px solid #e2e8f0;padding:6px 8px;text-align:left;vertical-align:top}' +
        'th{background:#edf2f7;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.04em}' +
        'tr:nth-child(even) td{background:#f7fafc}' +
        '@media print{body{padding:0}button{display:none}}' +
        '</style></head><body>' +
        '<h2>' + title + '</h2>' +
        '<table><thead><tr>' + headers.join('') + '</tr></thead><tbody>' + rowsHtml + '</tbody></table>' +
        '<script>window.onload=function(){window.print();}<\/script>' +
        '</body></html>');
    win.document.close();
};

// ---- Recommendations tab filter bar (Kingdom + Park) ----
(function() {
    function initFilterBar(bar, filterVarName, dtVarName) {
        if (!bar) return;
        bar.addEventListener('click', function(e) {
            var btn = e.target.closest('.kn-rec-filter-btn');
            if (!btn) return;
            var filter = btn.dataset.filter;
            bar.querySelectorAll('.kn-rec-filter-btn').forEach(function(b) {
                b.classList.toggle('kn-rec-filter-active', b.dataset.filter === filter);
            });
            window[filterVarName] = filter;
            if (window[dtVarName]) window[dtVarName].draw();
        });
    }

    initFilterBar(
        document.querySelector('#kn-tab-recommendations .kn-rec-filter-bar'),
        'knRecActiveFilter', 'knRecDT'
    );
    initFilterBar(
        document.querySelector('#pk-tab-recommendations .kn-rec-filter-bar'),
        'pkRecActiveFilter', 'pkRecDT'
    );

    // Info popover toggle
    document.addEventListener('click', function(e) {
        var infoBtn = e.target.closest('.kn-rec-filter-info-btn');
        if (infoBtn) {
            var pop = infoBtn.parentElement.querySelector('.kn-rec-filter-popover');
            var isOpen = pop.classList.contains('kn-pop-open');
            document.querySelectorAll('.kn-rec-filter-popover.kn-pop-open').forEach(function(p) { p.classList.remove('kn-pop-open'); });
            if (!isOpen) pop.classList.add('kn-pop-open');
            return;
        }
        if (!e.target.closest('.kn-rec-filter-info')) {
            document.querySelectorAll('.kn-rec-filter-popover.kn-pop-open').forEach(function(p) { p.classList.remove('kn-pop-open'); });
        }
    });
})();
// ── Email spell-checker ──────────────────────────────────────────────────────
// Attaches a "Did you mean …?" suggestion banner to an email input.
// inputId:      id of the <input type="email"> element
// suggestionId: id of the companion .esc-suggestion <div>
window.initEmailSpellCheck = function(inputId, suggestionId) {
    var input = document.getElementById(inputId);
    var box   = document.getElementById(suggestionId);
    if (!input || !box) return;

    var useBtn     = box.querySelector('.esc-suggestion-use');
    var dismissBtn = box.querySelector('.esc-suggestion-dismiss');
    var suggText   = box.querySelector('.esc-suggestion strong');

    function check() {
        if (typeof window.EmailSpellChecker === 'undefined') return;
        var val = (input.value || '').trim();
        if (!val) { box.classList.remove('esc-visible'); return; }
        var result = window.EmailSpellChecker.run({ email: val });
        if (result && result.full && result.full !== val) {
            suggText.textContent = result.full;
            box.classList.add('esc-visible');
        } else {
            box.classList.remove('esc-visible');
        }
    }

    input.addEventListener('blur', check);

    if (useBtn) useBtn.addEventListener('click', function() {
        input.value = suggText.textContent;
        box.classList.remove('esc-visible');
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });

    if (dismissBtn) dismissBtn.addEventListener('click', function() {
        box.classList.remove('esc-visible');
    });
};
// ---- Admission & Fees management (event detail + create) ----
(function() {
    var cfg = (typeof EvConfig !== 'undefined' && EvConfig.hasFees) ? EvConfig
            : (typeof EcConfig !== 'undefined' && EcConfig.hasFees) ? EcConfig : null;
    if (!cfg) return;

    var evFees = (cfg.fees || []).map(function(f) {
        return { AdmissionType: f.AdmissionType || '', Cost: parseFloat(f.Cost) || 0 };
    });

    var listId = cfg.feesListId || 'ev-fees-list';

    function render() {
        var list = document.getElementById(listId);
        if (!list) return;
        list.innerHTML = '';
        if (evFees.length === 0) {
            list.innerHTML = '<div style="color:#718096;font-size:13px;padding:4px 0">No fees added — event is free.</div>';
            serialize();
            return;
        }
        evFees.forEach(function(fee, idx) {
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px';
            var typeVal = (fee.AdmissionType || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;');
            var costVal = (typeof fee.Cost === 'number' ? fee.Cost : 0).toFixed(2);
            row.innerHTML =
                '<input type="text" placeholder="Admission type (e.g. Full Weekend)" value="' + typeVal + '" ' +
                'data-fees-idx="' + idx + '" data-fees-field="AdmissionType" ' +
                'style="flex:1;padding:5px 8px;border:1px solid #cbd5e0;border-radius:4px;font-size:13px">' +
                '<span style="color:#718096;font-size:13px;flex-shrink:0">$</span>' +
                '<input type="number" min="0" step="0.01" value="' + costVal + '" ' +
                'data-fees-idx="' + idx + '" data-fees-field="Cost" ' +
                'style="width:80px;padding:5px 8px;border:1px solid #cbd5e0;border-radius:4px;font-size:13px">' +
                '<button type="button" data-fees-remove="' + idx + '" title="Remove" ' +
                'style="background:none;border:none;cursor:pointer;color:#e53e3e;font-size:18px;padding:0 3px;line-height:1">&times;</button>';
            list.appendChild(row);
        });
        list.querySelectorAll('input[data-fees-idx]').forEach(function(inp) {
            inp.addEventListener('input', function() {
                var i = parseInt(this.getAttribute('data-fees-idx'));
                var f = this.getAttribute('data-fees-field');
                if (!evFees[i]) return;
                evFees[i][f] = (f === 'Cost') ? (parseFloat(this.value) || 0) : this.value;
                serialize();
            });
        });
        list.querySelectorAll('button[data-fees-remove]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var i = parseInt(this.getAttribute('data-fees-remove'));
                evFees.splice(i, 1);
                render();
            });
        });
        serialize();
    }

    function serialize() {
        var el = document.getElementById('ev-fees-json');
        if (el) el.value = JSON.stringify(evFees);
    }

    window.evFeesAdd = function() {
        evFees.push({ AdmissionType: '', Cost: 0 });
        render();
        var inputs = document.querySelectorAll('#' + listId + ' input[type="text"]');
        if (inputs.length) inputs[inputs.length - 1].focus();
    };

    // Re-init fees from server data when edit modal opens
    window.evFeesReset = function(fees) {
        evFees = (fees || []).map(function(f) {
            return { AdmissionType: f.AdmissionType || '', Cost: parseFloat(f.Cost) || 0 };
        });
        render();
    };

    // Hook form submit to serialize
    var form = document.getElementById('ev-edit-form') || document.getElementById('ec-form');
    if (form) {
        form.addEventListener('submit', function() { serialize(); });
    }

    render();
})();
// ---- External Links management (event detail + create) ----
(function() {
    var LINK_ICONS = [
        { icon: 'fas fa-ticket-alt', label: 'Ticket'    },
        { icon: 'fab fa-facebook',  label: 'Facebook'  },
        { icon: 'fab fa-discord',   label: 'Discord'   },
        { icon: 'fas fa-globe',     label: 'Globe'     },
        { icon: 'far fa-clipboard', label: 'Clipboard' },
        { icon: 'fas fa-link',      label: 'Link'      },
    ];

    var cfg = (typeof EvConfig !== 'undefined' && EvConfig.hasLinks) ? EvConfig
            : (typeof EcConfig !== 'undefined' && EcConfig.hasLinks) ? EcConfig : null;
    if (!cfg) return;

    var evLinks = (cfg.links || []).map(function(l) {
        return { Title: l.Title || '', Url: l.Url || '', Icon: l.Icon || '' };
    });

    var listId = cfg.linksListId || 'ev-links-list';

    // Close all icon menus on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('[data-links-icon-btn],[data-links-icon-menu],[data-links-icon-pick]')) {
            var list = document.getElementById(listId);
            if (list) list.querySelectorAll('[data-links-icon-menu]').forEach(function(m) { m.style.display = 'none'; });
        }
    });

    function render() {
        var list = document.getElementById(listId);
        if (!list) return;
        list.innerHTML = '';
        if (evLinks.length === 0) {
            list.innerHTML = '<div style="color:#718096;font-size:13px;padding:4px 0">No links added.</div>';
            serialize();
            return;
        }
        evLinks.forEach(function(link, idx) {
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:6px';

            var menuHtml = '<div data-links-icon-menu="' + idx + '" ' +
                'style="display:none;position:absolute;top:100%;left:0;z-index:999;background:#fff;' +
                'border:1px solid #cbd5e0;border-radius:6px;padding:6px;box-shadow:0 4px 12px rgba(0,0,0,0.12);' +
                'flex-wrap:wrap;gap:4px;width:160px">' +
                LINK_ICONS.map(function(li) {
                    var active = link.Icon === li.icon;
                    return '<button type="button" title="' + li.label + '" data-links-icon-pick="' + idx + '" data-links-icon-val="' + li.icon + '" ' +
                        'style="width:34px;height:34px;border:1px solid ' + (active ? '#4299e1' : '#e2e8f0') + ';' +
                        'border-radius:4px;background:' + (active ? '#ebf8ff' : '#fff') + ';cursor:pointer;' +
                        'font-size:14px;display:flex;align-items:center;justify-content:center">' +
                        '<i class="' + li.icon + '"></i></button>';
                }).join('') +
                '</div>';

            var titleVal = (link.Title || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
            var urlVal   = (link.Url   || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');

            var noIcon = !link.Icon;
            row.innerHTML =
                '<div style="position:relative;flex-shrink:0">' +
                    '<button type="button" data-links-icon-btn="' + idx + '" title="Choose icon" ' +
                    'style="width:36px;height:34px;border:1px solid ' + (noIcon ? '#fc8181' : '#cbd5e0') + ';' +
                    'border-radius:4px;background:' + (noIcon ? '#fff5f5' : '#fff') + ';' +
                    'cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;' +
                    (noIcon ? 'box-shadow:0 0 0 2px #fed7d7;' : '') + '">' +
                    (noIcon ? '<i class="fas fa-question" style="color:#fc8181;font-size:13px"></i>' : '<i class="' + link.Icon + '"></i>') +
                    '</button>' +
                    menuHtml +
                '</div>' +
                '<input type="text" placeholder="Title (e.g. Register Here)" value="' + titleVal + '" ' +
                'data-links-idx="' + idx + '" data-links-field="Title" ' +
                'style="flex:1;min-width:0;padding:5px 8px;border:1px solid #cbd5e0;border-radius:4px;font-size:13px">' +
                '<input type="text" placeholder="https://\u2026" value="' + urlVal + '" ' +
                'data-links-idx="' + idx + '" data-links-field="Url" ' +
                'style="flex:2;min-width:0;padding:5px 8px;border:1px solid #cbd5e0;border-radius:4px;font-size:13px">' +
                '<button type="button" data-links-remove="' + idx + '" title="Remove" ' +
                'style="background:none;border:none;cursor:pointer;color:#e53e3e;font-size:18px;padding:0 3px;line-height:1;flex-shrink:0">\xd7</button>';

            list.appendChild(row);
        });

        list.querySelectorAll('button[data-links-icon-btn]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var i    = parseInt(this.getAttribute('data-links-icon-btn'));
                var menu = list.querySelector('[data-links-icon-menu="' + i + '"]');
                if (!menu) return;
                var showing = menu.style.display === 'flex';
                list.querySelectorAll('[data-links-icon-menu]').forEach(function(m) { m.style.display = 'none'; });
                if (!showing) menu.style.display = 'flex';
            });
        });

        list.querySelectorAll('button[data-links-icon-pick]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var i   = parseInt(this.getAttribute('data-links-icon-pick'));
                var val = this.getAttribute('data-links-icon-val');
                if (!evLinks[i]) return;
                evLinks[i].Icon = val;
                list.querySelectorAll('[data-links-icon-menu]').forEach(function(m) { m.style.display = 'none'; });
                render();
            });
        });

        list.querySelectorAll('input[data-links-idx]').forEach(function(inp) {
            inp.addEventListener('input', function() {
                var i = parseInt(this.getAttribute('data-links-idx'));
                var f = this.getAttribute('data-links-field');
                if (!evLinks[i]) return;
                evLinks[i][f] = this.value;
                serialize();
            });
        });

        list.querySelectorAll('button[data-links-remove]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var i = parseInt(this.getAttribute('data-links-remove'));
                evLinks.splice(i, 1);
                render();
            });
        });

        serialize();
    }

    function serialize() {
        var el = document.getElementById('ev-links-json');
        if (el) el.value = JSON.stringify(evLinks);
    }

    window.evLinksAdd = function() {
        evLinks.push({ Title: '', Url: '', Icon: '' });
        render();
        var inputs = document.querySelectorAll('#' + listId + ' input[data-links-field="Title"]');
        if (inputs.length) inputs[inputs.length - 1].focus();
    };

    window.evLinksReset = function(links) {
        evLinks = (links || []).map(function(l) {
            return { Title: l.Title || '', Url: l.Url || '', Icon: l.Icon || '' };
        });
        render();
    };

    var form = document.getElementById('ev-edit-form') || document.getElementById('ec-form');
    if (form) {
        form.addEventListener('submit', function() { serialize(); });
    }

    render();
})();
