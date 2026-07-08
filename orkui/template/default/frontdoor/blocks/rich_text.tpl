<?php
/*
 * Partial: rich_text.tpl — CMS alias of richtext.tpl.
 *
 * A block typed 'rich_text' (the CMS editor's block) renders IDENTICALLY to the
 * shipped 'richtext' block, so this is a thin include rather than a duplicated
 * copy — one place to change the rich-text rendering. $blockFields (+ shared
 * $data / $blockMeta) are already in scope and pass straight through.
 *
 * body is sanitized AUTHORITATIVELY at the storage choke point every writer
 * passes through — CmsPage::ReplaceBlocks (via CmsSanitizer::Clean) — NOT at
 * render time. So the stored HTML is already clean and is emitted raw here (see
 * richtext.tpl); there is deliberately no re-sanitize in this partial.
 */
include __DIR__ . '/richtext.tpl';
