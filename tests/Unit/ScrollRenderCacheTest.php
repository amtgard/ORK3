<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Item 38, the caching half. Scroll::Render() is a pure function of its scroll
 * array and the partial files, so a reprint is a cache hit.
 *
 * Two properties matter more than the speed, and both are what these tests are
 * for:
 *
 *   1. THE CACHE IS PER CERTIFICATE, NEVER PER PAGE. A 200-certificate page is
 *      megabytes and memcached's per-item limit is 1 MB — an oversized set() fails
 *      SILENTLY, so a page-sized entry would look cached and never be.
 *   2. NO VIEWER IS IN THE KEY, and none may be. That is safe only because the
 *      (viewer, award) gate has already run before Render() sees anything; caching
 *      an authority decision is how this surface leaked once before.
 *
 * These assert on the key's construction rather than on memcached, so they hold
 * on a host with no daemon (the test bootstrap stubs the extension with a get()
 * that always misses).
 */
final class ScrollRenderCacheTest extends TestCase
{
    /** @return array<string, mixed> */
    private function scroll(array $overrides = []): array
    {
        return $overrides + [
            'order'       => 'rose',
            'rank'        => 3,
            'ranked'      => true,
            'variant'     => '',
            'title'       => 'Order of the Rose',
            'titlePhrase' => '',
            'player'      => 'Haliax Duskstrider',
            'pronoun'     => 'their',
            'reason'      => 'for service',
            'date'        => '13th May 2024',
            'givenBy'     => 'Someone',
            'issuerTitle' => 'Barony',
            'issuerName'  => 'Mordengaard',
            'useThe'      => true,
            'useOf'       => true,
            'heraldry'    => '',
            'flavour'     => '',
            'primary'     => '#111',
            'secondary'   => '#222',
            'resources'   => [],
            'recipe'      => [],
        ];
    }

    private function key(array $scroll, string $artSize = Scroll::ART_MASTER): string
    {
        $m = new ReflectionMethod(Scroll::class, 'renderCacheKey');
        $m->setAccessible(true);

        return (string) $m->invoke(null, $scroll, $artSize);
    }

    public function testTheSameScrollKeysTheSame(): void
    {
        $this->assertSame($this->key($this->scroll()), $this->key($this->scroll()));
    }

    /**
     * The whole scroll array is the key, which is the only construction that
     * cannot forget a field. A key hand-assembled from awards_id + variant would
     * have served a reprint with a different recipe the first one's markup.
     */
    public function testEveryFieldThatChangesTheCertificateChangesTheKey(): void
    {
        $base = $this->key($this->scroll());

        $differs = [
            'variant'     => ['variant' => 'owl'],
            'recipe'      => ['recipe' => ['ground' => 'linen', 'border' => 'knot']],
            'pronoun'     => ['pronoun' => 'her'],
            'title'       => ['title' => 'Order of the Lion'],
            'customName'  => ['title' => 'Order of the Raider'],
            'titlePhrase' => ['titlePhrase' => 'with the title of'],
            'rank'        => ['rank' => 9],
            'player'      => ['player' => 'Someone Else'],
            'reason'      => ['reason' => 'for something else'],
            'heraldry'    => ['heraldry' => '/assets/kingdom/0004.png'],
            'primary'     => ['primary' => '#900'],
        ];

        foreach ($differs as $label => $override) {
            $this->assertNotSame(
                $base,
                $this->key($this->scroll($override)),
                'two prints differing in ' . $label . ' must not share cached markup'
            );
        }
    }

    /** A display-size render must not be served the master's markup, or vice versa. */
    public function testTheArtSizeIsPartOfTheKey(): void
    {
        $this->assertNotSame(
            $this->key($this->scroll(), Scroll::ART_MASTER),
            $this->key($this->scroll(), Scroll::ART_DISPLAY)
        );

        // An unknown size resolves to the master everywhere, key included.
        $this->assertSame(
            $this->key($this->scroll(), Scroll::ART_MASTER),
            $this->key($this->scroll(), 'thumbnail')
        );
    }

    /**
     * A .tpl edit at deploy time must invalidate everything already composed, and
     * the stamp is taken across ALL scroll partials rather than per order because
     * an order partial pulls in the shared _border / _text / _heraldry layers.
     */
    public function testTheKeyCarriesAPartialGenerationStamp(): void
    {
        $m = new ReflectionMethod(Scroll::class, 'partialGeneration');
        $m->setAccessible(true);
        $stamp = (int) $m->invoke(null);

        $this->assertGreaterThan(0, $stamp, 'no scroll partials were found to stamp');

        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/system/lib/ork3/class.Scroll.php');
        $this->assertStringContainsString("'tpl'  => self::partialGeneration()", $src);
        $this->assertStringContainsString("revised-frontend/scroll/*.tpl", $src);
    }

    /**
     * The per-item ceiling exists because memcached drops an oversized value with
     * no error anyone checks. It has to stay below 1 MB with room to spare.
     */
    public function testTheCacheRefusesAnythingApproachingTheMemcachedItemLimit(): void
    {
        $c = new ReflectionClass(Scroll::class);
        $max = (int) $c->getConstant('RENDER_CACHE_MAX_BYTES');

        $this->assertGreaterThan(0, $max);
        $this->assertLessThan(1024 * 1024, $max);
    }

    /**
     * The regression this whole class guards. If a viewer, a session, a token or a
     * permission ever appears in the key, the cache has started answering an
     * authority question.
     */
    public function testNoViewerOrPermissionIsAnywhereNearTheKey(): void
    {
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/system/lib/ork3/class.Scroll.php');
        $from = (int) strpos($src, 'private static function renderCacheKey');
        $to   = (int) strpos($src, 'private static function partialGeneration');
        $body = substr($src, $from, $to - $from);

        foreach (['viewer', 'Viewer', 'session', 'token', 'Allowed', 'HasAuthority'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, 'the render cache key must not touch ' . $forbidden);
        }
    }
}
