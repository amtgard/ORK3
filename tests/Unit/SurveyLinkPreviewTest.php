<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SurveyLinkPreview shapes the logged-out face of a survey link: the og card
 * a chat crawler embeds and the lines the LoginRequired page shows a person.
 */
final class SurveyLinkPreviewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../system/lib/ork3/class.SurveyLinkPreview.php';
    }

    private function row(array $over = []): array
    {
        return array_merge([
            'survey_id'   => 7,
            'scope_type'  => 'kingdom',
            'scope_id'    => 31,
            'title'       => 'Autumn Reign Feedback',
            'description' => "How did the **Autumn** reign go?\nTell us.",
            'status'      => 'open',
            'open_at'     => null,
            'close_at'    => '2026-12-03 23:59:59',
        ], $over);
    }

    public function testOpenKingdomSurveyWithCreditAndCloseDate(): void
    {
        $p = SurveyLinkPreview::build($this->row(), 'The Kingdom of the Nine Blades', true, 'https://ork.test/Survey/s/abc', '2026-11-22');
        $this->assertSame('Autumn Reign Feedback', $p['og']['title']);
        $this->assertSame('https://ork.test/Survey/s/abc', $p['og']['url']);
        $this->assertSame(
            'How did the Autumn reign go? Tell us. For players of The Kingdom of the Nine Blades. Earns a bonus credit toward class levels. Closes Dec 3.',
            $p['og']['description']
        );
        $this->assertSame('Kingdom survey', $p['kicker']);
        $this->assertSame('Log in to the ORK to take it.', end($p['lines']));
        $this->assertSame('Log in to take the survey', $p['button']);
    }

    public function testParkAndOrkAudiencesAndNoCreditLine(): void
    {
        $park = SurveyLinkPreview::build($this->row(['scope_type' => 'park', 'description' => '']), 'Felfrost', false, 'u', '2026-11-22');
        $this->assertSame('For members of Felfrost. Closes Dec 3.', $park['og']['description']);
        $this->assertSame('Park survey', $park['kicker']);

        $ork = SurveyLinkPreview::build($this->row(['scope_type' => 'ork', 'description' => '', 'close_at' => null]), '', false, 'u', '2026-11-22');
        $this->assertSame('For every Amtgard player.', $ork['og']['description']);
        $this->assertSame('Amtgard survey', $ork['kicker']);
    }

    public function testClosedNotYetOpenAndMissingSurveys(): void
    {
        $closed = SurveyLinkPreview::build($this->row(['close_at' => '2026-11-01 00:00:00', 'description' => '']), 'X', true, 'u', '2026-11-22');
        $this->assertSame('For players of X. This survey is closed.', $closed['og']['description']);
        $this->assertSame('Log in to the ORK', $closed['button']);

        $draft = SurveyLinkPreview::build($this->row(['status' => 'draft', 'description' => '']), 'X', true, 'u', '2026-11-22');
        $this->assertStringContainsString('This survey is closed.', $draft['og']['description']);

        $soon = SurveyLinkPreview::build($this->row(['open_at' => '2026-12-01 00:00:00', 'description' => '']), 'X', true, 'u', '2026-11-22');
        $this->assertStringContainsString('This survey is not open yet.', $soon['og']['description']);
        $this->assertStringNotContainsString('bonus credit', $soon['og']['description']);

        $none = SurveyLinkPreview::build(null, '', false, 'u');
        $this->assertSame('Survey not found', $none['og']['title']);
        $this->assertSame('Survey not found', $none['heading']);
    }

    public function testSnippetStripsMarkdownAndTruncates(): void
    {
        $this->assertSame('Bold and link here', SurveyLinkPreview::snippet('**Bold** and [link](x) `here`'));
        $long = str_repeat('word ', 60);
        $s = SurveyLinkPreview::snippet($long);
        $this->assertLessThanOrEqual(SurveyLinkPreview::SNIPPET_MAX, mb_strlen($s));
        $this->assertStringEndsWith('…', $s);
    }
}
