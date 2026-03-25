FROM php:8.4-fpm-bookworm

WORKDIR /var/www/html

# Install PostgreSQL 17 client (pg_dump) to match production DB major version.
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    gnupg \
    git \
    unzip \
    libpq-dev \
    libonig-dev \
    libzip-dev \
    libsqlite3-dev \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
       | gpg --dearmor -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.gpg \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.gpg] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
       > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update && apt-get install -y --no-install-recommends postgresql-client-17 \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo pdo_pgsql pgsql pcntl pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

ENV DB_BACKUP_PG_DUMP_BINARY=/usr/lib/postgresql/17/bin/pg_dump

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/custom.ini

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
