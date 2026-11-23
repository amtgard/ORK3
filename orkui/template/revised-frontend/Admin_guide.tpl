<?php
/* -----------------------------------------------
   ORK Administrator Guide — docs/admin-guide.md rendered by GuideDoc
   (controller.Admin::guide). Variables:
     $GuideHtml     rendered HTML, h2/h3 carry ids
     $GuideToc      list of {id, text, level} for the table of contents
     $GuideUpdated  unix mtime of the Markdown file, or null
     $Error         optional message when the file is missing
   The page is admin-only (the controller redirects everyone else), so the
   h1 inside the Markdown is dropped: the hero carries the title.
   ----------------------------------------------- */
$adgHtml    = (string) ($GuideHtml ?? '');
$adgToc     = is_array($GuideToc ?? null) ? $GuideToc : [];
$adgUpdated = !empty($GuideUpdated) ? date('M j, Y', (int) $GuideUpdated) : '';
$adgError   = isset($Error) ? trim((string) $Error) : '';
$adgHtml    = (string) preg_replace('#^\s*<h1>.*?</h1>#s', '', $adgHtml, 1);
$adgSections = count(array_filter($adgToc, static fn ($t) => (int) $t['level'] === 2));
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<style>
/* Layout: sticky contents rail + reading column. */
.adg-layout { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 28px; align-items: start; }
.adg-toc { position: sticky; top: 76px; }
.adg-toc-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--ork-text-on-ground); margin: 0 0 8px; }
.adg-toc ol { list-style: none; margin: 0; padding: 0; border-left: 2px solid var(--ork-border); }
.adg-toc li { margin: 0; }
.adg-toc a { display: block; padding: 6px 12px; margin-left: -2px; border-left: 2px solid transparent; font-size: 13px; line-height: 1.35; color: var(--ork-text-secondary); text-decoration: none; }
.adg-toc li.adg-sub a { padding-left: 24px; font-size: 12px; }
.adg-toc a:hover { color: var(--ork-text); text-decoration: none; }
.adg-toc a.adg-current { color: #2b6cb0; border-left-color: #2b6cb0; font-weight: 700; }
html[data-theme="dark"] .adg-toc a.adg-current { color: var(--ork-link-bright); border-left-color: var(--ork-link-bright); }
.adg-toc-print { margin-top: 14px; }
.adg-print-btn { background: none; border: 0; padding: 6px 12px; font: inherit; font-size: 13px; color: var(--ork-text-secondary); cursor: pointer; display: inline-flex; align-items: center; gap: 6px; border-radius: 6px; }
.adg-print-btn:hover { color: var(--ork-text); background: var(--ork-bg-secondary); }

.adg-doc { background: var(--ork-card-bg); border: 1px solid var(--ork-border); border-radius: 10px; box-shadow: var(--ork-shadow-card); padding: 28px 36px 36px; max-width: calc(76ch + 72px); }
.adg-md { max-width: 76ch; font-size: 15px; line-height: 1.6; color: var(--ork-text); }

/* orkui.css styles every bare heading as a grey pill; the guide wants plain
   document headings (same reset the survey guide uses), repeated for dark mode. */
.adg-md h2, .adg-md h3, .adg-md h4,
html[data-theme="dark"] .adg-md h2, html[data-theme="dark"] .adg-md h3, html[data-theme="dark"] .adg-md h4 {
    background: none; border: 0; border-radius: 0; padding: 0; box-shadow: none; text-shadow: none;
    color: var(--ork-text); font-family: var(--ork-font-heading); line-height: 1.3; overflow-wrap: anywhere;
    scroll-margin-top: 80px;
}
.adg-md h2 { font-size: 21px; margin: 36px 0 12px; padding-bottom: 8px; border-bottom: 1px solid var(--ork-border); }
.adg-md h3 { font-size: 16px; margin: 24px 0 8px; }
.adg-md > h2:first-child { margin-top: 0; }
.adg-md p { margin: 0 0 12px; }
.adg-md ul, .adg-md ol { margin: 8px 0 14px; padding-left: 24px; }
.adg-md li { margin-bottom: 6px; }
.adg-md li > p { margin: 0; }
.adg-md a { color: #2b6cb0; }
html[data-theme="dark"] .adg-md a { color: var(--ork-link-bright); }
.adg-md strong { color: var(--ork-text); }
.adg-md code { background: var(--ork-bg-secondary); padding: 1px 5px; border-radius: 4px; font-size: .92em; }
.adg-md blockquote { margin: 12px 0; padding: 8px 14px; border-left: 4px solid #2b6cb0; background: var(--ork-bg-secondary); border-radius: 0 6px 6px 0; }
.adg-md table { border-collapse: collapse; width: 100%; margin: 12px 0 18px; font-size: 14px; }
.adg-md th, .adg-md td { border: 1px solid var(--ork-border); padding: 8px 10px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
.adg-md th { background: var(--ork-bg-secondary); font-weight: 700; }

/* Hover anchor on each heading: the link people paste into Discord. */
.adg-md .adg-anchor { margin-left: 8px; font-size: .75em; color: var(--ork-text-hint); text-decoration: none; opacity: 0; transition: opacity .15s; }
.adg-md h2:hover .adg-anchor, .adg-md h3:hover .adg-anchor, .adg-md .adg-anchor:focus { opacity: 1; }
.adg-md h2:target, .adg-md h3:target { animation: adg-flash 1.6s ease-out; }
@keyframes adg-flash { from { background: rgba(66,153,225,0.25); } to { background: transparent; } }

/* Wide tables scroll on their own rather than the page. */
.adg-md .adg-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }

@media (max-width: 900px) {
    .adg-layout { grid-template-columns: 1fr; gap: 16px; }
    .adg-toc { position: static; }
    .adg-toc details { background: var(--ork-card-bg); border: 1px solid var(--ork-border); border-radius: 10px; padding: 10px 14px; }
    .adg-toc summary { cursor: pointer; font-weight: 700; font-size: 14px; min-height: 44px; display: flex; align-items: center; gap: 8px; }
    .adg-toc ol { margin-top: 8px; }
    .adg-toc a { min-height: 44px; display: flex; align-items: center; }
    .adg-doc { padding: 20px 16px 28px; }
    .adg-md { font-size: 15px; }
    .adg-md h2 { font-size: 19px; margin-top: 28px; }
}
@media (min-width: 901px) {
    .adg-toc summary { list-style: none; pointer-events: none; }
    .adg-toc summary::-webkit-details-marker { display: none; }
    .adg-toc summary .adg-toc-chevron { display: none; }
}
@media print {
    .cp-hero-badges, .cp-hero-stats, .adg-toc, .ork-header, .site-header, #wn-overlay { display: none !important; }
    .adg-layout { display: block; }
    .adg-doc { border: 0; box-shadow: none; padding: 0; }
    .adg-md { max-width: none; font-size: 12pt; }
    .adg-md .adg-anchor { display: none; }
    .adg-md a[href^="http"]::after { content: " (" attr(href) ")"; font-size: .85em; color: #555; }
}
</style>

<!-- =============================================
     HERO
     ============================================= -->
<div class="cp-hero">
	<div class="cp-hero-content">
		<div class="cp-hero-icon">
			<i class="fas fa-book-open"></i>
		</div>
		<div class="cp-hero-info">
			<h1 class="cp-hero-title">ORK Administrator Guide</h1>
			<div class="cp-hero-sub">What global admin is for, what to check before acting, and where to log what you did</div>
			<div class="cp-hero-badges">
				<span class="cp-hero-badge"><i class="fas fa-shield-alt"></i> Admins only</span>
<?php if ($adgUpdated !== ''): ?>
				<span class="cp-hero-badge"><i class="fas fa-clock"></i> Updated <?= htmlspecialchars($adgUpdated) ?></span>
<?php endif; ?>
				<a class="cp-hero-badge" href="<?= UIR ?>Admin" style="text-decoration:none"><i class="fas fa-arrow-left"></i> Back to Admin</a>
			</div>
		</div>
		<div class="cp-hero-stats">
			<div class="cp-hero-stat">
				<span class="cp-hero-stat-val"><?= (int) $adgSections ?></span>
				<span class="cp-hero-stat-lbl">Sections</span>
			</div>
		</div>
	</div>
</div>

<?php if ($adgError !== ''): ?>
<div class="adm-card" style="padding:18px 20px;"><i class="fas fa-exclamation-triangle" style="color:#c53030;margin-right:8px"></i><?= htmlspecialchars($adgError) ?></div>
<?php else: ?>
<div class="adg-layout">
	<nav class="adg-toc" aria-label="On this page">
		<details id="adg-toc-details">
			<summary><i class="fas fa-list adg-toc-chevron" aria-hidden="true"></i> <span class="adg-toc-title" style="margin:0">On this page</span></summary>
			<ol id="adg-toc-list">
<?php foreach ($adgToc as $t): ?>
				<li class="<?= (int) $t['level'] === 3 ? 'adg-sub' : '' ?>"><a href="#<?= htmlspecialchars($t['id']) ?>"><?= htmlspecialchars($t['text']) ?></a></li>
<?php endforeach; ?>
			</ol>
		</details>
		<div class="adg-toc-print">
			<button type="button" class="adg-print-btn" onclick="window.print()"><i class="fas fa-print" aria-hidden="true"></i> Print this guide</button>
		</div>
	</nav>

	<article class="adg-doc">
		<div class="adg-md" id="adg-md"><?= $adgHtml ?></div>
	</article>
</div>
<?php endif; ?>

<script>
(function () {
	var md = document.getElementById('adg-md');
	if (!md) return;

	// Contents: always open beside the text on desktop; a collapsed, tappable
	// list above it on phones so the guide itself is not pushed off screen.
	var det = document.getElementById('adg-toc-details');
	function tocMode() { if (det && window.innerWidth > 900) det.open = true; }
	tocMode();
	window.addEventListener('resize', tocMode);

	// Wide tables scroll inside a wrapper on narrow screens.
	Array.prototype.forEach.call(md.querySelectorAll('table'), function (t) {
		var w = document.createElement('div');
		w.className = 'adg-table-wrap';
		t.parentNode.insertBefore(w, t);
		w.appendChild(t);
	});

	// Hover anchor on each section heading.
	var heads = Array.prototype.slice.call(md.querySelectorAll('h2[id], h3[id]'));
	heads.forEach(function (h) {
		var a = document.createElement('a');
		a.className = 'adg-anchor';
		a.href = '#' + h.id;
		a.setAttribute('aria-label', 'Link to this section');
		a.innerHTML = '<i class="fas fa-link" aria-hidden="true"></i>';
		h.appendChild(a);
	});

	// Contents rail: highlight the section in view.
	var links = {};
	Array.prototype.forEach.call(document.querySelectorAll('#adg-toc-list a'), function (a) {
		links[a.getAttribute('href').slice(1)] = a;
	});
	var current = null;
	function setCurrent(id) {
		if (id === current) return;
		if (current && links[current]) links[current].classList.remove('adg-current');
		current = id;
		if (links[id]) links[id].classList.add('adg-current');
	}
	function onScroll() {
		var line = 96, pick = heads.length ? heads[0].id : null;
		for (var i = 0; i < heads.length; i++) {
			if (heads[i].getBoundingClientRect().top <= line) pick = heads[i].id; else break;
		}
		if (pick) setCurrent(pick);
	}
	window.addEventListener('scroll', onScroll, { passive: true });
	onScroll();
})();
</script>
