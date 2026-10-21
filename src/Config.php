<?php

namespace CallStats;

class Config
{
    /** @var array */
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function load($path)
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Config file not found: ' . $path);
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new \RuntimeException('Config file must return an array: ' . $path);
        }
        return new self($data);
    }

    /**
     * Dotted-key lookup, e.g. get('db.callstats.dsn').
     */
    public function get($key, $default = null)
    {
        $node = $this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }
}
