<?php
/**
 * Partial: catalog.tpl  (MEDIA block) — "Store Catalog".
 * CSS lives in frontdoor/css/blocks.css (scoped: fdb-catalog); the detail modal
 * is driven by frontdoor/js/frontdoor.js, delegated off document exactly like
 * the staff_roster contact card, so any number of catalog blocks share it.
 * Receives: $blockFields, shared $data, UIR, $fdIsPreview
 *
 * Fields:
 *   kicker?, heading?, subheading?  section header (escaped)
 *   columns  int 2..4 (default 3)
 *   items[]  each:
 *     image        media ref {src,thumb,display,alt} — the card thumbnail
 *     images[]     media ref[] — extra photos, shown only in the modal
 *     title, subtitle, price, description   plain text (escaped; description
 *                  keeps its line breaks via CSS pre-line)
 *     href         the external store URL (CmsPage::URL_FIELDS — already
 *                  SafeHrefOrHash'd on save; '' becomes '#')
 *     store        store name for "Buy on {store}"; blank = auto-detected
 *                  from href's host (CmsBlockRegistry::CatalogStoreName)
 *     badge        '' | new | limited | going_soon | best_seller | sold_out | custom
 *     badge_text   the label when badge = custom
 *
 * ORK never takes the order: the Buy button always links OUT, in a new tab.
 * A sold-out item keeps its card but its button becomes an inert "Sold out".
 *
 * "Dumb" partial: renders $blockFields only, fetches nothing. The modal is
 * filled from a per-block JSON island with textContent — never innerHTML — so
 * authored strings cannot become markup on the client either.
 */
$fdcKicker = trim((string) ($blockFields['kicker'] ?? ''));
$fdcHeading = trim((string) ($blockFields['heading'] ?? ''));
$fdcSub = trim((string) ($blockFields['subheading'] ?? ''));
$fdcCols = (int) ($blockFields['columns'] ?? 3);
if ($fdcCols < 2 || $fdcCols > 4) {
    $fdcCols = 3;
}

$fdcBadgeLabels = [
    'new'         => 'New',
    'limited'     => 'Limited Time',
    'going_soon'  => 'Going Soon',
    'best_seller' => 'Best Seller',
    'sold_out'    => 'Sold Out',
];

// A media ref's best URL for a role, falling back through its renditions.
$fdcPick = static function ($ref, array $order) {
    if (!is_array($ref)) {
        return '';
    }
    foreach ($order as $k) {
        if (!empty($ref[$k]) && $ref[$k] !== '#') {
            return (string) $ref[$k];
        }
    }
    return '';
};

$fdcRaw = $blockFields['items'] ?? [];
$fdcRaw = is_array($fdcRaw) ? array_values(array_filter($fdcRaw, 'is_array')) : [];

$fdcItems = [];
foreach ($fdcRaw as $fdcIt) {
    $title = trim((string) ($fdcIt['title'] ?? ''));
    $cover = $fdcIt['image'] ?? [];
    $thumb = $fdcPick($cover, ['display', 'thumb', 'src']);
    // An item with neither a name nor a picture has nothing to show.
    if ($title === '' && $thumb === '') {
        continue;
    }

    // Modal photos: the cover first, then the extras, full-size where known.
    $photos = [];
    $extras = (isset($fdcIt['images']) && is_array($fdcIt['images'])) ? $fdcIt['images'] : [];
    foreach (array_merge([$cover], $extras) as $ref) {
        $full = $fdcPick($ref, ['src', 'display', 'thumb']);
        if ($full === '') {
            continue;
        }
        $photos[] = [
            'src'   => $full,
            'thumb' => $fdcPick($ref, ['thumb', 'display', 'src']),
            'alt'   => (string) ($ref['alt'] ?? ''),
        ];
    }

    $rawHref = trim((string) ($fdcIt['href'] ?? ''));
    $hasHref = ($rawHref !== '' && $rawHref !== '#');
    $href = $hasHref ? CmsSanitizer::SafeHrefOrHash($rawHref) : '';
    if ($href === '#') {
        $hasHref = false;
        $href = '';
    }

    $store = trim((string) ($fdcIt['store'] ?? ''));
    if ($store === '' && $hasHref) {
        $store = CmsBlockRegistry::CatalogStoreName($href);
    }

    $badge = (string) ($fdcIt['badge'] ?? '');
    if ($badge === 'custom') {
        $badgeLabel = trim((string) ($fdcIt['badge_text'] ?? ''));
    } else {
        $badgeLabel = $fdcBadgeLabels[$badge] ?? '';
    }
    if ($badgeLabel === '') {
        $badge = '';
    }
    // Sold out means no way to buy — drop the link entirely rather than just
    // hiding it, so it isn't sitting in the modal's data island either.
    if ($badge === 'sold_out') {
        $hasHref = false;
        $href = '';
    }

    $fdcItems[] = [
        'title'       => $title,
        'subtitle'    => trim((string) ($fdcIt['subtitle'] ?? '')),
        'price'       => trim((string) ($fdcIt['price'] ?? '')),
        'description' => trim((string) ($fdcIt['description'] ?? '')),
        'thumb'       => $thumb,
        'alt'         => (string) (is_array($cover) ? ($cover['alt'] ?? '') : ''),
        'photos'      => $photos,
        'href'        => $href,
        'cta'         => $hasHref ? ($store !== '' ? 'Buy on ' . $store : 'Buy now') : '',
        'badge'       => $badge,
        'badge_label' => $badgeLabel,
        'sold_out'    => ($badge === 'sold_out'),
    ];
}

