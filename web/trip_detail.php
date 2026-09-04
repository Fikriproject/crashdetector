<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if (!isset($_GET['device'])) {
    header("Location: dashboard.php");
    exit();
}

$device_id = $_GET['device'];
$trip_date = isset($_GET['date']) ? $_GET['date'] : null;
$accident_time = isset($_GET['accident_time']) ? $_GET['accident_time'] : null;

if (!$trip_date && !$accident_time) {
    header("Location: dashboard.php");
    exit();
}

// Validate access to this device
$has_access = false;
$stmt_owner = $conn->prepare("SELECT id FROM device WHERE device_id = ? AND owner_id = ?");
$stmt_owner->bind_param("si", $device_id, $user_id);
$stmt_owner->execute();
$check_owner = $stmt_owner->get_result();

if($check_owner->num_rows > 0) {
    $has_access = true;
} else {
    $stmt_viewer = $conn->prepare("SELECT id FROM device_viewers WHERE device_id = ? AND user_id = ?");
    $stmt_viewer->bind_param("si", $device_id, $user_id);
    $stmt_viewer->execute();
    $check_viewer = $stmt_viewer->get_result();
    if($check_viewer->num_rows > 0) {
        $has_access = true;
    }
}

if(!$has_access) {
    header("Location: dashboard.php");
    exit();
}

// Fetch Logs
if ($accident_time) {
    // 10 minutes focused window (5 mins before and 5 mins after)
    $stmt = $conn->prepare("SELECT * FROM log_perjalanan WHERE device_id = ? AND recorded_time BETWEEN (STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') - INTERVAL 5 MINUTE) AND (STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') + INTERVAL 5 MINUTE) ORDER BY recorded_time DESC");
    $stmt->bind_param("sss", $device_id, $accident_time, $accident_time);
} else {
    // Full day log
    $stmt = $conn->prepare("SELECT * FROM log_perjalanan WHERE device_id = ? AND DATE(recorded_time) = ? ORDER BY recorded_time DESC");
    $stmt->bind_param("ss", $device_id, $trip_date);
}

$stmt->execute();
$logs = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRASH DETEKTOR - Detail Perjalanan</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .log-card {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
        }
        .log-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 0.5rem;
		padding-bottom: 0.5rem;
		border-bottom: 1px solid #f1f5f9;
        }
        .log-time { font-weight: 600; color: var(--primary); }
        .log-status { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; padding: 0.2rem 0.5rem; border-radius: 12px; }
        .status-aman { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-emergency { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        
        .log-data {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            text-align: center;
            margin-bottom: 0.5rem;
        }
        .data-label { color: var(--text-muted); font-size: 0.75rem; margin-bottom: 0.2rem; }
        .data-val { font-weight: 600; }
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
            <div class="app-header mobile-only flex-row">
                <a href="dashboard.php" class="back-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
                    Back
                </a>
                <div class="app-title" style="text-align: right;">Detail Perjalanan</div>
            </div>
            
            <!-- Header Desktop Tambahan (Opsional) -->
            <div class="app-header desktop-header flex-row" style="border:none; display:none;">
                <h2 style="font-size: 1.5rem; font-weight: 700;">Detail Perjalanan</h2>
            </div>
        
        <div class="app-content">
            <div class="card text-center" style="padding: 1rem; margin-bottom: 1.5rem;">
                <h3 style="margin-bottom: 0.25rem; font-size: 1rem;">Alat: <?php echo htmlspecialchars($device_id); ?></h3>
                <p class="text-muted" style="font-size: 0.85rem;">Tanggal: <?php echo date('d F Y', strtotime($trip_date ? $trip_date : $accident_time)); ?></p>
                <div style="margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-muted);">
                    Total Rekaman: <?php echo $logs->num_rows; ?> data
                </div>
            </div>

            <?php if($logs->num_rows == 0): ?>
                <div class="alert alert-error">Tidak ada data perjalanan pada tanggal ini.</div>
            <?php else: ?>
                <div class="history-list">
                    <?php while($row = $logs->fetch_assoc()): 
                        $status_class = (strtolower($row['status']) == 'emergency') ? 'status-emergency' : 'status-aman';
                    ?>
                    <div class="log-card">
                        <div class="log-header">
                            <span class="log-time"><?php echo date('H:i:s', strtotime($row['recorded_time'])); ?></span>
                            <span class="log-status <?php echo $status_class; ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                        </div>
                        
                        <div class="log-data">
                            <div>
                                <div class="data-label">Roll</div>
                                <div class="data-val"><?php echo number_format($row['roll'], 1); ?>°</div>
                            </div>
                            <div>
                                <div class="data-label">Pitch</div>
                                <div class="data-val"><?php echo number_format($row['pitch'], 1); ?>°</div>
                            </div>
                            <div>
                                <div class="data-label">Yaw</div>
                                <div class="data-val"><?php echo number_format($row['yaw'], 1); ?>°</div>
                            </div>
                            <div>
                                <div class="data-label">Accel</div>
                                <div class="data-val"><?php echo number_format($row['total_accel'], 2); ?>g</div>
                            </div>
                            <div>
                                <div class="data-label">Speed</div>
                                <div class="data-val"><?php echo number_format($row['speed'], 1); ?></div>
                            </div>
                            <div>
                                <div class="data-label">Alt</div>
                                <div class="data-val"><?php echo number_format($row['altitude'], 1); ?></div>
                            </div>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f1f5f9; padding-top: 0.5rem; margin-top: 0.5rem;">
                            <span style="font-size: 0.75rem; color: var(--text-muted);">
                                GPS: <?php echo substr($row['latitude'], 0, 7) . ', ' . substr($row['longitude'], 0, 7); ?>
                            </span>
                            <?php if($row['latitude'] != 0): ?>
                            <a href="https://maps.google.com/?q=<?php echo $row['latitude'].','.$row['longitude']; ?>" target="_blank" class="btn btn-outline" style="padding: 0.2rem 0.5rem; font-size: 0.7rem; display: flex; align-items: center; gap: 0.2rem; border-radius: 6px; border-color: var(--primary); color: var(--primary); width: auto;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                Maps
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            <?php endif; ?>
        </div>
        </div> <!-- End content-area -->
    </div>
    
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
