#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <WiFiClient.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <Wire.h>
#include <Adafruit_MPU6050.h>
#include <Adafruit_Sensor.h>
#include <RTClib.h>
#include <TinyGPS++.h>
#include <SoftwareSerial.h>
#include <SPI.h>
#include <SD.h>
#include <time.h>

#define BUZZER_PIN D0  
#define SD_CS D8       
#define SIM_TX D3      
#define SIM_RX D4      

const char* ssid = "realme 8 5G";
const char* password = "12345678";
const char* server_url_post = "http://10.163.242.215/iot_accident_system/web/api/post_data.php";
const char* server_url_get  = "http://10.163.242.215/iot_accident_system/web/api/get_config.php";
const char* mqtt_server = "broker.hivemq.com";
const char* device_id = "ESP8266-001";

Adafruit_MPU6050 mpu;
RTC_DS3231 rtc;
TinyGPSPlus gps;
SoftwareSerial sim800l(SIM_TX, SIM_RX);
WiFiClient espClient;
PubSubClient mqtt(espClient);

WiFiClient asyncClient;
bool async_http_busy = false;
unsigned long async_http_timer = 0;

float threshold_pitch = 60.0;
float threshold_roll = 60.0;
float threshold_yaw = 180.0;
float threshold_accel = 2.5;

float pitch_offset = 0.0;
float roll_offset = 0.0;
float gyro_z_offset = 0.0;

String emergency_contacts[5];
int contact_count = 0;

bool accident_detected = false;
unsigned long accident_timer_start = 0;
bool potential_accident = false;
bool ntp_synced = false;

bool mpu_ok = false;
bool rtc_ok = false;
bool sd_ok = false;
bool sim_ok = false;
bool safe_mode_active = false;

unsigned long last_sd_write = 0;
unsigned long last_mqtt_publish = 0;
unsigned long last_mqtt_status_publish = 0;
unsigned long last_http_post = 0; 
unsigned long last_recovery_attempt = 0;

float last_speed = 0.0;
unsigned long last_speed_time = 0;

// Fungsi untuk kalibrasi sensor MPU6050
void calibrateSensors() {
  if (!mpu_ok) return;
  Serial.println("Memulai Kalibrasi... HARAP JANGAN GERAKKAN ALAT!");
  tone(BUZZER_PIN, 1500, 100); delay(200);
  tone(BUZZER_PIN, 1500, 100); delay(200);
  
  int samples = 100;
  for (int i = 0; i < samples; i++) {
    sensors_event_t a, g, temp;
    mpu.getEvent(&a, &g, &temp);
    
    float p = atan2(-a.acceleration.x, sqrt(a.acceleration.y * a.acceleration.y + a.acceleration.z * a.acceleration.z)) * 180.0 / PI;
    float r = atan2(a.acceleration.y, a.acceleration.z) * 180.0 / PI;
    
    pitch_offset += p;
    roll_offset += r;
    gyro_z_offset += g.gyro.z;
    delay(20);
  }
  
  pitch_offset /= samples;
  roll_offset /= samples;
  gyro_z_offset /= samples;
  
  Serial.print("Kalibrasi Selesai! Pitch Offset: "); Serial.print(pitch_offset);
  Serial.print(" | Roll Offset: "); Serial.println(roll_offset);
  
  tone(BUZZER_PIN, 2000, 500);
  delay(500);
}

