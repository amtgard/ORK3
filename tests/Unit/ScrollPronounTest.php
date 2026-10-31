<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The certificate needs the POSSESSIVE — "with her third".
 *
 * Free text is subject/object ("he/him"), so reading it naively yields "him" and the
 * certificate says "with him third". The leading token is matched against
 * ork_pronoun.subject instead, longest match first so "e/ey" and "ze/zie" resolve
 * before the shorter subjects they start with.
 */
final class ScrollPronounTest extends TestCase
{
    /** Shaped like GetPronounList()['Pronouns']. */
    private function rows(): array
    {
        return [
            1  => ['Subject' => 'e/ey',  'Possessive' => 'eir'],
            2  => ['Subject' => 'he',    'Possessive' => 'his'],
            4  => ['Subject' => 'she',   'Possessive' => 'her'],
            5  => ['Subject' => 'they',  'Possessive' => 'their'],
            8  => ['Subject' => 'ze/zie', 'Possessive' => 'hir'],
        ];
    }

    public function testHeHimResolvesToHisNotHim(): void
    {
        $this->assertSame('his', Scroll::PossessiveFromFreetext('he/him', $this->rows()));
    }

    public function testCommonFormsResolve(): void
    {
        $this->assertSame('her', Scroll::PossessiveFromFreetext('she/her', $this->rows()));
        $this->assertSame('their', Scroll::PossessiveFromFreetext('they/them', $this->rows()));
        $this->assertSame('her', Scroll::PossessiveFromFreetext('she/they', $this->rows()));
    }

    /** "e/ey/em" must match the subject "e/ey", not the bare "e" prefix of it. */
    public function testMultiTokenSubjectsMatchLongestFirst(): void
    {
        $this->assertSame('eir', Scroll::PossessiveFromFreetext('e/ey/em', $this->rows()));
        $this->assertSame('hir', Scroll::PossessiveFromFreetext('ze/zie/hir', $this->rows()));
    }

    public function testUnknownOrEmptyFreetextYieldsNothing(): void
    {
        $this->assertSame('', Scroll::PossessiveFromFreetext('', $this->rows()));
        $this->assertSame('', Scroll::PossessiveFromFreetext('xyzzy/plugh', $this->rows()));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $this->assertSame('his', Scroll::PossessiveFromFreetext('He/Him', $this->rows()));
    }

    /**
     * Item 46. The match used to require an exact string or a bare `subject/`
     * prefix, so the two shapes people actually type both fell silently through to
     * "their": spaces around the slash, and a trailing full stop.
     *
     * "He/Him." is in here as a CONTROL — it already worked, because the prefix
     * test never looked past the slash. The punctuation half only ever bit
     * slash-less values, and saying so keeps the next reader from "fixing" a
     * problem that was not there.
     */
    public function testWhitespaceAndTrailingPunctuationNoLongerLoseTheMatch(): void
    {
        $this->assertSame('her', Scroll::PossessiveFromFreetext('she / her', $this->rows()));
        $this->assertSame('her', Scroll::PossessiveFromFreetext('She /Her', $this->rows()));
        $this->assertSame('their', Scroll::PossessiveFromFreetext('They.', $this->rows()));
        $this->assertSame('their', Scroll::PossessiveFromFreetext('  they  ', $this->rows()));
        $this->assertSame('hir', Scroll::PossessiveFromFreetext('ze / zie', $this->rows()));
        $this->assertSame('his', Scroll::PossessiveFromFreetext('(he/him)', $this->rows()));

        // Control: already correct before the normalisation landed.
        $this->assertSame('his', Scroll::PossessiveFromFreetext('He/Him.', $this->rows()));
    }

    public function testNormalisationKeepsTheSlashAndDropsNothingElse(): void
    {
        $this->assertSame('he/him', Scroll::NormalisePronounText('  He / Him.  '));
        $this->assertSame('ze/zir', Scroll::NormalisePronounText('"Ze/Zir"'));
        $this->assertSame('e/ey', Scroll::NormalisePronounText('E/EY'));
        $this->assertSame('', Scroll::NormalisePronounText('   '));
    }

    /**
     * THE ASYMMETRY, pinned. A profile value that missed the subject table fell to
     * "their" while the same string typed into the officer's override box got the
     * post-slash fallback — so a recipient's own "ze/zir" printed "their" and an
     * officer retyping it printed "zir". One resolver now serves both doors, so
     * the two must agree on every input.
     */
    public function testBothPronounDoorsResolveIdentically(): void
    {
        foreach (['ze/zir', 'she / her', 'They.', 'he/him', 'xe/xem', 'his', 'ey/em/eir'] as $value) {
            $this->assertSame(
                Scroll::ResolvePossessive($value, $this->rows(), Scroll::PRONOUN_OVERRIDE_MAX_LENGTH),
                Scroll::ResolvePronounOverride($value, $this->rows()),
                'the override door must be the shared resolver: ' . $value
            );
        }

        // The specific regression: an unmatched SET now resolves the same way from
        // either door instead of collapsing to "their" from one of them.
        $this->assertSame('zir', Scroll::ResolvePossessive('ze/zir', $this->rows(), 40));
        $this->assertSame('zir', Scroll::ResolvePronounOverride('ze/zir', $this->rows()));
    }

    public function testResolverOrderIsTableThenSlashThenTheWordItself(): void
    {
        // 1 — the table wins even when a slash is present.
        $this->assertSame('his', Scroll::ResolvePossessive('he/him', $this->rows(), 40));
        // 2 — an unmatched set takes the token after the FIRST slash.
        $this->assertSame('xyr', Scroll::ResolvePossessive('xe/xyr/xem', $this->rows(), 40));
        // 3 — an unmatched slash-less value is already a possessive word.
        $this->assertSame('pers', Scroll::ResolvePossessive('Pers', $this->rows(), 40));
        // Nothing usable survives the split.
        $this->assertSame('their', Scroll::ResolvePossessive('///', $this->rows(), 40));
        // Empty in, empty out — the caller decides what "no answer" means.
        $this->assertSame('', Scroll::ResolvePossessive('   ', $this->rows(), 40));
    }

    /** A certificate must never print a capitalised pronoun mid-sentence. */
    public function testEveryResolvedValueIsLowercase(): void
    {
        foreach (['He/Him', 'SHE / HER', 'Ze/Zir', 'HIS'] as $value) {
            $resolved = Scroll::ResolvePossessive($value, $this->rows(), 40);
            $this->assertSame(mb_strtolower($resolved), $resolved, $value);
        }
    }
}
