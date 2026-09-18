FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    bash \
    git \
    curl \
    unzip \
    autoconf \
    build-base \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    oniguruma-dev \
    icu-dev \
    libxml2-dev \
    zip \
    libzip-dev \
    libpq-dev \
    linux-headers \
    && docker-php-ext-configure zip \
    && docker-php-ext-install pdo_mysql bcmath zip exif intl pcntl \
    && pecl install --nobuild redis-6.0.2 && cd "$(pecl config-get temp_dir)/redis" && phpize && ./configure && make && make install \
    && docker-php-ext-enable redis \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
