<?php
require 'db.php';
header('Content-Type: application/json');

// Prevent crawlers
header('X-Robots-Tag: noindex, nofollow');

try {
    $limousine_id = filter_input(INPUT_GET, 'limousine_id', FILTER_SANITIZE_NUMBER_INT);
    $date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING);
    $service_type = 'limousine';

    if (!$limousine_id || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid limousine ID or date']);
        exit;
    }

    // Fetch bookings for the limousine on the given date
    $stmt = $pdo->prepare("
        SELECT pickup_date, pickup_time, hours, limo_service_type
        FROM bookings
        WHERE service_type = ?
        AND service_id = ?
        AND status != 'cancelled'
        AND DATE(pickup_date) = ?
    ");
    $stmt->execute([$service_type, $limousine_id, $date]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate unavailable time slots
    $unavailable_slots = [];
    foreach ($bookings as $booking) {
        // Extract date-only from pickup_date
        $pickup_date_only = (new DateTime($booking['pickup_date']))->format('Y-m-d');
        
        // Validate and normalize pickup_time (remove seconds if present)
        $pickup_time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $booking['pickup_time']) 
            ? substr($booking['pickup_time'], 0, 5) 
            : null;
        if (!$pickup_time) {
            // error_log("Invalid pickup_time format for booking: " . json_encode($booking), 3, '/logs/debug.log');
            continue;
        }

        // Create pickup_datetime safely
        $pickup_datetime_str = "$pickup_date_only $pickup_time";
        $pickup_datetime = new DateTime($pickup_datetime_str);
        
        // Calculate duration
        $duration_hours = ($booking['limo_service_type'] === 'Hourly' && $booking['hours']) ? $booking['hours'] : 2;
        $end_datetime = (clone $pickup_datetime)->modify("+{$duration_hours} hours");

        // Only include slots for the requested date
        if ($end_datetime->format('Y-m-d') < $date || $pickup_datetime->format('Y-m-d') > $date) {
            continue; // Skip if booking doesn’t overlap with requested date
        }

        $start = $pickup_datetime;
        $end = $end_datetime->format('Y-m-d') > $date ? new DateTime("$date 23:59:59") : $end_datetime;

        $current = clone $start;
        while ($current <= $end) {
            $unavailable_slots[] = $current->format('H:i');
            $current->modify('+30 minutes');
        }
    }

    // Remove duplicates and sort
    $unavailable_slots = array_unique($unavailable_slots);
    sort($unavailable_slots);

    echo json_encode(['unavailable_slots' => $unavailable_slots]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    // error_log("get_unavailable_slots_limousine error: " . $e->getMessage() . " | Booking data: " . json_encode($booking ?? []), 3, '/logs/php_errors.log');
}
?>