# Brenner

PHP-only HTTPS API gateway for databases that are reachable only from the web host itself (for example MySQL/MariaDB on `localhost:3306` in shared hosting).

Brenner lets a desktop client (for example C#) call your hosted API, while PHP performs all database access server-side. The client never receives direct database credentials.

## What Brenner Solves

On many shared hosting plans:

- Database servers are not remotely reachable from your desktop app.
- PHP can still connect locally (`localhost:3306`).

Brenner bridges that gap:

1. Desktop app authenticates with API client credentials.
2. API issues a GUID token.
3. Desktop app calls allowlisted actions with that GUID.
4. PHP executes database work locally and returns JSON.

## Core Model

- Transport: HTTPS only.
- Request format: JSON via `POST` to `public/api.php`.
- Authentication: rotating GUID tokens.
- Authorization: per-client scopes (`db.read`, `db.write`, `db.admin`).
- Query safety:
  - `DB.READ` is read-only by keyword policy.
  - `DB.WRITE` is write-only by keyword policy.
  - `DB.EXECUTE` is intentionally full-power (admin scope required).

## GUID Semantics

- `AUTH.OPEN_LINK` returns the first GUID (`guid_sequence = 0`).
- Non-consuming actions reuse the same GUID.
- Consuming actions rotate to a new GUID.
- Exact retries of a spent consuming request replay the cached response.
- Mismatched retries on a spent GUID return `AUTH_GUID_ALREADY_USED`.
- After a later consume, older spent GUID replay records are pruned.

## Feature Summary

- Pure PHP + HTML project (no Node.js, no Vite).
- Config layering with safe committed defaults + local secret overrides.
- Case-insensitive client ID matching with ambiguity protection.
- Built-in fallback action catalog (guards against stale deploys missing core actions).
- File-based GUID/session store with locking.
- Schema discovery actions (`DB.PROFILES`, `DB.TABLES`, `DB.COLUMNS`).
- Data/DDL execution actions with scope gates.
- C# sample client at `examples/CSharp/HTTPSAPIGatewayClient.cs`.

## Repository Layout

- `public/api.php`: API entrypoint and envelope/error handling.
- `public/index.php`: human-readable action and usage page.
- `src/API/APIActionRunner.php`: action dispatch + parameter + SQL policy.
- `src/Auth/ClientCredentialStore.php`: client auth + scope normalization.
- `src/Auth/RotatingGUIDSessionStore.php`: GUID lifecycle and replay handling.
- `src/Database/DatabaseManager.php`: PDO profile management.
- `config/app.php`: runtime app settings.
- `config/auth.php`: auth defaults and clients.
- `config/databases.php`: DB profile defaults and profiles.
- `config/actions.php`: action catalog (allowlist).
- `config/*.local.php`: local secret overrides (excluded from Git).

## Requirements

- PHP `8.1+` (uses `array_is_list` and modern type features).
- PDO extension and relevant driver (`pdo_mysql` for MySQL/MariaDB).
- Writable session storage directory (`storage/api_sessions` by default).
- HTTPS on the public endpoint.

## Quick Start

1. Deploy this repository to your host.
2. Point web root to `public/`.
3. Keep committed defaults in:
   - `config/app.php`
   - `config/auth.php`
   - `config/databases.php`
   - `config/actions.php`
4. Create local secret files on host:
   - `config/auth.local.php`
   - `config/databases.local.php`
5. Ensure `storage/api_sessions` is writable by PHP.
6. Set `debug` to `false` in production (`config/app.php`).
7. Call `SYSTEM.STATUS`, then `AUTH.OPEN_LINK`, then authenticated actions.

Generate a password hash for API client secrets:

```powershell
php -r "echo password_hash('your-client-secret', PASSWORD_DEFAULT), PHP_EOL;"
```

## Configuration

### `config/app.php`

Main runtime flags:

- `api_version`: API version string shown in status/docs.
- `timezone`: PHP timezone (example: `Europe/London`).
- `debug`: include exception details when true.
- `default_database_profile`: fallback when request omits `params.database`.
- `require_https`: reject non-HTTPS traffic when true.
- `trust_forwarded_proto`: trust `X-Forwarded-Proto` when behind proxy.

### `config/auth.php` and `config/auth.local.php`

`config/auth.php` should contain safe defaults only. Put real secret hashes in `auth.local.php`.

Supported keys:

- `session_ttl_seconds`: GUID TTL.
- `session_storage_path`: where GUID records are stored.
- `client_defaults`: defaults applied to all clients (for example default scopes).
- `clients`: map of client definitions.

Client definition keys:

- `secret_hash` (required): output from `password_hash`.
- `display_name` (optional).
- `scopes` (optional): string/array/nested arrays are flattened.

Notes:

- Client IDs are matched case-insensitively.
- Ambiguous IDs differing only by case are rejected as config errors.

Example `config/auth.local.php`:

```php
<?php

declare(strict_types=1);

return [
	'client_defaults' => [
		'scopes' => ['db.read'],
	],
	'clients' => [
		'DESKTOP001' => [
			'display_name' => 'Desktop Client 001',
			'secret_hash' => 'paste_password_hash_here',
		],
		'DESKTOP002' => [
			'display_name' => 'Desktop Client 002',
			'secret_hash' => 'paste_password_hash_here',
			'scopes' => ['db.read', 'db.write'],
		],
		'CRITERION' => [
			'display_name' => 'Criterion Super User',
			'secret_hash' => 'paste_password_hash_here',
			'scopes' => ['db.read', 'db.write', 'db.admin'],
		],
	],
];
```

### `config/databases.php` and `config/databases.local.php`

`config/databases.php` contains shared defaults (`_defaults`) and non-secret structure. Put real credentials in `databases.local.php`.

Defaults commonly include:

- `driver` (`mysql`)
- `host` (`localhost`)
- `port` (`3306`)
- `charset` (`utf8mb4`)

Example `config/databases.local.php`:

```php
<?php

declare(strict_types=1);

return [
	'private_mysql' => [
		'database' => 'light-house',
		'username' => 'api001',
		'password' => 'paste_real_password_here',
	],
	'private_mysql_reporting' => [
		'database' => 'light-house-reporting',
		'username' => 'api002',
		'password' => 'paste_real_password_here',
	],
];
```

If a profile sets `driver` to an empty string, Brenner falls back to defaults. Prefer explicitly setting `driver => 'mysql'` if you override this field.

### `config/actions.php`

This file defines the action allowlist and parameter schema. Brenner also ships a built-in fallback action catalog merged with `config/actions.php`, which helps prevent partial deploys from dropping core actions unexpectedly.

## Scope Model

- `db.read`: `DB.PROFILES`, `DB.TABLES`, `DB.COLUMNS`, `DB.READ`
- `db.write`: `DB.WRITE`
- `db.admin`: `DB.EXECUTE`

Important: scope allows API entry, but real capability still depends on database user grants.

## API Contract

### Endpoint

- Path: `/public/api.php` (or `/api.php` if `public/` is docroot)
- Method: `POST`
- Content-Type: `application/json`
- HTTPS: required by default

### Request Envelope

```json
{
  "action": "ACTION.NAME",
  "guid": "optional-for-authenticated-actions",
  "params": {}
}
```

- `action` is required.
- `params` must be a JSON object.
- `guid` is required only for authenticated actions.

Optional header:

- `X-Request-ID`: if valid (8-100 chars, `[A-Za-z0-9._:-]`), echoed in response; otherwise generated.

### Success Envelope

```json
{
  "ok": true,
  "request_id": "d3c48f7f4f264d7ea0d0f4d1d1684f51",
  "timestamp": "2026-03-14T10:06:11+00:00",
  "action": "DB.READ",
  "data": {},
  "meta": {},
  "client": {
    "client_id": "DESKTOP001",
    "scopes": ["db.read"]
  },
  "guid": "fda7ed57-1c46-4f80-8ab8-2b55d771d6f9",
  "guid_sequence": 0,
  "guid_expires_at": "2026-03-14T10:36:11+00:00",
  "error": null
}
```

### Error Envelope

```json
{
  "ok": false,
  "request_id": "abc123...",
  "timestamp": "2026-03-14T10:06:11+00:00",
  "action": "DB.READ",
  "data": null,
  "meta": null,
  "client": null,
  "guid": null,
  "guid_sequence": null,
  "guid_expires_at": null,
  "error": {
    "http_status": 422,
    "code": "VALIDATION_OBJECT_REQUIRED",
    "message": "Parameter \"bindings\" must be an object.",
    "details": {}
  }
}
```

## Built-In Actions

| Action | Auth | GUID | Scope | Purpose |
|---|---|---|---|---|
| `SYSTEM.STATUS` | No | N/A | None | Service/status metadata |
| `AUTH.OPEN_LINK` | No (credential exchange) | Issues first GUID | None | Authenticate client and open GUID session |
| `LINK.INFO` | Yes | Reuse | None | Authenticated probe |
| `LINK.ECHO` | Yes | Consume | None | Echo and replay/rotation testing |
| `LINK.REQUIRE_TAG` | Yes | Reuse | None | Validation probe |
| `DB.PROFILES` | Yes | Reuse | `db.read` | List configured DB profiles |
| `DB.TABLES` | Yes | Reuse | `db.read` | List schema tables (MySQL/MariaDB) |
| `DB.COLUMNS` | Yes | Reuse | `db.read` | List table column metadata |
| `DB.READ` | Yes | Reuse | `db.read` | Run read-only SQL |
| `DB.WRITE` | Yes | Consume | `db.write` | Run DML write SQL |
| `DB.EXECUTE` | Yes | Consume | `db.admin` | Run full-power SQL (admin) |

## SQL Action Rules

### `DB.READ`

- Exactly one statement.
- Trailing semicolon is stripped once.
- Multiple statements are rejected.
- Allowed first keyword: `SELECT`, `SHOW`, `DESCRIBE`, `DESC`, `EXPLAIN`, `WITH`.
- Rejects write-looking `WITH` queries.
- Rejects `INTO OUTFILE`.
- `bindings` must be a JSON object (empty `{}` is valid).
- `max_rows` range: `1..5000`.

### `DB.WRITE`

- Exactly one statement.
- Allowed first keyword: `INSERT`, `UPDATE`, `DELETE`, `REPLACE`.
- `bindings` must be a JSON object.

### `DB.EXECUTE`

- Admin endpoint for full SQL execution.
- `max_rows` range: `1..10000`.
- `all_rowsets` controls multi-rowset collection.
- Designed for superuser workflows (DDL/routines/events/triggers included if DB grants allow them).

### Bindings Rules

- Binding keys must match: `^[A-Za-z_][A-Za-z0-9_]*$` (with or without leading `:` in payload key).
- Values must be scalar (`string`, `number`, `bool`) or `null`.

## Examples

### 1) Open GUID link

```json
{
  "action": "AUTH.OPEN_LINK",
  "params": {
    "client_id": "DESKTOP001",
    "client_secret": "your-client-secret"
  }
}
```

### 2) List DB profiles

```json
{
  "action": "DB.PROFILES",
  "guid": "current-guid",
  "params": {}
}
```

### 3) Read query

```json
{
  "action": "DB.READ",
  "guid": "current-guid",
  "params": {
    "database": "private_mysql",
    "sql": "SELECT DATABASE() AS active_database",
    "bindings": {},
    "max_rows": 25
  }
}
```

### 4) Write query (consumes GUID)

```json
{
  "action": "DB.WRITE",
  "guid": "current-guid",
  "params": {
    "database": "private_mysql",
    "sql": "UPDATE users SET updated_at = NOW() WHERE id = :id",
    "bindings": {
      "id": 1
    }
  }
}
```

### 5) Admin execute (consumes GUID)

```json
{
  "action": "DB.EXECUTE",
  "guid": "current-guid",
  "params": {
    "database": "private_mysql",
    "sql": "CREATE TABLE IF NOT EXISTS demo_admin (id INT PRIMARY KEY AUTO_INCREMENT, note VARCHAR(190) NOT NULL)",
    "bindings": {},
    "max_rows": 500,
    "all_rowsets": true
  }
}
```

## C# Client Integration Pattern

Reference implementation: `examples/CSharp/HTTPSAPIGatewayClient.cs`.

Recommended flow:

1. Call `SYSTEM.STATUS` (optional health check).
2. Call `AUTH.OPEN_LINK` with client credentials.
3. Cache returned `guid`, `guid_sequence`, `guid_expires_at`.
4. Include `guid` on authenticated requests.
5. After each response, replace cached GUID state with response values.
6. For network timeouts on consuming calls, retry once with same payload and same spent GUID to benefit from replay protection.

## Deployment Checklist (Public Hosting)

1. Web root points to `public/`.
2. HTTPS enforced (`require_https = true`).
3. `config/*.local.php` present on server with real secrets.
4. `storage/api_sessions` writable by PHP.
5. `debug = false` for production.
6. If you redeploy action/runner code, clear OPcache or reload PHP workers.
7. If using WAF/bot protection (for example Imunify360), allowlist automation IPs used by integration tests.

## Security Checklist

- Keep database credentials server-side only.
- Keep `config/*.local.php` out of Git.
- Use strong client secrets and rotate regularly.
- Use least-privilege DB users per profile.
- Grant `db.admin` only to trusted clients.
- Keep production `debug` off.
- Log and monitor `request_id`, error codes, and unusual action volumes.

## Troubleshooting

### `DB.*` all return `ACTION_NOT_FOUND`

Cause: stale deploy missing updated action catalog.

Fix:

1. Open `/public/index.php` and check the action table includes `DB.PROFILES`.
2. Redeploy:
   - `config/actions.php`
   - `src/API/APIActionRunner.php`
   - `src/Support/Config.php`
3. Reload OPcache / PHP workers.

### `AUTH_SCOPE_DENIED`

Cause: authenticated client lacks required scope (for example missing `db.read`).

Fix: update client scopes in `config/auth.local.php`, then reopen link (`AUTH.OPEN_LINK`) and retry.

### `AUTH_INVALID_CLIENT`

Cause: wrong client ID/secret pair or bad hash config.

Fix: verify exact client ID + matching plaintext secret + `secret_hash` generated by `password_hash`.

### `VALIDATION_OBJECT_REQUIRED` for `bindings`

Cause: `bindings` sent as array/string instead of JSON object.

Fix: send object form, for example `"bindings": {}`.

### `DB.TABLES` fails with unsupported/empty driver

Cause: profile `driver` override is invalid/empty.

Fix: set `driver` to `mysql` (or remove invalid override and rely on defaults).

### `AUTH_GUID_ALREADY_USED`

Cause: consumed GUID reused with non-identical request payload.

Fix: use latest returned GUID, or retry exact same request if response-loss recovery is intended.

### `AUTH_GUID_INVALID` / `AUTH_GUID_EXPIRED`

Cause: bad GUID format, unknown GUID, pruned spent GUID, or expired session.

Fix: reopen via `AUTH.OPEN_LINK`.

### `CONTENT_TYPE_INVALID` or `REQUEST_JSON_INVALID`

Cause: bad `Content-Type` or malformed JSON.

Fix: use `Content-Type: application/json` and valid JSON object payload.

## Extending Brenner with Custom SQL Actions

You can add custom action names to `config/actions.php` using type `sql`.

Pattern:

- Choose `database` profile name.
- Provide SQL text.
- Define params schema.
- Set `result` mode:
  - `all`
  - `one`
  - `value`
  - `write`
- Set scope requirements.
- Configure `consume_guid` (or rely on `result = write` default consumption).

Start from the commented examples already in `config/actions.php`.

## Operational Caveats

- GUID state is file-based. In multi-node deployments, use shared storage or adapt the store.
- Replay cache keeps only the most recent spent GUID path in the chain.
- `DB.TABLES`/`DB.COLUMNS` metadata actions currently enforce MySQL/MariaDB driver.
- `DB.EXECUTE` can run destructive SQL by design. Use carefully.

## Git Safety

This repository ignores:

- `config/*.local.php`
- `storage/api_sessions/*` (except `.gitkeep`)

Do not commit real secrets to any tracked file.
