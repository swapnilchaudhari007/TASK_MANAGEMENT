<?php
// Credentials come from environment variables; defaults are for local XAMPP only.
$conn = new mysqli(
	getenv('TMS_DB_HOST') ?: 'localhost',
	getenv('TMS_DB_USER') ?: 'root',
	getenv('TMS_DB_PASS') ?: '',
	getenv('TMS_DB_NAME') ?: 'tms_db'
);
if ($conn->connect_error) {
	error_log('DB connect failed: ' . $conn->connect_error);
	die('Database connection failed.');
}
$conn->set_charset('utf8mb4');
