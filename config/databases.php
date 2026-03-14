<?php

declare(strict_types=1);

return [
	/*
	 * Fixed MySQL/MariaDB profile for hosted systems where the database is
	 * available as localhost:3306 from the website's PHP runtime.
	 *
	 * Put the real database name, username, and password in
	 * config/databases.local.php so they stay out of Git.
	 */
	'private_mysql' => [
		'driver' => 'mysql',
		'host' => 'localhost',
		'port' => 3306,
		'database' => '',
		'username' => '',
		'password' => '',
		'charset' => 'utf8mb4',
	],
];
