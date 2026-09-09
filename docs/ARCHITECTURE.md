# Architecture map

## Authentication (web)

```text
index.php login
→ login_user() password_verify + throttle
→ session_regenerate_id
→ admin pages require_login + require_permission
→ logout.php destroys session
```

## Worker authentication

```text
Admin registers worker
→ random token shown once
→ SHA-256 stored in workers.token_hash
→ Worker config.ini / WABOT_WORKER_TOKEN
→ Authorization: Bearer <token>
→ authenticate_worker()
```

## Messaging

```text
Campaign / send-now
→ render_template
→ message_jobs rows
→ worker claim_next_job (SKIP LOCKED)
→ WhatsApp Web send
→ report_job_result (owner check, idempotent)
→ recalculate_campaign_stats
```

## Scheduling

```text
scheduled_at (UTC)
→ activate_due_scheduled_jobs()
→ pending → claimable
```

## Retry / stale recovery

```text
failed report with attempts < max → pending
attempts >= max → failed
processing + old claimed_at + stale heartbeat → requeue/fail
```

## Media

```text
admin upload → MIME/ext/size checks → random stored_name
→ worker download only if owns processing job with that media_id
```
