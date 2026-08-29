<?php
/**
 * =============================================================================
 * Officer Details Modal — shared partial (Kingdom + Park)
 * =============================================================================
 *
 * ONE modal, included by BOTH Kingdomnew_index.tpl and Parknew_index.tpl, with
 * two tabs: "Current Officers" (the full position tree) and "Officer History"
 * (lazy-loaded, re-homed from the old main-content tab).
 *
 * A neutral `of-` prefix is used throughout so a single copy serves both
 * surfaces. Everything it needs — chrome CSS, overlay show/hide, tab CSS, JS —
 * is self-contained in this file; nothing has to be added to revised.css.
 *
 * -----------------------------------------------------------------------------
 * INPUT CONTRACT — the including template must set these BEFORE the include:
 * -----------------------------------------------------------------------------
 *   $officerList  array   Officer rows from Kingdom::buildOfficerRows(). Keys read
 *                         here (all optional — see "graceful degradation" below):
 *                           PositionId       int|null  position registry id
 *                           ParentPositionId int|null  the office this reports to
 *                           Classification   string    'crown' | 'supporting'
 *                           SortOrder        int|null  per-kingdom sibling order
 *                           DisplayTitle     string    kingdom-aliased office name
 *                           CanonicalKey     string    stable key ('prime_minister')
 *                           OfficerRole      string    human label (display fallback)
 *                           OfficerId        int
 *                           MundaneId        int|null  0/null == vacant
 *                           Persona          string
 *                           GivenName        string    (blank unless authorized)
 *                           Surname          string    (blank unless authorized)
 *                           UserName         string
 *                           HideWhenVacant   int
 *   $ofScope      string  'kingdom' | 'park'   (default 'kingdom')
 *   $ofOrgId      int     kingdom_id or park_id
 *   $ofOrgName    string  display name for the modal title
 *
 * OPTIONAL, picked up from the host page if present:
 *   $OfficerHistoryRoleOptions / $ohRoleOptions   array  key => label, role filter
 *   $CanEditKingdom (kingdom) / $CanManagePark (park)    bool, gates history edit
 *
 * -----------------------------------------------------------------------------
 * PUBLIC JS API (for the host page's officers-bar arrow button)
 * -----------------------------------------------------------------------------
 *   ofOpenOfficerModal()   — opens on the Current Officers tab
 *   ofCloseOfficerModal()  — closes
 *
 * -----------------------------------------------------------------------------
 * GRACEFUL DEGRADATION
 * -----------------------------------------------------------------------------
 * PositionId / Classification / SortOrder are being ADDED to buildOfficerRows()
 * concurrently with this file. Until they land:
 *   - no PositionId  -> every row is its own root; the list renders FLAT, in the
 *                       order the SELECT already sorted it. No fatal, no notice.
 *   - no Classification -> no crown glyph, nothing else changes.
 *   - no SortOrder   -> siblings fall back to the row's SQL position, which is
 *                       already ordered by classification + sort_order + role.
 *
 * -----------------------------------------------------------------------------
 * LAYERING
 * -----------------------------------------------------------------------------
 * The tree is built in plain PHP over an array already in hand. No SQL, no
 * domain class, no Ork3::$Lib — nothing here crosses a layer boundary.
 */

// ---------------------------------------------------------------------------
// Input normalisation
// ---------------------------------------------------------------------------
$ofScope   = (isset($ofScope) && $ofScope === 'park') ? 'park' : 'kingdom';
$ofIsPark  = ($ofScope === 'park');
$ofOrgId   = (int)($ofOrgId ?? 0);
$ofOrgName = (string)($ofOrgName ?? '');
$ofRows    = is_array($officerList ?? null) ? $officerList : [];

// The host page's own prefix. Used only to (a) address its Add/Edit history
// modals, which stay where they are, and (b) detect whether the old
// main-content history panel has been removed yet.
$ofHostPrefix = $ofIsPark ? 'pk' : 'kn';

// Endpoint. Confirmed by reading the controllers, not guessed:
//   kingdom -> KingdomAjax/kingdom/{id}/officerhistory  (controller.KingdomAjax.php:994)
//   park    -> ParkAjax/park/{id}/officerhistory        (controller.ParkAjax.php:441)
// Both take an optional ?Role= canonical key, and BOTH require a logged-in
// session (KingdomAjax:12, ParkAjax:11) — they answer {"status":5} to a guest,
// which the JS below reports as a sign-in prompt rather than a failure.
$ofHistoryUrl = UIR . ($ofIsPark
	? 'ParkAjax/park/' . $ofOrgId . '/officerhistory'
	: 'KingdomAjax/kingdom/' . $ofOrgId . '/officerhistory');

// History editing permission, per surface (Kingdom and Park do not share a flag).
$ofCanEditHistory = $ofIsPark
	? !empty($CanManagePark)
	: (bool)($CanEditKingdom ?? false);

// Role filter options. Both host templates already derive this from
// $OfficerHistoryRoleOptions; re-derive rather than depend on include order.
$ofRoleOptions = [];
if (is_array($OfficerHistoryRoleOptions ?? null)) {
	$ofRoleOptions = $OfficerHistoryRoleOptions;
} elseif (is_array($ohRoleOptions ?? null)) {
	$ofRoleOptions = $ohRoleOptions;
}

