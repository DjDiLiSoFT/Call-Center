<?php

// Copy to config/config.php and adjust.
return [
    'timezone' => 'Asia/Tashkent',
    'db' => [
        'callstats' => ['dsn' => 'mysql:host=127.0.0.1;dbname=callstats;charset=utf8', 'user' => 'callstats', 'pass' => ''],
        'source'    => ['dsn' => 'mysql:host=127.0.0.1;dbname=asteriskcdrdb;charset=utf8', 'user' => 'callstats_ro', 'pass' => ''],
        'fpbx'      => ['dsn' => 'mysql:host=127.0.0.1;dbname=asterisk;charset=utf8', 'user' => 'callstats_ro', 'pass' => ''],
    ],
    'queue_log' => ['path' => '/var/log/asterisk/queue_log'],
    'monitor'   => ['path' => '/var/spool/asterisk/monitor'],
    'collector' => ['lookback_minutes' => 120, 'queue_ring_timeout' => 15, 'direct_ring_timeout' => 20, 'max_call_hours' => 24],
];
