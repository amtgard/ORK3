FROM ubuntu:trusty

ENV DEBIAN_FRONTEND noninteractive

# add NGINX official stable repository
RUN echo "deb http://ppa.launchpad.net/nginx/stable/ubuntu `lsb_release -cs` main" > /etc/apt/sources.list.d/nginx.list

# add PHP5.6 unofficial repository (https://launchpad.net/~ondrej/+archive/ubuntu/php)
RUN echo "deb http://ppa.launchpad.net/ondrej/php/ubuntu `lsb_release -cs` main" > /etc/apt/sources.list.d/php.list

RUN apt-get update -y
RUN apt-get install -y --force-yes --no-install-recommends \
ca-certificates \
supervisor \
nginx \
curl \
php5.6-fpm \
php5.6-cli \
php5.6-curl \
php5.6-gd \
php5.6-mysql \
php5.6-redis \
php5.6-mcrypt \
php5.6-xml

RUN sed -e 's/;daemonize = yes/daemonize = no/' -i /etc/php/5.6/fpm/php-fpm.conf

RUN echo "\ndaemon off;" >> /etc/nginx/nginx.conf

RUN apt-get autoclean && apt-get -y autoremove

RUN rm -f /etc/nginx/sites-enabled/default

RUN usermod -u 1000 www-data

RUN mkdir -p /run/php

ADD . /srv/www

ADD docker-config/nginx-vhost /etc/nginx/sites-enabled/app
ADD docker-config/supervisor.conf /etc/supervisor/conf.d/supervisor.conf

WORKDIR /srv/www

CMD ["/usr/bin/supervisord"]