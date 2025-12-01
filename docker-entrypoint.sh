#!/bin/bash
set -e

# default env values (can be overridden via Render environment variables)
: "${APP_USER:=app}"
: "${APP_DIR:=/var/www/html}"
: "${PORT:=10000}"

# Ensure app directory ownership
chown -R "${APP_USER}:${APP_USER}" "${APP_DIR}" || true

# Create run script for PHP built-in server (used by supervisord)
cat > /docker-entrypoint/run-php-server.sh <<'SH'
#!/bin/bash
set -e
PORT="${PORT:-10000}"
APP_DIR="${APP_DIR:-/var/www/html}"

# Run PHP built-in server as the app user
exec su -c "php -S 0.0.0.0:${PORT} -t ${APP_DIR}" "${APP_USER}"
SH

chmod +x /docker-entrypoint/run-php-server.sh

# Finally exec the original command (supervisord)
exec "$@"
