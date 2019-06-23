FROM php:5.6-apache
RUN a2enmod headers
RUN a2enmod rewrite

RUN apt-get update && apt-get install -y \
&& docker-php-ext-install mysql
