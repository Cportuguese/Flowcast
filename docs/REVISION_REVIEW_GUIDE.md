# Alpha revision — review guide

Everything below is **uncommitted** on `main` (83 changed files, 64 new). This
guide walks through it in the order that makes the most sense to review, says
where each change lives, and how to see it working. Links open the files in
VS Code (Ctrl+click in the Markdown preview).

> Delete this file once you've reviewed — it describes the work, not the system.

## 1. Seeing all the changes

```sh
git status                  # two deletions are already staged (old home pages)
git diff HEAD --stat        # every change, staged or not, against the last commit
```

In VS Code, the Source Control panel lists the same files. New files show as
"U" (untracked).

**Suggested order:** routes → booking API → queue API → visits/certificates →
security fixes → web pages → phone screens → ML → seeders/tests.

## 2. What the alpha panel asked for, and where it is

Log in at the web app with these (password `password` for all):
`admin@flowcast.test` (Nurse Portia), `dentist@flowcast.test`,
`doctor@flowcast.test`. The phone app uses `patient@flowcast.test`.

| Panel request | What was built | Key files | Try it |
|---|---|---|---|
| Nurse Portia is head/admin; dentist and doctor get schedule views | Role-based navigation: Portia runs everything; the dentist and doctor see **My Schedule** and **Visit Records** only | [api.php](../api/routes/api.php), [AppShell.tsx](../web/src/components/AppShell.tsx), [NurseHome.tsx](../web/src/pages/home/NurseHome.tsx), [MySchedule.tsx](../web/src/pages/home/MySchedule.tsx) | Log in as each of the three |
| Book one of three professionals; schedules Portia Mon–Sat 8–6, dentist Tue/Thu 8–5, doctor Fri 1–3 | Per-professional hours and slots | [ProviderController.php](../api/app/Http/Controllers/Api/ProviderController.php), [ClinicSetupSeeder.php](../api/database/seeders/ClinicSetupSeeder.php), [ProviderHoursSection.tsx](../web/src/components/ProviderHoursSection.tsx) | Web: Clinic Settings → "When patients can book each professional" |
| Booking like a calendar: pick a day, then times appear | Month calendar → times → reason → health record check → emailed code | [AppointmentController.php](../api/app/Http/Controllers/Api/AppointmentController.php) (`calendar`, `availableSlots`, `requestOtp`, `store`), [book-appointment.tsx](../mobile/app/book-appointment.tsx), [MonthCalendar.tsx](../mobile/components/MonthCalendar.tsx) | Phone: Appointments → Book a new appointment |
| OTP confirmation for bookings | 6-digit email code: hashed, 10 min, 5 tries, one per minute, tied to the slot | [BookingOtp.php](../api/app/Services/BookingOtp.php), [BookingCode.php](../api/app/Mail/BookingCode.php) | In development the code is in `api/storage/logs/laravel.log` |
| "Your booking is under review"; nurse confirms; "you'll be served at X, N people ahead" | Bookings start `pending`; Portia confirms or declines; the phone shows the time and people ahead | `approve`/`decline`/`peopleAhead` in [AppointmentController.php](../api/app/Http/Controllers/Api/AppointmentController.php), [ReviewActions.tsx](../web/src/components/ReviewActions.tsx), [appointments.tsx (phone)](<../mobile/app/(tabs)/appointments.tsx>) | Web: Today → "Bookings to review" |
| Department option everywhere; registration with department; counts by department (visited/skipped) with a filter | UDD schools and courses; analytics and reports by department/course | [udd.php](../api/config/udd.php), [Udd.php](../api/app/Support/Udd.php), [DepartmentBreakdown.php](../api/app/Services/DepartmentBreakdown.php), [AnalyticsDashboard.tsx](../web/src/pages/AnalyticsDashboard.tsx), [Reports.tsx](../web/src/pages/Reports.tsx) | Web: Analytics → "Who came, by department"; Reports → Department summary |
| Student ID like 23-1057-651, not used in predictions | Format checked at sign-up; not an input to any model | `idNumberFormat` in [HealthRecord.php](../api/app/Support/HealthRecord.php) | Phone: sign up |
| Visit search by department, full name, middle name, birthdate, ID | All of those filters | `search` in [VisitController.php](../api/app/Http/Controllers/Api/VisitController.php), [VisitsSearch.tsx](../web/src/pages/VisitsSearch.tsx) | Web: Visit Records |
| "Diagnosis" → "Intervention" (and "Treatment" → "Remarks") | Column rename + every screen, report and the certificate | [rework_visit_records migration](../api/database/migrations/2026_10_11_120003_rework_visit_records_for_interventions.php), [VisitFormModal.tsx](../web/src/components/VisitFormModal.tsx), [medical.blade.php](../api/resources/views/certificates/medical.blade.php) | Record a visit, issue a certificate |
| Validated patient health record (full middle name, birthdate, sex, address, department, ID) | Shared rules for sign-up, profile edits and walk-ins | [HealthRecord.php](../api/app/Support/HealthRecord.php), [HealthRecordForm.tsx](../mobile/components/HealthRecordForm.tsx), [register.tsx](../mobile/app/register.tsx), [health-record.tsx](../mobile/app/health-record.tsx) | Phone: sign up (2 steps); account menu → My health record |
| Phone prediction more visible | Bigger estimate, "likely called around" time | [index.tsx (phone Home)](<../mobile/app/(tabs)/index.tsx>) | Phone: check in, then Home |
| Analytics for a 3-professional clinic | Wait-time model rebuilt for one nurse; professionals table | [WaitTimeFeatures.php](../api/app/Services/Ml/WaitTimeFeatures.php), [TrainMlModels.php](../api/app/Console/Commands/TrainMlModels.php) | Web: Analytics → Model performance |

