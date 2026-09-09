# WhatsApp Bot Control Panel — Deployment Guide

## Architecture

```text
PHP Web Control Panel  →  PHP REST API  →  MySQL
                                ↑
                     Windows Python Worker (Bearer token)
                                ↓
                     Playwright → Edge/Chrome → WhatsApp Web
```

Worker never connects to MySQL. Delivery is **at-least-once** with WhatsApp uncertainty (not exactly-once).

## 1. Web server (XAMPP / Apache + PHP 8.3+)

1. Copy project so `web/` is served, e.g. `C:\xampp\htdocs\whatsapp_bot\web`
2. Copy `web/config.example.ini` → `web/config.local.ini`
3. Set database credentials and `base_url`
4. Ensure Apache allows `.htaccess` (`AllowOverride All`)
5. PHP extensions: `pdo_mysql`, `fileinfo`, `json`, `mbstring`

## 2. Database

```bash
mysql -u root -p < database/schema.sql
```

Default login:

- Username: `admin`
- Password: `admin123`

**Change the password immediately** in Settings.

## 3. Worker (Windows)

1. Install Python 3.12+
2. Run `worker\scripts\1_INSTALL.bat`
3. In Control Panel → Workers → Register worker → copy token (shown once)
4. Copy `worker\config.example.ini` → `worker\config.ini`
5. Set:

```ini
[api]
base_url = http://YOUR_HOST/whatsapp_bot/web/api/worker
worker_token = PASTE_TOKEN_HERE
```

6. Run `worker\scripts\2_LINK_WHATSAPP.bat` and scan QR
7. Keep worker running with `3_START_WORKER.bat`

## 4. WhatsApp linking notes

- Do not reload aggressively while QR is shown
- Persistent profile is under `worker/wa_profile/` (gitignored)
- If you see `authentication_required`, re-run link script and scan QR
- Unofficial automation; WhatsApp UI changes can break selectors

## 5. Production hardening

- HTTPS + `secure_cookies = 1`
- Strong `app_key` and DB password
- Disable `display_errors`
- Restrict admin by network / VPN
- Backup MySQL regularly
- Rotate worker tokens if leaked
- Never commit `config.local.ini` or `worker/config.ini`

## 6. Smoke test checklist

1. Login / logout / wrong password
2. Create contact + CSV import
3. Create template with `{{name}}`
4. Register worker + heartbeat online
5. Link WhatsApp (QR → connected)
6. Send-now to a consenting test number
7. Pause / cancel campaign
8. Confirm message + audit logs
