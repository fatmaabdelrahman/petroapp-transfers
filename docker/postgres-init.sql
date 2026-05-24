-- This script runs once when the Postgres data volume is first created.
-- It creates the test database alongside the production database.
-- Both share the same Postgres instance but are fully isolated schemas.

CREATE DATABASE petroapp_test;
GRANT ALL PRIVILEGES ON DATABASE petroapp_test TO petroapp;
