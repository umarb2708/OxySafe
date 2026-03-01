<?php
/**
 * OxySafe – Update Medics / Health Profile
 *
 * Accessible by:
 *   - Any logged-in user for their own account
 *   - Admin for any user via ?username=target
 *
 * AQI Threshold Calculation Algorithm
 * ─────────────────────────────────────
 *   If X < AQI ≤ Y → Caution  (X = caution_threshold)
 *   If AQI > Y     → Dangerous (Y = danger_threshold)
 *
 * Auto-calculation when doctor has NOT prescribed:
 *
 *   Diagnosed:
 *     Asthma / COPD / Chronic bronchitis  → caution=50,  danger=100
 *     Severe allergies                    → caution=75,  danger=125
 *     Other                               → caution=75,  danger=150
 *
 *   Non-diagnosed (discomfort × sensitivity matrix):
 *     Frequently  + Highly       → caution=50,  danger=100
 *     Frequently  + Moderately   → caution=75,  danger=125
 *     Frequently  + Not          → caution=75,  danger=150
 *     Sometimes   + Highly       → caution=75,  danger=125
 *     Sometimes   + Moderately   → caution=100, danger=150
 *     Sometimes   + Not          → caution=100, danger=150
 *     Rarely      + Highly       → caution=100, danger=150
 *     Rarely      + Moderately   → caution=100, danger=200
 *     Rarely/Never+ Not          → caution=150, danger=200
 *     Never       + any          → caution=150, danger=200
 *
 *   Prefer not to say: standard → caution=100, danger=150
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/session.php';

requireLogin();

$pdo          = getDB();
$sessionUser  = getSessionUser($pdo);
$isAdmin      = (bool) $sessionUser['is_admin'];

// Determine target username
$targetUsername = trim($_GET['username'] ?? $sessionUser['username']);

// Security: non-admins can only edit their own profile
if (!$isAdmin && $targetUsername !== $sessionUser['username']) {
    header('Location: ' . BASE_URL . '/update_medics.php?username=' . urlencode($sessionUser['username']));
    exit;
}

// Load target user
$stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u AND is_admin = 0 LIMIT 1');
$stmt->execute([':u' => $targetUsername]);
$targetUser = $stmt->fetch();

if (!$targetUser) {
    die('<p style="color:red;padding:2rem;">User not found.</p>');
}

// ── AQI calculation function ──────────────────────────────────
function calcThresholds(
    string $diagnosed,
    string $condition,
    string $discomfort,
    string $sensitivity
): array {
    if ($diagnosed === 'yes') {
        return match ($condition) {
            'asthma', 'copd', 'chronic_bronchitis'
                => ['caution' => 50,  'danger' => 100],
            'severe_allergies'
                => ['caution' => 75,  'danger' => 125],
            default
                => ['caution' => 75,  'danger' => 150],
        };
    }

    if ($diagnosed === 'prefer_not') {
        return ['caution' => 100, 'danger' => 150]; // standard
    }

    // Non-diagnosed: matrix
    return match (true) {
        $discomfort === 'frequently' && $sensitivity === 'highly'
            => ['caution' => 50,  'danger' => 100],
        ($discomfort === 'frequently' && $sensitivity === 'moderately') ||
        ($discomfort === 'sometimes'  && $sensitivity === 'highly')
            => ['caution' => 75,  'danger' => 125],
        ($discomfort === 'frequently' && $sensitivity === 'not') ||
        ($discomfort === 'sometimes'  && ($sensitivity === 'moderately' || $sensitivity === 'not')) ||
        ($discomfort === 'rarely'     && $sensitivity === 'highly')
            => ['caution' => 100, 'danger' => 150],
        $discomfort === 'rarely' && $sensitivity === 'moderately'
            => ['caution' => 100, 'danger' => 200],
        default
            => ['caution' => 150, 'danger' => 200],
    };
}

$success = false;
$errors  = [];
$calcResult = null;   // holds computed thresholds to show user

// ── Handle form submission ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $diagnosed    = $_POST['diagnosed']   ?? '';
    $condition    = $_POST['condition']   ?? '';
    $discomfort   = $_POST['discomfort']  ?? '';
    $sensitivity  = $_POST['sensitivity'] ?? '';
    $doctorLimit  = $_POST['doctor_limit'] ?? '';
    $doctorAqi    = (int) ($_POST['doctor_aqi']     ?? 0);
    $doctorEarly  = (int) ($_POST['doctor_early']   ?? 0);
    $confirmed    = !empty($_POST['confirm']);

    if (!$confirmed) $errors[] = 'Please tick the confirmation checkbox before submitting.';

    if (empty($errors)) {
        if ($doctorLimit === 'yes') {
            // Doctor-prescribed values
            if ($doctorAqi < 1 || $doctorAqi > 500)  $errors[] = 'Maximum AQI must be between 1 and 500.';
            if ($doctorEarly < 1 || $doctorEarly > 500) $errors[] = 'Early warning AQI must be between 1 and 500.';
            if ($doctorEarly >= $doctorAqi)           $errors[] = 'Early warning must be lower than maximum AQI.';

            if (empty($errors)) {
                $caution = $doctorEarly;
                $danger  = $doctorAqi;
            }
        } else {
            // Auto-calculate
            $t = calcThresholds($diagnosed, $condition, $discomfort, $sensitivity);
            $caution = $t['caution'];
            $danger  = $t['danger'];
            $calcResult = $t;
        }

        if (empty($errors)) {
            $upd = $pdo->prepare(
                'UPDATE users SET caution_threshold = :c, danger_threshold = :d WHERE username = :u'
            );
            $upd->execute([':c' => $caution, ':d' => $danger, ':u' => $targetUsername]);
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Profile – OxySafe</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body class="dashboard-page">

<!-- ─── Navbar ─────────────────────────────────────────────── -->
<nav class="navbar">
    <div class="nav-brand"><span class="logo-icon">🌬️</span> OxySafe</div>
    <div class="nav-user">
        <?php if ($isAdmin): ?>
            <a href="<?= BASE_URL ?>/admin_dashboard.php" class="btn btn-outline btn-sm">← Admin</a>
        <?php else: ?>
            <a href="<?= BASE_URL ?>/user_dashboard.php" class="btn btn-outline btn-sm">← Dashboard</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/auth/logout.php" class="btn btn-outline btn-sm">Logout</a>
    </div>
</nav>

<main class="container" style="max-width:680px">

    <div class="page-header">
        <div>
            <h2 class="page-title">⚕️ Health Profile</h2>
            <p class="page-sub">
                Configuring thresholds for
                <strong><?= htmlspecialchars($targetUser['name']) ?></strong>
                (<code><?= htmlspecialchars($targetUsername) ?></code>)
            </p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <p>✅ Thresholds saved!
               <strong>Caution ≥ <?= $caution ?></strong>,
               <strong>Dangerous > <?= $danger ?></strong>
               <?php if ($calcResult): ?>
                   <em>(auto-calculated from your responses)</em>
               <?php endif; ?>
            </p>
            <p style="margin-top:8px">
                <?php if ($isAdmin): ?>
                    <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/admin_dashboard.php">← Back to Admin</a>
                <?php else: ?>
                    <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/user_dashboard.php">View Dashboard →</a>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <div class="card medics-card">
        <form method="POST" id="medics-form" novalidate>

            <!-- ── Step 1: Diagnosed? ──────────────────────── -->
            <div class="form-step">
                <label class="step-label">
                    <span class="step-num">1</span>
                    Do you have any medically diagnosed respiratory condition?
                </label>
                <select name="diagnosed" id="diagnosed" class="form-select" required
                        onchange="handleDiagnosed(this.value)">
                    <option value="">— Select —</option>
                    <option value="yes">Yes</option>
                    <option value="no">No</option>
                    <option value="prefer_not">Prefer not to say</option>
                </select>
            </div>

            <!-- ── Step 2: Which condition? (show if yes) ──── -->
            <div class="form-step conditional" id="step-condition" style="display:none">
                <label class="step-label">
                    <span class="step-num">2</span>
                    If yes, please select the condition:
                </label>
                <select name="condition" id="condition" class="form-select">
                    <option value="">— Select condition —</option>
                    <option value="asthma">Asthma</option>
                    <option value="copd">COPD</option>
                    <option value="chronic_bronchitis">Chronic bronchitis</option>
                    <option value="severe_allergies">Severe allergies</option>
                    <option value="other">Other respiratory condition</option>
                </select>
            </div>

            <!-- ── Step 3: Discomfort? (show if no) ─────────── -->
            <div class="form-step conditional" id="step-discomfort" style="display:none">
                <label class="step-label">
                    <span class="step-num">3</span>
                    Do you usually experience breathing discomfort in polluted air?
                </label>
                <select name="discomfort" id="discomfort" class="form-select">
                    <option value="">— Select frequency —</option>
                    <option value="frequently">Frequently</option>
                    <option value="sometimes">Sometimes</option>
                    <option value="rarely">Rarely</option>
                    <option value="never">Never</option>
                </select>
            </div>

            <!-- ── Step 4: Sensitivity? (show if no) ─────────── -->
            <div class="form-step conditional" id="step-sensitivity" style="display:none">
                <label class="step-label">
                    <span class="step-num">4</span>
                    Would you consider yourself sensitive to dust or pollution?
                </label>
                <select name="sensitivity" id="sensitivity" class="form-select">
                    <option value="">— Select sensitivity —</option>
                    <option value="highly">Highly sensitive</option>
                    <option value="moderately">Moderately sensitive</option>
                    <option value="not">Not sensitive</option>
                </select>
            </div>

            <!-- ── Step 5: Doctor limit (common) ─────────────── -->
            <div class="form-step" id="step-doctor-q" style="display:none">
                <label class="step-label">
                    <span class="step-num">5</span>
                    Has your doctor recommended a specific AQI limit for you?
                </label>
                <select name="doctor_limit" id="doctor_limit" class="form-select"
                        onchange="handleDoctorLimit(this.value)">
                    <option value="">— Select —</option>
                    <option value="yes">Yes</option>
                    <option value="no">No</option>
                </select>
            </div>

            <!-- ── Step 6 & 7: Doctor-prescribed values ──────── -->
            <div id="step-doctor-vals" style="display:none">
                <div class="form-step">
                    <label class="step-label">
                        <span class="step-num">6</span>
                        Enter the maximum AQI level you should be exposed to:
                        <small class="step-hint">(this will be your <strong>Danger</strong> threshold)</small>
                    </label>
                    <input type="number" name="doctor_aqi" id="doctor_aqi"
                           class="form-input-num" min="1" max="500"
                           placeholder="e.g. 100">
                </div>
                <div class="form-step">
                    <label class="step-label">
                        <span class="step-num">7</span>
                        Enter AQI level at which you want an early warning alert:
                        <small class="step-hint">(this will be your <strong>Caution</strong> threshold)</small>
                    </label>
                    <input type="number" name="doctor_early" id="doctor_early"
                           class="form-input-num" min="1" max="500"
                           placeholder="e.g. 75">
                </div>
            </div>

            <!-- ── Confirmation checkbox ──────────────────── -->
            <div class="form-step confirm-step" id="step-confirm" style="display:none">
                <label class="checkbox-label">
                    <input type="checkbox" name="confirm" value="1">
                    I confirm that these values are based on medical advice or personal preference.
                </label>
            </div>

            <div class="form-actions" id="form-actions" style="display:none">
                <button type="submit" class="btn btn-primary btn-block">
                    Save Health Profile
                </button>
            </div>

        </form>
    </div>
    <?php endif; ?>

    <!-- AQI Threshold Reference Card ──────────────────────── -->
    <div class="card ref-card">
        <h3 class="card-title">📊 AQI Reference</h3>
        <div class="aqi-ref-table">
            <div class="ref-row"><span class="pill pill-aqi" style="background:#00e40022;color:#00e400">0–50</span><span>Good</span></div>
            <div class="ref-row"><span class="pill pill-aqi" style="background:#ffff0022;color:#cccc00">51–100</span><span>Moderate</span></div>
            <div class="ref-row"><span class="pill pill-aqi" style="background:#ff7e0022;color:#ff7e00">101–150</span><span>Unhealthy for Sensitive Groups</span></div>
            <div class="ref-row"><span class="pill pill-aqi" style="background:#ff000022;color:#ff6666">151–200</span><span>Unhealthy</span></div>
            <div class="ref-row"><span class="pill pill-aqi" style="background:#8f3f9722;color:#cc88ff">201–300</span><span>Very Unhealthy</span></div>
            <div class="ref-row"><span class="pill pill-aqi" style="background:#7e002222;color:#ff4466">301–500</span><span>Hazardous</span></div>
        </div>
    </div>

</main>

<script>
function showEl(id)  { document.getElementById(id).style.display = ''; }
function hideEl(id)  { document.getElementById(id).style.display = 'none'; }

function handleDiagnosed(val) {
    // hide all conditional sections first
    hideEl('step-condition');
    hideEl('step-discomfort');
    hideEl('step-sensitivity');
    hideEl('step-doctor-q');
    hideEl('step-doctor-vals');
    hideEl('step-confirm');
    hideEl('form-actions');

    if (val === 'yes') {
        showEl('step-condition');
        // listen for condition selection to reveal step 5
        document.getElementById('condition').onchange = () => showCommon();
    } else if (val === 'no') {
        showEl('step-discomfort');
        showEl('step-sensitivity');
        // show step 5 once both are selected
        ['discomfort','sensitivity'].forEach(id => {
            document.getElementById(id).onchange = maybeShowCommon;
        });
    } else if (val === 'prefer_not') {
        showCommon(); // go directly to step 5
    }
}

function maybeShowCommon() {
    const d = document.getElementById('discomfort').value;
    const s = document.getElementById('sensitivity').value;
    if (d && s) showCommon();
}

function showCommon() {
    showEl('step-doctor-q');
}

function handleDoctorLimit(val) {
    if (val === 'yes') {
        showEl('step-doctor-vals');
    } else {
        hideEl('step-doctor-vals');
    }
    if (val) {
        showEl('step-confirm');
        showEl('form-actions');
    }
}
</script>

</body>
</html>
