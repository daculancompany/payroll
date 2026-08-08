-- Audit trail for DTR recomputes. A recompute rewrites hours/late/UT/OT in
-- bulk (batch-wide or per-employee), so disputes need to know who ran it,
-- when, and how many rows moved. Written best-effort by
-- Action::logRecompute(); read by dtr-documents.php ("last recomputed" line).
CREATE TABLE IF NOT EXISTS dtr_recompute_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    ddtr_id     INT NULL,                       -- batch scope (NULL for per-employee runs)
    employee_id INT NULL,                       -- employee scope (NULL for batch runs)
    ran_by      INT NULL,                       -- users.id
    scope       VARCHAR(20) NOT NULL DEFAULT 'batch',   -- 'batch' | 'employee'
    scanned     INT NOT NULL DEFAULT 0,
    changed     INT NOT NULL DEFAULT 0,
    repending   INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ddtr (ddtr_id),
    KEY idx_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
