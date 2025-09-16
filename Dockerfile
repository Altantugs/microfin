# PHP 8.3 болгоно (zipstream-php шаардлага)
FROM php:8.3-cli

# Системийн багцууд + PHP өргөтгөлүүд
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libicu-dev libzip-dev zip \
    libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j$(nproc) gd pdo pdo_pgsql intl zip

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Ажиллах хавтас
WORKDIR /app

# Build кэшийг ашиглахын тулд эхлээд зөвхөн composer файлууд
COPY composer.json composer.lock* symfony.lock* ./

# post-install скриптүүдээс болж тасрахгүйн тулд --no-scripts
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts

# Кодыг дараа нь хуулна
COPY . .

# Autoload-оо production горимоор сайжруулна
RUN composer dump-autoload --no-dev --optimize

# Runtime env
ENV APP_ENV=prod
ENV APP_DEBUG=0

# Render 8000 порт руу чиглүүлдэг
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
