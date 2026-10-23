-- Additive tenant knowledge centre. Existing settings, history and quotas are preserved.
-- Install only through prepare-knowledge-centre.php after reviewing its checksum.
CREATE TABLE knowledge_centres (
  business_id BIGINT UNSIGNED NOT NULL,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
  draft_ciphertext MEDIUMBLOB NOT NULL,
  draft_nonce BINARY(12) NOT NULL,
  draft_tag BINARY(16) NOT NULL,
  draft_key_version SMALLINT UNSIGNED NOT NULL,
  draft_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  published_revision BIGINT UNSIGNED NULL,
  published_ciphertext MEDIUMBLOB NULL,
  published_nonce BINARY(12) NULL,
  published_tag BINARY(16) NULL,
  published_key_version SMALLINT UNSIGNED NULL,
  published_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  compiled_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  knowledge_revision BIGINT UNSIGNED NULL,
  published_by BIGINT UNSIGNED NULL,
  published_at DATETIME NULL,
  updated_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (business_id),
  CONSTRAINT fk_knowledge_centre_business FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE RESTRICT,
  CONSTRAINT fk_knowledge_centre_editor FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_knowledge_centre_publisher FOREIGN KEY (published_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_knowledge_centre_published CHECK (
    (published_revision IS NULL AND published_ciphertext IS NULL AND published_nonce IS NULL AND published_tag IS NULL
     AND published_key_version IS NULL AND published_hash IS NULL AND compiled_hash IS NULL AND knowledge_revision IS NULL
     AND published_by IS NULL AND published_at IS NULL)
    OR (published_revision IS NOT NULL AND published_revision <= revision AND published_ciphertext IS NOT NULL
        AND published_nonce IS NOT NULL AND published_tag IS NOT NULL AND published_key_version IS NOT NULL
        AND published_hash IS NOT NULL AND compiled_hash IS NOT NULL AND knowledge_revision IS NOT NULL
        AND published_by IS NOT NULL AND published_at IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE knowledge_media (
  id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id BIGINT UNSIGNED NOT NULL,
  mime VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  size INT UNSIGNED NOT NULL,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  body_ciphertext MEDIUMBLOB NOT NULL,
  body_nonce BINARY(12) NOT NULL,
  body_tag BINARY(16) NOT NULL,
  body_key_version SMALLINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_knowledge_media_tenant (business_id,id),
  KEY ix_knowledge_media_created (business_id,created_at),
  CONSTRAINT fk_knowledge_media_centre FOREIGN KEY (business_id) REFERENCES knowledge_centres (business_id) ON DELETE RESTRICT,
  CONSTRAINT fk_knowledge_media_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_knowledge_media_mime CHECK (mime IN ('image/jpeg','image/png')),
  CONSTRAINT chk_knowledge_media_size CHECK (size BETWEEN 1 AND 2097152)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
