FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libxml2-dev \
        libzip-dev \
        libicu-dev \
        unzip \
    && docker-php-ext-install \
        pdo_mysql \
        bcmath \
        xml \
        dom \
        intl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
