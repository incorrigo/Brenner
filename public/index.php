<?php

declare(strict_types=1);

use Brenner\Support\Config;

require_once dirname(__DIR__) . '/src/autoload.php';

$config = Config::fromDirectory(dirname(__DIR__) . '/config');
$actions = $config->actions();
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Private Database Gateway</title>
	<style>
		:root {
			color-scheme: light;
			--bg: #f3efe6;
			--panel: #fffdf8;
			--ink: #1f1914;
			--muted: #62584d;
			--line: #d8c9b6;
			--accent: #7f2f12;
			--accent-soft: #f0d0bf;
			--code: #171311;
		}

		* { box-sizing: border-box; }

		body {
			margin: 0;
			font-family: Georgia, "Times New Roman", serif;
			background:
				radial-gradient(circle at top left, rgba(127, 47, 18, 0.12), transparent 28rem),
				linear-gradient(180deg, #f8f2e8 0%, var(--bg) 100%);
			color: var(--ink);
		}

		main {
			width: min(1080px, calc(100vw - 2rem));
			margin: 2rem auto;
			display: grid;
			gap: 1.2rem;
		}

		.panel {
			background: rgba(255, 253, 248, 0.92);
			border: 1px solid var(--line);
			border-radius: 20px;
			padding: 1.5rem;
			box-shadow: 0 18px 40px rgba(35, 31, 26, 0.08);
		}

		h1, h2 {
			margin: 0 0 0.75rem;
			font-weight: 600;
			letter-spacing: 0.01em;
		}

		p {
			margin: 0 0 1rem;
			color: var(--muted);
			line-height: 1.6;
		}

		.lead { font-size: 1.08rem; }

		.grid {
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 1rem;
		}

		.badge {
			display: inline-block;
			padding: 0.25rem 0.55rem;
			margin-bottom: 0.75rem;
			border-radius: 999px;
			background: var(--accent-soft);
			color: var(--accent);
			font-size: 0.82rem;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.08em;
		}

		.notice {
			padding: 0.8rem 1rem;
			border-radius: 14px;
			background: #fff2eb;
			color: var(--accent);
			border: 1px solid #ebc5b3;
		}

		pre, code {
			font-family: Consolas, "Courier New", monospace;
		}

		pre {
			margin: 0;
			padding: 1rem;
			border-radius: 14px;
			background: var(--code);
			color: #f8efe3;
			overflow: auto;
		}

		table {
			width: 100%;
			border-collapse: collapse;
			font-size: 0.95rem;
		}

		th, td {
			text-align: left;
			padding: 0.8rem 0.6rem;
			border-bottom: 1px solid var(--line);
			vertical-align: top;
		}

		th { color: var(--muted); font-weight: 600; }

		@media (max-width: 860px) {
			.grid { grid-template-columns: 1fr; }
		}
	</style>
</head>
<body>
<main>
	<section class="panel">
		<div class="badge">HTTPS Rotating Link</div>
		<h1>Private Database Gateway</h1>
		<p class="lead">This endpoint is designed for a C# Windows desktop client using a rotating single-use command GUID.</p>
		<div class="notice">
			All application requests go to <code>/api.php</code> as <code>POST</code> JSON over <code>HTTPS</code>. The PHP server then talks to MySQL on <code>localhost:3306</code>.
		</div>
	</section>

	<section class="grid">
		<article class="panel">
			<h2>Open Link</h2>
			<pre>{
  "action": "AUTH.OPEN_LINK",
  "params": {
    "client_id": "DESKTOP001",
    "client_secret": "your-client-secret"
  }
}</pre>
			<p>The response returns a <code>session_id</code> and a one-time <code>command_guid</code>.</p>
		</article>

		<article class="panel">
			<h2>Authenticated Request</h2>
			<pre>{
  "action": "LINK.PING",
  "session": {
    "session_id": "f2cb8d9e-2af7-4f93-9f33-e0f5841dca74",
    "command_guid": "8ef175e9-e388-4c83-940b-6f4f97d58868"
  },
  "params": {}
}</pre>
			<p>Every authenticated response returns the next <code>command_guid</code>. The old one is invalid after one use.</p>
		</article>
	</section>

	<section class="panel">
		<h2>Replay Safety</h2>
		<pre>{
  "ok": true,
  "request_id": "d3c48f7f4f264d7ea0d0f4d1d1684f51",
  "action": "LINK.PING",
  "data": {
    "authenticated": true,
    "message": "Rotating link is alive."
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
}</pre>
			<p>If the client retries the exact same request because the response was lost, the gateway replays the cached prior response instead of breaking session state.</p>
		</section>

	<section class="panel">
		<h2>Configured Actions</h2>
		<table>
			<thead>
				<tr>
					<th>Action</th>
					<th>Auth</th>
					<th>Description</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($actions as $name => $action): ?>
					<tr>
						<td><strong><?= htmlspecialchars($name, ENT_QUOTES) ?></strong></td>
						<td><?= ($action['requires_auth'] ?? true) === true ? 'Rotating link' : 'Credentials only' ?></td>
						<td><?= htmlspecialchars((string) ($action['description'] ?? 'No description.'), ENT_QUOTES) ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<section class="panel">
		<h2>Desktop Sample</h2>
		<p>The sample C# caller lives at <code>examples/CSharp/HTTPSAPIGatewayClient.cs</code>.</p>
		<p>API version: <strong><?= htmlspecialchars((string) $config->app('api_version', 'unknown'), ENT_QUOTES) ?></strong></p>
	</section>
</main>
</body>
</html>
