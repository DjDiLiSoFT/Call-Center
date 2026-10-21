#!/bin/bash
# CallStats installer / updater for FreePBX 16 (Sangoma Linux 7 / CentOS 7). Run as root
# from the unpacked release directory:  bash install/install.sh [--rebuild]
# Idempotent: safe to run again (update). config/config.php is never overwritten.
#   --rebuild   after an update, also rebuild history (2026-08-01 .. today)
set -euo pipefail

APP=/opt/callstats
APP_USER=asterisk
HIST_FROM=2026-08-01
PHP=/usr/bin/php
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DO_REBUILD=0

for a in "$@"; do
    case "$a" in
        --rebuild) DO_REBUILD=1 ;;
        -h|--help) sed -n '2,6p' "$0"; exit 0 ;;
        *) echo "Неизвестный параметр: $a" >&2; exit 2 ;;
    esac
done

say() { printf '\n==> %s\n' "$*"; }
die() { printf '\nОШИБКА: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "запустите от root"
[ -f "$SRC/bin/collect.php" ] && [ -f "$SRC/sql/schema.sql" ] || die "запускайте из распакованного архива: bash install/install.sh"
[ -x "$PHP" ] || die "не найден $PHP"
command -v mysql >/dev/null 2>&1 || die "не найден клиент mysql"
id "$APP_USER" >/dev/null 2>&1 || die "нет пользователя $APP_USER (это не сервер FreePBX?)"
[ -d /etc/httpd/conf.d ] || die "нет /etc/httpd/conf.d (Apache не установлен?)"

# Run a command as the asterisk user (old util-linux has no 'runuser -u').
as_app() {
    local cmd
    cmd="$(printf '%q ' "$@")"
    runuser -s /bin/bash "$APP_USER" -c "$cmd"
}

# MariaDB as root: local socket; password from MYSQL_ROOT_PASS or ~/.my.cnf if needed.
mysql_root() {
    if [ -n "${MYSQL_ROOT_PASS:-}" ]; then
        MYSQL_PWD="$MYSQL_ROOT_PASS" mysql -uroot "$@"
    else
        mysql -uroot "$@"
    fi
}
mysql_root -e 'SELECT 1' >/dev/null 2>&1 \
    || die "не удалось подключиться к MariaDB как root. Если у root есть пароль: MYSQL_ROOT_PASS='пароль' bash install/install.sh"

FIRST_INSTALL=0
[ -f "$APP/config/config.php" ] || FIRST_INSTALL=1

# 1. Copy the code (config/config.php is kept).
say "Копирование файлов в $APP"
mkdir -p "$APP"
for d in bin install public sql src templates; do
    rm -rf "${APP:?}/$d"
done
mkdir -p "$APP/config"
tar -C "$SRC" --exclude='config/config.php' -cf - bin install public sql src templates config README.md \
    | tar -C "$APP" -xf -
chown -R root:root "$APP"
chmod -R go-w "$APP"
chmod +x "$APP"/install/*.sh 2>/dev/null || true
# Readable by Apache/cron (asterisk) through root:asterisk group and world-read of code.
chmod -R a+rX "$APP"

# 2. Database, users and config (first install only).
if [ "$FIRST_INSTALL" -eq 1 ]; then
    say "Создание базы данных, пользователей и config.php"
    genpass() { head -c 512 /dev/urandom | tr -dc 'A-Za-z0-9' | head -c 24; }
    PASS_RW="$(genpass)"
    PASS_RO="$(genpass)"
    [ "${#PASS_RW}" -eq 24 ] && [ "${#PASS_RO}" -eq 24 ] || die "не удалось сгенерировать пароли"

    # Users are created for both 'localhost' (socket) and '127.0.0.1' (TCP, as in config).
    SQL="CREATE DATABASE IF NOT EXISTS callstats CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
    for h in localhost 127.0.0.1; do
        SQL="$SQL
GRANT ALL PRIVILEGES ON callstats.* TO 'callstats'@'$h' IDENTIFIED BY '$PASS_RW';
GRANT SELECT ON asteriskcdrdb.cdr TO 'callstats_ro'@'$h' IDENTIFIED BY '$PASS_RO';
GRANT SELECT ON asterisk.users TO 'callstats_ro'@'$h';
GRANT SELECT ON asterisk.queues_config TO 'callstats_ro'@'$h';"
    done
    SQL="$SQL
FLUSH PRIVILEGES;"
    printf '%s\n' "$SQL" | mysql_root || die "не удалось создать базу и пользователей"

    CFG="$APP/config/config.php"
    cp "$APP/config/config.sample.php" "$CFG"
    CS_RW="$PASS_RW" CS_RO="$PASS_RO" CS_CFG="$CFG" "$PHP" -r '
        $c = require getenv("CS_CFG");
        $c["db"]["callstats"]["pass"] = getenv("CS_RW");
        $c["db"]["source"]["pass"]    = getenv("CS_RO");
        $c["db"]["fpbx"]["pass"]      = getenv("CS_RO");
        file_put_contents(getenv("CS_CFG"), "<?php\n\nreturn " . var_export($c, true) . ";\n");
    ' || die "не удалось записать config.php"
    chown root:"$APP_USER" "$CFG"
    chmod 640 "$CFG"
    unset PASS_RW PASS_RO SQL
else
    say "Обновление: config/config.php сохранён без изменений"
    chown root:"$APP_USER" "$APP/config/config.php"
    chmod 640 "$APP/config/config.php"
fi

# 3. Schema (CREATE TABLE IF NOT EXISTS).
say "Применение схемы базы данных"
CFG="$APP/config/config.php"
CS_CFG="$CFG" "$PHP" -r '
    $c = require getenv("CS_CFG");
    $d = $c["db"]["callstats"];
    $pdo = new PDO($d["dsn"], $d["user"], $d["pass"], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents($argv[1]));
    echo "ok\n";
' "$APP/sql/schema.sql" || die "не удалось применить sql/schema.sql"

# 4. Apache.
say "Настройка Apache"
CONF=/etc/httpd/conf.d/callstats.conf
cp "$APP/install/callstats.conf" "$CONF"
chmod 644 "$CONF"
if command -v apachectl >/dev/null 2>&1; then
    CT=apachectl
elif command -v httpd >/dev/null 2>&1; then
    CT="httpd"
else
    rm -f "$CONF"; die "не найден apachectl"
fi
if ! $CT configtest; then
    rm -f "$CONF"
    die "проверка конфигурации Apache не прошла. Файл $CONF удалён, Apache не тронут. Покажите вывод выше разработчику."
fi
systemctl reload httpd || die "не удалось перезагрузить httpd"

# 5. Cron and log.
say "Настройка cron и журнала"
touch /var/log/callstats.log
chown "$APP_USER":"$APP_USER" /var/log/callstats.log
chmod 640 /var/log/callstats.log
cp "$APP/install/callstats.cron" /etc/cron.d/callstats
chmod 644 /etc/cron.d/callstats
if [ -d /etc/logrotate.d ]; then
    cp "$APP/install/callstats.logrotate" /etc/logrotate.d/callstats
    chmod 644 /etc/logrotate.d/callstats
fi

# 6. First install: login + history. Update: only with --rebuild.
TODAY="$(date +%Y-%m-%d)"
if [ "$FIRST_INSTALL" -eq 1 ]; then
    say "Создайте логин и пароль для входа на сайт"
    until as_app "$PHP" "$APP/bin/set-password.php"; do
        echo "Попробуйте ещё раз."
    done
fi
if [ "$FIRST_INSTALL" -eq 1 ] || [ "$DO_REBUILD" -eq 1 ]; then
    say "Загрузка истории с $HIST_FROM по $TODAY (может занять несколько минут)"
    as_app "$PHP" "$APP/bin/rebuild.php" --from="$HIST_FROM" --to="$TODAY" \
        || die "загрузка истории не удалась. Исправьте ошибку и выполните: bash install/install.sh --rebuild"
fi

# 7. Done.
HOST="$(hostname -f 2>/dev/null || hostname)"
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
say "Готово"
echo "Откройте в браузере: https://$HOST/callstats/"
[ -n "$IP" ] && echo "или:                 https://$IP/callstats/"
echo "Сборщик данных запускается каждую минуту (журнал: /var/log/callstats.log)."
