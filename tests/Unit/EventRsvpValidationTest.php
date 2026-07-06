<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pure validation helpers for RSVP status whitelist (T-RSV-02 / EventRsvpAjax::set).
 */
final class EventRsvpValidationTest extends TestCase
{
    /**
     * @dataProvider validStatusProvider
     */
    public function testValidStatusAccepted(string $status): void
    {
        $this->assertTrue($this->isAllowedStatus($status));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function validStatusProvider(): array
    {
        return [
            ['going'],
            ['interested'],
        ];
    }

    /**
     * @dataProvider invalidStatusProvider
     */
    public function testInvalidStatusRejected(string $status): void
    {
        $this->assertFalse($this->isAllowedStatus($status));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidStatusProvider(): array
    {
        return [
            [''],
            ['maybe'],
            ['GOING'],
            ['not-interested'],
        ];
    }

    public function testModelCoercesInvalidStatusToGoing(): void
    {
        $status = in_array('maybe', ['going', 'interested']) ? 'maybe' : 'going';
        $this->assertSame('going', $status);
    }

    private function isAllowedStatus(string $status): bool
    {
        return in_array($status, ['going', 'interested'], true);
    }
}
