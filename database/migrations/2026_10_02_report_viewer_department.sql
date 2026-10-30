-- ============================================================================
--  Smart HR System — upgrade 3.0.0 -> 3.1.0
--  Report viewers carry an authority level
--
--  A report viewer now belongs to a department exactly like an officer and
--  reads only what that department's level reaches: a district-level office
--  the whole district, a tehsil-level office the tehsil, any other department
--  only itself. Until now report viewers read the whole province.
--
--  Existing report viewers without a department are placed in the Deputy
--  Commissioner Office Nankana Sahib (district level) — the reach they had in
--  practice. Change it per account in Administration -> Users & Roles.
--  Safe to run more than once.
-- ============================================================================
UPDATE users u
  JOIN roles r ON r.id = u.role_id AND r.name = 'report_viewer'
   SET u.department_id = (SELECT d.id FROM departments d WHERE d.code = 'NNS-DC')
 WHERE u.department_id IS NULL;
