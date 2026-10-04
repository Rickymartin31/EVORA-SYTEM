# EVORA — An Integrated Web Application for Event Management

Capstone project: a centralized event management platform covering organizer verification, event
creation, participant registration, payment validation, QR-based attendance, item claiming, and reporting.

See `docs/` for architecture, ERD, use-case, user-flow, and module-structure documentation.

## Setup (XAMPP)

1. This project already lives at `C:\xampp\htdocs\EVORA SYSTEM`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Import the database:
   ```
   C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
   C:\xampp\mysql\bin\mysql.exe -u root < database\seed.sql
   ```
4. Dependencies are already installed under `vendor/`. To reinstall from scratch:
   ```
   C:\xampp\php\php.exe composer.phar install
   ```
5. Visit **http://localhost/EVORA%20SYSTEM/public/index.php**

## Default Administrator Account

- Email: `admin@evora.local`

Use the administrator credentials configured for the deployment environment.

## Configuration

Edit `config/config.php` for:
- Database credentials (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`)
- `BASE_URL` if the project folder is renamed or moved
- `QR_SECRET` — change before any real deployment
- Upload size/type limits

## Tech Stack

PHP 8, MySQL/MariaDB, Bootstrap 5, vanilla JS, Chart.js. Composer libraries:
`endroid/qr-code` (QR generation), `dompdf/dompdf` (PDF reports/certificates),
`phpoffice/phpspreadsheet` (Excel reports). QR scanning in the browser uses `html5-qrcode` via CDN.
