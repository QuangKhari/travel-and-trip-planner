# Travela — ảnh php-fpm dùng chung cho app, queue worker và scheduler (B2, PF-02).
#   docker compose build && docker compose up -d
# CSS/JS của site là file tĩnh trong public/ có tham số ?v= (đổi khi sửa) nên không cần bước build Node.
# Giai đoạn 1: thư viện PHP (không có gói dev, autoload tối ưu)
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-req=ext-*
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# Giai đoạn 2: chạy
FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev libicu-dev libonig-dev \
        default-mysql-client libfcgi-bin procps curl unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring intl zip gd bcmath opcache pcntl exif \
    && apt-get purge -y --auto-remove && rm -rf /var/lib/apt/lists/*

COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

ENV PHP_FPM_MAX_CHILDREN=24
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY docker/entrypoint.sh /usr/local/bin/travela-entrypoint
RUN chmod +x /usr/local/bin/travela-entrypoint \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["travela-entrypoint"]
CMD ["php-fpm"]
