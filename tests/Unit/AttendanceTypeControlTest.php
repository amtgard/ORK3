<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AttendanceTypeControlTest extends TestCase
{
    public function testActiveOption(): void
    {
        $h = ork_attendance_type_control('online');
        $this->assertSame(1, substr_count($h, 'aria-pressed="true"'));
        $this->assertSame(2, substr_count($h, 'aria-pressed="false"'));
        $this->assertMatchesRegularExpression('/class="orn-type-opt orn-type-active" data-value="online" aria-pressed="true">Online</', $h);
        $this->assertStringContainsString('In-Person', $h);
        $this->assertStringContainsString('data-name="AttendanceType"', $h);
    }

    public function testJunkNormalizesToAll(): void
    {
        $h = ork_attendance_type_control('bogus');
        $this->assertMatchesRegularExpression('/data-value="all" aria-pressed="true">All</', $h);
        $this->assertStringContainsString('data-value="all">', explode('>', $h, 2)[0] . '>');
    }

    public function testEscaping(): void
    {
        $h = ork_attendance_type_control('all', '"><script>x</script>');
        $this->assertStringNotContainsString('<script>', $h);
        $this->assertStringContainsString('&lt;script&gt;', $h);
    }

    public function testNote(): void
    {
        $this->assertSame('Showing in-person sign-ins only.', ork_attendance_type_note('in_person'));
        $this->assertSame('Showing online sign-ins only.', ork_attendance_type_note('online'));
        $this->assertSame('', ork_attendance_type_note('all'));
        $this->assertSame('', ork_attendance_type_note(null));
        $this->assertSame('', ork_attendance_type_note('bogus'));
    }
}
