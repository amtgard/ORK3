<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The per-print pronoun override is free text typed by a human, not a closed
 * vocabulary — reachable from a plain <input>, not a <select> of known-safe
 * values. Trusting it verbatim reopens the exact trap the stored
 * pronoun_freetext resolution exists to close: an officer typing "he/him"
 * must still print "his", never "he/him" or "him".
 */
final class ScrollPronounOverrideTest extends TestCase
{
    /** Shaped like GetPronounList()['Pronouns']. */
    private function rows(): array
    {
        return [
            1 => ['Subject' => 'e/ey',   'Possessive' => 'eir'],
            2 => ['Subject' => 'he',     'Possessive' => 'his'],
            4 => ['Subject' => 'she',    'Possessive' => 'her'],
            5 => ['Subject' => 'they',   'Possessive' => 'their'],
            8 => ['Subject' => 'ze/zie', 'Possessive' => 'hir'],
        ];
    }

    public function testSubjectObjectOverrideResolvesToPossessive(): void
    {
        $this->assertSame('his', Scroll::ResolvePronounOverride('he/him', $this->rows()));
        $this->assertSame('her', Scroll::ResolvePronounOverride('she/her', $this->rows()));
        $this->assertSame('their', Scroll::ResolvePronounOverride('they/them', $this->rows()));
    }

    /** A typed value that is already a possessive, with no matching subject, passes through. */
    public function testAlreadyPossessiveOverridePassesThroughUnchanged(): void
    {
        $this->assertSame('his', Scroll::ResolvePronounOverride('his', $this->rows()));
        $this->assertSame('hir', Scroll::ResolvePronounOverride('hir', $this->rows()));
        $this->assertSame('eir', Scroll::ResolvePronounOverride('eir', $this->rows()));
    }

    /** "with His third" is wrong mid-sentence — the override can never print a capital. */
    public function testResultIsAlwaysLowercased(): void
    {
        $this->assertSame('his', Scroll::ResolvePronounOverride('He/Him', $this->rows()));
        $this->assertSame('his', Scroll::ResolvePronounOverride('His', $this->rows()));
        $this->assertSame('zyr', Scroll::ResolvePronounOverride('ZYR', $this->rows()));
    }

    public function testEmptyOverrideYieldsNothing(): void
    {
        $this->assertSame('', Scroll::ResolvePronounOverride('', $this->rows()));
        $this->assertSame('', Scroll::ResolvePronounOverride('   ', $this->rows()));
    }

    /**
     * A client-side maxlength is not a server-side guarantee. A pasted wall of
     * text is capped before it ever reaches a printed certificate.
     */
    public function testOverLengthOverrideIsCapped(): void
    {
        $result = Scroll::ResolvePronounOverride(str_repeat('x', 500), $this->rows());
        $this->assertLessThanOrEqual(20, strlen($result));
        $this->assertSame(str_repeat('x', 20), $result);
    }
}