// Fungsi untuk power-on self-test dan inisialisasi modul
void performPOST() {
  Serial.println("Performing Power-On Self-Test (POST)...");
  int retries = 3;
  
  while(retries > 0) {
    mpu_ok = mpu.begin(0x69);
    if (!mpu_ok) Serial.println("POST Failed: MPU6050");
    
    rtc_ok = rtc.begin();
    if (!rtc_ok) Serial.println("POST Failed: RTC DS3231");
    
    SPI.begin(); 
    pinMode(SD_CS, OUTPUT);
    sd_ok = SD.begin(SD_CS);
    if (!sd_ok) Serial.println("POST Failed: SD Card");
    
    sim800l.println("AT");
    delay(500);
    sim_ok = sim800l.find("OK");
    if (!sim_ok) Serial.println("POST Failed: SIM800L");

    if(mpu_ok && rtc_ok && sd_ok && sim_ok) {
      Serial.println("POST Passed! Semua modul OK.");
      tone(BUZZER_PIN, 1000, 500); 
      return;
    }
    
    Serial.println("POST Retrying...");
    tone(BUZZER_PIN, 500, 1000); 
    delay(2000);
    retries--;
  }
  
  Serial.println("POST Selesai dengan kegagalan pada beberapa modul.");
  if (!mpu_ok) {
    Serial.println("KRITIKAL: MPU6050 gagal. MASUK SAFE MODE!");
    safe_mode_active = true;
  }
  if (!sim_ok) {
    Serial.println("KRITIKAL: SIM800L gagal. SMS tidak dapat dikirim.");
  }
}

// Fungsi untuk mengecek kesehatan dan memulihkan koneksi modul
void checkModuleHealth() {
  if (millis() - last_recovery_attempt > 5000) {
    last_recovery_attempt = millis();
    
    Wire.beginTransmission(0x69);
    if (Wire.endTransmission() == 0) {
      if (!mpu_ok) {
        Serial.println("Recovery: MPU6050 terdeteksi kembali!");
        mpu_ok = mpu.begin(0x69);
        if (mpu_ok) {
          Serial.println("Recovery Sukses: MPU6050 aktif. Keluar dari Safe Mode.");
          safe_mode_active = false;
          calibrateSensors();
        }
      }
    } else {
      if (mpu_ok) { 
        Serial.println("KRITIKAL: MPU6050 Terputus di tengah jalan! MASUK SAFE MODE (GPS).");
        mpu_ok = false;
        safe_mode_active = true;
      }
    }
    
    Wire.beginTransmission(0x68);
    if (Wire.endTransmission() == 0) {
      if (!rtc_ok) {
        Serial.println("Recovery: RTC terdeteksi kembali.");
        rtc_ok = rtc.begin();
      }
    } else {
      if (rtc_ok) {
        Serial.println("Peringatan: RTC Terputus!");
        rtc_ok = false;
      }
    }
    
    sim800l.setTimeout(200);
    sim800l.println("AT");
    if (sim800l.find("OK")) {
      if (!sim_ok) {
        Serial.println("Recovery Sukses: SIM800L aktif kembali.");
        sim_ok = true;
      }
    } else {
      if (sim_ok) {
        Serial.println("Peringatan: SIM800L Terputus / Tidak Merespon!");
        sim_ok = false;
      }
    }
    sim800l.setTimeout(1000);
    
    static unsigned long last_sd_retry = 0;
    if (sd_ok) {
      File root = SD.open("/");
      if (root) {
        root.close();
      } else {
        Serial.println("Peringatan: SD Card Terputus/Dilepas!");
        sd_ok = false;
        last_sd_retry = millis();
      }
    } else {
      if (millis() - last_sd_retry > 60000) {
        sd_ok = SD.begin(SD_CS);
        if (sd_ok) {
          Serial.println("Recovery: SD Card terdeteksi kembali!");
        }
        last_sd_retry = millis();
      }
    }
    
    static uint32_t last_gps_chars = 0;
    if (gps.charsProcessed() > last_gps_chars) {
      last_gps_chars = gps.charsProcessed();
    } else {
      Serial.println("Peringatan: GPS Tidak Mengirim Data (Kabel putus/Mati)!");
    }
  }
}

