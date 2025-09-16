# PHP 8.3 (zipstream-php шаардлага)
FROM php:8.3-cli

# Системийн багцууд + PHP өргөтгөлүүд (PhpSpreadsheet-д GD хэрэгтэй)
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libicu-dev libzip-dev zip \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j$(nproc) gd pdo pdo_pgsql intl zip

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Ажиллах хавтас
WORKDIR /app

# 1) Кэшэнд ээлтэй алхам: зөвхөн composer файлуудыг эхлээд хуулж install (NO SCRIPTS)
COPY composer.json composer.lock* symfony.lock* ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts

# 2) Бүх кодоо хуулна
COPY . .

# 3) Одоо scripts-тайгаар дахин install → autoload_runtime.php үүснэ
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Prod env
ENV APP_ENV=prod
ENV APP_DEBUG=0

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
