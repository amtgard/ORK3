<?php

/**
 * Renders an in-repo Markdown guide (docs/*.md) for a full page: Parsedown in
 * safe mode, then an id on every h2/h3 so sections can be linked to, and a
 * table of contents built from those headings.
 *
 * The Markdown file stays the single source of truth; nothing here is
 * content-specific. The modal helps (SurveyAjax/help, QualTestAjax/help) use
 * Parsedown directly because a scroll box has no use for anchors.
 */
class GuideDoc
{
    /**
     * @return array{html: string, toc: list<array{id: string, text: string, level: int}>}
     */
    public static function render(string $markdown): array
    {
        require_once DIR_SYSTEM . 'lib/Parsedown.php';
        $pd = new Parsedown();
        $pd->setSafeMode(true);       // never render raw HTML from a file, even our own
        $pd->setBreaksEnabled(false); // hard-wrapped paragraphs stay paragraphs
        $html = $pd->text($markdown);

        $toc  = [];
        $seen = [];
        $html = preg_replace_callback(
            '#<h([23])>(.*?)</h\1>#s',
            static function (array $m) use (&$toc, &$seen): string {
                $level = (int) $m[1];
                $inner = $m[2];
                $text  = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $id    = self::slug($text, $seen);
                $toc[] = ['id' => $id, 'text' => $text, 'level' => $level];
                return '<h' . $level . ' id="' . $id . '">' . $inner . '</h' . $level . '>';
            },
            $html
        );

        return ['html' => $html, 'toc' => $toc];
    }

    /**
     * "High-impact actions" -> "high-impact-actions"; a repeat gets -2, -3, …
     * The id is also the URL fragment people paste into Discord, so it is
     * plain ASCII and stable for as long as the heading text is.
     *
     * @param array<string, true> $seen ids already issued (updated in place)
     */
    public static function slug(string $text, array &$seen): string
    {
        $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
        if ($base === '') {
            $base = 'section';
        }
        $id = $base;
        for ($n = 2; isset($seen[$id]); $n++) {
            $id = $base . '-' . $n;
        }
        $seen[$id] = true;
        return $id;
    }
}
