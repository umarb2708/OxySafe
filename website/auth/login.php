<?php
/**
 * OxySafe – Login page
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session.php';

redirectIfLoggedIn();

$error  = '';
$emailVal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $emailVal = $email;

    if ($email && $password) {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            loginUser($user);
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
    }
    $error = 'Invalid email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – OxySafe</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">
        <span class="logo-icon">🌬️</span>
        <h1>OxySafe</h1>
        <p>Sign in to your account</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><p><?= htmlspecialchars($error) ?></p></div>
    <?php endif; ?>

    <form method="POST" novalidate>
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autofocus
                   value="<?= htmlspecialchars($emailVal) ?>">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Sign In</button>
    </form>

    <p class="auth-footer">Don't have an account?
        <a href="<?= BASE_URL ?>/auth/register.php">Register here</a>
    </p>
</div>
</body>
</html>
