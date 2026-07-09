<?php
/*
 * frontdoor/_helpers.tpl — shared PLAIN-PHP helpers for the front-door, blog,
 * and org-site templates (extract()+include; never Smarty).
 *
 * Included (function_exists-guarded, so it is safe to include more than once per
 * request) wherever a raw DB date needs human-readable display formatting.
 * render_blocks.tpl includes it once up front so every block partial can rely on
 * it; the standalone blog page templates include it themselves because they
 * format dates OUTSIDE the block-render loop.
 */
if (!function_exists('fdFormatDate')) {
    /**
     * Format a raw date/datetime string for human-readable display.
     *
     * Guards bad input: returns '' when $raw is empty or unparseable (never a
     * raw ISO string or a bogus 1970 epoch), so callers can test the result
     * for '' and suppress the label. Replaces the strtotime()+date() idiom that
     * was copy-pasted across the blog + front-door date sites.
     *
     * @param mixed  $raw a date string (e.g. a DB "Y-m-d H:i:s")
     * @param string $fmt a PHP date() format (e.g. 'M j, Y')
     * @return string the formatted date, or '' on empty/invalid input
     */
    function fdFormatDate($raw, $fmt)
    {
        $raw = (string) $raw;
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        return $ts !== false ? date($fmt, $ts) : '';
    }
}
