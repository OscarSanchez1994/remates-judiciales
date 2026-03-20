FROM php:8.2-fpm

# Instalar dependencias del sistema y extensiones PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libxml2-dev \
        poppler-utils \
    && docker-php-ext-install pdo pdo_mysql curl \
    && docker-php-ext-enable opcache \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html