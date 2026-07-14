<?php if (!empty($Error)): ?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css">
<div class="rp-context" style="background:#fed7d7;border-color:#fc8181;color:#9b2c2c;">
	<i class="fas fa-exclamation-circle rp-context-icon" style="color:#e53e3e;"></i>
	<span><?= htmlspecialchars($Error) ?></span>
</div>
<?php return; endif; ?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css">

<style>
/* ── QualTest Take Page — qt- prefix ─────────────────────── */

/* ── View Transitions ──────────────────────────────────────── */
.qt-view {
	opacity: 0;
	transform: translateY(10px);
	transition: opacity 0.25s ease, transform 0.25s ease;
	display: none;
}
.qt-view-active {
	display: block;
	opacity: 1;
	transform: translateY(0);
}
.qt-view-entering {
	display: block;
	opacity: 0;
	transform: translateY(10px);
}

/* ── Pre-Test: Overview Hero ────────────────────────────────── */
.qt-overview {
	background: #fff;
	border: 1px solid var(--rp-border);
	border-radius: 8px;
	overflow: hidden;
	margin-bottom: 20px;
}
.qt-overview-header {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 28px 28px 20px;
	border-bottom: 1px solid var(--rp-border);
	background: var(--rp-bg-light);
}
.qt-overview-icon {
	width: 56px;
	height: 56px;
	border-radius: 12px;
	background: linear-gradient(135deg, #2b6cb0, #4338ca);
	display: flex;
	align-items: center;
	justify-content: center;
	color: #fff;
	font-size: 1.5rem;
	flex-shrink: 0;
}
.qt-overview-info { flex: 1; }
.qt-overview-title {
	font-size: 1.25rem;
	font-weight: 700;
	color: var(--rp-text);
	margin: 0;
	/* reset global heading styles */
	background: transparent;
	border: none;
	padding: 0;
	border-radius: 0;
	text-shadow: none;
}
.qt-overview-scope {
	font-size: 0.85rem;
	color: var(--rp-text-muted);
	margin-top: 3px;
}
.qt-overview-scope a {
	color: var(--rp-accent);
	text-decoration: none;
}
.qt-overview-scope a:hover { text-decoration: underline; }

/* ── Stat chips ─────────────────────────────────────────────── */
.qt-stat-chips {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	padding: 20px 28px;
	border-bottom: 1px solid var(--rp-border);
}
.qt-stat-chip {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 18px;
	background: var(--rp-bg-light);
	border: 1px solid var(--rp-border);
	border-radius: 8px;
	flex: 1 1 0;
	min-width: 120px;
}
.qt-stat-chip-icon {
	font-size: 1rem;
	color: var(--rp-accent);
	width: 20px;
	text-align: center;
	flex-shrink: 0;
}
.qt-stat-chip-text {
	font-size: 0.82rem;
	color: var(--rp-text-muted);
	line-height: 1.3;
}
.qt-stat-chip-value {
	font-weight: 700;
	color: var(--rp-text);
	font-size: 0.95rem;
	display: block;
}

/* ── Status badge (large pill) ──────────────────────────────── */
.qt-status-section { padding: 20px 28px; border-bottom: 1px solid var(--rp-border); }
.qt-status-pill {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 8px 20px;
	border-radius: 24px;
	font-size: 0.95rem;
	font-weight: 700;
}
.qt-status-pill-pass    { background: #c6f6d5; color: #276749; }
.qt-status-pill-expired { background: #fef3c7; color: #b7791f; }
.qt-status-pill-none    { background: #edf2f7; color: #718096; }
.qt-status-pill-blocked { background: #fed7d7; color: #9b2c2c; }
.qt-status-detail {
	font-size: 0.88rem;
	color: var(--rp-text-muted);
	line-height: 1.55;
	margin-top: 10px;
}
.qt-status-detail strong { color: var(--rp-text-body); }

/* ── Instructions callout ───────────────────────────────────── */
.qt-instructions-section { padding: 20px 28px; border-bottom: 1px solid var(--rp-border); }
.qt-instructions-box {
	padding: 14px 18px;
	background: #ebf4ff;
	border: 1px solid #bee3f8;
	border-left: 4px solid #2b6cb0;
	border-radius: 0 6px 6px 0;
	font-size: 0.92rem;
	color: #2c5282;
	line-height: 1.65;
	white-space: pre-line;
}
.qt-instructions-label {
	font-size: 0.72rem;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: #2b6cb0;
	margin-bottom: 8px;
	display: flex;
	align-items: center;
	gap: 5px;
}

/* ── "What to expect" blurb ─────────────────────────────────── */
.qt-expect-section { padding: 20px 28px; border-bottom: 1px solid var(--rp-border); }
.qt-expect-blurb {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 12px 16px;
	background: #f7fafc;
	border: 1px solid var(--rp-border);
	border-radius: 6px;
	font-size: 0.88rem;
	color: var(--rp-text-body);
	line-height: 1.55;
}
.qt-expect-blurb-icon {
	color: var(--rp-accent);
	flex-shrink: 0;
	margin-top: 2px;
}

/* ── CTA area ───────────────────────────────────────────────── */
.qt-cta-section { padding: 24px 28px; text-align: center; }
.qt-begin-btn {
	display: inline-flex;
	align-items: center;
	gap: 10px;
	padding: 14px 40px;
	background: linear-gradient(135deg, #2b6cb0, #2c5282);
	color: #fff;
	border: none;
	border-radius: 8px;
	font-size: 1.05rem;
	font-weight: 700;
	cursor: pointer;
	transition: background 0.15s, transform 0.15s, box-shadow 0.15s;
	box-shadow: 0 2px 8px rgba(43, 108, 176, 0.25);
}
.qt-begin-btn:hover {
	background: linear-gradient(135deg, #2c5282, #1e3a5f);
	transform: translateY(-1px);
	box-shadow: 0 4px 14px rgba(43, 108, 176, 0.35);
}
.qt-begin-btn:active { transform: translateY(0); }
.qt-cta-back {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	margin-top: 14px;
	font-size: 0.88rem;
	color: var(--rp-text-muted);
	text-decoration: none;
	transition: color 0.15s;
}
.qt-cta-back:hover { color: var(--rp-accent); }
.qt-blocked-msg {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 14px 18px;
	background: #fed7d7;
	border: 1px solid #feb2b2;
	border-radius: 6px;
	font-size: 0.9rem;
	color: #9b2c2c;
	line-height: 1.5;
	max-width: 520px;
	margin: 0 auto 14px;
	text-align: left;
}

/* ── Question View ─────────────────────────────────────────── */
.qt-question-card {
	background: #fff;
	border: 1px solid var(--rp-border);
	border-radius: 8px;
	padding: 24px 28px;
	margin-bottom: 20px;
}

/* Segmented progress bar */
.qt-progress-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 10px;
}
.qt-progress-text {
	font-size: 0.82rem;
	font-weight: 600;
	color: var(--rp-text-muted);
}
.qt-progress-score {
	font-size: 0.78rem;
	color: var(--rp-text-hint);
}
.qt-progress-segments {
	display: flex;
	gap: 3px;
	margin-bottom: 24px;
}
.qt-progress-seg {
	flex: 1;
	height: 8px;
	border-radius: 4px;
	background: #e2e8f0;
	transition: background 0.3s;
}
.qt-progress-seg-done {
	background: #38a169;
}
.qt-progress-seg-current {
	background: #2b6cb0;
	box-shadow: 0 0 0 2px rgba(43, 108, 176, 0.25);
}

/* Question text */
.qt-q-text {
	font-size: 1.15rem;
	font-weight: 600;
	color: #2d3748;
	margin-bottom: 22px;
	line-height: 1.6;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--rp-border);
}

/* Answer options — card style */
.qt-answers { list-style: none; padding: 0; margin: 0 0 18px; }
.qt-answer-item { margin-bottom: 10px; }
.qt-answer-label {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 14px 18px;
	border: 2px solid #e2e8f0;
	border-radius: 8px;
	cursor: pointer;
	font-size: 0.95rem;
	color: #2d3748;
	transition: background 0.15s, border-color 0.15s, box-shadow 0.15s, transform 0.15s;
	line-height: 1.5;
}
.qt-answer-radio {
	width: 20px;
	height: 20px;
	border-radius: 50%;
	border: 2px solid #cbd5e0;
	flex-shrink: 0;
	margin-top: 1px;
	display: flex;
	align-items: center;
	justify-content: center;
	transition: border-color 0.15s, background 0.15s;
}
.qt-answer-radio-inner {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: transparent;
	transition: background 0.15s;
}
.qt-answer-label:hover:not(.qt-ans-disabled):not(.qt-ans-correct):not(.qt-ans-wrong) {
	background: #f7fafc;
	border-color: #bee3f8;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
	transform: translateY(-1px);
}
.qt-answer-label:hover:not(.qt-ans-disabled):not(.qt-ans-correct):not(.qt-ans-wrong) .qt-answer-radio {
	border-color: #2b6cb0;
}
.qt-answer-label.qt-ans-selected:not(.qt-ans-correct):not(.qt-ans-wrong) {
	border-color: #2b6cb0;
	background: #ebf8ff;
}
.qt-answer-label.qt-ans-selected:not(.qt-ans-correct):not(.qt-ans-wrong) .qt-answer-radio {
	border-color: #2b6cb0;
}
.qt-answer-label.qt-ans-selected:not(.qt-ans-correct):not(.qt-ans-wrong) .qt-answer-radio-inner {
	background: #2b6cb0;
}
.qt-answer-label.qt-ans-correct {
	background: #f0fff4;
	border-color: #38a169;
	color: #276749;
	pointer-events: none;
}
.qt-answer-label.qt-ans-correct .qt-answer-radio {
	border-color: #38a169;
	background: #38a169;
}
.qt-answer-label.qt-ans-correct .qt-answer-radio-inner {
	background: #fff;
}
.qt-answer-label.qt-ans-wrong {
	background: #fff5f5;
	border-color: #e53e3e;
	color: #9b2c2c;
	pointer-events: none;
}
.qt-answer-label.qt-ans-wrong .qt-answer-radio {
	border-color: #e53e3e;
	background: #e53e3e;
}
.qt-answer-label.qt-ans-wrong .qt-answer-radio-inner {
	background: #fff;
}
.qt-answer-label.qt-ans-disabled {
	pointer-events: none;
	opacity: 0.55;
}

/* Feedback bar with slide-down animation */
.qt-feedback {
	padding: 12px 18px;
	border-radius: 6px;
	font-size: 0.92rem;
	font-weight: 600;
	margin-top: 14px;
	overflow: hidden;
	max-height: 0;
	opacity: 0;
	transition: max-height 0.3s ease, opacity 0.3s ease, padding 0.3s ease, margin 0.3s ease;
	padding-top: 0;
	padding-bottom: 0;
	margin-top: 0;
}
.qt-feedback.qt-feedback-show {
	max-height: 80px;
	opacity: 1;
	padding: 12px 18px;
	margin-top: 14px;
}
.qt-fb-correct { background: #c6f6d5; color: #276749; }
.qt-fb-wrong   { background: #fed7d7; color: #9b2c2c; }

/* Navigation row */
.qt-nav-row {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	margin-top: 18px;
	gap: 10px;
}
.qt-nav-btn {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 10px 26px;
	background: #2b6cb0;
	color: #fff;
	border: none;
	border-radius: 6px;
	font-size: 0.92rem;
	font-weight: 700;
	cursor: pointer;
	transition: background 0.15s, transform 0.1s;
}
.qt-nav-btn:hover { background: #2c5282; transform: translateY(-1px); }
.qt-nav-btn:active { transform: translateY(0); }

/* Card footer (Rules of Play attribution) */
.qt-card-footer {
	margin-top: 20px;
	padding-top: 12px;
	border-top: 1px solid var(--rp-border);
	font-size: 0.78rem;
	color: var(--rp-text-muted);
	text-align: center;
	font-style: italic;
}

/* Report question */
.qt-report-area { margin-top: 14px; }
.qt-report-toggle-btn {
	background: none;
	border: none;
	font-size: 0.82rem;
	color: #718096;
	cursor: pointer;
	padding: 4px 0;
	display: inline-flex;
	align-items: center;
	gap: 5px;
}
.qt-report-toggle-btn:hover { color: #e53e3e; }
.qt-report-form {
	margin-top: 8px;
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
}
.qt-report-select {
	padding: 5px 8px;
	border: 1px solid #cbd5e0;
	border-radius: 4px;
	font-size: 0.85rem;
}
.qt-report-submit {
	padding: 5px 12px;
	background: #e53e3e;
	color: #fff;
	border: none;
	border-radius: 4px;
	font-size: 0.82rem;
	font-weight: 600;
	cursor: pointer;
}
.qt-report-submit:hover { background: #c53030; }
.qt-report-cancel {
	padding: 5px 10px;
	background: transparent;
	color: #718096;
	border: 1px solid #cbd5e0;
	border-radius: 4px;
	font-size: 0.82rem;
	cursor: pointer;
}
.qt-report-thanks {
	font-size: 0.82rem;
	color: #276749;
	display: none;
}

/* Question card content fade for next-question transition */
.qt-question-content {
	transition: opacity 0.2s ease;
}
.qt-question-content-fading {
	opacity: 0;
}

/* ── Loading ─────────────────────────────────────────────── */
.qt-loading {
	text-align: center;
	padding: 60px 0;
	color: var(--rp-text-muted);
	font-size: 1rem;
	opacity: 0;
	transition: opacity 0.2s ease;
}
.qt-loading.qt-loading-show {
	opacity: 1;
}
.qt-loading-spinner {
	font-size: 2rem;
	margin-bottom: 12px;
	display: block;
	color: var(--rp-accent);
}
.qt-error-msg {
	background: #fed7d7;
	border: 1px solid #fc8181;
	color: #9b2c2c;
	padding: 11px 16px;
	border-radius: 6px;
	font-size: 0.88rem;
	margin-bottom: 16px;
	display: none;
}

/* ── Result View ─────────────────────────────────────────── */
.qt-result-card {
	background: #fff;
	border: 1px solid var(--rp-border);
	border-radius: 8px;
	padding: 48px 28px 36px;
	margin-bottom: 20px;
	text-align: center;
}
.qt-result-icon { font-size: 4rem; margin-bottom: 16px; }
.qt-result-heading {
	font-size: 1.5rem;
	font-weight: 700;
	margin: 0 0 8px;
	/* reset global heading styles */
	background: transparent;
	border: none;
	padding: 0;
	border-radius: 0;
	text-shadow: none;
}
.qt-result-heading-pass { color: #276749; }
.qt-result-heading-fail { color: #9b2c2c; }
.qt-result-score {
	font-size: 3rem;
	font-weight: 800;
	margin-bottom: 6px;
	line-height: 1.1;
}
.qt-result-pass  { color: #276749; }
.qt-result-fail  { color: #9b2c2c; }
.qt-result-breakdown {
	font-size: 1rem;
	color: var(--rp-text-body);
	margin-bottom: 6px;
}
.qt-result-detail {
	font-size: 0.92rem;
	color: var(--rp-text-muted);
	margin-bottom: 20px;
}
.qt-result-expiry-badge {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	padding: 8px 20px;
	border-radius: 24px;
	font-size: 0.9rem;
	font-weight: 600;
	margin-bottom: 28px;
	background: #c6f6d5;
	color: #276749;
}
.qt-result-actions {
	display: flex;
	justify-content: center;
	gap: 14px;
	flex-wrap: wrap;
}
.qt-back-link {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 11px 24px;
	background: transparent;
	color: #2b6cb0;
	border: 2px solid #2b6cb0;
	border-radius: 8px;
	font-size: 0.92rem;
	font-weight: 600;
	text-decoration: none;
	transition: background 0.15s, transform 0.1s;
}
.qt-back-link:hover { background: #ebf4ff; transform: translateY(-1px); }

/* ── Answer review (post-test + history drill-in) ───────────── */
.qt-review-toggle {
	display: inline-flex; align-items: center; gap: 8px;
	margin-top: 8px; padding: 9px 20px;
	background: transparent; color: #4a5568;
	border: 2px solid #cbd5e0; border-radius: 8px;
	font-size: 0.9rem; font-weight: 600; cursor: pointer;
	transition: background 0.15s;
}
.qt-review-toggle:hover { background: #f7fafc; }
.qt-review-wrap { margin-top: 18px; text-align: left; }
.qt-review-q {
	border: 1px solid #e2e8f0; border-radius: 10px;
	padding: 14px 16px; margin-bottom: 12px; background: #fff;
}
.qt-review-q-head { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; }
.qt-review-q-badge { flex: 0 0 auto; font-size: 1.05rem; line-height: 1.3; }
.qt-review-q-correct .qt-review-q-badge { color: #276749; }
.qt-review-q-wrong   .qt-review-q-badge { color: #9b2c2c; }
.qt-review-q-text { font-weight: 600; color: #2d3748; }
.qt-review-opt {
	display: flex; align-items: center; gap: 9px;
	padding: 7px 10px; border-radius: 7px; font-size: 0.9rem;
	color: #4a5568; margin: 3px 0;
}
.qt-review-opt-correct   { background: #f0fff4; color: #276749; }
.qt-review-opt-wrongpick { background: #fff5f5; color: #9b2c2c; }
.qt-review-opt-icon { flex: 0 0 1.1em; text-align: center; }
.qt-review-opt-tag { margin-left: auto; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; opacity: .8; }

/* ── Test history list (status view) ────────────────────────── */
.qt-history-section { padding: 16px 22px 4px; }
.qt-history-title { font-size: 0.82rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #718096; margin-bottom: 10px; }
.qt-history-item {
	display: flex; align-items: center; gap: 12px; width: 100%;
	padding: 11px 14px; margin-bottom: 8px;
	background: #fff; border: 1px solid #e2e8f0; border-radius: 9px;
	cursor: pointer; text-align: left; font: inherit;
	transition: border-color 0.15s, box-shadow 0.15s;
}
.qt-history-item:hover { border-color: #a0aec0; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
.qt-history-outcome { flex: 0 0 auto; font-size: 1.1rem; }
.qt-history-pass .qt-history-outcome { color: #276749; }
.qt-history-fail .qt-history-outcome { color: #9b2c2c; }
.qt-history-meta { display: flex; flex-direction: column; gap: 1px; }
.qt-history-score { font-weight: 700; color: #2d3748; font-size: 0.95rem; }
.qt-history-date { font-size: 0.8rem; color: #718096; }
.qt-history-chevron { margin-left: auto; color: #a0aec0; }
.qt-history-detail { padding: 4px 14px 2px; }

html[data-theme="dark"] .qt-review-q,
html[data-theme="dark"] .qt-history-item { background: #2d3748; border-color: #4a5568; }
html[data-theme="dark"] .qt-review-q-text,
html[data-theme="dark"] .qt-history-score { color: #e2e8f0; }
html[data-theme="dark"] .qt-review-opt { color: #cbd5e0; }
html[data-theme="dark"] .qt-review-opt-correct   { background: #22543d; color: #9ae6b4; }
html[data-theme="dark"] .qt-review-opt-wrongpick { background: #63171b; color: #feb2b2; }
html[data-theme="dark"] .qt-review-toggle { color: #cbd5e0; border-color: #4a5568; }
html[data-theme="dark"] .qt-review-toggle:hover { background: #374151; }

/* ── Mobile ─────────────────────────────────────────────────── */
@media (max-width: 600px) {
	.qt-overview-header { padding: 20px 18px 16px; gap: 12px; }
	.qt-overview-icon { width: 44px; height: 44px; font-size: 1.2rem; border-radius: 10px; }
	.qt-overview-title { font-size: 1.1rem; }
	.qt-stat-chips { padding: 16px 18px; gap: 8px; }
	.qt-stat-chip { min-width: calc(50% - 8px); flex: 1 1 calc(50% - 8px); padding: 8px 12px; }
	.qt-status-section, .qt-instructions-section, .qt-expect-section, .qt-cta-section { padding: 16px 18px; }
	.qt-begin-btn { width: 100%; justify-content: center; padding: 14px 20px; }
	.qt-cta-back { display: flex; justify-content: center; }
	.qt-question-card { padding: 18px 16px; }
	.qt-answer-label { padding: 12px 14px; }
	.qt-nav-row { flex-direction: column; }
	.qt-nav-btn { width: 100%; justify-content: center; }
	.qt-result-card { padding: 32px 18px 28px; }
	.qt-result-score { font-size: 2.4rem; }
	.qt-result-actions { flex-direction: column; }
	.qt-back-link { width: 100%; justify-content: center; }
	.qt-blocked-msg { margin-left: 0; margin-right: 0; }
}

/* ── Dark mode ────────────────────────────────────────── */
html[data-theme="dark"] .qt-overview,
html[data-theme="dark"] .qt-question-card,
html[data-theme="dark"] .qt-result-card {
	background: var(--ork-card-bg, #2d3748);
	border-color: var(--ork-border, #4a5568);
}
html[data-theme="dark"] .qt-overview-header { background: var(--ork-bg-tertiary, #374151); }
html[data-theme="dark"] .qt-overview-title  { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .qt-stat-chip       { background: var(--ork-bg-tertiary, #374151); border-color: var(--ork-border, #4a5568); }
html[data-theme="dark"] .qt-stat-chip-value { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .qt-status-pill-pass    { background: #22543d; color: #9ae6b4; }
html[data-theme="dark"] .qt-status-pill-expired { background: #744210; color: #fbd38d; }
html[data-theme="dark"] .qt-status-pill-none    { background: #4a5568; color: #cbd5e0; }
html[data-theme="dark"] .qt-status-pill-blocked { background: #742a2a; color: #feb2b2; }
html[data-theme="dark"] .qt-status-detail strong { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .qt-instructions-box {
	background: #2a4365;
	border-color: #4299e1;
	border-left-color: #63b3ed;
	color: #ebf8ff;
}
html[data-theme="dark"] .qt-instructions-label { color: #90cdf4; }
html[data-theme="dark"] .qt-expect-blurb {
	background: var(--ork-bg-tertiary, #374151);
	border-color: var(--ork-border, #4a5568);
	color: var(--ork-text-secondary, #cbd5e0);
}
html[data-theme="dark"] .qt-expect-blurb-icon { color: #90cdf4; }
html[data-theme="dark"] .qt-blocked-msg {
	background: #742a2a;
	border-color: #fc8181;
	color: #feb2b2;
}
html[data-theme="dark"] .qt-cta-back { color: var(--ork-text-muted, #a0aec0); }
html[data-theme="dark"] .qt-cta-back:hover { color: #90cdf4; }
html[data-theme="dark"] .qt-q-text {
	color: var(--ork-text, #e2e8f0);
	border-bottom-color: var(--ork-border, #4a5568);
}
html[data-theme="dark"] .qt-progress-text  { color: var(--ork-text-muted, #a0aec0); }
html[data-theme="dark"] .qt-progress-score { color: var(--ork-text-muted, #a0aec0); }
html[data-theme="dark"] .qt-progress-seg   { background: #4a5568; }
html[data-theme="dark"] .qt-answer-label {
	background: var(--ork-bg-tertiary, #374151);
	border-color: var(--ork-border, #4a5568);
	color: var(--ork-text, #e2e8f0);
}
html[data-theme="dark"] .qt-answer-radio { border-color: #718096; }
html[data-theme="dark"] .qt-answer-label:hover:not(.qt-ans-disabled):not(.qt-ans-correct):not(.qt-ans-wrong) {
	background: #4a5568;
	border-color: #63b3ed;
}
html[data-theme="dark"] .qt-answer-label:hover:not(.qt-ans-disabled):not(.qt-ans-correct):not(.qt-ans-wrong) .qt-answer-radio { border-color: #63b3ed; }
html[data-theme="dark"] .qt-answer-label.qt-ans-selected:not(.qt-ans-correct):not(.qt-ans-wrong) {
	background: #2a4365;
	border-color: #63b3ed;
}
html[data-theme="dark"] .qt-answer-label.qt-ans-selected:not(.qt-ans-correct):not(.qt-ans-wrong) .qt-answer-radio { border-color: #63b3ed; }
html[data-theme="dark"] .qt-answer-label.qt-ans-selected:not(.qt-ans-correct):not(.qt-ans-wrong) .qt-answer-radio-inner { background: #63b3ed; }
html[data-theme="dark"] .qt-answer-label.qt-ans-correct {
	background: #22543d;
	border-color: #38a169;
	color: #9ae6b4;
}
html[data-theme="dark"] .qt-answer-label.qt-ans-wrong {
	background: #742a2a;
	border-color: #fc8181;
	color: #feb2b2;
}
html[data-theme="dark"] .qt-answer-label.qt-ans-wrong .qt-answer-radio { border-color: #fc8181; background: #fc8181; }
html[data-theme="dark"] .qt-fb-correct { background: #22543d; color: #9ae6b4; }
html[data-theme="dark"] .qt-fb-wrong   { background: #742a2a; color: #feb2b2; }
html[data-theme="dark"] .qt-card-footer {
	color: var(--ork-text-muted, #a0aec0);
	border-top-color: var(--ork-border, #4a5568);
}
/* Result card */
html[data-theme="dark"] .qt-result-heading { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .qt-result-score   { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .qt-result-breakdown,
html[data-theme="dark"] .qt-result-detail  { color: var(--ork-text-secondary, #cbd5e0); }
html[data-theme="dark"] .qt-result-expiry-badge { background: #22543d; color: #9ae6b4; }
html[data-theme="dark"] .qt-back-link { color: var(--ork-text-muted, #a0aec0); }
html[data-theme="dark"] .qt-back-link:hover { color: #90cdf4; }
/* Inline error context strip from $Error branch */
html[data-theme="dark"] .rp-context[style*="fed7d7"] {
	background: #742a2a !important;
	border-color: #fc8181 !important;
	color: #feb2b2 !important;
}
html[data-theme="dark"] .rp-context[style*="fed7d7"] .rp-context-icon { color: #fc8181 !important; }
/* Report-question form inside question view */
html[data-theme="dark"] .qt-report-area button,
html[data-theme="dark"] .qt-report-toggle-btn { color: var(--ork-text-secondary, #cbd5e0); }
html[data-theme="dark"] .qt-report-select {
	background: var(--ork-input-bg, #374151);
	border-color: var(--ork-input-border, #4a5568);
	color: var(--ork-text, #e2e8f0);
}
html[data-theme="dark"] .qt-loading { color: var(--ork-text-muted, #a0aec0); }
/* ── In-product confirm modal (replaces native confirm) ── */
.qt-confirm-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9500; align-items:center; justify-content:center; }
.qt-confirm-overlay.qt-open { display:flex; }
.qt-confirm-modal { background:#fff; border-radius:8px; padding:22px 24px; min-width:300px; max-width:420px; width:100%; box-shadow:0 4px 24px rgba(0,0,0,0.18); }
.qt-confirm-title { margin:0 0 10px; font-size:1rem; font-weight:700; color:#2d3748; }
/* orkui.css paints every h1..h6 as a grey "chip" (background + border + white text-shadow),
   which on a modal title reads as a pale box — a glaring white box in dark mode. Strip it.
   The dark-context selector is specific enough to beat that global heading rule. */
.qt-confirm-title, html[data-theme="dark"] .qt-confirm-title {
	background:none; border:none; box-shadow:none; text-shadow:none; padding:0; border-radius:0;
}
.qt-confirm-body { font-size:0.9rem; color:#4a5568; line-height:1.5; margin-bottom:18px; }
.qt-confirm-footer { display:flex; gap:10px; justify-content:flex-end; }
.qt-confirm-btn { padding:7px 16px; border-radius:5px; font-size:0.85rem; font-weight:600; cursor:pointer; border:none; }
.qt-confirm-cancel { background:#e2e8f0; color:#2d3748; }
.qt-confirm-cancel:hover { background:#cbd5e0; }
.qt-confirm-ok { background:#2b6cb0; color:#fff; }
.qt-confirm-ok:hover { background:#2c5282; }
html[data-theme="dark"] .qt-confirm-modal { background: var(--ork-bg-secondary, #2d3748); }
html[data-theme="dark"] .qt-confirm-title { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .qt-confirm-body { color: var(--ork-text-secondary, #cbd5e0); }
html[data-theme="dark"] .qt-confirm-cancel { background: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .qt-confirm-cancel:hover { background: #718096; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-clipboard-check rp-header-icon"></i>
				<h1 class="rp-header-title">Take <?= htmlspecialchars($TestLabel) ?></h1>
			</div>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?= UIR ?>Kingdom/profile/<?= (int)$KingdomId ?>">
					<i class="fas fa-chess-rook rp-scope-chip-label"></i>
					<?= htmlspecialchars($KingdomName) ?>
				</a>
			</div>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Answer each question below. You need <strong><?= (int)$Config['PassPercent'] ?>%</strong> to pass. Results are recorded for <?= htmlspecialchars($KingdomName) ?>.</span>
	</div>

	<div class="rp-body">

		<div class="rp-table-area">

			<!-- Error display -->
			<div class="qt-error-msg" id="qt-error-msg"></div>

			<!-- Loading indicator -->
			<div class="qt-loading" id="qt-loading">
				<i class="fas fa-spinner fa-spin qt-loading-spinner"></i>
				Loading questions&hellip;
			</div>

			<!-- ── A. Pre-Test Status View ───────────────────── -->
			<div id="qt-status-view" class="qt-view qt-view-active">
				<div class="qt-overview">

					<!-- Overview header: icon + test name + kingdom -->
					<div class="qt-overview-header">
						<div class="qt-overview-icon">
							<i class="fas fa-<?= ($TestType === 'corpora') ? 'scroll' : 'gavel' ?>"></i>
						</div>
						<div class="qt-overview-info">
							<h2 class="qt-overview-title"><?= htmlspecialchars($TestLabel) ?></h2>
							<div class="qt-overview-scope">
								<i class="fas fa-chess-rook" style="margin-right:3px;opacity:0.5;"></i>
								<a href="<?= UIR ?>Kingdom/profile/<?= (int)$KingdomId ?>"><?= htmlspecialchars($KingdomName) ?></a>
							</div>
						</div>
					</div>

					<!-- Stat chips row -->
					<div class="qt-stat-chips">
						<div class="qt-stat-chip">
							<div class="qt-stat-chip-icon"><i class="fas fa-list-ol"></i></div>
							<div class="qt-stat-chip-text">
								<span class="qt-stat-chip-value"><?= (int)$Config['QuestionCount'] ?></span>
								Questions
							</div>
						</div>
						<div class="qt-stat-chip">
							<div class="qt-stat-chip-icon"><i class="fas fa-bullseye"></i></div>
							<div class="qt-stat-chip-text">
								<span class="qt-stat-chip-value"><?= (int)$Config['PassPercent'] ?>%</span>
								Required to Pass
							</div>
						</div>
						<?php if (!empty($Config['ValidDays'])): ?>
						<div class="qt-stat-chip">
							<div class="qt-stat-chip-icon"><i class="fas fa-calendar-check"></i></div>
							<div class="qt-stat-chip-text">
								<span class="qt-stat-chip-value"><?= (int)$Config['ValidDays'] ?> days</span>
								Validity
							</div>
						</div>
						<?php elseif (!empty($Config['ValidUntil'])): ?>
						<div class="qt-stat-chip">
							<div class="qt-stat-chip-icon"><i class="fas fa-calendar-check"></i></div>
							<div class="qt-stat-chip-text">
								<span class="qt-stat-chip-value"><?= htmlspecialchars($Config['ValidUntil']) ?></span>
								Valid Until
							</div>
						</div>
						<?php endif; ?>
						<?php if (!empty($Config['MaxRetakes'])): ?>
						<div class="qt-stat-chip">
							<div class="qt-stat-chip-icon"><i class="fas fa-redo"></i></div>
							<div class="qt-stat-chip-text">
								<span class="qt-stat-chip-value"><?= (int)$Config['MaxRetakes'] ?></span>
								Max Retakes
							</div>
						</div>
						<?php endif; ?>
					</div>

					<!-- Status badge section -->
					<div class="qt-status-section">
						<?php if (!empty($PlayerResult)): ?>
							<?php if ($PlayerResult['Expired']): ?>
								<span class="qt-status-pill qt-status-pill-expired">
									<i class="fas fa-exclamation-triangle"></i> Expired
								</span>
								<div class="qt-status-detail">
									Your qualification expired on <strong><?= date('M j, Y', strtotime($PlayerResult['ExpiresAt'])) ?></strong>.
									<?php if ($PlayerResult['ScorePercent']): ?>
										Last score: <strong><?= (int)$PlayerResult['ScorePercent'] ?>%</strong>.
									<?php endif; ?>
									<?php if ($RetakeCount > 0): ?>
										You have retaken this test <strong><?= (int)$RetakeCount ?></strong> time<?= $RetakeCount !== 1 ? 's' : '' ?>.
									<?php endif; ?>
								</div>
							<?php else: ?>
								<span class="qt-status-pill qt-status-pill-pass">
									<i class="fas fa-check-circle"></i> Passed
								</span>
								<div class="qt-status-detail">
									Score: <strong><?= (int)$PlayerResult['ScorePercent'] ?>%</strong>
									&mdash; Valid until <strong><?= date('M j, Y', strtotime($PlayerResult['ExpiresAt'])) ?></strong>.
									<?php if ($RetakeCount > 0): ?>
										Retakes: <strong><?= (int)$RetakeCount ?></strong>.
									<?php endif; ?>
								</div>
							<?php endif; ?>
						<?php else: ?>
							<span class="qt-status-pill qt-status-pill-none">
								<i class="fas fa-minus-circle"></i> Not Yet Taken
							</span>
							<div class="qt-status-detail">You have not yet taken this test.</div>
						<?php endif; ?>
					</div>

					<?php if (!empty($Config['Instructions'])): ?>
					<!-- Instructions callout -->
					<div class="qt-instructions-section">
						<div class="qt-instructions-label"><i class="fas fa-info-circle"></i> Instructions</div>
						<div class="qt-instructions-box"><?= nl2br(htmlspecialchars($Config['Instructions'])) ?></div>
					</div>
					<?php endif; ?>

					<!-- What to expect blurb -->
					<div class="qt-expect-section">
						<div class="qt-expect-blurb">
							<i class="fas fa-lightbulb qt-expect-blurb-icon"></i>
							<span>
								<strong>What to expect:</strong>
								You'll answer <?= (int)$Config['QuestionCount'] ?> questions one at a time.
								Each answer is shown immediately after you select it.
								You need <strong><?= (int)$Config['PassPercent'] ?>%</strong> to pass.
							</span>
						</div>
					</div>

					<!-- CTA: Begin / Retake -->
					<div class="qt-cta-section">
						<?php if ($RetakeBlocked): ?>
						<div class="qt-blocked-msg">
							<i class="fas fa-ban" style="flex-shrink:0;margin-top:2px;"></i>
							<span>You have reached the maximum number of retakes for this test and cannot take it again.</span>
						</div>
						<?php else: ?>
						<button class="qt-begin-btn" id="qt-begin-btn">
							<i class="fas fa-play-circle"></i>
							<?= !empty($PlayerResult) ? 'Retake Test' : 'Begin Test' ?>
						</button>
						<?php endif; ?>
						<div>
							<a class="qt-cta-back" href="<?= UIR ?>Kingdom/profile/<?= (int)$KingdomId ?>">
								<i class="fas fa-arrow-left"></i> Back to Kingdom
							</a>
						</div>
					</div>

				</div><!-- /.qt-overview -->

				<?php if (!empty($PlayerAttempts)): ?>
				<!-- Durable history: every past attempt (pass or fail), reviewable. -->
				<div class="qt-history-section">
					<div class="qt-history-title"><i class="fas fa-history"></i> Your Test History</div>
					<?php foreach ($PlayerAttempts as $att): ?>
						<?php $att_passed = !empty($att['Passed']); ?>
						<button type="button"
							class="qt-history-item <?= $att_passed ? 'qt-history-pass' : 'qt-history-fail' ?>"
							data-attempt-id="<?= (int)$att['QualAttemptId'] ?>"
							aria-expanded="false">
							<span class="qt-history-outcome">
								<i class="fas <?= $att_passed ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
							</span>
							<span class="qt-history-meta">
								<span class="qt-history-score"><?= $att_passed ? 'Passed' : 'Did not pass' ?> &middot; <?= (int)$att['ScorePercent'] ?>%</span>
								<span class="qt-history-date"><?= date('M j, Y \a\t g:i A', strtotime($att['TakenAt'])) ?><?= !empty($att['RulesVersion']) ? ' &middot; ' . htmlspecialchars($att['RulesVersion']) : '' ?></span>
							</span>
							<span class="qt-history-chevron"><i class="fas fa-chevron-down"></i></span>
						</button>
						<div class="qt-history-detail" id="qt-history-detail-<?= (int)$att['QualAttemptId'] ?>" style="display:none;"></div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</div><!-- /#qt-status-view -->

			<!-- ── B. Question View (shown during quiz) ────────────── -->
			<div id="qt-question-view" class="qt-view">
				<div class="qt-question-card">
					<div class="qt-question-content" id="qt-question-content">
						<div class="qt-progress-header">
							<div class="qt-progress-text" id="qt-progress-text">Question 1 of 10</div>
							<div class="qt-progress-score" id="qt-progress-score"></div>
						</div>
						<div class="qt-progress-segments" id="qt-progress-segments"></div>
						<div class="qt-q-text" id="qt-q-text"></div>
						<!-- Hint shown for multi-correct questions; hidden for single. -->
						<div class="qt-multi-hint" id="qt-multi-hint" style="display:none;font-size:0.82rem;color:#4a5568;margin:6px 0 10px;">
							<i class="fas fa-check-square" style="margin-right:5px;color:#2b6cb0;"></i>Select all that apply, then submit.
						</div>
						<ul class="qt-answers" id="qt-answers"></ul>
						<!-- Per-question submit for multi-correct questions.
						     Single questions submit on click and don't show this. -->
						<div id="qt-multi-submit-row" style="display:none;margin:0 0 14px;">
							<button class="qt-nav-btn" id="qt-multi-submit-btn" disabled><i class="fas fa-check"></i> Submit Answer</button>
						</div>
						<div class="qt-feedback" id="qt-feedback"></div>
						<div class="qt-report-area" id="qt-report-area" style="display:none;">
							<button class="qt-report-toggle-btn" id="qt-report-btn">
								<i class="fas fa-flag" style="color:#e53e3e;"></i> Report Question
							</button>
							<div class="qt-report-form" id="qt-report-form" style="display:none;">
								<select class="qt-report-select" id="qt-report-reason">
									<option value="">— Select a reason —</option>
									<option value="wording">Question is worded poorly</option>
									<option value="correct">My answer was correct</option>
									<option value="outdated">This has not been updated for recent changes</option>
									<option value="other">Other</option>
								</select>
								<button class="qt-report-submit" id="qt-report-submit">Submit</button>
								<button class="qt-report-cancel" id="qt-report-cancel">Cancel</button>
								<span class="qt-report-thanks" id="qt-report-thanks"><i class="fas fa-check-circle"></i> Thanks for your report.</span>
							</div>
						</div>
						<div class="qt-nav-row">
							<button class="qt-nav-btn" id="qt-next-btn" style="display:none;">Next <i class="fas fa-chevron-right"></i></button>
							<button class="qt-nav-btn" id="qt-submit-btn" style="display:none;"><i class="fas fa-check"></i> Submit Test</button>
						</div>
						<?php if (!empty($Config['RulesVersion'])): ?>
						<div class="qt-card-footer">
							<?php if ($TestType === 'reeve'): ?>
							Based on Amtgard Rules of Play Version <?= htmlspecialchars($Config['RulesVersion']) ?>
							<?php else: ?>
							Based on <?= htmlspecialchars($Config['RulesVersion']) ?>
							<?php endif; ?>
						</div>
						<?php endif; ?>
					</div>
				</div>
			</div><!-- /#qt-question-view -->

			<!-- ── C. Result View (shown after submission) ─────────── -->
			<div id="qt-result-view" class="qt-view">
				<div class="qt-result-card">
					<div class="qt-result-icon" id="qt-result-icon"></div>
					<h2 class="qt-result-heading" id="qt-result-heading"></h2>
					<div class="qt-result-score" id="qt-result-score"></div>
					<div class="qt-result-breakdown" id="qt-result-breakdown"></div>
					<div class="qt-result-detail" id="qt-result-detail"></div>
					<div id="qt-result-expiry-wrap" style="display:none;">
						<div class="qt-result-expiry-badge" id="qt-result-expiry">
							<i class="fas fa-calendar-check"></i>
							<span id="qt-result-expiry-text"></span>
						</div>
					</div>
					<div class="qt-result-actions">
						<a class="qt-back-link" href="<?= UIR ?>Kingdom/profile/<?= (int)$KingdomId ?>">
							<i class="fas fa-chess-rook"></i> Back to Kingdom
						</a>
						<button class="qt-begin-btn" id="qt-retake-btn" style="display:none;">
							<i class="fas fa-redo"></i> Retake Test
						</button>
					</div>
					<button class="qt-review-toggle" id="qt-review-toggle" style="display:none;">
						<i class="fas fa-tasks"></i> <span id="qt-review-toggle-text">Review Your Answers</span>
					</button>
					<div class="qt-review-wrap" id="qt-review-wrap" style="display:none;"></div>
				</div>
			</div><!-- /#qt-result-view -->

		</div><!-- /.rp-table-area -->

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<!-- In-product confirm modal -->
<div class="qt-confirm-overlay" id="qt-confirm-overlay">
	<div class="qt-confirm-modal">
		<h4 class="qt-confirm-title" id="qt-confirm-title"></h4>
		<div class="qt-confirm-body" id="qt-confirm-body"></div>
		<div class="qt-confirm-footer">
			<button type="button" class="qt-confirm-btn qt-confirm-cancel" id="qt-confirm-cancel">Cancel</button>
			<button type="button" class="qt-confirm-btn qt-confirm-ok" id="qt-confirm-ok">Confirm</button>
		</div>
	</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
<script>
(function() {
	var BASE_URL    = '<?= UIR ?>';
	// In-product replacement for native confirm() — qtConfirm({title, body, confirmLabel, onConfirm})
	var qtConfirm = (function() {
		var overlay  = document.getElementById('qt-confirm-overlay');
		var titleEl  = document.getElementById('qt-confirm-title');
		var bodyEl   = document.getElementById('qt-confirm-body');
		var cancelEl = document.getElementById('qt-confirm-cancel');
		var okEl     = document.getElementById('qt-confirm-ok');
		var pending  = null;
		function close() { overlay.classList.remove('qt-open'); pending = null; }
		okEl.addEventListener('click', function() { var cb = pending; close(); if (cb) cb(); });
		cancelEl.addEventListener('click', close);
		overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });
		return function(opts) {
			opts = opts || {};
			titleEl.textContent = opts.title || 'Please Confirm';
			bodyEl.textContent  = opts.body || '';
			okEl.textContent    = opts.confirmLabel || 'Confirm';
			pending = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
			overlay.classList.add('qt-open');
		};
	})();
	var KINGDOM_ID  = <?= (int)$KingdomId ?>;
	var TEST_TYPE   = '<?= htmlspecialchars($TestType, ENT_QUOTES) ?>';
	var TEST_LABEL  = '<?= htmlspecialchars($TestLabel, ENT_QUOTES) ?>';
	var RETAKE_BLOCKED = <?= !empty($RetakeBlocked) ? 'true' : 'false' ?>;

	// View elements
	var statusView   = document.getElementById('qt-status-view');
	var questionView = document.getElementById('qt-question-view');
	var resultView   = document.getElementById('qt-result-view');
	var loadingEl    = document.getElementById('qt-loading');
	var errorMsgEl   = document.getElementById('qt-error-msg');

	// Question view elements
	var progressText    = document.getElementById('qt-progress-text');
	var progressScore   = document.getElementById('qt-progress-score');
	var progressSegs    = document.getElementById('qt-progress-segments');
	var questionContent = document.getElementById('qt-question-content');
	var qTextEl         = document.getElementById('qt-q-text');
	var answersEl       = document.getElementById('qt-answers');
	var multiHintEl     = document.getElementById('qt-multi-hint');
	var multiSubmitRow  = document.getElementById('qt-multi-submit-row');
	var multiSubmitBtn  = document.getElementById('qt-multi-submit-btn');
	// Set of currently-checked answer ids for the multi-select question in view.
	// Rebuilt at the start of each renderQuestion().
	var multiSelected   = null;
	var feedbackEl      = document.getElementById('qt-feedback');
	var reportArea      = document.getElementById('qt-report-area');
	var reportBtn       = document.getElementById('qt-report-btn');
	var reportForm      = document.getElementById('qt-report-form');
	var reportReason    = document.getElementById('qt-report-reason');
	var reportSubmit    = document.getElementById('qt-report-submit');
	var reportCancel    = document.getElementById('qt-report-cancel');
	var reportThanks    = document.getElementById('qt-report-thanks');
	var nextBtn         = document.getElementById('qt-next-btn');
	var submitBtn       = document.getElementById('qt-submit-btn');
	var beginBtn        = document.getElementById('qt-begin-btn');

	// Result view elements
	var resultIcon      = document.getElementById('qt-result-icon');
	var resultHeading   = document.getElementById('qt-result-heading');
	var resultScore     = document.getElementById('qt-result-score');
	var resultBreakdown = document.getElementById('qt-result-breakdown');
	var resultDetail    = document.getElementById('qt-result-detail');
	var resultExpiryWrap = document.getElementById('qt-result-expiry-wrap');
	var resultExpiryText = document.getElementById('qt-result-expiry-text');
	var retakeBtn       = document.getElementById('qt-retake-btn');

	// Quiz state
	var questions    = [];
	var answers      = {};
	var correctCount = 0;
	var currentIdx   = 0;
	var passPercent  = <?= (int)$Config['PassPercent'] ?>;
	var maxRetakes   = <?= (int)$Config['MaxRetakes'] ?>;
	var retakesTaken = <?= (int)$RetakeCount ?>;

	function showError(msg) {
		errorMsgEl.textContent = msg;
		errorMsgEl.style.display = msg ? 'block' : 'none';
	}

	function showLoading(show) {
		if (show) {
			loadingEl.style.display = 'block';
			requestAnimationFrame(function() {
				loadingEl.classList.add('qt-loading-show');
			});
		} else {
			loadingEl.classList.remove('qt-loading-show');
			setTimeout(function() { loadingEl.style.display = 'none'; }, 200);
		}
	}

	// View transition system: fade out current, then fade in target
	function showView(viewName) {
		var views = [statusView, questionView, resultView];
		var target = null;
		if (viewName === 'status')   target = statusView;
		if (viewName === 'question') target = questionView;
		if (viewName === 'result')   target = resultView;

		// Hide all non-target views
		views.forEach(function(v) {
			if (v !== target) {
				v.classList.remove('qt-view-active');
				v.classList.remove('qt-view-entering');
				// After transition, set display:none
				setTimeout(function() {
					if (!v.classList.contains('qt-view-active') && !v.classList.contains('qt-view-entering')) {
						v.style.display = 'none';


					}
				}, 260);
			}
		});

		// Show target with enter animation
		if (target) {
			target.style.display = 'block';
			target.classList.add('qt-view-entering');
			target.classList.remove('qt-view-active');
			// Force reflow, then activate
			void target.offsetHeight;
			requestAnimationFrame(function() {
				target.classList.remove('qt-view-entering');
				target.classList.add('qt-view-active');
			});
		}
	}

	// Begin button
	if (beginBtn) {
		beginBtn.addEventListener('click', function() {
			if (RETAKE_BLOCKED) return;
			startQuiz();
		});
	}

	function startQuiz() {
		if (beginBtn) beginBtn.disabled = true;
		questions    = [];
		answers      = {};
		correctCount = 0;
		currentIdx   = 0;

		showView(null);
		showLoading(true);
		showError('');

		var fd = new FormData();
		fd.append('KingdomId', KINGDOM_ID);
		fd.append('TestType',  TEST_TYPE);
		fetch(BASE_URL + 'QualTestAjax/gettest', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				showLoading(false);
				if (j.status !== 0) {
					showView('status');
					showError(j.error || 'Unable to load test questions.');
					if (beginBtn) beginBtn.disabled = false;
					return;
				}
				questions   = j.questions;
				passPercent = j.pass_percent;
				buildProgressSegments();
				renderQuestion(0);
			})
			.catch(function() {
				showLoading(false);
				showView('status');
				showError('Network error. Please try again.');
				if (beginBtn) beginBtn.disabled = false;
			});
	}

	function buildProgressSegments() {
		progressSegs.innerHTML = '';
		for (var i = 0; i < questions.length; i++) {
			var seg = document.createElement('div');
			seg.className = 'qt-progress-seg';
			seg.dataset.idx = i;
			progressSegs.appendChild(seg);
		}
	}

	function updateProgressSegments() {
		var segs = progressSegs.querySelectorAll('.qt-progress-seg');
		for (var i = 0; i < segs.length; i++) {
			segs[i].className = 'qt-progress-seg';
			if (answers.hasOwnProperty(questions[i].QualQuestionId)) {
				segs[i].classList.add('qt-progress-seg-done');
			}
			if (i === currentIdx) {
				// Current overrides done styling while answering
				if (!answers.hasOwnProperty(questions[i].QualQuestionId)) {
					segs[i].classList.add('qt-progress-seg-current');
				}
			}
		}
	}

	function renderQuestion(idx) {
		var q     = questions[idx];
		var total = questions.length;
		currentIdx = idx;

		// Fade out content, then update and fade in
		questionContent.classList.add('qt-question-content-fading');

		setTimeout(function() {
			progressText.textContent = 'Question ' + (idx + 1) + ' of ' + total;
			progressScore.textContent = correctCount + ' correct so far';
			updateProgressSegments();
			qTextEl.textContent = q.QuestionText;

			feedbackEl.classList.remove('qt-feedback-show', 'qt-fb-correct', 'qt-fb-wrong');
			feedbackEl.className = 'qt-feedback';
			nextBtn.style.display    = 'none';
			submitBtn.style.display  = 'none';
			reportArea.style.display = 'none';
			reportForm.style.display = 'none';
			reportThanks.style.display = 'none';
			reportReason.value = '';

			var isMulti = (q.AnswerMode === 'multi');
			multiHintEl.style.display    = isMulti ? '' : 'none';
			multiSubmitRow.style.display = isMulti ? '' : 'none';
			multiSubmitBtn.disabled      = true;
			multiSelected = isMulti ? Object.create(null) : null;

			answersEl.innerHTML = '';
			q.Answers.forEach(function(a) {
				var li    = document.createElement('li');
				li.className = 'qt-answer-item';
				var label = document.createElement('label');
				label.className = 'qt-answer-label';
				label.dataset.answerId = a.QualAnswerId;
				label.setAttribute('tabindex', '0');
				label.setAttribute('role', isMulti ? 'checkbox' : 'radio');
				label.setAttribute('aria-checked', 'false');

				// Selection indicator — same visual pill, filled per aria-checked
				// so single (radio) and multi (checkbox) look consistent.
				var radio = document.createElement('span');
				radio.className = 'qt-answer-radio';
				var inner = document.createElement('span');
				inner.className = 'qt-answer-radio-inner';
				radio.appendChild(inner);
				label.appendChild(radio);

				// Answer text
				var text = document.createElement('span');
				text.textContent = a.AnswerText;
				label.appendChild(text);

				if (isMulti) {
					var toggle = function() {
						if (isChecking) return;
						var already = !!multiSelected[a.QualAnswerId];
						if (already) {
							delete multiSelected[a.QualAnswerId];
							label.classList.remove('qt-ans-selected');
							label.setAttribute('aria-checked', 'false');
						} else {
							multiSelected[a.QualAnswerId] = true;
							label.classList.add('qt-ans-selected');
							label.setAttribute('aria-checked', 'true');
						}
						multiSubmitBtn.disabled = (Object.keys(multiSelected).length === 0);
					};
					label.addEventListener('click', toggle);
					label.addEventListener('keydown', function(e) {
						if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
					});
				} else {
					label.addEventListener('click', function() { checkAnswer(q, a.QualAnswerId); });
					label.addEventListener('keydown', function(e) {
						if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); checkAnswer(q, a.QualAnswerId); }
					});
				}
				li.appendChild(label);
				answersEl.appendChild(li);
			});

			// Fade content back in
			questionContent.classList.remove('qt-question-content-fading');

			showView('question');
		}, idx === 0 ? 0 : 200); // No delay on first question
	}

	var isChecking = false;
	// `selected` is a number for single-select and an array of numbers for
	// multi-select. Both cases funnel through here so the feedback + scoring
	// pathway is identical.
	function checkAnswer(q, selected) {
		if (isChecking) return;
		var isMulti = Array.isArray(selected);
		if (isMulti && selected.length === 0) return;
		isChecking = true;

		var allLabels = answersEl.querySelectorAll('.qt-answer-label');
		// Freeze the current visual state — highlight what was picked; for
		// single, we clear other selections; for multi, keep what's checked.
		if (isMulti) {
			// Ensure the aria-checked reflects the current multiSelected set.
			allLabels.forEach(function(l) {
				var picked = selected.indexOf(parseInt(l.dataset.answerId, 10)) !== -1;
				l.classList.toggle('qt-ans-selected', picked);
				l.setAttribute('aria-checked', picked ? 'true' : 'false');
			});
			multiSubmitBtn.disabled = true;
		} else {
			allLabels.forEach(function(l) {
				l.classList.remove('qt-ans-selected');
				l.setAttribute('aria-checked', 'false');
			});
			var selLabel0 = answersEl.querySelector('[data-answer-id="' + selected + '"]');
			if (selLabel0) {
				selLabel0.classList.add('qt-ans-selected');
				selLabel0.setAttribute('aria-checked', 'true');
			}
		}

		// Disable further clicks while we score.
		allLabels.forEach(function(l) { l.classList.add('qt-ans-disabled'); });

		var fd = new FormData();
		fd.append('KingdomId',  KINGDOM_ID);
		fd.append('TestType',   TEST_TYPE);
		fd.append('QuestionId', q.QualQuestionId);
		if (isMulti) {
			selected.forEach(function(id) { fd.append('AnswerIds[]', id); });
		} else {
			fd.append('AnswerId', selected);
			fd.append('AnswerIds[]', selected); // dual-post for forward compat
		}
		fetch(BASE_URL + 'QualTestAjax/checkanswer', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (j.status !== 0) {
					allLabels.forEach(function(l) {
						l.classList.remove('qt-ans-disabled');
						l.classList.remove('qt-ans-selected');
					});
					showError(j.error || 'Error checking answer.');
					isChecking = false;
					return;
				}

				answers[q.QualQuestionId] = selected;

				// Highlight the RIGHT set (server may return the full set for
				// multi via correct_answer_ids). For single the legacy
				// correct_answer_id field still populates a one-element array.
				var correctIds = Array.isArray(j.correct_answer_ids)
					? j.correct_answer_ids.map(function(x) { return parseInt(x, 10); })
					: (j.correct_answer_id ? [parseInt(j.correct_answer_id, 10)] : []);

				if (j.is_correct) {
					correctCount++;
					var pickedIds = isMulti ? selected : [selected];
					pickedIds.forEach(function(id) {
						var l = answersEl.querySelector('[data-answer-id="' + id + '"]');
						if (l) { l.classList.remove('qt-ans-selected'); l.classList.add('qt-ans-correct'); }
					});
					feedbackEl.className = 'qt-feedback qt-fb-correct';
					feedbackEl.innerHTML = '<i class="fas fa-check-circle" style="margin-right:6px;"></i> Correct!';
				} else {
					var pickedIdsW = isMulti ? selected : [selected];
					pickedIdsW.forEach(function(id) {
						var l = answersEl.querySelector('[data-answer-id="' + id + '"]');
						if (!l) return;
						l.classList.remove('qt-ans-selected');
						// Wrong pick unless it's also in the correct set — in which
						// case it was partially right; still show as correct so
						// the player sees which of their picks was right.
						if (correctIds.indexOf(id) !== -1) l.classList.add('qt-ans-correct');
						else                               l.classList.add('qt-ans-wrong');
					});
					// Also mark any correct answers the player MISSED.
					correctIds.forEach(function(id) {
						if (pickedIdsW.indexOf(id) !== -1) return;
						var l = answersEl.querySelector('[data-answer-id="' + id + '"]');
						if (l) { l.classList.remove('qt-ans-disabled'); l.classList.add('qt-ans-correct'); }
					});
					feedbackEl.className = 'qt-feedback qt-fb-wrong';
					feedbackEl.innerHTML = '<i class="fas fa-times-circle" style="margin-right:6px;"></i> Sorry, that\'s not correct.';
					reportArea.style.display = 'block';
					reportBtn.dataset.questionId = q.QualQuestionId;
				}

				isChecking = false;

				// Slide-down feedback
				requestAnimationFrame(function() {
					feedbackEl.classList.add('qt-feedback-show');
				});

				// Update progress
				progressScore.textContent = correctCount + ' correct so far';
				updateProgressSegments();

				// Hide the multi UI once the answer's locked in — the row
				// showed the "Submit Answer" affordance during selection;
				// after submission, Next / Submit Test takes over below.
				multiSubmitRow.style.display = 'none';
				multiHintEl.style.display    = 'none';

				if (currentIdx < questions.length - 1) {
					nextBtn.style.display = 'inline-flex';
				} else {
					submitBtn.style.display = 'inline-flex';
				}
			})
			.catch(function() {
				allLabels.forEach(function(l) {
					l.classList.remove('qt-ans-disabled');
					l.classList.remove('qt-ans-selected');
				});
				showError('Network error. Please try again.');
				isChecking = false;
			});
	}

	// Report question handlers
	reportBtn.addEventListener('click', function() {
		reportForm.style.display = 'flex';
		reportBtn.style.display  = 'none';
	});
	reportCancel.addEventListener('click', function() {
		reportForm.style.display = 'none';
		reportBtn.style.display  = 'inline-flex';
	});
	reportSubmit.addEventListener('click', function() {
		var reason = reportReason.value;
		if (!reason) { showError('Please select a reason.'); return; }
		var fd = new FormData();
		fd.append('QuestionId', reportBtn.dataset.questionId);
		fd.append('Reason', reason);
		fetch(BASE_URL + 'QualTestAjax/reportquestion', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (j && j.status !== 0) {
					reportForm.style.display = 'none';
					reportBtn.style.display  = 'inline-flex';
					showError(j.error || 'Failed to submit report.');
					return;
				}
				reportForm.style.display   = 'none';
				reportThanks.style.display = 'inline';
			})
			.catch(function() {
				reportForm.style.display = 'none';
				reportBtn.style.display  = 'inline-flex';
				showError('Failed to submit report. Please try again.');
			});
	});

	// Next button
	// Multi-select submit: flushes the currently-checked answer set through
	// the same checkAnswer() the single flow uses.
	multiSubmitBtn.addEventListener('click', function() {
		if (multiSubmitBtn.disabled) return;
		var q = questions[currentIdx];
		var picks = Object.keys(multiSelected || {}).map(function(k) { return parseInt(k, 10); });
		if (!picks.length) return;
		checkAnswer(q, picks);
	});

	nextBtn.addEventListener('click', function() {
		if (currentIdx < questions.length - 1) renderQuestion(currentIdx + 1);
	});

	// Submit test
	submitBtn.addEventListener('click', function() {
		qtConfirm({
			title: 'Submit Test',
			body: 'Submit your test? This cannot be undone.',
			confirmLabel: 'Submit',
			onConfirm: function() {
				showView(null);
				showLoading(true);
				showError('');
				var fd = new FormData();
				fd.append('KingdomId', KINGDOM_ID);
				fd.append('TestType',  TEST_TYPE);
				fd.append('Answers',   JSON.stringify(answers));
				fetch(BASE_URL + 'QualTestAjax/submittest', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(j) {
						showLoading(false);
						if (j.status !== 0) {
							showView('question');
							showError(j.error || 'Error submitting test.');
							return;
						}
						showResult(j);
					})
					.catch(function() {
						showLoading(false);
						showView('question');
						showError('Network error. Please try again.');
					});
			}
		});
	});

	// Animated score counter using requestAnimationFrame
	function animateScore(targetPercent, duration) {
		var startTime = null;
		function step(timestamp) {
			if (!startTime) startTime = timestamp;
			var progress = Math.min((timestamp - startTime) / duration, 1);
			// Ease-out curve
			var eased = 1 - Math.pow(1 - progress, 3);
			var current = Math.round(eased * targetPercent);
			resultScore.textContent = current + '%';
			if (progress < 1) {
				requestAnimationFrame(step);
			}
		}
		requestAnimationFrame(step);
	}

	function fireConfetti() {
		if (typeof window.confetti !== 'function') return;
		var end = Date.now() + 2000;
		var colors = ['#276749', '#48bb78', '#f6e05e', '#ffffff'];
		(function frame() {
			window.confetti({
				particleCount: 4,
				angle: 60,
				spread: 55,
				origin: { x: 0, y: 0.7 },
				colors: colors,
				zIndex: 2000
			});
			window.confetti({
				particleCount: 4,
				angle: 120,
				spread: 55,
				origin: { x: 1, y: 0.7 },
				colors: colors,
				zIndex: 2000
			});
			if (Date.now() < end) requestAnimationFrame(frame);
		})();
	}

	function showResult(j) {
		if (j.passed) {
			resultIcon.innerHTML    = '<i class="fas fa-check-circle qt-result-pass" style="font-size:inherit;"></i>';
			resultHeading.className = 'qt-result-heading qt-result-heading-pass';
			resultHeading.textContent = 'Congratulations!';
			resultScore.className   = 'qt-result-score qt-result-pass';
			resultBreakdown.textContent = j.correct + ' of ' + j.total + ' correct';
			resultDetail.textContent = 'You needed ' + j.pass_percent + '% to pass. You scored ' + j.score_percent + '%. Well done!';
			if (j.expires_at) {
				var d = new Date(j.expires_at.replace(' ', 'T'));
				resultExpiryText.textContent = 'Valid until ' + d.toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'});
				resultExpiryWrap.style.display = 'block';
			} else {
				resultExpiryWrap.style.display = 'none';
			}
			retakesTaken++;
			RETAKE_BLOCKED = (maxRetakes > 0 && retakesTaken >= maxRetakes);
			if (retakeBtn) retakeBtn.style.display = 'none';
			fireConfetti();
		} else {
			resultIcon.innerHTML    = '<i class="fas fa-times-circle qt-result-fail" style="font-size:inherit;"></i>';
			resultHeading.className = 'qt-result-heading qt-result-heading-fail';
			resultHeading.textContent = 'Not Quite';
			resultScore.className   = 'qt-result-score qt-result-fail';
			resultBreakdown.textContent = j.correct + ' of ' + j.total + ' correct';
			resultDetail.textContent = 'You needed ' + j.pass_percent + '%, you scored ' + j.score_percent + '%. Keep studying and try again!';
			resultExpiryWrap.style.display = 'none';
			retakesTaken++;
			RETAKE_BLOCKED = (maxRetakes > 0 && retakesTaken >= maxRetakes);
			if (retakeBtn && !RETAKE_BLOCKED) retakeBtn.style.display = 'inline-flex';
		}

		showView('result');
		// Start animated counter after view transition begins
		resultScore.textContent = '0%';
		setTimeout(function() {
			animateScore(j.score_percent, 1000);
		}, 300);

		// Offer the durable "Review Your Answers" drill-in for this fresh attempt.
		setupPostTestReview(j.attempt_id);
	}

	// Retake button
	if (retakeBtn) {
		retakeBtn.addEventListener('click', function() {
			startQuiz();
		});
	}

	// ── Answer review (shared by post-test + history) ───────────────────
	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	// Build the review HTML from an attempt-detail object (see QualTest::getAttemptDetail).
	function renderReview(attempt) {
		if (!attempt || !attempt.Questions || !attempt.Questions.length) {
			return '<div style="color:#718096;font-size:0.9rem;">No answer detail was recorded for this attempt.</div>';
		}
		var html = '';
		attempt.Questions.forEach(function(q, i) {
			var qClass = q.Correct ? 'qt-review-q-correct' : 'qt-review-q-wrong';
			var badge  = q.Correct ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>';
			var archived = (q.Archived ? ' <span style="font-size:0.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#92400e;background:#fef3c7;padding:1px 6px;border-radius:4px;margin-left:4px;white-space:nowrap;">Archived</span>' : '') + (q.NotInLiveSet ? ' <span style="font-size:0.64rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:#64748b;background:#f1f5f9;border:1px solid #e2e8f0;padding:0 6px;border-radius:4px;margin-left:4px;white-space:nowrap;">Not in current test</span>' : '');
			html += '<div class="qt-review-q ' + qClass + '">';
			html += '<div class="qt-review-q-head"><span class="qt-review-q-badge">' + badge + '</span>'
				+ '<span class="qt-review-q-text">' + (i + 1) + '. ' + esc(q.QuestionText) + archived + '</span></div>';
			(q.Options || []).forEach(function(o) {
				var cls = '', icon = '<i class="far fa-circle qt-review-opt-icon"></i>', tag = '';
				if (o.WasSelected && o.IsCorrect) {
					cls = 'qt-review-opt-correct';
					icon = '<i class="fas fa-check-circle qt-review-opt-icon"></i>';
					tag = '<span class="qt-review-opt-tag">Your pick</span>';
				} else if (o.WasSelected && !o.IsCorrect) {
					cls = 'qt-review-opt-wrongpick';
					icon = '<i class="fas fa-times-circle qt-review-opt-icon"></i>';
					tag = '<span class="qt-review-opt-tag">Your pick</span>';
				} else if (!o.WasSelected && o.IsCorrect) {
					cls = 'qt-review-opt-correct';
					icon = '<i class="fas fa-check qt-review-opt-icon"></i>';
					tag = '<span class="qt-review-opt-tag">Correct</span>';
				}
				html += '<div class="qt-review-opt ' + cls + '">' + icon
					+ '<span>' + esc(o.AnswerText) + '</span>' + tag + '</div>';
			});
			html += '</div>';
		});
		return html;
	}

	// Fetch one attempt's detail; cb(attemptObjOrNull).
	function fetchAttempt(attemptId, cb) {
		var fd = new FormData();
		fd.append('AttemptId', attemptId);
		fetch(BASE_URL + 'QualTestAjax/attemptdetail', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) { cb(j && j.status === 0 ? j.attempt : null); })
			.catch(function() { cb(null); });
	}

	function setupPostTestReview(attemptId) {
		var toggle = document.getElementById('qt-review-toggle');
		var wrap   = document.getElementById('qt-review-wrap');
		if (!toggle || !wrap) return;
		if (!attemptId) { toggle.style.display = 'none'; wrap.style.display = 'none'; return; }
		var loaded = false, open = false;
		var label = document.getElementById('qt-review-toggle-text');
		toggle.style.display = 'inline-flex';
		wrap.style.display = 'none';
		wrap.innerHTML = '';
		toggle.onclick = function() {
			open = !open;
			wrap.style.display = open ? 'block' : 'none';
			if (label) label.textContent = open ? 'Hide Your Answers' : 'Review Your Answers';
			if (open && !loaded) {
				wrap.innerHTML = '<div style="color:#718096;font-size:0.9rem;">Loading…</div>';
				fetchAttempt(attemptId, function(att) {
					loaded = true;
					wrap.innerHTML = renderReview(att);
				});
			}
		};
	}

	// History list on the status view: expand each row to its review inline.
	Array.prototype.forEach.call(document.querySelectorAll('.qt-history-item'), function(btn) {
		var id     = btn.getAttribute('data-attempt-id');
		var detail = document.getElementById('qt-history-detail-' + id);
		if (!detail) return;
		var loaded = false;
		btn.addEventListener('click', function() {
			var open = detail.style.display !== 'none';
			detail.style.display = open ? 'none' : 'block';
			btn.setAttribute('aria-expanded', open ? 'false' : 'true');
			var chev = btn.querySelector('.qt-history-chevron i');
			if (chev) chev.className = open ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
			if (!open && !loaded) {
				detail.innerHTML = '<div style="color:#718096;font-size:0.9rem;padding:6px 0;">Loading…</div>';
				fetchAttempt(id, function(att) {
					loaded = true;
					detail.innerHTML = renderReview(att);
				});
			}
		});
	});
})();
</script>
