FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    mysql-client \
    curl \
    git \
    linux-headers \
    $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql sockets pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/private \
    && touch /var/www/html/bot.log \
    && chown www-data:www-data /var/www/html/bot.log

USER www-data

EXPOSE 9000

CMD ["php-fpm"]