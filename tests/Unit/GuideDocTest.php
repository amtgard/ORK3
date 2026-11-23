<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GuideDocTest extends TestCase
{
    protected function setUp(): void
    {
        require_once DIR_SYSTEM . 'lib/ork3/class.GuideDoc.php';
    }

    public function testHeadingsGetIdsAndTheTocFollowsThem(): void
    {
        $md = "# Title\n\nIntro.\n\n## High-impact actions\n\nText.\n\n### Merge players\n\n## When unsure\n\n## When unsure\n";
        $r  = GuideDoc::render($md);

        $this->assertStringContainsString('<h2 id="high-impact-actions">High-impact actions</h2>', $r['html']);
        $this->assertStringContainsString('<h3 id="merge-players">Merge players</h3>', $r['html']);
        // A repeated heading still gets a unique anchor.
        $this->assertStringContainsString('<h2 id="when-unsure">When unsure</h2>', $r['html']);
        $this->assertStringContainsString('<h2 id="when-unsure-2">When unsure</h2>', $r['html']);
        // The h1 is the page title, not a section: no id, not in the TOC.
        $this->assertStringContainsString('<h1>Title</h1>', $r['html']);

        $this->assertSame(
            [
                ['id' => 'high-impact-actions', 'text' => 'High-impact actions', 'level' => 2],
                ['id' => 'merge-players',       'text' => 'Merge players',       'level' => 3],
                ['id' => 'when-unsure',         'text' => 'When unsure',         'level' => 2],
                ['id' => 'when-unsure-2',       'text' => 'When unsure',         'level' => 2],
            ],
            $r['toc']
        );
    }

    public function testInlineMarkupInHeadingsIsKeptButNotInTheSlug(): void
    {
        $r = GuideDoc::render("## Don't *browse* `private` details\n");
        $this->assertSame('don-t-browse-private-details', $r['toc'][0]['id']);
        $this->assertSame("Don't browse private details", $r['toc'][0]['text']);
        $this->assertStringContainsString('<em>browse</em>', $r['html']);
    }

    public function testRawHtmlInTheFileIsEscapedNotRendered(): void
    {
        $r = GuideDoc::render("## Section\n\n<script>alert(1)</script>\n");
        $this->assertStringNotContainsString('<script>', $r['html']);
        $this->assertStringContainsString('&lt;script&gt;', $r['html']);
    }

    public function testTheShippedAdminGuideRendersWithEverySectionAnchored(): void
    {
        $path = DIR_BASENAME . 'docs/admin-guide.md';
        $this->assertFileIsReadable($path);
        $r = GuideDoc::render((string) file_get_contents($path));
        $ids = array_column(array_filter($r['toc'], static fn ($t) => $t['level'] === 2), 'id');
        $this->assertContains('high-impact-actions', $ids);
        $this->assertContains('granting-and-reviewing-access', $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertStringNotContainsString('****', $r['html']);
    }
}
