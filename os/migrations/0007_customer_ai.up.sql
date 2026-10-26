-- Extend the approved owner pilot without changing any existing enablement,
-- recipients, knowledge, quotas or approvals. Existing accounts keep test scope.
ALTER TABLE ai_reply_settings
  ADD COLUMN audience_mode VARCHAR(16) NOT NULL DEFAULT 'test',
  ADD COLUMN audience_approved_by BIGINT UNSIGNED NULL,
  ADD COLUMN audience_approved_at DATETIME NULL,
  ADD CONSTRAINT fk_ai_audience_approver FOREIGN KEY (audience_approved_by)
    REFERENCES users (id) ON DELETE RESTRICT,
  ADD CONSTRAINT chk_ai_audience_mode CHECK (audience_mode IN ('test','all')),
  ADD CONSTRAINT chk_ai_audience_approval CHECK (
    audience_mode = 'test' OR (audience_approved_by IS NOT NULL AND audience_approved_at IS NOT NULL)
  );

CREATE TABLE ai_reply_dispatch_state (
  name VARCHAR(32) NOT NULL,
  last_business_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_reply_dispatch_state (name,last_business_id,updated_at)
  VALUES ('all',0,UTC_TIMESTAMP());
