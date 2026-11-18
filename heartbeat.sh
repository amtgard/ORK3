#!/bin/sh
# Keep php-fpm pool valid: empty env[...] values crash FPM; bootstrap loads repo .env instead.
POOL=/etc/php/8.1/fpm/pool.d/www.conf
if [ -f "$POOL" ]; then
    sed -i '/^env\[.*\] = ""$/d' "$POOL"
    sed -i '/^env\[ORK3_/d' "$POOL"
    sed -i '/^env\[CF_/d' "$POOL"
    sed -i '/^env\[AMAZON_/d' "$POOL"
    sed -i '/^env\[DB_/d' "$POOL"
    sed -i '/^env\[HOST_ENV/d' "$POOL"
    sed -i '/^env\[IDP_/d' "$POOL"
    if ! grep -q '^env\[ENVIRONMENT\]' "$POOL"; then
        echo 'env[ENVIRONMENT] = DEV' >> "$POOL"
    fi
fi

mkdir -p /var/log/nginx
/usr/sbin/service nginx start
/usr/sbin/service php8.1-fpm start
/usr/sbin/service memcached start

while true; do sleep 1; done