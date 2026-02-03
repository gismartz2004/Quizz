#!/bin/bash
set -e

# Update Nginx port if provided by env
PORT="${PORT:-8080}"
sed -i "s/listen 8080;/listen ${PORT};/g" /etc/nginx/sites-available/default

# Start PHP-FPM in background
php-fpm -D

# Start Nginx in foreground
echo "Starting Nginx on port ${PORT}..."
nginx -g "daemon off;"
