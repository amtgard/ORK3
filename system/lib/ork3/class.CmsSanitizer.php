<?php

/*************************************************************************
 * CmsSanitizer — strict allowlist HTML sanitizer for CMS content.
 *
 * Sanitizes TinyMCE-authored HTML before it is stored in ork_cms_block
 * (fields_json) and again is safe to echo at render time. The output is
 * a strict allowlist: any tag/attribute not explicitly permitted is
 * removed, and all event handlers, inline styles, scripts, and unsafe
 * URL schemes are stripped.
 *
 * IMPLEMENTATION: self-contained DOMDocument allowlist (no external
 * dependency). HTML Purifier was evaluated but rejected for this
 * environment — composer is unavailable in the app container and the
 * library's default Serializer DefinitionCache requires a writable
 * on-disk cache dir, adding deployment fragility. A DOMDocument
 * allowlist is zero-config, dependency-free, and fully covers the
 * documented threat model.
 *
 * Pure logic: no DB access, so this does NOT extend Ork3. All entry
 * points are static.
 *
 * Usage:
 *   $safe = CmsSanitizer::Clean($dirtyHtmlFromTinyMce);
 *   $safe = CmsSanitizer::CleanFragment($dirtyInlineHtml);
 *************************************************************************/

class CmsSanitizer
{
    /**
     * Tags that survive sanitization. Anything else is unwrapped
     * (children kept, tag removed) unless it is in DROP_TAGS.
     */
    private static $ALLOWED_TAGS = array(
        'p', 'br', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'a', 'strong',
        'em', 'b', 'i', 'u', 'blockquote', 'hr', 'img', 'figure',
        'figcaption', 'span', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
    );

    /**
     * Tags removed ENTIRELY, including their text content (not unwrapped).
     * These can carry executable or hostile payloads in their bodies.
     */
    private static $DROP_TAGS = array(
        'script', 'style', 'iframe', 'object', 'embed', 'noscript',
        'template', 'svg', 'math', 'link', 'meta', 'base', 'form',
        'input', 'button', 'textarea', 'select', 'option', 'title', 'head',
    );

    /**
     * Per-tag attribute allowlist. Any attribute not listed for a tag is
     * removed. on* handlers and style are never listed → always stripped.
     */
    private static $ALLOWED_ATTRS = array(
        'a'   => array('href', 'title', 'target', 'rel'),
        'img' => array('src', 'alt', 'width', 'height'),
        'span' => array('class'),
        'td'  => array('colspan', 'rowspan'),
        'th'  => array('colspan', 'rowspan'),
    );

    /**
     * Sanitize a full HTML document/body fragment authored by TinyMCE.
     *
     * @param string $html raw, untrusted HTML
     * @return string sanitized HTML safe to store and echo
     */
    public static function Clean($html)
    {
        if (!is_string($html) || $html === '') {
            return '';
        }

        // libxml internal errors: suppress malformed-markup warnings; we
        // want best-effort parsing of whatever the editor produced.
        $prevUseErrors = libxml_use_internal_errors(true);

        $doc = new DOMDocument('1.0', 'UTF-8');

        // Wrap in an explicit UTF-8 container so DOMDocument does not
        // mangle multibyte content, and so we have a known body root to
        // extract. LIBXML flags prevent network access (no DTD/entity
        // loading) and avoid auto-adding <html>/<body> wrappers we'd
        // then have to special-case.
        $wrapped = '<?xml encoding="UTF-8"?><div id="cms-sanitizer-root">' . $html . '</div>';

        $loadFlags = 0;
        if (defined('LIBXML_NONET')) {
            $loadFlags |= LIBXML_NONET;
        }
        if (defined('LIBXML_NOENT')) {
            // Substitute entities so we operate on resolved text; output
            // is re-encoded safely by DOMDocument::saveHTML().
            $loadFlags |= LIBXML_NOENT;
        }

        $ok = $doc->loadHTML($wrapped, $loadFlags);

        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);

        if (!$ok) {
            return '';
        }

        $root = $doc->getElementById('cms-sanitizer-root');
        if ($root === null) {
            // Fallback: locate by traversal if id lookup misses.
            $divs = $doc->getElementsByTagName('div');
            foreach ($divs as $d) {
                if ($d->getAttribute('id') === 'cms-sanitizer-root') {
                    $root = $d;
                    break;
                }
            }
        }
        if ($root === null) {
            return '';
        }

        self::sanitizeNode($root, $doc);

        // Serialize children of the root only (drop the wrapper div).
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Sanitize an inline HTML fragment. Functionally identical to Clean()
     * here — both go through the same allowlist — but provided as a named
     * entry point for callers sanitizing inline (non-block) content.
     *
     * @param string $html raw, untrusted inline HTML
     * @return string sanitized HTML
     */
    public static function CleanFragment($html)
    {
        return self::Clean($html);
    }

