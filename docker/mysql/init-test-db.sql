-- Runs once, on a fresh mysql_data volume (docker-entrypoint-initdb.d).
-- Separate database so the PHPUnit suite can freely migrate/truncate
-- without touching jobs_db, which you poke at manually.
CREATE DATABASE IF NOT EXISTS jobs_db_test;
