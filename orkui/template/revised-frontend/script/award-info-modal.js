/* ===========================================================
 * Award Info modal — the shared (i) "award details" dialog.
 *
 * Markup: revised-frontend/_award_info_modal.tpl (included once per page by the
 * player profile, the Kingdom and Park profiles and the Recommendations Manager).
 * Any element with class .pn-award-info-btn and data-awards-id (plus an optional
 * data-award-name) opens it: the Awards/Titles rows on a profile and the granted
 * rows of the closed-recs panels / Recs Manager. Programmatic:
 *   window.ornAwardInfo.open(awardsId, awardName, triggerEl)
 *
 * Every viewer. Lazily fetches PlayerAjax/award_info/{AwardsId} (filtered for the
 * session user server-side) and caches each answer for the page's life. No jQuery
 * and no page config object: the Recs Manager loads neither revised.js nor a
 * *Config, so the base URL comes from the overlay's data-uir.
 * =========================================================== */
(function() {
    if (window.ornAwardInfo) return;   // guard a double include
    function gid(id) { return document.getElementById(id); }
    var ov = gid('pn-award-info-overlay');
    if (!ov) return;
    var uir = ov.getAttribute('data-uir') || '/orkui/';
    // The Recs Manager renders the overlay in its own modal shell, which is shown and
    // hidden by the [hidden] attribute rather than the .pn-open class.
    var toggleHidden = ov.getAttribute('data-ai-toggle-hidden') === '1';
    var cache = {};
    var lastTrigger = null;
    var currentId = 0;
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fmtDate(s) {
        if (!s || /^0000/.test(s)) return '';
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
        if (!m) return esc(s);
        var d = new Date(+m[1], +m[2] - 1, +m[3]);
        return esc(d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }));
    }
    function person(id, name) {
        if (!name) return '';
        return id > 0
            ? '<a href="' + uir + 'Player/profile/' + (+id) + '">' + esc(name) + '</a>'
            : esc(name);
    }
    function section(icon, title, inner) {
        return '<section class="pn-ai-section"><h4 class="pn-ai-h"><i class="fas ' + icon + '"></i> ' + title + '</h4>' + inner + '</section>';
    }
    function empty(text) { return '<p class="pn-ai-empty">' + text + '</p>'; }

    function renderRecs(info) {
        if (!info.RecsShown) {
            return section('fa-thumbs-up', 'Recommendations', empty(info.RecsWithheld === 'login'
                ? 'Sign in to see the recommendations behind this award.'
                : 'This kingdom keeps its recommendations private.'));
        }
        var recs = info.Recommendations || [];
        if (!recs.length) return section('fa-thumbs-up', 'Recommendations', empty('No recommendations on record for this award.'));
        var html = '<ul class="pn-ai-recs">';
        recs.forEach(function(r) {
            var who = r.IsAnonymous ? '<span class="pn-ai-anon">Anonymous</span>' : (person(r.RecommendedById, r.RecommendedBy) || '<span class="pn-ai-anon">Unknown</span>');
            var seconds = r.Seconds > 0
                ? '<span class="pn-ai-seconds" data-tip="Players who seconded this recommendation"><i class="fas fa-user-plus"></i> ' + (+r.Seconds) + '</span>'
                : '';
            html += '<li class="pn-ai-rec">'
                + '<div class="pn-ai-rec-head"><span class="pn-ai-rec-who">' + who + '</span>'
                + '<span class="pn-ai-rec-meta">' + fmtDate(r.DateRecommended) + seconds + '</span></div>'
                + (r.Reason ? '<div class="pn-ai-rec-reason">' + esc(r.Reason) + '</div>' : '')
                + '</li>';
        });
        return section('fa-thumbs-up', 'Recommendations', html + '</ul>');
    }

    function row(label, value) {
        return value ? '<div class="pn-ai-row"><span class="pn-ai-label">' + label + '</span><span class="pn-ai-value">' + value + '</span></div>' : '';
    }
    function renderCourt(info) {
        var c = info.Court;
        if (!c) return section('fa-gavel', 'Court', empty('No court record for this award.'));
        var where = c.EventName || c.ParkName || c.KingdomName || '';
        var courtLink = '<a href="' + uir + 'Reports/court&CourtId=' + (+c.CourtId) + '">' + esc(c.CourtName || 'Court') + '</a>';
        var html = '<div class="pn-ai-rows">'
            + row('Court', courtLink)
            + row('Date', fmtDate(c.CourtDate))
            + row('Where', esc(where))
            + row('Given by', person(c.GivenById, c.GivenBy));
        if (c.Scroll && c.Scroll.Maker) html += row('Scroll', person(c.Scroll.MakerId, c.Scroll.Maker));
        if (c.Regalia && c.Regalia.Maker) html += row('Regalia', person(c.Regalia.MakerId, c.Regalia.Maker));
        (c.Artisans || []).forEach(function(a) {
            html += row(esc(a.Contribution || 'Artisan'), person(a.MundaneId, a.Persona));
        });
        html += '</div>';
        if (c.Citation) {
            html += '<blockquote class="pn-ai-citation">' + esc(c.Citation) + '</blockquote>';
        }
        return section('fa-gavel', 'Court', html);
    }
    // Print state for EVERY award (not only finalized-court ones). "Not printed
    // yet" is only news to someone who could print it, so others see nothing.
    function renderScroll(info) {
        if (info.PrintedWithScrollery) {
            var when = fmtDate(info.LastPrintedAt);
            return section('fa-scroll', 'Scroll', '<p class="pn-ai-scroll-state"><i class="fas fa-print"></i> Printed with Scrollery'
                + (when ? ' on ' + when : '') + '</p>');
        }
        return info.CanPrint ? section('fa-scroll', 'Scroll', empty('Not printed yet.')) : '';
    }
    // The footer's View/Print links, for viewers the render route will serve.
    function setScrollLinks(info) {
        var view = gid('pn-award-info-view'), print = gid('pn-award-info-print');
        if (!view || !print) return;
        var id = info ? parseInt(info.AwardsId, 10) : 0;
        var show = !!(info && info.CanPrint && id > 0);
        if (show) {
            var base = uir + 'Scrollery/render&awards=' + id;
            view.href = base;
            print.href = base + '&print=1';
        } else {
            view.removeAttribute('href');
            print.removeAttribute('href');
        }
        view.style.display = show ? '' : 'none';
        print.style.display = show ? '' : 'none';
    }

    function render(info, name) {
        // Title here, not only on fetch, so a reopen from cache keeps its rank.
        if (info.IsLadder && info.Rank > 0) {
            gid('pn-award-info-name').textContent = (name || info.AwardName) + ' · Rank ' + info.Rank;
        }
        gid('pn-award-info-body').innerHTML = renderRecs(info) + renderCourt(info) + renderScroll(info);
        setScrollLinks(info);
    }
    function isOpen() { return ov.classList.contains('pn-open'); }
    function open(awardsId, name, trigger) {
        var id = parseInt(awardsId, 10);
        if (!id) return;
        lastTrigger = trigger || null;
        name = name || '';
        gid('pn-award-info-name').textContent = name || 'Award Details';
        var body = gid('pn-award-info-body');
        ov.classList.add('pn-open');
        if (toggleHidden) ov.hidden = false;
        ov.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        gid('pn-award-info-close-btn').focus();
        currentId = id;
        if (cache[id]) { render(cache[id], name); return; }
        setScrollLinks(null);
        body.innerHTML = '<p class="pn-ai-empty"><i class="fas fa-spinner fa-spin"></i> Loading…</p>';
        fetch(uir + 'PlayerAjax/award_info/' + id, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || d.status !== 0 || !d.info) throw new Error((d && d.error) || 'Could not load details');
                cache[id] = d.info;
                // A slow answer for a row the user has since moved off must not
                // land in (and point the print links of) another award's modal.
                if (currentId !== id) return;
                if (isOpen()) render(d.info, name);
            })
            .catch(function(err) {
                if (currentId !== id) return;
                body.innerHTML = '<p class="pn-ai-empty pn-ai-error">' + esc(err.message || 'Could not load details') + '</p>';
            });
    }
    function close() {
        if (!isOpen()) return;
        ov.classList.remove('pn-open');
        if (toggleHidden) ov.hidden = true;
        ov.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (lastTrigger && document.body.contains(lastTrigger)) lastTrigger.focus();
    }
    // Delegated, so rows rendered later (lazy panels, infinite scroll) need no wiring.
    document.addEventListener('click', function(e) {
        var btn = e.target.closest ? e.target.closest('.pn-award-info-btn') : null;
        if (!btn) return;
        e.preventDefault();
        open(btn.getAttribute('data-awards-id'), btn.getAttribute('data-award-name'), btn);
    });
    gid('pn-award-info-close-btn').addEventListener('click', close);
    gid('pn-award-info-done').addEventListener('click', close);
    // Printing logs the scroll, so the cached "Not printed yet" goes stale:
    // the next open refetches.
    if (gid('pn-award-info-print')) {
        gid('pn-award-info-print').addEventListener('click', function() { delete cache[currentId]; });
    }
    var downOnSelf = false;
    ov.addEventListener('mousedown', function(e) { downOnSelf = (e.target === this); });
    ov.addEventListener('click', function(e) { if (downOnSelf && e.target === this) close(); });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) close();
    });

    window.ornAwardInfo = { open: open, close: close };
})();
