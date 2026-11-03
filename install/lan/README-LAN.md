# Block Plant ERP — LAN Deployment Guide

This kit turns the PC where the application is already installed into the **server**
for the office network, and gives every other PC a one-click shortcut to it.

```
                    Office LAN (192.168.5.x)
                            |
        +-------------------+-------------------+
        |                   |                   |
   Server PC           Client PC 1         Client PC 2
   192.168.5.96        (browser)           (browser)
        |
   Apache (port 80)  ->  http://192.168.5.96/block_plant/
   MySQL (127.0.0.1 only)
   G:\xampp\htdocs\block_plant
```

There is **one** copy of the application and **one** database — on the server.
Client PCs install nothing. They only get a shortcut that opens the server in a
browser window.

---

## Current server values

| Item | Value |
|---|---|
| Server LAN IP | `192.168.5.96` |
| Server host name | `DESKTOP-4C855ET` |
| Application URL | `http://192.168.5.96/block_plant/` |
| Backup URL (by name) | `http://DESKTOP-4C855ET/block_plant/` |
| Application folder | `G:\xampp\htdocs\block_plant` |
| Database | `block_plant` (MySQL, user `root`, empty password) |
| Default login | `admin` / `password` — **change it** |

The URL must keep the `/block_plant/` path: 264 links inside the application are
written with that path.

---

## Before you start

- The server PC must stay **switched on** and connected to the office network
  while people use the system.
- XAMPP must be installed on the server PC (it already is).
- You need **Administrator** rights on the server PC for step 1.
- Note the server's IP address. If the router hands out a different address later,
  the client shortcuts stop working — see *Changing the server address* below.

---

## Step 1 — Prepare the server

Double-click **`Setup-LAN.bat`**. Windows asks for permission; accept it.

The script:

1. Confirms **Apache2.4** and **mysql** are running and set to start automatically.
2. Opens **TCP port 80** in Windows Firewall for Domain and Private networks.
3. Makes **MySQL listen on `127.0.0.1` only**, so other PCs cannot reach the
   database directly. The web pages still work because Apache talks to MySQL
   locally. `my.ini` is backed up first.
4. Tests the application on `localhost`, the LAN IP and the host name, and prints
   a summary of everything it changed.

Run it again at any time — it is safe and only fixes what is missing.

Useful switches:

```powershell
.\Setup-LAN.ps1 -SkipMySqlHardening     # leave MySQL as it is
.\Setup-LAN.ps1 -SkipFirewall          # do not touch firewall rules
.\Setup-LAN.ps1 -XamppPath "C:\xampp"  # XAMPP is somewhere else
```

> **Note on the network profile.** If Windows marks the office network as
> *Public*, the firewall rules for Domain/Private do not apply. Set it to
> *Private* in **Settings > Network & Internet > Ethernet > Network profile**.

---

## Step 2 — Install the database

Double-click **`Install-Database.bat`** and choose:

