# Private Database Gateway (Brenner)

Brenner is a PHP-only HTTPS JSON API that lets a desktop app work with databases on shared hosting where remote DB access is blocked.

Desktop app -> `public/api.php` over HTTPS  
PHP on host -> MySQL/MariaDB on `localhost:3306`

The desktop app never gets raw DB credentials.

## Core Idea

You authenticate once with `AUTH.OPEN_LINK` and get a GUID.

- Non-consuming actions reuse the same GUID.
- Consuming actions rotate to a fresh GUID.
- Lost-response retries are safe: exact retry of a spent GUID replays the cached response.

## Config Model (One Reference Each)

You asked for one shared reference for auth defaults and one shared reference for DB defaults. Brenner now does that:

1. Auth shared defaults: `config/auth.php` -> `client_defaults`
2. DB shared defaults: `config/databases.php` -> `_defaults`
3. Existing profile/client-specific overrides still work in local files.

You can add many clients and many DB profiles without repeating common settings.

## Files

- `config/app.php`:
  - `default_database_profile` used by DB actions when `params.database` is omitted.
- `config/auth.php`:
  - GUID/session settings.
  - `client_defaults` (shared auth defaults for all clients).
- `config/auth.local.php`:
  - real client secrets and optional per-client scope overrides.
- `config/databases.php`:
  - `_defaults` (shared DB defaults: driver/host/port/charset).
- `config/databases.local.php`:
  - real DB profile credentials (`database`, `username`, `password`).
- `config/actions.php`:
  - allowlisted API actions.

## Setup

1. Point web root to `public/`.
2. Keep `config/databases.php` committed (safe defaults).
3. Create `config/databases.local.php` from `config/databases.local.php.example`.
4. Add real credentials for each DB profile.
5. Create `config/auth.local.php` from `config/auth.local.php.example`.
6. Add real client secret hashes and scopes.
7. Keep all `*.local.php` files out of Git.

Generate a secret hash:

```powershell
php -r "echo password_hash('your-client-secret', PASSWORD_DEFAULT), PHP_EOL;"
```

## Example Local Config

### `config/auth.local.php`

```php
<?php

declare(strict_types=1);

return [
	'client_defaults' => [
		'scopes' => ['db.read'],
	],
	'clients' => [
		'DESKTOP001' => [
			'secret_hash' => '...password_hash...',
		],
		'DESKTOP002' => [
			'secret_hash' => '...password_hash...',
			'scopes' => ['db.read', 'db.write'],
		],
		'CRITERION' => [
			'secret_hash' => '...password_hash...',
			'scopes' => ['db.read', 'db.write', 'db.admin'],
		],
	],
];
```

### `config/databases.local.php`

```php
<?php

declare(strict_types=1);

return [
	'private_mysql' => [
		'database' => 'light-house',
		'username' => 'api001',
		'password' => '...',
	],
	'private_mysql_reporting' => [
		'database' => 'light-house-reporting',
		'username' => 'api002',
		'password' => '...',
	],
];
```

## Scope Model

Scopes control what API actions a client can call.

- `db.read`:
  - `DB.PROFILES`, `DB.TABLES`, `DB.COLUMNS`, `DB.READ`
- `db.write`:
  - `DB.WRITE`
- `db.admin`:
  - `DB.EXECUTE` (full-power execution)

## Criterion-Style Full Privileges

To match a "super user" like your `criterion` account, use:

1. a DB user that actually has those MySQL grants
2. a Brenner client with `db.admin`
3. `DB.EXECUTE`

`DB.EXECUTE` is the full-power endpoint for data + structure operations, including:

- Data: `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `REPLACE`
- Structure: `CREATE`, `DROP`, `ALTER`, `INDEX`, views, routines, events, triggers, locks, references

Important: Brenner cannot exceed the DB user's actual grants. If MySQL denies something, Brenner returns the DB error.

## API Contract

All requests:

- `POST`
- `Content-Type: application/json`
- `HTTPS` (required; HSTS-compatible)

### Open Link

```json
{
  "action": "AUTH.OPEN_LINK",
  "params": {
    "client_id": "CRITERION",
    "client_secret": "your-client-secret"
  }
}
```

### Typical Authenticated Request

```json
{
  "action": "DB.PROFILES",
  "guid": "current-guid-from-last-response",
  "params": {}
}
```

## Action Reference

- `SYSTEM.STATUS` (no auth): status metadata.
- `LINK.INFO` (auth, reuse): schema-free authenticated probe.
- `LINK.ECHO` (auth, consume): replay/rotation test action.
- `LINK.REQUIRE_TAG` (auth, reuse): validation probe.
- `DB.PROFILES` (auth, reuse): list profile names.
- `DB.TABLES` (auth, reuse): list tables in schema.
- `DB.COLUMNS` (auth, reuse): list table columns.
- `DB.READ` (auth, reuse): read-only SQL (`SELECT/SHOW/DESCRIBE/EXPLAIN/with-read-safe CTE`).
- `DB.WRITE` (auth, consume): DML SQL (`INSERT/UPDATE/DELETE/REPLACE`).
- `DB.EXECUTE` (auth, consume): full SQL execution for admin/superuser workflows.

## Request Examples

### List Tables

```json
{
  "action": "DB.TABLES",
  "guid": "current-guid",
  "params": {
    "database": "private_mysql"
  }
}
```

### Describe Columns

```json
{
  "action": "DB.COLUMNS",
  "guid": "current-guid",
  "params": {
    "database": "private_mysql",
    "table": "users"
  }
}
```

### Safe Read Query

```json
{
  "action": "DB.READ",
  "guid": "current-guid",
  "params": {
    "database": "private_mysql",
    "sql": "SELECT * FROM information_schema.tables WHERE table_schema = :schema LIMIT 50",
    "bindings": {
      "schema": "light-house"
    },
    "max_rows": 500
  }
}
```

### Safe Write Query

```json
{
  "action": "DB.WRITE",
  "guid": "current-guid",
  "params": {
    "database": "private_mysql",
    "sql": "UPDATE your_table SET updated_at = NOW() WHERE id = :id",
    "bindings": {
      "id": 1
    }
  }
}
```

### Full-Power Superuser Query

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

## DB.EXECUTE Response Shape

`DB.EXECUTE` returns:

- `result_sets`: array of rowsets (for statements that return data)
- `result_set_count`
- `row_count` (returned rows)
- `affected_rows` (DML/DDL impact when reported by driver)
- `truncated` and `max_rows` for output limiting
- normal GUID fields in envelope (`guid`, `guid_sequence`, `guid_expires_at`)

## Security and Operational Notes

- This does not bypass network routing: PHP must still reach MySQL locally on host.
- Keep `config/*.local.php` out of version control.
- Do not send DB credentials to desktop clients.
- `DB.EXECUTE` is intentionally powerful. Grant `db.admin` only to trusted clients.
- With great power comes blast radius: DDL and destructive SQL are possible by design when using `db.admin`.

