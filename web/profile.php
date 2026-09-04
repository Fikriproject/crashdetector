<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$error_msg = '';
$success_msg = '';
$csrf_token = generate_csrf_token();

if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'deleted') {
        $success_msg = "Perangkat berhasil dihapus dari akun Anda.";
    } else if ($_GET['msg'] == 'paired') {
        $pairing_success = true;
        $success_msg = "Berhasil menambahkan alat baru.";
    } else if ($_GET['msg'] == 'emergency') {
        $pairing_success = true;
        $success_msg = "Berhasil terhubung sebagai Kontak Darurat!";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }
}

$stmt_user = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$user_info = $stmt_user->get_result()->fetch_assoc();

// Update Profile
if (isset($_POST['update_profile'])) {
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $address = $conn->real_escape_string($_POST['address']);

    $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=?, address=? WHERE id=?");
    $stmt->bind_param("ssssi", $full_name, $email, $phone, $address, $user_id);
    $stmt->execute();
    
    $stmt_sync = $conn->prepare("UPDATE pairing SET emergency_contact_name=?, emergency_contact_phone=? WHERE user_id=?");
    $stmt_sync->bind_param("ssi", $full_name, $phone, $user_id);
    $stmt_sync->execute();
    
    $_SESSION['full_name'] = $full_name;
    $success_msg = "Profil berhasil diperbarui.";
    
    $stmt_user->execute();
    $user_info = $stmt_user->get_result()->fetch_assoc();
}

// Add Device / Pairing Logic
if (isset($_POST['add_device'])) {
    $input_id = $conn->real_escape_string($_POST['new_device_id']);
    
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
                    $pairing_success = true;
                    $success_msg = "Berhasil terhubung sebagai Kontak Darurat!";
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
        // Device ID / MAC address
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
                $pairing_success = true;
                $success_msg = "Berhasil menambahkan alat baru.";
            } else if ($dev['owner_id'] != $user_id) {
                $error_msg = "Alat ini sudah memiliki pemilik. Gunakan Code Pairing.";
            }
        } else {
            $stmt_ins2 = $conn->prepare("INSERT INTO device (device_id, owner_id, status) VALUES (?, ?, 'active')");
            $stmt_ins2->bind_param("si", $device_id, $user_id);
            $stmt_ins2->execute();
            $pairing_success = true;
            $success_msg = "Berhasil mendaftarkan alat baru.";
        }
    }
}

// Fetch owned and followed devices
$stmt_owned = $conn->prepare("SELECT * FROM device WHERE owner_id = ?");
$stmt_owned->bind_param("i", $user_id);
$stmt_owned->execute();
$owned_devices_res = $stmt_owned->get_result();
$owned_devices = [];
$all_devices = [];
$has_owned_device = false;
while($row = $owned_devices_res->fetch_assoc()) {
    $owned_devices[] = $row['device_id'];
    $all_devices[] = $row['device_id'];
    $has_owned_device = true;
}

