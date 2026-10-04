# Hosting EVORA

This guide moves EVORA from XAMPP to a web host (shared hosting with cPanel/hPanel, or a VPS).
Requirements: **PHP 8.1+** (8.2 recommended) with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `gd`;
**MySQL 8 / MariaDB 10.4+**; **HTTPS**; **cron jobs**.

## 1. Folder layout

Keep the code and every secret **outside** the public web folder. Only `public/` is served.

```
/home/USER/
├── evora/                  ← the whole EVORA project (config, core, modules, vendor, storage, …)
│   └── public/             ← the ONLY folder the web server serves
├── evora_secrets/          ← evora.ini, paypal_real.ini, mail.ini, google.ini   (private)
└── evora_backups/          ← automatic database backups                          (private)
```

Point the domain's **document root** to `/home/USER/evora/public`
(hPanel/cPanel: *Domains → document root*; Apache VPS: `DocumentRoot`).

If the host only allows `public_html`, put the project at `/home/USER/evora`, then make
`public_html` a symlink to `/home/USER/evora/public` (or copy `public/` into `public_html` and
change the two `__DIR__ . '/../…'` require paths at the top of `public/index.php` and
`public/uploads_guard.php` to `/home/USER/evora/…`). Never put `evora_secrets` inside `public_html`.

## 2. Upload

1. Upload the project folder **including `vendor/`** (or run `composer install --no-dev` on the host).
2. Do **not** upload `storage/logs/` contents, local backups or any `*.ini` with secrets into the project.
3. Make `storage/` writable by PHP (`chmod -R 775 storage` on Linux).

## 3. Database

1. Create a database and a database user with a strong password (hosting panel → *MySQL Databases*),
   and give the user ALL privileges on that database (TRIGGER is required).
2. Import the clean database file built by `php scripts/export_clean_database.php`
   (`evora_clean_production_YYYYMMDD_HHMMSS.sql`) with phpMyAdmin → *Import*, or
   `mysql -u USER -p DBNAME < evora_clean_production_….sql`.
   It contains all tables, foreign keys and triggers, the reference data, and **one administrator**
   with an unknown random password.

## 4. Settings (`evora_secrets/`)

| File | What to put |
|---|---|
| `evora.ini` | Start from `evora.production.ini` (made by the export script, already has a new random `QR_SECRET`) — fill `APP_URL` (https), `DB_*`, `BACKUP_DIR`. Template: `config/evora.ini.example`. |
| `mail.ini` | Gmail SMTP as on XAMPP; set `APP_BASE_URL=https://your-domain` (or remove the line — `APP_URL` is used). |
| `paypal_real.ini` | PayPal app credentials. For real money use **Live** credentials and `PAYPAL_ENV=live`. Set `PAYPAL_OAUTH_REDIRECT_URI=https://your-domain/index.php?route=organizer-paypal-callback`. |
| `google.ini` | Google client ID/secret. The Return URL becomes `https://your-domain/index.php?route=google-callback` automatically (or set `GOOGLE_REDIRECT_URI`). |

Files must be readable by PHP but not public (`chmod 640`).

## 5. Update the outside services

- **PayPal app** → *Log in with PayPal* → Return URL: `https://your-domain/index.php?route=organizer-paypal-callback`.
  Organizers connect their PayPal again on the live site (and again after switching sandbox → live).
- **Google Cloud** → *Clients → EVORA Web* → Authorized redirect URI: `https://your-domain/index.php?route=google-callback`;
  *Branding*: add the domain, privacy policy (`/index.php?route=privacy-policy`) and terms
  (`/index.php?route=user-agreement`) links; *Audience → Publish app* so everyone can use it.
- The `127.0.0.1` forwarder files (`evora_google_callback*.php`, `evora_paypal_callback.php`) are for XAMPP only — do not upload them.

## 6. Cron jobs

Use the host's PHP CLI path (often `/usr/bin/php` or `/usr/local/bin/php`; cPanel shows it).

```
*/5  * * * *  /usr/bin/php /home/USER/evora/scripts/send_email_queue.php --limit=20  >/dev/null 2>&1
*/15 * * * *  /usr/bin/php /home/USER/evora/scripts/participant_jobs.php            >/dev/null 2>&1
0    3 * * *  /usr/bin/php /home/USER/evora/scripts/backup_database.php --keep=14   >/dev/null 2>&1
```

| Job | Does |
|---|---|
| `send_email_queue.php` | sends queued notification emails |
| `participant_jobs.php` | waitlist offers/expiry, "event is tomorrow" and saved-event reminders |
| `backup_database.php` | daily database backup into `BACKUP_DIR/auto/<db>`, keeps 14; uses mysqldump if allowed, otherwise a built-in PHP dump |

Download a backup now and then (or sync `evora_backups/` to cloud storage) so a copy exists off the server.

## 7. First login and checks

1. Open `https://your-domain/` — if you see *"EVORA is not configured for production yet"*, `evora.ini` is missing
   a long `QR_SECRET`, a `DB_PASS`, or an https `APP_URL`.
2. Login page → **Forgot password** with the administrator email → set a strong password →
   log in → enter the emailed **login code**.
3. Admin → **System Settings**: system name, logo, and an official contact email (shown on the legal pages).
4. Admin → **Error Log**: "Last database backup" turns green after the first nightly cron run
   (or run `php scripts/backup_database.php` once by hand).
5. Test: register a participant (verification email arrives with an https link), an organizer application,
   Continue with Google, and a PayPal sandbox payment before switching PayPal to live.
