-- Final department list (IDs are fixed/official — they match employee.department_id).
-- Note: there is intentionally NO DEPT 9 in the official list.
-- Idempotent: re-running updates names in place without duplicating rows.

INSERT INTO department (id, name) VALUES
  (1,  'ADMINISTRATION/FINANCE/HR/BILLING/PHILHEALTH/RECORDS'),
  (2,  'CENTRAL SUPPLY ROOM'),
  (3,  'DIETARY'),
  (4,  'EYE CENTER'),
  (5,  'HOUSEKEEPING/LINEN'),
  (6,  'LABORATORY/XRAY'),
  (7,  'MAINTENANCE'),
  (8,  'RADIOLOGY/MRI/XTRAY'),
  (10, 'NURSING SERVICE/RES.PHY'),
  (11, 'CARDIOVASCULAR LABORATORY'),
  (12, 'DELIVERY ROOM/NICU'),
  (13, 'EMERGENCY/OUT PATIENT'),
  (14, 'HEMODIALYSIS/HDU TECHNICIAN'),
  (15, 'INTENSIVE CARE UNIT'),
  (16, 'NUCLEAR MEDICINE'),
  (17, 'ONCOLOGY'),
  (18, 'OPERATING ROOM'),
  (19, 'PHARMACY'),
  (20, 'RESIDENT PHYSICIAN')
ON DUPLICATE KEY UPDATE name = VALUES(name);
