FROM php:8.2-apache

# 1. Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpq-dev \
    && docker-php-ext-install zip pdo pdo_mysql pdo_pgsql mysqli \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Configure Apache for Cloud Run / Environment variables
# (Moved to entrypoint.sh for runtime configuration)

# 4. Set Working Directory
WORKDIR /var/www/html

# 5. Copy Files (respects .dockerignore)
COPY . /var/www/html/

# 6. Install Dependencies (Optimized for Production)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 7. Permissions & Scripts
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && sed -i 's/\r$//' /var/www/html/entrypoint.sh \
    && chmod +x /var/www/html/entrypoint.sh

# 8. Environment Defaults
ENV PORT=8080
EXPOSE 8080

# 9. Start Apache via Entrypoint
ENTRYPOINT ["/var/www/html/entrypoint.sh"]
CMD ["apache2-foreground"]