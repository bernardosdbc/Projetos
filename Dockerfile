FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client libzip-dev unzip \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY src/composer.json src/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts --optimize-autoloader

COPY src ./
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi \
    && cp -a vendor /opt/vendor

COPY docker/entrypoint.sh /usr/local/bin/project-entrypoint
RUN chmod +x /usr/local/bin/project-entrypoint

ENTRYPOINT ["project-entrypoint"]

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
