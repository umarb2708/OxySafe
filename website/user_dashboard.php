<?php
/**
 * OxySafe – User Dashboard
 * Polls sensor_data for the logged-in user every 10 s.
 * Requires caution_threshold & danger_threshold to be set;
 * redirects to update_medics.php if not yet configured.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/session.php';

requireLogin();

$pdo  = getDB();
$user = getSessionUser($pdo);

// ── Guard: thresholds must be configured ─────────────────────
if ($user['caution_threshold'] === null || $user['danger_threshold'] === null) {
    header('Location: ' . BASE_URL . '/update_medics.php?username=' . urlencode($user['username']));
    exit;
}

$caution = (int) $user['caution_threshold'];
$danger  = (int) $user['danger_threshold'];

// ── Fetch last 20 readings for history table ──────────────────
$stmt = $pdo->prepare(
    "SELECT temp, humidity, dust_density, aqi,
            DATE_FORMAT(recorded_at, '%Y-%m-%d %H:%i:%s') AS recorded_at
     FROM sensor_data
     WHERE username = :u
     ORDER BY recorded_at DESC
     LIMIT 20"
);
$stmt->execute([':u' => $user['username']]);
$history = $stmt->fetchAll();

// ── Latest reading ────────────────────────────────────────────
$latest = $history[0] ?? null;
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

<!-- ─── Navbar ─────────────────────────────────────────────── -->
<nav class="navbar">
    <div class="nav-brand"><span class="logo-icon">🌬️</span> OxySafe</div>
    <div class="nav-user">
        <span>👤 <?= htmlspecialchars($user['name']) ?></span>
        <a href="<?= BASE_URL ?>/update_medics.php?username=<?= urlencode($user['username']) ?>"
           class="btn btn-outline btn-sm">⚕️ Update Medics</a>
        <a href="<?= BASE_URL ?>/auth/logout.php" class="btn btn-outline btn-sm">Logout</a>
    </div>
</nav>

<!-- ─── Dynamic Alert Banner ───────────────────────────────── -->
<div id="alert-banner" class="alert-banner hidden" role="alert">
    <div id="alert-text">⚠️ AQI threshold exceeded!</div>
    <button onclick="this.parentElement.classList.add('hidden')">✕</button>
</div>

<main class="container">

    <div class="page-header">
        <div>
            <h2 class="page-title">Air Quality Monitor</h2>
            <p class="page-sub">
                Thresholds —
                <span class="pill pill-yellow">Caution ≥ <?= $caution ?></span>
                <span class="pill pill-red">Dangerous > <?= $danger ?></span>
            </p>
        </div>
        <p class="last-update">Updated: <span id="last-update-time">—</span></p>
    </div>

    <!-- ─── Live AQI Card ──────────────────────────────────── -->
    <div class="live-grid">

        <section class="card aqi-card" id="aqi-card">
            <div class="aqi-level-pill" id="aqi-level-pill">—</div>
            <h2>Air Quality Index</h2>
            <div class="aqi-gauge">
                <svg viewBox="0 0 200 120" class="gauge-svg">
                    <!-- background arc -->
                    <path d="M 20 110 A 80 80 0 0 1 180 110"
                          fill="none" stroke="#2e3148" stroke-width="18" stroke-linecap="round"/>
                    <!-- coloured arc -->
                    <path id="gauge-arc"
                          d="M 20 110 A 80 80 0 0 1 180 110"
                          fill="none" stroke="#444" stroke-width="18"
                          stroke-linecap="round"
                          stroke-dasharray="0 251"/>
                    <!-- needle -->
                    <line id="gauge-needle"
                          x1="100" y1="110" x2="100" y2="38"
                          stroke="#e8eaf6" stroke-width="2" stroke-linecap="round"
                          transform-origin="100 110"/>
                    <circle cx="100" cy="110" r="6" fill="#e8eaf6"/>
                </svg>
                <div class="aqi-value" id="aqi-value">—</div>
            </div>
            <div class="aqi-label" id="aqi-category">Waiting for data…</div>

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

        <!-- ── Metric Cards ──────────────────────────────────── -->
        <div class="metrics-col">
            <div class="card metric-card glow-card">
                <div class="metric-icon">🌡️</div>
                <div class="metric-label">Temperature</div>
                <div class="metric-value" id="temperature">
                    <?= $latest ? number_format($latest['temp'], 1) : '—' ?>
                </div>
                <div class="metric-unit">°C</div>
            </div>
            <div class="card metric-card glow-card">
                <div class="metric-icon">💧</div>
                <div class="metric-label">Humidity</div>
                <div class="metric-value" id="humidity">
                    <?= $latest ? number_format($latest['humidity'], 1) : '—' ?>
                </div>
                <div class="metric-unit">%</div>
            </div>
            <div class="card metric-card glow-card">
                <div class="metric-icon">🌫️</div>
                <div class="metric-label">Dust Density</div>
                <div class="metric-value" id="dust-density">
                    <?= $latest ? number_format($latest['dust_density'], 1) : '—' ?>
                </div>
                <div class="metric-unit">µg/m³</div>
            </div>
        </div>

    </div><!-- /live-grid -->

    <!-- ─── Reading History Table ──────────────────────────── -->
    <div class="card table-card">
        <h3 class="card-title">📜 Recent Readings</h3>
        <?php if (empty($history)): ?>
            <p class="empty-msg">No data received yet. Ensure the device is online.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table class="data-table" id="history-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Temp (°C)</th>
                        <th>Humidity (%)</th>
                        <th>Dust (µg/m³)</th>
                        <th>AQI</th>
                        <th>Level</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $row):
                        $lvl = aqiLevel((float)$row['aqi'], $caution, $danger);
                        $lvlClass = match($lvl) {
                            'Safe'      => 'pill-green',
                            'Caution'   => 'pill-yellow',
                            'Dangerous' => 'pill-red',
                            default     => ''
                        };
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($row['recorded_at']) ?></td>
                        <td><?= number_format($row['temp'], 1) ?></td>
                        <td><?= number_format($row['humidity'], 1) ?></td>
                        <td><?= number_format($row['dust_density'], 1) ?></td>
                        <td><?= number_format($row['aqi'], 1) ?></td>
                        <td><span class="pill <?= $lvlClass ?>"><?= $lvl ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</main>

<!-- ─── JS: Poll API every 10 s ───────────────────────────── -->
<script>
const API_URL   = '<?= BASE_URL ?>/api/get_data.php';
const CAUTION   = <?= $caution ?>;
const DANGER    = <?= $danger ?>;
const POLL_MS   = 10000;

const $el = id => document.getElementById(id);

function aqiColour(aqi) {
    if (aqi <= 50)  return '#00e400';
    if (aqi <= 100) return '#a8c400';
    if (aqi <= 150) return '#ff7e00';
    if (aqi <= 200) return '#ff0000';
    if (aqi <= 300) return '#8f3f97';
    return '#7e0023';
}

function levelFromAqi(aqi) {
    if (aqi <= CAUTION) return { label: 'Safe',      cls: 'pill-green' };
    if (aqi <= DANGER)  return { label: 'Caution',   cls: 'pill-yellow' };
    return               { label: 'Dangerous', cls: 'pill-red' };
}

// SVG gauge arc (semicircle)  arc length ≈ 251
function updateGauge(aqi) {
    const pct   = Math.min(aqi / 300, 1);
    const total = 251;
    const dash  = (pct * total).toFixed(1);
    const colour = aqiColour(aqi);
    $el('gauge-arc').setAttribute('stroke-dasharray', dash + ' ' + total);
    $el('gauge-arc').setAttribute('stroke', colour);
    // rotate needle: 0 aqi → -90°, 300 aqi → +90°
    const angle = (pct * 180) - 90;
    $el('gauge-needle').setAttribute('transform', `rotate(${angle}, 100, 110)`);
}

function prependTableRow(d, lvl) {
    const tbody = document.querySelector('#history-table tbody');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>${d.recorded_at}</td>
        <td>${d.temperature.toFixed(1)}</td>
        <td>${d.humidity.toFixed(1)}</td>
        <td>${d.dust_density.toFixed(1)}</td>
        <td>${d.aqi.toFixed(1)}</td>
        <td><span class="pill ${lvl.cls}">${lvl.label}</span></td>`;
    tbody.insertBefore(tr, tbody.firstChild);
    // keep max 20 rows
    while (tbody.rows.length > 20) tbody.deleteRow(tbody.rows.length - 1);
}

async function fetchData() {
    try {
        const resp = await fetch(API_URL, { cache: 'no-store' });
        if (!resp.ok) return;
        const d = await resp.json();
        if (d.error) return;

        const aqi   = d.aqi;
        const colour = aqiColour(aqi);
        const lvl    = levelFromAqi(aqi);

        // AQI display
        $el('aqi-value').textContent    = aqi.toFixed(0);
        $el('aqi-value').style.color    = colour;
        $el('aqi-category').textContent = d.category;

        // Gauge
        updateGauge(aqi);

        // Progress bar
        const pct = Math.min((aqi / 300) * 100, 100).toFixed(1);
        $el('aqi-bar').style.width      = pct + '%';
        $el('aqi-bar').style.background = colour;

        // Level pill on card
        const pill = $el('aqi-level-pill');
        pill.textContent = lvl.label;
        pill.className   = 'aqi-level-pill pill ' + lvl.cls;

        // Card glow
        const card = $el('aqi-card');
        card.style.boxShadow = `0 0 40px ${colour}44, var(--shadow)`;

        // Metrics
        $el('temperature').textContent  = d.temperature.toFixed(1);
        $el('humidity').textContent     = d.humidity.toFixed(1);
        $el('dust-density').textContent = d.dust_density.toFixed(1);
        $el('last-update-time').textContent = d.recorded_at;

        // Alert banner
        const banner = $el('alert-banner');
        if (lvl.label === 'Dangerous') {
            $el('alert-text').textContent =
                `🚨 DANGEROUS! AQI ${aqi.toFixed(0)} exceeds your danger limit of ${DANGER}. Move to a safer area!`;
            banner.classList.remove('hidden');
            banner.classList.add('banner-red');
            banner.classList.remove('banner-orange');
        } else if (lvl.label === 'Caution') {
            $el('alert-text').textContent =
                `⚠️ CAUTION! AQI ${aqi.toFixed(0)} is above your caution limit of ${CAUTION}.`;
            banner.classList.remove('hidden');
            banner.classList.add('banner-orange');
            banner.classList.remove('banner-red');
        } else {
            banner.classList.add('hidden');
        }

        // Prepend row to history
        prependTableRow(d, lvl);

    } catch (err) {
        console.warn('OxySafe poll failed:', err.message);
    }
}

// bootstrap gauge with any existing data
<?php if ($latest): ?>
updateGauge(<?= (float)$latest['aqi'] ?>);
$el('aqi-value').textContent    = '<?= number_format((float)$latest['aqi'], 0) ?>';
$el('aqi-value').style.color    = aqiColour(<?= (float)$latest['aqi'] ?>);
$el('aqi-category').textContent = '<?= addslashes(aqiCategory((float)$latest['aqi'])) ?>';
const _pct = Math.min(<?= (float)$latest['aqi'] ?> / 300 * 100, 100).toFixed(1);
$el('aqi-bar').style.width      = _pct + '%';
$el('aqi-bar').style.background = aqiColour(<?= (float)$latest['aqi'] ?>);
const _lvl = levelFromAqi(<?= (float)$latest['aqi'] ?>);
const _pill = $el('aqi-level-pill');
_pill.textContent = _lvl.label;
_pill.className   = 'aqi-level-pill pill ' + _lvl.cls;
<?php endif; ?>

fetchData();
setInterval(fetchData, POLL_MS);
</script>
</body>
</html>
