FROM php:8.2-cli

# Шаардлагатай сангууд
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libicu-dev libzip-dev zip \
    && docker-php-ext-install pdo pdo_pgsql intl

# Composer суулгах
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Ажиллах хавтас
WORKDIR /app
COPY . /app

# Dependencies татах (plugins disable хийхгүйгээр)
RUN composer install --no-dev --optimize-autoloader --no-interaction


# Symfony app ажиллуулах
CMD ["sh","-c","php -S 0.0.0.0:$PORT -t public"]