## 3. Fixes from the review passes (after the revision was built)

**Security review**
- **ID numbers are only a claim until checked.** A patient could have signed
  up with a classmate's ID; the nurse's lookup would then put the classmate's
  visit on the impostor's account. Now: self-entered IDs show **"ID unchecked"**
  to staff, the lookup shows birthdate and email to compare, **"Card matches —
  check in"** marks it checked, **"Not this person"** takes the number off the
  wrong account, and a checked ID can't be changed from the phone.
  [PatientIdentityController.php](../api/app/Http/Controllers/Api/PatientIdentityController.php),
  [WalkInPanel.tsx](../web/src/components/WalkInPanel.tsx),
  tests: [PatientIdentityTest.php](../api/tests/Feature/Security/PatientIdentityTest.php).
  *Try it:* Live Queue → Register walk-in → ID `26-1102-457` (sample newcomer).
- The live queue feed is the nurse's only ([channels.php](../api/routes/channels.php)).
- CSV exports neutralise spreadsheet formulas ([csv.ts](../web/src/lib/csv.ts)).
- Two library advisories patched: `league/commonmark` (API) and `source-map-js` (web build).

**Bugs found and fixed** (each has a test or was checked in the browser)
- Booking: double-tapped Confirm made two bookings; calendar vs. booking
  disagreed on day 60; today showed "Full" when its times had just passed;
  the dentist could complete next week's booking early.
- Counter/TV: a late booked patient couldn't check in on the booking; the
  nurse couldn't check in a booked patient from the web; "No-show"/"Cancel"
  on someone already in the queue; IDs typed without dashes found nobody;
  TV "Up next" ignored booked priority; event days showed a day early.
- Reliability: with the realtime server (Reverb) down, a queue action could
  exceed PHP's 30-second limit ([Realtime.php](../api/app/Support/Realtime.php));
  three migrations could not be rolled back on MySQL with data.
- Phone: tabs didn't refresh when opened; tapping two days quickly could show
  the wrong day's times; Completed/Declined/No-show badges had no colour
  ([tailwind.config.js](../mobile/tailwind.config.js) — restart Expo with
  `npx expo start -c` once so it rebuilds the styles).
- Web: fast filter changes could show the previous filter's results; visit
  search treated `%` and `_` as wildcards.
- ML: a model now replaces the simple formula only on a **clear** win (more
  than twice the noise), and the ridge penalty is chosen by the
  one-standard-error rule — see `chooseLambda`/`shouldPromote` in
  [TrainMlModels.php](../api/app/Console/Commands/TrainMlModels.php).

## 4. What was verified, and how to re-run it

| Check | Result | Command |
|---|---|---|
| API tests | 395 passed | `cd api && php artisan test` |
| PHP style | clean | `cd api && vendor/bin/pint --test` |
| Web type-check + build | clean (no size warning) | `cd web && npm run build` |
| Web lint | 0 errors, 10 warnings (data-loading style, also in older pages) | `cd web && npm run lint` |
| Phone type-check / Android bundle | clean | `cd mobile && npx tsc --noEmit` / `npx expo export --platform android` |
| Migrations undo and redo | all six new ones, SQLite and MySQL with data | `php artisan migrate:rollback --step=6` then `php artisan migrate` (on a copy!) |
| Full lifecycle on MySQL (58 checks) | passed, with Reverb both running and down | sign-up → book nurse + dentist with codes → confirm → check in → called → visit + medicine + certificate → dentist visit → role limits → analytics |
| Every web page, as each role | rendered, no console errors (also the production build) | — |
| Phone screens (rendered in a browser) | sign-up, Home, Appointments, Visits, health record, full booking with a code | — |

The end-to-end and screenshot scripts were run from a temporary folder and are
not part of the project.

## 5. Still yours to decide or do

- **Real booking emails:** put a Gmail address and app password in `api/.env`
  (see [DEPLOYMENT.md](../DEPLOYMENT.md), section 1). Until then codes are only in the log.
- **UDD department codes:** only SITE, STE and SIHM are confirmed; check the
  rest with the registrar ([udd.php](../api/config/udd.php)).
- **Phone dependency advisories:** `npm audit` in `mobile/` lists 34, all in
  Expo's development tools (bundler/CLI), not in the installed app. Don't run
  `npm audit fix --force` there; it breaks Expo's pinned versions.
- **Not run on a real phone** in this round — the phone screens were checked by
  rendering them in a browser. Worth one pass on your demo phone.
- **Commit** when you're happy (nothing is committed yet).

## 6. Demo data

```sh
cd api
# Beta test: three years of history, 4,000 patients (about 4 minutes)
php -d memory_limit=2G artisan migrate:fresh --seed --seeder=BetaDemoSeeder
# Alpha demo: six months, 240 patients (about 40 seconds)
php artisan migrate:fresh --seed --seeder=AlphaDemoSeeder
# Either way, each demo morning, so "Today" has a queue to show:
php artisan db:seed --class=AlphaTodaySeeder
```

The beta data is in the database now. `BetaDemoSeeder` is in
[BetaDemoSeeder.php](../api/database/seeders/BetaDemoSeeder.php); a student only
appears in visits after the year in their ID number, and the inventory is
delivered about every quarter.

The sample history is synthetic and is generated relative to the day you run
it, so the model figures on the Analytics page change slightly with each reset.
