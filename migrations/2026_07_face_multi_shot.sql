-- ---------------------------------------------------------------------------
-- Allow an employee to hold SEVERAL face vectors instead of one.
--
-- The kiosk enrolls a face from several angles/lighting conditions and matches
-- a live scan against every stored vector, keeping the best score. The original
-- UNIQUE (employee_id, model) collapsed those shots into a single row: each
-- upload overwrote the previous one and only the last angle survived.
--
-- `face_index` mirrors `finger_index` on biometric_kiosk_templates — a stable
-- slot label ("face-1"…"face-6") so re-enrolling the same slot REPLACES it
-- rather than growing the table without limit.
--
-- `photo` holds the cropped face image for display only (roster avatars,
-- enrollment review). Recognition never reads it.
--
-- Run once:  mysql -u root payroll < migrations/2026_07_face_multi_shot.sql
-- Safe to re-run.
-- ---------------------------------------------------------------------------

-- Existing rows are the employee's first shot.
ALTER TABLE `biometric_kiosk_faces`
  ADD COLUMN IF NOT EXISTS `face_index` VARCHAR(32) NOT NULL DEFAULT 'face-1' AFTER `model`;

ALTER TABLE `biometric_kiosk_faces`
  ADD COLUMN IF NOT EXISTS `photo` VARCHAR(255) DEFAULT NULL AFTER `embedding`;

ALTER TABLE `biometric_kiosk_faces`
  DROP INDEX IF EXISTS `uniq_employee_model`;

ALTER TABLE `biometric_kiosk_faces`
  ADD UNIQUE KEY IF NOT EXISTS `uniq_employee_model_face` (`employee_id`, `model`, `face_index`);