$stmt_followed = $conn->prepare("SELECT device_id FROM device_viewers WHERE user_id = ?");
$stmt_followed->bind_param("i", $user_id);
$stmt_followed->execute();
$followed_devices_res = $stmt_followed->get_result();
while($row = $followed_devices_res->fetch_assoc()) $all_devices[] = $row['device_id'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRASH DETEKTOR - Profile</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

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
                <a href="dashboard.php" class="back-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    Back
                </a>
                <div class="app-title" style="text-align: right;">User Profile</div>
            </div>
            
            <!-- Header Desktop Tambahan (Opsional) -->
            <div class="app-header desktop-header flex-row" style="border:none; display:none;">
                <h2 style="font-size: 1.5rem; font-weight: 700;">User Profile</h2>
            </div>
        
        <div class="app-content">
            <div style="max-width: 1100px; margin: 0 auto; padding-top: 1rem;">
                <?php if(!empty($error_msg)): ?>
                    <div class="alert alert-error" style="font-size: 0.85rem; margin-bottom: 1rem;"><?php echo htmlspecialchars($error_msg); ?></div>
                <?php endif; ?>
                <?php if(!empty($success_msg)): ?>
                    <div class="alert alert-success" style="font-size: 0.85rem; margin-bottom: 1rem;"><?php echo htmlspecialchars($success_msg); ?></div>
                <?php endif; ?>

                <div class="grid-2" style="align-items: start; gap: 1.5rem;">
                    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                        <div class="card" style="margin-bottom: 0;">
                            <div class="user-icon large" style="margin: 0 auto 1.5rem auto;">
                                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                            
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                                    <div>
                                        <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Nama Lengkap</label>
                                        <input type="text" name="full_name" class="form-control-outlined" style="background: #f8fafc;" value="<?php echo htmlspecialchars($user_info['full_name'] ?? ''); ?>" required>
                                    </div>
                                    <div>
                                        <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Email</label>
                                        <input type="email" name="email" class="form-control-outlined" style="background: #f8fafc;" value="<?php echo htmlspecialchars($user_info['email'] ?? ''); ?>" required>
                                    </div>
                                    <div>
                                        <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">No HP</label>
                                        <input type="text" name="phone" class="form-control-outlined" style="background: #f8fafc;" value="<?php echo htmlspecialchars($user_info['phone'] ?? ''); ?>" required>
                                    </div>
                                    <div>
                                        <label style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Alamat</label>
                                        <input type="text" name="address" class="form-control-outlined" style="background: #f8fafc;" value="<?php echo htmlspecialchars($user_info['address'] ?? ''); ?>" required>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 1rem;">
                                    <button type="submit" name="update_profile" class="btn btn-primary" style="flex: 2; padding: 0.85rem; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(59,130,246,0.2);">Update Profile</button>
                                    <a href="logout.php" class="btn btn-danger" style="flex: 1; padding: 0.85rem; border-radius: 12px; display: flex; justify-content: center; align-items: center;">Logout</a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Right Column: Pengaturan Alat & Daftar Pairing -->
                    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                        <div class="card" style="margin-bottom: 0;">
                            <h3 style="font-size: 1.05rem; margin-bottom: 1.25rem; color: var(--text-main); border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">Manajemen Alat</h3>
                            <div style="display: flex; flex-direction: column; gap: 1rem;">
                                <?php if(!empty($owned_devices)): ?>
                                    <?php foreach($owned_devices as $dev): ?>
                                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; background-color: #f8fafc; border: 1px solid var(--border-color); border-radius: 12px; transition: all 0.2s;">
                                            <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">ID Perangkat</span>
                                                <span style="font-weight: 700; font-size: 1rem; color: var(--text-main);"><?php echo $dev; ?></span>
                                            </div>
                                            <a href="device_settings.php?device_id=<?php echo urlencode($dev); ?>" class="btn btn-primary" style="width: auto; padding: 0.6rem 1.2rem; font-size: 0.85rem; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.2);">Pengaturan</a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="padding: 1.5rem; text-align: center; background-color: #f8fafc; border-radius: 12px; font-size: 0.9rem; color: var(--text-muted); border: 1px dashed var(--border-color);">
                                        Belum ada alat yang Anda miliki.
                                    </div>
                                <?php endif; ?>
                                
                                <div style="display: flex; gap: 1rem; margin-top: 0.5rem;">
                                    <!-- Tambah Alat Button toggles the Add Form -->
                                    <button type="button" onclick="$('#add_device_form').slideToggle();" class="btn btn-outline" style="flex: 1; padding: 0.75rem; border-radius: 10px; border-color: var(--border-color); color: var(--text-muted); font-size: 0.9rem;">+ Tambah Alat</button>
                                    <?php if($has_owned_device): ?>
                                    <a href="pairing.php" class="btn btn-outline" style="flex: 1; padding: 0.75rem; border-radius: 10px; border-color: var(--border-color); color: var(--text-muted); font-size: 0.9rem; display: flex; align-items: center; justify-content: center;">+ Kontak Darurat</a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Hidden Add Device / Pairing Form -->
                            <form id="add_device_form" method="POST" style="display: none; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                <h4 style="font-size: 0.85rem; margin-bottom: 0.5rem; color: var(--text-muted);">Masukkan ID Device / Code Pairing</h4>
                                <div class="form-group" style="margin-bottom: 0.5rem;">
                                    <input type="text" name="new_device_id" class="form-control-outlined" placeholder="ID/Code" required>
                                </div>
                                <button type="submit" name="add_device" class="btn btn-primary" style="padding: 0.5rem; font-size: 0.85rem; border-radius: 8px;">Simpan Alat</button>
                            </form>
                        </div>
                        
                        <div class="card" style="margin-bottom: 0;">
                            <h3 style="font-size: 1rem; margin-bottom: 0.5rem; color: var(--primary);">Daftar Pairing Aktif</h3>
                            <div style="font-weight: 600; font-size: 0.85rem; color: var(--text-muted);">
                                <?php echo !empty($all_devices) ? implode(', ', $all_devices) : 'Belum ada perangkat yang ditautkan.'; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Grafik Penggunaan - Lebar ke Samping -->
                <div class="card" style="margin-top: 1.5rem; margin-bottom: 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <h3 style="font-size: 1rem; margin: 0; color: var(--primary);">Grafik Penggunaan</h3>
                        <div style="display: flex; gap: 0.5rem;">
                            <select id="chartMonth" class="form-control-outlined" style="padding: 0.25rem 0.5rem; font-size: 0.8rem; width: auto;" onchange="loadChartData()">
                                <?php 
                                $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                                $currMonth = date('n');
                                foreach($months as $idx => $m) {
                                    $val = $idx + 1;
                                    $sel = ($val == $currMonth) ? 'selected' : '';
                                    echo "<option value='$val' $sel>$m</option>";
                                }
                                ?>
                            </select>
                            <select id="chartYear" class="form-control-outlined" style="padding: 0.25rem 0.5rem; font-size: 0.8rem; width: auto;" onchange="loadChartData()">
                                <?php 
                                $currYear = date('Y');
                                for($y = $currYear; $y >= $currYear - 3; $y--) {
                                    echo "<option value='$y'>$y</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div style="height: 100px; width: 100%;">
                        <canvas id="usageChart"></canvas>
                    </div>
                </div>
                
                <div class="text-center text-muted" style="font-size: 0.8rem; margin-top: 1rem;">
                    APP V1.0.0.0
                </div>
            </div>
        </div>
        </div> <!-- End content-area -->
    </div>
    
    <script>
        let usageChart;
        const ctx = document.getElementById('usageChart').getContext('2d');
        
        function initChart(labels, data) {
            if (usageChart) {
                usageChart.destroy();
            }
            usageChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Lama Penggunaan',
                        data: data,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        tension: 0.3,
                        fill: true,
                        pointRadius: 2,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let mins = context.parsed.y || 0;
                                    let h = Math.floor(mins / 60);
                                    let m = mins % 60;
                                    let timeStr = "";
                                    if (h > 0) timeStr += h + " jam ";
                                    timeStr += m + " menit";
                                    return ' ' + timeStr;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { 
                            display: true,
                            grid: { display: false },
                            ticks: { font: { size: 10 } }
                        },
                        y: { 
                            display: false, // hide y axis labels to save space
                            beginAtZero: true 
                        }
                    },
                    layout: {
                        padding: 0
                    }
                }
            });
        }

        function loadChartData() {
            let m = document.getElementById('chartMonth').value;
            let y = document.getElementById('chartYear').value;
            fetch('api/get_chart_data.php?month=' + m + '&year=' + y)
                .then(res => res.json())
                .then(res => {
                    initChart(res.labels, res.data);
                })
                .catch(err => console.error(err));
        }

        // Initial load
        loadChartData();
    </script>
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

    <?php if(isset($pairing_success) && $pairing_success): ?>
    <div class="emergency-modal-overlay" style="display: flex; opacity: 1; z-index: 999999; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.7); backdrop-filter: blur(5px); align-items: center; justify-content: center;">
        <div class="emergency-modal" style="background: #ffffff; border-radius: 24px; padding: 2.5rem 2rem; max-width: 400px; width: 90%; text-align: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); transform: scale(1);">
            <div class="emergency-icon-pulse" style="width: 80px; height: 80px; background: rgba(16, 185, 129, 0.1); color: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem auto; box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); animation: pulseSuccess 2s infinite;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h2 style="color: #10b981; font-size: 1.5rem; margin-bottom: 0.5rem; font-weight: 800;">Penautan Berhasil!</h2>
            <p style="color: #475569; font-size: 0.95rem; margin-bottom: 0;">Memuat ulang halaman...</p>
        </div>
    </div>
    
    <style>
        @keyframes pulseSuccess {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
            70% { box-shadow: 0 0 0 20px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
    </style>

    <script>
        setTimeout(function() {
            window.location.href = 'profile.php';
        }, 2500);
    </script>
    <?php endif; ?>
</body>
</html>
