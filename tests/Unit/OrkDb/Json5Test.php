<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Json5;
use PHPUnit\Framework\TestCase;

final class Json5Test extends TestCase
{
    public function testDecodeStripsComments(): void
    {
        $decoded = Json5::decode(<<<'JSON5'
{
  // line comment
  "key": "value",
  /* block comment */
  "count": 1
}
JSON5);

        $this->assertSame(['key' => 'value', 'count' => 1], $decoded);
    }

    public function testDecodeFileReadsManifest(): void
    {
        $path = ORK3_ROOT . '/tools/ork-db/manifests/wiring.json5';
        $decoded = Json5::decodeFile($path);

        $this->assertSame(19306, $decoded['mirror']['port']);
        $this->assertSame('ork_test', $decoded['sandbox']['database']);
    }

    public function testDecodeThrowsOnInvalidJson(): void
    {
        $this->expectException(\RuntimeException::class);
        Json5::decode('{not json');
    }

    public function testDecodeFileThrowsWhenMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        Json5::decodeFile('/tmp/does-not-exist-' . uniqid('', true) . '.json5');
    }
}
