# EVORA UI Design System (v34)

All styles live in `public/assets/css/theme.css` (tokens at the top, the v34 component layer
at the bottom). Chart defaults live at the top of `public/assets/js/app.js`. Use the tokens and
classes below instead of hard-coded colors or inline styles.

## Color tokens

| Role | Token | Value | Use |
|---|---|---|---|
| Primary | `--evora-primary` | `#4f46e5` | Buttons, links, active states (6.3:1 on white) |
| Primary hover | `--evora-primary-dark` | `#4338ca` | Hover / pressed |
| Primary soft | `--evora-primary-soft` | `#eef2ff` | Tinted backgrounds, icon chips |
| Accent | `--evora-accent` | `#06b6d4` | Brand mark, sidebar highlight (decorative) |
| Canvas | `--evora-bg` | `#f5f7fb` | Page background |
| Surface | `--evora-surface` | `#ffffff` | Cards |
| Border | `--evora-border` | `#e6e8f0` | Card and table borders |
| Heading text | `--evora-heading` | `#111827` | Titles |
| Body text | `--evora-text` | `#1f2937` | Body |
| Muted text | `--evora-text-muted` | `#5b6474` | Secondary text (6.1:1 on white) |

Bootstrap's own `primary` (links, `.btn-primary`, `.btn-outline-primary`, `.text-primary`,
pagination, checkboxes, progress bars) is mapped to the EVORA indigo, so there is one primary color.

### Status colors (reserved meaning)

| Status | Background | Text | Examples |
|---|---|---|---|
| Success | `#dcfce7` | `#166534` | Paid, Approved, Completed, Verified, Refunded payout |
| Warning | `#fef3c7` | `#92400e` | Pending, Processing, Ongoing |
| Danger | `#fee2e2` | `#991b1b` | Failed, Rejected, Cancelled, Removed |
| Info | `#e0f2fe` | `#075985` | Open, informational |
| Neutral | `#f1f5f9` | `#334155` | Draft, Closed, Archived, Inactive |

Every status badge shows a colored dot **and** its label — color is never the only signal.
Use `badge badge-status-{status}` or the Bootstrap `badge bg-success|warning|danger|info`
classes; both render in this soft style. Solid `rounded-pill` badges are reserved for counters.

## Typography

Font: **Inter** (Google Fonts), falling back to Segoe UI / Roboto.

| Level | Size | Weight | Where |
|---|---|---|---|
| Page title | 1.125rem | 700 | Top bar (`h1.h5`) |
| Section / card title | 1rem | 650 | `h6.fw-semibold` inside `.card-evora` |
| Body | 0.9375rem | 400 | Default text |
| Helper text | 0.8125rem | 400 | `.small`, `.form-text` |
| Label | 0.72rem, uppercase, 0.05em tracking | 700 | Stat labels, table headers |

Numbers in stat tiles and tables use tabular figures so columns line up.

## Components

- **Cards**: `.card-evora` (white, 1rem radius, hairline border, soft shadow).
- **Stat tiles**: `.stat-card` with an icon chip, `.stat-value`, `.stat-label`, `.stat-desc`.
  Grid them `col-6 col-md-4 col-xl…` (two per row on phones). Only wrap in a link when clickable.
- **Quick actions**: `.quick-action` (add `.qa-primary` for the main action) with `.qa-icon` and `.qa-sub`.
- **Buttons**: `.btn-evora-primary` / `.btn-primary` (main action), `.btn-outline-secondary`
  (secondary), `.btn-danger` / `.btn-outline-danger` (destructive, always confirmed).
- **Tables**: `.table` inside `.table-responsive`; add `.table-datatable` for search, sort,
  pagination and CSV export. Any table left unwrapped is wrapped automatically on load.
- **Empty states**: `emptyState($icon, $message, $actionLabel, $actionUrl)` — give a next step.
- **Confirmations**: `<form data-confirm="…">` shows a SweetAlert2 dialog.
- **Tooltips**: add `data-bs-toggle="tooltip" title="…"`.

### Added in v34.1

- **Page header**: set `$pageDescription = '…';` (plain text) before including `layout_top.php`;
  optionally `$pageActions` (trusted HTML) for a primary action. The top bar keeps the page title.
- **Action groups**: wrap page actions in `.action-group` — primary first
  (`.btn-evora-primary`), then `<span class="action-divider">`, secondary actions
  (`.btn-outline-secondary`), and destructive ones inside `.action-danger-zone` (`.btn-outline-danger`).
  Never style a destructive action like the primary one.
