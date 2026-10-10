CREATE TABLE IF NOT EXISTS remembered_devices (
 selector CHAR(32) PRIMARY KEY,
 user_id INTEGER NOT NULL,
 token_hash CHAR(64) NOT NULL,
 password_fingerprint CHAR(64) NOT NULL,
 expires_at BIGINT NOT NULL
);
