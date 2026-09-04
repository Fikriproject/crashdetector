<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

require_once '../config.php';

if(isset($_GET['device_id']) && !empty($_GET['device_id'])) {
    $device_id = $conn->real_escape_string($_GET['device_id']);
    
    $response = array();
    
    // Fetch device config
    $stmt = $conn->prepare("SELECT threshold_pitch, threshold_roll, threshold_yaw, threshold_accel, status, wifi_ssid, wifi_password FROM device WHERE device_id = ?");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0) {
        $device_data = $result->fetch_assoc();
        $response['config'] = array(
            "pitch_limit" => floatval($device_data['threshold_pitch']),
            "roll_limit" => floatval($device_data['threshold_roll']),
            "yaw_limit" => floatval($device_data['threshold_yaw']),
            "accel_limit" => floatval($device_data['threshold_accel']),
            "wifi_ssid" => $device_data['wifi_ssid'] ? $device_data['wifi_ssid'] : "",
            "wifi_password" => $device_data['wifi_password'] ? $device_data['wifi_password'] : "",
            "status" => $device_data['status']
        );
        
        // Fetch emergency contacts from pairing table
        $stmt_contacts = $conn->prepare("
            SELECT emergency_contact_name, emergency_contact_phone 
            FROM pairing 
            WHERE device_id = ?
        ");
        $stmt_contacts->bind_param("s", $device_id);
        $stmt_contacts->execute();
        $result_contacts = $stmt_contacts->get_result();
        
        $contacts = array();
        while($row = $result_contacts->fetch_assoc()) {
            // Only add if they have a phone number
            if (!empty($row['emergency_contact_phone'])) {
                $contacts[] = array(
                    "name" => $row['emergency_contact_name'],
                    "phone" => $row['emergency_contact_phone']
                );
            }
        }
        $response['contacts'] = $contacts;
        
        http_response_code(200);
        echo json_encode($response);
        $stmt_contacts->close();
    } else {
        http_response_code(404);
        echo json_encode(array("message" => "Device not found."));
    }
    $stmt->close();
} else {
    http_response_code(400);
    echo json_encode(array("message" => "Missing device_id parameter."));
}

$conn->close();
?>
