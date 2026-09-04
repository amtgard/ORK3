/* ==========================================================================
   Scrollery — the create flow's live preview.

   Only order, rank and variant re-fetch: they change which art layers are
   drawn, so the server has to re-render the layer stack. Everything else is
   applied in place —

     * text fields write into the preview's own DOM
     * the heraldry sliders write CSS custom properties, exactly as Arcanyst's
       --heraldry-filter / --heraldry-transform did

   so dragging a slider or typing a name costs no round trip. With no Aurelia
   there is no shadow root either, so this is plain querySelector again.
   ========================================================================== */
(function () {
    'use strict';

    var cfg = window.ScConfig;
    if (!cfg) { return; }

    var $ = function (id) { return document.getElementById(id); };

    var awardEl   = $('sc-award'),
        rankEl    = $('sc-rank'),
        variantEl = $('sc-variant'),
        variantFd = $('sc-variant-field'),
        playerEl  = $('sc-player'),
        playerId  = $('sc-player-id'),
        acEl      = $('sc-player-ac'),
        reasonEl  = $('sc-reason'),
        dateEl    = $('sc-date'),
        givenEl   = $('sc-givenby'),
        titleEl   = $('sc-issuer-title'),
        nameEl    = $('sc-issuer-name'),
        useTheEl  = $('sc-use-the'),
        useOfEl   = $('sc-use-of'),
        mount     = $('sc-mount'),
        stage     = $('sc-stage'),
        frame     = $('sc-frame'),
        errEl     = $('sc-err');

    if (!awardEl || !mount) { return; }

    /* ---- helpers ------------------------------------------------------- */

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function selected() { return awardEl.options[awardEl.selectedIndex]; }

    function ordinal(n) {
        return ['', 'first', 'second', 'third', 'fourth', 'fifth',
                'sixth', 'seventh', 'eighth', 'ninth', 'tenth'][n] || '';
    }

    /* Certificate date, matching Scroll::FormatDate() — "13th May 2024". */
    function prettyDate(iso) {
        if (!iso) { return ''; }
        var parts = iso.split('-');
        if (parts.length !== 3) { return ''; }
        var d = new Date(+parts[0], +parts[1] - 1, +parts[2]);
        if (isNaN(d.getTime())) { return ''; }
        var day = d.getDate(),
            mod100 = day % 100,
            suffix = (mod100 >= 11 && mod100 <= 13)
                ? 'th'
                : ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][day % 10],
            months = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
                      'August', 'September', 'October', 'November', 'December'];
        return day + suffix + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    }

    /* ---- rank + variant options follow the chosen order ----------------- */

    function syncRankAndVariant() {
        var opt = selected();
        if (!opt) { return; }

        var min = parseInt(opt.dataset.min, 10) || 0,
            max = parseInt(opt.dataset.max, 10) || 10,
            keepRank = parseInt(rankEl.value, 10);

        rankEl.innerHTML = '';
        for (var r = min; r <= max; r++) {
            var o = document.createElement('option');
            o.value = r;
            o.textContent = r === 0 ? 'Unranked' : r;
            rankEl.appendChild(o);
        }
        // Hold the rank across an order change where it is still legal — warrior
        // starts at 1, so a rank of 0 cannot simply carry over.
        rankEl.value = (keepRank >= min && keepRank <= max) ? keepRank : min;

        var variants = (opt.dataset.variants || '').split(',').filter(Boolean);
        variantEl.innerHTML = '';
        variants.forEach(function (v) {
            var o = document.createElement('option');
            o.value = v;
            o.textContent = v.charAt(0).toUpperCase() + v.slice(1);
            variantEl.appendChild(o);
        });
        variantFd.style.display = variants.length ? '' : 'none';
    }

    /* ---- the round trip: order / rank / variant ------------------------- */

    var inFlight = null;

    function reload() {
        var opt = selected();
        if (!opt) { return; }

        var body = new URLSearchParams();
        body.set('scopeType',   cfg.scopeType);
        body.set('scopeId',     cfg.scopeId);
        body.set('awardId',     opt.value);
        body.set('rank',        rankEl.value || '0');
        body.set('variant',     variantEl.value || '');
        body.set('title',       opt.dataset.name || '');
        body.set('player',      playerEl.value.trim());
        body.set('playerId',    playerId.value || '0');
        body.set('reason',      reasonEl.value.trim());
        body.set('date',        dateEl.value || '');
        body.set('givenBy',     givenEl.value.trim());
        body.set('issuerTitle', titleEl.value.trim());
        body.set('issuerName',  nameEl.value.trim());
        body.set('useThe',      useTheEl.checked ? '1' : '0');
        body.set('useOf',       useOfEl.checked ? '1' : '0');

        if (inFlight) { inFlight.abort(); }
        var ctl = new AbortController();
        inFlight = ctl;
        stage.classList.add('is-busy');

        fetch(cfg.uir + 'ScrolleryAjax/preview', {
            method: 'POST',
            body: body,
            signal: ctl.signal,
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                stage.classList.remove('is-busy');
                if (!d || !d.Status || d.Status.Status !== 0) {
                    errEl.textContent = (d && d.Status && d.Status.Error) || 'Preview failed.';
                    return;
                }
                errEl.textContent = '';
                mount.innerHTML = d.Html;
                applyText();
                applyHeraldry();
            })
            .catch(function (e) {
                if (e.name === 'AbortError') { return; }
                stage.classList.remove('is-busy');
                errEl.textContent = 'Preview failed.';
            });
    }

    /* ---- in-place updates: no round trip ------------------------------- */

    function field(name) { return mount.querySelector('[data-scroll-text="' + name + '"]'); }

    function applyText() {
        var name = field('player');
        if (name) {
            var typed = playerEl.value.trim();
            // The blank writing-line is drawn only when there is no name, exactly as
            // the text layer does server-side. data-blank carries its URL.
            name.innerHTML = typed
                ? esc(typed)
                : '<img class="Underline" src="' + esc(name.dataset.blank || '') + '">';
        }

        var reason = field('reason');
        if (reason) { reason.innerHTML = esc(reasonEl.value.trim()) || '<br><br>'; }

        var date = field('date');
        if (date) { date.textContent = prettyDate(dateEl.value); }

        var sig = field('givenBy');
        if (sig) { sig.textContent = givenEl.value.trim(); }

        var issuer = field('issuerTitle');
        if (issuer) {
            issuer.textContent = 'and'
                + (useTheEl.checked ? ' the' : '')
                + ' ' + titleEl.value.trim()
                + (useOfEl.checked ? ' of' : '');
        }

        var issuerName = field('issuerName');
        if (issuerName) { issuerName.textContent = nameEl.value.trim(); }
    }

    /*
     * The heraldry adjustments are pure custom-property writes on the frame — the
     * same two properties Arcanyst published, and only the heraldry layer consumes
     * them. Dragging a slider costs no network request.
     */
    var heraldry = { brightness: 1, contrast: 1, scale: 1, x: 0, y: 0 };

    function applyHeraldry() {
        if (!frame) { return; }
        frame.style.setProperty('--heraldry-filter',
            'brightness(' + heraldry.brightness + ') contrast(' + heraldry.contrast + ')');
        frame.style.setProperty('--heraldry-transform',
            'translateX(' + heraldry.x + '%) translateY(' + heraldry.y + '%) scale(' + heraldry.scale + ')');
    }

    /* ---- recipient autocomplete ---------------------------------------- */

    var acTimer = null, OPEN = 'kn-ac-open';

    if (playerEl && acEl) {
        acEl.style.position = 'fixed';
        acEl.style.zIndex = '9999';

        playerEl.addEventListener('input', function () {
            playerId.value = '';
            applyText();
            var term = this.value.trim();
            if (term.length < 2) { acEl.classList.remove(OPEN); return; }

            clearTimeout(acTimer);
            acTimer = setTimeout(function () {
                // Scoped to the issuing kingdom first, as every other player search
                // in the ORK is; "&q=", not "?q=", because the route already carries
                // its own query string.
                fetch(cfg.uir + 'KingdomAjax/playersearch/' + cfg.kingdomId
                      + '&q=' + encodeURIComponent(term) + '&scope=own', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (rows) { render(rows || []); });
            }, 220);
        });

        function render(rows) {
            if (!rows.length) { acEl.classList.remove(OPEN); return; }
            // Row template mirrors the canonical kn-ac renderer in revised.js so the
            // Playersearch-v2 tier markers read the same everywhere.
            acEl.innerHTML = rows.slice(0, 10).map(function (p) {
                var abbr = (p.KAbbr && p.PAbbr)
                    ? ' <span style="color:#a0aec0;font-size:11px">(' + esc(p.KAbbr) + ':' + esc(p.PAbbr) + ')</span>'
                    : '';
                var inactive = p.Active === 0
                    ? ' <span style="color:#e53e3e;font-size:10px;font-weight:600">inactive</span>' : '';
                var suspended = p.Suspended === 1
                    ? ' <span style="margin-left:6px;background:#c53030;color:#fff;font-size:10px;font-weight:600;padding:1px 5px;border-radius:3px">suspended</span>'
                    : '';
                return '<div class="kn-ac-item' + (p.Suspended === 1 ? ' kn-ac-suspended' : '')
                     + '" tabindex="-1" data-id="' + p.MundaneId
                     + '" data-name="' + encodeURIComponent(p.Persona) + '">'
                     + esc(p.Persona) + abbr + suspended + inactive + '</div>';
            }).join('');
            if (typeof tnPositionAcFixed === 'function') { tnPositionAcFixed(playerEl, acEl); }
            acEl.classList.add(OPEN);
        }

        acEl.addEventListener('click', function (e) {
            var item = e.target.closest('.kn-ac-item[data-id]');
            if (!item) { return; }
            playerEl.value = decodeURIComponent(item.dataset.name);
            playerId.value = item.dataset.id;
            acEl.classList.remove(OPEN);
            // A real player brings their own pronoun, which only the server knows.
            reload();
        });

        document.addEventListener('click', function (e) {
            if (e.target !== playerEl && !acEl.contains(e.target)) { acEl.classList.remove(OPEN); }
        });

        // Shared arrow-key/Enter navigation, same as every other kn-ac consumer.
        if (typeof acKeyNav === 'function') {
            acKeyNav(playerEl, acEl, OPEN, '.kn-ac-item');
        }
    }

    /* ---- wiring --------------------------------------------------------- */

    awardEl.addEventListener('change', function () { syncRankAndVariant(); reload(); });
    rankEl.addEventListener('change', reload);
    variantEl.addEventListener('change', reload);

    [reasonEl, dateEl, givenEl, titleEl, nameEl].forEach(function (el) {
        el.addEventListener('input', applyText);
    });
    [useTheEl, useOfEl].forEach(function (el) { el.addEventListener('change', applyText); });
    // Translate is a percentage; the rest are percentages of 1x.
    function readControl(input) {
        var key = input.dataset.heraldryControl,
            raw = parseFloat(input.value);
        heraldry[key] = (key === 'x' || key === 'y') ? raw : raw / 100;
    }

    document.querySelectorAll('[data-heraldry-control]').forEach(function (input) {
        readControl(input);
        input.addEventListener('input', function () { readControl(input); applyHeraldry(); });
    });

    document.querySelectorAll('[data-reset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.querySelector('[data-heraldry-control="' + btn.dataset.reset + '"]');
            if (!input) { return; }
            input.value = input.dataset.default;
            readControl(input);
            applyHeraldry();
        });
    });

    $('sc-print').addEventListener('click', function () {
        // A kingdom whose awards all lack certificate art renders an empty <select>,
        // so there may be no option to read. Guard rather than throw silently.
        var opt = selected();
        if (!opt) { errEl.textContent = 'No printable award is selected.'; return; }
        var qs = new URLSearchParams();
        qs.set('scopeType',   cfg.scopeType);
        qs.set('scopeId',     cfg.scopeId);
        qs.set('awardId',     opt.value);
        qs.set('rank',        rankEl.value || '0');
        qs.set('variant',     variantEl.value || '');
        qs.set('title',       opt.dataset.name || '');
        qs.set('player',      playerEl.value.trim());
        qs.set('playerId',    playerId.value || '0');
        qs.set('reason',      reasonEl.value.trim());
        qs.set('date',        dateEl.value || '');
        qs.set('givenBy',     givenEl.value.trim());
        qs.set('issuerTitle', titleEl.value.trim());
        qs.set('issuerName',  nameEl.value.trim());
        qs.set('useThe',      useTheEl.checked ? '1' : '0');
        qs.set('useOf',       useOfEl.checked ? '1' : '0');
        qs.set('print',       '1');
        window.open(cfg.uir + 'Scrollery/compose&' + qs.toString(), '_blank', 'noopener');
    });

    syncRankAndVariant();
    applyHeraldry();
    reload();
}());
