-- HendyStore Store Ecosystem: additive PostgreSQL migration.
-- Safe to apply repeatedly. Existing users, products, balances and sessions are not deleted or rewritten.
CREATE TABLE IF NOT EXISTS app_product_specs (
  product_id BIGINT PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
  video_url TEXT NOT NULL DEFAULT '',
  minimum_requirements TEXT NOT NULL DEFAULT '',
  privacy_details TEXT NOT NULL DEFAULT '',
  updated_at TIMESTAMP NOT NULL
);
CREATE TABLE IF NOT EXISTS app_product_screenshots (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  image_url TEXT NOT NULL,
  caption VARCHAR(180) NOT NULL DEFAULT '',
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL
);
CREATE TABLE IF NOT EXISTS app_versions (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  version VARCHAR(60) NOT NULL,
  release_notes TEXT NOT NULL DEFAULT '',
  platform VARCHAR(40) NOT NULL DEFAULT 'universal',
  architecture VARCHAR(40) NOT NULL DEFAULT 'universal',
  min_os VARCHAR(120) NOT NULL DEFAULT '',
  package_url TEXT NOT NULL,
  package_size_bytes BIGINT NOT NULL DEFAULT 0,
  is_current SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL
);
CREATE TABLE IF NOT EXISTS app_product_reviews (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
  review_text TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  developer_reply TEXT NOT NULL DEFAULT '',
  developer_reply_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL,
  updated_at TIMESTAMP NOT NULL,
  UNIQUE(product_id, user_id)
);
CREATE TABLE IF NOT EXISTS app_user_library (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  source VARCHAR(20) NOT NULL DEFAULT 'download',
  last_version_id BIGINT NULL,
  last_platform VARCHAR(40) NOT NULL DEFAULT 'unknown',
  created_at TIMESTAMP NOT NULL,
  updated_at TIMESTAMP NOT NULL,
  UNIQUE(user_id, product_id)
);
CREATE TABLE IF NOT EXISTS app_download_history (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
  product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  version_id BIGINT NULL,
  platform VARCHAR(40) NOT NULL DEFAULT 'unknown',
  package_name VARCHAR(180) NOT NULL DEFAULT '',
  ip_hash VARCHAR(64) NOT NULL DEFAULT '',
  user_agent VARCHAR(500) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL
);
CREATE TABLE IF NOT EXISTS app_user_notifications (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  product_id BIGINT NULL REFERENCES products(id) ON DELETE CASCADE,
  version_id BIGINT NULL,
  notification_type VARCHAR(40) NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL DEFAULT '',
  read_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_app_versions_product ON app_versions(product_id, created_at);
CREATE INDEX IF NOT EXISTS idx_app_reviews_product ON app_product_reviews(product_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_app_library_user ON app_user_library(user_id, updated_at);
CREATE INDEX IF NOT EXISTS idx_app_download_user ON app_download_history(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_app_notifications_user ON app_user_notifications(user_id, read_at, created_at);

CREATE TABLE IF NOT EXISTS app_download_grants (
  token_hash VARCHAR(64) PRIMARY KEY,
  user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
  product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  version_id BIGINT NULL,
  platform VARCHAR(40) NOT NULL DEFAULT 'unknown',
  expires_at BIGINT NOT NULL,
  used_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL
);
