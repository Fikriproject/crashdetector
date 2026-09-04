<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['device_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing device_id']);
    exit();
}

$device_id = $data['device_id'];

$stmt = $conn->prepare("UPDATE log_perjalanan SET is_read = 1 WHERE device_id = ? AND is_read = 0");
$stmt->bind_param("s", $device_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
