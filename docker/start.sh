#!/bin/sh
set -e

# Pastikan direktori storage dan cache selalu ada (terutama jika persistent volume di-mount)
echo "Ensuring storage directory structure..."
mkdir -p /var/www/html/storage/app/public/barang-hilang
mkdir -p /var/www/html/storage/app/public/barang-temuan
mkdir -p /var/www/html/storage/app/public/profil-admin
mkdir -p /var/www/html/storage/app/public/profil-super
mkdir -p /var/www/html/storage/app/public/profil-user
mkdir -p /var/www/html/storage/framework/cache/data
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/logs

# Set izin akses www-data pada direktori storage
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Hapus symlink lama jika rusak dan buat ulang storage link
rm -f /var/www/html/public/storage
echo "Creating storage link..."
php artisan storage:link || true

# Jalankan migrasi database
echo "Running migrations..."
php artisan migrate --force

# Cache config untuk production
echo "Caching config..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Start supervisor (nginx + php-fpm)
echo "Starting services..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf

