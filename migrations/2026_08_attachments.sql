-- One optional proof/supporting document per loan and per attendance request:
-- a single image (jpg/png/webp) or PDF, max 5 MB, stored in uploads/ under a
-- unique server-generated name. The column holds only that filename — the file
-- itself is validated (size + real MIME via finfo) by payroll_save_attachment()
-- in db_connect.php before anything is written here.
ALTER TABLE loans
    ADD COLUMN IF NOT EXISTS attachment VARCHAR(255) NULL AFTER damount;
ALTER TABLE attendance_requests
    ADD COLUMN IF NOT EXISTS attachment VARCHAR(255) NULL AFTER notes;

-- Leaves too: same one-file rule (medical certificate, etc.)
ALTER TABLE leave_requests
    ADD COLUMN IF NOT EXISTS attachment VARCHAR(255) NULL AFTER reason;
