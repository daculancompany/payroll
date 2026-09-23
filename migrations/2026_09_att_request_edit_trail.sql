-- Admin may correct the hours on an attendance request, including one already
-- approved (update_attendance_request). The correction shows on the approval
-- trail, so the last edit is kept on the row: who, when, and what changed.
ALTER TABLE attendance_requests
    ADD COLUMN IF NOT EXISTS edited_by   INT          NULL DEFAULT NULL AFTER cancel_reason,
    ADD COLUMN IF NOT EXISTS edited_at   DATETIME     NULL DEFAULT NULL AFTER edited_by,
    ADD COLUMN IF NOT EXISTS edit_note   VARCHAR(255) NULL DEFAULT NULL AFTER edited_at;
