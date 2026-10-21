<?php

namespace CallStats\Web;

class Csrf
{
    private const KEY = 'csrf';

    /** @var array */
    private $session;

    public function __construct(array &$session)
    {
        $this->session = &$session;
    }

    public function token(): string
    {
        if (empty($this->session[self::KEY]) || !is_string($this->session[self::KEY])) {
            $this->session[self::KEY] = bin2hex(random_bytes(32));
        }
        return $this->session[self::KEY];
    }

    public function verify(?string $t): bool
    {
        if ($t === null || empty($this->session[self::KEY]) || !is_string($this->session[self::KEY])) {
            return false;
        }
        return hash_equals($this->session[self::KEY], $t);
    }
}
