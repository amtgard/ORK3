<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * JsonServer publishes every public PascalCase method on a whitelisted class.
 * This test is the regression guard: without it, the next PascalCase method
 * added to a registered class is silently published, ungated.
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
    public function testEveryPublishedMethodIsGatedOrReviewed(string $class): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/system/lib/ork3/class.' . $class . '.php');
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
            $body = $this->methodBody($source, $name);
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

    private function methodBody(string $source, string $name): string
    {
        $start = strpos($source, 'function ' . $name . '(');
        if ($start === false) {
            return '';
        }
        $next = strpos($source, "\n    public function ", $start + 1);
        $next = $next === false ? strlen($source) : $next;
        return substr($source, $start, $next - $start);
    }
}
