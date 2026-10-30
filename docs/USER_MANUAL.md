# Smart HR System — User Manual

Employee Lifecycle & Service Record Management · DC Office Nankana Sahib · Punjab Civil Servants Rules / S&GAD compliant

---

## 1. District → Tehsil → Department, and who sees what

Everything is organised in three levels:

| Level | What it is | Example |
|---|---|---|
| **1. District** | 41 districts of Punjab, grouped by division | Nankana Sahib |
| **2. Tehsil** | belongs to one district | Shahkot |
| **3. Department** | sits in one district and, unless it is at district headquarters, in one tehsil **of that district** | Assistant Commissioner Office Shahkot |

Every department carries an **authority level**, chosen when it is created:

| Level | Reach | Typical office |
|---|---|---|
| **District level** | every department of the district and of all its tehsils | Deputy Commissioner Office |
| **Tehsil level** | every department of that tehsil | Assistant Commissioner Office |
| **Department level** | only itself — a single authority | Tehsildar Office, District Health Authority, Arazi Record Centre … |

A district has one district-level office; a tehsil-level office must have a tehsil; a district-level office has none. The database itself enforces this — a department can never point at a tehsil of another district.

| Role | Sees | Can do |
|---|---|---|
| **Super Admin** | The whole province | Everything, including Users & Roles, System Settings, Audit Logs, Recycle Bin, Database Backup and Reset Data |
| **Officer — district-level department** (e.g. Deputy Commissioner) | Every employee of their district | Register employees, **publish** service records and orders, reports, the posting map, master data of their district, and their own office & signature |
| **Officer — tehsil-level department** (e.g. Assistant Commissioner) | Every employee of their tehsil | The same within their tehsil — except master data. A posting outside the tehsil is the district's order |
| **Officer — department level** | The employees of their own department, **read-only** | Employee profiles, the posting map, reports, the notification log and their own office & signature. Service records cannot be published at department level |
| **Report Viewer** | What their department's level reaches — district, tehsil or the department itself | Read-only: the employee list and profiles (with Excel / CSV export, profile print and service book), the Departments directory and every report. Cannot add or change anything |
| **Employee** | **Only their own record** | Their profile, posting history, posting orders and the notifications sent to them |

Officers and report viewers are both given a department in *Users & Roles*; a report viewer without one sees nothing and is flagged there. **Every officer is a signing authority** (see 5A). **Service records are published only at district or tehsil level.**

**Where an employee belongs** is decided by their department: its tehsil and district follow from it. When a transfer is recorded to a department in another tehsil, the employee moves there automatically — the new tehsil's officers start seeing them and the old ones stop.

### Choosing a department

Wherever a department is chosen — registering an employee, a posting or transfer, a reversion, a rejoining, an officer's account, report and map filters — it is chosen in the same order: **District**, then a **Tehsil of that district** (or *— District headquarters —* for offices with no tehsil), then a **Department** of that tehsil. The lists only contain what you can reach. The *Office / site* list that follows shows only the offices of the chosen department.

### Employee sign-in

Registering an employee creates their sign-in at the same time. The username is their **CNIC without dashes** (they can also type it with dashes), and a temporary password is shown once to the officer who registered them. At first sign-in the employee must choose their own password. The Super Admin can read an unused temporary password or issue a new one under *Administration → Users & Roles → Employee sign-ins*.

An employee's menu has four items: **My Profile, My Postings, My Orders, Notifications.** They see their personal and employment details, family and nominees, posting history, their **posting orders** (printable), their own documents, and every Email / SMS / WhatsApp message sent to them. They do not see disciplinary records, inquiries, leave or other service records, reports, or anyone else.

### Departments directory

*People → Departments* lists, read-only, the departments you reach: level, tehsil and district, head of office, serving staff, sanctioned and vacant posts and the number of offices, with Excel / CSV export. Filter by district → tehsil → department and by level; the staff button opens that department's employees. Officers, report viewers and the Super Admin all have it (the Super Admin starts with staffed departments only — untick *With staff only* to see all). Departments are changed only in *Master Data*.

