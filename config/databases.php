<?php

declare(strict_types=1);

return [
	/*
	 * Shared defaults applied to every DB profile unless overridden in that profile.
	 * Keep shared host/port/driver here so each profile only needs database/user/password.
	 */
	'_defaults' => [
		'driver' => 'mysql',
		'host' => 'localhost',
		'port' => 3306,
		'charset' => 'utf8mb4',
	],

	/*
	 * Add one of these for every database you want to access
	 */
	'private_mysql' => [
		'database' => '',
		'username' => '',
		'password' => '',
	],
];
