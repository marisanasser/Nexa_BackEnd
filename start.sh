#!/bin/sh
set -e

cd /var/www/html

php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"

