# EVORA Entity-Relationship Diagram

Full DDL lives in `database/schema.sql`. This is the logical ERD.

```mermaid
erDiagram
    ROLES ||--o{ USERS : has
    USERS ||--o| ORGANIZERS : "is (if organizer)"
    USERS ||--o| PARTICIPANTS : "is (if participant)"
    ORGANIZERS ||--o{ ORGANIZER_VERIFICATIONS : submits
    ORGANIZERS ||--o{ EVENTS : creates
    EVENT_CATEGORIES ||--o{ EVENTS : classifies
    EVENTS ||--o{ EVENT_STAFF : "assigns staff"
    USERS ||--o{ EVENT_STAFF : "works as"
    EVENT_STAFF ||--o{ STAFF_PERMISSIONS : grants

    EVENTS ||--o{ REGISTRATIONS : receives
    PARTICIPANTS ||--o{ REGISTRATIONS : submits
    REGISTRATIONS ||--o| PAYMENTS : requires
    REGISTRATIONS ||--o| QR_CODES : issues
    REGISTRATIONS ||--o{ ATTENDANCE : "checks in via"
    EVENTS ||--o{ ATTENDANCE : records
    USERS ||--o{ ATTENDANCE : "scanned by (staff)"
    REGISTRATIONS ||--o| CERTIFICATES : "earns"

    EVENTS ||--o{ EVENT_ITEMS : defines
    EVENT_ITEMS ||--o{ ITEM_CLAIMS : "claimed via"
    REGISTRATIONS ||--o{ ITEM_CLAIMS : claims
    USERS ||--o{ ITEM_CLAIMS : "released by (staff)"

    SPORTS ||--o{ TEAMS : categorizes
    EVENTS ||--o{ TEAMS : hosts
    TEAMS ||--o{ TEAM_MEMBERS : rosters
    PARTICIPANTS ||--o{ TEAM_MEMBERS : joins
    EVENTS ||--o{ MATCHES : schedules
    SPORTS ||--o{ MATCHES : categorizes
    TEAMS ||--o{ MATCHES : "competes in"
    MATCHES ||--o| MATCH_SCORES : records
    EVENTS ||--o{ TOURNAMENT_BRACKETS : structures
    MATCHES ||--o| TOURNAMENT_BRACKETS : "placed in"

    EVENTS ||--o{ EVENT_SPEAKERS : features
    EVENTS ||--o{ EVENT_SCHEDULE : agenda
    EVENT_SPEAKERS ||--o{ EVENT_SCHEDULE : presents

    USERS ||--o{ NOTIFICATIONS : receives
    USERS ||--o{ REPORTS : generates
    USERS ||--o{ ACTIVITY_LOGS : performs
```

## Enhancement Pass Additions

```mermaid
erDiagram
    EVENTS ||--o{ EVENT_STATUS_LOGS : tracks
    USERS ||--o{ EVENT_STATUS_LOGS : changes
    USERS ||--o{ SYSTEM_EVALUATIONS : submits
```

- `events` gained `admin_remarks`, `registration_deadline`, and an extended `status` enum:
  `draft → pending_approval → approved → open → closed → ongoing → completed → archived`
  (plus `cancelled`/`removed` for moderation). `event_status_logs` audits every transition.
- `users` gained `profile_picture_path` (shown on the staff QR-scan participant card).
- `certificates` gained `certificate_number` and `verification_token` (scanned from a QR printed on
  the certificate itself, resolved by the public `certificate-verify` route).
- `system_evaluations` — one row per ISO/IEC 25010 submission (8 category scores 1–5 + comment).

## Key Constraints

- `registrations`: `UNIQUE(event_id, participant_id)` — one registration per participant per event.
- `attendance`: `UNIQUE(registration_id, event_id)` — prevents duplicate check-in.
- `item_claims`: `UNIQUE(event_item_id, registration_id)` — prevents duplicate item claim.
- `event_staff`: `UNIQUE(event_id, user_id)` — a staff account is assigned to an event at most once.
- `qr_codes.registration_id`: `UNIQUE` — one QR per registration, generated only after approval
  (+ payment approval for paid events).
