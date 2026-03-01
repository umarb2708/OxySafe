/**
 * OxySafe - Air Quality Monitor Firmware
 * Hardware : ESP8266 NodeMCU + DHT11 + GP2Y1010AU0F Optical Dust Sensor
 * Author   : OxySafe Project
 * Date     : 2026-02-28
 *
 * Wiring:
 *  DHT11        -> D4  (GPIO2)
 *  Dust AOUT    -> A0  (Analog In)
 *  Dust LED CTL -> D5  (GPIO14)  via 150Ω resistor
 *  Dust VCC     -> 3.3V / 5V depending on sensor breakout board
 *
 * Libraries required (install via Arduino Library Manager):
 *  - ESP8266WiFi       (bundled with ESP8266 board package)
 *  - ESP8266HTTPClient (bundled with ESP8266 board package)
 *  - DHT sensor library by Adafruit
 *  - ArduinoJson       by Benoit Blanchon  (v6.x)
 */

// ─────────────────────────────────────────────────────────────
//  Includes
// ─────────────────────────────────────────────────────────────
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>
#include <DHT.h>
#include <ArduinoJson.h>

// ─────────────────────────────────────────────────────────────
//  User Configuration  ← Edit these before flashing
// ─────────────────────────────────────────────────────────────
const char* WIFI_SSID       = "YOUR_WIFI_SSID";
const char* WIFI_PASSWORD   = "YOUR_WIFI_PASSWORD";
const char* SERVER_URL      = "http://your-server.com/website/api/data.php";
const char* API_KEY         = "OXYSAFE_SECRET_KEY";   // must match config.php
const char* USERNAME        = "john_doe";              // must match username in DB

// ─────────────────────────────────────────────────────────────
//  Pin Definitions
// ─────────────────────────────────────────────────────────────
#define DHTPIN          D4    // DHT11 data pin
#define DHTTYPE         DHT11

#define DUST_LED_PIN    D5    // GP2Y1010AU0F LED drive pin
#define DUST_AOUT_PIN   A0    // GP2Y1010AU0F analog output

// ─────────────────────────────────────────────────────────────
//  Timing Constants
// ─────────────────────────────────────────────────────────────
#define SEND_INTERVAL_MS    10000UL   // 10 seconds
#define DUST_SAMPLING_TIME  280       // µs – LED on before ADC sample
#define DUST_DELTA_TIME     40        // µs – ADC hold time
#define DUST_SLEEP_TIME     9680      // µs – LED off period

// ─────────────────────────────────────────────────────────────
//  Globals
// ─────────────────────────────────────────────────────────────
DHT dht(DHTPIN, DHTTYPE);
WiFiClient wifiClient;
unsigned long lastSendTime = 0;

// ─────────────────────────────────────────────────────────────
//  Dust Sensor: Read raw density (µg/m³)
//  Based on GP2Y1010AU0F datasheet timing diagram
// ─────────────────────────────────────────────────────────────
float readDustDensity() {
    // Pulse LED and sample at correct timing
    digitalWrite(DUST_LED_PIN, LOW);          // LED ON
    delayMicroseconds(DUST_SAMPLING_TIME);

    int rawADC = analogRead(DUST_AOUT_PIN);   // 10-bit: 0–1023

    delayMicroseconds(DUST_DELTA_TIME);
    digitalWrite(DUST_LED_PIN, HIGH);         // LED OFF
    delayMicroseconds(DUST_SLEEP_TIME);

    // NodeMCU A0 pin: 0–3.3V mapped to 0–1023
    float voltage = rawADC * (3.3f / 1023.0f);

    // GP2Y1010AU0F: ~0.9V at 0 µg/m³, +0.5V per 100 µg/m³
    float dustDensity = (voltage - 0.9f) * 200.0f;
    if (dustDensity < 0.0f) dustDensity = 0.0f;

    return dustDensity;
}

// ─────────────────────────────────────────────────────────────
//  AQI Calculation  – EPA PM2.5 breakpoints
// ─────────────────────────────────────────────────────────────
float calculateAQI(float pm25) {
    // EPA breakpoint tables
    const float C_lo[] = {  0.0f,  12.1f,  35.5f,  55.5f, 150.5f, 250.5f, 350.5f };
    const float C_hi[] = { 12.0f,  35.4f,  55.4f, 150.4f, 250.4f, 350.4f, 500.4f };
    const float I_lo[] = {  0.0f,  51.0f, 101.0f, 151.0f, 201.0f, 301.0f, 401.0f };
    const float I_hi[] = { 50.0f, 100.0f, 150.0f, 200.0f, 300.0f, 400.0f, 500.0f };

    for (int i = 0; i < 7; i++) {
        if (pm25 >= C_lo[i] && pm25 <= C_hi[i]) {
            return ((I_hi[i] - I_lo[i]) / (C_hi[i] - C_lo[i])) * (pm25 - C_lo[i]) + I_lo[i];
        }
    }
    return 500.0f; // Hazardous beyond scale
}

