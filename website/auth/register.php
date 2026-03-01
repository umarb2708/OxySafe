<?php
/**
 * OxySafe – Register page  (first-time setup includes threshold)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session.php';

redirectIfLoggedIn();

$errors = [];
$values = ['username' => '', 'email' => '', 'device_id' => 'OXYSAFE_001', 'threshold_aqi' => 100];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username     = trim($_POST['username']     ?? '');
    $email        = trim($_POST['email']        ?? '');
    $password     = $_POST['password']          ?? '';
    $confirmPass  = $_POST['confirm_password']  ?? '';
    $threshold    = (int) ($_POST['threshold_aqi'] ?? 100);
    $deviceId     = trim($_POST['device_id']    ?? 'OXYSAFE_001');

    $values = compact('username', 'email', 'deviceId', 'threshold');

    // ── Validate inputs ───────────────────────────────────────
    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username))
        $errors[] = 'Username must be 3–50 characters (letters, numbers, underscore).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Please enter a valid email address.';
    if (strlen($password) < 8)
        $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirmPass)
        $errors[] = 'Passwords do not match.';
    if ($threshold < 1 || $threshold > 500)
        $errors[] = 'AQI threshold must be between 1 and 500.';

    if (empty($errors)) {
        $pdo = getDB();

        // Check for duplicate username / email
        $check = $pdo->prepare('SELECT id FROM users WHERE username = :u OR email = :e LIMIT 1');
        $check->execute([':u' => $username, ':e' => $email]);
        if ($check->fetch()) {
            $errors[] = 'Username or email already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, email, password_hash, threshold_aqi, device_id)
                 VALUES (:u, :e, :h, :t, :d)'
            );
            $stmt->execute([
                ':u' => $username,
                ':e' => $email,
                ':h' => $hash,
                ':t' => $threshold,
                ':d' => $deviceId,
            ]);
            loginUser(['id' => $pdo->lastInsertId(), 'username' => $username]);
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register – OxySafe</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">
        <span class="logo-icon">🌬️</span>
        <h1>OxySafe</h1>
        <p>Create your account</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?>
                <p><?= htmlspecialchars($e) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" novalidate>
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required
                   value="<?= htmlspecialchars($values['username']) ?>">
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required
                   value="<?= htmlspecialchars($values['email']) ?>">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required>
        </div>

        <div class="form-group">
            <label for="device_id">Device ID</label>
            <input type="text" id="device_id" name="device_id"
                   value="<?= htmlspecialchars($values['device_id']) ?>" required>
            <small>Must match DEVICE_ID in firmware.</small>
        </div>

        <div class="form-group">
            <label for="threshold_aqi">
                AQI Alert Threshold
                <span class="badge" id="threshold-badge"><?= $values['threshold_aqi'] ?></span>
            </label>
            <input type="range" id="threshold_aqi" name="threshold_aqi"
                   min="1" max="300" step="1"
                   value="<?= (int)$values['threshold_aqi'] ?>"
                   oninput="document.getElementById('threshold-badge').textContent=this.value">
            <div class="aqi-scale-labels">
                <span class="good">Good (0–50)</span>
                <span class="moderate">Moderate (51–100)</span>
                <span class="sensitive">USG (101–150)</span>
                <span class="unhealthy">Unhealthy (151–200)</span>
                <span class="very-unhealthy">V.Unhealthy (201–300)</span>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Create Account</button>
    </form>

    <p class="auth-footer">Already have an account?
        <a href="<?= BASE_URL ?>/auth/login.php">Sign in</a>
    </p>
</div>
</body>
</html>