- **Lifecycle stepper**: `statusSteps([['label' => …, 'meta' => …, 'state' => 'done|current|upcoming|failed']])`,
  or include `modules/shared/partials/lifecycle_steps.php` with
  `$lifecycle = ['type' => 'payout'|'refund', 'record' => $row]`.
- **Money**: `.money` (tabular, no wrap), `.money-lg` (headline amount), `.money-summary` grid.
- **Status stack** (participant rows): `.status-stack > .status-line > .status-key + badge`.
- **Confirmations** (no native `confirm()`/`prompt()`):
  - one button: `data-confirm-click="What happens" data-confirm-title="…?" data-confirm-button="…" data-confirm-variant="danger|primary|success"`;
  - whole form: `data-confirm="…"` (+ the same title/button/variant attributes);
  - reason required: `<form data-prompt="…" data-prompt-field="remarks" data-prompt-min="10" data-prompt-title="…" data-prompt-button="…">`.
  The same button submits the same form after confirming; required fields are validated first.
- **Utilities** replacing inline styles: `.icon-xl`, `.progress-thin`, `.mw-form`, `.mw-form-lg`,
  `.w-80px`, `.avatar .avatar-30|42|56|72`, `.legend-swatch.is-event|is-deadline`,
  `.event-cover-placeholder`, `.frame-bordered`, `.rating-stars` (decorative stars — always show the number).
- Text utilities `.text-success|warning|danger|info` and outline buttons in those colors are
  remapped to AA-contrast shades; `bg-*-subtle` badges follow the status palette.
- Long unbroken text (event names, references, file paths) wraps inside cards and headings.

### Added in v34.2 (specialized modules)

Markup helpers live in `modules/shared/partials/sports_ui.php` (`require_once` it); they only
render data the page already loaded.

- **Module overview card**: `moduleOverview($icon, $title, $metaList, $stats, $badgeHtml)` — icon chip,
  title, meta line and a `dl` of labelled figures (`tone` = success|warning|danger|info|primary).
  Used for tournament, race, seminar and Match Center summaries.
- **Match lifecycle badge**: `matchStatusBadge($status, $labels)` → Scheduled (info) → Live (danger,
  pulsing dot) / Paused (warning) → Completed (success) / Cancelled. Label always shown.
- **Match card**: `matchCard($match, ['label', 'href', 'labels', 'winner', 'showMeta', 'current'])` —
  Match #, both sides with score, VS divider, trophy + "(winner)" text from the *recorded* winner,
  live matches get a red edge. `.match-grid` lays cards out.
- **Bracket**: `.bracket > .bracket-round > .bracket-round-head + .bracket-round-body`; rounds side by
  side (focusable horizontal scroller), stacked under 768px. `bracketRoundLabels()` names rounds
  Quarterfinals / Semifinals / Final only for a clean single-elimination tree.
- **Scoreboard header**: `.scoreboard` (`.is-live`, `.scoreboard-lg`) with `.scoreboard-team`,
  `.scoreboard-name`, `.scoreboard-score`, `.scoreboard-vs`.
- **Ranks / podium**: `.rank-badge.rank-1|2|3` (number always shown), `.podium > .podium-place.place-N`
  (compact rows on phones).
- **Seminar**: `.session-card` (`.is-ongoing`, `.is-cancelled`), `.speaker-card` with photo or
  `.speaker-initials`, `.speaker-topic`, `.attendance-row` progress rows.
- **Client-side filters**: `<div class="filter-bar" data-filter-for="tableId">` with
  `data-filter="search|category|status"` controls; rows carry `data-search` / `data-category` /
  `data-status`, optional `tr.filter-empty`.
- **Auto-submitting select**: `<select data-autosubmit data-confirm-value="cancelled" data-confirm-*>` —
  the listed value asks first; Cancel restores the previous value.
- **Script confirmations**: page scripts call `window.EvoraConfirm({title, text, button, variant})`
  (returns the SweetAlert2 promise) instead of `confirm()`.
- Category labels (sport type, session type) use neutral `bg-primary` / `bg-secondary`; status colors
  stay reserved for status.
- Utilities: `.score-input`, `.scroll-y-320|420`, `.min-w-0`, `.min-w-200`, `.mw-320`, `.route-thumb`,
  `.qr-reader-box`, `.live-dot`.

