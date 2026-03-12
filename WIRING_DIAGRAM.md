# OxySafe - Detailed Wiring Diagram for GP2Y1010AU0F Dust Sensor

## GP2Y1010AU0F Specifications

- **Power Supply Voltage:** DC 5V ± 2V (typically 5V)
- **Current Consumption:** MAX 20mA (very low power)
- **Sensor Type:** Optical sensing system (infrared LED + phototransistor)
- **Minimum Particle Detection:** 0.8 µm
- **Clean Air Output Voltage:** 0.9V typical
- **Detection Method:** Photometry using single LED pulse

---

## GP2Y1010AU0F Pin Configuration

The Sharp GP2Y1010AU0F has **6 pins**:

| Pin # | Pin Name | Description | Connection |
|-------|----------|-------------|------------|
| 1 | **V-LED** | LED Anode (positive) | Connect to **5V** through **150Ω resistor** |
| 2 | **LED-GND** | LED Cathode (negative) | Connect to **GND** |
| 3 | **LED** | LED Control Input | Connect to **D5 (GPIO14)** - pulse LOW to activate LED |
| 4 | **S-GND** | Signal Ground | Connect to **GND** |
| 5 | **Vo** | Analog Output Voltage | Connect to **A0** (with voltage divider if needed) |
| 6 | **Vcc** | Power Supply | Connect to **5V** with **220µF capacitor** to GND |

---

## Complete Wiring Diagram

```
                                    ESP8266 NodeMCU
                         ┌─────────────────────────────────────┐
                         │                                      │
                         │  VIN (5V) ●                          │
                         │           │                          │
                         │       GND ●─────────┬────────────────┼──── Common GND
                         │           │         │                │
          DHT11 DATA ────┤       D4  ●         │                │
          GP2Y LED CTL ──┤       D5  ●         │                │
          GP2Y Vo ───────┤       A0  ●         │                │
              DHT11 VCC ─┤      3V3  ●         │                │
                         │           │         │                │
                         └───────────┼─────────┼────────────────┘
                                     │         │
                                     5V       GND


════════════════════════════════════════════════════════════════════════


        DHT11 Sensor                          GP2Y1010AU0F Dust Sensor
        ┌────────────┐                        ┌──────────────────────┐
        │            │                        │                      │
        │  VCC  ●────┼── 3.3V                 │  Pin 1 (V-LED)   ●───┼──[150Ω]── 5V
        │            │                        │                      │
        │  DATA ●────┼── D4 (GPIO2)           │  Pin 2 (LED-GND) ●───┼── GND
        │            │                        │                      │
        │  GND  ●────┼── GND                  │  Pin 3 (LED CTL) ●───┼── D5 (GPIO14)
        │            │                        │                      │
        └────────────┘                        │  Pin 4 (S-GND)   ●───┼── GND
                                              │                      │
                                              │  Pin 5 (Vo)      ●───┼──┬── A0 *
                                              │                      │  │
                                              │  Pin 6 (Vcc)     ●───┼──┼── 5V
                                              │                      │  │
                                              └──────────────────────┘  │
                                                        │                │
                                                        ├───[220µF]─── GND
                                                        │    (+)
                                                        │
                                                    (Capacitor: 
                                                     + terminal 
                                                     to Vcc)
                                                     
                                              Optional Voltage Divider
                                              for safe ADC reading:
                                                        │
                                                     [47kΩ]
                                                        │
                                                    A0 ─┤
                                                        │
                                                    [100kΩ]
                                                        │
                                                       GND

```

---

## Detailed Component Connections

### Power Supply
- **5V Source:** Connect to NodeMCU **VIN** pin (or use USB 5V directly)
  - The GP2Y1010AU0F **requires 5V** (not 3.3V)
  - NodeMCU's onboard regulator provides 3.3V for the DHT11 separately

### DHT11 Temperature & Humidity Sensor
1. **VCC** → NodeMCU **3V3** pin
2. **DATA** → NodeMCU **D4** (GPIO2)
   - Optional: 10kΩ pull-up resistor between DATA and VCC (usually on breakout board)
3. **GND** → Common ground

### GP2Y1010AU0F Dust Sensor

#### Pin 1 (V-LED) - LED Power
- Connect to **5V** through a **150Ω resistor** (1/4W or higher)
- This limits current to the infrared LED (~33mA peak during pulse)

#### Pin 2 (LED-GND) - LED Ground
- Connect directly to **GND**

#### Pin 3 (LED) - LED Control
- Connect to NodeMCU **D5** (GPIO14)
- The firmware pulses this pin **LOW** for 280µs to activate the LED
- When LOW, the internal LED turns on for dust measurement

#### Pin 4 (S-GND) - Signal Ground
- Connect directly to **GND**
- Important for clean analog readings

#### Pin 5 (Vo) - Analog Output
- Connect to NodeMCU **A0** (analog input)
- **⚠️ IMPORTANT:** ESP8266 A0 pin accepts **0-1V maximum**
- GP2Y1010AU0F outputs up to **~4V** at high dust levels
- **Solution:** Use a voltage divider:
  - 47kΩ resistor in series from Vo
  - 100kΩ resistor from A0 to GND
  - This scales ~4V down to ~0.95V safely