## 2. Dashboard

KPI cards show total / serving / status-wise counts (click a card to open the filtered employee list). Below them: department strength vs sanctioned posts, workforce composition, and the five alert panels — **Contract Expiry**, **Upcoming Retirements**, **LPR Eligible**, **Probation Completion**, **Deputation Reversion Due** — plus the recent activity feed. Alert windows are configurable in *System Settings*. The bell icon opens recent alerts; **View all notifications** lists every alert with unread / type filters and mark-as-read.

## 3. Master Data

*Master Data* menu (Super Admin, and officers of a district-level department for their own district) → pick a type on the left: **Districts, Tehsils, Departments**, Designations, Pay Scales, Leave Types, Award Types, Punishment Types, **Inquiry Types**, **Offices & Sites**, Employment Types, Blood Groups, Marital Status, Deputation Organizations, Reinstatement Types, Sanctioned Posts.

* **Districts, Tehsils** (Super Admin) — the Province of Punjab is pre-loaded: 41 districts and their tehsils. A tehsil cannot be moved to another district while departments are in it.
* **Departments** — pick the **Level** first. *District level*: choose the district. *Tehsil level*: choose the district, then a tehsil of that district. *Department level*: choose the district and, optionally, a tehsil. A department's code, head of office (used on the letterhead) and coordinates are set here. Use the district filter above the list to move between districts.
* **Offices & Sites** — buildings and sites where staff are posted; each belongs to a department (chosen district → tehsil → department) and can carry its own coordinates.
* **Add** opens a form; **pencil** edits; **pause/play** deactivates/activates (inactive items disappear from dropdowns but stay on old records).
* Super Admin: **trash** moves to the Recycle Bin, where it can be restored or permanently deleted (blocked if still referenced).
* Special flags drive automation: *Leave Types* → EOL / LPR; *Punishment Types* → Suspension / Removal / Dismissal; *Reinstatement Types* → With back benefits; *Employment Types* → Contract / Probation.
* **Sanctioned Posts** (Super Admin): department × designation × count; the roster report shows filled and vacant posts.
* **Coordinates live on master records only** — you cannot drop a pin anywhere on the map. Both **Departments** and **Offices & Sites** carry a **Latitude** and **Longitude**. To find them, open Google Maps, right-click the office and click the first line of the menu (it copies `31.4492, 73.7089`); paste the first number into Latitude and the second into Longitude.
* Each **Posting Location** also names the **Department / Branch** it belongs to. If an office has no coordinates of its own, the map uses the coordinates of that department, so nobody disappears from the map while the offices are still being surveyed.
* Every list has **Excel / CSV** export.

## 4. Employees

### 4.1 Add an employee
*Employees → New Employee.*
* CNIC is checked live for uniqueness (format `35202-1234567-1`).
* Enter DOB (age fills automatically) or type the age to pre-fill DOB.
* Department → the **employee code** preview updates (pattern set by Super Admin, e.g. `DCO-GEN-2026-0001`).
* Designation pre-fills the pay scale; scale pre-fills the minimum salary.
* *Contract* employment types require contract dates; regular types get probation dates (start = joining, end = +12 months by default).
* Fill the **Initial Posting** section — this becomes the first row of the immutable posting history.
* Retirement date is auto-calculated (DOB + retirement age, adjusted later for EOL breaks).
* Status starts as **Probation** (or **Active** if a confirmation date is given / no probation).

### 4.2 Employee profile (360° view)

Tabs: **Overview · Postings · Service Records · Leaves · Inquiries · Family · Documents & Orders · Notifications · Timeline** (plus **Audit** for the Super Admin).

