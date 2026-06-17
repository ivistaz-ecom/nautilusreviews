-- ============================================================
--  JSW Seafarer Staff Feedback Survey — MySQL Schema
--  Form Unique Code : JSW
--  Anonymous submissions identified by auto-generated reg_no
-- ============================================================

CREATE DATABASE IF NOT EXISTS jsw_survey CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE jsw_survey;

-- ----------------------------------------------------------
-- 1. SUBMISSIONS  (one row per form submission)
--    A single seafarer submits once per visit; they may rate
--    multiple staff members within that one submission.
-- ----------------------------------------------------------
CREATE TABLE submissions (
    id            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    reg_no        VARCHAR(30)      NOT NULL UNIQUE,           -- e.g. JSW-20240617-000001
    form_code     VARCHAR(10)      NOT NULL DEFAULT 'JSW',    -- unique code per form set
    vessel        VARCHAR(100)     NOT NULL,
    seafarer_rank VARCHAR(100)     NOT NULL DEFAULT 'Not specified',
    submitted_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_hash       VARCHAR(64)      NULL,                      -- optional: SHA-256 of IP (no raw IP stored)
    PRIMARY KEY (id),
    INDEX idx_form_code  (form_code),
    INDEX idx_vessel     (vessel),
    INDEX idx_submitted  (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------
-- 2. STAFF  (master list shared across all JSW forms)
-- ----------------------------------------------------------
CREATE TABLE staff (
    id         VARCHAR(50)  NOT NULL,                         -- matches JS id e.g. 'capt_ravindra'
    full_name  VARCHAR(150) NOT NULL,
    role       VARCHAR(150) NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed staff from the HTML
INSERT INTO staff (id, full_name, role) VALUES
  ('capt_ravindra', 'Capt Ravindra Kumar Singh', ''),
  ('lakshita',      'Lakshita',                  ''),
  ('sanika',        'Sanika',                    ''),
  ('suhas',         'Suhas',                     ''),
  ('mithun',        'Mithun',                    '');

-- ----------------------------------------------------------
-- 3. RESPONSES  (one row per staff member rated per submission)
--    Step 2 = multi-select  →  N rows per submission
--    Step 3 = ratings block  →  stored here
-- ----------------------------------------------------------
CREATE TABLE responses (
    id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    submission_id      INT UNSIGNED  NOT NULL,
    staff_id           VARCHAR(50)   NOT NULL,

    -- Step 3 ratings (1–5)
    rating_responsiveness  TINYINT UNSIGNED NOT NULL,
    rating_resolution      TINYINT UNSIGNED NOT NULL,
    rating_professionalism TINYINT UNSIGNED NOT NULL,
    rating_knowledge       TINYINT UNSIGNED NOT NULL,
    rating_overall         TINYINT UNSIGNED NOT NULL,
    avg_score              DECIMAL(4,2) GENERATED ALWAYS AS (
                               (rating_responsiveness + rating_resolution +
                                rating_professionalism + rating_knowledge +
                                rating_overall) / 5
                           ) STORED,

    -- Qualitative
    what_did_well      TEXT          NOT NULL,
    areas_to_improve   TEXT          NULL,
    specific_incident  TEXT          NULL,

    created_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_submission_staff (submission_id, staff_id),
    CONSTRAINT fk_resp_submission FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_resp_staff      FOREIGN KEY (staff_id)      REFERENCES staff(id),
    CONSTRAINT chk_r1 CHECK (rating_responsiveness  BETWEEN 1 AND 5),
    CONSTRAINT chk_r2 CHECK (rating_resolution      BETWEEN 1 AND 5),
    CONSTRAINT chk_r3 CHECK (rating_professionalism BETWEEN 1 AND 5),
    CONSTRAINT chk_r4 CHECK (rating_knowledge       BETWEEN 1 AND 5),
    CONSTRAINT chk_r5 CHECK (rating_overall         BETWEEN 1 AND 5),
    INDEX idx_staff_id    (staff_id),
    INDEX idx_avg_score   (avg_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------
-- 4. Handy view for reporting
-- ----------------------------------------------------------
CREATE OR REPLACE VIEW v_feedback AS
SELECT
    s.reg_no,
    s.form_code,
    s.vessel,
    s.seafarer_rank,
    s.submitted_at,
    st.full_name      AS staff_name,
    st.role           AS staff_role,
    r.rating_responsiveness,
    r.rating_resolution,
    r.rating_professionalism,
    r.rating_knowledge,
    r.rating_overall,
    r.avg_score,
    r.what_did_well,
    r.areas_to_improve,
    r.specific_incident
FROM responses  r
JOIN submissions s  ON s.id = r.submission_id
JOIN staff       st ON st.id = r.staff_id;
