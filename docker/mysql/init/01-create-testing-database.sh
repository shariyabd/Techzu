#!/bin/sh
set -e

docker_process_sql --database=mysql <<EOSQL
CREATE DATABASE IF NOT EXISTS techzu_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`techzu\\_testing%\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
EOSQL
