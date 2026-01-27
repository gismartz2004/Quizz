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

# 1.5 Configure PHP for performance
RUN { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=100M'; \
        echo 'post_max_size=100M'; \
        echo 'max_execution_time=300'; \
        echo 'date.timezone=America/Guayaquil'; \
    } > /usr/local/etc/php/conf.d/docker-php-config.ini

# 2. Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Configure Apache for Cloud Run / Environment variables
# (Moved to entrypoint.sh for runtime configuration)

# 4. Set Working Directory
WORKDIR /var/www/html

# 5. Copy entrypoint separately outside app volume path
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

# 6. Copy application files (respects .dockerignore)
COPY . /var/www/html/

# 7. Install Dependencies (Optimized for Production)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 8. Permissions & Scripts
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

# 9. Environment Defaults
ENV PORT=8080
EXPOSE 8080

# 10. Start Apache via Entrypoint (outside mounted volume)
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]