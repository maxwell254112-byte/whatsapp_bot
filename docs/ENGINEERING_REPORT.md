# Engineering Report — WhatsApp Bot Control Panel (greenfield build)

## A. Architecture summary

Internal consent-based messaging control panel:

- **Web (PHP 8.3 + MySQL 8)**: session auth, CSRF, roles/permissions, contacts, templates, campaigns, media, workers, logs, settings.
- **API**: worker Bearer-token endpoints for auth check, heartbeat, atomic job claim, result report, media download.
- **Worker (Python + Playwright)**: persistent browser profile, centralized WhatsApp Web adapter, heartbeat loop, claim/send/report, temp media cleanup.
- **Queue semantics**: at-least-once execution; stale `processing` jobs recovered when worker heartbeat is stale; duplicate reports are idempotent; workers cannot report jobs they do not own.

Timezone rule: user input = `Asia/Kuala_Lumpur`, DB/API timestamps = UTC, UI displays local.

## B. Files changed

Greenfield project — all files are new. Primary areas:

| Area | Path |
|------|------|
| Schema | `database/schema.sql` |
| PHP core | `web/includes/*` |
| Admin UI | `web/admin/*`, `web/index.php`, `web/assets/*` |
| Worker API | `web/api/worker/*` |
| Worker | `worker/*` |
| Docs/tests | `docs/*`, `tests/*` |

## C. Bugs found

N/A for pre-existing code (built from scratch). Design risks mitigated during build:

| Problem | Impact | Root cause | Fix |
|---------|--------|------------|-----|
| Double job claim | Duplicate WhatsApp sends | Concurrent workers | `FOR UPDATE SKIP LOCKED` + status CAS update |
| Stale processing | Jobs stuck forever | Worker crash | Heartbeat + claimed_at stale recovery |
| Foreign worker report | Counter corruption / spoofed success | Missing ownership check | `worker_id` must match + status=`processing` |
| QR reload race | Session destroyed / `post_logout` loop | Aggressive navigation | Wait without reload; single recovery navigation |
| Counter drift | Wrong campaign stats | Increment-only counters | Recalculate from `message_jobs` |

## D. Security findings

Built-in controls (informational baseline for a new system):

| Severity | Finding | Status |
|----------|---------|--------|
| Critical | None known in shipped defaults if password changed | — |
| High | Default admin password in schema | Must change on deploy |
| High | Worker token plaintext only at creation | Shown once; stored SHA-256 |
| Medium | Dev `secure_cookies=0` / `display_errors=1` | Harden for production |
| Medium | Unofficial WA automation account risk | Documented limitation |
| Low | Login throttle 10 / 15 min / IP+user | Implemented |
| Info | Uploads blocked via `.htaccess` | Authenticated download only |

## E. Database changes

Initial schema in `database/schema.sql` (tables: users, workers, contacts, contact_groups, contact_group_members, message_templates, media_files, campaigns, message_jobs, message_logs, audit_logs, system_settings, login_throttle).

## F. Worker changes

- Centralized selectors in `worker/whatsapp/selectors.py`
- Adapter methods: detect QR/connected, send, attach, diagnose+screenshot
- Avoids reload while QR visible; handles `post_logout=1` carefully
- Reports ownership-bound results; cleans temp media

## G. API changes

Worker endpoints (Bearer):

- `GET/POST api/worker/auth_check.php`
- `POST api/worker/heartbeat.php`
- `POST api/worker/claim.php`
- `POST api/worker/report.php`
- `GET api/worker/media.php?id=`

JSON envelope: `{success, message, data}`.

## H. Testing

| Test | Expected | Actual | Status |
|------|----------|--------|--------|
| PHP phone normalize | `012…` → `60…` | run_unit_tests | run locally |
| PHP templates | vars replaced, newlines kept | run_unit_tests | run locally |
| Campaign transitions | invalid blocked | run_unit_tests | run locally |
| Python smoke | selectors/version load | test_smoke.py | run locally |
| Concurrent claim | only one worker wins | requires MySQL integration | manual |
| Stale recovery | requeue after timeout | requires runtime | manual |
| E2E WhatsApp send | consenting test number | requires linked WA | manual |

## I. Remaining limitations

1. WhatsApp Web UI can change; selectors need maintenance.
2. Unofficial automation — no ban/safety guarantees.
3. Worker “sent” ≠ recipient delivery receipt.
4. Crash after WA accept / before report ⇒ possible duplicate (at-least-once).
5. Multi-worker global pacing is best-effort (server hourly/daily caps).
6. QR linking needs an interactive browser session.

## J. Deployment instructions

See [DEPLOYMENT.md](DEPLOYMENT.md).
