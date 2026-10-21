<?php

namespace CallStats\Build;

/**
 * One customer call (one linkedid), mirrors table `calls` plus its operator outcomes.
 */
class CallRecord
{
    /** @var string linkedid */
    public $callKey;
    /** @var string 'queue'|'direct'|'outbound'|'internal' */
    public $kind;
    /** @var string|null */
    public $queue;
    /** @var string */
    public $startedAt;
    /** @var string|null */
    public $answeredAt;
    /** @var string */
    public $endedAt;
    /** @var string */
    public $clientNumber = '';
    /** @var string|null */
    public $answeredBy;
    /** @var int */
    public $waitSec = 0;
    /** @var int */
    public $talkSec = 0;
    /** @var string|null */
    public $recordingFile;
    /** @var bool queue call built from CDR only (no queue_log) */
    public $approx = false;
    /** @var AgentOutcome[] */
    public $agents = [];
    /** @var array<int,array{from:string,to:string,at:string}> */
    public $transfers = [];
    /** @var array{cdr:string[],qlog:string[]} */
    public $sources = ['cdr' => [], 'qlog' => []];
}
