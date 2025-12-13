<?php
require 'config.php';

$data = getPostData();

$id = $data['id'];
$name = $data['name'];
$password = $data['password'];
$simNumber = $data['simNumber'];
$emergencyNumber = $data['emergencyNumber'];

$sql = "UPDATE users SET name='$name', password='$password', simNumber='$simNumber', emergencyNumber='$emergencyNumber' WHERE id=$id";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false, "message" => $conn->error]);
}

$conn->close();
?>
