#!/bin/sh
set -eu

if [ ! -f /var/www/html/vendor/autoload.php ]; then
    cp -a /opt/vendor/. /var/www/html/vendor/
fi

exec "$@"