<?php

namespace CallStats\Source;

class QueueLogEvent
{
    /** @var int */
    public $ts;
    /** @var string */
    public $callid;
    /** @var string */
    public $queue;
    /** @var string */
    public $agent;
    /** @var string */
    public $event;
    /** @var string[] fields after the 5th */
    public $data;
    /** @var string */
    public $raw;

    public function __construct(int $ts, string $callid, string $queue, string $agent, string $event, array $data, string $raw)
    {
        $this->ts = $ts;
        $this->callid = $callid;
        $this->queue = $queue;
        $this->agent = $agent;
        $this->event = $event;
        $this->data = $data;
        $this->raw = $raw;
    }
}
