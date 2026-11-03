# Changelog - Block Plant ERP System

All notable changes to this project are documented in this file.

## [Unreleased]

### 📱 Phone Layout — `assets/css/mobile.css`

- **New stylesheet** loaded last in `partials/header.php`, scoped to `@media (max-width: 768px)` so desktop styling is untouched. It fixes the reasons pages scrolled sideways on a phone:
  - **Top bar** was one long row (`flex-wrap: nowrap` from `glass.css`) and pushed the Logout link past the right edge. It is now two compact rows: brand on top, controls below. The brand name is shown again (truncated) instead of the empty gap `glass.css` left below 900px, and the command-palette button becomes icon-only.
  - **Notification panels** were 340px wide and anchored to a bell in the middle of the bar, so they hung off the left edge. They now open full-width directly under the top bar.
  - **Filter bars and stat rows** built with inline `grid-template-columns: repeat(…)` become one column. Page-local grids (`.sale-layout`, `.doc-layout`, `[class$="-grid"]`) collapse too, and their children get `min-width: 0` so a wide table can no longer stretch the column.
  - **Tables** were clipped by `overflow: hidden` from `advanced.css`; they now scroll inside their own box at every width up to 768px. A `<fieldset>` around the attendance table no longer widens the page (`min-width: 0`).
  - **Action cells** keep their buttons on one line (`td:has(.btn)`), which stops rows from growing to ~270px tall when the buttons wrap one per line.
  - **Inputs use a 16px font** so iOS does not zoom the page when a field is focused, buttons get 40px tap targets, and modals/panels are capped to the viewport.
- **Login screen** (`auth/login.php`): the decorative aurora orbs are now `position: fixed` so they no longer stretch the page sideways, the body scrolls vertically instead of clipping the card on short/landscape screens, the card uses `safe center` so it never starts above the top edge, and the username/password fields use a 16px font to stop iOS zoom.
- **Verified** at 320, 360 and 390px on the dashboard, sales list/create, purchases, inventory, production, HR (employees, attendance, attendance sheet), reports, finance, settings (users, Telegram) and notifications: `scrollWidth === clientWidth` on every page. Desktop at 976px is unchanged (single-row 70px top bar, sidebar, no brand in the bar).

### 🧭 Navigation — Module Menus Open on Tap and Click

- **The bug.** On phones, tablets and any window narrower than 768 CSS pixels (including a desktop zoomed to 125–200%), tapping a module in the sidebar such as **Sales & CRM** did nothing. Two scripts listened for the same tap: the sidebar accordion in `partials/header.php` opened the group, then the legacy mobile handler in `partials/footer.php` toggled it straight back closed. The result looked like the menu was broken.
- **The fix.** Removed the duplicate handler from `partials/footer.php` and left the sidebar's delegated accordion (which already works at every width and calls `preventDefault`) as the only listener. Added `.navbar .dropdown.open > .dropdown-menu { display: block; }` to `partials/header.php` so an opened group is visible at any width, not only inside the `max-width: 768px` media query or when the glass theme happens to be active.
- Desktop hover still opens the menus, a click now also opens them, and a second tap closes the group. Verified through the public tunnel at 390 px and at 976 px with no console errors.

### 📲 Telegram Daily Report & Alerts

- **The daily message.** Every working day at **18:00** the app sends one Telegram message with today's attendance (present, late, absent, leave, half-day, not recorded), the names behind each number, the running **16–15 payroll period** totals, and the days present per employee. The figures come from `attendance_sheet_rows()`, so they always match the printable sheet.
- **Seven alert triggers**, each with its own toggle: missing attendance entries, absences without approved leave, late arrivals, pending approvals (leave/overtime/loans), low stock, overdue customer balances, and asset maintenance due in the next seven days. The headline counts how many items need attention, and the message says **"Nothing needs your attention today"** when the list is empty.
- **Optional CSV attachment.** The day's attendance is attached as a UTF-8 CSV that opens directly in Excel, including employees with no row (marked *not recorded*). The toggle is on by default.
- **Settings screen** at **Settings → Telegram Alerts**: bot token, chat ID, send time, the seven toggles, **Find chat ID**, **Send test message**, **Send today's report now**, the last send, the last error, and the last ten log rows. A blank token field keeps the saved token, and the token is shown masked.
- **DNS fallback.** The office DNS servers do not resolve `api.telegram.org`. The Bot API client now tries the pinned Telegram IPs first (`149.154.167.220`, `149.154.166.110`) with `CURLOPT_RESOLVE`, then the system resolver as a last resort, so a lookup failure can no longer block a send. The IP can be overridden under **Advanced**.
- **Scheduled send.** `cron/telegram_daily.php` (with `cron/telegram_daily.bat`) uses a lock file, skips weekends, refuses to run before the saved send time, and records each send in `telegram_message_log`, so the same date is never delivered twice. `--dry-run`, `--date=`, `--force` and `--no-attachment` are supported. A Windows scheduled task **Block Plant Telegram Daily** runs it daily at 18:00.
- **Company timezone everywhere.** A new `apply_company_timezone()` helper makes the scheduled scripts use `company_settings.timezone` (falling back to `Africa/Addis_Ababa`) instead of the PHP default. Before this, the database held `UTC` while the office clock was +03:00, so the 18:00 guard saw 15:00 and silently skipped the report. The timezone was corrected to `Africa/Addis_Ababa`, the send-time check moved into `telegram_send_time_reached()` (unit-tested for the 15-minute grace), and the settings page now shows which timezone the send time follows.
- **Two new tables** (`telegram_settings`, `telegram_message_log`) are created automatically by `ensure_telegram_schema()`; `install/upgrade_telegram.sql` and the LAN clean dump include them for fresh installs.
- Verified end to end: the bot `@Allehrbot` answered `getMe`, the chat ID was captured from `getUpdates`, a test message arrived, the real report with CSV was delivered, and the scheduled task ran with exit code 0 and correctly skipped an already-sent date.

### ⏱ HR Attendance — Late Time Now Reduces the Days Present

- **The rule.** The printable sheet and the Excel export now subtract the period's late time from **በስራ ላይ የተገኙበት ቀን** at **8 working hours = 1 day**. A 2:37 total late time removes 0.33 from the count; previously late days were counted as fully present and the late column was only a note.
- **Both numbers stay visible.** The late time is still written as hours:minutes in **በአርፋጅ የተመዘገቡበት ሰዓት**, and a legend line under the table states the conversion so the sheet explains itself.
- **Two decimals.** Present days now show up to two decimals (a 30-minute late arrival is `0.06` day instead of being rounded away); the Excel cell format was widened from `0.#` to `0.##` to match.
- **One calculation.** The deduction lives in `attendance_sheet_rows()`, so the screen, the Excel export and any future consumer of the row data can never disagree.
- Verified on the current 16–15 period: 6 days with 2:37 late → **5.67**, 6 days with 0:30 late → **5.94**, 6 days with a half-day absence → **5.5**, and employees with no lateness unchanged at 6. `php -l` passes and both the sheet and the export load without warnings.

### 🌐 LAN Deployment Kit — Run Once, Use From Every Office PC

- **Server-side setup.** New `install/lan/Setup-LAN.bat` elevates and runs `Setup-LAN.ps1`, which confirms Apache and MySQL are running and set to Automatic, opens TCP 80 in Windows Firewall for Domain and Private networks, binds MySQL to `127.0.0.1` so the database is not reachable from the network (`my.ini` backed up first), and verifies the application answers on `localhost`, the LAN IP and the host name. `-DryRun` reports what would change without touching anything.
- **Database install with a choice.** `Install-Database.bat` offers **Full data** (`database/block_plant_full.sql`, the current company data), **Clean system** (`database/block_plant_clean.sql`, all 82 tables plus the default admin and starter master data) or **Backup only**. Every run writes a timestamped safety dump to `install/lan/backups/` first, asks for a typed confirmation, and verifies the table, user and employee counts afterwards.
- **Client package builder.** `Create-Client-Package.bat` detects the server address and writes `install/lan/client/` plus `Block-Plant-Client.zip`. The client only runs `Install-Client.bat`, which tests the connection and creates a **Block Plant ERP** shortcut on the Desktop and Start Menu — an Edge/Chrome app-mode window when available, a plain `.url` otherwise. `Test-Connection.bat` and `Uninstall-Client.bat` are included.
- **Data is protected.** `install/lan/` and `install/lan/database/` carry `Require all denied` `.htaccess` files because the dumps contain employee, payroll and customer data; verified that every kit file returns HTTP 403.
- Verified: both dumps import into a fresh database (82 tables each, clean = 1 user / 0 employees, full = live counts), the full login and dashboard/HR/sales pages load over `http://192.168.5.96/block_plant/`, the generated shortcut targets `msedge.exe --app=...` and is removed cleanly, `Setup-LAN.ps1 -DryRun` reports the MySQL hardening as the only pending change, and all PowerShell files parse cleanly.

### 🗑 HR — Employee Delete Option

- **Delete from the employee list.** Employees with **Delete HR records** permission now see a **Delete** button on every row of `hr/employees.php`. It asks for confirmation, then posts to the new `hr/employee_delete.php`, which is POST-only, checks the form token, and records the deletion in the activity log.
- **History is protected.** Deletion is refused when the employee has any linked history — attendance, payroll, leave requests, loans, overtime, performance reviews, assigned assets, documents, training, exit clearances or warning letters/revocations. The message names the blocking areas and points to **Clear history** (remove the records but keep the employee) or **Exit clearance** (deactivate), so payroll and audit records are never erased by accident.
- **Clean removal for unused employees.** An employee with no history is deleted together with their per-employee configuration rows (`leave_balances`, `employee_shifts`) in one transaction. Without **Delete** permission the button is hidden and the endpoint returns 403.
- Verified create → delete (including config-row cleanup), refusal on an employee with history, invalid-token rejection, GET redirect and the edit-only 403 case. `php -l` passes.