* **Inquiries** lists every disciplinary proceeding, with a warning banner and an *Inquiry Pending* badge on the hero while any is open.
* **Documents & Orders** holds uploaded documents *and* every official order generated for this employee — print or download each as Word straight from the list.
* **Notifications** shows the full Email / SMS / WhatsApp delivery history (recipient, message, result) alongside the system alerts raised for this employee.
* **View on Map** beside the current posting opens the Posting Map centred on this employee.
Tabs: **Overview**, **Postings**, **Service Records**, **Leaves**, **Family / Nominee**, **Documents**, **Timeline**, **Audit**.
* *Edit Profile* changes personal/employment data (all changes are logged with old → new values). Setting a **Confirmation Date** moves a probationer to **Active**.
* *Add Service Record* dropdown opens the relevant module with the employee pre-selected.
* *Family / Nominee*: add spouse/children etc., mark nominees with share % (total ≤ 100 %) and emergency contacts.
* *Documents*: upload CNIC, certificates, orders (PDF/image/Word, 10 MB). Files are private and served only to authorised users.
* *Timeline*: the complete visual service journey grouped by year — filter by category (career, postings, pay, leaves, discipline, status), search, see how many days each state lasted, open attachments (paper-clip) and jump to the underlying record.
* *Print* → comprehensive profile report; *Service Book* → chronological service history.

### 4.3 Activate / inactivate / delete
* Admins and Super Admin can mark a record **inactive** (soft state; it stays searchable with a filter).
* Only Super Admin can move an employee to the **Recycle Bin** (restorable) and permanently delete from there (removes all history and files).

## 5. Service Records (append-only)

Every module: choose the employee (search box), fill the form, optionally attach a document, tick **Notify Employee** channels (Email / SMS / WhatsApp — the recipient is shown on each chip; the message can be customised or left to the template), **Save**. Saving takes you straight to the **official order** for that record, with the edit panel open so you can set the filing number and adjust the letter before printing (see 5A). Delivery results appear in the confirmation message, on the profile (*Messages Sent to Employee*) and under **Notification Log**. Records are never edited or deleted; the Super Admin can **Void** a wrongly-entered record (reason required) — it stays visible as *Voided*, its timeline entry is removed and the employee status is re-derived.

| Module | What it does | Automation / rules |
|---|---|---|
| **Postings / Transfers** | Routine, Transfer, Temporary, Additional Charge, Punishment, Promotion posting | Closes the previous posting; only one *current*; department change updates the profile. Transfers blocked while suspended or on deputation |
| **Promotions** | Regular Promotion (post + scale) or Special Upgradation (scale only, Notional/Financial) | Unconfirmed employees: upgradation only. Suspended: blocked. Lower scale rejected. Optionally records a Promotion Posting |
| **Increments** | Annual increment (1 December) | Blocked while suspended or when a *Withholding of Increment* punishment is in force; cannot be lower than current salary |
| **Leaves** | All leave types with letter no/date, auto duration, status | **EOL > 6 months** → *Break in Service*, retirement date shifted. **LPR** approved → status *On LPR*. Overlaps and max-days enforced |
| **Awards** | Award type, order date/no, description | — |
| **Punishments** | Censure, Fine, Withholding, Reduction, Suspension, Removal, Dismissal | *Suspension* → status **Suspended** (start/end/pay status). *Removal* → automatic **Termination** exit. *Dismissal* → automatic **Dismissal** exit |
| **Inquiries** | Disciplinary proceedings: type, subject, allegation, inquiry officer, ordering authority. The same page **concludes** an open inquiry with an outcome and findings | An inquiry stays *Open* / *Under Inquiry* until concluded. Anyone with a pending inquiry is flagged on the profile, in the *Inquiries* register, in the *Inquiry Report*, and in the service book and profile report. Concluding does not impose a penalty — record that under *Punishments* so the status automation applies |
| **Reinstatements** | End a suspension | Only for suspended employees; status back to Active/Probation. *With back benefits* → missed 1-Dec increments are auto-created and salary restored |
| **Deputations** | Deputation to an external organization with expected reversion | Status **On Deputation**, posting of type *Deputation*. The same page records the **Reversion** when the employee is on deputation → status Active, posting *Reversion* to parent department |
| **Exit / Separation** | Resignation, Normal Retirement, Medical Invalidation (board ref/date/recommendation), Termination, Dismissal, Death in Service (date/cause), Contract Expired | Status updated, current posting closed. **Death in Service** closes the service book and flags nominees/spouse as *Pension Claimant* |
| **Rejoining** | Re-entry of a former employee — same employee ID, history preserved | Contract Expired / Resigned: allowed · Retired: contract only · Terminated: Super Admin override · Dismissed: never. *Fresh* restarts probation; new contract dates update the profile |

