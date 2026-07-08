<?php

/**
 * Seed exemplar blog post(s) to demonstrate the CMS blog. Idempotent: deletes
 * any existing post with the same slug, then recreates it published at global
 * scope with its body blocks and tags. Run once:
 *   docker exec ork3-php8-app php /var/www/ork.amtgard.com/db-migrations/2026-06-23-cms-seed-blog.php
 *
 * Author is resolved by persona at run time (falls back to the lowest mundane
 * id, then NULL) so the post is portable across testers' databases.
 */
// Web-reachable file: refuse any non-CLI (HTTP) invocation.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
chdir('/var/www/ork.amtgard.com/orkui');
define('DONOTWEBSERVICE', true);
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost:19080';
}
ob_start();
require('/var/www/ork.amtgard.com/startup.php');
ob_end_clean();
if (!defined('UIR')) {
    define('UIR', '/orkui/index.php?Route=');
}

global $DB;

$posts = new CmsPost();
$pages = new CmsPage(); // shared polymorphic block store (owner_type='post')
$now   = date('Y-m-d H:i:s');
$by    = 1; // seed actor (super-admin)

$clean = function ($html) {
    return class_exists('CmsSanitizer') ? CmsSanitizer::Clean($html) : $html;
};

// ---------------------------------------------------------------------------
// Resolve a portable author id: prefer the example persona, then the lowest
// mundane id, else NULL (the blog renders no byline when author is absent).
// ---------------------------------------------------------------------------
$resolveAuthor = function () use ($DB) {
    $DB->Clear();
    $DB->p = 'Tobias of Heraldsbridge';
    $row = $DB->DataSet('SELECT mundane_id FROM ' . DB_PREFIX . 'mundane WHERE persona = :p LIMIT 1');
    if ($row && $row->Next() && (int) $row->mundane_id > 0) {
        return (int) $row->mundane_id;
    }
    $DB->Clear();
    $row = $DB->DataSet('SELECT mundane_id FROM ' . DB_PREFIX . 'mundane ORDER BY mundane_id LIMIT 1');
    if ($row && $row->Next() && (int) $row->mundane_id > 0) {
        return (int) $row->mundane_id;
    }
    return null;
};
$authorId = $resolveAuthor();

// ---------------------------------------------------------------------------
// Post definitions: [post-meta, tags[], body blocks[]].
// ---------------------------------------------------------------------------
$defs = array();

$defs[] = array(
    'post' => array(
        'slug'    => 'new-rules-of-play',
        'title'   => 'New Rules of Play',
        'excerpt' => 'Just announced: the latest ROP document is here!',
    ),
    'tags'   => array('rules', 'documents'),
    'blocks' => array(
        array('type' => 'rich_text', 'fields' => array(
            'align' => 'left',
            'cta'   => array(),
            'body'  => $clean(
                '<p>Amtgard is proud to announce the latest version of the Rules of Play is here!</p>'
                . '<blockquote><p>"This is our best ruleset yet." Tobias of Heraldsbridge</p></blockquote>'
            ),
        )),
    ),
);

// ---------------------------------------------------------------------------
// Insert (idempotent: drop existing same-slug post first).
// ---------------------------------------------------------------------------
$report = array();
foreach ($defs as $def) {
    $slug = $def['post']['slug'];

    $existing = $posts->GetPostBySlug($slug, 'global', 0, false);
    if (!empty($existing) && !empty($existing['post_id'])) {
        $posts->DeletePost((int) $existing['post_id']); // fresh re-seed
    }

    $data = array_merge($def['post'], array(
        'status'       => 'published',
        'published_at' => $now,
        'author_id'    => $authorId,
        'scope_type'   => 'global',
        'scope_id'     => 0,
        'created_by'   => $by,
        'created_at'   => $now,
        'updated_by'   => $by,
        'updated_at'   => $now,
    ));
    $pid = (int) $posts->CreatePost($data);
    if ($pid <= 0) {
        $report[] = "$slug: FAILED to create";
        continue;
    }
    if (method_exists($posts, 'SetStatus')) {
        $posts->SetStatus($pid, 'published', $by);
    }

    $blocks = array();
    $i = 0;
    foreach ($def['blocks'] as $b) {
        $blocks[] = array(
            'type'    => $b['type'],
            'enabled' => 1,
            'order'   => $i++,
            'source'  => 'authored',
            'fields'  => $b['fields'],
        );
    }
    $n = (int) $pages->ReplaceBlocks('post', $pid, $blocks);
    $posts->SetTags($pid, $def['tags']);

    $report[] = "$slug: post_id=$pid, blocks=$n, tags=" . implode('+', $def['tags']);
}

echo json_encode(array(
    'author_id' => $authorId,
    'posts'     => $report,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
