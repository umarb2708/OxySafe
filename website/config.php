<?php
/**
 * OxySafe – Global Configuration
 */

// ─── Database ────────────────────────────────────────────────
define('DB_HOST',   'localhost');
define('DB_NAME',   'oxysafe_db');
define('DB_USER',   'root');          // ← change in production
define('DB_PASS',   '');              // ← change in production
define('DB_CHARSET','utf8mb4');

// ─── API Security ────────────────────────────────────────────
define('API_KEY',   'OXYSAFE_SECRET_KEY');  // must match firmware

// ─── Application ─────────────────────────────────────────────
define('APP_NAME',  'OxySafe');
define('BASE_URL',  'http://your-server.com/website');  // no trailing slash

// ─── Session timeout (seconds) ───────────────────────────────
define('SESSION_TIMEOUT', 3600);

// ─────────────────────────────────────────────────────────────
//  PDO Connection factory
// ─────────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s',
                       DB_HOST, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed.']));
        }
    }
    return $pdo;
}
