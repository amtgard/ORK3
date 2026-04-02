<?php
/* ── Pre-compute scope ────────────────────────────────────── */
$kingdom_id    = $kingdom_id ?? null;
$knights       = is_array($Knights)       ? $Knights       : array();
$relationships = is_array($BeltlineRelationships) ? $BeltlineRelationships : array();
// Strip magic_quotes-era backslash escapes from persona fields
$knights = array_map(function($k) {
	$k['Persona'] = stripslashes($k['Persona'] ?? '');
	return $k;
}, $knights);
$relationships = array_map(function($r) {
	$r['RecipientPersona'] = stripslashes($r['RecipientPersona'] ?? '');
	$r['GiverPersona']     = stripslashes($r['GiverPersona'] ?? '');
	return $r;
}, $relationships);
$knight_count  = count($knights);
$rel_count     = count($relationships);

$scope_label = '';
$scope_link  = '';
if ($kingdom_id && !empty($knights)) {
	// Derive kingdom name from relationships if available
	// (Not passed directly — use session)
}
?>

<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<style>
/* ── Beltline Explorer specific styles ───────────────────── */
.be-root { }

.be-selector-bar {
	display: flex;
	align-items: center;
	gap: 12px;
	background: #fff;
	border: 1px solid #dde2ec;
	border-radius: 8px;
	padding: 14px 18px;
	margin-bottom: 20px;
	flex-wrap: wrap;
}
.be-selector-bar label {
	font-weight: 600;
	color: #3a3f5c;
	white-space: nowrap;
}
.be-selector-bar select {
	flex: 1;
	min-width: 200px;
	padding: 7px 10px;
	border: 1px solid #c5cde0;
	border-radius: 6px;
	font-size: 14px;
	background: #f8f9fc;
	color: #3a3f5c;
}
.be-selector-bar select:focus {
	outline: none;
	border-color: #7c8cf8;
	box-shadow: 0 0 0 3px rgba(124,140,248,0.15);
}
.be-view-toggle {
	display: flex;
	gap: 6px;
}
.be-view-btn {
	padding: 6px 12px;
	border: 1px solid #c5cde0;
	border-radius: 6px;
	background: #f0f2f8;
	color: #555;
	cursor: pointer;
	font-size: 13px;
	transition: all 0.15s;
}
.be-view-btn.active, .be-view-btn:hover {
	background: #4f5bd5;
	color: #fff;
	border-color: #4f5bd5;
}

.be-empty-state {
	text-align: center;
	padding: 60px 20px;
	color: #8a93b2;
}
.be-empty-state i {
	font-size: 48px;
	margin-bottom: 14px;
	display: block;
	color: #c5cde0;
}
.be-empty-state h3 {
	background: transparent !important;
	border: none !important;
	padding: 0 !important;
	border-radius: 0 !important;
	text-shadow: none !important;
	color: #5a6282;
	margin-bottom: 8px;
}

/* ── Tree view ───────────────────────────────────────────── */
.be-tree-wrap {
	background: #fff;
	border: 1px solid #dde2ec;
	border-radius: 8px;
	padding: 20px 24px;
	overflow-x: auto;
}
.be-tree-title {
	font-size: 13px;
	font-weight: 600;
	color: #8a93b2;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	margin-bottom: 16px;
}

/* Classic CSS tree with connector lines */
.be-tree,
.be-tree ul {
	list-style: none;
	margin: 0;
	padding: 0;
}
.be-tree ul {
	padding-left: 28px;
	position: relative;
}
.be-tree ul::before {
	content: '';
	position: absolute;
	left: 10px;
	top: 0;
	bottom: 12px;
	border-left: 2px solid #d8dcea;
}
.be-tree li {
	position: relative;
	padding: 3px 0 3px 22px;
}
.be-tree li::before {
	content: '';
	position: absolute;
	left: 0;
	top: 14px;
	width: 20px;
	border-top: 2px solid #d8dcea;
}
/* Root node has no connector */
.be-tree > li::before,
.be-tree > li::after {
	display: none;
}

