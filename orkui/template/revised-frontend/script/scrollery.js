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
        recipeEl  = $('sc-recipe'),
        groundEl  = $('sc-ground'),
        borderEl  = $('sc-border'),
        borderFd  = $('sc-border-field'),
        paletteEl = $('sc-palette'),
        paletteFd = $('sc-palette-field'),
        swatchEl  = $('sc-palette-swatch'),
        rankSetEl = $('sc-rankset'),
        rankSetFd = $('sc-rankset-field'),
        pronounEl = $('sc-pronoun'),
        customEl    = $('sc-customname'),
        customFd    = $('sc-customname-field'),
        customLabel = $('sc-customname-label'),
        customHint  = $('sc-customname-hint'),
        withEl      = $('sc-withphrase'),
        withFd      = $('sc-withphrase-field'),
        pillsWrap = $('sc-rank-pills'),
        rankHint  = $('sc-rank-hint'),
        rankLegend = $('sc-rank-legend'),
        noteEl    = $('sc-recipe-note'),
        mount     = $('sc-mount'),
        stage     = $('sc-stage'),
        summaryEl = $('sc-summary'),
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

    /* ---- rank pills ----------------------------------------------------- */

    /*
     * The rank is a grid of pills, not a dropdown — the same control, painter and
     * colour scheme as the grant and recommend modals, via revised.js's shared
     * tnRankPaint() / tnRankPillInner(). A hidden #sc-rank input carries the value
     * and fires 'change', so everything downstream (the reload, the recipe note,
     * the print URL) still just reads rankEl.value and never learns it moved.
     *
     * heldRanks is the recipient's own award history, keyed by base award id —
     * PlayerAjax/.../awardranks, the identical call the Add Award modal makes. It
     * is empty until a recipient is chosen, and an empty map simply paints nothing
     * green, which is the honest picture: with no recipient there is no history to
     * show.
     */
    var heldRanks   = {};
    var rankTouched = false;   // has the officer picked a rank by hand?

    function heldForCurrentAward() {
        var opt = selected();
        if (!opt) { return 0; }
        // The option's value IS the base award id, which is how awardranks keys.
        return parseInt(heldRanks[opt.value], 10) || 0;
    }

    function setRank(r, touched) {
        if (touched) { rankTouched = true; }
        rankEl.value = r;
        paintRankPills();
        rankEl.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /*
     * The pills' held / forward / selected state was communicated purely through CSS
     * classes resolving to green, blue and a border — state in colour alone (WCAG
     * 1.4.1), on a control whose only accessible name was "Rank 3" (4.1.2).
     *
     * tnRankPaint() now sets aria-pressed and appends ', already earned' /
     * ', being awarded' to the name itself, so all three surfaces that share the
     * painter (this, Add Award, Recommend) get it from one place. This deliberately
     * carries no second copy of that logic — an interim one lived here and was
     * removed when the painter shipped, because two opinions about an accessible
     * name is how they drift.
     */
    function paintRankPills() {
        if (!pillsWrap || typeof tnRankPaint !== 'function') { return; }
        tnRankPaint(pillsWrap, 'sc', heldForCurrentAward(), parseInt(rankEl.value, 10) || 0);
    }

    /*
     * Rebuilt whenever the award changes, because the range is the order's: warrior
     * runs 1-10 and everything else 0-10, where 0 means "unranked" and prints a
     * blank line for somebody to write the level in by hand.
     *
     * Unranked is reached by clicking the selected pill again rather than by a pill
     * of its own — but only where the order actually allows it. On warrior, min is
     * 1 and there is no rank 0 to fall back to, so the click is a no-op.
     */
    function buildRankPills(min, max) {
        if (!pillsWrap) { return; }
        pillsWrap.innerHTML = '';
        for (var r = Math.max(min, 1); r <= max; r++) {
            var pill = document.createElement('button');
            pill.type        = 'button';
            pill.className   = 'sc-rank-pill';
            pill.dataset.rank = r;
            pill.innerHTML   = typeof tnRankPillInner === 'function'
                ? tnRankPillInner('sc', r)
                : '<span class="sc-rank-num">' + r + '</span>';
            // A placeholder name and state; paintRankPills() replaces both with the
            // held/forward wording as soon as the recipient's history is known.
            pill.setAttribute('aria-label', 'Rank ' + r);
            pill.setAttribute('aria-pressed', 'false');
            pillsWrap.appendChild(pill);
        }
        syncRankHint(min);
    }

    function syncRankHint(min) {
        if (!rankHint) { return; }
        var canClear = min < 1;
        rankHint.textContent = canClear
            ? 'Click the selected rank again to clear it — an unranked scroll prints a blank line to fill in by hand.'
            : 'This order has no unranked scroll, so a rank is always printed.';
        if (rankLegend) { rankLegend.hidden = !playerId.value; }
    }

    /*
     * The recipient's award history — the same endpoint and the same shape the Add
     * Award and Recommend modals read, so the green "already earned" pills mean
     * exactly what they mean everywhere else in the CRM.
     *
     * A failure here is deliberately quiet: the ranks are an ANNOTATION on a
     * control that already works. Losing them costs the officer the green
     * watermark, not the ability to pick a rank, so a dead fetch must not put an
     * error banner on a page whose preview is fine.
     */
    function loadHeldRanks(pid) {
        heldRanks = {};
        paintRankPills();
        if (rankLegend) { rankLegend.hidden = true; }
        if (!pid) { return; }

        fetch(cfg.uir + 'PlayerAjax/player/' + encodeURIComponent(pid) + '/awardranks')
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (ranks) {
                if (!ranks) { return; }
                heldRanks = ranks;
                if (rankLegend) { rankLegend.hidden = false; }
                // The officer has not chosen yet, so open on the rank they hold —
                // that is the certificate being printed. syncRankAndVariant() owns
                // that decision; re-running it keeps the rule in one place.
                if (!rankTouched) { syncRankAndVariant(); reload(); } else { paintRankPills(); }
            })
            .catch(function () { /* annotation only — see above */ });
    }

    if (pillsWrap) {
        pillsWrap.addEventListener('click', function (e) {
            var pill = e.target.closest('.sc-rank-pill');
            if (!pill) { return; }

            var opt = selected(),
                min = opt ? (parseInt(opt.dataset.min, 10) || 0) : 0,
                r   = parseInt(pill.dataset.rank, 10);

            // Click the live pill again to clear — where the order permits rank 0.
            if (r === (parseInt(rankEl.value, 10) || 0)) {
                if (min < 1) { setRank(0, true); }
                return;
            }
            setRank(r, true);
        });
    }

    /* ---- rank + variant options follow the chosen order ----------------- */

    function syncRankAndVariant() {
        var opt = selected();
        if (!opt) { return; }

        var min = parseInt(opt.dataset.min, 10) || 0,
            max = parseInt(opt.dataset.max, 10) || 10,
            keepRank = parseInt(rankEl.value, 10);

        buildRankPills(min, max);

        // Hold the rank across an order change where it is still legal — warrior
        // starts at 1, so a rank of 0 cannot simply carry over.
        rankEl.value = (keepRank >= min && keepRank <= max) ? keepRank : min;
        // Until the officer picks one by hand, the certificate being printed is
        // almost always for the rank the recipient now HOLDS — so once we know it,
        // open on it rather than on the bottom of the range.
        if (!rankTouched) {
            var held = heldForCurrentAward();
            if (held >= min && held <= max) { rankEl.value = held; }
        }
        paintRankPills();

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

    /* ---- the typed name for a custom entry ------------------------------ */

    /*
     * Custom Award (94) and Custom Title (246) are the ORK's internal placeholders,
     * not names: a kingdom that has not renamed the catalogue row leaves it reading
     * literally "Custom Award". Scroll::ResolveTitle() refuses to letter that phrase
     * onto a certificate and returns '' instead, so composing one without typing a
     * name prints a scroll with a BLANK award line. That is the right answer for a
     * reprint of an old grant nobody recorded a name for; it is not an answer for an
     * officer standing at the composer, who can simply say what the award is called.
     *
     * WHICH entries those are is stamped on the option as data-custom-name by
     * Scrollery_create.tpl, from ResolveTitle()'s own answer — deliberately not
     * re-derived here, and deliberately not keyed on the award id alone. Seven
     * kingdomaward rows in kingdom 1 carry award_id 94 under a real name ("Order of
     * the Hellrider", "Order of the Awesome"); those already resolve to their own
     * title, are not stamped, and must never be offered a field that would overwrite
     * it.
     */
    function customKind() {
        var opt = selected();
        return (opt && opt.dataset.customName) || '';
    }

    /*
     * The award and title wordings. Same split the Add Award modal makes between
     * "Custom Award Name" and "Custom Title Name" — the officer is looking at the
     * same two catalogue entries there.
     */
    var CUSTOM_COPY = {
        award: {
            label:       'Award name',
            placeholder: 'e.g. Order of the Silver Fletching',
            hint:        'Printed on the certificate as the name of the award — “Custom Award” is '
                       + 'the ORK’s own placeholder and is never lettered onto a scroll, so left '
                       + 'blank this certificate prints with no award name on it.',
            missing:     'Type the award’s name before printing — the certificate would otherwise '
                       + 'have no award name on it.'
        },
        title: {
            label:       'Title name',
            placeholder: 'e.g. Warden of the Northern Marches',
            hint:        'Printed on the certificate as the name of the title — “Custom Title” is '
                       + 'the ORK’s own placeholder and is never lettered onto a scroll, so left '
                       + 'blank this certificate prints with no title on it.',
            missing:     'Type the title’s name before printing — the certificate would otherwise '
                       + 'have no title on it.'
        }
    };

    /*
     * Shown only for those two, hidden otherwise — the same display idiom the variant
     * field beside it uses, rather than .is-inert. .is-inert is for a control that is
     * still TRUE but reaches nothing (the palette on a raster border); this one is not
     * a control at all for any other award, so fading it would leave a field on the
     * page that has no meaning.
     *
     * The typed value survives a switch away and back on purpose: an officer who taps
     * a ladder to look at its art has not withdrawn the name they typed.
     */
    function syncCustomName() {
        if (!customFd) { return; }

        var kind = customKind();
        customFd.style.display = kind ? '' : 'none';
        if (!customEl) { return; }

        customEl.required = !!kind;
        if (!kind) {
            clearCustomNameError();
            return;
        }

        var copy = CUSTOM_COPY[kind] || CUSTOM_COPY.award;
        if (customLabel) { customLabel.textContent = copy.label; }
        if (customHint)  { customHint.textContent  = copy.hint; }
        customEl.placeholder = copy.placeholder;
    }

    function customName() {
        return customKind() && customEl ? customEl.value.trim() : '';
    }

    /*
     * The "With phrase" box — the interstitial line between the recipient's name and
     * the title, offered only for the two custom entries.
     *
     * Shown with the same display idiom as the award-name field above it. The default
     * differs by kind because the entries are different parts of speech, and both
     * defaults are READ from the input's data- attributes, which the template stamped
     * from Scroll::DEFAULT_AWARD_PHRASE / DEFAULT_TITLE_PHRASE. Restating either
     * wording here would be a second opinion that drifts the first time one is reworded.
     *
     * withTouched follows the rankTouched idiom already in this file: switching between
     * Custom Award and Custom Title re-defaults the box, because the right wording
     * genuinely differs — but only until the officer types, after which their words are
     * theirs and no award change overwrites them.
     */
    var withTouched = false;

    function withPhraseDefault(kind) {
        if (!withEl) { return ''; }
        return withEl.dataset[kind === 'title' ? 'defaultTitle' : 'defaultAward'] || '';
    }

    function syncWithPhrase() {
        if (!withFd) { return; }

        var kind = customKind();
        withFd.style.display = kind ? '' : 'none';
        if (!withEl || !kind) { return; }

        if (!withTouched) { withEl.value = withPhraseDefault(kind); }
    }

    /*
     * Returned as null — not '' — when this award takes no phrase at all, so the caller
     * can OMIT the parameter rather than send an empty one. The server distinguishes
     * absent (use the default) from present-but-empty (print no line), and collapsing
     * the two here would make it impossible to clear the phrase.
     */
    function withPhrase() {
        return customKind() && withEl ? withEl.value.trim() : null;
    }

    function clearCustomNameError() {
        if (customEl) { customEl.removeAttribute('aria-invalid'); }
    }

    /*
     * What the certificate's order line will actually say.
     *
     * For a stamped entry that is the typed name and NOTHING ELSE — empty when the
     * field is empty, which is exactly what Scroll::ResolveTitle() returns for those
     * rows. Falling back to the option's own label would put "Custom Award" in the
     * preview and the summary while the server drew a blank line: the preview lying
     * about what prints is the defect this whole page exists to avoid.
     */
    function printedTitle() {
        if (customKind()) { return customName(); }
        var opt = selected();
        return opt ? (opt.dataset.name || '') : '';
    }

    /* ---- the custom scroll's design controls ---------------------------- */

    /*
     * Only the custom order composes its own art; every other order's layers are
     * fixed by its partial, so showing these anywhere else would be an inert lie.
     */
    function syncRecipeVisibility() {
        if (!recipeEl) { return; }
        var opt = selected();
        recipeEl.style.display = (opt && opt.dataset.order === 'custom') ? '' : 'none';
    }

    /*
     * The explanations are authored server-side, on the options themselves, out of
     * Scroll's own DARK_GROUNDS / GROUND_WASHES / BORDER_BAND constants — this only
     * joins whichever of them apply. Nothing here knows a ground is dark or a
     * border is a band, which is exactly the point: the renderer already decides
     * that, and a second opinion in the picker is how the two drift apart.
     */
    /*
     * Does the CHOSEN border actually draw the parametric knotwork the border-colour
     * pair recolours?
     *
     * The answer is stamped on the option as data-knot by Scrollery_create.tpl, out
     * of Scroll's own vocabulary and BORDER_BAND constant — deliberately NOT
     * re-derived here. Eight of the ten borders are raster frames that consume
     * neither --primary-color nor --secondary-color, and a JS-side list of which
     * ones would be a second opinion that drifts the moment a border is added.
     *
     * Unknown (an older cached template with no data-knot) reads as TRUE: the
     * control then behaves exactly as it did before, which is the safe direction —
     * a live control that does nothing beats an inert control that would have.
     */
    function borderDrawsKnot() {
        var b = borderEl && borderEl.options[borderEl.selectedIndex];
        return !b || b.dataset.knot === undefined || b.dataset.knot === '1';
    }

    function syncRecipeNote() {
        if (!noteEl) { return; }

        var notes = [],
            rank  = parseInt(rankEl.value, 10) || 0,
            g     = groundEl && groundEl.options[groundEl.selectedIndex],
            b     = borderEl && borderEl.options[borderEl.selectedIndex];

        if (g && g.dataset.note) { notes.push(g.dataset.note); }
        if (b && b.dataset.note) { notes.push(b.dataset.note); }

        // custom.tpl draws no numeral below rank 1, for every set — so at rank 0 the
        // numeral control genuinely does nothing, and saying so beats letting an
        // officer change it and see no difference.
        if (rankSetFd) { rankSetFd.classList.toggle('is-inert', rank < 1); }
        if (rank < 1) { notes.push('No numeral is drawn on an unranked scroll, so the numeral set has no effect.'); }

        // Same treatment for the palette: scroll/_border.tpl is the only consumer of
        // the colour pair and it renders only for the knot and the band, so on the
        // eight raster frames the officer was changing a colour, waiting for a full
        // server round trip, and seeing no difference — which reads as a broken page
        // rather than as a constraint.
        /*
         * Five of the twelve grounds — flame, linen, jovious, sunburst, map — are
         * lifted from orders that draw no border layer, because the frame is painted
         * into the plate. Any border over one of those is a SECOND border on top of
         * the first, which is what a flame ground with a knot border looked like.
         *
         * NormaliseRecipe() already forces 'none' server-side, so the certificate is
         * correct either way; this is the half that stops the officer wondering why
         * the control did nothing. Read from the option's stamp, never from a copy of
         * the list — the template stamped it from Scroll::GroundDrawsOwnBorder().
         */
        var ownBorder = !!(g && g.dataset.ownBorder === '1');
        if (borderFd)  { borderFd.classList.toggle('is-inert', ownBorder); }
        if (borderEl)  { borderEl.setAttribute('aria-disabled', ownBorder ? 'true' : 'false'); }
        if (ownBorder) {
            notes.push('The ' + (g ? g.textContent.trim() : 'chosen') + ' background already has its '
                     + 'own border drawn into it, so no separate border is added — a second one '
                     + 'would sit on top of the first.');
        }

        // The palette reaches the border, so a suppressed border makes it inert too.
        var knot = !ownBorder && borderDrawsKnot();
        if (paletteFd) { paletteFd.classList.toggle('is-inert', !knot); }
        // The swatch is the loudest part of the lie — two colours painted beside a
        // control that reaches nothing — so it comes off entirely rather than fading.
        if (swatchEl)  { swatchEl.hidden = !knot; }
        // .is-inert is pointer-events only, which is invisible to a screen reader;
        // aria-disabled is the same fact said in the other channel.
        if (paletteEl) { paletteEl.setAttribute('aria-disabled', knot ? 'false' : 'true'); }
        if (!knot && !ownBorder) {
            notes.push('The ' + (b ? b.textContent.trim() : 'chosen') + ' border is a fixed '
                     + 'illustration, so the border colours have no effect on it. Choose Knotwork '
                     + 'or Battle to colour the border.');
        }

        noteEl.textContent = notes.join(' ');
    }

    /*
     * The composer's only non-visual channel.
     *
     * The preview is an <svg> that reload() replaces wholesale with server markup;
     * nothing about it is readable, and its busy state was an opacity. This is the
     * same certificate said in a sentence — "Linen ground, knotwork border in Crown
     * gold, common numerals, rank 3" — into a visually-hidden aria-live region.
     *
     * Every phrase is the OPTION LABEL the officer is looking at, read off the same
     * <select> they chose it from. Nothing is re-derived, so the sentence cannot
     * describe a certificate other than the one drawn; and the parts that are inert
     * (the palette on a raster border, the numeral at rank 0) are left out, because
     * naming a value that reaches nothing is the same lie in a different channel.
     */
    function optionLabel(sel) {
        var o = sel && sel.options[sel.selectedIndex];
        return o ? o.textContent.trim() : '';
    }

    function syncSummary() {
        if (!summaryEl) { return; }

        var opt   = selected(),
            rank  = parseInt(rankEl.value, 10) || 0,
            parts = [];

        // printedTitle(), not the option's own label: for a stamped entry the label is
        // the placeholder "Custom Award", which is not what gets drawn — the typed name
        // is, or a blank. Saying the placeholder here would describe a certificate
        // other than the one on the stage, which is the one thing this sentence must
        // never do.
        if (!opt) {
            parts.push('No award');
        } else {
            parts.push(printedTitle() || (customKind()
                ? 'no award name yet — a blank line will print'
                : opt.textContent.trim()));
        }

        // The design controls exist only for the custom order; for every other one
        // the art is fixed by its own partial and there is nothing to describe.
        if (recipeEl && recipeEl.style.display !== 'none') {
            parts.push(optionLabel(groundEl) + ' ground');

            var border = optionLabel(borderEl);
            parts.push(borderDrawsKnot()
                ? border + ' border in ' + optionLabel(paletteEl)
                : border + ' border (a fixed illustration, so the border colours do not apply)');

            if (rank >= 1) { parts.push(optionLabel(rankSetEl) + ' numerals'); }
        }

        parts.push(rank >= 1 ? 'rank ' + rank : 'unranked, with a blank line for the rank');

        var v = variantEl && variantEl.value;
        if (v && variantFd && variantFd.style.display !== 'none') { parts.push(v + ' variant'); }

        summaryEl.textContent = parts.join(', ') + '.';
    }

    /* The chosen pair, shown beside its name — the colours are the point of the slot. */
    function syncSwatch() {
        if (!swatchEl || !paletteEl) { return; }
        var pair = (paletteEl.value || '|').split('|'),
            legs = swatchEl.children;
        if (legs.length === 2) {
            legs[0].style.background = pair[0] || 'transparent';
            legs[1].style.background = pair[1] || 'transparent';
        }
    }

    /*
     * The recipient's possessive pronoun. Sent EMPTY when untouched, which is what
     * tells the server to infer it from the chosen player's profile — a reprint of
     * the same award reaches the same value that way. Typed, it wins outright, and
     * it is per-print: nothing is written back to the profile. It re-fetches rather
     * than patching the DOM because the pronoun sits inside a sentence the text
     * layer assembles server-side.
     */
    function pronounOverride() {
        return pronounEl ? pronounEl.value.trim() : '';
    }

    /* Every recipe field the server needs, on whichever body/query object is going out. */
    function addRecipe(params) {
        if (!groundEl) { return; }
        var pair = (paletteEl.value || '|').split('|');
        params.set('ground',    groundEl.value);
        params.set('border',    borderEl.value);
        params.set('primary',   pair[0] || '');
        params.set('secondary', pair[1] || '');
        params.set('rankSet',   rankSetEl.value);
    }

    /* ---- the round trip: order / rank / variant ------------------------- */

    /*
     * is-busy is an opacity, which says nothing to anyone not looking at it.
     * aria-busy is the same fact in the other channel, and it also tells AT to hold
     * off announcing a region that is mid-replacement.
     */
    function setBusy(on) {
        if (!stage) { return; }
        stage.classList.toggle('is-busy', !!on);
        stage.setAttribute('aria-busy', on ? 'true' : 'false');
    }

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
        // Sent on every order, not only custom: Scroll::BuildScroll() ignores the
        // recipe for anything else, so there is nothing to branch on here.
        addRecipe(body);
        body.set('pronounOverride', pronounOverride());
        // Sent raw and on every order, exactly as the recipe is: BuildScroll() is the
        // one place that decides whether this award may be renamed at all, and it
        // ignores the key for anything that is not a custom entry.
        body.set('customName', customName());
        var wp = withPhrase();
        if (wp !== null) { body.set('titlePhrase', wp); }

        if (inFlight) { inFlight.abort(); }
        var ctl = new AbortController();
        inFlight = ctl;
        setBusy(true);

        fetch(cfg.uir + 'ScrolleryAjax/preview', {
            method: 'POST',
            body: body,
            signal: ctl.signal,
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                setBusy(false);
                if (!d || !d.Status || d.Status.Status !== 0) {
                    errEl.textContent = (d && d.Status && d.Status.Error) || 'Preview failed.';
                    return;
                }
                errEl.textContent = '';
                mount.innerHTML = d.Html;
                applyText();
                applyHeraldry();
                // applyText() already asked for a fit; this is the one that matters for
                // markup the server just sent, whose sizes are its first guess for an
                // EMPTY recipient. Both go through the same rAF-coalesced call, so the
                // pair costs one pass, not two.
                fitPreview();
                // The stage has just been replaced wholesale with new markup and said
                // nothing about it. This is the announcement.
                syncSummary();
            })
            .catch(function (e) {
                if (e.name === 'AbortError') { return; }
                setBusy(false);
                errEl.textContent = 'Preview failed.';
            });
    }

    /* ---- in-place updates: no round trip ------------------------------- */

    function field(name) { return mount.querySelector('[data-scroll-text="' + name + '"]'); }

    /*
     * Re-measure the certificate's type after any DOM write.
     *
     * scroll/_text.tpl sizes the name and the reason server-side from the length of
     * the text it was given — and on this page it was given an EMPTY recipient, so
     * the preview kept the largest bracket no matter what the officer typed. Measured
     * by workstream B on the live composer: a 100-character persona previewed at
     * 103 px and printed at 69 px, because the composer's guess and the print
     * surface's guess were computed from different strings.
     *
     * scrollery-autofit.js re-derives the identical ceiling from the text actually in
     * the box, using the coefficients _text.tpl stamps as data-af-len. fitSoon is
     * coalesced to one animation frame and costs ~1 ms for a single certificate, so
     * this is safe on every keystroke.
     *
     * Guarded on the global rather than the element: the file is loaded by the page
     * above this one, but a cached older copy without the export must degrade to the
     * server's first guess, not throw and take the whole preview down.
     */
    function fitPreview() {
        if (window.ScrolleryAutofit && typeof window.ScrolleryAutofit.fitSoon === 'function') {
            window.ScrolleryAutofit.fitSoon(mount);
        }
    }

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

        /*
         * The interstitial phrase. Only present in the DOM for the two custom entries —
         * _text.tpl renders the span at all only when the phrase is non-empty — so an
         * absent element is the correct no-op rather than a missing case. Clearing the
         * box to empty needs the server, which is what the reload on change is for:
         * removing the line changes the block spacing above the title.
         */
        var phrase = field('titlePhrase');
        if (phrase && withEl && withEl.value.trim() !== '') {
            phrase.textContent = withEl.value.trim();
        }

        var issuer = field('issuerTitle');
        if (issuer) {
            issuer.textContent = 'and'
                + (useTheEl.checked ? ' the' : '')
                + ' ' + titleEl.value.trim()
                + (useOfEl.checked ? ' of' : '');
        }

        var issuerName = field('issuerName');
        if (issuerName) { issuerName.textContent = nameEl.value.trim(); }

        /*
         * The order line, for a custom entry only.
         *
         * Selected as .Order rather than through field(): scroll/_text.tpl carries no
         * data-scroll-text on it (it is the one text block that has never been live),
         * and .Order is the same selector scrollery-autofit.js's own POLICY uses, so
         * the two cannot pick different elements. It is written ONLY when the chosen
         * award is a custom entry — every other award's title is the catalogue's, the
         * server already rendered it, and overwriting it here would be this page
         * holding a second opinion about a string it did not resolve.
         */
        if (customKind()) {
            var order = mount.querySelector('.Order');
            if (order) { order.textContent = printedTitle(); }
        }

        // The name, the reason and the order line are the three blocks autofit
        // measures, and all three are written from strings the server never saw — so
        // every write to them has to be re-measured.
        fitPreview();
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
            loadHeldRanks(item.dataset.id);
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

    awardEl.addEventListener('change', function () {
        // A rank picked for the previous award says nothing about this one, so the
        // hand-picked flag clears and syncRankAndVariant() is free to open on the
        // rank the recipient holds HERE. Where they hold none it still falls back
        // to carrying the old rank over, as it always did.
        rankTouched = false;
        syncRankAndVariant();
        syncCustomName();
        syncWithPhrase();
        syncRecipeVisibility();
        syncRecipeNote();
        reload();
    });
    rankEl.addEventListener('change', function () { syncRecipeNote(); reload(); });
    variantEl.addEventListener('change', reload);

    // The recipe controls change which art is drawn, so unlike the text fields they
    // cost a round trip.
    [groundEl, borderEl, rankSetEl].forEach(function (el) {
        if (!el) { return; }
        el.addEventListener('change', function () { syncSwatch(); syncRecipeNote(); reload(); });
    });

    // The palette is the exception: on a raster border the pair reaches nothing
    // (scroll/_border.tpl is its only consumer and renders only for the knot and the
    // band), so re-rendering the whole certificate to produce an identical page is a
    // round trip spent to show the officer no difference. The control is inert in
    // that state anyway; this is the guard behind it, not instead of it.
    if (paletteEl) {
        paletteEl.addEventListener('change', function () {
            syncSwatch();
            syncRecipeNote();
            if (borderDrawsKnot()) { reload(); } else { syncSummary(); }
        });
    }

    if (pronounEl) {
        // 'change', not 'input': one round trip when the officer is done typing,
        // not one per keystroke.
        pronounEl.addEventListener('change', reload);
    }

    [reasonEl, dateEl, givenEl, titleEl, nameEl].forEach(function (el) {
        el.addEventListener('input', applyText);
    });

    /*
     * Live, like the recipient and the reason beside it — the name is written into the
     * preview's own order line and re-fitted, with no round trip. It is not sent to the
     * server until the next reload() or the print, which is the same deal every other
     * text field on this page gets.
     *
     * syncSummary() as well as applyText(), because this field changes the ONE part of
     * the spoken sentence the other text fields do not: which award is being drawn.
     */
    if (withEl) {
        withEl.addEventListener('input', function () {
            // Their words from here on: no award switch re-defaults the box.
            withTouched = true;
            var had = !!field('titlePhrase');
            // Going to or from empty adds/removes the whole line, which the server
            // renders and which shifts the spacing above the title. Any other edit is
            // a text swap the DOM can do in place.
            if (had === (withEl.value.trim() !== '')) {
                applyText();
                fitPreview();
            } else {
                reload();
            }
        });
    }

    if (customEl) {
        customEl.addEventListener('input', function () {
            if (customEl.value.trim() !== '') {
                clearCustomNameError();
                errEl.textContent = '';
            }
            applyText();
            syncSummary();
        });
    }
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

    // Guarded like every other element in this file. The header button can be absent
    // (a template variant, a permission-gated header), and an unguarded listener here
    // throws before awInitPicker(), syncRankAndVariant() and the first reload() below —
    // taking the whole composer down rather than just the Print button.
    var printBtn = $('sc-print');
    if (printBtn) { printBtn.addEventListener('click', function () {
        // A kingdom whose awards all lack certificate art renders an empty <select>,
        // so there may be no option to read. Guard rather than throw silently.
        var opt = selected();
        if (!opt) { errEl.textContent = 'No printable award is selected.'; return; }

        /*
         * The one field on this page that is REQUIRED, and only while it is showing.
         * #sc-err is already role="alert", so the refusal is announced as well as
         * shown — the page's own validation channel, and never a native alert(),
         * which would block the print window this handler is about to open.
         *
         * compose() refuses the same print server-side; this is what keeps the
         * officer on the page with the cursor in the field instead of sending them
         * to a denial page for something a sentence could have said here.
         */
        var kind = customKind();
        if (kind && customEl && customEl.value.trim() === '') {
            errEl.textContent = (CUSTOM_COPY[kind] || CUSTOM_COPY.award).missing;
            customEl.setAttribute('aria-invalid', 'true');
            customEl.focus();
            return;
        }
        errEl.textContent = '';
        clearCustomNameError();

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
        addRecipe(qs);
        qs.set('pronounOverride', pronounOverride());
        qs.set('customName',  customName());
        var wpq = withPhrase();
        if (wpq !== null) { qs.set('titlePhrase', wpq); }
        qs.set('print',       '1');
        window.open(cfg.uir + 'Scrollery/compose&' + qs.toString(), '_blank', 'noopener');
    }); }

    /* ---- collapsible sidebar sections ----------------------------------- */

    /*
     * The header IS the button, so the whole bar is the hit target rather than just
     * the chevron. aria-expanded on that button is the single source of truth —
     * reports.css rotates the chevron off it and drops the header's bottom rule
     * when collapsed, so nothing here touches a class or a style.
     */
    document.querySelectorAll('[data-sc-collapse]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var body = $(btn.dataset.scCollapse),
                open = btn.getAttribute('aria-expanded') === 'true';
            if (!body) { return; }
            btn.setAttribute('aria-expanded', open ? 'false' : 'true');
            body.hidden = open;
        });
    });

    /*
     * The sectioned, searchable award picker — revised.js's shared awInitPicker(),
     * the same one behind the grant and recommend dropdowns. It hides the <select>
     * and puts a trigger button beside it, reading the optgroups the template
     * emitted; every later `awardEl.value` read still works, because it patches the
     * value setter rather than replacing the element.
     *
     * Guarded on the function, not on the element: revised.js is loaded by the
     * page above this file, but a cached older copy without the export should
     * degrade to a plain <select>, not throw and take the preview down with it.
     */
    if (typeof awInitPicker === 'function') { awInitPicker(awardEl); }

    syncRankAndVariant();
    syncCustomName();
        syncWithPhrase();
    syncRecipeVisibility();
    syncSwatch();
    syncRecipeNote();
    // Filled here as well as on every reload(), so the live region carries a sentence
    // even when the very first preview fetch fails.
    syncSummary();
    applyHeraldry();
    reload();
}());
