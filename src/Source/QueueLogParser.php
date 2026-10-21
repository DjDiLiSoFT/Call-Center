<?php

namespace CallStats\Source;

class QueueLogParser
{
    public static function parse(string $line): ?QueueLogEvent
    {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            return null;
        }
        $f = explode('|', $line);
        if (count($f) < 5 || $f[0] === '' || !ctype_digit($f[0])) {
            return null;
        }
        return new QueueLogEvent((int)$f[0], $f[1], $f[2], $f[3], $f[4], array_slice($f, 5), $line);
    }
}
