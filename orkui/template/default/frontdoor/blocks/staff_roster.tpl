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

                if ($link !== '') {
                    // Linked card (member profile / explicit URL) keeps navigating.
                    $open  = '<a class="fd-roster-card" href="' . htmlspecialchars($link, ENT_QUOTES) . '">';
                    $close = '</a>';
                } else {
                    // Unlinked card → clickable trigger for the contact-card modal.
                    // Carries the FULL (untruncated) bio so the modal can show it in
                    // full even though the card body clamps it.
                    $open = '<div class="fd-roster-card fd-roster-card-modal" role="button" tabindex="0"'
                        . ' aria-haspopup="dialog" aria-label="' . htmlspecialchars('View ' . $primary, ENT_QUOTES) . '"'
                        . ' data-fd-name="' . htmlspecialchars($primary, ENT_QUOTES) . '"'
                        . ' data-fd-secondary="' . htmlspecialchars($secondary, ENT_QUOTES) . '"'
                        . ' data-fd-role="' . htmlspecialchars($role, ENT_QUOTES) . '"'
                        . ' data-fd-bio="' . htmlspecialchars($bio, ENT_QUOTES) . '"'
                        . ' data-fd-initials="' . htmlspecialchars($initials, ENT_QUOTES) . '"'
                        . ' data-fd-img="' . htmlspecialchars($photoSrc, ENT_QUOTES) . '">';
                    $close = '</div>';
                }
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
                        <?php if ($link === ''): ?><div class="fd-roster-more">View details &rarr;</div><?php endif; ?>
                    <?php endif; ?>
                <?= $close ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
// Contact-card modal chrome + styles + behavior. Emitted ONCE per request even
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
    </div>
</div>
<style>
.fd-roster-card-modal { cursor: pointer; transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease; }
.fd-roster-card-modal:hover { transform: translateY(-3px); border-color: var(--gold); box-shadow: 0 10px 30px rgba(0,0,0,.28); }
.fd-roster-card-modal:focus-visible { outline: 2px solid var(--gold); outline-offset: 3px; }
.fd-roster-more { margin-top: 12px; font-size: 12px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--gold); }
.fd-rmodal { position: fixed; inset: 0; z-index: 1200; display: none; align-items: center; justify-content: center; padding: 22px; }
.fd-rmodal.is-open { display: flex; }
.fd-rmodal-backdrop { position: absolute; inset: 0; background: rgba(6,10,20,.72); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
.fd-rmodal-card { position: relative; z-index: 1; width: 100%; max-width: 540px; max-height: 88vh; overflow-y: auto; background: var(--fd-surface, #fff); color: var(--fd-text, #1a2236); border: 1px solid var(--fd-border, #e2e6ec); border-radius: 18px; padding: 34px 32px 30px; box-shadow: 0 28px 80px rgba(0,0,0,.55); text-align: center; animation: fdRModalIn .18s ease-out; }
@keyframes fdRModalIn { from { opacity: 0; transform: translateY(10px) scale(.985); } to { opacity: 1; transform: none; } }
.fd-rmodal-close { position: absolute; top: 12px; right: 14px; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; background: none; border: none; font-size: 26px; line-height: 1; color: var(--fd-text-muted, #5b6472); cursor: pointer; border-radius: 9px; transition: background .15s, color .15s; }
.fd-rmodal-close:hover { color: var(--fd-text, #1a2236); background: rgba(127,127,127,.14); }
.fd-rmodal-avatar { width: 110px; height: 110px; border-radius: 50%; margin: 4px auto 18px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: var(--navy, #0b1120); color: var(--gold, #f0b429); font-weight: 700; font-size: 40px; line-height: 1; font-family: var(--fd-font-body, sans-serif); }
.fd-rmodal-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.fd-rmodal-name { font-size: 27px; margin: 0 0 4px; line-height: 1.15; }
.fd-rmodal-secondary { color: var(--fd-text-muted, #5b6472); font-size: 14px; margin: 0 0 8px; }
.fd-rmodal-role { text-transform: uppercase; letter-spacing: .09em; font-size: 12.5px; font-weight: 700; color: var(--gold, #f0b429); margin: 0 0 20px; }
.fd-rmodal-bio { text-align: left; font-size: 15.5px; line-height: 1.7; color: var(--fd-text, #1a2236); white-space: pre-line; border-top: 1px solid var(--fd-border, #e2e6ec); padding-top: 18px; }
@media (max-width: 520px) { .fd-rmodal-card { padding: 28px 20px 24px; } .fd-rmodal-name { font-size: 23px; } }
</style>
<script>
(function () {
    if (window.__fdRosterModalInit) { return; }
    window.__fdRosterModalInit = true;
    function ready(fn) { if (document.readyState !== 'loading') { fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }
    ready(function () {
        var modal = document.getElementById('fdRosterModal');
        if (!modal) { return; }
        var cardEl  = modal.querySelector('.fd-rmodal-card');
        var avatar  = document.getElementById('fdRModalAvatar');
        var elName  = document.getElementById('fdRModalName');
        var elSec   = document.getElementById('fdRModalSecondary');
        var elRole  = document.getElementById('fdRModalRole');
        var elBio   = document.getElementById('fdRModalBio');
        var lastTrigger = null;

        function setField(el, val) { if (val) { el.textContent = val; el.hidden = false; } else { el.textContent = ''; el.hidden = true; } }

        function open(trigger) {
            var d = trigger.dataset;
            if (d.fdImg) {
                avatar.textContent = '';
                var im = document.createElement('img');
                im.src = d.fdImg; im.alt = '';
                avatar.appendChild(im);
            } else {
                avatar.textContent = d.fdInitials || '?';
            }
            elName.textContent = d.fdName || '';
            setField(elSec, d.fdSecondary || '');
            setField(elRole, d.fdRole || '');
            setField(elBio, d.fdBio || '');
            lastTrigger = trigger;
            modal.hidden = false;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            cardEl.focus();
        }
        function close() {
            modal.classList.remove('is-open');
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            if (lastTrigger && typeof lastTrigger.focus === 'function') { lastTrigger.focus(); }
            lastTrigger = null;
        }

        document.addEventListener('click', function (e) {
            var trigger = e.target.closest ? e.target.closest('.fd-roster-card-modal') : null;
            if (trigger) { e.preventDefault(); open(trigger); return; }
            if (e.target.closest && e.target.closest('[data-fd-close]')) { close(); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) { close(); return; }
            var trigger = (e.target.closest) ? e.target.closest('.fd-roster-card-modal') : null;
            if (trigger && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); open(trigger); }
        });
        // Minimal focus trap: keep Tab within the dialog (close button is the only
        // focusable control), returning focus to the dialog card when it escapes.
        modal.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab' || !modal.classList.contains('is-open')) { return; }
            var focusables = modal.querySelectorAll('button, [href], [tabindex]:not([tabindex="-1"])');
            if (!focusables.length) { e.preventDefault(); cardEl.focus(); return; }
            var first = focusables[0], last = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        });
    });
})();
</script>
<?php endif; ?>
