FROM php:8.2-apache

# =========================
# SYSTEM DEPENDENCIES
# =========================
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libzip-dev \
    libcurl4-openssl-dev \
    tesseract-ocr \
    tesseract-ocr-eng \
    zip unzip git curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install curl exif gd pdo pdo_mysql mbstring zip \
    && rm -rf /var/lib/apt/lists/*

# Enable required Apache modules
RUN a2enmod rewrite

# =========================
# APP FILES
# =========================
COPY . /var/www/html/
WORKDIR /var/www/html/

# =========================
# COMPOSER
# =========================
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

# =========================
# PERMISSIONS & ENTRYPOINT
# =========================
RUN chown -R www-data:www-data /var/www/html

# Copy the runtime entrypoint script and make it executable
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Use the custom script to start the container
CMD ["/usr/local/bin/entrypoint.sh"]
