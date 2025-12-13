<?php
require 'config.php';

$data = getPostData();

if (!$data) {
    echo json_encode(["success" => false, "message" => "No data"]);
    exit;
}

$name = $data['name'];
$phone = $data['phone'];
$password = $data['password'];
$deviceId = $data['deviceId'];
$simNumber = $data['simNumber'];
$emergencyNumber = $data['emergencyNumber'];

// Check if exists
$check = $conn->query("SELECT id FROM users WHERE phone = '$phone'");
if ($check->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "Phone already exists"]);
    exit;
}

$sql = "INSERT INTO users (name, phone, password, deviceId, simNumber, emergencyNumber) 
        VALUES ('$name', '$phone', '$password', '$deviceId', '$simNumber', '$emergencyNumber')";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false, "message" => $conn->error]);
}

$conn->close();
?>
