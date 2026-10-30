-- ============================================================================
--  Smart HR System — upgrade 1.1.0 -> 1.2.0
--  1. Coordinates on departments as well as offices, and offices linked to
--     the department they belong to (coordinates are only ever held by an
--     existing department or an existing posting location).
--  2. Signature image on official orders.
--
--  Safe to run after 2026_09_28_map_inquiries_orders.sql.
-- ============================================================================

-- ------------------------------------------- 1. DEPARTMENT COORDINATES ---
ALTER TABLE departments
  ADD COLUMN latitude  DECIMAL(10,7) DEFAULT NULL COMMENT 'Map coordinate of the branch office' AFTER code,
  ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL COMMENT 'Map coordinate of the branch office' AFTER latitude;

-- Every branch of the DC Office sits in the district complex unless set otherwise.
UPDATE departments SET latitude = 31.4492000, longitude = 73.7089000 WHERE latitude IS NULL;

-- --------------------------------- 2. OFFICE -> DEPARTMENT RELATIONSHIP ---
ALTER TABLE posting_locations
  ADD COLUMN department_id INT UNSIGNED DEFAULT NULL COMMENT 'Department this office belongs to' AFTER name,
  ADD KEY idx_loc_dept (department_id);

-- Seeded offices: the four tehsil offices and the record centres.
UPDATE posting_locations l
   SET l.department_id = (SELECT d.id FROM departments d WHERE d.code = 'GEN' LIMIT 1)
 WHERE l.department_id IS NULL AND l.name LIKE 'DC Office%';
UPDATE posting_locations l
   SET l.department_id = (SELECT d.id FROM departments d WHERE d.code = 'REV' LIMIT 1)
 WHERE l.department_id IS NULL AND (l.name LIKE '%Revenue%' OR l.name LIKE '%Arazi%');

-- ----------------------------------------------- 3. ORDER SIGNATURE ------
INSERT INTO settings (setting_key, setting_value, description) VALUES
 ('order_signature_file','','File name of the uploaded signature image in uploads/signatures/ (blank = print "Sd/-")'),
 ('order_signature_width','150','Printed width of the signature image in pixels')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
