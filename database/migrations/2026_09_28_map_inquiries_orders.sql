-- ============================================================================
--  Smart HR System — upgrade 1.0.0 -> 1.1.0
--  1. Posting locations carry coordinates so employees can be shown on a map
--  2. Inquiries become a full service record type (register + service book)
--  3. Official orders are generated from service records
--
--  Safe to run on an existing database: every statement is guarded.
--  phpMyAdmin: select the database -> Import -> this file.
-- ============================================================================

-- ---------------------------------------------------------------- 1. MAP ---
ALTER TABLE posting_locations
  ADD COLUMN latitude  DECIMAL(10,7) DEFAULT NULL COMMENT 'Map coordinate (e.g. 31.4504)' AFTER address,
  ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL COMMENT 'Map coordinate (e.g. 73.7069)' AFTER latitude;

-- Coordinates for the seeded Nankana Sahib locations (district HQ area).
UPDATE posting_locations SET latitude = 31.4492000, longitude = 73.7089000 WHERE name = 'DC Office Main Building'    AND latitude IS NULL;
UPDATE posting_locations SET latitude = 31.4475000, longitude = 73.7051000 WHERE name = 'AC Office Nankana Sahib'    AND latitude IS NULL;
UPDATE posting_locations SET latitude = 31.7167000, longitude = 73.9833000 WHERE name = 'AC Office Shahkot'          AND latitude IS NULL;
UPDATE posting_locations SET latitude = 31.5606000, longitude = 73.8347000 WHERE name = 'AC Office Sangla Hill'      AND latitude IS NULL;
UPDATE posting_locations SET latitude = 31.3833000, longitude = 73.9667000 WHERE name = 'AC Office Safdarabad'       AND latitude IS NULL;
UPDATE posting_locations SET latitude = 31.4530000, longitude = 73.7140000 WHERE name = 'Revenue Record Centre'      AND latitude IS NULL;
UPDATE posting_locations SET latitude = 31.4460000, longitude = 73.7160000 WHERE name = 'Land Record Centre (Arazi)' AND latitude IS NULL;

-- ---------------------------------------------------------- 2. INQUIRIES ---
CREATE TABLE IF NOT EXISTS inquiry_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  is_formal   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = formal inquiry under PEEDA / E&D rules',
  description VARCHAR(255) DEFAULT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  is_deleted  TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO inquiry_types (name, is_formal, description, sort_order)
SELECT * FROM (
  SELECT 'Preliminary / Fact-Finding Inquiry' AS a, 0 AS b, 'Initial fact finding before a formal inquiry is ordered' AS c, 1 AS d UNION ALL
  SELECT 'Formal Inquiry (PEEDA Act 2006)',     1, 'Regular inquiry under the Punjab Employees Efficiency, Discipline and Accountability Act', 2 UNION ALL
  SELECT 'Departmental Inquiry',                1, 'Inquiry ordered by the competent authority of the department', 3 UNION ALL
  SELECT 'Audit Para / Recovery Inquiry',       0, 'Inquiry arising out of an audit observation or recovery notice', 4 UNION ALL
  SELECT 'Anti-Corruption / External Inquiry',  0, 'Inquiry conducted by an outside agency (ACE, NAB, FIA)', 5
) t WHERE NOT EXISTS (SELECT 1 FROM inquiry_types);

CREATE TABLE IF NOT EXISTS inquiries (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ref_no             VARCHAR(30)  DEFAULT NULL,        -- human-readable reference e.g. INQ-2026-0001
  employee_id        INT UNSIGNED NOT NULL,
  inquiry_type_id    INT UNSIGNED NOT NULL,
  subject            VARCHAR(200) NOT NULL,
  allegation         VARCHAR(1000) DEFAULT NULL,
  order_no           VARCHAR(80)  DEFAULT NULL,        -- order by which the inquiry was ordered
  order_date         DATE DEFAULT NULL,
  initiated_date     DATE NOT NULL,
  inquiry_officer    VARCHAR(150) DEFAULT NULL,
  ordering_authority VARCHAR(150) DEFAULT NULL,
  status             ENUM('Open','Under Inquiry','Concluded') NOT NULL DEFAULT 'Open',
  -- conclusion
  outcome            VARCHAR(60)  DEFAULT NULL,        -- Exonerated / Minor Penalty Imposed / ...
  concluded_date     DATE DEFAULT NULL,
  closing_order_no   VARCHAR(80)  DEFAULT NULL,
  findings           VARCHAR(1000) DEFAULT NULL,
  punishment_id      INT UNSIGNED DEFAULT NULL,        -- punishment recorded as a result, if any
  concluded_by       INT UNSIGNED DEFAULT NULL,
  remarks            VARCHAR(500) DEFAULT NULL,
  is_voided          TINYINT(1) NOT NULL DEFAULT 0,
  void_reason        VARCHAR(255) DEFAULT NULL,
  voided_by          INT UNSIGNED DEFAULT NULL,
  voided_at          DATETIME DEFAULT NULL,
  created_by         INT UNSIGNED DEFAULT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inquiries_ref (ref_no),
  KEY idx_inq_emp (employee_id, status),
  KEY idx_inq_status (status, is_voided),
  KEY idx_inq_date (initiated_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------- 3. ORDERS ---
-- Letterhead taken from the office template: Punjab emblem + three bold lines.
DELETE FROM settings WHERE setting_key = 'order_office_line';
INSERT INTO settings (setting_key, setting_value, description) VALUES
 ('order_letterhead_1','THE OFFICE OF','Letterhead line 1 printed under the emblem on every order'),
 ('order_letterhead_2','ASSISTANT COMMISSIONER','Letterhead line 2 - the office issuing the order'),
 ('order_letterhead_3','DISTRICT NANKANA SAHIB','Letterhead line 3 - the district / station'),
 ('order_show_emblem','1','1 = print the Government of the Punjab emblem above the letterhead'),
 ('order_prefix','ORDER','Heading printed above the order body (e.g. ORDER or OFFICE ORDER)'),
 ('order_authority_name','','Name of the signing authority (blank = designation only)'),
 ('order_authority_designation','Assistant Commissioner','Designation printed under the signature'),
 ('order_authority_office','District Nankana Sahib','Office/station printed under the designation'),
 ('order_copy_to','The Deputy Commissioner, Nankana Sahib
The District Accounts Officer, Nankana Sahib
The Superintendent (Establishment)
Official concerned
Office record','Default distribution list on orders - one entry per line'),
 ('order_footer_note','This order is issued with the approval of the competent authority.','Closing sentence printed above the signature')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO settings (setting_key, setting_value, description) VALUES
 ('map_default_lat','31.4492','Map centre latitude when no locations have coordinates'),
 ('map_default_lng','73.7089','Map centre longitude when no locations have coordinates'),
 ('map_default_zoom','10','Initial zoom level of the posting map')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
