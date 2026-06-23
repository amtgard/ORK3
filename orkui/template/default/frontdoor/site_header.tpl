<?php
/**
 * Partial: site_header.tpl  (PLAIN PHP — extract()+include; never Smarty)
 *
 * SITE CHROME — the public navigation bar shared by every front-end page that
 * belongs to the Amtgard site (CMS pages via Page_view, blog index/post). The
 * nav is a property of the SITE, not of any single page, so it is rendered here
 * once as chrome rather than authored into each page's block list.
 *
 * It reuses frontdoor/blocks/marketing_nav.tpl, which is the single renderer of
 * the marketing nav. That partial sources its links from the editable CMS nav
 * store (ork_cms_nav_item, the 'marketing' menu); this header only supplies the
 * site-level logo / CTA / login chrome (kept in parity with the home page's nav
 * block in model.FrontDoor.php so the bar looks identical site-wide).
 *
 * FUTURE — per-site scoping: today there is exactly one site (Amtgard, global
 * scope), so marketing_nav.tpl resolves GetMenu('marketing', 'global', 0). When
 * the CMS gains kingdom/park sites (the ork_cms_* tables already carry
 * scope_type/scope_id), the owning controller will resolve the current site and
 * pass $siteScopeType / $siteScopeId through here; marketing_nav's GetMenu call
 * becomes scope-aware and the logo/CTA/login below come from per-site settings.
 * The render path does not change — only the menu/settings resolver gains scope.
 */
$siteLogoBase = HTTP_TEMPLATE . 'default/img/frontdoor/';
$siteUir      = defined('UIR') ? UIR : 'index.php?Route=';
$blockFields = [
    'logo'  => ['key' => 'logo', 'src' => $siteLogoBase . 'amtgard-logo.png', 'alt' => 'Amtgard'],
    // Find a Chapter -> the chapter directory (Atlas); Record Keeper -> the ORK
    // proper (the Kingdoms Directory). Internal routes resolve through UIR.
    'cta'   => ['label' => 'Find a Chapter', 'href' => $siteUir . 'Atlas'],
    'login' => ['label' => 'Record Keeper', 'href' => $siteUir . 'Directory'],
];
include DIR_TEMPLATE . 'default/frontdoor/blocks/marketing_nav.tpl';
