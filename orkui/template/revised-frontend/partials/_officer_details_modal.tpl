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
		// NO UserName fallback. buildOfficerRows emits GivenName/Surname gated behind
		// $is_authorized but UserName UNGATED, so falling back to it would print an
		// officer's ORK login name to signed-out visitors on a public profile whenever
		// their persona is blank. The sidebar this modal extends only ever rendered
		// Persona. GivenName/Surname are safe to keep in the chain precisely because
		// the domain already blanks them for an unauthorised viewer.
		$real = trim(trim((string)($row['GivenName'] ?? '')) . ' ' . trim((string)($row['Surname'] ?? '')));
		return $real !== '' ? $real : 'Name not published';
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

				// Lower-cased canonical key. The officer-HISTORY grouping falls back to
				// it whenever a row carries no usable PositionId (legacy officer rows,
				// and history rows written before the registry existed), so it is
				// captured here rather than re-derived from $ofRows a second time.
				$canonicalKey = strtolower(trim((string)($row['CanonicalKey'] ?? '')));
				if ($canonicalKey === '') {
					$canonicalKey = strtolower(trim((string)($row['Role'] ?? '')));
				}

				$nodes[$key] = [
					'Key'          => $key,
					'PositionId'   => $positionId,
					'CanonicalKey' => $canonicalKey,
					'ParentId'     => $parentId > 0 ? $parentId : 0,
					'IsCrown'      => ((string)($row['Classification'] ?? '')) === 'crown',
					'Title'        => $title,
					'SortOrder'    => $sortOrder,
					'Seq'          => $seq,
					'Holders'      => [],
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

// ---------------------------------------------------------------------------
// Officer History panels
// ---------------------------------------------------------------------------
// The history tab is ONE COLLAPSIBLE PANEL PER OFFICE, and the panel list is
// driven by the CURRENT offices, not by the history rows: every office earns a
// panel even with zero recorded terms, because the header itself carries
// information (who holds it right now). The shells are therefore rendered
// server-side from the very same nodes the Current Officers tree is built from,
// so the tab paints with current holders instantly; the existing lazy fetch then
// only has to fill the bodies.
//
// A history row that matches no current office -- a retired position, or a
// legacy row whose position_id is 0 -- is NOT dropped. The JS appends a panel
// for it at the bottom of the list, labelled from the row's own DisplayLabel
// snapshot. See renderHistory() below.
if (!function_exists('of_history_group_key')) {
	/**
	 * The grouping key a history row and a current office have to agree on:
	 * PositionId when it is real, else the (lower-cased) role/canonical key.
	 *
	 * $suffix keeps two DISTINCT legacy offices that both have an empty role
	 * string from collapsing into one panel titled after whichever came first.
	 */
	function of_history_group_key($positionId, $role, $suffix = '')
	{
		$positionId = (int)$positionId;
		if ($positionId > 0) {
			return 'p' . $positionId;
		}
		$role = strtolower(trim((string)$role));
		return $role !== '' ? ('r:' . $role) : ('n:' . $suffix);
	}
}

if (!function_exists('of_officer_history_panels')) {
	/**
	 * Flatten the office NODES into the ordered panel list.
	 *
	 * Order: crown offices pinned to the top, then supporting. Within each group
	 * the comparator is of_officer_cmp() -- the SAME sort the Current Officers
	 * tree orders siblings with, not a second one invented here, so the two tabs
	 * can never disagree about where an office sits.
	 *
	 * The list is FLAT rather than nested: a panel is a disclosure, and nesting
	 * disclosures inside disclosures would bury a deputy's history two clicks
	 * deep. A supporting deputy of a crown office therefore appears in the
	 * supporting group, not under its parent.
	 *
	 * @param array $nodes from of_officer_build_nodes()
	 * @return array ordered list of panel descriptors
	 */
	function of_officer_history_panels(array $nodes)
	{
		$panels = [];
		foreach ($nodes as $node) {
			$group = of_history_group_key($node['PositionId'], $node['CanonicalKey'], $node['Key']);

			// Two legacy officer ROWS sharing one role string are one office held
			// by two people, not two offices. Merge the holders instead of
			// emitting a duplicate panel.
			if (isset($panels[$group])) {
				foreach ($node['Holders'] as $holder) {
					$panels[$group]['Holders'][] = $holder;
				}
				continue;
			}

			$panels[$group] = [
				'Group'     => $group,
				'Role'      => $node['CanonicalKey'],
				'Title'     => $node['Title'],
				'IsCrown'   => $node['IsCrown'],
				'SortOrder' => $node['SortOrder'],
				'Seq'       => $node['Seq'],
				'Holders'   => $node['Holders'],
			];
		}

		$crown = [];
		$supporting = [];
		foreach ($panels as $panel) {
			if ($panel['IsCrown']) {
				$crown[] = $panel;
			} else {
				$supporting[] = $panel;
			}
		}
		usort($crown, 'of_officer_cmp');
		usort($supporting, 'of_officer_cmp');
		return array_merge($crown, $supporting);
	}
}

$ofPanels = of_officer_history_panels(of_officer_build_nodes($ofRows));

// Registry labels, keyed by LOWER-CASED canonical key, for the JS to name a
// history-only panel with when the row carries no DisplayLabel snapshot.
// history_role_options() sources these from the position registry INCLUDING
// retired positions, which is exactly the set a history-only panel comes from.
$ofRoleLabels = [];
foreach ($ofRoleOptions as $_ofRoleKey => $_ofRoleLabel) {
	$ofRoleLabels[strtolower(trim((string)$_ofRoleKey))] = (string)$_ofRoleLabel;
}

// Config handed to the JS below. JSON_HEX_* so nothing in a kingdom name can
// break out of the <script> element.
$ofJsConfig = json_encode([
	'scope'       => $ofScope,
	'hostPrefix'  => $ofHostPrefix,
	'historyUrl'  => $ofHistoryUrl,
	'playerUrl'   => UIR . 'Player/profile/',
	'canEdit'     => (bool)$ofCanEditHistory,
	'roleLabels'  => $ofRoleLabels,
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
/* .of-modal-body scrolls, and data-tip renders ABOVE its element by default, so a tip on
   the first row (the Add button, the first table row's edit/delete) paints outside the
   scroll container and is clipped. Anchor tips inside the body below their element -- the
   same remedy revised.css already applies elsewhere for this. */
#of-officers-overlay .of-modal-body [data-tip]::after {
	bottom: auto;
	top: calc(100% + 6px);
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
/* ---- Officer History: one collapsible panel per office ----
   Real <details>/<summary> disclosures, NOT a div with a click handler: that
   buys keyboard operation (Tab to the summary, Enter/Space to toggle) and the
   correct expanded/collapsed semantics for free, with no aria bookkeeping to
   drift out of step. */
#of-officers-overlay .of-oh-panels {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
#of-officers-overlay .of-oh-panel {
	border: 1px solid var(--ork-border);
	border-radius: 8px;
	background: var(--ork-card-bg);
	overflow: hidden;
}
/* The filter hides non-matching panels with [hidden]; the rule above sets no
   display, but a UA stylesheet gives <details> display:block, so state it. */
#of-officers-overlay .of-oh-panel[hidden] { display: none; }
#of-officers-overlay .of-oh-summary {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 4px 8px;
	padding: 9px 12px;
	font-size: 13px;
	cursor: pointer;
	background: var(--ork-bg-secondary);
	/* Both are needed: ::marker is the standard, ::-webkit-details-marker is
	   what Safari still uses. list-style:none alone leaves Safari's triangle. */
	list-style: none;
}
#of-officers-overlay .of-oh-summary::-webkit-details-marker { display: none; }
#of-officers-overlay .of-oh-summary::marker { content: ''; }
#of-officers-overlay .of-oh-summary:hover { background: var(--ork-bg-tertiary); }
#of-officers-overlay .of-oh-summary:focus-visible {
	outline: 2px solid var(--ork-blue-link);
	outline-offset: -2px;
}
#of-officers-overlay .of-oh-chev {
	flex-shrink: 0;
	width: 11px;
	font-size: 11px;
	color: var(--ork-text-muted);
	transition: transform 0.15s ease;
}
#of-officers-overlay .of-oh-panel[open] > .of-oh-summary .of-oh-chev { transform: rotate(90deg); }
#of-officers-overlay .of-oh-office {
	font-weight: 600;
	color: var(--ork-text-secondary);
	min-width: 0;
	overflow-wrap: anywhere;
}
#of-officers-overlay .of-oh-holder {
	flex: 1 1 auto;
	min-width: 0;
	text-align: right;
	color: var(--ork-text);
	overflow-wrap: anywhere;
}
#of-officers-overlay .of-oh-count {
	flex-shrink: 0;
	font-size: 11px;
	font-weight: 600;
	white-space: nowrap;
	color: var(--ork-text-muted);
	background: var(--ork-card-bg);
	border: 1px solid var(--ork-border);
	border-radius: 999px;
	padding: 1px 8px;
}
#of-officers-overlay .of-oh-body {
	padding: 10px 12px 12px;
	border-top: 1px solid var(--ork-border);
}
#of-officers-overlay .of-oh-none {
	font-size: 12px;
	font-style: italic;
	color: var(--ork-text-muted);
	line-height: 1.5;
}
/* Compact status line ABOVE the panel list, for the states where the panels are
   still worth showing (signed out, fetch failed, nothing recorded yet) and the
   big centred .of-empty would push them off the first screen. */