// ---------------------------------------------------------------------------
// Tree building
// ---------------------------------------------------------------------------
// function_exists guards: a partial included twice on one page must not fatal.
if (!function_exists('of_officer_display_name')) {
	/**
	 * Best available public name for an officer row.
	 * GivenName/Surname arrive blank unless the viewer is authorized, so they
	 * are the LAST fallback, not the first.
	 */
	function of_officer_display_name(array $row)
	{
		$persona = trim((string)($row['Persona'] ?? ''));
		if ($persona !== '') {
			return $persona;
		}
		$username = trim((string)($row['UserName'] ?? ''));
		if ($username !== '') {
			return $username;
		}
		$real = trim(trim((string)($row['GivenName'] ?? '')) . ' ' . trim((string)($row['Surname'] ?? '')));
		return $real !== '' ? $real : 'Unknown';
	}
}

if (!function_exists('of_officer_build_nodes')) {
	/**
	 * Collapse officer ROWS into position NODES.
	 *
	 * buildOfficerRows() emits one row per officer record, so a supporting
	 * office held by three people arrives as three rows sharing one PositionId.
	 * Feeding those straight into the tree would create three nodes for one
	 * office and the cycle guard would silently swallow two of them. Group
	 * first, then walk.
	 *
	 * Rows with no PositionId (legacy officers whose position_id is NULL) can
	 * never be grouped or nested, so each keeps its own node keyed by sequence.
	 *
	 * @return array [ nodeKey => node ]
	 */
	function of_officer_build_nodes(array $rows)
	{
		$nodes = [];
		$seq   = 0;
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$positionId = (int)($row['PositionId'] ?? 0);
			$key = $positionId > 0 ? 'p' . $positionId : 'r' . $seq;

			if (!isset($nodes[$key])) {
				$title = trim((string)($row['DisplayTitle'] ?? ''));
				if ($title === '') {
					$title = trim((string)($row['OfficerRole'] ?? ''));
				}
				if ($title === '') {
					$title = trim((string)($row['CanonicalKey'] ?? ''));
				}
				if ($title === '') {
					$title = 'Officer';
				}

				// SortOrder may be absent (pre-migration) or NULL (position has
				// no explicit order). Fall back to the row's SQL position: the
				// SELECT already ordered by classification + sort_order + role,
				// so that degrades to the server's own ordering.
				$sortOrder = (isset($row['SortOrder']) && is_numeric($row['SortOrder']))
					? (int)$row['SortOrder']
					: $seq;

				$parentId = (int)($row['ParentPositionId'] ?? 0);

				$nodes[$key] = [
					'Key'        => $key,
					'PositionId' => $positionId,
					'ParentId'   => $parentId > 0 ? $parentId : 0,
					'IsCrown'    => ((string)($row['Classification'] ?? '')) === 'crown',
					'Title'      => $title,
					'SortOrder'  => $sortOrder,
					'Seq'        => $seq,
					'Holders'    => [],
				];
			}

			$mundaneId = (int)($row['MundaneId'] ?? 0);
			if ($mundaneId > 0) {
				$nodes[$key]['Holders'][] = [
					'MundaneId' => $mundaneId,
					'Name'      => of_officer_display_name($row),
				];
			}
			$seq++;
		}
		return $nodes;
	}
}

if (!function_exists('of_officer_cmp')) {
	/**
	 * Sibling ordering. Rows CAN share a sort_order, so the comparator has to
	 * produce a total order or the list would jump between page loads:
	 * SortOrder -> crown before supporting -> title -> original sequence.
	 * (Mirrors the admin console's sortBySort, _manage_officers.tpl:813.)
	 */
	function of_officer_cmp(array $a, array $b)
	{
		if ($a['SortOrder'] !== $b['SortOrder']) {
			return $a['SortOrder'] <=> $b['SortOrder'];
		}
		if ($a['IsCrown'] !== $b['IsCrown']) {
			return $a['IsCrown'] ? -1 : 1;
		}
		$t = strcasecmp($a['Title'], $b['Title']);
		if ($t !== 0) {
			return $t;
		}
		return $a['Seq'] <=> $b['Seq'];
	}
}

