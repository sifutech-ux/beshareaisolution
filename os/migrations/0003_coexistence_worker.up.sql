-- BeShare AI OS 0003 — Coexistence worker lease and delivery records.
-- Forward only. migrate.php does not apply this file.
-- Do not run it on production until a separate approval.

ALTER TABLE whatsapp_connections
  ADD COLUMN callback_ready_at DATETIME NULL AFTER sync_deadline_at,
  ADD COLUMN fields_ready_at DATETIME NULL AFTER callback_ready_at;

ALTER TABLE coexistence_sync_jobs
  ADD COLUMN claim_token CHAR(32) NULL AFTER request_id,
  ADD COLUMN claimed_until DATETIME NULL AFTER claim_token;

ALTER TABLE coexistence_sync_jobs DROP CONSTRAINT chk_sync_status;

ALTER TABLE coexistence_sync_jobs
  ADD CONSTRAINT chk_sync_status CHECK (status IN (
    'authorized', 'dispatching', 'in_progress', 'completed', 'declined', 'failed', 'expired', 'cancelled', 'review'
  ));
