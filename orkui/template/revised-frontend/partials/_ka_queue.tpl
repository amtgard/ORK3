<?php
/* -----------------------------------------------------------------------
   Admin-console work-queue card.

   Extracted from Admin_kingdom.tpl so the Park console renders the SAME card
   rather than a near-copy that drifts. The function body below is the kingdom
   version unchanged; the only addition is the optional $unknown argument,
   which no kingdom call passes.

   Include this partial once, before the first _ka_queue() call. The
   function_exists() guard makes a second include a no-op.
   ----------------------------------------------------------------------- */
if (!function_exists('_ka_queue')) {
    /**
     * One queue card. A zero is not a task, so it renders muted with a tick instead
     * of a number the reader has to interpret, and stops being a link.
     *
     * $unknown is a THIRD state, distinct from both. It is for a queue whose count
     * could not be computed at all -- a park with no attendance rows ever has no
     * "days since the last signin", and rendering that as 0 would claim someone
     * signed in today. Pass the sentence to show; the card renders muted with an
     * em dash instead of a tick, and $count / $clear are ignored.
     *
     * @param int|string $count
     * @param string     $icon    FontAwesome class for the card icon
     * @param string     $label   headline when there IS work
     * @param string     $sub     one-line detail under the headline, work state only
     * @param string     $clear   headline when the count is zero
     * @param string     $href    destination when there is work ('#' for none)
     * @param string     $onclick JS to run instead of navigating (wins over $href)
     * @param string     $unknown headline for the unknown state; '' = not unknown
     */
    function _ka_queue($count, $icon, $label, $sub, $clear, $href, $onclick = '', $unknown = '')
    {
        $count = (int)$count;
        // Linkable only when there is somewhere to go: without permission to manage
        // officers the vacancy card has no destination, so it stays a plain card
        // rather than an anchor to '#'.
        $isUnknown = ($unknown !== '');
        $open  = !$isUnknown && $count > 0;
        $link  = $open && ($onclick !== '' || $href !== '#');
        $attrs = $link
            ? ($onclick !== ''
                ? 'href="#" onclick="' . htmlspecialchars($onclick) . '; return false;"'
                : 'href="' . htmlspecialchars($href) . '"')
            : '';
        $tag = $link ? 'a' : 'div';
        $mod = $isUnknown ? ' ka-ts-card-unknown' : ($open ? '' : ' ka-ts-card-clear');
        if ($isUnknown) {
            $value = '&mdash;';
        } elseif ($open) {
            $value = number_format($count);
        } else {
            $value = '<i class="fas fa-check"></i>';
        }
        echo '<' . $tag . ' class="ka-ts-card' . ($link ? ' ka-ts-card-link' : '') . $mod . '" ' . $attrs . '>'
            . '<div class="ka-ts-icon"><i class="fas ' . $icon . '"></i></div>'
            . '<div class="ka-ts-body">'
            . '<div class="ka-ts-num"><span class="ka-ts-val">'
            . $value
            . '</span></div>'
            . '<div class="ka-ts-lbl">' . htmlspecialchars($isUnknown ? $unknown : ($open ? $label : $clear)) . '</div>'
            . ($open ? '<div class="ka-ts-sub">' . htmlspecialchars($sub) . '</div>' : '')
            . '</div></' . $tag . '>';
    }
}
