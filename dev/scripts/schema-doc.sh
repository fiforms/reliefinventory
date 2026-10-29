#!/usr/bin/env bash
# Regenerates dev/SCHEMA.md by migrating throwaway schemas from scratch. See the header of dev/SCHEMA.md.
# Needs the database user from dev/TESTING.md (it may create `test_reliefinventory%` schemas).
set -euo pipefail
cd "$(dirname "$0")/../.."

env_value() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d "\"'"; }
export DB_HOST="${DB_HOST:-$(env_value DB_HOST)}" DB_PORT="${DB_PORT:-$(env_value DB_PORT)}"
export DB_USERNAME="${DB_USERNAME:-$(env_value DB_USERNAME)}" DB_PASSWORD="${DB_PASSWORD:-$(env_value DB_PASSWORD)}"

base=test_reliefinventory_schemadoc
export SCHEMA_DOC_WAREHOUSE=$base SCHEMA_DOC_CONTROL=${base}_control SCHEMA_DOC_ACCOUNTS=${base}_accounts
export DB_DATABASE=$SCHEMA_DOC_WAREHOUSE RI_CONTROL_DATABASE=$SCHEMA_DOC_CONTROL RI_ACCOUNTS_DATABASE=$SCHEMA_DOC_ACCOUNTS
export APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync RI_RUNTIME=systemd
export RI_WAREHOUSE_STORAGE_DIR=storage/framework/testing/warehouses RI_WAREHOUSE_SECRETS_DIR=storage/framework/testing/warehouse-secrets

for d in $SCHEMA_DOC_WAREHOUSE $SCHEMA_DOC_CONTROL $SCHEMA_DOC_ACCOUNTS; do
    MYSQL_PWD=$DB_PASSWORD mysql -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" -e "DROP DATABASE IF EXISTS \`$d\`; CREATE DATABASE \`$d\`"
done
php artisan instance:migrate --force > /dev/null
php dev/scripts/schema-doc.php
MYSQL_PWD=$DB_PASSWORD mysql -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" -e "DROP DATABASE IF EXISTS \`$SCHEMA_DOC_WAREHOUSE\`; DROP DATABASE IF EXISTS \`$SCHEMA_DOC_CONTROL\`; DROP DATABASE IF EXISTS \`$SCHEMA_DOC_ACCOUNTS\`" 2>/dev/null
