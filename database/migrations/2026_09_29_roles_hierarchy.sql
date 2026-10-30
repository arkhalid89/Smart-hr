-- ============================================================================
--  Smart HR System — upgrade 1.3.0 -> 1.4.0
--  Roles follow the government hierarchy:
--
--    Super Admin            system owner (settings, users, reset, audit)
--    District Administration every office in the district  <-- new
--    Department Head        only the departments assigned to them
--    Report Viewer          read-only reporting
--
--  Also clears the auto-appended closing sentence so an order ends with the
--  officer's own text; the setting stays available for anyone who wants it.
-- ============================================================================

INSERT INTO roles (id, name, label) VALUES (4, 'district_admin', 'District Administration')
ON DUPLICATE KEY UPDATE label = VALUES(label);

UPDATE roles SET label = 'Department Head' WHERE name = 'admin';
UPDATE roles SET label = 'Super Admin'     WHERE name = 'super_admin';
UPDATE roles SET label = 'Report Viewer'   WHERE name = 'report_viewer';

-- District Administration covers the whole district, so no per-department rows.
DELETE ud FROM user_departments ud
  JOIN users u ON u.id = ud.user_id
  JOIN roles r ON r.id = u.role_id
 WHERE r.name = 'district_admin';

UPDATE settings SET setting_value = '' WHERE setting_key = 'order_footer_note';
