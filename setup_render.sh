#!/usr/bin/env bash
set -euo pipefail

# 0) Safety: repo шалгах, branch+tag
test -f composer.json || { echo "composer.json олдсонгүй. Repo root дээрээ ажиллуул."; exit 1; }
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || { echo "Git repo биш сан шиг байна."; exit 1; }

BRANCH="chore/render-prod-hardening"
TAG="BEFORE_RENDER_HARDENING"

git checkout -b "$BRANCH" || git checkout "$BRANCH"
git tag -f "$TAG" || true

# 1) Prod override-ууд
mkdir -p config/packages/prod
cat > config/packages/prod/framework.yaml <<'YAML'
framework:
  trusted_proxies: '%env(TRUSTED_PROXIES)%'
  trusted_hosts: '%env(TRUSTED_HOSTS)%'
  session:
    cookie_secure: 'auto'
    cookie_samesite: lax
    cookie_httponly: true
  http_method_override: false
  php_errors:
    log: true
YAML

cat > config/packages/prod/doctrine.yaml <<'YAML'
doctrine:
  dbal:
    url: '%env(resolve:DATABASE_URL)%'
    server_version: '15'
YAML

# 2) Dockerfile + Caddyfile
mkdir -p infra
cat > Dockerfile <<'DOCKER'
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
DOCKER

cat > infra/Caddyfile <<'CADDY'
:8080 {
    root * /app/public
    encode zstd gzip
    php_fastcgi 127.0.0.1:9000
    file_server
    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
        X-Frame-Options "DENY"
        X-Content-Type-Options "nosniff"
        Referrer-Policy "no-referrer"
        Permissions-Policy "geolocation=(), microphone=(), camera=()"
    }
    @healthz { path /healthz }
    respond @healthz 200
}
CADDY

# 3) CI workflow
mkdir -p .github/workflows
cat > .github/workflows/ci.yml <<'YML'
name: CI
on:
  push: { branches: [ "**" ] }
  pull_request: { branches: [ "**" ] }
jobs:
  php:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: intl, pdo_pgsql
          coverage: none
      - name: Install deps
        run: composer install --no-interaction --prefer-dist
      - name: Lint YAML/Twig
        run: |
          php bin/console lint:yaml config --parse-tags
          php bin/console lint:twig templates
      - name: Validate Doctrine schema
        env:
          DATABASE_URL: 'sqlite:///%kernel.project_dir%/var/ci.db'
        run: |
          php bin/console doctrine:database:create --if-not-exists
          php bin/console doctrine:schema:validate --skip-sync
      - name: PHPUnit (optional)
        run: |
          if [ -d tests ]; then ./vendor/bin/phpunit --colors=always; else echo "no tests"; fi
YML

# 4) .env.example-д prod env жишээ нэмэх (давхардахгүй)
touch .env.example
if ! grep -q "TRUSTED_HOSTS" .env.example; then
cat >> .env.example <<'ENVV'

# --- Render prod env examples ---
# DATABASE_URL="postgresql://user:pass@host:5432/dbname?sslmode=require"
TRUSTED_PROXIES="127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16"
TRUSTED_HOSTS=".^myapp\\.onrender\\.com$"
ENVV
fi

# 5) Commit
git add -A
git commit -m "chore(prod): Render hardening via prod overrides, Docker (PHP-FPM+Caddy), healthcheck, CI" || true

echo "✅ Бэлэн. Push хий:"
echo "git push -u origin $BRANCH"

echo "➡️ Render дээр env-ээ тавь: APP_ENV=prod, APP_DEBUG=0, APP_SECRET, DATABASE_URL (sslmode=require), TRUSTED_*"
echo "Start Command: php-fpm -D && caddy run --config /etc/caddy/Caddyfile --adapter caddyfile"
echo "Health check: GET /healthz"
echo "Post-deploy: doctrine:migrations:migrate + doctrine:schema:validate"
