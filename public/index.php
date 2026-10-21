<?php

use CallStats\Db;
use CallStats\Web\App;

$config = require __DIR__ . '/../src/bootstrap.php';

$db = Db::connect(
    (string) $config->get('db.callstats.dsn'),
    (string) $config->get('db.callstats.user'),
    (string) $config->get('db.callstats.pass', '')
);

$app = new App($config, $db);
// Later tasks register page handlers here: $app->route('stats', function (array $get, array $post): string { ... });
$app->handle($_GET, $_POST, $_SERVER);
