# IoT Accident Crash Detector System

Sistem pendeteksi kecelakaan berbasis IoT terintegrasi dengan sensor MPU6050 (akselerometer & giroskop), modul GPS NEO-6M, RTC, MicroSD Logger, GSM/GPRS, dan Web Dashboard Monitoring real-time.

## 🚀 Fitur Utama
- **Deteksi Benturan Otomatis**: Algoritma kalkulasi ambang batas g-force & tilt angle menggunakan sensor MPU-6050.
- **Geolokasi Presisi**: Pelacakan koordinat lintang & bujur secara real-time via modul GPS.
- **Safe Mode & Watchdog Timer (WDT)**: Mencegah kegagalan sistem mikrokontroler dengan recovery otomatis.
- **Web Dashboard & Emergency Alert**: Dashboard berbasis Web PHP & MySQL untuk pemantauan armada, visualisasi rute perjalanan, dan notifikasi darurat.
- **Data Logging & Offline Backup**: Pencatatan log data telemetri ke kartu MicroSD jika jaringan GSM offline.
- **RESTful API & Postman Collection**: Endpoint API untuk sinkronisasi telemetri hardware ke server.

## 📁 Struktur Folder
- `/arduino`: Source code firmware mikrokontroler (ESP/Arduino) beserta unit test modul (MPU, GPS, RTC, SD Card, Buzzer, Safemode, WDT, Lead Time).
- `/web`: Web application dashboard (PHP, JavaScript, CSS/Assets, REST API).
- `db_schema.sql`: Skema database MySQL untuk sistem pemantauan kecelakaan.
- `Uji_Fungsional_Crash_Detektor_Lengkap_V2.postman_collection.json`: File Postman untuk pengujian API secara menyeluruh.

## 🛠️ Tech Stack
- **Hardware/Firmware**: C/C++, Arduino IDE, ESP32 / Arduino, MPU-6050, GPS NEO-6M, SIM800L / GSM Modul.
- **Backend & Web**: PHP, MySQL / MariaDB, JavaScript, CSS3, REST API.
- **Tools**: Postman, Git.

---
Dikembangkan oleh **Muchamad Fikri Ali** ([@Fikriproject](https://github.com/Fikriproject))
