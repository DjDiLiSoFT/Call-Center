<?php
// CLI: php bin/set-password.php — sets the web login and password (stored as a hash in settings).

use CallStats\Db;
use CallStats\Web\Auth;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$config = require __DIR__ . '/../src/bootstrap.php';

function prompt(string $label, bool $hidden = false): string
{
    echo $label;
    $stty = false;
    if ($hidden && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        $stty = trim((string) @shell_exec('stty -g 2>/dev/null')) !== '';
        if ($stty) {
            register_shutdown_function(function () {
                @shell_exec('stty echo');
            });
            @shell_exec('stty -echo');
        }
    }
    $line = fgets(STDIN);
    if ($stty) {
        @shell_exec('stty echo');
        echo "\n";
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

$login = trim(prompt('Логин: '));
if ($login === '') {
    fwrite(STDERR, "Логин не может быть пустым\n");
    exit(1);
}
$pass = prompt('Пароль (не менее 8 символов): ', true);
if (strlen($pass) < 8) {
    fwrite(STDERR, "Пароль слишком короткий (минимум 8 символов)\n");
    exit(1);
}
if ($pass !== prompt('Повторите пароль: ', true)) {
    fwrite(STDERR, "Пароли не совпадают\n");
    exit(1);
}

$db = Db::connect(
    (string) $config->get('db.callstats.dsn'),
    (string) $config->get('db.callstats.user'),
    (string) $config->get('db.callstats.pass', '')
);
(new Auth($db))->setCredentials($login, $pass);
echo "Готово: учётные данные сохранены.\n";
