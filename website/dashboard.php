<?php
/**
 * OxySafe – Dashboard
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/session.php';

requireLogin();

$pdo  = getDB();
$user = getSessionUser($pdo);

// ── Handle threshold update ────────────────────────────────────
$updateMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['threshold_aqi'])) {
    $newThreshold = (int) $_POST['threshold_aqi'];
    if ($newThreshold >= 1 && $newThreshold <= 500) {
        $stmt = $pdo->prepare('UPDATE users SET threshold_aqi = :t WHERE id = :id');
        $stmt->execute([':t' => $newThreshold, ':id' => $user['id']]);
        $user['threshold_aqi'] = $newThreshold;
        $updateMsg = 'Threshold updated successfully.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard – OxySafe</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/style.css">
</head>
<body class="dashboard-page">

<!-- ─── Navbar ──────────────────────────────────────────────── -->
<nav class="navbar">
    <div class="nav-brand">
        <span class="logo-icon">🌬️</span> OxySafe
    </div>
    <div class="nav-user">
        <span>👤 <?= htmlspecialchars($user['username']) ?></span>
        <a href="<?= BASE_URL ?>/auth/logout.php" class="btn btn-outline btn-sm">Logout</a>
    </div>
</nav>

<!-- ─── Alert Banner (hidden by default) ──────────────────────── -->
<div id="alert-banner" class="alert-banner hidden" role="alert">
    <span id="alert-text">⚠️ AQI has exceeded your threshold!</span>
    <button onclick="document.getElementById('alert-banner').classList.add('hidden')">✕</button>
</div>

<main class="container">

    <!-- Last updated timestamp -->
    <p class="last-update">Last updated: <span id="last-update-time">—</span></p>

    <!-- ─── AQI Gauge Card ───────────────────────────────────── -->
    <section class="card aqi-card">
        <h2>Air Quality Index</h2>
        <div class="aqi-gauge">
            <div class="aqi-value" id="aqi-value">—</div>
            <div class="aqi-label" id="aqi-category">Waiting for data…</div>
        </div>
        <div class="aqi-bar-track">
            <div class="aqi-bar-fill" id="aqi-bar"></div>
        </div>
        <div class="aqi-scale-labels">
            <span class="good">Good</span>
            <span class="moderate">Moderate</span>
            <span class="sensitive">USG</span>
            <span class="unhealthy">Unhealthy</span>
            <span class="very-unhealthy">V.Unhealthy</span>
            <span class="hazardous">Hazardous</span>
        </div>
    </section>

    <!-- ─── Sensor Metrics ──────────────────────────────────── -->
    <section class="metrics-grid">
        <div class="card metric-card">
            <div class="metric-icon">🌡️</div>
            <div class="metric-label">Temperature</div>
            <div class="metric-value" id="temperature">—</div>
            <div class="metric-unit">°C</div>
        </div>
        <div class="card metric-card">
            <div class="metric-icon">💧</div>
            <div class="metric-label">Humidity</div>
            <div class="metric-value" id="humidity">—</div>
            <div class="metric-unit">%</div>
        </div>
        <div class="card metric-card">
            <div class="metric-icon">🌫️</div>
            <div class="metric-label">Dust Density</div>
            <div class="metric-value" id="dust-density">—</div>
            <div class="metric-unit">µg/m³</div>
        </div>
    </section>

    <!-- ─── Threshold Settings ──────────────────────────────── -->
    <section class="card settings-card">
        <h2>Alert Settings</h2>
        <?php if ($updateMsg): ?>
            <div class="alert alert-success"><p><?= htmlspecialchars($updateMsg) ?></p></div>
        <?php endif; ?>
        <form method="POST" class="threshold-form">
            <label for="threshold_input">
                Alert me when AQI exceeds:
                <span class="badge" id="thresh-badge">
                    <?= (int)$user['threshold_aqi'] ?>
                </span>
            </label>
            <input type="range" id="threshold_input" name="threshold_aqi"
                   min="1" max="300" step="1"
                   value="<?= (int)$user['threshold_aqi'] ?>"
                   oninput="document.getElementById('thresh-badge').textContent=this.value">
            <div class="aqi-scale-labels">
                <span class="good">Good (0–50)</span>
                <span class="moderate">Moderate (51–100)</span>
                <span class="sensitive">USG (101–150)</span>
                <span class="unhealthy">Unhealthy (151–200)</span>
                <span class="very-unhealthy">V.Unhealthy (201–300)</span>
            </div>
            <button type="submit" class="btn btn-primary">Save Threshold</button>
        </form>
    </section>

</main>

<!-- ─── JavaScript: Poll API every 10 s ─────────────────────── -->
<script>
const API_URL   = '<?= BASE_URL ?>/api/get_data.php';
const POLL_MS   = 10000;   // 10 seconds – matches firmware send interval

const $ = id => document.getElementById(id);

// AQI colour thresholds
function aqiColour(aqi) {
    if (aqi <= 50)  return '#00e400';   // Good
    if (aqi <= 100) return '#ffff00';   // Moderate
    if (aqi <= 150) return '#ff7e00';   // Unhealthy for sensitive groups
    if (aqi <= 200) return '#ff0000';   // Unhealthy
    if (aqi <= 300) return '#8f3f97';   // Very Unhealthy
    return '#7e0023';                   // Hazardous
}

async function fetchData() {
    try {
        const resp = await fetch(API_URL, { cache: 'no-store' });
        if (!resp.ok) return;
        const d = await resp.json();

        // ── Update AQI gauge ─────────────────────────────────
        const aqi = d.aqi.toFixed(0);
        $('aqi-value').textContent    = aqi;
        $('aqi-category').textContent = d.category;

        const pct = Math.min((d.aqi / 300) * 100, 100).toFixed(1);
        const colour = aqiColour(d.aqi);
        $('aqi-bar').style.width      = pct + '%';
        $('aqi-bar').style.background = colour;
        $('aqi-value').style.color    = colour;

        // ── Update metrics ───────────────────────────────────
        $('temperature').textContent  = d.temperature.toFixed(1);
        $('humidity').textContent     = d.humidity.toFixed(1);
        $('dust-density').textContent = d.dust_density.toFixed(1);

        // ── Timestamp ────────────────────────────────────────
        $('last-update-time').textContent = d.recorded_at;

        // ── Alert ─────────────────────────────────────────────
        const banner = $('alert-banner');
        if (d.alert) {
            $('alert-text').textContent =
                `⚠️ AQI ${aqi} has exceeded your threshold of ${d.threshold}! (${d.category})`;
            banner.classList.remove('hidden');
        } else {
            banner.classList.add('hidden');
        }
    } catch (err) {
        console.warn('OxySafe: API poll failed –', err.message);
    }
}

// Initial fetch + schedule
fetchData();
setInterval(fetchData, POLL_MS);
</script>
</body>
</html>
