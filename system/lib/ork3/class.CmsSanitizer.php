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
     * Hard input byte budget (256 KB). TinyMCE-authored block HTML is far
     * smaller than this in practice; anything larger is truncated BEFORE the DOM
     * parse so an authenticated author can't ship a multi-megabyte payload that
     * pins CPU/memory in DOMDocument::loadHTML + the recursive allowlist walk.
     */
    private static $MAX_INPUT_BYTES = 262144; // 256 * 1024

    /**
     * Recursion-depth ceiling for _sanitizeNode(). Beyond this the subtree is
     * dropped wholesale — bounds the deeply-nested-markup DoS (each level costs a
     * recursion frame + an iterator_to_array snapshot). Legit editor content
     * (nested lists/tables) never approaches this.
     */
    private static $MAX_DEPTH = 60;

    /**
     * Total node budget across the whole walk. A backstop to the byte cap:
     * once exhausted the remaining siblings are dropped and the walk bails, so a
     * pathological wide/flat payload can't blow the work budget.
     */
    private static $MAX_NODES = 20000;

    /**
     * Tags that survive sanitization. Anything else is unwrapped
     * (children kept, tag removed) unless it is in DROP_TAGS.
     */
    private static $ALLOWED_TAGS = array(
        'p', 'br', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'a', 'strong',
        'em', 'b', 'i', 'u', 's', 'sub', 'sup', 'blockquote', 'hr', 'img',
        'figure', 'figcaption', 'span', 'table', 'caption', 'colgroup',
        'col', 'thead', 'tbody', 'tr', 'th', 'td',
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
        'th'  => array('colspan', 'rowspan', 'scope'),
        'col' => array('span'),
        'colgroup' => array('span'),
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

        // Bound the parse cost. Truncate oversize input before the DOM
        // parse — the allowlist walk is already robust to malformed markup, so a
        // mid-tag cut is harmless, and this caps the DOMDocument work up front.
        if (strlen($html) > self::$MAX_INPUT_BYTES) {
            $html = substr($html, 0, self::$MAX_INPUT_BYTES);
        }

        // libxml internal errors: suppress malformed-markup warnings; we
        // want best-effort parsing of whatever the editor produced.
        $prevUseErrors = libxml_use_internal_errors(true);

        $doc = new DOMDocument('1.0', 'UTF-8');

        // Wrap in an explicit UTF-8 container so DOMDocument does not
        // mangle multibyte content, and so we have a known body root to
        // extract.
        $wrapped = '<?xml encoding="UTF-8"?><div id="cms-sanitizer-root">' . $html . '</div>';

        // LIBXML_NONET blocks network-fetched DTDs/entities. We deliberately
        // do NOT set LIBXML_NOENT: that flag SUBSTITUTES entities at parse
        // time, which on hostile input enables XXE / internal-entity
        // expansion (e.g. an injected <!DOCTYPE> with a SYSTEM "file://"
        // entity). loadHTML already decodes ordinary HTML character
        // references (&#x6a; etc.) natively without it.
        $loadFlags = 0;
        if (defined('LIBXML_NONET')) {
            $loadFlags |= LIBXML_NONET;
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

        // An unbalanced closing tag in author markup (e.g. a stray </div> typed
        // into the raw_html block's HTML textarea) closes the wrapper early, so
        // everything after it is re-parented as a SIBLING of the wrapper and
        // would be dropped silently. Unwrap the wrapper into <body> and walk the
        // body instead, which keeps that trailing content in document order.
        $body = $root->parentNode;
        if ($body instanceof DOMElement && strtolower($body->nodeName) === 'body') {
            while ($root->firstChild !== null) {
                $body->insertBefore($root->firstChild, $root);
            }
            $body->removeChild($root);
            $root = $body;
        }

        // A shared node budget threaded through the recursive walk.
        $budget = array('nodes' => self::$MAX_NODES);
        self::_sanitizeNode($root, $doc, 0, $budget);

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
     *
     * $depth and the shared &$budget bound the walk against an
     * authenticated deep-nesting / node-flood DoS — past MAX_DEPTH the subtree is
     * dropped, and once the node budget is exhausted the remaining siblings are
     * dropped and the walk bails.
     */
    private static function _sanitizeNode(DOMNode $node, DOMDocument $doc, $depth = 0, &$budget = null)
    {
        if ($budget === null) {
            $budget = array('nodes' => self::$MAX_NODES);
        }
        if (!$node->hasChildNodes()) {
            return;
        }

        // Depth budget: beyond the ceiling, drop the entire subtree rather than
        // recurse deeper (bounds the recursion-frame + snapshot cost).
        if ($depth > self::$MAX_DEPTH) {
            foreach (iterator_to_array($node->childNodes) as $deep) {
                $node->removeChild($deep);
            }
            return;
        }

        // Snapshot: we mutate the live child list below.
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            // Node budget: once exhausted, drop every remaining sibling and bail.
            if ($budget['nodes'] <= 0) {
                if ($child->parentNode !== null) {
                    $child->parentNode->removeChild($child);
                }
                continue;
            }
            $budget['nodes']--;

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
                    self::_sanitizeNode($child, $doc, $depth + 1, $budget);
                    self::_unwrap($child);
                    continue;
                }

                // 3) Allowed tag: filter attributes, then recurse.
                self::_filterAttributes($child, $tag);
                self::_sanitizeNode($child, $doc, $depth + 1, $budget);
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
    private static function _filterAttributes(DOMElement $el, $tag)
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
                if (!self::IsSafeUrl($value)) {
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
     * the security contract. Protocol-relative (//) is also rejected.
     *
     * Public so controllers can call CmsSanitizer::IsSafeUrl() at the trust
     * boundary before persisting user-supplied URLs. Also called internally
     * from _filterAttributes().
     *
     * @return bool true if the URL is safe to keep / store
     */
    public static function IsSafeUrl($url)
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        // Percent-decode ONCE (matching a browser's single decode of an href)
        // BEFORE stripping + scheme checks. Without this, an encoded scheme like
        // "%6aavascript:" (%6a = 'j') or an encoded control char like
        // "java%09script:" survives every check and returns true, yet the browser
        // decodes it on click and executes javascript:. Decoding first, then
        // stripping controls, collapses both back to the literal "javascript:".
        $url = rawurldecode($url);

        // Strip ALL Unicode whitespace/control characters that can hide a
        // scheme, e.g. "java\tscript:" or scheme prefixed with U+2000.
        // Using \pZ (separators) + \pC (other controls, incl. \x00-\x1f,
        // \x7f, NBSP U+00A0, etc.) with the /u flag covers every bypass
        // that ASCII-only stripping misses.
        $stripped = preg_replace('/[\pZ\pC]+/u', '', $url);

        // preg_replace returns null on malformed UTF-8 (PREG_BAD_UTF8_ERROR);
        // treating that as an empty/"safe" URL would let invalid-encoding
        // payloads bypass the scheme checks below. Fail closed.
        if ($stripped === null || $stripped === '' || preg_last_error() !== PREG_NO_ERROR) {
            return false;
        }

        $lower = strtolower($stripped);

        // Explicit deny of dangerous schemes (covers entity-decoded forms
        // such as &#x6a;avascript: which the HTML parser resolves to real
        // characters before this check runs).
        $deny = array('javascript:', 'data:', 'vbscript:', 'file:', 'about:', 'blob:');
        foreach ($deny as $bad) {
            if (strpos($lower, $bad) === 0) {
                return false;
            }
        }

        // Browsers normalize backslashes to forward slashes when resolving a
        // URL under a special scheme, so "\\host", "\/host" and "/\host" all
        // resolve exactly like the protocol-relative "//host". Normalize here
        // so the checks below see the same string the browser will.
        $lower = str_replace('\\', '/', $lower);

        // Protocol-relative URLs (leading "//") are NOT allowed: they resolve
        // to an attacker-controlled host in http and https contexts alike.
        if (strlen($lower) >= 2 && $lower[0] === '/' && $lower[1] === '/') {
            return false;
        }

        // Allow root-relative (/) relative (.) anchor (#) or query-only (?)
        // paths — none of these contain a scheme so they are safe.
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
     * Front-door CTA/link href guard: return $href when it passes IsSafeUrl(),
     * otherwise the inert '#'. Centralizes the ternary that block partials
     * (richtext, steps, cta_band, card_grid, hero_carousel, marketing_nav, …)
     * previously copy-pasted, so the safe-href policy lives in one place.
     *
     * @param mixed $href author-supplied URL (may be null/empty)
     * @return string the href if safe, else '#'
     */
    public static function SafeHrefOrHash($href)
    {
        $href = (string) $href;
        return ($href !== '' && self::IsSafeUrl($href)) ? $href : '#';
    }

    /**
     * Escape a string for an XML text node or attribute value.
     *
     * XML, NOT HTML5: the ENT_XML1 flag is what makes this correct for the RSS
     * feeds and the sitemap. HTML5 escaping would emit named entities (e.g.
     * &apos; handling and the wider HTML5 entity set) that are not defined in
     * plain XML, producing a document a strict parser rejects.
     *
     * Lives here — on the DB-free utility class both the lib and controller
     * layers already load — so CmsPost's RSS builder and Controller_Site's
     * sitemap escape identically instead of each carrying a private copy.
     *
     * @param mixed $text
     * @return string
     */
    public static function XmlEscape($text)
    {
        return htmlspecialchars((string)$text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Render author-written markdown to HTML that is safe to inject into a page.
     *
     * The policy — Parsedown safe mode (raw HTML escaped, unsafe URL schemes
     * refused), breaks enabled (officers type single newlines and expect them
     * to survive), plus a post-pass that drops <img> so authored content can't
     * pull a remote image into someone else's page — is a SANITIZATION
     * decision, so it belongs on this class rather than being redeclared as a
     * render-time global by each template that happens to need it.
     *
     * Returns '' when Parsedown is unavailable: emitting the raw author string
     * instead would be the one failure mode this function exists to prevent.
     *
     * @param mixed $text raw markdown as the author wrote it
     * @return string sanitized HTML
     */
    public static function SafeMarkdown($text)
    {
        $text = (string)$text;
        if ($text === '') {
            return '';
        }
        if (!class_exists('Parsedown')) {
            if (!defined('DIR_LIB') || !is_file(DIR_LIB . 'Parsedown.php')) {
                return '';
            }
            require_once DIR_LIB . 'Parsedown.php';
        }
        $clean = str_replace(array('<br />', '<br/>', '<br>'), "\n", $text);
        $html  = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($clean);
        return preg_replace('/<img[^>]*>/i', '', $html);
    }

    /**
     * Replace an element with its children (unwrap), preserving order.
     */
    private static function _unwrap(DOMElement $el)
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
