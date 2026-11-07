# Fixes log

A running record of the bugs and mistakes found in Flowcast and how each was
fixed, newest first. **Add an entry whenever something is fixed**: what went
wrong, how it showed up, what changed, and where. That way a fix can be
explained at the defense, and a fixed bug can be recognised if it comes back.

Entry format: **title** — what went wrong → how it was fixed (files).

## Rules decided by the clinic

These are choices, not bugs. A fix must never undo them.

- **Only staff issue medical certificates**: the head nurse (Portia) for any
  visit, the dentist and physician for visits they recorded. Patients cannot
  issue, view or download one through the app; they ask the clinic for the
  signed copy. *(2026-11-07)*
- **A patient's ID number is only a claim until the nurse checks it** against
  their UDD ID card. *(2026-10-12)*
- **Only the head nurse works the live queue.** The dentist and physician see
  their own schedule and visit records.
- **A learned forecasting model replaces the simple formula only on a clear
  win**, more than twice the noise in the comparison. *(2026-10-13)*

---

## 2026-11-07

- **Patients could get a medical certificate** — the phone showed a *Download
  certificate* button, and the server allowed any patient to view and download
  a certificate issued for their own visit. The clinic's rule is that only
  staff handle certificates. → View and download are now staff-only (403 for
  patients). The patient's data no longer contains the download link, the
  button is gone, and the screen says to ask the nurse for the signed copy.
  The old tests that required patients to be able to download were rewritten
  to require the opposite. (`api/routes/api.php`, `CertificateController.php`,
  `VisitController.php`, `mobile/app/visit-detail.tsx`; tests in
  `MedicalCertificateTest.php`, `RoleAccessTest.php`.)
  *Note:* `docs/ROLE_UI_SPEC.md` still says patients may download their own
  certificate; that older spec is out of date on this point.
- **Log out disappeared from the phone's account menu** — the menu was an
  Android alert dialog, which shows at most 3 buttons and silently drops the
  rest. Adding "My health record" made it 4, so Log out was cut off. → Replaced
  with a proper pop-up menu that can hold any number of options.
  (`mobile/components/AccountMenu.tsx`, `mobile/app/(tabs)/index.tsx`.)
- **Queue summary report took 8.4 seconds over three years** — it loaded all
  36,000 queue entries as full records. → Reads plain rows and does the time
  arithmetic directly: 0.85 seconds, identical numbers.
  (`ReportController.php`.)
- **Type errors after the Expo 58 upgrade** — newer type definitions rejected
  the tab icon colour and the booking screen's scroll reference. They did not
  affect the running app. (`mobile/app/(tabs)/_layout.tsx`,
  `mobile/app/book-appointment.tsx`.)
- **Beta data: accounts dated wrongly** — the demo patient's account was
  created after their own visits, and about 175 graduate-student accounts were
  dated as far back as 2006 and never visited. → Accounts are dated to the
  patient's enrollment (June of the year in their ID number); anyone already at
  the clinic when the history begins registered in the weeks before it.
  (`DemoHistorySeeder.php`, `BetaDemoSeeder.php`.)

## 2026-10-13

- **Database changes could not be undone** — rolling back two of this
  revision's migrations failed (SQLite: a column was dropped before the index
  on it; MySQL: an index was dropped that a foreign key still used). → Steps
  reordered; both now roll back and re-apply cleanly with data.
  (`2026_10_11_120002…`, `2026_10_11_120003…`.)
- **A queue click could fail when the live-update server was down** — each
  live update waited about 2 seconds for a server that never answered, and one
  queue action sends a dozen, exceeding PHP's 30-second limit. → After one
  failure, live updates pause for 30 seconds; timeouts added.
  (`api/app/Support/Realtime.php`, `config/broadcasting.php`.)
- **Status badges had no colour on the phone** — Completed, Declined and
  No-show were unstyled because the styling tool did not read the `lib/`
  folder where their classes live. → Folder added to the scan.
  (`mobile/tailwind.config.js`.)
- **TV board showed a giant blue bar** when nobody was being served. → Shows
  "Please wait to be called". (`web/src/pages/TvBoard.tsx`.)
- **Two libraries had security advisories** — `league/commonmark` (API) and
  `source-map-js` (web build). → Updated to patched versions.
- **Spreadsheet formulas in CSV exports** — text a patient typed, such as a
  booking reason, could run as a formula when opened in Excel. → Such cells are
  prefixed with an apostrophe. (`web/src/lib/csv.ts`.)
- **Forecast model chosen by tie order** — the wait-time model showed "λ 0"
  (no penalty) because every penalty scored the same and the first won. → The
  strongest penalty within one standard error of the best is chosen. A model
  now replaces the simple formula only when its advantage is more than twice
  the noise, so a 1% edge by luck no longer switches it on.
  (`TrainMlModels.php`, `ModelEvaluator.php`.)

## 2026-10-12

**Security**
- **One person could claim a classmate's ID number** — the nurse's ID lookup
  would then check the real student in on the impostor's account, and the
  visit and certificate were saved where the impostor could read them. → IDs
  typed by patients show "ID unchecked" until the nurse compares the card;
  the lookup shows birthdate and email; "Not this person" takes the ID off the
  wrong account; a checked ID cannot be changed from the app.
  (`PatientIdentityController.php`, `WalkInPanel.tsx`.)
- **The live queue feed was open to the dentist and physician**, including
  names, phone numbers and booking reasons. → Nurse only. (`routes/channels.php`.)

**Booking**
- **Double-tapping Confirm made two bookings** — the same-day check ran before
  the slot was locked. → Checked again inside the lock.
- **The calendar offered day 60, but booking it was refused** — the two used
  different cut-offs. → Both use the end of day 60.
- **Late in the day, today showed "Full"** when its times had only passed. →
  Shown as past, not full.
- **The dentist or physician could complete next week's booking early.** →
  A visit can only be recorded on or before the booking's day.
- **A booking vanished from "Upcoming" at its time**, so a patient arriving
  five minutes late could not check in on it. → Confirmed bookings stay
  upcoming all day; the phone card says when the time has come or passed.
- **The nurse could not check in a booked patient from the web**, so they lost
  their place in line. → "Check in" on today's confirmed bookings, and the ID
  lookup finds the booking.
- **"No-show" and "Cancel" worked on patients already in the queue**, leaving a
  stuck ticket. → Refused; Skip in the queue settles both.
- **Finishing or skipping a booked patient did not update their phone.** →
  Now notified, along with the people booked after them.

**Counter, TV and search**
- **A student ID typed without dashes ("231057651") found nobody**, and
  registering them then failed. → Normalised to 23-1057-651.
- **TV "Up next" ignored booked priority** and showed tickets by number. → Shown
  in the order patients will be called; refreshes every minute.
- **Event days showed one day early** (a UTC timestamp). → Plain calendar date.
- **Visit search treated `%` and `_` as wildcards**; a lone `%` matched every
  patient. → Matched literally.

**Phone**
- **Tapping two days quickly could show one day's times under the other.** →
  Only the latest request is shown.
- **Tabs did not refresh when opened**, so a visit just recorded did not
  appear. → Reload on every visit to the tab.
- **The development server address broke tunnel and deployed URLs.** → Swapped
  only between local addresses.

**Web**
- **Fast filter changes could show the previous filter's results** (analytics,
  appointments date, queue history). → Only the newest reply is shown.
- **Confirm / Decline were off-screen on a phone-sized window.** → Cards below
  640 pixels wide.
- **A bundle too large** (520 kB). → Pages load on demand (299 kB).
