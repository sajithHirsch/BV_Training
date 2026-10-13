# BV_Training
BV_Training 
Regional_Manager.php

Weekly training-enrollment report, emailed to each regional manager with their
own region's data. Reads entirely from SQL Server (`HSSB`).


What it does
Connects to SQL Server (`HSSB` on `DBLVH1`) via `$sqlsrvConn`.
Pulls every active region (`regions.active = 'Y'`) and its manager — the
manager's email comes from `regions.email`, and their name is looked up
from `users\_getallusers` by matching that email.
For each region: enrollment counts (Total / Active / Complete) grouped by
branch, plus the manager's own personal training status, plus a
"Course Status Changes" table (courses that went Active/Inactive since
last Tuesday — this report runs weekly on Tuesdays).
Emails one HTML report per region to that region's manager, CC/BCC'ing a
fixed internal distribution list (see the script for the current
recipients — they're hardcoded in the loop).