if (!function_exists('of_officer_render_level')) {
	/**
	 * Render one level of the tree, recursing into children.
	 *
	 * Guards, mirroring the admin console's renderGroupTree
	 * (_manage_officers.tpl:822-854):
	 *   - $seen  cycle guard: a position already drawn is never drawn again, so
	 *            a parent<->child loop in the data terminates instead of
	 *            recursing forever.
	 *   - depth  cap of 12. A chain deeper than that is malformed data, not a
	 *            real org chart; the cap keeps a pathological row set from
	 *            blowing the stack.
	 *   - orphan a row whose ParentPositionId points at a position that is NOT
	 *            in this set (retired, or filtered out) is collected as a ROOT
	 *            by the caller rather than dropped. Unlike the admin console it
	 *            gets NO "Reports to X (retired)" caption: a member has no
	 *            action to take on that and should not see a dangling
	 *            reference to an office that no longer exists.
	 *
	 * @param string[] $keys     node keys at this level, pre-sorted
	 * @param array    $nodes    key => node
	 * @param array    $children parentPositionId => [nodeKey, ...]
	 * @param array    $seen     by-ref cycle guard
	 */
	function of_officer_render_level(array $keys, array $nodes, array $children, array &$seen, $depth = 0)
	{
		$html = '';
		foreach ($keys as $key) {
			if (!isset($nodes[$key]) || isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$node = $nodes[$key];

			$html .= '<li class="of-node">';
			$html .= '<div class="of-row">';

			$html .= '<div class="of-role">';
			if ($node['IsCrown']) {
				$html .= '<i class="fas fa-crown of-crown-glyph" aria-hidden="true"></i>'
					. '<span class="of-sr-only">Crown office: </span>';
			}
			$html .= htmlspecialchars($node['Title']);
			$html .= '</div>';

			$html .= '<div class="of-holder">';
			if (!empty($node['Holders'])) {
				$parts = [];
				foreach ($node['Holders'] as $holder) {
					$parts[] = '<a href="' . htmlspecialchars(UIR) . 'Player/profile/'
						. (int)$holder['MundaneId'] . '">'
						. htmlspecialchars($holder['Name']) . '</a>';
				}
				$html .= implode('<span class="of-holder-sep">, </span>', $parts);
			} else {
				$html .= '<span class="of-vacant">Vacant</span>';
			}
			$html .= '</div>';

			$html .= '</div>'; // .of-row

			$pid = $node['PositionId'];
			if ($pid > 0 && !empty($children[$pid]) && $depth < 12) {
				$kidNodes = [];
				foreach ($children[$pid] as $kidKey) {
					if (isset($nodes[$kidKey])) {
						$kidNodes[] = $nodes[$kidKey];
					}
				}
				usort($kidNodes, 'of_officer_cmp');
				$kidKeys = array_column($kidNodes, 'Key');
				$inner = of_officer_render_level($kidKeys, $nodes, $children, $seen, $depth + 1);
				if ($inner !== '') {
					$html .= '<ul class="of-children">' . $inner . '</ul>';
				}
			}

			$html .= '</li>';
		}
		return $html;
	}
}

if (!function_exists('of_officer_render_tree')) {
	/**
	 * Build the tree across the WHOLE set and render it.
	 *
	 * Deliberately NOT built per classification group: the admin console's
	 * original bug was building within a group, which made a supporting deputy
	 * reporting to a crown office render as a false root. One set, one tree;
	 * crown offices then naturally sit at the top because they carry the low
	 * sort orders and no parent.
	 */
	function of_officer_render_tree(array $rows)
	{
		$nodes = of_officer_build_nodes($rows);
		if (empty($nodes)) {
			return '';
		}

		// PositionId -> node key, for parent resolution.
		$byPosition = [];
		foreach ($nodes as $key => $node) {
			if ($node['PositionId'] > 0) {
				$byPosition[$node['PositionId']] = $key;
			}
		}

		$children = [];
		$rootNodes = [];
		foreach ($nodes as $key => $node) {
			$parentId = $node['ParentId'];
			$parentKey = ($parentId > 0 && isset($byPosition[$parentId])) ? $byPosition[$parentId] : null;
			// $parentKey === $key guards a self-parenting row, which would
			// otherwise be a one-node cycle.
			if ($parentKey !== null && $parentKey !== $key) {
				$children[$parentId][] = $key;
			} else {
				$rootNodes[] = $node;   // top-level, orphan, or self-parented
			}
		}

		usort($rootNodes, 'of_officer_cmp');
		$seen = [];
		$inner = of_officer_render_level(array_column($rootNodes, 'Key'), $nodes, $children, $seen, 0);

		// Safety sweep. Two data pathologies leave rows unrendered after the
		// first pass, and NO officer may silently vanish from a public list:
		//   1. a pure cycle (A reports to B, B reports to A) produces NO root
		//      at all, so the tree would come back empty;
		//   2. a subtree below the depth-12 cap is not drawn under its parent.
		// Anything still unseen is drawn at top level instead of dropped —
		// the same "orphan renders at root" rule, applied to the leftovers.
		$guard = 0;
		while (count($seen) < count($nodes) && $guard++ < 20) {
			$left = [];
			foreach ($nodes as $key => $node) {
				if (!isset($seen[$key])) {
					$left[] = $node;
				}
			}
			usort($left, 'of_officer_cmp');
			$more = of_officer_render_level(array_column($left, 'Key'), $nodes, $children, $seen, 0);
			if ($more === '') {
				break;
			}
			$inner .= $more;
		}

		return $inner === '' ? '' : '<ul class="of-tree">' . $inner . '</ul>';
	}
}

$ofTreeHtml = of_officer_render_tree($ofRows);

// Config handed to the JS below. JSON_HEX_* so nothing in a kingdom name can
// break out of the <script> element.
$ofJsConfig = json_encode([
	'scope'       => $ofScope,
	'hostPrefix'  => $ofHostPrefix,
	'historyUrl'  => $ofHistoryUrl,
	'playerUrl'   => UIR . 'Player/profile/',
	'canEdit'     => (bool)$ofCanEditHistory,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>

<style>
/* =============================================================================
   Officer Details Modal — self-contained.

   The `kn-modal-*` / `pn-modal-*` classes are NOT generic in this codebase:
   revised.css scopes every one of them to a specific overlay id (see the two
   long selector lists at revised.css:1806-1839 and :1840-1860). Reusing the
   class names without also editing revised.css would render an UNSTYLED box,
   and visibility is a class toggle rather than a `display` swap, so the modal
   would never appear at all.

   Rather than require an edit to a file this partial does not own, the chrome
   and the show/hide are reproduced here, scoped to #of-officers-overlay, in the
   exact shape of the house pattern. NOTHING needs adding to revised.css.
   ============================================================================= */

/* ---- Overlay + show/hide (house pattern: opacity + visibility, .of-open) ---- */
#of-officers-overlay {
	position: fixed;
	inset: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 20px;
	z-index: var(--z-modal, 10100);
	opacity: 0;
	pointer-events: none;
	visibility: hidden;
	transition: opacity 0.2s, visibility 0s 0.2s;
}
#of-officers-overlay.of-open {
	opacity: 1;
	pointer-events: auto;
	visibility: visible;
	transition: opacity 0.2s, visibility 0s 0s;
}

/* The Add / Edit officer-history modals live on the host page and carry an
   INLINE z-index of var(--z-modal) — the same layer as this modal — so they
   would open behind it. Inline styles beat a stylesheet rule, hence
   !important. This is the relationship .ka-overlay-top already uses
   (admin-console.css:255). These four ids only ever exist on the two pages
   that include this partial. */
#kn-oh-backfill-overlay,
#kn-oh-edit-overlay,
#pk-oh-backfill-overlay,
#pk-oh-edit-overlay {
	z-index: var(--z-modal-top, 10200) !important;
}

