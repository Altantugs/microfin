# ▶ PHP 8.3 CLI суурь
FROM php:8.3-cli

# ─ PHP өргөтгөлүүд (Postgres дэмжлэгтэй)
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpq-dev libicu-dev libzip-dev zip \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd pdo pdo_pgsql intl zip \
 && rm -rf /var/lib/apt/lists/*

# ─ Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ─ Prod орчин
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=prod \
    APP_DEBUG=0 \
    # Render $PORT өгдөг — default 8000
    PORT=8000

WORKDIR /app

# 1) Зөвхөн dependency файлуудыг хуулж, install (scripts АЖИЛЛУУЛАХГҮЙ)
COPY composer.json composer.lock* symfony.lock* ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-ansi \
    --no-progress --optimize-autoloader --no-scripts

# 2) Апп-ын бүх код
COPY . .

# 3) Prod autoload optimize
RUN composer dump-autoload -o --classmap-authoritative

# 4) Эрүүл мэндийн шалгалт байгаа тохиолдолд л (заавал биш)
#    HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
#      CMD php -r 'exit((@file_get_contents("http://127.0.0.1:${PORT}/healthz")===false)?1:0);'

# Render дээр $PORT-оор сонсдог байх ёстой
EXPOSE 8000
CMD ["sh", "-lc", "php -S 0.0.0.0:${PORT} -t public"]
