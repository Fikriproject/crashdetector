<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$error_msg = '';
$success_msg = '';

if (!isset($_GET['device_id'])) {
    header("Location: profile.php");
    exit();
}
$device_id = $conn->real_escape_string($_GET['device_id']);

// Check ownership
$stmt = $conn->prepare("SELECT * FROM device WHERE device_id = ? AND owner_id = ?");
$stmt->bind_param("si", $device_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("Akses ditolak. Anda bukan pemilik perangkat ini.");
}
$device = $result->fetch_assoc();

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_threshold'])) {
    $threshold_pitch = floatval($_POST['threshold_pitch']);
    $threshold_roll = floatval($_POST['threshold_roll']);
    $threshold_yaw = floatval($_POST['threshold_yaw']);
    $threshold_accel = floatval($_POST['threshold_accel']);
    
    $stmt_update = $conn->prepare("UPDATE device SET threshold_pitch=?, threshold_roll=?, threshold_yaw=?, threshold_accel=? WHERE device_id=?");
    $stmt_update->bind_param("dddds", $threshold_pitch, $threshold_roll, $threshold_yaw, $threshold_accel, $device_id);
    if($stmt_update->execute()) {
        $success_msg = "Pengaturan Ambang Batas (Threshold) berhasil diperbarui. Perangkat akan menggunakan nilai ini secara otomatis.";
        // refresh device data
        $stmt->execute();
        $device = $stmt->get_result()->fetch_assoc();
    } else {
        $error_msg = "Terjadi kesalahan saat menyimpan pengaturan.";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_wifi'])) {
    $wifi_ssid = $conn->real_escape_string($_POST['wifi_ssid']);
    $wifi_password = $conn->real_escape_string($_POST['wifi_password']);
    
    $stmt_update = $conn->prepare("UPDATE device SET wifi_ssid=?, wifi_password=? WHERE device_id=?");
    $stmt_update->bind_param("sss", $wifi_ssid, $wifi_password, $device_id);
    if($stmt_update->execute()) {
        $success_msg = "Kredensial WiFi berhasil disimpan! Alat akan menggunakan koneksi ini saat pertama kali dinyalakan.";
        $stmt->execute();
        $device = $stmt->get_result()->fetch_assoc();
    } else {
        $error_msg = "Terjadi kesalahan saat menyimpan pengaturan WiFi.";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['remove_device'])) {
    $confirm_text = $_POST['confirm_delete'] ?? '';
    if (strtolower(trim($confirm_text)) === 'delete') {
        $stmt_remove = $conn->prepare("UPDATE device SET owner_id = NULL WHERE device_id = ? AND owner_id = ?");
        $stmt_remove->bind_param("si", $device_id, $user_id);
        if ($stmt_remove->execute()) {
            header("Location: profile.php?msg=deleted");
            exit();
        } else {
            $error_msg = "Gagal menghapus perangkat.";
        }
    } else {
        $error_msg = "Konfirmasi gagal. Anda harus mengetik kata 'delete' dengan benar.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRASH DETEKTOR - Pengaturan Alat</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .info-box-modern {
            background: linear-gradient(to right, #eff6ff, #f8fafc);
            border: 1px solid #bfdbfe;
            border-left: 4px solid #3b82f6;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            display: flex;
            gap: 1.25rem;
            align-items: flex-start;
            box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.05);
        }
        .info-icon-wrapper {
            background: #dbeafe;
            color: #2563eb;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .info-box-modern h4 {
            color: #1e3a8a;
            margin: 0 0 0.5rem 0;
            font-size: 1.05rem;
            font-weight: 700;
        }
        .info-box-modern p {
            color: #334155;
            font-size: 0.9rem;
            line-height: 1.6;
            margin: 0;
        }
        .info-box-modern em {
            color: #64748b;
            font-size: 0.85rem;
            display: block;
            margin-top: 0.75rem;
        }
        .form-label-modern {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            color: #334155;
        }
        .input-group {
            margin-bottom: 1.5rem;
        }
        .settings-card {
            padding: 2.5rem;
            border-radius: 20px;
        }
        @media (max-width: 768px) {
            .settings-card { padding: 1.5rem; }
        }
    </style>

    <!-- PWA Setup -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#3b82f6">
    <link rel="apple-touch-icon" href="assets/img/icon-192.png">
    <script>
      if ("serviceWorker" in navigator) {
        window.addEventListener("load", () => {
          navigator.serviceWorker.register("sw.js").then(registration => {
            console.log("SW registered:", registration);
          }).catch(error => {
            console.log("SW registration failed:", error);
          });
        });
      }
    </script>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Khusus Desktop -->
        <aside class="sidebar">
            <div class="sidebar-logo">CRASH DETEKTOR</div>
            <div class="sidebar-menu">
                <a href="dashboard.php" class="sidebar-link">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Dashboard
                </a>
                <a href="riwayat.php" class="sidebar-link">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Riwayat Perjalanan
                </a>
                <a href="profile.php" class="sidebar-link active">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Profil
                </a>
            </div>
            <a href="logout.php" class="sidebar-link" style="color: var(--danger); margin-top: auto;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Logout
            </a>
        </aside>

        <div class="content-area">
            <!-- Header Mobile Saja -->
            <div class="app-header mobile-only flex-row">
                <a href="profile.php" class="back-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    Kembali
                </a>
                <div class="app-title" style="text-align: right;">Pengaturan Alat</div>
            </div>
            
            <div class="app-content">
                <div style="max-width: 850px; margin: 0 auto; padding-top: 1rem;">
                    <?php if(!empty($error_msg)): ?>
                        <div class="alert alert-error" style="font-size: 0.9rem; margin-bottom: 1.5rem; border-radius: 12px;"><?php echo htmlspecialchars($error_msg); ?></div>
                    <?php endif; ?>
                    <?php if(!empty($success_msg)): ?>
                        <div class="alert alert-success" style="font-size: 0.9rem; margin-bottom: 1.5rem; border-radius: 12px;"><?php echo htmlspecialchars($success_msg); ?></div>
                    <?php endif; ?>

                    <!-- Header -->
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
                        <div>
                            <h2 style="font-size: 1.5rem; font-weight: 700; color: #0f172a; margin-bottom: 0.25rem;">Konfigurasi Sensor</h2>
                            <p style="color: #64748b; font-size: 0.95rem; margin: 0;">ID Perangkat: <strong style="color: #0f172a;"><?php echo htmlspecialchars($device_id); ?></strong></p>
                        </div>
                        <div style="display: inline-flex; align-items: center; justify-content: center; width: 56px; height: 56px; background: #eff6ff; color: #3b82f6; border-radius: 14px;">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>
                        </div>
                    </div>
                        
                    <!-- Info Box -->
                    <div class="info-box-modern" style="margin-bottom: 2rem;">
                        <div class="info-icon-wrapper">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                        </div>
                        <div>
                            <h4>Rekomendasi Sistem: 60 Derajat</h4>
                            <p>
                                Berdasarkan studi literatur, kemiringan maksimal sepeda motor saat menikung ekstrim berada pada rentang <strong>40-50 derajat</strong>. Ambang batas <strong>60 derajat</strong> ditetapkan sebagai indikator akurat kecelakaan (jatuh).
                            </p>
                            <em>Alat ini memiliki fitur <strong>Auto-Kalibrasi</strong> yang mereset titik 0 saat dinyalakan, memastikan akurasi tetap terjaga di posisi pemasangan apapun.</em>
                        </div>
                    </div>
                            
                    <div style="display: grid; grid-template-columns: 1fr; gap: 2rem; margin-bottom: 2rem;">
                        <!-- Form Threshold -->
                        <form method="POST" action="" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 2rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);">
                            <h3 style="font-size: 1.1rem; font-weight: 700; color: #0f172a; margin-bottom: 1.5rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">Pengaturan Ambang Batas (Threshold)</h3>
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem; margin-bottom: 2rem;">
                                <div>
                                <label class="form-label-modern" for="threshold_pitch">Threshold Pitch (Depan/Belakang)</label>
                                <input type="number" step="0.1" name="threshold_pitch" id="threshold_pitch" class="form-control-outlined" style="background: #f8fafc; font-size: 1rem;" value="<?php echo htmlspecialchars($device['threshold_pitch']); ?>" required>
                            </div>
                            <div>
                                <label class="form-label-modern" for="threshold_roll">Threshold Roll (Samping)</label>
                                <input type="number" step="0.1" name="threshold_roll" id="threshold_roll" class="form-control-outlined" style="background: #f8fafc; font-size: 1rem;" value="<?php echo htmlspecialchars($device['threshold_roll']); ?>" required>
                            </div>
                            <div>
                                <label class="form-label-modern" for="threshold_yaw">Threshold Yaw (Putaran)</label>
                                <input type="number" step="0.1" name="threshold_yaw" id="threshold_yaw" class="form-control-outlined" style="background: #f8fafc; font-size: 1rem;" value="<?php echo htmlspecialchars($device['threshold_yaw']); ?>" required>
                            </div>
                            <div>
                                <label class="form-label-modern" for="threshold_accel">Threshold Akselerasi (G-Force)</label>
                                <input type="number" step="0.1" name="threshold_accel" id="threshold_accel" class="form-control-outlined" style="background: #f8fafc; font-size: 1rem;" value="<?php echo htmlspecialchars($device['threshold_accel']); ?>" required>
                            </div>
                            </div>
                                    
                            <button type="submit" name="update_threshold" class="btn btn-primary" style="padding: 1rem; font-size: 1.05rem; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(59,130,246,0.2); width: 100%;">Simpan Pengaturan Sensor</button>
                        </form>

                        <!-- Form WiFi (Komentar Sementara) 
                        <form method="POST" action="" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 2rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);">
                            <h3 style="font-size: 1.1rem; font-weight: 700; color: #0f172a; margin-bottom: 1.5rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">Pengaturan Koneksi WiFi Alat</h3>
                            <p style="font-size: 0.9rem; color: #64748b; margin-bottom: 1.5rem; line-height: 1.5;">Masukkan SSID dan Password WiFi yang akan digunakan oleh alat untuk terhubung ke internet. Pengaturan ini hanya perlu dilakukan satu kali, kecuali jika Anda mengganti koneksi WiFi.</p>
                            
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem; margin-bottom: 2rem;">
                                <div>
                                    <label class="form-label-modern" for="wifi_ssid">Nama WiFi (SSID)</label>
                                    <input type="text" name="wifi_ssid" id="wifi_ssid" class="form-control-outlined" style="background: #f8fafc; font-size: 1rem;" placeholder="Contoh: Indihome-Home" value="<?php echo htmlspecialchars($device['wifi_ssid'] ?? ''); ?>" required>
                                </div>
                                <div>
                                    <label class="form-label-modern" for="wifi_password">Password WiFi</label>
                                    <input type="text" name="wifi_password" id="wifi_password" class="form-control-outlined" style="background: #f8fafc; font-size: 1rem;" placeholder="Masukkan password wifi" value="<?php echo htmlspecialchars($device['wifi_password'] ?? ''); ?>">
                                </div>
                            </div>
                                    
                            <button type="submit" name="update_wifi" class="btn btn-primary" style="background: var(--success); padding: 1rem; font-size: 1.05rem; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.2); width: 100%;">Simpan Kredensial WiFi</button>
                        </form>
                        -->
                        
                        <!-- Form Hapus Alat -->
                        <form method="POST" action="" style="background: #ffffff; border: 1px solid #fee2e2; border-radius: 16px; padding: 2rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02);">
                            <h3 style="font-size: 1.1rem; font-weight: 700; color: #dc2626; margin-bottom: 1.5rem; border-bottom: 1px solid #fecaca; padding-bottom: 0.75rem;">Zona Berbahaya: Hapus Alat</h3>
                            <p style="font-size: 0.9rem; color: #475569; margin-bottom: 1.5rem; line-height: 1.6;">Tindakan ini akan memutus tautan akun Anda dengan alat ini. Riwayat perjalanan sebelumnya akan tetap tersimpan di database untuk keamanan, tetapi Anda tidak akan bisa lagi memantau alat ini kecuali Anda mem-pairing ulangnya.</p>
                            
                            <div style="margin-bottom: 1.5rem;">
                                <label class="form-label-modern" for="confirm_delete" style="color: #dc2626;">Ketik <strong>delete</strong> untuk mengonfirmasi:</label>
                                <input type="text" name="confirm_delete" id="confirm_delete" class="form-control-outlined" style="background: #fef2f2; border-color: #fecaca; font-size: 1rem; color: #dc2626;" placeholder="Ketik delete" required>
                            </div>
                                    
                            <button type="submit" name="remove_device" class="btn btn-danger" style="background: #dc2626; color: #ffffff; padding: 1rem; font-size: 1.05rem; border-radius: 12px; box-shadow: 0 10px 15px -3px rgba(220, 38, 38, 0.2); width: 100%; border: none; cursor: pointer; font-weight: 600;">Hapus Kepemilikan Alat</button>
                        </form>
                        
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="mobile-nav">
        <a href="dashboard.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            Dashboard
        </a>
        <a href="riwayat.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Riwayat
        </a>
        <a href="profile.php" class="active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Profil
        </a>
    </div>
</body>
</html>
