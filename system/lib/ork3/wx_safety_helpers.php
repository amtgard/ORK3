<?php

/**
 * Weather safety helpers — the badge itself is the click target for
 * Extreme heat / Frostbite risk labels; clicking opens a modal (wired in
 * default.theme) with authoritative safety links.
 *
 * Loaded via startup.php's DIR_ORK3 autoload sweep so both the theme AND
 * the content templates (Park/Event/Weather pages) can call these before
 * the theme runs (framework renders content templates before the theme —
 * see system/lib/system/class.View.php:97-119).
 *
 * Two helpers because the click affordance and the visible ⓘ icon are
 * added at different points in the badge markup:
 *   - wx_safety_attrs()      → attributes on the badge element itself
 *   - wx_safety_icon_html()  → the small ⓘ shown inline with the label
 */

if (!function_exists('_wx_safety_kind')) {
    function _wx_safety_kind($label)
    {
        if ($label === 'Extreme heat') {
            return 'heat';
        }
        if ($label === 'Frostbite risk') {
            return 'cold';
        }
        return null;
    }
}

if (!function_exists('wx_safety_attrs')) {
    // Click + a11y attributes to splice onto the opening tag of a badge
    // element. Leading space so it composes cleanly right after another
    // attribute. Empty string when the label isn't heat/cold.
    //
    // Notably: no inline style — the cursor:pointer comes from a single
    // CSS rule targeting [data-wx-safety] in default.theme. Badges often
    // already carry their own inline style, and a second style attribute
    // would be silently discarded by the browser.
    function wx_safety_attrs($label)
    {
        $kind = _wx_safety_kind($label);
        if ($kind === null) {
            return '';
        }
        return ' onclick="wxSafetyDialog(\'' . $kind . '\')"'
             . ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();wxSafetyDialog(\'' . $kind . '\');}"'
             . ' role="button" tabindex="0" data-wx-safety="' . $kind . '"';
    }
}

if (!function_exists('wx_safety_icon_html')) {
    // The tiny ⓘ that renders inline with the label text. Callers only
    // want this on labeled badges — icon-only badge sites should NOT call
    // this (no room for it next to the emoji). Empty for non-heat/cold.
    function wx_safety_icon_html($label)
    {
        if (_wx_safety_kind($label) === null) {
            return '';
        }
        return ' <i class="fas fa-info-circle" style="margin-left:3px;opacity:0.8;font-size:0.9em"></i>';
    }
}
