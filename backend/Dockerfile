FROM php:8.3-fpm-alpine AS extensions

RUN apk add --no-cache $PHPIZE_DEPS icu-dev postgresql-dev linux-headers \
    && docker-php-ext-install pdo_pgsql intl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis

FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

FROM extensions AS devtools

RUN apk add --no-cache git unzip \
    && addgroup -S app \
    && adduser -S -G app app \
    && echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/zz-devtools-memory-limit.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts

COPY . .

FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache fcgi icu-libs libpq \
    && addgroup -S app \
    && adduser -S -G app app

RUN echo 'memory_limit=128M' > /usr/local/etc/php/conf.d/zz-memory-limit.ini

COPY --from=extensions /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=extensions /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY --from=vendor /app/bin /var/www/html/bin
COPY --from=vendor /app/config /var/www/html/config
COPY --from=vendor /app/migrations /var/www/html/migrations
COPY --from=vendor /app/public /var/www/html/public
COPY --from=vendor /app/src /var/www/html/src
COPY --from=vendor /app/composer.json /var/www/html/composer.json
COPY --from=vendor /app/composer.lock /var/www/html/composer.lock

WORKDIR /var/www/html
USER app

CMD ["php-fpm", "-F"]
