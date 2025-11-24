FROM php:8.2-apache

# 1. Instalar dependencias del sistema (git y zip son necesarios para Composer)
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip pdo pdo_mysql mysqli

# 2. Instalar Composer (copiándolo de la imagen oficial)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Configurar Apache para Cloud Run
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf

# 4. Directorio de trabajo
WORKDIR /var/www/html

# 5. Copiar archivos (Gracias al .dockerignore, NO copiará la carpeta vendor)
COPY . /var/www/html/

# 6. EJECUTAR COMPOSER INSTALL (Aquí se crea la carpeta vendor nueva y limpia)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 7. Dar permisos a la carpeta html (incluyendo la nueva vendor)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# 8. Variables de entorno
EXPOSE 8080
ENV PORT 8080

# 9. Iniciar
CMD ["apache2-foreground"]