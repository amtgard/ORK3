<?php
/**
 * Partial: gallery.tpl  (MEDIA block) — CSS lives in frontdoor/css/blocks.css;
 * the lightbox behaviour ships as a scoped inline <script>.
 * Receives: $blockFields, shared $data, UIR
 *
 * Fields:
 *   images   media ref[] each {key,media_id,src,thumb,alt,focal}
 *   columns  int 2..4 (default 3)
 *   caption? string — optional gallery caption (escaped)
 *
 * Responsive thumbnail grid; clicking a thumb opens a self-contained lightbox
 * (inline script + overlay markup scoped to this block, no external library).
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
<div class="fd-pad">
    <div class="fdb-gallery-grid" id="<?= htmlspecialchars($fdbGid, ENT_QUOTES) ?>" style="--fdb-cols:<?= (int) $fdbCols ?>;">
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

    <div class="fdb-gallery-lb" id="<?= htmlspecialchars($fdbGid, ENT_QUOTES) ?>-lb" role="dialog" aria-modal="true" aria-label="Image viewer" tabindex="-1">
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

    var thumbs   = Array.prototype.slice.call(grid.querySelectorAll('.fdb-gallery-thumb'));
    var lbImg    = lb.querySelector('img');
    var lbCount  = lb.querySelector('.fdb-gallery-lb-count');
    var closeBtn = lb.querySelector('.fdb-gallery-lb-close');
    var cur      = 0;
    var lastFocused = null;
    var prevBodyOverflow = '';
    var inertNodes = [];   // {el, hadAriaHidden, prevAriaHidden, hadInert}

    // Make everything outside the lightbox inert + aria-hidden so SR/keyboard
    // users can't Tab or scroll past the trap. We hide the siblings of the
    // lightbox at every level from its parent up to <body>.
    function setBackgroundInert(on) {
        if (on) {
            inertNodes = [];
            var node = lb;
            while (node && node.parentNode && node.parentNode.nodeType === 1) {
                var parent = node.parentNode;
                var kids = parent.children;
                for (var k = 0; k < kids.length; k++) {
                    var sib = kids[k];
                    if (sib === node) { continue; }
                    inertNodes.push({
                        el: sib,
                        hadAriaHidden: sib.hasAttribute('aria-hidden'),
                        prevAriaHidden: sib.getAttribute('aria-hidden'),
                        hadInert: sib.hasAttribute('inert')
                    });
                    sib.setAttribute('aria-hidden', 'true');
                    sib.setAttribute('inert', '');
                }
                if (parent === document.body || parent.tagName === 'BODY') { break; }
                node = parent;
            }
        } else {
            inertNodes.forEach(function (r) {
                if (r.hadAriaHidden) { r.el.setAttribute('aria-hidden', r.prevAriaHidden); }
                else { r.el.removeAttribute('aria-hidden'); }
                if (!r.hadInert) { r.el.removeAttribute('inert'); }
            });
            inertNodes = [];
        }
    }

    function show(i) {
        if (!thumbs.length) { return; }
        cur = (i + thumbs.length) % thumbs.length;
        var t = thumbs[cur];
        lbImg.src = t.getAttribute('data-fdb-full');
        lbImg.alt = t.getAttribute('data-fdb-alt') || '';
        lbCount.textContent = (cur + 1) + ' / ' + thumbs.length;
    }
    // Currently-visible focusable controls inside the dialog (for the Tab trap).
    function focusables() {
        return Array.prototype.slice.call(lb.querySelectorAll('button'))
            .filter(function (el) { return el.offsetParent !== null; });
    }
    function open(i) {
        lastFocused = document.activeElement;
        show(i);
        // Lock body scroll and inert the background BEFORE showing so the
        // background can't be scrolled or tabbed into behind the overlay.
        prevBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        setBackgroundInert(true);
        lb.classList.add('fdb-gallery-open');
        document.addEventListener('keydown', onKey, true);
        // Move focus into the dialog so keyboard users land inside it.
        (closeBtn || lb).focus();
    }
    function close() {
        lb.classList.remove('fdb-gallery-open');
        document.removeEventListener('keydown', onKey, true);
        // Reverse the background inerting + restore body scroll.
        setBackgroundInert(false);
        document.body.style.overflow = prevBodyOverflow;
        // Restore focus to the thumb (or whatever) that opened the lightbox.
        if (lastFocused && typeof lastFocused.focus === 'function') { lastFocused.focus(); }
    }
    function onKey(e) {
        if (e.key === 'Escape') { close(); return; }
        if (e.key === 'ArrowLeft') { show(cur - 1); return; }
        if (e.key === 'ArrowRight') { show(cur + 1); return; }
        if (e.key === 'Tab') {
            // Trap Tab within the dialog.
            var f = focusables();
            if (!f.length) { e.preventDefault(); return; }
            var first = f[0], last = f[f.length - 1], active = document.activeElement;
            if (e.shiftKey) {
                if (active === first || !lb.contains(active)) { e.preventDefault(); last.focus(); }
            } else {
                if (active === last || !lb.contains(active)) { e.preventDefault(); first.focus(); }
            }
        }
    }

    thumbs.forEach(function (t) {
        t.addEventListener('click', function () {
            open(parseInt(t.getAttribute('data-fdb-idx'), 10) || 0);
        });
    });
    lb.querySelector('.fdb-gallery-lb-prev').addEventListener('click', function (e) { e.stopPropagation(); show(cur - 1); });
    lb.querySelector('.fdb-gallery-lb-next').addEventListener('click', function (e) { e.stopPropagation(); show(cur + 1); });
    closeBtn.addEventListener('click', close);
    lb.addEventListener('click', function (e) {
        // click on the backdrop (not the image or nav buttons) closes
        if (e.target === lb) { close(); }
    });
})();
</script>
