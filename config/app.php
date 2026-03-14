<?php

declare(strict_types=1);

return [
	'name' => 'Private Database Gateway',
	'timezone' => 'Europe/London',
	'debug' => true,

	/*
	 * Set this to a long random string before exposing the API.
	 * If you set it to null, authentication is disabled.
	 */
	'api_key' => 'change-this-api-key',

	/*
	 * Leave this empty when the API and the website live on the same origin.
	 * Add exact origins only when a separate site needs to call this API.
	 */
	'allowed_origins' => [
		'http://localhost:8000',
	],
];
