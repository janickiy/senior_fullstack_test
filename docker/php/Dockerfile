FROM php:8.4-fpm-bookworm

ARG APP_UID=1000
ARG APP_GID=1000
ARG MEMCACHED_VERSION=3.4.0

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libfcgi-bin \
        libicu-dev \
        libzip-dev \
        libmemcached-dev \
        libssl-dev \
        libsasl2-dev \
        zlib1g-dev \
    && pkg-config --print-errors --exists libmemcached \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip \
    && pecl install --configureoptions 'with-libmemcached-dir="/usr" with-zlib-dir="/usr" with-system-fastlz="no" enable-memcached-igbinary="no" enable-memcached-msgpack="no" enable-memcached-json="no" enable-memcached-protocol="no" enable-memcached-sasl="yes" enable-memcached-session="yes"' "memcached-${MEMCACHED_VERSION}" \
    && docker-php-ext-enable memcached \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# mbstring and OPcache are already enabled in the official PHP 8.4 image.
RUN php -r 'foreach (["pdo_mysql", "intl", "zip", "mbstring", "Zend OPcache", "memcached"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: ".$extension.PHP_EOL); exit(1); } }'

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=755 docker/php/healthcheck.sh /usr/local/bin/php-healthcheck

RUN groupadd --gid "${APP_GID}" --non-unique app \
    && useradd --uid "${APP_UID}" --gid "${APP_GID}" --create-home app \
    && mkdir -p /home/app/.composer \
    && chown -R app /home/app /var/www/html

ENV HOME=/home/app \
    COMPOSER_HOME=/home/app/.composer

WORKDIR /var/www/html
USER app

CMD ["php-fpm"]