// Fungsi inisialisasi utama perangkat, pin, dan koneksi
void setup() {
  delay(3000); 

  Serial.begin(9600);
  Serial.println("========================================");
  Serial.println("  TEST CASE 04: GPS NEO-6M");
  Serial.println("========================================"); 
  
  pinMode(SIM_TX, INPUT_PULLUP);
  sim800l.begin(9600);
  
  pinMode(BUZZER_PIN, OUTPUT);
  
  Wire.begin(D2, D1);    
  
  performPOST();

  if (mpu_ok) calibrateSensors();

  WiFi.begin(ssid, password);
  Serial.println("Connecting to WiFi...");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
  }
  Serial.println("WiFi Connected");
  
  configTime(7 * 3600, 0, "pool.ntp.org", "time.nist.gov");
  
  // Tunggu TCP stack ESP8266 benar-benar stabil setelah WiFi terhubung
  // (terlalu cepat bisa menyebabkan HTTP -11 READ_TIMEOUT)
  Serial.println("Menunggu koneksi stabil...");
  delay(3000);
  Serial.print("IP Server target: "); Serial.println(server_url_get);
  fetchConfig();
  mqtt.setServer(mqtt_server, 1883);
}

// Fungsi untuk mengambil konfigurasi batas sensor dari server
void fetchConfig() {
  if (WiFi.status() == WL_CONNECTED) {
    WiFiClient client;
    HTTPClient http;
    String url = String(server_url_get) + "?device_id=" + device_id;
    
    http.begin(client, url);
    http.setTimeout(10000); // Tunggu response server hingga 10 detik (default hanya 5 detik)
    int httpCode = http.GET();
    
    if (httpCode == 200) { 
      String payload = http.getString();
      DynamicJsonDocument doc(1024);
      DeserializationError error = deserializeJson(doc, payload);
      
      if (!error) {
        float p = doc["config"]["pitch_limit"].as<float>();
        if(p > 0) threshold_pitch = p;
        
        float r = doc["config"]["roll_limit"].as<float>();
        if(r > 0) threshold_roll = r;
        
        float y = doc["config"]["yaw_limit"].as<float>();
        if(y > 0) threshold_yaw = y;
        
        float a = doc["config"]["accel_limit"].as<float>();
        if(a > 0) threshold_accel = a;
        
        JsonArray contacts = doc["contacts"].as<JsonArray>();
        contact_count = 0;
        for (JsonVariant v : contacts) {
          if(contact_count < 5) {
            emergency_contacts[contact_count] = v["phone"].as<String>();
            contact_count++;
          }
        }
        Serial.println("Config fetched successfully.");
      }
    } else {
      Serial.print("Fetch config failed, HTTP Code: ");
      Serial.println(httpCode);
    }
    http.end();
  }
}

// Fungsi untuk menyambungkan kembali koneksi MQTT
void reconnectMQTT() {
  if (!mqtt.connected()) {
    if (mqtt.connect(device_id)) {
      Serial.println("MQTT Connected");
    }
  }
}

// Fungsi untuk mendapatkan waktu saat ini dengan format string
String getFormattedDateTime() {
  if (rtc_ok) {
    DateTime now = rtc.now();
    char buf[20];
    snprintf(buf, sizeof(buf), "%04d-%02d-%02d %02d:%02d:%02d", now.year(), now.month(), now.day(), now.hour(), now.minute(), now.second());
    return String(buf);
  } else if (gps.time.isValid() && gps.date.isValid()) {
    char buf[20];
    snprintf(buf, sizeof(buf), "%04d-%02d-%02d %02d:%02d:%02d", gps.date.year(), gps.date.month(), gps.date.day(), gps.time.hour(), gps.time.minute(), gps.time.second());
    return String(buf);
  } else {
    return "2000-01-01 00:00:00";
  }
}

