/* ============================================================
   ORK3 Survey — Results page controller  (survey-results.js)

   Talks to SurveyAjax/results and SurveyAjax/rows (design spec §6)
   and draws one card per question with the chart the spec's §7
   table prescribes.

   Colour policy (dataviz method):
   - Categorical identity uses a fixed eight-hue order, never cycled,
     assigned in sequence. Both mode palettes were validated with the
     six-check validator (adjacent pairs: worst CVD ΔE 9.1 light /
     8.4 dark, worst normal-vision ΔE 19.6 / 19.3). A ninth series is
     never a generated hue: cross-tab groups past the eighth fold into
     one neutral-grey "Other groups" series (#29).
   - A single-series bar or column is ONE hue — bar length already
     encodes the value, so identity colour is not spent re-encoding it.
   - Matrix columns are ORDINAL (strongly disagree → strongly agree),
     so they take a one-hue blue ramp with monotone lightness, clamped
     to the steps that still clear 2:1 on each surface.
   - NPS is POLARITY, so it takes the documented diverging pair
     (blue ↔ red) with a neutral gray midpoint for passives. The mid
     step is a visible gray rather than the near-surface one because
     these are bar segments, not heatmap cells.
   - Three light-mode hues sit under 3:1 on the light surface, so the
     relief rule applies: every single-series bar/column carries a
     visible direct label, and the row-level table below the charts is
     the table view.

   Privacy (review #4): the server replaces any aggregate or cross-tab
   group of fewer than MIN_CELL responses with {suppressed:true}. This
   file never back-computes one (no "total minus the others"): a
   suppressed question or group is drawn as a statement, not a bar.

   Highcharts: orkui.js inlines Highcharts 3.0.7 and owns the
   window.Highcharts global. Survey_results.tpl loads 11.4.8 inside a
   sandbox that hides that global for the duration of the load and
   republishes the fresh copy as window.SvHighcharts, so this file must
   never touch window.Highcharts - that is still the 3.0.7 build.
   ============================================================ */
