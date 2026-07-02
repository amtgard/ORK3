<?php
/*
 * Partial: rich_text.tpl — CMS alias of richtext.tpl.
 *
 * A block typed 'rich_text' (the CMS editor's block) renders IDENTICALLY to the
 * shipped 'richtext' block, so this is a thin include rather than a duplicated
 * copy — one place to change the rich-text rendering. $blockFields (+ shared
 * $data / $blockMeta) are already in scope and pass straight through.
 *
 * body is sanitized server-side at save (CmsSanitizer / HTML Purifier) and
 * emitted raw here — see richtext.tpl.
 */
include __DIR__ . '/richtext.tpl';
