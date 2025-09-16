FROM php:8.3-cli

# 1. Системийн хэрэгцээт багцууд + PHP өргөтгөлүүд
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libicu-dev libzip-dev zip \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) gd pdo pdo_pgsql intl zip \
 && rm -rf /var/lib/apt/lists/*

# 2. Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 3. Prod орчны тохиргоо + auto-scripts алгасах
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV SYMFONY_SKIP_AUTO_SCRIPT=1

# 4. Ажиллах хавтас
WORKDIR /app

# 5. Dependency install (build cache-д ээлтэй)
COPY composer.json composer.lock* symfony.lock* ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

# 6. Кодыг хуулна
COPY . /app

# 7. (сонголт) cache warmup хийх бол auto-scripts алгассан тул гараар хийж болно
# RUN php bin/console cache:warmup --env=prod || true

# 8. Symfony app ажиллуулах
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
