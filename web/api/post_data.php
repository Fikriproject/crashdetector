<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

require_once '../config.php';

// Get POST data
$data = json_decode(file_get_contents("php://input"));

if(
    !empty($data->id_device) &&
    isset($data->lokasi->latitude) &&
    isset($data->lokasi->longitude) &&
    isset($data->nilai_sensor->roll) &&
    isset($data->nilai_sensor->pitch) &&
    isset($data->status)
){
    $device_id = $conn->real_escape_string($data->id_device);
    
    // Fallback recorded_time from DB default if not provided, else use provided
    $recorded_time = isset($data->waktu_kejadian) ? $conn->real_escape_string($data->waktu_kejadian) : date('Y-m-d H:i:s');
    
    $lat = floatval($data->lokasi->latitude);
    $lng = floatval($data->lokasi->longitude);
    $speed = isset($data->lokasi->speed) ? floatval($data->lokasi->speed) : 0.0;
    $alt = isset($data->lokasi->altitude) ? floatval($data->lokasi->altitude) : 0.0;
    
    $roll = floatval($data->nilai_sensor->roll);
    $pitch = floatval($data->nilai_sensor->pitch);
    $yaw = isset($data->nilai_sensor->yaw) ? floatval($data->nilai_sensor->yaw) : 0.0;
    $total_accel = isset($data->nilai_sensor->accel_total) ? floatval($data->nilai_sensor->accel_total) : 0.0;
    $temp = isset($data->nilai_sensor->suhu) ? floatval($data->nilai_sensor->suhu) : 0.0;
    
    $status = $conn->real_escape_string($data->status);

    // Check if device exists
    $check_stmt = $conn->prepare("SELECT id FROM device WHERE device_id = ?");
    $check_stmt->bind_param("s", $device_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if($result->num_rows > 0) {
        $stmt = $conn->prepare("INSERT INTO log_perjalanan (device_id, recorded_time, latitude, longitude, speed, altitude, roll, pitch, yaw, total_accel, temperature, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->bind_param("ssddddddddds", $device_id, $recorded_time, $lat, $lng, $speed, $alt, $roll, $pitch, $yaw, $total_accel, $temp, $status);
        
        if($stmt->execute()){
            $log_id = $stmt->insert_id;
            
            // Pemicu Notifikasi Otomatis
            if ($status === 'Kecelakaan') {
                $stmt_contacts = $conn->prepare("SELECT emergency_contact_name, emergency_contact_phone FROM pairing WHERE device_id = ?");
                $stmt_contacts->bind_param("s", $device_id);
                $stmt_contacts->execute();
                $contacts_res = $stmt_contacts->get_result();
                
                while($contact = $contacts_res->fetch_assoc()) {
                    // Simulasi pengiriman SMS/HTTP
                    $stmt_notif = $conn->prepare("INSERT INTO log_notifikasi (log_perjalanan_id, notification_type, delivery_status) VALUES (?, 'SMS', 'Success')");
                    $stmt_notif->bind_param("i", $log_id);
                    $stmt_notif->execute();
                }
            }
            
            http_response_code(201);
            echo json_encode(array("message" => "Data logged successfully.", "log_id" => $log_id));
        } else{
            http_response_code(503);
            echo json_encode(array("message" => "Unable to log data.", "error" => $stmt->error));
        }
        $stmt->close();
    } else {
        http_response_code(404);
        echo json_encode(array("message" => "Device not found."));
    }
    $check_stmt->close();
} else {
    http_response_code(400);
    echo json_encode(array("message" => "Incomplete data structure."));
}

$conn->close();
?>
