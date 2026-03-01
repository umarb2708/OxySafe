<?php
/**
 * OxySafe API – Receive sensor data from ESP8266
 * Method : POST
 * URL    : /website/api/data.php
 * Auth   : X-API-Key header must match API_KEY in config.php
 *
 * JSON body expected:
 * {
 *   "username"     : "john_doe",
 *   "temp"         : 28.50,
 *   "humidity"     : 65.00,
 *   "dust_density" : 35.20,
 *   "aqi"          : 98.4
 * }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

// ─── Allow POST only ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method Not Allowed. Use POST.']));
}

// ─── Validate API Key ─────────────────────────────────────────
$receivedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($receivedKey !== API_KEY) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorised – invalid API key.']));
}

// ─── Parse JSON body ─────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid JSON payload.']));
}

// ─── Validate required fields ─────────────────────────────────
$required = ['username', 'temp', 'humidity', 'dust_density', 'aqi'];
foreach ($required as $field) {
    if (!isset($body[$field])) {
        http_response_code(400);
        exit(json_encode(['error' => "Missing field: $field"]));
    }
}

$username    = trim($body['username']);
$temp        = (float) $body['temp'];
$humidity    = (float) $body['humidity'];
$dustDensity = (float) $body['dust_density'];
$aqi         = (float) $body['aqi'];

// ─── Verify username exists in users table ────────────────────
$pdo   = getDB();
$check = $pdo->prepare('SELECT id FROM users WHERE username = :u AND is_admin = 0 LIMIT 1');
$check->execute([':u' => $username]);
if (!$check->fetch()) {
    http_response_code(404);
    exit(json_encode(['error' => 'Username not found.']));
}

// ─── Sanity-check sensor ranges ───────────────────────────────
if ($temp        < -40 || $temp        > 85   ||
    $humidity    <   0 || $humidity    > 100  ||
    $dustDensity <   0 || $dustDensity > 1000 ||
    $aqi         <   0 || $aqi         > 500) {
    http_response_code(422);
    exit(json_encode(['error' => 'Sensor values out of acceptable range.']));
}

// ─── Store in database ────────────────────────────────────────
$stmt = $pdo->prepare(
    'INSERT INTO sensor_data (username, temp, humidity, dust_density, aqi)
     VALUES (:u, :t, :h, :d, :a)'
);
$stmt->execute([
    ':u' => $username,
    ':t' => $temp,
    ':h' => $humidity,
    ':d' => $dustDensity,
    ':a' => $aqi,
]);

http_response_code(200);
echo json_encode([
    'status'  => 'ok',
    'message' => 'Data stored successfully.',
    'id'      => $pdo->lastInsertId(),
]);
