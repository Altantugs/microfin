FROM php:8.3-fpm-alpine AS php
WORKDIR /app
RUN apk add --no-cache icu-dev libzip-dev oniguruma-dev git unzip curl bash \
 && docker-php-ext-install intl pdo pdo_pgsql
RUN php -r "copy('https://getcomposer.org/installer','composer-setup.php');" \
 && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
 && rm composer-setup.php
COPY . /app
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader \
 && php bin/console cache:clear --env=prod

FROM caddy:2.8.4-alpine
WORKDIR /app
COPY --from=php /usr/local/bin/php-fpm /usr/local/bin/php-fpm
COPY --from=php /app /app
COPY infra/Caddyfile /etc/caddy/Caddyfile
EXPOSE 8080
CMD ["/bin/sh","-lc","php-fpm -D && exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile"]
