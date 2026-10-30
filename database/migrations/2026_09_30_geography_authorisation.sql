-- ============================================================================
--  Smart HR System — upgrade 1.5.0 -> 2.0.0
--  Authorisation follows the district administration:
--
--    Super Admin               everything
--    Deputy Commissioner (DC)  every employee in their district
--    Assistant Commissioner    every employee in their tehsil (one AC per tehsil,
--      (AC)                    several tehsils per district)
--    Report Viewer             read-only reports
--    Employee                  their own profile, postings, orders, notifications
--
--  DC and AC are signing authorities: each keeps their own letterhead, signing
--  details, distribution list and signature, used on the orders they issue.
--
--  Access is geographic. An employee belongs to the district and tehsil of the
--  office they are currently posted to; the posting engine keeps that in step.
--
--  Run after the earlier migrations. Existing data is carried over; see the
--  notes marked "ACTION" for the one thing that needs a human decision.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------- 1. GEOGRAPHY ---
CREATE TABLE IF NOT EXISTS districts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  code        VARCHAR(20)  NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  is_deleted  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_district_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tehsils (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  district_id INT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  code        VARCHAR(20)  NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  is_deleted  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tehsil_code (district_id, code),
  KEY idx_tehsil_district (district_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO districts (id, name, code, sort_order)
SELECT 1, 'Nankana Sahib', 'NNS', 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM districts);

INSERT INTO tehsils (district_id, name, code, sort_order)
SELECT * FROM (
  SELECT 1 AS d, 'Nankana Sahib' AS n, 'NNS' AS c, 1 AS s UNION ALL
  SELECT 1,      'Shahkot',              'SKT',      2      UNION ALL
  SELECT 1,      'Sangla Hill',          'SGH',      3      UNION ALL
  SELECT 1,      'Safdarabad',           'SFD',      4
) t WHERE NOT EXISTS (SELECT 1 FROM tehsils);

-- ------------------------------------------ 2. OFFICES SIT IN THE MAP ---
-- An office belongs to a district, and to a tehsil unless it is a
-- district-level office (the DC office itself).
ALTER TABLE posting_locations
  ADD COLUMN district_id INT UNSIGNED DEFAULT NULL AFTER department_id,
  ADD COLUMN tehsil_id   INT UNSIGNED DEFAULT NULL AFTER district_id,
  ADD KEY idx_loc_district (district_id),
  ADD KEY idx_loc_tehsil (tehsil_id);

UPDATE posting_locations SET district_id = (SELECT MIN(id) FROM districts) WHERE district_id IS NULL;

UPDATE posting_locations l JOIN tehsils t ON t.code = 'NNS' AND t.district_id = l.district_id
   SET l.tehsil_id = t.id
 WHERE l.tehsil_id IS NULL AND l.name IN ('AC Office Nankana Sahib', 'Revenue Record Centre', 'Land Record Centre (Arazi)');
UPDATE posting_locations l JOIN tehsils t ON t.code = 'SKT' AND t.district_id = l.district_id
   SET l.tehsil_id = t.id WHERE l.tehsil_id IS NULL AND l.name = 'AC Office Shahkot';
UPDATE posting_locations l JOIN tehsils t ON t.code = 'SGH' AND t.district_id = l.district_id
   SET l.tehsil_id = t.id WHERE l.tehsil_id IS NULL AND l.name = 'AC Office Sangla Hill';
UPDATE posting_locations l JOIN tehsils t ON t.code = 'SFD' AND t.district_id = l.district_id
   SET l.tehsil_id = t.id WHERE l.tehsil_id IS NULL AND l.name = 'AC Office Safdarabad';
-- Any other office stays district-level until a tehsil is chosen for it
-- in Master Data -> Posting Locations.

-- ------------------------------------ 3. EMPLOYEES FOLLOW THEIR POSTING ---
ALTER TABLE employees
  ADD COLUMN district_id INT UNSIGNED DEFAULT NULL AFTER department_id,
  ADD COLUMN tehsil_id   INT UNSIGNED DEFAULT NULL AFTER district_id,
  ADD KEY idx_emp_district (district_id),
  ADD KEY idx_emp_tehsil (tehsil_id);

UPDATE employees e
  LEFT JOIN postings p          ON p.id = e.current_posting_id
  LEFT JOIN posting_locations l ON l.id = p.location_id
   SET e.district_id = COALESCE(l.district_id, (SELECT MIN(id) FROM districts)),
       e.tehsil_id   = l.tehsil_id
 WHERE e.district_id IS NULL;

-- ------------------------------------------------------------ 4. USERS ---
ALTER TABLE users
  ADD COLUMN district_id          INT UNSIGNED DEFAULT NULL AFTER employee_id,
  ADD COLUMN tehsil_id            INT UNSIGNED DEFAULT NULL AFTER district_id,
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER tehsil_id,
  ADD KEY idx_users_district (district_id),
  ADD KEY idx_users_tehsil (tehsil_id);

-- ------------------------------------------------------------ 5. ROLES ---
-- Role ids are kept so that existing accounts keep working.
INSERT INTO roles (id, name, label) VALUES
 (1, 'super_admin',   'Super Admin'),
 (2, 'ac',            'Assistant Commissioner (AC)'),
 (3, 'report_viewer', 'Report Viewer'),
 (4, 'dc',            'Deputy Commissioner (DC)'),
 (5, 'employee',      'Employee')
ON DUPLICATE KEY UPDATE name = VALUES(name), label = VALUES(label);

-- The former "District Administration" accounts are the DC.
UPDATE users u JOIN roles r ON r.id = u.role_id
   SET u.district_id = (SELECT MIN(id) FROM districts)
 WHERE r.name IN ('dc', 'ac') AND u.district_id IS NULL;

-- ACTION: former "Department Head" accounts are now ACs, but a department
-- does not say which tehsil they run. They can see no employees until a
-- tehsil is chosen for them in Administration -> Users & Roles, which flags
-- every AC without one.

-- ------------------------------------------------ 6. SIGNING AUTHORITIES ---
CREATE TABLE IF NOT EXISTS signing_profiles (
  user_id               INT UNSIGNED NOT NULL,
  letterhead_1          VARCHAR(120) DEFAULT NULL,
  letterhead_2          VARCHAR(120) DEFAULT NULL,
  letterhead_3          VARCHAR(120) DEFAULT NULL,
  show_emblem           TINYINT(1)   NOT NULL DEFAULT 1,
  order_prefix          VARCHAR(40)  DEFAULT NULL,
  authority_name        VARCHAR(120) DEFAULT NULL,
  authority_designation VARCHAR(120) DEFAULT NULL,
  authority_office      VARCHAR(160) DEFAULT NULL,
  copy_to               TEXT         DEFAULT NULL,
  footer_note           VARCHAR(300) DEFAULT NULL,
  signature_file        VARCHAR(120) DEFAULT NULL,
  signature_width       SMALLINT UNSIGNED NOT NULL DEFAULT 150,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------- 7. A LOGIN PER EMPLOYEE ---
-- Username is the CNIC without dashes. The password is random and must be
-- changed at first sign-in; the Super Admin can see or reset it in
-- Users & Roles. Employees already linked to an officer account keep that one.
INSERT INTO users (username, password, full_name, email, role_id, employee_id, must_change_password, is_active, created_at)
SELECT REPLACE(e.cnic, '-', ''),
       SUBSTRING(MD5(CONCAT(RAND(), e.id, e.cnic)), 1, 8),
       e.name, e.email, 5, e.id, 1, 1, NOW()
  FROM employees e
 WHERE e.is_deleted = 0
   AND NOT EXISTS (SELECT 1 FROM users u  WHERE u.employee_id = e.id AND u.is_deleted = 0)
   AND NOT EXISTS (SELECT 1 FROM users u2 WHERE u2.username = REPLACE(e.cnic, '-', ''));

SET FOREIGN_KEY_CHECKS = 1;