| Choice | What it does |
|---|---|
| **1 — Current company data** | Replaces the database with `database\block_plant_full.sql`: every employee, attendance row, payroll run, leave request, loan, sale, purchase and accounting entry as they are now. |
| **2 — Clean, empty system** | Erases the database and installs `database\block_plant_clean.sql`: all 82 tables, the default `admin` login and the starter master data only. No employees, no transactions. |
| **3 — Backup only** | Changes nothing; writes a timestamped dump to `backups\`. |

Before choices 1 and 2 the script **always writes a safety backup** of the
existing database to `backups\`. If the import fails, the old data is still
there.

You are asked to type `YES` (full) or `ERASE` (clean) so the install cannot run
by accident.

After the import the script verifies the table count, user count and employee
count and reports the result.

### Restoring a backup

```bat
"G:\xampp\mysql\bin\mysql.exe" -u root < "G:\xampp\htdocs\block_plant\install\lan\backups\block_plant_before_Full_20261030-120611.sql"
```

The dump contains `CREATE DATABASE IF NOT EXISTS` and `USE`, so it restores the
whole `block_plant` database in one step. It can also be imported through
phpMyAdmin.

---

## Step 3 — Build the client package

Double-click **`Create-Client-Package.bat`**.

It detects the server IP, writes the client files into `client\`, and creates
**`Block-Plant-Client.zip`**.

The generated files:

| File | Purpose |
|---|---|
| `Install-Client.bat` | What the client double-clicks |
| `Install-Client.ps1` | Checks the server, creates the shortcuts |
| `Block Plant ERP.url` | Plain browser shortcut (fallback) |
| `server-url.txt` | The server address, used by every script |
| `Test-Connection.bat` | Shows whether the server answers |
| `Uninstall-Client.bat` | Removes the shortcuts again |
| `README.txt` | Short instructions for the client |

If the IP is wrong, pass the right address:

```powershell
.\Create-Client-Package.ps1 -ServerUrl "http://192.168.5.96/block_plant/"
```

---

## Step 4 — Set up each client PC

1. Copy `Block-Plant-Client.zip` (or the `client\` folder) to the client PC —
   by USB stick, or through a shared folder on the server.
2. Extract it, for example to `C:\BlockPlantClient`.
3. Double-click **`Install-Client.bat`**.
4. It tests the connection, then creates **Block Plant ERP** on the Desktop and
   in the Start Menu.
5. Double-click the shortcut and sign in.

The shortcut opens Microsoft Edge (or Chrome) in **app mode** — a clean window
with no tabs or address bar. If neither browser is found, a normal `.url`
shortcut is created instead.

> The kit files live under `install\lan\`, which is blocked from the web by
> `.htaccess` because the SQL dumps contain company data. That is why the client
> package is copied physically and not downloaded from the browser.

---

## Daily operation

- **Start the day:** the server PC is on and signed in; Apache and MySQL start
  automatically. Nothing else to do.
- **Stop the day:** shut down the server PC normally. Clients lose access until
  it is back.
- **Backups:** run `Install-Database.bat` → choice 3 whenever you want a copy of
  the data. Keep the `backups\` folder on an external disk as well.
- **Uploaded files:** employee photos and documents live in
  `G:\xampp\htdocs\block_plant\uploads\`. A database backup does **not** contain
  those files — copy that folder too when archiving.

---

## Troubleshooting

| Symptom | Check |
|---|---|
| Client says *"Cannot reach the server"* | Server PC switched on? On the office network? `Test-Connection.bat` on the client. |
| Client browser shows *"site can't be reached"* | Windows Firewall on the server. Re-run `Setup-LAN.bat`. |
| Client reaches the server but gets a database error | MySQL service stopped on the server. Start `mysql` in the XAMPP control panel or `services.msc`. |
| Shortcut opens an old address | The server IP changed. Re-run `Create-Client-Package.bat` on the server and reinstall the client package. |
| Only some PCs can connect | The working PCs are on the office LAN; the failing one may be on guest Wi-Fi or mobile data. |
| Page loads but is very slow | Server PC is on Wi-Fi with a weak signal, or the network cable is unplugged. |
| *"Access denied"* after install | Normal — that is the login page. Sign in with your own account. |

Quick checks on the server:

```powershell
Get-Service Apache2.4, mysql                    # both should be Running
Get-NetTCPConnection -State Listen -LocalPort 80
Invoke-WebRequest http://192.168.5.96/block_plant/ -UseBasicParsing
```

---

## Security checklist

- [ ] **Change the default `admin` password** (Settings > Users) right after the
      first login, and remove any test accounts.
- [ ] MySQL is bound to `127.0.0.1`, so the database is not reachable from the
      network. Verify with `netstat -ano | findstr :3306` — only `127.0.0.1`.
- [ ] Do not forward port 80 on the router to this PC. The application is meant
      for the office LAN, not the internet.
- [ ] Keep `install\lan\` and `install\lan\backups\` off any shared folder that
      other users can browse.
- [ ] Give each person their own login with the modules they need, instead of
      sharing `admin`.
- [ ] The XAMPP `root` MySQL account has no password. It is safe while MySQL is
      local-only; set a password if you ever change the bind address.

---

## Changing the server address

If the server PC gets a new IP address:

1. Run `Create-Client-Package.bat` again on the server — it picks up the new IP.
2. Copy the new `client\` folder to each PC and run `Install-Client.bat` again.

To avoid this in the future, ask the router administrator to reserve
`192.168.5.96` for this PC, or use the host name URL
`http://DESKTOP-4C855ET/block_plant/`, which follows the PC rather than its IP.

---

## Files in this kit

```
install\lan\
  START-HERE.txt                Short plain-text version of these three steps
  README-LAN.md                 This guide
  Setup-LAN.bat                 Server setup launcher (asks for Administrator)
  Setup-LAN.ps1                 Firewall, services, MySQL binding, URL tests
  Install-Database.bat          Menu: full data / clean / backup
  Install-Database.ps1          Import, safety backup and verification
  Create-Client-Package.bat     Builds the client package
  Create-Client-Package.ps1     Writes client\ and Block-Plant-Client.zip
  Block-Plant-Client.zip        Ready-to-copy package for client PCs
  database\
    block_plant_full.sql        Full dump with the current company data
    block_plant_clean.sql       Clean install: tables + default admin + master data
  client\
    Install-Client.bat/.ps1     Client setup
    Test-Connection.bat/.ps1    Connection test
    Uninstall-Client.bat/.ps1   Remove the shortcuts
    Block Plant ERP.url         Browser shortcut
    server-url.txt              Server address
    README.txt                  Client instructions
  backups\                      Safety backups written by Install-Database
```

---

## What the application still needs on the server

| Component | Current setting |
|---|---|
| Apache | `Listen 80`, `DocumentRoot G:/xampp/htdocs`, `AllowOverride All` |
| PHP | 8.x from XAMPP, extensions already enabled |
| MySQL | database `block_plant`, 82 tables, `utf8mb4` |
| Time zone | `Africa/Addis_Ababa` is set by the attendance reminder; PHP default is `Europe/Berlin` — change `date.timezone` in `php.ini` if dates look off |
| Cron (optional) | `cron\attendance_reminder.bat` for automatic attendance reminders |
