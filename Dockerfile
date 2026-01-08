FROM php:8.4-fpm

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libonig-dev \
    libzip-dev \
    libsqlite3-dev \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo pdo_pgsql pgsql pcntl pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . /var/www/html

RUN mkdir -p bootstrap/cache \
    storage/logs \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/app \
    && chmod -R 777 bootstrap/cache storage \
    && rm -rf bootstrap/cache/*.php \
    && rm -rf vendor

ARG COMPOSER_FLAGS="--no-interaction --prefer-dist --optimize-autoloader --no-dev"
RUN composer install $COMPOSER_FLAGS

RUN php artisan storage:link || true

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8000

CMD php -S 0.0.0.0:${PORT:-8000} -t public
