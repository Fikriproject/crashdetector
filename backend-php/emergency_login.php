<?php
require 'config.php';

$data = getPostData();
$emergencyNumber = $data['emergencyNumber'];

$result = $conn->query("SELECT * FROM users WHERE emergencyNumber = '$emergencyNumber'");

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo json_encode(["success" => true, "user" => $user]);
} else {
    echo json_encode(["success" => false]);
}

$conn->close();
?>
