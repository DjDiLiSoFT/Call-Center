<?php

namespace CallStats\Tests\Unit;

use CallStats\Source\QueueLogParser;
use PHPUnit\Framework\TestCase;

class QueueLogParserTest extends TestCase
{
    public function test_parses_ringnoanswer(): void
    {
        $e = QueueLogParser::parse('1792556228|1792556228.239671|1000|1010|RINGNOANSWER|0');
        $this->assertSame('RINGNOANSWER', $e->event);
        $this->assertSame('1010', $e->agent);
        $this->assertSame(['0'], $e->data);
        $this->assertSame(1792556228, $e->ts);
    }

    public function test_parses_enterqueue_with_empty_field(): void
    {
        $e = QueueLogParser::parse('1792549722|1792549722.239533|1000|NONE|ENTERQUEUE||905290506|1');
        $this->assertSame(['', '905290506', '1'], $e->data);
    }

    public function test_returns_null_for_garbage(): void
    {
        $this->assertNull(QueueLogParser::parse('abc'));
        $this->assertNull(QueueLogParser::parse(''));
    }
}