#### Pin 6 (Vcc) - Sensor Power
- Connect to **5V** power rail
- **CRITICAL:** Place a **220µF electrolytic capacitor** between Vcc and GND
  - Positive terminal (+) connects to Vcc (Pin 6)
  - Negative terminal (-) connects to GND
  - This capacitor suppresses noise from LED pulses and stabilizes voltage

---

## Why 5V is Required

According to the Sharp GP2Y1010AU0F datasheet:
- **Operating Voltage:** DC 5V ± 2V (3V to 7V range)
- **Optimal Performance:** 5V
- The sensor's infrared LED requires sufficient voltage to generate the light intensity needed for accurate dust particle detection
- At 3.3V, the LED output would be weak, resulting in poor sensitivity and inaccurate readings

---

## Voltage Divider Calculation (for A0 protection)

ESP8266 A0 can only handle **0-1V**. GP2Y1010AU0F outputs **0V (clean) to ~4V (dusty)**.

**Voltage Divider Formula:**
```
Vout = Vin × (R2 / (R1 + R2))

Using R1 = 47kΩ and R2 = 100kΩ:
Vout = 4V × (100k / (47k + 100k)) = 4V × 0.68 = 2.72V ❌ Still too high!

Better option: R1 = 100kΩ, R2 = 33kΩ:
Vout = 4V × (33k / (100k + 33k)) = 4V × 0.248 = 0.99V ✓ Safe!
```

**Recommended Voltage Divider:**
- **R1 (series):** 100kΩ
- **R2 (to GND):** 33kΩ

---

## Assembly Checklist

- [ ] Connect **5V** power source to NodeMCU VIN and GP2Y Pin 6
- [ ] Connect **150Ω resistor** between 5V and GP2Y Pin 1 (V-LED)
- [ ] Connect **220µF capacitor** between GP2Y Pin 6 (Vcc) and GND (observe polarity!)
- [ ] Connect **voltage divider** (100kΩ + 33kΩ) between GP2Y Pin 5 (Vo) and A0
- [ ] Connect **D5** to GP2Y Pin 3 (LED control)
- [ ] Connect **D4** to DHT11 DATA pin
- [ ] Connect all **GND pins** to common ground rail
- [ ] Double-check voltage levels before powering on

---

## How the Dust Sensor Works

1. **Standby State:** LED is OFF, Vo outputs ~0.9V (clean air baseline)

2. **Measurement Pulse:** (every 10ms in firmware)
   - Firmware pulls **Pin 3 LOW** → LED turns ON
   - Wait 280µs for light to stabilize
   - Read **A0** to sample Vo voltage
   - Pull **Pin 3 HIGH** → LED turns OFF

3. **Dust Detection:**
   - Clean air: Vo ≈ 0.9V
   - Dusty air: Vo increases (up to ~4V for heavy dust)
   - More dust particles = more light scattering = higher Vo

4. **Conversion to µg/m³:**
   - Firmware applies Sharp's calibration curve:
   ```
   Dust Density (µg/m³) = 0.17 × Vo - 0.1
   ```

---

## Safety Notes

⚠️ **CRITICAL WARNINGS:**

1. **Never connect GP2Y1010AU0F directly to 3.3V** - it will underperform and give inaccurate readings

2. **Always use the 220µF capacitor on Vcc** - without it, LED pulses cause voltage spikes that corrupt sensor readings

3. **Protect A0 with voltage divider** - connecting Vo directly to A0 can damage the ESP8266 ADC

4. **Use correct resistor for LED** - 150Ω is specified by Sharp; too low = LED damage, too high = weak signal

5. **Check capacitor polarity** - reversed electrolytic capacitors can explode

---

## Testing

After wiring, upload the firmware and check Serial Monitor output:
```
[GP2Y1010] Raw ADC: 150  →  Dust: 45.2 µg/m³
```

**Expected clean air readings:**
- Raw ADC: ~150-200 (depends on voltage divider)
- Dust density: 0-50 µg/m³

**Test dust detection:**
- Wave paper/cloth near sensor → dust reading should increase
- Blow smoke/dust → reading should spike to 200+ µg/m³

---

## Troubleshooting

| Problem | Possible Cause | Solution |
|---------|---------------|----------|
| Dust always reads 0 | No 5V power to sensor | Check Pin 6 has 5V |
| Very high dust readings | No voltage divider on A0 | Add 100kΩ/33kΩ divider |
| Erratic/noisy readings | No 220µF capacitor | Add capacitor on Vcc-GND |
| Sensor doesn't respond | LED not pulsing | Check D5 connection to Pin 3 |
| ADC always maxed out | Vo directly to A0 (overvoltage) | Use voltage divider! |

---

## References

- Sharp GP2Y1010AU0F Datasheet: [link](https://www.sharpsde.com/products/optoelectronic-components/model/GP2Y1010AU0F/)
- ESP8266 ADC specifications: 0-1V input range, 10-bit resolution
- OxySafe firmware: `firmware/oxysafe.ino`

---

**Last Updated:** March 12, 2026  
**Document Version:** 1.0
