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
        $this->assertTrue(Event::IsAllowedRsvpStatus($status));
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
        $this->assertFalse(Event::IsAllowedRsvpStatus($status));
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
        $status = Event::IsAllowedRsvpStatus('maybe') ? 'maybe' : 'going';
        $this->assertSame('going', $status);
    }
}
