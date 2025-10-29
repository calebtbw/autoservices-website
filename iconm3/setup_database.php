<?php
require 'db.php';

try {
    // Create slots table (Car Detailing and Valet Only)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS slots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            service_type VARCHAR(50) NOT NULL,
            slot_date DATE NOT NULL,
            slot_time TIME NOT NULL,
            is_booked TINYINT(1) DEFAULT 0,
            UNIQUE (service_type, slot_date, slot_time),
            INDEX idx_service_type (service_type),
            INDEX idx_slot_date (slot_date)
        )
    ");

    // Create clients table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            service VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_service (service)
        )
    ");

    // Create vehicles table 5
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            model VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL,
            daily_price DECIMAL(10,2) NOT NULL, 
            availability TINYINT(1) DEFAULT 1,
            image VARCHAR(255),
            latitude DECIMAL(9,6) NOT NULL,
            longitude DECIMAL(9,6) NOT NULL,
            address TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_availability (availability)
        )
    ");

    // Create bookings table 4 6 7
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bookings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            service_type VARCHAR(50) NOT NULL,
            rental_type ENUM('hourly', 'daily') NOT NULL DEFAULT 'hourly', 
            limo_service_type VARCHAR(255) NULL,
            valet_included TINYINT(1) DEFAULT 0, 
            valet_slot_id INT DEFAULT NULL, 
            service_id INT NOT NULL,
            slot_id INT NULL, 
            total_amount DECIMAL(10,2) NOT NULL,
            pickup_date DATETIME NOT NULL,
            pickup_time TIME NOT NULL,
            pickup_location TEXT NOT NULL,
            dropoff_location TEXT NOT NULL,
            dropoff_date DATETIME DEFAULT NULL,
            dropoff_time TIME DEFAULT NULL,
            hours INT DEFAULT NULL,
            payment_id VARCHAR(100) DEFAULT NULL,
            payment_method VARCHAR(20) NOT NULL,
            payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            payment_request_id VARCHAR(100) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            refund_pending TINYINT(1) DEFAULT 0, 
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            INDEX idx_service (service_type, service_id),
            INDEX idx_dates (pickup_date, dropoff_date),
            INDEX idx_status (status),
            INDEX idx_payment_status (payment_status)
        )
    ");

    // Create limousines table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS limousines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            model VARCHAR(255) NOT NULL,
            description TEXT,
            image VARCHAR(255),
            availability TINYINT(1) DEFAULT 1,
            service_types JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_availability (availability)
        )
    ");

    // Create valet_services table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS valet_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL
        )
    ");

    // Create car_services table //
    $pdo->exec("
        CREATE TABLE car_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10, 2) NOT NULL,
            valet_price DECIMAL(10, 2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
    ");

    // Create admins table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Create settings table for cut-off controls
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            name VARCHAR(50) PRIMARY KEY,
            value VARCHAR(255) NOT NULL
        )
    ");

    // Create audit logs table for logging admin actions
    $pdo->exec("
        CREATE TABLE audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            action VARCHAR(255) NOT NULL,
            booking_id INT,
            admin_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
        )
    ");

    // Insert default admin
    $defaultAdminPassword = password_hash('BET2z7CT&A%k6v', PASSWORD_DEFAULT);
    $pdo->exec("INSERT IGNORE INTO admins (username, password) VALUES ('admin', '$defaultAdminPassword')");

    // Insert default valet services
    $pdo->exec("
        INSERT IGNORE INTO valet_services (name, description, price) VALUES
        ('Event Valet', 'Valet service for events and gatherings', 80.00),
        ('VIP Valet', 'Premium valet service with extra care', 120.00)
    ");

    // Insert default car servicing services //
    $pdo->exec("
        INSERT INTO car_services (name, description, price, valet_price) VALUES
        ('Basic Servicing', 'Oil change, filter replacement, and basic inspection.', 100.00, 50.00),
        ('Intermediate Servicing', 'Includes Basic Servicing plus brake check and fluid top-up.', 200.00, 50.00),
        ('Advanced Servicing', 'Comprehensive servicing including diagnostics and tire alignment.', 350.00, 50.00)
    ");

    echo "Database setup completed successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>