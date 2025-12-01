#!/bin/bash
set -e

# default env values (can be overridden via Render environment variables)
: "${APP_DIR:=/var/www/html}"
: "${PORT:=10000}"

# Ensure app dir exists
mkdir -p "${APP_DIR}"

# Create run script for PHP built-in server (used by supervisord)
cat > /docker-entrypoint/run-php-server.sh <<'SH'
#!/bin/bash
set -e
PORT="${PORT:-10000}"
APP_DIR="${APP_DIR:-/var/www/html}"

cd "$APP_DIR"
# Start PHP built-in server on 0.0.0.0:$PORT
exec php -S 0.0.0.0:"$PORT" -t "$APP_DIR"
SH

chmod +x /docker-entrypoint/run-php-server.sh

# Finally exec the original command (supervisord)
exec "$@"
