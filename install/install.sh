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
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
DO_REBUILD=0
MARK="$APP/config/.installed"

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
APP_REAL="$(cd "$APP" 2>/dev/null && pwd -P || echo "$APP")"
[ "$SRC" != "$APP_REAL" ] || die "установщик запущен из $APP. Распакуйте архив в другую папку (например, cd /root; tar xzf callstats-*.tgz) и запустите оттуда: bash install/install.sh"
[ -f "$SRC/bin/collect.php" ] && [ -f "$SRC/sql/schema.sql" ] || die "запускайте из распакованного архива: bash install/install.sh"
[ -x "$PHP" ] || die "не найден $PHP"
command -v mysql >/dev/null 2>&1 || die "не найден клиент mysql"
id "$APP_USER" >/dev/null 2>&1 || die "нет пользователя $APP_USER (это не сервер FreePBX?)"
[ -d /etc/httpd/conf.d ] || die "нет /etc/httpd/conf.d (Apache не установлен?)"

# Run a command as the asterisk user (old util-linux has no 'runuser -u').
as_app() {
    local cmd
    cmd="$(printf '%q ' "$@")"
    runuser -s /bin/bash "$APP_USER" -c "cd / && $cmd"
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

# FIRST_INSTALL stays 1 until the very last step writes $MARK, so a failed run is resumed on re-run.
FIRST_INSTALL=0
[ -f "$MARK" ] || FIRST_INSTALL=1
HAVE_CONFIG=0
[ -f "$APP/config/config.php" ] && HAVE_CONFIG=1

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
# (config.php itself is never made world-readable)
find "$APP" -path "$APP/config/config.php" -prune -o -exec chmod a+rX {} +

# 2. Database, users and config (only while config.php does not exist yet).
if [ "$HAVE_CONFIG" -eq 0 ]; then
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
    # Patch a temp copy and move it into place, so a failure never leaves a half-made config.php.
    CFG_TMP="$CFG.tmp"
    rm -f "$CFG_TMP"
    install -m 640 -o root -g "$APP_USER" "$APP/config/config.sample.php" "$CFG_TMP"
    CS_RW="$PASS_RW" CS_RO="$PASS_RO" CS_CFG="$CFG_TMP" "$PHP" -r '
        $c = require getenv("CS_CFG");
        $c["db"]["callstats"]["pass"] = getenv("CS_RW");
        $c["db"]["source"]["pass"]    = getenv("CS_RO");
        $c["db"]["fpbx"]["pass"]      = getenv("CS_RO");
        file_put_contents(getenv("CS_CFG"), "<?php\n\nreturn " . var_export($c, true) . ";\n");
    ' || { rm -f "$CFG_TMP"; die "не удалось записать config.php"; }
    mv -f "$CFG_TMP" "$CFG" || die "не удалось записать config.php"
    unset PASS_RW PASS_RO SQL
else
    say "config/config.php уже есть, он сохранён без изменений"
    chown root:"$APP_USER" "$APP/config/config.php"
    chmod 640 "$APP/config/config.php"
fi

# 2b. AMI access for the «Онлайн» page: FreePBX's own manager user (read-only use: QueueStatus,
# CoreShowChannels, ExtensionStateList). Filled in only when config.php has no ami.user yet.
CFG="$APP/config/config.php"
if ! CS_CFG="$CFG" "$PHP" -r '$c = require getenv("CS_CFG"); exit(empty($c["ami"]["user"]) ? 1 : 0);'; then
    AMI_USER=$(mysql_root -N -B -r -e "SELECT value FROM asterisk.freepbx_settings WHERE keyword='AMPMGRUSER'" 2>/dev/null || true)
    AMI_PASS=$(mysql_root -N -B -r -e "SELECT value FROM asterisk.freepbx_settings WHERE keyword='AMPMGRPASS'" 2>/dev/null || true)
    [ "$AMI_USER" = NULL ] && AMI_USER=''
    [ "$AMI_PASS" = NULL ] && AMI_PASS=''
    if [ -n "$AMI_USER" ] && [ -n "$AMI_PASS" ]; then
        say "Подключение страницы «Онлайн» к Asterisk (AMI, пользователь $AMI_USER)"
        CFG_TMP="$CFG.tmp"
        install -m 640 -o root -g "$APP_USER" "$CFG" "$CFG_TMP"
        CS_CFG="$CFG_TMP" CS_AU="$AMI_USER" CS_AP="$AMI_PASS" "$PHP" -r '
            $c = require getenv("CS_CFG");
            $c["ami"] = ["host" => "127.0.0.1", "port" => 5038, "user" => getenv("CS_AU"), "secret" => getenv("CS_AP")];
            if (!isset($c["live"])) { $c["live"] = ["refresh_sec" => 3, "service_level_sec" => 60]; }
            file_put_contents(getenv("CS_CFG"), "<?php\n\nreturn " . var_export($c, true) . ";\n");
        ' || { rm -f "$CFG_TMP"; die "не удалось записать настройки AMI в config.php"; }
        mv -f "$CFG_TMP" "$CFG" || die "не удалось записать config.php"
    else
        say "ВНИМАНИЕ: не нашёл AMPMGRUSER/AMPMGRPASS в настройках FreePBX — страница «Онлайн» не заработает, пока не впишете ami.user/ami.secret в $CFG (README, раздел «Онлайн»)"
    fi
    unset AMI_USER AMI_PASS
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
    CT="httpd -t"
else
    rm -f "$CONF"; die "не найден apachectl"
fi
if [ "$CT" = apachectl ]; then CT="apachectl configtest"; fi
if ! $CT; then
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

# Smoke check: the page answers and PHP is executed (not shown as source) under the Alias.
say "Проверка сайта"
SMOKE_OK=0
if command -v curl >/dev/null 2>&1; then
    for base in http://127.0.0.1 https://127.0.0.1; do
        code="$(curl -sk -o /dev/null -m 15 -w '%{http_code}' "$base/callstats/" || true)"
        if [ "$code" = 200 ] || [ "$code" = 302 ]; then
            body="$(curl -sk -m 15 "$base/callstats/?p=login" || true)"
            if printf '%s' "$body" | grep -q '<?php'; then
                break
            fi
            SMOKE_OK=1
            break
        fi
    done
fi
if [ "$SMOKE_OK" -eq 1 ]; then
    echo "Сайт отвечает, PHP выполняется."
else
    echo "ВНИМАНИЕ: не удалось подтвердить, что сайт работает (установка продолжается)." >&2
    echo "  Проверьте: открывается ли адрес ниже в браузере; работает ли PHP в Apache (php -v; rpm -qa | grep php);" >&2
    echo "  нет ли ошибок в /var/log/httpd/error_log; не мешает ли другая настройка Apache (apachectl -S)." >&2
fi

# 6. Login (first install, or when no password is set yet) and history (first install / --rebuild).
TODAY="$(date +%Y-%m-%d)"
HAVE_AUTH="$(CS_CFG="$CFG" "$PHP" -r '
    $c = require getenv("CS_CFG");
    $d = $c["db"]["callstats"];
    $pdo = new PDO($d["dsn"], $d["user"], $d["pass"]);
    echo (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE k = '"'"'auth_hash'"'"' AND v <> '"''"'")->fetchColumn();
' 2>/dev/null || echo 0)"
if [ "$FIRST_INSTALL" -eq 1 ] || [ "$HAVE_AUTH" != 1 ]; then
    say "Создайте логин и пароль для входа на сайт"
    pw_ok=0
    for attempt in 1 2 3; do
        if as_app "$PHP" "$APP/bin/set-password.php"; then pw_ok=1; break; fi
        echo "Не получилось (попытка $attempt из 3)."
    done
    [ "$pw_ok" -eq 1 ] || die "пароль не задан. Задайте его позже командой: runuser -s /bin/bash asterisk -c \"cd / && $PHP $APP/bin/set-password.php\" , затем снова запустите bash install/install.sh"
fi
if [ "$FIRST_INSTALL" -eq 1 ] || [ "$DO_REBUILD" -eq 1 ]; then
    say "Загрузка истории с $HIST_FROM по $TODAY (может занять несколько минут)"
    as_app "$PHP" "$APP/bin/rebuild.php" --from="$HIST_FROM" --to="$TODAY" \
        || die "загрузка истории не удалась. Исправьте ошибку и снова запустите: bash install/install.sh"
fi

# 7. Completion marker (last step), then the address.
date '+%Y-%m-%d %H:%M:%S' > "$MARK"
chown root:"$APP_USER" "$MARK"
chmod 640 "$MARK"

HOST="$(hostname -f 2>/dev/null || hostname)"
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
say "Готово"
echo "Откройте в браузере:"
echo "  https://$HOST/callstats/   или   http://$HOST/callstats/"
if [ -n "$IP" ]; then
    echo "  https://$IP/callstats/   или   http://$IP/callstats/"
fi
echo "Сборщик данных запускается каждую минуту (журнал: /var/log/callstats.log)."
