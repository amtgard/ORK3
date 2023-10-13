#/bin/sh

/usr/sbin/service nginx start
/usr/sbin/service php8.1-fpm start
/usr/sbin/service memcached start

while true; do sleep 1; done