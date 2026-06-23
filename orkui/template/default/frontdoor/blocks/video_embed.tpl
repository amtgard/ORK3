<?php
/**
 * Partial: video_embed.tpl  (MEDIA block) — self-contained (own scoped <style>)
 * Receives: $blockFields, shared $data, UIR
 *
 * Fields:
 *   provider  'youtube'|'vimeo'
 *   video_id  string — preferred; the bare id
 *   url       string — fallback; a pasted watch/share URL we parse for the id
 *   caption?  string — optional caption (escaped)
 *
 * Responsive 16:9 iframe. YouTube uses the privacy-enhanced youtube-nocookie
 * domain. The extracted id is hard-validated to [A-Za-z0-9_-] only before it is
 * ever placed in the iframe URL, so a hostile id/url cannot break out of the
 * src attribute or inject a different origin.
 *
 * "Dumb" partial: renders $blockFields only, fetches nothing.
 */
$fdbProvider = strtolower(trim((string) ($blockFields['provider'] ?? 'youtube')));
$fdbVideoId  = trim((string) ($blockFields['video_id'] ?? ''));
$fdbUrl      = trim((string) ($blockFields['url'] ?? ''));
$fdbCaption  = $blockFields['caption'] ?? '';

if ($fdbProvider !== 'vimeo') {
    $fdbProvider = 'youtube';
}

/**
 * Extract a provider video id from a pasted URL when no explicit id is given.
 * Returns a raw candidate string (validated/sanitized by the caller below).
 */
$fdbExtractId = static function (string $provider, string $url): string {
    if ($url === '') {
        return '';
    }
    if ($provider === 'youtube') {
        // youtu.be/<id>, watch?v=<id>, /embed/<id>, /shorts/<id>
        if (preg_match('#(?:youtu\.be/|v=|/embed/|/shorts/)([A-Za-z0-9_-]{6,})#', $url, $m)) {
            return $m[1];
        }
    } else { // vimeo
        // vimeo.com/<digits> (optionally player.vimeo.com/video/<digits>)
        if (preg_match('#vimeo\.com/(?:video/)?(\d{4,})#', $url, $m)) {
            return $m[1];
        }
    }
    return '';
};

// Prefer explicit id; otherwise parse the URL.
$fdbCandidate = $fdbVideoId !== '' ? $fdbVideoId : $fdbExtractId($fdbProvider, $fdbUrl);

// HARD sanitize: youtube ids are [A-Za-z0-9_-]; vimeo ids are digits. Strip
// everything else so the value placed in the src can never inject markup or a
// foreign host. An empty result after sanitizing means "don't render".
if ($fdbProvider === 'youtube') {
    $fdbId = preg_replace('/[^A-Za-z0-9_-]/', '', $fdbCandidate);
} else {
    $fdbId = preg_replace('/[^0-9]/', '', $fdbCandidate);
}

if ($fdbId === '' || $fdbId === null) {
    return;
}

if ($fdbProvider === 'youtube') {
    $fdbEmbed = 'https://www.youtube-nocookie.com/embed/' . $fdbId;
    $fdbTitle = 'YouTube video player';
} else {
    $fdbEmbed = 'https://player.vimeo.com/video/' . $fdbId;
    $fdbTitle = 'Vimeo video player';
}
?>
<style>
/* scoped: fdb-video */
.fdb-video-wrap {
    max-width: 880px;
    margin: 0 auto;
}
.fdb-video-frame {
    position: relative;
    width: 100%;
    padding-top: 56.25%; /* 16:9 */
    border-radius: 10px;
    overflow: hidden;
    background: #000;
    box-shadow: 0 8px 30px rgba(0, 0, 0, .25);
}
.fdb-video-frame iframe {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
}
.fdb-video-cap {
    margin-top: 10px;
    text-align: center;
    font-size: 13px;
    color: #667;
}
html[data-theme="dark"] .fdb-video-cap { color: #9aa6c0; }
</style>
<div class="fd-pad">
    <div class="fdb-video-wrap">
        <div class="fdb-video-frame">
            <iframe src="<?= htmlspecialchars($fdbEmbed, ENT_QUOTES) ?>"
                    title="<?= htmlspecialchars($fdbTitle, ENT_QUOTES) ?>"
                    loading="lazy"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen></iframe>
        </div>
        <?php if ($fdbCaption !== ''): ?>
            <div class="fdb-video-cap"><?= htmlspecialchars($fdbCaption, ENT_QUOTES) ?></div>
        <?php endif; ?>
    </div>
</div>
