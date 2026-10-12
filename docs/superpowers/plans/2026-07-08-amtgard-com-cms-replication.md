# amtgard.com → CMS Replication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replicate the content of www.amtgard.com's 15 nav pages into the CMS as published global-scope pages with re-hosted images, replicated navigation, and local render verification.

**Architecture:** Extract-then-seed. ~15 parallel agents each extract one page into a JSON spec + download its assets to a staging dir (no DB writes). The orchestrator then runs one CLI seed script that uploads assets via `CmsMedia::Upload()`, creates pages via `CmsPage::CreatePage`/`ReplaceBlocks` (parents-first for the hierarchy), and relinks the existing marketing nav menu to the new pages.

**Tech Stack:** PHP 8 CLI seed scripts (pattern: `db-migrations/2026-06-23-cms-seed-exemplars.php`), MariaDB, Docker (`ork3-php8-app` container, app at localhost:19080), Chrome MCP for verification.

## Global Constraints

- All content targets `scope_type='global', scope_id=0` (the org-wide front door).
- Build against `HTTP_HOST=localhost:19080`; store media `src` as **root-relative** (`/assets/…`) so content is host-agnostic (matches exemplar `$IMG` style).
- Seed author / media `uploaded_by` = mundane_id `1` (super-admin, per exemplar `$by = 1`).
- Seeds are CLI-only (`if (PHP_SAPI !== 'cli') exit`) and idempotent (delete-by-slug before create; media dedup by deterministic filename; nav relink matches by label).
- Reserved slugs forbidden: `blog`, `post`, `p`, `k` (none of our slugs collide).
- Block field shapes follow `orkui/controller/controller.Cms.php::_starter()` `$defaults` (authoritative). Block `type` allowlist: `controller.CmsAjax.php:45-52`.
- Allowed block types: `hero_carousel`, `rich_text`, `heading`, `card_grid`, `cta_band`, `steps`, `staff_roster`, `accordion`, `quote`, `table`, `gallery`, `photo_mosaic`, `image`, `video_embed`, `file_download`, `columns`, `divider`, `spacer`, `raw_html`. No dynamic blocks (static replication).
- Page `type` is inert at render (author metadata only) — pick a sensible preset (`composed`/`article`/`media`/`resource`).
- Run any seed with: `docker exec ork3-php8-app php /var/www/ork.amtgard.com/db-migrations/<file>.php`
- DB CLI is **mariadb**: `docker exec -i ork3-php8-db mariadb -u root -proot ork`
- Never `git add -A`; stage files explicitly. Do not stage `class.Authorization.php`.

### Page inventory (slug → parent, source URL)

| slug | parent | type | source |
|---|---|---|---|
| `about` | — | composed | https://www.amtgard.com/about |
| `mission` | about | article | https://www.amtgard.com/mission |
| `staff` | about | composed | https://www.amtgard.com/staff |
| `volunteers` | about | article | https://www.amtgard.com/volunteers |
| `join` | — | composed | https://www.amtgard.com/join |
| `learn-the-basics` | join | composed | https://www.amtgard.com/learn-the-basics |
| `start-a-chapter` | join | composed | https://www.amtgard.com/start-a-chapter |
| `programs` | — | composed | https://www.amtgard.com/programs |
| `foodfight` | programs | composed | https://www.amtgard.com/foodfight |
| `olympiad` | programs | composed | https://www.amtgard.com/olympiad |
| `media` | — | media | https://www.amtgard.com/media |
| `galleries` | media | media | https://www.amtgard.com/galleries |
| `writing` | media | article | https://www.amtgard.com/writing |
| `resources` | — | resource | https://www.amtgard.com/resources |
| `documents` | resources | resource | https://www.amtgard.com/documents |

Staging root: `/private/tmp/claude-501/-Users-averykrouse-GitHub-ORK-tobias-ORK3-tobias/9dbdb1e9-ff20-41ce-b213-ba54187c26a0/scratchpad/amtgard-clone/`
Container path to same staging (mounted?): assets are read by the seed via a path passed as `argv[1]`; if the scratchpad is not mounted into the container, the seed's asset step copies staging into `db-migrations/.amtgard-assets/` first (Task 3, Step 2).

---

### Task 1: Extract all 15 pages (parallel agents → JSON specs + assets)

