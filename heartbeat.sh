#!/bin/sh

# Pass deployment-specific container env through to php-fpm. The sysv
# `service` wrapper below scrubs the environment before starting the fpm
# master, so the pool's clear_env=no has nothing to inherit (ENVIRONMENT
# only reaches PHP because the image bakes env[ENVIRONMENT] into the pool
# config). This script IS started with the container env, so materialize
# the vars into the pool config here. No-op when none are set (local dev);
# idempotent across container restarts (delete-then-append).
POOL=/etc/php/8.1/fpm/pool.d/www.conf
sed -i '/^env\[ORK3_/d; /^env\[CF_/d' "$POOL"
for v in ORK3_DB_HOST ORK3_DB_USER ORK3_DB_PASSWORD ORK3_DB_DATABASE ORK3_DB_PROFILE CF_API_TOKEN CF_ZONE_ID; do
	val=$(printenv "$v") && echo "env[$v] = \"$val\"" >> "$POOL"
done

/usr/sbin/service nginx start
/usr/sbin/service php8.1-fpm start
/usr/sbin/service memcached start

while true; do sleep 1; done
