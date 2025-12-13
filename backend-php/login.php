<?php
require 'config.php';

$data = getPostData();
$phone = $data['phone'];
$password = $data['password'];

$result = $conn->query("SELECT * FROM users WHERE phone = '$phone' AND password = '$password'");

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo json_encode(["success" => true, "user" => $user]);
} else {
    echo json_encode(["success" => false, "message" => "Invalid credentials"]);
}

$conn->close();
?>
