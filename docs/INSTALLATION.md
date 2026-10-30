# Smart HR System — Deployment & Installation Guide

Core PHP 8.2 · MySQL 8 / MariaDB 10.4+ · Bootstrap 5 (CDN) · works on standard cPanel shared hosting.

## 1. Requirements

| Item | Requirement |
|---|---|
| PHP | 8.1 or newer (8.2 recommended) with `mysqli`, `mbstring`, `fileinfo`, `zip` (for .xlsx export; CSV works without it), `gd` optional |
| Database | MySQL 8.x or MariaDB 10.4+ |
| Web server | Apache with `mod_rewrite` (the included `.htaccess` protects `/includes`, `/database`, `/storage`, `/uploads`) — Nginx works with equivalent `location` deny rules |
| Browser | Any modern browser (CDN assets need internet access on the client). The **Posting Map** additionally loads Leaflet from cdnjs and map tiles from OpenStreetMap, so client machines need outbound internet for that page |

## 2. Upload files (cPanel)

1. Zip the project folder and upload it via **File Manager**, then extract into the target folder:
   * root domain → `public_html/`
   * sub-folder → `public_html/hr/` (any folder name works)
2. Make these folders writable (755 is normally enough; use 775 if uploads fail):
   `uploads/`, `uploads/photos/`, `uploads/documents/`, `storage/backups/`
3. Do **not** upload the `docker/` folder (local testing only).

## 3. Create the database

1. cPanel → **MySQL® Databases** → create a database (e.g. `cpuser_smart_hr`) and a user with **ALL PRIVILEGES** on it.
2. cPanel → **phpMyAdmin** → select the database → **Import** → choose `database/schema.sql` → Go.
   *Going live with the data of the working system instead?* Import only `database/smart_hr_current.sql` (full structure and all current data) and skip the demo data. It contains the sign-in passwords — delete it from the server once imported.
3. Optional demo data (sample DC Office Nankana Sahib employees covering every lifecycle scenario): import `database/demo_data.sql` **after** the schema.

## 4. Configure `config.php` (the only file you edit)

```php
define('BASE_URL', 'https://yourdomain.com/hr');   // NO trailing slash. Root domain: 'https://yourdomain.com'
define('DB_HOST', 'localhost');
define('DB_NAME', 'cpuser_smart_hr');
define('DB_USER', 'cpuser_hruser');
define('DB_PASS', 'your-db-password');
define('APP_ENV', 'production');                    // 'local' shows PHP errors
```

Every link, asset, redirect and AJAX call is built from `BASE_URL`, so moving the app to another folder or domain only requires changing this line.

## 5. First login

| Role | Username | Password |
|---|---|---|
| Super Admin | `superadmin` | `admin123` |
| Officer — Deputy Commissioner Office Nankana Sahib (district level) | `dc` | `dc123` |
| Officer — Assistant Commissioner Office Nankana Sahib (tehsil level) | `ac` | `ac123` |
| Report Viewer — DC Office Nankana Sahib (district level) | `reports` | `reports123` |

Change these passwords immediately (**Profile → Change Password**) and create real users under **Administration → Users & Roles**.

## 6. Daily automation (contract expiry, LPR flags, alerts)

The system already runs its daily checks automatically when any user logs in (at most once per day). For hands-off operation add a cPanel cron job (**Advanced → Cron Jobs**), once per day:

```
php /home/CPUSER/public_html/hr/cron/daily.php
```

or, if PHP CLI is unavailable, set a *Cron Secret Key* in **System Settings** and use:

```
curl -s "https://yourdomain.com/hr/cron/daily.php?key=YOUR_KEY" > /dev/null
```

## 7. Backups

* **Database Backup** menu → *Generate & Download Backup* produces a full `.sql` dump (pure PHP, no `mysqldump` needed). Copies are kept in `storage/backups/`.
* Back up the `uploads/` folder separately (photos and documents) with cPanel **Backup** or File Manager.
* **Reset Database** (Super Admin) takes a safety backup, rebuilds the schema and can reload the demo data.

## 8. Security notes

