FROM php:8.3-apache

# System deps: Tesseract + ImageMagick + required PHP extension libs
RUN apt-get update && apt-get install -y \
    tesseract-ocr \
    tesseract-ocr-eng \
    tesseract-ocr-fin \
    imagemagick \
    libmagickwand-dev \
    ghostscript \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN pecl install imagick \
    && docker-php-ext-enable imagick \
    && docker-php-ext-install exif

# ImageMagick policy: allow reading/writing images (locked down by default in Debian)
# Find policy.xml regardless of ImageMagick version (6 or 7)
RUN POLICY=$(find /etc/ImageMagick* -name policy.xml 2>/dev/null | head -1) \
    && if [ -n "$POLICY" ]; then \
        sed -i 's|rights="none" pattern="PDF"|rights="read|write" pattern="PDF"|' "$POLICY"; \
    fi

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