**Files:**
- Create: `…/scratchpad/amtgard-clone/specs/<slug>.json` (×15)
- Create: `…/scratchpad/amtgard-clone/assets/<slug>/*` (downloaded images/PDFs)
- Create: `…/scratchpad/amtgard-clone/validate.php` (schema/asset validator)

**Interfaces:**
- Produces: one spec JSON per slug, shape below. Consumed by Task 3.

**Spec JSON shape (the contract every extraction agent fills):**
```json
{
  "slug": "learn-the-basics",
  "type": "composed",
  "parent_slug": "join",
  "title": "Learn the Basics",
  "meta_description": "<=155 chars",
  "blocks": [
    { "type": "rich_text", "fields": { "kicker":"", "heading":"…", "body":"<p>…</p>", "align":"left" } },
    { "type": "gallery", "fields": { "columns": 3, "caption": "" },
      "assets": { "images": [ { "file": "1.jpg", "alt": "…" } ] } },
    { "type": "file_download", "fields": { "files": [] },
      "assets": { "files": [ { "file": "corpora.pdf", "label": "Corpora", "name": "corpora.pdf" } ] } }
  ]
}
```
Rules: media lives ONLY in `assets` (filenames relative to `assets/<slug>/`), never in `fields`. Block order = array order. `body` HTML is clean semantic HTML (`<p><ul><li><a><strong><em><h3>`). Omit empty optional fields.

- [ ] **Step 1: Create staging dirs**

```bash
STG=/private/tmp/claude-501/-Users-averykrouse-GitHub-ORK-tobias-ORK3-tobias/9dbdb1e9-ff20-41ce-b213-ba54187c26a0/scratchpad/amtgard-clone
mkdir -p "$STG/specs" "$STG/assets"
for s in about mission staff volunteers join learn-the-basics start-a-chapter programs foodfight olympiad media galleries writing resources documents; do mkdir -p "$STG/assets/$s"; done
ls "$STG"
```
Expected: `assets  specs`

- [ ] **Step 2: Dispatch 15 extraction agents (use superpowers:dispatching-parallel-agents)**

One `general-purpose` agent per slug, all in one message. Each agent's prompt (substitute SLUG / URL / PARENT / TYPE / STAGING):

> You are extracting ONE page of www.amtgard.com into a CMS page spec. Target page: `<URL>` (slug `<SLUG>`, parent `<PARENT or none>`, page type `<TYPE>`).
> 1. Read the page. Use WebFetch first; if content is JS-rendered or you can't see it, load Chrome MCP (`tabs_create_mcp`+`navigate`+`get_page_text`/`read_page`) — you MAY use Chrome. Follow any sub-links that are clearly part of THIS page's content (fold them in as extra blocks); do NOT follow into other top-level nav pages.
> 2. Decompose ALL visible body content (exclude site header/footer/nav chrome) into an ordered list of CMS blocks using ONLY these types: hero_carousel, rich_text, heading, card_grid, cta_band, steps, staff_roster, accordion, quote, table, gallery, photo_mosaic, image, video_embed, file_download, divider. Prefer rich_text for prose, card_grid/steps/accordion/table/staff_roster where the source uses that structure, gallery/photo_mosaic for photo sets, video_embed for embedded YouTube/Vimeo, file_download for linked PDFs/docs. Field keys per the CMS `_starter` defaults — ask me nothing; infer keys from these examples: rich_text{kicker,heading,body,align,cta{label,href}}, card_grid{kicker,heading,subheading,cards[]{icon,title,blurb,href}}, steps{kicker,heading,band,steps[]{n,title,body},cta{label,href}}, accordion{items[]{q,a}}, table{caption,header_first_row,rows[]}, gallery{columns,caption}, video_embed{provider,video_id,url,caption}, file_download{files[]}, staff_roster{kicker,heading,people[]{name,role,image}}, quote{text,cite}.
> 3. Download every content image to `<STAGING>/assets/<SLUG>/` with curl (`curl -sL -o`), naming them `1.jpg`, `2.jpg`, … (keep the real extension). Download every linked PDF/doc there too (keep its real filename). Record each in the block's `assets`. Rewrite any internal amtgard.com link that corresponds to one of our slugs (about/mission/staff/volunteers/join/learn-the-basics/start-a-chapter/programs/foodfight/olympiad/media/galleries/writing/resources/documents) to `UIRPLACEHOLDER/Page/view/<that-slug>` in hrefs; leave external links absolute; leave the chapter directory as `UIRPLACEHOLDER/Atlas`.
> 4. Write the spec to `<STAGING>/specs/<SLUG>.json` exactly matching this shape: {slug,type,parent_slug,title,meta_description,blocks:[{type,fields,assets?}]}. media ONLY in `assets` (filenames relative to assets/<SLUG>/), never in fields. Validate it's valid JSON.
> Return: the block count, image count, pdf count, and any content you were unsure how to map.

