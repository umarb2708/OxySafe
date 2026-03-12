# OxySafe – Air Quality Monitoring System

---

## Table of Contents
1. [Introduction](#1-introduction)
2. [Hardware Used](#2-hardware-used)
3. [Software Used](#3-software-used)
4. [Working](#4-working)
5. [Pin Diagram](#5-pin-diagram)
6. [Website Directory & Files](#6-website-directory--files)

---

## 1. Introduction

**OxySafe** is an IoT-based air quality monitoring system designed to measure and report environmental parameters in real time. The device reads temperature, humidity, and particulate matter (dust) levels using dedicated sensors mounted on an ESP8266 NodeMCU microcontroller. Collected data is transmitted over Wi-Fi to a centralized PHP/MySQL web server, where both regular users and administrators can monitor readings through individual dashboards.

Key highlights:
- Real-time sensor readings posted every **10 seconds**
- **AQI (Air Quality Index)** calculated on-device using the EPA PM2.5 breakpoint formula
- Configurable per-user **caution** and **danger** AQI thresholds with visual alerts on the dashboard
- **Captive-portal Wi-Fi setup** — no hardcoded credentials; the device hosts its own Wi-Fi access point on first boot for easy configuration
- Auto-purge of sensor records older than **6 hours** to keep the database lean
- Role-based web access: **Admin** (user management) and **User** (personal dashboard)

---

## 2. Hardware Used

| # | Component | Model | Purpose |
|---|-----------|-------|---------|
| 1 | Microcontroller | **ESP8266 NodeMCU v1.0** | Main compute unit; Wi-Fi, sensor reading, HTTP client |
| 2 | Temperature & Humidity Sensor | **DHT11** | Measures ambient temperature (°C) and relative humidity (%) |
| 3 | Optical Dust Sensor | **Sharp GP2Y1010AU0F** | Measures particulate matter (PM2.5/PM10) density in µg/m³ |
| 4 | Resistor | **150 Ω** | Current-limiting resistor between 5V and GP2Y1010 V-LED pin |
| 5 | Capacitor | **220 µF electrolytic** | Power-supply decoupling on GP2Y1010 Vcc to suppress LED-pulse noise |
| 6 | USB cable / 5 V supply | — | Powers NodeMCU via VIN; **GP2Y1010 requires 5V** (DHT11 uses 3.3V) |
| 7 | Voltage Divider Resistors | 100kΩ + 33kΩ | Scales GP2Y1010 output (0-4V) to safe ESP8266 ADC range (0-1V) |

---

## 3. Software Used

### Firmware (Arduino / ESP8266)

| Library | Version | Purpose |
|---------|---------|---------|
| **ESP8266WiFi** | Bundled with ESP8266 board package | Wi-Fi station & soft-AP management |
| **ESP8266WebServer** | Bundled with ESP8266 board package | Captive-portal HTTP server for Wi-Fi setup |
| **ESP8266HTTPClient** | Bundled with ESP8266 board package | HTTP POST of sensor data to the server |
| **EEPROM** | Bundled with ESP8266 board package | Non-volatile storage of Wi-Fi credentials & server details |
| **DHT sensor library** | Adafruit (any recent v1.x) | Reading temperature and humidity from DHT11 |
| **ArduinoJson** | Benoit Blanchon v6.x | Serializing sensor readings to JSON for the API |

> **IDE:** Arduino IDE 1.8+ or VS Code with the Arduino extension.  
> **Board package:** `esp8266` by ESP8266 Community (install via Boards Manager).

### Backend Web Application

| Technology | Role |
|------------|------|
| **PHP 8.x** | Server-side scripting for all pages and API endpoints |
| **MySQL 8 / MariaDB** | Relational database (users, sensor readings) |
| **PDO (PHP Data Objects)** | Secure, prepared-statement database access layer |
| **bcrypt (`password_hash`)** | Password hashing for stored user credentials |
| **HTML5 / CSS3** | Frontend markup and custom dark-themed styling |
| **JavaScript (Vanilla)** | Auto-polling dashboard (fetches new readings every 10 s) |
| **Apache / Nginx** | Web server to host the PHP application |

---

## 4. Working

### 4.1 Boot Sequence

```
Power on
   │
   ▼
Load credentials from EEPROM flash
   │
   ├─ No valid config ──────────────────────────────────────┐
   │                                                         │
   ▼                                                         ▼
Try connecting to saved Wi-Fi (15 s timeout)         Enter Config Mode
   │                                                    ESP8266 starts
   ├─ Failed ──────────────────────────────────────►   Soft-AP "OxySafe"
   │                                                   User opens browser
   │                                                   at 192.168.4.1
   │                                                   Fills: SSID / Pass /
   │                                                          Server IP / Username
   │                                                   Saved to EEPROM → Reboot
   ▼
Connected to Wi-Fi
   │
   ▼
Start sensor loop (POST every 10 s)
```

### 4.2 Sensor Loop (Normal Operation)

Every **10 seconds** the firmware:

1. **Reads DHT11** — temperature (°C) and humidity (%).
2. **Reads GP2Y1010** — pulses the infrared LED LOW for 280 µs, samples the ADC, converts the raw voltage to dust density (µg/m³) using the Sharp calibration curve.
3. **Calculates AQI** — applies the EPA PM2.5 linear interpolation formula across 7 breakpoint ranges (0 – 500).
4. **HTTP POSTs** a JSON payload to `/oxysafe/api/data.php` with an `X-API-Key` header for authentication.
5. Prints a formatted summary to the serial monitor for debugging.

### 4.3 API & Database

The PHP endpoint (`api/data.php`) receives the POST, validates the API key, checks the username exists, validates sensor value ranges, and inserts the row into the `sensor_data` table. A MySQL scheduled event automatically purges records older than 6 hours.

### 4.4 Web Dashboards

| Role | Landing Page | Capabilities |
|------|-------------|--------------|
| **Admin** | `admin_dashboard.php` | Add / delete users, view all device statuses, manage per-user thresholds |
| **User** | `user_dashboard.php` | Live AQI gauge, temperature, humidity, 20-reading history table, colour-coded caution/danger alerts |

The user dashboard auto-refreshes via JavaScript every 10 s using `api/get_data.php`, keeping readings up to date without a full page reload. On first login, users are redirected to `update_medics.php` to set their personal caution and danger AQI thresholds before the dashboard becomes active.

### 4.5 AQI Colour Scale

| AQI Range | Category | Colour |
|-----------|----------|--------|
| 0 – 50 | Good | Green |
| 51 – 100 | Moderate | Yellow |
| 101 – 150 | Unhealthy for Sensitive Groups | Orange |
| 151 – 200 | Unhealthy | Red |
| 201 – 300 | Very Unhealthy | Purple |
| 301 – 500 | Hazardous | Maroon |

---

## 5. Pin Diagram

### Connection Summary

| Sensor / Component | Sensor Pin | ESP8266 NodeMCU Pin | Notes |
|--------------------|-----------|---------------------|-------|
| **DHT11** | VCC | 3V3 | |
| DHT11 | DATA | **D4** (GPIO2) | 10 kΩ pull-up to 3.3 V (usually on breakout) |
| DHT11 | GND | GND | |
| **GP2Y1010AU0F** | Pin 1 – V-LED | **5V** via 150 Ω | LED anode; resistor limits current to ~33mA |
| GP2Y1010 | Pin 2 – LED-GND | GND | LED cathode |
| GP2Y1010 | Pin 3 – LED | **D5** (GPIO14) | Firmware pulses LOW to activate LED |
| GP2Y1010 | Pin 4 – S-GND | GND | Signal ground |
| GP2Y1010 | Pin 5 – Vo | **A0** via voltage divider | Analog dust output (0-4V); needs 100kΩ/33kΩ divider |
| GP2Y1010 | Pin 6 – Vcc | **5V** | Power supply + 220 µF cap to GND (observe polarity!) |

### ASCII Wiring Diagram

```
                     ┌───────────────────────────────────┐
                     │        ESP8266 NodeMCU             │
                     │                                    │
            3.3V ────┤ 3V3                           D4  ├──── DHT11 DATA
             GND ────┤ GND                           D5  ├──[150Ω]──┐
                     │                               A0  ├────────── │──── GP2Y Vo (pin 5)
                     └───────────────────────────────────┘           │
                                                                      │
  DHT11                                                    GP2Y1010AU0F
  ┌──────────┐                                             ┌──────────────────┐
  │ VCC  ●───┼── 3.3V                                      │ Pin 1 (V-LED) ●──┼──[150Ω]── 5V
  │ DATA ●───┼── D4                                        │ Pin 2 (LED-GND)●──┼── GND
  │ GND  ●───┼── GND                                       │ Pin 3 (LED)   ●──┼── D5
  └──────────┘                                             │ Pin 4 (S-GND) ●──┼── GND
                                                           │ Pin 5 (Vo)    ●──┼── [100kΩ] ── A0
                                                           │ Pin 6 (Vcc)   ●──┼── 5V ─┬─────── GND
                                                           └──────────────────┘       │        │
                                                                                  220µF cap  [33kΩ]
                                                                             (+ towards Vcc)    │
                                                                                              GND
```
⚠️ CRITICAL NOTES:**  
> 1. **5V Power Required:** The GP2Y1010AU0F requires **5V** (not 3.3V) for proper operation. Connect Pin 1 and Pin 6 to 5V rail.  
> 2. **ADC Protection:** ESP8266 `A0` accepts **0-1V maximum**. GP2Y1010 outputs up to ~4V. **You MUST use a voltage divider** (100kΩ series + 33kΩ to GND) between `Vo` and `A0` to prevent ADC damage.  
> 3. **220µF Capacitor:** Always install between Vcc (Pin 6) and GND with correct polarity to suppress LED pulse noise.  
> 4. **See [WIRING_DIAGRAM.md](WIRING_DIAGRAM.md) for complete assembly instructions.**
> **ADC voltage note:** The ESP8266 `A0` pin accepts **0 – 1 V** maximum. The GP2Y1010 outputs up to ~3.3 V. If your dust sensor module does **not** include a built-in voltage divider, add a resistor divider (e.g. 47 kΩ series + 100 kΩ to GND) between `Vo` and `A0` to scale the signal appropriately.

---

## 6. Website Directory & Files

```
website/
│
├── index.php               # Login page — authenticates users via bcrypt;
│                           #   redirects admins to admin_dashboard.php,
│                           #   regular users to user_dashboard.php
│
├── config.php              # Global configuration — DB credentials, API key,
│                           #   BASE_URL, session timeout; PDO factory function
│
├── dashboard.php           # Generic dashboard (AQI threshold update view)
│
├── admin_dashboard.php     # Admin-only view — add/delete users, view all
│                           #   device statuses, manage per-user AQI thresholds
│
├── user_dashboard.php      # Per-user live view — AQI gauge, temperature,
│                           #   humidity, 20-row history table; auto-polls
│                           #   every 10 s; colour-coded alerts for
│                           #   caution/danger thresholds
│
├── update_medics.php       # First-login threshold setup — user sets personal
│                           #   caution and danger AQI levels before accessing
│                           #   the main dashboard
│
│
├── api/                    # REST-like endpoints consumed by firmware & JS
│   ├── data.php            # POST endpoint — receives JSON from ESP8266;
│   │                       #   validates API key, checks username, validates
│   │                       #   sensor ranges, inserts into sensor_data table
│   └── get_data.php        # GET endpoint — returns latest sensor reading for
│                           #   a given username as JSON (used by dashboard JS
│                           #   for auto-refresh polling)
│
├── auth/                   # Authentication helpers
│   ├── login.php           # Handles login form POST (redirects to index.php)
│   ├── logout.php          # Destroys session and redirects to login
│   └── register.php        # New-user registration form and handler
│
├── css/
│   └── style.css           # Dark-theme stylesheet shared across all pages
│                           #   (auth cards, dashboard layout, tables, alerts)
│
├── db/
│   ├── schema.sql          # Database DDL — creates oxysafe_db, users table,
│   │                       #   sensor_data table with FK + index, and the
│   │                       #   MySQL event that auto-purges records > 6 h old
│   └── seed.php            # One-time seeder — creates the default admin user
│                           #   with a bcrypt-hashed password
│
└── includes/
    └── session.php         # Session management helpers — loginUser(),
                            #   requireLogin(), requireAdmin(),
                            #   redirectIfLoggedIn(), getSessionUser()
```

### Database Tables

#### `users`
| Column | Type | Description |
|--------|------|-------------|
| `id` | INT UNSIGNED, PK | Auto-increment user ID |
| `name` | VARCHAR(100) | Full display name |
| `username` | VARCHAR(50), UNIQUE | Login handle / device identifier |
| `password` | VARCHAR(255) | bcrypt hash |
| `is_admin` | TINYINT(1) | `1` = admin, `0` = regular user |
| `caution_threshold` | SMALLINT UNSIGNED | AQI level triggering caution alert |
| `danger_threshold` | SMALLINT UNSIGNED | AQI level triggering danger alert |
| `created_at` | TIMESTAMP | Row creation time |
| `updated_at` | TIMESTAMP | Last modification time |

#### `sensor_data`
| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED, PK | Auto-increment reading ID |
| `username` | VARCHAR(50), FK | Links to `users.username` |
| `temp` | DECIMAL(5,2) | Temperature in °C |
| `humidity` | DECIMAL(5,2) | Relative humidity in % |
| `dust_density` | DECIMAL(8,2) | Particulate matter density in µg/m³ |
| `aqi` | DECIMAL(6,1) | Calculated Air Quality Index value |
| `recorded_at` | TIMESTAMP | UTC timestamp of the reading |

> Records are automatically purged after **6 hours** by the `purge_old_readings` MySQL scheduled event.

---

## 7. Default Login Credentials

> ⚠️ **Change the admin password immediately after first login.**

The database seeder (`website/db/seed.php`) creates one default administrator account:

| Field | Value |
|-------|-------|
| **Username** | `admin` |
| **Password** | `admin@123` |
| **Role** | Admin (`is_admin = 1`) |

To seed the database, run once after applying `schema.sql`:
```bash
php website/db/seed.php
```

Regular user accounts are created by the admin through the Admin Dashboard.

---

*OxySafe Project — last updated March 2026*
