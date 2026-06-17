-- ============================================================
--  JSW Seafarer Staff Feedback Survey — MySQL Schema
--  Form Unique Code : JSW
--  Anonymous submissions identified by auto-generated reg_no
--
--  SHARED HOSTING (Hostinger) — Import directly into your
--  existing database via phpMyAdmin. No CREATE DATABASE needed.
-- ============================================================

-- ----------------------------------------------------------
-- 1. FORMS  (one row per form / department)
--    Each form has its own unique code and its own staff list
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `forms` (
  `form_code`   VARCHAR(10)   NOT NULL,
  `form_name`   VARCHAR(150)  NOT NULL,
  `description` TEXT          DEFAULT NULL,
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`form_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: JSW is your first form — add others here later
INSERT IGNORE INTO `forms` (`form_code`, `form_name`) VALUES
  ('JSW', 'JSW Seafarer Staff Feedback');

-- ----------------------------------------------------------
-- 2. STAFF  (per form — each form has its own staff list)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `staff` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `form_code`   VARCHAR(10)   NOT NULL,
  `staff_key`   VARCHAR(80)   NOT NULL,       -- slug e.g. 'capt_ravindra'
  `full_name`   VARCHAR(150)  NOT NULL,
  `role`        VARCHAR(150)  DEFAULT NULL,
  `is_active`   TINYINT(1)    NOT NULL DEFAULT 1,
  `sort_order`  TINYINT       NOT NULL DEFAULT 0,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_staff` (`form_code`, `staff_key`),
  KEY `idx_form_code` (`form_code`),
  CONSTRAINT `fk_staff_form` FOREIGN KEY (`form_code`) REFERENCES `forms` (`form_code`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed staff for JSW form
INSERT IGNORE INTO `staff` (`form_code`, `staff_key`, `full_name`, `role`, `sort_order`) VALUES
  ('JSW', 'capt_ravindra', 'Capt Ravindra Kumar Singh', '', 1),
  ('JSW', 'lakshita',      'Lakshita',                  '', 2),
  ('JSW', 'sanika',        'Sanika',                    '', 3),
  ('JSW', 'suhas',         'Suhas',                     '', 4),
  ('JSW', 'mithun',        'Mithun',                    '', 5),
  ('JSW', 'shanti',         'Shanti',                   '', 6);

-- ----------------------------------------------------------
-- 3. SUBMISSIONS  (one row per form submission)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `submissions` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `reg_no`        VARCHAR(30)   NOT NULL,
  `form_code`     VARCHAR(10)   NOT NULL,
  `vessel`        VARCHAR(100)  NOT NULL,
  `seafarer_rank` VARCHAR(100)  NOT NULL DEFAULT 'Not specified',
  `submitted_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_hash`       VARCHAR(64)   DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reg_no`   (`reg_no`),
  KEY `idx_form_code`      (`form_code`),
  KEY `idx_vessel`         (`vessel`),
  KEY `idx_submitted`      (`submitted_at`),
  CONSTRAINT `fk_sub_form` FOREIGN KEY (`form_code`) REFERENCES `forms` (`form_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 4. RESPONSES  (one row per staff member rated per submission)
--    Step 2 multi-select → N rows per submission
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `responses` (
  `id`                     INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `submission_id`          INT UNSIGNED     NOT NULL,
  `staff_id`               INT UNSIGNED     NOT NULL,   -- FK to staff.id
  `rating_responsiveness`  TINYINT UNSIGNED NOT NULL,
  `rating_resolution`      TINYINT UNSIGNED NOT NULL,
  `rating_professionalism` TINYINT UNSIGNED NOT NULL,
  `rating_knowledge`       TINYINT UNSIGNED NOT NULL,
  `rating_overall`         TINYINT UNSIGNED NOT NULL,
  `what_did_well`          TEXT             NOT NULL,
  `areas_to_improve`       TEXT             NOT NULL,
  `specific_incident`      TEXT             DEFAULT NULL,
  `other_feedback`         TEXT             DEFAULT NULL,
  `created_at`             DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submission_staff` (`submission_id`, `staff_id`),
  KEY `idx_staff_id`  (`staff_id`),
  CONSTRAINT `fk_resp_submission` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resp_staff`      FOREIGN KEY (`staff_id`)      REFERENCES `staff` (`id`),
  CONSTRAINT `chk_r1` CHECK (`rating_responsiveness`  BETWEEN 1 AND 5),
  CONSTRAINT `chk_r2` CHECK (`rating_resolution`      BETWEEN 1 AND 5),
  CONSTRAINT `chk_r3` CHECK (`rating_professionalism` BETWEEN 1 AND 5),
  CONSTRAINT `chk_r4` CHECK (`rating_knowledge`       BETWEEN 1 AND 5),
  CONSTRAINT `chk_r5` CHECK (`rating_overall`         BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 5. Reporting view
-- ----------------------------------------------------------
CREATE OR REPLACE VIEW `v_feedback` AS
SELECT
  s.reg_no,
  s.form_code,
  f.form_name,
  s.vessel,
  s.seafarer_rank,
  s.submitted_at,
  st.staff_key,
  st.full_name  AS staff_name,
  st.role       AS staff_role,
  r.rating_responsiveness,
  r.rating_resolution,
  r.rating_professionalism,
  r.rating_knowledge,
  r.rating_overall,
  ROUND((r.rating_responsiveness + r.rating_resolution +
         r.rating_professionalism + r.rating_knowledge +
         r.rating_overall) / 5, 2) AS avg_score,
  r.what_did_well,
  r.areas_to_improve,
  r.specific_incident,
  r.other_feedback
FROM `responses`   r
JOIN `submissions` s  ON s.id         = r.submission_id
JOIN `staff`       st ON st.id        = r.staff_id
JOIN `forms`       f  ON f.form_code  = s.form_code;

-- ----------------------------------------------------------
-- 6. Migration — run on existing databases only
--    (skip if importing this file on a fresh install)
-- ----------------------------------------------------------
-- ALTER TABLE `responses`
--   ADD COLUMN `other_feedback` TEXT DEFAULT NULL AFTER `specific_incident`;
--
-- ALTER TABLE `responses`
--   MODIFY COLUMN `areas_to_improve` TEXT NOT NULL;
--
-- Then re-run section 5 (CREATE OR REPLACE VIEW `v_feedback` ...) above.