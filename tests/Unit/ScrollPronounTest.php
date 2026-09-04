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
}
