#!/usr/bin/env bash
# Downloads PHP 7.4.33, composer and MariaDB 5.5.68 (portable) into tools/.
set -euo pipefail
cd "$(dirname "$0")"
DL="$(pwd)/_dl"; mkdir -p "$DL"

PHP_URL="https://windows.php.net/downloads/releases/archives/php-7.4.33-nts-Win32-vc15-x64.zip"
MARIA_URL="https://archive.mariadb.org/mariadb-5.5.68/winx64-packages/mariadb-5.5.68-winx64.zip"
COMPOSER_URL="https://getcomposer.org/download/latest-2.2.x/composer.phar"

unzip_to() { # zip dest
  if command -v unzip >/dev/null 2>&1; then unzip -q -o "$1" -d "$2"
  else powershell.exe -NoProfile -Command "Expand-Archive -Force -LiteralPath '$(cygpath -w "$1")' -DestinationPath '$(cygpath -w "$2")'"; fi
}

if [ ! -f php/php.exe ]; then
  [ -f "$DL/php.zip" ] || curl -fL -o "$DL/php.zip" "$PHP_URL"
  mkdir -p php && unzip_to "$DL/php.zip" php
fi
if [ ! -f php/php.ini ]; then
  cp php/php.ini-development php/php.ini
  sed -i \
    -e 's/^;\s*extension_dir = "ext"/extension_dir = "ext"/' \
    -e 's/^;\s*extension=pdo_mysql/extension=pdo_mysql/' \
    -e 's/^;\s*extension=mbstring/extension=mbstring/' \
    -e 's/^;\s*extension=openssl/extension=openssl/' \
    -e 's/^;\s*extension=zlib/extension=zlib/' php/php.ini
fi

if [ ! -f composer.phar ]; then
  curl -fL -o composer.phar "$COMPOSER_URL"
fi

if [ ! -f mariadb/bin/mysqld.exe ]; then
  [ -f "$DL/mariadb.zip" ] || curl -fL -o "$DL/mariadb.zip" "$MARIA_URL"
  mkdir -p "$DL/maria-x"
  unzip_to "$DL/mariadb.zip" "$DL/maria-x"
  mkdir -p mariadb
  cp -r "$DL"/maria-x/mariadb-5.5.68-winx64/. mariadb/
fi
if [ ! -d mariadb-data ]; then
  cp -r mariadb/data mariadb-data
fi
echo "tools ready"
