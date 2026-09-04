#include <Wire.h>
#include <RTClib.h>
#include <TinyGPS++.h>

RTC_DS3231 rtc;
TinyGPSPlus gps;

// Pin konfigurasi MPU & RTC menggunakan D1 (SCL) dan D2 (SDA)
// GPS menggunakan Pin RX (GPIO3)

void setup() {
  Serial.begin(9600);
  delay(2000); // Tunggu serial monitor terbuka
  Serial.println("\n\n=== TESTING MODUL RTC & GPS NEO-6M ===");

  Wire.begin(D2, D1);

  if (!rtc.begin()) {
    Serial.println("[RTC] ERROR: Modul RTC DS3231 Tidak Terdeteksi di jalur I2C!");
  } else {
    Serial.println("[RTC] Modul RTC terhubung dan OK.");
    if (rtc.lostPower()) {
      Serial.println("[RTC] Peringatan: Baterai RTC mati atau waktu belum disetel (Masih di tahun 2000).");
    }
  }
  
  Serial.println("[GPS] Menunggu Sinyal Satelit GPS... (Pastikan antena menghadap langit terbuka)");
  Serial.println("========================================\n");
}

void loop() {
  // 1. Baca data dari GPS melalui port RX secara terus menerus (Non-blocking)
  while (Serial.available() > 0) {
    gps.encode(Serial.read());
  }

  // 2. Cetak hasil setiap 2 detik
  static unsigned long last_print = 0;
  if (millis() - last_print > 2000) {
    // --- BACA RTC ---
    Serial.print("[RTC] Waktu Internal: ");
    DateTime now = rtc.now();
    char buf[20];
    snprintf(buf, sizeof(buf), "%04d-%02d-%02d %02d:%02d:%02d", now.year(), now.month(), now.day(), now.hour(), now.minute(), now.second());
    Serial.println(buf);

    // --- BACA GPS ---
    Serial.print("[GPS] Satelit Terkunci: ");
    Serial.println(gps.satellites.value());
    
    if (gps.location.isValid()) {
      Serial.print("[GPS] Lokasi Ter-Lock! Lat: ");
      Serial.print(gps.location.lat(), 6);
      Serial.print(", Lng: ");
      Serial.println(gps.location.lng(), 6);
      
      // Opsional: Validasi waktu GPS
      if (gps.time.isValid()) {
        Serial.print("[GPS] Waktu dari Satelit (UTC): ");
        char tbuf[15];
        snprintf(tbuf, sizeof(tbuf), "%02d:%02d:%02d", gps.time.hour(), gps.time.minute(), gps.time.second());
        Serial.println(tbuf);
      }
    } else {
      Serial.println("[GPS] Masih mencari posisi... (0.000000, 0.000000)");
    }

    Serial.println("----------------------------------------");
    last_print = millis();
  }
}
