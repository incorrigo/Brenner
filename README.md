# Private Database Gateway

This project is a PHP-only HTTPS JSON API for a C# Windows desktop client that needs indirect access to MySQL or MariaDB data on a hosted web system.

The desktop client talks to `public/api.php`.
PHP talks to MySQL on `localhost:3306`.
The desktop client never receives database credentials.

## What Changed

- Browser-first assumptions were removed.
- The API now accepts `POST` JSON only.
- The API now enforces `HTTPS`.
- Stateless bearer tokens were replaced with a rotating single-use session GUID.
- The server replays the last response when the exact same consumed GUID is retried.
- Response payloads now use a stable envelope for C# DTO deserialization.
- A sample C# caller was updated at `examples/CSharp/HTTPSAPIGatewayClient.cs`.

## Files

- `config/app.php`: app-wide settings such as timezone and HTTPS enforcement.
- `config/auth.php`: rotating session settings and desktop client auth config.
- `config/auth.local.php.example`: local-only desktop client secret hashes.
- `config/databases.php`: fixed MySQL connection defaults for `localhost:3306`.
- `config/databases.local.php`: local-only database name, username, and password.
- `config/actions.php`: allowlisted actions and required scopes.
- `storage/api_sessions/`: runtime session storage for rotating command GUIDs.
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

Login request:

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
  "action": "LINK.PING",
  "session": {
    "session_id": "f2cb8d9e-2af7-4f93-9f33-e0f5841dca74",
    "command_guid": "8ef175e9-e388-4c83-940b-6f4f97d58868"
  },
  "params": {}
}
```

Success response envelope:

```json
{
  "ok": true,
  "request_id": "d3c48f7f4f264d7ea0d0f4d1d1684f51",
  "timestamp": "2026-03-14T02:00:00+00:00",
  "action": "LINK.PING",
  "data": {
    "authenticated": true,
    "message": "Rotating link is alive."
  },
  "meta": {
    "method": "POST",
    "type": "static"
  },
  "client": {
    "client_id": "DESKTOP001",
    "scopes": [
      "users.read"
    ]
  },
  "session": {
    "session_id": "f2cb8d9e-2af7-4f93-9f33-e0f5841dca74",
    "command_guid": "3cfb7b95-358c-4f45-b0aa-5c37a9ee25d2",
    "sequence": 1,
    "expires_at": "2026-03-14T02:30:00+00:00"
  },
  "error": null
}
```

Error response envelope:

```json
{
  "ok": false,
  "request_id": "d3c48f7f4f264d7ea0d0f4d1d1684f51",
  "action": "LINK.PING",
  "data": null,
  "meta": null,
  "client": {
    "client_id": "DESKTOP001",
    "scopes": [
      "users.read"
    ]
  },
  "session": {
    "session_id": "f2cb8d9e-2af7-4f93-9f33-e0f5841dca74",
    "command_guid": "3cfb7b95-358c-4f45-b0aa-5c37a9ee25d2",
    "sequence": 1,
    "expires_at": "2026-03-14T02:30:00+00:00"
  },
  "error": {
    "http_status": 422,
    "code": "VALIDATION_MISSING_PARAMETER",
    "message": "Missing required parameter \"id\".",
    "details": {
      "parameter": "id"
    }
  },
  "timestamp": "2026-03-14T02:00:00+00:00"
}
```

## Replay Behavior

If the server consumed a command GUID and the response was lost in transit, the client can retry the exact same request with the same consumed GUID. The gateway will replay the cached previous response instead of breaking the session.

If the client retries a different request body with a consumed GUID, the gateway rejects it.

## Current Example Actions

- `AUTH.OPEN_LINK`: issue a session ID and the first command GUID.
- `SYSTEM.STATUS`: unauthenticated health check.
- `LINK.PING`: authenticated protocol probe that does not depend on the database schema.
- `LINK.ECHO`: authenticated echo action used for replay and retry verification.
- `USERS.LIST`: authenticated SQL read action requiring `users.read`.
- `USER.BY_ID`: authenticated SQL read action requiring `users.read`.

## Security Notes

- This API does not bypass network reachability. PHP still has to be able to reach MySQL directly.
- Do not expose raw SQL to the desktop client.
- Keep database credentials and auth secrets in `*.local.php` files only.
- Do not SHA-512 the MySQL password before giving it to PDO. PDO needs the real password.
- The rotating command GUID is not a substitute for `HTTPS`; it assumes a protected transport.
- A single embedded desktop client secret is still extractable. For real per-user auth, replace `AUTH.OPEN_LINK` with your own user login flow.