// ─────────────────────────────────────────────────────────────
//  AQI Category helper
// ─────────────────────────────────────────────────────────────
const char* aqiCategory(float aqi) {
    if (aqi <= 50)  return "Good";
    if (aqi <= 100) return "Moderate";
    if (aqi <= 150) return "Unhealthy for Sensitive Groups";
    if (aqi <= 200) return "Unhealthy";
    if (aqi <= 300) return "Very Unhealthy";
    return "Hazardous";
}

// ─────────────────────────────────────────────────────────────
//  Send data to server via HTTP POST (JSON body)
// ─────────────────────────────────────────────────────────────
void sendDataToServer(float temperature, float humidity,
                      float dustDensity, float aqi) {
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("[WiFi] Not connected – skipping send.");
        return;
    }

    HTTPClient http;
    http.begin(wifiClient, SERVER_URL);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-API-Key", API_KEY);
    http.setTimeout(8000);

    // Build JSON payload
    StaticJsonDocument<256> doc;
    doc["username"]     = USERNAME;
    doc["temp"]         = serialized(String(temperature, 2));
    doc["humidity"]     = serialized(String(humidity, 2));
    doc["dust_density"] = serialized(String(dustDensity, 2));
    doc["aqi"]          = serialized(String(aqi, 1));

    String payload;
    serializeJson(doc, payload);

    Serial.print("[HTTP] POST → ");
    Serial.println(SERVER_URL);
    Serial.printf("[HTTP] User : %s\n", USERNAME);
    Serial.print("[HTTP] Payload: ");
    Serial.println(payload);

    int httpCode = http.POST(payload);

    if (httpCode > 0) {
        Serial.printf("[HTTP] Response code: %d\n", httpCode);
        if (httpCode == HTTP_CODE_OK) {
            Serial.print("[HTTP] Response body: ");
            Serial.println(http.getString());
        }
    } else {
        Serial.printf("[HTTP] Error: %s\n", http.errorToString(httpCode).c_str());
    }

    http.end();
}

// ─────────────────────────────────────────────────────────────
//  WiFi Connection
// ─────────────────────────────────────────────────────────────
void connectWiFi() {
    Serial.printf("\n[WiFi] Connecting to %s", WIFI_SSID);
    WiFi.mode(WIFI_STA);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 30) {
        delay(500);
        Serial.print(".");
        attempts++;
    }

    if (WiFi.status() == WL_CONNECTED) {
        Serial.printf("\n[WiFi] Connected! IP: %s\n",
                      WiFi.localIP().toString().c_str());
    } else {
        Serial.println("\n[WiFi] Connection FAILED. Will retry in main loop.");
    }
}

// ─────────────────────────────────────────────────────────────
//  Setup
// ─────────────────────────────────────────────────────────────
void setup() {
    Serial.begin(115200);
    delay(200);

    Serial.println("\n=============================");
    Serial.println("  OxySafe – Air Quality Node ");
    Serial.println("=============================");

    // Peripheral init
    pinMode(DUST_LED_PIN, OUTPUT);
    digitalWrite(DUST_LED_PIN, HIGH); // LED off by default

    dht.begin();
    Serial.println("[DHT11] Initialised.");

    connectWiFi();

    // Force first reading immediately
    lastSendTime = millis() - SEND_INTERVAL_MS;
}

// ─────────────────────────────────────────────────────────────
//  Loop
// ─────────────────────────────────────────────────────────────
void loop() {
    // Reconnect WiFi if dropped
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("[WiFi] Disconnected. Reconnecting...");
        connectWiFi();
    }

    unsigned long now = millis();
    if (now - lastSendTime < SEND_INTERVAL_MS) return;
    lastSendTime = now;

    // ── Read sensors ──────────────────────────────────────────
    float temperature = dht.readTemperature();    // Celsius
    float humidity    = dht.readHumidity();
    float dustDensity = readDustDensity();         // µg/m³
    float aqi         = calculateAQI(dustDensity);

    // Validate DHT reading
    if (isnan(temperature) || isnan(humidity)) {
        Serial.println("[DHT11] Read failed! Check wiring.");
        return;
    }

    // ── Serial log ────────────────────────────────────────────
    Serial.println("─────────────────────────────");
    Serial.printf("Temperature  : %.1f °C\n",    temperature);
    Serial.printf("Humidity     : %.1f %%\n",    humidity);
    Serial.printf("Dust Density : %.2f µg/m³\n", dustDensity);
    Serial.printf("AQI          : %.0f  (%s)\n", aqi, aqiCategory(aqi));
    Serial.println("─────────────────────────────");

    // ── Send to server ────────────────────────────────────────
    sendDataToServer(temperature, humidity, dustDensity, aqi);
}
