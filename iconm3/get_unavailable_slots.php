<?php
require 'db.php';
header('Content-Type: application/json');

// Prevent crawlers
header('X-Robots-Tag: noindex, nofollow');

try {
    $date = filter_input(INPUT_POST, 'date', FILTER_SANITIZE_STRING);
    $service_id = filter_input(INPUT_POST, 'service_id', FILTER_SANITIZE_NUMBER_INT);
    $service_type = filter_input(INPUT_POST, 'service_type', FILTER_SANITIZE_STRING);
    $rental_type = filter_input(INPUT_POST, 'rental_type', FILTER_SANITIZE_STRING);

    if (!$date || !$service_id || !$service_type || !$rental_type) {
        echo json_encode(['error' => 'Missing required parameters']);
        exit;
    }

    if ($service_type !== 'car_rental' || !in_array($rental_type, ['hourly', 'daily'])) {
        echo json_encode(['error' => 'Invalid service or rental type']);
        exit;
    }

    $unavailable_slots = [];

    // Define valid time slots for hourly rentals (7:00 AM to 11:00 PM)
    $valid_times = [];
    for ($hour = 7; $hour <= 22; $hour++) {
        $valid_times[] = sprintf("%02d:00", $hour);
        if ($hour < 22) {
            $valid_times[] = sprintf("%02d:30", $hour);
        }
    }

    // Fetch bookings for the given date and vehicle
    $stmt = $pdo->prepare("
        SELECT pickup_date, dropoff_date
        FROM bookings
        WHERE service_type = ?
        AND service_id = ?
        AND status != 'cancelled'
        AND DATE(pickup_date) <= ? 
        AND DATE(dropoff_date) >= ?
    ");
    $stmt->execute([$service_type, $service_id, $date, $date]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rental_type === 'daily') {
        // For daily rentals, check if any booking overlaps with the day
        foreach ($bookings as $booking) {
            $pickup = new DateTime($booking['pickup_date']);
            $dropoff = new DateTime($booking['dropoff_date']);
            $pickup_date = $pickup->format('Y-m-d');
            $dropoff_date = $dropoff->format('Y-m-d');

            if ($pickup_date <= $date && $dropoff_date >= $date) {
                // Day is booked; mark as unavailable
                $unavailable_slots = ['07:00', '23:00']; // Indicate day is taken
                break;
            }
        }
    } else {
        // For hourly rentals, generate unavailable slots
        foreach ($bookings as $booking) {
            $pickup = new DateTime($booking['pickup_date']);
            $dropoff = new DateTime($booking['dropoff_date']);
            $pickup_date = $pickup->format('Y-m-d');
            $dropoff_date = $dropoff->format('Y-m-d');

            if ($pickup_date <= $date && $dropoff_date >= $date) {
                $start = ($pickup_date === $date) ? $pickup : new DateTime("$date 07:00:00");
                $end = ($dropoff_date === $date) ? $dropoff : new DateTime("$date 23:00:00");

                // Adjust start and end to be within 7:00 AM to 11:00 PM
                $start_hour = (int)$start->format('H');
                $start_minute = (int)$start->format('i');
                if ($start_hour < 7) {
                    $start->setTime(7, 0);
                } elseif ($start_hour == 22 && $start_minute > 30) {
                    $start->setTime(22, 30);
                }

                $end_hour = (int)$end->format('H');
                $end_minute = (int)$end->format('i');
                if ($end_hour > 22 || ($end_hour == 22 && $end_minute > 30)) {
                    $end->setTime(22, 30);
                } elseif ($end_hour < 7) {
                    continue; // Skip if end time is before 7:00 AM
                }

                while ($start <= $end) {
                    $slot = $start->format('H:i');
                    if (in_array($slot, $valid_times) && !in_array($slot, $unavailable_slots)) {
                        $unavailable_slots[] = $slot;
                    }
                    $start->modify('+30 minutes');
                }
            }
        }
        // Sort slots for consistency
        sort($unavailable_slots);
    }

    echo json_encode(['unavailable_slots' => $unavailable_slots]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Server error']);
    // error_log("get_unavailable_slots error: " . $e->getMessage(), 3, '/iconm3/logs/php_errors.log');
}
?>