<?php

namespace CallStats\Build;

/**
 * Maps Asterisk channel / queue member strings to FreePBX extension numbers.
 *
 * Resolution order:
 *  1. Exact known extension ('1001') -> itself.
 *  2. Channel string (Local/PJSIP/SIP/IAX2) whose number is a known extension -> that number.
 *  3. Exact queue member name from $namesToExt -> mapped extension.
 *  4. Otherwise the raw string unchanged.
 */
class AgentResolver
{
    private const CHANNEL_RE = '#^(?:Local|PJSIP|SIP|IAX2)/(\d+)#';

    /** @var string[] */
    private $extensions;

    /** @var array<string,string> */
    private $namesToExt;

    /**
     * @param string[]             $extensions  FreePBX internal numbers
     * @param array<string,string> $namesToExt  queue member name => extension
     */
    public function __construct(array $extensions, array $namesToExt = [])
    {
        $this->extensions = array_map('strval', $extensions);
        $this->namesToExt = $namesToExt;
    }

    public function resolve(string $raw): string
    {
        if (in_array($raw, $this->extensions, true)) {
            return $raw;
        }
        if (preg_match(self::CHANNEL_RE, $raw, $m) && in_array($m[1], $this->extensions, true)) {
            return $m[1];
        }
        if (isset($this->namesToExt[$raw])) {
            return (string) $this->namesToExt[$raw];
        }
        return $raw;
    }

    public function isExtension(string $raw): bool
    {
        return in_array($this->resolve($raw), $this->extensions, true);
    }
}
