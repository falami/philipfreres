FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd intl mbstring zip pdo_mysql pdo_sqlite opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/app
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --no-scripts \
    && composer clear-cache

COPY docker/local/environment.env .env
COPY docker/local/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/local/php.ini /usr/local/etc/php/conf.d/local.ini
COPY docker/local/start.sh /usr/local/bin/start-local
RUN mkdir -p var public/uploads config/packages/dev \
    && chmod +x /usr/local/bin/start-local
CMD ["start-local"]
