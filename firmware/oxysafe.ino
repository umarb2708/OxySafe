/**
 * OxySafe - Air Quality Monitor Firmware
 * Hardware : ESP8266 NodeMCU + DHT22 + GP2Y1010AU0F Optical Dust Sensor
 * Author   : OxySafe Project
 * Date     : 2026-03-01
 *
 * Wiring:
 *  DHT22        -> D4  (GPIO2)
 *  Dust AOUT    -> A0  (Analog In) - try WITHOUT voltage divider first!
 *  Dust LED CTL -> D6  (GPIO12) - pulses LOW to activate LED
 *  Dust V-LED   -> 5V via 150Ω resistor
 *  Dust VCC     -> 5V (REQUIRED - sensor needs 5V, not 3.3V)
 *  Dust GND     -> GND (Pin 2 and Pin 4)
 *  220µF cap    -> Between Dust VCC (Pin 6) and GND (+ to VCC)
 *
 * TEST MODE:
 *  FLASH button (built-in on NodeMCU) - Press to cycle through test AQI values:
 *    1st press: AQI 45  (Good - Safe)
 *    2nd press: AQI 120 (Moderate - Caution)
 *    3rd press: AQI 250 (Very Unhealthy - Dangerous!)
 *    4th press: Return to normal sensor readings
 *
 * IMPORTANT: GP2Y1010AU0F outputs 0.9V-4V which is SAFE for ESP8266's A0 (0-3.3V max).
 * Try connecting Pin 5 (Vo) DIRECTLY to A0 first. Only add voltage divider if needed.
 *
 * Optional Voltage Divider for A0 (only if direct connection doesn't work):
 *  - 150kΩ (series) + 47kΩ (to GND) [May reduce signal too much!]
 *  - 100kΩ (series) + 27kΩ (to GND)
 *  - 82kΩ (series) + 22kΩ (to GND)
 *
 * Libraries required (install via Arduino Library Manager):
 *  - ESP8266WiFi       (bundled with ESP8266 board package)
 *  - ESP8266WebServer  (bundled with ESP8266 board package)
 *  - ESP8266HTTPClient (bundled with ESP8266 board package)
 *  - ArduinoOTA        (bundled with ESP8266 board package)
 *  - EEPROM            (bundled with ESP8266 board package)
 *  - DHT sensor library by Adafruit
 *  - ArduinoJson       by Benoit Blanchon  (v6.x)
 *
 * Boot behaviour:
 *  1. Load WiFi credentials + server IP + username from EEPROM flash.
 *  2. Try to connect to WiFi for up to 15 s.
 *  3. If connection fails → enter Config Mode:
 *       AP SSID : OxySafe
 *       AP Pass : Oxy@123#
 *       Web UI  : http://192.168.4.1
 *     User fills in new credentials and submits the form.
 *     ESP saves to EEPROM, reboots, and retries connection.
 *  4. Once connected → normal sensor loop (POST every 10 s).
 */

// ─────────────────────────────────────────────────────────────
//  Includes
// ─────────────────────────────────────────────────────────────
#include <ESP8266WiFi.h>
#include <ESP8266WebServer.h>
#include <ESP8266HTTPClient.h>
#include <ESP8266mDNS.h>
#include <ArduinoOTA.h>
#include <WiFiClient.h>
#include <EEPROM.h>
#include <DHT.h>
// ESP8266 core 2.5.0: pgm_read_ptr returns void* which is incompatible with ArduinoJson v7
#define ARDUINOJSON_ENABLE_PROGMEM 0
#include <ArduinoJson.h>

// ─────────────────────────────────────────────────────────────
//  Fixed / compile-time constants
// ─────────────────────────────────────────────────────────────
#define API_KEY             "OXYSAFE_SECRET_KEY"   // shared secret, must match config.php
#define API_PATH            "/oxysafe/api/data.php"

#define AP_SSID             "OxySafe"
#define AP_PASSWORD         "Oxy@123#"

#define OTA_HOSTNAME        "oxysafe"         // appears in Arduino IDE port list
#define OTA_PASSWORD        "Oxysafe@OTA123#"  // change before deploying

#define WIFI_TIMEOUT_MS     15000UL   // 15 s before falling into config mode
#define SEND_INTERVAL_MS    10000UL   // sensor POST interval

