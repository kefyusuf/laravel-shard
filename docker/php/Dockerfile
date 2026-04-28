ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-alpine

RUN apk add --no-cache \
        bash git unzip libzip-dev oniguruma-dev sqlite-dev linux-headers \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite zip bcmath sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /app

CMD ["sh"]
