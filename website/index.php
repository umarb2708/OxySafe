<?php
/**
 * OxySafe – Login Page (index.php)
 * Authenticates via users table; redirects:
 *   is_admin = 1  →  admin_dashboard.php
 *   is_admin = 0  →  user_dashboard.php (checks thresholds)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/session.php';

redirectIfLoggedIn();

$error    = '';
$userVal  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']      ?? '';
    $userVal  = $username;

    if ($username && $password) {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            loginUser($user);
            $dest = $user['is_admin']
                ? BASE_URL . '/admin_dashboard.php'
                : BASE_URL . '/user_dashboard.php';
            header('Location: ' . $dest);
            exit;
        }
    }
    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OxySafe – Login</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body class="auth-page">

<div class="auth-card">
    <div class="auth-logo">
        <div class="logo-circle">
            <span class="logo-icon">🌬️</span>
        </div>
        <h1>OxySafe</h1>
        <p>Air Quality Monitoring System</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><p><?= htmlspecialchars($error) ?></p></div>
    <?php endif; ?>

    <form method="POST" novalidate autocomplete="off">
        <div class="form-group">
            <label for="username">Username</label>
            <div class="input-icon-wrap">
                <span class="input-icon">👤</span>
                <input type="text" id="username" name="username" required autofocus
                       placeholder="Enter username"
                       value="<?= htmlspecialchars($userVal) ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <div class="input-icon-wrap">
                <span class="input-icon">🔒</span>
                <input type="password" id="password" name="password"
                       required placeholder="Enter password">
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">
            Sign In &nbsp;→
        </button>
    </form>
</div>

</body>
</html>
