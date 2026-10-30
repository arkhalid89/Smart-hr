-- ============================================================================
--  Smart HR System — upgrade 1.2.0 -> 1.3.0
--  Editable order documents: after a service record is saved the officer is
--  taken to the order, where the filing number, the subject, the body of the
--  letter and the distribution (CC) list can be adjusted before printing.
--
--  Only the edits are stored. Anything left untouched keeps being generated
--  from the service record, so an order never drifts from the record.
-- ============================================================================

CREATE TABLE IF NOT EXISTS order_documents (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_table VARCHAR(40)  NOT NULL,          -- postings, promotions, punishments, ...
  record_id    INT UNSIGNED NOT NULL,
  filing_no    VARCHAR(120) DEFAULT NULL,      -- office file / diary number printed as "No. ..."
  subject      VARCHAR(300) DEFAULT NULL,
  body         TEXT         DEFAULT NULL,      -- one paragraph per line
  copy_to      TEXT         DEFAULT NULL,      -- one entry per line
  updated_by   INT UNSIGNED DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_doc (record_table, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
