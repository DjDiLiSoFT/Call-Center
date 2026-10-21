<?php

namespace CallStats\Tests\Unit;

use CallStats\Build\OutcomeRules;
use PHPUnit\Framework\TestCase;

class OutcomeRulesTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function test_decide(array $kinds, bool $byThis, bool $byOther, string $expected): void
    {
        $this->assertSame($expected, OutcomeRules::decide($kinds, $byThis, $byOther));
    }

    public function cases(): array
    {
        return [
            'rang, nobody answered'        => [['rang'], false, false, 'missed'],
            'rang, colleague answered'     => [['rang'], false, true, 'other_answered'],
            'canceled, nobody answered'    => [['canceled'], false, false, 'caller_hangup'],
            'offline, colleague answered'  => [['offline'], false, true, 'offline'],
            'offline then rang, unanswered' => [['offline', 'rang'], false, false, 'missed'],
            'answered by this'             => [[], true, false, 'answered'],
        ];
    }
}
