FROM php:5.6-apache
RUN a2enmod headers
RUN a2enmod rewrite

RUN apt-get update && apt-get install -y \
&& docker-php-ext-install mysql
RUN apt-get update -y && apt-get install -y \
  libpng-dev \
  libfreetype6-dev \
  libjpeg-dev \
  libxpm-dev \
  libvpx-dev
RUN docker-php-ext-configure gd \
    --with-freetype-dir=/usr/include/ \
    --with-jpeg-dir=/usr/include/ \
    --with-xpm-dir=/usr/include \
    --with-vpx-dir=/usr/include/ # php <7.0 (use webp for php >=7.0)

RUN docker-php-ext-install gd
