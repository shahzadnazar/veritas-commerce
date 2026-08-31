#!/bin/sh
# The test suite runs against its own database so a `migrate:fresh` during
# testing can never drop development data.
set -e

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" <<-EOSQL
    CREATE DATABASE ${POSTGRES_DB}_test OWNER $POSTGRES_USER;
EOSQL
