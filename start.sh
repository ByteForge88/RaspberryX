#!/usr/bin/env bash

cd "$(dirname "$0")" || exit 1

PHP_DIR="./bin/php"
PHP_BINARY=""

for candidate in "$PHP_DIR/bin/php" "$PHP_DIR/php"; do
    if [ -x "$candidate" ]; then
        PHP_BINARY="$candidate"
        break
    fi
done

if [ -z "$PHP_BINARY" ]; then
    echo "[ERROR] PHP is missing from $PHP_DIR. Get it from https://github.com/ByteForge88/RaspberryX-PHPBinaries" >&2
    exit 1
fi

PHP_ARGS=()

for ini in "$PHP_DIR/php.ini" "$PHP_DIR/bin/php.ini"; do
    if [ -f "$ini" ]; then
        PHP_ARGS=(-c "$ini")
        break
    fi
done

if [ -f "RaspberryX.phar" ]; then
    exec "$PHP_BINARY" "${PHP_ARGS[@]}" -r "require 'phar://RaspberryX.phar/src/raspberry/RaspberryX.php';"
fi

if [ ! -f "src/raspberry/RaspberryX.php" ]; then
    echo "[ERROR] RaspberryX.phar and the source entry point are missing." >&2
    exit 1
fi

exec "$PHP_BINARY" "${PHP_ARGS[@]}" "src/raspberry/RaspberryX.php"