<?php

namespace CallStats\Tests\Unit;

use CallStats\Web\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_clock_is_short_minutes_and_seconds(): void
    {
        $this->assertSame('0:05', Format::clock(5));
        $this->assertSame('1:51', Format::clock(111));
        $this->assertSame('1:02:03', Format::clock(3723));
        $this->assertSame('—', Format::clock(null));
    }
}
