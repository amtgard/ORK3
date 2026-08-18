<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommonFunctionsTest extends TestCase
{
    #[DataProvider('validIdProvider')]
    public function testValidId(mixed $id, bool $expected): void
    {
        $this->assertSame($expected, valid_id($id));
    }

    public static function validIdProvider(): array
    {
        return [
            'positive integer' => [1, true],
            'positive numeric string' => ['42', true],
            'zero' => [0, false],
            'negative' => [-1, false],
            'non-numeric string' => ['abc', false],
            'null coerced' => [null, false],
        ];
    }

    public function testEncodeBase64UrlSafeRoundTrip(): void
    {
        $original = 'hello+world/test';
        $encoded = encodeBase64UrlSafe($original);
        $this->assertSame('aGVsbG8rd29ybGQvdGVzdA==', $encoded);
        $this->assertSame($original, decodeBase64UrlSafe($encoded));
    }

    public function testTrimlen(): void
    {
        $this->assertTrue(trimlen('x'));
        $this->assertFalse(trimlen('   '));
        $this->assertFalse(trimlen(''));
    }
}
