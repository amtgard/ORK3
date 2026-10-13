<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class OfficerAdminActionsTest extends TestCase
{
    public function testTheFourNewActionsAreDispatchable(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        foreach (['transition', 'addhistory', 'edithistory', 'deletehistory'] as $action) {
            self::assertMatchesRegularExpression(
                "/case '{$action}':/",
                $src,
                "{$action} must have a dispatch case"
            );
            self::assertMatchesRegularExpression(
                "/'{$action}'\s*=>/",
                $src,
                "{$action} must have a gate entry, or it fails closed as Unknown action"
            );
        }
    }

    public function testEveryActionForwardsTheSessionToken(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        foreach (['actionTransition', 'actionAddHistory', 'actionEditHistory', 'actionDeleteHistory'] as $m) {
            $start = strpos($src, "function {$m}(");
            self::assertNotFalse($start, "{$m} must exist");
            $body = substr($src, $start, 1200);
            self::assertStringContainsString(
                '$this->session->token',
                $body,
                "{$m} must forward the session token -- \$this->__session yields uid 0"
            );
        }
    }

    public function testEditAndDeleteHistoryDoNotSendScope(): void
    {
        // Their domain methods authorize against the ROW's kingdom/park. Sending a
        // caller-supplied scope would invite someone to trust it later.
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        foreach (['actionEditHistory', 'actionDeleteHistory'] as $m) {
            $start = strpos($src, "function {$m}(");
            $body  = substr($src, $start, 1200);
            self::assertStringNotContainsString(
                "'KingdomId'",
                $body,
                "{$m} must not pass a caller-supplied KingdomId"
            );
            self::assertStringNotContainsString(
                "'ParkId'",
                $body,
                "{$m} must not pass a caller-supplied ParkId"
            );
        }
    }
}
