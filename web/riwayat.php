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
    <title>CRASH DETEKTOR - Riwayat Perjalanan</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
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
                <a href="riwayat.php" class="sidebar-link active">
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
                <h2 style="font-size: 1.5rem; font-weight: 700;">Riwayat Perjalanan</h2>
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
                <!-- Main Column -->
                <div style="display: flex; flex-direction: column; gap: 1rem; max-width: 600px; margin: 0 auto;">
                    
                    <?php if(count($all_devices) > 1): ?>
                    <div style="margin-bottom: 1.5rem; text-align: center;">
                        <h3 style="margin-bottom: 0.5rem; color: var(--text-main); font-size: 1rem;">Pilih Alat yang akan dicek:</h3>
                        <select id="deviceSelect" onchange="showDeviceHistory(this.value)" class="form-control-outlined" style="max-width: 300px; display: inline-block; text-align: center; font-weight: 600;">
                            <?php foreach($all_devices as $dev): ?>
                                <option value="<?php echo htmlspecialchars($dev['device_id']); ?>"><?php echo htmlspecialchars($dev['device_id']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div>
                        <?php 
                        $i = 1;
                        foreach($all_devices as $dev): 
                            $dev_logs = $conn->query("SELECT DATE(recorded_time) as trip_date FROM log_perjalanan WHERE device_id = '{$dev['device_id']}' GROUP BY DATE(recorded_time) ORDER BY trip_date DESC");
                            
                            $available_dates = [];
                            while($log = $dev_logs->fetch_assoc()) {
                                $available_dates[] = $log['trip_date'];
                            }
                            
                            $title = $dev['is_owner'] ? "Alat $i ({$dev['device_id']})" : "Alat Pemilik: " . htmlspecialchars(explode(' ', $dev['owner_name'])[0]);
                            $safe_id = htmlspecialchars($dev['device_id']);
                        ?>
                        <div class="card hist-card" id="hist-card-<?php echo $safe_id; ?>" style="padding: 1.5rem; margin-bottom: 0;">
                            <script>
                                window['availableDates_<?php echo $safe_id; ?>'] = <?php echo json_encode($available_dates); ?>;
                            </script>
                            
                            <h3 style="margin-bottom: 0.5rem; font-size: 1rem; font-weight: 700; color: var(--primary); text-align: center;"><?php echo $title; ?></h3>
                            <div style="font-size: 0.8rem; text-align: center; color: var(--text-muted); margin-bottom: 1.5rem;">Pilih tanggal untuk melihat log perjalanan</div>
                            
                            <div style="display: flex; gap: 0.5rem; justify-content: center; margin-bottom: 2rem;">
                                <select id="day-picker-<?php echo $safe_id; ?>" onchange="checkData('<?php echo $safe_id; ?>')" class="form-control-outlined" style="max-width: 80px; padding: 0.75rem 0.5rem; font-size: 0.9rem; border-radius: 8px;">
                                    <option value="">Tgl</option>
                                    <?php for($d=1; $d<=31; $d++): $dd = str_pad($d, 2, '0', STR_PAD_LEFT); ?>
                                        <option value="<?php echo $dd; ?>"><?php echo $dd; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <select id="month-picker-<?php echo $safe_id; ?>" onchange="checkData('<?php echo $safe_id; ?>')" class="form-control-outlined" style="max-width: 130px; padding: 0.75rem 0.5rem; font-size: 0.9rem; border-radius: 8px;">
                                    <option value="">Bulan</option>
                                    <option value="01">Januari</option>
                                    <option value="02">Februari</option>
                                    <option value="03">Maret</option>
                                    <option value="04">April</option>
                                    <option value="05">Mei</option>
                                    <option value="06">Juni</option>
                                    <option value="07">Juli</option>
                                    <option value="08">Agustus</option>
                                    <option value="09">September</option>
                                    <option value="10">Oktober</option>
                                    <option value="11">November</option>
                                    <option value="12">Desember</option>
                                </select>
                                <select id="year-picker-<?php echo $safe_id; ?>" onchange="checkData('<?php echo $safe_id; ?>')" class="form-control-outlined" style="max-width: 100px; padding: 0.75rem 0.5rem; font-size: 0.9rem; border-radius: 8px;">
                                    <option value="">Tahun</option>
                                    <?php 
                                    $currentYear = date('Y');
                                    for($y = $currentYear; $y >= $currentYear - 2; $y--): ?>
                                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div id="result-<?php echo $safe_id; ?>" style="text-align: center; min-height: 80px; display: flex; align-items: center; justify-content: center;">
                                <?php if(empty($available_dates)): ?>
                                    <div style="color:var(--text-muted); font-size: 0.85rem;">Belum ada riwayat terekam di alat ini.</div>
                                <?php else: ?>
                                    <div style="color:var(--text-muted); font-size: 0.85rem;">Terdapat <?php echo count($available_dates); ?> hari dengan catatan perjalanan.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php 
                        $i++;
                        endforeach; 
                        ?>
                    </div>
                </div>

                </div> <!-- End Status, Realtime, History list -->
            <?php endif; ?>
        </div>
        </div> <!-- End content-area -->

    <?php if (count($all_devices) > 0): ?>
    <script>
        function showDeviceHistory(devId) {
            document.querySelectorAll('.hist-card').forEach(el => el.style.display = 'none');
            let targetCard = document.getElementById('hist-card-' + devId);
            if(targetCard) targetCard.style.display = 'block';
        }

        // Tampilkan card pertama secara default
        let firstDev = document.querySelector('#deviceSelect') ? document.querySelector('#deviceSelect').value : '<?php echo htmlspecialchars($all_devices[0]['device_id']); ?>';
        showDeviceHistory(firstDev);

        function checkData(devId) {
            let day = document.getElementById('day-picker-' + devId).value;
            let month = document.getElementById('month-picker-' + devId).value;
            let year = document.getElementById('year-picker-' + devId).value;
            let resDiv = document.getElementById('result-' + devId);
            let datesArray = window['availableDates_' + devId];
            
            if(!day || !month || !year) {
                let msg = datesArray.length === 0 ? 'Belum ada riwayat terekam di alat ini.' : 'Terdapat ' + datesArray.length + ' hari dengan catatan perjalanan.';
                resDiv.innerHTML = '<div style="color:var(--text-muted); font-size: 0.85rem;">' + msg + '</div>';
                return;
            }
            
            let dateInput = year + '-' + month + '-' + day;
            
            if(datesArray && datesArray.includes(dateInput)) {
                let dParts = dateInput.split('-');
                let dShow = dParts[2] + '-' + dParts[1] + '-' + dParts[0];
                
                resDiv.innerHTML = `
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1.5rem; border-radius: 12px; width: 100%;">
                        <div style="color: var(--success); font-weight: 700; margin-bottom: 1rem; font-size: 1.05rem;">Data Perjalanan ${dShow} Ditemukan!</div>
                        <a href="trip_detail.php?device=${encodeURIComponent(devId)}&date=${dateInput}" class="btn btn-success" style="padding: 0.75rem 1.5rem; font-size: 1rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 0.5rem; justify-content: center; width: auto; font-weight: 600;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            Lihat Log Detail Perjalanan
                        </a>
                    </div>
                `;
            } else {
                resDiv.innerHTML = `
                    <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 1.5rem; border-radius: 12px; width: 100%;">
                        <div style="color: var(--danger); font-weight: 600; font-size: 1rem;">Tidak ada rekaman data pada tanggal tersebut.</div>
                    </div>
                `;
            }
        }
    </script>
    <?php endif; ?>
    <div class="mobile-nav">
        <a href="dashboard.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            Dashboard
        </a>
        <a href="riwayat.php" class="active">
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
