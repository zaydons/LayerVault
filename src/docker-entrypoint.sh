#!/bin/bash

# LayerVault Docker Entrypoint Script
# Handles directory permissions, initialization, and dynamic PHP configuration

set -e

# Configure PHP settings based on environment variables
echo "Configuring PHP settings from environment variables..."
cat > /usr/local/etc/php/conf.d/uploads.ini <<EOF
upload_max_filesize = ${PHP_UPLOAD_MAX_FILESIZE}
post_max_size = ${PHP_POST_MAX_SIZE}
memory_limit = ${PHP_MEMORY_LIMIT}
max_execution_time = ${PHP_MAX_EXECUTION_TIME}
EOF

# Ensure data and uploads directories exist with proper permissions
echo "Setting up directories..."
mkdir -p /var/www/html/data /var/www/html/uploads /var/www/html/thumbnails
chown -R www-data:www-data /var/www/html/data
chmod -R 755 /var/www/html/data

# Initialize database if it doesn't exist
if [ ! -f "${LAYERVAULT_DB_PATH}" ]; then
    echo "Initializing database at ${LAYERVAULT_DB_PATH}..."
    touch "${LAYERVAULT_DB_PATH}"
    chown www-data:www-data "${LAYERVAULT_DB_PATH}"
    chmod 644 "${LAYERVAULT_DB_PATH}"
fi

echo "Starting Apache with LayerVault configuration..."
# Start Apache
exec apache2-foreground
