#!/bin/bash
set -e

# default env values (can be overridden via Render environment variables)
: "${MYSQL_ROOT_PASSWORD:=rootpass}"
: "${MYSQL_DATABASE:=mcq_app}"
: "${MYSQL_USER:=mcq_user}"
: "${MYSQL_PASSWORD:=mcq_pass}"
: "${APP_USER:=app}"
: "${APP_DIR:=/var/www/html}"
: "${PORT:=10000}"

# ensure mysql datadir owner
chown -R mysql:mysql /var/lib/mysql || true

# initialize DB if not present
if [ ! -d "/var/lib/mysql/mysql" ] || [ -z "$(ls -A /var/lib/mysql 2>/dev/null)" ]; then
  echo "Initializing MariaDB data directory..."
  mysqld --initialize-insecure --user=mysql --datadir=/var/lib/mysql
fi

# start a temporary mysqld for initial setup (bind to localhost)
mysqld_safe --datadir=/var/lib/mysql --skip-networking=true &
MYSQLD_PID=$!

# wait for server socket to be ready
echo "Waiting for temporary MariaDB to start..."
for i in {1..30}; do
  if mysqladmin ping --silent; then
    break
  fi
  sleep 1
done

# secure and create DB/user if not exists
mysql -uroot <<SQL || true
ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PASSWORD}';
FLUSH PRIVILEGES;
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${MYSQL_USER}'@'%' IDENTIFIED BY '${MYSQL_PASSWORD}';
GRANT ALL ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL

# stop temp server
echo "Stopping temporary MariaDB..."
mysqladmin -uroot -p"${MYSQL_ROOT_PASSWORD}" shutdown || true
wait ${MYSQLD_PID} 2>/dev/null || true

# ensure permissions
chown -R mysql:mysql /var/lib/mysql
chown -R ${APP_USER}:${APP_USER} ${APP_DIR}

# adjust Apache/PHP built-in port by creating a small run script for supervisor to use environment PORT
cat > /docker-entrypoint/run-php-server.sh <<'SH'
#!/bin/bash
set -e
PORT="${PORT:-10000}"
APP_DIR="${APP_DIR:-/var/www/html}"
# Run PHP built-in server as the app user
exec su -c "php -S 0.0.0.0:${PORT} -t ${APP_DIR}" ${APP_USER}
SH
chmod +x /docker-entrypoint/run-php-server.sh

# Finally exec the original command (supervisord)
exec "$@"