### Added in v30.2 (refund guidance)

- **Refund lifecycle**: `lifecycle_steps.php` with `type => 'refund'` shows Pending Request → Approved →
  Processing Refund ("Awaiting PayMongo refund" while approved) → Completed; rejected shows a failed step.
- **Numbered guide**: `<ol class="refund-guide">` with `.refund-guide-title` + `.refund-guide-text` per step.
- **Destination box**: `.refund-destination` (info tint) — where the money goes back.
- **Copy to clipboard**: `<button type="button" data-copy="text">` (shows "Copied"; falls back to
  `execCommand`). Only for non-secret identifiers (e.g. a PayMongo payment ID).
- `.refund-note` (status explanation under a status line), `.impact-negative` (earnings deductions).

### Staff QR scanner

- Page `modules/staff/scan.php`, script `public/assets/js/staff_scanner.js`, library
  `public/assets/vendor/html5-qrcode.min.js` (html5-qrcode 2.3.8, Apache-2.0, served locally — no CDN needed at venues).
- Camera starts on **Start Camera** (or by itself when the browser already allowed it); rear camera preferred,
  first listed camera as fallback. Every failure has its own message: blocked, no camera, camera busy,
  insecure page, unsupported browser, library missing — each with Try Again / Use Manual Entry.
- Scanning pauses while a result is shown; Stop Camera, event change, tab hidden and page unload release the camera.
- Classes: `.scanner-frame`, `.scanner-placeholder`, `.scanner-status.is-idle|info|success|warning|danger`,
  `.scan-result.is-*` with `.scan-result-title` / `.scan-result-facts`, `.recent-scan`.
- `staff-scan-verify` answers with a `code` (ready, checked_in, already_checked_in, not_eligible, wrong_event,
  invalid_qr, invalidated, not_found, no_permission, session) and `checked_in_at`.

### Alerts & notices (persistence rules)

Use `evoraAlert($kind, $trustedHtml, $opts)` from `core/helpers.php`. How long a message stays is decided
by its kind, never by the page:

| Kind | Look | Disappears | Close button | Use for |
|---|---|---|---|---|
| `action` | warning + left accent | only when the page stops rendering it (action done) | no | agreement acceptance, verification, missing setup, pending approvals, locked fields |
| `error` | danger + left accent | never on its own | yes | failed actions, validation errors (flash `error`) |
| `warning` | warning | never on its own | yes | cautions that need no action |
| `info` | info | never, unless `autohide` is set | yes | guidance |
| `success` | success | after 4 s (toast when SweetAlert2 is loaded) | yes | "Saved", "Done" (flash `success`) |

- `$opts`: `icon`, `actions` (trusted HTML buttons/links), `class`, `autohide` (ms), `toast` (true).
- Behavior lives in `public/assets/js/app.js`: an alert hides **only** if it has `data-autohide="ms"`;
  timers pause while the alert is hovered or focused. Plain `.alert` markup is therefore persistent too.
- Escape user data with `e()` before passing it — `$trustedHtml` is printed as is.

## Charts (Chart.js)

- Single-series trends use the brand hue: `EvoraChart.brand` / `EvoraChart.brandFill`.
- Status splits use `EvoraChart.status.success|warning|danger|neutral`
  (`#15803d`, `#f59e0b`, `#dc2626`, `#64748b`) — validated for color-vision deficiency
  (adjacent CVD ΔE 15.1, normal-vision ΔE 24.1). Doughnut legends show "Label (count)".
- Two values: use a bar, not a two-slice doughnut. Never use status colors for ordinary series.
- Defaults set globally: Inter font, muted axes, recessive grid, 2px lines without overshoot,
  rounded bars, 2px gaps between slices, index hover tooltips, consistent aspect ratios.

## Layout, responsiveness and accessibility

- Landmarks: skip link → `nav.evora-sidebar` (`aria-label`) → `header.evora-topbar` → `main#main-content`.
- Active sidebar item has `aria-current="page"`; icons are `aria-hidden`.
- Phones/tablets (< 992px): the sidebar slides in over a backdrop; it closes on backdrop tap,
  Escape or link click, and the menu button reports `aria-expanded`.
- Touch devices get ~44px controls; visible focus rings everywhere (`:focus-visible`).
- `prefers-reduced-motion` disables animations; print hides the app chrome.
- Personal settings live in the top-right account menu, not the sidebar (v33).
