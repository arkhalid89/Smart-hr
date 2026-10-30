# Smart HR System

Employee Lifecycle & Service Record Management for government offices (pilot: DC Office Nankana Sahib).
Core PHP 8.2 · MySQL (MySQLi prepared statements) · Bootstrap 5 / jQuery / Select2 / DataTables via CDN · cPanel shared-hosting ready.

* **Install / deploy:** [docs/INSTALLATION.md](docs/INSTALLATION.md)
* **User manual:** [docs/USER_MANUAL.md](docs/USER_MANUAL.md)
* **Database:** `database/schema.sql` (structure + masters + default users), `database/demo_data.sql` (sample lifecycle data), `database/migrations/` (upgrades for databases that are already live)

## Folder map

| Path | Purpose |
|---|---|
| `config.php` | The only file to edit: `BASE_URL`, database, environment |
| `includes/` | Core: `db.php`, `functions.php`, `auth.php` (RBAC), `audit.php`, `audit_ui.php`, `employee.php`, `service_engine.php` (all lifecycle rules), `service_ui.php`, `order_ui.php` (official orders), `masters_config.php`, `export.php` (xlsx/csv), `backup.php`, `header.php` / `footer.php` (layout + CDN assets) |
| `auth/` | Login, logout, profile, change password |
| `dashboard/` | KPI dashboard, alerts, notifications |
| `employees/` | Listing, add/edit, 360° profile, family, documents, **posting map**, AJAX endpoints |
| `service/` | Postings, promotions, increments, leaves, awards, punishments, **inquiries**, reinstatements, deputations, exits, rejoining |
| `masters/` | Generic AJAX CRUD for all master data |
| `reports/` | 10 tabular reports + printable profile, service book and **official orders** (`order.php`) |
| `admin/` | Users & roles, settings, sanctioned posts, audit logs, recycle bin, backup, reset |
| `cron/daily.php` | Daily automation (also runs at login) |
| `uploads/`, `storage/` | Protected files (photos, documents, backups) |
| `docker/` | Local test environment only |

## Roles

Everything hangs off **District → Tehsil → Department** (the Province of Punjab is pre-loaded). Every department has an authority level — *district* (e.g. Deputy Commissioner Office: the whole district), *tehsil* (e.g. Assistant Commissioner Office: the tehsil) or *department* (only itself). Roles: **Super Admin** (everything) · **Officer** (reaches what their department's level reaches; district- and tehsil-level officers publish service records; every officer signs orders with their own letterhead and signature) · **Report Viewer** (read-only employees, profiles, departments and reports, limited to their department's reach like an officer) · **Employee** (their own record only). The database enforces the chain with foreign keys and a check constraint. See `includes/auth.php`, `includes/geo.php` and `includes/signing.php`.

Default logins: `superadmin / admin123`, `dc / dc123` (officer, DC Office Nankana Sahib), `ac / ac123` (officer, AC Office Nankana Sahib), `reports / reports123` (report viewer, DC Office Nankana Sahib). With the demo data, every employee signs in with their CNIC digits and `employee123`, and is asked to change it.
