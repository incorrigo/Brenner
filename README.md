# Private Database Gateway

This project is a PHP-only `HTTPS` JSON API for a C# Windows desktop client that needs indirect access to MySQL or MariaDB data on a hosted web system.

The desktop client talks to `public/api.php`.
PHP talks to MySQL on `localhost:3306`.
The desktop client never receives database credentials.

## Files

- `config/app.php`: app-wide settings such as timezone and HTTPS enforcement.
- `config/auth.php`: GUID lifetime and desktop client auth config.
- `config/auth.local.php.example`: local-only desktop client secret hashes.
- `config/databases.php`: fixed MySQL connection defaults for `localhost:3306`.
- `config/databases.local.php`: local-only database name, username, and password.
- `config/actions.php`: allowlisted actions, scopes, and GUID-consumption rules.
- `storage/api_sessions/`: runtime storage for active and spent GUID records.
- `public/api.php`: HTTPS JSON API entry point.
- `public/index.php`: human-readable API reference page.
- `examples/CSharp/HTTPSAPIGatewayClient.cs`: sample C# caller.

## Fixed Database Connection Shape

The committed base config assumes:

- driver: `mysql`
- host: `localhost`
- port: `3306`
- charset: `utf8mb4`

The per-site values that stay outside Git are:

- database name
- database username
- database password

## Setup

1. Point your web server document root to `public/`.
2. Keep `config/databases.php` committed with the non-secret MySQL defaults.
3. Create `config/databases.local.php` from `config/databases.local.php.example`.
4. Put the real database name, username, and password in `config/databases.local.php`.
5. Create `config/auth.local.php` from `config/auth.local.php.example`.
6. Add at least one desktop client in `config/auth.local.php` with a password hash and scopes.
7. Edit `config/actions.php` so the SQL matches your real schema.

Generate a client secret hash with PHP:

```powershell
php -r "echo password_hash('your-client-secret', PASSWORD_DEFAULT), PHP_EOL;"
```

## API Contract

Every request is:

- `POST`
- `Content-Type: application/json`
- `HTTPS`

Your site already has `HSTS`, which is good. The API still checks for `HTTPS` on each request.

Open-link request:

```json
{
  "action": "AUTH.OPEN_LINK",
  "params": {
    "client_id": "DESKTOP001",
    "client_secret": "your-client-secret"
  }
}
```

Authenticated request:

```json
{
  "action": "LINK.ECHO",
  "guid": "8ef175e9-e388-4c83-940b-6f4f97d58868",
  "params": {
    "nonce": "retry-me"
  }
}
```

Success response envelope:

```json
{
  "ok": true,
  "request_id": "d3c48f7f4f264d7ea0d0f4d1d1684f51",
  "timestamp": "2026-03-14T02:00:00+00:00",
  "action": "LINK.ECHO",
  "data": {
    "echo": {
      "nonce": "retry-me"
    },
    "message": "Echo accepted."
  },
  "meta": {
    "method": "POST",
    "type": "echo"
  },
  "client": {
    "client_id": "DESKTOP001",
    "scopes": [
      "gateway.basic"
    ]
  },
  "guid": "3cfb7b95-358c-4f45-b0aa-5c37a9ee25d2",
  "guid_sequence": 1,
  "guid_expires_at": "2026-03-14T02:30:00+00:00",
  "error": null
}
```

## GUID Policy

- `AUTH.OPEN_LINK` returns the first GUID.
- Non-consuming authenticated actions such as `LINK.INFO` and `LINK.REQUIRE_TAG` keep the same GUID and refresh its expiry.
- Consuming actions return a fresh GUID. The current schema-free consuming action is `LINK.ECHO`.
- SQL actions with `'result' => 'write'` consume the GUID by default unless you override them.

## Replay Behavior

If a consuming action succeeded on the server but the response was lost in transit, the client can retry the exact same request with the same spent GUID. The gateway replays the cached prior response, including the replacement GUID.

If the client retries a different request body with a spent GUID, the gateway rejects it with `AUTH_GUID_ALREADY_USED`.

## Current Example Actions

- `AUTH.OPEN_LINK`: issue the first GUID.
- `SYSTEM.STATUS`: unauthenticated status check.
- `LINK.ECHO`: authenticated echo action used for replay and retry verification. This action consumes the GUID.
- `LINK.INFO`: authenticated schema-free probe. This action reuses the GUID.
- `LINK.REQUIRE_TAG`: authenticated schema-free validation probe requiring `params.tag`. This action reuses the GUID.

## Security Notes

- This API does not bypass network reachability. PHP still has to be able to reach MySQL directly.
- Do not expose raw SQL to the desktop client.
- Keep database credentials and auth secrets in `*.local.php` files only.
- Do not SHA-512 the MySQL password before giving it to PDO. PDO needs the real password.
- The rotating GUID does not replace `HTTPS`; it assumes a protected transport.
- A single embedded desktop client secret is still extractable. For real per-user auth, replace `AUTH.OPEN_LINK` with your own user login flow.
- Table-specific SQL is intentionally not enabled by default. Add your own SQL actions in `config/actions.php` for your real schema.
