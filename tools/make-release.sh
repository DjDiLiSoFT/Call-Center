#!/bin/bash
# Builds dist/callstats-<YYYYMMDD>.tgz from the committed state (HEAD).
# Run from anywhere:  bash tools/make-release.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

STAMP="$(date +%Y%m%d)"
NAME="callstats-$STAMP"
OUT="$ROOT/dist/$NAME.tgz"
STAGE="$ROOT/dist/.stage"

if [ -n "$(git status --porcelain -- bin config/config.sample.php install public sql src templates README.md)" ]; then
    echo "ВНИМАНИЕ: есть незакоммиченные изменения; в архив попадёт только закоммиченное (HEAD)." >&2
fi

rm -rf "$STAGE"
mkdir -p "$STAGE/$NAME"

git archive HEAD -- bin config/config.sample.php install public sql src templates README.md \
    | tar -x -C "$STAGE/$NAME"

chmod +x "$STAGE/$NAME"/install/*.sh

# Release files must be LF-only.
if grep -rIl $'\r' "$STAGE/$NAME" >/dev/null; then
    echo "ОШИБКА: в архиве найдены файлы с CRLF:" >&2
    grep -rIl $'\r' "$STAGE/$NAME" >&2
    rm -rf "$STAGE"
    exit 1
fi

mkdir -p "$ROOT/dist"
rm -f "$OUT"
tar -C "$STAGE" --owner=0 --group=0 --numeric-owner -czf "$OUT" "$NAME"
rm -rf "$STAGE"

echo "Создан: $OUT"
tar -tzf "$OUT" | wc -l | xargs echo "Файлов в архиве:"