/* ---- Box / header / body ---- */
#of-officers-overlay .of-modal-box {
	background: var(--ork-card-bg);
	border-radius: 12px;
	box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
	width: 640px;
	max-width: 100%;
	max-height: 90vh;
	max-height: 90dvh;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
#of-officers-overlay .of-modal-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 16px 20px;
	border-bottom: 1px solid var(--ork-border);
	flex-shrink: 0;
	background: var(--ork-bg-secondary);
}
/* orkui.css gives every global h1-h6 a grey pill box. Reset it. */
#of-officers-overlay .of-modal-title {
	background: none;
	border: 0;
	border-radius: 0;
	padding: 0;
	margin: 0;
	font-size: 16px;
	font-weight: 700;
	color: var(--ork-text);
	line-height: 1.3;
	min-width: 0;
	overflow-wrap: anywhere;
}
#of-officers-overlay .of-modal-title-icon {
	margin-right: 8px;
	color: #d69e2e;
}
#of-officers-overlay .of-modal-close-btn {
	background: none;
	border: 1px solid transparent;
	border-radius: 6px;
	font-size: 22px;
	line-height: 1;
	color: var(--ork-text-muted);
	cursor: pointer;
	padding: 0 6px;
	flex-shrink: 0;
}
#of-officers-overlay .of-modal-close-btn:hover { color: var(--ork-text); }
#of-officers-overlay .of-modal-close-btn:focus-visible {
	outline: 2px solid var(--ork-blue-link);
	outline-offset: 1px;
	color: var(--ork-text);
}
#of-officers-overlay .of-modal-body {
	padding: 18px 20px 20px;
	overflow-y: auto;
	flex: 1;
	min-height: 0;
}

/* ---- Tabs (shape mirrors .pn-design-tabs / .pn-design-tab) ---- */
#of-officers-overlay .of-tabs {
	display: flex;
	gap: 0;
	padding: 0 20px;
	border-bottom: 2px solid var(--ork-border);
	background: var(--ork-bg-secondary);
	flex-shrink: 0;
	overflow-x: auto;
	scrollbar-width: none;
	-ms-overflow-style: none;
}
#of-officers-overlay .of-tabs::-webkit-scrollbar { display: none; }
#of-officers-overlay .of-tab {
	padding: 10px 16px;
	font-size: 13px;
	font-weight: 600;
	color: var(--ork-text-muted);
	background: none;
	border: none;
	border-bottom: 2px solid transparent;
	margin-bottom: -2px;
	cursor: pointer;
	white-space: nowrap;
}
#of-officers-overlay .of-tab i { margin-right: 6px; }
#of-officers-overlay .of-tab:hover { color: var(--ork-text); }
#of-officers-overlay .of-tab:focus-visible {
	outline: 2px solid var(--ork-blue-link);
	outline-offset: -2px;
	border-radius: 4px 4px 0 0;
}
#of-officers-overlay .of-tab.of-active {
	color: var(--ork-link);
	border-bottom-color: var(--ork-link);
}
#of-officers-overlay .of-panel { display: none; }
#of-officers-overlay .of-panel.of-active { display: block; }

/* ---- Current Officers tree ---- */
#of-officers-overlay .of-tree,
#of-officers-overlay .of-children {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}
/* Nesting is shown by INDENT with a rail — the only nesting signal this surface
   needs. Mirrors .mo-children (_manage_officers.tpl:334). */
#of-officers-overlay .of-children {
	margin: 6px 0 2px 10px;
	padding-left: 14px;
	border-left: 2px solid var(--ork-border);
}
#of-officers-overlay .of-node { margin: 0; }
#of-officers-overlay .of-row {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	padding: 7px 10px;
	border-radius: 6px;
	background: var(--ork-bg-secondary);
	border: 1px solid var(--ork-border);
}
#of-officers-overlay .of-role {
	font-size: 13px;
	font-weight: 600;
	color: var(--ork-text-secondary);
	min-width: 0;
	overflow-wrap: anywhere;
}
/* #d69e2e is the established crown gold (.mo-crown-glyph,
   _manage_officers.tpl:263). It reads at 4.0:1 on the dark card surface, so it
   is deliberately kept as-is in dark mode too — a decorative glyph beside a
   text label, not the label itself. */
