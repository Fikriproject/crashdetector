<?php
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_GET['device_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$user_id = $_SESSION['user_id'];
$device_id = $conn->real_escape_string($_GET['device_id']);

// Check if the current user owns this device and its code is NULL now (meaning consumed or expired manually)
$stmt = $conn->prepare("SELECT pairing_code, pairing_code_expires_at FROM device WHERE device_id = ? AND owner_id = ?");
$stmt->bind_param("si", $device_id, $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows > 0) {
    $row = $res->fetch_assoc();
    // If it was recently consumed, the pairing_code will be NULL
    // Since we want to just know if it was consumed, if it's NULL now, we return true.
    // The frontend will stop polling after it receives true.
    if ($row['pairing_code'] === null && $row['pairing_code_expires_at'] === null) {
        // One more check: Did the number of viewers actually change?
        // Let's just assume if it's null, it's either consumed or expired. 
        // We'll return paired: true so frontend reloads and shows the new user (or just an empty input if it expired).
        echo json_encode(['success' => true, 'paired' => true]);
        exit();
    }
}

echo json_encode(['success' => true, 'paired' => false]);
?>
