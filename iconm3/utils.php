<?php
  require_once 'db.php';
  require_once 'config.php';

  function logAuditAction($pdo, $action, $booking_id, $admin_id = null) {
      try {
          // Skip if booking_id is not null and invalid
          if ($booking_id !== null) {
              $stmt = $pdo->prepare("SELECT id FROM bookings WHERE id = ?");
              $stmt->execute([$booking_id]);
              if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                  error_log("Audit log skipped: Invalid booking_id $booking_id for action: $action", 3, './logs/php_errors.log');
                  return;
              }
          }
          $stmt = $pdo->prepare("INSERT INTO audit_logs (action, booking_id, admin_id) VALUES (?, ?, ?)");
          $stmt->execute([$action, $booking_id, $admin_id]);
      } catch (PDOException $e) {
          error_log("Audit log error: " . $e->getMessage(), 3, './logs/php_errors.log');
      }
  }

  function sendTelegramNotification($pdo, $booking_id, $event_type = 'confirmation') {
      try {
          // Fetch booking details with all required fields
          $stmt = $pdo->prepare("
              SELECT b.id, b.service_type, b.pickup_date, b.pickup_time, b.pickup_location,
                     b.dropoff_date, b.dropoff_time, b.dropoff_location, b.hours,
                     b.total_amount, b.payment_method, b.payment_status, b.status,
                     b.slot_id, b.rental_type, b.valet_included, c.name, c.email, c.phone,
                     COALESCE(l.model, v.model, NULL) AS service_name
              FROM bookings b
              JOIN clients c ON b.client_id = c.id
              LEFT JOIN limousines l ON b.service_id = l.id AND b.service_type = 'limousine'
              LEFT JOIN vehicles v ON b.service_id = v.id AND b.service_type = 'car_rental'
              WHERE b.id = ?
          ");
          $stmt->execute([$booking_id]);
          $booking = $stmt->fetch(PDO::FETCH_ASSOC);

          if (!$booking) {
              error_log("Telegram notification error: Booking not found for ID $booking_id\n", 3, './logs/telegram_errors.log');
              return false;
          }

          // Fetch service name for car_detailing, car_servicing, or valet
          $service_name = $booking['service_name'];
          if (in_array($booking['service_type'], ['car_servicing', 'car_detailing'])) {
              try {
                  $stmt = $pdo->prepare("SELECT name FROM car_services WHERE id = ?");
                  $stmt->execute([$booking['service_id']]);
                  $service = $stmt->fetch(PDO::FETCH_ASSOC);
                  $service_name = $service['name'] ?? 'Unknown Service';
              } catch (PDOException $e) {
                  error_log("Car services query error for booking ID $booking_id: " . $e->getMessage(), 3, './logs/telegram_errors.log');
                  $service_name = 'Unknown Service';
              }
          } elseif ($booking['service_type'] === 'valet') {
              try {
                  $stmt = $pdo->prepare("SELECT name FROM valet_services WHERE id = ?");
                  $stmt->execute([$booking['service_id']]);
                  $service = $stmt->fetch(PDO::FETCH_ASSOC);
                  $service_name = $service['name'] ?? 'Unknown Valet Service';
              } catch (PDOException $e) {
                  error_log("Valet services query error for booking ID $booking_id: " . $e->getMessage(), 3, './logs/telegram_errors.log');
                  $service_name = 'Unknown Valet Service';
              }
          }

          // HTML escape function
          $escapeHtml = function($string) {
              return htmlspecialchars($string ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
          };

          // Format payment method
          $paymentMethodDisplay = $booking['payment_method'] ?? 'N/A';
          if ($paymentMethodDisplay === 'card') {
              $paymentMethodDisplay = 'Credit/Debit Card';
          } elseif ($paymentMethodDisplay === 'paynow') {
              $paymentMethodDisplay = 'PayNow';
          }

          // Build message
          if ($event_type === 'refund') {
              $message = "<b>🔄 Booking Refunded</b>\n";
          } elseif ($event_type === 'completion') {
              $message = "<b>✔️ Booking Completed</b>\n";
          } else {
              $message = "<b>✅ New Booking Confirmed</b>\n";
          }
          $message .= "-------------------\n";

          // Client Information
          $message .= "<b>Client Information</b>\n";
          $message .= "Booking ID: <code>{$booking['id']}</code>\n";
          $message .= "Name: " . $escapeHtml($booking['name']) . "\n";
          $message .= "Email: " . $escapeHtml($booking['email']) . "\n";
          $message .= "Phone: " . $escapeHtml($booking['phone']) . "\n";
          $message .= "-------------------\n";

          // Service Details
          $message .= "<b>Service Details</b>\n";
          $message .= "Service Type: " . $escapeHtml($booking['service_type']) . "\n";
          if ($booking['service_type'] === 'car_rental') {
              $rental_type = $booking['rental_type'] === 'hourly' ? 'Hourly' : 'Daily';
              $duration = $booking['rental_type'] === 'hourly' ? " ({$booking['hours']} hours)" : " (" . ceil(($booking['hours'] ?: 24) / 24) . " days)";
              $message .= "Rental Type: " . $escapeHtml($rental_type . $duration) . "\n";
              $message .= "Vehicle: " . $escapeHtml($service_name) . "\n";
          } elseif ($booking['service_type'] === 'car_servicing') {
              $message .= "Service: " . $escapeHtml($service_name) . "\n";
              $valet_status = $booking['valet_included'] ? 'Yes' : 'No';
              $message .= "Valet Option: " . $escapeHtml($valet_status) . "\n";
          } else {
              $message .= "Service: " . $escapeHtml($service_name) . "\n";
          }
          $message .= "-------------------\n";

          // Booking Schedule
          $message .= "<b>Booking Schedule</b>\n";
          $pickupDateTime = $booking['pickup_date'] ? $escapeHtml("{$booking['pickup_date']} {$booking['pickup_time']}") : 'N/A';
          $dropoffDateTime = ($booking['dropoff_date'] && $booking['dropoff_time']) ? $escapeHtml("{$booking['dropoff_date']} {$booking['dropoff_time']}") : 'N/A';
          $message .= "Pickup Date & Time: " . $pickupDateTime . "\n";
          $message .= "Dropoff Date & Time: " . $dropoffDateTime . "\n";
          $message .= "Pickup Location: " . $escapeHtml($booking['pickup_location']) . "\n";
          $message .= "Dropoff Location: " . $escapeHtml($booking['dropoff_location']) . "\n";
          $message .= "Hours: " . ($booking['hours'] ? $escapeHtml($booking['hours']) : 'N/A') . "\n";
          $message .= "-------------------\n";

          // Payment Information
          $message .= "<b>Payment Information</b>\n";
          $amount = $event_type === 'refund' ? "S$" . number_format($booking['total_amount'], 2) . " (Refunded)" : "S$" . number_format($booking['total_amount'], 2);
          $message .= "Amount: " . $amount . "\n";
          $message .= "Payment Method: " . $escapeHtml($paymentMethodDisplay) . "\n";
          $message .= "Payment Status: " . $escapeHtml($event_type === 'refund' ? 'Refunded' : $booking['payment_status']) . "\n";
          $message .= "Booking Status: " . $escapeHtml($booking['status']) . "\n";

          // Released Resources for Refunds
          if ($event_type === 'refund') {
              $message .= "-------------------\n";
              $message .= "<b>Released Resources</b>\n";
              if (in_array($booking['service_type'], ['car_detailing', 'valet', 'car_servicing']) && $booking['slot_id']) {
                  $message .= "Resource: Time slot (ID: <code>{$booking['slot_id']}</code>) now available\n";
                  if ($booking['service_type'] === 'car_servicing' && $booking['valet_included']) {
                      $message .= "Valet Resource: Valet slot (ID: <code>{$booking['valet_slot_id']}</code>) now available\n";
                  }
              } elseif (in_array($booking['service_type'], ['car_rental', 'limousine'])) {
                  $vehicleType = $booking['service_type'] === 'car_rental' ? 'Vehicle' : 'Limousine';
                  $message .= "Resource: {$vehicleType} (" . $escapeHtml($service_name) . ") now available\n";
              } else {
                  $message .= "Resource: None\n";
              }
          }

          // Log message content
          error_log("Telegram message content for booking ID $booking_id ($event_type): $message\n", 3, './logs/telegram.log');

          // Send to Telegram
          $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
          $payload = [
              'chat_id' => TELEGRAM_GROUP_CHAT_ID,
              'text' => $message,
              'parse_mode' => 'HTML'
          ];

          $ch = curl_init($url);
          curl_setopt($ch, CURLOPT_POST, 1);
          curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
          $response = curl_exec($ch);
          if (curl_errno($ch)) {
              error_log("Telegram API error: " . curl_error($ch) . "\n", 3, './logs/telegram_errors.log');
              curl_close($ch);
              return false;
          }
          curl_close($ch);

          $responseData = json_decode($response, true);
          if (!$responseData['ok']) {
              error_log("Telegram API error for booking ID $booking_id ($event_type): " . $response . "\nMessage: $message\n", 3, './logs/telegram_errors.log');
              return false;
          }

          error_log("Telegram notification sent for booking ID $booking_id ($event_type): $response\n", 3, './logs/telegram.log');
          return true;
      } catch (Exception $e) {
          error_log("Telegram notification error for booking ID $booking_id ($event_type): " . $e->getMessage() . "\n", 3, './logs/telegram_errors.log');
          return false;
      }
  }

  function releaseResources($pdo, $booking_id, $service_type, $service_id, $slot_id = null, $valet_option = null) {
      try {
          error_log("Releasing resources for booking_id: $booking_id, service_type: $service_type, service_id: $service_id, slot_id: " . ($slot_id ?? 'NULL') . ", valet_option: " . ($valet_option ?? 'NULL'), 3, './logs/debug.log');

          // Handle slot release for car_detailing, valet, and car_servicing
          if (in_array($service_type, ['car_detailing', 'valet', 'car_servicing']) && $slot_id !== null) {
              $stmt = $pdo->prepare("UPDATE slots SET is_booked = 0 WHERE id = ? AND is_booked = 1");
              $stmt->execute([$slot_id]);
              if ($stmt->rowCount() > 0) {
                  error_log("Slot released successfully for slot_id: $slot_id", 3, './logs/debug.log');
                  logAuditAction($pdo, "Slot released for cancelled booking", $booking_id);
              } else {
                  error_log("No slot released for slot_id: $slot_id (already free or not found)", 3, './logs/debug.log');
              }
          }

          // Handle valet slot release for car_servicing
          if ($service_type === 'car_servicing' && $valet_option && is_numeric($valet_option)) {
              $stmt = $pdo->prepare("UPDATE slots SET is_booked = 0 WHERE id = ? AND is_booked = 1");
              $stmt->execute([$valet_option]);
              if ($stmt->rowCount() > 0) {
                  error_log("Valet slot released successfully for valet_slot_id: $valet_option", 3, './logs/debug.log');
                  logAuditAction($pdo, "Valet slot released for cancelled car_servicing booking", $booking_id);
              } else {
                  error_log("No valet slot released for valet_slot_id: $valet_option (already free or not found)", 3, './logs/debug.log');
              }
          } elseif ($service_type === 'car_servicing' && $valet_option) {
              error_log("Valet option released for car_servicing booking_id: $booking_id (boolean flag)", 3, './logs/debug.log');
              logAuditAction($pdo, "Valet option released for cancelled car_servicing booking", $booking_id);
          }

          // For car_rental and limousine, no slot release needed
          if (in_array($service_type, ['car_rental', 'limousine'])) {
              error_log("No slot release needed for $service_type, booking_id: $booking_id", 3, './logs/debug.log');
              logAuditAction($pdo, "Resources released for cancelled $service_type booking", $booking_id);
          }
      } catch (PDOException $e) {
          error_log("Error releasing resources for booking_id: $booking_id: " . $e->getMessage(), 3, './logs/php_errors.log');
          logAuditAction($pdo, "Failed to release resources: " . $e->getMessage(), $booking_id);
      }
  }
  ?>