* Passwords are stored in plain text by current requirement. To switch to hashing later, change only `verify_password()` in `includes/auth.php` and hash on save in `admin/users.php` / `auth/change_password.php`.
* `/uploads` is blocked from direct URL access; files are served through `employees/photo.php` and `employees/download.php` after permission checks.
* All forms and AJAX calls are CSRF-protected; all SQL uses prepared statements.
* Use HTTPS (Let's Encrypt in cPanel) so the session cookie is sent with the `Secure` flag.

## 9. Local testing with Docker (developers)

```bash
docker compose -f docker/docker-compose.yml up -d --build
# app: http://localhost:8080   phpMyAdmin: http://localhost:8081 (root / root)
# demo data (fresh DB only):
docker exec docker-web-1 php /var/www/html/database/seed_demo.php --export
```

## 9A. Upgrading an existing installation

Versions 1.1.0 and 1.2.0 add the Posting Map, the Inquiries service record type, official order generation and the order signature. On a **new** installation `database/schema.sql` already contains everything — nothing extra to do. To upgrade a database that is already live:

1. Take a backup first (*Database Backup* menu, or phpMyAdmin → Export).
2. phpMyAdmin → select the database → **Import**, in this order:
   * `database/migrations/2026_09_28_map_inquiries_orders.sql` — posting map, inquiries, orders
   * `database/migrations/2026_09_28b_dept_coords_signature.sql` — department coordinates, office → department link, order signature
   * `database/migrations/2026_09_28c_order_documents.sql` — editable order documents (filing number, body, CC)
   * `database/migrations/2026_09_29_roles_hierarchy.sql` — the District Administration role
   * `database/migrations/2026_09_30_geography_authorisation.sql` — **2.0.0 authorisation**: districts and tehsils, DC / AC / Employee roles, per-officer signing profiles, and a sign-in for every employee

   * `database/migrations/2026_10_01_district_tehsil_department.sql` — **3.0.0 District → Tehsil → Department**: the Province of Punjab (41 districts, their tehsils and departments), department authority levels, the single *Officer* role, and foreign keys across the whole schema
   * `database/migrations/2026_10_02_report_viewer_department.sql` — **3.1.0**: report viewers get a department and read only what its level reaches (existing ones are placed in the DC Office Nankana Sahib)

   After the 3.0.0 migration: the old districts, tehsils and branch-departments are replaced by the Punjab list. Every office is placed under the department it belongs to (AC office of its tehsil, Arazi Record Centre, or the DC office for district headquarters); employees follow the department of their office; the old branch name is kept as the posting's *section*; sanctioned posts are merged under the DC office. A DC account becomes an officer of the DC office of its district, an AC account an officer of the AC office of its tehsil; an AC account that had no tehsil keeps no department and is flagged in *Users & Roles*. Old tehsils and districts are matched to the new ones by name.
3. Upload the new files as usual. No `config.php` change is needed.
4. Make `uploads/signatures/` writable (755, or 775 if the upload fails) — the signature image is stored there.

The migration adds `latitude` / `longitude` to `posting_locations`, creates the `inquiries` and `inquiry_types` tables, and inserts the order / map settings. It is written so that re-running it does not duplicate anything.

## 9A-1. Setting up a district after installing

1. *Master Data → Districts / Tehsils* — the 41 districts of Punjab and their tehsils are pre-loaded; check the ones you use.
2. *Master Data → Departments* — each district already has a district-level Deputy Commissioner Office, a tehsil-level Assistant Commissioner Office per tehsil and the usual district and tehsil departments. Add or correct departments here: choose the **level** first, then district → tehsil. Set the head of office (it goes on the letterhead) and the coordinates.
3. *Master Data → Offices & Sites* — the buildings where staff are posted, each under its department.
4. *Users & Roles* — create an **Officer** for each office that needs one and choose their department (district → tehsil → department). A district-level department gives the whole district, a tehsil-level one the tehsil, anything else only that department (read-only). They are asked to set their own password at first sign-in.
5. Each officer opens **My Office & Signature** and uploads their signature.
5. Employees already on the register get their sign-ins from the migration; new employees get one when they are registered. Hand out the temporary passwords from *Users & Roles → Employee sign-ins*.

## 9B. Official orders — letterhead setup

*Administration → System Settings → Official Orders* controls what is printed on every order:

| Setting | Purpose |
|---|---|
| Letterhead Line 1 / 2 / 3 | The three bold lines beside the emblem (used only when no officer's own profile applies), e.g. `THE OFFICE OF` / `ASSISTANT COMMISSIONER` / `DISTRICT NANKANA SAHIB` |
| Print Government Emblem | `1` prints the Government of the Punjab emblem (`assets/img/punjab-emblem.jpg`) above the letterhead |
| Order Heading | The centred, underlined heading — `ORDER` or `OFFICE ORDER` |
| Signing Authority — Name / Designation / Office | The signature block. Leaving the name blank prints `Sd/-` |
| Distribution List | One entry per line; printed as the numbered "A copy is forwarded…" endorsement. Leave blank to omit the endorsement entirely |
| Closing Sentence | Printed as the final paragraph of every order |
| Signature Image | Upload a scanned signature (PNG/JPG/GIF/WEBP, max 2 MB). Stored in `uploads/signatures/` and embedded into the page and the Word file, so `/uploads` stays closed to direct requests. Tick *Remove* to clear it |
| Signature Width | Printed width in pixels — 120 to 200 suits most signatures |

The settings above are the defaults for **every** order. An individual order can be adjusted on the order page itself (filing number, subject, body, CC list); those per-order edits are kept in the `order_documents` table and only hold the fields that were actually changed.

The layout is taken from the office Word template and is fixed: A4, margins 1.43 / 1.79 / 2.54 cm, the emblem 3.07 × 2.73 cm floating 1.31 cm into the left margin, and the three headings in a 6.96 cm block 10.5 cm from the left margin, bold 14 pt. Only the wording is configurable. To use a different emblem, replace `assets/img/punjab-emblem.jpg` (keep the file name). **Download Word** produces a `.doc` with the emblem and the signature embedded, so it opens correctly offline.

Goudy Old Style ships with MS Office on Windows; machines without it fall back to Sorts Mill Goudy from Google Fonts, which is the same typeface design.

## 9C. Posting map — coordinates

Coordinates are only ever held by an existing master record — a **Department** or a **Posting Location**. Set them in *Master Data*: open Google Maps, right-click the office, click the first line of the context menu (it copies `31.4492, 73.7089`), then paste the first number into **Latitude** and the second into **Longitude**.

Each posting location also names the **Department / Branch** it belongs to, which gives every employee a fallback chain:

1. the coordinates of the office they are posted to;
2. failing that, the coordinates of the department that office belongs to;
3. failing that, the coordinates of the employee's own department.

The map page reports how many offices are being shown at their department's location, and lists any employee who could not be placed at all.

The default map centre and zoom (used only when no location has coordinates yet) are in *System Settings → Posting Map*.

## 10. Troubleshooting

| Symptom | Fix |
|---|---|
| Blank page / 500 | Set `APP_ENV` to `local` temporarily to see the error; check PHP version ≥ 8.1 |
| Order letterhead cut off when printing | In the browser print dialog set **Margins: Default**. The page declares its own margins (1.43 / 0.85 / 2.54 / 1.1 cm) so the emblem and headings, which overhang the text column as in the Word template, stay inside the printable area |
| "Database connection failed" | Verify credentials in `config.php`; on cPanel the DB name/user are prefixed with your account name |
| Links go to the wrong folder | `BASE_URL` must match the exact public URL of the folder containing `index.php` |
| Photo/document upload fails | Make `uploads/` writable; check `upload_max_filesize` ≥ 10M in cPanel *MultiPHP INI Editor* |
| Excel download is CSV | PHP `zip` extension is missing — enable it in *Select PHP Version → Extensions* |
| 403 on `/uploads/...` | Expected: files are served via PHP, not by direct URL |
| Posting Map is blank / grey | The client machine cannot reach cdnjs or OpenStreetMap — check the office internet/proxy. If tiles load but no pins appear, no posting location has coordinates yet |
| Map shows "N employees not on the map" | Neither their posting office nor their department has coordinates. The table under the map names the reason for each |
| Signature upload fails | Make `uploads/signatures/` writable (775). The image must be PNG/JPG/GIF/WEBP and 2 MB or smaller |
| An officer sees no employees | Their account has no department (flagged in yellow in *Users & Roles*), or nobody is registered in the departments they reach |
| An officer cannot add service records | Expected for a department-level department: service records are published at district or tehsil level only |
| A department is not offered when posting | The lists only contain what the officer reaches — a tehsil-level officer posts within their tehsil; a transfer to another tehsil is recorded at district level |
| Employee cannot sign in | Username is the CNIC without dashes. Issue a new temporary password from *Users & Roles → Employee sign-ins* (key icon) |
| Order shows `Sd/-` instead of a name | Set *System Settings → Official Orders → Signing Authority — Name* |
| Word order opens without the emblem | Older MS Word versions ignore embedded images; use **Print / Save as PDF** instead |

## 11. Employee messaging (Email / SMS / WhatsApp)

Every service-record form has a **Notify Employee** section — the officer ticks Email, SMS and/or WhatsApp and the message goes out right after the record is saved. Delivery is logged under **Notification Log** (and on the employee profile). Configure the gateways once in **Administration → System Settings** and use the **Test Messaging** panel at the bottom of that page.

| Channel | Settings | Notes |
|---|---|---|
| Email | *From Address* (+ optional SMTP host, port, encryption, username, password) | Blank SMTP host = PHP `mail()` (works on cPanel). SMTP supports TLS (587) and SSL (465). |
| SMS | *API URL* with placeholders, method, headers, body | Works with any HTTP gateway (Telenor, Jazz, Zong, BranchSMS, Twilio…). Placeholders: `{to}` (923…), `{message}`, `{message_url}` (URL-encoded), `{message_json}` (JSON-escaped), `{sender}`. Success = HTTP 2xx. |
| WhatsApp | *Provider = meta*: Phone Number ID + permanent access token (+ optional approved template). *Provider = generic*: same placeholder style as SMS. | Meta Cloud API plain-text messages only reach users within a 24-hour session; set an approved **template** (one `{{1}}` body variable) for unrestricted delivery. |

Examples:

```
# SMS via GET
API URL   : https://sms.example.com/api?key=YOUR_KEY&sender={sender}&to={to}&message={message_url}
Method    : GET

# SMS via JSON POST
API URL   : https://api.example.com/v1/messages
Method    : POST
Headers   : Authorization: Bearer YOUR_KEY
            Content-Type: application/json
Body      : {"to":"{to}","from":"{sender}","text":"{message_json}"}
```

Local numbers (`0300-1234567`) are converted to international format (`923001234567`) using the *Country Code* setting. The employee's **Mobile No.** is used for SMS and **WhatsApp No.** (or mobile if blank) for WhatsApp.

### Twilio (SMS) quick setup

On **System Settings → Test Messaging** click **Twilio preset**, enter the Account SID, Auth Token and your Twilio number (or Messaging Service SID) — the SMS fields are filled automatically:

```
API URL : https://api.twilio.com/2010-04-01/Accounts/ACxxxxxxxx/Messages.json
Method  : POST
Headers : Authorization: Basic base64(ACCOUNT_SID:AUTH_TOKEN)
          Content-Type: application/x-www-form-urlencoded
Body    : To=%2B{to}&From=%2B1XXXXXXXXXX&Body={message_url}
```

Then **Save Settings** and **Send Test**. Twilio checklist:
* **Geo permissions** — Console → *Messaging → Settings → Geo permissions* → enable **Pakistan (+92)** (otherwise error 21408).
* **Trial accounts** can only message *verified* numbers (Console → *Phone Numbers → Verified Caller IDs*) and prefix a trial notice; upgrade the account for production.
* Rotate the Auth Token if it has ever been shared outside the team (Console → *Account → API keys & tokens*).
