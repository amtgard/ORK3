<?php
/*
 * Partial: richtext.tpl — legacy DB alias of rich_text.tpl.
 *
 * 'rich_text' is the canonical block type (script/cms-block-editor.js);
 * 'richtext' is the older stored type name, still present on shipped/seeded
 * pages. It renders IDENTICALLY, so this is a thin include rather than a
 * duplicated copy — one place to change the rich-text rendering. $blockFields
 * (+ shared $data / $blockMeta) are already in scope and pass straight through.
 */
include __DIR__ . '/rich_text.tpl';