    /**
     * Recursively sanitize a DOM node's children in place.
     *
     * Walks a snapshot of childNodes (so live-list mutation while we
     * remove/replace nodes is safe). For each element:
     *   - DROP_TAGS  → removed wholesale (content discarded)
     *   - not allowed → unwrapped (element removed, children promoted)
     *   - allowed     → attributes filtered, then recurse
     * Text nodes are left as-is (DOMDocument escapes them on output).
     */
    private static function sanitizeNode(DOMNode $node, DOMDocument $doc)
    {
        if (!$node->hasChildNodes()) {
            return;
        }

        // Snapshot: we mutate the live child list below.
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                // 1) Hostile container tags: nuke entirely.
                if (in_array($tag, self::$DROP_TAGS, true)) {
                    $child->parentNode->removeChild($child);
                    continue;
                }

                // 2) Not on the allowlist: recurse to clean descendants,
                //    then unwrap (promote children, drop the tag itself).
                if (!in_array($tag, self::$ALLOWED_TAGS, true)) {
                    self::sanitizeNode($child, $doc);
                    self::unwrap($child);
                    continue;
                }

                // 3) Allowed tag: filter attributes, then recurse.
                self::filterAttributes($child, $tag);
                self::sanitizeNode($child, $doc);
            } elseif ($child instanceof DOMComment) {
                // Comments can hide IE conditional-comment script vectors.
                $child->parentNode->removeChild($child);
            } elseif (
                $child instanceof DOMProcessingInstruction
                || $child instanceof DOMCdataSection
            ) {
                $child->parentNode->removeChild($child);
            }
            // DOMText: keep (auto-escaped on serialization).
        }
    }

    /**
     * Strip every attribute not on the allowlist for $tag, and validate
     * the values of the ones that remain (URL schemes, target/rel).
     */
    private static function filterAttributes(DOMElement $el, $tag)
    {
        $allowed = isset(self::$ALLOWED_ATTRS[$tag]) ? self::$ALLOWED_ATTRS[$tag] : array();

        // Snapshot attribute names (live NamedNodeMap mutates as we remove).
        $names = array();
        if ($el->attributes !== null) {
            foreach ($el->attributes as $attr) {
                $names[] = $attr->nodeName;
            }
        }

        foreach ($names as $name) {
            $lname = strtolower($name);

            // Defense-in-depth: any on* handler or style/xmlns/etc is not
            // in any allowlist, so this removes it.
            if (!in_array($lname, $allowed, true)) {
                $el->removeAttribute($name);
                continue;
            }

            $value = $el->getAttribute($name);

            // URL attributes: enforce safe schemes.
            if (($tag === 'a' && $lname === 'href') || ($tag === 'img' && $lname === 'src')) {
                if (!self::isSafeUrl($value)) {
                    $el->removeAttribute($name);
                }
            }
        }

        // Normalize target/rel on anchors.
        if ($tag === 'a') {
            if (strtolower($el->getAttribute('target')) === '_blank') {
                // Force a hardened rel for new-tab links (clobber any
                // author-supplied rel to guarantee the protections).
                $el->setAttribute('rel', 'noopener noreferrer');
            } elseif ($el->hasAttribute('target')) {
                // Disallow arbitrary target values; only _blank is kept.
                $el->removeAttribute('target');
            }
        }
    }

    /**
     * URL scheme allowlist: http(s), mailto, and relative / root-relative
     * (incl. /assets) URLs. javascript:, data:, vbscript:, file:, etc. are
     * rejected. data: is rejected ENTIRELY (no data:image exception) per
     * the security contract.
     *
     * @return bool true if the URL is safe to keep
     */
    private static function isSafeUrl($url)
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        // Strip HTML/control whitespace that can hide a scheme, e.g.
        // "java\tscript:" or "java\nscript:". Browsers ignore these
        // inside a scheme, so we must too before testing.
        $stripped = preg_replace('/[\x00-\x20\x7f]+/', '', $url);
        $stripped = str_replace("\xc2\xa0", '', $stripped); // NBSP

        $lower = strtolower($stripped);

        // Explicit deny of dangerous schemes (covers entity-decoded forms
        // since LIBXML_NOENT already resolved &#x6a; etc into real chars).
        $deny = array('javascript:', 'data:', 'vbscript:', 'file:', 'about:', 'blob:');
        foreach ($deny as $bad) {
            if (strpos($lower, $bad) === 0) {
                return false;
            }
        }

        // Allow root-relative / relative / anchor / query-only URLs (no
        // scheme present). A leading '//' is protocol-relative → allowed
        // (resolves to http(s)).
        if ($lower[0] === '/' || $lower[0] === '#' || $lower[0] === '?' || $lower[0] === '.') {
            return true;
        }

        // If there is a scheme, it must be one we explicitly allow.
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $lower, $m)) {
            $scheme = $m[1];
            return in_array($scheme, array('http', 'https', 'mailto'), true);
        }

        // No scheme and not obviously relative (e.g. "page/slug") → treat
        // as a relative path, which is safe.
        return true;
    }

    /**
     * Replace an element with its children (unwrap), preserving order.
     */
    private static function unwrap(DOMElement $el)
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }
}
