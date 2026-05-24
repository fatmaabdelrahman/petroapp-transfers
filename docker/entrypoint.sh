#!/usr/bin/env bash
set -e

# Wait for postgres to be ready (skip if no DB host configured)
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

# Run migrations on startup (idempotent).
# Skipped when RUN_MIGRATIONS=false — used by the test runner so
# RefreshDatabase owns the test DB lifecycle entirely.
if [ "${RUN_MIGRATIONS:-true}" = "true" ] && [ -n "$DB_HOST" ]; then
  php artisan migrate --force || true
fi

exec "$@"
