#!/bin/sh
# Entrypoint del backend: espera MySQL, asegura APP_KEY y migra.
set -e

DB_HOST="${DB_HOST:-mysql}"
DB_PORT="${DB_PORT:-3306}"

# Espera a que MySQL acepte conexiones (máx. ~60 s).
i=0
until php -r "\$c = @new PDO('mysql:host=${DB_HOST};port=${DB_PORT}', getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: ''); exit(0);" 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    echo "MySQL no responde en ${DB_HOST}:${DB_PORT} despues de 60 s" >&2
    exit 1
  fi
  echo "Esperando MySQL en ${DB_HOST}:${DB_PORT}... (${i}s)"
  sleep 1
done

# APP_KEY:key:generate escribe en .env, así que si viene por entorno
# se exporta para que artisan la vea en este mismo arranque.
if [ -z "${APP_KEY:-}" ]; then
  export APP_KEY
  APP_KEY="$(php artisan key:generate --show)"
  echo "APP_KEY generada para este arranque (guardala en tu compose global)."
fi

# Migraciones (desactivable con SKIP_MIGRATIONS=true, p. ej. para réplicas).
if [ "${SKIP_MIGRATIONS:-false}" != "true" ]; then
  php artisan migrate --force
fi

# Seed solo si se pide explícito (las cuentas demo no van a prod por defecto).
if [ "${RUN_SEEDERS:-false}" = "true" ]; then
  php artisan db:seed --force
fi

php artisan config:clear
php artisan route:clear
php artisan view:clear

exec "$@"
