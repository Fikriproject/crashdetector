<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success_msg = '';

$csrf_token = generate_csrf_token();

// Check CSRF for any POST request
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }
}


// Get user's first owned device with remaining seconds calculated by MySQL
$stmt = $conn->prepare("SELECT *, UNIX_TIMESTAMP(pairing_code_expires_at) - UNIX_TIMESTAMP(NOW()) as remaining_sec FROM device WHERE owner_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$owned_device = $stmt->get_result()->fetch_assoc();

// Generate Pairing Code
if (isset($_POST['generate_code']) && $owned_device) {
    $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
    // Set expiry to 1 minute from now
    $stmt = $conn->prepare("UPDATE device SET pairing_code = ?, pairing_code_expires_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE id = ?");
    $stmt->bind_param("si", $code, $owned_device['id']);
    $stmt->execute();
    
    // Refresh device data
    $stmt = $conn->prepare("SELECT *, UNIX_TIMESTAMP(pairing_code_expires_at) - UNIX_TIMESTAMP(NOW()) as remaining_sec FROM device WHERE owner_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $owned_device = $stmt->get_result()->fetch_assoc();
    
    $success_msg = "Code generated successfully. Valid for 1 minute.";
}

// Remove Pairing User
if (isset($_POST['remove_viewer']) && $owned_device) {
    $viewer_id = (int)$_POST['viewer_id'];
    mysqli_begin_transaction($conn);
    try {
        $stmt_p = $conn->prepare("DELETE FROM pairing WHERE device_id = ? AND user_id = ?");
        $stmt_p->bind_param("si", $owned_device['device_id'], $viewer_id);
        $stmt_p->execute();
        
        $stmt_d = $conn->prepare("DELETE FROM device_viewers WHERE device_id = ? AND user_id = ?");
        $stmt_d->bind_param("si", $owned_device['device_id'], $viewer_id);
        $stmt_d->execute();
        
        mysqli_commit($conn);
        $success_msg = "Kontak Darurat berhasil dihapus.";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error_msg = "Gagal menghapus kontak.";
    }
}

// Fetch users paired with this device (pairing)
$paired_users = [];
if ($owned_device) {
    $stmt = $conn->prepare("
        SELECT u.id as viewer_id, u.full_name, u.email, u.phone, u.address 
        FROM pairing p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.device_id = ?
    ");
    $stmt->bind_param("s", $owned_device['device_id']);
    $stmt->execute();
    $viewers_res = $stmt->get_result();
    while($row = $viewers_res->fetch_assoc()) {
        $paired_users[] = $row;
    }
}

// Prepare remaining seconds for JS
$remaining_seconds = 0;
if ($owned_device && !empty($owned_device['pairing_code']) && isset($owned_device['remaining_sec'])) {
    $remaining_seconds = (int)$owned_device['remaining_sec'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRASH DETEKTOR - Pairing User</title>
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
                    Back
                </a>
                <div class="app-title" style="text-align: right;">Pairing User</div>
            </div>
            
            <!-- Header Desktop Tambahan (Opsional) -->
            <div class="app-header desktop-header flex-row" style="border:none; display:none;">
                <h2 style="font-size: 1.5rem; font-weight: 700;">Pairing User</h2>
            </div>
        
        <div class="app-content">
            <div style="max-width: 900px; margin: 0 auto;">
                <?php if(!empty($success_msg)): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
                <?php endif; ?>
                
                <?php if(!$owned_device): ?>
                    <div class="alert alert-error">Anda belum memiliki Alat. Tambahkan alat di Dashboard.</div>
                <?php else: ?>
                    <form method="POST" class="card" style="margin-bottom: 2rem; padding: 1.5rem;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <h3 style="font-size: 1rem; margin-bottom: 1rem; color: var(--primary);">Generate Code Pairing</h3>
                        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                            <input type="text" id="pairing_code_input" class="form-control-outlined" style="flex: 1; padding: 0.75rem; text-align: center; font-weight: bold; letter-spacing: 3px; font-size: 1.2rem; background: #f9fafb;" readonly value="<?php echo htmlspecialchars($owned_device['pairing_code'] ?? ''); ?>" placeholder="----">
                            <button type="submit" name="generate_code" class="btn btn-primary" style="width: auto; padding: 0.75rem 1.5rem; border-radius: 8px;">Generate</button>
                        </div>
                        <?php if($remaining_seconds > 0): ?>
                        <div class="text-center text-muted mt-2" style="font-size: 0.85rem;" id="countdown_container">
                            Kode berlaku dalam: <strong id="countdown_timer" style="color: var(--danger);">01:00</strong>
                        </div>
                        <?php endif; ?>
                    </form>
                    
                    <h3 style="font-size: 1.1rem; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid var(--border-color);">Daftar Kontak Darurat</h3>
                    
                    <?php if(count($paired_users) == 0): ?>
                        <div class="card text-center" style="padding: 2rem;">
                            <p class="text-muted" style="font-size: 0.95rem;">Belum ada user yang terhubung dengan perangkat Anda.</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="grid-2" style="align-items: start;">
                    <?php 
                    $i = 1;
                    foreach($paired_users as $user): 
                    ?>
                    <div class="card" style="padding: 1.25rem; margin-bottom: 0;">
                        <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
                            <div class="user-icon" style="width: 40px; height: 40px; background: rgba(37, 99, 235, 0.1); color: var(--primary);">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                            <h4 style="font-size: 1rem; font-weight: 700; flex: 1; color: var(--text-main);">Kontak <?php echo $i; ?></h4>
                            <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus kontak darurat ini?');" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="viewer_id" value="<?php echo $user['viewer_id']; ?>">
                                <button type="submit" name="remove_viewer" class="btn btn-outline" style="border-color: var(--danger); color: var(--danger); padding: 0.3rem 0.75rem; font-size: 0.8rem; border-radius: 6px; width: auto; font-weight: 600;">Hapus</button>
                            </form>
                        </div>
                        
                        <div class="form-group mb-2">
                            <label>Nama Lengkap</label>
                            <input type="text" class="form-control-outlined" readonly value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" style="background: #f9fafb; border-color: transparent;">
                        </div>
                        <div class="form-group mb-2">
                            <label>Email</label>
                            <input type="text" class="form-control-outlined" readonly value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" style="background: #f9fafb; border-color: transparent;">
                        </div>
                        <div class="form-group mb-2">
                            <label>Nomor HP</label>
                            <input type="text" class="form-control-outlined" readonly value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" style="background: #f9fafb; border-color: transparent;">
                        </div>
                        <div class="form-group mb-1">
                            <label>Alamat</label>
                            <input type="text" class="form-control-outlined" readonly value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>" style="background: #f9fafb; border-color: transparent;">
                        </div>
                    </div>
                    <?php 
                    $i++;
                    endforeach; 
                    ?>
                    </div>
                <?php endif; ?>
                
                <div class="text-center text-muted mt-4" style="font-size: 0.8rem; margin-top: auto; padding-top: 2rem; padding-bottom: 2rem;">
                    APP V1.0.0.0
                </div>
            </div>
        </div>
        </div> <!-- End content-area -->
    </div>

    <script>
        // Start remaining seconds provided perfectly from MySQL time delta
        let remaining = <?php echo max(0, $remaining_seconds); ?>;
        let deviceId = "<?php echo $owned_device ? htmlspecialchars($owned_device['device_id']) : ''; ?>";
        let hasCode = <?php echo ($remaining_seconds > 0) ? 'true' : 'false'; ?>;
        
        let timerInterval, pollInterval;

        function updateTimer() {
            if (!hasCode) return;

            remaining--;
            
            if (remaining <= 0) {
                $('#countdown_timer').text("Kedaluwarsa");
                $('#pairing_code_input').val(''); // Clear the code visually
                clearInterval(timerInterval);
                clearInterval(pollInterval);
                return;
            }

            let mins = Math.floor(remaining / 60);
            let secs = remaining % 60;
            $('#countdown_timer').text((mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs);
        }

        function checkPairingStatus() {
            if (!hasCode || remaining <= 0) return;
            
            $.getJSON('api_check_pairing.php?device_id=' + deviceId, function(data) {
                if (data.paired) {
                    clearInterval(timerInterval);
                    clearInterval(pollInterval);
                    $('#countdown_container').hide(); // Hide the timer
                    $('#pairing_code_input').val(''); // Clear the code
                    alert("Pairing Berhasil!");
                    window.location.reload(); // Reload to show new user and remove timer from PHP
                }
            });
        }

        if (hasCode) {
            timerInterval = setInterval(updateTimer, 1000);
            updateTimer();
            // Poll API every 3 seconds to check if code was used
            pollInterval = setInterval(checkPairingStatus, 3000);
        }
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
</body>
</html>