if (empty($fdcItems) && $fdcHeading === '' && $fdcKicker === '' && $fdcSub === '') {
    if ($fdIsPreview) {
        fdEmptyBlockNotice('This store catalog block is empty — add an item to show it.');
    }
    return;
}

$fdcId = 'fdbcat-' . substr(md5(uniqid('', true)), 0, 8);

// The modal's data island: exactly what the dialog paints, nothing more.
$fdcModalData = array_map(static function ($it) {
    return [
        'title'       => $it['title'],
        'subtitle'    => $it['subtitle'],
        'price'       => $it['price'],
        'description' => $it['description'],
        'photos'      => $it['photos'],
        'href'        => $it['href'],
        'cta'         => $it['cta'],
        'badge'       => $it['badge'],
        'badgeLabel'  => $it['badge_label'],
        'soldOut'     => $it['sold_out'],
    ];
}, $fdcItems);
?>
<div class="fd-pad fdb-catalog" id="<?= htmlspecialchars($fdcId, ENT_QUOTES) ?>" style="--fdb-cols:<?= (int) $fdcCols ?>;">
    <?php if ($fdcKicker !== '' || $fdcHeading !== '' || $fdcSub !== ''): ?>
        <div class="fdb-catalog-head">
            <?php if ($fdcKicker !== ''): ?>
                <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($fdcKicker, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($fdcHeading !== ''): ?>
                <h2 class="fd-sec-title"><?= htmlspecialchars($fdcHeading, ENT_QUOTES) ?></h2>
            <?php endif; ?>
            <?php if ($fdcSub !== ''): ?>
                <p class="fd-body-text fdb-catalog-sub"><?= htmlspecialchars($fdcSub, ENT_QUOTES) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($fdcItems)): ?>
        <ul class="fdb-catalog-grid" role="list">
            <?php foreach ($fdcItems as $fdcIdx => $fdcItem): ?>
                <?php $fdcName = $fdcItem['title'] !== '' ? $fdcItem['title'] : 'Item ' . ($fdcIdx + 1); ?>
                <li class="fdb-catalog-card<?= $fdcItem['sold_out'] ? ' is-sold-out' : '' ?>">
                    <?php // Pointer shortcut only: the title button below is the one keyboard/SR stop per card. ?>
                    <button type="button" class="fdb-catalog-thumb" data-fdb-cat-open="<?= (int) $fdcIdx ?>" tabindex="-1" aria-hidden="true">
                        <?php if ($fdcItem['thumb'] !== ''): ?>
                            <img src="<?= htmlspecialchars($fdcItem['thumb'], ENT_QUOTES) ?>"
                                 alt="<?= htmlspecialchars($fdcItem['alt'], ENT_QUOTES) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="fdb-catalog-noimg"><i class="fas fa-store" aria-hidden="true"></i></span>
                        <?php endif; ?>
                    </button>
                    <?php if ($fdcItem['badge'] !== ''): ?>
                        <span class="fdb-catalog-badge" data-badge="<?= htmlspecialchars($fdcItem['badge'], ENT_QUOTES) ?>"><?= htmlspecialchars($fdcItem['badge_label'], ENT_QUOTES) ?></span>
                    <?php endif; ?>
                    <div class="fdb-catalog-body">
                        <h3 class="fdb-catalog-title">
                            <button type="button" class="fdb-catalog-open" data-fdb-cat-open="<?= (int) $fdcIdx ?>" aria-haspopup="dialog">
                                <?= htmlspecialchars($fdcName, ENT_QUOTES) ?>
                            </button>
                        </h3>
                        <?php if ($fdcItem['subtitle'] !== ''): ?>
                            <div class="fdb-catalog-subtitle"><?= htmlspecialchars($fdcItem['subtitle'], ENT_QUOTES) ?></div>
                        <?php endif; ?>
                        <div class="fdb-catalog-foot">
                            <?php if ($fdcItem['price'] !== ''): ?>
                                <span class="fdb-catalog-price"><?= htmlspecialchars($fdcItem['price'], ENT_QUOTES) ?></span>
                            <?php endif; ?>
                            <?php if ($fdcItem['sold_out']): ?>
                                <span class="fdb-catalog-cta is-disabled" aria-disabled="true">Sold out</span>
                            <?php elseif ($fdcItem['cta'] !== ''): ?>
                                <a class="fdb-catalog-cta" href="<?= htmlspecialchars($fdcItem['href'], ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= htmlspecialchars($fdcItem['cta'], ENT_QUOTES) ?>
                                    <i class="fas fa-external-link-alt" aria-hidden="true"></i>
                                    <span class="fdb-catalog-sr">(opens in a new tab)</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <script type="application/json" class="fdb-catalog-data"><?= json_encode($fdcModalData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endif; ?>
</div>
<?php
// One shared detail dialog per request, like staff_roster's #fdRosterModal.
if (!empty($fdcItems) && empty($GLOBALS['__fd_catalog_modal_emitted'])):
    $GLOBALS['__fd_catalog_modal_emitted'] = true;
?>
<div class="fdb-cmodal" id="fdCatalogModal" hidden>
    <div class="fdb-cmodal-backdrop" data-fdb-cat-close></div>
    <div class="fdb-cmodal-card" role="dialog" aria-modal="true" aria-labelledby="fdCatalogModalTitle" tabindex="-1">
        <button type="button" class="fdb-cmodal-close" data-fdb-cat-close aria-label="Close">&times;</button>
        <div class="fdb-cmodal-media">
            <div class="fdb-cmodal-stage"><img src="" alt=""></div>
            <div class="fdb-cmodal-strip" role="group" aria-label="More photos"></div>
        </div>
        <div class="fdb-cmodal-info">
            <span class="fdb-catalog-badge fdb-cmodal-badge" hidden></span>
            <h2 class="fdb-cmodal-title" id="fdCatalogModalTitle"></h2>
            <div class="fdb-cmodal-subtitle" hidden></div>
            <div class="fdb-cmodal-price" hidden></div>
            <div class="fdb-cmodal-desc" hidden></div>
            <a class="fdb-catalog-cta fdb-cmodal-cta" href="#" target="_blank" rel="noopener noreferrer" hidden>
                <span class="fdb-cmodal-cta-label"></span>
                <i class="fas fa-external-link-alt" aria-hidden="true"></i>
                <span class="fdb-catalog-sr">(opens in a new tab)</span>
            </a>
            <span class="fdb-catalog-cta is-disabled fdb-cmodal-soldout" aria-disabled="true" hidden>Sold out</span>
            <div class="fdb-cmodal-nav">
                <button type="button" class="fdb-cmodal-step" data-fdb-cat-step="-1"><i class="fas fa-chevron-left" aria-hidden="true"></i> Previous</button>
                <span class="fdb-cmodal-count" aria-live="polite"></span>
                <button type="button" class="fdb-cmodal-step" data-fdb-cat-step="1">Next <i class="fas fa-chevron-right" aria-hidden="true"></i></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
