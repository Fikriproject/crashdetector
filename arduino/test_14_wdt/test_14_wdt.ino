// ============================================================
//  TEST 14 - WATCHDOG TIMER (WDT) SELF-RECOVERY
//  Mikrokontroler merestart diri apabila program membeku.
//
//  WDT ESP8266:
//   - Software WDT : timeout ~3.2 detik (di-feed oleh yield/delay)
//   - Hardware WDT : timeout ~8.0 detik (backup layer)
//
//  Alur otomatis:
//   1. Sistem berjalan normal, WDT di-feed rutin
//   2. Setelah 8 detik, sistem masuk tight-loop (freeze)
//   3. WDT timeout ~3.2 dtk -> reboot -> baca alasan reset
//
//  Board  : ESP8266 (NodeMCU / Wemos D1 Mini)
//  Modul  : MPU6050 (I2C), RTC DS3231 (I2C), Buzzer
// ============================================================

#include <Wire.h>
#include <RTClib.h>
#include <Adafruit_MPU6050.h>
#include <Adafruit_Sensor.h>

#define BUZZER_PIN D0
#define SDA_PIN    D2
#define SCL_PIN    D1

#define PHASE_NORMAL_DURATION_MS  8000UL   // 8 detik fase normal
#define WDT_FREEZE_DURATION_MS    5000UL   // 5 detik freeze (WDT trigger ~3.2 dtk)
#define WDT_FEED_INTERVAL_MS      1000UL   // feed WDT setiap 1 detik

RTC_DS3231       rtc;
Adafruit_MPU6050 mpu;

bool rtc_ok  = false;
bool mpu_ok  = false;

unsigned long last_wdt_feed      = 0;
unsigned long last_monitor_print = 0;
unsigned long phase_start_time   = 0;
bool freeze_phase_started        = false;

// ============================================================
//  Baca alasan reset dari register ESP8266
// ============================================================
void printResetReason() {
  String reason = ESP.getResetReason();

  Serial.println(F("==========================================================="));
  Serial.print(F("[BOOT] Reset Reason  : ")); Serial.println(reason);
  Serial.print(F("[BOOT] Reset Info    : ")); Serial.println(ESP.getResetInfo());

  if (reason.indexOf("Watchdog") >= 0) {
    Serial.println(F("[BOOT] Hardware WDT Reset"));
    Serial.println(F("[WDT]  Sistem melakukan self-recovery dari kondisi freeze."));
    for (int i = 0; i < 3; i++) {
      tone(BUZZER_PIN, 2500, 120);
      delay(250);
    }
  } else {
    Serial.println(F("[BOOT] Power-On Reset"));
    tone(BUZZER_PIN, 1200, 300);
    delay(400);
  }
  Serial.println(F("==========================================================="));
}

// ============================================================
//  Inisialisasi modul
// ============================================================
void performPOST() {
  mpu_ok = mpu.begin(0x69);
  rtc_ok = rtc.begin();

  Serial.print(F("[POST] MPU6050    : ")); Serial.println(mpu_ok ? F("OK") : F("GAGAL"));
  Serial.print(F("[POST] RTC DS3231 : ")); Serial.println(rtc_ok ? F("OK") : F("GAGAL"));
  Serial.println(F("==========================================================="));
}

// ============================================================
//  Waktu dari RTC
// ============================================================
String getTime() {
  if (!rtc_ok) return "--:--:--";
  DateTime now = rtc.now();
  char buf[10];
  snprintf(buf, sizeof(buf), "%02d:%02d:%02d",
           now.hour(), now.minute(), now.second());
  return String(buf);
}

// ============================================================
//  SETUP
// ============================================================
void setup() {
  delay(2000);
  Serial.begin(9600);
  Wire.begin(SDA_PIN, SCL_PIN);
  pinMode(BUZZER_PIN, OUTPUT);

  ESP.wdtFeed();

  Serial.println(F(""));
  Serial.println(F("==========================================================="));
  Serial.println(F("  Sistem Deteksi Kecelakaan Sepeda Motor"));
  Serial.println(F("  Watchdog Timer (WDT) - Self Recovery Test"));
  Serial.println(F("==========================================================="));

  printResetReason();
  performPOST();

  phase_start_time = millis();
}

// ============================================================
//  LOOP
// ============================================================
void loop() {
  unsigned long now_ms = millis();

  if (!freeze_phase_started) {

    // Feed WDT setiap 1 detik agar sistem tidak reboot
    if (now_ms - last_wdt_feed >= WDT_FEED_INTERVAL_MS) {
      ESP.wdtFeed();
      last_wdt_feed = now_ms;
    }

    // Monitor data setiap 1 detik — sama seperti sistem utama
    if (now_ms - last_monitor_print >= 1000) {
      last_monitor_print = now_ms;

      unsigned long uptime_s   = (now_ms - phase_start_time) / 1000;
      unsigned long freeze_in  = (PHASE_NORMAL_DURATION_MS / 1000) - uptime_s;

      float accel_total = 0;
      if (mpu_ok) {
        sensors_event_t a, g, temp;
        mpu.getEvent(&a, &g, &temp);
        accel_total = sqrt(
          pow(a.acceleration.x, 2) +
          pow(a.acceleration.y, 2) +
          pow(a.acceleration.z, 2)
        ) / 9.81;
      }

      Serial.println(F("==========================================================="));
      Serial.print(F("[TIME] ")); Serial.println(getTime());
      Serial.print(F("[WDT]  Status : AKTIF | Uptime: "));
      Serial.print(uptime_s); Serial.print(F(" dtk | Freeze in: "));
      Serial.print(freeze_in); Serial.println(F(" dtk"));
      if (mpu_ok) {
        Serial.print(F("[MPU]  Accel Total : "));
        Serial.print(accel_total, 3); Serial.println(F(" g"));
      }
      Serial.print(F("[POST] MPU:")); Serial.print(mpu_ok ? F("OK") : F("ERR"));
      Serial.print(F(" | RTC:")); Serial.println(rtc_ok ? F("OK") : F("ERR"));
      Serial.println(F("==========================================================="));
    }

    // Masuk ke fase freeze setelah fase normal selesai
    if (now_ms - phase_start_time >= PHASE_NORMAL_DURATION_MS) {
      freeze_phase_started = true;

      Serial.println(F("==========================================================="));
      Serial.println(F("[WDT]  Sistem membeku. Menunggu WDT timeout..."));
      Serial.println(F("==========================================================="));
      Serial.flush();

      // Tight-loop tanpa yield/delay/wdtFeed
      // Software WDT akan trigger pada ~3.2 detik -> reboot otomatis
      unsigned long freeze_start = millis();
      while (millis() - freeze_start < WDT_FREEZE_DURATION_MS) {
        // tidak ada apapun — WDT akan trigger sebelum 5000 ms
      }

      // Titik ini tidak akan pernah tercapai jika WDT berfungsi normal
      Serial.println(F("[WDT]  PERINGATAN: WDT tidak memicu reboot!"));
    }
  }
}
