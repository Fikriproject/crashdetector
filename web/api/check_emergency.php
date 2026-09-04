<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Get all devices owned by user or where user is a viewer
$devices = [];

// Owned devices
$stmt_owned = $conn->prepare("SELECT device_id FROM device WHERE owner_id = ?");
$stmt_owned->bind_param("i", $user_id);
$stmt_owned->execute();
$res_owned = $stmt_owned->get_result();
while($row = $res_owned->fetch_assoc()) {
    $devices[] = $row['device_id'];
}

// Followed devices
$stmt_followed = $conn->prepare("SELECT device_id FROM device_viewers WHERE user_id = ?");
$stmt_followed->bind_param("i", $user_id);
$stmt_followed->execute();
$res_followed = $stmt_followed->get_result();
while($row = $res_followed->fetch_assoc()) {
    $devices[] = $row['device_id'];
}

$emergencies = [];

if (count($devices) > 0) {
    // For each device, get the latest unread emergency log
    foreach($devices as $dev_id) {
        $stmt_log = $conn->prepare("SELECT id, status, recorded_time, latitude, longitude FROM log_perjalanan WHERE device_id = ? AND status = 'emergency' AND is_read = 0 ORDER BY recorded_time DESC LIMIT 1");
        $stmt_log->bind_param("s", $dev_id);
        $stmt_log->execute();
        $res_log = $stmt_log->get_result();
        
        if ($res_log->num_rows > 0) {
            $log = $res_log->fetch_assoc();
            
            $emergencies[] = [
                'log_id' => $log['id'],
                'device_id' => $dev_id,
                'status' => 'emergency',
                'recorded_time' => $log['recorded_time'],
                'lat' => $log['latitude'],
                'lon' => $log['longitude']
            ];
        }
    }
}

echo json_encode(['success' => true, 'emergencies' => $emergencies]);
?>
