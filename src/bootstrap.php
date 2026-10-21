<?php

use CallStats\Config;

require_once __DIR__ . '/autoload.php';

$configPath = getenv('CALLSTATS_CONFIG') ?: __DIR__ . '/../config/config.php';
$config = Config::load($configPath);
date_default_timezone_set((string) $config->get('timezone', 'UTC'));

return $config;
