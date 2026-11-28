<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Hardening for the Circle of Knights controllers: every routable action takes
 * only optional parameters (a bare route must answer, not 500), and every write
 * in the Ajax controller refuses anything but POST.
 */
final class KnightCircleControllersTest extends TestCase
{
    private const WRITES = ['candidate_add', 'candidate_move', 'note_add', 'note_delete', 'config_save', 'gmok_set', 'deputy_add', 'admin_remove'];
    private const READS  = ['roster', 'board', 'candidate', 'admins'];

    public function testRouteMethodsHaveNoRequiredParameters(): void
    {
        require_once DIR_UI . 'controller/controller.KnightCircle.php';
        require_once DIR_UI . 'controller/controller.KnightCircleAjax.php';

        $routes = [Controller_KnightCircle::class => ['index']];
        $routes[Controller_KnightCircleAjax::class] = array_merge(self::READS, self::WRITES);
        foreach ($routes as $class => $methods) {
            foreach ($methods as $name) {
                $this->assertTrue(method_exists($class, $name), "$class::$name");
                $this->assertSame(0, (new ReflectionMethod($class, $name))->getNumberOfRequiredParameters(), "$class::$name");
            }
        }
    }

    public function testEveryWriteRequiresPost(): void
    {
        $source = file_get_contents(DIR_UI . 'controller/controller.KnightCircleAjax.php');
        $this->assertNotFalse($source);
        foreach (self::WRITES as $name) {
            $this->assertMatchesRegularExpression('/function ' . $name . '\(\$p = null\)\s*\{[^}]*\$this->requirePost\(\);/s', $source, $name);
        }
        foreach (self::READS as $name) {
            $body = substr($source, (int) strpos($source, 'function ' . $name . '('), 400);
            $this->assertStringNotContainsString('$_POST', substr($body, 0, (int) strpos($body, '}')), $name);
        }
    }

    public function testNoNativeDialogsInAssets(): void
    {
        $js = file_get_contents(DIR_UI . 'template/default/script/knightcircle.js');
        $this->assertNotFalse($js);
        $this->assertDoesNotMatchRegularExpression('/(?<![.\w])(alert|confirm|prompt)\s*\(/', $js);
    }
}
