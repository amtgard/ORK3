<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guards the controller/model -> service seam for missing Token arguments.
 *
 * Service methods in system/lib/ork3 resolve the acting user with
 * IsAuthorized($request['Token']) and gate their writes on HasAuthority().
 * A caller that omits Token therefore resolves to mundane_id 0 and is refused.
 *
 * Until 307dcad0 ("Polish: harden ork_session helpers") this was survivable:
 * IsAuthorized_h() short-circuited on $_SESSION['is_authorized_mundane_id']
 * before it ever inspected the token, so a missing Token still resolved to the
 * logged-in user. Removing that short-circuit was correct -- it accepted any
 * token once a session existed -- but it turned three long-dormant call sites
 * into live failures:
 *
 *   - Controller_Admin adddues        (PR #505) -- dues silently not saved
 *   - Controller_Admin one_shot       (PR #506) -- face image never uploaded
 *   - Model_Reports get_authorization_list (PR #506) -- empty admin list
 *
 * None of these were catchable by the existing suite: the services are correct
 * and well covered, and phpunit.xml.dist does not include orkui/ at all. The
 * defect lives purely in the wiring, so this test inspects the wiring.
 *
 * Two shapes are checked, matching the two shapes of the bugs found:
 *   A. a call site passing an array literal to a token-gated model method
 *   B. a model method building a request array literal for a token-gated
 *      service method
 *
 * Call sites that pass arguments positionally or forward an opaque variable
 * are out of scope -- they cannot be resolved statically and are covered by
 * the service-level tests instead.
 */
final class TokenGatedCallSiteTest extends TestCase
{
    /** Methods reached with no Token by design (public/self-service surfaces). */
    private const ALLOWED = [
        // e.g. 'Model_Foo::bar',
    ];

    public function testNoCallSitePassesAnArrayLiteralWithoutAToken(): void
    {
        $offenders = [];

        foreach ($this->sources(ORK3_ROOT . '/orkui/controller/*.php') as $file => $src) {
            foreach ($this->callSites($src, array_keys($this->modelForwarders())) as $site) {
                if (!$this->isArrayLiteral($site['args'])) {
                    continue;
                }
                if ($this->mentionsToken($site['args'])) {
                    continue;
                }
                $offenders[] = sprintf(
                    '%s:%d %s() -> %s',
                    basename($file),
                    $site['line'],
                    $site['name'],
                    implode(',', $this->modelForwarders()[$site['name']])
                );
            }
        }

        $offenders = array_values(array_diff($offenders, self::ALLOWED));

        $this->assertSame([], $offenders, sprintf(
            "Call sites reach a token-gated service without supplying a Token.\n"
            . "IsAuthorized() will resolve mundane_id 0 and the service will refuse the\n"
            . "request, typically without surfacing an error. Add\n"
            . "    'Token' => \$this->session->token,\n"
            . "to:\n  %s",
            implode("\n  ", $offenders)
        ));
    }

    public function testNoModelBuildsATokenGatedRequestWithoutAToken(): void
    {
        $gated = $this->tokenGatedServiceMethods();
        $offenders = [];

        foreach ($this->sources(ORK3_ROOT . '/orkui/model/model.*.php') as $file => $src) {
            foreach ($this->methodBodies($src) as $method => $body) {
                // Only bodies that both build a request literal and call a gated service.
                if (!preg_match('/\$request\s*=\s*(array\s*\(|\[)/', $body, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $called = [];
                foreach ($this->serviceCalls($body) as $svc) {
                    if (isset($gated[$svc])) {
                        $called[] = $svc;
                    }
                }
                if ($called === []) {
                    continue;
                }
                // $m[1] captures 'array(' or '['; the bracket is its last character.
                $literal = $this->balanced($body, $m[1][1] + strlen($m[1][0]) - 1);
                if ($this->mentionsToken($literal)) {
                    continue;
                }
                $offenders[] = sprintf(
                    '%s::%s() -> %s',
                    basename($file, '.php'),
                    $method,
                    implode(',', array_unique($called))
                );
            }
        }

        $offenders = array_values(array_diff($offenders, self::ALLOWED));

        $this->assertSame([], $offenders, sprintf(
            "Model methods build a request for a token-gated service without a Token.\n"
            . "The service will resolve mundane_id 0 and refuse or return an empty\n"
            . "result. Add 'Token' => \$this->session->token to:\n  %s",
            implode("\n  ", $offenders)
        ));
    }

    public function testDetectorFindsTheServiceMethodsItIsMeantToGuard(): void
    {
        // Self-check: if the detector silently matches nothing (a refactor renames
        // IsAuthorized, or the request key changes) both tests above pass vacuously.
        $gated = $this->tokenGatedServiceMethods();

        $this->assertGreaterThan(50, count($gated), 'Token-gated service scan matched implausibly few methods.');
        foreach (['AddDues', 'AddAward', 'AddOneShotFaceImage', 'GetAuthorizations'] as $known) {
            $this->assertArrayHasKey($known, $gated, "$known should be detected as token-gated.");
        }
        $this->assertArrayHasKey('add_dues', $this->modelForwarders());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array<string,string> path => source */
    private function sources(string $glob): array
    {
        $out = [];
        foreach (glob($glob) ?: [] as $path) {
            $out[$path] = (string) file_get_contents($path);
        }
        return $out;
    }

    /** Service methods that resolve an actor from $request['Token']. */
    private function tokenGatedServiceMethods(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        foreach ($this->sources(ORK3_ROOT . '/system/lib/ork3/class.*.php') as $src) {
            foreach ($this->methodBodies($src) as $name => $body) {
                if (preg_match('/IsAuthorized\s*\(\s*\$request\[\s*[\'"]Token[\'"]\s*\]/', $body)) {
                    $cache[$name] = true;
                }
            }
        }
        return $cache;
    }

    /** Model methods that forward to a token-gated service method. */
    private function modelForwarders(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $gated = $this->tokenGatedServiceMethods();
        $cache = [];
        foreach ($this->sources(ORK3_ROOT . '/orkui/model/model.*.php') as $src) {
            foreach ($this->methodBodies($src) as $name => $body) {
                foreach ($this->serviceCalls($body) as $svc) {
                    if (isset($gated[$svc])) {
                        $cache[$name][] = $svc;
                    }
                }
            }
        }
        foreach ($cache as $k => $v) {
            $cache[$k] = array_values(array_unique($v));
        }
        return $cache;
    }

    /** @return array<string,string> method name => body */
    private function methodBodies(string $src): array
    {
        $lines = explode("\n", $src);
        $bodies = [];
        $cur = null;
        $start = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $m)) {
                if ($cur !== null) {
                    $bodies[$cur] = implode("\n", array_slice($lines, $start, $i - $start));
                }
                $cur = $m[1];
                $start = $i;
            }
        }
        if ($cur !== null) {
            $bodies[$cur] = implode("\n", array_slice($lines, $start));
        }
        return $bodies;
    }

    /** CamelCase service-style calls inside a body. */
    private function serviceCalls(string $body): array
    {
        preg_match_all('/->\s*([A-Z][A-Za-z0-9_]*)\s*\(/', $body, $m);
        return $m[1];
    }

    /** @return list<array{name:string,args:string,line:int}> */
    private function callSites(string $src, array $names): array
    {
        if ($names === []) {
            return [];
        }
        $sites = [];
        $pattern = '/->\s*(' . implode('|', array_map('preg_quote', $names)) . ')\s*\(/';
        if (!preg_match_all($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($m[0] as $i => $hit) {
            $open = strpos($src, '(', $hit[1]);
            if ($open === false) {
                continue;
            }
            $sites[] = [
                'name' => $m[1][$i][0],
                'args' => $this->balanced($src, $open),
                'line' => substr_count(substr($src, 0, $hit[1]), "\n") + 1,
            ];
        }
        return $sites;
    }

    /** Text of the bracketed run starting at $open, brackets included. */
    private function balanced(string $src, int $open): string
    {
        $pairs = ['(' => ')', '[' => ']'];
        $openChar = $src[$open];
        if (!isset($pairs[$openChar])) {
            return '';
        }
        $closeChar = $pairs[$openChar];
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === $openChar) {
                $depth++;
            } elseif ($src[$i] === $closeChar) {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $open, $i - $open + 1);
                }
            }
        }
        return '';
    }

    /** True when the argument text is a bare array literal (not a merge or variable). */
    private function isArrayLiteral(string $args): bool
    {
        $inner = trim($args);
        $inner = trim(substr($inner, 1, -1));
        return (bool) preg_match('/^(array\s*\(|\[)/', $inner);
    }

    private function mentionsToken(string $text): bool
    {
        return (bool) preg_match('/token/i', $text);
    }
}
