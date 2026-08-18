-- Scanner-side similarity reports.
-- The desktop fingerprint scanner posts one row whenever a live scan verified
-- against MORE THAN ONE employee (possible duplicate / look-alike enrollment),
-- with the ranked candidate list it saw. Lets admins find which fingers need
-- re-enrolling instead of guessing from "wrong person" DTR complaints.
CREATE TABLE IF NOT EXISTS biometric_similarity_reports (
  id                  INT(11)      NOT NULL AUTO_INCREMENT,
  scan_time           DATETIME     NOT NULL,
  matched_employee_id INT(11)      DEFAULT NULL COMMENT 'employee the punch was saved for; NULL when rejected as ambiguous',
  decision            VARCHAR(20)  NOT NULL DEFAULT 'saved' COMMENT 'saved | ambiguous | debug | audit | nomatch',
  candidate_count     INT(11)      NOT NULL DEFAULT 0 COMMENT 'employees that VERIFIED at the identify threshold',
  candidates          TEXT         NOT NULL COMMENT 'JSON [{employee_id,name,finger,far,percent,verified}] best first',
  device              VARCHAR(100) DEFAULT NULL,
  operator_user_id    INT(11)      DEFAULT NULL,
  reviewed_at         DATETIME     DEFAULT NULL,
  reviewed_by         INT(11)      DEFAULT NULL,
  review_note         VARCHAR(255) DEFAULT NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_matched (matched_employee_id),
  KEY idx_scan_time (scan_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