// ─────────────────────────────────────────────────────────────
//  Pin Definitions
// ─────────────────────────────────────────────────────────────
#define DHTPIN          D4
#define DHTTYPE         DHT22
#define DUST_LED_PIN    D6    // Changed from D5 to D6 (GPIO12)
#define DUST_AOUT_PIN   A0
#define FLASH_BUTTON    D3    // GPIO0 - Built-in FLASH button for testing

// ─────────────────────────────────────────────────────────────
//  EEPROM Layout  (total 256 bytes)
//
//  Addr  Len   Field
//  0     1     Magic byte (0xAB = valid data written)
//  1     33    wifi_ssid      (max 32 chars + '\0')
//  34    64    wifi_password  (max 63 chars + '\0')
//  98    33    server_ip      (max 32 chars + '\0')  e.g. "192.168.1.200"
//  131   51    username       (max 50 chars + '\0')
// ─────────────────────────────────────────────────────────────
#define EEPROM_SIZE         256
#define EEPROM_MAGIC_ADDR   0
#define EEPROM_SSID_ADDR    1
#define EEPROM_PASS_ADDR    34
#define EEPROM_SIP_ADDR     98
#define EEPROM_USER_ADDR    131
#define EEPROM_MAGIC_VAL    0xAB

// ─────────────────────────────────────────────────────────────
//  Runtime config (loaded from EEPROM)
// ─────────────────────────────────────────────────────────────
char cfg_ssid[33]     = "";
char cfg_password[64] = "";
char cfg_server_ip[33]= "";
char cfg_username[51] = "";

// ─────────────────────────────────────────────────────────────
//  Globals
// ─────────────────────────────────────────────────────────────
DHT              dht(DHTPIN, DHTTYPE);
WiFiClient       wifiClient;
ESP8266WebServer configServer(80);
unsigned long    lastSendTime = 0;
bool             inConfigMode = false;

// Test mode for FLASH button
int              testAqiLevel = 0;    // 0=off, 1=Good, 2=Caution, 3=Dangerous
unsigned long    lastButtonPress = 0;

// ═════════════════════════════════════════════════════════════
//  EEPROM helpers
// ═════════════════════════════════════════════════════════════

/** Write a null-terminated string to EEPROM at addr, max maxLen chars. */
void eepromWriteStr(int addr, const char* str, int maxLen) {
    int i = 0;
    while (i < maxLen - 1 && str[i] != '\0') {
        EEPROM.write(addr + i, str[i]);
        i++;
    }
    EEPROM.write(addr + i, '\0');
}

/** Read a null-terminated string from EEPROM into buf (maxLen incl. '\0'). */
void eepromReadStr(int addr, char* buf, int maxLen) {
    for (int i = 0; i < maxLen - 1; i++) {
        buf[i] = EEPROM.read(addr + i);
        if (buf[i] == '\0') return;
    }
    buf[maxLen - 1] = '\0';
}

/** Save current cfg_* variables to EEPROM. */
void saveConfig() {
    EEPROM.write(EEPROM_MAGIC_ADDR, EEPROM_MAGIC_VAL);
    eepromWriteStr(EEPROM_SSID_ADDR,  cfg_ssid,      33);
    eepromWriteStr(EEPROM_PASS_ADDR,  cfg_password,  64);
    eepromWriteStr(EEPROM_SIP_ADDR,   cfg_server_ip, 33);
    eepromWriteStr(EEPROM_USER_ADDR,  cfg_username,  51);
    EEPROM.commit();
    Serial.println("[EEPROM] Config saved.");
}

/** Load cfg_* variables from EEPROM. Returns true if magic byte is valid. */
bool loadConfig() {
    uint8_t magic = EEPROM.read(EEPROM_MAGIC_ADDR);
    if (magic != EEPROM_MAGIC_VAL) {
        Serial.println("[EEPROM] No valid config found.");
        return false;
    }
    eepromReadStr(EEPROM_SSID_ADDR,  cfg_ssid,      33);
    eepromReadStr(EEPROM_PASS_ADDR,  cfg_password,  64);
    eepromReadStr(EEPROM_SIP_ADDR,   cfg_server_ip, 33);
    eepromReadStr(EEPROM_USER_ADDR,  cfg_username,  51);

    Serial.printf("[EEPROM] Loaded — SSID: %s  Server: %s  User: %s\n",
                  cfg_ssid, cfg_server_ip, cfg_username);
    return true;
}

