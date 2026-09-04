<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$error_msg = '';
$csrf_token = generate_csrf_token();

// Handle Add/Pair Device on Dashboard directly
if (isset($_POST['pair_device_first'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }
    $input_id = $_POST['device_id'];
    
    // Check if input is a 6-character Pairing Code
    if (strlen($input_id) === 6) {
        $stmt = $conn->prepare("SELECT id, device_id, owner_id, pairing_code_expires_at FROM device WHERE pairing_code = ? AND pairing_code IS NOT NULL");
        $stmt->bind_param("s", $input_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            $dev = $res->fetch_assoc();
            if (strtotime($dev['pairing_code_expires_at']) > time()) {
                if ($dev['owner_id'] != $user_id) {
                    $stmt_cv = $conn->prepare("SELECT id FROM device_viewers WHERE device_id = ? AND user_id = ?");
                    $stmt_cv->bind_param("si", $dev['device_id'], $user_id);
                    $stmt_cv->execute();
                    
                    if ($stmt_cv->get_result()->num_rows == 0) {
                        mysqli_begin_transaction($conn);
                        try {
                            $stmt_ins = $conn->prepare("INSERT INTO device_viewers (device_id, user_id) VALUES (?, ?)");
                            $stmt_ins->bind_param("si", $dev['device_id'], $user_id);
                            $stmt_ins->execute();
                            
                            $stmt_u = $conn->prepare("SELECT full_name, phone FROM users WHERE id = ?");
                            $stmt_u->bind_param("i", $user_id);
                            $stmt_u->execute();
                            $u_data = $stmt_u->get_result()->fetch_assoc();
                            
                            $stmt_pair = $conn->prepare("INSERT INTO pairing (device_id, user_id, emergency_contact_name, emergency_contact_phone) VALUES (?, ?, ?, ?)");
                            $stmt_pair->bind_param("siss", $dev['device_id'], $user_id, $u_data['full_name'], $u_data['phone']);
                            $stmt_pair->execute();
                            
                            $stmt_upd = $conn->prepare("UPDATE device SET pairing_code = NULL, pairing_code_expires_at = NULL WHERE id = ?");
                            $stmt_upd->bind_param("i", $dev['id']);
                            $stmt_upd->execute();
                            
                            mysqli_commit($conn);
                        } catch (Exception $e) {
                            mysqli_rollback($conn);
                        }
                    } else {
                        $stmt_upd = $conn->prepare("UPDATE device SET pairing_code = NULL, pairing_code_expires_at = NULL WHERE id = ?");
                        $stmt_upd->bind_param("i", $dev['id']);
                        $stmt_upd->execute();
                    }
                    header("Location: profile.php");
                    exit();
                } else {
                    $error_msg = "Anda tidak bisa mem-pairing alat Anda sendiri dengan kode ini.";
                }
            } else {
                $error_msg = "Kode Pairing sudah kedaluwarsa.";
            }
        } else {
            $error_msg = "Kode Pairing tidak valid atau sudah digunakan.";
        }
    } else {
        $device_id = $input_id;
        $stmt_check = $conn->prepare("SELECT id, owner_id FROM device WHERE device_id = ?");
        $stmt_check->bind_param("s", $device_id);
        $stmt_check->execute();
        $check = $stmt_check->get_result();
        
        if ($check->num_rows > 0) {
            $dev = $check->fetch_assoc();
            if ($dev['owner_id'] == NULL) {
                $stmt_upd2 = $conn->prepare("UPDATE device SET owner_id = ?, status = 'active' WHERE device_id = ?");
                $stmt_upd2->bind_param("is", $user_id, $device_id);
                $stmt_upd2->execute();
                header("Location: profile.php");
                exit();
            } else if ($dev['owner_id'] != $user_id) {
                $error_msg = "Alat ini sudah memiliki pemilik. Gunakan Code Pairing untuk menghubungkan.";
            }
        } else {
            $stmt_ins2 = $conn->prepare("INSERT INTO device (device_id, owner_id, status) VALUES (?, ?, 'active')");
            $stmt_ins2->bind_param("si", $device_id, $user_id);
            $stmt_ins2->execute();
            header("Location: profile.php");
            exit();
        }
    }
}

$stmt_owned = $conn->prepare("SELECT * FROM device WHERE owner_id = ?");
$stmt_owned->bind_param("i", $user_id);
$stmt_owned->execute();
$owned_devices_res = $stmt_owned->get_result();
$all_devices = [];
while($row = $owned_devices_res->fetch_assoc()) {
    $row['is_owner'] = true;
    $all_devices[] = $row;
}

$stmt_followed = $conn->prepare("
    SELECT d.*, u.full_name as owner_name 
    FROM device_viewers dv 
    JOIN device d ON dv.device_id = d.device_id 
    JOIN users u ON d.owner_id = u.id
    WHERE dv.user_id = ?
");
$stmt_followed->bind_param("i", $user_id);
$stmt_followed->execute();
$followed_devices_res = $stmt_followed->get_result();
while($row = $followed_devices_res->fetch_assoc()) {
    $row['is_owner'] = false;
    $all_devices[] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>CRASH DETEKTOR - Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/paho-mqtt/1.0.1/mqttws31.min.js"></script>

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
    <div class="app-container <?php echo !empty($all_devices) ? 'has-devices' : ''; ?>">
        <!-- Sidebar Khusus Desktop -->
        <aside class="sidebar">
            <div class="sidebar-logo">CRASH DETEKTOR</div>
            <div class="sidebar-menu">
                <a href="dashboard.php" class="sidebar-link active">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Dashboard
                </a>
                <a href="riwayat.php" class="sidebar-link">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Riwayat Perjalanan
                </a>
                <a href="profile.php" class="sidebar-link">
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
            <div class="app-header mobile-only flex-row" style="background: var(--bg-color); border:none;">
                <div style="font-weight: 600; font-size: 1.1rem;">
                    Halo, <?php echo htmlspecialchars(explode(' ', $_SESSION['full_name'] ?? 'User')[0]); ?>
                </div>
                <a href="profile.php" class="user-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </a>
            </div>

            <!-- Header Desktop Tambahan (Opsional) -->
            <div class="app-header desktop-header flex-row" style="border:none; display:none;">
                <h2 style="font-size: 1.5rem; font-weight: 700;">Dashboard</h2>
                <div style="font-weight: 600; color: var(--text-muted);">
                    Selamat Datang, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?>
                </div>
            </div>
        
        <div class="app-content">
            <?php if(!empty($error_msg)): ?>
                <div class="alert alert-error" style="font-size: 0.85rem;"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <?php if (count($all_devices) == 0): ?>
                <div class="card text-center" style="margin-top: 2rem;">
                    <h3 style="margin-bottom: 0.5rem;">Belum ada Alat</h3>
                    <p class="text-muted" style="font-size: 0.9rem; margin-bottom: 1.5rem;">Masukkan ID Device atau Code Pairing untuk mulai memantau.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-group">
                            <input type="text" name="device_id" class="form-control-outlined" placeholder="ID Device / Code Pairing" required>
                        </div>
                        <button type="submit" name="pair_device_first" class="btn btn-primary mt-2">Tambahkan</button>
                    </form>
                </div>
            <?php else: ?>
                <!-- Left Column: Utama -->
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    <!-- Status Alat (Grid) -->
                <div class="grid-2">
                    <?php 
                    $i = 1;
                    foreach($all_devices as $dev): 
                        $title = $dev['is_owner'] ? "Status Alat $i" : "Pairing motor (Pemilik: " . htmlspecialchars(explode(' ', $dev['owner_name'])[0]) . ")";
                    ?>
                    <div class="card" style="padding: 1.5rem; margin-bottom: 0; position:relative; text-align: center; display: flex; flex-direction: column; justify-content: space-between;">
                        <h3 style="margin-bottom: 1rem; font-size: <?php echo $dev['is_owner'] ? '1.1rem' : '0.95rem'; ?>; font-weight: 700; color: var(--primary);"><?php echo $title; ?></h3>
                        
                        <div id="safemode-badge-<?php echo htmlspecialchars($dev['device_id']); ?>" style="display:none; text-align:center; background:var(--danger); color:white; font-size:0.75rem; padding:4px 8px; border-radius:12px; margin-bottom:1rem; font-weight:bold; width: fit-content; margin-left: auto; margin-right: auto;">SAFE MODE AKTIF</div>
                        
                        <div style="display: flex; justify-content: space-around; align-items: center; margin-bottom: 1.5rem; background: #f8fafc; padding: 1rem; border-radius: 12px; border: 1px solid var(--border-color);">
                            <div style="flex: 1;">
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Koneksi Server</div>
                                <div id="conn-<?php echo htmlspecialchars($dev['device_id']); ?>" style="font-weight: 700; font-size: 1rem; color: var(--text-muted);">Menunggu...</div>
                            </div>
                            <div style="width: 1px; height: 35px; background: var(--border-color);"></div>
                            <div style="flex: 1;">
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.25rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Status Alat</div>
                                <div id="status-aktif-<?php echo htmlspecialchars($dev['device_id']); ?>" style="font-weight: 700; font-size: 1rem; color: var(--text-muted);">Offline</div>
                            </div>
                        </div>
                        
                        <button onclick="openModulModal('<?php echo htmlspecialchars($dev['device_id']); ?>')" class="btn btn-outline" style="width: 100%; padding: 0.75rem; border-radius: 12px; font-size: 0.9rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; border-color: var(--border-color); color: var(--text-main); font-weight: 600; background: white; transition: all 0.2s;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                            Cek Detail Modul & Sensor
                        </button>
                    </div>
                    <?php 
                    $i++;
                    endforeach; 
                    ?>
                </div>

                <!-- Pembacaan Realtime -->
                <div class="grid-2">
                    <?php 
                    $i = 1;
                    foreach($all_devices as $dev): 
                        $r = '-';
                        $p = '-';
                        $y = '-';
                        $loc = '-';
                        
                        $title = $dev['is_owner'] ? "Pembacaan Realtime Alat $i" : "Pembacaan Alat (Pemilik: " . htmlspecialchars(explode(' ', $dev['owner_name'])[0]) . ")";
                        $safe_id = htmlspecialchars($dev['device_id']);
                    ?>
                    <div class="card" style="padding: 1.5rem; text-align: center; margin-bottom: 0; position:relative; display: flex; flex-direction: column; justify-content: space-between;">
                        <span id="indicator-<?php echo $safe_id; ?>" style="position:absolute; right:15px; top:15px; width:10px; height:10px; background:gray; border-radius:50%; box-shadow: 0 0 5px rgba(0,0,0,0.1);"></span>
                        <h3 style="margin-bottom: 1rem; font-size: 1.1rem; font-weight: 700; color: var(--primary);"><?php echo $title; ?></h3>
                        
                        <div style="font-size: 0.75rem; color: var(--text-muted); text-align: left; background: #f8fafc; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.5rem; line-height: 1.6; border: 1px solid var(--border-color);">
                            <strong style="color: var(--text-main);">Panduan Membaca Sensor:</strong><br>
                            • <strong>Roll:</strong> Kemiringan motor ke Kiri/Kanan<br>
                            • <strong>Pitch:</strong> Kemiringan motor ke Depan/Belakang (Menanjak/Menurun)<br>
                            • <strong>Yaw:</strong> Arah rotasi atau hadap motor
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; margin-bottom: 1.5rem; font-size: 0.85rem; background: white; padding: 1rem; border-radius: 12px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                            <div style="flex: 1; border-right: 1px solid var(--border-color);">
                                <div style="color:var(--text-muted); margin-bottom:0.25rem; font-weight: 600;">ROLL</div>
                                <div id="roll-<?php echo $safe_id; ?>" style="font-weight: 700; font-size: 1.1rem; color: var(--text-main); margin-bottom: 0.25rem;"><?php echo $r; ?></div>
                                <div id="roll-stat-<?php echo $safe_id; ?>" style="font-size: 0.7rem; font-weight: 600; color: var(--primary); background: rgba(59,130,246,0.1); padding: 2px 6px; border-radius: 12px; display: inline-block;">Seimbang</div>
                            </div>
                            <div style="flex: 1; border-right: 1px solid var(--border-color);">
                                <div style="color:var(--text-muted); margin-bottom:0.25rem; font-weight: 600;">PITCH</div>
                                <div id="pitch-<?php echo $safe_id; ?>" style="font-weight: 700; font-size: 1.1rem; color: var(--text-main); margin-bottom: 0.25rem;"><?php echo $p; ?></div>
                                <div id="pitch-stat-<?php echo $safe_id; ?>" style="font-size: 0.7rem; font-weight: 600; color: var(--primary); background: rgba(59,130,246,0.1); padding: 2px 6px; border-radius: 12px; display: inline-block;">Datar</div>
                            </div>
                            <div style="flex: 1;">
                                <div style="color:var(--text-muted); margin-bottom:0.25rem; font-weight: 600;">YAW</div>
                                <div id="yaw-<?php echo $safe_id; ?>" style="font-weight: 700; font-size: 1.1rem; color: var(--text-main); margin-bottom: 0.25rem;"><?php echo $y; ?></div>
                                <div id="yaw-stat-<?php echo $safe_id; ?>" style="font-size: 0.7rem; font-weight: 600; color: var(--text-muted);">Arah Hadap</div>
                            </div>
                        </div>
                        <div style="font-size: 0.8rem; text-align: center; margin-top: 0.5rem; border-top: 1px solid #f1f5f9; padding-top: 0.75rem;">
                            <div style="color:var(--text-muted); margin-bottom:0.25rem;">Lokasi</div>
                            <div style="display: flex; justify-content: center; align-items: center; gap: 0.5rem;">
                                <span id="loc-<?php echo $safe_id; ?>" style="font-weight: 600; font-size: 0.85rem;"><?php echo $loc; ?></span>
                                <?php if ($loc !== '-'): ?>
                                <a id="mapbtn-<?php echo $safe_id; ?>" href="https://maps.google.com/?q=<?php echo $loc; ?>" target="_blank" class="btn btn-outline" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; display: flex; align-items: center; gap: 0.2rem; border-radius: 6px; border-color: var(--primary); color: var(--primary); width: auto;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                    Maps
                                </a>
                                <?php else: ?>
                                <a id="mapbtn-<?php echo $safe_id; ?>" href="#" target="_blank" class="btn btn-outline" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; display: none; align-items: center; gap: 0.2rem; border-radius: 6px; border-color: var(--primary); color: var(--primary); width: auto;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                    Maps
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php 
                    $i++;
                    endforeach; 
                    ?>
                </div>

                </div> <!-- End Status, Realtime -->
            <?php endif; ?>
        </div>
        </div> <!-- End content-area -->

        <!-- 3rd Column (Right Sidebar for Emergency Steps only on Desktop) -->
        <?php if (!empty($all_devices)): ?>
        <div class="right-sidebar">
            <h3 style="font-size: 1.05rem; margin-bottom: 0.75rem; color: var(--danger); text-align: left; display: flex; align-items: center; gap: 0.5rem;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Langkah Penanganan Kecelakaan
            </h3>
            <div style="font-size: 0.9rem; color: var(--text-main); line-height: 1.6;">
                <p style="margin-bottom: 0.5rem;">Jika Anda menerima indikator <strong>DARURAT</strong> di dashboard atau mendapat popup peringatan kecelakaan dari sistem, lakukan langkah berikut secara berurutan:</p>
                <ol style="margin-left: 1.25rem; font-weight: 500;">
                    <li>Tarik napas panjang dan usahakan jangan panik.</li>
                    <li>Cobalah menghubungi kontak pengendara secara langsung melalui nomor HP yang terdaftar.</li>
                    <li>Segera cek titik koordinat (Peta/Maps) lokasi terakhir pada kolom <strong>Pembacaan Realtime</strong> di kiri untuk mengetahui posisi pasti alat.</li>
                    <li>Jika pengendara tidak dapat dihubungi dan lokasi menunjukkan posisi statis cukup lama, bersiaplah untuk menghubungi pihak medis atau meluncur ke lokasi kejadian.</li>
                </ol>
            </div>
        </div>
        <?php endif; ?>

    </div> <!-- End app-container -->

    <!-- Modul Status Modals for each device -->
    <?php 
    if(!empty($all_devices)) {
        foreach($all_devices as $index => $dev): 
            $i = $index + 1;
    ?>
    <div id="modulModal-<?php echo htmlspecialchars($dev['device_id']); ?>" class="emergency-modal-overlay" style="display: none; align-items: center; justify-content: center; z-index: 1000; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(4px);">
        <div class="card" style="width: 90%; max-width: 400px; padding: 2rem; margin: auto; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);">
            <h3 style="margin-bottom: 1.5rem; text-align: center; color: var(--primary); font-size: 1.2rem; font-weight: 700;">Detail Modul Alat <?php echo $i; ?></h3>
            
            <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 2rem;">
                <div style="display:flex; justify-content:space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                    <span style="color: var(--text-main); font-size: 0.95rem;">MPU6050</span>
                    <span id="mpu-<?php echo htmlspecialchars($dev['device_id']); ?>" style="font-weight:700; color:var(--text-muted);">Menunggu...</span>
                </div>
                <div style="display:flex; justify-content:space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                    <span style="color: var(--text-main); font-size: 0.95rem;">NEO-6M GPS</span>
                    <span id="gps-<?php echo htmlspecialchars($dev['device_id']); ?>" style="font-weight:700; color:var(--text-muted);">Menunggu...</span>
                </div>
                <div style="display:flex; justify-content:space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                    <span style="color: var(--text-main); font-size: 0.95rem;">SIM800L</span>
                    <span id="sim-<?php echo htmlspecialchars($dev['device_id']); ?>" style="font-weight:700; color:var(--text-muted);">Menunggu...</span>
                </div>
                <div style="display:flex; justify-content:space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                    <span style="color: var(--text-main); font-size: 0.95rem;">SD Card</span>
                    <span id="sd-<?php echo htmlspecialchars($dev['device_id']); ?>" style="font-weight:700; color:var(--text-muted);">Menunggu...</span>
                </div>
                <div style="display:flex; justify-content:space-between; align-items: center; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem;">
                    <span style="color: var(--text-main); font-size: 0.95rem;">RTC Module</span>
                    <span id="rtc-<?php echo htmlspecialchars($dev['device_id']); ?>" style="font-weight:700; color:var(--text-muted);">Menunggu...</span>
                </div>
                <div style="display:flex; justify-content:space-between; align-items: center;">
                    <span style="color: var(--text-main); font-size: 0.95rem;">Kalibrasi MPU</span>
                    <span id="calib-<?php echo htmlspecialchars($dev['device_id']); ?>" style="font-weight:700; color:var(--text-muted); font-size:0.85rem;">-</span>
                </div>
            </div>
            
            <button onclick="closeModulModal('<?php echo htmlspecialchars($dev['device_id']); ?>')" class="btn btn-primary" style="width: 100%; border-radius: 12px; font-size:1rem; font-weight:600; padding:0.75rem;">Tutup</button>
        </div>
    </div>
    <?php 
        endforeach;
    } 
    ?>

    <!-- Emergency Modal -->
    <div id="emergencyModal" class="emergency-modal-overlay">
        <div class="emergency-modal">
            <div class="emergency-icon-pulse">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <h2 style="color: var(--danger); font-size: 1.5rem; margin-bottom: 1rem; font-weight: 700;">DARURAT KECELAKAAN!</h2>
            <p style="color: var(--text-main); font-size: 1rem; margin-bottom: 1.5rem; line-height: 1.5;" id="emergencyModalText">
                Indikasi kecelakaan terdeteksi. Harap segera periksa!
            </p>
            <button onclick="closeEmergencyModal()" class="btn btn-primary" style="width: 100%; padding: 1rem; font-size: 1rem; border-radius: 12px; background: var(--text-main);">Tutup Peringatan</button>
        </div>
    </div>

    <!-- MQTT WebSocket Script -->
    <?php if (count($all_devices) > 0): ?>
    <script>
        const devices = <?php echo json_encode(array_column($all_devices, 'device_id')); ?>;
        const brokerUrl = "broker.hivemq.com";
        const brokerPort = 8000;
        const clientId = "web_" + Math.random().toString(16).substr(2, 8);
        
        let alertShown = {};
        let deviceTimeouts = {};
        
        const client = new Paho.MQTT.Client(brokerUrl, brokerPort, "/mqtt", clientId);

        client.onConnectionLost = function(responseObject) {
            console.log("MQTT Connection Lost:", responseObject.errorMessage);
            devices.forEach(dev => {
                let connEl = document.getElementById('conn-' + dev);
                if (connEl) {
                    connEl.innerText = "Disconnected";
                    connEl.style.color = "var(--danger)";
                }
                let indEl = document.getElementById('indicator-' + dev);
                if (indEl) {
                    indEl.style.background = "gray";
                }
            });
            setTimeout(connectMQTT, 5000);
        };

        client.onMessageArrived = function(message) {
            try {
                let topicParts = message.destinationName.split('/');
                let topicType = topicParts[1]; // telemetry or status
                let devId = topicParts[topicParts.length - 1];
                let payload = JSON.parse(message.payloadString);
                
                // Device is sending data, so it must be Active and Connected to Server!
                let connEl = document.getElementById('conn-' + devId);
                let statusEl = document.getElementById('status-aktif-' + devId);
                
                if (connEl) {
                    connEl.innerText = "Terhubung";
                    connEl.style.color = "var(--success)";
                }
                if (statusEl) {
                    statusEl.innerText = "Aktif";
                    statusEl.style.color = "var(--success)";
                }
                
                // Reset the offline timer for this device (assuming 15 seconds timeout)
                if(deviceTimeouts[devId]) clearTimeout(deviceTimeouts[devId]);
                deviceTimeouts[devId] = setTimeout(() => {
                    let cEl = document.getElementById('conn-' + devId);
                    let sEl = document.getElementById('status-aktif-' + devId);
                    
                    if (cEl) {
                        cEl.innerText = "Terputus";
                        cEl.style.color = "var(--danger)";
                    }
                    if (sEl) {
                        sEl.innerText = "Offline";
                        sEl.style.color = "var(--text-muted)";
                    }
                    
                    // Reset nilai sensor karena offline
                    ['roll', 'pitch', 'yaw', 'accel'].forEach(sensor => {
                        let el = document.getElementById(sensor + '-' + devId);
                        if (el) el.innerText = "-";
                    });
                }, 15000); // Diubah dari 5000 ke 15000 agar tidak terputus saat kirim SMS
                
                if (topicType === 'telemetry') {
                    if (document.getElementById('roll-' + devId)) {
                        let rollVal = parseFloat(payload.roll);
                        let pitchVal = parseFloat(payload.pitch);
                        
                        document.getElementById('roll-' + devId).innerText = payload.roll + "°";
                        document.getElementById('pitch-' + devId).innerText = payload.pitch + "°";
                        
                        // Dynamic Roll Status
                        let rollStat = document.getElementById('roll-stat-' + devId);
                        if(rollStat) {
                            if(rollVal > 15) { rollStat.innerText = "Condong Kanan"; rollStat.style.color = "var(--danger)"; rollStat.style.background = "rgba(239,68,68,0.1)"; }
                            else if(rollVal < -15) { rollStat.innerText = "Condong Kiri"; rollStat.style.color = "var(--danger)"; rollStat.style.background = "rgba(239,68,68,0.1)"; }
                            else if(rollVal > 5) { rollStat.innerText = "Miring Kanan"; rollStat.style.color = "orange"; rollStat.style.background = "rgba(255,165,0,0.1)"; }
                            else if(rollVal < -5) { rollStat.innerText = "Miring Kiri"; rollStat.style.color = "orange"; rollStat.style.background = "rgba(255,165,0,0.1)"; }
                            else { rollStat.innerText = "Seimbang"; rollStat.style.color = "var(--success)"; rollStat.style.background = "rgba(16,185,129,0.1)"; }
                        }
                        
                        // Dynamic Pitch Status
                        let pitchStat = document.getElementById('pitch-stat-' + devId);
                        if(pitchStat) {
                            if(pitchVal > 15) { pitchStat.innerText = "Turun Tajam"; pitchStat.style.color = "var(--danger)"; pitchStat.style.background = "rgba(239,68,68,0.1)"; }
                            else if(pitchVal < -15) { pitchStat.innerText = "Naik Tajam"; pitchStat.style.color = "var(--danger)"; pitchStat.style.background = "rgba(239,68,68,0.1)"; }
                            else if(pitchVal > 5) { pitchStat.innerText = "Menanjak"; pitchStat.style.color = "orange"; pitchStat.style.background = "rgba(255,165,0,0.1)"; }
                            else if(pitchVal < -5) { pitchStat.innerText = "Menurun"; pitchStat.style.color = "orange"; pitchStat.style.background = "rgba(255,165,0,0.1)"; }
                            else { pitchStat.innerText = "Datar"; pitchStat.style.color = "var(--success)"; pitchStat.style.background = "rgba(16,185,129,0.1)"; }
                        }

                        if(payload.yaw !== undefined) {
                            document.getElementById('yaw-' + devId).innerText = payload.yaw + "°";
                        }
                        if(payload.lat !== undefined && payload.lon !== undefined) {
                            let locStr = payload.lat + "," + payload.lon;
                            document.getElementById('loc-' + devId).innerText = locStr;
                            
                            let mapBtn = document.getElementById('mapbtn-' + devId);
                            if(mapBtn) {
                                mapBtn.href = "https://maps.google.com/?q=" + locStr;
                                mapBtn.style.display = "flex"; // Show button if it was hidden
                            }
                        }
                        
                        if(payload.p_off !== undefined && payload.r_off !== undefined) {
                            let calibEl = document.getElementById('calib-' + devId);
                            if(calibEl) {
                                calibEl.innerText = "R: " + payload.r_off + "°, P: " + payload.p_off + "°";
                            }
                        }

                        // Popup Alert Logic
                        if(payload.status && payload.status.toLowerCase() === 'emergency') {
                            if(!alertShown[devId]) {
                                showEmergencyModal(devId);
                                alertShown[devId] = true;
                            }
                        } else {
                            // Reset alert if it goes back to safe
                            alertShown[devId] = false;
                        }

                        // Blink indicator to show active data reception
                        let ind = document.getElementById('indicator-' + devId);
                        if(ind) {
                            ind.style.background = "var(--success)";
                            setTimeout(() => { ind.style.background = "rgba(16, 185, 129, 0.3)"; }, 200);
                        }
                    }
                } else if (topicType === 'status') {
                    // Update Module Statuses
                    let updateStatus = (id, val, isCritical) => {
                        let el = document.getElementById(id);
                        if(el) {
                            if(val === 'ok') {
                                el.innerText = "OK";
                                el.style.color = "var(--success)";
                            } else {
                                el.innerText = "GAGAL";
                                el.style.color = isCritical ? "var(--danger)" : "orange";
                            }
                        }
                    };
                    
                    updateStatus('mpu-' + devId, payload.mpu, true);
                    updateStatus('gps-' + devId, payload.gps, true);
                    updateStatus('sim-' + devId, payload.sim, true);
                    updateStatus('sd-' + devId, payload.sd, false);
                    updateStatus('rtc-' + devId, payload.rtc, false);
                    
                    let badge = document.getElementById('safemode-badge-' + devId);
                    if(badge) {
                        badge.style.display = payload.safe_mode ? "block" : "none";
                    }
                }
            } catch(e) {
                console.error("Error parsing MQTT payload", e);
            }
        };

        function connectMQTT() {
            client.connect({
                onSuccess: function() {
                    console.log("MQTT Connected");
                    devices.forEach(dev => {
                        client.subscribe("motosafe/telemetry/" + dev);
                        client.subscribe("motosafe/status/" + dev);
                        let connEl = document.getElementById('conn-' + dev);
                        let statusEl = document.getElementById('status-aktif-' + dev);
                        
                        // Default to disconnected/offline until device actually sends data
                        if (connEl) {
                            connEl.innerText = "Terputus";
                            connEl.style.color = "var(--danger)";
                        }
                        if (statusEl) {
                            statusEl.innerText = "Offline";
                            statusEl.style.color = "var(--text-muted)";
                        }
                    });
                },
                useSSL: false // Set to true if broker supports WSS on 8884 and you are on HTTPS
            });
        }

        connectMQTT();

        // Polling from Database for Emergency Alerts
        setInterval(function() {
            fetch('api/check_emergency.php')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.emergencies) {
                    let currentEmergencies = data.emergencies.map(e => e.device_id);
                    
                    data.emergencies.forEach(em => {
                        let devId = em.device_id;
                        
                        // Show modal if not shown
                        if(!alertShown[devId]) {
                            showEmergencyModal(devId, em.recorded_time);
                            alertShown[devId] = true;
                        } else {
                            if (currentEmergencyDeviceId === devId) {
                                let btn = document.getElementById('historyFocusBtn');
                                if(btn) btn.setAttribute('onclick', `markReadAndGo('${devId}', '&accident_time=${em.recorded_time}')`);
                            }
                        }
                        
                        // Update location if available
                        if(document.getElementById('loc-' + devId) && em.lat && em.lon) {
                            let locStr = em.lat + "," + em.lon;
                            document.getElementById('loc-' + devId).innerText = locStr;
                            let mapBtn = document.getElementById('mapbtn-' + devId);
                            if(mapBtn) {
                                mapBtn.href = "https://maps.google.com/?q=" + locStr;
                                mapBtn.style.display = "flex";
                            }
                        }
                    });
                    
                    // Reset alert flag for devices no longer in emergency
                    devices.forEach(dev => {
                        if (!currentEmergencies.includes(dev) && document.getElementById('status-aktif-' + dev)?.innerText !== 'Aktif') {
                            // Resetting so it can trigger again next time
                            alertShown[dev] = false;
                        }
                    });
                }
            })
            .catch(err => console.error('Error polling DB for emergency:', err));
        }, 5000);

        function openModulModal(devId) {
            let modal = document.getElementById('modulModal-' + devId);
            if (modal) {
                modal.style.display = 'flex';
                // slight delay for transition
                setTimeout(() => { modal.classList.add('show'); }, 10);
            }
        }

        function closeModulModal(devId) {
            let modal = document.getElementById('modulModal-' + devId);
            if (modal) {
                modal.classList.remove('show');
                setTimeout(() => { modal.style.display = 'none'; }, 300);
            }
        }

        function checkData(devId) {
            let dateInput = document.getElementById('date-picker-' + devId).value;
            let resDiv = document.getElementById('result-' + devId);
            let datesArray = window['availableDates_' + devId];
            
            if(!dateInput) {
                resDiv.innerHTML = '<div style="color:var(--danger); font-size: 0.85rem; font-weight: 600;">Harap pilih tanggal terlebih dahulu.</div>';
                return;
            }
            
            if(datesArray && datesArray.includes(dateInput)) {
                // Split for display dd-mm-yyyy
                let dParts = dateInput.split('-');
                let dShow = dParts[2] + '-' + dParts[1] + '-' + dParts[0];
                
                resDiv.innerHTML = `
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1rem; border-radius: 12px; width: 100%;">
                        <div style="color: var(--success); font-weight: 700; margin-bottom: 0.75rem; font-size: 0.95rem;">Data Perjalanan ${dShow} Ditemukan!</div>
                        <a href="trip_detail.php?device=${encodeURIComponent(devId)}&date=${dateInput}" class="btn btn-success" style="padding: 0.6rem 1rem; font-size: 0.85rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 0.5rem; justify-content: center; width: 100%;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            Lihat Log Detail
                        </a>
                    </div>
                `;
            } else {
                resDiv.innerHTML = `
                    <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 1rem; border-radius: 12px; width: 100%;">
                        <div style="color: var(--danger); font-weight: 600; font-size: 0.9rem;">Tidak ada rekaman data pada tanggal tersebut.</div>
                    </div>
                `;
            }
        }

        let currentEmergencyDeviceId = null;

        function showEmergencyModal(deviceId, accidentTime = null) {
            currentEmergencyDeviceId = deviceId;
            let timeParam = '';
            if (accidentTime) {
                timeParam = `&accident_time=${accidentTime}`;
            } else {
                let now = new Date();
                let year = now.getFullYear();
                let month = String(now.getMonth() + 1).padStart(2, '0');
                let day = String(now.getDate()).padStart(2, '0');
                timeParam = `&date=${year}-${month}-${day}`;
            }
            
            document.getElementById('emergencyModalText').innerHTML = 
                "Alat <strong>" + deviceId + "</strong> mendeteksi indikasi kecelakaan!<br><br>" +
                "<div style='text-align: left; background: rgba(239,68,68,0.05); padding: 1rem; border-radius: 8px; border-left: 4px solid var(--danger); font-size: 0.9rem;'>" +
                "<strong style='display:block; margin-bottom: 0.5rem;'>Langkah Penanganan:</strong>" +
                "<ol style='margin-left: 1.25rem; line-height: 1.6;'>" +
                "<li>Tarik napas panjang dan jangan panik.</li>" +
                "<li>Segera hubungi pengguna alat atau kontak darurat terkait.</li>" +
                "<li>Cek titik koordinat lokasi terakhir pada peta di bawah.</li>" +
                "</ol>" +
                "</div><br>" +
                `<a id='historyFocusBtn' href='javascript:void(0)' onclick='markReadAndGo("${deviceId}", "${timeParam}")' class='btn btn-danger' style='display:block; text-align:center; padding: 1rem; border-radius: 12px; margin-bottom: 0.5rem; text-decoration:none;'>Lihat Riwayat (Fokus 10 Menit)</a>`;
                
            let modal = document.getElementById('emergencyModal');
            modal.style.display = 'flex';
            // slight delay to allow display:flex to apply before adding class for transition
            setTimeout(() => { modal.classList.add('show'); }, 10);
        }

        function closeEmergencyModal() {
            if (currentEmergencyDeviceId) {
                fetch('api/mark_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ device_id: currentEmergencyDeviceId })
                }).catch(e => console.error(e));
                currentEmergencyDeviceId = null;
            }
            
            let modal = document.getElementById('emergencyModal');
            modal.classList.remove('show');
            setTimeout(() => { modal.style.display = 'none'; }, 300);
        }

        function markReadAndGo(deviceId, timeParam) {
            if (currentEmergencyDeviceId) {
                fetch('api/mark_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ device_id: currentEmergencyDeviceId })
                }).then(() => {
                    window.location.href = 'trip_detail.php?device=' + deviceId + timeParam;
                }).catch(e => {
                    console.error(e);
                    window.location.href = 'trip_detail.php?device=' + deviceId + timeParam;
                });
            } else {
                window.location.href = 'trip_detail.php?device=' + deviceId + timeParam;
            }
        }
    </script>
    <?php endif; ?>
    <div class="mobile-nav">
        <a href="dashboard.php" class="active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            Dashboard
        </a>
        <a href="riwayat.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Riwayat
        </a>
        <a href="profile.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Profil
        </a>
    </div>
</body>
</html>