#of-officers-overlay .of-oh-note {
	font-size: 12px;
	line-height: 1.5;
	color: var(--ork-text-muted);
	background: var(--ork-bg-secondary);
	border: 1px solid var(--ork-border);
	border-radius: 6px;
	padding: 8px 12px;
	margin-bottom: 10px;
}
#of-officers-overlay .of-oh-note i { margin-right: 6px; opacity: 0.7; }

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
/* No .of-oh-role-badge: the Role column is gone, so the badge that carried the
   office name and its "(current)" suffix has nothing left to say inside a
   per-office panel. "Present" in the End Date column is now the only marker of
   a running term. */
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
	/* Office + count stay on line one, the holder drops to its own line under
	   them rather than being squeezed to a two-character column. */
	#of-officers-overlay .of-oh-office { flex: 1 1 auto; }
	#of-officers-overlay .of-oh-count  { order: 2; }
	#of-officers-overlay .of-oh-holder {
		order: 3;
		flex: 0 0 100%;
		text-align: left;
		padding-left: 19px;
	}
	#of-officers-overlay .of-oh-body { padding: 10px; }
}

@media (prefers-reduced-motion: reduce) {
	#of-officers-overlay,
	#of-officers-overlay.of-open,
	#of-officers-overlay .of-oh-chev,
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

				<div class="of-oh-note" id="of-oh-note" style="display:none" role="status"></div>

				<div class="of-empty" id="of-oh-empty" style="display:none" role="status"></div>

				<!-- Panel shells, one per CURRENT office, rendered server-side so the
				     tab paints with current holders before the history fetch returns.
				     Panels for offices that exist only in history are appended at the
				     bottom of this container by renderHistory(). -->
				<div class="of-oh-panels" id="of-oh-panels">
					<?php foreach ($ofPanels as $_ofPanel): ?>
					<details class="of-oh-panel"
						data-group="<?= htmlspecialchars($_ofPanel['Group']) ?>"
						data-role="<?= htmlspecialchars($_ofPanel['Role']) ?>"
						data-title="<?= htmlspecialchars(strtolower($_ofPanel['Title'])) ?>">
						<summary class="of-oh-summary">
							<i class="fas fa-chevron-right of-oh-chev" aria-hidden="true"></i>
							<span class="of-oh-office"><?php if ($_ofPanel['IsCrown']): ?><i class="fas fa-crown of-crown-glyph" aria-hidden="true"></i><span class="of-sr-only">Crown office: </span><?php endif; ?><?= htmlspecialchars($_ofPanel['Title']) ?></span>
							<span class="of-oh-holder"><?php
								/* EVERY holder is named -- a multi-occupant supporting
								   office must not silently lose a name in the one place a
								   member goes to read who holds it.
								   Separator is " · ", NOT the ", " the Current Officers
								   tab uses: there the names are anchors, so the comma is
								   unambiguous, but here they are plain text and personas
								   in this database really do contain commas (mundane
								   105622 is "Hank the Tank,  Firebug of Crystal Groves",
								   one person), which would read as two holders.
								   Plain text, not links: an anchor nested inside a
								   <summary> is a second interactive control competing
								   with the disclosure for the same click and the same
								   Enter key. The Current Officers tab is where the links
								   live. */
								if (!empty($_ofPanel['Holders'])) {
									$_ofNames = [];
									foreach ($_ofPanel['Holders'] as $_ofHolder) {
										$_ofNames[] = htmlspecialchars($_ofHolder['Name']);
									}
									echo implode('<span class="of-holder-sep"> &middot; </span>', $_ofNames);
								} else {
									echo '<span class="of-vacant">Vacant</span>';
								}
							?></span>
							<span class="of-oh-count"></span>
						</summary>
						<div class="of-oh-body">
							<div class="of-oh-none">Loading&hellip;</div>
						</div>
					</details>
					<?php endforeach; ?>
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

	var prevBodyOverflow = '';
	var historyLoaded = false;

	// ---------------------------------------------------------------------
	// Open / close
	// ---------------------------------------------------------------------
	function openModal() {
		lastFocus = document.activeElement;
		// Reset to Current Officers. The docblock promises it, and the arrow that opens
		// this modal points at the officer tree -- without the reset, a viewer who last
		// looked at History reopens onto a stale history table instead.
		switchPanel('current');
		overlay.classList.add('of-open');
		prevBodyOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		var closeBtn = gid('of-modal-close-btn');
		if (closeBtn) { closeBtn.focus(); }
	}

	function closeModal() {
		overlay.classList.remove('of-open');
		// Restore the PREVIOUS value, not ''. A nested dialog opened from inside this
		// modal (the history delete confirm) blanks body.overflow on its own close, so
		// hard-coding '' here compounds that; keeping the prior value means this modal
		// only ever undoes its own lock.
		document.body.style.overflow = prevBodyOverflow || '';
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

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined && text !== null) { n.textContent = text; }
		return n;
	}

	function icon(cls) {
		var i = document.createElement('i');
		i.className = cls;
		i.setAttribute('aria-hidden', 'true');
		return i;
	}

	function panelsBox() { return gid('of-oh-panels'); }

	function allPanels() {
		var box = panelsBox();
		return box ? box.querySelectorAll('.of-oh-panel') : [];
	}

	function setLoading(on) {
		var l = gid('of-oh-loading');
		if (l) { l.style.display = on ? '' : 'none'; }
	}

	// Compact line above the panels, for states where the panel HEADERS are
	// still worth reading (signed out, fetch failed, nothing recorded yet).
	function showNote(message) {
		var note = gid('of-oh-note');
		if (!note) { return; }
		note.textContent = '';
		if (!message) { note.style.display = 'none'; return; }
		note.appendChild(icon('fas fa-info-circle'));
		note.appendChild(document.createTextNode(' ' + message));
		note.style.display = '';
	}

	// Full-height empty state, for when there is nothing to show at all.
	function showEmpty(message) {
		var empty = gid('of-oh-empty');
		if (!empty) { return; }
		empty.textContent = '';
		if (!message) { empty.style.display = 'none'; return; }
		empty.appendChild(icon('fas fa-scroll'));
		empty.appendChild(document.createTextNode(' ' + message));
		empty.style.display = '';
	}

	// Blank every panel body/count and drop the panels a previous render
	// invented. Used both to reset before a fetch and to degrade on failure.
	function resetPanels(bodyText) {
		var box = panelsBox();
		if (!box) { return; }
		var stale = box.querySelectorAll('.of-oh-dynamic');
		for (var s = 0; s < stale.length; s++) { stale[s].parentNode.removeChild(stale[s]); }
		clearFilterOptions();
		var panels = box.querySelectorAll('.of-oh-panel');
		for (var i = 0; i < panels.length; i++) {
			var count = panels[i].querySelector('.of-oh-count');
			var body  = panels[i].querySelector('.of-oh-body');
			if (count) { count.textContent = ''; }
			if (body) {
				body.textContent = '';
				body.appendChild(el('div', 'of-oh-none', bodyText || ''));
			}
		}
	}

	function degrade(message) {
		resetPanels(message);
		showEmpty(allPanels().length === 0 ? message : '');
		showNote(allPanels().length === 0 ? '' : message);
		applyFilter();
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

		setLoading(true);
		showNote('');
		showEmpty('');
		resetPanels('Loading…');

		fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
			.then(function (r) {
				if (!r.ok) { throw new Error('HTTP ' + r.status); }
				return r.json();
			})
			.then(function (resp) {
				setLoading(false);
				// Both endpoints answer {"status":5} to a signed-out visitor.
				// This modal is on a public profile, so that is an expected
				// state, not a failure. The panel HEADERS stay — current
				// holders are public and were rendered server-side.
				if (resp && resp.status === 5) {
					degrade('Sign in to view officer history.');
					return;
				}
				if (!resp || resp.status !== 0) {
					degrade('Officer history could not be loaded.');
					return;
				}
				ohRows = resp.history || [];
				// The host page's Add/Edit handlers index into <prefix>OhData.
				// Keep it in step so the stacked Edit modal opens the right row.
				window[OF.hostPrefix + 'OhData'] = ohRows;
				renderHistory(ohRows);
			})
			.catch(function () {
				setLoading(false);
				degrade('Officer history could not be loaded.');
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

	// -----------------------------------------------------------------
	// Grouping history rows onto office panels
	// -----------------------------------------------------------------
	// Classification is 'crown' | 'supporting' | null, and null means UNKNOWN
	// (retired position, or a row that predates the registry) -- NOT supporting.
	// It therefore sorts AFTER supporting, never with crown.
	function classRank(classification) {
		var c = String(classification || '').toLowerCase();
		if (c === 'crown') { return 0; }
		if (c === 'supporting') { return 1; }
		return 2;
	}

	// What to CALL an office that exists only in history. The snapshot of what
	// it was called at the time wins: history should say what the office was
	// called then, not what the registry renamed it to since.
	function labelFor(h) {
		var snapshot = String((h && h.DisplayLabel) || '').trim();
		if (snapshot !== '') { return snapshot; }
		var role = String((h && h.Role) || '').trim();
		// The role filter's options come from the position registry INCLUDING
		// retired positions, so a retired office usually still has a real name
		// available here.
		var registry = (OF.roleLabels && OF.roleLabels[role.toLowerCase()]) || '';
		if (registry !== '') { return String(registry); }
		if (role === '') { return 'Unrecorded office'; }
		// Last resort only. "royal_scribe" is a database identifier, not an
		// office name, and must never reach a member's screen as-is.
		return role.replace(/_/g, ' ').replace(/\b[a-z]/g, function (c) { return c.toUpperCase(); });
	}

	// Newest term first: start date descending, then id descending so two terms
	// that start on the same day still have a stable order.
	function cmpTerm(a, b) {
		var sa = String((a && a.StartDate) || '');
		var sb = String((b && b.StartDate) || '');
		if (sa !== sb) { return sa < sb ? 1 : -1; }
		return (parseInt(b && b.OfficerHistoryId, 10) || 0) - (parseInt(a && a.OfficerHistoryId, 10) || 0);
	}

	// The role filter is server-rendered from the position REGISTRY, so an office
	// that exists only in history (a legacy row whose position_id is 0) has no
	// option and would be reachable only via "All Roles". Add one, so the filter
	// can navigate to every panel it can see. Marked, and cleared on each render,
	// so repeated fetches cannot pile duplicates up.
	function addFilterOption(spec) {
		var sel = gid('of-oh-role-filter');
		if (!sel || !spec.role) { return; }
		for (var i = 0; i < sel.options.length; i++) {
			if (String(sel.options[i].value).toLowerCase() === spec.role) { return; }
		}
		var opt = document.createElement('option');
		opt.value = spec.role;
		opt.textContent = spec.label;
		opt.setAttribute('data-of-dynamic', '1');
		sel.appendChild(opt);
	}

	function clearFilterOptions() {
		var sel = gid('of-oh-role-filter');
		if (!sel) { return; }
		var added = sel.querySelectorAll('option[data-of-dynamic]');
		for (var i = 0; i < added.length; i++) {
			// Never remove the option the viewer is currently filtered by --
			// that would silently reset the control to "All Roles".
			if (!added[i].selected) { added[i].parentNode.removeChild(added[i]); }
		}
	}

	function makeDynamicPanel(spec) {
		var d = document.createElement('details');
		d.className = 'of-oh-panel of-oh-dynamic';
		d.setAttribute('data-group', spec.key);
		d.setAttribute('data-role', spec.role);
		d.setAttribute('data-title', spec.label.toLowerCase());

		var s = el('summary', 'of-oh-summary');
		s.appendChild(icon('fas fa-chevron-right of-oh-chev'));

		var office = el('span', 'of-oh-office');
		if (spec.rank === 0) {
			office.appendChild(icon('fas fa-crown of-crown-glyph'));
			office.appendChild(el('span', 'of-sr-only', 'Crown office: '));
		}
		office.appendChild(document.createTextNode(spec.label));
		s.appendChild(office);

		// Deliberately NOT "Vacant": the office is not part of this group's
		// current roster at all, which is a different statement.
		var holder = el('span', 'of-oh-holder');
		holder.appendChild(el('span', 'of-vacant', 'Not a current office'));
		s.appendChild(holder);

		s.appendChild(el('span', 'of-oh-count'));
		d.appendChild(s);
		d.appendChild(el('div', 'of-oh-body'));
		return d;
	}

	// Rows are built with createElement + textContent throughout. Persona and
	// Notes are user-supplied free text; nothing here goes near innerHTML, so
	// there is no escaping to get wrong.
	function buildTermTable(indices, rows) {
		var wrap  = el('div', 'of-table-wrap');
		var table = el('table', 'of-oh-table');
		var thead = document.createElement('thead');
		var htr   = document.createElement('tr');
		// No Role column: inside a per-office panel it repeats the header.
		var cols  = ['Persona', 'Start Date', 'End Date', 'Notes'];
		for (var c = 0; c < cols.length; c++) {
			var th = el('th', null, cols[c]);
			th.setAttribute('scope', 'col');
			htr.appendChild(th);
		}
		if (OF.canEdit) {
			var actTh = document.createElement('th');
			actTh.setAttribute('scope', 'col');
			actTh.appendChild(el('span', 'of-sr-only', 'Actions'));
			htr.appendChild(actTh);
		}
		thead.appendChild(htr);
		table.appendChild(thead);

		var tbody = document.createElement('tbody');
		for (var i = 0; i < indices.length; i++) {
			var idx = indices[i];
			var h   = rows[idx];
			var tr  = document.createElement('tr');
			var isCurrent = !h.EndDate || String(h.EndDate).indexOf('0000') === 0;

			if (parseInt(h.MundaneId, 10) > 0) {
				var a = document.createElement('a');
				a.href = OF.playerUrl + parseInt(h.MundaneId, 10);
				a.textContent = h.Persona || 'Unknown';
				tr.appendChild(td(a));
			} else {
				tr.appendChild(td(el('span', 'of-vacant', 'Vacant')));
			}

			var startCell = td(fmtDate(h.StartDate));
			startCell.className = 'of-oh-nowrap';
			tr.appendChild(startCell);

			// "Present" is the only remaining marker that a term is the running
			// one, now that the role badge that used to carry "(current)" is
			// gone with the Role column.
			var endCell = isCurrent ? td(el('span', 'of-oh-present', 'Present')) : td(fmtDate(h.EndDate));
			endCell.className = 'of-oh-nowrap';
			tr.appendChild(endCell);

			var notesCell = document.createElement('td');
			if (h.Notes) {
				notesCell.appendChild(el('span', 'of-oh-notes', h.Notes));
			}
			tr.appendChild(notesCell);

			if (OF.canEdit) {
				var actions = document.createElement('td');
				actions.className = 'of-oh-nowrap';
				// The index passed through is the row's position in the FLAT
				// response array, not its position in this panel: the host
				// page's Edit modal indexes into window.<prefix>OhData.
				actions.appendChild(actionBtn('edit', idx, h));
				actions.appendChild(actionBtn('delete', idx, h));
				tr.appendChild(actions);
			}

			tbody.appendChild(tr);
		}
		table.appendChild(tbody);
		wrap.appendChild(table);
		return wrap;
	}

	function fillPanel(panel, indices, rows) {
		var count = panel.querySelector('.of-oh-count');
		var body  = panel.querySelector('.of-oh-body');
		if (count) {
			count.textContent = indices.length === 0
				? 'No terms'
				: (indices.length + (indices.length === 1 ? ' term' : ' terms'));
		}
		if (!body) { return; }
		body.textContent = '';
		if (indices.length === 0) {
			// An office with nothing recorded says so, rather than opening onto
			// an empty table with headings and no rows.
			body.appendChild(el('div', 'of-oh-none', 'No terms have been recorded for this office yet.'));
			return;
		}
		indices.sort(function (a, b) { return cmpTerm(rows[a], rows[b]); });
		body.appendChild(buildTermTable(indices, rows));
	}

	function renderHistory(rows) {
		var box = panelsBox();
		if (!box) { return; }
		rows = rows || [];

		// Drop the panels the PREVIOUS render invented; the server-rendered
		// shells stay where they are.
		var stale = box.querySelectorAll('.of-oh-dynamic');
		for (var s = 0; s < stale.length; s++) { stale[s].parentNode.removeChild(stale[s]); }

		// Indices over the server-rendered shells. byRole/byTitle are the
		// graceful-degradation path: until the backend emits PositionId on
		// history rows, every row arrives with 0 and has to find its office by
		// canonical key (byRole) or, for a legacy row that stored a human label
		// rather than a key, by office name (byTitle).
		var byGroup = {}, byRole = {}, byTitle = {};
		var shells = box.querySelectorAll('.of-oh-panel');
		for (var p = 0; p < shells.length; p++) {
			var shell = shells[p];
			byGroup[shell.getAttribute('data-group')] = shell;
			var sr = shell.getAttribute('data-role');
			if (sr && !byRole[sr]) { byRole[sr] = shell; }
			var st = shell.getAttribute('data-title');
			if (st && !byTitle[st]) { byTitle[st] = shell; }
		}

		var groups = {};   // panel group key -> [index into rows]
		var extras = {};   // group key -> descriptor for a panel we must invent
		for (var i = 0; i < rows.length; i++) {
			var h    = rows[i] || {};
			var pid  = parseInt(h.PositionId, 10) || 0;
			var role = String(h.Role || '').trim().toLowerCase();
			var key;
			if (pid > 0 && byGroup['p' + pid]) {
				key = 'p' + pid;
			} else if (role && byRole[role]) {
				key = byRole[role].getAttribute('data-group');
			} else if (role && byTitle[role]) {
				key = byTitle[role].getAttribute('data-group');
			} else {
				// No current office answers for this row -- a retired position,
				// or a legacy row whose position_id is 0. It gets its own panel
				// at the bottom rather than being dropped on the floor.
				key = 'h:' + (pid > 0 ? ('p' + pid) : ('r:' + role));
				if (!extras[key]) {
					extras[key] = {
						key:   key,
						role:  role,
						label: labelFor(h),
						rank:  classRank(h.Classification)
					};
				}
			}
			if (!groups[key]) { groups[key] = []; }
			groups[key].push(i);
		}

		// History-only panels all sit BELOW the current offices; among
		// themselves they keep crown -> supporting -> unknown, then name.
		var extraKeys = [];
		for (var k in extras) {
			if (Object.prototype.hasOwnProperty.call(extras, k)) { extraKeys.push(k); }
		}
		extraKeys.sort(function (a, b) {
			if (extras[a].rank !== extras[b].rank) { return extras[a].rank - extras[b].rank; }
			return extras[a].label.localeCompare(extras[b].label);
		});
		for (var e = 0; e < extraKeys.length; e++) {
			box.appendChild(makeDynamicPanel(extras[extraKeys[e]]));
			addFilterOption(extras[extraKeys[e]]);
		}

		var panels = box.querySelectorAll('.of-oh-panel');
		for (var q = 0; q < panels.length; q++) {
			fillPanel(panels[q], groups[panels[q].getAttribute('data-group')] || [], rows);
		}

		var visible = applyFilter();
		var sel     = gid('of-oh-role-filter');
		var filtered = sel ? String(sel.value || '').trim() : '';

		if (panels.length === 0) {
			// Nothing current, nothing historical. ork_officer_history is
			// near-empty across the whole database, so this reads as finished
			// rather than broken.
			showNote('');
			showEmpty('No officer terms have been recorded here yet. Past officers appear once their terms are entered.');
		} else if (filtered !== '' && visible === 0) {
			showNote('');
			showEmpty('No officer terms are recorded for ' + selectedRoleLabel() + '.');
		} else if (rows.length === 0) {
			showEmpty('');
			showNote('No officer terms have been recorded here yet. Each office below still shows who holds it now.');
		} else {
			showEmpty('');
			showNote('');
		}
	}

	function selectedRoleLabel() {
		var sel = gid('of-oh-role-filter');
		if (!sel || sel.selectedIndex < 0) { return 'this office'; }
		var text = String(sel.options[sel.selectedIndex].text || '').trim();
		return text !== '' ? text : 'this office';
	}

	// Choosing a role narrows the list to that one office and opens it, because
	// picking it IS the request to read it. "All Roles" restores every panel,
	// collapsed.
	function applyFilter() {
		var sel  = gid('of-oh-role-filter');
		var role = sel ? String(sel.value || '').trim().toLowerCase() : '';
		var box  = panelsBox();
		if (!box) { return 0; }
		var panels = box.querySelectorAll('.of-oh-panel');
		var shown  = 0;
		for (var i = 0; i < panels.length; i++) {
			var match = (role === '') || (String(panels[i].getAttribute('data-role') || '') === role);
			panels[i].hidden = !match;
			panels[i].open   = (role !== '' && match);
			if (match) { shown++; }
		}
		return shown;
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
