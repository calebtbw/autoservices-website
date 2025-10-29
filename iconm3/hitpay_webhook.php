<?php
require 'db.php';
require 'config.php';
require 'utils.php';

// Set content type to JSON with UTF-8
header('Content-Type: application/json; charset=UTF-8');

// Log the start of the webhook
error_log("Webhook received: " . date('Y-m-d H:i:s') . "\n", 3, './logs/webhook.log');

// Log all headers for debugging
error_log("Headers: " . print_r(getallheaders(), true) . "\n", 3, './logs/webhook.log');

// Get the raw POST data
$payload = file_get_contents('php://input');
error_log("Raw payload: " . $payload . "\n", 3, './logs/webhook.log');

// Get the signature from the Hitpay-Signature header
$signature = $_SERVER['HTTP_HITPAY_SIGNATURE'] ?? '';
if (empty($signature)) {
    error_log("Webhook error: Missing Hitpay-Signature header\n", 3, './logs/php_errors.log');
    http_response_code(400);
    exit("Missing Hitpay-Signature header");
}

// Verify the webhook signature
$expectedSignature = hash_hmac('sha256', $payload, HITPAY_SALT);
error_log("Computed signature: $expectedSignature\nReceived signature: $signature\n", 3, './logs/webhook.log');
if (!hash_equals($expectedSignature, $signature)) {
    error_log("Webhook error: Invalid signature\n", 3, './logs/php_errors.log');
    http_response_code(400);
    exit("Invalid signature");
}

// Parse the payload
$data = json_decode($payload, true);
if (!$data) {
    error_log("Webhook error: Invalid payload\n", 3, './logs/php_errors.log');
    http_response_code(400);
    exit("Invalid payload");
}

// Log the event with all fields
error_log("Webhook event (parsed): " . json_encode($data) . "\n", 3, './logs/webhook.log');

