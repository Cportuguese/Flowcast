# Flowcast — Role Access Confirmation & Per-Role Dashboard Spec

## Context

Flowcast has three roles (`admin`, `personnel`, `patient`) plus a public TV board. Through Phases 1–4 the UI grew page-by-page: the web app has no shared shell (every page renders its own "Back" / "Log out"), the web `/dashboard` is a flat row of buttons, patients who log in on web see an empty page, and staff who log in on mobile see a placeholder. This session is the **design/planning** pass: (1) confirm exactly which buttons each role has today, and (2) specify a minimalist dashboard for each role, derived from the existing navy/blue brand — no reference images were supplied, so nothing is copied from external mockups.

**Deliverable: design spec only.** On approval, the only code-adjacent action is saving this spec into the repo as `docs/ROLE_UI_SPEC.md` so it survives beyond this session. No UI code changes.

Decisions already made by the user: derive visuals from the existing brand; web shell = left sidebar; patient dashboard = mobile home redesign (web patients get a "use the mobile app" page); spec only.

---

## Part 1 — What is ALREADY in the system (confirmed)

Access is enforced in two layers that agree with each other:
- API: `role` middleware ([EnsureUserHasRole.php](api/app/Http/Middleware/EnsureUserHasRole.php)) on route groups in [api.php](api/routes/api.php)
- Web: `ProtectedRoute allowedRoles` in [App.tsx](web/src/App.tsx#L17-L83) + inline `isStaff` / `isAdmin` in [Dashboard.tsx](web/src/pages/Dashboard.tsx#L7-L8)

### ADMIN (web) — ✅ confirmed
| Where | Button / action | API |
|---|---|---|
| Dashboard | Open Live Queue, Appointments, Visit Records, Open TV Board ↗, Analytics, **Clinic Settings, Reports, Audit Log**, Log out | — |
| Live Queue | Call Next, Register walk-in → Check in, Mark Done (→ opens Visit Form), Mark Skipped, status filter tabs | `POST /admin/queue/call-next`, `/walk-in`, `PATCH …/complete`, `…/skip` |
| Visit Form modal | Complaint chips, vitals, diagnosis, treatment, referred to → Save record / Cancel | `POST /visits` |
| Appointments | Date picker, Mark No-show | `GET /appointments?date`, `PATCH /appointments/{id}/no-show` |
| Visit Records | Search by School ID / date, row expand (read-only) | `GET /admin/visits/search` |
| Analytics | Read-only tiles + 3 charts | `GET /analytics/*` |
| Clinic Settings *(admin only)* | Weekly hours per-day Save, Flag event day, Remove event day | `POST /admin/schedules`, `POST/DELETE /admin/event-days` |
| Reports *(admin only)* | Run (×4 reports), Download CSV, Print / Export PDF | `GET /admin/reports/*` |
| Audit Log *(admin only)* | Filter + Search (read-only) | `GET /admin/audit-logs` |
| Inventory | Low-stock / expiring banners, Stock in, Stock out, expand row for batches, **Add/Edit/Archive medicine, Dispose expired, History** | `GET /medicines`, `POST /inventory/stock-in`, `POST /inventory/stock-out`, `GET /inventory/expiring-soon`, `POST/PATCH /admin/medicines`, `GET /admin/inventory/expired`, `POST /admin/inventory/batches/{id}/dispose` |
| Inventory History *(admin only)* | Filter by date/medicine/type, Download CSV | `GET /admin/inventory/history` |
| Queue History *(admin only)* | Date picker, per-day stats (total / completed / skipped / avg wait), read-only table with wait + consult minutes | `GET /queue/{date}/history` |

### PERSONNEL (web) — ✅ confirmed
Everything in the Admin table **except** Clinic Settings, Reports, Audit Log, queue history, medicine catalog edits, expired-batch disposal, and Inventory History. Personnel can: Call Next, Register walk-in, Mark Done, Mark Skipped, Save visit record (incl. medicines given, which deducts stock), Mark No-show, search Visit Records, view Analytics, open TV Board, view Inventory, Stock in, Stock out.

> Note: routes under `/admin/queue/*` and `/admin/visits/search` are personnel-accessible despite the prefix ([api.php:71-88](api/routes/api.php#L71-L88)). Naming only — behaviour is correct.

### PATIENT (mobile) — ✅ confirmed
| Screen | Button / action | API |
|---|---|---|
| Queue (home) | My Appointments, Visit History, Check in for my appointment, Check in as walk-in, Back to check-in, Log out | `GET /queue/mine`, `POST /queue/check-in`, `GET /appointments/mine` |
| Book appointment | Date strip, slot chips, Reason, Confirm appointment, Close | `GET /schedule`, `GET /appointments/available-slots`, `POST /appointments` |
| My Appointments | Book a new appointment, Cancel appointment (booked/checked_in only), Close | `POST /appointments/{id}/cancel` |
| Visit History / Detail | Tap visit → detail (read-only), Close | `GET /visits/mine`, `GET /visits/{queueEntry}` |
| Login / Register | Sign in, Create Account | `POST /login`, `POST /register` |

Patient on **web**: `/dashboard` shows a "Flowcast for patients lives in the mobile app" card ([PatientWebNotice.tsx](web/src/components/PatientWebNotice.tsx)); staff routes show an access-denied card.

### TV BOARD (public, no login) — ✅ confirmed
[TvBoard.tsx](web/src/pages/TvBoard.tsx): **zero buttons**. Shows "Now Serving #N" and "Up Next" (10 numbers). Live via public channel `queue-board.{date}`, chime on `ticket.called`. Data is numbers + status only (no names) — correct for a public screen.

### Not in the system (but referenced in memory)
- **Medical certificate generation** — no endpoint, no button anywhere (`grep certificat` → 0 hits). Deferred at Phase 4. Intended rule (from project memory): personnel generate; admin view/download only. Included below as *planned* placeholders, clearly marked.

### Gaps found during confirmation — all resolved (Sep 20, 2026)
1. ~~Web has no shared shell~~ → [AppShell.tsx](web/src/components/AppShell.tsx) + [PageHeader.tsx](web/src/components/PageHeader.tsx); routes nested in [App.tsx](web/src/App.tsx).
2. ~~Patient on web / staff on mobile see empty or placeholder pages~~ → [PatientWebNotice.tsx](web/src/components/PatientWebNotice.tsx) and the staff notice in [mobile/app/index.tsx](mobile/app/index.tsx).
3. ~~Mobile Register never sends `patient_type` / `contact_no`~~ → optional "I am a" chips + Contact Number in [register.tsx](mobile/app/register.tsx).
4. ~~Admin has no UI for queue history~~ → [QueueHistory.tsx](web/src/pages/QueueHistory.tsx) at `/admin/queue-history`.
5. ~~`ProtectedRoute` redirects silently on wrong role~~ → renders an access-denied card with a link home ([ProtectedRoute.tsx](web/src/components/ProtectedRoute.tsx)).

---

## Part 2 — Design system (shared by all four surfaces)

Derived entirely from what exists; **no new colors**.

| Token | Value | Source |
|---|---|---|
| `brand` | `#0b1636` navy | [AuthLayout.tsx:7](web/src/layouts/AuthLayout.tsx#L7), mobile primary buttons |
| `accent` | `blue-600` / `#2a78d6` | primary buttons, chart "actual" line |
| `surface` | white cards on `gray-50` page | all pages |
| `border` | `slate-200` | all pages |
| `text` / `muted` | `slate-900` / `slate-500` | all pages |
| status: waiting / in_consultation / done / skipped·no_show | `slate-100` / `blue-100` / `green-100` / `red-100` | [QueueBoard.tsx:21-26](web/src/pages/QueueBoard.tsx#L21-L26) |
| Font | system-ui stack | [index.css:4](web/src/index.css#L4) |
| Radius / shadow | `rounded-lg`, `shadow-sm` | all cards |

Minimalist rules: one primary (navy or blue-600) button per view; everything else is outline or text; no icons-only buttons without labels; max 4–5 stat tiles; no decorative gradients; whitespace over borders.

Reusable pieces to extract when implementing (currently copy-pasted): `AppShell` (sidebar + content), `PageHeader` (title + date + actions slot), `StatTile` (already local in [AnalyticsDashboard.tsx:319](web/src/pages/AnalyticsDashboard.tsx#L319)), `StatusPill`, `Button` (primary/outline/danger/ghost).

---

## Part 3 — Per-role dashboards

### 3.1 Web shell (Admin + Personnel)

Fixed left sidebar, 232px (collapses to 64px icon rail below `lg`). Nav is role-filtered — the same list, with an **Admin** section appended for admins only.

```
┌──────────┬────────────────────────────────────────────────────┐
│ ▮ Flowcast│  {Page title}                    Fri, Sep 19 · 10:42 │
│          │                                                    │
│ Home     │  ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐       │
│ Live Queue│  │ tiles… │ │        │ │        │ │        │       │
│ Appointments│ └────────┘ └────────┘ └────────┘ └────────┘     │
│ Visit Records│                                                │
│ Analytics│  (page content)                                    │
│ TV Board ↗│                                                   │
│          │                                                    │
│ ─ ADMIN ─│   ← this block only when role === 'admin'          │
│ Clinic Settings│                                              │
│ Reports  │                                                    │
│ Audit Log│                                                    │
│          │                                                    │
│ ● Ana R. │  ← user chip: name, role badge, "Log out" text link │
│  personnel│                                                   │
└──────────┴────────────────────────────────────────────────────┘
```

Sidebar visibility per role:

| Nav item | Admin | Personnel | Patient (web) |
|---|---|---|---|
| Home | ✅ | ✅ | — (sees "Use the mobile app" page) |
| Live Queue | ✅ | ✅ | — |
| Appointments | ✅ | ✅ | — |
| Visit Records | ✅ | ✅ | — |
| Inventory | ✅ | ✅ | — |
| Analytics | ✅ | ✅ *(keep as today; flag if you want it admin-only)* | — |
| TV Board ↗ (new tab) | ✅ | ✅ | — |
| Clinic Settings | ✅ | ✗ | — |
| Reports | ✅ | ✗ | — |
| Queue History | ✅ | ✗ | — |
| Inventory History | ✅ | ✗ | — |
| Audit Log | ✅ | ✗ | — |
| Log out | ✅ | ✅ | ✅ |

Removes the per-page "Back" button entirely (sidebar replaces it). Per-page "Log out" moves to the user chip.

### 3.2 PERSONNEL — Home ("Today")

Operational, queue-first. One primary action.

```
Today                                                Fri, Sep 19
┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐
│ Waiting  │ │ In consult│ │ Done today│ │ Avg wait │
│   12     │ │    1     │ │    23    │ │  8 min   │
└──────────┘ └──────────┘ └──────────┘ └──────────┘

┌─ Now serving ────────────────────────────────────┐
│  #14  Juan Cruz · walk-in · called 10:31         │
│                         [Mark Done] [Mark Skipped]│
└──────────────────────────────────────────────────┘
        [ Call Next (12 waiting) ]   Register walk-in

Up next                          Today's appointments (6)
 #15  M. Reyes    6 min           09:30  A. Santos   checked in
 #16  L. Dela Cruz 4 min          10:00  B. Lim      booked   · Mark No-show
 #17  …                           10:30  …
 See full queue →                 See all →
```

Buttons visible: **Call Next** (primary), Register walk-in (outline), Mark Done / Mark Skipped (row actions on the current ticket), Mark No-show (row action on appointments), "See full queue →", "See all →". Nothing admin-only appears.

Data sources already exist: `GET /queue/today`, `GET /appointments?date=today`, `GET /analytics/dashboard` (for avg wait), channel `queue.{date}.staff`.

*Planned (not in system):* after Save record, the Visit Form modal gets a **Generate certificate** button — personnel only.

### 3.3 ADMIN — Home ("Overview")

Oversight-first. Admin can still operate the queue (they have the permission) but it's one click away, not the hero.

```
Overview                                             Fri, Sep 19
┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐
│ Waiting  │ │ Done today│ │ Avg wait │ │ No-shows │ │ Event day│
│   12     │ │    23    │ │  8 min   │ │ 4 (Sep)  │ │  none    │
└──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘

┌─ Queue now ───────────────┐  ┌─ Last 7 days ──────────────────┐
│ Serving #14 · 12 waiting  │  │  ▁▃▅▇▅▃▂  arrivals (sparkline) │
│ Open Live Queue →         │  │  View analytics →              │
└───────────────────────────┘  └────────────────────────────────┘

┌─ Clinic ──────────────────┐  ┌─ Recent activity ──────────────┐
│ Open today 08:00–17:00    │  │ 10:31 Ana R. called #14        │
│ Next event day: Sep 25    │  │ 10:20 Ana R. created visit #13 │
│ Clinic Settings →         │  │ 09:58 Schedule updated (Mon)   │
└───────────────────────────┘  │ View audit log →               │
                               └────────────────────────────────┘
        Reports →   Queue history (by date) →
```

Buttons visible: everything Personnel has (via sidebar) **plus** Clinic Settings →, Reports →, View audit log →, Queue history → (new small page for `GET /queue/{date}/history`, gap #4). No primary CTA on this page — the admin home is read-mostly; the one prominent link is "Open Live Queue →".

Data sources: `GET /analytics/dashboard`, `GET /analytics/volume-forecast?days=7`, `GET /admin/reports/no-show-summary?month`, `GET /admin/schedules`, `GET /admin/event-days`, `GET /admin/audit-logs` (limit to last 5).

*Planned (not in system):* certificates appear in Visit Records rows as **View / Download** only — admin never sees "Generate".

### 3.4 PATIENT — Mobile home (redesign of [queue.tsx](mobile/app/queue.tsx))

Move from Stack-with-pseudo-tabs to a **3-tab bottom bar** (Expo Router `(tabs)` group): **Home · Appointments · Visits**. Existing screens map 1:1; `book-appointment` and `visit-detail` stay as stack pushes. "Log out" moves to a small header avatar menu / settings row instead of a floating text link.

```
┌──────────────────────────────┐
│ Hi, Maria            Sep 19 ⚙│
│                              │
│ ┌──────────────────────────┐ │   STATE A — not in queue
│ │ You're not in line yet   │ │
│ │                          │ │
│ │ [ Check in as walk-in ]  │ │   ← primary (navy)
│ └──────────────────────────┘ │
│ ┌──────────────────────────┐ │   shown only if appointment today
│ │ Appointment today 10:30  │ │
│ │ Check in for appointment │ │   ← secondary (blue-600)
│ └──────────────────────────┘ │
│                              │
│ Next appointment             │
│  Mon Sep 22 · 09:00  → view  │
│  Book an appointment →       │
│                              │
├──────────┬─────────┬─────────┤
│  ● Home  │  Appts  │  Visits │
└──────────┴─────────┴─────────┘

┌──────────────────────────────┐
│ ┌──────────────────────────┐ │   STATE B — in queue (waiting)
│ │   YOUR NUMBER            │ │
│ │       14                 │ │   ← big, slate-900
│ │   3 people ahead         │ │   ← blue-600
│ │   ~8 min · Position #4   │ │
│ └──────────────────────────┘ │
│  Now serving #11             │   ← new: from personal channel payload
└──────────────────────────────┘
   STATE C — called: card turns blue-600 bg, "It's your turn — go to the clinic"
   STATE D — done/skipped: "Back to check-in" outline button (as today)
```

Buttons per tab:
- **Home**: Check in as walk-in, Check in for my appointment (conditional), Back to check-in (conditional), Book an appointment →, ⚙ (Log out inside).
- **Appointments**: Book a new appointment (primary), Cancel appointment (row, booked/checked_in only).
- **Visits**: tap row → detail (read-only). *Planned:* **Download certificate** on visit detail once certificates exist.

Fix while redesigning: send `patient_type` + `contact_no` from Register (gap #3).

Patient on **web**: replace empty `/dashboard` with a single centered card — logo, "Flowcast for patients lives in the mobile app", Log out. Same card, mirrored, for staff on mobile ([index.tsx](mobile/app/index.tsx)): "Staff tools are on the web dashboard".

### 3.5 TV BOARD — public

No buttons — confirmed and kept. Refinements only:

```
┌──────────────────────────────────────────────────────┐
│ UDD Clinic                             10:42  ● live │
│                                                      │
│               NOW SERVING                            │
│                                                      │
│                  14                                  │   ← 12rem, blue-400, dark bg (as today)
│                                                      │
│   UP NEXT   15   16   17   18   19   20   21   22    │
│                                                      │
│   Closed state: "Clinic opens Mon 08:00" when no     │
│   entries and outside schedule hours                 │
└──────────────────────────────────────────────────────┘
```

Additions: clock, live/offline dot (Echo connection state), closed state. Never shows names, never shows staff data, no auth — matches `boardToday` sanitisation ([QueueController.php:249](api/app/Http/Controllers/Api/QueueController.php#L249)).

---

## Part 4 — Consolidated button matrix (the answer to "which button should be visible")

| Button | Admin | Personnel | Patient | TV |
|---|---|---|---|---|
| Call Next | ✅ | ✅ | ✗ | ✗ |
| Register walk-in | ✅ | ✅ | ✗ | ✗ |
| Mark Done / Mark Skipped | ✅ | ✅ | ✗ | ✗ |
| Save visit record | ✅ | ✅ | ✗ | ✗ |
| Mark No-show | ✅ | ✅ | ✗ | ✗ |
| Search visit records | ✅ | ✅ | ✗ | ✗ |
| View analytics | ✅ | ✅ | ✗ | ✗ |
| Open TV Board | ✅ | ✅ | ✗ | (is the board) |
| Weekly hours Save / Event day Flag / Remove | ✅ | ✗ | ✗ | ✗ |
| Run report / Download CSV / Print | ✅ | ✗ | ✗ | ✗ |
| Audit log Search | ✅ | ✗ | ✗ | ✗ |
| Queue history by date | ✅ | ✗ | ✗ | ✗ |
| Check in (walk-in / appointment) | ✗ | ✗ | ✅ | ✗ |
| Book / Cancel appointment | ✗ | ✗ | ✅ | ✗ |
| View own visit history | ✗ | ✗ | ✅ | ✗ |
| Log out | ✅ | ✅ | ✅ | ✗ |
| *Generate certificate (planned)* | ✗ | ✅ | ✗ | ✗ |
| *View / Download certificate (planned)* | ✅ | ✅ | ✅ own | ✗ |

All ✅/✗ in the non-italic rows are **already enforced today** at both API and UI layers. Italic rows do not exist yet.

---

## Part 5 — Open decisions for you (not blocking the spec)

1. Should **Analytics** stay visible to Personnel, or become admin-only? Today: both roles. Spec keeps it.
2. Mobile bottom tabs vs keeping the current stack + two buttons — spec recommends tabs.
3. Whether admin should be able to **Call Next** from their Home, or only from Live Queue — spec says Live Queue only (keeps admin home read-mostly).

---

## Implementation order (for a future session — NOT this one)

1. ~~`AppShell` + `PageHeader`~~ — done (commit d252b25). `StatTile` extracted with step 2; `StatusPill` / `Button` still inline.
2. ~~Split `Dashboard.tsx` into role-switched homes~~ — done: [PersonnelHome.tsx](web/src/pages/home/PersonnelHome.tsx), [AdminHome.tsx](web/src/pages/home/AdminHome.tsx), shared [useTodayQueue.ts](web/src/lib/useTodayQueue.ts) + [StatTile.tsx](web/src/components/StatTile.tsx). "Register walk-in" on Home opens `/queue?walkin=1`.
3. ~~Small `QueueHistory` page for admin~~ — done with the Part 1 gap fixes.
4. Mobile: `app/(tabs)/` group with `index` (home), `appointments`, `visits`; move Log out to header; send `patient_type`/`contact_no` on register.
5. TV board: clock, connection dot, closed state.
6. Certificates: separate phase (API first), then the three planned buttons.

## Verification (for this session)

Spec only — verification is a read-through: every ✅ in Part 4 traces to a route in [api.php](api/routes/api.php) and a guard in [App.tsx](web/src/App.tsx) / [Dashboard.tsx](web/src/pages/Dashboard.tsx); every "planned" item is marked as such. On approval, save this file to `docs/ROLE_UI_SPEC.md` in the repo.