**Contract expiry** is automatic: when `today > contract_to` the daily check sets status **Contract Expired** and records an exit.

## 5A. Official orders

**Who signs.** An order carries the letterhead, name, designation, signature and CC list of the officer who **issued** it — the officer who recorded the service record — no matter who opens it later. If the entry was made by the Super Admin, the order is signed by an officer of the tehsil-level office of the employee's tehsil, or of the district-level office for staff at district headquarters; failing that, by the office-wide defaults in System Settings. The order page shows which authority is signing and why.

**My Office & Signature** (every officer): each officer keeps their own letterhead lines, order heading, the name / designation / office printed under their signature, their default CC list, an optional closing sentence and a scanned signature, with a live preview. It starts pre-filled from their department — its head of office and its tehsil or district (e.g. *THE OFFICE OF / ASSISTANT COMMISSIONER / TEHSIL SHAHKOT*). The Super Admin can open any officer's profile from *Administration → Signing Authorities* to help set it up.

Every posting / transfer, promotion, increment, leave sanction, award, punishment or suspension, reinstatement, deputation, exit, rejoining and inquiry produces an **official order** on the office letterhead (Government of the Punjab emblem + the three office lines).

* **Saving a service record opens its order straight away**, with the edit panel expanded. You can also open any order later from the **file icon** in a service register, or from the employee profile (Postings, Service Records, Leaves, Inquiries, Timeline, and the *Documents & Orders* tab).
* The order page is a workbench: the **editor on the left**, a live **preview of the printed page on the right**. The preview scales down to fit narrower screens and always shows exactly what will print.
* **Edit this order** (officers only) lets you change, per order:
  * **Filing / Diary Number** — printed as &ldquo;No. …&rdquo; on both the order and the endorsement. Type the number only; the word &ldquo;No.&rdquo; is added for you.
  * **Subject** line.
  * **Body of the letter** — one paragraph per line. Two or more lines print as a numbered list, a single line as a plain paragraph.
  * **Copy forwarded to (CC)** — one entry per line. Clear the box entirely to print the order with no endorsement.
* Only what you actually change is stored. Anything you leave alone keeps being generated from the service record, so corrections to the record still flow through. **Reset to default** discards your wording and goes back to the generated text.
* An order that has been edited is marked **edited** on the panel, and the change is written to the audit log.
* Except for your edits, the order is **generated from the record every time it is opened**, so it can be reprinted a year later and still matches the service record. Nothing is duplicated.
* **Print / Save as PDF** uses the browser print dialog (A4). The letterhead deliberately overhangs the text column, exactly as in the office template, so the printed page uses slightly wider page margins and gives the difference back as padding — the body text still begins 2.54 cm from the left and ends 1.79 cm from the right, and nothing is clipped. In the print dialog leave **Margins: Default**; choosing *None* or a custom value can cut the emblem.
* **Download Word** saves a `.doc` file that opens in MS Word for editing before signature.
* The order number is the officer's own order number when one was entered on the record; otherwise the system reference (e.g. `PST-2026-0004`) is used.
* If a record is voided, its order is still viewable but is stamped **NOT A VALID ORDER**.
* The **letterhead follows the office Word template exactly** — the Government of the Punjab emblem at the left margin and the three bold lines in Goudy Old Style to its right, on A4 with the template's own margins. The wording of those lines, the order heading, the body, the signing authority and the "copy forwarded to" list are all yours to change in *Administration → System Settings → Official Orders*; the geometry stays fixed.
* **Signature**: upload a scanned signature there (a PNG with a transparent background looks best) and it prints above the name on every order, including the Word download. With no signature and no name set, orders print `Sd/-`.

