<?php
/**
 * Partial: gallery.tpl  (MEDIA block) — self-contained (own scoped <style> + <script>)
 * Receives: $blockFields, shared $data, UIR
 *
 * Fields:
 *   images   media ref[] each {key,media_id,src,thumb,alt,focal}
 *   columns  int 2..4 (default 3)
 *   caption? string — optional gallery caption (escaped)
 *
 * Responsive thumbnail grid; clicking a thumb opens a self-contained lightbox
 * (inline <script> + overlay markup scoped to this block, no external library).
 * ESC / click-outside / × to close, prev/next nav. Uses ref.thumb in the grid
 * and ref.src in the lightbox.
 *
 * "Dumb" partial: renders $blockFields only, fetches nothing. Escapes every
 * authored string with htmlspecialchars(..., ENT_QUOTES).
 */
$fdbImages  = $blockFields['images'] ?? [];
$fdbImages  = is_array($fdbImages) ? array_values(array_filter($fdbImages, 'is_array')) : [];
$fdbCaption = $blockFields['caption'] ?? '';
$fdbCols    = (int) ($blockFields['columns'] ?? 3);
if ($fdbCols < 2 || $fdbCols > 4) {
    $fdbCols = 3;
}

// Keep only entries with a usable image; fall back thumb→src and src→thumb.
$fdbItems = [];
foreach ($fdbImages as $fdbImg) {
    $fdbFull  = $fdbImg['src']   ?? '';
    $fdbThumb = $fdbImg['thumb'] ?? '';
    if ($fdbFull === '' && $fdbThumb === '') {
        continue;
    }
    $fdbItems[] = [
        'full'  => $fdbFull !== '' ? $fdbFull : $fdbThumb,
        'thumb' => $fdbThumb !== '' ? $fdbThumb : $fdbFull,
        'alt'   => $fdbImg['alt'] ?? '',
    ];
}

if (empty($fdbItems)) {
    return;
}

// Unique id so multiple gallery blocks on one page don't collide.
$fdbGid = 'fdbgal-' . substr(md5(uniqid('', true)), 0, 8);
?>
<style>
/* scoped: fdb-gallery */
.fdb-gallery-grid {
    display: grid;
    grid-template-columns: repeat(<?= (int) $fdbCols ?>, 1fr);
    gap: 10px;
}
@media (max-width: 760px) {
    .fdb-gallery-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 460px) {
    .fdb-gallery-grid { grid-template-columns: 1fr; }
}
.fdb-gallery-thumb {
    display: block;
    width: 100%;
    aspect-ratio: 4 / 3;
    border: 0;
    padding: 0;
    margin: 0;
    cursor: pointer;
    border-radius: 8px;
    overflow: hidden;
    background: #f1f3f8;
}
.fdb-gallery-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform .25s ease;
}
.fdb-gallery-thumb:hover img { transform: scale(1.05); }
.fdb-gallery-cap {
    margin-top: 12px;
    text-align: center;
    font-size: 13px;
    color: #667;
}
/* Lightbox overlay */
.fdb-gallery-lb {
    position: fixed;
    inset: 0;
    z-index: 100000;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(6, 9, 18, .92);
}
.fdb-gallery-lb.fdb-gallery-open { display: flex; }
.fdb-gallery-lb img {
    max-width: 90vw;
    max-height: 84vh;
    object-fit: contain;
    border-radius: 6px;
    box-shadow: 0 10px 50px rgba(0, 0, 0, .6);
}
.fdb-gallery-lb-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(255, 255, 255, .12);
    color: #fff;
    border: 0;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    font-size: 26px;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
