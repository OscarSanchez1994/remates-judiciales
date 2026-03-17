FROM php:8.2-fpm

# Instalar extensiones necesarias
RUN docker-php-ext-install pdo pdo_mysql curl json

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html