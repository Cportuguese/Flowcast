# Flowcast — Deployment & Security Checklist

Everything in the code is already hardened; this is the short list of things
that can only be done on the server, plus the settings that must be changed
from their development values. Work through it top to bottom before the
first production deploy, and again after any infrastructure change.

## 1. API (`api/`)

### Environment (`.env` on the server)

| Key | Production value | Why |
|---|---|---|
| `APP_ENV` | `production` | Disables dev-only behaviour. |
| `APP_DEBUG` | `false` | **Required.** `true` leaks stack traces, config and DB credentials on any error. |
| `APP_URL` | `https://<api-domain>` | Used in generated links. |
| `CORS_ALLOWED_ORIGINS` | `https://<web-domain>` | Only the web app's origin. Comma-separate if there is more than one (e.g. a separate TV-board host). Must include whatever origin loads the web app or live queue updates break (`broadcasting/auth` is CORS-checked). |
| `RATE_LIMIT_API` | `120` | Per authenticated user, per minute. |
| `RATE_LIMIT_GUEST` | `300` | Per IP for unauthenticated requests. High on purpose: a campus network sits behind one NAT. |
| `RATE_LIMIT_AUTH` | `5` | Login/register attempts per (email, IP) per minute. |
| `RATE_LIMIT_PUBLIC` | `60` | TV board fetches per IP per minute. |
| `REVERB_APP_SECRET`, `APP_KEY`, `DB_PASSWORD` | fresh values | Rotate anything that was ever used in development. `php artisan key:generate` for `APP_KEY`. |
| `REVERB_SCHEME` | `https` | WebSockets over TLS. |
| `SESSION_SECURE_COOKIE` | `true` | Only matters for `/up` and the CSRF cookie route, but set it. |

Rate limits are read at request time, so bumping them for a busy day
(orientation, intramurals, field-trip medicals) is an `.env` edit plus
`php artisan config:clear` — no code change or redeploy.

### Install

```sh
composer install --no-dev --optimize-autoloader   # --no-dev is REQUIRED (see below)
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan storage:link      # not needed for certificates (private disk), harmless
```

**`--no-dev` is a security requirement, not an optimisation.** `laravel/boost`
is a dev dependency that registers an unauthenticated `POST /_boost/browser-logs`
route. It must never exist on a production server.

### Staff accounts

Never create admin/personnel users in tinker — `role` is not mass-assignable,
so `User::create([... 'role' => 'admin'])` silently produces a *patient*.
Use the command, which also writes an audit entry:

```sh
php artisan make:staff nurse@school.edu                       # personnel, random password printed
php artisan make:staff head@school.edu --role=admin --name="Dr. Cruz"
```

Patients self-register from the mobile app.

### Background processes (must be running)

| Process | Command | Purpose |
|---|---|---|
| Scheduler | `php artisan schedule:run` every minute via cron, or `schedule:work` as a service | Prunes expired tokens daily; generates analytics snapshots. |
| Reverb | `php artisan reverb:start` behind TLS | Live queue / TV board updates. |
| Queue worker | `php artisan queue:work` (only if any job is ever queued) | Currently nothing is queued; broadcasts are `ShouldBroadcastNow`. |

### Files

- Certificates are written to the **private** local disk
  (`storage/app/private/certificates/<year>/`). Keep that directory out of
  the web root and in your backup set.
- `storage/` and `bootstrap/cache/` need to be writable by the PHP user.

## 2. Web app (`web/`)

Build with the production API and Reverb values in `web/.env`:

```sh
VITE_API_URL=https://<api-domain>/api
VITE_REVERB_HOST=<api-domain>
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
npm run build
```

The build injects a **Content-Security-Policy** `<meta>` tag whose
`connect-src` is derived from those values. If the API or WebSocket origin
changes, rebuild. Extra origins go in `VITE_CSP_CONNECT_SRC` (comma-separated).

Serve `dist/` as static files with SPA fallback (every unknown path → `index.html`).
Serving it from the **same domain as the API** (e.g. API under `/api`) is
the simplest layout: CORS stops applying entirely and the CSP `connect-src`
is just `'self'`.

Add at the web server (these can't be set from a `<meta>` tag):

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
```

## 3. Mobile app (`mobile/`)

`mobile/.env` for the production build:

```sh
EXPO_PUBLIC_API_URL=https://<api-domain>/api
EXPO_PUBLIC_REVERB_HOST=<api-domain>
EXPO_PUBLIC_REVERB_PORT=443
EXPO_PUBLIC_REVERB_SCHEME=https
```

Tokens are stored in the device keychain (`expo-secure-store`) and expire
after 30 days. Downloaded certificates are deleted from the app cache after
the share sheet closes.

## 4. Accepted tradeoffs (documented on purpose)

**Web session token in `localStorage`.** The web app authenticates with a
Sanctum bearer token kept in `localStorage`. Any successful XSS could read it
and act as that user until the token expires. We chose this over Sanctum's
cookie/SPA mode because the same API must also serve the mobile app with
bearer tokens, and cookie mode adds CSRF handling, stateful-domain
configuration and same-site constraints that don't fit a two-client system.
Mitigations in place: an 8-hour web token lifetime, a strict CSP that blocks
inline and third-party scripts, no `dangerouslySetInnerHTML` anywhere,
server-side revocation on logout, and audit logging of every login.

**Mobile offline cache holds the patient's own visit summaries.** The
patient app keeps a last-known copy of the signed-in patient's queue entry,
appointments and visit list (date, complaint, diagnosis) in the app's
private storage, so the three main screens still render in the clinic
waiting room where there is no signal. It is unencrypted but sandboxed to
the app, only ever contains the signed-in patient's own records, is used
only when a request never reached the server, and is wiped on logout and
on any 401. This matches how mainstream health apps behave on a personal
device and was judged an acceptable tradeoff for a usable offline screen.

**Revoked tokens do not close an already-open WebSocket.** Logging out
elsewhere or changing a password revokes the other device's token, and its
next API call fails with 401 and signs it out — but Reverb does not
re-check auth on an open connection, so that device's live subscription
persists until the socket reconnects (app backgrounded, network change).
A server-side kick would need Reverb internals; accepted for now.

## 5. Pre-launch verification

```sh
# API
cd api && php artisan test            # must be green
php artisan route:list                # every api/* route shows auth:sanctum + role:, except login/register/queue-board
curl -i https://<api-domain>/api/user # expect 401, JSON body, no stack trace

# Web
curl -s https://<web-domain>/ | grep -o 'Content-Security-Policy[^>]*'   # CSP present
```

## 6. What the code already enforces (no action needed)

- Every endpoint behind `auth:sanctum`; role checks via `role:` middleware;
  in-controller ownership checks on the four "own record" endpoints.
- Token expiry (8h web / 30d mobile), per-token, hard ceiling 30 days.
- Per-user and per-IP rate limiting on every route; login keyed by email+IP.
- `role` not mass-assignable; staff created only via `make:staff`.
- Audit log (append-only, admin read-only) covering auth events, staff
  creation, all queue/appointment/visit/schedule/medicine changes and
  certificate issue + download.
- Inventory stock-out is transactional, locked, never negative.
- Clients tear down the WebSocket on logout and on any 401.
- 198 automated tests including a route × role access matrix.
