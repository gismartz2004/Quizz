#!/bin/bash
set -e

# Usar variable de entorno PORT o defecto 8080
PORT="${PORT:-8080}"

# Reemplazar puerto en configuración de Apache
sed -i "s/80/${PORT}/g" /etc/apache2/sites-available/000-default.conf
sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf

# Configurar ServerName para evitar advertencia
echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Ejecutar el comando original (apache2-foreground)
exec "$@"