Assign: about→composed, mission→article/about, staff→composed/about, volunteers→article/about, join→composed, learn-the-basics→composed/join, start-a-chapter→composed/join, programs→composed, foodfight→composed/programs, olympiad→composed/programs, media→media, galleries→media/media, writing→article/media, resources→resource, documents→resource/resources.

- [ ] **Step 3: Write the validator**

Create `…/scratchpad/amtgard-clone/validate.php`:
```php
<?php
$stg = __DIR__;
$slugs = ['about','mission','staff','volunteers','join','learn-the-basics','start-a-chapter','programs','foodfight','olympiad','media','galleries','writing','resources','documents'];
$fail = 0;
foreach ($slugs as $s) {
    $f = "$stg/specs/$s.json";
    if (!is_file($f)) { echo "MISSING spec: $s\n"; $fail++; continue; }
    $j = json_decode(file_get_contents($f), true);
    if (!$j) { echo "BAD JSON: $s\n"; $fail++; continue; }
    foreach (['slug','title','blocks'] as $k) if (!isset($j[$k])) { echo "$s: missing $k\n"; $fail++; }
    if (($j['slug'] ?? '') !== $s) { echo "$s: slug mismatch\n"; $fail++; }
    foreach (($j['blocks'] ?? []) as $i => $b) {
        if (empty($b['type'])) { echo "$s block $i: no type\n"; $fail++; }
        foreach ($b['assets']['images'] ?? [] as $im) if (!is_file("$stg/assets/$s/{$im['file']}")) { echo "$s: missing image {$im['file']}\n"; $fail++; }
        foreach ($b['assets']['files'] ?? [] as $fl) if (!is_file("$stg/assets/$s/{$fl['file']}")) { echo "$s: missing file {$fl['file']}\n"; $fail++; }
    }
    echo "$s: OK (" . count($j['blocks'] ?? []) . " blocks)\n";
}
echo $fail ? "\nFAIL: $fail problem(s)\n" : "\nALL 15 SPECS VALID\n";
exit($fail ? 1 : 0);
```

- [ ] **Step 4: Run the validator**

Run: `php "$STG/validate.php"`
Expected: `ALL 15 SPECS VALID` (re-dispatch any agent whose page failed; fix and re-run until clean).

- [ ] **Step 5: Commit the specs+assets to scratchpad is not needed** (staging is not a git repo). Skip commit; proceed to Task 2.

---

### Task 2: Reconcile & spot-check extracted content

**Files:** (read-only review of `specs/*.json`)

**Interfaces:** Consumes Task 1 specs; produces a go/no-go for seeding.

- [ ] **Step 1: Cross-check against source**

For each of the 15 specs, WebFetch the source URL and confirm the spec captured the page's headings, body paragraphs, lists, images, and any PDF links (dispatch 3-4 review agents in parallel, ~4 pages each, using superpowers:dispatching-parallel-agents). Each returns: per page, MATCH or a list of missing/garbled content.

- [ ] **Step 2: Fix gaps**

