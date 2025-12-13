CREATE DATABASE IF NOT EXISTS accident_db;

USE accident_db;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    deviceId VARCHAR(50) NOT NULL,
    simNumber VARCHAR(20) NOT NULL,
    emergencyNumber VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (name, phone, password, deviceId, simNumber, emergencyNumber) VALUES 
('Pengguna Demo', '08123456789', 'admin', 'DEVICE_001', '08111222333', '08999999999');
