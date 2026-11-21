#!/bin/sh
set -e

[ -f .env ] || cp .env.example .env
[ -d vendor ] || composer install --no-interaction --prefer-dist

# An empty APP_KEY from env_file would hide the key in .env, so drop it first.
if [ -z "$APP_KEY" ]; then
  unset APP_KEY
  grep -q '^APP_KEY=.\+' .env || php artisan key:generate --force
fi

# Wait for Postgres, then build the schema with sample data.
until php artisan migrate:fresh --seed --force; do
  echo "Waiting for database..."; sleep 3
done

exec php artisan serve --host=0.0.0.0 --port=8000
