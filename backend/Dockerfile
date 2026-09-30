# ============================================================
# Backend API — PHP 8.2 FPM (FastCGI :9000)
# ============================================================
FROM php:8.2-fpm-alpine

# Paquets système + headers de compilation des extensions
RUN apk add --no-cache \
    bash \
    git \
    curl \
    unzip \
    zip \
    autoconf \
    build-base \
    linux-headers \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    oniguruma-dev \
    icu-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath gd zip exif intl pcntl \
    && pecl install --nobuild redis-6.0.2 \
    && cd "$(pecl config-get temp_dir)/redis" \
    && phpize && ./configure && make && make install \
    && docker-php-ext-enable redis

# Binaire Composer officiel
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# .dockerignore exclut vendor/ et .env : les dépendances sont
# installées dans l'image (production, autoload optimisé).
COPY . .

RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --no-progress \
        --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