#of-officers-overlay .of-crown-glyph {
	color: #d69e2e;
	margin-right: 5px;
	font-size: 12px;
}
#of-officers-overlay .of-holder {
	font-size: 13px;
	color: var(--ork-text);
	text-align: right;
	min-width: 0;
	overflow-wrap: anywhere;
}
#of-officers-overlay .of-holder a { color: var(--ork-link); text-decoration: none; }
#of-officers-overlay .of-holder a:hover { text-decoration: underline; }
#of-officers-overlay .of-holder a:focus-visible {
	outline: 2px solid var(--ork-blue-link);
	outline-offset: 2px;
	border-radius: 3px;
}
#of-officers-overlay .of-holder-sep { color: var(--ork-text-muted); }
/* Replaces the hardcoded <em style="color:#a0aec0">Vacant</em> the sidebars
   use — a class, with a dark counterpart, instead of an inline literal. */
#of-officers-overlay .of-vacant {
	/* --ork-text-secondary, not the #a0aec0 the old sidebars hardcode: that measures
	   2.26:1 on the light modal surface, and "Vacant" is real information about the
	   office, not decoration. The italic already marks it as secondary.
	   #4a5568 light = 7.53:1, #cbd5e0 dark = 8.07:1. */
	font-style: italic;
	color: var(--ork-text-secondary);
}

/* ---- Empty states ---- */
#of-officers-overlay .of-empty {
	text-align: center;
	padding: 30px 16px;
	color: var(--ork-text-muted);
	font-size: 13px;
	line-height: 1.5;
}
#of-officers-overlay .of-empty i {
	display: block;
	font-size: 22px;
	margin-bottom: 8px;
	opacity: 0.55;
}
#of-officers-overlay .of-loading {
	text-align: center;
	padding: 26px 16px;
	color: var(--ork-text-muted);
	font-size: 13px;
}

/* ---- Officer History tab ---- */
#of-officers-overlay .of-oh-toolbar {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 14px;
	flex-wrap: wrap;
}
#of-officers-overlay .of-oh-filter-select {
	padding: 6px 10px;
	border: 1px solid var(--ork-input-border);
	border-radius: 6px;
	font-size: 13px;
	color: var(--ork-text);
	background: var(--ork-input-bg);
	cursor: pointer;
	max-width: 100%;
}
#of-officers-overlay .of-oh-filter-select:focus-visible {
	outline: 2px solid var(--ork-blue-link);
	outline-offset: 1px;
}
#of-officers-overlay .of-oh-add-btn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	font-size: 13px;
	font-weight: 600;
	border-radius: 6px;
	border: 1px solid var(--ork-border);
	background: var(--ork-card-bg);
	color: var(--ork-text-secondary);
	cursor: pointer;
}
#of-officers-overlay .of-oh-add-btn:hover {
	background: var(--ork-bg-tertiary);
	color: var(--ork-text);
}
#of-officers-overlay .of-oh-add-btn:focus-visible {
	outline: 2px solid var(--ork-blue-link);
	outline-offset: 1px;
}
/* Wide content scrolls inside its OWN container; the page body never moves. */
#of-officers-overlay .of-table-wrap {
	overflow-x: auto;
	-webkit-overflow-scrolling: touch;
}
#of-officers-overlay .of-oh-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}
#of-officers-overlay .of-oh-table thead th {
	background: var(--ork-bg-secondary);
	border-bottom: 2px solid var(--ork-border);
	padding: 8px 10px;
	text-align: left;
	font-weight: 600;
	color: var(--ork-text-secondary);
	font-size: 12px;
	text-transform: uppercase;
	letter-spacing: .03em;
	white-space: nowrap;
}
#of-officers-overlay .of-oh-table tbody tr { border-bottom: 1px solid var(--ork-border); }
#of-officers-overlay .of-oh-table tbody tr:hover { background: var(--ork-bg-secondary); }
#of-officers-overlay .of-oh-table tbody td {
	padding: 8px 10px;
	color: var(--ork-text);
	vertical-align: middle;
}
#of-officers-overlay .of-oh-table td a { color: var(--ork-link); text-decoration: none; }
#of-officers-overlay .of-oh-table td a:hover { text-decoration: underline; }
#of-officers-overlay .of-oh-role-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 11px;
	font-weight: 600;
	background: var(--ork-badge-blue-bg);
	color: var(--ork-badge-blue-text);
	white-space: nowrap;
}
#of-officers-overlay .of-oh-role-badge.of-oh-current {
	background: var(--ork-badge-green-bg);
	color: var(--ork-badge-green-text);
}
#of-officers-overlay .of-oh-present { color: #276749; font-style: italic; }
html[data-theme="dark"] #of-officers-overlay .of-oh-present { color: #9ae6b4; }
#of-officers-overlay .of-oh-notes {
	font-size: 11px;
	color: var(--ork-text-muted);
	font-style: italic;
	display: inline-block;
	max-width: 220px;
	overflow-wrap: anywhere;
}
#of-officers-overlay .of-oh-nowrap { white-space: nowrap; }
#of-officers-overlay .of-oh-edit-btn,
#of-officers-overlay .of-oh-del-btn {
	background: none;
	border: 1px solid transparent;
	cursor: pointer;
	font-size: 14px;
	padding: 4px;
	border-radius: 4px;
	opacity: 0.6;
	transition: opacity 0.15s;
}
#of-officers-overlay .of-oh-edit-btn { color: var(--ork-blue-link); margin-right: 2px; }
#of-officers-overlay .of-oh-del-btn { color: #e53e3e; }
html[data-theme="dark"] #of-officers-overlay .of-oh-del-btn { color: #fc8181; }
#of-officers-overlay .of-oh-edit-btn:hover,
#of-officers-overlay .of-oh-del-btn:hover { opacity: 1; background: var(--ork-bg-tertiary); }
#of-officers-overlay .of-oh-edit-btn:focus-visible,
#of-officers-overlay .of-oh-del-btn:focus-visible {
	opacity: 1;
	outline: 2px solid var(--ork-blue-link);
	outline-offset: 1px;
}

