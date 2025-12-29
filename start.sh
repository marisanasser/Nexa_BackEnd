#!/bin/sh
set -e

cd /var/www/html

# Force environment variables to .env file to ensure Laravel picks them up
echo "STRIPE_SECRET_KEY=${STRIPE_SECRET_KEY}" >> .env
echo "STRIPE_PUBLISHABLE_KEY=${STRIPE_PUBLISHABLE_KEY}" >> .env
echo "STRIPE_WEBHOOK_SECRET=${STRIPE_WEBHOOK_SECRET}" >> .env

php artisan config:clear
php artisan cache:clear
php artisan view:clear

# Forensic Debugging
ls -R /var/www/html > public/fs_debug.txt
php artisan route:list > public/routes_debug.txt
chmod 777 public/*.txt


php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"

