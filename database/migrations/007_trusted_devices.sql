CREATE TABLE admin_trusted_devices (
 id BINARY(16) PRIMARY KEY,
 admin_id BINARY(16) NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 credential_binding CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 description VARCHAR(190) NOT NULL,
 created_at DATETIME(6) NOT NULL, expires_at DATETIME(6) NOT NULL,
 last_used_at DATETIME(6) NULL, revoked_at DATETIME(6) NULL,
 FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE,
 INDEX ix_trusted_owner(admin_id,revoked_at,expires_at),
 CONSTRAINT chk_trusted_expiry CHECK (expires_at > created_at)
) ENGINE=InnoDB;
ALTER TABLE admin_sessions ADD COLUMN trust_offered_at DATETIME(6) NULL;
