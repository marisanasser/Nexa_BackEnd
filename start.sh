#!/bin/sh
set -e

cd /var/www/html

# Ensure .env exists
touch .env

set_env() {
  key="$1"
  val="$2"
  if [ -n "$val" ]; then
    grep -v "^${key}=" .env > .env.tmp || true
    mv .env.tmp .env
    printf "%s=%s\n" "$key" "$val" >> .env
  fi
}

set_env "APP_ENV" "${APP_ENV:-production}"
set_env "APP_DEBUG" "${APP_DEBUG:-false}"
set_env "APP_URL" "${APP_URL:-https://www.nexacreators.com}"
set_env "CACHE_DRIVER" "${CACHE_DRIVER:-file}"
set_env "SESSION_DRIVER" "${SESSION_DRIVER:-file}"
set_env "SESSION_LIFETIME" "${SESSION_LIFETIME:-120}"
set_env "SESSION_DOMAIN" "${SESSION_DOMAIN:-nexacreators.com}"
set_env "SESSION_SECURE_COOKIE" "${SESSION_SECURE_COOKIE:-true}"
set_env "SANCTUM_STATEFUL_DOMAINS" "${SANCTUM_STATEFUL_DOMAINS:-nexacreators.com,www.nexacreators.com}"
set_env "BROADCAST_DRIVER" "${BROADCAST_DRIVER}"
set_env "REVERB_APP_KEY" "${REVERB_APP_KEY}"
set_env "REVERB_APP_SECRET" "${REVERB_APP_SECRET}"
set_env "REVERB_APP_ID" "${REVERB_APP_ID}"
set_env "REVERB_HOST" "${REVERB_HOST}"
set_env "REVERB_PORT" "${REVERB_PORT}"
set_env "REVERB_SCHEME" "${REVERB_SCHEME}"
set_env "STRIPE_SECRET_KEY" "${STRIPE_SECRET_KEY}"
set_env "STRIPE_PUBLISHABLE_KEY" "${STRIPE_PUBLISHABLE_KEY}"
set_env "STRIPE_WEBHOOK_SECRET" "${STRIPE_WEBHOOK_SECRET}"
set_env "DATABASE_URL" "${DATABASE_URL}"
set_env "DB_CONNECTION" "${DB_CONNECTION}"
set_env "DB_HOST" "${DB_HOST}"
set_env "DB_PORT" "${DB_PORT}"
set_env "DB_DATABASE" "${DB_DATABASE}"
set_env "DB_USERNAME" "${DB_USERNAME}"
set_env "DB_PASSWORD" "${DB_PASSWORD}"
set_env "DB_SSLMODE" "${DB_SSLMODE}"
set_env "FRONTEND_URL" "${FRONTEND_URL}"
set_env "MAIL_MAILER" "${MAIL_MAILER}"
set_env "MAIL_HOST" "${MAIL_HOST}"
set_env "MAIL_PORT" "${MAIL_PORT}"
set_env "MAIL_USERNAME" "${MAIL_USERNAME}"
set_env "MAIL_PASSWORD" "${MAIL_PASSWORD}"
set_env "MAIL_ENCRYPTION" "${MAIL_ENCRYPTION}"
set_env "MAIL_FROM_ADDRESS" "${MAIL_FROM_ADDRESS}"
set_env "MAIL_FROM_NAME" "${MAIL_FROM_NAME}"
set_env "AWS_ACCESS_KEY_ID" "${AWS_ACCESS_KEY_ID}"
set_env "AWS_SECRET_ACCESS_KEY" "${AWS_SECRET_ACCESS_KEY}"
set_env "AWS_DEFAULT_REGION" "${AWS_DEFAULT_REGION}"
set_env "AWS_SES_REGION" "${AWS_SES_REGION}"
set_env "FILESYSTEM_DISK" "${FILESYSTEM_DISK:-gcs}"
set_env "GOOGLE_CLOUD_PROJECT_ID" "${GOOGLE_CLOUD_PROJECT_ID}"
set_env "GOOGLE_CLOUD_STORAGE_BUCKET" "${GOOGLE_CLOUD_STORAGE_BUCKET}"

if [ -z "${DB_HOST}" ] && [ -n "${CLOUD_SQL_CONNECTION_NAME}" ]; then
  set_env "DB_HOST" "/cloudsql/${CLOUD_SQL_CONNECTION_NAME}"
fi

if [ -z "${DB_CONNECTION}" ]; then
  set_env "DB_CONNECTION" "pgsql"
fi

# Normalize and parse DATABASE_URL or SUPABASE_DATABASE_URL if present
URL_SRC=""
if [ -n "${DATABASE_URL}" ]; then
  URL_SRC="${DATABASE_URL}"
elif [ -n "${SUPABASE_DATABASE_URL}" ]; then
  URL_SRC="${SUPABASE_DATABASE_URL}"
fi

if [ -n "${URL_SRC}" ]; then
  NORM_URL="${URL_SRC}"
  case "${NORM_URL}" in
    postgresql://*)
      NORM_URL="postgres://${NORM_URL#postgresql://}"
      ;;
    postgres://*)
      ;;
    *)
      # leave as is
      ;;
  esac

  URL_NO_SCHEME="${NORM_URL#*://}"
  USERPASS="${URL_NO_SCHEME%%@*}"
  REST="${URL_NO_SCHEME#*@}"
  USERNAME="${USERPASS%%:*}"
  PASSWORD="${USERPASS#*:}"
  HOSTPORT="${REST%%/*}"
  PATHQ="${REST#*/}"
  HOST="${HOSTPORT%%:*}"
  PORT="${HOSTPORT#*:}"
  DBNAME="${PATHQ%%\?*}"
  QUERY="${PATHQ#*\?}"

  # Apply parsed values if corresponding envs are empty
  [ -z "${DB_HOST}" ] && [ -n "${HOST}" ] && set_env "DB_HOST" "${HOST}"
  [ -z "${DB_PORT}" ] && [ -n "${PORT}" ] && set_env "DB_PORT" "${PORT}"
  [ -z "${DB_DATABASE}" ] && [ -n "${DBNAME}" ] && set_env "DB_DATABASE" "${DBNAME}"
  [ -z "${DB_USERNAME}" ] && [ -n "${USERNAME}" ] && set_env "DB_USERNAME" "${USERNAME}"
  [ -z "${DB_PASSWORD}" ] && [ -n "${PASSWORD}" ] && set_env "DB_PASSWORD" "${PASSWORD}"

  # sslmode=require default if not provided
  SSLMODE_REQ="require"
  case "${QUERY}" in
    *sslmode=*)
      # keep as provided through DB_SSLMODE or query, prefer explicit env
      ;;
    *)
      [ -z "${DB_SSLMODE}" ] && set_env "DB_SSLMODE" "${SSLMODE_REQ}"
      ;;
  esac
fi

# Ensure APP_KEY exists
if ! grep -q "^APP_KEY=" .env || [ -z "${APP_KEY}" ]; then
  php artisan key:generate --force || true
fi

# Clear and refresh caches/autoload
composer dump-autoload -o || true
php artisan optimize:clear || true
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan storage:link || true

# Run database migrations
php artisan migrate --force

# Forensic Debugging
ls -R /var/www/html > public/fs_debug.txt
php artisan route:list > public/routes_debug.txt
chmod 777 public/*.txt


php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
