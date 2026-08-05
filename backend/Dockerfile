FROM php:8.3-fpm-alpine AS extensions

RUN apk add --no-cache $PHPIZE_DEPS icu-dev postgresql-dev linux-headers \
    && docker-php-ext-install pdo_pgsql intl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis

FROM php:8.3-fpm-alpine AS runtime

RUN apk add --no-cache icu-libs libpq \
    && addgroup -S app \
    && adduser -S -G app app

COPY --from=extensions /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=extensions /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY public /var/www/html/public

WORKDIR /var/www/html
USER app

CMD ["php-fpm", "-F"]