(function () {
    'use strict';

    var CFG       = window.SvConfig || {};
    var UIR       = CFG.uir || '';
    var SURVEY_ID = parseInt(CFG.surveyId, 10) || 0;
    var CSRF      = typeof CFG.csrf === 'string' ? CFG.csrf : '';
    var QUESTIONS = Array.isArray(CFG.questions) ? CFG.questions : [];
    /* A shared viewer (sharing-and-credits spec §2, §5) reads charts and
       stats only: no rows table, export, print or response panel — those
       elements are not on the page (Survey_results.tpl), so every listener
       and reload against them must be skipped rather than fail quietly. */
    var SHARED    = (window.SvConfig || {}).access === 'shared';

    if (!SURVEY_ID) { return; }

    var TEXT_TYPES     = { short_text: 1, paragraph: 1 };
    var TEXT_PREVIEW   = 20;    /* responses shown before "Show all" */
    var ROWS_PAGE      = 100;
    var MAX_SERIES     = 8;     /* categorical palette length; groups past it fold (#29) */
    var NARROW_MQ      = '(max-width: 900px)';

    var QNUM = {};              /* question_id → Q-number, shared by cards, table and panel */
    QUESTIONS.forEach(function (q, i) { QNUM[q.question_id] = q.num || (i + 1); });

    /* ---------------------------------------------------------
       Palette (see the header note; values from the validated
       reference palette, not eyeballed)
       --------------------------------------------------------- */

    var SV_COLORS = {
        light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'],
        dark : ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767']
    };

    /* One-hue ordinal ramp, light → dark. Light starts at step 250 and dark
       stops at step 600 so the end nearest each surface still clears 2:1. */
    var SV_RAMP = {
        light: ['#86b6ef', '#6da7ec', '#5598e7', '#3987e5', '#2a78d6', '#256abf', '#1c5cab', '#184f95', '#104281', '#0d366b'],
        dark : ['#cde2fb', '#b7d3f6', '#9ec5f4', '#86b6ef', '#6da7ec', '#5598e7', '#3987e5', '#2a78d6', '#256abf', '#184f95']
    };

    /* Diverging pair + neutral midpoint, for NPS polarity. The midpoint gray
       doubles as the "Other groups" fold colour: both mean "not one of the
       named identities". */
    var SV_DIVERGING = {
        light: { neg: '#e34948', mid: '#a0aec0', pos: '#2a78d6' },
        dark : { neg: '#e66767', mid: '#718096', pos: '#3987e5' }
    };

    /* ---------------------------------------------------------
       Small helpers
       --------------------------------------------------------- */

    function $(id) { return document.getElementById(id); }

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function num(v, dp) {
        if (v === null || v === undefined || v === '') { return '—'; }
        var n = Number(v);
        if (!isFinite(n)) { return '—'; }
        if (dp === undefined) { dp = 0; }
        return n.toFixed(dp).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
    }

    function pct(v) {
        if (v === null || v === undefined) { return '—'; }
        return num(v, 1) + '%';
    }

    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

    /* 2894 → "2,894". */
    function thousands(n) {
        return String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function duration(secs) {
        if (secs === null || secs === undefined || secs === '') { return '—'; }
        var s = Math.max(0, Math.round(Number(secs)));
        if (!isFinite(s)) { return '—'; }
        var m = Math.floor(s / 60);
        var r = s % 60;
        return m + 'm ' + (r < 10 ? '0' : '') + r + 's';
    }

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];

    /* 'Y-m-d' or 'Y-m-d H:i:s' → { date: 'September 10, 2026', time: '10:14 AM' | null }.
       Parsed by hand, never through Date(), so a date-only value cannot slide a
       day in a timezone west of UTC. */
    function humanDate(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(s || ''));
        if (!m) { return { date: String(s || ''), time: null }; }
        var date = MONTHS[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
        var time = null;
        if (m[4] !== undefined) {
            var h = parseInt(m[4], 10);
            time = ((h % 12) || 12) + ':' + m[5] + ' ' + (h < 12 ? 'AM' : 'PM');
        }
        return { date: date, time: time };
    }

    function typeLabel(t) {
        var map = {
            single: 'Single choice', multi: 'Multiple choice', dropdown: 'Dropdown', yesno: 'Yes / No',
            rating: 'Rating', nps: 'Net promoter', matrix: 'Matrix', ranking: 'Ranking', pairwise: 'Pairwise',
            short_text: 'Short text', paragraph: 'Paragraph', number: 'Number', date: 'Date'
        };
        return map[t] || t;
    }

    function questionById(qid) {
        for (var i = 0; i < QUESTIONS.length; i++) {
            if (Number(QUESTIONS[i].question_id) === Number(qid)) { return QUESTIONS[i]; }
        }
        return null;
    }

    /* Sample n evenly spaced steps out of an ordinal ramp. */
    function rampSteps(ramp, n) {
        if (n <= 0) { return []; }
        if (n === 1) { return [ramp[Math.floor(ramp.length / 2)]]; }
        var out = [];
        for (var i = 0; i < n; i++) {
            out.push(ramp[Math.round(i * (ramp.length - 1) / (n - 1))]);
        }
        return out;
    }

    function minCell() {
        var s = state.payload && state.payload.summary;
        return (s && parseInt(s.min_cell, 10)) || 5;
    }

    /* ---------------------------------------------------------
       Theme
       --------------------------------------------------------- */

    /* Set while the browser is producing print output: paper is white whatever
       the screen theme is, and browsers drop the dark card background, so the
       dark chart palette would print near-white labels on white. */
    var printingLight = false;

    function svIsDark() {
        if (printingLight) { return false; }
        var a = document.documentElement.getAttribute('data-theme');
        if (a === 'dark') { return true; }
        if (a === 'light') { return false; }
        return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }

    function svChartTheme() {
        var dk = svIsDark();
        return {
            dark   : dk,
            colors : dk ? SV_COLORS.dark : SV_COLORS.light,
            ramp   : dk ? SV_RAMP.dark : SV_RAMP.light,
            div    : dk ? SV_DIVERGING.dark : SV_DIVERGING.light,
            text   : dk ? '#e2e8f0' : '#2d3748',
            muted  : dk ? '#a0aec0' : '#556270', // 6.2:1 on white, 5.1:1 on the badge fill (AA at 11px)
            grid   : dk ? '#3a4557' : '#e6e6e6',
            line   : dk ? '#4a5568' : '#ccd6eb',
            /* Card background — the 2px gap colour between stacked segments. */
            surface: dk ? '#2d3748' : '#ffffff',
            tipBg  : dk ? '#1a2035' : '#ffffff',
            tipEdge: dk ? '#818cf8' : '#cbd5e0'
        };
    }

    function baseCfg(theme, type) {
        return {
            chart: {
                type           : type,
                backgroundColor: 'transparent',
                style          : { fontFamily: 'inherit' },
                spacing        : [8, 8, 8, 8],
                animation      : false
            },
            title  : { text: null },
            credits: { enabled: false },
            legend : {
                enabled       : false,
                itemStyle     : { color: theme.muted, fontWeight: '600', fontSize: '11px' },
                itemHoverStyle: { color: theme.text },
                /* Option labels are officer-typed text: escape them. */
                labelFormatter: function () { return esc(this.name); }
            },
            tooltip: {
                backgroundColor: theme.tipBg,
                borderColor    : theme.tipEdge,
                borderWidth    : 1,
                shadow         : false,
                style          : { color: theme.text, fontSize: '12px' },
                formatter      : function () {
                    return '<b>' + esc(this.key) + '</b><br/>' + esc(this.series.name) + ': <b>' +
                        num(this.y, 2) + '</b>';
                }
            },
            xAxis: {
                labels    : {
                    style    : { color: theme.muted, fontSize: '11px' },
                    formatter: function () { return esc(this.value); }
                },
                lineColor : theme.line,
                tickColor : theme.line
            },
            yAxis: {
                title        : { text: null },
                gridLineColor: theme.grid,
                lineColor    : theme.line,
                labels       : { style: { color: theme.muted, fontSize: '11px' } }
            },
            plotOptions: {
                series: { animation: false, borderWidth: 0 }
            }
        };
    }

    function labelStyle(theme) {
        return { color: theme.text, textOutline: 'none', fontWeight: '600', fontSize: '11px' };
    }

    /* Perceived luminance of a #rrggbb fill. A label printed INSIDE a stacked
       segment sits on that segment's colour, not on the card, so the theme
       foreground is the wrong choice: in dark mode the ramp's lightest steps
       are near-white and a white label vanishes on them. */
    function onFill(hex) {
        var m = /^#([0-9a-f]{6})$/i.exec(String(hex || ''));
        if (!m) { return null; }
        var n = parseInt(m[1], 16);
        var lum = (0.2126 * ((n >> 16) & 255) + 0.7152 * ((n >> 8) & 255) + 0.0722 * (n & 255)) / 255;
        return lum > 0.55 ? '#1a202c' : '#ffffff';
    }

    /* labelStyle for a label that sits on top of `fill`. */
    function labelStyleOn(theme, fill) {
        var st = labelStyle(theme);
        var c = onFill(fill);
        if (c) { st.color = c; }
        return st;
    }

    /* Horizontal bars get taller with every category (and with every bar per
       category) instead of squeezing labels into ellipses (#33). */
    function barHeight(cats, perCat) {
        var slot = Math.max(30, (perCat || 1) * 12 + 14);
        return Math.max(220, cats * slot + 70);
    }

    /* ---------------------------------------------------------
       Chart specs, one per question type (spec §7 table)
       --------------------------------------------------------- */

    function specChoice(q, theme) {
        var counts = (q.agg && q.agg.counts) || [];
        var cfg = baseCfg(theme, 'bar');
        cfg.chart.height = barHeight(counts.length, 1);
        cfg.xAxis.categories = counts.map(function (c) { return c.label; });
        cfg.yAxis.allowDecimals = false;
        cfg.yAxis.min = 0;
        cfg.yAxis.title = { text: 'Responses', style: { color: theme.muted, fontSize: '11px' } };
        cfg.plotOptions.bar = {
            borderRadius: 4,
            pointPadding: 0.08,
            groupPadding: 0.12,
            dataLabels  : {
                enabled  : true,
                style    : labelStyle(theme),
                formatter: function () {
                    var p = counts[this.point.index];
                    return this.y + ' (' + (p && p.pct !== null ? num(p.pct, 1) : '0') + '%)';
                }
            }
        };
        cfg.tooltip.formatter = function () {
            var p = counts[this.point.index] || {};
            return '<b>' + esc(p.label) + '</b><br/>' + this.y + ' of ' + (q.n || 0) +
                ' who answered (' + num(p.pct, 1) + '%)';
        };
        cfg.series = [{
            name       : 'Responses',
            color      : theme.colors[0],
            data       : counts.map(function (c) { return c.count; }),
            /* One series: bar length carries the value, so one hue only. */
            colorByPoint: false
        }];
        return cfg;
    }

    function specRating(q, theme) {
        var dist = (q.agg && q.agg.distribution) || [];
        var cfg = baseCfg(theme, 'column');
        cfg.xAxis.categories = dist.map(function (d) { return String(d.value); });
        cfg.xAxis.title = { text: 'Rating', style: { color: theme.muted, fontSize: '11px' } };
        cfg.yAxis.allowDecimals = false;
        cfg.yAxis.min = 0;
        cfg.plotOptions.column = {
            borderRadius: 4,
            dataLabels  : { enabled: true, style: labelStyle(theme) }
        };
        cfg.tooltip.formatter = function () {
            return '<b>Rating ' + esc(this.key) + '</b><br/>' + plural(this.y, 'response', 'responses');
        };
        cfg.series = [{
            name : 'Responses',
            color: theme.colors[0],
            data : dist.map(function (d) { return d.count; })
        }];
        return cfg;
    }

    function specNps(q, theme) {
        var a = q.agg || {};
        var n = a.n || 0;
        var cfg = baseCfg(theme, 'bar');
        cfg.chart.height = 150;
        cfg.xAxis.categories = ['Responses'];
        cfg.xAxis.visible = false;
        cfg.yAxis.min = 0;
        cfg.yAxis.max = n || 1;
        cfg.yAxis.visible = false;
        cfg.legend.enabled = true;
        cfg.legend.reversed = true;
        cfg.plotOptions.series.stacking = 'normal';
        cfg.plotOptions.bar = {
            /* 2px surface gap between stacked segments. */
            borderWidth: 2,
            borderColor: theme.surface,
            dataLabels : {
                enabled  : true,
                style    : labelStyle(theme),
                formatter: function () {
                    if (!this.y) { return null; }
                    return this.y + ' (' + num(this.y / (n || 1) * 100, 0) + '%)';
                }
            }
        };
        cfg.tooltip.formatter = function () {
            return '<b>' + esc(this.series.name) + '</b><br/>' + this.y + ' of ' + n +
                ' (' + num(this.y / (n || 1) * 100, 1) + '%)';
        };
        cfg.series = [
            { name: 'Detractors (0–6)', color: theme.div.neg, data: [a.detractors || 0],
                dataLabels: { style: labelStyleOn(theme, theme.div.neg) } },
            { name: 'Passives (7–8)', color: theme.div.mid, data: [a.passives || 0],
                dataLabels: { style: labelStyleOn(theme, theme.div.mid) } },
            { name: 'Promoters (9–10)', color: theme.div.pos, data: [a.promoters || 0],
                dataLabels: { style: labelStyleOn(theme, theme.div.pos) } }
        ];
        return cfg;
    }

    function specMatrix(q, theme) {
        var a = q.agg || {};
        var cols = a.columns || [];
        var rows = a.rows || [];
        var colors = rampSteps(theme.ramp, cols.length);

        var cfg = baseCfg(theme, 'bar');
        cfg.chart.height = Math.max(300, barHeight(rows.length, 2) + 40);
        cfg.xAxis.categories = rows.map(function (r) { return r.label; });
        cfg.yAxis.min = 0;
        cfg.yAxis.max = 100;
        cfg.yAxis.labels.format = '{value}%';
        cfg.legend.enabled = cols.length > 1;
        cfg.plotOptions.series.stacking = 'percent';
        cfg.plotOptions.bar = {
            borderWidth: 2,
            borderColor: theme.surface,
            dataLabels : {
                enabled  : true,
                style    : labelStyle(theme),
                formatter: function () {
                    var p = this.percentage || 0;
                    /* Only label a segment wide enough to hold the number. */
                    return p >= 10 ? Math.round(p) + '%' : null;
                }
            }
        };
        cfg.tooltip.formatter = function () {
            var p = this.percentage || 0;
            return '<b>' + esc(this.key) + '</b><br/>' + esc(this.series.name) + ': <b>' +
                this.y + '</b> (' + num(p, 1) + '%)';
        };
        cfg.series = cols.map(function (c, i) {
            return {
                name      : c.label,
                color     : colors[i],
                dataLabels: { style: labelStyleOn(theme, colors[i]) },
                data : rows.map(function (r) {
                    var cell = 0;
                    (r.counts || []).forEach(function (x) {
                        if (Number(x.option_id) === Number(c.option_id)) { cell = x.count; }
                    });
                    return cell;
                })
            };
        });
        return cfg;
    }

    function specRanking(q, theme) {
        var opts = ((q.agg && q.agg.options) || []).slice();
        /* Lower mean rank is better — sort the best to the top. */
        opts.sort(function (a, b) {
            var av = a.mean_rank === null ? Infinity : a.mean_rank;
            var bv = b.mean_rank === null ? Infinity : b.mean_rank;
            return av - bv;
        });

        var cfg = baseCfg(theme, 'bar');
        cfg.chart.height = barHeight(opts.length, 1);
        cfg.xAxis.categories = opts.map(function (o) { return o.label; });
        cfg.yAxis.min = 0;
        cfg.yAxis.reversed = false;
        cfg.yAxis.title = { text: 'Mean rank (lower is better)', style: { color: theme.muted, fontSize: '11px' } };
        cfg.plotOptions.bar = {
            borderRadius: 4,
            dataLabels  : {
                enabled  : true,
                style    : labelStyle(theme),
                formatter: function () { return this.y === null ? null : num(this.y, 2); }
            }
        };
        cfg.tooltip.formatter = function () {
            var o = opts[this.point.index] || {};
            return '<b>' + esc(o.label) + '</b><br/>Mean rank: <b>' + num(o.mean_rank, 2) + '</b><br/>' +
                'Ranked first: <b>' + (o.first_count || 0) + '</b><br/>Borda score: <b>' + (o.score || 0) + '</b>';
        };
        cfg.series = [{
            name : 'Mean rank',
            color: theme.colors[0],
            /* An option nobody ranked has no mean — null, not a drawn 0. */
            data : opts.map(function (o) { return o.mean_rank === null ? null : o.mean_rank; })
        }];
        return cfg;
    }

    /* Bradley-Terry strength in rank order (#35): the server already sorted
       the options; unranked ones (too few matchups, never matched) come last
       with a null bar, their win % still in the tooltip. */
    function specPairwise(q, theme) {
        var opts = ((q.agg && q.agg.options) || []).slice();
        var cfg = baseCfg(theme, 'bar');
        cfg.chart.height = barHeight(opts.length, 1);
        cfg.xAxis.categories = opts.map(function (o) { return o.label; });
        cfg.yAxis.min = 0;
        cfg.yAxis.max = 100;
        cfg.yAxis.labels.format = '{value}%';
        cfg.yAxis.title = { text: 'Strength: chance of beating a typical option', style: { color: theme.muted, fontSize: '11px' } };
        cfg.plotOptions.bar = {
            borderRadius: 4,
            dataLabels  : {
                enabled  : true,
                style    : labelStyle(theme),
                formatter: function () { return this.y === null ? null : num(this.y, 1) + '%'; }
            }
        };
        cfg.tooltip.formatter = function () {
            var o = opts[this.point.index] || {};
            var ranked = o.rank !== null && o.rank !== undefined;
            return '<b>' + esc(o.label) + '</b><br/>' +
                (ranked ? 'Strength: <b>' + num(o.strength, 1) + '%</b><br/>' : (o.too_few ? '<i>Too few matchups to rank</i><br/>' : '')) +
                'Win % (a tie counts half): <b>' + (o.win_pct === null || o.win_pct === undefined ? '—' : num(o.win_pct, 1) + '%') + '</b><br/>' +
                'Won ' + (o.wins || 0) + ' · Tied ' + (o.ties || 0) + ' · Lost ' + (o.losses || 0) + '<br/>' +
                'Matchups: <b>' + (o.appearances || 0) + '</b>';
        };
        cfg.series = [{
            name : 'Strength',
            color: theme.colors[0],
            data : opts.map(function (o) {
                return (o.rank === null || o.rank === undefined || o.strength === null || o.strength === undefined) ? null : o.strength;
            })
        }];
        return cfg;
    }

    /* Number answers (#30). mode 'values': whole numbers with few distinct
       values, one column per value, with the integer gaps between them filled
       so the axis reads as a number line. mode 'bins': a histogram whose labels
       the server already built (integer edges for whole numbers). */
    function specNumber(q, theme) {
        var a = q.agg || {};
        var cats = [];
        var data = [];
        var byValue = a.mode === 'values';
        if (byValue) {
            var vals = a.values || [];
            var lo = vals.length ? vals[0].value : 0;
            var hi = vals.length ? vals[vals.length - 1].value : 0;
            if (vals.length && hi - lo + 1 <= 40) {
                var map = {};
                vals.forEach(function (v) { map[v.value] = v.count; });
                for (var v = lo; v <= hi; v++) { cats.push(String(v)); data.push(map[v] || 0); }
            } else {
                vals.forEach(function (x) { cats.push(String(x.value)); data.push(x.count); });
            }
        } else {
            (a.bins || []).forEach(function (b) {
                cats.push(b.label !== undefined ? String(b.label) : (num(b.from, 2) + '–' + num(b.to, 2)));
                data.push(b.count);
            });
        }
        var cfg = baseCfg(theme, 'column');
        cfg.xAxis.categories = cats;
        cfg.xAxis.title = { text: byValue ? 'Answer' : 'Answer range', style: { color: theme.muted, fontSize: '11px' } };
        if (cats.length > 12) { cfg.xAxis.labels.rotation = -45; }
        cfg.yAxis.allowDecimals = false;
        cfg.yAxis.min = 0;
        cfg.yAxis.title = { text: 'Responses', style: { color: theme.muted, fontSize: '11px' } };
        cfg.plotOptions.column = {
            borderRadius: 4,
            pointPadding: 0.04,
            groupPadding: 0.06,
            dataLabels  : {
                enabled  : cats.length <= 24,
                style    : labelStyle(theme),
                formatter: function () { return this.y ? this.y : null; }
            }
        };
        cfg.tooltip.formatter = function () {
            return '<b>' + esc(this.key) + '</b><br/>' + plural(this.y, 'response', 'responses');
        };
        cfg.series = [{ name: 'Responses', color: theme.colors[0], data: data }];
        return cfg;
    }

    /* Date answers (#30): one column per period, empty periods included by the
       server, grouped by year when the answers span more than about 3 years.
       Columns, not a line: these are counts per bucket, not a trend. */
    function specDate(q, theme) {
        var a = q.agg || {};
        var periods = a.periods || [];
        var byYear = a.granularity === 'year';
        var cfg = baseCfg(theme, 'column');
        cfg.xAxis.categories = periods.map(function (p) { return p.label; });
        cfg.xAxis.title = { text: byYear ? 'Year' : 'Month', style: { color: theme.muted, fontSize: '11px' } };
        if (periods.length > 12) { cfg.xAxis.labels.rotation = -45; }
        cfg.yAxis.allowDecimals = false;
        cfg.yAxis.min = 0;
        cfg.yAxis.title = { text: 'Answers', style: { color: theme.muted, fontSize: '11px' } };
        cfg.plotOptions.column = {
            borderRadius: 3,
            pointPadding: 0.04,
            groupPadding: 0.04,
            dataLabels  : {
                enabled  : periods.length <= 16,
                style    : labelStyle(theme),
                formatter: function () { return this.y ? this.y : null; }
            }
        };
        cfg.tooltip.formatter = function () {
            return '<b>' + esc(this.key) + '</b><br/>' + plural(this.y, 'answer', 'answers');
        };
        cfg.series = [{ name: 'Answers', color: theme.colors[0], data: periods.map(function (p) { return p.count; }) }];
        return cfg;
    }

    /* ---------------------------------------------------------
       Cross-tab (#29)
       --------------------------------------------------------- */

    /* Sort the server's groups (every option of the cross-tab question, in
       option order) into what can be drawn. Colour slots go to the groups that
       have answers, in option order; past the eighth they fold into one grey
       "Other groups" series. Suppressed and empty groups take no slot — they
       are named in the card note instead of drawn as a fake 0. */
    function crosstabPlan(q) {
        var groups = (q.crosstab && q.crosstab.groups) || [];
        var drawn = [];
        var folded = [];
        var suppressed = [];
        var empty = [];
        groups.forEach(function (g) {
            if (g.suppressed) { suppressed.push(g); return; }
            if (!g.n) { empty.push(g); return; }
            if (drawn.length < MAX_SERIES) { drawn.push(g); } else { folded.push(g); }
        });
        return { groups: groups, drawn: drawn, folded: folded, suppressed: suppressed, empty: empty };
    }

    function groupLabel(g) {
        if (g.suppressed) { return g.label + ' (fewer than ' + minCell() + ')'; }
        if (!g.n) { return g.label + ' (no responses)'; }
        return g.label + ' (n=' + g.n + ')';
    }

    function crosstabNote(q) {
        var plan = crosstabPlan(q);
        var bits = [];
        var names = function (list) { return list.map(function (g) { return esc(g.label); }).join(', '); };
        if (plan.folded.length && q.type !== 'rating' && q.type !== 'nps') {
            bits.push('Groups past the first ' + MAX_SERIES + ' are combined into <strong>Other groups</strong>: ' +
                names(plan.folded) + '.');
        }
        if (plan.suppressed.length) {
            bits.push('Too few responses to show (fewer than ' + minCell() + '): ' + names(plan.suppressed) + '.');
        }
        if (plan.empty.length) {
            bits.push('No responses: ' + names(plan.empty) + '.');
        }
        return bits.length ? '<p class="svr-card-note">' + bits.join(' ') + '</p>' : '';
    }

    /* Rating / NPS: one comparable figure per group rather than an unreadable
       pile of distributions — mean for rating, NPS score for nps. Every group is
       a category; empty and suppressed groups stay on the axis with a greyed
       label and a null (undrawn) value, never a plotted 0. */
    function specCrosstabScore(q, theme) {
        var t = q.type;
        var groups = (q.crosstab && q.crosstab.groups) || [];
        var cfg = baseCfg(theme, 'bar');
        cfg.chart.height = barHeight(groups.length, 1);
        cfg.xAxis.categories = groups.map(groupLabel);
        cfg.xAxis.labels.formatter = function () {
            var g = groups[this.pos] || {};
            var txt = esc(this.value);
            /* Answered groups in the text ink, empty and suppressed ones greyed
               and italic. Colour rides on the span (a tspan fill), which the
               global dark-mode `.highcharts-axis-labels text` rule cannot reach. */
            return (g.suppressed || !g.n)
                ? '<span style="color:' + theme.muted + ';font-style:italic">' + txt + '</span>'
                : '<span style="color:' + theme.text + '">' + txt + '</span>';
        };
        cfg.yAxis.title = {
            text : t === 'nps' ? 'NPS score' : 'Mean rating',
            style: { color: theme.muted, fontSize: '11px' }
        };
        if (t === 'nps') { cfg.yAxis.min = -100; cfg.yAxis.max = 100; } else { cfg.yAxis.min = 0; }
        cfg.plotOptions.bar = {
            borderRadius: 4,
            dataLabels  : {
                enabled  : true,
                style    : labelStyle(theme),
                formatter: function () { return this.y === null ? null : num(this.y, 2); }
            }
        };
        cfg.tooltip.formatter = function () {
            var g = groups[this.point.index] || {};
            return '<b>' + esc(g.label) + '</b><br/>' +
                (t === 'nps' ? 'Score' : 'Mean') + ': <b>' + num(this.y, 2) + '</b><br/>n = ' + (g.n || 0);
        };
        cfg.series = [{
            name : t === 'nps' ? 'NPS score' : 'Mean rating',
            color: theme.colors[0],
            data : groups.map(function (g) {
                if (g.suppressed || !g.n) { return null; }
                var a = g.agg || {};
                var v = t === 'nps' ? a.score : a.mean;
                return v === null || v === undefined ? null : Number(v);
            })
        }];
        return cfg;
    }

    /* Choice types: one series per group, categories are the answer options.
       Default view is PERCENT WITHIN GROUP as grouped bars, so a group of 7 and
       a group of 30 compare on the same scale; the Count view stacks raw
       counts. */
    function specCrosstabChoice(q, theme) {
        var plan = crosstabPlan(q);
        var mode = state.xtMode[q.question_id] || 'pct';
        var counts = (q.agg && q.agg.counts) || [];
        var series = [];

        var seriesFor = function (g) {
            var byId = {};
            ((g.agg && g.agg.counts) || []).forEach(function (c) { byId[c.option_id] = c; });
            return counts.map(function (c) {
                var x = byId[c.option_id];
                if (!x) { return null; }
                return mode === 'pct' ? (x.pct === null ? null : Number(x.pct)) : x.count;
            });
        };

        /* Stacked (count) labels sit INSIDE their segment and take a colour
           that contrasts with its fill; grouped (percent) labels sit on the
           card and keep the theme ink. */
        var withLabel = function (opts, fill) {
            if (mode === 'count') { opts.dataLabels = { style: labelStyleOn(theme, fill) }; }
            return opts;
        };

        plan.drawn.forEach(function (g, i) {
            series.push(withLabel({
                name : groupLabel(g),
                color: theme.colors[i],
                data : seriesFor(g),
                svrN : g.n
            }, theme.colors[i]));
        });

        if (plan.folded.length) {
            var foldN = 0;
            var foldCounts = {};
            plan.folded.forEach(function (g) {
                foldN += g.n;
                ((g.agg && g.agg.counts) || []).forEach(function (c) {
                    foldCounts[c.option_id] = (foldCounts[c.option_id] || 0) + c.count;
                });
            });
            series.push(withLabel({
                name : 'Other groups (n=' + foldN + ')',
                color: theme.div.mid,
                data : counts.map(function (c) {
                    var k = foldCounts[c.option_id] || 0;
                    return mode === 'pct' ? (foldN ? Math.round(k / foldN * 1000) / 10 : null) : k;
                }),
                svrN : foldN
            }, theme.div.mid));
        }

        var cfg = baseCfg(theme, 'bar');
        cfg.chart.height = barHeight(counts.length, mode === 'pct' ? series.length : 1) + 30;
        cfg.xAxis.categories = counts.map(function (c) { return c.label; });
        cfg.yAxis.min = 0;
        cfg.legend.enabled = series.length > 1;
        if (mode === 'pct') {
            cfg.yAxis.max = 100;
            cfg.yAxis.labels.format = '{value}%';
            cfg.yAxis.title = { text: '% of each group', style: { color: theme.muted, fontSize: '11px' } };
            cfg.plotOptions.bar = {
                borderRadius: 3,
                pointPadding: 0.04,
                groupPadding: 0.1,
                dataLabels  : {
                    /* Selective labels only: one number per bar is noise past two groups. */
                    enabled  : series.length <= 2,
                    style    : labelStyle(theme),
                    formatter: function () { return this.y === null ? null : num(this.y, 0) + '%'; }
                }
            };
            cfg.tooltip.formatter = function () {
                var n = this.series.userOptions.svrN || 0;
                var k = Math.round((this.y || 0) * n / 100);
                return '<b>' + esc(this.key) + '</b><br/>' + esc(this.series.name) + ': <b>' +
                    num(this.y, 1) + '%</b> (' + k + ' of ' + n + ')';
            };
        } else {
            cfg.yAxis.allowDecimals = false;
            cfg.yAxis.title = { text: 'Responses', style: { color: theme.muted, fontSize: '11px' } };
            cfg.plotOptions.series.stacking = 'normal';
            cfg.plotOptions.bar = {
                borderWidth: 2,
                borderColor: theme.surface,
                dataLabels : {
                    enabled  : true,
                    style    : labelStyle(theme),
                    formatter: function () { return this.y ? this.y : null; }
                }
            };
            cfg.tooltip.formatter = function () {
                return '<b>' + esc(this.key) + '</b><br/>' + esc(this.series.name) + ': <b>' + this.y + '</b>';
            };
        }
        cfg.series = series;
        return cfg;
    }

    function hasCrosstab(q) {
        return !!(q.crosstab && (q.crosstab.groups || []).length);
    }

    function specFor(q, theme) {
        if (q.agg && q.agg.suppressed) { return null; }
        if (hasCrosstab(q)) {
            return (q.type === 'rating' || q.type === 'nps') ? specCrosstabScore(q, theme) : specCrosstabChoice(q, theme);
        }
        switch (q.type) {
            case 'single':
            case 'dropdown':
            case 'yesno':
            case 'multi':   return specChoice(q, theme);
            case 'rating':  return specRating(q, theme);
            case 'nps':     return specNps(q, theme);
            case 'matrix':  return specMatrix(q, theme);
            case 'ranking': return specRanking(q, theme);
            case 'pairwise': return specPairwise(q, theme);
            case 'number':  return specNumber(q, theme);
            case 'date':    return specDate(q, theme);
            default:        return null;
        }
    }

    /* ---------------------------------------------------------
       Callout figures under a chart
       --------------------------------------------------------- */

    function calloutsFor(q) {
        var a = q.agg || {};
        var out = [];
        switch (q.type) {
            case 'rating':
                out.push(['Mean', num(a.mean, 2)], ['Median', num(a.median, 2)], ['Scale', num(a.min) + '–' + num(a.max)]);
                break;
            case 'nps':
                out.push(['NPS score', num(a.score, 1), 'svr-nps-tile'],
                    ['Promoters', num(a.promoters)], ['Passives', num(a.passives)], ['Detractors', num(a.detractors)]);
                break;
            case 'number':
                out.push(['Mean', num(a.mean, 2)], ['Median', num(a.median, 2)],
                    ['Min', num(a.min, 2)], ['Max', num(a.max, 2)]);
                break;
            case 'date':
                out.push(['Earliest', a.min ? humanDate(a.min).date : '—'], ['Latest', a.max ? humanDate(a.max).date : '—']);
                break;
            case 'multi':
                out.push(['Mean picked', num(a.mean_selected, 2)]);
                break;
            case 'matrix':
                /* The mean is over valued columns only; an N/A-style column
                   is left out of it, so say so and give its n (#34). */
                var hasNa = (a.columns || []).some(function (c) { return c.value_num === null || c.value_num === undefined; });
                (a.rows || []).forEach(function (r) {
                    if (r.weighted_mean !== null && r.weighted_mean !== undefined) {
                        out.push([r.label + ' · Mean' + (hasNa ? ' (excl. N/A)' : '') + ', n=' + thousands(r.mean_n || 0),
                            num(r.weighted_mean, 2)]);
                    }
                });
                break;
            case 'pairwise':
                out.push(['Possible matchups', num(a.possible)],
                    ['Average % of matchups', a.avg_pct === null || a.avg_pct === undefined ? '—' : num(a.avg_pct, 1) + '%'],
                    ['Avg per respondent', a.avg_count === null || a.avg_count === undefined ? '—' : num(a.avg_count, 1) + ' of ' + num(a.possible)],
                    ['Matchups judged', num(a.judged)]);
                break;
            default:
                break;
        }
        if (!out.length) { return ''; }
        var html = '<div class="svr-callouts">';
        out.forEach(function (c) {
            html += '<div class="svr-callout ' + (c[2] || '') + '">' +
                '<div class="svr-callout-value">' + esc(c[1]) + '</div>' +
                '<div class="svr-callout-label">' + esc(c[0]) + '</div></div>';
        });
        return html + '</div>';
    }

    /* ---------------------------------------------------------
       Card rendering
       --------------------------------------------------------- */

    /* The server caps each list (TEXT_SAMPLE_LIMIT); total is the full count,
       so a capped list says "500 of 3,214" rather than "all 500" (#33). */
    function textListLabel(loaded, total) {
        if (total > loaded) {
            return 'Showing ' + thousands(loaded) + ' of ' + thousands(total) + ' responses' +
                (SHARED ? '' : '. Export the CSV for the rest.');
        }
        return 'All ' + thousands(loaded) + ' responses shown';
    }

    function textListHtml(texts, prefix, total) {
        total = Math.max(texts.length, total || 0);
        var shown = texts.slice(0, TEXT_PREVIEW);
        var html = '<ul class="svr-textlist" id="' + prefix + '-list">';
        shown.forEach(function (t) { html += '<li>' + esc(t) + '</li>'; });
        html += '</ul>';
        if (texts.length > TEXT_PREVIEW) {
            html += '<button type="button" class="sv-btn svr-showmore" data-more="' + prefix + '" data-total="' + total + '">' +
                (total > texts.length ? 'Show ' + thousands(texts.length) + ' of ' + thousands(total) + ' responses'
                    : 'Show all ' + thousands(texts.length) + ' responses') + '</button>';
        }
        return html;
    }

    /* "n = 42 of 60": answered of reached (#35). The tip says what the card's
       percentages are OF, which is the thing an officer misreads. */
    function badgeHtml(q) {
        /* Nothing matched at all: "n = 0", not "n hidden" — there is no one to
           protect, so do not imply that answers are being withheld. */
        if (noResponses()) { return '<span class="svr-badge">n = 0</span>'; }
        if (q.agg && q.agg.suppressed) {
            return '<span class="svr-badge" data-tip="Hidden: fewer than ' + minCell() +
                ' responses match these filters." tabindex="0">n hidden</span>';
        }
        var n = Number(q.n || 0);
        var reached = q.reached === null || q.reached === undefined ? null : Number(q.reached);
        if (reached === null || reached < n) {
            return '<span class="svr-badge">n = ' + n + '</span>';
        }
        /* Pairwise percentages are of matchups (win % over the matchups an
           option appeared in, the average over the possible ones); a ranking,
           rating, number or date card shows none. Only the choice types'
           percentages are of people. */
        var who = plural(n, 'person', 'people');
        var tip = (q.type === 'pairwise' ? who + ' answered. The percentages here are of matchups, not people. '
            : (TEXT_TYPES[q.type] || q.type === 'ranking' || q.type === 'rating' ||
                q.type === 'number' || q.type === 'date' ? who + ' answered. '
            : 'Percentages are of the ' + who + ' who answered. ')) +
            plural(reached, 'response', 'responses') + ' reached this question' +
            (reached > n ? '; ' + (reached - n) + ' left it blank.' : '.');
        return '<span class="svr-badge" data-tip="' + esc(tip) + '" tabindex="0">n = ' + n + ' of ' + reached + '</span>';
    }

    function xtToggleHtml(q) {
        if (!hasCrosstab(q) || q.type === 'rating' || q.type === 'nps') { return ''; }
        var mode = state.xtMode[q.question_id] || 'pct';
        var btn = function (m, label) {
            return '<button type="button" class="svr-xt-btn" data-xt-qid="' + q.question_id + '" data-xt-mode="' + m +
                '" aria-pressed="' + (mode === m ? 'true' : 'false') + '">' + label + '</button>';
        };
        return '<div class="svr-xt-toggle" role="group" aria-label="Show the split as">' +
            btn('pct', '% within group') + btn('count', 'Count') + '</div>';
    }

    /* The ranking as a sortable table (tabular data = DataTables). Rank sorts
       ascending by default. Rank and Strength come from the server's
       Bradley-Terry fit (#35); an option in too few matchups is listed
       unranked. Unranked options sort after ranked ones on every column, in
       either direction: the hidden last column (0 ranked, 1 too few matchups,
       2 never matched) is pinned ahead of whatever the reader sorts by
       (orderFixed.pre). */
    var PW_SORTKEY_COL = 8;

    function pairwiseTableHtml(q) {
        var opts = (q.agg && q.agg.options) || [];
        var html = '<div class="svr-pw-tablewrap"><table class="display svr-pw-table" style="width:100%" ' +
            'aria-labelledby="svr-title-' + q.question_id + '"><thead><tr>' +
            '<th scope="col">Rank</th><th scope="col">Option</th>' +
            '<th scope="col"><span data-tip="Chance of beating a typical option, from a Bradley-Terry fit: a win over a strong option counts for more than a win over a weak one. This sets the rank." tabindex="0">Strength</span></th>' +
            '<th scope="col">Win %</th>' +
            /* W / T / L: the house data-tip for sighted users, the full word for screen readers. */
            '<th scope="col"><span class="svr-pw-abbr" data-tip="Wins" aria-hidden="true">W</span><span class="sv-visually-hidden">Wins</span></th>' +
            '<th scope="col"><span class="svr-pw-abbr" data-tip="Ties" aria-hidden="true">T</span><span class="sv-visually-hidden">Ties</span></th>' +
            '<th scope="col"><span class="svr-pw-abbr" data-tip="Losses" aria-hidden="true">L</span><span class="sv-visually-hidden">Losses</span></th>' +
            '<th scope="col">Matchups</th>' +
            '<th scope="col" class="svr-pw-sortkey">Unranked</th></tr></thead><tbody>';
        opts.forEach(function (o, i) {
            var unranked = o.rank === null || o.rank === undefined;
            var seen = o.win_pct !== null && o.win_pct !== undefined;
            var hasStrength = o.strength !== null && o.strength !== undefined;
            var rankCell = !unranked ? String(o.rank)
                : (o.too_few ? '<span data-tip="Seen in too few matchups to rank reliably." tabindex="0">Too few matchups</span>' : '—');
            html += '<tr>' +
                '<td data-order="' + (unranked ? 100000 + i : o.rank) + '">' + rankCell + '</td>' +
                '<td>' + esc(o.label) + '</td>' +
                '<td data-order="' + (unranked || !hasStrength ? -1 : o.strength) + '">' + (unranked || !hasStrength ? '—' : num(o.strength, 1) + '%') + '</td>' +
                '<td data-order="' + (seen ? o.win_pct : -1) + '">' + (seen ? num(o.win_pct, 1) + '%' : '—') + '</td>' +
                '<td>' + (o.wins || 0) + '</td><td>' + (o.ties || 0) + '</td><td>' + (o.losses || 0) + '</td>' +
                '<td>' + (o.appearances || 0) + '</td>' +
                '<td class="svr-pw-sortkey">' + (!unranked ? 0 : (seen ? 1 : 2)) + '</td></tr>';
        });
        return html + '</tbody></table></div>';
    }

    function destroyPairwiseTables() {
        (state.pwTables || []).forEach(function (t) { try { t.destroy(); } catch (e) { /* gone */ } });
        state.pwTables = [];
    }

    function initPairwiseTables(host) {
        var jq = window.jQuery;   // not `$`: this file's `$` is getElementById
        if (!jq || !jq.fn || !jq.fn.DataTable) { return; }
        Array.prototype.forEach.call(host.querySelectorAll('.svr-pw-table'), function (table) {
            var many = table.tBodies[0] && table.tBodies[0].rows.length > 25;
            state.pwTables.push(jq(table).DataTable({
                order       : [[0, 'asc']],
                orderFixed  : { pre: [[PW_SORTKEY_COL, 'asc']] },
                columnDefs  : [
                    { targets: PW_SORTKEY_COL, visible: false, searchable: false },
                    // Strength, Win %, W, T, L and Matchups read high to low on the first click.
                    { targets: [2, 3, 4, 5, 6, 7], orderSequence: ['desc', 'asc'] }
                ],
                paging      : many,
                pageLength  : 25,
                searching   : many,
                info        : false,
                lengthChange: false,
                autoWidth   : false
            }));
        });
    }

    function cardHtml(q) {
        var a   = q.agg || {};
        var n   = Number(q.n || a.n || 0);
        var qid = q.question_id;
        var qn  = QNUM[qid];
        var html = '<section class="rp-chart-card svr-card" data-qid="' + qid + '">' +
            '<div class="svr-card-head">' +
            '<div><h3 class="svr-card-title" id="svr-title-' + qid + '">' +
            esc(q.prompt || ('Question ' + qid)) + '</h3>' +
            '<span class="svr-card-type">' + (qn ? 'Q' + qn + ' &middot; ' : '') + esc(typeLabel(q.type)) + '</span></div>' +
            badgeHtml(q) +
            '</div>';

        /* Zero matches is its own state, not the 1–4 minimum-cell statement:
           the page notice above the cards carries the Reset filters action. */
        if (noResponses()) {
            /* A held share is waiting, not filtered out: its own neutral
               hourglass statement, matching the page notice. */
            html += heldText()
                ? '<div class="svr-empty svr-empty-held"><i class="fas fa-hourglass-half" aria-hidden="true"></i> ' +
                  esc(heldText()) + '</div></section>'
                : '<div class="svr-empty svr-empty-nomatch"><i class="fas fa-filter-circle-xmark" aria-hidden="true"></i> ' +
                  esc(noResponsesText()) + '</div></section>';
            return html;
        }

        if (a.suppressed) {
            html += '<div class="svr-suppressed"><i class="fas fa-eye-slash" aria-hidden="true"></i> ' +
                'Too few responses to show (fewer than ' + minCell() + ').</div></section>';
            return html;
        }

        if (!n) {
            html += '<div class="svr-empty">No answers yet.</div></section>';
            return html;
        }

        if (TEXT_TYPES[q.type]) {
            if (state.summaryMode || SHARED) {
                /* Summary for sharing (#5): comments are counted, never printed.
                   A shared viewer's payload carries no texts at all. */
                html += '<p class="svr-comment-count"><i class="fas fa-comment-dots" aria-hidden="true"></i> ' +
                    plural(n, 'written comment', 'written comments') +
                    (SHARED ? '. Individual comments stay with the survey’s owners.' : '') + '</p>';
            } else {
                html += textListHtml(a.texts || [], 'svr-txt-' + qid, a.n);
            }
            html += '</section>';
            return html;
        }

        if (hasCrosstab(q)) {
            html += '<div class="svr-xt-head"><p class="svr-field-hint">Split by: <strong>' +
                esc(q.crosstab.prompt) + '</strong></p>' + xtToggleHtml(q) + '</div>';
            html += crosstabNote(q);
        } else if (state.appliedFilters && Number(state.appliedFilters.crosstab_question_id) === Number(qid)) {
            /* The source is never split by itself (#9): say why its card has no split. */
            html += '<div class="svr-xt-head"><p class="svr-field-hint">' +
                'This is the question the results are split by.</p></div>';
        }

        /* aria-labelledby, not aria-label: the Highcharts accessibility module
           writes its own aria-label onto this container, and labelledby wins
           the accessible-name computation, so every chart region is announced
           with the question it belongs to instead of a generic label. */
        html += '<div class="svr-chart' + (q.type === 'matrix' || q.type === 'ranking' || q.type === 'pairwise' ? ' svr-chart-tall' : '') +
            '" id="svr-chart-' + qid + '" data-qid="' + qid + '" aria-labelledby="svr-title-' + qid + '"></div>';
        html += calloutsFor(q);
        if (q.type === 'pairwise') { html += pairwiseTableHtml(q); }

        var others = a.other_texts || [];
        /* A shared payload sends other_count, never the words. */
        var otherCount = SHARED ? (a.other_count || 0) : (a.other_texts_n || others.length);
        if (otherCount) {
            if (state.summaryMode || SHARED) {
                html += '<p class="svr-comment-count"><i class="fas fa-comment-dots" aria-hidden="true"></i> ' +
                    plural(otherCount, '&ldquo;Other&rdquo; answer', '&ldquo;Other&rdquo; answers') + ' written in' +
                    (SHARED ? '. Individual comments stay with the survey’s owners.' : '') + '</p>';
            } else {
                html += '<details class="svr-other"><summary><i class="fas fa-comment-dots"></i> ' +
                    '&ldquo;Other&rdquo; answers (' + thousands(a.other_texts_n || others.length) + ')</summary>' +
                    textListHtml(others, 'svr-oth-' + qid, a.other_texts_n) + '</details>';
            }
        }

        html += '</section>';
        return html;
    }

    /* ---------------------------------------------------------
       State
       --------------------------------------------------------- */

    var state = {
        payload       : null,
        charts        : {},       /* question_id → Highcharts instance */
        table         : null,
        pwTables      : [],       /* pairwise ranking DataTable instances, one per card */
        loading       : false,
        seq           : 0,        /* results request sequence: the latest Apply wins (#27) */
        abort         : null,
        appliedFilters: null,     /* the ONLY filter set load(), the rows table and the export read (#27) */
        xtMode        : {},       /* question_id → 'pct' | 'count' (#29) */
        summaryMode   : false,    /* Summary for sharing (#5) */
        pageRows      : [],       /* rows of the current table page, for the response panel (#36) */
        pageTotal     : 0,
        rowsDraw      : 0,        /* draw number of the newest rows request */
        panelIdx      : -1,
        panelReturn   : null,
        pickers       : {}
    };

    /* ---------------------------------------------------------
       Charts — built lazily as their cards near the viewport (#41)
       --------------------------------------------------------- */

    var observer = null;

    function destroyCharts() {
        Object.keys(state.charts).forEach(function (k) {
            try { state.charts[k].destroy(); } catch (e) { /* already gone */ }
        });
        state.charts = {};
        if (observer) { observer.disconnect(); }
    }

    function afterPaint(fn) {
        var done = false;
        var run = function () {
            if (done) { return; }
            done = true;
            fn();
        };
        if (window.requestAnimationFrame) { window.requestAnimationFrame(run); }
        window.setTimeout(run, 250);
    }

    function payloadQuestion(qid) {
        var list = (state.payload && state.payload.questions) || [];
        for (var i = 0; i < list.length; i++) {
            if (Number(list[i].question_id) === Number(qid)) { return list[i]; }
        }
        return null;
    }

    /* Build one card's chart. Returns true once the card needs no more work
       (built, or nothing to build) so the observer can stop watching it. */
    function buildOne(q) {
        var HC = window.SvHighcharts;
        if (!q || !HC || !HC.Chart) { return true; }
        if (state.charts[q.question_id]) { return true; }
        var el = $('svr-chart-' + q.question_id);
        if (!el) { return true; }
        /* Highcharts #13 guard: never render into a collapsed box. Not done:
           the next resize or intersection retries. */
        if (el.offsetParent === null || el.clientWidth <= 0) { return false; }
        var theme = svChartTheme();
        var cfg = specFor(q, theme);
        if (!cfg) { return true; }
        cfg.chart.renderTo = el;
        /* The a11y module parses description as HTML: escape the prompt so it reads as text. */
        cfg.accessibility = { description: esc(q.prompt || ('Question ' + q.question_id)) };
        /* Horizontal bars: wrap long option labels inside a width budget
           instead of ellipsising them — there is no hover on touch (#33). */
        if (cfg.chart.type === 'bar') {
            cfg.xAxis.labels.style.width = Math.max(90, Math.round(el.clientWidth * 0.36)) + 'px';
            cfg.xAxis.labels.style.textOverflow = 'none';
            cfg.xAxis.labels.style.whiteSpace = 'normal';
        }
        try {
            state.charts[q.question_id] = new HC.Chart(cfg);
            /* A chart that sized itself (bars grow with their categories)
               replaces the CSS pre-build reservation; the rest keep it, which
               is also the height Highcharts reads on the next rebuild. */
            el.style.minHeight = cfg.chart.height ? cfg.chart.height + 'px' : '';
        } catch (e) {
            el.innerHTML = '<div class="svr-empty">This chart could not be drawn.</div>';
        }
        return true;
    }

    function buildCharts() {
        destroyCharts();
        if (!state.payload) { return; }
        var qs = state.payload.questions || [];
        if (!('IntersectionObserver' in window)) {
            qs.forEach(buildOne);
            return;
        }
        if (!observer) {
            observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (!en.isIntersecting) { return; }
                    var q = payloadQuestion(en.target.getAttribute('data-qid'));
                    if (buildOne(q)) { observer.unobserve(en.target); }
                });
            }, { rootMargin: '400px 0px' });
        }
        qs.forEach(function (q) {
            var el = $('svr-chart-' + q.question_id);
            if (el) { observer.observe(el); }
        });
    }

    /* Paper needs every chart, not just the ones near the viewport. */
    function buildAllCharts() {
        destroyCharts();
        if (!state.payload) { return; }
        (state.payload.questions || []).forEach(buildOne);
    }

    function rebuildOne(qid) {
        var c = state.charts[qid];
        if (c) { try { c.destroy(); } catch (e) { /* gone */ } delete state.charts[qid]; }
        buildOne(payloadQuestion(qid));
    }

    /* ---------------------------------------------------------
       Filters
       --------------------------------------------------------- */

    var DEFAULT_FILTERS = {
        kingdom_ids: [], consent: 'any', date_from: null, date_to: null,
        crosstab_question_id: null, include_test: false
    };

    /* The controls as they stand now — read ONLY by Apply. */
    function readFilters() {
        var kingdoms = [];
        Array.prototype.forEach.call(document.querySelectorAll('.svr-kingdom:checked'), function (cb) {
            var v = parseInt(cb.value, 10);
            if (v > 0) { kingdoms.push(v); }
        });
        var xt = $('svr-crosstab');
        return {
            kingdom_ids         : kingdoms,
            consent             : ($('svr-consent') || {}).value || 'any',
            date_from           : ($('svr-date-from') || {}).value || null,
            date_to             : ($('svr-date-to') || {}).value || null,
            crosstab_question_id: xt && xt.value ? parseInt(xt.value, 10) : null,
            include_test        : !!($('svr-include-test') || {}).checked
        };
    }

    function setDate(id, value) {
        var fp = state.pickers[id];
        if (fp) {
            if (value) { fp.setDate(value, false, 'Y-m-d'); } else { fp.clear(false); }
        } else if ($(id)) {
            $(id).value = value || '';
        }
        syncDateClear(id);
    }

    /* Put a filter set into the controls (Reset, a shared URL). */
    function writeControls(f) {
        f = f || DEFAULT_FILTERS;
        var ids = (f.kingdom_ids || []).map(Number);
        Array.prototype.forEach.call(document.querySelectorAll('.svr-kingdom'), function (cb) {
            var v = parseInt(cb.value, 10);
            /* A shared viewer's "All kingdoms" radio (value 0) stands for no pick. */
            cb.checked = ids.indexOf(v) !== -1 || (v === 0 && !ids.length);
        });
        var consent = $('svr-consent');
        if (consent) {
            consent.value = ['any', 'full', 'partial', 'anonymous'].indexOf(f.consent) !== -1 ? f.consent : 'any';
        }
        setDate('svr-date-from', f.date_from || '');
        setDate('svr-date-to', f.date_to || '');
        var xt = $('svr-crosstab');
        if (xt) {
            /* An id the <select> does not offer leaves it on "None" (assigning
               an unmatched value would blank the select instead). */
            var want = f.crosstab_question_id ? String(f.crosstab_question_id) : '';
            var offered = Array.prototype.some.call(xt.options, function (o) { return o.value === want; });
            xt.value = offered ? want : '';
        }
        if ($('svr-include-test')) { $('svr-include-test').checked = !!f.include_test; }
    }

    function activeFilterCount(f) {
        if (!f) { return 0; }
        var c = 0;
        if (f.kingdom_ids && f.kingdom_ids.length) { c++; }
        if (f.consent && f.consent !== 'any') { c++; }
        if (f.date_from) { c++; }
        if (f.date_to) { c++; }
        if (f.crosstab_question_id) { c++; }
        if (f.include_test) { c++; }
        return c;
    }

    /* Zero responses in the rendered payload. Distinct from suppression
       (1..MIN_CELL-1): nothing is being hidden, the slice is simply empty. */
    function noResponses() {
        var s = state.payload && state.payload.summary;
        return !!s && (parseInt(s.responses, 10) || 0) === 0;
    }

    /* Only the kingdom, consent and date filters can narrow the slice (a
       split-by or test responses never remove a row), so with none of them
       set an empty survey has simply not been answered yet. */
    function narrowingFilters(f) {
        return !!f && !!((f.kingdom_ids && f.kingdom_ids.length) ||
            (f.consent && f.consent !== 'any') || f.date_from || f.date_to);
    }

    /* An ongoing share holding back its first few matches (summary.held:
       some match, fewer than MIN_CELL). A flag only; the count never ships. */
    function heldText() {
        var s = state.payload && state.payload.summary;
        return (s && s.held) ? 'Results appear here once at least ' + minCell() + ' responses match this view.' : '';
    }

    function noResponsesText() {
        if (heldText()) { return heldText(); }
        return narrowingFilters(state.appliedFilters) ? 'No responses match these filters.' : 'No responses yet.';
    }

    function resetFilters() {
        writeControls(DEFAULT_FILTERS);
        onApply();
    }

    function syncFilterToggle() {
        var lbl = $('svr-filters-toggle-label');
        if (!lbl) { return; }
        var c = activeFilterCount(state.appliedFilters);
        lbl.textContent = c ? 'Filters (' + c + ' active)' : 'Filters';
    }

    function setFiltersOpen(open) {
        var sb = $('svr-sidebar');
        var tg = $('svr-filters-toggle');
        if (!sb || !tg) { return; }
        sb.classList.toggle('svr-collapsed', !open);
        tg.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function isNarrow() {
        return !!(window.matchMedia && window.matchMedia(NARROW_MQ).matches);
    }

    function syncExport(filters) {
        var a = $('svr-export');
        if (!a) { return; }
        a.setAttribute('href', UIR + 'Survey/export/' + SURVEY_ID +
            '&filters=' + encodeURIComponent(JSON.stringify(filters)));
        var an = $('svr-export-analysis');
        if (an) {
            an.setAttribute('href', UIR + 'Survey/export/' + SURVEY_ID + '&format=analysis' +
                '&filters=' + encodeURIComponent(JSON.stringify(filters)));
        }
    }

    /* ---------------------------------------------------------
       Shareable URL: #filters=<json>&summary=1 (#36, #5)
       --------------------------------------------------------- */

    function hashParams() {
        var out = {};
        String(location.hash || '').replace(/^#/, '').split('&').forEach(function (kv) {
            if (!kv) { return; }
            var i = kv.indexOf('=');
            var k = i === -1 ? kv : kv.slice(0, i);
            var v = i === -1 ? '' : kv.slice(i + 1);
            try { out[decodeURIComponent(k)] = decodeURIComponent(v); } catch (e) { /* malformed */ }
        });
        return out;
    }

    function filtersFromHash() {
        var p = hashParams();
        if (!p.filters) { return null; }
        try {
            var f = JSON.parse(p.filters);
            return f && typeof f === 'object' ? f : null;
        } catch (e) {
            return null;
        }
    }

    function writeHash() {
        var parts = [];
        if (activeFilterCount(state.appliedFilters)) {
            parts.push('filters=' + encodeURIComponent(JSON.stringify(state.appliedFilters)));
        }
        if (state.summaryMode) { parts.push('summary=1'); }
        var next = parts.length ? '#' + parts.join('&') : '';
        /* ?summary=1 is an entry point only; from here on the hash carries it. */
        var search = location.search.replace(/([?&])summary=1(&|$)/, function (m, a, b) { return b ? a : ''; });
        if (next === (location.hash || '') && search === location.search) { return; }
        var url = location.pathname + search + next;
        try { history.replaceState(null, '', url); } catch (e) { location.hash = next; }
    }

    function summaryRequested() {
        if (/(?:^|[?&])summary=1(?:&|$)/.test(location.search)) { return true; }
        return hashParams().summary === '1';
    }

    /* ---------------------------------------------------------
       Transport
       --------------------------------------------------------- */

    function post(action, fields, signal) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        var opts = {
            method     : 'POST',
            body       : fd,
            credentials: 'same-origin',
            headers    : { 'X-CSRF-Token': CSRF }
        };
        if (signal) { opts.signal = signal; }
        return fetch(UIR + 'SurveyAjax/' + action, opts).then(function (r) { return r.json(); });
    }

    function notice(msg, show) {
        var el = $('svr-notice');
        if (!el) { return; }
        if (!show) { el.hidden = true; el.innerHTML = ''; return; }
        /* Unhide first: a role="status" mutation inside a hidden element is not
           announced. */
        el.hidden = false;
        el.innerHTML = '<i class="fas fa-circle-info"></i><span>' + esc(msg) + '</span>';
    }

    /* Expired token (#43): an inline notice with a Reload link, never a
       native dialog. */
    function csrfNotice(msg) {
        var el = $('svr-csrf');
        if (!el) { return; }
        el.hidden = false;
        el.innerHTML = '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i> <span>' +
            esc(msg || 'Your security token expired. Reload the page and try again.') + '</span> ' +
            '<a href="' + esc(location.href) + '" class="sv-notice-link" data-svr-reload>Reload</a>';
    }

    /* Short screen-reader status line. The card grid itself is deliberately not
       a live region — re-rendering it would read every chart card aloud. */
    function announce(msg) {
        var el = $('svr-live');
        if (el) { el.textContent = msg; }
    }

    function statusMessage(r) {
        if (r.csrf) { return r.error || 'Your security token expired. Reload the page and try again.'; }
        if (r.status === 5) { return 'Your session expired — log in again to see these results.'; }
        if (r.status === 3) { return 'You do not have permission to view these results.'; }
        return r.error || 'Something went wrong loading the results.';
    }

    function failed(r) {
        if (r && r.csrf) { csrfNotice(r.error); return; }
        notice(statusMessage(r || {}), true);
    }

    /* ---------------------------------------------------------
       Summary + cards
       --------------------------------------------------------- */

    function setStatTip(el, tip) {
        if (!el) { return; }
        if (tip) {
            el.setAttribute('data-tip', tip);
            el.setAttribute('tabindex', '0');
            el.classList.add('svr-stat-na');
        } else {
            el.removeAttribute('data-tip');
            el.removeAttribute('tabindex');
            el.classList.remove('svr-stat-na');
        }
    }

    function renderSummary(s, filters) {
        var mc = parseInt(s.min_cell, 10) || 5;
        var responses = parseInt(s.responses, 10) || 0;
        $('svr-stat-responses').textContent = thousands(responses);
        /* The label follows the count: "1 Response", never "1 Responses". */
        var respLabel = $('svr-stat-responses-label');
        if (respLabel) { respLabel.textContent = responses === 1 ? 'Response' : 'Responses'; }
        var none = responses === 0;

        /* Response rate against the current eligible audience (#35). */
        var rateEl = $('svr-stat-rate');
        var rateHint = $('svr-stat-rate-hint');
        var audience = s.audience === null || s.audience === undefined ? null : Number(s.audience);
        if (s.response_rate !== null && s.response_rate !== undefined && audience) {
            var rate = Number(s.response_rate) * 100;
            /* The rate's numerator is real responses only. Without test rows
               that is exactly the Responses figure; with them included, derive
               it from the rate rather than print a count that mixes the two. */
            var got = filters.include_test ? Math.round(Number(s.response_rate) * audience) : (Number(s.responses) || 0);
            rateEl.textContent = rate > 0 && rate < 0.1 ? '<0.1%' : pct(rate);
            rateHint.textContent = thousands(got) + ' of ' + thousands(audience) + ' current audience';
            setStatTip(rateEl, null);
        } else {
            rateEl.textContent = '—';
            rateHint.textContent = audience ? 'current audience ' + thousands(audience) : 'of the current audience';
            setStatTip(rateEl, s.narrowing
                ? 'Not shown while a kingdom, consent or date filter is set: the eligible audience cannot be narrowed the same way.'
                : 'The size of this survey’s eligible audience is not known, so there is no rate to show.');
        }

        var compEl = $('svr-stat-completion');
        if (s.completion !== null && s.completion !== undefined) {
            compEl.textContent = pct(Number(s.completion) * 100);
            setStatTip(compEl, null);
        } else {
            compEl.textContent = '—';
            setStatTip(compEl, s.narrowing
                ? 'Not shown while a kingdom, consent or date filter is set: starts carry no kingdom, consent or date.'
                : 'Not enough start data: responses from before starts were tracked cannot be compared.');
        }

        var durEl = $('svr-stat-duration');
        durEl.textContent = duration(s.median_duration);
        setStatTip(durEl, none ? noResponsesText().replace(/\.$/, ': there is no time to show.')
            : (s.suppressed ? 'Hidden: fewer than ' + mc + ' responses match these filters.' : null));

        /* null while suppressed (#4): the split of fewer than MIN_CELL people. */
        var c = s.consent_breakdown || null;
        var part = function (v, label) {
            return '<span class="svr-consent-part"><b>' + (c ? (v || 0) : '—') + '</b> ' + label + '</span>';
        };
        $('svr-stat-consent').innerHTML = part(c && c.full, 'full') + part(c && c.partial, 'partial') +
            part(c && c.anonymous, 'anon');

        var ex = parseInt(s.excluded_anonymous, 10) || 0;
        if (ex > 0 && filters.kingdom_ids.length) {
            notice(ex + (ex === 1 ? ' anonymous response is' : ' anonymous responses are') +
                ' excluded by the kingdom filter — anonymous responses carry no kingdom.', true);
        } else {
            notice('', false);
        }

        /* Minimum cell size (#4). */
        var sup = $('svr-suppressed');
        if (sup) {
            sup.classList.toggle('svr-nomatch-notice', none);
            if (none) {
                /* Zero matches (not the 1–4 minimum-cell case): say so plainly
                   and offer the way back. Unfiltered, the survey has simply
                   not been answered yet, so there is nothing to reset. */
                sup.hidden = false;
                sup.innerHTML = heldText()
                    ? '<i class="fas fa-hourglass-half" aria-hidden="true"></i> <span>' + esc(heldText()) + '</span>'
                    : narrowingFilters(filters)
                    ? '<i class="fas fa-filter-circle-xmark" aria-hidden="true"></i> <span>No responses match these filters.</span> ' +
                      '<button type="button" class="sv-btn svr-nomatch-reset" data-svr-reset-filters>' +
                      '<i class="fas fa-rotate-left" aria-hidden="true"></i> Reset filters</button>'
                    : '<i class="fas fa-inbox" aria-hidden="true"></i> <span>No responses yet. Results appear here once people start answering.</span>';
            } else if (s.suppressed) {
                sup.hidden = false;
                var rn = Number(s.responses) || 0;
                /* A manager's rows table still lists the matching responses, so
                   only the charts are hidden; a shared lens exposes no rows. The
                   rows sentence is its own span so Summary for sharing (which
                   hides the table) can hide it without a re-render. */
                sup.innerHTML = '<i class="fas fa-eye-slash" aria-hidden="true"></i> <span>' +
                    'Only ' + plural(rn, 'response matches', 'responses match') + ' these filters. ' +
                    (SHARED
                        ? 'Answers from fewer than ' + mc + ' people are hidden so no one can be singled out.'
                        : 'Charts are hidden when fewer than ' + mc + ' responses match, so no one can be singled out.' +
                          ($('svr-rows')
                              ? '<span class="svr-sup-rows"' + (state.summaryMode ? ' hidden' : '') +
                                '> Managers can still see individual rows in the table below.</span>'
                              : '')) +
                    ' Widen the filters to see results.</span>';
            } else {
                sup.hidden = true;
                sup.innerHTML = '';
            }
        }
        var rule = $('svr-rule-note');
        if (rule) {
            rule.hidden = !s.partial_cell_rule;
            rule.textContent = s.partial_cell_rule
                ? 'With a kingdom filter set, partial-consent responses from any kingdom with fewer than ' + mc +
                  ' of them are left out, so a small group cannot be singled out.'
                : '';
        }
    }

    /* Caption for Summary for sharing (#5): which slice this is, how many
       people, and how they consented, so a filtered subset is never presented
       as the whole kingdom's view. */
    function renderCaption() {
        var el = $('svr-caption');
        if (!el) { return; }
        if (!state.summaryMode || !state.payload) { el.hidden = true; el.innerHTML = ''; return; }
        var f = state.appliedFilters || DEFAULT_FILTERS;
        var s = state.payload.summary || {};
        var bits = [];
        /* A shared viewer's lens is a filter the server always applies, so the
           caption names it — "None — every response" would contradict the lens
           strip printed on the same page. The label comes from the payload
           (summary.lens), the org name from the page. */
        var lens = SHARED ? ((s.lens && s.lens.label) || CFG.lens || '') : '';
        if (lens === 'kingdom') {
            bits.push('Players of ' + (CFG.lensOrg || 'your kingdom') + ' only');
        } else if (lens === 'park') {
            bits.push((CFG.lensOrg || 'Your park') + ' players only');
        }
        if (f.kingdom_ids && f.kingdom_ids.length) {
            var names = [];
            Array.prototype.forEach.call(document.querySelectorAll('.svr-kingdom'), function (cb) {
                if (f.kingdom_ids.indexOf(parseInt(cb.value, 10)) !== -1) { names.push(cb.getAttribute('data-name') || cb.value); }
            });
            bits.push('Kingdom: ' + names.join(', '));
        }
        if (f.consent && f.consent !== 'any') {
            bits.push('Consent: ' + ({ full: 'Full', partial: 'Partial', anonymous: 'Anonymous' }[f.consent] || f.consent));
        }
        if (f.date_from || f.date_to) {
            bits.push('Submitted ' + (f.date_from ? humanDate(f.date_from).date : 'any time') + ' – ' +
                (f.date_to ? humanDate(f.date_to).date : 'today'));
        }
        if (f.crosstab_question_id) {
            var xq = questionById(f.crosstab_question_id);
            bits.push('Split by: ' + (xq ? 'Q' + QNUM[xq.question_id] + ' ' + xq.prompt : 'question ' + f.crosstab_question_id));
        }
        if (f.include_test) { bits.push('Test responses included'); }

        var c = s.consent_breakdown;
        var today = new Date();
        var todayStr = MONTHS[today.getMonth()] + ' ' + today.getDate() + ', ' + today.getFullYear();
        el.hidden = false;
        el.innerHTML =
            '<div class="svr-caption-title"><i class="fas fa-file-lines" aria-hidden="true"></i> Summary for sharing</div>' +
            '<p><strong>Filters:</strong> ' + (bits.length ? esc(bits.join(' · ')) : 'None — every response') + '</p>' +
            '<p><strong>n = ' + (s.responses || 0) + '</strong> ' + ((s.responses || 0) === 1 ? 'response' : 'responses') +
            (c ? ' · Consent: ' + (c.full || 0) + ' full, ' + (c.partial || 0) + ' partial, ' + (c.anonymous || 0) + ' anonymous' : '') +
            ' · Prepared ' + esc(todayStr) + '</p>' +
            '<p class="svr-caption-foot">Written comments and individual responses are left out of this summary. ' +
            'Groups of fewer than ' + minCell() + ' responses are hidden.</p>';
    }

    function renderCards(questions) {
        var host = $('svr-cards');
        destroyPairwiseTables();
        if (!questions.length) {
            host.innerHTML = '<div class="rp-chart-card svr-card"><div class="svr-empty">' +
                'This survey has no answerable questions yet.</div></div>';
            return;
        }
        host.innerHTML = questions.map(cardHtml).join('');
        initPairwiseTables(host);
    }

    function announceCards(payload) {
        var qn = (payload.questions || []).length;
        var rn = parseInt((payload.summary || {}).responses, 10) || 0;
        announce(qn + (qn === 1 ? ' question' : ' questions') + ', ' +
            rn + (rn === 1 ? ' response' : ' responses') + ' shown.');
    }

    /* ---------------------------------------------------------
       Rows table
       --------------------------------------------------------- */

    function personaCell(row) {
        if (row.persona && row.mundane_id) {
            return '<a href="' + esc(UIR + 'Player/profile/' + row.mundane_id) + '">' + esc(row.persona) + '</a>';
        }
        if (row.persona) { return esc(row.persona); }
        return '<span class="svr-cell-anon">—</span>';
    }


    /* In the table the row itself is the tab stop (a hundred extra stops per
       page would bury the keyboard user), so cell tips are hover-only there and
       the reason is spelled out in the response panel instead. */
    function maskedCell(inPanel) {
        if (inPanel) {
            return '<span class="svr-cell-anon svr-masked">Hidden &mdash; fewer than ' + minCell() +
                ' partial-consent respondents share it</span>';
        }
        return '<span class="svr-cell-anon svr-masked" data-tip="Hidden: fewer than ' + minCell() +
            ' partial-consent respondents share this value, so showing it could identify them.">Hidden</span>';
    }

    function kingdomCell(row, inPanel) {
        if (row.kingdom) { return esc(row.kingdom); }
        if (row.masked && row.consent === 'partial') { return maskedCell(inPanel); }
        return '<span class="svr-cell-anon">—</span>';
    }

    function tenureCell(row, inPanel) {
        if (row.tenure_label) { return esc(row.tenure_label); }
        if (row.tenure_years !== null && row.tenure_years !== undefined) {
            return esc(row.tenure_years + (row.tenure_years === 1 ? ' year' : ' years'));
        }
        if (row.masked && row.consent === 'partial') { return maskedCell(inPanel); }
        return '<span class="svr-cell-anon">—</span>';
    }

    /* "September 10, 2026" (+ "10:14 AM" for full consent). Partial and
       anonymous rows carry the day only — say so rather than print a fake
       midnight (#31). */
    function submittedHtml(row, inPanel) {
        var d = humanDate(row.submitted_at);
        if (!row.submitted_at) { return '<span class="svr-cell-anon">—</span>'; }
        if (row.time_withheld) {
            if (inPanel) {
                return '<span class="svr-when">' + esc(d.date) + '<span class="svr-when-time">Time withheld</span></span>';
            }
            return '<span class="svr-when" data-tip="Time withheld: this respondent shared only the day.">' +
                esc(d.date) + ' <i class="fas fa-clock svr-when-icon" aria-hidden="true"></i>' +
                '<span class="sv-visually-hidden"> (time withheld)</span></span>';
        }
        return '<span class="svr-when">' + esc(d.date) + (d.time ? '<span class="svr-when-time">' + esc(d.time) + '</span>' : '') + '</span>';
    }

    function consentLabel(c) {
        return { full: 'Full', partial: 'Partial', anonymous: 'Anonymous' }[c] || c;
    }

    /* A date answer reads "August 13, 1995" on screen, like every other date on
       the page; the CSV keeps the ISO value. */
    function answerText(q, v) {
        if (q && q.type === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(String(v))) { return humanDate(v).date; }
        return String(v);
    }

    function rowArray(row, qids, idx) {
        var out = [
            '<span class="svr-row-num" data-svr-idx="' + idx + '">' + esc(row.response_id) + '</span>',
            '<span class="svr-tag">' + esc(consentLabel(row.consent)) + '</span>' +
                (row.is_test ? ' <span class="svr-tag">test</span>' : ''),
            personaCell(row),
            kingdomCell(row),
            tenureCell(row),
            submittedHtml(row),
            duration(row.duration_seconds)
        ];
        qids.forEach(function (qid) {
            var v = (row.answers || {})[qid];
            out.push(v === undefined || v === null || v === '' ? '<span class="svr-cell-anon">—</span>' :
                '<span class="svr-cell-answer' + ((questionById(qid) || {}).type === 'pairwise' ? ' svr-cell-clamp' : '') + '">' +
                esc(answerText(questionById(qid), v)) + '</span>');
        });
        return out;
    }

    function initRows() {
        if (SHARED) { return; }
        var table = $('svr-rows');
        if (!table || !window.jQuery || !window.jQuery.fn || !window.jQuery.fn.DataTable) { return; }

        var qids  = QUESTIONS.map(function (q) { return q.question_id; });
        var heads = ['#', 'Consent', 'Persona', 'Kingdom', 'Years played', 'Submitted', 'Time'].map(function (h) {
            return '<th>' + esc(h) + '</th>';
        }).concat(QUESTIONS.map(function (q) {
            /* Q1..Qn, full prompt in a tip (#36): 23 prose headers made the
               table about 6,000px wide. */
            return '<th><span class="svr-qhead" tabindex="0" data-tip="' + esc(q.prompt || ('Question ' + q.question_id)) +
                '">Q' + QNUM[q.question_id] + '</span><span class="sv-visually-hidden"> ' +
                esc(q.prompt || '') + '</span></th>';
        }));

        table.innerHTML = '<thead><tr>' + heads.join('') + '</tr></thead><tbody></tbody>';

        state.table = window.jQuery(table).DataTable({
            serverSide  : true,
            processing  : true,
            searching   : false,
            ordering    : false,
            lengthChange: false,
            pageLength  : ROWS_PAGE,
            deferRender : true,
            autoWidth   : false,
            language    : {
                /* Neutral: the same table is empty on an unanswered survey
                   too; the notice above the cards says which case it is. */
                emptyTable : 'No responses to show.',
                processing : 'Loading…',
                infoEmpty  : 'No responses',
                zeroRecords: 'No responses to show.'
            },
            /* "of 1 response", never "of 1 responses". */
            infoCallback: function (settings, start, end, max, total) {
                if (!total) { return 'No responses'; }
                return 'Showing ' + thousands(start) + ' to ' + thousands(end) + ' of ' +
                    plural(total, 'response', 'responses').replace(/^\d+/, thousands(total));
            },
            createdRow: function (tr) {
                tr.setAttribute('tabindex', '0');
                tr.classList.add('svr-row');
            },
            ajax: function (data, callback) {
                /* The APPLIED filters, never the controls: paging after editing
                   (but not applying) a filter must not change the slice (#27). */
                state.rowsDraw = data.draw;
                /* DataTables drops a stale draw by its draw number; the rows the
                   response panel reads must be dropped the same way, or a slow
                   earlier page would replace the page on screen. */
                var latest = function () { return data.draw === state.rowsDraw; };
                post('rows', {
                    SurveyId: SURVEY_ID,
                    Filters : JSON.stringify(state.appliedFilters || DEFAULT_FILTERS),
                    Offset  : data.start,
                    Limit   : data.length
                }).then(function (r) {
                    if (r.status !== 0) {
                        if (latest()) { failed(r); state.pageRows = []; }
                        callback({ draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
                        return;
                    }
                    var rows = r.rows || [];
                    if (latest()) {
                        state.pageRows = rows;
                        state.pageTotal = r.total || 0;
                    }
                    callback({
                        draw           : data.draw,
                        recordsTotal   : r.total,
                        recordsFiltered: r.total,
                        data           : rows.map(function (row, i) { return rowArray(row, qids, i); })
                    });
                }).catch(function () {
                    if (latest()) { state.pageRows = []; }
                    callback({ draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: [] });
                });
            }
        });

        /* A new page or filter set replaces the rows the panel is reading. */
        window.jQuery(table).on('draw.dt', function () {
            if (state.panelIdx !== -1) { closePanel(false); }
        });

        table.addEventListener('click', function (e) {
            if (e.target.closest('a, button')) { return; }
            var tr = e.target.closest('tr.svr-row');
            if (tr) { openPanelFromRow(tr); }
        });
        table.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') { return; }
            var tr = e.target.closest('tr.svr-row');
            if (!tr || e.target !== tr) { return; }
            e.preventDefault();
            openPanelFromRow(tr);
        });
    }

    /* ---------------------------------------------------------
       Individual response panel (#36)
       --------------------------------------------------------- */

    function openPanelFromRow(tr) {
        var marker = tr.querySelector('[data-svr-idx]');
        if (!marker) { return; }
        var idx = parseInt(marker.getAttribute('data-svr-idx'), 10);
        if (isNaN(idx) || !state.pageRows[idx]) { return; }
        state.panelReturn = tr;
        openPanel(idx);
    }

    function metaRow(label, valueHtml) {
        return '<div class="svr-panel-meta-row"><dt>' + esc(label) + '</dt><dd>' + valueHtml + '</dd></div>';
    }

    function renderPanel() {
        var row = state.pageRows[state.panelIdx];
        if (!row) { return; }
        var title = $('svr-panel-title');
        title.textContent = 'Response ' + row.response_id + ' of ' + state.pageTotal;

        var html = '<dl class="svr-panel-meta">' +
            metaRow('Consent', '<span class="svr-tag">' + esc(consentLabel(row.consent)) + '</span>' +
                (row.is_test ? ' <span class="svr-tag">test</span>' : ''));
        if (row.consent === 'full') { html += metaRow('Persona', personaCell(row)); }
        if (row.consent !== 'anonymous') {
            html += metaRow('Kingdom', kingdomCell(row, true));
            html += metaRow('Years played', tenureCell(row, true));
        }
        html += metaRow('Submitted', submittedHtml(row, true));
        if (row.duration_seconds !== null && row.duration_seconds !== undefined) {
            html += metaRow('Time taken', esc(duration(row.duration_seconds)));
        }
        html += '</dl><ol class="svr-panel-answers">';
        QUESTIONS.forEach(function (q) {
            var v = (row.answers || {})[q.question_id];
            var has = !(v === undefined || v === null || v === '');
            html += '<li class="svr-panel-qa"><div class="svr-panel-q"><span class="svr-panel-qnum">Q' +
                QNUM[q.question_id] + '</span> ' + esc(q.prompt || ('Question ' + q.question_id)) + '</div>' +
                '<div class="svr-panel-a' + (has ? '' : ' svr-cell-anon') + '">' + (has ? esc(answerText(q, v)) : 'No answer') + '</div></li>';
        });
        html += '</ol>';
        $('svr-panel-body').innerHTML = html;
        $('svr-panel-body').scrollTop = 0;

        $('svr-panel-prev').disabled = state.panelIdx <= 0;
        $('svr-panel-next').disabled = state.panelIdx >= state.pageRows.length - 1;

        Array.prototype.forEach.call(document.querySelectorAll('#svr-rows tr.svr-row-active'), function (r) {
            r.classList.remove('svr-row-active');
        });
        var marker = document.querySelector('#svr-rows [data-svr-idx="' + state.panelIdx + '"]');
        var tr = marker ? marker.closest('tr') : null;
        if (tr) { tr.classList.add('svr-row-active'); state.panelReturn = tr; }
    }

    function openPanel(idx) {
        var panel = $('svr-panel');
        if (!panel) { return; }
        state.panelIdx = idx;
        panel.hidden = false;
        renderPanel();
        $('svr-panel-title').focus();
    }

    function stepPanel(d) {
        var next = state.panelIdx + d;
        if (next < 0 || next >= state.pageRows.length) { return; }
        state.panelIdx = next;
        renderPanel();
        $('svr-panel-title').focus();
    }

    function closePanel(restoreFocus) {
        var panel = $('svr-panel');
        if (!panel || panel.hidden) { state.panelIdx = -1; return; }
        panel.hidden = true;
        state.panelIdx = -1;
        Array.prototype.forEach.call(document.querySelectorAll('#svr-rows tr.svr-row-active'), function (r) {
            r.classList.remove('svr-row-active');
        });
        if (restoreFocus !== false && state.panelReturn && document.body.contains(state.panelReturn)) {
            state.panelReturn.focus();
        }
        state.panelReturn = null;
    }

    /* data-tip tooltips are shown by the shared script/survey-tip.js. */

    /* ---------------------------------------------------------
       Summary for sharing (#5)
       --------------------------------------------------------- */

    function setSummaryMode(on, redraw) {
        state.summaryMode = !!on;
        var root = $('svr-root');
        if (root) { root.classList.toggle('svr-summary-mode', state.summaryMode); }
        var btn = $('svr-summary-toggle');
        if (btn) { btn.setAttribute('aria-pressed', state.summaryMode ? 'true' : 'false'); }
        if (state.summaryMode) { closePanel(false); }
        var supRows = document.querySelector('#svr-suppressed .svr-sup-rows');
        if (supRows) { supRows.hidden = state.summaryMode; }
        /* The row table is not even fetched in summary mode (it would log a
           rows_view for data nobody sees); build it on the way out. */
        if (!state.summaryMode && !state.table) { initRows(); }
        if (redraw && state.payload) {
            renderCards(state.payload.questions);
            buildCharts();
        }
        renderCaption();
        writeHash();
    }

    /* ---------------------------------------------------------
       Load
       --------------------------------------------------------- */

    function setBusy(b) {
        state.loading = b;
        var cards = $('svr-cards');
        if (cards) { cards.setAttribute('aria-busy', b ? 'true' : 'false'); }
        var apply = $('svr-apply');
        if (apply) { apply.disabled = b; }
    }

    /* The latest request wins (#27): each call aborts the one in flight and
       a response whose sequence number is stale is dropped. The export link
       and the URL are synced by the request that actually renders. */
    function load() {
        var filters = state.appliedFilters || DEFAULT_FILTERS;
        /* A shared viewer has no date bounds (the server drops them: a public
           home-park credit is dated the day taken). A #filters= hash from a
           manager's link may still carry them, so drop them here as well, or
           the caption would describe a filter that was never applied. */
        if (SHARED && (filters.date_from || filters.date_to)) {
            var undated = {};
            Object.keys(filters).forEach(function (k) { undated[k] = filters[k]; });
            undated.date_from = null;
            undated.date_to = null;
            filters = state.appliedFilters = undated;
        }
        var seq = ++state.seq;
        if (state.abort) { try { state.abort.abort(); } catch (e) { /* done */ } }
        state.abort = window.AbortController ? new AbortController() : null;
        setBusy(true);

        post('results', { SurveyId: SURVEY_ID, Filters: JSON.stringify(filters), Context: CFG.context || '' }, state.abort ? state.abort.signal : null)
            .then(function (r) {
                if (seq !== state.seq) { return; }
                setBusy(false);
                if (r.status !== 0) {
                    failed(r);
                    destroyCharts();
                    $('svr-cards').innerHTML = '';
                    return;
                }
                state.payload = { summary: r.summary || {}, questions: r.questions || [] };
                syncExport(filters);
                writeHash();
                renderSummary(state.payload.summary, filters);
                destroyCharts();
                renderCards(state.payload.questions);
                renderCaption();
                announceCards(state.payload);
                /* Cards are in the DOM but not yet laid out; wait one frame so
                   every chart container has a real width. A context that is
                   never painted (hidden tab, print/screenshot harness, a
                   background restore) never fires that frame, so a timer backs
                   it up and whichever arrives first wins. */
                afterPaint(buildCharts);
            })
            .catch(function (e) {
                if (seq !== state.seq) { return; }
                setBusy(false);
                if (e && e.name === 'AbortError') { return; }
                notice('Could not reach the server. Check your connection and try again.', true);
            });
    }

    /* ---------------------------------------------------------
       Wiring
       --------------------------------------------------------- */

    /* Expands one truncated text list. The button is relabelled in place rather
       than removed: removing the element that currently has focus drops focus
       to <body> and throws a keyboard or screen-reader user back to the top of
       the page. */
    function expandTextList(btn, tell) {
        if (btn.getAttribute('aria-disabled') === 'true') { return; }
        var prefix = btn.getAttribute('data-more');
        var list   = $(prefix + '-list');
        if (!list || !state.payload) { return; }
        var qid = parseInt(prefix.replace(/^svr-(txt|oth)-/, ''), 10);
        var kind = prefix.indexOf('svr-oth-') === 0 ? 'other_texts' : 'texts';
        var q = payloadQuestion(qid);
        if (!q) { return; }
        var all = (q.agg && q.agg[kind]) || [];
        list.innerHTML = all.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('');
        btn.setAttribute('aria-disabled', 'true');
        var label = textListLabel(all.length, parseInt(btn.getAttribute('data-total'), 10) || all.length);
        btn.textContent = label;
        if (tell) { announce(/\.$/.test(label) ? label : label + '.'); }
    }

    /* Everything must be on the paper: expand every truncated list and open
       every collapsed "Other" block (summary mode renders neither), build every
       chart including the ones never scrolled to, and draw them in the light
       theme because the browser drops the dark background. */
    function expandForPrint() {
        Array.prototype.forEach.call(document.querySelectorAll('.svr-showmore'), function (b) {
            expandTextList(b, false);
        });
        Array.prototype.forEach.call(document.querySelectorAll('details.svr-other'), function (d) {
            if (!d.open) { d.open = true; d.setAttribute('data-svr-print-open', '1'); }
        });
    }

    function restoreAfterPrint() {
        Array.prototype.forEach.call(document.querySelectorAll('details.svr-other[data-svr-print-open]'), function (d) {
            d.open = false;
            d.removeAttribute('data-svr-print-open');
        });
    }

    function onApply() {
        state.appliedFilters = readFilters();
        syncFilterToggle();
        closePanel(false);
        load();
        if (state.table) { state.table.ajax.reload(null, true); }
        /* On a phone the open filter card sits between the stats and the
           charts; fold it away once applied and keep focus on its toggle. */
        if (isNarrow()) {
            setFiltersOpen(false);
            var tg = $('svr-filters-toggle');
            if (tg) { tg.focus(); }
        }
    }

    function syncDateClear(id) {
        var btn = document.querySelector('.svr-date-clear[data-clear="' + id + '"]');
        if (btn) { btn.hidden = !($(id) && $(id).value); }
    }

    function initDatePickers() {
        ['svr-date-from', 'svr-date-to'].forEach(function (id) {
            var node = $(id);
            if (!node) { return; }
            if (typeof window.flatpickr !== 'function') {
                /* CDN blocked: fall back to the native control. */
                node.type = 'date';
                node.addEventListener('change', function () { syncDateClear(id); });
                return;
            }
            state.pickers[id] = window.flatpickr(node, {
                /* The alt input everywhere, phones included, so the date always
                   reads "September 10, 2026". */
                disableMobile: true,
                dateFormat   : 'Y-m-d',
                altInput     : true,
                altFormat    : 'F j, Y',
                altInputClass: 'sv-input svr-date svr-date-alt',
                allowInput   : false,
                onChange     : function () { syncDateClear(id); }
            });
            if (state.pickers[id].altInput) {
                state.pickers[id].altInput.placeholder = node.placeholder;
                /* The visible input is the alt one; point the label at it. */
                var lbl = document.querySelector('label[for="' + id + '"]');
                state.pickers[id].altInput.id = id + '-alt';
                if (lbl) { lbl.setAttribute('for', id + '-alt'); }
            }
        });
        Array.prototype.forEach.call(document.querySelectorAll('.svr-date-clear'), function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-clear');
                setDate(id, '');
                var fp = state.pickers[id];
                var focusEl = fp && fp.altInput ? fp.altInput : $(id);
                if (focusEl) { focusEl.focus(); }
            });
        });
    }

    function restoreFromUrl() {
        var f = filtersFromHash();
        writeControls(f || DEFAULT_FILTERS);
        /* Re-read, so ids the controls do not offer (a kingdom no longer
           present, a deleted question) drop out of the applied set. */
        state.appliedFilters = readFilters();
        syncFilterToggle();
        syncExport(state.appliedFilters);
        state.summaryMode = summaryRequested();
        var root = $('svr-root');
        if (root) { root.classList.toggle('svr-summary-mode', state.summaryMode); }
        var btn = $('svr-summary-toggle');
        if (btn) { btn.setAttribute('aria-pressed', state.summaryMode ? 'true' : 'false'); }
    }

    /* ---------------------------------------------------------
       Clear Results (managers only): shared .sv-overlay shell, a
       fresh server count on every open, and a confirm button that
       stays disabled through a 5-second countdown restarted on
       each open. Never a native confirm().
       --------------------------------------------------------- */

    var CLEAR_SECONDS = 5;
    var CLEAR_FLASH   = 'svr-cleared-' + SURVEY_ID;
    var clearState    = { timer: null, busy: false, ready: false, opener: null, seq: 0 };

    function clearFocusables() {
        return Array.prototype.filter.call(
            $('svr-clear-overlay').querySelectorAll('button:not([disabled])'),
            function (b) { return b.offsetParent !== null; });
    }

    function clearError(msg) {
        var el = $('svr-clear-error');
        el.textContent = msg || '';
        el.style.display = msg ? 'block' : 'none';
    }

    function clearArm(ready) {
        var ok = $('svr-clear-ok');
        clearState.ready = ready;
        ok.disabled = !ready;
        ok.setAttribute('aria-disabled', ready ? 'false' : 'true');
    }

    function clearCountdown() {
        var ok = $('svr-clear-ok');
        var left = CLEAR_SECONDS;
        clearInterval(clearState.timer);
        clearArm(false);
        ok.textContent = 'Clear results (' + left + ')';
        clearState.timer = setInterval(function () {
            left--;
            if (left > 0) { ok.textContent = 'Clear results (' + left + ')'; return; }
            clearInterval(clearState.timer);
            clearState.timer = null;
            ok.textContent = 'Clear results';
            clearArm(!clearState.busy);
        }, 1000);
    }

    function openClear() {
        var ov = $('svr-clear-overlay');
        var seq = ++clearState.seq;
        clearState.opener = document.activeElement;
        clearState.busy = false;
        clearError('');
        $('svr-clear-count').textContent = 'Counting responses…';
        ov.classList.add('sv-open');
        clearCountdown();
        $('svr-clear-cancel').focus();
        post('clear_results', { SurveyId: SURVEY_ID }).then(function (r) {
            if (seq !== clearState.seq) { return; }
            if (r.status !== 0) { clearError(r.error || statusMessage(r)); return; }
            var n = parseInt(r.count, 10) || 0;
            $('svr-clear-count').innerHTML = n === 0
                ? 'There are no responses to delete.'
                : '<strong>' + esc(thousands(n)) + ' ' + (n === 1 ? 'response' : 'responses') +
                  '</strong> (test and real) will be permanently deleted.';
        }).catch(function () {
            if (seq === clearState.seq) { clearError('Could not reach the server to count the responses.'); }
        });
    }

    function closeClear() {
        if (clearState.busy) { return; }
        clearState.seq++;
        clearInterval(clearState.timer);
        clearState.timer = null;
        clearArm(false);
        $('svr-clear-overlay').classList.remove('sv-open');
        var back = clearState.opener;
        clearState.opener = null;
        if (back && back.focus) { back.focus(); }
    }

    function confirmClear() {
        /* The disabled attribute already stops clicks and Enter/Space; this
           guard also covers a scripted click and a second click in flight. */
        if (!clearState.ready || clearState.busy) { return; }
        clearState.busy = true;
        clearArm(false);
        var ok = $('svr-clear-ok');
        ok.classList.add('sv-is-busy');
        ok.textContent = 'Clearing…';
        clearError('');
        var fail = function (msg) {
            clearState.busy = false;
            ok.classList.remove('sv-is-busy');
            ok.textContent = 'Clear results';
            clearArm(true);
            clearError(msg);
        };
        post('clear_results', { SurveyId: SURVEY_ID, Confirm: 1 }).then(function (r) {
            if (r.status !== 0) { fail(r.error || statusMessage(r)); return; }
            try { sessionStorage.setItem(CLEAR_FLASH, String(parseInt(r.cleared, 10) || 0)); } catch (e) { /* no storage */ }
            /* Reload without the filter hash: the kingdom list and every chart
               come back from the server as the empty state. */
            location.replace(location.pathname + location.search);
        }).catch(function () { fail('Could not reach the server. Check your connection and try again.'); });
    }

    function initClear() {
        var btn = $('svr-clear');
        var ov  = $('svr-clear-overlay');
        if (!btn || !ov) { return; }
        btn.addEventListener('click', openClear);
        $('svr-clear-cancel').addEventListener('click', closeClear);
        $('svr-clear-ok').addEventListener('click', confirmClear);
        ov.addEventListener('click', function (e) { if (e.target === ov) { closeClear(); } });
        ov.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.key === 'Esc') {
                e.preventDefault();
                e.stopPropagation();
                closeClear();
                return;
            }
            if (e.key !== 'Tab') { return; }
            var f = clearFocusables();
            if (!f.length) { e.preventDefault(); return; }
            var first = f[0], last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });

        var flash = null;
        try { flash = sessionStorage.getItem(CLEAR_FLASH); sessionStorage.removeItem(CLEAR_FLASH); } catch (e) { /* no storage */ }
        var note = $('svr-cleared');
        if (flash !== null && note) {
            var n = parseInt(flash, 10) || 0;
            note.hidden = false;
            note.innerHTML = '<i class="fas fa-circle-check" aria-hidden="true"></i><span>Results cleared: ' +
                esc(thousands(n)) + ' ' + (n === 1 ? 'response' : 'responses') + ' deleted. Attendance credits already posted were kept.</span>';
        }
    }

    function boot() {
        initDatePickers();
        restoreFromUrl();
        initClear();

        if ($('svr-apply')) { $('svr-apply').addEventListener('click', onApply); }
        if ($('svr-reset')) {
            $('svr-reset').addEventListener('click', resetFilters);
        }

        /* Filters disclosure below 900px (#33): collapsed on arrival so the
           first chart sits right after the stat row. */
        if ($('svr-filters-toggle')) {
            setFiltersOpen(!isNarrow());
            $('svr-filters-toggle').addEventListener('click', function () {
                setFiltersOpen($('svr-sidebar').classList.contains('svr-collapsed'));
            });
        }

        if ($('svr-summary-toggle')) {
            $('svr-summary-toggle').addEventListener('click', function () { setSummaryMode(!state.summaryMode, true); });
        }
        if ($('svr-print')) {
            $('svr-print').addEventListener('click', function () { window.print(); });
        }

        document.addEventListener('click', function (e) {
            /* "Show all" on a truncated text list. */
            var more = e.target.closest ? e.target.closest('.svr-showmore') : null;
            if (more) { expandTextList(more, true); return; }

            /* Cross-tab percent / count toggle. */
            var xt = e.target.closest ? e.target.closest('.svr-xt-btn') : null;
            if (xt) {
                var qid = parseInt(xt.getAttribute('data-xt-qid'), 10);
                state.xtMode[qid] = xt.getAttribute('data-xt-mode');
                Array.prototype.forEach.call(xt.parentNode.querySelectorAll('.svr-xt-btn'), function (b) {
                    b.setAttribute('aria-pressed', b === xt ? 'true' : 'false');
                });
                rebuildOne(qid);
                return;
            }

            var reload = e.target.closest ? e.target.closest('[data-svr-reload]') : null;
            if (reload) { e.preventDefault(); location.reload(); return; }

            /* "Reset filters" in the zero-match notice. The button is re-rendered
               away, so hand focus to the sidebar's own Reset (on a phone
               onApply() already parks it on the Filters toggle). */
            var rf = e.target.closest ? e.target.closest('[data-svr-reset-filters]') : null;
            if (rf) {
                resetFilters();
                if (!isNarrow() && $('svr-reset')) { $('svr-reset').focus(); }
            }
        });

        if ($('svr-panel-prev')) { $('svr-panel-prev').addEventListener('click', function () { stepPanel(-1); }); }
        if ($('svr-panel-next')) { $('svr-panel-next').addEventListener('click', function () { stepPanel(1); }); }
        if ($('svr-panel-close')) { $('svr-panel-close').addEventListener('click', function () { closePanel(true); }); }

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            var panel = $('svr-panel');
            if (panel && !panel.hidden) { closePanel(true); }
        });

        /* A pasted link in the same tab only changes the hash. */
        window.addEventListener('hashchange', function () {
            var hadTable = !!state.table;
            restoreFromUrl();
            closePanel(false);
            load();
            /* Summary mode shows no rows, so it fetches none. */
            if (!state.summaryMode) {
                if (!hadTable) { initRows(); } else { state.table.ajax.reload(null, true); }
            }
        });

        /* Redraw on a theme change — the toggle stamps html[data-theme], and the
           system setting moves without touching the attribute. */
        var themeObs = new MutationObserver(function () { buildCharts(); });
        themeObs.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        if (window.matchMedia) {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            if (mq.addEventListener) {
                mq.addEventListener('change', function () { buildCharts(); });
            } else if (mq.addListener) {
                mq.addListener(function () { buildCharts(); });
            }
        }

        window.addEventListener('beforeprint', function () {
            expandForPrint();
            if (svIsDark()) { printingLight = true; }
            buildAllCharts();
        });

        window.addEventListener('afterprint', function () {
            restoreAfterPrint();
            if (printingLight) { printingLight = false; }
            buildCharts();
        });

        /* Charts do not reflow on their own when the grid changes track count.
           Width only: a phone's URL bar changes the height on every scroll. */
        var rt = null;
        var lastW = window.innerWidth;
        window.addEventListener('resize', function () {
            if (window.innerWidth === lastW) { return; }
            lastW = window.innerWidth;
            clearTimeout(rt);
            rt = setTimeout(buildCharts, 250);
        });

        load();
        if (!state.summaryMode) { initRows(); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
