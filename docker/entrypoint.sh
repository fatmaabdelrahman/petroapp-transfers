#!/usr/bin/env bash
set -e

# Wait for postgres to be ready (skip if no DB host configured, e.g. unit tests)
if [ -n "$DB_HOST" ]; then
  echo "Waiting for postgres at $DB_HOST:${DB_PORT:-5432}..."
  until pg_isready -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-postgres}" >/dev/null 2>&1; do
    sleep 1
  done
  echo "Postgres is ready."
fi

# Ensure app key exists
if [ -z "$APP_KEY" ] && [ -f .env ] && ! grep -q "^APP_KEY=base64" .env; then
  php artisan key:generate --force || true
fi

# Create the test database if it doesn't exist (safe to run every time)
if [ -n "$DB_HOST" ]; then
  PGPASSWORD="${DB_PASSWORD}" psql \
    -h "${DB_HOST}" -p "${DB_PORT:-5432}" \
    -U "${DB_USERNAME}" -d postgres \
    -c "CREATE DATABASE ${DB_DATABASE}_test" 2>/dev/null || true
fi

# Run migrations on startup (idempotent)
if [ "${RUN_MIGRATIONS:-true}" = "true" ] && [ -n "$DB_HOST" ]; then
  php artisan migrate --force || true
fi

exec "$@"
