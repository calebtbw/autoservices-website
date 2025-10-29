<?php
require 'db.php';
header('Content-Type: application/json');

// Prevent crawlers
header('X-Robots-Tag: noindex, nofollow');

try {
    $service_type = filter_input(INPUT_POST, 'service_type', FILTER_SANITIZE_STRING);
    $service_id = filter_input(INPUT_POST, 'service_id', FILTER_SANITIZE_NUMBER_INT);
    $pickup_date = filter_input(INPUT_POST, 'pickup_date', FILTER_SANITIZE_STRING);
    $pickup_time = filter_input(INPUT_POST, 'pickup_time', FILTER_SANITIZE_STRING);
    $dropoff_date = filter_input(INPUT_POST, 'dropoff_date', FILTER_SANITIZE_STRING);
    $dropoff_time = filter_input(INPUT_POST, 'dropoff_time', FILTER_SANITIZE_STRING);
    $rental_type = filter_input(INPUT_POST, 'rental_type', FILTER_SANITIZE_STRING);

    if (!$service_type || !$service_id || !$pickup_date || !$pickup_time || !$dropoff_date || !$dropoff_time || !$rental_type) {
        echo json_encode(['error' => 'Missing required parameters']);
        exit;
    }

    if ($service_type !== 'car_rental' || !in_array($rental_type, ['hourly', 'daily'])) {
        echo json_encode(['error' => 'Invalid service or rental type']);
        exit;
    }

    $pickup_datetime = new DateTime("$pickup_date $pickup_time");
    $dropoff_datetime = new DateTime("$dropoff_date $dropoff_time");

    // Validate times
    if ($rental_type === 'daily') {
        if ($pickup_time !== '07:00' || $dropoff_time !== '23:00') {
            echo json_encode(['error' => 'Daily rentals must have pickup at 7:00 AM and drop-off at 11:00 PM']);
            exit;
        }
    } else {
        // Hourly rentals: validate within 7:00 AM to 11:00 PM
        $valid_times = [];
        for ($hour = 7; $hour <= 22; $hour++) {
            $valid_times[] = sprintf("%02d:00", $hour);
            if ($hour < 22) {
                $valid_times[] = sprintf("%02d:30", $hour);
            }
        }
        if (!in_array($pickup_time, $valid_times) || !in_array($dropoff_time, $valid_times)) {
            echo json_encode(['error' => 'Hourly rental times must be between 7:00 AM and 11:00 PM']);
            exit;
        }
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM bookings
        WHERE service_type = ?
        AND service_id = ?
        AND status != 'cancelled'
        AND (
            (pickup_date <= ? AND dropoff_date >= ?)
            OR (pickup_date >= ? AND pickup_date <= ?)
            OR (dropoff_date >= ? AND dropoff_date <= ?)
        )
    ");
    $stmt->execute([
        $service_type,
        $service_id,
        $dropoff_datetime->format('Y-m-d H:i:s'),
        $pickup_datetime->format('Y-m-d H:i:s'),
        $pickup_datetime->format('Y-m-d H:i:s'),
        $dropoff_datetime->format('Y-m-d H:i:s'),
        $pickup_datetime->format('Y-m-d H:i:s'),
        $dropoff_datetime->format('Y-m-d H:i:s')
    ]);

    $has_overlap = $stmt->fetchColumn() > 0;

    echo json_encode(['has_overlap' => $has_overlap]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Server error']);
    // error_log("check_overlap error: " . $e->getMessage(), 3, '/iconm3/logs/php_errors.log');
}
?>