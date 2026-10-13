Regional_Manager.php
Weekly training-enrollment report, emailed to each regional manager with their
own region's data. Reads entirely from SQL Server (`HSSB`).
What it does
Connects to SQL Server (`HSSB` on `DBLVH1`) via `$sqlsrvConn`.
Pulls every active region (`regions.active = 'Y'`) and its manager — the
manager's email comes from `regions.email`, and their name is looked up
from `users\\\_getallusers` by matching that email.
For each region: enrollment counts (Total / Active / Complete) grouped by
branch, plus the manager's own personal training status, plus a
"Course Status Changes" table (courses that went Active/Inactive since
last Tuesday — this report runs weekly on Tuesdays).
Emails one HTML report per region to that region's manager, CC/BCC'ing a
fixed internal distribution list (see the script for the current
recipients — they're hardcoded in the loop).
`regions` table
`id` — the region's identifier (`RegionID` elsewhere in the script).
`name` — region display name.
`email` — the regional manager's email address (the send-to address).
`active` — `'Y'`/`'N'` flag; only active regions are included.
`temp` — exists on the table but is not filtered on.
Enrollment data and the manager's own training status come from
`CourseEnrollments\\\_GetUserCourseEnrollments`, `users\\\_getallusers`,
`users\\\_groupsforusers`, and `group\\\_list`, every table fully qualified as
`\\\[HSSB].\\\[dbo].\\\[table]`.
Dependencies
PHPMailer — autoloaded from `../../PhpMailer/vendor/autoload.php`.
PDO SQL Server driver (`sqlsrv`).
`SendGrid\\\_config.php` (same folder) — SMTP host/credentials, not included
in this share. You'll need your own copy defining:
`$smtpHost`, `$smtpUsername`, `$smtpPassword`, `$smtpSecure`, `$smtpPort`.
`SQL\\\_ServerCredentials.php` (repo root) — SQL Server host/credentials for
`$sqlsrvConn`, not included in this share. You'll need your own copy
defining: `$serverName`, `$connectionOptions\\\['Database'|'Uid'|'PWD']`.
Running it
No CLI args — runs top to bottom, sends all emails, echoes a success/failure
message. There's no dry-run flag in this script (unlike the ZOOM weekly
reports) — it always actually sends.
