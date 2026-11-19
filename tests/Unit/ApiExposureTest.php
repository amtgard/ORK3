<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * JsonServer publishes every public PascalCase method on a whitelisted class.
 *
 * SCOPE: this test guards OfficerPosition ONLY. registeredClassProvider()
 * intersects the whitelist with a hardcoded ['OfficerPosition'], so adding a
 * new class to orkservice/Json/index.php does NOT automatically get a check
 * here -- Player, Kingdom, Award and the rest of the existing registry have
 * never been swept for ungated public methods, and widening the provider
 * would immediately go red against that pre-existing surface, which is a
 * separate, much larger piece of work than this task. Within OfficerPosition,
 * this IS the regression guard: without it, the next public PascalCase method
 * added to the class is silently published, ungated.
 */
final class ApiExposureTest extends TestCase
{
    /** Methods deliberately public and safe to call without a token. */
    private const REVIEWED_PUBLIC = [
        'OfficerPosition' => [
            'GetPositions',
            'ResolvePositionId', 'ResolveCanonicalKey', 'PermissionKeyFor',
        ],
    ];

    /** @return list<array{0:string}> */
    public static function registeredClassProvider(): array
    {
        $src = file_get_contents(__DIR__ . '/../../orkservice/Json/index.php');
        preg_match_all("/'([A-Za-z]+)'/", $src, $m);
        return array_map(static fn ($c) => [$c], array_values(array_intersect($m[1], ['OfficerPosition'])));
    }

    /** @dataProvider registeredClassProvider */
    public function testEveryPublishedMethodOnOfficerPositionIsGatedOrReviewed(string $class): void
    {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            // ONLY these two are genuinely unreachable. Casing is NOT a filter:
            // method_exists() is case-insensitive and the caller picks the casing,
            // so a public lowerStart() answers ?call=Class/LowerStart.
            if ($name === '__construct' || str_contains($name, '_')) {
                continue;
            }
            if (in_array($name, self::REVIEWED_PUBLIC[$class] ?? [], true)) {
                continue;
            }
            $body = $this->methodBody($method);
            self::assertStringContainsString(
                'IsAuthorized',
                $body,
                "{$class}::{$name} is published by orkservice/Json but never checks a token. "
                . 'Either gate it, rename it with an underscore, make it private, or '
                . 'add it to REVIEWED_PUBLIC with a reason. Renaming it lowercase-initial '
                . 'does NOT hide it -- PHP method names are case-insensitive.'
            );
        }
    }

    public function testSqlFragmentBuildersAreNotPublishable(): void
    {
        // method_exists is CASE-INSENSITIVE, so a lowercase rename would still pass a
        // naive check here and still be dispatchable. Underscores are the real lever.
        self::assertFalse(method_exists('OfficerPosition', 'DisplayTitleSql'));
        self::assertFalse(method_exists('OfficerPosition', 'SortOrderSql'));
        self::assertTrue(method_exists('OfficerPosition', 'display_title_sql'));
        self::assertTrue(method_exists('OfficerPosition', 'sort_order_sql'));
    }

    public function testRbacServiceIsNotRegistered(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkservice/Json/index.php');
        self::assertStringNotContainsString(
            "'RBACService'",
            $src,
            'RBACService has a 21-method read surface (GetEffectivePermissions, '
            . 'GetUserRoles, GetKingdomRoleAssignments) that has had no authorization '
            . 'pass. Registering it publishes all of them.'
        );
    }

    /**
     * Exact source of one method's body, comments stripped.
     *
     * Two bugs this deliberately avoids, both proven to produce false negatives
     * in an earlier version of this test:
     *
     * 1. Anchoring on strpos($source, 'function NAME(') finds the first TEXTUAL
     *    occurrence of that string in the whole file -- a docblock or comment
     *    mentioning "function NAME(" in prose anchors the slice to the wrong
     *    place entirely. ReflectionMethod::getStartLine()/getEndLine() give the
     *    exact declared boundaries instead, with no text search involved.
     *
     * 2. Terminating the slice at the next "\n    public function " stops ONLY
     *    at a non-static public method at 4-space indent -- not at `private`,
     *    not `protected`, not `public static`. A method followed by private
     *    helpers (as TransitionOfficer is) overshoots straight through those
     *    helpers into the NEXT public method's leading docblock, which can
     *    itself contain the word "IsAuthorized" in prose and make the assertion
     *    pass for a method that never calls it. getEndLine() has no such gap:
     *    it is the line of the method's own closing brace, period.
     *
     * Comments are then stripped via token_get_all() so a docblock that merely
     * MENTIONS IsAuthorized (as TransitionOfficer's own neighbours do, in
     * describing the gate-then-delegate pattern) cannot satisfy the assertion
     * on behalf of a method that doesn't actually call it.
     */
    private function methodBody(ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        if ($file === false) {
            return '';
        }
        $lines = file($file);
        if ($lines === false) {
            return '';
        }
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        $slice = implode('', array_slice($lines, $start, max(0, $end - $start)));
        return $this->stripComments($slice);
    }

    private function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all('<?php ' . $code) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }
}
