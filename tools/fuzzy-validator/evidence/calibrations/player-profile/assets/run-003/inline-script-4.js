
	// ---- Search toggle ----
	function nsToggle(btn) {
		var sw = document.getElementById('nav-search-wrap');
		var isOpen = sw.classList.toggle('nav-search-open');
		btn.classList.toggle('active');
		if (isOpen) {
			setTimeout(function(){ var el = document.getElementById('UniversalSearch'); if (el) el.focus(); }, 50);
		} else {
			var el = document.getElementById('UniversalSearch');
			if (el) el.value = '';
			var dd = document.getElementById('nav-search-dropdown');
			if (dd) { dd.className = ''; dd.innerHTML = ''; }
		}
	}

	// User's home kingdom ID for priority sorting (0 = not logged in / unknown)
	var nsKid = 0;
	var nsPid = 0;
	var nsUir = 'http://localhost:19080/orkui/index.php?Route=';

	$(document).ready(function() {
		// ---- Dropdowns: close on outside click ----
		$(document).on('click.navDropdowns', function(e) {
			['nav-avatar-wrap', 'nav-resources-wrap'].forEach(function(id) {
				var wrap = document.getElementById(id);
				if (wrap && !wrap.contains(e.target)) wrap.classList.remove('open');
			});
			var mwrap = document.getElementById('nav-maintenance-wrap');
			if (mwrap && !mwrap.contains(e.target)) {
				var pop = document.getElementById('nav-maintenance-pop');
				if (pop) pop.style.display = 'none';
			}
		});

		// ---- Universal search ----
		var nsTimer = null;
		var nsXhr   = null;

		function nsEsc(str) {
			return $('<span>').text(str || '').html();
		}

		function nsSearch(term) {
			var $dd = $('#nav-search-dropdown');
			$dd.html('<div class="nsdd-loading"><i class="fas fa-spinner fa-spin"></i>&ensp;Searching&hellip;</div>').addClass('nsdd-visible');
			if (nsXhr) nsXhr.abort();
			nsXhr = $.getJSON(nsUir + 'SearchAjax/universal&q=' + encodeURIComponent(term) + '&kid=' + nsKid + '&pid=' + nsPid,
				function(res) { nsRender(res || {}); }
			).fail(function(xhr) {
				if (xhr.statusText !== 'abort')
					$dd.html('<div class="nsdd-empty">Search unavailable</div>');
			});
		}

		function nsRender(res) {
			var $dd = $('#nav-search-dropdown');
			var html = '';
			var sep = false;
			var players  = res.players  || [];
			var parks    = res.parks    || [];
			var kingdoms = res.kingdoms || [];
			var units    = res.units    || [];

			if (players.length) {
				html += '<div class="nsdd-section-header"><i class="fas fa-user fa-fw"></i>&ensp;Players</div>';
				$.each(players, function(i, p) {
					var lbl = p.name ? nsEsc(p.name) : '<em class="nsdd-no-value">No Persona</em>';
					var sub = nsEsc((p.abbr || '') + ' \u00b7 ' + (p.park || ''));
					html += '<a class="nsdd-item" href="?Route=Player/profile/' + p.id + '">' +
						'<span class="nsdd-item-icon"><i class="fas fa-user fa-fw"></i></span>' +
						'<span class="nsdd-item-content"><div class="nsdd-item-label">' + lbl + '</div>' +
						'<div class="nsdd-item-sub">' + sub + '</div></span></a>';
				});
				sep = true;
			}

			if (parks.length) {
				if (sep) html += '<div class="nsdd-divider"></div>';
				html += '<div class="nsdd-section-header"><i class="fas fa-tree fa-fw"></i>&ensp;Parks</div>';
				$.each(parks, function(i, p) {
					html += '<a class="nsdd-item" href="?Route=Park/profile/' + p.id + '">' +
						'<span class="nsdd-item-icon"><i class="fas fa-tree fa-fw"></i></span>' +
						'<span class="nsdd-item-content"><div class="nsdd-item-label">' + nsEsc(p.name) + '</div></span></a>';
				});
				sep = true;
			}

			if (kingdoms.length) {
				if (sep) html += '<div class="nsdd-divider"></div>';
				html += '<div class="nsdd-section-header"><i class="fas fa-crown fa-fw"></i>&ensp;Kingdoms</div>';
				$.each(kingdoms, function(i, k) {
					html += '<a class="nsdd-item" href="?Route=Kingdom/profile/' + k.id + '">' +
						'<span class="nsdd-item-icon"><i class="fas fa-crown fa-fw"></i></span>' +
						'<span class="nsdd-item-content"><div class="nsdd-item-label">' + nsEsc(k.name) + '</div></span></a>';
				});
				sep = true;
			}

			if (units.length) {
				if (sep) html += '<div class="nsdd-divider"></div>';
				html += '<div class="nsdd-section-header"><i class="fas fa-shield-alt fa-fw"></i>&ensp;Companies &amp; Households</div>';
				$.each(units, function(i, u) {
					var icon = (u.unitType === 'Household') ? 'fas fa-home fa-fw' : 'fas fa-shield-alt fa-fw';
					html += '<a class="nsdd-item" href="?Route=Unit/index/' + u.id + '">' +
						'<span class="nsdd-item-icon"><i class="' + icon + '"></i></span>' +
						'<span class="nsdd-item-content"><div class="nsdd-item-label">' + nsEsc(u.name) + '</div>' +
						'<div class="nsdd-item-sub">' + nsEsc(u.unitType || '') + '</div></span></a>';
				});
			}

			if (!html) {
				html = '<div class="nsdd-empty">No results found</div>';
			}

			$dd.html(html);
		}

		(function() {
			var inp = document.getElementById('UniversalSearch');
			if (!inp) return;
			function updatePlaceholder() {
				inp.placeholder = window.innerWidth < 768
					? 'Search\u2026'
					: 'Search active players, parks, kingdoms\u2026';
			}
			updatePlaceholder();
			window.addEventListener('resize', updatePlaceholder);
		})();

		$('#UniversalSearch').on('input', function() {
			var term = $.trim($(this).val());
			clearTimeout(nsTimer);
			if (term.length < 2) {
				$('#nav-search-dropdown').removeClass('nsdd-visible').empty();
				return;
			}
			nsTimer = setTimeout(function() { nsSearch(term); }, 350);
		}).on('keydown', function(e) {
			if (e.key === 'Escape') {
				$('#nav-search-dropdown').removeClass('nsdd-visible').empty();
				document.getElementById('nav-search-wrap').classList.remove('nav-search-open');
				document.getElementById('nav-search-toggle').classList.remove('active');
				$(this).blur();
			} else if (e.key === 'ArrowDown') {
				e.preventDefault();
				$('#nav-search-dropdown .nsdd-item').first().focus();
			}
		});

		$('#nav-search-dropdown').on('keydown', '.nsdd-item', function(e) {
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				$(this).nextAll('.nsdd-item').first().focus();
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				var $prev = $(this).prevAll('.nsdd-item').first();
				if ($prev.length) $prev.focus(); else $('#UniversalSearch').focus();
			} else if (e.key === 'Escape') {
				$('#nav-search-dropdown').removeClass('nsdd-visible').empty();
				$('#UniversalSearch').focus();
			}
		});

		// Once the user clicks/focuses into the search, lock it open so that mousing
		// off does NOT collapse it or blur the input. It stays open until an
		// explicit outside click (below) or Escape.
		$('#UniversalSearch').on('focus', function() {
			document.getElementById('nav-search-wrap').classList.add('nav-search-open');
			document.getElementById('nav-search-toggle').classList.add('active');
		});

		// Close the search (collapse + clear) only on an actual click OUTSIDE it.
		$(document).on('click.searchDropdown', function(e) {
			if (!$(e.target).closest('#nav-search-wrap').length) {
				$('#nav-search-dropdown').removeClass('nsdd-visible').empty();
				document.getElementById('nav-search-wrap').classList.remove('nav-search-open');
				document.getElementById('nav-search-toggle').classList.remove('active');
				var _inp = document.getElementById('UniversalSearch');
				if (_inp) { _inp.value = ''; _inp.blur(); }
			}
		});
	});
	