// Fungsi perulangan utama program
void loop() {
  while (Serial.available() > 0) {
    gps.encode(Serial.read()); 
  }
  
  if (!mqtt.connected()) {
    reconnectMQTT();
  }
  mqtt.loop();

  if (async_http_busy) {
    if (asyncClient.available()) {
      while(asyncClient.available()) {
        asyncClient.read();
      }
      asyncClient.stop();
      async_http_busy = false;
    } else if (!asyncClient.connected() && millis() - async_http_timer > 500) {
      asyncClient.stop();
      async_http_busy = false;
    } else if (millis() - async_http_timer > 5000) {
      asyncClient.stop();
      async_http_busy = false;
    }
  }

  ESP.wdtFeed();
  checkModuleHealth();

  if (!ntp_synced && WiFi.status() == WL_CONNECTED && rtc_ok) {
    time_t now = time(nullptr);
    if (now > 1000000000) {
      struct tm timeinfo;
      gmtime_r(&now, &timeinfo);
      rtc.adjust(DateTime(timeinfo.tm_year + 1900, timeinfo.tm_mon + 1, timeinfo.tm_mday, timeinfo.tm_hour, timeinfo.tm_min, timeinfo.tm_sec));
      Serial.println("[NTP] Sinkronisasi Sukses! RTC diperbarui ke jam internet.");
      ntp_synced = true;
    }
  }

  float pitch = 0, roll = 0, yaw = 0, total_accel = 0, temp_val = 0;

  if (mpu_ok) {
    sensors_event_t a, g, temp;
    mpu.getEvent(&a, &g, &temp);
    
    float raw_pitch = atan2(-a.acceleration.x, sqrt(a.acceleration.y * a.acceleration.y + a.acceleration.z * a.acceleration.z)) * 180.0 / PI;
    float raw_roll = atan2(a.acceleration.y, a.acceleration.z) * 180.0 / PI;
    
    pitch = raw_pitch - pitch_offset;
    roll = raw_roll - roll_offset;
    
    static unsigned long last_time = millis();
    unsigned long current_time = millis();
    float dt = (current_time - last_time) / 1000.0;
    last_time = current_time;
    
    static float internal_yaw = 0.0;
    internal_yaw += ((g.gyro.z - gyro_z_offset) * 180.0 / PI) * dt;
    yaw = internal_yaw;

    total_accel = sqrt(pow(a.acceleration.x,2) + pow(a.acceleration.y,2) + pow(a.acceleration.z,2)) / 9.81; 
    temp_val = temp.temperature;
  }
  
  float lat = gps.location.isValid() ? gps.location.lat() : 0.0;
  float lng = gps.location.isValid() ? gps.location.lng() : 0.0;
  float spd = gps.speed.isValid() ? gps.speed.kmph() : 0.0;
  if (spd < 3.0) spd = 0.0; // Filter GPS drift (noise) saat diam
  float alt = gps.altitude.isValid() ? gps.altitude.meters() : 0.0;
  int sats = gps.satellites.isValid() ? gps.satellites.value() : 0;


  if (millis() - last_mqtt_publish > 2000) {
    String payload = "{";
    payload += "\"lat\":" + String(lat, 6) + ",";
    payload += "\"lon\":" + String(lng, 6) + ",";
    payload += "\"sats\":" + String(sats) + ",";
    payload += "\"speed\":" + String(spd, 1) + ",";
    payload += "\"roll\":" + String(roll, 1) + ",";
    payload += "\"pitch\":" + String(pitch, 1) + ",";
    payload += "\"yaw\":" + String(yaw, 1) + ",";
    payload += "\"status\":\"" + String(accident_detected ? "emergency" : "AMAN") + "\",";
    payload += "\"p_off\":" + String(pitch_offset, 1) + ",";
    payload += "\"r_off\":" + String(roll_offset, 1);
    payload += "}";
    
    String topic = String("motosafe/telemetry/") + device_id;
    if (mqtt.publish(topic.c_str(), payload.c_str())) {
      Serial.print("[MQTT Telemetry] ");
      Serial.println(payload);
    } else {
      Serial.println("[MQTT Error] Gagal mengirim telemetry (Koneksi terputus)");
    }
    last_mqtt_publish = millis();
  }

  if (millis() - last_mqtt_status_publish > 5000) {
    bool gps_ok = gps.charsProcessed() > 10;
    String payload = "{";
    payload += "\"mpu\":\"" + String(mpu_ok ? "ok" : "error") + "\",";
    payload += "\"gps\":\"" + String(gps_ok ? "ok" : "error") + "\",";
    payload += "\"rtc\":\"" + String(rtc_ok ? "ok" : "error") + "\",";
    payload += "\"sd\":\"" + String(sd_ok ? "ok" : "error") + "\",";
    payload += "\"sim\":\"" + String(sim_ok ? "ok" : "error") + "\",";
    payload += "\"safe_mode\":" + String(safe_mode_active ? "true" : "false");
    payload += "}";
    
    String topic = String("motosafe/status/") + device_id;
    mqtt.publish(topic.c_str(), payload.c_str());
    last_mqtt_status_publish = millis();
    
    Serial.print("[MQTT Status] ");
    Serial.println(payload);
  }

  bool is_crashing = false;

  if (!safe_mode_active && mpu_ok) {
    if (abs(pitch) > threshold_pitch || abs(roll) > threshold_roll || abs(yaw) > threshold_yaw || total_accel > threshold_accel) {
      is_crashing = true;
    }
  } else {
    if (millis() - last_speed_time >= 1000) {
      if (last_speed_time != 0) {
        float speed_drop = last_speed - spd;
        if (speed_drop >= 20.0) {
          is_crashing = true;
        }
      }
      last_speed = spd;
      last_speed_time = millis();
    }
  }

  if (is_crashing) {
    if (!potential_accident) {
      potential_accident = true;
      accident_timer_start = millis();
    } else {
      if (millis() - accident_timer_start > 2000 && !accident_detected) {
        accident_detected = true;

        String payload = "{";
        payload += "\"lat\":" + String(lat, 6) + ",";
        payload += "\"lon\":" + String(lng, 6) + ",";
        payload += "\"speed\":" + String(spd, 1) + ",";
        payload += "\"roll\":" + String(roll, 1) + ",";
        payload += "\"pitch\":" + String(pitch, 1) + ",";
        payload += "\"yaw\":" + String(yaw, 1) + ",";
        payload += "\"status\":\"emergency\",";
        payload += "\"p_off\":" + String(pitch_offset, 1) + ",";
        payload += "\"r_off\":" + String(roll_offset, 1);
        payload += "}";
        String topic = String("motosafe/telemetry/") + device_id;
        mqtt.publish(topic.c_str(), payload.c_str());
        last_mqtt_publish = millis();
        
        Serial.print("[MQTT KECELAKAAN] ");
        Serial.println(payload);
        
        handleAccident(lat, lng, spd, alt, pitch, roll, yaw, total_accel, temp_val);
      }
    }
  } else {
    potential_accident = false;
    if (accident_detected) {
      accident_detected = false; 
      noTone(BUZZER_PIN);        
      last_http_post = millis(); 
    }
  }

  if (sd_ok && millis() - last_sd_write > 2000) {
    logToSD(lat, lng, alt, spd, roll, pitch, yaw, total_accel, temp_val, accident_detected ? "emergency" : "Aman");
    last_sd_write = millis();
  }

  unsigned long http_interval = accident_detected ? 2000 : 15000;
  if (millis() - last_http_post > http_interval) {
    sendHTTPPost(lat, lng, spd, alt, pitch, roll, yaw, total_accel, temp_val, accident_detected ? "emergency" : "Aman");
    last_http_post = millis();
  }
}

