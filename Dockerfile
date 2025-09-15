FROM php:8.2-cli

# Системийн сангууд
RUN apt-get update && apt-get install -y \
    git unzip curl ca-certificates libpq-dev libicu-dev libzip-dev zip \
 && docker-php-ext-install pdo pdo_pgsql intl zip opcache \
 && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Ажиллах хавтас
WORKDIR /app

# Dependencies install only
COPY composer.json composer.lock* symfony.lock* ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts

# Copy all code after composer
COPY . .

# Дараа нь scripts ажиллуул
RUN composer install --no-dev --optimize-autoloader --no-interaction


# PROD горим.
ENV APP_ENV=prod
ENV APP_DEBUG=0

# Symfony cache-ийг build үе дээр урьдчилж бэлдэнэ.
RUN php bin/console cache:clear --env=prod \
 && php bin/console cache:warmup --env=prod


# Үлдсэн код
COPY . .

# Prod ENV (+ кэшийг бэлдье)
ENV APP_ENV=prod \
    APP_DEBUG=0
# Render дээр APP_SECRET-г Dashboard-оос ENV-ээр өг.
RUN php bin/console cache:clear --env=prod \
 && php bin/console cache:warmup --env=prod

# OPcache тохиргоо (prod-д маш чухал)
RUN printf "%s\n" \
  "opcache.enable=1" \
  "opcache.enable_cli=1" \
  "opcache.memory_consumption=192" \
  "opcache.interned_strings_buffer=16" \
  "opcache.max_accelerated_files=20000" \
  "opcache.validate_timestamps=0" \
  "opcache.jit=1255" \
  "opcache.jit_buffer_size=64M" \
  > /usr/local/etc/php/conf.d/opcache.ini

# Фолдер permission (кэш/лог бичих)
RUN chown -R www-data:www-data var \
 && find var -type d -exec chmod 775 {} \;

USER www-data

# IMPORTANT: built-in server-ийг router-тай ажиллуул!
CMD ["sh","-c","php -S 0.0.0.0:$PORT -t public public/index.php"]
