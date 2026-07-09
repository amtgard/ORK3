<?php
/**
 * Partial: marketing_nav.tpl
 * Receives: $blockFields (logo, items[], cta, login), $LoggedIn, $ViewerName, $UserKingdomId, UIR
 *
 * Nav source: the editable 'marketing' menu from the CMS nav store
 * (ork_cms_nav_item) is the authoritative source when it has rows. Like
 * blog_feed.tpl / events_feed.tpl, this DYNAMIC-ish block sources the menu
 * itself via the model pass-through (new APIModel('CmsNav') -> GetMenu),
 * since no controller injects nav rows onto the page. The lib resolves each
 * item to a renderable 'href' + 'target' (page/post slug, url, or dynamic
 * route key); see class.CmsNav.php.
 *
 * Fallback: if the store is empty/unavailable, we keep rendering the original
 * $blockFields['items'] hardcoded defaults (no behavioral regression). The
 * logo, cta, and login always come from $blockFields regardless of source.
 */
$logo  = $blockFields['logo']  ?? [];
$items = $blockFields['items'] ?? [];
$cta   = $blockFields['cta']   ?? [];
$login = $blockFields['login'] ?? [];

// Prefer the editable 'marketing' menu from the CMS nav store, when present.
$navFromStore = [];
if (class_exists('APIModel')) {
    try {
        $navModel  = new APIModel('CmsNav');
        $navResult = $navModel->GetMenu('marketing', 'global', 0);
        if (is_array($navResult) && !empty($navResult)) {
            $navFromStore = $navResult;
        }
    } catch (\Throwable $e) {
        $navFromStore = [];
    }
}

if (!empty($navFromStore)) {
    // Normalize store items to the shape the markup below expects
    // (label, href, target, children[label,href,target]).
    $items = [];
    foreach ($navFromStore as $navItem) {
        $row = [
            'label'  => (string) ($navItem['label'] ?? ''),
            'href'   => (string) ($navItem['href'] ?? '#'),
            'target' => (string) ($navItem['target'] ?? ''),
        ];
        if (!empty($navItem['children']) && is_array($navItem['children'])) {
            $kids = [];
            foreach ($navItem['children'] as $navChild) {
                $kids[] = [
                    'label'  => (string) ($navChild['label'] ?? ''),
                    'href'   => (string) ($navChild['href'] ?? '#'),
                    'target' => (string) ($navChild['target'] ?? ''),
                ];
            }
            if (!empty($kids)) {
                $row['children'] = $kids;
            }
        }
        $items[] = $row;
    }
}

// Active-nav matching: the current CMS page slug (set by Controller_Page::view;
// unset on the front-door home). A top-level item is active when its own target
// OR any of its children's targets resolve to Page/view/<current slug>.
$fdCurSlug = (string) ($CurrentSlug ?? '');
$fdHrefMatchesCurrent = static function ($href) use ($fdCurSlug): bool {
    if ($fdCurSlug === '' || !is_string($href)) {
        return false;
    }
    $needle = 'Page/view/';
    $pos = strpos($href, $needle);
    if ($pos === false) {
        return false;
    }
    $tail = substr($href, $pos + strlen($needle));
    $tail = preg_replace('/[?#].*$/', '', $tail);   // strip query/hash
    return $tail === $fdCurSlug || rawurldecode($tail) === $fdCurSlug;
};
$fdItemIsActive = static function ($item) use ($fdHrefMatchesCurrent): bool {
    if ($fdHrefMatchesCurrent($item['href'] ?? '')) {
        return true;
    }
    if (!empty($item['children']) && is_array($item['children'])) {
        foreach ($item['children'] as $c) {
            if ($fdHrefMatchesCurrent($c['href'] ?? '')) {
                return true;
            }
        }
    }
    return false;
};
?>
<nav class="fd-nav">
    <?php if (!empty($logo['src'])): ?>
        <img class="fd-logo"
             src="<?= htmlspecialchars($logo['src'], ENT_QUOTES) ?>"
             alt="<?= htmlspecialchars($logo['alt'] ?? '', ENT_QUOTES) ?>">
    <?php endif; ?>

    <div class="fd-navlinks">
        <?php foreach ($items as $item): ?>
            <?php $fdActiveCls = $fdItemIsActive($item) ? ' class="is-active"' : ''; ?>
            <div class="fd-navitem">
                <?php if (!empty($item['children'])): ?>
                    <a<?= $fdActiveCls ?> href="<?= htmlspecialchars(CmsSanitizer::SafeHrefOrHash($item['href'] ?? ''), ENT_QUOTES) ?>"<?= !empty($item['target']) ? ' target="' . htmlspecialchars($item['target'], ENT_QUOTES) . '" rel="noopener"' : '' ?> aria-haspopup="true" aria-expanded="false">
                        <?= htmlspecialchars($item['label'] ?? '', ENT_QUOTES) ?> &#9660;
                    </a>
                    <div class="fd-dropdown">
                        <?php foreach ($item['children'] as $child): ?>
                            <a href="<?= htmlspecialchars(CmsSanitizer::SafeHrefOrHash($child['href'] ?? ''), ENT_QUOTES) ?>"<?= !empty($child['target']) ? ' target="' . htmlspecialchars($child['target'], ENT_QUOTES) . '" rel="noopener"' : '' ?>>
                                <?= htmlspecialchars($child['label'] ?? '', ENT_QUOTES) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <a<?= $fdActiveCls ?> href="<?= htmlspecialchars(CmsSanitizer::SafeHrefOrHash($item['href'] ?? ''), ENT_QUOTES) ?>"<?= !empty($item['target']) ? ' target="' . htmlspecialchars($item['target'], ENT_QUOTES) . '" rel="noopener"' : '' ?>>
                        <?= htmlspecialchars($item['label'] ?? '', ENT_QUOTES) ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($login['label'])): ?>
        <a class="fd-nav-login" href="<?= htmlspecialchars($login['href'] ?? '#', ENT_QUOTES) ?>">
            <?= htmlspecialchars($login['label'], ENT_QUOTES) ?>
        </a>
    <?php endif; ?>

    <?php if (!empty($cta['label'])): ?>
        <a class="fd-nav-cta" href="<?= htmlspecialchars($cta['href'] ?? '#', ENT_QUOTES) ?>">
            <?= htmlspecialchars($cta['label'], ENT_QUOTES) ?>
        </a>
    <?php endif; ?>

    <button class="fd-nav-toggle" aria-label="Menu">&#9776;</button>
</nav>