### 🧹 HR — Clear Employee History (Keep the Employee)

- **Start an employee fresh.** A new **Clear history** action on `hr/employees.php` opens `hr/employee_clear_history.php`, which permanently removes the employee's attendance, payroll, leave requests and balances, loans and repayments, overtime, performance reviews, asset assignments, documents, training, exit clearances, warning letters/revocations and shift assignments — while keeping the employee record.
- **Type the employee code to confirm.** The confirmation page lists the exact number of records in each area and keeps the destructive button disabled until the employee code is typed exactly. The server checks the code again, so the action cannot be triggered by a stray click or a forged request.
- **Employee profile stays.** Name, employee code, department, designation, salary, bank details, shift and status are unchanged, ready for new attendance from now. Company asset records stay in the system and fixed assets issued to the employee are marked available again; cashbook and accounting entries are not touched — only the employee's assignments and history are removed.
- **Files are removed.** Uploaded employee documents and warning-evidence files are deleted from disk after the database transaction commits; a failed purge rolls back and changes nothing. Every purge is recorded in the activity log with the counts removed.
- Verified with a disposable employee holding a row in all 16 linked areas plus two uploaded files: wrong code refused, correct code cleared every row and both files, the employee row stayed, and an edit-only HR user got 403 with no action link. `php -l` passes.

### 🕒 HR Attendance — Shift Times and Automatic Late Marking

- **Company working hours are built in.** The daily register now shows the two company sessions in Ethiopian local time and standard time: **morning 2:30 AM – 6:30 PM (08:30 – 12:30)**, **afternoon 7:30 PM – 11:30 PM (13:30 – 17:30)**, with the **Friday morning session ending at 5:30 AM (11:30)**. Helpers `attendance_work_sessions()`, `attendance_local_time_label()`, `attendance_shift_options()` and `attendance_shift_label()` keep the schedule in one place.
- **The recorded time decides lateness.** Each register row now has a **Shift** selector (Morning/Afternoon) and a live hint under **Clock In** — *On time* or *Late by N min*. Entering a clock-in after **2:45 AM (08:45)** for the morning shift or **7:45 PM (13:45)** for the afternoon shift sets the status to **late** automatically; an on-time clock-in sets it to **present**. The rule is applied again on the server, so a modified form cannot bypass it.
- **Only late needs a time.** Marking an employee **present** is accepted as on time without a clock-in, and **half-day** is accepted without a time as well. Only a **late** row requires the clock-in that justifies it; the form warns before submitting and the server refuses the whole save, listing the employees missing a time (nothing is half-saved). Absent and leave rows do not need a time.
- **Late-time popup.** Choosing **late** in the register — or having a row marked late automatically from the clock-in — opens a styled dialog with the employee's name, date and shift, a clock-in field, an optional clock-out and a live **Late by N min / On time** result. Saving writes the time into the row and flashes it; the dialog also opens for the first late row still missing a time when the register is submitted. Esc, Cancel and the backdrop close it and return focus to the row, and the popup is skipped when the user is already heading for **Save Attendance**.
- **Shift per employee.** Employees now have a default **Work Shift** (Morning/Afternoon) on the employee form, shown in the employee list and pre-selected in the register; HR can still switch the shift for a single day. The shift used is stored on each attendance row.
- Verified the grace boundaries (08:45 on time, 08:46 late; 13:45 on time, 13:46 late), Friday 11:30 morning end, automatic present/late switching, missing-time rejection, and the employee shift save. `php -l` passes.

### 🔔 HR Attendance — Warning Letter Notifications

