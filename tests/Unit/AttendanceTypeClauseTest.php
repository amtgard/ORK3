<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AttendanceTypeClauseTest extends TestCase
{
    public function testNormalize(): void
    {
        $this->assertSame('all', Attendance::NormalizeType('all'));
        $this->assertSame('in_person', Attendance::NormalizeType('in_person'));
        $this->assertSame('online', Attendance::NormalizeType('online'));
        $this->assertSame('all', Attendance::NormalizeType(''));
        $this->assertSame('all', Attendance::NormalizeType(null));
        $this->assertSame('all', Attendance::NormalizeType('ONLINE'));
        $this->assertSame('all', Attendance::NormalizeType("online' OR 1"));
    }

    public function testClause(): void
    {
        $this->assertSame('', Attendance::TypeClause('all'));
        $this->assertSame(' AND a.online = 0', Attendance::TypeClause('in_person'));
        $this->assertSame(' AND att.online = 1', Attendance::TypeClause('online', 'att'));
        $this->assertSame(' AND a.online = 1', Attendance::TypeClause('online', 'a; DROP'));
    }
}
