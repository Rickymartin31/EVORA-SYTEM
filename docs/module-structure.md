# EVORA Module Structure

```
config/config.php          Global constants: DB creds, base URL, QR secret, upload limits
core/                       Framework-less service layer (see architecture.md)
database/
  schema.sql                Full DDL (27 tables, InnoDB, FK-constrained)
  seed.sql                  Roles, event categories, sports, default admin account
modules/
  auth/                     Login, logout, participant/organizer self-registration
  admin/                    Dashboard, organizer verification queue, event monitoring,
                             user management, platform reports, activity logs
  organizer/                Dashboard, event CRUD, staff assignment, registrations,
                             payments, event items, sports sub-module (teams/matches/
                             bracket), seminar sub-module (speakers/schedule/certificates),
                             event-scoped reports
  staff/                    Dashboard, QR scanner, attendance list, item-claim scanner
  participant/               Dashboard, event browsing/detail, registration, payment
                             upload, QR wallet, certificate wallet, registration history
  shared/                   Cross-role: profile, notifications, layout partials, 404
public/
  index.php                 Front controller + route table (single entry point)
  uploads_guard.php         Authorization-checked file streaming for private uploads
  assets/                   CSS/JS/img served directly by Apache
storage/uploads/            Private file storage, never web-linked directly
  ids/ docs/ payments/ banners/ items/ qr/ certificates/
docs/                       This documentation set
```

## Route Naming Convention

Routes are flat strings dispatched by `core/Router.php` via `?route=`, grouped by prefix:
`admin-*`, `organizer-*`, `staff-*`, plus unprefixed shared/participant-facing routes
(`events`, `event-view`, `event-register`, `profile`, `notifications`, `login`, etc).
Every handler re-asserts its own role/ownership requirement — the router itself does no
authorization, it only maps a route name to a file.
