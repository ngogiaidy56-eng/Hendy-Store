-- Hendy Store production schema for Neon PostgreSQL.
-- The PHP application applies equivalent idempotent statements at first boot.
CREATE TABLE IF NOT EXISTS users (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'customer',
  neon_auth_subject TEXT NULL,
  created_at TIMESTAMP NOT NULL
);
ALTER TABLE users ADD COLUMN IF NOT EXISTS neon_auth_subject TEXT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_neon_auth_subject
  ON users(neon_auth_subject) WHERE neon_auth_subject IS NOT NULL;

CREATE TABLE IF NOT EXISTS products (
  id BIGSERIAL PRIMARY KEY,
  slug VARCHAR(180) NOT NULL UNIQUE,
  title VARCHAR(180) NOT NULL,
  developer VARCHAR(160) NOT NULL,
  category VARCHAR(100) NOT NULL,
  short_description VARCHAR(255) NOT NULL,
  description TEXT NOT NULL,
  version VARCHAR(40) NOT NULL DEFAULT '1.0.0',
  platform VARCHAR(180) NOT NULL DEFAULT 'Windows, Android, Web',
  price_type VARCHAR(30) NOT NULL DEFAULT 'Free',
  price_amount_vnd BIGINT NOT NULL DEFAULT 0 CHECK (price_amount_vnd >= 0),
  icon VARCHAR(500) NOT NULL,
  cover VARCHAR(500) NOT NULL,
  screenshots_json TEXT NOT NULL DEFAULT '[]',
  download_url TEXT NOT NULL DEFAULT '',
  downloads BIGINT NOT NULL DEFAULT 0,
  rating NUMERIC(2,1) NOT NULL DEFAULT 0,
  featured SMALLINT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL,
  updated_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_products_category_status ON products(category,status);
CREATE INDEX IF NOT EXISTS idx_products_downloads ON products(downloads);

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key VARCHAR(64) PRIMARY KEY,
  hits INTEGER NOT NULL DEFAULT 0,
  window_started_at BIGINT NOT NULL
);
CREATE TABLE IF NOT EXISTS app_meta (
  meta_key VARCHAR(100) PRIMARY KEY,
  meta_value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS app_sessions (
  session_id VARCHAR(128) PRIMARY KEY,
  session_data TEXT NOT NULL,
  expires_at BIGINT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_app_sessions_expires ON app_sessions(expires_at);
