FROM serversideup/php:8.4-fpm-nginx AS base

USER root
RUN install-php-extensions redis intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM base AS deploy

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

USER root
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
