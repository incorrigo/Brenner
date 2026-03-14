<?php

declare(strict_types=1);

use Brenner\Support\Config;

require_once dirname(__DIR__) . '/src/autoload.php';

$config = Config::fromDirectory(dirname(__DIR__) . '/config');
$actions = $config->actions();
$defaultAction = array_key_first($actions) ?? 'system.status';
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
			--bg: #f5f1e8;
			--panel: #fffdf8;
			--ink: #231f1a;
			--muted: #6c645a;
			--line: #d4cab9;
			--accent: #8b3a1d;
			--accent-soft: #e9c9b7;
			--success: #215732;
		}

		* {
			box-sizing: border-box;
		}

		body {
			margin: 0;
			font-family: Georgia, "Times New Roman", serif;
			background:
				radial-gradient(circle at top left, rgba(139, 58, 29, 0.10), transparent 32rem),
				linear-gradient(180deg, #f8f2e8 0%, var(--bg) 100%);
			color: var(--ink);
		}

		main {
			width: min(1100px, calc(100vw - 2rem));
			margin: 2rem auto;
			display: grid;
			gap: 1.25rem;
			grid-template-columns: 1.1fr 0.9fr;
		}

		.panel {
			background: rgba(255, 253, 248, 0.92);
			border: 1px solid var(--line);
			border-radius: 20px;
			padding: 1.5rem;
			box-shadow: 0 18px 40px rgba(35, 31, 26, 0.08);
			backdrop-filter: blur(8px);
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

		label {
			display: block;
			margin-bottom: 1rem;
			font-size: 0.95rem;
			color: var(--muted);
		}

		input, select, textarea, button {
			width: 100%;
			border-radius: 14px;
			border: 1px solid var(--line);
			padding: 0.9rem 1rem;
			font: inherit;
			color: var(--ink);
			background: #fff;
		}

		textarea {
			min-height: 220px;
			resize: vertical;
			font-family: Consolas, "Courier New", monospace;
			font-size: 0.92rem;
		}

		button {
			border: 0;
			background: linear-gradient(135deg, var(--accent) 0%, #b75b35 100%);
			color: #fff8f3;
			font-weight: 700;
			cursor: pointer;
			transition: transform 0.18s ease, box-shadow 0.18s ease;
			box-shadow: 0 10px 25px rgba(139, 58, 29, 0.22);
		}

		button:hover {
			transform: translateY(-1px);
		}

		.actions {
			display: grid;
			gap: 0.75rem;
		}

		.action-card {
			border: 1px solid var(--line);
			border-radius: 14px;
			padding: 1rem;
			background: linear-gradient(180deg, #fff, #fffaf4);
		}

		.action-card strong {
			display: block;
			margin-bottom: 0.35rem;
		}

		code, pre {
			font-family: Consolas, "Courier New", monospace;
		}

		pre {
			margin: 0;
			padding: 1rem;
			border-radius: 14px;
			background: #211d18;
			color: #f8efe3;
			overflow: auto;
			min-height: 320px;
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
			background: #eef7ef;
			color: var(--success);
			border: 1px solid #c5dfcb;
		}

		@media (max-width: 900px) {
			main {
				grid-template-columns: 1fr;
			}
		}
	</style>
</head>
<body>
<main>
	<section class="panel">
		<div class="badge">PHP only</div>
		<h1>Private Database Gateway</h1>
		<p>
			This website never talks to the private database directly. It only calls <code>api.php</code>,
			and the PHP server performs the database work locally.
		</p>
		<div class="notice">
			The server running this code must already be able to reach the private database over LAN, VPN, or localhost.
		</div>
		<form id="gateway-form">
			<label>
				API key
				<input id="api-key" name="api_key" type="password" placeholder="Matches config/app.php">
			</label>
			<label>
				Action
				<select id="action" name="action">
					<?php foreach ($actions as $name => $action): ?>
						<option value="<?= htmlspecialchars($name, ENT_QUOTES) ?>" <?= $name === $defaultAction ? 'selected' : '' ?>>
							<?= htmlspecialchars($name, ENT_QUOTES) ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				Method
				<input id="method" type="text" value="GET" readonly>
			</label>
			<label>
				Params JSON
				<textarea id="params" name="params">{}</textarea>
			</label>
			<button type="submit">Call API</button>
		</form>
	</section>

	<section class="panel">
		<h2>Response</h2>
		<pre id="response">Submit a request to inspect the JSON response.</pre>
	</section>

	<section class="panel">
		<h2>Available Actions</h2>
		<div class="actions">
			<?php foreach ($actions as $name => $action): ?>
				<article class="action-card">
					<strong><?= htmlspecialchars($name, ENT_QUOTES) ?></strong>
					<div><?= htmlspecialchars(implode(', ', $action['methods'] ?? ['GET']), ENT_QUOTES) ?></div>
					<p><?= htmlspecialchars((string) ($action['description'] ?? 'No description.'), ENT_QUOTES) ?></p>
					<?php if (!empty($action['params']) && is_array($action['params'])): ?>
						<code><?= htmlspecialchars(json_encode($action['params'], JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?></code>
					<?php else: ?>
						<code>No parameters.</code>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
</main>

<script>
	const actions = <?= json_encode($actions, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>;
	const form = document.getElementById('gateway-form');
	const actionSelect = document.getElementById('action');
	const methodField = document.getElementById('method');
	const paramsField = document.getElementById('params');
	const responseBox = document.getElementById('response');

	function buildExamplePayload(actionName) {
		const action = actions[actionName] || {};
		const rules = action.params || {};
		const payload = {};

		Object.keys(rules).forEach((key) => {
			const rule = rules[key];

			if (Object.prototype.hasOwnProperty.call(rule, 'default')) {
				payload[key] = rule.default;
				return;
			}

			switch (rule.type) {
				case 'int':
				case 'integer':
					payload[key] = rule.min || 1;
					break;
				case 'bool':
				case 'boolean':
					payload[key] = true;
					break;
				case 'float':
					payload[key] = 1.5;
					break;
				default:
					payload[key] = '';
			}
		});

		return JSON.stringify(payload, null, 2);
	}

	function syncActionUi() {
		const action = actions[actionSelect.value] || {};
		const methods = action.methods || ['GET'];

		methodField.value = methods[0] || 'GET';
		paramsField.value = buildExamplePayload(actionSelect.value);
	}

	actionSelect.addEventListener('change', syncActionUi);

	form.addEventListener('submit', async (event) => {
		event.preventDefault();

		const actionName = actionSelect.value;
		const apiKey = document.getElementById('api-key').value;
		const action = actions[actionName] || {};
		const method = ((action.methods || ['POST'])[0] || 'POST').toUpperCase();
		let params = {};

		try {
			params = paramsField.value.trim() === '' ? {} : JSON.parse(paramsField.value);
		} catch (error) {
			responseBox.textContent = 'Params JSON is invalid.';
			return;
		}

		responseBox.textContent = 'Loading...';

		let response;

		if (method === 'GET') {
			const query = new URLSearchParams({ action: actionName });

			Object.keys(params).forEach((key) => {
				query.set(key, String(params[key]));
			});

			response = await fetch(`./api.php?${query.toString()}`, {
				method: 'GET',
				headers: {
					'X-Api-Key': apiKey,
				},
			});
		} else {
			response = await fetch('./api.php', {
				method,
				headers: {
					'Content-Type': 'application/json',
					'X-Api-Key': apiKey,
				},
				body: JSON.stringify({
					action: actionName,
					params: params,
				}),
			});
		}

		const text = await response.text();

		try {
			const json = JSON.parse(text);
			responseBox.textContent = JSON.stringify(json, null, 2);
		} catch (error) {
			responseBox.textContent = text;
		}
	});

	syncActionUi();
</script>
</body>
</html>
