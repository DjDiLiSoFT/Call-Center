#!/usr/bin/env bash
# Usage: tools/test-db.sh start|stop   (MariaDB 5.5 on 127.0.0.1:3307, root without password)
set -euo pipefail
cd "$(dirname "$0")"
BASE="$(pwd)/mariadb"; DATA="$(pwd)/mariadb-data"; PORT=3307
ping_db() { "$BASE/bin/mysqladmin.exe" --no-defaults -h127.0.0.1 -P$PORT -uroot ping >/dev/null 2>&1; }
case "${1:-}" in
  start)
    if ping_db; then echo "already running"; exit 0; fi
    nohup "$BASE/bin/mysqld.exe" --no-defaults --port=$PORT --bind-address=127.0.0.1 \
      --basedir="$(cygpath -m "$BASE")" --datadir="$(cygpath -m "$DATA")" --console \
      >"$(pwd)/mariadb.log" 2>&1 &
    for i in $(seq 1 30); do
      if ping_db; then echo "started on $PORT"; exit 0; fi
      sleep 1
    done
    echo "failed to start, see tools/mariadb.log" >&2; exit 1 ;;
  stop)
    "$BASE/bin/mysqladmin.exe" --no-defaults -h127.0.0.1 -P$PORT -uroot shutdown && echo stopped ;;
  *) echo "usage: $0 start|stop" >&2; exit 2 ;;
esac