- **HR is told when a warning letter is issued.** Opening a warning letter in `hr/attendance_warning_print.php` now creates an in-app notification for administrators, HR managers and HR users allowed to print or approve warning letters. The notification names the employee, the reference (`WRN-…`), the working days absent and the period, and links straight to the letter.
- **The employee is told too.** When the employee has a login account, they receive their own notification explaining the warning, the reference and the period, and asking them to contact HR if the record is wrong. Employees are matched to accounts by an explicit `employees.user_id` when present, then an exact name match, then a unique `users.username` = `employees.email` match; ambiguous or missing matches are skipped so a letter is never sent to the wrong person.
- **Sent once per warning.** New `attendance_warning_issues` table records each generated letter, deduplicated by employee plus the exact set of absence dates. Re-opening or re-printing the same letter sends nothing, while a warning that later covers additional absences notifies again. The issuing user is excluded from the notification.
- Notifications are bilingual (English/Amharic, following the issuing user's language) and are written in the same transaction as the issue record. Verified English and Amharic messages, recipient resolution, duplicate suppression and the employee notification path; `php -l` passes.

### 🖨 HR Attendance — Printable Employee Attendance Sheet

- **New printable form.** `hr/attendance_sheet.php` reproduces the printed Amharic payroll form **የሠራተኞች ቀሪ መለኪያ ቅጽ**: company heading in Amharic and English, department, Ethiopian date range (e.g. `16/12/18-15/01/19`), and columns for days present, unexcused absence days, late, excused absence days and reason. The note legend reads **ዓመት ፈቃድ(Annual Leave)...... ልዩ ፈቃድ(Casual Leave)...... ሕመም ፈቃድ(Sick Leave).....**.
- **16–15 payroll periods.** New helpers `jdn_to_gregorian()`, `ethiopian_to_jdn()`, `ethiopian_to_gregorian()`, `ethiopian_month_shift()`, `format_ethiopian_short_date()` and `ethiopian_payroll_period()` in `config/helpers.php` default the sheet to the **previous complete 16–15 Ethiopian period**, with **Current** and **Previous** buttons. A payroll month counts at most **30 working days**: the Pagume-crossing period keeps its 16 Nehase to 15 Meskerem date range, but Pagume (month 13) days are excluded from the counts.
- **Auto-filled figures.** `attendance_sheet_rows()` groups active employees by department and pre-fills: present days = countable period days (maximum 30) minus unexcused absences and approved **unpaid** leave (unrecorded past days count as present, unrecorded future days are ignored), unexcused absences from `absent` rows, and the excused **day** and **reason** columns from approved `leave_requests` (e.g. `5` / `Sick Leave`). Half-days count as 0.5 present and 0.5 absent. Employees are excluded before their joining date.
- **Pre-filled future days count immediately.** A day later in the period that already has a saved register row — for example tomorrow's attendance entered today — now counts on the sheet straight away, including its late time or absence. Unrecorded future days are still ignored, and future approved-leave rows do not inflate the counts. This fixes saved future attendance not appearing on the sheet. When the newest register entry is in the current period, the sheet (which opens on the previous payroll period) also shows a link to **Show the current period**.
- **Paid leave counts as present.** Approved paid leave (annual, sick, casual, emergency) no longer reduces days present and never reduces payroll; only approved unpaid leave is deducted, matching `payroll_attendance_summary()`. The attendance report, HR dashboard, department report and monthly submission summary also count paid leave in their **Present** figures. A bilingual hint on the sheet explains the rule.
- **Late status.** The daily register now offers **late** alongside present, absent, half-day and leave. Late days count as present on the attendance sheet and are not deducted by payroll. The sheet's **በአርፋጅ የተመዘገቡበት ሰዓት** column now writes the **total late time as hours:minutes** (47 minutes → `0:47`, 1 h 30 min → `1:30`), summed from each late day's recorded clock-in against the shift grace time (`attendance_late_minutes()`); late days with no recorded time contribute nothing.
- **Printing.** Choose **One sheet per department** (default) or **All employees in one sheet**; the combined layout adds a department column and sorts employees by department. Each department sheet starts on a new A4 landscape page, the toolbar and filters are hidden when printing, the table header repeats on page breaks, rows are kept together, and the form keeps its outer border. Links were added to the attendance register, attendance report and HR menu.
- **Excel export.** An **Export Excel** button sits next to **Print** and downloads the same sheet as `attendance_sheet_<from>_<to>.xls`. The workbook repeats the company heading (Amharic and English), the Amharic title, department, Ethiopian 16–15 date range, the same columns, legend and signature lines, and follows the selected period, department and layout — one block per department, or all employees with the department column.
- Verified against the supplied form: Ethiopian period/date conversions, department filtering, previous-period counts, screen rendering, and an inlined-CSS print simulation (toolbar hidden, sidebar removed, full-width bordered form that fits A4 landscape). `php -l` passes.

### 🕒 HR Attendance — Late Status

- `hr/attendance.php` accepts and stores `late`; `attendance_status_label()` maps it to **Late / ዘግይቶ የመጣ**.
- The attendance report filter, KPI cards, employee summary, CSV and Excel exports all include a **Late** column.
- Late is also counted in the monthly submission summary, department report and the HR dashboard snapshot.

### 📎 HR Attendance — Revocation Evidence Files

- **Real documents, not just a reference.** When revoking a warning, HR editors can attach up to **five** evidence files (PDF, JPG/JPEG, PNG, WEBP, DOC, DOCX; **10 MB each**) in addition to the evidence-reference note.
- **Private storage and authenticated download.** Files are stored under `uploads/warning_evidence/{revocation_id}` with a deny-all `.htaccess`, recorded in `attendance_warning_revocation_files`, and downloaded through the authenticated `hr/attendance_warning_evidence.php` endpoint. Direct web access is denied.
- **Visible and auditable.** The revoke confirmation and the revoked-warning panel list each file's original name, size, uploader and upload time with a download button. Restoring a warning removes its stored files. Partial uploads report which files failed without losing the revocation.
- Verified upload validation (type, count, size), authenticated download, and restore cleanup. `php -l` passes.

### 🔐 HR — Approve Permission Required to Revoke/Restore Warnings

- **Approve is required.** Revoking and restoring warning letters now requires **Approve HR requests**. **Edit HR records** can still open and print a warning letter, but the Revoke and Restore actions are hidden and `hr/attendance_warning_revoke.php` returns 403. The register, letter page and revoked-warning panel follow the same rule.
- Verified with edit-only, view-only and admin users: edit-only sees the print action but no Revoke (revoke URL 403), view-only sees neither, admin sees both. `php -l` passes.

### 👥 HR — Scoped Permission Management

- **Grant HR actions from inside HR.** New `hr/permissions.php` lets admins, HR managers and non-department users with `hr edit` grant **edit**, **delete** and **approve** to HR department users. Grants live in the dedicated `hr_permission_grants` table, so stale or unrelated `user_permissions` rows are never activated; HR users always keep baseline view/create and HR managers keep full HR access.
- **Safe and auditable.** The page refuses non-HR targets and HR managers (who already have full access), and every change is written to the activity log. It is linked from the HR menu.
- Verified grant/clear round-trips and access checks with temporary HR users. `php -l` passes.

### 🚫 HR Attendance — Warning Letter Revocation

- **Evidence-based revocation.** HR editors now see a **Revoke** action beside every active warning. `hr/attendance_warning_revoke.php` records the reason/evidence reviewed and an optional evidence reference in the new `attendance_warning_revocations` table.
- **Revoked letters cannot be reprinted.** The warning register removes revoked employees from the active list and KPIs, and shows them in a separate **Revoked warnings** panel. The letter page checks whether the warning’s absence dates fall inside a revoked period and displays a “Warning letter revoked” notice instead of the letter, even when `print=1` is present. A later absence outside the revoked period starts a new active warning.
- **Restore and audit.** HR editors can restore a revoked warning from the panel, making it active and printable again. Revoke and restore actions are written to the activity log.
- **Bilingual.** Revocation screens, notices, buttons and the revoked-letter page follow the English/Amharic switcher.
- Verified the English and Amharic revoke/restore flows, active-list filtering, blocked reprint, restored letter, and period-drift coverage. All temporary test revocations and attendance rows were removed. `php -l` passes on all touched PHP files.

### 🛠 HR Attendance — Joining-Date Guard

- **No attendance before joining.** `hr/attendance.php` now lists only active employees whose `date_of_joining` is on or before the selected date, and the POST handler validates the same rule. A direct POST for an employee who has not joined yet is ignored.
- **Related calculations stay consistent.** The daily reminder, leave-to-attendance sync, attendance report/export, warning candidates, finance attendance review and payroll deduction queries ignore attendance rows dated before the employee's joining date.
- **Verified.** Employee #10 (joined 2026-10-22) is absent from the 2026-10-13 register, a direct POST for 2026-10-12 creates no row, the employee appears on 2026-10-22, and a valid employee still saved normally for the same test date. The test row was removed.
- **Old invalid rows removed.** Eight attendance rows dated before the related employee's joining date were deleted after review. A final check found **0** remaining pre-joining rows and **0** temporary test rows.

### ⚠️ HR Attendance — Printable Warning Letters

- **New warning register.** `hr/attendance_warnings.php` lists active employees with more than two recorded full-day absences on working days in a selected period. It has from/to and department filters, summary KPIs, absence dates and the longest consecutive working-day run.
- **Printable written warning.** `hr/attendance_warning_print.php` generates a one-page company-headed warning letter with employee details, the absent dates, total and consecutive absence counts, policy text, HR signature and employee acknowledgement. The letter auto-prints when opened from the register (`print=1`) and can be reprinted from its toolbar.
- **English and Amharic.** The warning register and letter follow the app language switcher, and the letter has its own EN/አማ toggle. Warning-specific text lives in `attendance_warning_text()`; exact display dates switch to the Ethiopian calendar (including ዓ.ም., Ethiopian month names and weekdays) when Amharic is selected, while date inputs stay Gregorian for data entry. The company name/tagline use the bilingual company settings, and the letter uses an Ethiopic-capable font stack. Amharic attendance labels use **የሰዓት መቆጣጠሪያ** instead of the previous **መገኘት** terminology.
- **Accurate absence counting.** New helpers `attendance_absence_days()`, `attendance_absence_summary()`, `working_days_between()` and `attendance_warning_candidates()` in `config/helpers.php` count only `absent` rows on configured working days. Approved leave, half-days, weekends and unrecorded days are excluded. Friday-to-Monday counts as consecutive; a working day in between breaks the run.
- **HR/Admin only printing.** Any HR viewer can see the warning register, but the print link and the letter page require `hr edit` permission. View-only HR users see an “HR editor required” note and receive a 403 if they open the print URL directly.
- **Navigation.** The register is linked from the HR menu, HR overview actions, the daily attendance page and the attendance report.
- Verified with helper date cases, a temporary 3-day absence for employee #1, list/filter/empty-state rendering, the generated letter and its one-page height, the no-letter state for an employee with 0 absences, and a temporary view-only HR user (list visible, print denied). Both the English and Amharic letters were printed to PDF as single A4 pages. All temporary attendance rows and the test user were removed.

### ⏰ HR Attendance — Working-Day Reminder

- **Daily reminder when the register is incomplete.** New `cron/attendance_reminder.php` counts active employees with no attendance row for the date. When scheduled after the workday it sends one in-app notification to admins, HR users/managers and users with HR edit permission, and writes the same summary to the existing email log.
- **One reminder per date.** A new `attendance_reminder_log` table has a unique date key, so repeated cron runs are skipped. Complete registers, weekends, duplicate runs and runs without recipients are skipped without writing a log row.
- **HR overview banner.** `hr/index.php` now shows an amber **Attendance is not fully recorded for today** banner on working days, with the missing/active count and a direct link to the daily register. The banner disappears once every active employee has a row.
- **Configurable working week.** `is_working_day()` defaults to Monday-Friday; define `ATTENDANCE_WORKING_DAYS` as `[1,2,3,4,5,6]` to include Saturday. The Task Scheduler/cron trigger controls the send time; `cron/ATTENDANCE_REMINDER.md` shows a 5:00 PM weekday schedule.
- **Safe operations.** The script uses a file lock, creates its log table idempotently, wraps notification and log writes in a transaction, and supports `--dry-run`, `--force` and `--date=YYYY-MM-DD` for testing. `cron/attendance_reminder.bat` and `cron/ATTENDANCE_REMINDER.md` cover manual runs and Windows Task Scheduler setup.
- Verified with helper/date checks, a dry run, a real test-date run and duplicate-run skip, then removed the test notifications and log row. `php -l` passes on the touched PHP files.

### 🧰 Asset Clearance — Returned Condition & Maintenance Requests

- **Clearance now records the returned condition for every asset.** `hr/asset_clearance.php` and `hr/asset_clearance_ajax.php` use the same condition choices as **Return Asset** (Excellent, Good, Fair, Poor, Damaged, Needs Repair, Not Working, Broken), require a condition before clearing, and show the recorded condition and return date in the asset list. The old one-click clear silently wrote `condition_returned = NULL`; the bulk path now opens a per-asset form so each asset gets its own condition, notes and clearance date.
- **Automatic maintenance requests.** Conditions of Poor, Damaged, Needs Repair, Not Working or Broken create a high-priority `asset_maintenance` request (source `asset_return`, due the next day) and notify maintenance users, exactly like Return Asset. Duplicate open requests for the same employee asset are skipped.
- **Fixed-asset consistency.** Returning or clearing an asset now marks the linked fixed asset active and updates `assets.condition_status` using the return condition (Excellent/Good → good, Fair → fair, Poor/Needs Repair → poor, Damaged → damaged, Not Working/Broken → critical). This also fixes Return Asset, which previously wrote the non-numeric string `'active'` to the tinyint `assets.status` column.
- **Safer workflow.** Both screens now share `return_employee_asset()`, which validates the condition/date, verifies the asset belongs to the selected employee, refuses an asset that is already returned, logs the action, and records notes. View-only HR users can inspect assets but cannot clear them; write forms are hidden and POSTs return 403.
- Verified with individual and bulk clearance, an invalid condition, a duplicate attempt, a wrong-employee attempt, and the Return Asset path: conditions, return dates, notes, fixed-asset status/condition, maintenance rows, notifications and activity logs were checked, then all test rows and the temporary fixed asset were removed.

### 🔧 Asset Maintenance — Manual-Asset Completion Status

- **Completed manual-asset requests no longer jump back to Pending.** `assets/maintenance.php` runs an old normalisation query on every load that reopened `source = 'asset_return'` rows with `asset_id` empty/null. Fixed assets were unaffected because they have an `asset_id`; manually entered employee assets have no fixed-asset link, so their completed requests were reset to `pending` on the next page load.
- The normalisation now only touches untouched rows (`status = 'pending'`, no completion date, no completion notes) and never reopens a completed request. A one-time repair also marks existing rows that already have `completed_at`/`completion_notes` as `completed`, correcting the two rows that were stuck in Pending.
- **Manual assets now show their name in the pending queue.** The pending-request query uses `COALESCE(a.name, ea.asset_name, 'N/A')` instead of only the fixed-asset name, so the Complete dialog and pending table no longer show “N/A” for manually entered assets.
- `hr/asset_issuance.php`’s old cleanup now also requires `completed_at IS NULL` before reopening a return request, so a genuine completion with no notes is not mistaken for an untouched row.
- **Repaired manual assets can return to service in the same step.** The completion dialog now offers a checked-by-default “Return this repaired manual asset to the employee and mark it **Issued** again” option for eligible manual assets. Completing the request sets the employee asset back to `issued`, clears its return date and returned condition, appends a maintenance note and writes an activity-log entry. The option is hidden for fixed-asset-linked rows, inactive employees and already-reissued assets; a serial conflict, another open maintenance request for the same serial, or an inactive employee leaves the record `returned` with an explanatory message.
- Verified by returning a manual asset (“Needs Repair”), completing its maintenance request, then loading `assets/maintenance.php` twice: the request stayed `completed` with its completion date, cost and notes. Test rows were removed and the pre-existing stuck requests were repaired to `completed`. The reissue path was also checked against a fixed-asset control row: the manual asset became `issued` again while the fixed asset’s employee record correctly stayed `returned`.

### 🚫 Asset Issuance — No Reissue While Under Maintenance

- **Why it happened.** Manual issuance only checked for an existing row with the same serial in `status = 'issued'`. Once an asset was returned it became `returned`, so the same serial could be issued again while its repair request was still open. Fixed assets were listed whenever `assets.status = 1`, which return sets immediately, so a fixed asset under repair also stayed selectable.
- **Server-side guard.** `hr/asset_issuance.php` now calls the new `open_maintenance_request_for_serial()` and `open_maintenance_request_for_fixed_asset()` helpers in `config/helpers.php`. A manual serial or fixed asset with a `pending`/`in_progress` maintenance request is refused with a clear message, and no new issuance row is created.
- **UI guard.** Fixed assets with open maintenance are removed from the “Select Fixed Asset” list, and the issuance records table shows an amber **Under maintenance** badge for returned assets that still have an open request. The serial field now explains that the same serial must be entered for the guard to match.
- Fixed-asset issuance now writes numeric `0` to the tinyint `assets.status` (not available) instead of the string `'assigned'`, matching the active/inactive convention used elsewhere and avoiding strict-mode errors.
- Fixed-asset issuance also refuses an asset that already has an issued employee-asset row, so an out-of-sync asset status cannot create a second issuance.
- Verified with a manual asset and a fixed asset: both were blocked while pending, the fixed asset disappeared from the dropdown, and after completion the fixed asset was selectable and the manual serial could be issued again. A second fixed-asset issuance was also rejected. All test rows were removed.

### 💵 Employee Loans — Partial or Full Manual Repayment

- **Employees can now repay a loan outside payroll.** `hr/loan_repayments.php` gained a “Record Loan Repayment” panel for HR editors and finance creators. Pick an approved loan with an outstanding balance, enter a partial amount or click **Use full balance**, choose the payment date and method, and optionally record a receipt/reference number.
- **Validated and transaction-safe.** The repayment must be positive and cannot exceed the remaining balance; the date cannot be in the future; the payment method and reference are validated. The loan row is locked `FOR UPDATE`, the balance is reduced in the same transaction, and a one-use form token plus a disabled submit button prevent CSRF and double submission.
- **Full accounting trail.** Each manual repayment writes a `loan_repayments` row with `source = 'manual'` and a `notes` value of **Partial payment** or **Full settlement** (new idempotent `notes` column), a cashbook receipt, and a balanced journal entry (debit Cash `1110`, credit Employee Loans Receivable `1160`). Activity is logged.
- **Payroll stays separate.** Payroll queries now filter `source = 'payroll'`, so a manual repayment never suppresses or inflates a monthly payroll installment. Regenerated payroll also keeps the actual prior installment amount instead of treating the repayment count as the amount.
- **History clarity.** The repayment register labels manual rows as Partial payment / Full settlement and shows the payment method and receipt reference alongside payroll deductions.
- Verified with partial and full repayments, overpayment and bad-token rejection, cashbook/journal creation and full cleanup; chart balances and test rows were restored.

### 📊 HR Attendance — Printable Report & Excel/CSV Export

- **Attendance report for HR.** New `hr/attendance_report.php` filters the register by date range, employee, department and status, with summary KPIs, per-employee totals and a detailed daily log. It is linked from the HR menu, the HR overview and the daily attendance page.
- **Print and export.** The report has a print layout (company header, period/filters, repeated table headers) and downloads as an Excel-compatible `.xls` file or UTF-8 CSV through `hr/attendance_export.php`. Both exports include the summary, per-employee totals and detail rows.
- **Bilingual.** Report labels, statuses, period and generated date use the existing English/Amharic language switcher; shared helpers (`attendance_report_records()`, `attendance_report_summary()`, `attendance_report_employee_summary()`, `attendance_status_label()`) keep the page and exports consistent.
- **No more double saves.** Daily attendance now carries a one-use form token, disables the save button after submission, and compares submitted values with the saved rows. A double-click or repeated POST is rejected, unchanged rows are skipped, and saving an already-saved register shows “already saved — no changes” without another activity-log entry.

### 🧮 Payroll — Future-Period Guard & Attendance/Leave Wiring

- **Future payroll is blocked.** Generation, attendance submission/approval, finance approval/transfer and payment now reject any period that starts after the current month, so next month's payroll cannot be created or paid early. `finance/unpaid_payroll.php` also excludes future periods from unpaid totals and shows a warning when future rows exist.
- **Leave approvals now write to the attendance register.** Approving a leave request upserts each date as `leave` for that employee (`sync_leave_to_attendance()`), preserving existing notes; `hr/attendance.php` pre-marks employees on approved leave for the selected date and links to leave management.
- **Payroll deducts attendance and leave.** `payroll_attendance_summary()` counts recorded absences, half-days (0.5) and approved **Unpaid Leave** dates (excluding days already absent) at the daily rate (basic ÷ 30). Paid leave types are not deducted.
- Payroll gained idempotent `unpaid_days` and `attendance_deduction` columns; generation stores the snapshot and includes it in total deductions/net pay. The payroll table shows unpaid days, attendance deduction and a summary card; payslips break out the attendance deduction and unpaid days.
- **Annual leave balances.** New `leave_balances` table (per employee/year entitlement + adjustment) and a Leave Management panel showing entitlement, carried forward, used and available days. Used days come from approved Annual Leave requests; unused days are carried into the next year automatically, and negative adjustments can expire days. Entitlements can be saved per employee or seeded with a default, and the leave request form shows the selected employee's available annual leave.
- **Annual leave over-requests are blocked.** The request form now validates Annual Leave against the available balance before saving, splitting cross-year requests by calendar year and reserving other pending annual requests. Approval applies the same check, so an over-limit request cannot be approved; both errors are shown in English and Amharic.
- **Existing over-limit request corrected.** The already-approved 69-day request #17 (Helen Dasure, EMP0007) was set to `rejected`, its 69 synced attendance rows were removed, and her 2026 annual balance was restored to 40 days. A JSON backup of the request and removed attendance rows was saved under the temporary OpenCode directory.
- **Printable leave request form.** `hr/leave_print.php` renders a signed A4 form with company header, employee details, leave dates, reason, annual-leave balance table (including the effect of a pending request across year boundaries), employee declaration and signature blocks for the employee, supervisor, HR and handover recipient. New requests open the form immediately after submission, and every row in Leave Management has a “🖨 Form” link.
- **Leave Management is now bilingual (English / አማርኛ).** The leave list, annual-balance panel, leave request form and printable form use the application language switcher; all labels, flash/validation messages, leave types, statuses, dates and the print-form declaration/signature blocks have Amharic translations. The printable form also has its own EN/አማ toggle so a signed copy can be produced in either language without leaving the page.
- Existing approved leave was backfilled into attendance (18 date rows) and `php -l` passes on every touched file.

### 🏭 Assets Module — Permissions, Maintenance Completion & Correct Depreciation

- **Write actions were open to view-only users.** `assets/asset_form.php`, `assets/categories.php` and `assets/maintenance.php` were gated only on `assets view`, so any reader could create/edit assets, categories and maintenance records by POSTing directly. Create/edit/delete now check `assets create`/`assets edit`/`assets delete` independently, forms and row actions are hidden without permission, and `assets/journal.php` now requires `assets create` (the sidebar already hid its link).
- **POST handled after the header.** All asset mutations now run before `partials/header.php` with validation errors surfaced through flash + PRG, so refreshing cannot repeat a write. Success and failure are logged with `log_activity`.
- `assets/asset_form.php` validates code format/uniqueness, name/category, purchase date (not future), cost, supplier, useful life (1–100), salvage ≤ cost, current value, location, condition whitelist (now includes `critical`) and notes length; it also shows maintenance statistics for the selected asset, restores submitted input on error, and suggests the next `ASSET####` code.
- `assets/categories.php`: validation (name ≤100, unique, rate 0–100), PRG, edit via `?edit=`, activate/deactivate, and **delete is refused while assets reference the category** (deactivate instead). Asset counts and purchase cost per category are shown.
- **Maintenance completion tracking.** `asset_maintenance` gained idempotent `status`, `completed_at` and `completion_notes` columns; requests raised from asset returns are marked `pending` once, and completing one now records the completion date, actual cost, next date and notes **without overwriting the original request date**, and only from `pending` (duplicate completion is rejected). Maintenance records can be added, edited and deleted, and the page gained filters, KPIs, a pending-request queue, a completion modal and print styling.
- **Depreciation was wrong.** `assets/depreciation.php` used `YEAR(today) - YEAR(purchase_date)` (whole calendar years, so a December purchase showed a full year immediately) and ignored `useful_life`. It now uses shared `calculate_asset_depreciation()` — straight-line over useful life (falling back to the category rate), day-accurate elapsed years, capped at cost − salvage — with an as-of date, category/status filters, annual and accumulated figures, computed book value, a variance against the recorded value, KPIs, totals, print and CSV (`assets/depreciation_export.php`). The per-asset "Post" link pre-selects the depreciation journal type.
- `assets/alerts.php` now classifies every active asset (overdue, due within 30/7 days, no schedule), adds pending maintenance requests and condition alarms as separate sections, and links each row to the maintenance page.
- New `assets/export_csv.php` (register, filtered) and asset register improvements: search/category/condition/status filters, KPIs, next-maintenance due badges, usage totals, empty state and print.
- Fixed asset journal defects: disposal derived book value from a posted field that could disagree with accumulated depreciation (entries then failed the balance check); it now computes book value = cost − accumulated, recomputes gain/loss, validates proceeds/accumulated, caps accumulated at cost, rejects inactive assets and future dates, truncates descriptions to the column limit, blocks a second depreciation entry for the same asset/month, and caps depreciation at the remaining depreciable value. `journal.php` now honours `?type=` deep links and fixes a strict-type asset selection mismatch.
- Verified with `php -l` and a **76-assertion** HTTP/database suite covering expense and asset create/edit, category guards, maintenance add/complete/duplicate-complete, recurring generation and duplicate prevention, validation, permissions for a temporary view-only user, CSV output, and full cleanup of test users, assets, categories, maintenance, expenses, cashbook rows and activity logs (all baseline counts restored).

### 🧾 Expenses Module — Approval, Voucher, Recurring & Export Workflow

- **Approval and cashbook consistency.** Expenses previously defaulted to `approved` and had no approval UI. New shared helpers `expense_categories()`, `expense_payment_methods()`, `expense_statuses()`, `is_valid_date_string()` and `post_expense_cashbook()` centralise the vocabulary and guarantee **exactly one cashbook row per approved voucher**. Admin-created expenses follow the same auto-approve path; non-admin expenses stay `pending` and notify approvers.
- `expenses/save.php`: POST-only and `expenses create` gated, full validation (valid non-future date, category, description ≤255, amount ≤99,999,999.99, payment method, reference ≤80, notes ≤2000), transaction-wrapped write, old-input restore, activity logging and "save and add another".
- `expenses/create.php`: create gate, merged category list, restored input, approval notice, maxlengths and flash rendering.
- `expenses/index.php`: validated and auto-swapped date range, category/method/status/search filters, pagination (50), KPIs (filtered total, approved, pending, cash), **approved-only cash totals**, pending badges, deep links to vouchers, CSV export and print.
- New `expenses/view.php`: printable voucher with company header, status/approval details, approval comment, cashbook-posting check, audit trail and inline approve/reject for `expenses approve`.
- `expenses/approvals.php`: `expenses approve` on POST, pending lock, approve through `post_expense_cashbook()`, rejection reason required (≤255), notifications, KPIs, pending and recent-decision tables, pending CSV export and print.
- New `expenses/export_csv.php`: expenses-scoped filtered CSV with UTF-8 BOM, totals/approved/pending summary rows, 10,000-row cap and export logging.
- `expenses/recurring.php` rebuilt: idempotent `last_generated_at` column, view/create/delete permissions, add/edit/pause/resume/delete, validation, **same-day and duplicate generation guards**, auto-approval for admins / pending + notify for others, next-due advancement, KPIs and print.
- Generated recurring vouchers follow the same approval/cashbook flow as manual expenses and never duplicate the same template/due-date occurrence.
- Covered by the same **76-assertion** suite: invalid amount rejection, admin auto-approval, single cashbook row, search, voucher detail, CSV, recurring create/generate/duplicate guard/next-due, plus cleanup restoring all counts.

### ⚙️ Settings Module — Permission Matrix, Backups, Logs & Branding

- All management pages (`company.php`, `users.php`, `permissions.php`, `blocks.php`, `backup.php`) now require `settings edit`; read-only pages (`activity_log.php`, `timeline.php`, `documents.php`, `profile.php`) allow `settings view` where appropriate. Previously every Settings page could be opened and mutated by any signed-in user.
- **User administration:** creating/editing/deactivating users is gated, duplicate usernames are rejected, a user cannot deactivate or demote themselves, and the **last active system administrator cannot be demoted or deactivated**.
- **Permission matrix:** system-admin accounts bypass the matrix and cannot be edited there; explicit module rows are preserved, department access takes precedence, and each module's create/edit/delete/approve columns are explained with the correct labels.
- **Block types:** sales/quotations/production/recipes/holding-stock references now block hard deletion — the block is deactivated instead.
- **Document library uploads:** extension whitelist, size cap, MIME rejection, randomised stored filename and an `.htaccess` execution block; uploads are restricted to permitted settings users.
- **Backups:** restricted to `settings edit`, with selective table export and streamed gzip (no `php://output` seekability failure), plus safer filename handling.
- New `settings/export_logs.php`: settings-scoped, filtered CSV export of the activity log with UTF-8 BOM.
- `config/helpers.php` additions: `permission_module_labels()`, `format_bytes()` and cached `company_settings($reload = false)`; sidebar branding/company details and settings links now respect permissions.
- Company settings, logo, colours and currency are reflected consistently across the app; the backup and document pages explain their limits inline.
- Verified with **101 assertions** (users, permissions, company, blocks, documents, backup/export, profile, activity log, timeline), including permission denials, last-admin protection, upload rejection, backup permission, CSV output and cleanup — company values and the single pre-existing document row were preserved.

### 💰 Finance Module — Ledger Integrity, Approvals & Report Fixes

- **Trial balance was not built from the ledger.** `finance/trial_balance.php` totalled the denormalised `chart_of_accounts.balance` cache, ignored inactive accounts inconsistently, and treated any unknown account type as a credit while only rendering `liability/equity/revenue` rows as credits — so the rows could not add up to the totals. It now derives every figure from **posted `journal_entry_lines`** (draft/void excluded, accounts on `status = 1`), supports an **as-of date** (default today, validated with `checkdate`, plus "All posted" / "Today" shortcuts), places each account's net balance in the debit or credit column, flags any imbalance, and adds an empty state, KPI cards, per-account drill-down to the general ledger, CSV export and a print layout. This exposed real cache drift on four accounts: Cash `2,421,639.17` vs ledger `2,420,659.17`, Revenue `1,835,680.00` vs `1,834,700.00`, Finished Goods `24,041.75` vs `24,634.42` and COGS `1,386,062.37` vs `1,385,469.70` (the differences net to zero, so the cache itself is still balanced in total).
- **`finance/loan_approval.php` could approve a rejected loan.** The approve action had no `pending` guard (only the transfer step did), so any loan — including rejected and already-approved ones — could be flipped to approved. Both actions now require the loan to exist, be `pending` and belong to an active employee, and run as a single conditional `UPDATE … WHERE approval_status = 'pending'` with an affected-row check, so two reviewers can't race. Rejection now requires a trimmed reason (≤255 chars, control characters rejected) which is **stored in a new `rejection_reason` column** and shown in a new "Rejected requests" table; reviewer and review date are recorded in `approved_by`/`approval_date`. Also fixed: **the deduction end date was one month too long** (a 12-month loan generated 13 monthly deductions — new approvals now use start + duration − 1 months), POST now runs before the header with flash + PRG (the old `set_modal` call after `header.php` never displayed), notifications are capped to the 255-char column, `log_activity` records every decision, and the page gained KPI cards, approve/reject modals, per-row status/approval details and print styling.
- **`finance/payroll_approval.php`:** POST moved before the header (its confirmation modal was set after `header.php` and never rendered), approve/reject wrapped in a transaction, `rowCount()` guards so repeating the action reports "nothing to approve/reject" instead of silently succeeding, bank reference validated (≤100 chars, control characters rejected, auto-generated when blank), transfer notes now actually stored (in `payroll.notes`) instead of being discarded, every approval logged, and notifications sent only when rows were changed. The status banner previously read the first payroll row, so a mixed period displayed one arbitrary status; it now aggregates per-status counts and only offers actions for rows in `transferred`. Totals now come from real payroll rows (including overtime and stored `net_salary`) instead of falling back to an employee's basic salary, and employees without a payroll row are shown separately instead of inflating the totals. Per-row status badges, approver/bank reference columns and print styling added.
- **`finance/journal_form.php`:** entry posting now delegates to the existing `post_accounting_journal()` inside a transaction (one source of truth for entry numbering and balance updates), with per-line errors (negative amounts, both sides on one line, unknown/inactive account), a clear "difference of X" message, a friendly duplicate-entry message, minimum two lines, server-side entry/date/description/reference validation, PRG + flash, `log_activity`, and a dynamic multi-line editor with live debit/credit totals (the previous form could only post two lines and lost all input on error).
- **`finance/accounts.php`:** creating an account requires `finance create`; editing, deactivating and reactivating require `finance edit`; the form and row controls are hidden for viewers. Account type is whitelisted, code/name are trimmed and length-checked, parents must exist and be active, parent changes are cycle-checked, and an account's code and type are locked once it has journal lines or a nonzero balance (changing a type flips the sign convention used by the balance sheet). Deactivation is refused — with a specific reason — when the account has a nonzero balance, existing journal lines, or active children, because all reports filter `status = 1` and removing a valued account breaks `Assets = Liabilities + Equity`. Adds CSV export, print styling, a show-inactive toggle and a read-only view for viewers.
- **`finance/cashbook.php` rewritten.** The closing balance **subtracted `income` rows instead of adding them** (income was counted as money out), so the running balance and closing figure were wrong. Inflows are now `receipt` + `income`, outflows `payment` + `expense`; dates are validated and swapped, the type filter is whitelisted, and the page gained quick ranges, KPI cards, a running-balance column, an empty state, CSV export, print output and proper escaping.
- **`finance/journal.php` / `finance/journal_view.php`:** flash messages were set but never rendered on the register, the "New Journal Entry" button ignored permissions, and the detail page always showed a green status badge, took its footer totals from the header row instead of the lines, and returned a bare `die()` for a missing entry. The register now has search/date filters, ledger-balanced KPI, a 200-row notice, correct badges, CSV and print; the detail page has status-aware badges, line-derived totals with an explicit imbalance/mismatch warning, created/posted-by metadata, per-line general-ledger links, print styling and a styled 404.
- **`finance/unpaid_payroll.php`:** `?month=abc` crashed with a `TypeError` from `mktime()` (month/year are now clamped ints), payment POST requires `finance create` and runs before the header with PRG + flash, the selected payroll row is locked `FOR UPDATE` and only payable when `payment_status` is blank/pending/unpaid (double-payment guard), date/method/reference are validated, the payment and its cashbook entry are written in one transaction, and the action is logged. Adds KPIs (including 30-day overdue), a data-attribute modal (the old inline `onclick` broke on quotes), and hides payment controls from viewers.
- **`finance/attendance_review.php`:** approve/reject now require `finance approve` (was view-only), reject only works from `pending` and approve only from a non-approved state (with `rowCount()` checks before notifying), the period query uses a date range instead of `MONTH()/YEAR()` (index-friendly), month/year are clamped, actions use flash + PRG and `log_activity`, and the page gained KPIs, status badges, a print layout and a help panel.
- **`finance/general_ledger.php`:** removed a stray `+` before the `@media` rule and `</style>`, which invalidated the page's entire responsive/print stylesheet.
- **Navigation:** the Finance menu linked "Payroll Generation" and "Unpaid Payroll" to HR-gated pages, so finance-only users saw links that returned 403; both entries are now shown only with `hr view`. Added `.btn-danger` to `assets/css/advanced.css` for destructive actions.
- Verified with `php -l` on every touched file and **444 assertions across 9 suites** (cashbook 17, journal 35, accounts 56, payroll + attendance 64, payroll approval 57, loan approval 57, trial balance 38, journal view 34, cross-page smoke/permissions 86), covering validation, permissions, double-submit guards, CSV output, ledger-vs-cache figures, styled 404s and XSS escaping. The database was restored to its original state after each run (test payroll rows, loans, journals, notifications, activity rows and temporary users removed).

### 📦 Inventory Module — Missing Ledger, Permission & Valuation Fixes

- **The `finished_stock_transactions` table did not exist.** `inventory/finished_movement.php` crashed with a fatal error on every load, and `log_finished_stock_transaction()` failed *silently*, so every finished-goods movement from production runs, sales invoices and stock adjustments was being discarded with no record at all. New `ensure_finished_stock_transaction_schema()` helper (static-guarded, mirrors `stock_transactions` with block-type/user foreign keys) is now called at the page top of `sales/save.php`, `production/save.php`, `inventory/finished_adjust.php` and `inventory/finished_movement.php`. The DDL is deliberately **outside** those save transactions — running it inside would implicitly commit them mid-write.
- **Movement history backfilled (69 rows):** 28 production receipts + 41 sales issues reconstructed from `production.good_quantity` and `sale_items.quantity`, each tagged `Backfilled from …`. The reconstruction was verified first — `produced − sold = on_hand` for every block type — and after backfilling the sum of movements still equals on-hand stock exactly (0 / 500 / 100), and continues to reconcile after live sales post on top. Only the new table was written; no stock quantity was touched. The script is one-off and aborts if re-run.
- **Permission holes on write endpoints:** `inventory/adjustments.php` and `inventory/reorder.php` required only `inventory view`, so any read-only user could move stock or change reorder points; POST handling now requires `inventory edit`, the forms and action buttons only render for editors, and `inventory/finished_adjust.php` already required edit. End-to-end test with a temporary view-only department user: form hidden, POSTed adjustments/reorder/deactivate all rejected with stock and row counts unchanged, `finished_adjust.php` returned 403, and all read pages still returned 200.
- **`inventory/materials.php` allowed any viewer to change material master data** — including `unit_cost`, which drives weighted-average production costing and inventory valuation. Add/update/deactivate now require `inventory edit` (the form and per-row buttons are hidden otherwise), the GET-based deactivate is gated and logged, mutations run *before* the header include and redirect (PRG) so refreshing can't re-submit them, and add now rejects negative opening stock and negative unit cost (the edit path already rejected negative cost; the add path did not).
- **`inventory/adjustments.php` validation:** adjustment type whitelisted to `increase|decrease`, quantity must be finite and > 0, date must match `YYYY-MM-DD`, reason required, material must exist and be active (`FOR UPDATE`), the decrease is guarded both in the UPDATE (`current_stock + ? >= 0`) and by affected-row count so stock can't go negative, and `stock_adjustments` + `stock_transactions` audit rows are written in one transaction with `log_activity`.
- **`inventory/dispatch.php`:** status whitelisted to `scheduled|in_transit|delivered|delayed` (was stored verbatim), dispatch date validated, rejected input now shows an error flash instead of failing silently, and a posted invoice id must actually exist. New per-row actions: advance/correct a dispatch's status (previously a dispatch could never be updated without duplicating the row) and a delete button gated on `inventory delete`.
- **`inventory/low_stock_alert.php` fatal:** `current_stock / reorder_point` threw a `DivisionByZeroError` in PHP 8 when a reorder point was 0 (the ORDER BY had the same division). Both are guarded, and the "Create PO" / "Order Now" links now only render with `purchases/create`.
- **Broken deep link fixed:** `low_stock_alert.php` links to `reorder.php?material=ID`, which the page ignored; it now opens that material's modal (bogus ids are ignored), so view-only users still can't reach it.
- **`inventory/movement.php` displayed almost every row wrong:** the real transaction types are `purchase_receipt` (28) and `production_issue` (84) — 112 of 120 rows — but the colour switch only knew `purchase`/`production`/`in`/`out`, so nearly everything rendered as a grey dot under a legend naming types that don't exist. The switch now covers the real values (plus legacy aliases) and the legend matches. Added a material filter, period In/Out totals, quick range presets (this month / last month / all time), date validation with swap, a row-limit notice, an `OPENING` type colour/legend entry, an inline CSV export and a print layout.
- **Movement/finished-goods reporting parity:** `inventory/finished_movement.php` gained quick range presets (data spans months while the page defaulted to the current one), an inline CSV export, a print layout and a "showing the 300 most recent" notice; `inventory/index.php` (Finished Stock) gained a print/CSV export, a cost-value column and KPI alongside the retail figures, and a `production create`-gated "New Production" button.
- **Valuation corrected — cost vs retail were mixed.** `analytics.php` summed raw materials *at cost* plus finished goods *at selling price* and called the result "inventory value", and the overview KPI disagreed with its own doughnut chart. `api/inventory_charts_data.php` now reports `finished_cost` (weighted-average `average_unit_cost`) and `finished_retail` separately plus `total_cost`/`total_retail`, the chart is cost-consistent on both slices, and overview/analytics label the basis explicitly (cost, retail, unrealised margin) with the accounting number — total inventory at cost — surfaced as its own KPI. Legacy JSON keys are preserved.
- Verified: `php -l` clean on every touched file; all 11 inventory pages plus the charts API return 200 with no warnings/fatals; 25+ functional tests (adjustments, materials, dispatch, low-stock deep link, finished movement CSV, movement CSV/totals) pass with the database restored to its original state after each run.

### 🏭 Production Module — Permission Fixes & Register

- **Security fix:** `production/targets.php` and `production/quality.php` were gated only on `production view`, so any read-only user could POST and create production targets and quality inspections (and the forms were shown to everyone). Creating and deleting now requires `production create`; the forms and per-row delete buttons only render for users with that permission, invalid input produces an error flash instead of silently re-rendering, and activity is logged.
- **Validation:** quality-check `result` is whitelisted to `pass|fail|pending` (a posted value like `HACKED` was stored verbatim and could break the badge styling), and target/check dates must be real `YYYY-MM-DD` dates. Schedule `priority` is whitelisted to `low|normal|high|urgent` and schedule `status` to `planned|in_progress|completed|cancelled`.
- `production/schedule.php`: the status update was nested inside the `create` permission check, so a user with `production edit` but not `create` couldn't update a schedule; each action now checks its own permission. The `from`/`to` date filters are validated (swapped when reversed), flashes are displayed, and an empty-state row was added.
- **New production register** (`production/index.php`): filters (production number search, block type, date range), KPI cards (runs shown, good blocks, rejection rate, cost of runs shown, cost MTD, good blocks today, scheduled jobs), an inline CSV export (guarded by `production/view`) and a print-friendly layout. The page previously dumped 200 rows with no filtering or totals.
- `production/view.php`: styled 404 for an unknown run (was a bare redirect), a cost breakdown panel (materials / direct labor / overhead / total / cost per good block), a material-cost total row, quality checks linked to the run, and a print button.
- `production/create.php`: the unit-cost input was editable but silently ignored by `save.php` (valuation always uses the raw-material weighted-average cost), so it is now read-only with a tooltip explaining the source.
- Production navigation now includes a permission-gated "New Run" link and orders entries as orders → new run → planning → schedule → targets → quality → BOM → analytics. New language key `new_production_run` (English/Amharic).

### 🛒 Purchasing Module — Register, Filters & Approval Fixes

- `purchases/view.php`: styled 404 for an unknown purchase order (was a bare redirect).
- **New purchase register filters** (`purchases/index.php`): search (PO number or supplier), supplier, payment status, approval status and date-range filters, plus KPI cards (POs shown, total value, payables, paid), an empty state, a print layout and an Export CSV button (`purchases/export_csv.php`, guarded by `purchases/view`).
- **Rejected purchase orders were counted as money owed.** Rejection only set `approval_status='rejected'` and left `balance = total_amount` and `payment_status='credit'`, so every rejected PO inflated the payables KPI and the AR/AP aging report — 7 rejected POs showed **1,185,600** of phantom payables against **0.00** real. Rejection now zeroes `paid_amount`, sets `balance = 0` and flags `payment_status = 'cancelled'`, and the existing rejected rows were corrected (rejected with balance > 0: 7 → 0; payables: 1,185,600 → 0).
- The "Request Purchase Approval" dropdown and the pending approval queue only excluded `approved` POs, so rejected POs kept showing up and could be re-approved; both queries now exclude `rejected` as well (`NOT IN ('approved','rejected')`).

### 📈 AR Aging, Customer Statement, Pipeline Drag & Drop, Multi-line Invoices

- `reports/aging.php` upgraded: as-of date, summary/detail views, per-customer AR and per-supplier AP bucket tables (0–30 / 31–60 / 61–90 / 90+), invoice and purchase detail rows linked to their documents, KPI cards, totals rows, a print layout and an inline CSV export; a bad customer id renders the styled 404.
- New `reports/customer_statement.php`: customer and date-range statement with opening balance (opening balance + invoices − payments before the range), invoice and payment rows with a running balance, totals, inline CSV and print output; linked from the Reports navigation and from `customers/view.php`.
- `crm/pipeline.php`: deals can be **dragged and dropped** between stages (optimistic UI move, per-column counts, revert on failure) through a small JSON endpoint `crm/pipeline_move.php` that validates the stage, updates status, logs activity and returns proper 401/405/422 JSON for API callers; the stage `<select>` remains as a no-JS fallback.
- **Multi-line sales invoices:** `sales/create.php` now supports adding/removing product lines with a per-line stock indicator and a live totals panel (product lines, total quantity, subtotal, logistics, discount, total, balance), and Convert-to-Sale pre-fills **all** quotation lines instead of only the first. `sales/save.php` accepts the posted arrays (falling back to the legacy single-line fields), validates per line, aggregates stock checks so repeated lines can't overdraw, and writes one sale, N sale items, one SALE journal and one aggregated COGS journal.
- New language keys `customer_statement`, `subtotal`, `total_quantity`, `product_lines`, `no_line_items`, `remove` (English/Amharic).

### 🔄 HR → Finance Attendance Hand-off (Payroll Gate)

- New HR page `hr/attendance_submit.php` ("Submit Attendance to Finance"): HR submits a month's attendance to finance, with a period summary, submission status and history.
- Payroll generation (`finance/payroll.php`) is now blocked until HR has submitted attendance for the period **and** finance has approved it. A status banner and the Generate button reflect the gate.
- `finance/attendance_review.php` is now review-only: finance approves or rejects HR submissions (rejections notify HR to resubmit). The old "Submit to Finance" form was removed from the finance page.
- Fixed: payroll already in a final stage (transferred/approved/paid) was still regenerated on submit; generation is now truly skipped.
- New shared helpers in `config/helpers.php`: `ensure_attendance_submission_schema()`, `get_attendance_submission()`, `attendance_approved_for_period()`.
- HR navigation now includes "Submit Attendance to Finance".

### 📊 Payroll Report

- New `reports/payroll.php` ("Payroll Report"): per-employee payroll detail for a selected month/year (basic, allowances, overtime, deductions, net, workflow status, paid/transferred date) with department subtotals and a grand total, plus a month-by-month summary for the selected year.
- Filters: month, year, department, workflow status and employee (name or code) search. The page shows the HR → finance attendance gate status for the selected period, and amounts are shown in the configured currency.
- Export CSV (`reports/export_csv.php?type=payroll`) and print-friendly output; linked from the Reports navigation and from `finance/payroll.php`.
- New language keys `payroll_reports` (English/Amharic).

### 🧾 Employee Payslip & Bank Transfer Schedule

- New `finance/payslip.php`: printable single-employee payslip for a payroll record (earnings — basic, allowances, overtime with hours; deductions — other and loan recovery; gross and net pay; payment details and a receipt/signature block). Reachable by payroll id or by employee + month/year, with auto-print support, available to HR, finance and reports viewers.
- New `finance/payroll_bank.php` ("Bank Transfer Schedule"): finance payment instruction list for a period (employee, bank account, net pay, status, transfer date, reference) with KPI cards, a missing-bank-account count, CSV export for bank upload and print output.
- Payslip links added to the payroll report rows (`reports/payroll.php`) and the payroll generation table (`finance/payroll.php`); the bank schedule is linked from `finance/payroll.php`.
- New language keys for the payslip, bank schedule and payroll labels (English/Amharic).

### 📒 Payroll Journal Posting (period close)

- New `finance/payroll_journal.php` ("Payroll Journal"): books one payroll period into the general ledger as a single balanced double-entry — gross pay is expensed, and the credits settle net pay from cash, reduce employee loan recovery, and hold back withheld deductions. The page shows a live preview of the entry, the period totals and account mapping before posting.
- Posting is idempotent per period (entry number `PAYROLL-YYYY-MM`), so a period can never be double-booked; an already-posted period shows a link to the posted entry instead of the post button. Posting requires finance create permission; finance/HR viewers can preview.
- Account mapping is selectable (salary expense, payment/cash account, loan account, deductions account) with sensible defaults; new shared helpers `ensure_payroll_journal_accounts()` and `payroll_period_totals()` in `config/helpers.php` keep the entry balanced off the stored gross and reuse `post_accounting_journal()`.
- Added `finance/payroll_journal.php` to the Finance navigation and a "Payroll Journal" action to `finance/payroll.php`.

### 🧾 Payslip Run, Print & Email

- `finance/payslip.php` now renders a whole period in one pass (a "payslip run") with page breaks, so an entire payroll can be printed to PDF in a single print job; the original single-employee payslip is unchanged.
- Opening `finance/payslip.php` with no parameters now shows a period picker instead of a 404; the picker is reachable from the new "Payslip Run" HR navigation entry.
- Batch runs can email every payslip through the application mailer (`send_email_notification()`), reporting how many were sent and how many were skipped for missing email addresses, and the whole run is linked from `finance/payroll.php` ("Print All Payslips").
- New language keys for the payslip run, journal, and account labels (English/Amharic).

### 🚚 Gate Module — Clearance Register & Audit Fixes

- New `gate/register.php` ("Clearance Register"): the full, filterable history of gate clearances (the guard dashboard only lists the live queue and the last few exits). Filter by status, date range and free text (clearance number, plate, invoice, customer or driver); KPI cards show total, exited, open, open > 24h, holds and average dwell time.
- The register shows the full lifecycle (opened, loaded, exited, printed), per-row dwell time from loading to exit, a print-friendly layout and an inline CSV export; linked from the Gate dashboard and the Gate Exit Report.
- `reports/export_csv.php` gained `type=gate` (status/date/search aware); the Gate Exit Report (`reports/gate.php`) now offers CSV export and links to the register.
- Gate dashboard (`gate/index.php`) now shows "Open > 24h" and "Average dwell (30 days)" KPIs and a link to the register.
- `gate/clearance_print.php` now records `printed_at` on first print (surfaced in the register and both CSV exports), making the previously unused column meaningful.
- Replaced the bare `die('Clearance not found.')` on gate pages with a shared, styled 404 (`render_not_found()` in `config/helpers.php`).
- New language keys for the register, gate statuses, filters and the 404 page (English/Amharic).

### 🤝 Sales & CRM — Register, Conversion & Audit Fixes

- New Sales register (`sales/index.php`): filters (invoice/customer search, payment status, gate clearance status, date range), KPI cards (invoices shown, invoiced value, collected, outstanding), an inline CSV export (guarded by `sales/view`) and a print-friendly layout. Previously the page dumped every invoice with no filtering or totals.
- Fixed the broken "Convert to Sale" flow: `quotations/*` linked to `sales/create.php?quotation=ID`, but the sale form ignored the parameter and opened blank. `sales/create.php` now pre-fills the customer, product, quantity, unit price and discount from the quotation (with a notice when the quotation has multiple lines, since an invoice records one product line), and `sales/save.php` marks the source quotation **accepted** on save. Also added `?customer=ID` pre-fill so a customer page or a won deal can start a new sale.
- Quotations module hardening: `quotations/index.php`, `create.php` and `view.php` now bootstrap the database/helpers explicitly and require the existing `sales` permission (view for reading, create for new quotations, edit for status changes) instead of being open to any logged-in user; input is validated (quotation date, at least one line item), the list gained status/search filters, KPI cards and empty states, and an invalid id returns the styled 404.
- CRM pipeline (`crm/pipeline.php`): fixed a permission bug where moving a deal's stage was nested inside the `crm create` check even though the board only requires `crm edit`, so editors without create saw a silent no-op. Stage changes, opportunity editing, reopen and delete are now separate, correctly-gated actions. Added: a "Closed deals" table for won/lost opportunities with reopen and delete, per-card inline editing (name, value, probability, expected close, owner, notes), overdue highlighting, an "overdue" KPI, and a "Won value" KPI.
- Sales commissions (`crm/commissions.php`): filters (rep, status, invoice, sale-date range), KPI cards (total, paid, due) and an inline CSV export; the "Add commission" form now validates the sale and rep, and the page shows flash messages.
- Customer feedback (`crm/feedback.php`): validated input (customer/date/rating ranges) with flash messages; added filters (customer, minimum rating, date range, text search), KPI cards (responses, overall/product/service/delivery averages) and an inline CSV export.
- Small fixes: `crm/delivery_print.php`, `sales/view.php`, `sales/vehicle.php` and `quotations/view.php` no longer `die()`/`exit()` with a bare message — they return the shared styled 404 (`render_not_found()`); the customer detail invoice link now opens the specific invoice (`sales/view.php?id=`) instead of the invoice list; `customers/view.php` gained a "+ New sale" action.
- New language keys `new_quotation` (English/Amharic) plus pipeline/opportunity labels.

### 🐛 Fixes

- `finance/payroll_print.php` was missing (404) even though `finance/payroll.php` links to it for the "Print Admin Sign-off" / post-generation print. Added a finance entry point that renders the shared sign-off template, made `hr/payroll_print.php` path-independent, and pointed its "Back to Payroll" link at the finance payroll page.
- `/block_plant/hr/payroll.php` (HR nav, HR overview button, and older notification links) returned 404 because the page was never created after payroll generation moved to `finance/payroll.php`. Added a permission-checked `hr/payroll.php` that redirects to `finance/payroll.php` preserving the `month`/`year` query, so existing stored notification links work too.
- `finance/payroll.php` used the undefined language keys `overtime_hours`, `overtime_payment` and `total_overtime_payment`, so the payroll table and summary exposed raw key names; added English/Amharic entries.
- `dashboard/search.php` linked supplier results to the non-existent `suppliers/view.php` (404); the supplier name now links to the suppliers directory.
- `index.php` still carried ~470 lines of unreachable duplicate dashboard code (with broken `../partials/...` includes and `../module/...` links) after its redirect; reduced to a clean entry point that redirects to `dashboard/index.php`.
- Hard-coded English nav labels for "Submit Attendance to Finance", "Attendance Review" and "Payroll Generation" are now translated.
- Removed the leftover root development scripts `fix.php`, `test_db.php`, `scratch_test.php`, `scratch_fix.php` and the stale, unauthenticated `system_status.php`; the root now contains only `index.php`, and the now-unneeded root `.htaccess` deny rules were removed as well.

## [2.0.0] - 2024 - Full ERP Edition

### 🎉 Major Release - Complete ERP System

This is a complete rewrite and expansion from v1.0, transforming a basic block plant management system into a comprehensive enterprise resource planning solution.

### ✨ New Modules Added

#### HR & Payroll Module
- Employee management with full profiles
- Daily attendance tracking with clock in/out
- Leave management system with approval workflow
- Monthly payroll processing with allowances and deductions
- Employee reports and analytics

#### Fixed Assets Module
- Asset registration and tracking
- Asset categories with depreciation rates
- Maintenance scheduling and tracking
- Depreciation calculation and reports
- Asset lifecycle management

#### Quotations Module
- Create and manage customer quotations
- Convert quotations to sales orders
- Track quotation status (pending/accepted/rejected)
- Expiry date management
- Professional quotation printing

#### Advanced Inventory Features
- Stock adjustments (increase/decrease)
- Reorder level management
- Low stock alerts and warnings
- Material requirement planning
- Stock valuation reports

#### Financial Management
- Complete chart of accounts
- Journal entry system
- Cash book with running balance
- Financial statements (P&L, Balance Sheet)
- Expense categorization and tracking

#### Comprehensive Reports
- Sales reports with filtering
- Purchase reports by supplier
- Production efficiency reports
- Inventory valuation reports
- Financial statements
- Aging analysis (receivables/payables)
- Custom date range filtering

#### System Settings
- Company information management
- User management with roles
- Block types configuration
- Database backup and restore
- System preferences

### 🔧 Enhanced Features

#### Dashboard
- Real-time KPIs and metrics
- Today's vs monthly performance
- Recent activities feed
- Low stock warnings
- Quick action shortcuts
- Advanced widgets page

#### Production Management
- Production planning module
- Demand forecasting
- Days of stock calculation
- Material availability checking
- Quality metrics tracking

#### Navigation
- Professional header with dropdown menus
- Organized module grouping
- User profile display
- Reusable header/footer partials
- Mobile-responsive menu

#### User Interface
- Modern, clean design
- Consistent styling across modules
- Color-coded status indicators
- Print-friendly layouts
- Responsive grid layouts
- Professional forms

### 📊 New Reports
1. Sales Reports - By date, customer, with totals
2. Purchase Reports - By supplier, payment status
3. Production Reports - Efficiency, quality, costs
4. Inventory Reports - Stock valuation, materials
5. Financial Reports - P&L, expense breakdown
6. Aging Reports - Customer/supplier aging analysis

### 🗄️ Database Changes
- Added 15+ new tables
- Enhanced existing tables with new fields
- Added foreign key constraints
- Improved data integrity
- Transaction support
- Audit trail fields (created_by, created_at)

### 📚 Documentation
- Complete README.md (500+ lines)
- Detailed FEATURES.md (1000+ lines)
- Quick Start Guide
- Enhanced installation instructions
- System overview document
- Comprehensive user guide

### 🔒 Security Improvements
- Enhanced password hashing (bcrypt cost 12)
- SQL injection prevention (prepared statements)
- XSS protection (htmlspecialchars)
- Session management improvements
- User activity tracking
- Role-based access control framework

### 💻 Technical Improvements
- Modular code structure
- Reusable components
- Better error handling
- Transaction management
- Optimized queries
- Clean code practices

### 🎨 UI/UX Improvements
- Consistent color scheme
- Professional styling
- Better form layouts
- Visual status indicators
- Improved table designs
- Better mobile responsiveness

---

## [1.0.0] - Initial Release

### Features
- Basic sales management
- Purchase order system
- Production tracking
- Inventory management
- Customer & supplier databases
- Basic expense tracking
- Simple dashboard
- User authentication

### Modules
- Dashboard
- Sales
- Purchases
- Production
- Inventory
- Customers
- Suppliers
- Expenses

### Database
- 15 core tables
- Basic relationships
- Simple schema

---

## Version Comparison

### v1.0 vs v2.0

| Feature | v1.0 | v2.0 |
|---------|------|------|
| Modules | 8 | 10 |
| Database Tables | 15 | 30+ |
| Features | ~100 | 300+ |
| Reports | 0 | 6 types |
| Documentation | Basic | Comprehensive |
| HR Module | ❌ | ✅ |
| Assets Module | ❌ | ✅ |
| Financial Accounting | ❌ | ✅ |
| Quotations | ❌ | ✅ |
| Reorder Management | ❌ | ✅ |
| User Management | Basic | Advanced |
| Backup System | ❌ | ✅ |
| Production Planning | ❌ | ✅ |
| Print Invoices | ❌ | ✅ |

---

## Upgrade Notes

### Upgrading from v1.0 to v2.0

**⚠️ Important: This is a MAJOR upgrade with breaking changes**

1. **Backup your data first!**
   - Export existing database
   - Save all files
   - Create backup copy

2. **Database Migration**
   - Import new database.sql
   - This will add new tables
   - Existing data will be preserved in compatible tables

3. **File Structure Changes**
   - New folders: hr/, assets/, reports/, settings/, partials/
   - All existing files retained
   - New files added

4. **Configuration**
   - No changes to database.php
   - New settings in Settings module
   - Configure company information

5. **New Features Setup**
   - Add employees in HR module
   - Configure asset categories
   - Set reorder levels
   - Configure company settings

### Migration Checklist

- [ ] Backup existing database
- [ ] Backup existing files
- [ ] Import new database schema
- [ ] Copy new files to server
- [ ] Test login functionality
- [ ] Verify existing data
- [ ] Configure new modules
- [ ] Train users on new features
- [ ] Update documentation for users

---

## Future Roadmap

### Planned for v3.0
- Multi-location support
- Advanced analytics dashboard
- Email notifications
- SMS integration
- Barcode scanning
- Mobile app
- API for third-party integration
- Advanced reporting with charts
- Document management
- Workflow automation

### Under Consideration
- Multi-currency support
- Multi-language interface
- Cloud backup integration
- Real-time notifications
- Advanced permissions system
- Customer portal
- Supplier portal
- Mobile app for attendance
- Biometric integration
- Vehicle tracking

---

## Support

For issues, questions, or feature requests:
1. Check the documentation (README.md, FEATURES.md)
2. Review the troubleshooting section
3. Verify your installation
4. Check system requirements

---

## Credits

**Development Team**: Block Plant ERP Team
**Version**: 2.0 Full ERP Edition
**Release Date**: 2024
**License**: Commercial Use Ready

---

**Thank you for using Block Plant ERP!** 🧱