// ═════════════════════════════════════════════════════════════
//  Config Mode Web Server
// ═════════════════════════════════════════════════════════════

/** HTML config form served at http://192.168.4.1 */
const char CONFIG_PAGE[] PROGMEM = R"rawhtml(
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OxySafe Setup</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#0f1117;color:#e8eaf6;font-family:sans-serif;
       display:flex;align-items:center;justify-content:center;min-height:100vh}
  .card{background:#1a1d27;border:1px solid #2e3148;border-radius:14px;
        padding:36px 32px;width:100%;max-width:400px;box-shadow:0 4px 32px rgba(0,0,0,.5)}
  h1{text-align:center;font-size:1.5rem;margin-bottom:4px}
  p.sub{text-align:center;color:#7b83a8;font-size:.85rem;margin-bottom:24px}
  label{display:block;font-size:.85rem;color:#7b83a8;margin-bottom:5px;font-weight:600}
  input{width:100%;background:#232636;border:1px solid #2e3148;color:#e8eaf6;
        border-radius:8px;padding:10px 12px;font-size:.95rem;outline:none;
        margin-bottom:16px;transition:border-color .2s}
  input:focus{border-color:#5c6bc0}
  button{width:100%;background:#5c6bc0;color:#fff;border:none;border-radius:8px;
         padding:12px;font-size:1rem;font-weight:700;cursor:pointer;
         transition:opacity .15s}
  button:hover{opacity:.88}
  .icon{text-align:center;font-size:2.2rem;margin-bottom:8px}
</style>
</head>
<body>
<div class="card">
  <div class="icon">🌬️</div>
  <h1>OxySafe Setup</h1>
  <p class="sub">Configure device Wi-Fi &amp; server settings</p>
  <form method="POST" action="/save">
    <label>Wi-Fi SSID</label>
    <input type="text"     name="ssid"      placeholder="Your network name"   required maxlength="32">
    <label>Wi-Fi Password</label>
    <input type="password" name="password"  placeholder="Network password"            maxlength="63">
    <label>Server IP</label>
    <input type="text"     name="server_ip" placeholder="e.g. 192.168.1.200"  required maxlength="32">
    <label>Username</label>
    <input type="text"     name="username"  placeholder="Your OxySafe username" required maxlength="50">
    <button type="submit">Save &amp; Connect</button>
  </form>
</div>
</body>
</html>
)rawhtml";

/** Served after saving — tells user to wait */
const char SAVED_PAGE[] PROGMEM = R"rawhtml(
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OxySafe – Saved</title>
<style>
  body{background:#0f1117;color:#e8eaf6;font-family:sans-serif;
       display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center}
  .card{background:#1a1d27;border:1px solid #2e3148;border-radius:14px;padding:40px 32px;max-width:360px}
  h2{margin:12px 0 8px}
  p{color:#7b83a8;font-size:.9rem}
</style>
</head>
<body>
<div class="card">
  <div style="font-size:2.4rem">✅</div>
  <h2>Settings Saved!</h2>
  <p>The device will now restart and connect to your network.<br><br>
  You can close this page.</p>
</div>
</body>
</html>
)rawhtml";

void handleConfigRoot() {
    configServer.send(200, "text/html", FPSTR(CONFIG_PAGE));
}

void handleConfigSave() {
    if (configServer.hasArg("ssid"))      configServer.arg("ssid").toCharArray(cfg_ssid,      33);
    if (configServer.hasArg("password"))  configServer.arg("password").toCharArray(cfg_password, 64);
    if (configServer.hasArg("server_ip")) configServer.arg("server_ip").toCharArray(cfg_server_ip, 33);
    if (configServer.hasArg("username"))  configServer.arg("username").toCharArray(cfg_username,  51);

    saveConfig();
    configServer.send(200, "text/html", FPSTR(SAVED_PAGE));

    Serial.println("[Config] New credentials saved. Rebooting in 2 s...");
    delay(2000);
    ESP.restart();
}

/** Start AP + web server for configuration. Blocks until reboot. */
void enterConfigMode() {
    inConfigMode = true;
    Serial.println("\n[Config] Entering Config Mode...");

    WiFi.disconnect(true);
    delay(100);
    WiFi.mode(WIFI_AP);
    WiFi.softAP(AP_SSID, AP_PASSWORD);

    Serial.printf("[Config] AP started — SSID: %s  Pass: %s\n", AP_SSID, AP_PASSWORD);
    Serial.printf("[Config] Open browser at: http://%s\n",
                  WiFi.softAPIP().toString().c_str());

    configServer.on("/",      HTTP_GET,  handleConfigRoot);
    configServer.on("/save",  HTTP_POST, handleConfigSave);
    configServer.onNotFound([]() {
        configServer.sendHeader("Location", "/");
        configServer.send(302);
    });
    configServer.begin();
    Serial.println("[Config] Web server started. Waiting for user...");

    // Block here — handleClient() only; sensor loop does NOT run in config mode
    while (true) {
        configServer.handleClient();
        yield();
    }
}

// ═════════════════════════════════════════════════════════════
//  WiFi Connection  (STA mode, 15 s timeout)
// ═════════════════════════════════════════════════════════════
bool connectWiFi() {
    Serial.printf("\n[WiFi] Connecting to \"%s\"", cfg_ssid);
    WiFi.mode(WIFI_STA);
    WiFi.begin(cfg_ssid, cfg_password);

    unsigned long start = millis();
    while (WiFi.status() != WL_CONNECTED) {
        if (millis() - start >= WIFI_TIMEOUT_MS) {
            Serial.println("\n[WiFi] Timeout — could not connect.");
            return false;
        }
        delay(250);
        Serial.print(".");
    }

    Serial.printf("\n[WiFi] Connected! IP: %s\n",
                  WiFi.localIP().toString().c_str());
    return true;
}

// ═════════════════════════════════════════════════════════════
//  OTA  (ArduinoOTA)
// ═════════════════════════════════════════════════════════════
void setupOTA() {
    ArduinoOTA.setHostname(OTA_HOSTNAME);
    ArduinoOTA.setPassword(OTA_PASSWORD);

    ArduinoOTA.onStart([]() {
        String type = (ArduinoOTA.getCommand() == U_FLASH) ? "sketch" : "filesystem";
        Serial.println("[OTA] Starting update: " + type);
    });
    ArduinoOTA.onEnd([]() {
        Serial.println("\n[OTA] Update complete. Rebooting...");
    });
    ArduinoOTA.onProgress([](unsigned int progress, unsigned int total) {
        Serial.printf("[OTA] Progress: %u%%\r", progress * 100 / total);
    });
    ArduinoOTA.onError([](ota_error_t error) {
        Serial.printf("[OTA] Error[%u]: ", error);
        if      (error == OTA_AUTH_ERROR)    Serial.println("Auth Failed");
        else if (error == OTA_BEGIN_ERROR)   Serial.println("Begin Failed");
        else if (error == OTA_CONNECT_ERROR) Serial.println("Connect Failed");
        else if (error == OTA_RECEIVE_ERROR) Serial.println("Receive Failed");
        else if (error == OTA_END_ERROR)     Serial.println("End Failed");
    });

    ArduinoOTA.begin();
    Serial.printf("[OTA] Ready — hostname: %s\n", OTA_HOSTNAME);
}

// ═════════════════════════════════════════════════════════════
//  Dust Sensor  (GP2Y1010AU0F)
// ═════════════════════════════════════════════════════════════
#define DUST_SAMPLING_TIME  280   // µs
#define DUST_DELTA_TIME      40   // µs
#define DUST_SLEEP_TIME    9680   // µs

float readDustDensity() {
    // Read baseline (LED off)
    digitalWrite(DUST_LED_PIN, HIGH);
    delay(50);
    int baselineADC = analogRead(DUST_AOUT_PIN);
    float baselineV = baselineADC * (3.3f / 1023.0f);
    
    // Pulse LED and read
    digitalWrite(DUST_LED_PIN, LOW);
    delayMicroseconds(DUST_SAMPLING_TIME);
    int rawADC = analogRead(DUST_AOUT_PIN);
    delayMicroseconds(DUST_DELTA_TIME);
    digitalWrite(DUST_LED_PIN, HIGH);
    delayMicroseconds(DUST_SLEEP_TIME);

    float voltage     = rawADC * (3.3f / 1023.0f);
    float dustDensity = (voltage - 0.9f) * 200.0f;
    
    // Enhanced diagnostics
    Serial.println("════════════════════════════════════════");
    Serial.println("[DUST SENSOR DIAGNOSTIC]");
    Serial.printf("  Baseline (LED OFF): %4d ADC = %.3f V\n", baselineADC, baselineV);
    Serial.printf("  Active (LED ON):    %4d ADC = %.3f V\n", rawADC, voltage);
    Serial.printf("  Calculated Dust:    %.2f µg/m³\n", dustDensity);
    
    if (baselineADC == 0 && rawADC == 0) {
        Serial.println("\n  ⚠️  PROBLEM: Both readings are 0 ADC!");
        Serial.println("  → Pin 5 (Vo) not connected to A0");
        Serial.println("  → Or voltage divider reducing signal too much");
        Serial.println("  → Try connecting Vo DIRECTLY to A0 (remove divider)");
    } else if (rawADC < 30) {
        Serial.println("\n  ⚠️  PROBLEM: Signal too weak!");
        Serial.println("  → Voltage divider may be too strong");
        Serial.println("  → Remove voltage divider, connect Vo direct to A0");
        Serial.println("  → GP2Y1010AU0F outputs 0.9-4V (safe for ESP8266)");
    } else if (baselineADC == rawADC) {
        Serial.println("\n  ⚠️  PROBLEM: No change when LED pulses!");
        Serial.println("  → LED may not be turning on");
        Serial.println("  → Check Pin 1 (V-LED) connected to 5V via resistor");
        Serial.println("  → Check Pin 3 (LED CTL) connected to D6");
    }
    Serial.println("════════════════════════════════════════");
    
    return max(dustDensity, 0.0f);
}

// ═════════════════════════════════════════════════════════════
//  AQI  (EPA PM2.5 breakpoints)
// ═════════════════════════════════════════════════════════════
float calculateAQI(float pm25) {
    const float C_lo[] = {  0.0f,  12.1f,  35.5f,  55.5f, 150.5f, 250.5f, 350.5f };
    const float C_hi[] = { 12.0f,  35.4f,  55.4f, 150.4f, 250.4f, 350.4f, 500.4f };
    const float I_lo[] = {  0.0f,  51.0f, 101.0f, 151.0f, 201.0f, 301.0f, 401.0f };
    const float I_hi[] = { 50.0f, 100.0f, 150.0f, 200.0f, 300.0f, 400.0f, 500.0f };

    for (int i = 0; i < 7; i++) {
        if (pm25 >= C_lo[i] && pm25 <= C_hi[i])
            return ((I_hi[i] - I_lo[i]) / (C_hi[i] - C_lo[i])) * (pm25 - C_lo[i]) + I_lo[i];
    }
    return 500.0f;
}

const char* aqiCategory(float aqi) {
    if (aqi <= 50)  return "Good";
    if (aqi <= 100) return "Moderate";
    if (aqi <= 150) return "Unhealthy for Sensitive Groups";
    if (aqi <= 200) return "Unhealthy";
    if (aqi <= 300) return "Very Unhealthy";
    return "Hazardous";
}

// ═════════════════════════════════════════════════════════════
//  HTTP POST to server
// ═════════════════════════════════════════════════════════════
void sendDataToServer(float temperature, float humidity,
                      float dustDensity, float aqi) {
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("[WiFi] Not connected — skipping send.");
        return;
    }

    // Build URL from stored server IP
    String serverUrl = String("http://") + cfg_server_ip + API_PATH;

    HTTPClient http;
    http.begin(wifiClient, serverUrl);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-API-Key", API_KEY);
    http.setTimeout(8000);

    JsonDocument doc;
    doc["username"]     = cfg_username;
    doc["temp"]         = serialized(String(temperature, 2));
    doc["humidity"]     = serialized(String(humidity, 2));
    doc["dust_density"] = serialized(String(dustDensity, 2));
    doc["aqi"]          = serialized(String(aqi, 1));

    String payload;
    serializeJson(doc, payload);

    Serial.printf("[HTTP] POST → %s\n", serverUrl.c_str());
    Serial.printf("[HTTP] User : %s  Payload: %s\n", cfg_username, payload.c_str());

    int httpCode = http.POST(payload);
    if (httpCode > 0) {
        Serial.printf("[HTTP] Response: %d", httpCode);
        if (httpCode == HTTP_CODE_OK)
            Serial.printf("  %s", http.getString().c_str());
        Serial.println();
    } else {
        Serial.printf("[HTTP] Error: %s\n", http.errorToString(httpCode).c_str());
    }
    http.end();
}

// ═════════════════════════════════════════════════════════════
//  Setup
// ═════════════════════════════════════════════════════════════
void setup() {
    Serial.begin(115200);
    delay(200);

    Serial.println("\n================================");
    Serial.println("   OxySafe – Air Quality Node  ");
    Serial.println("================================");

    // Peripheral init
    pinMode(DUST_LED_PIN, OUTPUT);
    digitalWrite(DUST_LED_PIN, HIGH);   // LED off
    pinMode(FLASH_BUTTON, INPUT_PULLUP); // FLASH button for testing
    dht.begin();
    
    Serial.println("\n[TEST MODE] FLASH button initialized");
    Serial.println("  Press FLASH button to cycle through AQI test values:");
    Serial.println("  - 1st press: Send AQI 45 (Good - Safe)");
    Serial.println("  - 2nd press: Send AQI 120 (Moderate - Caution)");
    Serial.println("  - 3rd press: Send AQI 250 (Very Unhealthy - Dangerous)");
    Serial.println("  - 4th press: Return to normal sensor readings");
    Serial.println("");
    
    // Dust sensor diagnostic test
    Serial.println("\n[DUST SENSOR TEST]");
    Serial.println("Testing LED control pin (D6 / GPIO12)...");
    Serial.println("D6 will blink 5 times - measure voltage during test:");
    Serial.println("");
    
    for (int i = 0; i < 5; i++) {
        // LED ON
        digitalWrite(DUST_LED_PIN, LOW);
        Serial.printf("  Test %d: D6 = LOW  (measure now: should be ~0V)\n", i+1);
        delay(1000);
        
        // LED OFF
        digitalWrite(DUST_LED_PIN, HIGH);
        Serial.printf("  Test %d: D6 = HIGH (measure now: should be ~3.3V)\n", i+1);
        delay(1000);
    }
    
    Serial.println("\n[RESULT] Did D6 voltage change between 0V and 3.3V?");
    Serial.println("  → YES: D6 pin is working, check dust sensor wiring");
    Serial.println("  → NO:  D6 pin may be damaged, try different GPIO pin");
    
    Serial.println("\n[CHECKLIST] Verify these connections:");
    Serial.println("  [ ] Pin 6 (Vcc)     → 5V with 220µF capacitor to GND");
    Serial.println("  [ ] Pin 1 (V-LED)   → 5V through 120Ω resistor");
    Serial.println("  [ ] Pin 2 (LED-GND) → GND");
    Serial.println("  [ ] Pin 3 (LED CTL) → D6 (GPIO12) **CHANGED FROM D5**");
    Serial.println("  [ ] Pin 4 (S-GND)   → GND");
    Serial.println("  [ ] Pin 5 (Vo)      → A0 (with voltage divider if needed)");
    Serial.println("");

    // Load config from flash
    EEPROM.begin(EEPROM_SIZE);
    bool hasConfig = loadConfig();

    // Attempt WiFi connection if config exists
    bool connected = false;
    if (hasConfig && strlen(cfg_ssid) > 0) {
        connected = connectWiFi();
    } else {
        Serial.println("[Setup] No config in flash — going straight to Config Mode.");
    }

    // If couldn't connect, enter AP config mode (does not return)
    if (!connected) {
        enterConfigMode();
    }

    // Start OTA listener
    setupOTA();

    // Normal operation starts here
    lastSendTime = millis() - SEND_INTERVAL_MS;   // trigger first read immediately
    Serial.println("[Setup] Ready. Starting sensor loop.");
}

// ═════════════════════════════════════════════════════════════
//  Loop
// ═════════════════════════════════════════════════════════════
void loop() {
    ArduinoOTA.handle();   // handle incoming OTA upload requests

    // Check FLASH button for test mode
    if (digitalRead(FLASH_BUTTON) == LOW) {  // Button pressed (active LOW)
        unsigned long now = millis();
        if (now - lastButtonPress > 1000) {  // Debounce: 1 second between presses
            lastButtonPress = now;
            testAqiLevel = (testAqiLevel + 1) % 4;  // Cycle 0->1->2->3->0
            
            Serial.println("\n╔════════════════════════════════════════╗");
            Serial.println("║       FLASH BUTTON PRESSED!            ║");
            Serial.println("╚════════════════════════════════════════╝");
            
            if (testAqiLevel == 0) {
                Serial.println("  → Test Mode OFF - Using real sensor data");
            } else if (testAqiLevel == 1) {
                Serial.println("  → Test Mode: AQI 45 (Good - SAFE)");
            } else if (testAqiLevel == 2) {
                Serial.println("  → Test Mode: AQI 120 (Moderate - CAUTION)");
            } else if (testAqiLevel == 3) {
                Serial.println("  → Test Mode: AQI 250 (Very Unhealthy - DANGEROUS!)");
            }
            Serial.println("");
            
            delay(200);  // Additional debounce delay
        }
    }

    // Reconnect WiFi if it drops
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("[WiFi] Disconnected. Reconnecting...");
        if (!connectWiFi()) {
            // WiFi gone for good — fall into config mode
            enterConfigMode();
        }
    }

    unsigned long now = millis();
    if (now - lastSendTime < SEND_INTERVAL_MS) return;
    lastSendTime = now;

    // Read sensors (or use test values)
    float temperature, humidity, dustDensity, aqi;
    
    if (testAqiLevel == 0) {
        // Normal mode - read actual sensors
        temperature = dht.readTemperature();
        humidity    = dht.readHumidity();
        dustDensity = readDustDensity();
        aqi         = calculateAQI(dustDensity);
    } else {
        // Test mode - use fake values
        temperature = 25.0;  // Normal room temp
        humidity    = 50.0;  // Normal humidity
        
        if (testAqiLevel == 1) {
            aqi = 45.0;           // Good (Safe)
            dustDensity = 8.0;
        } else if (testAqiLevel == 2) {
            aqi = 120.0;          // Moderate (Caution threshold)
            dustDensity = 40.0;
        } else {  // testAqiLevel == 3
            aqi = 250.0;          // Very Unhealthy (Dangerous!)
            dustDensity = 180.0;
        }
        
        Serial.println("════════════════════════════════════════");
        Serial.println("[TEST MODE ACTIVE]");
        Serial.printf("  Sending fake AQI: %.0f (%s)\n", aqi, aqiCategory(aqi));
        Serial.println("════════════════════════════════════════");
    }

    // Enhanced diagnostics
    Serial.println("─────────────────────────────────");
    Serial.println("[DIAGNOSTIC MODE]");
    
    if (isnan(temperature) || isnan(humidity)) {
        Serial.println("[DHT22] Read failed! Check wiring.");
        Serial.println("Possible causes:");
        Serial.println("  - Wrong sensor type (DHT11 instead of DHT22?)");
        Serial.println("  - Faulty sensor or poor connection");
        Serial.println("  - Insufficient power supply");
        return;
    }
    
    // Check for suspiciously low readings
    if (temperature < 5.0 || humidity < 20.0) {
        Serial.println("[WARNING] Abnormal readings detected!");
        Serial.println("  → Temperature too low or humidity too low");
        Serial.println("  → If using DHT11, change DHTTYPE to DHT11");
        Serial.println("  → Check 3.3V/5V power supply");
        Serial.println("  → Try a different DHT sensor");
    }

    Serial.printf("Temperature  : %.1f °C\n",    temperature);
    Serial.printf("Humidity     : %.1f %%\n",    humidity);
    Serial.printf("Dust Density : %.2f µg/m³\n", dustDensity);
    Serial.printf("AQI          : %.0f  (%s)\n", aqi, aqiCategory(aqi));
    
    if (testAqiLevel > 0) {
        Serial.println("⚠️  TEST MODE ACTIVE - Data is FAKE!");
    }
    
    Serial.println("─────────────────────────────────");

    sendDataToServer(temperature, humidity, dustDensity, aqi);
}
