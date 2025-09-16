FROM php:8.3-cli

# — PHP extensions (pdo_pgsql, intl, zip, gd)
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libicu-dev libzip-dev zip \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) gd pdo pdo_pgsql intl zip \
 && rm -rf /var/lib/apt/lists/*

# — Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# — Prod env
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=prod
ENV APP_DEBUG=0

WORKDIR /app

# 1) Dependencies л татна (scripts-гүй — bin/console хараахан байхгүй)
COPY composer.json composer.lock* symfony.lock* ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts

# 2) Одоо бүх кодыг хуулна
COPY . /app

# 3) Autoload-оо л сайжруулна (scripts АЖИЛЛАХГҮЙ)
RUN composer dump-autoload -o

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