/* ---- Screen-reader-only text ---- */
#of-officers-overlay .of-sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}

/* ---- Narrow screens ---- */
@media (max-width: 640px) {
	#of-officers-overlay { padding: 10px; }
	#of-officers-overlay .of-modal-box { max-height: 94vh; max-height: 94dvh; }
	#of-officers-overlay .of-modal-body { padding: 14px; }
	#of-officers-overlay .of-tabs { padding: 0 10px; }
	#of-officers-overlay .of-tab { padding: 10px 12px; }
	#of-officers-overlay .of-row { flex-direction: column; align-items: flex-start; gap: 2px; }
	#of-officers-overlay .of-holder { text-align: left; }
	#of-officers-overlay .of-children { margin-left: 4px; padding-left: 10px; }
	#of-officers-overlay .of-oh-notes { max-width: 160px; }
}

@media (prefers-reduced-motion: reduce) {
	#of-officers-overlay,
	#of-officers-overlay.of-open,
	#of-officers-overlay .of-oh-edit-btn,
	#of-officers-overlay .of-oh-del-btn {
		transition: none;
	}
}
</style>

<!-- =============================================
     Officer Details Modal
     ============================================= -->
<div id="of-officers-overlay" role="dialog" aria-modal="true" aria-labelledby="of-modal-title">
	<div class="of-modal-box">

		<div class="of-modal-header">
			<h3 class="of-modal-title" id="of-modal-title">
				<i class="fas fa-crown of-modal-title-icon" aria-hidden="true"></i><?= htmlspecialchars($ofOrgName !== '' ? $ofOrgName . ' Officers' : 'Officers') ?>
			</h3>
			<button type="button" class="of-modal-close-btn" id="of-modal-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="of-tabs" role="tablist" aria-label="Officer details">
			<button type="button" class="of-tab of-active" id="of-tabbtn-current" role="tab"
				aria-selected="true" aria-controls="of-panel-current" data-panel="current">
				<i class="fas fa-users" aria-hidden="true"></i>Current Officers
			</button>
			<button type="button" class="of-tab" id="of-tabbtn-history" role="tab"
				aria-selected="false" aria-controls="of-panel-history" data-panel="history">
				<i class="fas fa-history" aria-hidden="true"></i>Officer History
			</button>
		</div>

		<div class="of-modal-body">

			<!-- ── Tab 1: Current Officers ── -->
			<div class="of-panel of-active" id="of-panel-current" role="tabpanel" aria-labelledby="of-tabbtn-current" tabindex="0">
				<?php if ($ofTreeHtml !== ''): ?>
					<?= $ofTreeHtml ?>
				<?php else: ?>
					<div class="of-empty">
						<i class="fas fa-user-slash" aria-hidden="true"></i>
						No officers are on record for <?= htmlspecialchars($ofOrgName !== '' ? $ofOrgName : 'this group') ?>.
					</div>
				<?php endif; ?>
			</div>

			<!-- ── Tab 2: Officer History (lazy-loaded on first activation) ── -->
			<div class="of-panel" id="of-panel-history" role="tabpanel" aria-labelledby="of-tabbtn-history" tabindex="0">
				<div class="of-oh-toolbar">
					<label class="of-sr-only" for="of-oh-role-filter">Filter officer history by role</label>
					<select id="of-oh-role-filter" class="of-oh-filter-select">
						<option value="">All Roles</option>
						<?php foreach ($ofRoleOptions as $_ofRoleKey => $_ofRoleLabel): ?>
						<option value="<?= htmlspecialchars((string)$_ofRoleKey) ?>"><?= htmlspecialchars((string)$_ofRoleLabel) ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ($ofCanEditHistory): ?>
					<button type="button" class="of-oh-add-btn" id="of-oh-add-btn"
						data-tip="Record an officer term that predates the ORK">
						<i class="fas fa-plus" aria-hidden="true"></i> Add Historical Record
					</button>
					<?php endif; ?>
				</div>

				<div class="of-loading" id="of-oh-loading" style="display:none" role="status">
					<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Loading officer history&hellip;
				</div>

				<div class="of-empty" id="of-oh-empty" style="display:none" role="status"></div>

				<div class="of-table-wrap" id="of-oh-table-wrap" style="display:none">
					<table class="of-oh-table" id="of-oh-table">
						<thead>
							<tr>
								<th scope="col">Role</th>
								<th scope="col">Persona</th>
								<th scope="col">Start Date</th>
								<th scope="col">End Date</th>
								<th scope="col">Notes</th>
								<?php if ($ofCanEditHistory): ?>
								<th scope="col"><span class="of-sr-only">Actions</span></th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody id="of-oh-tbody"></tbody>
					</table>
				</div>
			</div>

		</div>
	</div>
</div>

<script>
/* =============================================================================
   Officer Details Modal — behaviour
   ============================================================================= */