.fdb-gallery-lb-btn:hover { background: rgba(255, 255, 255, .25); }
.fdb-gallery-lb-prev { left: 18px; }
.fdb-gallery-lb-next { right: 18px; }
.fdb-gallery-lb-close {
    position: absolute;
    top: 16px;
    right: 18px;
    background: none;
    border: 0;
    color: #fff;
    font-size: 34px;
    line-height: 1;
    cursor: pointer;
    opacity: .8;
}
.fdb-gallery-lb-close:hover { opacity: 1; }
.fdb-gallery-lb-count {
    position: absolute;
    bottom: 20px;
    left: 0;
    right: 0;
    text-align: center;
    color: #cdd5e6;
    font-size: 13px;
}
html[data-theme="dark"] .fdb-gallery-thumb { background: #1b2236; }
html[data-theme="dark"] .fdb-gallery-cap { color: #9aa6c0; }
</style>
<div class="fd-pad">
    <div class="fdb-gallery-grid" id="<?= htmlspecialchars($fdbGid, ENT_QUOTES) ?>">
        <?php foreach ($fdbItems as $fdbIdx => $fdbItem): ?>
            <button type="button" class="fdb-gallery-thumb"
                    data-fdb-full="<?= htmlspecialchars($fdbItem['full'], ENT_QUOTES) ?>"
                    data-fdb-alt="<?= htmlspecialchars($fdbItem['alt'], ENT_QUOTES) ?>"
                    data-fdb-idx="<?= (int) $fdbIdx ?>">
                <img src="<?= htmlspecialchars($fdbItem['thumb'], ENT_QUOTES) ?>"
                     alt="<?= htmlspecialchars($fdbItem['alt'], ENT_QUOTES) ?>"
                     loading="lazy">
            </button>
        <?php endforeach; ?>
    </div>

    <?php if ($fdbCaption !== ''): ?>
        <div class="fdb-gallery-cap"><?= htmlspecialchars($fdbCaption, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <div class="fdb-gallery-lb" id="<?= htmlspecialchars($fdbGid, ENT_QUOTES) ?>-lb" role="dialog" aria-modal="true" aria-label="Image viewer">
        <button type="button" class="fdb-gallery-lb-close" aria-label="Close">&times;</button>
        <button type="button" class="fdb-gallery-lb-btn fdb-gallery-lb-prev" aria-label="Previous">&#10094;</button>
        <img src="" alt="">
        <button type="button" class="fdb-gallery-lb-btn fdb-gallery-lb-next" aria-label="Next">&#10095;</button>
        <div class="fdb-gallery-lb-count"></div>
    </div>
</div>
<script>
(function () {
    var grid = document.getElementById('<?= $fdbGid ?>');
    var lb   = document.getElementById('<?= $fdbGid ?>-lb');
    if (!grid || !lb || grid.dataset.fdbBound) { return; }
    grid.dataset.fdbBound = '1';

    var thumbs  = Array.prototype.slice.call(grid.querySelectorAll('.fdb-gallery-thumb'));
    var lbImg   = lb.querySelector('img');
    var lbCount = lb.querySelector('.fdb-gallery-lb-count');
    var cur     = 0;

    function show(i) {
        if (!thumbs.length) { return; }
        cur = (i + thumbs.length) % thumbs.length;
        var t = thumbs[cur];
        lbImg.src = t.getAttribute('data-fdb-full');
        lbImg.alt = t.getAttribute('data-fdb-alt') || '';
        lbCount.textContent = (cur + 1) + ' / ' + thumbs.length;
    }
    function open(i) {
        show(i);
        lb.classList.add('fdb-gallery-open');
        document.addEventListener('keydown', onKey);
    }
    function close() {
        lb.classList.remove('fdb-gallery-open');
        document.removeEventListener('keydown', onKey);
    }
    function onKey(e) {
        if (e.key === 'Escape') { close(); }
        else if (e.key === 'ArrowLeft') { show(cur - 1); }
        else if (e.key === 'ArrowRight') { show(cur + 1); }
    }

    thumbs.forEach(function (t) {
        t.addEventListener('click', function () {
            open(parseInt(t.getAttribute('data-fdb-idx'), 10) || 0);
        });
    });
    lb.querySelector('.fdb-gallery-lb-prev').addEventListener('click', function (e) { e.stopPropagation(); show(cur - 1); });
    lb.querySelector('.fdb-gallery-lb-next').addEventListener('click', function (e) { e.stopPropagation(); show(cur + 1); });
    lb.querySelector('.fdb-gallery-lb-close').addEventListener('click', close);
    lb.addEventListener('click', function (e) {
        // click on the backdrop (not the image or nav buttons) closes
        if (e.target === lb) { close(); }
    });
})();
</script>