// Fungsi untuk menangani aksi saat kecelakaan terjadi (alarm, HTTP, SMS)
void handleAccident(float lat, float lng, float spd, float alt, float p, float r, float y, float a, float t) {
  tone(BUZZER_PIN, 2000); 
  
  sendHTTPPost(lat, lng, spd, alt, p, r, y, a, t, "emergency");
  last_http_post = millis(); 
  
  String time_str = getFormattedDateTime();
  if (sim_ok) {
    for(int i=0; i<contact_count; i++) {
      sendSMS(emergency_contacts[i], lat, lng, time_str);
    }
  } else {
    Serial.println("Gagal Kirim SMS karena SIM800L bermasalah.");
  }
}

// Fungsi untuk mencatat data ke SD Card (Blackbox)
void logToSD(float lat, float lng, float alt, float spd, float roll, float pitch, float yaw, float accel, float tmp, String status) {
  File dataFile = SD.open("blackbox.csv", FILE_WRITE);
  if (dataFile) {
    String dataString = getFormattedDateTime() + ",";
    dataString += String(lat, 6) + "," + String(lng, 6) + "," + String(alt, 1) + "," + String(spd, 1) + ",";
    dataString += String(roll, 1) + "," + String(pitch, 1) + "," + String(yaw, 1) + ",";
    dataString += String(accel, 2) + "," + String(tmp, 1) + "," + status;
    
    dataFile.println(dataString);
    dataFile.close();
  }
}

