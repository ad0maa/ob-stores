# Production image: FrankenPHP (PHP 8.4 + built-in web server) serving public/.
# Any request that isn't a real file goes to public/index.php, like php -S.

# 1. Build the Vue bundle and copy jQuery into public/
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
COPY frontend frontend
RUN npm ci && npm run build

# 2. PHP runtime
FROM dunglas/frankenphp:1-php8.4
RUN install-php-extensions pdo_mysql \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize
COPY --from=assets /app/public/build public/build
COPY --from=assets /app/public/assets/vendor public/assets/vendor

# Plain HTTP on 8080; the platform terminates HTTPS in front of it.
ENV SERVER_NAME=:8080
EXPOSE 8080
