<?php
/**
 * Partial: staff_roster.tpl
 * Receives: $blockFields (kicker, heading, subheading, presentation, people[]), UIR
 * people[] each: image['src','alt'], persona_name, mundane_name, role, bio, mundane_id, href, show_mundane
 *
 * PII/consent (C21): a person's real (mundane) name is PUBLISHED ONLY when they
 * have explicitly opted in via show_mundane. Without that consent the card shows
 * the Amtgard persona alone — even when the block's presentation is "Real name
 * leads" — so a roster can never expose a member's legal name without opt-in.
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
            <h2 class="fd-sec-title"><?= htmlspecialchars($heading, ENT_QUOTES) ?></h2>
        <?php endif; ?>
        <?php if (!empty($subheading)): ?>
            <p class="fd-roster-sub" style="margin:6px 0 0;font-size:15px;text-align:center;"><?= htmlspecialchars($subheading, ENT_QUOTES) ?></p>
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

                // C21 consent gate: the real name is publishable ONLY when opted in.
                // Legacy rows (authored before the opt-in existed) carry no
                // show_mundane key and therefore default to withheld.
                $showMundane = !empty($person['show_mundane']);
                $mundanePub  = $showMundane ? $mundane : '';

                if ($presentation === 'mundane' && $mundanePub !== '') {
                    // Real name leads only when it's actually publishable.
                    $primary   = $mundanePub;
                    $secondary = $persona;
                } else {
                    // Persona leads — and is the forced fallback whenever the real
                    // name is withheld (no consent) so it never leaks as secondary.
                    $primary   = ($persona !== '') ? $persona : $mundanePub;
                    $secondary = ($persona !== '' && $mundanePub !== '') ? $mundanePub : '';
                }
                if ($primary === '') { continue; }

                $link = '';
                if ($mid > 0) {
                    $link = UIR . 'Player/profile/' . $mid;
                } elseif ($href !== '' && CmsSanitizer::IsSafeUrl($href)) {
                    $link = $href;
                }

                // Initials for the monogram fallback (and the modal avatar).
                $nameParts = preg_split('/\s+/', $primary, -1, PREG_SPLIT_NO_EMPTY);
                $initials  = '';
                foreach (array_slice($nameParts, 0, 2) as $np) {
                    $initials .= mb_strtoupper(mb_substr($np, 0, 1));
                }
                if ($initials === '') { $initials = '?'; }
                $photoSrc = !empty($img['src']) ? (string) $img['src'] : '';

                // EVERY card opens the shared contact-card modal — identical-looking
                // cards must behave identically. A card that has a destination (member
                // profile / explicit URL) carries it as data-fd-link so the modal shows
                // a "View full profile ->" link, rather than silently navigating into
                // the internal app on click. Carries the FULL (untruncated) bio so the
                // modal can show it even though the card body clamps it.
                $open = '<div class="fd-roster-card fd-roster-card-modal" role="button" tabindex="0"'
                    . ' aria-haspopup="dialog" aria-label="' . htmlspecialchars('View ' . $primary, ENT_QUOTES) . '"'
                    . ' data-fd-name="' . htmlspecialchars($primary, ENT_QUOTES) . '"'
                    . ' data-fd-secondary="' . htmlspecialchars($secondary, ENT_QUOTES) . '"'
                    . ' data-fd-role="' . htmlspecialchars($role, ENT_QUOTES) . '"'
                    . ' data-fd-bio="' . htmlspecialchars($bio, ENT_QUOTES) . '"'
                    . ' data-fd-initials="' . htmlspecialchars($initials, ENT_QUOTES) . '"'
                    . ' data-fd-img="' . htmlspecialchars($photoSrc, ENT_QUOTES) . '"'
                    . ($link !== '' ? ' data-fd-link="' . htmlspecialchars($link, ENT_QUOTES) . '"' : '')
                    . '>';
                $close = '</div>';
                ?>
                <?= $open ?>
                    <?php if ($photoSrc !== ''): ?>
                        <img class="fd-roster-photo" src="<?= htmlspecialchars($photoSrc, ENT_QUOTES) ?>" alt="<?= htmlspecialchars(($img['alt'] ?? '') !== '' ? $img['alt'] : $primary, ENT_QUOTES) ?>">
                    <?php else: ?>
                        <div class="fd-roster-photo fd-roster-photo-empty" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES) ?></div>
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
                    <div class="fd-roster-more">View details &rarr;</div>
                <?= $close ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
// Contact-card modal chrome. Emitted ONCE per request even
// when several staff_roster blocks appear on a page (a single shared dialog,
// populated from the clicked card's data-* attributes).
if (empty($GLOBALS['__fd_roster_modal_emitted'])):
    $GLOBALS['__fd_roster_modal_emitted'] = true;
?>
<div class="fd-rmodal" id="fdRosterModal" hidden aria-hidden="true">
    <div class="fd-rmodal-backdrop" data-fd-close></div>
    <div class="fd-rmodal-card" role="dialog" aria-modal="true" aria-labelledby="fdRModalName" tabindex="-1">
        <button type="button" class="fd-rmodal-close" data-fd-close aria-label="Close">&times;</button>
        <div class="fd-rmodal-avatar" id="fdRModalAvatar" aria-hidden="true"></div>
        <h3 class="fd-rmodal-name fd-serif" id="fdRModalName"></h3>
        <div class="fd-rmodal-secondary" id="fdRModalSecondary" hidden></div>
        <div class="fd-rmodal-role" id="fdRModalRole" hidden></div>
        <div class="fd-rmodal-bio" id="fdRModalBio" hidden></div>
        <a class="fd-rmodal-profile" id="fdRModalProfile" hidden><i class="fas fa-external-link-alt"></i> View full profile &rarr;</a>
    </div>
</div>
<?php endif; ?>
