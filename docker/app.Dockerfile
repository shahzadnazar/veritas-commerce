FROM php:8.4-cli-alpine

RUN apk add --no-cache postgresql-dev libzip-dev icu-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql pgsql zip intl bcmath opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 8000
