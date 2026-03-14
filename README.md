# Private Database Gateway

This project is a PHP-only bridge between your website and databases that are not exposed for remote access.

How it works:

1. A browser calls `public/api.php` over normal HTTP or HTTPS.
2. PHP validates the request and maps the requested action to a pre-approved SQL statement.
3. PHP connects to the private database with PDO and returns JSON.

Important constraint:

The machine running this PHP code must already be able to reach the database. This does not bypass network rules. It only moves the database call from the browser into server-side PHP.

## Files

- `config/app.php`: API key, timezone, CORS origins.
- `config/databases.php`: PDO connection profiles.
- `config/actions.php`: Named actions that are allowed to run.
- `public/api.php`: JSON API entry point.
- `public/index.php`: Plain HTML test page.

## Setup

1. Point your web server document root to `public/`.
2. Edit `config/app.php` and replace the API key.
3. Create `config/databases.local.php` from `config/databases.local.php.example`.
4. Fill in the database name, username, and password in `config/databases.local.php`.
5. Edit `config/actions.php` so the SQL matches your real tables.
6. Test `system.status` first, then your real actions.

For your hosting layout, the committed base config already assumes:

- MySQL or MariaDB
- host `localhost`
- port `3306`

Only these values should vary per site:

- database name
- database username
- database password

## Local run

```powershell
php -S 127.0.0.1:8000 -t public
```

Then open `http://127.0.0.1:8000/`.

## API examples

Health check:

```text
GET /api.php?action=system.status
X-Api-Key: change-this-api-key
```

Single record:

```json
POST /api.php
{
  "action": "user.by_id",
  "params": {
    "id": 4
  }
}
```

## Security notes

- Do not expose raw SQL from the browser.
- Keep credentials only in PHP config files.
- Do not SHA-512 the database password before giving it to PDO. The MySQL client needs the real password to authenticate.
- Restrict CORS to exact origins if another site needs to call this API.
- Prefer action-specific queries instead of a generic query endpoint.
