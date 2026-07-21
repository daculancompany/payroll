-- 10-finger enrollment: track capture quality per finger so the hands UI can
-- flag weak enrollments (amber) vs good ones (green). finger_index already
-- identifies WHICH finger (canonical codes LEFT_THUMB … RIGHT_PINKY); this adds
-- the "how good was it" dimension. NULL = quality not reported (legacy rows).

ALTER TABLE `biometric_kiosk_templates`
    ADD COLUMN `quality` SMALLINT NULL DEFAULT NULL AFTER `template`;
