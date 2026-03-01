<?php
/**
 * OxySafe – Admin Dashboard
 * Only accessible when is_admin = 1
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/session.php';

requireAdmin();

$pdo     = getDB();
$message = '';
$msgType = '';

// ── Handle Add New User ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_user') {
    $name     = trim($_POST['name']     ?? '');
    $uname    = trim($_POST['username'] ?? '');
    $pwd      = $_POST['password']      ?? '';
    $errors   = [];

    if (!$name)  $errors[] = 'Name is required.';
    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $uname))
        $errors[] = 'Username must be 3–50 characters (letters, numbers, underscore).';
    if (strlen($pwd) < 6)
        $errors[] = 'Password must be at least 6 characters.';

    if (empty($errors)) {
        $check = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $check->execute([':u' => $uname]);
        if ($check->fetch()) {
            $errors[] = "Username '{$uname}' is already taken.";
        } else {
            $hash = password_hash($pwd, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, username, password, is_admin) VALUES (:n, :u, :p, 0)'
            );
            $stmt->execute([':n' => $name, ':u' => $uname, ':p' => $hash]);
            // Pass newly created username to JS for the redirect prompt
            $message = "USER_ADDED:{$uname}";
            $msgType = 'success';
        }
    }

    if (!empty($errors)) {
        $message = implode('<br>', $errors);
        $msgType = 'danger';
    }
}

// ── Handle Update User ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {
    $uname    = trim($_POST['username'] ?? '');
    $newUname = trim($_POST['new_username'] ?? '');
    $newPwd   = $_POST['new_password'] ?? '';
    $errors   = [];

    $check = $pdo->prepare('SELECT id FROM users WHERE username = :u AND is_admin = 0 LIMIT 1');
    $check->execute([':u' => $uname]);
    $target = $check->fetch();

    if (!$target) $errors[] = 'User not found or cannot update admin accounts.';
    if ($newUname && !preg_match('/^[a-zA-Z0-9_]{3,50}$/', $newUname))
        $errors[] = 'New username must be 3–50 characters.';

    if (empty($errors)) {
        $updates = [];
        $params  = [':id' => $target['id']];
        if ($newUname)    { $updates[] = 'username = :nu'; $params[':nu'] = $newUname; }
        if ($newPwd)      { $updates[] = 'password = :np'; $params[':np'] = password_hash($newPwd, PASSWORD_BCRYPT); }
        if ($updates) {
            $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
            $message = 'User updated successfully.';
            $msgType = 'success';
        } else {
            $message = 'No changes provided.';
            $msgType = 'danger';
        }
    }

    if (!empty($errors)) {
        $message = implode('<br>', $errors);
        $msgType = 'danger';
    }
}

// ── Load all non-admin users for the update dropdown ──────────
$allUsers = $pdo->query('SELECT id, name, username FROM users WHERE is_admin = 0 ORDER BY name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard – OxySafe</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body class="dashboard-page">

<!-- ─── Navbar ─────────────────────────────────────────────── -->
<nav class="navbar">
    <div class="nav-brand"><span class="logo-icon">🌬️</span> OxySafe</div>
    <div class="nav-user">
        <span class="admin-badge">⚙️ Admin</span>
        <span>👤 <?= htmlspecialchars($_SESSION['name']) ?></span>
        <a href="<?= BASE_URL ?>/auth/logout.php" class="btn btn-outline btn-sm">Logout</a>
    </div>
</nav>

<main class="container">

    <div class="page-header">
        <h2 class="page-title">Admin Dashboard</h2>
        <p class="page-sub">Manage OxySafe users</p>
    </div>

    <!-- ── Toast message ──────────────────────────────────── -->
    <?php if ($message && $msgType !== '' && !str_starts_with($message, 'USER_ADDED:')): ?>
        <div class="alert alert-<?= $msgType ?>"><p><?= $message ?></p></div>
    <?php endif; ?>

    <!-- ─── Stats row ──────────────────────────────────────── -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-val"><?= count($allUsers) ?></div>
            <div class="stat-label">Total Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-val">
                <?php
                $configured = $pdo->query(
                    "SELECT COUNT(*) FROM users
                     WHERE is_admin=0
                       AND caution_threshold IS NOT NULL
                       AND danger_threshold IS NOT NULL"
                )->fetchColumn();
                echo $configured;
                ?>
            </div>
            <div class="stat-label">Medics Configured</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⚠️</div>
            <div class="stat-val">
                <?php
                $pending = $pdo->query(
                    "SELECT COUNT(*) FROM users
                     WHERE is_admin=0
                       AND (caution_threshold IS NULL OR danger_threshold IS NULL)"
                )->fetchColumn();
                echo $pending;
                ?>
            </div>
            <div class="stat-label">Pending Setup</div>
        </div>
    </div>

    <!-- ─── Action Buttons ──────────────────────────────── -->
    <div class="action-row">
        <button class="btn btn-primary btn-lg" onclick="openModal('modal-add')">
            ➕ Add New User
        </button>
        <button class="btn btn-outline btn-lg" onclick="openModal('modal-update')">
            ✏️ Update User
        </button>
    </div>

    <!-- ─── Users Table ─────────────────────────────────── -->
    <div class="card table-card">
        <h3 class="card-title">📋 Registered Users</h3>
        <?php if (empty($allUsers)): ?>
            <p class="empty-msg">No users registered yet.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Caution AQI</th>
                        <th>Danger AQI</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allUsers as $i => $u):
                        $stmt2 = $pdo->prepare('SELECT caution_threshold, danger_threshold FROM users WHERE username=:u');
                        $stmt2->execute([':u' => $u['username']]);
                        $thresh = $stmt2->fetch();
                        $configured = $thresh['caution_threshold'] !== null && $thresh['danger_threshold'] !== null;
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($u['name']) ?></td>
                        <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                        <td><?= $thresh['caution_threshold'] ?? '—' ?></td>
                        <td><?= $thresh['danger_threshold']  ?? '—' ?></td>
                        <td>
                            <?php if ($configured): ?>
                                <span class="pill pill-green">Configured</span>
                            <?php else: ?>
                                <span class="pill pill-yellow">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</main>

<!-- ═══ MODAL: Add New User ═══════════════════════════════════ -->
<div id="modal-add" class="modal-overlay hidden" onclick="closeOnOverlay(event,'modal-add')">
    <div class="modal-box">
        <div class="modal-header">
            <h3>➕ Add New User</h3>
            <button class="modal-close" onclick="closeModal('modal-add')">✕</button>
        </div>
        <form method="POST" id="form-add">
            <input type="hidden" name="action" value="add_user">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" required placeholder="e.g. John Doe">
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required placeholder="e.g. john_doe">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="Min. 6 characters">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-add')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ MODAL: Update User ════════════════════════════════════ -->
<div id="modal-update" class="modal-overlay hidden" onclick="closeOnOverlay(event,'modal-update')">
    <div class="modal-box">
        <div class="modal-header">
            <h3>✏️ Update User</h3>
            <button class="modal-close" onclick="closeModal('modal-update')">✕</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_user">
            <div class="form-group">
                <label>Select User</label>
                <select name="username" required class="form-select">
                    <option value="">— choose a user —</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= htmlspecialchars($u['username']) ?>">
                            <?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>New Username <small>(leave blank to keep)</small></label>
                <input type="text" name="new_username" placeholder="Optional">
            </div>
            <div class="form-group">
                <label>New Password <small>(leave blank to keep)</small></label>
                <input type="password" name="new_password" placeholder="Optional">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-update')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update User</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Modal helpers ─────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function closeOnOverlay(e, id) {
    if (e.target === document.getElementById(id)) closeModal(id);
}

// ── After user creation: prompt to configure medics ──────────
<?php if (str_starts_with($message, 'USER_ADDED:')): ?>
(function () {
    const newUser = '<?= addslashes(substr($message, 11)) ?>';
    const go = confirm(
        '✅ User "' + newUser + '" created!\n\n' +
        'Would you like to configure their health medics thresholds now?'
    );
    if (go) {
        window.location.href = '<?= BASE_URL ?>/update_medics.php?username=' + encodeURIComponent(newUser);
    }
})();
<?php endif; ?>
</script>

</body>
</html>
