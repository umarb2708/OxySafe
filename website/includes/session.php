<?php
/**
 * OxySafe – Session & auth helpers  (v2)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['path' => '/']);
    session_start();
}

/** Redirect to login page if not authenticated. */
function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

/** Redirect to login page if not authenticated AND not admin. */
function requireAdmin(): void {
    requireLogin();
    if (empty($_SESSION['is_admin'])) {
        header('Location: ' . BASE_URL . '/user_dashboard.php');
        exit;
    }
}

/** Redirect to appropriate dashboard if already logged in. */
function redirectIfLoggedIn(): void {
    if (!empty($_SESSION['user_id'])) {
        $dest = !empty($_SESSION['is_admin'])
            ? BASE_URL . '/admin_dashboard.php'
            : BASE_URL . '/user_dashboard.php';
        header('Location: ' . $dest);
        exit;
    }
}

/** Fetch the currently logged-in user row from DB. */
function getSessionUser(PDO $pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        session_destroy();
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
    return $user;
}

/** Set session variables for a logged-in user. */
function loginUser(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['name']     = $user['name'];
    $_SESSION['is_admin'] = (int) $user['is_admin'];
}

/** AQI level label using two-threshold algorithm. */
function aqiLevel(float $aqi, ?int $caution, ?int $danger): string {
    if ($caution === null || $danger === null) return 'Unknown';
    if ($aqi <= $caution)  return 'Safe';
    if ($aqi <= $danger)   return 'Caution';
    return 'Dangerous';
}

/** AQI category name from EPA breakpoints. */
function aqiCategory(float $aqi): string {
    if ($aqi <= 50)  return 'Good';
    if ($aqi <= 100) return 'Moderate';
    if ($aqi <= 150) return 'Unhealthy for Sensitive Groups';
    if ($aqi <= 200) return 'Unhealthy';
    if ($aqi <= 300) return 'Very Unhealthy';
    return 'Hazardous';
}
