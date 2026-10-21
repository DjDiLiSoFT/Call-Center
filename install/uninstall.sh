#!/bin/bash
# Removes CallStats: Apache conf, cron, logrotate, /opt/callstats.
# The database and DB users are removed only with --drop-db.
#   bash /opt/callstats/install/uninstall.sh [--drop-db]
set -uo pipefail

DROP_DB=0
for a in "$@"; do
    case "$a" in
        --drop-db) DROP_DB=1 ;;
        *) echo "Неизвестный параметр: $a" >&2; exit 2 ;;
    esac
done

[ "$(id -u)" -eq 0 ] || { echo "ОШИБКА: запустите от root" >&2; exit 1; }

echo "Будет удалено: /opt/callstats, /etc/httpd/conf.d/callstats.conf, /etc/cron.d/callstats, /etc/logrotate.d/callstats"
[ "$DROP_DB" -eq 1 ] && echo "А также база данных callstats и пользователи callstats, callstats_ro (вся собранная статистика пропадёт)."
echo "Журнал /var/log/callstats.log и данные FreePBX не трогаются."
printf 'Продолжить? Введите yes: '
read -r ans
[ "$ans" = "yes" ] || { echo "Отменено."; exit 1; }

mysql_root() {
    if [ -n "${MYSQL_ROOT_PASS:-}" ]; then
        MYSQL_PWD="$MYSQL_ROOT_PASS" mysql -uroot "$@"
    else
        mysql -uroot "$@"
    fi
}

if [ "$DROP_DB" -eq 1 ]; then
    # DROP USER fails if the user does not exist; ignore that.
    for h in localhost 127.0.0.1; do
        mysql_root -e "DROP USER 'callstats'@'$h'" 2>/dev/null
        mysql_root -e "DROP USER 'callstats_ro'@'$h'" 2>/dev/null
    done
    mysql_root -e 'DROP DATABASE IF EXISTS callstats' || echo "Не удалось удалить базу callstats" >&2
fi

rm -f /etc/cron.d/callstats /etc/logrotate.d/callstats /etc/httpd/conf.d/callstats.conf
if command -v apachectl >/dev/null 2>&1 && apachectl configtest >/dev/null 2>&1; then
    systemctl reload httpd
else
    echo "ВНИМАНИЕ: конфигурация Apache не проходит проверку, httpd не перезагружен." >&2
fi
rm -rf /opt/callstats
echo "Готово."
