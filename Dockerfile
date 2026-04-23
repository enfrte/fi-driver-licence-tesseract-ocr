FROM php:8.3-apache

# System deps: Tesseract only
RUN apt-get update && apt-get install -y \
    tesseract-ocr \
    tesseract-ocr-eng \
    tesseract-ocr-fin \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install exif

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App files
COPY src/ /var/www/html/
COPY composer.json /var/www/html/

WORKDIR /var/www/html

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Writable dirs for uploads and tesseract tmp files
RUN mkdir -p /var/www/html/uploads /tmp/ocr \
    && chown -R www-data:www-data /var/www/html /tmp/ocr \
    && chmod 700 /tmp/ocr

# PHP config: allow larger uploads for ID photo files
RUN echo "upload_max_filesize=20M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size=20M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time=60" >> /usr/local/etc/php/conf.d/uploads.ini

EXPOSE 80