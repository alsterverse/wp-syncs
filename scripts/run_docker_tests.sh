#!/usr/bin/env bash



function run() {


WP_VERSION=$1
PHP_VERSION=$2
WP_DB_HOST=$3
PHPUNIT_VERSION="^7"

if [ $PHP_VERSION == "7.1" ]
then
 PHPUNIT_VERSION="^5.7"
fi;

# RUN docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install zip mysqli gd

docker build  -t wp-test-run-php-$WP_VERSION  -f- .  <<EOF
# syntax=docker/dockerfile:1.2
FROM php:${PHP_VERSION}-cli
RUN mv /usr/local/etc/php/php.ini-development /usr/local/etc/php/php.ini
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update && apt-get install -y \
        libzip-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        zip \
        git


RUN docker-php-ext-configure gd \
  && docker-php-ext-install zip mysqli gd


ENV COMPOSER_CACHE_DIR=/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN mkdir -p /composer && mkdir /app
WORKDIR /app
COPY . /app

RUN chown -R www-data:www-data /app && chown -R www-data:www-data /composer


RUN --mount=type=cache,id=composer${PHP_VERSION},mode=0777,target=/composer composer validate --strict && \
  composer require --no-update --dev phpunit/phpunit:${PHPUNIT_VERSION} roots/wordpress:${WP_VERSION} wp-phpunit/wp-phpunit:${WP_VERSION} && \
  composer install && \
  composer show





EOF
# docker run --rm --network wp-net --rm --name wp-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=wp_phpunit_tests -d mysql:5.7

echo "Running test on WP: $WP_VERSION on PHP: php$PHP_VERSION"
docker run --rm --name wp-test-run-exec --network host -e WP_VERSION=${WP_VERSION} -e WP_DB_HOST=${WP_DB_HOST} wp-test-run-php-$WP_VERSION composer test

}

#run "4.9.18" "7.1" "host.docker.internal"
run "5.8.1" "7.4" "host.docker.internal"