## 5B. Posting Map (officers only)

*Posting Map* in the People menu shows every employee at the office they are currently posted to. It is visible to the Super Admin and to officers, each within their own reach — the report viewer cannot open it.

* Pins are numbered with the count of employees at that office; click one for the office details, the department split and the list of employees (photo, designation, code, posted since) — each name links to the profile.
* A **round grey pin** is a department location — employees shown there are posted to an office that has no coordinates of its own (or to no office at all). The popup says so.
* **View on Map** on any employee profile opens the map centred on that employee. That office is pinned in **red**, its card opens automatically, and the employee's own name is highlighted in the list.
* Filter by **district → tehsil → department**, and choose whether to include separated/retired employees.
* The right-hand list shows every office; clicking one flies the map to it.
* **Not on the map** below the map lists employees who could not be placed, and says why — either no posting location was recorded on their current posting, or that location has no coordinates yet.
* Coordinates are entered once — per office in *Master Data → Offices & Sites*, or per department in *Master Data → Departments*.

## 6. Reports

*Reports* menu: Departmental Roster (with sanctioned/filled/vacant), **Area-wise Employees** (who is posted at each office, with tenure at the post), Contract Expiry (X days), Retirement List (X years), LPR Eligibility, Probation Completion, Deputation, Suspension, **Inquiry Report** (pending / concluded), Break in Service, Death in Service, plus per-employee **Profile Report** and **Service Book** — both of which carry an *Inquiries / Disciplinary Proceedings* section.
Each report has the **district → tehsil → department** filter, **Excel**, **CSV** and **Print / PDF** (browser print → *Save as PDF*).

**Area-wise Employees** groups the register by the office recorded on each employee's current posting, with a chip per area showing its headcount; every row also shows the tehsil and district. Narrow it with the district / tehsil / department filter or to a single office, and choose whether to include separated staff. An employee whose posting has no office recorded is grouped under their own department and labelled *(no office recorded)*, so the totals always add up to the full register.

## 7. Administration (Super Admin)

* **Users & Roles** — create users and choose the role. An **Officer** is given one department (district → tehsil → department); the department's level decides what they reach and whether they publish. Link a user to an employee profile (an officer can also be on the register), activate/deactivate, issue a temporary password, recycle bin. Officers without a department are flagged at the top of the page.
* **System Settings** — organization name/address, **employee code pattern** (`{PREFIX} {DEPT} {YYYY} {YY} {MM} {SEQ:n}`, sequence scope), retirement age, probation months, LPR window, EOL break threshold, terminated-rejoin policy, alert windows, cron key, **Official Orders** (letterhead lines, emblem, order heading, signing authority, distribution list, closing sentence) and **Posting Map** defaults.
* **Sanctioned Posts**, **Recycle Bin** (all soft-deleted records), **Audit Logs** (Super Admin only — who/what/when/IP with before → after diff, exportable), **Database Backup**, **Reset Database** (type `RESET` + password; optional demo data reload).

## 8. Everyday tips

* Dropdowns are searchable — start typing.
* Dates use the browser date picker; ages and durations calculate automatically.
* Red and amber badges on the profile mean attention: *Unconfirmed*, *LPR Eligible*, *Break in Service*, *Service Book Closed*, *Inquiry Pending*.
* If something was entered wrongly in a service module, ask the Super Admin to **Void** it and re-enter — never try to "fix" history by editing the profile.
