# Sistem Pendeteksi Kecelakaan (CrashDetect)

Project Ionic ini menampilkan data sensor MPU6050 (Pitch, Roll, Gyro) dari Microcontroller via MQTT dan mendeteksi kondisi kecelakaan.

## Fitur
1. **Login & Register**: Pengguna dapat mendaftarkan perangkat mereka.
2. **Dashboard Real-time**: Menampilkan data kemiringan dan status bahaya.
3. **Emergency Link**: Kontak darurat dapat login menggunakan nomor HP mereka untuk memantau kondisi pengguna (tanpa password).

## Cara Menjalankan
1. Install dependencies:
   ```bash
   npm install
   ```
2. Jalankan di browser:
   ```bash
   ionic serve
   ```

## Konfigurasi MQTT
App ini dikonfigurasi menggunakan Broker Public `broker.emqx.io` pada port `8083` (WebSocket).
Topik default: `device/{DEVICE_ID}/data`

## Simulasi Data (Testing)
Anda dapat mengirim data palsu menggunakan software MQTTX atau terminal untuk mengetes tampilan App.

**Topik:** `device/DEVICE_001/data`
**Payload (JSON):**
```json
{
  "pitch": 45,
  "roll": 10,
  "gyro": "X:0.1 Y:0.2 Z:0.0",
  "accident": false
}
```

Untuk mengetes bahaya:
```json
{
  "pitch": 80,
  "roll": 10,
  "gyro": "...",
  "accident": true
}
```

## Akun Demo Default
- **User Login:**
  - No HP: `08123456789`
  - Password: `admin`
  - Device ID: `DEVICE_001`
- **Emergency Login (Link):**
  - No Darurat: `08999999999`
