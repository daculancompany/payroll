-- ---------------------------------------------------------------------------
-- Face preview photo for kiosk enrollments.
--
-- The mobile kiosk stores only the embedding (numbers) — nothing a human can
-- look at. This adds the cropped face JPEG captured at enrollment so the
-- admin employee pages can show who the registered face actually is.
--
-- Only the relative path lives in the database; the image itself is written
-- to uploads/faces/. One photo per face row, replaced on re-enroll — same
-- rule as the embedding.
--
-- Run once:  mysql -u root payroll < migrations/2026_07_biometric_kiosk_face_photo.sql
-- Safe to re-run on MariaDB (XAMPP default) thanks to IF NOT EXISTS.
-- ---------------------------------------------------------------------------

ALTER TABLE `biometric_kiosk_faces`
  ADD COLUMN IF NOT EXISTS `photo` VARCHAR(255) NULL DEFAULT NULL AFTER `embedding`;