.be-node {
	display: inline-flex;
	align-items: center;
	gap: 7px;
	padding: 5px 10px;
	border-radius: 6px;
	background: #f8f9fc;
	border: 1px solid #e4e8f0;
	margin: 2px 0;
	transition: background 0.12s;
}
.be-node:hover {
	background: #eef0fc;
}
.be-node.be-node-root {
	background: #fff8e1;
	border-color: #f0c040;
}
.be-node.be-node-selected {
	background: #e8edfc;
	border-color: #7c8cf8;
	box-shadow: 0 0 0 2px rgba(124,140,248,0.25);
}
.be-node.be-node-selected .be-persona {
	font-weight: 700;
}

.be-persona {
	color: #3a3f5c;
	text-decoration: none;
	font-size: 14px;
}
.be-persona:hover {
	color: #4f5bd5;
	text-decoration: underline;
}

.be-knight-types {
	font-size: 11px;
	color: #7a6000;
	font-style: italic;
}

.be-title-badge {
	font-size: 11px;
	font-weight: 600;
	padding: 2px 7px;
	border-radius: 20px;
	letter-spacing: 0.03em;
	white-space: nowrap;
}
.be-badge-squire    { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }
.be-badge-manatarms { background: #cce5ff; color: #004085; border: 1px solid #7abaff; }
.be-badge-page      { background: #d4edda; color: #155724; border: 1px solid #5cb85c; }
.be-badge-lordspage  { background: #d1ecf1; color: #0c5460; border: 1px solid #5bc0de; }
.be-badge-womanatarms { background: #f3e8ff; color: #5b2d8e; border: 1px solid #c084fc; }

.be-date {
	font-size: 11px;
	color: #9aa1bc;
}

/* ── Table view ──────────────────────────────────────────── */
.be-table-wrap {
	background: #fff;
	border: 1px solid #dde2ec;
	border-radius: 8px;
	padding: 20px 24px;
	overflow-x: auto;
}

/* ── No-lineage notice ───────────────────────────────────── */
.be-no-lineage {
	text-align: center;
	padding: 30px;
	color: #8a93b2;
	font-style: italic;
}
</style>

<div class="rp-root be-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-sitemap rp-header-icon"></i>
				<h1 class="rp-header-title">Beltline Explorer</h1>
			</div>
<?php if (isset($this->__session->kingdom_id)) : ?>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?=UIR?>Kingdom/profile/<?=(int)$this->__session->kingdom_id?>">
					<i class="fas fa-chess-rook"></i> Kingdom
				</a>
			</div>
<?php endif; ?>
		</div>
	</div>

	<!-- ── Context strip ──────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<div>
			<span>Select a knight to explore their beltline family — squires, persons-at-arms, pages, and their full lineage going both up and down the chain.</span>
			<p style="margin: 8px 0 0;">Please note, beltline data is based on the presence of associate titles (Page, Squire, Man-at-Arms, Woman-at-Arms, Noble&#39;s Page) being present in the titles list of the given player with a proper assignment of &ldquo;Given By&rdquo; indicating the individual who took them on as an associate. If the data in this report looks incorrect or data is missing, please review the player profiles involved and ask your local officers to help correct any missing or incomplete data.</p>
		</div>
	</div>

<?php if (!empty($no_kingdom)) : ?>
	<div class="be-empty-state">
		<i class="fas fa-sitemap"></i>
		<h3>No kingdom selected</h3>
		<p>Log in or navigate from a kingdom page to use this report.</p>
	</div>
<?php else : ?>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-chess-king"></i></div>
			<div class="rp-stat-number"><?=$knight_count?></div>
			<div class="rp-stat-label">Knights</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-shield-alt"></i></div>
			<div class="rp-stat-number"><?=$rel_count?></div>
			<div class="rp-stat-label">Beltline Records</div>
		</div>
	</div>

	<!-- ── Knight selector ───────────────────────────────── -->
	<div class="be-selector-bar">
		<label for="be-knight-select"><i class="fas fa-chess-king"></i> Select Knight:</label>
		<select id="be-knight-select">
			<option value="">— Choose a knight —</option>
<?php foreach ($knights as $k) : ?>
			<option value="<?=(int)$k['MundaneId']?>"><?=htmlspecialchars($k['Persona'])?></option>
<?php endforeach; ?>
		</select>
		<div class="be-view-toggle">
			<button class="be-view-btn active" id="be-btn-tree" title="Tree view"><i class="fas fa-sitemap"></i> Tree</button>
			<button class="be-view-btn" id="be-btn-table" title="Table view"><i class="fas fa-list"></i> Table</button>
		</div>
	</div>

	<!-- ── Tree view ─────────────────────────────────────── -->
	<div id="be-tree-view">
		<div class="be-empty-state" id="be-tree-empty">
			<i class="fas fa-sitemap"></i>
			<h3>Select a knight above</h3>
			<p>The beltline tree will appear here.</p>
		</div>
		<div class="be-tree-wrap" id="be-tree-container" style="display:none;">
			<div class="be-tree-title" id="be-tree-title"></div>
			<ul class="be-tree" id="be-tree"></ul>
		</div>
	</div>

	<!-- ── Table view ───────────────────────────────────── -->
	<div id="be-table-view" style="display:none;">
		<div class="be-empty-state" id="be-table-empty">
			<i class="fas fa-list"></i>
			<h3>Select a knight above</h3>
			<p>The beltline members list will appear here.</p>
		</div>
		<div class="be-table-wrap" id="be-table-container" style="display:none;">
			<table id="be-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Persona</th>
						<th>Title</th>
						<th>Given By</th>
						<th>Date</th>
					</tr>
				</thead>
				<tbody id="be-table-body"></tbody>
			</table>
		</div>
	</div>

<?php endif; ?>

</div><!-- /rp-root -->

<?php if (empty($no_kingdom)) : ?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<script>
(function() {
	var BASE_URL = <?= json_encode(UIR) ?>;

	/* ── Raw data from PHP ──────────────────────────────── */
	var allRelationships = <?= json_encode($relationships) ?>;
	var allKnights       = <?= json_encode($knights) ?>;
	var allKnightIds     = <?= json_encode(array_values($AllKnightIds ?? [])) ?>;
	var knightTypes      = <?= json_encode((object)($KnightTypes ?? [])) ?>;

	/* ── Build lookup maps ──────────────────────── */
	// childrenBest[giver_id][recipient_id] = most-recent rel (deduplicated before building childrenMap)
	var childrenBest = {};
	// beltlineParentMap[recipient_id] = highest-priority beltline parent record
	// Priority: Squire(4) > Man-At-Arms(3) > Lords-Page(2) > Page(1)
	var beltlineParentMap = {};
	var PEERAGE_PRIORITY = { 'Squire': 4, 'Man-At-Arms': 3, 'Woman-At-Arms': 3, 'Lords-Page': 2, 'Page': 1 };
	function effectivePeerage(rel) {
		if (rel.Peerage && PEERAGE_PRIORITY[rel.Peerage]) return rel.Peerage;
		if (rel.TitleName && /woman.{0,3}at.{0,3}arms/i.test(rel.TitleName)) return 'Woman-At-Arms';
		return rel.Peerage || 'Page';
	}
	// personaMap[mundane_id] = persona
	var personaMap = {};

	// Seed from knights list
	allKnights.forEach(function(k) {
		personaMap[k.MundaneId] = k.Persona;
	});

	allRelationships.forEach(function(rel) {
		personaMap[rel.RecipientId] = rel.RecipientPersona;
		if (rel.GiverId) {
			personaMap[rel.GiverId] = rel.GiverPersona;
			// Deduplicate per (GiverId, RecipientId) — keep only the most recent title
			if (!childrenBest[rel.GiverId]) childrenBest[rel.GiverId] = {};
			var prevBest = childrenBest[rel.GiverId][rel.RecipientId];
			if (!prevBest || (rel.Date || '') > (prevBest.Date || '')) {
				childrenBest[rel.GiverId][rel.RecipientId] = rel;
			}
			// Track highest-priority beltline parent for backward traversal
			var newPri = PEERAGE_PRIORITY[effectivePeerage(rel)] || 0;
			var existing = beltlineParentMap[rel.RecipientId];
			var existingPri = existing ? (PEERAGE_PRIORITY[existing.Peerage] || 0) : -1;
			if (newPri > existingPri) {
				beltlineParentMap[rel.RecipientId] = rel;
			}
		}
	});

	// Flatten childrenBest into childrenMap arrays
	var childrenMap = {};
	Object.keys(childrenBest).forEach(function(giverId) {
		childrenMap[giverId] = Object.keys(childrenBest[giverId]).map(function(recId) {
			return childrenBest[giverId][recId];
		});
	});

	/* ── Knight set for quick lookup (dropdown + global) ── */
	var knightSet = {};
	allKnights.forEach(function(k) { knightSet[k.MundaneId] = true; });
	allKnightIds.forEach(function(id) { knightSet[id] = true; });

	/* ── Helpers ─────────────────────────────────────────*/
	function esc(s) {
		if (!s) return '';
		return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	function peerage_to_class(rel) {
		var map = {
			'Squire':        'be-badge-squire',
			'Man-At-Arms':   'be-badge-manatarms',
			'Woman-At-Arms': 'be-badge-womanatarms',
			'Page':          'be-badge-page',
			'Lords-Page':    'be-badge-lordspage'
		};
		return map[effectivePeerage(rel)] || 'be-badge-squire';
	}

	/* ── Find root of beltline (walk up all beltline types) ─── */
	function findRoot(id, visited) {
		if (!visited) visited = {};
		if (visited[id]) return id; // cycle guard
		visited[id] = true;
		var parent = beltlineParentMap[id];
		if (parent && parent.GiverId) {
			return findRoot(parent.GiverId, visited);
		}
		return id;
	}

	/* ── Collect all nodes in this lineage (BFS from root) */
	function collectLineageIds(rootId) {
		var ids = {};
		var queue = [rootId];
		ids[rootId] = true;
		while (queue.length) {
			var cur = queue.shift();
			var kids = childrenMap[cur] || [];
			kids.forEach(function(rel) {
				if (!ids[rel.RecipientId]) {
					ids[rel.RecipientId] = true;
					queue.push(rel.RecipientId);
				}
			});
		}
		return ids;
	}

	/* ── Render a single tree node ──────────────────────── */
	function renderNodeHtml(nodeId, titleBadge, dateStr, selectedId, visited) {
		if (!visited) visited = {};
		if (visited[nodeId]) return ''; // cycle guard
		visited[nodeId] = true;

		var persona   = esc(personaMap[nodeId] || 'Unknown');
		var isSelected = nodeId == selectedId;
		var isKnight   = !!knightSet[nodeId];
		var isRoot     = !beltlineParentMap[nodeId] || !beltlineParentMap[nodeId].GiverId;

		var nodeClass = 'be-node';
		if (isSelected) nodeClass += ' be-node-selected';
		if (isRoot && !titleBadge) nodeClass += ' be-node-root';

		var html = '<li>';
		html += '<div class="' + nodeClass + '">';

		if (titleBadge) {
			html += '<span class="be-title-badge ' + titleBadge.cls + '">' + esc(titleBadge.name) + '</span>';
		}
		html += '<a href="' + BASE_URL + 'Player/profile/' + nodeId + '" class="be-persona">' + persona + '</a>';
		if (dateStr) {
			html += '<span class="be-date">' + esc(dateStr) + '</span>';
		}
		if (isKnight) {
			var types = knightTypes[nodeId];
			if (types && types.length) {
				html += '<span class="be-knight-types">' + types.map(esc).join(', ') + '</span>';
			}
		}
		html += '</div>';

		// Children
		var kids = childrenMap[nodeId] || [];
		if (kids.length) {
			html += '<ul>';
			kids.forEach(function(rel) {
				var badge = {
					name: rel.TitleName,
					cls:  peerage_to_class(rel)
				};
				html += renderNodeHtml(rel.RecipientId, badge, rel.Date, selectedId, visited);
			});
			html += '</ul>';
		}

		html += '</li>';
		return html;
	}

	/* ── Render tree for a selected knight ─────────────── */
	function renderTree(knightId) {
		var rootId   = findRoot(knightId, {});
		var rootName = esc(personaMap[rootId] || 'Unknown');

		document.getElementById('be-tree-title').textContent =
			'Beltline of ' + (personaMap[rootId] || 'Unknown') +
			(rootId != knightId ? ' (root ancestor of selected)' : '');

		var html = renderNodeHtml(rootId, null, null, knightId, {});
		document.getElementById('be-tree').innerHTML = html;
		document.getElementById('be-tree-empty').style.display = 'none';
		document.getElementById('be-tree-container').style.display = 'block';
	}

	/* ── Render table for a selected knight ─────────────── */
	var dtTable = null;
	function renderTable(knightId) {
		var rootId    = findRoot(knightId, {});
		var lineage   = collectLineageIds(rootId);

		// Collect all relationships in this lineage
		var rows = [];
		allRelationships.forEach(function(rel) {
			if (lineage[rel.RecipientId]) {
				rows.push(rel);
			}
		});

		var tbody = document.getElementById('be-table-body');
		tbody.innerHTML = '';
		rows.forEach(function(rel) {
			var tr = document.createElement('tr');
			tr.innerHTML =
				'<td><a href="' + BASE_URL + 'Player/profile/' + rel.RecipientId + '">' + esc(rel.RecipientPersona) + '</a></td>' +
				'<td><span class="be-title-badge ' + peerage_to_class(rel) + '">' + esc(rel.TitleName) + '</span></td>' +
				'<td>' + (rel.GiverId ? '<a href="' + BASE_URL + 'Player/profile/' + rel.GiverId + '">' + esc(rel.GiverPersona) + '</a>' : '') + '</td>' +
				'<td>' + esc(rel.Date) + '</td>';
			tbody.appendChild(tr);
		});

		if (dtTable) {
			dtTable.destroy();
			dtTable = null;
		}

		document.getElementById('be-table-empty').style.display = 'none';
		document.getElementById('be-table-container').style.display = 'block';

		dtTable = $('#be-table').DataTable({
			dom: 'lfrtip',
			pageLength: 50,
			order: [[3, 'asc']],
			destroy: true
		});
	}

	/* ── View toggle ─────────────────────────────────────── */
	var currentView = 'tree';
	var currentKnightId = null;

	document.getElementById('be-btn-tree').addEventListener('click', function() {
		currentView = 'tree';
		document.getElementById('be-btn-tree').classList.add('active');
		document.getElementById('be-btn-table').classList.remove('active');
		document.getElementById('be-tree-view').style.display = '';
		document.getElementById('be-table-view').style.display = 'none';
	});

	document.getElementById('be-btn-table').addEventListener('click', function() {
		currentView = 'table';
		document.getElementById('be-btn-table').classList.add('active');
		document.getElementById('be-btn-tree').classList.remove('active');
		document.getElementById('be-tree-view').style.display = 'none';
		document.getElementById('be-table-view').style.display = '';
		if (currentKnightId) renderTable(currentKnightId);
	});

	/* ── Knight selector ─────────────────────────────────── */
	document.getElementById('be-knight-select').addEventListener('change', function() {
		var val = parseInt(this.value, 10);
		if (!val) {
			currentKnightId = null;
			document.getElementById('be-tree-empty').style.display = '';
			document.getElementById('be-tree-container').style.display = 'none';
			document.getElementById('be-table-empty').style.display = '';
			document.getElementById('be-table-container').style.display = 'none';
			return;
		}
		currentKnightId = val;
		renderTree(val);
		if (currentView === 'table') renderTable(val);
	});

})();
</script>
<?php endif; ?>
