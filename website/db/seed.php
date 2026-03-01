<?php
/**
 * OxySafe – Database Seed
 * Run ONCE from command line after schema migration:
 *   php website/db/seed.php
 *
 * Creates the default admin user:
 *   id=1, name=Administrator, username=admin, password=admin@123, is_admin=1
 */

require_once __DIR__ . '/../config.php';

$pdo = getDB();

// Check if admin already exists
$check = $pdo->prepare("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
$check->execute();
if ($check->fetch()) {
    echo "Admin user already exists. Skipping.\n";
    exit(0);
}

$hash = password_hash('admin@123', PASSWORD_BCRYPT);

$stmt = $pdo->prepare(
    "INSERT INTO users (id, name, username, password, is_admin, created_at)
     VALUES (1, 'Administrator', 'admin', :hash, 1, NOW())"
);
$stmt->execute([':hash' => $hash]);

echo "Admin user created successfully.\n";
echo "  username : admin\n";
echo "  password : admin@123\n";
echo "  IMPORTANT: Change the password after first login!\n";
