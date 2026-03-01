<?php
/**
 * OxySafe API – Return latest sensor reading for dashboard polling
 * Method : GET
 * URL    : /website/api/get_data.php
 * Auth   : Session-based (must be logged in)
 *
 * Response JSON:
 * {
 *   "temperature"  : 28.50,
 *   "humidity"     : 65.00,
 *   "dust_density" : 35.20,
 *   "aqi"          : 98.4,
 *   "recorded_at"  : "2026-03-01 10:23:45",
 *   "caution"      : 75,
 *   "danger"       : 150,
 *   "level"        : "Safe",        // Safe | Caution | Dangerous
 *   "category"     : "Moderate"     // EPA category name
 * }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session.php';

requireLogin();

$pdo  = getDB();
$user = getSessionUser($pdo);

$username = $user['username'];
$caution  = $user['caution_threshold'] !== null ? (int) $user['caution_threshold'] : null;
$danger   = $user['danger_threshold']  !== null ? (int) $user['danger_threshold']  : null;

// ─── Fetch latest reading for this user ──────────────────────
$stmt = $pdo->prepare(
    'SELECT temp, humidity, dust_density, aqi,
            DATE_FORMAT(recorded_at, "%Y-%m-%d %H:%i:%s") AS recorded_at
     FROM sensor_data
     WHERE username = :u
     ORDER BY recorded_at DESC
     LIMIT 1'
);
$stmt->execute([':u' => $username]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit(json_encode(['error' => 'No data available yet.']));
}

$aqi = (float) $row['aqi'];

echo json_encode([
    'temperature'  => (float) $row['temp'],
    'humidity'     => (float) $row['humidity'],
    'dust_density' => (float) $row['dust_density'],
    'aqi'          => $aqi,
    'recorded_at'  => $row['recorded_at'],
    'caution'      => $caution,
    'danger'       => $danger,
    'level'        => aqiLevel($aqi, $caution, $danger),
    'category'     => aqiCategory($aqi),
]);
}
