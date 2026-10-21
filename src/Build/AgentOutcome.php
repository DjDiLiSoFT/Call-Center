<?php

namespace CallStats\Build;

/**
 * Exactly one outcome of one operator (per direction) in one call.
 *
 * outcome: 'answered' | 'other_answered' | 'missed' | 'caller_hangup' | 'offline' (direction 'in');
 *          'answered' | 'not_connected' (direction 'out').
 */
class AgentOutcome
{
    /** @var string */
    public $extension;
    /** @var string 'in'|'out' */
    public $direction;
    /** @var string */
    public $outcome;
    /** @var int seconds it rang before answer (answered) or in total (not answered) */
    public $ringSec;
    /** @var int */
    public $talkSec;
    /** @var string 'Y-m-d H:i:s' */
    public $firstRingAt;
    /** @var string|null 'Y-m-d H:i:s' */
    public $answeredAt;

    public function __construct(
        string $extension,
        string $direction,
        string $outcome,
        int $ringSec,
        int $talkSec,
        string $firstRingAt,
        ?string $answeredAt
    ) {
        $this->extension = $extension;
        $this->direction = $direction;
        $this->outcome = $outcome;
        $this->ringSec = $ringSec;
        $this->talkSec = $talkSec;
        $this->firstRingAt = $firstRingAt;
        $this->answeredAt = $answeredAt;
    }
}
