#!/usr/bin/env zsh
# Helper script to create a MySQL test database and run the test suite using MySQL.
# Usage:
#  MYSQL_ROOT_USER=root MYSQL_ROOT_PASSWORD=secret DB_USERNAME=testuser DB_PASSWORD=testpass DB_DATABASE=next_venture ./scripts/test-mysql.sh

set -euo pipefail

# configuration (can be overridden by env)
MYSQL_HOST=${DB_HOST:-127.0.0.1}
MYSQL_PORT=${DB_PORT:-3306}
MYSQL_ROOT_USER=${MYSQL_ROOT_USER:-root}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD:-cp321.imation}
DB_NAME=${DB_DATABASE:-next_venture}
DB_USER=${DB_USERNAME:-testuser}
DB_PASS=${DB_PASSWORD:-testpass}

# Wait for mysql to accept connections (simple loop)
MAX_RETRIES=12
RETRY=0
until mysqladmin ping -h"${MYSQL_HOST}" -P"${MYSQL_PORT}" -u"${MYSQL_ROOT_USER}" -p"${MYSQL_ROOT_PASSWORD}" --silent; do
  RETRY=$((RETRY+1))
  if [ $RETRY -ge $MAX_RETRIES ]; then
    echo "MySQL did not become available after $MAX_RETRIES attempts"
    exit 1
  fi
  echo "Waiting for MySQL... (attempt $RETRY/$MAX_RETRIES)"
  sleep 2
done

# Create database and user (idempotent-ish)
SQL="CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASS}'; \
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%'; \
FLUSH PRIVILEGES;"

mysql -h"${MYSQL_HOST}" -P"${MYSQL_PORT}" -u"${MYSQL_ROOT_USER}" -p"${MYSQL_ROOT_PASSWORD}" -e "$SQL"

# Run tests with DB env overrides so phpunit uses MySQL
DB_CONNECTION=mysql \
DB_HOST=${MYSQL_HOST} \
DB_PORT=${MYSQL_PORT} \
DB_DATABASE=${DB_NAME} \
DB_USERNAME=${DB_USER} \
DB_PASSWORD=${DB_PASS} \
composer test
