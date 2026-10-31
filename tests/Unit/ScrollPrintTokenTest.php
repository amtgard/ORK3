<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Item 41, domain half. Scrollery/render re-checks the session, so a headless
 * renderer, an archiver or a PDF sidecar has no way to authenticate to it. The
 * signed print token is the hand-off: a page that has ALREADY passed the
 * per-(viewer, award) gate mints one, and the render route verifies it in place of
 * the session read.
 *
 * The security property these tests exist for: a token is bound to an EXACT id set
 * and cannot be widened.
 */
final class ScrollPrintTokenTest extends TestCase
{
    private const VIEWER = 46193;

    public function testAValidTokenVerifiesAndNamesItsViewer(): void
    {
        $token  = Scroll::MintPrintToken([101, 102, 103], self::VIEWER);
        $result = Scroll::VerifyPrintToken($token, [101, 102, 103]);

        $this->assertTrue($result['Valid']);
        $this->assertSame(self::VIEWER, $result['ViewerId']);
        $this->assertSame('', $result['Reason']);
    }

    /**
     * THE POINT OF THE THING. A token minted for three certificates must not print
     * a fourth, and the fourth is the only difference between the two requests.
     */
    public function testATokenCannotBeWidenedToAnExtraAward(): void
    {
        $token = Scroll::MintPrintToken([101, 102, 103], self::VIEWER);

        $widened = Scroll::VerifyPrintToken($token, [101, 102, 103, 104]);
        $this->assertFalse($widened['Valid']);
        $this->assertSame(0, $widened['ViewerId']);
        $this->assertSame('bad-signature', $widened['Reason']);

        // A completely different set, same length.
        $this->assertFalse(Scroll::VerifyPrintToken($token, [201, 202, 203])['Valid']);
    }

    /**
     * Narrowing is refused too. Not strictly required by the threat model — the
     * holder was entitled to the superset — but "this token is for this run" is a
     * rule with no edges, and an edge is where the next mistake lives.
     */
    public function testATokenCannotBeNarrowedEither(): void
    {
        $token = Scroll::MintPrintToken([101, 102, 103], self::VIEWER);

        $this->assertFalse(Scroll::VerifyPrintToken($token, [101, 102])['Valid']);
        $this->assertFalse(Scroll::VerifyPrintToken($token, [101])['Valid']);
    }

    /**
     * A render URL's ids are a SELECTION, not a sequence, so the same set in a
     * different order is the same grant — and a repeated id is not a second one.
     */
    public function testTheIdSetIsOrderAndDuplicateInsensitive(): void
    {
        $token = Scroll::MintPrintToken([103, 101, 102], self::VIEWER);

        $this->assertTrue(Scroll::VerifyPrintToken($token, [101, 102, 103])['Valid']);
        $this->assertTrue(Scroll::VerifyPrintToken($token, [102, 103, 101])['Valid']);
        $this->assertTrue(Scroll::VerifyPrintToken($token, [101, 101, 102, 103])['Valid']);
    }

    public function testATamperedSignatureIsRefused(): void
    {
        $token = Scroll::MintPrintToken([101], self::VIEWER);

        // Flip the last hex digit to something it is not.
        $last     = substr($token, -1);
        $tampered = substr($token, 0, -1) . ($last === 'a' ? 'b' : 'a');

        $this->assertFalse(Scroll::VerifyPrintToken($tampered, [101])['Valid']);
    }

    /**
     * The viewer id is signed, so a token cannot be re-pointed at a different
     * (more privileged) viewer by editing the field that carries it.
     */
    public function testTheViewerCannotBeSwappedInTheToken(): void
    {
        $token = Scroll::MintPrintToken([101], self::VIEWER);
        $parts = explode('.', $token);
        $this->assertCount(4, $parts);

        $parts[1] = '1'; // mundane 1 is the `admin` service account
        $this->assertFalse(Scroll::VerifyPrintToken(implode('.', $parts), [101])['Valid']);
    }

    /** The expiry is signed too — it cannot simply be pushed into the future. */
    public function testTheExpiryCannotBeExtended(): void
    {
        $token = Scroll::MintPrintToken([101], self::VIEWER);
        $parts = explode('.', $token);

        $parts[2] = (string) (time() + 86400);
        $this->assertFalse(Scroll::VerifyPrintToken(implode('.', $parts), [101])['Valid']);
    }

    /**
     * A non-positive TTL mints an already-dead token. That is the fail-closed
     * behaviour AND the only way to exercise this branch without sleeping.
     */
    public function testAnExpiredTokenIsRefused(): void
    {
        $token  = Scroll::MintPrintToken([101], self::VIEWER, -10);
        $result = Scroll::VerifyPrintToken($token, [101]);

        $this->assertFalse($result['Valid']);
        $this->assertSame('expired', $result['Reason']);
    }

    public function testMalformedTokensAreRefusedRatherThanParsed(): void
    {
        foreach (['', 'nonsense', 'v1.1.2', 'v2.1.2.abc', 'v1..2.abc', 'v1.0.2.abc', 'v1.x.2.abc'] as $bad) {
            $result = Scroll::VerifyPrintToken($bad, [101]);
            $this->assertFalse($result['Valid'], 'must refuse: ' . var_export($bad, true));
            $this->assertSame(0, $result['ViewerId']);
        }
    }

    /**
     * A leading-zero or padded viewer id must not round-trip through (int) into a
     * different signed string than the one presented.
     */
    public function testNonCanonicalNumericFieldsAreRefused(): void
    {
        $token = Scroll::MintPrintToken([101], self::VIEWER);
        $parts = explode('.', $token);

        $padded    = $parts;
        $padded[1] = '0' . $parts[1];
        $this->assertFalse(Scroll::VerifyPrintToken(implode('.', $padded), [101])['Valid']);

        $spaced    = $parts;
        $spaced[1] = ' ' . $parts[1];
        $this->assertFalse(Scroll::VerifyPrintToken(implode('.', $spaced), [101])['Valid']);
    }

    public function testMintingRefusesWithoutAViewerOrWithoutIds(): void
    {
        $this->assertSame('', Scroll::MintPrintToken([101], 0));
        $this->assertSame('', Scroll::MintPrintToken([101], -1));
        $this->assertSame('', Scroll::MintPrintToken([], self::VIEWER));
        $this->assertSame('', Scroll::MintPrintToken([0, -3], self::VIEWER));
    }

    public function testVerifyingAgainstNoIdsIsRefused(): void
    {
        $token = Scroll::MintPrintToken([101], self::VIEWER);

        $this->assertSame('no-ids', Scroll::VerifyPrintToken($token, [])['Reason']);
        $this->assertSame('no-ids', Scroll::VerifyPrintToken($token, [0])['Reason']);
    }

    /**
     * The signing key has to be stable across processes — PHP-FPM will answer the
     * render with a different worker than minted the token — so a per-process
     * random secret would look fine in one request and fail in production.
     */
    public function testTheSignatureIsStableForTheSameInputs(): void
    {
        $a = Scroll::MintPrintToken([101, 102], self::VIEWER, 300);
        $b = Scroll::MintPrintToken([102, 101], self::VIEWER, 300);

        // Same set, same viewer, same second -> byte-identical.
        $this->assertSame($a, $b);
    }

    /** The token must not leak which awards it covers to whoever holds it. */
    public function testTheTokenDoesNotCarryTheIdList(): void
    {
        $token = Scroll::MintPrintToken([987654, 123456], self::VIEWER);

        $this->assertStringNotContainsString('987654', $token);
        $this->assertStringNotContainsString('123456', $token);
    }
}