(function () {
	var OF = <?= $ofJsConfig ?>;

	function gid(id) { return document.getElementById(id); }

	var overlay = gid('of-officers-overlay');
	if (!overlay) { return; }

	var lastFocus  = null;
	var historyLoaded = false;

	// ---------------------------------------------------------------------
	// Open / close
	// ---------------------------------------------------------------------
	function openModal() {
		lastFocus = document.activeElement;
		overlay.classList.add('of-open');
		document.body.style.overflow = 'hidden';
		var closeBtn = gid('of-modal-close-btn');
		if (closeBtn) { closeBtn.focus(); }
	}

	function closeModal() {
		overlay.classList.remove('of-open');
		document.body.style.overflow = '';
		// Focus is returned, not trapped: the user can always tab away.
		if (lastFocus && typeof lastFocus.focus === 'function' && document.contains(lastFocus)) {
			lastFocus.focus();
		}
		lastFocus = null;
	}

	function isOpen() { return overlay.classList.contains('of-open'); }

	window.ofOpenOfficerModal  = openModal;
	window.ofCloseOfficerModal = closeModal;

	var closeBtn = gid('of-modal-close-btn');
	if (closeBtn) { closeBtn.addEventListener('click', closeModal); }

	// Overlay click closes — but only when the press STARTED on the overlay,
	// so drag-selecting text inside the box and releasing outside does not.
	var pressOnOverlay = false;
	overlay.addEventListener('mousedown', function (e) { pressOnOverlay = (e.target === overlay); });
	overlay.addEventListener('click', function (e) {
		if (pressOnOverlay && e.target === overlay) { closeModal(); }
		pressOnOverlay = false;
	});

	document.addEventListener('keydown', function (e) {
		if ((e.key === 'Escape' || e.keyCode === 27) && isOpen()) {
			// Let the stacked Add/Edit history modals handle their own Escape
			// first — they sit at --z-modal-top above this one.
			var top = document.getElementById(OF.hostPrefix + '-oh-backfill-overlay');
			var edt = document.getElementById(OF.hostPrefix + '-oh-edit-overlay');
			var stackedOpen = (top && top.style.display && top.style.display !== 'none')
				|| (edt && edt.style.display && edt.style.display !== 'none');
			if (!stackedOpen) { closeModal(); }
		}
	});

	// ---------------------------------------------------------------------
	// Tabs
	// ---------------------------------------------------------------------
	function switchPanel(name) {
		var tabs = overlay.querySelectorAll('.of-tab');
		for (var i = 0; i < tabs.length; i++) {
			var on = tabs[i].getAttribute('data-panel') === name;
			tabs[i].classList.toggle('of-active', on);
			tabs[i].setAttribute('aria-selected', on ? 'true' : 'false');
		}
		var panels = overlay.querySelectorAll('.of-panel');
		for (var j = 0; j < panels.length; j++) {
			panels[j].classList.toggle('of-active', panels[j].id === 'of-panel-' + name);
		}
		// Lazy-load on FIRST activation of the history tab, not on modal open.
		if (name === 'history' && !historyLoaded) {
			historyLoaded = true;
			loadHistory();
		}
	}

	var tabButtons = overlay.querySelectorAll('.of-tab');
	for (var t = 0; t < tabButtons.length; t++) {
		(function (btn) {
			btn.addEventListener('click', function () { switchPanel(btn.getAttribute('data-panel')); });
		})(tabButtons[t]);
	}

	// ---------------------------------------------------------------------
	// Officer History
	// ---------------------------------------------------------------------
	var ohRows = [];

	function showOnly(which, message) {
		var loading = gid('of-oh-loading');
		var empty   = gid('of-oh-empty');
		var wrap    = gid('of-oh-table-wrap');
		if (loading) { loading.style.display = (which === 'loading') ? '' : 'none'; }
		if (wrap)    { wrap.style.display    = (which === 'table')   ? '' : 'none'; }
		if (empty) {
			empty.style.display = (which === 'empty') ? '' : 'none';
			if (which === 'empty') {
				empty.textContent = '';
				var icon = document.createElement('i');
				icon.className = 'fas fa-scroll';
				icon.setAttribute('aria-hidden', 'true');
				empty.appendChild(icon);
				empty.appendChild(document.createTextNode(message || ''));
			}
		}
	}

	function loadHistory() {
		var sel  = gid('of-oh-role-filter');
		var role = sel ? sel.value : '';
		// "&Role=", NOT "?Role=". UIR is ".../index.php?Route=", so the query
		// string has already begun; a second "?" would be swallowed into the
		// VALUE of Route, leaving $_GET['Role'] unset and $action equal to
		// "officerhistory?Role=x", which matches no branch in the controller.
		// (The host pages' knLoadOfficerHistory / pkLoadOfficerHistory still
		// build "?Role=" — see the report; that filter is broken today.)
		var url  = OF.historyUrl + (role ? ('&Role=' + encodeURIComponent(role)) : '');

		showOnly('loading');

		fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
			.then(function (r) {
				if (!r.ok) { throw new Error('HTTP ' + r.status); }
				return r.json();
			})
			.then(function (resp) {
				// Both endpoints answer {"status":5} to a signed-out visitor.
				// This modal is on a public profile, so that is an expected
				// state, not a failure.
				if (resp && resp.status === 5) {
					showOnly('empty', 'Sign in to view officer history.');
					return;
				}
				if (!resp || resp.status !== 0) {
					showOnly('empty', 'Officer history could not be loaded.');
					return;
				}
				ohRows = resp.history || [];
				// The host page's Add/Edit handlers index into <prefix>OhData.
				// Keep it in step so the stacked Edit modal opens the right row.
				window[OF.hostPrefix + 'OhData'] = ohRows;
				renderHistory(ohRows);
			})
			.catch(function () {
				showOnly('empty', 'Officer history could not be loaded.');
			});
	}

	function fmtDate(s) {
		if (!s) { return ''; }
		// Guard the zero date and anything non-ISO rather than printing
		// "Dec 31, 1969".
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s));
		if (!m || m[1] === '0000') { return ''; }
		var d = new Date(m[1] + '-' + m[2] + '-' + m[3] + 'T00:00:00');
		if (isNaN(d.getTime())) { return ''; }
		var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
		return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
	}

	function td(child) {
		var cell = document.createElement('td');
		if (child !== null && child !== undefined) {
			cell.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		}
		return cell;
	}

	// Rows are built with createElement + textContent throughout. Persona,
	// Role and Notes are user-supplied free text; nothing here goes near
	// innerHTML, so there is no escaping to get wrong.
	function renderHistory(rows) {
		var tbody = gid('of-oh-tbody');
		if (!tbody) { return; }
		tbody.textContent = '';

		if (!rows || rows.length === 0) {
			// ork_officer_history is near-empty across the whole database, so
			// "no records" is the NORMAL state here, not an error. Say so in a
			// way that reads as finished rather than broken.
			showOnly('empty', ' No officer terms have been recorded here yet. Past officers appear once their terms are entered.');
			return;
		}

		showOnly('table');

		for (var i = 0; i < rows.length; i++) {
			var h  = rows[i];
			var tr = document.createElement('tr');
			var isCurrent = !h.EndDate || String(h.EndDate).indexOf('0000') === 0;

			var badge = document.createElement('span');
			badge.className = 'of-oh-role-badge' + (isCurrent ? ' of-oh-current' : '');
			badge.textContent = (h.Role || '') + (isCurrent ? ' (current)' : '');
			tr.appendChild(td(badge));

			var personaCell;
			if (parseInt(h.MundaneId, 10) > 0) {
				var a = document.createElement('a');
				a.href = OF.playerUrl + parseInt(h.MundaneId, 10);
				a.textContent = h.Persona || 'Unknown';
				personaCell = td(a);
			} else {
				var vac = document.createElement('span');
				vac.className = 'of-vacant';
				vac.textContent = 'Vacant';
				personaCell = td(vac);
			}
			tr.appendChild(personaCell);

			var startCell = td(fmtDate(h.StartDate));
			startCell.className = 'of-oh-nowrap';
			tr.appendChild(startCell);

			var endCell;
			if (isCurrent) {
				var pres = document.createElement('span');
				pres.className = 'of-oh-present';
				pres.textContent = 'Present';
				endCell = td(pres);
			} else {
				endCell = td(fmtDate(h.EndDate));
			}
			endCell.className = 'of-oh-nowrap';
			tr.appendChild(endCell);

			var notesCell = document.createElement('td');
			if (h.Notes) {
				var n = document.createElement('span');
				n.className = 'of-oh-notes';
				n.textContent = h.Notes;
				notesCell.appendChild(n);
			}
			tr.appendChild(notesCell);

			if (OF.canEdit) {
				var actions = document.createElement('td');
				actions.className = 'of-oh-nowrap';
				actions.appendChild(actionBtn('edit', i, h));
				actions.appendChild(actionBtn('delete', i, h));
				tr.appendChild(actions);
			}

			tbody.appendChild(tr);
		}
	}

	// The Add / Edit / Delete history modals stay on the host page — this modal
	// re-homes the VIEW, not the editors. Call through to the host's functions
	// by name, and no-op safely if the host does not expose them.
	function hostCall(name, arg) {
		var fn = window[OF.hostPrefix + name];
		if (typeof fn === 'function') { fn(arg); }
	}

	function actionBtn(kind, index, row) {
		var b = document.createElement('button');
		b.type = 'button';
		var icon = document.createElement('i');
		icon.setAttribute('aria-hidden', 'true');
		if (kind === 'edit') {
			b.className = 'of-oh-edit-btn';
			b.setAttribute('data-tip', 'Edit this record');
			b.setAttribute('aria-label', 'Edit officer history record');
			icon.className = 'fas fa-pencil-alt';
			b.addEventListener('click', function () {
				window[OF.hostPrefix + 'OhData'] = ohRows;
				hostCall('OpenOhEditModal', index);
			});
		} else {
			b.className = 'of-oh-del-btn';
			b.setAttribute('data-tip', 'Delete this record');
			b.setAttribute('aria-label', 'Delete officer history record');
			icon.className = 'fas fa-trash-alt';
			b.addEventListener('click', function () {
				hostCall('DeleteOhRecord', parseInt(row.OfficerHistoryId, 10));
			});
		}
		b.appendChild(icon);
		return b;
	}

	var roleFilter = gid('of-oh-role-filter');
	if (roleFilter) { roleFilter.addEventListener('change', loadHistory); }

	var addBtn = gid('of-oh-add-btn');
	if (addBtn) {
		addBtn.addEventListener('click', function () { hostCall('OpenOhBackfillModal'); });
	}

	// The host page's add / edit / delete handlers each finish by calling
	// <prefix>LoadOfficerHistory() to refresh. Once the old main-content panel
	// is removed that function's elements are gone and it throws. Re-point it
	// at this modal's table — but only when the old panel really is gone, so
	// that mid-migration (panel still present) the host keeps its own version.
	document.addEventListener('DOMContentLoaded', function () {
		if (document.getElementById(OF.hostPrefix + '-tab-officerhistory')) { return; }
		window[OF.hostPrefix + 'LoadOfficerHistory'] = function () {
			historyLoaded = true;
			loadHistory();
		};
	});
})();
</script>
