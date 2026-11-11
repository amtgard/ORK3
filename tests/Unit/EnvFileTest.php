<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ork3-envfile-' . uniqid('', true);
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @chmod($file, 0644);
                    @unlink($file);
                }
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testSkipsBlankLinesAndComments(): void
    {
        $path = $this->writeEnv(<<<'ENV'


# comment
VISIBLE=yes
ENV);

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $this->assertSame('yes', getenv('VISIBLE'));
        $this->assertCount(1, $this->keySetTraces($parser));
    }

    public function testExportPrefix(): void
    {
        $path = $this->writeEnv("export EXPORTED=from-export\n");

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $this->assertSame('from-export', getenv('EXPORTED'));
    }

    public function testSingleQuotedValue(): void
    {
        $path = $this->writeEnv("SINGLE='literal\\n'\n");

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $this->assertSame('literal\\n', getenv('SINGLE'));
    }

    public function testDoubleQuotedEscapesAndDollarNotExpanded(): void
    {
        $path = $this->writeEnv(<<<'ENV'
DOUBLE="line1\nline2\"slash\\tail"
DOLLAR="$NOT_EXPANDED"
ENV);

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $this->assertSame("line1\nline2\"slash\\tail", getenv('DOUBLE'));
        $this->assertSame('$NOT_EXPANDED', getenv('DOLLAR'));
    }

    public function testUnquotedValue(): void
    {
        $path = $this->writeEnv("PLAIN=unquoted-value\n");

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $this->assertSame('unquoted-value', getenv('PLAIN'));
    }

    public function testInvalidKeysAreIgnored(): void
    {
        $path = $this->writeEnv("9BAD=start\nBAD-DASH=dash\nGOOD=ok\n");

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $this->assertFalse(getenv('9BAD'));
        $this->assertFalse(getenv('BAD-DASH'));
        $this->assertSame('ok', getenv('GOOD'));
    }

    public function testMissingFileTracesAndDoesNotThrow(): void
    {
        $parser = new EnvFile();
        $parser->loadFromFile($this->tmpDir . '/missing.env');

        $this->assertSame(
            [['level' => 'debug', 'event' => 'env_file_missing']],
            $parser->getTrace()
        );
    }

    public function testUnreadableFileTracesAndDoesNotThrow(): void
    {
        $path = $this->tmpDir . '/locked.env';
        file_put_contents($path, "SECRET=hidden\n");
        chmod($path, 0000);

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $events = array_column($parser->getTrace(), 'event');
        $this->assertContains('env_file_unreadable', $events);
        $this->assertFalse(getenv('SECRET'));

        chmod($path, 0644);
    }

    public function testFillUnsetKeepsExistingValue(): void
    {
        putenv('KEEP_ME=original');
        $_ENV['KEEP_ME'] = 'original';

        $path = $this->writeEnv("KEEP_ME=from-file\nNEW_FROM_FILE=fresh\n");

        $parser = new EnvFile();
        $parser->loadFromFile($path, true);

        $this->assertSame('original', getenv('KEEP_ME'));
        $this->assertSame('fresh', getenv('NEW_FROM_FILE'));

        $events = $parser->getTrace();
        $this->assertContains(
            ['level' => 'debug', 'event' => 'env_key_kept', 'detail' => 'KEEP_ME'],
            $events
        );
        $this->assertContains(
            ['level' => 'debug', 'event' => 'env_key_set', 'detail' => 'NEW_FROM_FILE'],
            $events
        );

        putenv('KEEP_ME');
        putenv('NEW_FROM_FILE');
        unset($_ENV['KEEP_ME'], $_ENV['NEW_FROM_FILE'], $_SERVER['KEEP_ME'], $_SERVER['NEW_FROM_FILE']);
    }

    public function testFillUnsetFalseOverwritesExisting(): void
    {
        putenv('OVERWRITE=before');
        $_ENV['OVERWRITE'] = 'before';

        $path = $this->writeEnv("OVERWRITE=after\n");

        $parser = new EnvFile();
        $parser->loadFromFile($path, false);

        $this->assertSame('after', getenv('OVERWRITE'));
        $this->assertContains(
            ['level' => 'debug', 'event' => 'env_key_set', 'detail' => 'OVERWRITE'],
            $parser->getTrace()
        );

        putenv('OVERWRITE');
        unset($_ENV['OVERWRITE'], $_SERVER['OVERWRITE']);
    }

    public function testIgnoresLinesWithoutEqualsSign(): void
    {
        $path = $this->writeEnv("NO_EQUALS\nGOOD=ok\n");

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $this->assertFalse(getenv('NO_EQUALS'));
        $this->assertSame('ok', getenv('GOOD'));

        putenv('GOOD');
        unset($_ENV['GOOD'], $_SERVER['GOOD']);
    }

    public function testEmptyValue(): void
    {
        $path = $this->writeEnv("EMPTY=\n");

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $this->assertSame('', getenv('EMPTY'));

        putenv('EMPTY');
        unset($_ENV['EMPTY'], $_SERVER['EMPTY']);
    }

    public function testUnclosedQuoteForms(): void
    {
        $path = $this->writeEnv("SINGLE_OPEN='no-close\nDOUBLE_OPEN=\"no-close\n");

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $this->assertSame('no-close', getenv('SINGLE_OPEN'));
        $this->assertSame('no-close', getenv('DOUBLE_OPEN'));

        putenv('SINGLE_OPEN');
        putenv('DOUBLE_OPEN');
        unset($_ENV['SINGLE_OPEN'], $_ENV['DOUBLE_OPEN'], $_SERVER['SINGLE_OPEN'], $_SERVER['DOUBLE_OPEN']);
    }

    public function testDoubleQuotedUnknownEscapeKeepsBackslash(): void
    {
        $path = $this->writeEnv("UNKNOWN=\"\\z\"\n");

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $this->assertSame('\\z', getenv('UNKNOWN'));

        putenv('UNKNOWN');
        unset($_ENV['UNKNOWN'], $_SERVER['UNKNOWN']);
    }

    public function testAssignsToEnvAndServerSuperglobals(): void
    {
        $path = $this->writeEnv("TRIPLE=assigned\n");

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $this->assertSame('assigned', $_ENV['TRIPLE'] ?? null);
        $this->assertSame('assigned', $_SERVER['TRIPLE'] ?? null);

        putenv('TRIPLE');
        unset($_ENV['TRIPLE'], $_SERVER['TRIPLE']);
    }

    public function testTraceRecordsDoNotContainValues(): void
    {
        $secret = 'fixture-secret-value-' . uniqid('', true);
        $path = $this->writeEnv('TRACE_ONLY_KEY=' . $secret . "\n");

        $parser = new EnvFile();
        $parser->loadFromFile($path);

        $encoded = json_encode($parser->getTrace(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertSame(
            ['level' => 'debug', 'event' => 'env_key_set', 'detail' => 'TRACE_ONLY_KEY'],
            $parser->getTrace()[0]
        );

        putenv('TRACE_ONLY_KEY');
        unset($_ENV['TRACE_ONLY_KEY'], $_SERVER['TRACE_ONLY_KEY']);
    }

    private function writeEnv(string $contents): string
    {
        $path = $this->tmpDir . '/test.env';
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @return list<array{level: string, event: string, detail?: string}>
     */
    private function keySetTraces(EnvFile $parser): array
    {
        return array_values(array_filter(
            $parser->getTrace(),
            static fn (array $row): bool => ($row['event'] ?? '') === 'env_key_set'
        ));
    }
}