// Fungsi untuk mengirim data ke server via HTTP POST
void sendHTTPPost(float lat, float lng, float spd, float alt, float p, float r, float y, float a, float t, String status) {
  if (WiFi.status() == WL_CONNECTED) {
    if (async_http_busy) {
      if (millis() - async_http_timer > 5000) {
        asyncClient.stop();
        async_http_busy = false;
      } else {
        return;
      }
    }
    
    String url = String(server_url_post);
    String host = "";
    String path = "";
    int port = 80;
    
    if (url.startsWith("http://")) {
        url = url.substring(7);
        int slashIdx = url.indexOf('/');
        if (slashIdx != -1) {
            host = url.substring(0, slashIdx);
            path = url.substring(slashIdx);
            
            int colonIdx = host.indexOf(':');
            if (colonIdx != -1) {
                port = host.substring(colonIdx + 1).toInt();
                host = host.substring(0, colonIdx);
            }
        }
    }

    if (asyncClient.connect(host.c_str(), port)) {
      DynamicJsonDocument doc(1024);
      doc["id_device"] = device_id;
      doc["waktu_kejadian"] = getFormattedDateTime();
      
      JsonObject lokasi = doc.createNestedObject("lokasi");
      lokasi["latitude"] = lat;
      lokasi["longitude"] = lng;
      lokasi["altitude"] = alt;
      lokasi["speed"] = spd;
      
      JsonObject sensor = doc.createNestedObject("nilai_sensor");
      sensor["roll"] = r;
      sensor["pitch"] = p;
      sensor["yaw"] = y;
      sensor["accel_total"] = a;
      sensor["suhu"] = t;
      
      doc["status"] = status;

      String json_str;
      serializeJson(doc, json_str);
      
      Serial.print("[HTTP POST] ");
      Serial.println(json_str);

      asyncClient.println("POST " + path + " HTTP/1.1");
      asyncClient.println("Host: " + host);
      asyncClient.println("Content-Type: application/json");
      asyncClient.println("Content-Length: " + String(json_str.length()));
      asyncClient.println("Connection: close");
      asyncClient.println();
      asyncClient.print(json_str);
      
      async_http_busy = true;
      async_http_timer = millis();
    } else {
      Serial.println("Async HTTP Connect Failed");
    }
  }
}

// Fungsi delay yang tetap memproses task background seperti MQTT dan WDT
void smartDelay(unsigned long ms) {
  unsigned long start = millis();
  while (millis() - start < ms) {
    mqtt.loop();
    ESP.wdtFeed();
    delay(10);
  }
}

// Fungsi untuk mengirim notifikasi SMS
void sendSMS(String phone, float lat, float lng, String time_str) {
  sim800l.println("AT+CMGF=1"); 
  smartDelay(500);
  sim800l.print("AT+CMGS=\"");
  sim800l.print(phone);
  sim800l.println("\"");
  smartDelay(500);
  
  sim800l.println("[CRASH DETEKTOR - DARURAT]");
  sim800l.print("Telah terdeteksi indikasi kecelakaan pada kendaraan ");
  sim800l.print(device_id);
  sim800l.println("!");
  sim800l.print("Waktu: ");
  sim800l.println(time_str);
  sim800l.print("Lokasi GPS: https://maps.google.com/?q=");
  sim800l.print(lat, 6);
  sim800l.print(",");
  sim800l.println(lng, 6);
  sim800l.println("Segera hubungi korban atau lakukan pengecekan ke lokasi!");
  smartDelay(500);
  
  sim800l.write(26); 
  smartDelay(3000);
}

