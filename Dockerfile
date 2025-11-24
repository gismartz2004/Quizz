FROM php:8.2-apache

# 1. Instalar utilidades del sistema necesarias para Composer (git, unzip)
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install zip pdo pdo_mysql mysqli

# 2. Instalar Composer globalmente copiándolo de la imagen oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Configurar Apache para Cloud Run (Puerto 8080)
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf

# 4. Establecer directorio de trabajo
WORKDIR /var/www/html

# 5. Copiar todo el código al contenedor
COPY . /var/www/html/

# 6. EJECUTAR COMPOSER INSTALL (Esta es la solución al error)
# --no-dev: No instala dependencias de desarrollo
# --optimize-autoloader: Hace que cargue más rápido
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 7. Dar permisos correctos (importante después del composer install)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# 8. Exponer puerto y variables
EXPOSE 8080
ENV PORT 8080

# 9. Iniciar Apache
CMD ["apache2-foreground"]