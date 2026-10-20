#!/bin/bash
# Legt beim ersten Start eine Test-Datenbank an und gibt dem Anwendungsbenutzer Rechte darauf.
set -e
mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\_test\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
