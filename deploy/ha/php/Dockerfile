# PHP 8.3 FPM image for the platform.
FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libpng-dev libicu-dev libsodium-dev pandoc poppler-utils \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd intl bcmath opcache sodium \
    && pecl install redis && docker-php-ext-enable redis && rm -rf /var/lib/apt/lists/*
COPY deploy/ha/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
WORKDIR /var/www/platform
COPY --chown=www-data:www-data . .
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && php composer-setup.php --quiet && rm composer-setup.php \
    && php composer.phar install --no-dev --optimize-autoloader --no-interaction && rm composer.phar \
    && php artisan storage:link || true
USER www-data
CMD ["php-fpm"]