// Handle the event
if ($data['status'] === 'succeeded') {
    $payment_id = $data['id'] ?? null;
    $payment_request_id = $data['payment_request']['id'] ?? null;
    $booking_id = $data['payment_request']['reference_number'] ?? null;
    $received_amount = $data['payment_request']['amount'] ?? null;

    error_log("Payment succeeded: payment_id=$payment_id, payment_request_id=$payment_request_id, booking_id=$booking_id, amount=$received_amount\n", 3, './logs/webhook.log');

    if ($payment_request_id && $booking_id && $received_amount) {
        try {
            // Check if the booking exists and matches criteria
            $stmt = $pdo->prepare("
                SELECT id, payment_status, status, service_type, total_amount, rental_type, hours, service_id
                FROM bookings
                WHERE payment_request_id = ? AND id = ? AND payment_status = 'pending'
            ");
            $stmt->execute([$payment_request_id, $booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                $error = "No matching booking found or payment_status is not pending";
                error_log("Webhook error: $error. payment_request_id=$payment_request_id, booking_id=$booking_id\n", 3, './logs/php_errors.log');
                logAuditAction($pdo, "Webhook failed to process payment: $error", $booking_id, null);
                http_response_code(400);
                exit("No matching booking found or payment_status is not pending");
            }

            // Validate total_amount for car_rental
            if ($booking['service_type'] === 'car_rental') {
                $stmt = $pdo->prepare("SELECT price, daily_price FROM vehicles WHERE id = ?");
                $stmt->execute([$booking['service_id']]);
                $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($vehicle) {
                    $expected_amount = 0;
                    if ($booking['rental_type'] === 'hourly') {
                        $expected_amount = $booking['hours'] * $vehicle['price'];
                    } elseif ($booking['rental_type'] === 'daily') {
                        $expected_amount = $vehicle['daily_price']; // Fixed daily rate
                    }
                    error_log("Car rental validation: booking_id=$booking_id, rental_type={$booking['rental_type']}, hours={$booking['hours']}, daily_price={$vehicle['daily_price']}, expected_amount=$expected_amount, received_amount=$received_amount\n", 3, './logs/webhook.log');
                    if (abs(floatval($received_amount) - $expected_amount) > 0.01) {
                        $error = "Invalid total_amount for car_rental: received=$received_amount, expected=$expected_amount";
                        error_log("Webhook error: $error\n", 3, './logs/php_errors.log');
                        logAuditAction($pdo, "Webhook failed to process payment: $error", $booking_id, null);
                        http_response_code(400);
                        exit("Invalid total_amount");
                    }
                }
            }

            // Start a transaction for atomic updates
            $pdo->beginTransaction();
            // Update the booking with payment_id and set payment_status to 'completed'
            $stmt = $pdo->prepare("
                UPDATE bookings
                SET payment_id = ?, payment_status = 'completed'
                WHERE payment_request_id = ? AND id = ? AND payment_status = 'pending'
            ");
            $stmt->execute([$payment_id, $payment_request_id, $booking_id]);
            if ($stmt->rowCount() > 0) {
                // Update booking status to confirmed if not already completed or cancelled
                if ($booking['status'] !== 'completed' && $booking['status'] !== 'cancelled') {
                    $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
                    $stmt->execute([$booking_id]);
                    logAuditAction($pdo, "Webhook updated service status to confirmed due to payment success", $booking_id, null);
                }

                // Log payment status update
                logAuditAction($pdo, "Webhook confirmed payment", $booking_id, null);

                // Clear revenue cache
                $cacheFile = './cache/total_revenue.txt';
                if (file_exists($cacheFile)) {
                    if (!unlink($cacheFile)) {
                        error_log("Webhook warning: Failed to clear revenue cache\n", 3, './logs/webhook.log');
                        logAuditAction($pdo, "Webhook failed to clear revenue cache", $booking_id, null);
                    } else {
                        error_log("Revenue cache cleared\n", 3, './logs/webhook.log');
                        logAuditAction($pdo, "Webhook cleared revenue cache", $booking_id, null);
                    }
                }

                // Send Telegram notification
                if (!sendTelegramNotification($pdo, $booking_id, 'confirmation')) {
                    error_log("Webhook warning: Failed to send Telegram notification for booking ID $booking_id\n", 3, './logs/webhook.log');
                    logAuditAction($pdo, "Telegram notification failed for confirmation", $booking_id, null);
                } else {
                    logAuditAction($pdo, "Telegram notification sent for confirmation", $booking_id, null);
                }

                $pdo->commit();
                error_log("Booking updated to completed and confirmed: payment_request_id $payment_request_id, booking_id $booking_id\n", 3, './logs/webhook.log');
            } else {
                $pdo->rollBack();
                $error = "No rows updated";
                error_log("Webhook error: $error. payment_request_id=$payment_request_id, booking_id=$booking_id\n", 3, './logs/php_errors.log');
                logAuditAction($pdo, "Webhook failed to process payment: $error", $booking_id, null);
                http_response_code(400);
                exit("No rows updated");
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Database error: " . $e->getMessage();
            error_log("Webhook error: Failed to update booking - $error\n", 3, './logs/php_errors.log');
            logAuditAction($pdo, "Webhook failed to process payment: $error", $booking_id, null);
            http_response_code(500);
            exit("Database error");
        }
    } else {
        $error = "Missing payment_request_id, booking_id, or amount";
        error_log("Webhook error: $error\n", 3, './logs/webhook.log');
        http_response_code(400);
        exit("Missing required fields");
    }
} elseif ($data['status'] === 'refunded') {
    $payment_id = $data['id'] ?? null;
    $booking_id = $data['payment_request']['reference_number'] ?? null;
    $payment_request_id = $data['payment_request']['id'] ?? null;

    error_log("Refund received: payment_id=$payment_id, payment_request_id=$payment_request_id, booking_id=$booking_id\n", 3, './logs/webhook.log');
    error_log("Webhook refund payload: " . json_encode($data) . "\n", 3, './logs/webhook.log');

    if ($booking_id || $payment_id || $payment_request_id) {
        try {
            // Find booking by booking_id, payment_id, or payment_request_id
            $stmt = $pdo->prepare("
                SELECT id, payment_status, status, service_type, service_id, slot_id, refund_pending, valet_included, valet_slot_id
                FROM bookings
                WHERE (id = :booking_id OR payment_id = :payment_id OR payment_request_id = :payment_request_id)
                  AND refund_pending = 1
            ");
            $stmt->execute([
                ':booking_id' => $booking_id,
                ':payment_id' => $payment_id,
                ':payment_request_id' => $payment_request_id
            ]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                $error = "No matching booking with refund_pending=1 found for booking_id=$booking_id, payment_id=$payment_id, payment_request_id=$payment_request_id";
                error_log("Webhook error: $error\n", 3, './logs/php_errors.log');
                logAuditAction($pdo, "Webhook failed to process refund: $error", $booking_id, null);
                http_response_code(400);
                exit("No matching booking found");
            }

            $booking_id = $booking['id'];
            error_log("Webhook booking found: booking_id=$booking_id, payment_status={$booking['payment_status']}, status={$booking['status']}, refund_pending={$booking['refund_pending']}\n", 3, './logs/webhook.log');

            // Start a transaction
            $pdo->beginTransaction();
            // Update payment_status, status, and clear refund_pending
            $stmt = $pdo->prepare("
                UPDATE bookings
                SET payment_status = 'refunded', status = 'cancelled', refund_pending = 0
                WHERE id = ? AND payment_status = 'completed' AND refund_pending = 1
            ");
            $stmt->execute([$booking_id]);
            $updatedRows = $stmt->rowCount();
            error_log("Webhook refund update: $updatedRows rows affected for booking_id: $booking_id\n", 3, './logs/webhook.log');

            if ($updatedRows > 0) {
                // Release resources
                error_log("Webhook calling releaseResources for booking_id: $booking_id, service_type: {$booking['service_type']}, service_id: {$booking['service_id']}, slot_id: " . ($booking['slot_id'] ?? 'NULL') . ", valet_option: " . ($booking['valet_slot_id'] ?? 'NULL') . "\n", 3, './logs/webhook.log');
                releaseResources($pdo, $booking_id, $booking['service_type'], $booking['service_id'], $booking['slot_id'], $booking['valet_slot_id']);

                // Log refund and resource release
                logAuditAction($pdo, "Webhook processed refund and cancelled booking", $booking_id, null);
                logAuditAction($pdo, "Webhook released resources for cancelled booking", $booking_id, null);

                // Clear revenue cache
                $cacheFile = './cache/total_revenue.txt';
                if (file_exists($cacheFile)) {
                    if (!unlink($cacheFile)) {
                        error_log("Webhook warning: Failed to clear revenue cache\n", 3, './logs/webhook.log');
                        logAuditAction($pdo, "Webhook failed to clear revenue cache", $booking_id, null);
                    } else {
                        error_log("Webhook revenue cache cleared\n", 3, './logs/webhook.log');
                        logAuditAction($pdo, "Webhook cleared revenue cache", $booking_id, null);
                    }
                }

                // Send Telegram notification for refund
                if (!sendTelegramNotification($pdo, $booking_id, 'refund')) {
                    error_log("Webhook warning: Failed to send Telegram notification for refund, booking_id: $booking_id\n", 3, './logs/webhook.log');
                    logAuditAction($pdo, "Webhook Telegram notification failed for refund", $booking_id, null);
                } else {
                    error_log("Webhook Telegram notification sent for booking_id: $booking_id\n", 3, './logs/webhook.log');
                    logAuditAction($pdo, "Webhook Telegram notification sent for refund", $booking_id, null);
                }

                $pdo->commit();
                error_log("Webhook booking updated to refunded and cancelled: booking_id $booking_id\n", 3, './logs/webhook.log');
            } else {
                $pdo->rollBack();
                $error = "No rows updated for refund, payment_status={$booking['payment_status']}, status={$booking['status']}, refund_pending={$booking['refund_pending']}";
                error_log("Webhook error: $error. booking_id=$booking_id\n", 3, './logs/php_errors.log');
                logAuditAction($pdo, "Webhook failed to process refund: $error", $booking_id, null);
                http_response_code(400);
                exit("No rows updated");
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Database error: " . $e->getMessage();
            error_log("Webhook error: Failed to process refund - $error\n", 3, './logs/php_errors.log');
            logAuditAction($pdo, "Webhook failed to process refund: $error", $booking_id, null);
            http_response_code(500);
            exit("Database error");
        }
    } else {
        $error = "Missing booking_id, payment_id, and payment_request_id for refund";
        error_log("Webhook error: $error\n", 3, './logs/webhook.log');
        http_response_code(400);
        exit("Missing identifiers");
    }
} else {
    error_log("Unexpected event status: " . $data['status'] . "\n", 3, './logs/webhook.log');
}

http_response_code(200);
echo "Webhook processed successfully";
?>