For any page flagged incomplete, edit its `specs/<slug>.json` directly (or re-dispatch that page's extraction agent). Re-run `php "$STG/validate.php"`.
Expected: `ALL 15 SPECS VALID` and reviewers report MATCH.

---

### Task 3: Page seed script

**Files:**
- Create: `db-migrations/2026-07-08-cms-seed-amtgard.php`

**Interfaces:**
- Consumes: `specs/*.json` + `assets/<slug>/*` (path passed as `argv[1]`).
- Produces: 15 published global pages with blocks + hierarchy; `ork_cms_media` rows.
- Helper contract used by Task 4: pages exist at `GetPageBySlug($slug,'global',0,true)`.

- [ ] **Step 1: Write the seed script**

Create `db-migrations/2026-07-08-cms-seed-amtgard.php`:
```php
<?php
/**
 * Seed amtgard.com content replication into the CMS (global scope).
 * Idempotent: deletes non-system pages by slug then recreates published;
 * media deduped by deterministic filename. Reads extracted specs+assets.
 * Run: docker exec ork3-php8-app php \
 *   /var/www/ork.amtgard.com/db-migrations/2026-07-08-cms-seed-amtgard.php /path/to/amtgard-clone
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$STG = isset($argv[1]) ? rtrim($argv[1], '/') : (__DIR__ . '/.amtgard-assets');
if (!is_dir("$STG/specs")) { fwrite(STDERR, "no specs dir at $STG/specs\n"); exit(1); }
chdir('/var/www/ork.amtgard.com/orkui');
define('DONOTWEBSERVICE', true);
if (empty($_SERVER['HTTP_HOST'])) { $_SERVER['HTTP_HOST'] = 'localhost:19080'; }
ob_start(); require('/var/www/ork.amtgard.com/startup.php'); ob_end_clean();
if (!defined('UIR')) { define('UIR', '/orkui/index.php?Route='); }

global $DB;
$cms   = new CmsPage();
$media = new CmsMedia();
$now   = date('Y-m-d H:i:s');
$by    = 1;

// dependency order: parents before children
$order = ['about','join','programs','media','resources',
          'mission','staff','volunteers','learn-the-basics','start-a-chapter',
          'foodfight','olympiad','galleries','writing','documents'];

// Upload one staging image, dedup by deterministic filename, return a root-relative media ref.
$uploadImage = function ($slug, $localFile, $alt, $idx) use ($media, $DB, $by) {
    $abs = "$GLOBALS[STG]/assets/$slug/$localFile"; // note: closure var below
    return null; // replaced in Step 2 helper wiring
};
```
(The upload helper is finished in Step 2 — split out so the dedup query is explicit.)

- [ ] **Step 2: Finish the media-upload helper + main loop**

Replace the placeholder `$uploadImage` and append the main loop:
```php
$ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
$toRel = function ($url) { // strip scheme+host -> root-relative
    $p = parse_url($url); return isset($p['path']) ? $p['path'] . (isset($p['query'])?'?'.$p['query']:'') : $url;
};
$uploadImage = function ($slug, $localFile, $alt, $idx) use ($media, $DB, $by, $STG, $toRel) {
    $abs = "$STG/assets/$slug/$localFile";
    if (!is_file($abs)) { return null; }
    $fname = "amtg-$slug-$idx." . strtolower(pathinfo($localFile, PATHINFO_EXTENSION) ?: 'jpg');
    // dedup: reuse an existing global media row with this filename
    $DB->Clear(); $DB->filename = $fname;
    $DB->DataSet("SELECT media_id, path, thumb_path FROM " . DB_PREFIX . "cms_media WHERE filename = :filename AND scope_type='global' AND scope_id=0 AND deleted_at IS NULL LIMIT 1");
    $DB->Next();
    if ((int)$DB->media_id > 0) {
        return ['key'=>'m'.$DB->media_id, 'media_id'=>(int)$DB->media_id,
                'src'=>'/assets/'.$DB->path, 'thumb'=>$DB->thumb_path?'/assets/'.$DB->thumb_path:'/assets/'.$DB->path,
                'alt'=>(string)$alt, 'focal'=>'50% 50%'];
    }
    $row = $media->Upload(base64_encode(file_get_contents($abs)), $fname, (string)$alt, $by, ['type'=>'global','id'=>0]);
    if (empty($row['media_id'])) { fwrite(STDERR, "upload failed: $slug/$localFile\n"); return null; }
    return ['key'=>'m'.$row['media_id'], 'media_id'=>(int)$row['media_id'],
            'src'=>$toRel($row['src']), 'thumb'=>$toRel($row['thumb'] ?: $row['src']),
            'alt'=>(string)$alt, 'focal'=>'50% 50%'];
};

// copy a staging PDF/doc into assets/cms-docs and return its root-relative url (interim; see plan Risks)
$docsDir = DIR_ASSETS . 'cms-docs';
if (!is_dir($docsDir)) { @mkdir($docsDir, 0775, true); }
$copyDoc = function ($slug, $localFile, $name) use ($STG, $docsDir) {
    $abs = "$STG/assets/$slug/$localFile"; if (!is_file($abs)) { return null; }
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name ?: basename($localFile));
    @copy($abs, "$docsDir/$safe");
    return '/assets/cms-docs/' . $safe;
};

$idBySlug = [];
$report = [];
foreach ($order as $slug) {
    $spec = json_decode(@file_get_contents("$STG/specs/$slug.json"), true);
    if (!$spec) { $report[] = "$slug: NO SPEC"; continue; }
    $existing = $cms->GetPageBySlug($slug, 'global', 0, false);
    if (!empty($existing) && empty($existing['is_system'])) { $cms->DeletePage((int)$existing['page_id']); }
    $parentId = !empty($spec['parent_slug']) && isset($idBySlug[$spec['parent_slug']]) ? $idBySlug[$spec['parent_slug']] : null;
    $pid = (int)$cms->CreatePage([
        'slug'=>$slug, 'type'=>$spec['type'] ?? 'composed', 'title'=>$spec['title'] ?? $slug,
        'meta_description'=>$spec['meta_description'] ?? null, 'status'=>'published', 'published_at'=>$now,
        'scope_type'=>'global', 'scope_id'=>0, 'is_system'=>0, 'parent_id'=>$parentId,
        'created_by'=>$by, 'created_at'=>$now, 'updated_by'=>$by, 'updated_at'=>$now,
    ]);
    if ($pid <= 0) { $report[] = "$slug: CREATE FAILED"; continue; }
    $idBySlug[$slug] = $pid;
    $cms->SetStatus($pid, 'published', $by);

    // build blocks, resolving assets -> refs / urls
    $blocks = []; $i = 0;
    foreach ($spec['blocks'] as $b) {
        $f = $b['fields'] ?? [];
        // resolve internal-link placeholders in any href-bearing string
        array_walk_recursive($f, function (&$v) { if (is_string($v)) { $v = str_replace('UIRPLACEHOLDER/', UIR, $v); } });
        if (!empty($b['assets']['images'])) {
            $refs = [];
            foreach ($b['assets']['images'] as $k => $im) { $r = $uploadImage($slug, $im['file'], $im['alt'] ?? '', "$i-$k"); if ($r) $refs[] = $r; }
            if ($b['type'] === 'image')          { $f['image'] = $refs[0] ?? ['src'=>'']; }
            elseif ($b['type'] === 'hero_carousel') { foreach ($refs as $ri=>$r){ $f['slides'][$ri]['image']=$r; } }
            else                                  { $f['images'] = $refs; } // gallery / photo_mosaic / staff etc.
        }
        if (!empty($b['assets']['files'])) {
            $files = [];
            foreach ($b['assets']['files'] as $fl) { $u = $copyDoc($slug, $fl['file'], $fl['name'] ?? ''); if ($u) $files[] = ['label'=>$fl['label'] ?? ($fl['name'] ?? 'Download'), 'url'=>$u]; }
            $f['files'] = $files;
        }
        $blocks[] = ['type'=>$b['type'], 'enabled'=>1, 'order'=>$i++, 'source'=>'authored', 'fields'=>$f];
    }
    $n = $cms->ReplaceBlocks('page', $pid, $blocks);
    $report[] = "$slug: page_id=$pid parent=" . ($parentId ?? '-') . " blocks=$n";
}
echo implode("\n", $report) . "\n";
```
Fix the leftover placeholder-`$uploadImage` stub from Step 1 (delete those 4 lines; the real closure above is authoritative). Also add `$STG` to the top-level scope (already a top var).

- [ ] **Step 3: Stage assets into the container (if scratchpad isn't mounted)**

```bash
docker exec ork3-php8-app mkdir -p /var/www/ork.amtgard.com/db-migrations/.amtgard-assets
docker cp "$STG/specs"  ork3-php8-app:/var/www/ork.amtgard.com/db-migrations/.amtgard-assets/
docker cp "$STG/assets" ork3-php8-app:/var/www/ork.amtgard.com/db-migrations/.amtgard-assets/
```

- [ ] **Step 4: Run the seed**

Run: `docker exec ork3-php8-app php /var/www/ork.amtgard.com/db-migrations/2026-07-08-cms-seed-amtgard.php /var/www/ork.amtgard.com/db-migrations/.amtgard-assets`
Expected: 15 lines like `about: page_id=NN parent=- blocks=5` … no `CREATE FAILED` / `NO SPEC` / `upload failed`.

- [ ] **Step 5: Verify in DB**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT slug,status,parent_id,(SELECT COUNT(*) FROM ork_cms_block b WHERE b.owner_type='page' AND b.owner_id=p.page_id) blocks FROM ork_cms_page p WHERE scope_type='global' AND deleted_at IS NULL ORDER BY parent_id,slug;"
```
Expected: 15 rows (plus prior exemplar pages), all `status=published`, children showing a numeric `parent_id`, `blocks > 0`.

- [ ] **Step 6: Commit the seed script**

```bash
git add db-migrations/2026-07-08-cms-seed-amtgard.php
git commit -m "Enhancement: CMS — amtgard.com content replication seed (15 global pages)"
```

---

### Task 4: Nav relink

**Files:**
- Create: `db-migrations/2026-07-08-cms-nav-relink-amtgard.php`

**Interfaces:** Consumes Task 3 pages (by slug). Uses `CmsNav::ListItems($menu,$scope,$id)` + `CmsNav::UpdateItem($navId,$data)` (see `2026-06-23-cms-nav-relink.php`).

- [ ] **Step 1: Ensure the marketing menu exists**

Run: `docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SELECT COUNT(*) FROM ork_cms_nav_item WHERE menu='marketing' AND scope_type='global';"`
If `0`: run `docker exec ork3-php8-app php /var/www/ork.amtgard.com/db-migrations/2026-06-23-cms-seed-nav.php` first, then re-check (expect ~18).

- [ ] **Step 2: Write the relink migration**

Create `db-migrations/2026-07-08-cms-nav-relink-amtgard.php` (modeled on `2026-06-23-cms-nav-relink.php`):
```php
<?php
/** Relink the marketing menu to the amtgard.com replication pages (global). Idempotent. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
if (empty($_SERVER['HTTP_HOST'])) { $_SERVER['HTTP_HOST'] = 'localhost:19080'; }
require_once __DIR__ . '/../startup.php';
if (!defined('UIR')) { define('UIR', HTTP_UI_REMOTE . 'index.php?Route='); }

$nav = new CmsNav();
$cms = new CmsPage();
$pid = function ($slug) use ($cms) { $r = $cms->GetPageBySlug($slug, 'global', 0, true); return (!empty($r['page_id'])) ? (int)$r['page_id'] : null; };

// label => target. Internal pages by slug; two intentional externals kept.
$pageFor = [
    'About'=>'about', 'Join'=>'join', 'AI Programs'=>'programs', 'Media'=>'media', 'Official Resources'=>'resources',
    'Mission'=>'mission', 'Staff'=>'staff', 'Volunteers'=>'volunteers',
    'Learn the Basics'=>'learn-the-basics', 'Start a Chapter'=>'start-a-chapter',
    'Food Fight'=>'foodfight', 'Olympiad'=>'olympiad',
    'Galleries'=>'galleries', 'Writing'=>'writing', 'Documents'=>'documents',
];
$external = [
    'Home'           => ['dynamic', 'index.php?Route='],
    'Find a Chapter' => ['dynamic', 'Atlas'],
    'Merch'          => ['url', 'https://www.redbubble.com/people/amtgardmarket/shop'],
];

$items = $nav->ListItems('marketing', 'global', 0);
$updated = []; $skipped = []; $unmatched = [];
foreach ($items as $it) {
    $label = (string)($it['label'] ?? '');
    if (isset($pageFor[$label])) {
        $id = $pid($pageFor[$label]);
        if (!$id) { $skipped[] = "$label (page missing)"; continue; }
        $nav->UpdateItem((int)$it['nav_id'], ['link_type'=>'page','page_id'=>$id,'post_id'=>null,'url'=>null]);
        $updated[] = "$label -> page:{$pageFor[$label]}";
    } elseif (isset($external[$label])) {
        [$lt, $url] = $external[$label];
        $nav->UpdateItem((int)$it['nav_id'], ['link_type'=>$lt,'page_id'=>null,'post_id'=>null,'url'=>$url]);
        $updated[] = "$label -> $lt";
    } else { $unmatched[] = $label; }
}
echo json_encode(['updated'=>$updated,'skipped'=>$skipped,'unmatched'=>$unmatched], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n";
```

- [ ] **Step 3: Run it**

Run: `docker exec ork3-php8-app php /var/www/ork.amtgard.com/db-migrations/2026-07-08-cms-nav-relink-amtgard.php`
Expected: `updated` lists all 15 page relinks + Home/Find a Chapter/Merch; `skipped` empty; `unmatched` empty (or only benign extras).

- [ ] **Step 4: Commit**

```bash
git add db-migrations/2026-07-08-cms-nav-relink-amtgard.php
git commit -m "Enhancement: CMS — relink marketing nav to amtgard.com replication pages"
```

---

### Task 5: Local render verification

**Files:** (no code unless a regression needs a block-field fix in a spec → re-seed)

**Interfaces:** Consumes the live local app.

- [ ] **Step 1: Confirm app is up**

Run: `docker ps --format '{{.Names}}' | grep ork3-php8 && curl -s -o /dev/null -w '%{http_code}\n' http://localhost:19080/orkui/`
Expected: containers listed, `200`. If down: `docker-compose -f docker-compose.php8.yml up -d`.

- [ ] **Step 2: Verify every page renders (Chrome, use claude-in-chrome skill)**

For each slug load `http://localhost:19080/orkui/index.php?Route=Page/view/<slug>` and confirm: title + all blocks present, images/thumbs load (no broken img), PDFs download, internal links resolve, no console errors, dark-mode holds (`html[data-theme="dark"]`). Batch across a few checks; capture a screenshot of `about`, `galleries`, `documents`, `staff` as evidence.
Expected: all 15 render with content; note any block that renders empty/wrong.

- [ ] **Step 3: Verify nav dropdowns**

On any front-door page, confirm the marketing menu shows the 6 dropdowns and that clicking child items lands on the new CMS pages (not amtgard.com), while Find a Chapter → Atlas and Merch → Redbubble.

- [ ] **Step 4: Fix regressions & re-seed**

For any wrong block: edit the offending `specs/<slug>.json`, `docker cp` it back, re-run the Task 3 seed (idempotent), re-verify. Repeat until all 15 are clean.

- [ ] **Step 5: Final report**

Summarize to the user: pages created, images re-hosted (count), PDFs hosted, nav relinked, screenshots, and the two documented follow-ups (in-CMS document storage; prod-host media rebuild).

---

## Risks / Follow-ups (carry into delivery notes)

1. **In-CMS document (PDF) storage is deferred** — media library is raster-only; interim self-hosts PDFs in `assets/cms-docs/` referenced by URL in `file_download`. Recommend a follow-up enhancement: document upload/storage in the CMS with a mime allowlist + safe serving.
2. **Media `src` is root-relative** (host-agnostic) so no prod host baking is needed for images. The physical files live in `assets/cms-media/` (normal CMS media store) and travel with the app + DB as usual. If deploying to a fresh env, re-run the Task 3 seed (with staging assets present) to recreate rows+files.
3. **Seed reads staging assets** (`.amtgard-assets`), not committed binaries — keeps the repo lean; the built media is standard CMS media thereafter.
4. **amtgard.com sitemap captured 2026-07-08**; agents reconcile live during extraction (Task 2 spot-check catches drift).

## Self-Review

- **Spec coverage:** all 15 pages (Task 1/3), re-host images (Task 3 `$uploadImage`), PDFs interim (Task 3 `$copyDoc`, Risk 1), nav incl. external links (Task 4), hierarchy (`parent_id`, Task 3), verification incl. dark mode (Task 5), build origin localhost:19080 + root-relative src (Global Constraints) — all covered.
- **Placeholders:** the only intentional stub is the Step-1 `$uploadImage` skeleton, explicitly finished/replaced in Step 2 (called out). No TBD/TODO.
- **Type consistency:** `$uploadImage($slug,$file,$alt,$idx)`→ref, `$copyDoc($slug,$file,$name)`→url, `$pid($slug)`→int|null, ref shape `{key,media_id,src,thumb,alt,focal}` used consistently in Task 3 and matches what gallery/image/hero_carousel partials read (`src`/`thumb`). Nav uses `ListItems`/`UpdateItem` per the existing relink file.
