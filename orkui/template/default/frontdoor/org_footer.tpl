<?php
/*
 * org_footer.tpl — subtle "Part of the Amtgard ORK" tie-back for a standalone
 * org site. PLAIN PHP. Deliberately the ONLY global-ORK affordance on an org
 * site (no top bar / member bar); links back to the ORK app root (UIR).
 */
$uir = defined('UIR') ? UIR : '';
?>
<footer class="org-footer">
    <a class="org-footer-tie" href="<?= htmlspecialchars($uir, ENT_QUOTES) ?>">
        Part of the Amtgard ORK <i class="fas fa-external-link-alt" aria-hidden="true"></i>
    </a>
</footer>
