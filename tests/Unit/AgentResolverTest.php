<?php

namespace CallStats\Tests\Unit;

use CallStats\Build\AgentResolver;
use PHPUnit\Framework\TestCase;

class AgentResolverTest extends TestCase
{
    private function resolver(): AgentResolver
    {
        return new AgentResolver(['1001', '1002'], ['Иванов' => '1002']);
    }

    public function test_resolves_known_forms(): void
    {
        $r = $this->resolver();
        $this->assertSame('1001', $r->resolve('1001'));
        $this->assertSame('1001', $r->resolve('Local/1001@from-queue/n'));
        $this->assertSame('1001', $r->resolve('Local/1001@from-queue-0000ce7e;1'));
        $this->assertSame('1001', $r->resolve('PJSIP/1001-00008e77'));
        $this->assertSame('1001', $r->resolve('PJSIP/1001'));
        $this->assertSame('1001', $r->resolve('SIP/1001-0001'));
        $this->assertSame('1002', $r->resolve('Иванов'));
    }

    public function test_unknown_kept_as_is(): void
    {
        $r = $this->resolver();
        $this->assertSame('Petrov', $r->resolve('Petrov'));
        $this->assertSame('PJSIP/552020515-00008e72', $r->resolve('PJSIP/552020515-00008e72'));
        $this->assertFalse($r->isExtension('PJSIP/552020515-00008e72'));
    }

    public function test_is_extension(): void
    {
        $r = $this->resolver();
        $this->assertTrue($r->isExtension('PJSIP/1002-0001'));
        $this->assertTrue($r->isExtension('Иванов'));
        $this->assertFalse($r->isExtension('Petrov'));
    }
}
