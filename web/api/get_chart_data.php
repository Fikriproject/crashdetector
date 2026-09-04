<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["error" => "Unauthorized"]);
    exit();
}

require_once '../config.php';

$user_id = $_SESSION['user_id'];
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$device_id = isset($_GET['device_id']) ? $conn->real_escape_string($_GET['device_id']) : 'all';

// Validate user has access to devices
$allowed_devices = [];
$stmt_own = $conn->prepare("SELECT device_id FROM device WHERE owner_id = ?");
$stmt_own->bind_param("i", $user_id);
$stmt_own->execute();
$res = $stmt_own->get_result();
while ($row = $res->fetch_assoc()) {
    $allowed_devices[] = $row['device_id'];
}
$stmt_view = $conn->prepare("SELECT device_id FROM device_viewers WHERE user_id = ?");
$stmt_view->bind_param("i", $user_id);
$stmt_view->execute();
$res = $stmt_view->get_result();
while ($row = $res->fetch_assoc()) {
    $allowed_devices[] = $row['device_id'];
}

if (empty($allowed_devices)) {
    echo json_encode(["labels" => [], "data" => []]);
    exit();
}

$device_filter = "";
if ($device_id !== 'all' && in_array($device_id, $allowed_devices)) {
    $device_filter = "AND device_id = '$device_id'";
} else {
    $in_devices = "'" . implode("','", $allowed_devices) . "'";
    $device_filter = "AND device_id IN ($in_devices)";
}

$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$labels = [];
$data_minutes = [];

for ($i = 1; $i <= $days_in_month; $i++) {
    $labels[] = (string)$i;
    $data_minutes[$i] = 0;
}

$sql = "
SELECT 
    DAY(recorded_time) as day_of_month,
    device_id,
    TIMESTAMPDIFF(MINUTE, MIN(recorded_time), MAX(recorded_time)) as duration_minutes,
    COUNT(*) as log_count
FROM log_perjalanan 
WHERE MONTH(recorded_time) = $month 
  AND YEAR(recorded_time) = $year
  $device_filter
GROUP BY day_of_month, device_id
";

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $day = (int)$row['day_of_month'];
        $duration = (int)$row['duration_minutes'];
        if ($duration == 0 && $row['log_count'] > 0) {
            $duration = 1; // at least 1 min if there are logs
        }
        $data_minutes[$day] += $duration;
    }
}

$final_data = [];
for ($i = 1; $i <= $days_in_month; $i++) {
    $final_data[] = $data_minutes[$i];
}

echo json_encode([
    "labels" => $labels,
    "data" => $final_data
]);
?>
