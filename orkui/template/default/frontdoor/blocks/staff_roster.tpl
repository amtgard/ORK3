<?php
/**
 * Partial: staff_roster.tpl
 * Receives: $blockFields (kicker, heading, subheading, presentation, people[]), UIR
 * people[] each: image['src','alt'], persona_name, mundane_name, role, bio, mundane_id, href
 */
$kicker       = $blockFields['kicker']       ?? '';
$heading      = $blockFields['heading']      ?? '';
$subheading   = $blockFields['subheading']   ?? '';
$presentation = (($blockFields['presentation'] ?? 'amtgard') === 'mundane') ? 'mundane' : 'amtgard';
$people       = $blockFields['people']       ?? [];
?>
<div class="fd-pad fd-roster">
    <div style="text-align:center;margin-bottom:22px;">
        <?php if (!empty($kicker)): ?>
            <div class="fd-kicker fd-kicker-d" style="margin-bottom:8px;"><?= htmlspecialchars($kicker, ENT_QUOTES) ?></div>
        <?php endif; ?>
        <?php if (!empty($heading)): ?>
            <h3 class="fd-sec-title"><?= htmlspecialchars($heading, ENT_QUOTES) ?></h3>
        <?php endif; ?>
        <?php if (!empty($subheading)): ?>
            <p style="color:#667;margin:6px 0 0;font-size:15px;text-align:center;"><?= htmlspecialchars($subheading, ENT_QUOTES) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!empty($people) && is_array($people)): ?>
        <div class="fd-roster-grid">
            <?php foreach ($people as $person): ?>
                <?php
                if (!is_array($person)) { continue; }
                $img     = (isset($person['image']) && is_array($person['image'])) ? $person['image'] : [];
                $persona = trim((string)($person['persona_name'] ?? ''));
                $mundane = trim((string)($person['mundane_name'] ?? ''));
                $role    = trim((string)($person['role'] ?? ''));
                $bio     = trim((string)($person['bio'] ?? ''));
                $mid     = (int)($person['mundane_id'] ?? 0);
                $href    = trim((string)($person['href'] ?? ''));

                if ($presentation === 'mundane') {
                    $primary   = ($mundane !== '') ? $mundane : $persona;
                    $secondary = ($mundane !== '') ? $persona : '';
                } else {
                    $primary   = ($persona !== '') ? $persona : $mundane;
                    $secondary = ($persona !== '') ? $mundane : '';
                }
                if ($primary === '') { continue; }

                $link = '';
                if ($mid > 0) {
                    $link = UIR . 'Player/profile/' . $mid;
                } elseif ($href !== '' && CmsSanitizer::IsSafeUrl($href)) {
                    $link = $href;
                }
                $open  = ($link !== '') ? '<a class="fd-roster-card" href="' . htmlspecialchars($link, ENT_QUOTES) . '">' : '<div class="fd-roster-card">';
                $close = ($link !== '') ? '</a>' : '</div>';
                ?>
                <?= $open ?>
                    <?php if (!empty($img['src'])): ?>
                        <img class="fd-roster-photo" src="<?= htmlspecialchars($img['src'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars(($img['alt'] ?? '') !== '' ? $img['alt'] : $primary, ENT_QUOTES) ?>">
                    <?php else: ?>
                        <div class="fd-roster-photo fd-roster-photo-empty"><i class="fas fa-user"></i></div>
                    <?php endif; ?>
                    <div class="fd-roster-name fd-serif"><?= htmlspecialchars($primary, ENT_QUOTES) ?></div>
                    <?php if ($secondary !== ''): ?>
                        <div class="fd-roster-secondary"><?= htmlspecialchars($secondary, ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <?php if ($role !== ''): ?>
                        <div class="fd-roster-role"><?= htmlspecialchars($role, ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <?php if ($bio !== ''): ?>
                        <div class="fd-roster-bio"><?= nl2br(htmlspecialchars($bio, ENT_QUOTES)) ?></div>
                    <?php endif; ?>
                <?= $close ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
